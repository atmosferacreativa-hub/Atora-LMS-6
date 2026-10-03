# SECURITY REPORT — Atora LMS 6.5.9 "Cierre definitivo de la fase estática"

## 1. Executive Summary

```
Version: 6.5.9
Base: 6.5.8
Branch: fix/6.5.9-cierre-estatico
Build: atora-lms-6.5.9-cierre-estatico.zip
Status: READY FOR DYNAMIC PENETRATION TEST
```

This sprint's mandate was narrow and explicit: close 1 HIGH + 4 MEDIUM
findings confirmed by an external line-by-line audit against 6.5.8 —
"el objetivo es cerrar, no volver a auditar todo el árbol." No
free-ranging scope; anything found outside the five named packages
goes to `docs/DEUDA-TECNICA.md`, not fixed this sprint. This is
explicitly the last static-hardening round before a dynamic
penetration test.

## 2. Security status

```
CRITICAL: 0
HIGH:     0 (1 closed this sprint — PT-1)
MEDIUM:   0 (4 closed this sprint — PT-2 through PT-5)
LOW:      2 (unchanged, see Residual risks)
```

## 3. Final Evidence Gate

| Finding | Root cause file | Changed? | Automated test | Static verification | Result |
|---|---|---|---|---|---|
| PT-1 (HIGH): 2FA form had no attempt limit; AJAX limited by IP only | `modules/security/class-2fa-manager.php` | YES | No (no PHP interpreter) | YES — 5 cases in `TwoFaRateLimitTest.php` + 2 in `RateLimiterConcurrencyTest.php` | FIXED |
| PT-2: X-Real-IP trusted without per-header authorization | `includes/class-atora-client-ip.php` | YES | No | YES — 3 new cases (g/h/i) in `ClientIpTest.php`, 6 prior scenarios re-verified unchanged | FIXED |
| PT-3: CRM read Telegram chat_id from usermeta, not the table | `modules/crm/trait-crm-events-messaging.php`, `modules/crm-v2/trait-crm-v2-pipeline.php`, `modules/crm-v2/class-crm-v2.php`, `modules/messaging/class-telegram-bot.php` | YES | No | YES — 4 cases in `TelegramCrmSourceOfTruthTest.php` (new) | FIXED |
| PT-4: enrollment password limiter peek-then-consume race | `includes/enrollment-manager/trait-enrollment-manager-access-enrollment.php` | YES | No | YES — existing 6 cases unchanged + 1 new real-concurrency case (10 parallel OS processes) | FIXED |
| PT-5: WhatsApp code-attempts + code-request counters, same race | `modules/messaging/class-messaging-preferences.php` | YES | No | YES — `PhoneVerifyBruteForceTest.php` rewritten (7 cases incl. 1 real-concurrency), 3 dependent test files updated with the new `$wpdb` fixture | FIXED |

Additional finding discovered and fixed during this sprint's own PT-4
audit pass (mandated exhaustive `Rate_Limiter::peek` search), not
separately named in the work order but directly within its stated
reasoning:

| Finding | Root cause file | Changed? | Automated test | Static verification | Result |
|---|---|---|---|---|---|
| PT-1's own 2FA gate (written earlier in this same sprint) replicated the identical peek-then-consume race PT-4 diagnosed | `modules/security/class-2fa-manager.php` | YES | No | YES — covered by the same `TwoFaRateLimitTest.php`/`RateLimiterConcurrencyTest.php` suite | FIXED |

A second self-caught issue, found during PT-5 implementation (not a
separate audit finding, an internal correctness check before
considering the package closed): `invalidate_current_code()` originally
also reset the WhatsApp code-attempts rate-limit counter, which —
called from `verify_phone_code()`'s own "too many attempts" branch —
undid the block it had just applied. Under real concurrency this would
let one blocked process's reset silently reopen the window for a
sibling still racing, which the new concurrency test would have caught
regardless. Decoupled before commit: `invalidate_current_code()` now
only clears the code's hash/expiry; the limiter is released only by an
explicit success or a fresh code request.

## 4. REST matrix

