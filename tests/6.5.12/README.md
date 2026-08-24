# tests/6.5.12/ — regression suite for the routing/bootstrap fix

Maps to the 15-item minimum test list from the 6.5.12 OT, plus the
explicitly-required static hook-order regression guard.

| # | Area | Where | Executed in this environment? |
|---|---|---|---|
| 01 | bootstrap-order | `run_static_checks.py::check_structural_bootstrap_is_top_level` | **Yes** |
| 02 | course-cpt-exists-after-init | `run_static_checks.py::check_course_cpt_deterministic_registration` | **Yes** |
| 03 | course-rewrite-registered | same as 02 | **Yes** |
| 04 | course-valid-url | `StructuralRouteRegistrationTest.php::test_structural_bootstrap_calls_the_real_cpt_registration_method` | Hand-verified with Python against real source; PHPUnit itself not executable (no PHP interpreter) |
| 05 | course-invalid-url | see below | Not independently testable without a WordPress runtime — see note |
| 06 | instructor-rewrite-registered | `run_static_checks.py::check_instructor_deterministic_registration` | **Yes** |
| 07 | instructor-valid-url | `StructuralRouteRegistrationTest.php::test_structural_bootstrap_calls_the_real_instructor_rewrite_method` | Hand-verified, not PHPUnit-executed |
| 08 | instructor-invalid-url | see below | Not independently testable without a WordPress runtime |
| 09 | query-vars | `run_static_checks.py::check_instructor_deterministic_registration` | **Yes** |
| 10 | template-routing | `run_static_checks.py::check_loader_visibility_fix_intact` | **Yes** |
| 11 | one-time-rewrite-flush | `run_static_checks.py::check_rewrite_flush_lifecycle` | **Yes** |
| 12 | no-runtime-flush | same as 11 | **Yes** |
| 13 | performance-regression | `run_static_checks.py::check_no_performance_regression` | **Yes** |
| 14 | security-regression | see below | Diff-based, not a runnable test |
| 15 | upgrade-6511-to-6512 | see below | No — needs a real, populated WordPress/MySQL install |
| hook-order | explicit regression guard | `run_static_checks.py::check_no_registered_earlier_priority_than_current` | **Yes** |

## Running the static checks now

```
python3 tests/6.5.12/run_static_checks.py
```

No dependencies beyond Python 3's standard library. Verified against
the pre-fix `fix/6.5.11-runtime-rewrite-hotfix` commit (`git
worktree`, ad hoc, not retained): correctly **failed 4 of its 4
fixable categories** there (bootstrap-order, course-cpt-registration,
instructor-rewrite-registration, the rewrite-version bump check), and
passes cleanly (8/8) against the fixed tree.

## 05/08 — invalid URLs must still 404

Not independently testable in this environment. This sprint added no
new route-matching logic (no new regex, no changed CPT rewrite
structure) — the `/docentes/([^/]+)/?$` pattern and the `lm_course`
CPT's `rewrite.slug` are byte-for-byte unchanged from 6.5.11 (verified:
`git diff fix/6.5.11-runtime-rewrite-hotfix...fix/6.5.12-routing-bootstrap-fix
-- includes/class-cpt.php includes/class-instructor.php` is empty —
neither file was touched this sprint at all). Whatever 404 behavior
those patterns already had for a genuinely non-matching URL is
unaffected by moving *when* the same patterns get registered.

## 14 — security regression

No security control was touched. Verified via
`git diff fix/6.5.11-runtime-rewrite-hotfix...fix/6.5.12-routing-bootstrap-fix --stat`
and a targeted grep for
`permission_callback|current_user_can|check_ajax_referer|check_admin_referer|wp_verify_nonce`
across the added/removed diff lines (zero code matches — see
`ROUTING-REPORT-6.5.12.md`).

## 15 — upgrade 6.5.11 → 6.5.12

Not executable here — requires a real WordPress install with an
existing 6.5.11 database (including its own `atora_lms_rewrite_version`
option already set to `'6.5.11-1'`) to actually demonstrate the
corrective re-flush. Manually traced instead — see
`ROUTING-REPORT-6.5.12.md` section 17.
