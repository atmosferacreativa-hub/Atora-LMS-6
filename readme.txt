=== ATORA LMS ===
Contributors: mundocap, atmosferacreativa
Tags: lms, learning, courses, education, ai, grading, certificates
Requires at least: 6.4
Tested up to: 6.4
Requires PHP: 8.1
Stable tag: 6.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

ATORA LMS is a modular learning management system for WordPress with AI-assisted teaching, assessments, certificates, and student engagement tools.

== Description ==
ATORA LMS brings a complete learning ecosystem to WordPress. It is built for educators and teams that need a flexible LMS with modern workflows, AI-assisted tools, and a clean, role-based experience.

Key features include:
* Courses, lessons, and programs with structured curricula.
* Teacher dashboard with inbox, pending reviews, and quick actions.
* SpeedGrade-style grading interface with rubric support.
* AI assistant for course planning, lesson improvement, rubrics, and quizzes.
* Certificates, progress tracking, and student engagement tools.
* Commerce and enrollment flows with WooCommerce or external checkout integration.
* Analytics and operational metrics for instructors and admins.

ATORA LMS is designed to be extended with custom modules and integrations, while keeping core workflows fast and reliable.

== Installation ==
1. Upload the plugin folder to your `/wp-content/plugins/` directory or install it via the WordPress Plugins screen.
2. Activate the plugin.
3. Go to the ATORA admin menu to configure roles, courses, and settings.
4. (Optional) Add API keys in the AI settings to enable AI features.

== Frequently Asked Questions ==
= Do I need API keys to use ATORA LMS? =
No. The LMS works without AI. API keys are only required for AI-assisted features.

= Can I sell courses with WooCommerce? =
Yes. ATORA LMS can integrate with WooCommerce for enrollment and checkout workflows.

= Where are translations stored? =
Translation files are loaded from the `/languages` directory.

== Screenshots ==
1. Teacher dashboard overview.
2. SpeedGrade evaluation panel.
3. AI teaching assistant panel.
4. Course overview template.

