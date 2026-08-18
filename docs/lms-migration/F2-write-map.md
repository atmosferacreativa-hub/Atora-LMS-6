# F2 — Mapa de escrituras runtime

> Fuente autoritativa: todas las rutas que mutan matrícula, progreso, calificación,
> certificado o caducidad. Columna **dualwrite** = ✅ si la escritura en tabla
> se espeja en legacy cuando `atora_lms_dualwrite = true`.
>
> Actualizar este mapa cada vez que se añada o elimine un punto de entrada.

---

## Matrículas de curso

| Origen | Mecanismo | Acción disparada | legacy→tabla | tabla→legacy |
|---|---|---|---|---|
| `CLMS_Helper::enroll_user_in_course()` | `update_user_meta` + `do_action('clms_user_enrolled')` | `clms_user_enrolled` | ✅ compat layer | ✅ `atora/lms/enrolled` → mirror |
| Metabox — "Matricular" | `CLMS_Helper::enroll_user_in_course()` | `clms_user_enrolled` | ✅ compat layer | ✅ idem |
| Metabox — "Quitar" | `update_user_meta` + **`do_action('clms_user_unenrolled')`** (F2.1) | `clms_user_unenrolled` | ✅ compat layer | n/a (desmatrícula) |
| `class-maintenance.php` — repair | `update_user_meta` + **`do_action('clms_user_enrolled')`** (F2.1) | `clms_user_enrolled` | ✅ compat layer | ✅ idem |
| Commerce / WooCommerce | `clms_user_enrolled` (via `class-commerce-enrollment.php`) | `clms_user_enrolled` | ✅ compat layer | ✅ idem |
| `LMS_Write_Facade::enroll()` | Tabla directa | `atora/lms/enrolled` | n/a | ✅ si dualwrite ON |
| `LMS_Write_Facade::unenroll()` | Tabla directa | `atora/lms/unenrolled` | n/a | ✅ si dualwrite ON |
| REST `POST /migration/run` | `migrate_all()` (migración histórica) | — | ✅ (migración) | n/a |

## Caducidad de acceso

| Origen | Mecanismo | Acción disparada | legacy→tabla | tabla→legacy |
|---|---|---|---|---|
| `clms_user_course_access_expiration_updated` | cualquier emisor legacy | idem | ✅ compat layer | — |
| `LMS_Write_Facade::set_access_expiry()` | Tabla directa | `atora/lms/access_expiry_set` | n/a | ✅ `mirror_access_expiry_to_legacy()` (F2.3a) |

## Progreso de lección

| Origen | Mecanismo | Acción disparada | legacy→tabla | tabla→legacy |
|---|---|---|---|---|
| Legacy `clms_lesson_completed` | cualquier emisor | `clms_lesson_completed` | ✅ compat layer | ✅ `atora/lms/lesson_completed` → mirror |
| `LMS_Write_Facade::complete_lesson()` | `LMS_Enrollment_Service::complete_lesson()` | `atora/lms/lesson_completed` | n/a | ✅ si dualwrite ON |

## Completación de curso

| Origen | Mecanismo | Acción | legacy→tabla | tabla→legacy |
|---|---|---|---|---|
| `clms_course_completed` | cualquier emisor | idem | ✅ compat layer | ✅ re-dispara `clms_course_completed` con guarda |
| `LMS_Enrollment_Service::recalculate_progress()` | auto al llegar a 100% | `atora/lms/course_completed` | n/a | ✅ si dualwrite ON |

## Matrículas de programa

| Origen | Mecanismo | Acción | legacy→tabla | tabla→legacy |
|---|---|---|---|---|
| `CLMS_Helper::enroll_user_in_program()` | `update_user_meta` + `clms_user_enrolled_in_program` | idem | ✅ compat layer | ✅ mirror si dualwrite |
| `LMS_Write_Facade::enroll_program()` | Tabla directa | `atora/lms/program_enrolled` | n/a | ✅ si dualwrite ON |
| `LMS_Write_Facade::unenroll_program()` | Tabla directa | `atora/lms/program_unenrolled` | n/a | ✅ si dualwrite ON |

## Calificaciones (gradebook)

| Origen | Mecanismo | Acción | legacy→tabla | tabla→legacy |
|---|---|---|---|---|
| `LMS_Write_Facade::set_grade()` | Tabla directa | `atora/lms/grade_set` | n/a | ✅ si dualwrite ON |
| Legacy `_clms_gradebook_course_*` | usermeta directo (no acción) | — | ⚠️ sin cubrir aún | — |

## Certificados

| Origen | Mecanismo | Acción | legacy→tabla | tabla→legacy |
|---|---|---|---|---|
| `LMS_Write_Facade::issue_certificate()` | Tabla directa | `atora/lms/certificate_issued` | n/a | ✅ si dualwrite ON |
| Legacy `_clms_certificate_record_*` | usermeta directo (no acción) | — | ⚠️ sin cubrir aún | — |

---

## Leyenda

- **✅** = cubierto
- **n/a** = no aplica (escritura ya es el origen)
- **⚠️** = pendiente de cerrar en F2.3 si el emisor legacy escribe directo en usermeta sin acción

## Gate de cierre de F2

Todas las filas de la columna `legacy→tabla` y `tabla→legacy` deben ser ✅ o n/a.
Ejecutar `reconcile()` con `atora_lms_dualwrite = true` y ejercer ambas vías sobre
un alumno de prueba: resultado debe ser 0 divergencias.
