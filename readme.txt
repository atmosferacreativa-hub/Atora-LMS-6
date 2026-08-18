=== ATORA LMS ===
Contributors: mundocap, atmosferacreativa
Tags: lms, learning, courses, education, ai, grading, certificates
Requires at least: 6.4
Tested up to: 6.4
Requires PHP: 8.1
Stable tag: 6.5.1
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
= 6.5.1 =
* Security fixes for CRM permissions, arbitrary email sending, WhatsApp verification enforcement, and MCP API key rate limiting. Recommended update.
= 5.0.0 =
* Recommended update for the ATORA_v5 branch.
