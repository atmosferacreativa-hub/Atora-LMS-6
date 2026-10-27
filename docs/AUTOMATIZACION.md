# Automatización (6.38.0)

Código: `modules/automation/class-automation-triggers.php` (disparadores), `class-automation-flows.php` (motor),
`class-automation-engine.php` (acciones y cola anterior), `views/editor.php` + `assets/js/atora-automation-flow.js`
(editor). Pruebas: `tests/integration/AutomationFlowsTest.php`, `AutomationEditorViewTest.php`,
`scripts/flow-editor-browser-check.cjs` (Chromium).

## Disparadores

Un solo conjunto. Varias fuentes del mismo hecho (LMS anterior y tablas propias) disparan **una vez** por petición
(clave: disparador + persona + objeto). Un disparador sin usuario lleva el contacto; nunca se usa a otro usuario.

| Disparador | Payload (`contexto`) | Fuentes (hooks) |
|---|---|---|
| `user_registered` | — | `user_register` |
| `course_enrolled` | `course_id` | `clms_user_enrolled_in_course` (se emite en cada matrícula real desde 6.38.0), `atora/lms/enrolled` |
| `lesson_completed` | `lesson_id`, `course_id` | `clms_lesson_completed`, `atora/lms/lesson_completed` |
| `course_completed` | `course_id` | `clms_course_completed`, `atora/lms/course_completed` |
| `grade_published` | `submission_id`, `lesson_id`, `course_id`, `grade` | `clms_grade_published` (solo notas publicadas) |
| `assignment_due_soon` | `lesson_id`, `course_id`, `due_at` | `atora/academic/assignment_due_soon` (servicio de recordatorios) |
| `student_at_risk` | `course_id`, `reason` (`inactivity` \| `low_grade`) | alertas del servicio académico (`clms_ai_*_alert_generated`) |
| `inactivity_detected` | `course_id`, `days_inactive`, `progress_percent` | revisión diaria de matrículas activas sin actividad (`clms_inactivity_days_threshold`, 14) |
| `certificate_issued` | `course_id` | `clms_certificate_issued`, `atora/lms/certificate_issued` |
| `badge_earned` | `badge` | `atora/gamification/badge_earned` |
| `learning_path_updated` | — | `atora/lms/learning_path_updated` |
| `purchase_completed` | `order_id` | `woocommerce_order_status_completed` |
| `cart_abandoned` | `cart_id`, `contact_id`, `total` | `atora/cart/abandoned` |
| `ltv_updated` | `contact_id`, `amount`, `order_id` | `atora/contact/ltv_updated` |
| `email_opened`, `email_clicked` | `queue_id`, `contact_id` | webhook del proveedor de correo |
| `tag_added`, `tag_removed` | `tag`, `contact_id` | contactos (CRM) |
| `form_submitted` | `form_id`, `entry_id`, `contact_id` | formularios |
| `contact_created` | `contact_id` | CRM |
| `deal_stage_changed` | `deal_id`, `stage`, `contact_id` | CRM (el usuario que movió la etapa no es el destinatario) |

### Retirados y migrados (`Automation_Engine::migrate_6380`, idempotente, al cargar)

| Retirado | Pasa a | Motivo |
|---|---|---|
| `lesson_completed_v2`, `course_completed_v2`, `enrollment_v2` | `lesson_completed`, `course_completed`, `course_enrolled` | duplicados; sus manejadores no existían (nunca se disparaban) |
| `enrollment_completed` | `course_enrolled` | mismo hecho con otro nombre |
| `inactivity_academic` | `inactivity_detected` | dos revisiones de inactividad distintas para lo mismo |
| `campaign_opened` | `email_opened` | escuchaba `atora/email/opened`, que nada emite |
| `grade_below_threshold` | `grade_published` + condición `grade < 60` | es una nota publicada con condición |
| `webinar_registered` | se desactiva | no tenía fuente |

También se corrigen dos plantillas que los predefinidos usaban y no existían (`cart_recovery` → `cart_abandonment`,
`certificate_issued` → `certificate_ready`). El resultado queda en la opción `atora_automation_migration_report`.

## Flujos

