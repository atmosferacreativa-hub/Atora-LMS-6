# ROUTING REPORT — Atora LMS 6.5.12 "Routing Bootstrap Fix"

## 1. Executive Summary

```
Version: 6.5.12
Base:    6.5.11 (fix/6.5.11-runtime-rewrite-hotfix, commit ca896fc)
Branch:  fix/6.5.12-routing-bootstrap-fix
Build:   atora-lms-6.5.12-routing-bootstrap-fix.zip
Status:  READY FOR PRODUCTION TEST
```

This sprint's mandate was narrow: after 6.5.11 (white screen gone,
performance improved), valid course and instructor profile URLs still
returned 404. The OT supplied a specific hook-timing hypothesis to
verify, not implement blindly. It was verified from scratch against
the actual 6.5.11 source rather than assumed — the general mechanism
in the hypothesis (WordPress's own hook system genuinely does support
adding a callback to a currently-running action and having it fire in
the same pass) checks out as correct in the general case, but a
distinct, concrete risk was found in this specific codebase's own
6.5.11 fix: the versioned rewrite-flush only ever runs once, and never
re-verifies that what it captured was complete. This sprint closes
that gap directly — deterministic, top-level structural registration
that removes the dependency on hook-nesting timing entirely for the
pieces that matter — rather than either blindly implementing the
supplied hypothesis's suggested code or dismissing it outright.

## 2. Verified production symptom

- White screen gone (6.5.10).
- Site performance improved (6.5.11).
- Valid course URLs still 404.
- Valid instructor profile URLs still 404.

## 3. Actual root cause

**The supplied hypothesis, taken literally, is not quite right**:
WordPress's hook system (`WP_Hook::add_filter()`/`resort_active_iterations()`,
core behavior stable since WP 4.7) *does* pick up and fire a callback
added to a currently-executing action, for any priority not yet
reached in that same pass. `CLMS_CPT`'s `add_action('init', ..., 5)`
and `CLMS_Instructor`'s `add_action('init', ..., default 10)`, both
registered from inside the already-running `init:1` closure that calls
`CLMS_Loader::boot()`, are not fundamentally broken by this mechanism
in the general case — "init priority 5 has already passed" is not
literally true; priority 5 has not been reached yet when priority 1's
callbacks are still running.

**The actual, concrete risk**: normal-request URL routing in WordPress
does not re-invoke `register_post_type()`/`add_rewrite_rule()` at all
— it matches the incoming URL against the *cached* `rewrite_rules`
option, built once, the last time `flush_rewrite_rules()` ran. 6.5.11
fixed the confirmed lack of any upgrade-time flush by adding exactly
one version-gated flush on `wp_loaded`. But that fix has a
self-inflicted flaw: it flushes once and unconditionally marks the
migration "done," with no verification that CPT/rewrite registration
had actually completed *for that specific request* before the flush
read the current rule state. If, for any reason — a genuine edge case
in this codebase's three-level-deep nested-closure chain (outer
`init:1` closure → `atora_lms_require_module()`'s callback →
`CLMS_Loader::boot()`'s module-group loop → `CLMS_CPT`'s own
constructor), interference from another plugin also mutating the
`init` hook, or any other interaction specific to a given site's
plugin/theme mix — registration had not completed by the moment of
that one flush, the site would be **permanently** stuck with
incomplete rules: the version-gate never retries a flush it believes
already succeeded.

This is confirmed as at least a plausible, non-theoretical failure
mode regardless of whether the base hook-nesting mechanism is reliable
in the abstract — and it fully explains the reported symptom (valid
URLs still 404 after 6.5.11) without needing to overturn the general
mechanism's correctness.

## 4. Hook lifecycle before

```
plugin file load
plugins_loaded
init:0   — (nothing Atora-specific)
init:1   — outer closure: CLMS_Loader::boot()
             └─ CLMS_CPT::__construct()       → add_action('init', register_post_types, 5)  [nested, same pass]
             └─ CLMS_Instructor::__construct() → add_action('init', register_rewrite, 10)     [nested, same pass]
init:5   — register_post_types() fires (via nested registration)
init:6   — register_taxonomies() fires (via nested registration)
init:10  — register_rewrite() fires (via nested registration)
init:20  — 6.5.11's versioned rewrite-flush check (wp_loaded, actually — see below)
wp_loaded:20 — flush_rewrite_rules() if atora_lms_rewrite_version stale
template_redirect / template_include — instructor profile template swap
```

No step here is provably broken in the general case — but every
structural registration step depends on the nested nested-hook
mechanism actually completing, for every request type, on every site,
before the one-shot flush reads the current state.

