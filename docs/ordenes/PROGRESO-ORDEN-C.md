# Progreso — Orden C — Comunicación (publicada en 6.39.0; la orden decía 6.36.0)

## Estado
- Inicio: 2026-10-26. Bloques 0–3 en código y pruebas (2026-10-27). Publicado dentro de 6.39.0 (no hubo 6.38.0).
- PR fusionados: #116 (webhooks + boletín por lotes), #117 (Email Engine), #118 (boletín: contactos), #119 (automatización).

## Bloque 0 — Lectura
- **Mapa de envíos.** Una sola cola de correo (`atora_email_queue`, `Email_Queue`); el enrutador de mensajes
  (`atora_message_queue`) encola en ella el canal correo; el CRM v2 manda campañas por el proveedor o por
  `ATORA_Email_Gateway` → `CLMS_Email` → `wp_mail` (envíos directos de seguridad: 2FA, recuperación). No hay dos colas
  de correo independientes: no se detuvo.
- **Disparadores.** Duplicados del v1: `lesson_completed`/`_v2`, `course_completed`/`_v2`, `course_enrolled`/
  `enrollment_completed`/`enrollment_v2`, `inactivity_detected`/`inactivity_academic`; `grade_below_threshold` es
  `grade_published` con condición. Muertos: los `_v2`, insignias y rutas (sin manejador), `campaign_opened`
  (`atora/email/opened` nadie lo emite), `course_enrolled` (`clms_user_enrolled_in_course` nadie lo emitía),
  `webinar_registered` (sin fuente). Detalle y migración en `docs/AUTOMATIZACION.md`.
- **Listas.** Antes: el boletín usaba usuarios + meta `atora_consent_marketing`; las listas del CRM
  (`atora_crm_lists` + `atora_contact_list_pivot`) no se usaban para el boletín. Ahora un suscriptor es un contacto.

## Fallos graves encontrados y corregidos
- El boletín usaba una plantilla inexistente: no se enviaba a nadie. Los acentos del contenido se corrompían.
- El enlace de baja no llevaba token (no hacía nada) y el token aceptado era el ID consecutivo de la cola (cualquiera
  podía dar de baja a otra persona).
- Los avisos académicos por correo del enrutador (`atora_*`) no estaban en el catálogo: la cola los rechazaba
  («nueva lección» no salía nunca).
- Ningún código emitía `clms_user_enrolled_in_course`: no salía el correo de bienvenida al curso ni se disparaban
  automatizaciones, webhooks salientes ni el refresco del CRM al matricular.
- El aviso interno de las automatizaciones disparaba un hook sin oyentes; los disparadores de CRM sin usuario usaban
  al usuario 1 (administrador) como destinatario.

## Bloque 1 — Email Engine — hecho
Cola única con prioridad, reintentos, `GET_LOCK`, límites por proveedor, filas colgadas; webhooks firmados en los
seis proveedores con protección contra repetición; supresión por rebote permanente y queja; baja segura de un clic;
plantillas con editor, variables validadas y vista previa; panel de entregabilidad (métricas + SPF/DKIM/DMARC).
Pruebas: `EmailWebhookSecurityTest`, `EmailEngineDeliveryTest`, `MessagingEmailTemplatesTest`,
`EmailTemplateEditorTest`, `EmailDeliverabilityTest`.

## Bloque 2 — Newsletter — hecho
Suscriptores como contactos; migración idempotente con informe previo (decisión del titular: solo consentimiento
explícito); segmentos en BD; lotes con pausa/reanudación/cancelación sin duplicados; doble opt-in, baja de un clic,
preferencias y registro de consentimiento; métricas por envío y A/B. Prueba de 10.000 contactos: 0,06 s y < 1 MB
extra (límite fijado 8 MB). Pruebas: `NewsletterSendTest`.
Pendiente menor: la página de preferencias por categoría sigue siendo la genérica (la baja de un clic sí funciona).

## Bloque 3 — Automatización — hecho
Disparadores unificados con migración; motor de flujos con esperas, condiciones, objetivo, acciones (incluye tarea al
docente y mensaje en el buzón); no reingreso, bucles, límite diario, reanudación sin repetir, versionado; editor con
lienzo, validación antes de activar, modo prueba; cinco plantillas de flujo. Pruebas: `AutomationFlowsTest`,
`AutomationEditorViewTest`, `scripts/flow-editor-browser-check.cjs` (Chromium, 1280 y 375 px).
Decisión menor: el lienzo dibuja el flujo y se edita con tarjetas (sin arrastrar y soltar).

## Evidencia
| Tipo | Estado |
|---|---|
| Doc | `docs/EMAIL.md`, `docs/NEWSLETTER.md`, `docs/AUTOMATIZACION.md` |
| Código | #116, #117, #118 fusionados; automatización en PR |
| Prueba | Integración en CI (MySQL 8) y local; editor probado en Chromium |
| Real | **No**: sin cuentas reales de proveedores, sin DNS real, sin envío a buzones reales |

## Cierre
- Pendiente: versión 6.38.0, changelog, ESTADO, ZIP verificado, etiqueta (la crea el titular).
