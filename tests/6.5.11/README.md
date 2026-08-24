# tests/6.5.11/ — regression suite for the runtime/rewrite hotfix

Maps to the 14-item minimum test list from the 6.5.11 OT.

| # | Area | Where | Executed in this environment? |
|---|---|---|---|
| 01 | course-post-type-registration | `run_static_checks.py::check_course_post_type_registration` | **Yes** |
| 02 | course-permalink-generation | `RewriteResolutionTest.php::test_course_rewrite_slug_and_archive_slug_agree` | Logic hand-verified with Python against the real source (see below) — PHPUnit itself not executable, no PHP interpreter |
| 03 | course-rewrite-resolution | same as 02 | same |
| 04 | instructor-route-registration | `run_static_checks.py::check_instructor_route_registration` | **Yes** |
| 05 | instructor-permalink-generation | `RewriteResolutionTest.php::test_instructor_rewrite_target_matches_registered_query_var` | Hand-verified, not PHPUnit-executed |
| 06 | instructor-rewrite-resolution | `RewriteResolutionTest.php::test_instructor_rewrite_regex_matches_the_historical_url_shape` | Hand-verified, not PHPUnit-executed |
| 07 | rewrite-flush-lifecycle | `run_static_checks.py::check_rewrite_flush_lifecycle` | **Yes** |
| 08 | no-runtime-flush | `run_static_checks.py::check_no_unconditional_runtime_flush` | **Yes** |
| 09 | admin-diagnostics-disabled-by-default | `run_static_checks.py::check_admin_diagnostics_gate` + `check_orphan_scan_scoped_to_own_slugs` | **Yes** |
| 10 | admin-diagnostics-explicit-only | same as 09 | **Yes** |
| 11 | no-runtime-schema-ddl | `run_static_checks.py::check_no_runtime_schema_ddl` | **Yes** (re-verification of the 6.5.10 fix) |
| 12 | runtime-log-noise | `run_static_checks.py::check_runtime_log_noise` | **Yes** |
| 13 | security-regression | see below | Diff-based, not a runnable test |
| 14 | upgrade-6510-to-6511 | see below | No — needs a real, populated WordPress/MySQL install |

## Running the static checks now

```
python3 tests/6.5.11/run_static_checks.py
```

No dependencies beyond Python 3's standard library. Verified against
the pre-fix `fix/6.5.10-production-hotfix` commit (`git worktree`, ad
hoc, not retained in the tree): correctly **failed 4 of its 4
fixable categories** there (rewrite-flush-lifecycle, no-runtime-flush,
admin-diagnostics-explicit-only, admin-diagnostics-disabled-by-default),
and passes cleanly (8/8) against the fixed tree — proof the checks are
meaningful, not tautological.

## 02/03/05/06 — permalink generation & rewrite resolution

`RewriteResolutionTest.php` is written as a real PHPUnit test (no
WordPress bootstrap needed — it reads the actual production source
files and checks the literal regex/slug values with plain PCRE, not
WP_Rewrite), but PHPUnit itself cannot run here (no PHP interpreter —
`php -v` → `command not found: php`, the disclosed constraint carried
through this entire engagement). Every assertion in it was
independently hand-verified with an equivalent Python `re` script
against the real `includes/class-instructor.php` and
`includes/class-cpt.php` files before being committed:

```
pattern: ^docentes/([^/]+)/?$
matches docentes/maria-perez/: <Match>, group1: maria-perez
extra-segment match (should be none): None
target: index.php?clms_instructor=$matches[1]
has clms_instructor query var registration: True
has_archive: cursos
rewrite.slug: cursos
```

Full end-to-end resolution (an actual HTTP request against
`/docentes/maria-perez/` returning 200 with the right `$wp_query`
state) needs a real WordPress/MySQL runtime and was not executable
here — see item 14.

## 13 — security regression

No security control (permission_callback, ownership check, nonce,
capability check, rate limiter, IP trust boundary, sanitization/
escaping, `$wpdb->prepare()`) was touched by this hotfix's diff.
Verified by running
`git diff fix/6.5.10-production-hotfix...fix/6.5.11-runtime-rewrite-hotfix --stat`
and confirming the file list contains no `modules/security/`,
`includes/class-atora-*` rate-limiting, REST permission-callback, or
authentication file, plus a targeted grep for
`permission_callback|current_user_can|check_ajax_referer|check_admin_referer|wp_verify_nonce`
across the added/removed diff lines (zero matches). See
`RUNTIME-REPORT-6.5.11.md` for the full accounting.

## 14 — upgrade 6.5.10 → 6.5.11

Not executable here — requires a real WordPress install with an
existing 6.5.10 database (including its own `atora_v5_schema_version`/
`atora_crm_v2_schema_version` state from the 6.5.10 sprint) and a
populated `rewrite_rules` option to actually demonstrate stale-cache
recovery. Manually traced instead — see
`RUNTIME-REPORT-6.5.11.md` section 14.
