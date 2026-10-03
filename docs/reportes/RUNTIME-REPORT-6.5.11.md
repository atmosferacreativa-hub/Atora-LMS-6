# RUNTIME REPORT — Atora LMS 6.5.11 "Runtime / Rewrite Hotfix"

## 1. Executive Summary

```
Version: 6.5.11
Base:    6.5.10 (fix/6.5.10-production-hotfix, commit f770cb1)
Branch:  fix/6.5.11-runtime-rewrite-hotfix
Build:   atora-lms-6.5.11-runtime-rewrite-hotfix.zip
Status:  READY FOR PRODUCTION TEST
```

This sprint's mandate was runtime stability only, not new features:
restore course/instructor permalinks after the 6.5.10 update, stop an
admin-menu diagnostic from scanning and logging noise about
third-party plugins on every wp-admin page load, and make the
rewrite-flush lifecycle predictable (registered before flush, flushed
only once per genuine need, never on an ordinary request). Both named
regressions traced to a single unifying root cause for the 404s (a
rewrite-cache lifecycle gap that predates this specific update, only
now visible because 6.5.10 fixed the fatals that were masking
everything downstream) and a separate, independently confirmed root
cause for the diagnostic storm.

## 2. Production symptoms

- White screen gone (6.5.10 fixed the two fatals).
- WordPress loads.
- Site noticeably slow.
- Course links → 404.
- Instructor profile URLs → 404.
- Logs repeatedly show `[ATORA] Slug de menú admin registrado N
  veces` / `[ATORA] Página admin huérfana` for WordPress core and
  third-party pages (WooCommerce, Yoast, LiteSpeed, Site Kit, Action
  Scheduler).
- No new PHP Fatal Error associated with the 404s.

## 3. Root cause of course 404

**Not a registration bug.** `includes/class-cpt.php`'s
`register_course_post_type()` registers `lm_course` with
`public => true`, `publicly_queryable => true`, `query_var => true`,
`rewrite => ['slug' => 'cursos']`, `has_archive => 'cursos'` — all
correct, unchanged by any prior sprint's diff. `CLMS_CPT` is wired
into `CLMS_Loader`'s `base_modules` (`includes/class-loader.php`),
booted unconditionally on **every** normal request via
`CLMS_Loader::boot()` (called from `atora_lms.php`'s top-level
`add_action('init', ..., 1)` block) — not only during plugin
activation. Its constructor hooks `register_post_types()` onto `init`
at priority 5; since this happens from *within* an already-executing
`init` pass (priority 1), and WordPress's own hook system
(`WP_Hook::add_filter()`/`resort_active_iterations()`, core behavior
since 4.7, not specific to this plugin) picks up and fires
newly-added callbacks for any priority that hasn't already been
passed, `register_post_types()` genuinely does run within the same
request. Confirmed by tracing the full call chain, not assumed.

