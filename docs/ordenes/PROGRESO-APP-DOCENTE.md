# Progreso — App del docente (plugin 6.40.0 · app 1.3.0)

## Bloque 1 — Contenido del curso
| Punto | Código | Prueba automática | Real |
|---|---|---|---|
| Lecciones del curso para el docente (también ocultas), tipo, vencimiento en la zona del sitio, entregas por calificar, clase en vivo | `ATORA_Mobile_Teacher_Content_Controller::lessons` | `TeacherContentApiTest::test_teacher_lists_all_lessons_in_order_with_details` | Pendiente |
| El docente abre la lección (también oculta) en vista de estudiante; el estudiante no ve la oculta; otro docente, no | `ATORA_Mobile_REST_Controller::teacher_preview` | `test_teacher_previews_lessons_including_hidden_student_does_not` | Pendiente |
| Publicar u ocultar y cambiar la fecha de entrega con aviso opcional, idempotente | `update_lesson` | `test_publish_hide_and_change_due_date_idempotent_with_notice` | Pendiente |
| El asistente con delegación ve pero no gestiona; fecha inválida 400 | `can_manage` | `test_assistant_and_invalid_input_are_rejected` | — |

Las 4 pruebas fallan con `main` y pasan con este bloque. App: pendiente (PR aparte cuando el plugin esté en `main`).