## 5. Hook lifecycle after

```
plugin file load
plugins_loaded
init:0   — NEW: top-level structural bootstrap (atora_lms.php, not nested)
             └─ ReflectionClass('CLMS_CPT')->newInstanceWithoutConstructor()
                  ->register_post_types()   [direct call, not a hook registration]
                  ->register_taxonomies()   [direct call]
             └─ ReflectionClass('CLMS_Instructor')->newInstanceWithoutConstructor()
                  ->register_rewrite()      [direct call]
                  add_filter('query_vars', [instance, 'register_query_vars'])
init:1   — outer closure: CLMS_Loader::boot() (unchanged)
             └─ CLMS_CPT::__construct()       → add_action('init', register_post_types, 5)  [still nested; now redundant, harmless]
             └─ CLMS_Instructor::__construct() → add_action('init', register_rewrite, 10)     [still nested; now redundant, harmless]
init:5/6/10 — same nested callbacks still fire (or don't — no longer matters either way)
wp_loaded:20 — 6.5.11's versioned rewrite-flush check, now against ATORA_LMS_REWRITE_VERSION='6.5.12-1'
             — by this point, CPT/rewrite state is GUARANTEED complete regardless of nested-hook timing
template_redirect / template_include — unchanged
```

By the time any flush runs (the `wp_loaded` migration, activation, or
a manual permalink re-save), structural registration is deterministic
and has already happened at `init:0`, before `CLMS_Loader::boot()`
even starts. The nested registration inside `CLMS_CPT`/`CLMS_Instructor`'s
own constructors is untouched (no code deleted) and still fires
redundantly — WordPress accepts re-registering the same CPT/rewrite
rule without error — so this is purely additive.

## 6. Course CPT timing

`includes/class-cpt.php` was **not modified this sprint** (confirmed:
`git diff fix/6.5.11-runtime-rewrite-hotfix...fix/6.5.12-routing-bootstrap-fix
-- includes/class-cpt.php` is empty). `register_post_types()` is now
additionally called directly, as a plain method call with no hook
indirection, at `init` priority 0 — before `CLMS_Loader::boot()`
(priority 1) even begins. `post_type_exists('lm_course')` is true from
that point in the request forward, deterministically, for every
request type.

## 7. Instructor rewrite timing

`includes/class-instructor.php` was also **not modified this sprint**.
`register_rewrite()` (which itself calls `add_rewrite_tag('%clms_instructor%', ...)`
then `add_rewrite_rule('^docentes/([^/]+)/?$', 'index.php?clms_instructor=$matches[1]', 'top')`)
is now called directly at `init` priority 0, before `CLMS_Loader::boot()`.

## 8. Query var validation

`register_query_vars()` (the `query_vars` filter callback exposing
`clms_instructor`) is registered via `add_filter()` from the early,
constructor-bypassed instance at `init` priority 0. Filters are read
lazily during request parsing (well after `init`/`wp_loaded` complete)
— their registration was never actually at risk from hook-nesting
timing the way action-based CPT/rewrite-rule registration was, but is
now registered at the earliest possible point regardless, for
consistency and defense in depth.

## 9. Rewrite rule validation

Not executable against a real `$wp_rewrite->wp_rewrite_rules()` in
this environment (no WordPress runtime). Statically confirmed instead
that the exact same `^docentes/([^/]+)/?$` pattern and `'cursos'`
rewrite slug from 6.5.11 are what the new priority-0 bootstrap invokes
— no new pattern, no changed slug, only earlier and deterministic
invocation. See `tests/6.5.12/README.md` for the hand-verified Python
cross-check against the real source.

## 10. Template routing validation

`includes/class-instructor.php`'s `maybe_load_profile_template()`
still uses `CLMS_Loader::get_template_path()` (the public wrapper
added in the 6.5.10 loader-visibility fix) rather than calling the
protected `resolve_file_path()` directly — confirmed unchanged and
intact; the 6.5.10 fatal ("Call to protected method") has not been
reintroduced. `template_include`/`add_shortcode`/`add_meta_boxes`/user
-profile-field hooks (all registered inside `CLMS_Instructor`'s full
constructor, not called directly by the new early bootstrap) continue
to be registered exactly once, via `CLMS_Loader`'s normal instantiation
at `init:1` — the early, constructor-bypassed instance deliberately
does **not** duplicate these.

404-state handling (`$wp_query->is_404`, `status_header()`) inside the
instructor virtual-route path was inspected per the OT's own audit
item and found unchanged, unmodified by any prior sprint, and not
implicated by the specific reported symptom (routing never reaching
the CPT/rewrite registration in time, not a 404-flag-clearing defect)
— left untouched per the instruction not to broadly force
`status_header(200)` on unknown URLs without evidence it's needed.

