# Progreso — Orden A (CRM: retirar v1, v2 completo · 6.34.0)

- Inicio: 2026-10-23
- Estado: en curso

## Bloque 0 — Multisitio no compatible

- [ ] Activación en red bloqueada (mensaje claro).
- [ ] Aviso si se activa dentro de una red (por-sitio).
- [ ] Declarado en `readme.txt` y `docs/ESTADO.md`.
- [ ] Prueba: la activación en red se rechaza.

## Bloque 1 — Primitivas compartidas

- [ ] Servicio nuevo en `includes/contacts-core/`.
- [ ] Reapuntadas llamadas desde v2 y desde fuera del CRM.
- [ ] v1 delega al servicio.
- [ ] Pruebas existentes pasan sin cambios.

## Bloque 2 — Portar v1 → v2

- [ ] Exportación CSV + cola correo + streaming.
- [ ] API mensajes (/crm/messages/*) con permisos.
- [ ] WhatsApp/Telegram → conversaciones.
- [ ] Etiquetado automático diario + scoring recalc en cron.
- [ ] Marcar correo abierto / mensaje leído con fecha preservada.
- [ ] Pruebas por punto.

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
