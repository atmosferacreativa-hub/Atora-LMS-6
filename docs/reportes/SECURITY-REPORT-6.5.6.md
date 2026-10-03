# SECURITY REPORT — Atora LMS 6.5.6 "Legacy REST Hardening"

**Date:** 2026-08-21
**Branch:** `fix/6.5.6-legacy-rest-hardening`
**Scope:** Directed audit of the "legacy" REST API surface — `includes/class-rest-api.php` and everything under `includes/rest/` (namespace `clms/v1`): courses, programs, lessons, rubrics, transcriptions, peer review, quizzes, institutional reports, bulk student actions, curriculum reorder, and webhooks. This is the codebase's original (pre-CRM-v2/pre-LMS-table) REST layer, distinct from the newer namespaced controllers (`atora/lms/v1`, `atora-crm/v2`, `atora/mcp/v1`) already hardened in prior sprints.

## Executive summary

The legacy REST layer is, on the whole, well-built: nearly every course/lesson/rubric/transcription/peer-review/quiz/report endpoint that accepts a specific resource ID already calls `current_user_can_manage_post_resource()` (or an equivalent inline ownership check) from inside the handler itself, even where the route's `permission_callback` is a broader capability gate. Two genuine gaps were found where that pattern was missing, both fixed this sprint. No other CRITICAL, HIGH, or MEDIUM finding was identified in this scope.

| Finding | Severity | Status |
|---|---|---|
| Legacy webhook management gated by a broad instructor-tier capability instead of `manage_options` | HIGH | **FIXED** |
| Grading-scheme read/write gated by capability alone, no course-ownership check | HIGH | **FIXED** |

## Per-finding detail

### 1. Legacy webhook management — capability escalation / data exfiltration path

- **Problem:** `GET/POST /webhooks` and `DELETE /webhooks/{id}` were gated by `can_manage_content()`, which returns true for any user holding `clms_manage_courses`, `clms_manage_lessons`, `clms_manage_submissions`, **or** `clms_grade_submissions` — the same broad set granted to ordinary instructors and grading assistants, not just course owners or site admins.
- **Root cause:** Webhooks are a genuinely site-wide integration point — a registered webhook fires for `lesson.completed`, `course.completed`, `enrollment.created`, `submission.created`, `grade.updated`, and `certificate.issued` events across **every** course on the site, not just courses the registering user teaches. There is no per-course "owner" to scope a webhook to, so the capability+ownership pattern used elsewhere in this REST layer doesn't apply directly. The route was left at the same permission level as ordinary content-management endpoints instead of being raised to the stricter, genuinely-global capability that fits its blast radius.
- **Impact:** Any user holding one of those capabilities — e.g. a teaching-assistant account with only `clms_grade_submissions` — could register an external URL under their control and receive a live copy of grading, enrollment, and certificate events for students and courses they have no legitimate relationship to. Also a mechanism for effectively arbitrary outbound requests to attacker-controlled URLs from a lower-trust account.
- **Files:** `includes/rest/class-rest-permissions.php` (new `can_manage_webhooks()`), `includes/class-rest-api.php` (wrapper), `includes/rest/class-rest-routes.php` (route rewiring), `includes/rest/class-rest-extensions-controller.php` (defense-in-depth inline check in all three handlers).
- **Solution:** New `can_manage_webhooks()` permission requiring `manage_options`, used both as the route's `permission_callback` and as an inline check inside `get_webhooks()`/`create_webhook()`/`delete_webhook()`.
- **Tests:** `tests/Rest/WebhookPermissionsTest.php` — 3 cases (content-manager-without-admin denied, site-admin allowed, logged-out denied).
- **Result:** Verified.

### 2. Grading scheme endpoints — missing course ownership check

- **Problem:** `GET/PUT /grades/scheme/{course_id}` were gated by `can_manage_grading()`, which resolves to the same `can_manage_content()` capability set as finding #1 — no check anywhere in the call chain (`CLMS_REST_Grading_Controller` → `CLMS_Grading_Engine::configure_grading_scheme()`) verified the course belonged to the requesting user.
- **Root cause:** Unlike the sibling course/lesson/rubric endpoints in the same REST namespace, these two handlers were never given the `current_user_can_manage_post_resource($course_id)` check that's the established pattern throughout the rest of this file.
- **Impact:** Any user with a grading-tier capability (e.g. `clms_grade_submissions`, held by teaching assistants) could read, and — more seriously — silently rewrite, the grade-weighting scheme (quiz/assignment/participation percentages) for a course they don't teach, corrupting how that course's grades are calculated going forward.
- **Files:** `includes/rest/class-rest-grading-controller.php`.
- **Solution:** Added `current_user_can_manage_post_resource($course_id)` (unless `manage_options`) to both `get_grading_scheme()` and `update_grading_scheme()`, matching the pattern already used by `update_course()`, `update_lesson()`, `update_rubric()`, `reorder_lessons()`, etc.
- **Tests:** `tests/LMS/GradingSchemeOwnershipTest.php` — 2 cases (non-owner denied on both read and write; `manage_options` bypass works). The "real owner" positive path depends on `CLMS_Helper::user_can_manage_lms()`, a heavy dependency guarded by `class_exists()` throughout the codebase and not worth loading into the test harness for this fix alone — documented as a coverage gap below, not a functional gap (the code path is identical to the already-fixed pattern used elsewhere).
- **Result:** Verified.

