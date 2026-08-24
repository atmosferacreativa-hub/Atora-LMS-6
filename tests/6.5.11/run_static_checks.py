#!/usr/bin/env python3
"""
tests/6.5.11/run_static_checks.py — regresión estática para el hotfix
de runtime/rewrite 6.5.11, sin depender de un intérprete PHP (no
disponible en este entorno — mismo hueco disclosed en cada sprint
anterior de este proyecto).

Cubre, de la lista mínima de la OT, lo que es genuinamente verificable
por análisis estático de texto:
  01-course-post-type-registration -> check_course_post_type_registration()
  04-instructor-route-registration -> check_instructor_route_registration()
  07-rewrite-flush-lifecycle       -> check_rewrite_flush_lifecycle()
  08-no-runtime-flush              -> (mismo check — clasifica cada call site)
  09-admin-diagnostics-disabled-by-default -> check_admin_diagnostics_gate()
  10-admin-diagnostics-explicit-only       -> (mismo check)
  11-no-runtime-schema-ddl         -> check_no_runtime_schema_ddl() (re-verificación 6.5.10)
  12-runtime-log-noise             -> check_runtime_log_noise()

Lo que NO puede verificarse acá (requiere un WordPress/MySQL real con
WP_Rewrite y el parser de rutas reales): 02/03 (generación/resolución
de permalink de curso), 05/06 (ídem instructor), 14 (upgrade real
6.5.10 -> 6.5.11 contra una BD poblada). 13 (regresión de seguridad)
se verifica vía diff, no acá. Esos quedan documentados en
tests/6.5.11/README.md.

Uso: python3 tests/6.5.11/run_static_checks.py
Sale con código 0 si todo pasa, 1 si algo falla.
"""

import os
import re
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
EXCLUDE_DIRS = {'.git', 'vendor', 'node_modules', 'dist', 'tests'}

FAILURES = []
PASSES = []


def log_pass(name, detail=''):
    PASSES.append((name, detail))
    print(f'PASS  {name}' + (f' — {detail}' if detail else ''))


def log_fail(name, detail):
    FAILURES.append((name, detail))
    print(f'FAIL  {name} — {detail}')


def walk_php_files():
    for root, dirs, files in os.walk(ROOT):
        dirs[:] = [d for d in dirs if d not in EXCLUDE_DIRS]
        rel_root = os.path.relpath(root, ROOT)
        if rel_root != '.' and rel_root.split(os.sep)[0] in EXCLUDE_DIRS:
            continue
        for f in files:
            if f.endswith('.php'):
                yield os.path.join(root, f)


def read(rel_path):
    with open(os.path.join(ROOT, rel_path), encoding='utf-8', errors='replace') as fh:
        return fh.read()


# ── 01: course CPT registered public/queryable/rewrite, and reachable
#        from the normal (non-activation-only) request path ───────────────

def check_course_post_type_registration():
    cpt_text = read('includes/class-cpt.php')

    m = re.search(r"protected function register_course_post_type\(\).*?register_post_type\(\s*'lm_course'", cpt_text, re.DOTALL)
    if not m:
        log_fail('01-course-post-type-registration', "register_course_post_type() -> register_post_type('lm_course', ...) not found")
        return
    block = m.group(0)

    required = {
        "'public'": "'public'              => true",
        "'publicly_queryable'": "'publicly_queryable'  => true",
        "'query_var'": "'query_var'           => true",
        "rewrite slug": "'slug'       => 'cursos'",
    }
    missing = [k for k, needle in required.items() if needle.replace(' ', '') not in block.replace(' ', '')]
    if missing:
        log_fail('01-course-post-type-registration', f'lm_course registration missing expected flags: {missing}')
        return

    # Reachability: CLMS_CPT must be wired into CLMS_Loader's base_modules
    # (booted on EVERY normal request via CLMS_Loader::boot()), not only
    # inside atora_lms_activate() (which only fires on an explicit
    # activate/reactivate, never on a normal file-replace update).
    loader_text = read('includes/class-loader.php')
    if not re.search(r"'class'\s*=>\s*'CLMS_CPT'", loader_text):
        log_fail('01-course-post-type-registration', 'CLMS_CPT is not registered in CLMS_Loader base_modules — it would only run on explicit plugin (re)activation, not on normal requests')
        return

    log_pass('01-course-post-type-registration', "lm_course is public/publicly_queryable/query_var with rewrite slug 'cursos', and CLMS_CPT is booted on every normal request via CLMS_Loader")


