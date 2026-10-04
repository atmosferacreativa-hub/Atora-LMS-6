# CHANGELOG — 6.5.10

## 6.5.10

- Fixed legacy messaging bridge namespace resolution.
- Fixed invalid external access to protected loader path resolution.
- Ensured required LMS parity tables are created during install/upgrade.
- Improved MySQL/MariaDB utf8mb4 index compatibility.
- Centralized Atora WP-Cron schedule registration.
- Improved schema migration idempotency.
- Added regression tests for fresh install and 6.5.9 upgrade paths.
- Preserved existing security hardening and intended functional behavior.

See `SECURITY-REPORT-6.5.10.md` for the full evidence gate (root
cause, files changed, exact fixes, and the two additional findings —
a private-method external call in `CLMS_Maintenance` and four
orphaned CRM v2 email drip-sequence tables — surfaced by this
sprint's own mandated repository-wide audits).