## 11. Rewrite flush lifecycle

Unchanged in structure from 6.5.11 — still exactly 3 call sites
(activation-only, deactivation-only, one versioned migration on
`wp_loaded`), still none unconditional. The version marker itself was
bumped: `ATORA_LMS_REWRITE_VERSION` from `'6.5.11-1'` to `'6.5.12-1'`,
so any site that already consumed the 6.5.11 flush (potentially with
incomplete rules, per section 3) gets exactly one more corrective
flush — this time with deterministic registration already in place
before `wp_loaded` fires.

## 12. Performance regression check

```
tests/6.5.12/run_static_checks.py::check_no_performance_regression — PASS
```

Confirmed all three 6.5.11 performance fixes remain intact and
unmodified: the admin-menu diagnostic's explicit `ATORA_DEBUG_ADMIN_AUDIT`
opt-in gate, its ATORA-owned-slug scoping, and the schema-DDL
version-gating audited across all six table-creating files. Total
`flush_rewrite_rules()` call sites remains 3 (not increased). No new
heavy operation was moved earlier — the new `init:0` bootstrap calls
exactly two lightweight, already-existing registration methods
(`register_post_types()`, `register_taxonomies()`, `register_rewrite()`)
via reflection, nothing resembling the "heavy runtime bootstrap"
(messaging, automation, analytics, CRM processing, queues) the OT
explicitly says must stay at its existing lifecycle stage — none of
that was touched or moved.

## 13. Security regression check

```
git diff fix/6.5.11-runtime-rewrite-hotfix...fix/6.5.12-routing-bootstrap-fix --stat
```
4 files changed: `atora_lms.php`, `readme.txt`, and two new test files
(plus `.distignore`, a packaging-only change). Zero matches for
`permission_callback`, `current_user_can`, `check_ajax_referer`,
`check_admin_referer`, or `wp_verify_nonce` in the added/removed diff
lines outside this report's/tests' own documentation prose. No file
under `modules/security/`, no rate-limiter file, no REST
permission-callback file, and no authentication file appears in the
changed-file list. Routing success does not by itself expose
unpublished/private content — this sprint changes *when* routes are
registered, never what authorization logic runs downstream of a
resolved route (post status checks, enrollment requirements, capability
checks all live in unmodified code).

## 14. Files modified

| File | Change |
|---|---|
| `atora_lms.php` | New deterministic structural bootstrap at `init:0`; `ATORA_LMS_REWRITE_VERSION` bumped to `6.5.12-1`; version bump |
| `readme.txt` | Version bump, changelog, upgrade notice |
| `.distignore` | Added `ROUTING-REPORT-*.md` exclusion (packaging only) |

`includes/class-cpt.php` and `includes/class-instructor.php` — **not
modified**. Their registration logic, CPT arguments, and rewrite
pattern are byte-for-byte unchanged; only the timing of one additional
invocation changed, from `atora_lms.php`.

## 15. Tests executed

```
python3 tests/6.5.12/run_static_checks.py
8 passed, 0 failed
```

Covers: the structural bootstrap's top-level (non-nested) placement
and its use of `newInstanceWithoutConstructor()` to avoid duplicate
hook registration; direct confirmation it calls the real
`register_post_types()`/`register_taxonomies()`/`register_rewrite()`
methods; the 6.5.10 loader-visibility fix remaining intact; the
rewrite-flush lifecycle classification plus the version-bump
requirement; a dedicated hook-order regression guard (the exact
"registered during init:X for init:Y where Y<X" pattern the OT calls
out, scoped to `CLMS_CPT`/`CLMS_Instructor`); and re-verification that
no 6.5.11 performance fix was reverted.

Verified meaningful by running the same script against the pre-fix
`fix/6.5.11-runtime-rewrite-hotfix` commit (`git worktree`, ad hoc, not
retained): correctly failed 4 of its 4 fixable categories there.

`StructuralRouteRegistrationTest.php`'s assertions were independently
hand-verified with an equivalent Python `re` script against the real
`atora_lms.php`/`class-cpt.php`/`class-instructor.php` before being
committed (results in `tests/6.5.12/README.md`).

Full-tree PHP syntax proxy (brace/paren balance, no PHP interpreter
available): 496 files checked, 483 balanced, 13 imbalanced — the same
11 pre-existing Spanish-prose false positives carried forward from
every prior sprint, plus the 2 regex-pattern-heavy test files
(`tests/6.5.11/RewriteResolutionTest.php`,
`tests/6.5.12/StructuralRouteRegistrationTest.php`) already confirmed
false positives (PCRE escape sequences inside string literals throw
off naive paren counting; both manually read and confirmed
syntactically valid).

