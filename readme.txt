=== ATORA LMS ===
Contributors: mundocap, atmosferacreativa
Tags: lms, learning, courses, education, ai, grading, certificates
Requires at least: 6.4
Tested up to: 6.4
Requires PHP: 8.1
Stable tag: 6.5.6
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
