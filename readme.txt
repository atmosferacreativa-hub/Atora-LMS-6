=== ATORA LMS ===
Contributors: atorastudio, atmosferacreativa
Tags: lms, learning, courses, education, ai, grading, certificates
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 6.18.11
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

ATORA LMS is a modular learning management system for WordPress with AI-assisted teaching, assessments, certificates, and student engagement tools. Author: Atora Studio (atora.studio). Created by Atmósfera Creativa.

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
= 6.16.1 =
* Peer review: calibración bloquea revisiones hasta completarse (pass/warn), con fallback seguro.

= 6.16.0 =
* Epic 4 (MVP): Coevaluación mejorada — calibración por lección, scoring de consistencia (outliers) y reporte admin.
* DB: nueva tabla de auditoría `wp_clms_peer_review_audit_log`.
* Lesson settings: modo ciego + calibración (ejemplar + pauta) disponibles en la metabox de evaluación.

= 6.15.4 =
* Learning Analytics: incluye “entregas perdidas” en señales y export BI (CSV/JSON) usando `wp_atora_early_warning`.

= 6.15.3 =
* Learning Analytics: trend/delta por estudiante, nuevos tipos de alerta (high_risk/inactivity/risk_spike) y notificación con contexto (curso + delta).
* Learning Analytics: export BI incluye trend/delta/alert_type (CSV/JSON).
* DB: `wp_atora_student_analytics` agrega columnas de trend/delta para consultas más rápidas.

= 6.15.2 =
* Learning Analytics: export JSON (admin + REST) con esquema BI-friendly (schema_version=1).
* Learning Analytics: timeline de estudiante (submissions recientes + links a SpeedGrade) y entregas perdidas en el detalle.
* Early Warning: helper público para listar entregas perdidas por estudiante/curso (reusado por Learning Analytics).

= 6.15.1 =
* Learning Analytics: filtros por cohorte/docente, listado multi-curso, export CSV multi-curso y vista de detalle por estudiante.
* Learning Analytics REST: endpoints por cohorte/docente y scan por cohorte/docente.

= 6.15.0 =
* Added Learning Analytics (MVP): risk snapshots per student/course (DB table + daily cron + REST + admin dashboard + CSV export).
* Added internal notifications (MVP): alerts teachers when a student reaches high risk (daily anti-spam).

= 6.14.1 =
* Hardening Group Assessment: enabled “Trabajo en grupo” mode in lesson UI and added group context to the student submission form.
* Improved course group management UI (create groups, assign members, safe preset autogeneration) and added locked-group safe add-only flow.
* Prevented double grading in SpeedGrade by excluding shadow submissions and restricting group lessons to master submissions only.

= 6.14.0 =
* Added Group Assessment (work in groups): course-level group management, group submissions, grade propagation to all members, per-student overrides, and CSV export.
* Improved Rubrics: per-criterion weights (auto-normalized), configurable scales (0–4, 0–5, 0–20, 0–100, A–F), holistic flag, and exemplars/benchmarks per level. Added shared rubric presets.
* Added Early Warning (MVP): detects missed submissions and sends internal notifications to teachers; includes REST endpoint for course warnings.

