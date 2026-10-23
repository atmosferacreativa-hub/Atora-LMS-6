# Progreso — Orden A (CRM: retirar v1, v2 completo · 6.34.0)

- Inicio: 2026-10-23
- Estado: completada (2026-10-23)

## Bloque 0 — Multisitio no compatible

- [x] Activación en red bloqueada (mensaje claro).
- [x] Aviso si se activa dentro de una red (por-sitio).
- [x] Declarado en `readme.txt` y `docs/ESTADO.md`.
- [x] Prueba: la activación en red se rechaza (`MultisiteUnsupportedTest`).

## Bloque 1 — Primitivas compartidas

- [x] Servicio compartido en `includes/contacts-core/` (usa `CLMS_Contacts_Core_Service`).
- [x] Reapuntadas llamadas desde v2 y desde fuera del CRM (se evita `\ATORA\CRM\CRM::*` salvo `get_contact_id_by_user()` pendiente de Bloque 4 y `CRM::init()` mientras exista v1).
- [x] v1 delega al servicio.
- [x] Pruebas existentes pasan sin cambios (CI verde).

PR: #76 (merge 2026-10-23).

## Bloque 2 — Portar v1 → v2

- [x] Exportación CSV + cola correo + streaming.
- [x] API mensajes (/crm/messages/*) con permisos.
- [x] WhatsApp/Telegram → conversaciones.
- [x] Etiquetado automático diario + scoring recalc en cron.
- [x] Marcar correo abierto / mensaje leído con fecha preservada.
- [x] Pruebas por punto.

PR: #78 (merge 2026-10-23).

## Bloque 3 — Eliminar CRM v1

- [x] Sin referencias a `\\ATORA\\CRM\\CRM` ni `modules/crm/`.
- [x] `modules/crm/` eliminado + desprogramar crons al actualizar.
- [x] Menús v1 redirigen a v2.
- [x] Prueba de humo (pantallas + rutas REST sin fatales).

PR: #80 (merge 2026-10-23).

## Bloque 4 — Automatización

- [x] Implementado `get_contact_id_by_user()` en servicio compartido.
- [x] Quitado `method_exists()`.
- [x] Prueba: automatización etiqueta / mueve etapa realmente.

PR: #82 (merge 2026-10-23).

## Bloque 5 — CRM v2 completo

- [x] Campos personalizados.
- [x] Importación CSV avanzada.
- [x] Deduplicación + fusión.
- [x] Permisos por rol e institución.
- [x] Auditoría.
- [x] Pruebas por servicio (meta: casos principales + permisos).

PR: #84 (merge 2026-10-23). Follow-up: #85 (tripwire de `atora_phone`, merge 2026-10-23).

## Cierre — 6.34.0

- [x] Changelog + `docs/ESTADO.md` + actualización paridad.
- [x] Tag `v6.34.0` + ZIP verificado + release.

PR: #86 (merge 2026-10-23). Release: https://github.com/atmosferacreativa-hub/Atora-LMS-6/releases/tag/v6.34.0

ZIP: `dist/atora-lms-6.34.0.zip`

SHA-256: `0004eafbe62a137ef9b43cf2a9b41f6d77dcc8ac085ff81f971b0e85ba74879a`