No REST routes were added, removed, or re-scoped this sprint. The
matrix from `SECURITY-REPORT-6.5.6.md`/`SECURITY-REPORT-6.5.7.md`
remains accurate and was not re-audited — this sprint's scope was 2FA
form rate limiting, proxy header trust, Telegram CRM source-of-truth
alignment, and rate-limiter atomicity, not REST endpoint
authorization (already closed, no regression per section 6 below).

## 5. PHP

```
PHP version: NOT EXECUTED (no PHP interpreter available in this environment — disclosed constraint,
             unchanged across every sprint in this engagement)
Files checked (brace/paren balance proxy): 491
PASS (balanced): 480
FAIL (imbalanced): 11 — all pre-existing Spanish-prose false positives (uninstall.php,
             class-sentiment.php, class-ai-exams.php, class-transcription.php,
             class-clms-feedback-loop.php, commerce/class-commerce-customer.php,
             ai/class-ai-extractor.php, lms-migrator.php, crm-v2/views/admin.php,
             email-engine/views/test-send.php, and
             tests/Messaging/PhoneVerificationInvalidationTest.php — this last one
             pre-existed at 78/77 before this sprint's diff, which added 45 balanced
             parens on top; the imbalance is an unrelated unmatched "(" inside a
             Spanish-language docblock sentence, unrelated to any code introduced here)
```

