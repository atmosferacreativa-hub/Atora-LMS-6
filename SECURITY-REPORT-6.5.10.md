# SECURITY REPORT — Atora LMS 6.5.10 "Production Hotfix"

## 1. Executive Summary

```
Version: 6.5.10
Base:    6.5.9 (fix/6.5.9-cierre-estatico, commit b78d71c)
Branch:  fix/6.5.10-production-hotfix
Build:   atora-lms-6.5.10-production-hotfix.zip
Status:  READY FOR PRODUCTION TEST
```

This sprint's mandate was a stability/production hotfix, not a
security audit: fix the six named production regressions (PHP
fatals, a missing runtime table, a MySQL/MariaDB index-length error,
and unreliable custom WP-Cron interval registration), preserve every
prior security control unchanged, and eliminate other instances of
the same root-cause patterns wherever the mandated repository-wide
audits found them. No unrelated features were introduced.

Two of the six named bugs turned out, on inspection, to be more
widespread than their single reported instance: PT-1's namespace
pattern recurred in five other files, and the visibility-audit and
table-inventory audits each surfaced one additional real defect of
the same class (a private-method external call in `CLMS_Maintenance`,
and four wholly orphaned tables belonging to an unrelated CRM v2
feature). All are fixed and covered by this report's evidence gate.

## 2. Original 6.5.9 production regressions

| # | Symptom (as reported) | Confirmed? |
|---|---|---|
| PT-1 | Fatal: `Class "ATORA\CLMS_Academic_Messaging_Bridge" not found` | Yes |
| PT-2 | Fatal: `Call to protected method CLMS_Loader::resolve_file_path() from scope CLMS_Instructor` | Yes |
| PT-3 | Missing `atora_lms_parity_reads` table at runtime | Yes |
| PT-4 | `Specified key was too long; max key length is 1000 bytes` on `atora_course_terms` | Yes |
| PT-5 | `wp_schedule_event()` → `invalid_schedule` for `every_5_minutes`/`every_15_minutes` consumers | Yes |
| PT-6 | Repeated/non-idempotent DDL (`Table '...' already exists`) | See section 3 — investigated, no reproducible defect of this description found; see below |

## 3. Root cause for each

**PT-1 — namespace resolution.** `CLMS_Academic_Messaging_Bridge` is a
global (non-namespaced) class
(`includes/academic/class-academic-messaging-bridge.php`).
`modules/class-v5-modules.php` declares `namespace ATORA;`. An
unqualified static reference (`CLMS_Academic_Messaging_Bridge::init()`)
is resolved by PHP **at compile time** to
`ATORA\CLMS_Academic_Messaging_Bridge`, which does not exist — fatal
on every request where the messaging module loads. The preceding
`class_exists( 'CLMS_Academic_Messaging_Bridge' )` guard passed
anyway: a bare string argument to `class_exists()` is never
namespace-resolved, only the actual `Foo::bar()` syntax is — the guard
and the real call silently disagreed.

**PT-2 — method visibility.** `CLMS_Loader_Modules_Trait::resolve_file_path()`
is `protected`. `CLMS_Instructor::maybe_load_profile_template()`
obtained the shared loader instance via `clms_core('CLMS_Loader')` and
called `resolve_file_path()` directly from an unrelated class. The
existing `method_exists( $loader, 'resolve_file_path' )` guard did not
help — `method_exists()` does not distinguish visibility, so it
returned `true` immediately before the fatal.

**PT-3 — orphaned runtime table.** `LMS_Parity::ensure_table()` only
ran from `ATORA_LMS_Migration_Admin::init()` on the `init` hook. If
any earlier fatal in the same bootstrap chain aborted the request —
and PT-1's fatal sits earlier in that exact same chain
(`atora_lms.php` requires `class-v5-installer.php` → `class-v5-modules.php`
→ `class-lms-migration-admin.php`, in that order, all inside the same
`init` callback) — `ensure_table()` never ran, and the table was never
created. No official install/upgrade path covered it independently of
that hook succeeding.