== Changelog ==
= 6.7.0 =
* Added commercial followup plans: the same followup-plans engine built for teachers now serves salespeople on the commercial pipeline, with its own vocabulary and four starter templates (Deals estancados, Nutrir leads fríos, Antes del cierre de mes, Cuenta clave).
* Calendar blocks in the commercial view color by the conversion score of the most urgent contact in that occurrence — same design tokens as the rest of the site, no new palette.
* The side panel now shows each contact's active email-sequence enrollment (if any) so a salesperson always knows a contact is already being worked by an automated sequence — visible by default, never hidden without an explicit opt-in filter, and never altered automatically.
* A simple domain switch (Estudiantes/Ventas) appears only for users who have both academic and commercial plans — everyone else sees exactly what they saw before.
* No changes to existing academic followup plans, the teacher calendar, or the CRM followup board for installs that don't create a commercial plan.
= 6.6.0 =
* Added followup plans: teachers can set up their own contact rhythm with at-risk students on a visual monthly calendar (Planes de seguimiento).
* Four starter templates ("Chequeo semanal", "Alta atención", "Antes del cierre", "Solo hitos"), editable and saveable as the teacher's own reusable variants.
* Each occurrence resolves its student list live against the existing academic followup board at the moment it's viewed — a plan never freezes a student list, and marking a student as contacted never changes their followup stage.
* Drag an occurrence to reprogram it, pause a plan without losing its history, skip a single occurrence, or exclude one student from one occurrence — all from the same calendar screen, no separate settings page.
* Teachers get a notice on the day of an occurrence with students to review; empty occurrences never send a notice.
* No changes to the existing calendar or followup board behavior for installs that don't create a followup plan.
= 6.5.13 =
* Fixed course and instructor profile links still returning 404 after 6.5.12's automatic rewrite-rule refresh, on sites running a page-caching plugin.
* The plugin now purges known page-caching plugins (LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround Optimizer) after refreshing its rewrite rules.
* The admin "Purge cache" button now also clears page cache, not just the WordPress object cache.
= 6.5.12 =
* Fixed routing bootstrap order for course registration.
* Fixed instructor profile rewrite registration timing.
* Ensured structural routing hooks are registered before WordPress `init` processing.
* Preserved controlled one-time rewrite flushing.
* Added hook-order regression tests.
* Preserved 6.5.11 runtime performance improvements.
* No intended security or database behavior changes.
= 6.5.11 =
* Restored course permalink routing after 6.5.10.
* Restored instructor profile routing.
* Added controlled one-time rewrite migration behavior.
* Removed expensive admin-menu diagnostics from normal runtime.
* Reduced production log noise.
* Added rewrite and runtime-performance regression checks.
* Preserved 6.5.10 security and database hardening.
= 6.5.10 =
* Fixed legacy messaging bridge namespace resolution.
* Fixed invalid external access to protected loader path resolution.
* Ensured required LMS parity tables are created during install/upgrade.
* Improved MySQL/MariaDB utf8mb4 index compatibility.
* Centralized Atora WP-Cron schedule registration.
* Improved schema migration idempotency.
* Added regression tests for fresh install and 6.5.9 upgrade paths.
* Preserved existing security hardening and intended functional behavior.
= 6.5.9 =
* Security: two-factor login verification (the actual POST form used by the login flow, not just its AJAX counterpart) is now rate-limited per pending login, covering both regular and backup codes through a single shared quota.
* Security: the X-Real-IP forwarded header now requires its own explicit proxy authorization, separate from generic trusted-proxy trust, closing a spoofing path under certain reverse-proxy configurations.
* Security: CRM Telegram message attribution now reads exclusively from the same dedicated links table the bot itself uses as its source of truth, instead of legacy user data that could retain an ambiguous assignment after a resolved conflict.
* Hardening: the enrollment access-password limiter and both WhatsApp phone-verification counters (code attempts and code requests) now reserve their rate-limit quota atomically before evaluating an attempt, closing a race that allowed more attempts than intended under concurrent requests.
* Security review: this closes the static-hardening audit round; all findings from an external line-by-line review of 6.5.8 are resolved, verified with concurrency-specific regression tests where applicable.
= 6.5.8 =
* Security: strengthened proxy/header trust boundaries — private IP ranges are no longer trusted as reverse proxies by default, and Cloudflare's client-IP header now requires its own explicit configuration separate from generic proxy trust.
* Security: Telegram account linking now reads exclusively from its dedicated links table (not legacy user data) as the single source of truth, and re-linking to a new chat can no longer leave an account without any binding if the new chat is already taken.
* Hardening: the Telegram usermeta-to-table migration no longer assigns ambiguous historical links to an arbitrary user; conflicting entries are quarantined for manual review instead.
* Hardening: unified several remaining request counters (enrollment access codes, AI teaching assistant features, AI grading review, two-factor authentication) onto the same atomic, race-resistant limiting service, all failing safely closed if their backend is unavailable.
* Security: public form submissions and API rate limiting now fail safely closed (reject the request) instead of silently allowing unlimited traffic if their backend is unavailable.
* Security review: expanded regression coverage across CRM, LMS ownership, WhatsApp verification, MCP limits, digest locking, and all prior sprint fixes confirmed no regressions.
= 6.5.7 =
* Security: legacy course/program/lesson listings now scope draft, private, and "all" status requests to the requesting instructor's own content instead of trusting a client-supplied teacher_id or course_id.
* Security: client IP resolution now correctly walks the forwarded-header chain from the trusted-proxy edge inward, closing a way to spoof the reported client IP even behind a trusted proxy.
* Hardening: the affiliate click tracker now uses the same centralized, trusted-proxy-aware IP resolution as the rest of the plugin.
* Hardening: rate-limit tables (public forms, MCP API keys, and a new shared counter used by the AI assistant) are now purged of expired entries on an hourly schedule instead of growing indefinitely.
* Security: Telegram account linking now enforces chat-uniqueness at the database level (a chat can never be linked to two accounts, even under concurrent requests), replacing the previous best-effort application-level check.
* Hardening: the AI teaching assistant's rate limit now uses an atomic, race-resistant counter instead of a read-then-write pattern, and fails closed if the counter is unavailable.
* Production distribution now excludes internal migration-decision documents and the build script itself.
* Security review: full regression pass over CRM, LMS ownership, WhatsApp verification, MCP limits, unsubscribe, digest, and the 6.5.5/6.5.6 fixes confirmed no regressions.
= 6.5.6 =
* Security: legacy webhook management (register/list/delete outbound webhooks for lesson/course/enrollment/submission/grade/certificate events) now requires full site administration instead of a general content-management capability.
* Security: reading or editing a course's grading scheme (component weights) now requires ownership of that course instead of a general grading capability.
* Security review: full audit pass over the legacy REST API surface (courses, programs, lessons, rubrics, transcriptions, peer review, quizzes, reports, bulk actions, reorder) confirmed existing ownership checks are intact; no regressions found.
= 6.5.5 =
* Security: public form submissions now resolve the client IP through a centralized, trusted-proxy-aware resolver instead of trusting forwarded headers directly, closing a way to evade or poison the per-form rate limit.
* Security: public form submissions are now validated (form exists, correct type, valid nonce) before any rate-limit counter or other persistent state is created, closing a low-cost storage-exhaustion vector.
* Hardening: the public form rate limiter now uses an atomic, race-resistant counter instead of a read-then-write pattern.
* Hardening: the message digest queue now uses a unique claim token per batch, in addition to the existing claim-based locking, removing any theoretical ambiguity between overlapping claims.
* Security: enrollment access-code/password attempts are now rate-limited per user and link, closing a brute-force gap.
* Security: a Telegram chat can no longer become linked to two different WordPress accounts.
* Hardening: the MCP API rate limiter now uses an atomic counter instead of a read-then-write pattern.
* Hardening: additional isolation between the generic course create/update API and the legacy-content migration path, plus stricter validation when linking a course to its legacy post.
* Security: closed an authorization gap that allowed reading or appealing another student's grade via a crafted submission ID, and another that allowed processing grade appeals for courses an instructor doesn't own.
* Security: course/program enrollment management (enroll, unenroll, CSV import, WooCommerce product linking) now requires ownership of the specific course or program, not just a general management capability.
* Security: the academic setup wizard now verifies the current user owns the course before saving to it.
* Security: CRM campaign management (view, edit, launch, clone, pause, metrics) is now scoped to the campaign's owner for users without full CRM management access.
* Security: student progress-tracking records are now scoped to their owning student.
* Security review: full regression pass over previously closed CRM, LMS, WhatsApp, MCP, and messaging security fixes confirmed no regressions.
= 6.5.4 =
* Hardening: the unsubscribe link now requires an explicit confirmation click instead of acting on GET, so scanners and email previews can no longer unsubscribe a user by themselves.
* Hardening: the message digest queue is now claim-based, preventing duplicate summaries from overlapping cron runs, plus retention limits so it can't grow unbounded.
* Hardening: Telegram account linking codes now have much higher entropy and a failed-attempt lockout, matching the WhatsApp verification hardening from 6.5.1.
* Hardening: an MCP API key with the "all" scope no longer gets a higher rate limit than a plain "write" key for write operations.
* Hardening: public forms now throttle repeated submissions per IP per form.
* Hardening: native lessons and programs (without a linked legacy post) can now be created without hitting a database constraint, matching the courses fix from 6.5.3.
= 6.5.3 =
* Security: `wp_post_id` (the identity bridge to the legacy LMS content) can no longer be written through the generic course create/update REST endpoints, by anyone.
* Security: the courses table now allows multiple native courses without a legacy post link, fixing a schema constraint that previously only allowed one.
* Security: the legacy-to-tables migrator no longer silently trusts an existing table row during the migration window; it now flags instructor mismatches for manual review instead of assuming the row is correct.
= 6.5.2 =
* Security: instructors can no longer read, edit, view stats, enroll users into, or view the cohort of courses they don't own via the LMS REST API.
* Security: draft and private courses are no longer readable by arbitrary logged-in users, by listing or by direct ID.
* Security: removed a backward-compatibility fallback in phone verification that could have allowed unverified WhatsApp delivery for pre-6.5.1 accounts.
= 6.5.1 =
* Security: CRM v2 write endpoints now require management permission instead of view-only access.
* Security: fixed a scope check that could grant instructors global visibility over the contacts database instead of their own enrolled students.
* Security: closed a gap in the CRM inbox reply endpoint that allowed sending email to an arbitrary address.
* Security: WhatsApp messages now require a verified phone number, not just consent, across every send path (router, campaigns, CRM pipeline).
* Security: changing a student's phone number now invalidates its previous verification.
* Security: added a rate limit to phone verification code attempts.
* Security: fixed a rate-limit bucket collision and a scope-parsing bug in MCP API keys.
= 5.0.0 =
* Major release aligned with ATORA_v5 architecture and modules.

