#!/usr/bin/env python3
"""
tests/6.5.12/run_static_checks.py — regresión estática para el hotfix
de bootstrap de rutas 6.5.12, sin depender de un intérprete PHP (no
disponible en este entorno — mismo hueco disclosed en cada sprint
anterior de este proyecto).

Cubre, de la lista mínima de la OT, lo que es genuinamente verificable
por análisis estático de texto:
  01-bootstrap-order            -> check_structural_bootstrap_is_top_level()
  02-course-cpt-exists-after-init -> check_course_cpt_deterministic_registration()
  03-course-rewrite-registered  -> (mismo check, cubre CPT + taxonomía)
  06-instructor-rewrite-registered -> check_instructor_deterministic_registration()
  09-query-vars                 -> (mismo check — confirma el filtro query_vars)
  10-template-routing           -> check_loader_visibility_fix_intact()
  11-one-time-rewrite-flush     -> check_rewrite_flush_lifecycle() (re-verificación 6.5.11)
  12-no-runtime-flush           -> (mismo check)
  13-performance-regression     -> check_no_performance_regression()
  14-security-regression        -> ver 6.5.12 report / diff (no acá)
  HOOK-ORDER (regla explícita)  -> check_no_registered_earlier_priority_than_current()

Lo que NO puede verificarse acá (requiere un WordPress/MySQL real):
04/05 (resolución real de URL de curso válida/inválida), 07/08 (ídem
instructor), 15 (upgrade real 6.5.11 -> 6.5.12 contra una BD poblada).
Esos quedan documentados en tests/6.5.12/README.md.

Uso: python3 tests/6.5.12/run_static_checks.py
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


def read(rel_path):
    with open(os.path.join(ROOT, rel_path), encoding='utf-8', errors='replace') as fh:
        return fh.read()


def walk_php_files():
    for root, dirs, files in os.walk(ROOT):
        dirs[:] = [d for d in dirs if d not in EXCLUDE_DIRS]
        rel_root = os.path.relpath(root, ROOT)
        if rel_root != '.' and rel_root.split(os.sep)[0] in EXCLUDE_DIRS:
            continue
        for f in files:
            if f.endswith('.php'):
                yield os.path.join(root, f)


# ── 01: the new structural bootstrap add_action() is registered at the
#        TOP LEVEL of atora_lms.php, not nested inside another already-
#        executing 'init' callback ──────────────────────────────────────

def check_structural_bootstrap_is_top_level():
    bootstrap = read('atora_lms.php')
    lines = bootstrap.split('\n')

    # Find the structural-bootstrap add_action('init', ..., 0) call.
    m = re.search(r"add_action\(\s*'init',\s*static function \(\) \{\n(.*?)\n\}, 0 \);", bootstrap, re.DOTALL)
    if not m:
        log_fail('01-bootstrap-order', "no add_action('init', ..., 0) structural bootstrap callback found")
        return
    call_line = bootstrap[:m.start()].count('\n') + 1

    # Confirm indentation is at column 0 (top-level file scope), not
    # inside another function/closure body (which would be indented).
    line_text = lines[call_line - 1]
    if line_text.startswith('\t') or line_text.startswith(' '):
        log_fail('01-bootstrap-order', f'the structural bootstrap add_action() at line {call_line} appears indented — it may be nested inside another callback instead of at top-level file scope')
        return

    body = m.group(1)
    if 'ReflectionClass' not in body:
        log_fail('01-bootstrap-order', 'the structural bootstrap does not use ReflectionClass::newInstanceWithoutConstructor() — it may instantiate CLMS_CPT/CLMS_Instructor via new(), duplicating their constructors\' other hook registrations (admin columns, metaboxes, shortcodes) when CLMS_Loader creates its own instances later in the same request')
        return

    log_pass('01-bootstrap-order', f'the structural bootstrap callback (line {call_line}) is registered at top-level file scope, priority 0 — not nested inside another init callback, and avoids duplicate hook registration via newInstanceWithoutConstructor()')


# ── 02/03: course CPT + taxonomy registered deterministically, early ───

def check_course_cpt_deterministic_registration():
    bootstrap = read('atora_lms.php')

    m = re.search(r"add_action\(\s*'init',\s*static function \(\) \{\n(.*?)\n\}, 0 \);", bootstrap, re.DOTALL)
    if not m:
        log_fail('02-course-cpt-exists-after-init', 'structural bootstrap callback not found (see 01)')
        return
    body = m.group(1)

    if "'CLMS_CPT'" not in body:
        log_fail('02-course-cpt-exists-after-init', 'structural bootstrap does not reference CLMS_CPT at all')
        return
    if 'register_post_types()' not in body:
        log_fail('02-course-cpt-exists-after-init', 'structural bootstrap does not call register_post_types() directly (a method call, not just a hook registration relying on same-pass nested firing)')
        return
    if 'register_taxonomies()' not in body:
        log_fail('03-course-rewrite-registered', 'structural bootstrap does not call register_taxonomies() directly')
        return

    log_pass('02-course-cpt-exists-after-init / 03-course-rewrite-registered', 'the structural bootstrap calls CLMS_CPT::register_post_types() and register_taxonomies() as direct method calls (not hook-registration relying on nested same-pass firing) at init priority 0')


# ── 06/09: instructor rewrite rule + query var registered deterministically ──

def check_instructor_deterministic_registration():
    bootstrap = read('atora_lms.php')

    m = re.search(r"add_action\(\s*'init',\s*static function \(\) \{\n(.*?)\n\}, 0 \);", bootstrap, re.DOTALL)
    if not m:
        log_fail('06-instructor-rewrite-registered', 'structural bootstrap callback not found (see 01)')
        return
    body = m.group(1)

    if "'CLMS_Instructor'" not in body:
        log_fail('06-instructor-rewrite-registered', 'structural bootstrap does not reference CLMS_Instructor at all')
        return
    if 'register_rewrite()' not in body:
        log_fail('06-instructor-rewrite-registered', 'structural bootstrap does not call register_rewrite() directly')
        return
    if not re.search(r"add_filter\(\s*'query_vars',\s*array\(\s*\\?\$early_instructor,\s*'register_query_vars'", body):
        log_fail('09-query-vars', 'structural bootstrap does not register the clms_instructor query var via the early instance')
        return

    log_pass('06-instructor-rewrite-registered / 09-query-vars', 'the structural bootstrap calls CLMS_Instructor::register_rewrite() directly and registers its query_vars filter, at init priority 0')


# ── 10: the 6.5.10 loader-visibility fix (public get_template_path()
#        wrapper) is still in place, not reverted ──────────────────────

def check_loader_visibility_fix_intact():
    instructor_text = read('includes/class-instructor.php')
    loader_text = read('includes/class-loader.php')

    if 'get_template_path' not in loader_text:
        log_fail('10-template-routing', 'CLMS_Loader::get_template_path() public wrapper (6.5.10 fix) is missing')
        return
    if re.search(r"->resolve_file_path\(", instructor_text):
        log_fail('10-template-routing', 'class-instructor.php calls the protected resolve_file_path() directly again — the 6.5.10 fatal (Call to protected method) has been reintroduced')
        return
    if 'get_template_path' not in instructor_text:
        log_fail('10-template-routing', 'class-instructor.php no longer uses the get_template_path() wrapper at all')
        return

    log_pass('10-template-routing', 'the 6.5.10 loader-visibility fix (public get_template_path() wrapper) remains intact; class-instructor.php never calls the protected resolve_file_path() directly')


# ── 11/12: rewrite flush lifecycle — re-verification of the 6.5.11
#           fix, plus confirmation the version marker was bumped so
#           existing 6.5.11 installs get one corrective re-flush ──────

def check_rewrite_flush_lifecycle():
    bootstrap = read('atora_lms.php')
    lines = bootstrap.split('\n')

    call_sites = []
    for m in re.finditer(r'flush_rewrite_rules\(', bootstrap):
        line_no = bootstrap[:m.start()].count('\n') + 1
        if lines[line_no - 1].strip().startswith('//'):
            continue
        call_sites.append(line_no)

    if not call_sites:
        log_fail('11-one-time-rewrite-flush', 'no flush_rewrite_rules() call found at all')
        return

    def enclosing_function(line_no):
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
            classified['versioned-migration'] += 1
        else:
            classified['unclassified'].append((line_no, fn))

    if classified['unclassified']:
        log_fail('11-one-time-rewrite-flush', f"unclassified flush_rewrite_rules() call site(s): {classified['unclassified']} — possible unconditional runtime flush")
        return
    if classified['versioned-migration'] < 1:
        log_fail('11-one-time-rewrite-flush', 'no versioned one-time migration flush found')
        return

    log_pass('11-one-time-rewrite-flush / 12-no-runtime-flush', f'all {len(call_sites)} flush_rewrite_rules() call site(s) remain classified: {classified} — none unconditional')

    # 6.5.12-specific: the rewrite version marker must have been bumped
    # since 6.5.11 ('6.5.11-1'), so any site that already ran that flush
    # (potentially with incomplete rules, per this sprint's own root-cause
    # finding) gets exactly one corrective re-flush now that registration
    # is deterministic.
    if "'6.5.11-1'" in bootstrap and re.search(r"define\(\s*'ATORA_LMS_REWRITE_VERSION',\s*'6\.5\.11-1'", bootstrap):
        log_fail('11-one-time-rewrite-flush', "ATORA_LMS_REWRITE_VERSION is still pinned to 6.5.11-1 — existing 6.5.11 installs would never get a corrective re-flush under the new deterministic registration")
        return
    if not re.search(r"define\(\s*'ATORA_LMS_REWRITE_VERSION',\s*'6\.5\.12", bootstrap):
        log_fail('11-one-time-rewrite-flush', 'ATORA_LMS_REWRITE_VERSION was not bumped for 6.5.12')
        return
    log_pass('11-one-time-rewrite-flush (version bump)', 'ATORA_LMS_REWRITE_VERSION was bumped past 6.5.11-1, guaranteeing one corrective re-flush on every upgrading site')


# ── HOOK-ORDER regression guard: detect "registered during init:X for
#    init:Y where Y < X" for CLMS_CPT/CLMS_Instructor specifically ─────

def check_no_registered_earlier_priority_than_current():
    """
    The dangerous pattern this guards against: a callback body running
    at some known 'init' priority X that itself calls
    add_action('init', ..., Y) with Y < X — such a callback would NEVER
    fire in the same pass (its priority has already been passed), and
    would only run on some hypothetical NEXT do_action('init') that
    never comes in a single request. Neither CLMS_CPT's constructor
    (add_action('init', ..., 5)/6), always Y >= X since the loader boots
    it from init:1) nor the new structural bootstrap (top-level, not
    nested at all) exhibit this specific dangerous pattern — this check
    exists so a future change can't silently reintroduce it.
    """
    cpt_text = read('includes/class-cpt.php')
    instructor_text = read('includes/class-instructor.php')

    # CLMS_CPT's own registrations must be priority >= 1 (the priority
    # CLMS_Loader::boot() itself runs at) -- both 5 and 6 qualify.
    for m in re.finditer(r"add_action\(\s*'init',\s*array\(\s*\\?\$this,\s*'(\w+)'\s*\)\s*,\s*(\d+)\s*\)", cpt_text):
        method, priority = m.group(1), int(m.group(2))
        if priority < 1:
            log_fail('hook-order', f'CLMS_CPT registers {method}() at init priority {priority}, earlier than the priority (1) CLMS_Loader::boot() itself runs at — this callback could never fire via nested same-pass registration')
            return

    # CLMS_Instructor's add_action('init', [$this, 'register_rewrite']) has
    # no explicit priority (defaults to 10) -- also >= 1, safe by the same
    # reasoning, and additionally covered directly by the new structural
    # bootstrap regardless.
    if not re.search(r"add_action\(\s*'init',\s*array\(\s*\\?\$this,\s*'register_rewrite'\s*\)\s*\)", instructor_text):
        log_fail('hook-order', "CLMS_Instructor's constructor no longer registers register_rewrite() on 'init' at all")
        return

    log_pass('hook-order', "no Atora routing component registers an 'init' callback at a priority earlier than the context it would be instantiated from; the structural bootstrap additionally makes this moot for CLMS_CPT/CLMS_Instructor by calling the registration methods directly rather than relying on hook nesting")


# ── 13: no 6.5.11 performance fix was reverted ──────────────────────────

def check_no_performance_regression():
    guard_text = read('includes/admin-menu/class-menu-debug-guard.php')
    if 'ATORA_DEBUG_ADMIN_AUDIT' not in guard_text:
        log_fail('13-performance-regression', 'the 6.5.11 admin-menu diagnostic explicit opt-in gate (ATORA_DEBUG_ADMIN_AUDIT) is missing — the diagnostic storm fix was reverted')
        return
    if 'is_own_slug' not in guard_text:
        log_fail('13-performance-regression', 'the 6.5.11 ATORA-owned-slug scoping for the admin-menu diagnostic is missing')
        return

    bootstrap = read('atora_lms.php')
    flush_count = len(re.findall(r'flush_rewrite_rules\(', bootstrap)) - len(re.findall(r'//.*flush_rewrite_rules\(', bootstrap))
    if flush_count > 3:
        log_fail('13-performance-regression', f'found {flush_count} flush_rewrite_rules() call sites, more than the expected 3 (activation, deactivation, versioned migration) — a new unconditional flush may have been introduced')
        return

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
        text = read(rel)
        has_show_tables = 'SHOW TABLES LIKE' in text
        has_version_gate = bool(re.search(r'SCHEMA_VERSION|schema_version|OPT_TABLES_CONFIRMED', text))
        if not (has_show_tables or has_version_gate):
            ungated.append(rel)
    if ungated:
        log_fail('13-performance-regression', f'schema-DDL gating regressed in: {ungated}')
        return

    log_pass('13-performance-regression', 'all 6.5.11 performance fixes (admin-diagnostic opt-in gate + slug scoping, rewrite-flush lifecycle, schema-DDL gating) remain intact')


def main():
    check_structural_bootstrap_is_top_level()
    check_course_cpt_deterministic_registration()
    check_instructor_deterministic_registration()
    check_loader_visibility_fix_intact()
    check_rewrite_flush_lifecycle()
    check_no_registered_earlier_priority_than_current()
    check_no_performance_regression()

    print()
    print(f'{len(PASSES)} passed, {len(FAILURES)} failed')
    return 1 if FAILURES else 0


if __name__ == '__main__':
    sys.exit(main())