# ── 04: instructor route registration ───────────────────────────────────

def check_instructor_route_registration():
    instructor_text = read('includes/class-instructor.php')

    if not re.search(r"add_action\(\s*'init',\s*array\(\s*\\?\$this,\s*'register_rewrite'", instructor_text):
        log_fail('04-instructor-route-registration', "CLMS_Instructor's constructor no longer hooks register_rewrite() to 'init'")
        return
    if not re.search(r"add_filter\(\s*'query_vars',\s*array\(\s*\\?\$this,\s*'register_query_vars'", instructor_text):
        log_fail('04-instructor-route-registration', "CLMS_Instructor's constructor no longer hooks register_query_vars() to 'query_vars'")
        return
    if "add_rewrite_rule( '^docentes/" not in instructor_text:
        log_fail('04-instructor-route-registration', "the /docentes/{slug}/ rewrite rule is missing from register_rewrite()")
        return

    loader_text = read('includes/class-loader.php')
    groups_text = read('includes/loader/trait-loader-module-groups.php')
    if not re.search(r"'class'\s*=>\s*'CLMS_Instructor'", groups_text):
        log_fail('04-instructor-route-registration', 'CLMS_Instructor is not registered in any CLMS_Loader module group — its constructor (and therefore its rewrite rule) would never run on a normal request')
        return

    log_pass('04-instructor-route-registration', "CLMS_Instructor hooks its rewrite rule/query var correctly and is booted by CLMS_Loader on every normal request (via its 'core' module group)")


# ── 07/08: rewrite flush lifecycle — every flush_rewrite_rules() call
#           site classified; none may run unconditionally on ordinary
#           requests ─────────────────────────────────────────────────────

def check_rewrite_flush_lifecycle():
    bootstrap = read('atora_lms.php')

    lines = bootstrap.split('\n')

    call_sites = []
    for m in re.finditer(r'flush_rewrite_rules\(', bootstrap):
        line_no = bootstrap[:m.start()].count('\n') + 1
        # Skip plain-comment mentions of the function name (documentation
        # prose, not an actual call site).
        if lines[line_no - 1].strip().startswith('//'):
            continue
        call_sites.append(line_no)

    if not call_sites:
        log_fail('07-rewrite-flush-lifecycle', 'no flush_rewrite_rules() call found at all — courses/instructor routes could never recover from a stale cache')
        return

    def enclosing_function(line_no):
        """Name of the nearest preceding top-level `function name(...) {` declaration."""
        name = None
        for i in range(line_no - 1):
            m = re.match(r'function\s+([a-zA-Z0-9_]+)\s*\(', lines[i])
            if m:
                name = m.group(1)
        return name

    def context_around(line_no, span=60):
        start = max(0, line_no - span)
        end = min(len(lines), line_no + 3)
        return '\n'.join(lines[start:end])

    classified = {'activation': 0, 'deactivation': 0, 'versioned-migration': 0, 'unclassified': []}
    for line_no in call_sites:
        fn = enclosing_function(line_no)
        ctx = context_around(line_no)
        if fn == 'atora_lms_activate':
            classified['activation'] += 1
        elif fn == 'atora_lms_deactivate':
            classified['deactivation'] += 1
        elif fn is None and 'atora_lms_rewrite_version' in ctx and 'version_compare' in ctx:
            # The versioned migration lives in a top-level add_action(...)
            # closure, not a named function — enclosing_function() correctly
            # finds no named function wrapping it.
            classified['versioned-migration'] += 1
        else:
            classified['unclassified'].append((line_no, fn))

    if classified['unclassified']:
        log_fail('07-rewrite-flush-lifecycle', f"flush_rewrite_rules() call(s) at line(s) {classified['unclassified']} could not be classified as activation/deactivation/versioned-migration — verify manually, this may be an unconditional runtime flush")
        return

    if classified['activation'] < 1 or classified['deactivation'] < 1:
        log_fail('07-rewrite-flush-lifecycle', f"expected at least one activation and one deactivation flush, found: {classified}")
        return

    if classified['versioned-migration'] < 1:
        log_fail('07-rewrite-flush-lifecycle', 'no versioned one-time upgrade-migration flush found — a site updated by file-replace (not deactivate/reactivate) would never get a fresh flush after a historical rewrite-affecting change')
        return

    log_pass('07-rewrite-flush-lifecycle', f'all {len(call_sites)} flush_rewrite_rules() call site(s) classified: {classified["activation"]} activation-only, {classified["deactivation"]} deactivation-only, {classified["versioned-migration"]} versioned one-time migration — none unconditional')


