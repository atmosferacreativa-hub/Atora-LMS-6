# Progreso — Clases en vivo (plugin 6.39.0 · app 1.2.0)

Integración con proveedores externos (Zoom, Google Meet, Microsoft Teams). ATORA no transmite video.

## Bloques 1–3 (plugin) — PR `block/live-classes`

| Punto | Código | Prueba automática | Validación real |
|---|---|---|---|
| Una reunión por clase; reprogramar actualiza (Zoom PATCH, Teams PATCH) | `Live_Streaming::save_session` | `test_one_meeting_per_class_and_reschedule_updates_it` | Pendiente (sin cuentas reales) |
| Hora en UTC con la zona de la clase (antes: hora local tomada como UTC) | `ATORA_Live_Session_Service::utc_from_local`, repositorio | misma + `test_manual_live_class_is_read_too` | Pendiente |
| Enlace abre 10 min antes y cierra 15 min después; anfitrión al docente | `join_info`, `state` | `test_window_opens_ten_minutes_before_and_closes_after_end` | Pendiente |
| Webhook de Zoom: firma `v0:ts:cuerpo`, vigencia, una vez, validación de URL | `Provider_Zoom::handle_webhook` | `test_zoom_webhook_signature_freshness_and_url_validation` | Pendiente |
| Fin de reunión encontrado entre muchas lecciones | por tabla de sesiones | `test_zoom_meeting_ended_is_found_among_many_lessons…` | Pendiente |
| Cierre solo al terminar la clase (Meet cerraba al empezar) | `finalize_sessions` | `test_finalize_waits_for_the_end_of_class` | Pendiente |
| Recordatorios 24 h y 1 h independientes, reinicio al reprogramar, por la cola | `process_reminders` | `test_reminders_24h_and_1h_and_reset_on_reschedule` | Pendiente |
| Grabación a decisión del docente (sin / la sube después / enlace del proveedor) | `set_recording`, `clear_recording`, `provider_may_set_recording` | `test_teacher_controls_recording` | Pendiente |
| Teams por Graph: crear, reprogramar, asistencia, grabación | `Provider_Teams` | `test_teams_create_reschedule_attendance_and_recording` (Graph simulado) | Pendiente (sin inquilino real) |
| Ajustes: secretos no se muestran; vacío conserva; «borrar» elimina | `Live_Settings` | `test_settings_keep_secrets_when_left_blank` | — |
| Tarjeta de la lección: estado, hora en la zona de quien mira, botón, grabación | `render_live_class` | `test_lesson_card_shows_state_local_time_join_button_and_recording` | Pendiente |
| API de la app (`live` en la lección) y un solo evento de calendario | controlador móvil | `test_lesson_card_app_payload_and_single_calendar_event` | Pendiente |

Hallazgo adicional al cerrar el bloque: en `main` los recordatorios de clase en vivo no llegaban a nadie: llamaban a
`CLMS_Helper::get_course_students`, que no existe. Ahora usan `get_enrolled_student_ids`.

Las 12 pruebas fallan con el código de `main` y pasan con este.

PR #120 fusionado.

## Bloque 4 — App 1.2.0
- Código: tarjeta de clase en vivo (`src/components/LiveClassCard.tsx`, `src/live/liveClass.ts`); prueba Jest y
  recorrido Maestro `clase-en-vivo` con la semilla del plugin. PR app #38.
- Validación real: filas 66–67 de `docs/PRUEBA-TELEFONO.md` (pendiente).

## Cierre
- 6.39.0 (incluye Comunicación, planeada como 6.38.0), changelog, `docs/CLASES-EN-VIVO.md`, ESTADO. Etiqueta: el
  titular.
