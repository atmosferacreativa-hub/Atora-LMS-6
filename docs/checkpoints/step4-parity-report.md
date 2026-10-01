# Step 4 — Paridad (Lab :8080)

## Contexto
- Plugin: ATORA LMS 6.26.71 (build `ccb7eeb`)
- Entorno: `http://192.168.1.16:8080`
- Flags (final): `dualwrite=1`, `read_source=tables`

## Evidencia / artefactos (host)
- Forensics 34/37: `/Users/imac/atora-lab-backups/reconcile-buckets-8080-20260928T130226Z/course-34-37-forensics.txt`
- Snapshot DB pre-cutover (verificado + SHA256): `/Users/imac/atora-lab-backups/reconcile-buckets-8080-20260928T130226Z/db-before-step4-final-20261001-052025.sql.gz`
- Apply logs:
  - `/Users/imac/atora-lab-backups/reconcile-buckets-8080-20260928T130226Z/step4-apply.20261001T052147Z.log`
  - `/Users/imac/atora-lab-backups/reconcile-buckets-8080-20260928T130226Z/step4-apply-fix-orphans.20261001T052241Z.log`
- Dry-run (pre-mutations): `/Users/imac/atora-lab-backups/reconcile-buckets-8080-20260928T130226Z/step4-dry-run-summary.*.md`
- Parity runner (legacy vs tables):
  - `/Users/imac/atora-lab-backups/reconcile-buckets-8080-20260928T130226Z/step4-parity-run.20261001T052652Z.json`

## Resultado de reconcile()
- Resultado final: `pending_total=0`, `is_complete=true` (ver `step4-apply-fix-orphans.*.log`).

## Paridad funcional (muestras)
### Matrículas (usuario lab `53` — `estudiante@atora.test`)
- Legacy (`_clms_enrolled_courses`) y Tables (`atora_enrollments`) reportan los mismos 8 cursos WP: `139,143,149,301,312,428,701,5561`.

### REST `/clms/v1` (usuario 53)
- `/clms/v1/me`: **PASS** (200) legacy vs tables.
- `/clms/v1/me/profile`: **PASS** (200) legacy vs tables.
- `/clms/v1/me/courses`: **PASS** (200) legacy vs tables, **8 cursos**.

### REST build probe (admin)
- `/atora/v1/build-probe`: **PASS** (200) legacy vs tables.

## Incidencias resueltas durante Paso 4
- Bug: `GET /clms/v1/me/courses` usaba IDs tabulares (`course_id`) como si fueran IDs de `wp_posts`, devolviendo 0 cursos.
  - Fix: `includes/rest/class-rest-extensions-controller.php` ahora usa `LMS_Enrollment_Service::get_enrolled_wp_course_ids()`.

## Cutover
- Gate F4: **LISTO**.
- Flip ejecutado vía CLI: `wp atora lms cutover --run`.

## Estado por subsistema
- Cursos/Lecciones (migración + reconcile): **PASS**
- Matrículas: **PASS**
- REST `/clms/v1` (muestra mínima): **PASS**
- Mobile API: **WARN** (no se ejecutó login real desde app; cubrir en Paso 5 smoke)

---
Generado: REPO `docs/checkpoints/step4-parity-report.md`

Generated at (UTC): 20261001T052720Z