**PT-4 — utf8mb4 index overflow.** `atora_course_terms`'s
`uq_course_tax_slug(course_id, taxonomy(80), term_slug(200))` sums to
`8 + (80×4) + (200×4) = 1128` bytes under utf8mb4 (4 bytes/char) — over
both the reported 1000-byte limit and the stricter 767-byte historical
one still enforced on some MySQL/MariaDB configurations.

**PT-5 — cron schedule availability.** Six different modules
(`Calendar_Sync`, `LMS_Migration_Admin`, `Live_Streaming`,
`Automation_Engine`, `Email_Queue`, `Messaging_Router`) each
independently registered their own `add_filter('cron_schedules', ...)`
inside their own `init()`. `V5_Modules` loads v5 modules
*conditionally per request context* ("admin / public / cron, para
minimizar la huella de memoria" — its own docblock). A request context
that loads the module needing `every_5_minutes`/`every_15_minutes`
without also loading the *specific* module that registers it hits
`invalid_schedule`.

**PT-6 — investigated, not independently reproducible as described.**
Every `CREATE TABLE`/`dbDelta()` call site in the codebase
(`CLMS_DB_Migration`, the enrollment-manager invitations trait,
`V5_Installer`, `DB_Service`, `LMS_Parity`, `Digest_Store`) was traced
individually; all six are already gated by either a
`SHOW TABLES LIKE` pre-check, a schema-version option, or both — none
issue a raw, unconditional `CREATE TABLE` (WordPress's `dbDelta()`
itself never re-issues a literal `CREATE TABLE` against a table it
detects already exists — it computes `ALTER` statements instead — so a
genuine "table already exists" MySQL error is not reproducible from
any code path found in this repository). The one real inefficiency
found in this area — `LMS_Parity::ensure_table()` running two
`SHOW TABLES LIKE` queries on *every single request* rather than only
until confirmed — was closed as part of PT-3's fix (see section 4).
No fabricated fix was applied for a defect that could not be
evidenced; see section 19.

## 4. Files modified

| File | Bug(s) |
|---|---|
| `modules/class-v5-modules.php` | PT-1 |
| `modules/lms/class-lms-section-service.php` | PT-1 (audit) |
| `modules/lms/class-lms-parity.php` | PT-1 (audit), PT-3 |
| `modules/automation/class-automation-engine.php` | PT-1 (audit) |
| `modules/live-streaming/class-live-streaming.php` | PT-1 (audit) |
| `modules/crm-v2/rest/class-contact-actions-rest-controller.php` | PT-1 (audit) |
| `includes/class-loader.php` | PT-2 |
| `includes/class-instructor.php` | PT-2 |
| `includes/class-maintenance.php` | PT-2 (audit) |
| `includes/admin-menu/trait-admin-menu-widgets-and-hubs.php` | PT-2 (audit) |
| `modules/class-v5-installer.php` | PT-3, PT-4 |
| `modules/messaging/class-telegram-bot.php` | *(none — see note)* |
| `atora_lms.php` | PT-5, version bump |
| `modules/crm-v2/services/class-db-service.php` | PT-6 (audit finding — orphaned Sequence_Service tables) |

Note: `modules/messaging/class-telegram-bot.php` was touched in the
prior 6.5.9 sprint, not this one — listed in git history only, not
part of this diff; included here to avoid ambiguity since it shares a
directory with several 6.5.10 changes.

## 5. Exact fixes implemented

**PT-1.** Qualified every unqualified global-`CLMS_*` static
call/instantiation found inside a namespaced file with a leading
backslash (`\CLMS_Academic_Messaging_Bridge::init()`,
`\CLMS_Helper::enroll_user_in_course(...)`,
`\CLMS_Helper::get_course_lessons(...)`,
`\CLMS_Helper::get_course_students(...)`, `new \CLMS_AI_Manager()`).
No legacy class was migrated into a namespace — the OT's own
constraint ("Do not migrate all legacy classes into namespaces in
this hotfix").

**PT-2.** Added `CLMS_Loader::get_template_path( $file )`, a narrow
public wrapper delegating to the still-`protected`
`resolve_file_path()`; updated both call sites in
`CLMS_Instructor::maybe_load_profile_template()`. Same pattern reused
for the audit-found `CLMS_Maintenance::get_db_stats_public()` wrapper
around the still-`private` `get_db_stats()`, called externally from
`trait-admin-menu-widgets-and-hubs.php`.

**PT-3.** Wired `LMS_Parity::ensure_table()` into
`V5_Installer::install()`/`force_install()` (the proven,
version-gated activation+upgrade mechanism already used for every
other v5 table) via a new private `ensure_parity_tables()` step;
bumped `V5_Installer::SCHEMA_VERSION`. Reused the existing
`ensure_table()` rather than duplicating its schema. Added runtime
resilience: `log_read()`/`log_if_diff()` now catch `\Throwable` and
log at most once per request; `ensure_table()` itself now
short-circuits via a `atora_lms_parity_tables_confirmed` option
instead of running two `SHOW TABLES` queries on every request.

**PT-4.** Reduced `atora_course_terms`'s composite index prefixes —
`taxonomy(80)→taxonomy(32)`, `term_slug(200)→term_slug(150)` — bringing
both `uq_course_tax_slug` and `idx_taxonomy_slug` to 736/728 bytes,
safely under even the 767-byte historical limit. Column widths
(`VARCHAR(100)`/`VARCHAR(200)`) unchanged — only the index prefix
length. The mandated index audit found two more borderline
single-column indexes (`atora_courses.slug(200)`,
`atora_programs.slug(200)`, 800 bytes each — under the reported
1000-byte ceiling but over the 767-byte one) and reduced both to
`slug(150)` (600 bytes) preventively.

**PT-5.** Added one centralized, unconditional
`add_filter('cron_schedules', ...)` at top-level scope in
`atora_lms.php` — outside any conditionally-loaded module, so it runs
in every request context regardless of which module subset
`V5_Modules` decides to load. Registers the same two schedule names
with the same interval values already in use — no persisted schedule
ID renamed. Left the six existing per-module registrations in place
(redundant, harmless, identical values).

**PT-6 (audit finding).** Added `CREATE TABLE` statements for four
tables belonging to `Sequence_Service` (CRM v2's email drip-sequence
feature, active since 5.28.0) that had never had an installer at all
— see section 6. Also fixed a latent ordering bug this exposed in
`DB_Service::maybe_install_schema()` (see section 9).

## 6. Namespace audit

Every production file declaring a `namespace` (89 files, `tests/`
excluded) was grepped for `CLMS_[A-Za-z_]+` and every hit classified:

- **SAFE**: already backslash-qualified (`\CLMS_Helper::...`), a
  `class_exists()`/`method_exists()` string argument (never
  namespace-resolved), or a comment/docblock.
- **BUG**: an unqualified static call/instantiation of a global
  `CLMS_*` class from inside a namespaced file — six found (the
  reported one plus five more), all fixed, see section 5.
- **POTENTIAL BUG**: none remaining after triage — every ambiguous hit
  resolved to one of the two categories above on inspection.

`tests/6.5.10/run_static_checks.py::check_unqualified_clms_refs`
encodes this same check and passes (0 findings) against the fixed
tree; it was also run against the pre-fix commit and correctly found
all 6.

## 7. Visibility audit

Cross-referenced every `method_exists( $obj, 'name' )` call in the
codebase (432 call sites) against every `protected`/`private` method
declared anywhere (1012 declarations). 22 candidates shared a method
name with some protected/private declaration; each was individually
resolved to the *actual* class of the calling object:

- **21 false positives** — in every case the object variable is an
  instance of a *different, unrelated* class that happens to declare a
  **public** method of the same name (e.g. `Commerce_Hooks`' own
  `protected add_order_note()` vs. `WC_Order`'s public
  `add_order_note()`; `Gamification_Core`'s own `protected get_rules()`
  vs. `CLMS_Gamification_Rules`' public `get_rules()`). Coincidental
  name collisions, not visibility violations — each verified by
  locating the object's actual instantiation site and confirming the
  target class's declared visibility.
- **1 real bug**: `CLMS_Maintenance::get_db_stats()` is `private`;
  `trait-admin-menu-widgets-and-hubs.php` instantiates
  `CLMS_Maintenance` directly and calls it externally. Fixed per
  section 5.

`tests/6.5.10/run_static_checks.py::check_external_protected_calls`
covers the `CLMS_Loader` half of this audit (PT-2's own class) and
passes; the broader 22-candidate cross-reference is documented here
rather than re-encoded as a generic tool, since it required per-case
class-identity judgment a regex cannot make reliably.

## 8. Database/table inventory

Built two lists: every table referenced at runtime
(`{$wpdb->prefix}name` / `$wpdb->prefix . 'name'`, 72 distinct names
excluding `usermeta`) and every table with an actual `CREATE TABLE`
statement anywhere in the codebase. Cross-referencing them found:

- `atora_lms_parity_reads` (and its sibling `atora_lms_parity_log`) —
  the PT-3 finding, fixed.
- Four more, entirely unrelated to PT-3: `atora_email_sequences`,
  `atora_email_sequence_steps`, `atora_email_sequence_enrollments`,
  `atora_email_suppression` — all four used continuously by
  `Sequence_Service` (`modules/crm-v2/services/class-sequence-service.php`,
  CRM v2's email drip-sequence feature) since 5.28.0, with **no
  installer anywhere**. Unlike `LMS_Parity`, `Sequence_Service`
  already guards every method with `table_exists()` before querying,
  so this never produced a fatal — the entire feature was simply
  silently inert on every installation. Still a genuine orphaned
  runtime table per this hotfix's own BLOCKER criteria. Fixed in
  `DB_Service` (section 5), with column definitions inferred directly
  from `Sequence_Service`'s actual inserts/updates/selects, not
  redesigned.

`tests/6.5.10/run_static_checks.py::check_runtime_table_coverage`
passes (72/72 covered) against the fixed tree; see its docstring for
one disclosed heuristic limitation in how it was verified against the
pre-fix commit.

## 9. Installer coverage

- `V5_Installer::install()`/`force_install()` (activation + every-request
  upgrade-in-place, version-gated) now also creates the LMS parity
  tables (PT-3) and the corrected `atora_course_terms` schema (PT-4).
- `DB_Service::maybe_install_schema()` (CRM v2, same pattern) now also
  creates the four `Sequence_Service` tables (PT-6). A latent ordering
  bug this exposed was fixed in the same file:
  `ensure_runtime_columns()` (which `ALTER`s 12 tables to add a
  multi-tenant `academy_id` column) ran **before** the table-creation
  block. On the exact deploy where a table is first created,
  `ensure_runtime_columns()` would see it as not-yet-existing, skip
  it, and still mark the columns-version as up to date — silently
  starving that table of `academy_id` forever after. Swapped the
  order: create missing tables first, then check columns.
- `CLMS_DB_Migration`, the enrollment-manager invitations trait, and
  `Digest_Store` were all audited and found already correctly
  version-gated — no change needed.

## 10. SQL/index compatibility audit

Every `KEY`/`UNIQUE KEY` definition with a column-prefix length
(`column(N)`) in `modules/class-v5-installer.php` was extracted and its
worst-case utf8mb4 byte length computed (`N × 4` per prefixed column +
8 bytes per unprefixed `BIGINT` column in the same index). Six
composite/single-column prefixed indexes found; after PT-4's fix, all
six are under 1000 bytes (the reported ceiling), five of the six
already were, and the two touched are now also under the stricter
767-byte historical limit. `DB_Service`'s CRM v2 schema
(`modules/crm-v2/services/class-db-service.php`) has no
column-prefix indexes at all — not applicable.
`tests/6.5.10/run_static_checks.py::check_index_lengths` encodes this
and passes.

## 11. Cron audit

Confirmed, by reading `atora_lms.php` top-level (unconditional) code,
that the new centralized `cron_schedules` filter registers both
`every_5_minutes` and `every_15_minutes` outside any
`atora_lms_require_module(...)` conditional callback —
`tests/6.5.10/run_static_checks.py::check_cron_schedule_registration`
verifies this structurally (no unmatched enclosing require-module
block precedes the filter registration) and passes. No persisted
cron schedule ID was renamed anywhere in this diff.

## 12. Fresh-install validation

Not executable end-to-end in this environment (no WordPress/MySQL
runtime — see section 14). Statically confirmed instead: every table
this sprint's diff touches or adds
(`atora_lms_parity_log`/`_reads`, the corrected `atora_course_terms`,
and the four `Sequence_Service` tables) is reachable from
`atora_lms_activate()` (the real `register_activation_hook()` target)
via `V5_Installer::install()`/`DB_Service::maybe_install_schema()`,
both already proven, version-gated activation paths used by every
other table in the plugin.

## 13. Upgrade validation

See `tests/6.5.10/README.md`'s "10 — upgrade 6.5.9 → 6.5.10" section
for the full traced path. Summary: the existing "Bug #4 fix"
`plugins_loaded` hook in `atora_lms.php` already deletes
`atora_crm_v2_schema_version`/`atora_db_columns_version` on any
version bump, and `V5_Installer::SCHEMA_VERSION`/`DB_Service::SCHEMA_VERSION`
were both bumped in this sprint — both installers will re-run on the
very next request after a 6.5.9 → 6.5.10 deploy, with no reactivation
required. No `DROP TABLE`, no destructive migration, no cron schedule
ID renamed anywhere in this diff.

## 14. PHP lint

```
PHP version: NOT EXECUTED (no PHP interpreter available in this environment — disclosed
             constraint, unchanged across every sprint in this engagement; `php -v` →
             "command not found: php")
Files checked (brace/paren balance proxy): 494
PASS (balanced): 483
FAIL (imbalanced): 11 — the same pre-existing Spanish-prose false positives carried
             forward from every prior sprint's report (uninstall.php, class-sentiment.php,
             class-ai-exams.php, class-transcription.php, class-clms-feedback-loop.php,
             commerce/class-commerce-customer.php, ai/class-ai-extractor.php,
             lms-migrator.php, crm-v2/views/admin.php, email-engine/views/test-send.php,
             tests/Messaging/PhoneVerificationInvalidationTest.php) — none touched
             destructively by this sprint's diff, none newly introduced
```

`vendor/bin/phpcs`/`vendor/bin/phpunit`: NOT EXECUTED (no PHP
interpreter, no Composer dependencies installed).

## 15. Security regression results

```
git diff fix/6.5.9-cierre-estatico...fix/6.5.10-production-hotfix --stat
```
13 files changed, all on the stability side of the codebase (bootstrap,
installer, loader visibility, cron registration, CRM v2 schema). Zero
matches for `permission_callback`, `current_user_can`,
`check_ajax_referer`, `check_admin_referer`, or `wp_verify_nonce`
anywhere in the added/removed diff lines. No file under
`modules/security/`, no rate-limiter file
(`includes/class-atora-rate-limiter.php`, `includes/class-atora-client-ip.php`),
no REST permission-callback file, and no authentication file appears
in the changed-file list. Every prior sprint's hardening (REST
ownership, IDOR protections, nonce checks, capability checks, role/
student/instructor/affiliate boundaries, rate limiting, trusted
proxies, `X-Forwarded-For` handling, Telegram/webhook integrity,
upload validation, sanitization/escaping, `$wpdb->prepare()` usage) is
untouched by this diff.

## 16. Malware/secret scan results

```
Execution primitives (eval/base64_decode/gzinflate/shell_exec/exec/system/
passthru/proc_open/popen/assert/create_function), added lines only:
  1 match — tests/6.5.10/MessagingBridgeResolutionTest.php's own eval() call,
  defining a trivial dummy class inline for a namespace-resolution
  demonstration test, explicitly phpcs:ignore'd, and excluded from the
  distribution ZIP by .distignore (tests/ is never packaged).

Secrets (password/passwd/secret/api_key/apikey/token/Bearer/PRIVATE KEY/
BEGIN RSA/BEGIN OPENSSH), added lines only: 0 matches.

Extracted ZIP re-scan: 2 pre-existing, unrelated base64_decode() matches
  (modules/email-engine/class-email-engine.php's webhook signature decode,
  modules/messaging/class-messaging-preferences.php's unsubscribe-token
  decode) -- both legitimate, both use the strict-mode `true` parameter,
  neither touched by this sprint's diff.
```

## 17. External/non-Atora warnings

Not investigated this sprint — none were reported in this hotfix's
own OT (duplicated `SCRIPT_DEBUG`/`WP_MEMORY_LIMIT`, deprecated theme
sidebar warnings, missing Elementor files, LiteSpeed media warnings
were listed as *possible* categories to exclude from scope, not as
findings actually observed this round). No WordPress core, wp-config,
theme, or third-party plugin file was modified.

## 18. Remaining risks

```
LOW: unchanged from 6.5.9 -- no explicit Telegram "unlink" self-service endpoint;
     ATORA_Client_IP's conservative default (loopback-only trusted proxy) still
     requires explicit configuration behind a reverse proxy on a private IP.

MEDIUM: none newly introduced. One item to flag for the next static-review round
     (not a blocker, not touched here per this hotfix's own scope limits):
     five of the six add_filter('cron_schedules', ...) registrations still
     duplicated per-module (harmless today, identical values) -- worth
     collapsing to just the new centralized one in a future cleanup sprint,
     documented here rather than acted on, per this hotfix's "minimal
     compatible changes" constraint.

HIGH: none open.
BLOCKER: none open.
```

## 19. Tests not executable in current environment

- `tests/6.5.10/InstallerSchemaTest.php`,
  `ParityTableLifecycleTest.php`, `MessagingBridgeResolutionTest.php`
  — PHPUnit, require a PHP interpreter (none available here).
- Item 10 (upgrade 6.5.9 → 6.5.10) — requires a real, populated
  WordPress/MySQL installation; traced manually instead against the
  actual code paths (section 13).
- Item 12 (security regression) as a *runnable test* — evidenced
  instead via `git diff --stat` + targeted grep against the branch
  diff (section 15), which is the correct tool for a regression that
  is fundamentally "did anything change," not a behavior to unit-test.
- Full end-to-end fresh-install / plugin-activation / frontend /
  wp-admin / REST / AJAX / cron bootstrap (OT's "Fresh Install
  Validation" list) — no WordPress runtime in this environment;
  substituted with the static/structural checks in sections 6–11 and
  explicitly not claimed as production-validated (see section 20).
- PT-6's own investigation (section 3) is a negative result, not an
  executed test — documented as such rather than fabricated.

## 20. Final status

**READY FOR PRODUCTION TEST.**

All six named regressions are addressed: five with a direct code fix
(PT-1 through PT-5) and PT-6 investigated to a documented negative
result, with the one real inefficiency in that area folded into PT-3's
fix. Two mandated repository-wide audits (namespace, visibility) and
one table inventory found and fixed three additional instances of the
same root-cause patterns beyond the six originally named bugs. Zero
security-relevant lines touched, confirmed by diff. No PHP fatal,
parse error, illegal protected/private access, unresolved schema
mismatch, or known-incompatible index remains in the code this sprint
touched, to the extent verifiable by static analysis in an environment
with no PHP interpreter and no WordPress/MySQL runtime — that
limitation is disclosed throughout this report rather than
papered over with an unverified "production ready" claim, per this
hotfix's own instruction not to use that phrase without an actual
production validation and external security review, neither of which
occurred here.

## 21. SHA-256 of final ZIP

```
f44ad2ee07fa832123058c72069905d310c6ea1e0aa32714f04fa3a90ea4ca64  atora-lms-6.5.10-production-hotfix.zip
```
