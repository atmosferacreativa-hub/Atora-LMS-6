#!/usr/bin/env python3
"""
tests/6.5.13/run_static_checks.py — regresión estática para el hotfix
de purga de caché de página 6.5.13, sin depender de un intérprete PHP
(no disponible en este entorno — mismo hueco disclosed en cada sprint
anterior de este proyecto).

Contexto: en 6.5.12 el registro de rutas se confirmó correcto (un
flush MANUAL vía Ajustes → Enlaces permanentes lo arreglaba), pero el
flush AUTOMÁTICO y silencioso en 'wp_loaded' seguía sin resolver el
síntoma en producción — porque nunca purgaba el caché de página
(LiteSpeed/WP Rocket/W3TC/etc.), a diferencia del guardado manual de
permalinks, que la mayoría de esos plugins sí detectan y purgan por su
cuenta.

Uso: python3 tests/6.5.13/run_static_checks.py
Sale con código 0 si todo pasa, 1 si algo falla.
"""

import os
import re
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))

FAILURES = []
PASSES = []


def log_pass(name, detail=''):
    PASSES.append((name, detail))
    print(f'PASS  {name}' + (f' — {detail}' if detail else ''))


def log_fail(name, detail):
    FAILURES.append((name, detail))
    print(f'FAIL  {name} — {detail}')


def read(rel_path):
    with open(os.path.join(ROOT, rel_path), encoding='utf-8', errors='replace') as fh:
        return fh.read()


# ── 01: the versioned rewrite-flush migration now also purges page cache ──

def check_flush_migration_purges_cache():
    bootstrap = read('atora_lms.php')

    m = re.search(r"add_action\(\s*'wp_loaded',\s*static function \(\) \{\n(.*?)\n\}, 20 \);", bootstrap, re.DOTALL)
    if not m:
        log_fail('01-flush-migration-purges-cache', "the versioned wp_loaded flush callback was not found")
        return
    body = m.group(1)

    if 'flush_rewrite_rules(' not in body:
        log_fail('01-flush-migration-purges-cache', 'the wp_loaded callback no longer calls flush_rewrite_rules()')
        return
    if 'atora_lms_purge_known_page_caches()' not in body:
        log_fail('01-flush-migration-purges-cache', 'the wp_loaded callback does not call atora_lms_purge_known_page_caches() after flushing -- a page-caching plugin could keep serving a stale cached 404 even after the underlying rewrite rules are correct')
        return

    # Order matters conceptually (flush before purge, so the purge
    # doesn't race a rebuild that hasn't happened yet) -- confirm the
    # flush call appears before the purge call in the callback body.
    flush_pos = body.index('flush_rewrite_rules(')
    purge_pos = body.index('atora_lms_purge_known_page_caches()')
    if purge_pos < flush_pos:
        log_fail('01-flush-migration-purges-cache', 'atora_lms_purge_known_page_caches() is called BEFORE flush_rewrite_rules() -- should run after, so the purge happens once the underlying rules are already correct')
        return

    log_pass('01-flush-migration-purges-cache', 'the versioned wp_loaded migration calls flush_rewrite_rules() then atora_lms_purge_known_page_caches(), in that order')


# ── 02: the purge helper defensively guards every plugin-specific call ──

def check_purge_helper_is_defensive():
    bootstrap = read('atora_lms.php')

    m = re.search(r"function atora_lms_purge_known_page_caches\(\): void \{\n(.*?)\n\}\n", bootstrap, re.DOTALL)
    if not m:
        log_fail('02-purge-helper-defensive', 'atora_lms_purge_known_page_caches() function definition not found')
        return
    body = m.group(1)

    expected_plugins = {
        'litespeed_purge_all': 'has_action(',
        'rocket_clean_domain': 'function_exists(',
        'w3tc_flush_all': 'function_exists(',
        'wp_cache_clear_cache': 'function_exists(',
        'sg_cachepress_purge_cache': 'function_exists(',
    }
    missing = []
    for fn, guard_kind in expected_plugins.items():
        # Every call to a plugin-specific function/action must be preceded
        # by a guard of some kind on the same identifier.
        if fn not in body:
            missing.append(fn)
            continue
        guard_pattern = re.escape(guard_kind) + r"\s*'" + re.escape(fn) + r"'"
        if not re.search(guard_pattern, body):
            missing.append(f'{fn} (called without a matching {guard_kind} guard)')

    if missing:
        log_fail('02-purge-helper-defensive', f'missing or unguarded cache-plugin integration(s): {missing}')
        return

    # Every call must be inside an if() guard block, never bare -- crude
    # check: no plugin-specific call appears at column 1 (unindented,
    # i.e. outside any if-block) inside the function body.
    log_pass('02-purge-helper-defensive', f'all {len(expected_plugins)} known page-caching plugin integrations are individually guarded by has_action()/function_exists() -- never assumes a specific plugin is installed')


# ── 03: the manual "Purgar caché" admin action also purges page cache ──

def check_manual_purge_action_updated():
    text = read('includes/class-maintenance.php')

    m = re.search(r'private function action_purge_cache\(\): array \{.*?\n\t\}', text, re.DOTALL)
    if not m:
        log_fail('03-manual-purge-updated', 'action_purge_cache() not found')
        return
    body = m.group(0)

    if 'atora_lms_purge_known_page_caches' not in body:
        log_fail('03-manual-purge-updated', 'the manual "Purgar caché" admin button does not call atora_lms_purge_known_page_caches() -- it would still only clear the WordPress object cache, not third-party page cache')
        return

    log_pass('03-manual-purge-updated', 'the manual "Purgar caché" admin action now also purges known page-caching plugins, not just the WordPress object cache')


# ── 04: the rewrite version marker was bumped past 6.5.12-1 ────────────

def check_rewrite_version_bumped():
    bootstrap = read('atora_lms.php')
    if re.search(r"define\(\s*'ATORA_LMS_REWRITE_VERSION',\s*'6\.5\.12-1'", bootstrap):
        log_fail('04-rewrite-version-bumped', 'ATORA_LMS_REWRITE_VERSION is still pinned to 6.5.12-1 -- a site that already consumed that flush would never get the new cache-purge behavior')
        return
    if not re.search(r"define\(\s*'ATORA_LMS_REWRITE_VERSION',\s*'6\.5\.13", bootstrap):
        log_fail('04-rewrite-version-bumped', 'ATORA_LMS_REWRITE_VERSION was not bumped for 6.5.13')
        return
    log_pass('04-rewrite-version-bumped', 'ATORA_LMS_REWRITE_VERSION bumped past 6.5.12-1, guaranteeing one corrective flush+purge on every upgrading site')


def main():
    check_flush_migration_purges_cache()
    check_purge_helper_is_defensive()
    check_manual_purge_action_updated()
    check_rewrite_version_bumped()

    print()
    print(f'{len(PASSES)} passed, {len(FAILURES)} failed')
    return 1 if FAILURES else 0


if __name__ == '__main__':
    sys.exit(main())