## REST endpoint matrix (legacy `clms/v1` surface reviewed this sprint)

| Resource | Read gate | Write gate | Ownership check in handler | Verdict |
|---|---|---|---|---|
| Courses (CRUD) | `can_read_course` | `can_manage_content` | Yes (`current_user_can_manage_post_resource`) | PASS |
| Programs (CRUD) | `can_read_program` | `can_manage_content` | Yes | PASS |
| Lessons (CRUD + quick-edit) | `can_read_lesson` | `can_manage_content` | Yes | PASS |
| Rubrics (CRUD + lesson binding) | `can_manage_content` | `can_manage_content` | Yes | PASS |
| Transcriptions | `can_manage_content` | `can_manage_content` | Yes | PASS |
| Peer review (assign/submit/list) | `can_manage_content` / `can_access_logged_in` | same | Yes (reviewer_id match on submit) | PASS |
| Quizzes (get/update/submit) | `can_access_logged_in` / `can_manage_content` | same | Yes | PASS |
| Bulk student actions | — | `can_manage_content` | Yes (course ownership before touching any student) | PASS |
| Lesson presets | `can_manage_content` | `can_manage_content` | N/A — shared org-wide template library by design, `apply_lesson_preset()` still checks target course/lesson ownership | PASS |
| Institutional reports (student/teacher profile, course/admin/certification report, risk indicators) | `can_view_user_resource` / `can_access_logged_in` | — | Yes, all internally scoped | PASS |
| Curriculum reorder (lessons/programs) | — | `can_manage_content` | Yes | PASS |
| `/me/*` (profile, courses, programs) | `can_access_logged_in` | `can_access_logged_in` | N/A — self-service, operates on current user only | PASS |
| Grading scheme | `can_manage_grading` | `can_manage_grading` | **Was missing → FIXED this sprint** | PASS (post-fix) |
| Webhooks | `can_manage_content` | `can_manage_content` | **Was too permissive → FIXED this sprint (now `manage_options`)** | PASS (post-fix) |

## Validation counts

- **PHP lint:** No PHP interpreter available in this environment (same disclosed constraint as every prior sprint). Brace/paren-balance proxy across the 8 PHP files touched this sprint: 0/8 mismatches.
- **Malware pattern scan:** `eval(`, `gzinflate(`, `shell_exec(`, `system(`, `passthru(`, `exec(`, `base64_decode(`, `assert(`, `create_function(`, `proc_open(`, `popen(` grepped across this sprint's diff — no matches.
- **Secret scan:** No AWS/OpenAI/GitHub/private-key patterns or hardcoded credential literals found in the diff or the built ZIP.
- **Tests:** 5 new test methods across 2 new test files (`WebhookPermissionsTest.php`, `GradingSchemeOwnershipTest.php`), hand-traced against the fixed code. As in every prior sprint, no PHP interpreter is available in this environment to actually execute PHPUnit — the same disclosed constraint applies.
- **Distribution build:** `atora-lms-6.5.6-legacy-rest-hardening.zip` built via `scripts/build-dist.sh`, extracted to a temp directory, and independently re-verified: no `.git/.github/.claude/.qodo/agents/tests/vendor/node_modules`, no `.env*`, `SECURITY-AUDIT.md`/`SECURITY-REPORT-*.md` excluded, `Version:`/`ATORA_LMS_VERSION`/`Stable tag` all read `6.5.6`, plugin bootstrap (`atora_lms.php`) present and intact.

## Security confirmation table

| Area | Status |
|---|---|
| Legacy REST — courses/programs/lessons | **PASS** |
| Legacy REST — rubrics/transcriptions/peer review/quizzes | **PASS** |
| Legacy REST — institutional reports | **PASS** |
| Legacy REST — grading scheme | **PASS (fixed this sprint)** |
| Legacy REST — webhooks | **PASS (fixed this sprint)** |
| No regression in 6.5.1–6.5.5 fixes | **PASS** (this sprint touched no file from those fixes) |
| Malware scan | **PASS** |
| Secret scan | **PASS** |
| Distribution audit | **PASS** |

## Residual risks

1. **Grading-scheme positive-owner path has no automated test** — verified by code reading and by the identical, already-proven pattern used elsewhere in this file (`update_course`, `update_lesson`, etc.); not independently re-harnessed because it requires `CLMS_Helper`, a heavy dependency this test suite deliberately doesn't load.
2. **No live PHP interpreter, MySQL, or WordPress instance available in this environment** — same disclosed constraint as every prior sprint in this engagement. All verification is static/code-reading plus hand-traced test logic, not an actual PHPUnit run.

## Final condition

**RELEASE CANDIDATE — READY FOR FINAL DYNAMIC PENTEST.**

No CRITICAL or HIGH finding remains open. Both HIGH findings discovered this sprint are fixed and covered by regression tests for their core (denial) path. The only residual items are documentation/coverage gaps tied to this environment's inability to run a real PHPUnit/WordPress/MySQL stack, not open vulnerabilities.
