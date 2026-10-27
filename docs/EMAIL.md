# Correo (Email Engine, 6.38.0)

Código: `modules/email-engine/`. Pruebas: `tests/integration/EmailWebhookSecurityTest.php`,
`EmailEngineDeliveryTest.php`, `MessagingEmailTemplatesTest.php`, `EmailTemplateEditorTest.php`,
`EmailDeliverabilityTest.php`. Ningún correo sale en las pruebas: las llamadas a los proveedores se capturan.

## Cola única

`atora_email_queue` recibe todo: transaccional, académico (también lo que manda el enrutador de mensajes), boletín y
automatizaciones.

- Prioridad: lo transaccional sale antes que lo masivo.
- Un solo lote a la vez (`GET_LOCK`) y cada fila se reclama con un `UPDATE` condicional: nunca se envía dos veces.
- Reintentos con espera creciente (5, 25, 125 min); el último intento cae a `wp_mail`.
- Límites por proveedor y por minuto/hora (`rate_limit_{proveedor}_minute|_hour`, 0 = sin límite). Lo que pasa el
  límite espera al siguiente ciclo.
- Una fila en `sending` más de 30 min (proceso caído) pasa a `failed` con la causa: no se reenvía sola porque el
  proveedor pudo haberla aceptado.
- Deduplicación por contenido y metadatos dentro de una ventana.
- Destinatarios: usuarios o contactos del CRM sin cuenta (`contact_id`).

## Supresión y baja

- `atora_email_suppression` (se crea con el Email Engine): rebote permanente → nada; queja → nada comercial.
- Enlace de baja firmado (HMAC) por usuario o por contacto. Abrirlo (GET) pide confirmar; el POST da de baja. Un clic
  de Gmail/Yahoo (RFC 8058) con `List-Unsubscribe` y `List-Unsubscribe-Post` en lo comercial, en los seis
  proveedores. La baja corta boletín, ofertas y afiliados; la cuenta y los cursos siguen avisando.
- Toda baja queda en `atora_email_consent_log` (fecha, IP, navegador, origen).

## Webhooks de proveedores

`/wp-json/atora/v1/webhooks/{proveedor}`. Firma: SendGrid (ECDSA + vigencia), Mailgun (HMAC + vigencia + token de un
solo uso), Postmark (Basic Auth: Postmark no firma), Amazon SES (firma SNS con certificado de `sns.*.amazonaws.com`,
tema opcional), Brevo (token). Cada evento se procesa una vez. Solo el rebote permanente suprime.

## Plantillas

Catálogo en `Email_Templates::get_catalog()` (incluye las académicas del enrutador, `atora_*`). Ajustes → Correo →
Plantillas: editor por bloques (título, texto, botón, separador), vista previa escritorio/móvil, texto plano
automático. Una variable que la plantilla no conoce **no deja guardar**. «Restaurar» vuelve a la original.

## Entregabilidad

Pestaña Entregabilidad: enviados, entregados, rebotes, quejas, aperturas y clics por día y proveedor; verificador
SPF, DKIM (selector configurable o los habituales del proveedor) y DMARC con el registro exacto a publicar; enlace al
envío de prueba.