## 16. Tests not executable

- `tests/6.5.12/StructuralRouteRegistrationTest.php` — PHPUnit,
  requires a PHP interpreter (none available here); every assertion
  hand-verified instead (section 15).
- Items 04/05/07/08 (actual HTTP-level URL resolution, valid and
  invalid) and 15 (upgrade 6.5.11 → 6.5.12) — require a real
  WordPress/MySQL runtime with `WP_Rewrite` actually parsing a request;
  substituted with static source verification and not claimed as
  production-validated (section 20).

## 17. Upgrade 6.5.11 → 6.5.12

Not executable end-to-end here (no WordPress/MySQL runtime). Traced
manually:

1. Any existing 6.5.11 install already has
   `atora_lms_rewrite_version = '6.5.11-1'` persisted (from that
   sprint's own migration, assuming it ran at least once).
2. `atora_lms.php`'s `init:0` structural bootstrap now runs on every
   request, registering `lm_course`/`atora_teacher`/the `/docentes/{slug}/`
   rule deterministically before anything else.
3. The `wp_loaded` migration check now compares the stored
   `'6.5.11-1'` against the new `ATORA_LMS_REWRITE_VERSION` constant
   (`'6.5.12-1'`) — stale, so it proceeds.
4. By this point in the SAME request, structural registration from
   step 2 has already completed (guaranteed, not dependent on
   hook-nesting) — the flush that follows captures a known-correct
   rule set.
5. `flush_rewrite_rules(false)` runs once more, the option is updated
   to `'6.5.12-1'`, and no later request repeats it.
6. No table was created, altered, or dropped — no DB schema version
   bumped (no schema changed this sprint).
7. No cron schedule ID renamed; the 6.5.10/6.5.11 cron-registration fix
   is untouched.

## 18. Autonomous decisions

- **Verified rather than blindly implemented the supplied hypothesis**:
  the OT explicitly required this ("This must be verified against the
  actual 6.5.11 source before changing anything. Do not blindly
  implement the hypothesis if repository evidence disproves it.").
  Traced WordPress's actual `WP_Hook` mechanics rather than accepting
  the hypothesis's framing at face value, found the mechanism it
  described is not literally broken in general, but identified the
  concrete, narrower risk in this codebase's own 6.5.11 fix (a
  one-shot flush with no verification of what it captured) that fully
  explains the persisting symptom — and fixed that specific risk
  directly.
- **`ReflectionClass::newInstanceWithoutConstructor()` over `new`**:
  chosen specifically to avoid duplicating `CLMS_CPT`/`CLMS_Instructor`'s
  *other* constructor side effects (admin columns, metaboxes,
  shortcodes, `template_include`) when `CLMS_Loader` creates its own
  "real" instances later in the same request — the OT's own explicit
  instruction ("Do not create nonsensical ordering" / minimal
  structural subset only) ruled out moving the whole class
  instantiation earlier.
- **Left `CLMS_CPT`/`CLMS_Instructor`'s own nested `init` registration
  in place, unmodified**: removing it was unnecessary (redundant
  re-registration is harmless in WordPress) and riskier than leaving
  it — the OT's "safest, least invasive" default rule applies directly.
- **Bumped `ATORA_LMS_REWRITE_VERSION` again**: without this, any site
  that already consumed the 6.5.11 flush would never get the benefit
  of this sprint's deterministic registration, since the version-gate
  would still see itself as "done."
- **No DB schema version bump**: no table was created, altered, or
  dropped this sprint.

## 19. SHA-256

```
6da3191919e44fb3a447a1866874add15680904d2d3d0d0956c5f07b0f09a6b4  atora-lms-6.5.12-routing-bootstrap-fix.zip
```

## 20. Final status

**READY FOR PRODUCTION TEST.**

The specific, verifiable risk in the 6.5.11 fix (a one-shot rewrite
flush with no guarantee registration had completed before it ran) is
closed by making that registration deterministic and by forcing one
corrective re-flush for any site potentially still affected. This
assessment is bounded by this environment's disclosed limitation — no
PHP interpreter, no WordPress/MySQL runtime — so "resolves correctly"
here means "verified by direct trace of WordPress's documented hook
mechanics and hand-executed logic checks against the real production
source," not "observed working against a live site." That gap is
stated plainly rather than papered over, per this hotfix's own
instruction against "PRODUCTION READY," "100% FIXED," or "FULLY
SECURE" without an actual production/staging validation, which did
not occur here.