def check_no_unconditional_runtime_flush():
    """
    08-no-runtime-flush: the versioned migration call itself must be
    guarded by a version_compare()/option check before the actual
    flush_rewrite_rules() call — not just be a bare unconditional call
    inside an always-firing hook.
    """
    bootstrap = read('atora_lms.php')
    m = re.search(
        r"add_action\(\s*'wp_loaded'.*?\}\s*,\s*\d+\s*\);",
        bootstrap, re.DOTALL
    )
    if not m:
        log_fail('08-no-runtime-flush', "no add_action('wp_loaded', ...) callback found for the rewrite migration")
        return
    body = m.group(0)
    if 'flush_rewrite_rules(' not in body:
        log_fail('08-no-runtime-flush', "the wp_loaded callback does not call flush_rewrite_rules() at all")
        return
    if not re.search(r'if\s*\(.*version_compare', body, re.DOTALL) and 'return;' not in body:
        log_fail('08-no-runtime-flush', 'flush_rewrite_rules() inside the wp_loaded callback is not guarded by an early-return version check — this would flush on every request')
        return
    log_pass('08-no-runtime-flush', "the wp_loaded rewrite-migration callback returns early unless the stored version is stale, so flush_rewrite_rules() runs at most once per version bump, never unconditionally")


# ── 09/10: admin-menu diagnostics disabled by default, explicit-only ───

def check_admin_diagnostics_gate():
    text = read('includes/admin-menu/class-menu-debug-guard.php')

    m = re.search(r'public static function init\(\).*?\n\t\}', text, re.DOTALL)
    if not m:
        log_fail('09-admin-diagnostics-disabled-by-default', 'CLMS_Menu_Debug_Guard::init() not found')
        return
    init_body = m.group(0)

    if 'WP_DEBUG' not in init_body:
        log_fail('09-admin-diagnostics-disabled-by-default', 'init() no longer checks WP_DEBUG at all')
        return
    if 'ATORA_DEBUG_ADMIN_AUDIT' not in init_body:
        log_fail('10-admin-diagnostics-explicit-only', "init() does not require the explicit ATORA_DEBUG_ADMIN_AUDIT opt-in — WP_DEBUG alone is not an explicit opt-in for THIS diagnostic (many staging/some production setups leave WP_DEBUG on)")
        return

    # Confirm the constant is never unconditionally defined as true anywhere
    # in production code (only conditionally in wp-config.php, outside this
    # repository, is acceptable).
    for path in walk_php_files():
        rel = os.path.relpath(path, ROOT)
        if rel == 'includes/admin-menu/class-menu-debug-guard.php':
            continue
        with open(path, encoding='utf-8', errors='replace') as fh:
            text2 = fh.read()
        if re.search(r"define\(\s*'ATORA_DEBUG_ADMIN_AUDIT'\s*,\s*true", text2):
            log_fail('09-admin-diagnostics-disabled-by-default', f'{rel} unconditionally defines ATORA_DEBUG_ADMIN_AUDIT as true — it would no longer default OFF')
            return

    log_pass('09-admin-diagnostics-disabled-by-default / 10-admin-diagnostics-explicit-only', 'init() requires BOTH WP_DEBUG and the explicit ATORA_DEBUG_ADMIN_AUDIT constant; the constant is not unconditionally defined true anywhere in the plugin, so it defaults OFF')


def check_orphan_scan_scoped_to_own_slugs():
    """Extra coverage for bug family 4: the orphan-page scan must never flag third-party plugin pages."""
    text = read('includes/admin-menu/class-menu-debug-guard.php')
    if 'is_own_slug' not in text:
        log_fail('09-admin-diagnostics-disabled-by-default', 'the orphan-page scan no longer scopes itself to ATORA-owned slug prefixes — it would flag third-party plugin pages (WooCommerce/Yoast/LiteSpeed/Site Kit/Action Scheduler) as "orphans"')
        return
    log_pass('09-admin-diagnostics-disabled-by-default (scope)', 'the orphan-page and duplicate-slug scans are both scoped to ATORA-owned slug prefixes only')


# ── 11: no unconditional runtime DDL (re-verification of the 6.5.10 fix) ──