== Upgrade Notice ==
= 6.7.0 =
* New feature: commercial followup plans for the sales pipeline, reusing the followup-plans engine built in 6.6.0 — no changes to existing academic plans or the teacher calendar. Includes two new nullable/default-backfilled columns on the existing followup-plans table. Recommended update.
= 6.6.0 =
* New feature: followup plans on the teacher's calendar — a visual, non-technical way to schedule contact rhythm with at-risk students, built on top of the existing calendar and CRM followup board without changing their default behavior. Includes a new database table and a nullable column added to the existing calendar events table. Recommended update.
= 6.5.13 =
* Fixes course/instructor links still 404ing after 6.5.12 on sites using a page-caching plugin (LiteSpeed, WP Rocket, W3TC, etc.) -- the rewrite-rule refresh now also purges page cache. Recommended update.
= 6.5.12 =
* Routing bootstrap hotfix: course and instructor profile URLs are now registered deterministically before WordPress builds its rewrite rules, closing a residual 404 risk left after 6.5.11. Includes one more automatic, one-time rewrite-rule refresh. No new features, no security or database changes. Recommended update.
= 6.5.11 =
* Runtime stability hotfix: fixes course and instructor profile links returning 404 after a normal update (stale rewrite-rules cache, now refreshed automatically once on upgrade), and removes an admin-menu diagnostic that was scanning and logging noise about other plugins' pages on every wp-admin page load. No new features; all 6.5.10 security and database hardening preserved. Recommended update.
= 6.5.10 =
* Production stability hotfix: fixes a fatal on messaging bridge load, a fatal on instructor profile template resolution, missing LMS parity tables, a MySQL/MariaDB index-length error on course taxonomy terms, and unreliable custom WP-Cron interval registration. No new features; all prior security hardening preserved. Recommended update — includes a database schema change.
= 6.5.9 =
* Static security closure: 2FA login rate limiting, tighter proxy header trust, Telegram CRM source-of-truth alignment, and atomic (race-free) throttling for enrollment and WhatsApp verification. Recommended update.
= 6.5.8 =
* Final static security closure: trusted proxy policy hardening, Telegram binding integrity, unified atomic abuse controls, and fail-safe rate limiting throughout. Recommended update — includes a database schema change.
= 6.5.7 =
* Verified security closure: legacy REST listing ownership, trusted-proxy IP chain resolution, atomic rate-limit cleanup, and database-enforced Telegram link uniqueness. Recommended update — includes a database schema change.
= 6.5.6 =
* Legacy REST hardening: webhook management is now admin-only, and grading scheme changes are now scoped to the course's own instructor. Recommended update.
= 6.5.5 =
* Security hardening closure: trusted-proxy-aware IP resolution, hardened form/API/MCP rate limiting, authorization fixes for grade appeals, course/program enrollment management, CRM campaigns, and student progress tracking. Recommended update — includes a database schema change.
= 6.5.4 =
* Hardening for unsubscribe links, message digest locking, Telegram linking, MCP rate limits, form throttling, and native lesson/program creation. Recommended update — includes a database schema change.
= 6.5.3 =
* Security fixes for the wp_post_id identity bridge between the legacy and table-based LMS, plus a schema fix for native courses. Recommended update — includes a one-time database schema change.
= 6.5.2 =
* Security fixes for LMS REST course ownership and draft/private course visibility. Recommended update.
= 6.5.1 =
* Security fixes for CRM permissions, arbitrary email sending, WhatsApp verification enforcement, and MCP API key rate limiting. Recommended update.
= 5.0.0 =
* Recommended update for the ATORA_v5 branch.
