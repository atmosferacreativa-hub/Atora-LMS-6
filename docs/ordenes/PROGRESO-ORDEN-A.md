# Progreso — Orden A (CRM: retirar v1, v2 completo · 6.34.0)

- Inicio: 2026-10-23
- Estado: en curso

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

- [ ] Sin referencias a `\\ATORA\\CRM\\CRM` ni `modules/crm/`.
- [ ] `modules/crm/` eliminado + desprogramar crons al actualizar.
- [ ] Menús v1 redirigen a v2.
- [ ] Prueba de humo (pantallas + rutas REST sin fatales).

## Bloque 4 — Automatización

- [ ] Implementado `get_contact_id_by_user()` en servicio compartido.
- [ ] Quitado `method_exists()`.
- [ ] Prueba: automatización etiqueta / mueve etapa realmente.

## Bloque 5 — CRM v2 completo

- [ ] Campos personalizados.
- [ ] Importación CSV avanzada.
- [ ] Deduplicación + fusión.
- [ ] Permisos por rol e institución.
- [ ] Auditoría.
- [ ] Pruebas por servicio (meta: casos principales + permisos).

## Cierre — 6.34.0

- [ ] Changelog + `docs/ESTADO.md` + actualización paridad.
- [ ] Tag `v6.34.0` + ZIP verificado + release.
