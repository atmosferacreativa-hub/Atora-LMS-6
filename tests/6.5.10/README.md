# tests/6.5.10/ — regression suite for the 6.5.10 production hotfix

Maps to the 12-item minimum test list from the 6.5.10 OT.

| # | Area | Where | Executed in this environment? |
|---|---|---|---|
| 01 | php-syntax | `run_static_checks.py::check_php_syntax_balance` | **Yes** — brace/paren balance proxy (no PHP interpreter available) |
| 02 | namespace-legacy-classes | `run_static_checks.py::check_unqualified_clms_refs` | **Yes** |
| 03 | loader-visibility | `run_static_checks.py::check_external_protected_calls` | **Yes** |
| 04 | installer-schema | `InstallerSchemaTest.php` | No — PHPUnit, requires PHP |
| 05 | index-length | `run_static_checks.py::check_index_lengths` | **Yes** |
| 06 | parity-tables | `ParityTableLifecycleTest.php` | No — PHPUnit, requires PHP |
| 07 | cron-schedules | `run_static_checks.py::check_cron_schedule_registration` | **Yes** |
| 08 | messaging-bridge | `MessagingBridgeResolutionTest.php` | No — PHPUnit, requires PHP |
| 09 | fresh-install-static | `run_static_checks.py::check_all_dbdelta_calls_are_gated` | **Yes** |
| 10 | upgrade-659-to-6510 | see below | No — needs a real, populated WordPress/MySQL install |
| 11 | runtime-table-coverage | `run_static_checks.py::check_runtime_table_coverage` | **Yes** |
| 12 | security-regression | see below | Partially — evidenced by the git diff itself, not a runnable test |

## Running the static checks now

```
python3 tests/6.5.10/run_static_checks.py
```

No dependencies beyond Python 3's standard library. Exits 0 if every
check passes, 1 otherwise. This is the same class of substitution
used throughout this engagement for `php -l` — this environment has
no PHP interpreter (`php -v` → `command not found: php`), disclosed
and unchanged across every sprint. The script was verified against
the pre-fix 6.5.9 commit (`git worktree`, ad hoc, not retained in the
tree) and correctly failed 5 of its 6 fully-checkable categories
there — see the module-level checks for links to that verification
and one disclosed heuristic limitation.

## 10 — upgrade 6.5.9 → 6.5.10

Not executable here — requires a real WordPress install with an
existing 6.5.9 database populated with production-shaped data.
Manually traced instead, against the actual code path:

1. `atora_lms.php`'s `plugins_loaded` hook (priority 1) compares
   `atora_lms_plugin_version` against `ATORA_LMS_VERSION`; on the
   6.5.9 → 6.5.10 bump it deletes `atora_crm_v2_schema_version` and
   `atora_db_columns_version`, forcing `DB_Service::maybe_install_schema()`
   to treat both the schema and the columns as stale on the very next
   call.
2. `V5_Installer::install()` (called on every `init`, before
   `V5_Modules::boot()`) compares `atora_v5_schema_version` against
   the new `SCHEMA_VERSION` (`5.1.7-parity-table-and-index-fix`,
   bumped from `5.1.6-telegram-links-table`) — mismatch, so
   `create_tables()` (now with the corrected `atora_course_terms`
   index prefixes) and `ensure_parity_tables()` both run.
3. Existing tables are never dropped — every statement is
   `dbDelta(...)` or `CREATE TABLE IF NOT EXISTS`; the only structural
   changes are two index-prefix-length reductions and one
   never-before-existing table's creation, neither of which touches
   existing rows.
4. No cron schedule ID was renamed, so events already persisted in
   `wp_options`' `cron` array under `every_5_minutes`/`every_15_minutes`
   on an existing 6.5.9 install remain valid without rescheduling.
5. `CLMS_Academic_Messaging_Bridge`/`CLMS_Maintenance` continue to
   resolve correctly for any code that was already reaching them
   successfully pre-fix (this fix only removes the paths that fataled,
   it does not change what a successful resolution returns).

## 12 — security regression

No security control (permission_callback, ownership check, nonce,
capability check, rate limiter, IP trust boundary, sanitization/
escaping, `$wpdb->prepare()`) was touched by this hotfix's diff. Every
file this sprint modified was chosen because it is on the "stability"
side of the codebase (bootstrap, installer, loader visibility, cron
registration) — verified by running
`git diff fix/6.5.9-cierre-estatico...fix/6.5.10-production-hotfix --stat`
and confirming the file list contains no `modules/security/`,
`includes/class-atora-*` rate-limiting, REST permission-callback, or
authentication file. See `SECURITY-REPORT-6.5.10.md` section "Security
regression results" for the full diff-based accounting.