= 6.13.3 =
* Fixed Zoom attendance for students whose connection drops and reconnects mid-class — previously each reconnection was scored separately against the full class duration, so a student present the whole class could be marked "partial" instead of "present." Attendance is now aggregated per person before scoring, and duration is summed instead of overwritten by the last reconnection.
* Version housekeeping: the plugin version constant, header, and changelog now match the actual release history (they had been stuck at 6.11.0 since 6.12.0). This constant also gates cache-busting for enqueued scripts/styles and the data sent to the licensing server, so keeping it accurate matters beyond cosmetics.
= 6.13.2 =
* Fixed Zoom attendance silently dropping unidentified/guest participants — Zoom now records guests the same way Google Meet already did, instead of discarding them.
* Fixed the schema migration permanently losing the link to who organized a Google Meet session if that session's data was never fully saved — the safety option is now kept (and logged) instead of deleted.
* Fixed Google Drive attachment permissions so a teacher or admin can attach material to their own course even when they aren't personally enrolled in it.
= 6.13.1 =
* Added the live-class screen for lesson editing (provider selection, schedule, join link, and an attendance panel) — previously there was no admin screen at all for setting up a live class or reviewing who attended.
* Fixed several Google Meet attendance cases: multiple unidentified/guest participants in the same class no longer collapse into one attendance record, and the screen now explains clearly when a Meet class was created outside ATORA and therefore can never have automatic attendance.
* Fixed an open-registration gap: signing in with Google could create new accounts even when self-registration was supposed to be disabled for the Institution profile.
* Fixed a permissions gap letting any logged-in user attach a Google Drive file reference to another student's submission or an unrelated lesson.
* Hardened Google sign-in against abuse (rate limiting, stricter token checks) and against a silent failure if the encryption key protecting connected Google accounts is rotated — the account now shows "needs reauthorization" instead of just failing.
* Fixed the "N consecutive absences" alert using the order attendance was entered instead of the order the classes actually happened, and made it open a follow-up case for a student who never had one instead of only updating existing cases.
= 6.13.0 =
* Added persistent storage for live-class sessions and attendance (previously only kept as scattered post/user metadata, not queryable or reportable).
* Added Google Meet as a live-class provider alongside Zoom — creation, join links, and attendance tracking (including unidentified guests, shown as such rather than silently ignored).
* Added Google sign-in/registration, Google Calendar sync improvements, and Google Drive integration for course materials and submissions, all using your own Google Cloud project (no shared app to wait on for verification) and the least-privileged Drive access level available.
* Added an automated audit trail (who enrolled whom, who changed a grade, who exported data) and a dedicated Institution profile screen surfacing it alongside cohort and gradebook-export shortcuts.
* Connected attendance to the rest of the platform: repeated absences now surface in the teacher's follow-up queue and in the "Today" panel.
* Encrypted stored Google connection tokens at rest (previously stored in plain text).
= 6.12.0 =
* Replaced the three installation profiles with four clearer ones — Teacher, Institution, Creators, Academy — each a better fit for a specific kind of school, with no change to existing installs on upgrade.
* REST API routes belonging to a disabled module are no longer registered at all, closing a gap where deactivating a module in the admin screen didn't actually stop its API from responding.
* New installations now only create the database tables their chosen profile needs, instead of always creating every table upfront.
= 6.11.0 =
* Added a real student intervention timeline: at-risk alerts (inactivity/low-grade) and manual notes from teachers/coordinators are now recorded on a per-student history, viewable from a new student profile screen.
* Added the ability to assign a section coordinator from the existing cohort screen — previously this required a direct database edit and had no admin UI at all.
* "Today" now surfaces a real count of at-risk students for anyone assigned as a section coordinator, instead of showing nothing.
* Internal: extracted the CRM's core contact primitives (access checks, activity log, timeline) into a standalone service that no longer requires the CRM module to be active — existing CRM behavior is unchanged.
= 6.10.0 =
* Removed the public student leaderboard — competitive ranking doesn't fit the "support, not competition" approach the rest of the platform already follows.
* Added individual student badges per course, shown right on the student's own dashboard (no shortcode, no separate page): Estudiante sobresaliente, destacado, aplicado, regular, or en atención, based on punctuality, grade average, and course participation — each student is judged only against their own work, never compared to classmates.
* No changes to personal points/levels already shown on the student dashboard, or to any other screen.
= 6.9.1 =
* Fixed: the rubric listing endpoint let any instructor see every other instructor's rubrics, not just their own — the individual-rubric endpoints already scoped correctly, only the list didn't.
= 6.9.0 =
* Added a persistent search icon, visible in the same place on every ATORA admin screen — find a student, section, or contact directly, scoped to exactly what you'd already see by navigating normally.
* Added "Actividad" (Activity): a simple, calm feed of what you've already resolved this week — contacts marked, students who improved — right next to "Hoy", never mixed into it.
* Extracted the shared panel and list-row components that the academic calendar, commercial calendar, and "Hoy" each used independently — one component now, so future improvements to it apply everywhere at once. Same look, same behavior as before for existing users.
* No changes to the academic followup plans, commercial followup plans, or "Hoy" beyond this internal consolidation — everything works the same or better, never worse.
= 6.8.0 =
* Added "Hoy" (Today): a single entry screen that crosses academic followup occurrences, commercial followup occurrences, pending grading/inactive-students/quizzes, and overdue/upcoming tasks into one list, ordered by real urgency — no more deciding where to start.
* Every item links directly to the specific action (an occurrence's side panel, a contact, a task) instead of a generic listing you have to search through again.
* "Hoy" is now the default landing page after login for teachers and salespeople; administrators keep their existing flow unchanged.
* The existing daily digest message now links straight to "Hoy" instead of the general students hub.
* A calm, specific message appears when there's nothing urgent — never a blank screen.
* No changes to the academic followup plans, commercial followup plans, teacher panel, or CRM — "Hoy" only reads from them, it doesn't replace any of them.
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
= 6.11.0 =
* New: student intervention timeline and a student profile screen (reachable from at-risk notifications), plus the ability to assign section coordinators from the existing cohort screen. No database schema changes — reuses the existing CRM contact/activity tables. No changes to existing CRM/CRM v2 behavior. Recommended update.
= 6.10.0 =
* Product change: the public leaderboard shortcode is removed, replaced by individual (non-comparative) per-course student badges on the existing student dashboard. If you embedded [atora_leaderboard] anywhere, that block will stop rendering — no other screen changes. Recommended update.
= 6.9.1 =
* Security fix: instructors could list rubrics belonging to other instructors (read-only, no student data involved). Recommended update.
= 6.9.0 =
* New: persistent search and an "Actividad" feed, plus an internal consolidation of the panel/list components used by the academic calendar, commercial calendar, and "Hoy" — no database schema changes, no visible change to existing screens beyond the new search icon. Recommended update.
= 6.8.0 =
* New feature: "Hoy", a single urgency-ordered entry screen crossing academic/commercial followup plans, grading, and tasks — no database schema changes, no changes to any existing screen. Teachers and salespeople now land on it after login by default; admins are unaffected. Recommended update.
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
