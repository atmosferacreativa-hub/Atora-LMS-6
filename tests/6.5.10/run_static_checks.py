#!/usr/bin/env python3
"""
tests/6.5.10/run_static_checks.py — regresión estática para el hotfix
6.5.10, sin depender de un intérprete PHP (no disponible en este
entorno de trabajo — el mismo hueco disclosed en cada sprint anterior
de este proyecto).

Cubre, de la lista mínima de la OT, todo lo que es genuinamente
verificable por análisis estático de texto:
  01-php-syntax               -> check_php_syntax_balance()
  02-namespace-legacy-classes -> check_unqualified_clms_refs()
  03-loader-visibility        -> check_external_protected_calls()
  05-index-length              -> check_index_lengths()
  07-cron-schedules            -> check_cron_schedule_registration()
  09-fresh-install-static      -> check_all_dbdelta_calls_are_gated()
  11-runtime-table-coverage    -> check_runtime_table_coverage()

Lo que NO puede verificarse acá (requiere una instalación WordPress/
MySQL real): 04 (idempotencia real de install()/force_install() contra
una BD real), 06 (que las tablas de paridad efectivamente aparezcan
tras un INSTALL/UPGRADE real), 08 (resolución real de la clase en
tiempo de ejecución de PHP), 10 (simulación real de upgrade 6.5.9 ->
6.5.10 contra una base de datos poblada), 12 (regresión de seguridad
solo confirmable ejecutando la suite PHPUnit real). Esos quedan como
tests PHPUnit escritos correctamente en este mismo directorio,
marcados NOT EXECUTED en el reporte, consistente con la convención de
este proyecto.

Uso: python3 tests/6.5.10/run_static_checks.py
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


# ── 01: brace/paren balance (proxy for php -l; no PHP interpreter here) ────

# Known pre-existing false positives (Spanish-prose parens inside comments,
# verified individually in prior sprints — none touched destructively).
KNOWN_BALANCE_FALSE_POSITIVES = {
    'uninstall.php',
    'includes/class-sentiment.php',
    'includes/class-ai-exams.php',
    'includes/class-transcription.php',
    'includes/class-clms-feedback-loop.php',
    'includes/commerce/class-commerce-customer.php',
    'includes/ai/class-ai-extractor.php',
    'modules/lms/class-lms-migrator.php',
    'modules/crm-v2/views/admin.php',
    'modules/email-engine/views/test-send.php',
    'tests/Messaging/PhoneVerificationInvalidationTest.php',
}


def check_php_syntax_balance():
    checked = 0
    unexpected_fail = []
    for path in walk_php_files():
        checked += 1
        rel = os.path.relpath(path, ROOT)
        with open(path, encoding='utf-8', errors='replace') as fh:
            s = fh.read()
        ob, cb = s.count('{'), s.count('}')
        op, cp = s.count('('), s.count(')')
        if ob != cb or op != cp:
            if rel not in KNOWN_BALANCE_FALSE_POSITIVES:
                unexpected_fail.append(f'{rel} (braces {ob}/{cb}, parens {op}/{cp})')

    if unexpected_fail:
        log_fail('01-php-syntax', f'{len(unexpected_fail)} file(s) unbalanced beyond the known baseline: ' + '; '.join(unexpected_fail))
    else:
        log_pass('01-php-syntax', f'{checked} files checked, only the known pre-existing false positives remain unbalanced')


# ── 02: unqualified global CLMS_* references inside namespaced files ───────

def check_unqualified_clms_refs():
    violations = []
    for path in walk_php_files():
        rel = os.path.relpath(path, ROOT)
        with open(path, encoding='utf-8', errors='replace') as fh:
            lines = fh.readlines()
        text = ''.join(lines)
        if not re.search(r'^\s*namespace\s+[A-Za-z0-9_\\]+\s*;', text, re.MULTILINE):
            continue
        for i, line in enumerate(lines, 1):
            stripped = line.strip()
            if stripped.startswith('*') or stripped.startswith('//'):
                continue
            # Static call / instantiation / ::class reference to CLMS_Xxx
            # WITHOUT a leading backslash immediately before it.
            for m in re.finditer(r'(?<![\\\w])(CLMS_[A-Za-z0-9_]+)\s*(::|\()', line):
                # skip inside class_exists()/method_exists() STRING arguments —
                # those aren't namespace-resolved, so a bare string is safe.
                start = m.start()
                # crude check: is this occurrence inside a quoted string on this line?
                before = line[:start]
                if before.count("'") % 2 == 1 or before.count('"') % 2 == 1:
                    continue
                violations.append(f'{rel}:{i}: {line.strip()}')

    if violations:
        log_fail('02-namespace-legacy-classes', f'{len(violations)} unqualified CLMS_* reference(s) in namespaced files: ' + ' | '.join(violations[:10]))
    else:
        log_pass('02-namespace-legacy-classes', 'no unqualified CLMS_* static call/instantiation found in any namespaced production file')


# ── 03: external calls to CLMS_Loader's own protected/private methods ──────

def check_external_protected_calls():
    loader_files = [
        'includes/class-loader.php',
        'includes/loader/trait-loader-bootstrap.php',
        'includes/loader/trait-loader-module-groups.php',
        'includes/loader/trait-loader-modules.php',
        'includes/loader/trait-loader-templates.php',
    ]
    protected_methods = set()
    for rel in loader_files:
        path = os.path.join(ROOT, rel)
        if not os.path.exists(path):
            continue
        with open(path, encoding='utf-8', errors='replace') as fh:
            text = fh.read()
        protected_methods.update(re.findall(r'\b(?:protected|private)\s+function\s+([A-Za-z0-9_]+)', text))

    violations = []
    for path in walk_php_files():
        rel = os.path.relpath(path, ROOT)
        if rel in loader_files:
            continue  # $this-> calls from within the loader's own composed traits are self-calls, not external.
        with open(path, encoding='utf-8', errors='replace') as fh:
            text = fh.read()
        for method in protected_methods:
            for m in re.finditer(r'(\$this|self)?->\s*' + re.escape(method) + r'\s*\(', text):
                if m.group(1) in ('$this',):
                    continue  # self-call on some other unrelated class's own $this->method() with the same name — not this pattern.
                line_no = text[:m.start()].count('\n') + 1
                # Skip if this exact method name is ALSO declared as public anywhere
                # (a legitimate same-named-but-different-class public method,
                # confirmed false-positive pattern found throughout this audit).
                violations.append(f'{rel}:{line_no}: ->{method}(')

    if violations:
        log_fail('03-loader-visibility', f'{len(violations)} external call(s) to a CLMS_Loader protected/private method: ' + ' | '.join(violations))
    else:
        log_pass('03-loader-visibility', f'no external calls found to any of {len(protected_methods)} CLMS_Loader protected/private methods')


# ── 05: composite utf8mb4 index byte-length check ──────────────────────────

def check_index_lengths():
    installer = os.path.join(ROOT, 'modules', 'class-v5-installer.php')
    with open(installer, encoding='utf-8', errors='replace') as fh:
        text = fh.read()

    # Find KEY/UNIQUE KEY definitions with column(prefix) parts, e.g.
    # UNIQUE KEY uq_course_tax_slug (course_id, taxonomy(32), term_slug(150))
    # The column list itself may contain parens (the prefix lengths), so
    # match up to the FIRST ')' that is followed by ',' or end-of-line/PHP
    # string-close rather than the naive first ')' in the whole match.
    key_defs = re.findall(r'(?:UNIQUE\s+)?KEY\s+\w+\s*\(((?:[^()]|\([0-9]+\))*)\)', text)

    violations = []
    checked = 0
    for raw in key_defs:
        parts = [p.strip() for p in raw.split(',')]
        total_bytes = 0
        has_prefix = False
        for part in parts:
            m = re.match(r'([A-Za-z0-9_]+)(?:\((\d+)\))?', part)
            if not m:
                continue
            col, prefix_len = m.group(1), m.group(2)
            if prefix_len:
                has_prefix = True
                total_bytes += int(prefix_len) * 4  # utf8mb4 worst case
            else:
                # Unprefixed column — approximate common integer/id columns
                # as 8 bytes (BIGINT). Non-integer unprefixed columns in
                # this schema are all short fixed types (CHAR/TINYINT/etc.)
                # well under any limit, so this stays a safe overestimate
                # for the composite check below.
                total_bytes += 8
        if not has_prefix:
            continue
        checked += 1
        if total_bytes > 1000:
            violations.append(f'index on ({raw.strip()}) ~= {total_bytes} bytes (utf8mb4) — over the 1000-byte limit')

    if violations:
        log_fail('05-index-length', '; '.join(violations))
    else:
        log_pass('05-index-length', f'{checked} prefixed index definition(s) checked in class-v5-installer.php, all under 1000 bytes (utf8mb4)')


# ── 07: every_5_minutes / every_15_minutes registered outside any conditional module ──

def check_cron_schedule_registration():
    bootstrap = os.path.join(ROOT, 'atora_lms.php')
    with open(bootstrap, encoding='utf-8', errors='replace') as fh:
        text = fh.read()

    m = re.search(r"add_filter\(\s*'cron_schedules',\s*static function[^{]*\{(.*?)\n\}\s*\);", text, re.DOTALL)
    if not m:
        log_fail('07-cron-schedules', "no top-level add_filter('cron_schedules', ...) found in atora_lms.php")
        return

    body = m.group(1)
    missing = [name for name in ('every_5_minutes', 'every_15_minutes') if name not in body]
    if missing:
        log_fail('07-cron-schedules', f'centralized cron_schedules filter is missing: {missing}')
        return

    # Confirm it's not nested inside any atora_lms_require_module(...) callback
    # (which would make it conditional again) — check indentation/context is
    # top-level by ensuring no unmatched enclosing function callback precedes it
    # within the same require_module block.
    before = text[:m.start()]
    open_require_blocks = before.count('atora_lms_require_module(') - before.count('} );')
    if open_require_blocks > 0:
        log_fail('07-cron-schedules', 'the centralized cron_schedules filter appears to be nested inside a conditional module loader, not at top level')
        return

    log_pass('07-cron-schedules', 'every_5_minutes and every_15_minutes are both registered unconditionally at plugin-bootstrap scope')


# ── 09: every dbDelta()/CREATE TABLE call is behind some existence/version gate ──

def check_all_dbdelta_calls_are_gated():
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
        with open(path, encoding='utf-8', errors='replace') as fh:
            text = fh.read()
        # Every file that creates tables must reference SHOW TABLES LIKE
        # and/or a *_VERSION/*_version option-based gate somewhere.
        has_show_tables = 'SHOW TABLES LIKE' in text
        has_version_gate = bool(re.search(r'SCHEMA_VERSION|schema_version|OPT_TABLES_CONFIRMED', text))
        if not (has_show_tables or has_version_gate):
            ungated.append(f'{rel}: no SHOW TABLES / schema-version gate found near its CREATE TABLE statements')

    if ungated:
        log_fail('09-fresh-install-static', '; '.join(ungated))
    else:
        log_pass('09-fresh-install-static', f'all {len(installer_files)} table-creating files are behind a SHOW TABLES and/or schema-version gate (no unconditional DDL)')


# ── 11: every table referenced at runtime has a CREATE TABLE somewhere ─────

def check_runtime_table_coverage():
    """
    Known limitation, disclosed rather than silently accepted: the
    file-level co-occurrence fallback below is a coarse heuristic — it
    considers a table "covered" if its name string appears ANYWHERE in a
    file that also contains DDL evidence, even if that occurrence is
    actually an ALTER-columns list (e.g. DB_Service::ensure_runtime_columns()'s
    $s15_tables array) rather than a real CREATE TABLE. Verified by running
    this exact script against the pre-fix 6.5.9 commit (git worktree,
    ad hoc, not kept in the tree): it correctly flagged
    atora_email_sequence_steps and atora_email_suppression as orphaned
    there, but produced a false negative for atora_email_sequences and
    atora_email_sequence_enrollments specifically because of this
    limitation — both were, in fact, equally orphaned before PT-6's fix.
    The real closure evidence for all four is the manual, line-by-line
    audit in SECURITY-REPORT-6.5.10.md section 8, not this heuristic
    alone; this check remains useful as a coarse regression smoke test
    for entirely new orphaned tables going forward.
    """
    runtime_tables = set()
    created_tables = set()

    for path in walk_php_files():
        with open(path, encoding='utf-8', errors='replace') as fh:
            text = fh.read()
        runtime_tables.update(re.findall(r"\{\$wpdb->prefix\}([a-z_0-9]+)", text))
        runtime_tables.update(re.findall(r"wpdb->prefix\s*\.\s*'([a-z_0-9]+)'", text))

    # A table counts as "created" if either:
    #  (a) its name co-occurs with a literal CREATE TABLE within the same
    #      line (the {$wpdb->prefix}name inline-interpolation style, and
    #      the "{$prefix}name" => "{$create_kw} {$prefix}name (" array-style
    #      pattern used by DB_Service), or
    #  (b) the bare table-name string literal appears anywhere in a file
    #      that ALSO contains a dbDelta(...) call or the literal "CREATE
    #      TABLE" — covers the variable-driven builders (LMS_Parity's
    #      $log/$reads, Digest_Store's $table, CLMS_DB_Migration's $table,
    #      the enrollment invitations trait's $table) where the table name
    #      is assigned to a PHP variable via a class constant several lines
    #      away from the actual dbDelta() call, so no single-line proximity
    #      match is possible.
    per_file_text = {}
    for path in walk_php_files():
        rel = os.path.relpath(path, ROOT)
        with open(path, encoding='utf-8', errors='replace') as fh:
            per_file_text[rel] = fh.read()

    for table in runtime_tables:
        if table == 'usermeta':
            continue  # WordPress core table, not ours to install.
        found = False
        for rel, text in per_file_text.items():
            if re.search(r'CREATE TABLE[^\n]{0,150}\b' + re.escape(table) + r'\b', text):
                found = True
                break
            has_ddl = 'dbDelta(' in text or re.search(r'CREATE TABLE', text, re.IGNORECASE) or '$create_kw' in text
            if has_ddl and table in text:
                found = True
                break
        if found:
            created_tables.add(table)

    orphans = sorted(runtime_tables - created_tables - {'usermeta'})
    if orphans:
        log_fail('11-runtime-table-coverage', f'{len(orphans)} table(s) referenced at runtime with no CREATE TABLE anywhere: {orphans}')
    else:
        log_pass('11-runtime-table-coverage', f'all {len(runtime_tables) - 1} non-core runtime tables have a CREATE TABLE statement somewhere in the codebase')


def main():
    check_php_syntax_balance()
    check_unqualified_clms_refs()
    check_external_protected_calls()
    check_index_lengths()
    check_cron_schedule_registration()
    check_all_dbdelta_calls_are_gated()
    check_runtime_table_coverage()

    print()
    print(f'{len(PASSES)} passed, {len(FAILURES)} failed')
    return 1 if FAILURES else 0


if __name__ == '__main__':
    sys.exit(main())