```json
{ "version": 2, "start": "s1", "allow_reentry": false,
  "goal":  { "operator": "AND", "rules": [ { "field": "has_tag", "operator": "equals", "value": "volvio" } ] },
  "steps": [
    { "id": "s1", "type": "action", "action": { "type": "send_email", "template": "welcome_course" }, "next": "s2" },
    { "id": "s2", "type": "wait", "minutes": 4320, "next": "s3" },
    { "id": "s3", "type": "condition", "rules": { "operator": "AND", "rules": [ { "field": "course_progress", "operator": "greater_than", "value": "0" } ] }, "yes": null, "no": "s4" },
    { "id": "s4", "type": "action", "action": { "type": "send_email", "template": "inactivity_reminder" }, "next": null } ] }
```

- **Espera**: `minutes`, o `until` (fecha UTC), o `until_field` (campo del disparador, p. ej. `due_at`) con `offset_minutes`.
- **Condición**: reglas sobre el contexto, la meta `atora_{campo}` o campos especiales: `has_tag`, `risk_level`
  (`normal|medium|high`), `course_progress`, `course_completed`, `submitted_lesson`. Operadores: `equals`,
  `not_equals`, `greater_than`, `less_than`, `contains`, `is_true`, `is_false`.
- **Objetivo** (`goal`): se revisa antes de cada paso; al cumplirse el recorrido sale (`exited`, motivo `goal`).
- **Acciones**: `send_email`, `inbox_message` (buzón), `teacher_task` (aviso al docente del estudiante en ese curso),
  `add_tag`, `remove_tag`, `move_pipeline_stage`, `webhook`, `send_whatsapp`, `send_telegram`, `enroll_course`,
  `start_email_sequence`, `set_custom_field`. Un contacto sin cuenta recibe correo.
- Las automatizaciones anteriores (lista de acciones con `delay_minutes`) se leen como un flujo lineal; su cola
  (`atora_automation_queue`) se sigue procesando hasta vaciarse.

### Validación (antes de activar)

Identificadores únicos, paso inicial existente, enlaces a pasos existentes, sin ciclos, acciones conocidas, plantilla
de correo existente, etiqueta presente, webhook https, espera con minutos o fecha, condición con reglas. Un flujo
inválido se guarda como borrador pero no se activa (editor, lista y AJAX).

## Recorridos

Tablas: `atora_automation_versions` (definición por versión), `atora_automation_runs` (un recorrido por persona y
entrada), `atora_automation_run_steps` (paso ejecutado, único por recorrido).

- **Versionado**: cada cambio de definición crea una versión; quien está dentro sigue la versión con la que entró.
- **No reingreso**: por defecto una persona entra una sola vez a cada flujo; con `allow_reentry`, de nuevo cuando
  terminó el recorrido anterior.
- **Bucles**: si una acción de un flujo dispara otro y la cadena vuelve a un flujo que ya está en ella (o pasa de 5),
  el recorrido nuevo queda `blocked` con motivo `loop`.
- **Límite diario**: `atora_automation_daily_limit` (20) acciones por persona y día; al pasarlo, el recorrido sigue
  al día siguiente.
- **Fallos**: tres intentos con espera creciente (5, 25 min); luego `failed`. «Reintentar» sigue desde el paso que
  falló. Un recorrido colgado en `running` más de 30 min vuelve a la cola; lo ya hecho no se repite (paso único; los
  correos además llevan la clave del paso y la cola los deduplica).
- Cron: `atora_automation_cron` (5 min) y un evento inmediato al entrar alguien.

## Editor

Automatizaciones → **Editor de flujos**: lienzo con los pasos y las ramas sí/no, tarjetas para editar cada paso,
condiciones de entrada, objetivo, reingreso, JSON avanzado (funciona sin JavaScript), **Simular** (recorre el flujo
con los datos de un usuario sin ejecutar nada) y **Ejecutar de verdad con este contacto** (recorrido de prueba,
marcado como tal y fuera del límite diario y del no reingreso). Pestaña **Recorridos**: estado, paso, motivo y
reintento de los fallidos.

Decisión menor (anotada): el lienzo dibuja el flujo y se edita con las tarjetas; no hay arrastrar y soltar.

## Plantillas de flujo

Bienvenida a un curso · Recordatorio de tarea por vencer (sale al entregar) · Estudiante en riesgo (tarea al docente;
a la semana, si sigue en riesgo alto, escala) · Recuperación de inactivos · Felicitación por certificado. Se crean
inactivas.
