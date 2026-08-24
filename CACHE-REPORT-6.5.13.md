# CACHE REPORT — Atora LMS 6.5.13 "Page-Cache Purge on Rewrite Flush"

## 1. Executive Summary

```
Version: 6.5.13
Base:    6.5.12 (fix/6.5.12-routing-bootstrap-fix, commit feccba8)
Branch:  fix/6.5.13-cache-purge-on-flush
Build:   atora-lms-6.5.13-cache-purge-on-flush.zip
Status:  READY FOR PRODUCTION TEST
```

User-reported symptom on a live 6.5.12 site: course links, instructor
profile links, and both course preview modes (commercial/academic)
still returned 404 — but course *editing* worked fine, and manually
visiting Settings → Permalinks and clicking Save fixed it immediately.

## 2. Diagnosis

The manual-fix-works / automatic-fix-doesn't-work split is the key
diagnostic signal. It confirms 6.5.12's own diagnosis was correct —
CPT and rewrite-rule registration are genuinely fine, proven directly
by the fact that a manual flush (reading that exact same registered
state) fixed the problem outright. The gap was downstream of
registration: 6.5.12's automatic, silent `flush_rewrite_rules()` call
(on `wp_loaded`, version-gated) correctly rebuilds the `rewrite_rules`
database option, but never purges any page-caching plugin (LiteSpeed
Cache, WP Rocket, W3 Total Cache, etc.). A course or instructor URL
already cached as a 404 response by such a plugin keeps being served
straight from the cache layer, without the request ever reaching
PHP/WordPress again to see the corrected rules. Saving permalinks
manually in wp-admin fixed it because most caching plugins
independently detect that specific settings-page save and purge
themselves — a behavior a raw `flush_rewrite_rules()` function call,
called from code, never triggers on its own.

Course editing working, but neither preview mode working, is
consistent with the same explanation: the WordPress admin edit screen
(`post.php?post=X&action=edit`) doesn't depend on rewrite rules or
page cache at all, while both preview modes are `template_include`
filters that only evaluate once a request has already resolved as a
singular course view — which a cached 404 response never reaches.

This codebase already detects and displays `WP_CACHE` status in its
own diagnostic panels (`includes/settings/trait-settings-render.php`,
`includes/admin-menu/trait-admin-menu-main-pages-academic.php`,
`includes/admin-menu/trait-admin-menu-widgets-and-hubs.php`) — it was
aware a page cache might be active, but never acted on that awareness.

## 3. Fix

Added `atora_lms_purge_known_page_caches()` to `atora_lms.php`, called
immediately after `flush_rewrite_rules()` in the versioned `wp_loaded`
migration. Each integration is individually guarded
(`has_action()`/`function_exists()`/object-existence checks) — the
function never assumes a specific caching plugin is installed:

- LiteSpeed Cache (`litespeed_purge_all` action)
- WP Rocket (`rocket_clean_domain()`)
- W3 Total Cache (`w3tc_flush_all()`)
- WP Super Cache (`wp_cache_clear_cache()`)
- WP Fastest Cache (`$GLOBALS['wp_fastest_cache']->deleteCache(true)`)
- SiteGround Optimizer (`sg_cachepress_purge_cache()`)
- Generic object-cache drop-in (`wp_cache_flush()`)

Also wired into the existing manual "Purgar caché" admin button
(`includes/class-maintenance.php::action_purge_cache()`), which
previously only cleared the WordPress object cache and Atora's own
dashboard cache — never third-party page cache.

`ATORA_LMS_REWRITE_VERSION` bumped from `'6.5.12-1'` to `'6.5.13-1'`
so any site that already consumed the 6.5.12 flush (correct rules, no
cache purge) gets one more corrective flush+purge automatically on its
next request.

## 4. Files modified

| File | Change |
|---|---|
| `atora_lms.php` | New `atora_lms_purge_known_page_caches()`; called from the versioned flush migration; `ATORA_LMS_REWRITE_VERSION` bumped; version bump |
| `includes/class-maintenance.php` | Manual "Purgar caché" action now also calls the new purge helper |
| `readme.txt` | Version bump, changelog, upgrade notice |
| `.distignore` | Added `CACHE-REPORT-*.md` exclusion (packaging only) |

## 5. Tests

```
python3 tests/6.5.13/run_static_checks.py
4 passed, 0 failed
```

Covers: the flush migration calls the purge helper after (not before)
`flush_rewrite_rules()`; every plugin-specific call in the purge
helper is individually guarded; the manual admin action was updated;
the rewrite-version marker was bumped. Verified meaningful by running
against the pre-fix `fix/6.5.12-routing-bootstrap-fix` commit (`git
worktree`, ad hoc, not retained): correctly failed all 4 checks there.

Not executable in this environment: an actual end-to-end test against
a real caching plugin (no WordPress/caching-plugin runtime available
here — no PHP interpreter). The specific purge function names/actions
used are each plugin's own documented public API, not guessed.

## 6. Security regression

No security control touched — confirmed via
`git diff fix/6.5.12-routing-bootstrap-fix...fix/6.5.13-cache-purge-on-flush`;
2 production files changed, both purely cache-purge related, zero
matches for `permission_callback`/`current_user_can`/nonce-check
patterns in the diff.

## 7. Final status

**READY FOR PRODUCTION TEST.**

This assessment is bounded by this environment's disclosed limitation
(no PHP interpreter, no WordPress runtime, no actual caching-plugin
installation to test against) — the fix calls each plugin's own
documented, stable public purge API, defensively guarded, but has not
been observed running against a live LiteSpeed/WP Rocket/etc.
installation. Confirming the fix on the actual production site (the
one reported: 6.5.12, page cache active) is the recommended next step.