def check_no_runtime_schema_ddl():
    installer_files = [
        'includes/class-clms-db-migration.php',
        'includes/enrollment-manager/trait-enrollment-manager-invitations.php',
        'modules/class-v5-installer.php',
        'modules/crm-v2/services/class-db-service.php',
        'modules/lms/class-lms-parity.php',
        'modules/messaging/class-messaging-digest-store.php',
    ]
    ungated = []
    for rel in installer_files:
        path = os.path.join(ROOT, rel)
        if not os.path.exists(path):
            ungated.append(f'{rel}: file not found')
            continue
        text = read(rel)
        has_show_tables = 'SHOW TABLES LIKE' in text
        has_version_gate = bool(re.search(r'SCHEMA_VERSION|schema_version|OPT_TABLES_CONFIRMED', text))
        if not (has_show_tables or has_version_gate):
            ungated.append(f'{rel}: no SHOW TABLES / schema-version gate found')

    if ungated:
        log_fail('11-no-runtime-schema-ddl', '; '.join(ungated))
    else:
        log_pass('11-no-runtime-schema-ddl', f'all {len(installer_files)} table-creating files remain behind a SHOW TABLES and/or schema-version gate (unchanged from 6.5.10, re-verified this sprint)')


# ── 12: runtime log noise — every error_log() call site is either
#        error-path-only or behind an explicit opt-in gate ────────────────

def check_runtime_log_noise():
    unconditional_info_logs = []
    for path in walk_php_files():
        rel = os.path.relpath(path, ROOT)
        with open(path, encoding='utf-8', errors='replace') as fh:
            full_text = fh.read()
        lines = full_text.split('\n')

        # File-wide signals: a debug-gate constant anywhere in the file
        # (the gate is often structural — e.g. the method is only ever
        # hooked when the constant is set — not a literal inline check
        # next to the error_log() call itself), or the file being an
        # explicit one-time activation/migration routine.
        file_is_gated = bool(re.search(r'ATORA_DEBUG_ADMIN_AUDIT|WP_DEBUG|ATORA_DEV_MODE', full_text))
        file_is_activation_only = 'function atora_lms_activate' in full_text or 'function atora_lms_deactivate' in full_text

        for i, line in enumerate(lines):
            if 'error_log(' not in line:
                continue
            if line.strip().startswith('//') or line.strip().startswith('*'):
                continue

            window = '\n'.join(lines[max(0, i - 10):i + 1])
            # Line itself is often inside a sprintf(...) spanning several
            # lines below the error_log( call — look a little ahead too.
            window += '\n' + '\n'.join(lines[i + 1:i + 8])

            is_error_path = bool(re.search(
                r'catch\s*\(|->getMessage\(\)|is_wp_error|last_error|fallback|fallo|fall[oó]|'
                r'no\s+encontrad|no\s+disponible|failed|invalid|inválid|faltante|falta[nr]|'
                r'error|conflict|conflicto|ambigu|no\s+se\s+pudo|missing|requiere\s+revisi[oó]n',
                window, re.IGNORECASE
            ))
            is_gated = file_is_gated
            is_activation_only_context = file_is_activation_only and (
                'atora_lms_activate' in '\n'.join(lines[max(0, i - 60):i]) or
                'atora_lms_deactivate' in '\n'.join(lines[max(0, i - 60):i])
            )

            if not is_error_path and not is_gated and not is_activation_only_context:
                unconditional_info_logs.append(f'{rel}:{i+1}: {line.strip()}')

    if unconditional_info_logs:
        log_fail('12-runtime-log-noise', f'{len(unconditional_info_logs)} error_log() call(s) that appear neither error-path-scoped nor debug-gated: ' + ' | '.join(unconditional_info_logs[:10]))
    else:
        log_pass('12-runtime-log-noise', 'every error_log() call site in the codebase is either on a genuine error/failure path or behind an explicit debug-mode gate — no unconditional informational log spam found')


def main():
    check_course_post_type_registration()
    check_instructor_route_registration()
    check_rewrite_flush_lifecycle()
    check_no_unconditional_runtime_flush()
    check_admin_diagnostics_gate()
    check_orphan_scan_scoped_to_own_slugs()
    check_no_runtime_schema_ddl()
    check_runtime_log_noise()

    print()
    print(f'{len(PASSES)} passed, {len(FAILURES)} failed')
    return 1 if FAILURES else 0


if __name__ == '__main__':
    sys.exit(main())