**The actual bug**: `flush_rewrite_rules()` was only ever called from
`register_activation_hook()`/`register_deactivation_hook()` — both
fire *only* on an explicit activate/reactivate click in wp-admin. A
normal production plugin update (file-replace via FTP/SFTP, a release
manager, or WordPress's own "Update now") triggers **neither** hook.
If any rewrite-affecting change occurred at *any* point in this
plugin's history, every site updated by file-replace (the normal
path, and the one this engagement's own packaging process produces)
was permanently stuck on the stale `rewrite_rules` option cached in
the database — courses and instructor profiles both 404 even though
registration is, and always was, correct in the code.

## 4. Root cause of instructor profile 404

Same root cause as section 3 — `includes/class-instructor.php`'s
`CLMS_Instructor` is registered in `CLMS_Loader`'s `core` module
group (`includes/loader/trait-loader-module-groups.php`), booted on
every normal request the same way as `CLMS_CPT`, and its constructor
correctly hooks `register_rewrite()` (registering
`add_rewrite_rule('^docentes/([^/]+)/?$', 'index.php?clms_instructor=$matches[1]', 'top')`)
onto `init` and `register_query_vars()` onto the `query_vars` filter.
No change to `class-instructor.php` from the 6.5.10 loader-visibility
fix (which only added a public wrapper method,
`CLMS_Loader::get_template_path()`, and updated two unrelated call
sites inside `maybe_load_profile_template()`) altered this
registration path — verified directly against the 6.5.10 diff. The
only reason `/docentes/{slug}/` 404'd was the same stale
`rewrite_rules` cache described in section 3.

## 5. Rewrite registration architecture

```
atora_lms.php
  add_action('init', closure, priority 1)
    -> CLMS_Loader::boot()
         -> CLMS_Loader_Bootstrap_Trait::init()
              -> boot_base_modules_only()      [instantiates CLMS_CPT]
              -> foreach module_groups: boot_module_group('core', ...)
                                              [instantiates CLMS_Instructor, among others]
```

Both `CLMS_CPT::__construct()` and `CLMS_Instructor::__construct()`
call `add_action('init', ...)`/`add_rewrite_rule()`/`add_filter('query_vars', ...)`
from inside this already-running `init` pass; WordPress's hook system
runs the newly-registered callbacks in the same pass, so both CPT and
rewrite-rule registration genuinely complete before the request
finishes `init`. No change was needed here — this was investigated
exhaustively before concluding the bug lived in the flush lifecycle,
not the registration path.

## 6. Rewrite flush lifecycle before/after

**Before**: exactly two `flush_rewrite_rules()` call sites in the
entire codebase — `atora_lms_activate()` and `atora_lms_deactivate()`.
No upgrade-time flush existed at all.

**After**: three call sites, each classified and verified:
1. `atora_lms_activate()` — unchanged, still the activation-time flush.
2. `atora_lms_deactivate()` — unchanged.
3. New: a top-level `add_action('wp_loaded', ..., 20)` closure. Checks
   `get_option('atora_lms_rewrite_version')` against a new,
   independent `ATORA_LMS_REWRITE_VERSION` constant (`'6.5.11-1'` —
   deliberately **not** tied to `ATORA_LMS_VERSION`, so this does not
   turn into "flush on every future version bump forever," the exact
   deploy-level equivalent of the request-level anti-pattern this
   sprint prohibits). Returns immediately unless the stored version is
   stale. On a stale version: one soft flush (`flush_rewrite_rules(false)`
   — no `.htaccess` rewrite) and persists the new version so no later
   request repeats it. Runs on `wp_loaded` specifically because it
   fires after `init` has fully completed, guaranteeing
   `CLMS_CPT`/`CLMS_Instructor` have already registered their rules
   for this same request before the flush reads them.

`atora_lms_activate()` was also updated to set
`atora_lms_rewrite_version` directly (it already does its own flush),
so a fresh install's very next `wp_loaded` doesn't perform a redundant
second flush.

## 7. Admin diagnostic root cause

`includes/admin-menu/class-menu-debug-guard.php`'s
`CLMS_Menu_Debug_Guard::log_orphan_pages()` iterated the **full**
global `$menu`/`$submenu` (every plugin's registered admin pages, not
only ATORA's) on `admin_menu` priority 1000, and only filtered by
known ownership *after* collecting every slug — any third-party page
that didn't match ATORA's `CORE_PAGES` list or a registered module's
`provides_pages` was logged as "orphaned," which was simply incorrect;
it was never ATORA's page. `CLMS_Menu_Debug_Guard::init()` gated this
solely on `WP_DEBUG`, which is not a genuine explicit opt-in for this
specific diagnostic — many staging setups (and, per this sprint's own
log evidence, at least one real production environment) leave
`WP_DEBUG` on without intending "audit the admin menu on every
wp-admin page load."

## 8. Runtime performance fixes

- **Admin-menu diagnostic** (section 7): now requires the explicit
  `ATORA_DEBUG_ADMIN_AUDIT` constant (default OFF) *in addition to*
  `WP_DEBUG`, and both `log_duplicate_slugs()`/`log_orphan_pages()`
  skip any slug that isn't ATORA-owned (prefix `atora-`/`clms-`, or in
  `CORE_PAGES`) before doing any further work on it — third-party
  plugin pages are no longer scanned into a result at all, closing
  both the false-positive log noise and the per-request cost of
  processing every other plugin's menu registrations on every
  wp-admin page load (when the now-required opt-in is enabled at all;
  by default this diagnostic performs zero work on any request).
- **404 handling** (sections 3–4): every course/instructor link that
  previously 404'd now resolves directly — WordPress's 404 path
  (full query parse, template-hierarchy fallback cascade) is markedly
  more expensive per-request than a resolved single/archive page, so
  this alone accounts for a meaningful share of the reported
  "noticeably slow" symptom on any page containing course or
  instructor links.
- **Schema/DDL checks** (`SHOW TABLES`, `INFORMATION_SCHEMA`, `dbDelta()`)
  — re-audited this sprint (section 11 below), found already correctly
  version-gated from the 6.5.10 fix; no new runtime overhead found or
  introduced.
- No other unconditional, per-request expensive operation
  (`get_plugins()`, `get_users()`, unscoped `WP_Query`/`get_posts()`)
  was found wired to `init`/`wp_loaded`/`plugins_loaded` in the core
  bootstrap chain (`atora_lms.php`, `includes/class-loader.php`,
  `includes/loader/*.php`) — verified by direct grep and read of every
  match.

## 9. Logging fixes

Every `error_log()` call site in the codebase (27 total) was audited.
25 were already on genuine error/failure paths (caught exceptions,
`is_wp_error()`, missing-module/missing-table conditions, a
one-time-per-request dedup for `LMS_Parity`'s telemetry writes added
in 6.5.10) or inside activation-only code — none of those were
changed. The remaining 2 (`class-menu-debug-guard.php`'s duplicate-slug
and orphan-page logs) are the ones fixed in sections 7–8; they no
longer fire at all unless the new explicit opt-in is set, and even
then only for ATORA's own slugs.

## 10. Files modified

| File | Fix |
|---|---|
| `atora_lms.php` | Versioned one-time rewrite-flush migration (PT-1/PT-2/PT-3); version bump |
| `includes/admin-menu/class-menu-debug-guard.php` | Explicit opt-in gate + ATORA-owned-slug scoping (PT-4) |
| `readme.txt` | Version bump, changelog, upgrade notice |

No other file was touched this sprint — course/instructor CPT
registration (`includes/class-cpt.php`, `includes/class-instructor.php`)
needed no change, and all 6.5.10 security/database files are untouched.

## 11. Tests executed

```
python3 tests/6.5.11/run_static_checks.py
8 passed, 0 failed
```

Covers: course CPT registration + reachability from the normal
(non-activation-only) request path; instructor route registration +
reachability; rewrite-flush-lifecycle classification (every
`flush_rewrite_rules()` call site is activation/deactivation/versioned
-migration, none unconditional); the versioned migration's own
early-return guard; the admin-diagnostic explicit-opt-in gate and its
default-OFF state; the ATORA-owned-slug scoping of the orphan/duplicate
scan; re-verification of the 6.5.10 schema-DDL gating; and a full
`error_log()` audit for unconditional informational noise.

Verified meaningful by running the same script against the pre-fix
`fix/6.5.10-production-hotfix` commit (`git worktree`, ad hoc, not
retained): it correctly failed all 4 of its fixable categories there,
and passes cleanly (8/8) against this sprint's fixes.

Every `RewriteResolutionTest.php` assertion (permalink/rewrite-regex
agreement) was independently hand-verified with an equivalent Python
`re` script against the real production source files before being
committed — results recorded in `tests/6.5.11/README.md`.

Full-tree PHP syntax proxy (brace/paren balance, no PHP interpreter
available in this environment): 495 files checked, 483 balanced, 12
imbalanced — the same 11 pre-existing Spanish-prose false positives
carried forward from every prior sprint's report, plus 1 new one in
`tests/6.5.11/RewriteResolutionTest.php` itself, confirmed a false
positive (PCRE escape sequences like `\\(`/`\\)` inside string
literals throw off naive paren counting; stripping string literals
first shows the file's parens/braces both perfectly balanced).

## 12. Tests not executable

- `tests/6.5.11/RewriteResolutionTest.php` — PHPUnit, requires a PHP
  interpreter (none available here); every assertion hand-verified
  instead (section 11).
- Item 14 (upgrade 6.5.10 → 6.5.11) — requires a real, populated
  WordPress/MySQL installation with an actual stale `rewrite_rules`
  option to demonstrate recovery; traced manually against the code
  path instead (section 14).
- Full end-to-end runtime scenarios (anonymous/student/instructor/
  admin/cron/REST-AJAX matrices from the OT) — no WordPress runtime in
  this environment; substituted with the static/structural checks in
  sections 3–9 and not claimed as production-validated (section 17).

## 13. Security regression

```
git diff fix/6.5.10-production-hotfix...fix/6.5.11-runtime-rewrite-hotfix --stat
```
5 files changed: `atora_lms.php`, `includes/admin-menu/class-menu-debug-guard.php`,
`readme.txt`, and two new test files. Zero matches for
`permission_callback`, `current_user_can`, `check_ajax_referer`,
`check_admin_referer`, or `wp_verify_nonce` in the added/removed diff
lines outside this report's/tests' own documentation prose. No file
under `modules/security/`, no rate-limiter file, no REST
permission-callback file, and no authentication file appears in the
changed-file list. All 6.5.10 security and database hardening is
untouched. The rewrite/route fixes in this sprint do not add or
change any authorization logic — they only affect whether a URL
*resolves* to a route at all, never whether the resolved content is
public; post status, enrollment requirements, and capability checks
downstream of routing (in the actual template/controller code) are
unmodified by this diff.

## 14. Upgrade 6.5.10 → 6.5.11

Not executable end-to-end here (no WordPress/MySQL runtime). Traced
manually against the actual code:

1. `atora_lms.php`'s existing `plugins_loaded` "Bug #4 fix" hook
   detects the version bump and resets CRM v2 schema-version options
   (unrelated to this sprint, unchanged).
2. The new `wp_loaded` (priority 20) callback in this sprint's diff
   reads `atora_lms_rewrite_version` — absent or older than
   `'6.5.11-1'` on any existing 6.5.10 (or earlier) install, since the
   option never existed before this sprint.
3. By the time `wp_loaded` fires, `init` has already fully completed
   for this request, including `CLMS_Loader::boot()`'s registration of
   `lm_course`/`atora_teacher`/the `/docentes/{slug}/` rule (section
   5) — so the flush that follows captures the current, correct rule
   set.
4. `flush_rewrite_rules(false)` runs once, `atora_lms_rewrite_version`
   is persisted, and no later request on that site repeats it.
5. No table was created, altered, or dropped by this sprint — no DB
   schema version was bumped (correctly, per this hotfix's own
   instruction not to bump it without an actual schema change).
6. No cron schedule ID was renamed; the 6.5.10 cron-registration fix
   is untouched.

## 15. Remaining external warnings

`Constant SCRIPT_DEBUG already defined` — searched the entire
codebase for `define( 'SCRIPT_DEBUG'`: **zero matches**. ATORA does
not define this constant anywhere; the warning originates from the
site's own `wp-config.php` (or another plugin/mu-plugin) defining it
more than once, entirely outside this plugin's code. Not modified,
per this hotfix's explicit instruction not to edit `wp-config.php` or
third-party code. Documented here as external, not fixed.

No other external warning category listed in the OT (duplicated
`WP_MEMORY_LIMIT`/`WP_MAX_MEMORY_LIMIT`, deprecated theme sidebar
warnings, missing Elementor files, LiteSpeed media warnings) was
reported as an actual observed finding this sprint — they were listed
as categories to exclude from scope if encountered, not as confirmed
symptoms.

## 16. SHA-256

```
e223eae16df013728f68d2b466ac10f63a3135a2d5e7605a70aa7a17106a36f1  atora-lms-6.5.11-runtime-rewrite-hotfix.zip
```

(Rebuilt once after adding `RUNTIME-REPORT-*.md` to `.distignore` —
this report did not exist when the first hash was taken, and without
the pattern it would have leaked into the distribution on any future
rebuild, the same class of gap caught for `CHANGELOG-*.md` in 6.5.10.)

## 17. Final status

**READY FOR PRODUCTION TEST.**

Both named symptom families (course 404, instructor profile 404) are
resolved by one unifying, thoroughly-traced root cause fix (a rewrite
flush lifecycle gap that existed since before this hotfix's own
lineage, only newly visible once 6.5.10 removed the fatals masking
it) rather than two independent patches — deliberately preferred over
inventing a second explanation once the first was confirmed to cover
both symptoms. The admin-menu diagnostic storm is closed at its root
(explicit opt-in, ATORA-owned-slug scoping) rather than merely
silenced. No DB schema was touched. Zero security-relevant lines
changed, confirmed by diff. This assessment is bounded by this
environment's disclosed limitation — no PHP interpreter, no
WordPress/MySQL runtime — so "resolves correctly" here means
"verified by direct code trace and hand-executed logic checks against
the real production source," not "observed working against a live
site." That gap is stated plainly rather than papered over with an
unverified "production ready" claim, per this hotfix's own
instruction against using that phrase (or "100% fixed"/"fully
secure") without an actual production/staging validation, which did
not occur here.

## 18. Autonomous implementation decisions

- **Root-cause unification over two separate patches**: once the
  rewrite-flush-lifecycle gap was confirmed to independently explain
  both the course and instructor 404s (both CPT/route registration
  traced as already-correct), implemented one shared fix rather than
  searching for two unrelated bugs — the safer, more parsimonious
  explanation given the evidence, and reversible (the migration is
  additive, changes nothing if the hypothesis were somehow wrong for
  one of the two symptoms).
- **Separate `ATORA_LMS_REWRITE_VERSION` constant, not tied to
  `ATORA_LMS_VERSION`**: explicitly the OT's own suggested pattern
  ("a separate rewrite migration version is acceptable"); chosen
  specifically to avoid a flush-on-every-future-bump anti-pattern.
- **`wp_loaded` over a bare `init` hook for the flush check**: chosen
  because `wp_loaded` fires after `init` fully completes (including
  nested same-pass hook registrations), removing any doubt about
  registration-order races that a check on `init` itself — even at a
  very late priority — would still carry some residual risk of.
- **Kept `WP_DEBUG` as a co-requirement, not a replacement, for the
  admin-menu diagnostic's gate**: the diagnostic's original intent
  (development-time detection of a real code defect — a genuinely
  duplicated or ownerless slug) is still valuable; the fix layers a
  second explicit gate on top rather than removing the WP_DEBUG check
  entirely, preserving the tool for a developer who explicitly wants
  it in a debug environment.
- **No DB schema version bump**: no table was created, altered, or
  dropped this sprint; bumping `V5_Installer::SCHEMA_VERSION` or
  `DB_Service::SCHEMA_VERSION` without a real schema change would
  trigger their dbDelta() passes for no reason on every existing
  install's next request — an unnecessary cost this hotfix's own
  performance mandate specifically prohibits introducing.
