# Boletín (6.38.0)

Código: `modules/newsletter/class-newsletter.php` (envío), `class-newsletter-subscribers.php` (suscriptores).
Pruebas: `tests/integration/NewsletterSendTest.php`.

## Suscriptores

- El boletín es una lista del CRM (`atora_crm_lists`, slug `boletin`). Un suscriptor es un contacto
  (`atora_contacts`), con o sin cuenta; el correo se normaliza y no se duplica.
- Estados en `atora_contact_list_pivot`: `subscribed`, `pending` (falta confirmar), `unsubscribed`.
- **Doble opt-in** (activo por defecto, `atora_newsletter_double_optin`): el alta queda pendiente hasta abrir el
  enlace firmado del correo «Confirmar suscripción». A un usuario con cuenta siempre se le pide confirmar.
- Formulario público: `[atora_boletin]` (casilla de consentimiento obligatoria, campo trampa, 10 intentos por hora
  e IP). La respuesta no revela si el correo ya estaba suscrito.
- Consentimiento registrado en `atora_email_consent_log` (fecha, IP, origen) en alta, confirmación y baja.
- Baja: enlace del pie (un clic) o baja general de la cuenta.

## Migración desde el modelo anterior

Antes eran usuarios con la meta `atora_consent_marketing`, y sin meta se enviaba igual. Decisión del titular
(2026-10-27): **solo el consentimiento explícito queda suscrito**.

| Antes | Después |
|---|---|
| marca `1` | suscrito |
| sin marca | pendiente (no recibe nada hasta confirmar; «Pedir confirmación a los pendientes» manda el correo una vez) |
| marca `0`, baja general o dirección suprimida | no entra |

El registro extendido ya guarda `0` a quien no marca la casilla. Antes de migrar:
`wp eval-file scripts/newsletter-subscribers-report.php` (solo lectura). La migración es idempotente (botón en
Boletín o `Newsletter_Subscribers::migrate()`).

## Envío

- Por lotes de 200 por cron (`atora_nl_send_batch`), con bloqueo por boletín. Pausar, reanudar y cancelar (admin y
  REST `atora/v1/newsletters/{id}/pause|resume|cancel|status|metrics`).
- Nadie recibe dos copias: se comprueba en la cola por contacto (aunque el cron se corte o se reinicie tras cancelar)
  y hay ventana de deduplicación de 30 días.
- Segmentos resueltos en la base de datos, paginados: etiqueta, lista, curso, programa, institución, estado de
  matrícula, scoring del CRM, actividad (activo en los últimos N días / inactivo N días) y meta `atora_{campo}`.
  Prueba: 10.000 contactos en páginas de 500 con menos de 1 MB extra (límite fijado en la prueba: 8 MB).
- A/B de asunto determinista por contacto; el ganador se elige a las 24 h.
- Avisos automáticos de entradas del blog y episodios de podcast: se envían como un boletín más (por lotes, una vez
  por origen).

## Métricas por envío

En cola, enviados, entregados, aperturas, clics (y por enlace), rebotes, quejas y bajas de quienes lo recibieron.
Entregados, rebotes y quejas dependen de que el webhook del proveedor esté configurado.