`vendor/bin/phpcs`/`vendor/bin/phpunit`: NOT EXECUTED (no PHP
interpreter, no Composer dependencies installed in this environment —
same disclosed constraint as every prior sprint's report).

## 6. Automated tests

```
Executed: 0
Passed: 0
Failed: 0
Skipped: 0
```

No PHP interpreter/PHPUnit available in this environment. Every test
referenced in this report was hand-traced line-by-line against the
actual modified code, not executed by a real test runner — including
the concurrency tests, which are written to genuinely exercise real
OS-level parallelism (multiple `proc_open()`-launched child processes,
all started before any are waited on, sharing state through a real
file protected by `flock(LOCK_EX)`) when run under a real CI with PHP
available. This distinction is kept explicit per this engagement's
standing disclosure convention — a concurrency test that has not
actually run under real parallelism has not demonstrated anything
about atomicity yet.

## 7. Static/manual verification

```
Verified: 34 new/modified test methods across 11 test files, each hand-traced against
          the post-fix code:
  tests/Security/TwoFaRateLimitTest.php (new)                — 5 methods
  tests/Security/RateLimiterConcurrencyTest.php (new)        — 2 methods (real multi-process)
  tests/Security/ClientIpTest.php (extended)                 — 3 new methods
  tests/CRM/TelegramCrmSourceOfTruthTest.php (new)           — 4 methods
  tests/Enrollment/AccessLinkPasswordThrottleTest.php (ext.) — 1 new method (real multi-process)
  tests/Messaging/PhoneVerifyBruteForceTest.php (rewritten)  — 8 methods (1 real multi-process)
  tests/Messaging/PreferencesTest.php (fixture updated)      — no new methods, dependency fix
  tests/Messaging/PhoneVerificationInvalidationTest.php (fixture updated) — no new methods
  tests/Messaging/PhoneVerificationCompatRetiredTest.php (fixture updated) — no new methods
  tests/bootstrap.php — added requires for Two_FA_Manager and CRM_Events_Messaging_Trait
Failed: 0
```

## 8. Historical regressions

```
LMS REST ownership (legacy + table):  PASS (untouched this sprint, per the OT's own
                confirmation this area was already correct)
wp_post_id identity bridge:           PASS (untouched)
CF-Connecting-IP trust:               PASS (untouched — only the separate X-Real-IP
                tier was added; the 6.5.8 Cloudflare-specific list and its 6
                prior matrix scenarios re-verified passing unchanged)
Telegram relink safety (UPDATE not
DELETE-then-INSERT):                  PASS (untouched)
Forms fail-closed:                    PASS (untouched)
AI assistants (Copilots, grading,
Teacher/Student Assistant):           PASS (untouched — all already on
                ATORA_Rate_Limiter atomically per the OT's own confirmation)
Enrollment:                           PASS (5-attempts/15-min policy preserved,
                only the internal ordering changed from peek-then-consume to
                consume-first; existing 6-case suite behavior unchanged, 1 new
                concurrency case added)
WhatsApp:                             PASS (3/hour request limit and 5-attempt
                code limit preserved; only the internal ordering and storage
                changed; three dependent test files updated only to supply the
                now-required $wpdb rate-limit fixture, no assertion changed)
Telegram uniqueness/relink:           PASS (untouched — only CRM's read path changed,
                not the bot's own write/uniqueness logic)
2FA:                                  PASS + HARDENED (ajax_verify()'s pre-existing
                IP-based limiter left untouched as a complementary control;
                new user-based gate added without altering TOTP/email/SMS/
                WhatsApp dispatch logic)
```

## 9. Security scans

```
Malware: PASS (0 real matches in the extracted ZIP; one grep hit on
         includes/ai/class-ai-extractor.php's shell_exec() usage — pre-existing,
         guarded, unrelated to this sprint's diff, used for optional PDF text
         extraction)
Secrets: PASS (0 matches across this sprint's diff and the built ZIP)
SQL:     PASS (all new/changed queries use $wpdb->prepare() with placeholders,
         or delegate to ATORA_Rate_Limiter/Telegram_Bot methods already
         verified in prior sprints)
Output/XSS: PASS (no new output paths introduced this sprint)
Distribution: PASS (see build/audit results below)
```

## 10. Residual risks

```
LOW: unchanged from 6.5.8 — no explicit Telegram "unlink" self-service endpoint;
     ATORA_Client_IP's conservative default (loopback-only trusted proxy) still
     requires explicit configuration behind a reverse proxy on a private IP.
     Neither is a regression or new finding this sprint.

MEDIUM: none open (4 closed this sprint).
HIGH: none open (1 closed this sprint).
CRITICAL: none open.

Documented technical debt (docs/DEUDA-TECNICA.md), explicitly not fixed this
sprint per its own scope rules:
  - atora_telegram_chat_id usermeta write (Telegram_Bot::link_account()) kept
    for backward compatibility — candidate for future removal once confirmed
    nothing external reads it.
  - Telegram's own usermeta-based link-attempt counter (not this sprint's
    WhatsApp counters) — explicitly out of scope per PT-5.3, lower impact
    given its ~40-bit code entropy already hardened in 6.5.4.
```

## Distribution build & audit

`atora-lms-6.5.9-cierre-estatico.zip` built via `scripts/build-dist.sh`,
extracted to a fresh temporary directory, and independently
re-verified against the extracted contents:

- No `.git/`, `.github/`, `.claude/`, `.qodo/`, `agents/`, `tests/`,
  `docs/`, `lms-migration/`, `scripts/`, `node_modules/`, `vendor/`,
  `.env*`, `*.sql`, `backups/`.
- `SECURITY-AUDIT.md`/`SECURITY-REPORT-*.md` excluded; `SECURITY.md`
  (public policy) retained deliberately.
- `Version:`/`ATORA_LMS_VERSION`/`Stable tag` all read `6.5.9` in the
  extracted ZIP.
- `ATORA_Rate_Limiter` and `ATORA_Client_IP` present in the extracted
  `includes/`.
- Plugin bootstrap (`atora_lms.php`) present and intact.
- Malware and secret scans re-run against the extracted contents: both
  clean (see section 9).

## Release status

**READY FOR DYNAMIC PENETRATION TEST.**

CRITICAL = 0, HIGH = 0, known MEDIUM = 0. All five findings named in
this sprint's work order are closed with a real diff against the
identified root cause, and — per the OT's explicit requirement — every
race-condition fix (PT-1, PT-4, PT-5) carries a genuine multi-process
concurrency test, not just a sequential functional one. One additional
race (PT-1's own gate, written earlier in this sprint) was caught and
fixed by the same mandated exhaustive search that closed PT-4. This
closes the static-hardening stage of this engagement begun in 6.4.0;
per the OT's own closing statement, the next step is a dynamic
penetration test against a real WordPress/MySQL installation with four
roles (admin, Instructor A, Instructor B, student), which this
environment cannot execute and which should be prepared as a separate
document once this report is confirmed.
