# Progreso — Roles institucionales (plugin 6.41.0 · app 1.4.0)

## Bloque 1 — Instructor asistente
| Punto | Código | Prueba automática | Real |
|---|---|---|---|
| Aparece en el desplegable de roles | `CLMS_Access::filter_editable_roles` | `PregradeTest::test_instructor_assistant_is_in_the_roles_dropdown` | — |
| Con delegación, mismos privilegios que el instructor: gestiona lecciones y fechas, publica la planificación y define la evidencia; sin delegación, nada | `can_manage`, `ATORA_Hybrid_Plan_Service::can_publish` | `TeacherContentApiTest`, `TeacherPlanApiTest`, `HybridPlanServiceTest::test_assistant_with_delegation_has_instructor_privileges` (actualizadas: antes exigían lo contrario) | Pendiente |
| Lo que el asistente publica queda como pre-calificación (el estudiante no la ve); cola «Por validar»; el instructor valida publicando; auditoría de quién propuso y quién validó | `ATORA_Pregrade`, `ATORA_Grading_Save_Service`, `/teacher/submissions?status=to_validate` | `test_assistant_publish_becomes_pregrade_and_instructor_validates` | Pendiente |
| El instructor devuelve con comentario (obligatorio); el asistente no puede devolver | `POST /teacher/submissions/{id}/pregrade/return` | `test_instructor_returns_pregrade_with_comment` | — |
| SpeedGrader web: aviso de pre-calificación por validar o devuelta | `trait-grading-speedgrade.php` | — (vista) | Pendiente |

Hallazgo: antes de 6.41.0 el asistente con delegación ya publicaba notas directamente (el alcance docente lo incluía).
Las pruebas nuevas fallan con `main`.

## Bloque 2 — Control académico
| Punto | Código | Prueba automática | Real |
|---|---|---|---|
| Rol `lms_academic_control` en el desplegable; no califica, no edita contenido, sin API del docente | `CLMS_Access` | `AcademicControlTest::test_does_not_grade_nor_edit_content_and_has_no_teacher_api` | — |
| Nota final ajustada: motivo obligatorio, 0–100, historial con la anterior, la ve el estudiante, quitar vuelve a la calculada; parciales intactas; el instructor no corrige | `ATORA_Final_Grade_Service`, tabla `atora_final_grade_adjustments`, motor de evaluación | `test_corrects_final_grade_with_reason_and_history` | Pendiente |
| Lee informes y gestiona matrículas solo de su institución; página «Notas finales» | `ATORA_Teacher_Scope::views_institution`, `can_manage_resource_context`, `ATORA_Final_Grade_Admin` | `test_reads_reports_and_manages_enrollments_of_its_institution_only` | Pendiente |

Las 3 pruebas fallan con `main`.

## Bloque 3 — Auxiliar administrativo
| Punto | Código | Prueba automática | Real |
|---|---|---|---|
| Rol `lms_admin_assistant`: pedidos, pagos y reembolsos de la tienda (sin su configuración) | `CLMS_Access` | `AdminAssistantTest::test_cannot_change_grades_enrollments_or_content` | Pendiente (con WooCommerce real) |
| Nota final y estado en solo lectura; «No aprobado · debe repetir» y filtro; CSV | `ATORA_Finance_Academic_Admin` | `test_reads_final_grades_and_who_must_repeat` | Pendiente |
| No corrige notas, no matricula, no califica, sin API del docente ni contenido | permisos | `test_cannot_change_grades_enrollments_or_content` | — |

Los pedidos de WooCommerce no tienen institución: con varias instituciones, el auxiliar ve todos los pedidos de la
tienda. El estado académico sí se limita a su institución. Las 2 pruebas fallan con `main`.
