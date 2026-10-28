# Progreso — App del docente (plugin 6.40.0 · app 1.3.0)

## Bloque 1 — Contenido del curso
| Punto | Código | Prueba automática | Real |
|---|---|---|---|
| Lecciones del curso para el docente (también ocultas), tipo, vencimiento en la zona del sitio, entregas por calificar, clase en vivo | `ATORA_Mobile_Teacher_Content_Controller::lessons` | `TeacherContentApiTest::test_teacher_lists_all_lessons_in_order_with_details` | Pendiente |
| El docente abre la lección (también oculta) en vista de estudiante; el estudiante no ve la oculta; otro docente, no | `ATORA_Mobile_REST_Controller::teacher_preview` | `test_teacher_previews_lessons_including_hidden_student_does_not` | Pendiente |
| Publicar u ocultar y cambiar la fecha de entrega con aviso opcional, idempotente | `update_lesson` | `test_publish_hide_and_change_due_date_idempotent_with_notice` | Pendiente |
| El asistente con delegación ve pero no gestiona; fecha inválida 400 | `can_manage` | `test_assistant_and_invalid_input_are_rejected` | — |

Las 4 pruebas fallan con `main` y pasan con este bloque. App: pendiente (PR aparte cuando el plugin esté en `main`).

## Bloque 2 — Asistencia presencial
| Punto | Código | Prueba automática | Real |
|---|---|---|---|
| Sesión por curso, lección y fecha; una sola aunque lleguen dos teléfonos; la toma más reciente gana; reenvío sin cambios; hora futura del teléfono cuenta como "ahora" | `ATORA_Attendance_Service` | `AttendanceTest::test_take_attendance_one_session_per_day_and_latest_wins` | Pendiente |
| Validación y permisos (estudiante ajeno, estado, fecha, otro docente, estudiante) | `record`, rutas `/teacher/courses/{id}/attendance` | `test_validation_and_permissions` | — |
| Tres ausencias seguidas activan la racha del seguimiento; resumen en la ficha | regla existente + `summary` | `test_three_absences_in_a_row_start_the_streak` | Pendiente |
| El asistente con delegación toma lista | `can_take` | `test_assistant_with_delegation_takes_attendance` | — |
| La planificación híbrida marca su encuentro presencial y la API lo ofrece | `_atora_in_person` | `test_hybrid_plan_marks_in_person_lesson_and_api_lists_it` | — |
| Web: ATORA → Asistencia, misma lista editable | `ATORA_Attendance_Admin` | `test_web_page_saves_the_same_attendance` | Pendiente |

Las tablas de sesiones y asistencia se crean aunque el módulo de clases en vivo esté apagado (`migrate_6400_attendance`).
Las 6 pruebas fallan con `main` y pasan con este bloque.
