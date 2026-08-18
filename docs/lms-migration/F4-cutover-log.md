# F4 — Cutover Log (pre-flight + registro de flip)

> Este documento sirve dos propósitos:
> 1. **Pre-flight checklist** — cada condición debe verificarse y registrarse como ✅ antes de ejecutar el flip.
> 2. **Bitácora del flip** — quién lo ejecutó, cuándo y qué estado tenían las métricas en ese momento.
>
> El agente de código NO puede verificar las condiciones 1–5 (requieren el estado
> runtime de la base de datos de producción). Un humano con acceso al panel de migración
> y a la BD debe marcar cada item antes de usar el botón "Ejecutar flip".

---

## Condiciones de gate (D-006) — verificar TODAS antes del flip

| # | Condición | Cómo verificar | Estado |
|---|---|---|---|
| 1 | **Panel de paridad VERDE** — 0 divergencias en los 5 lectores críticos durante 14 días corridos | Panel F3 en `Admin > Migración LMS`: todos los contadores en verde. | ⬜ PENDIENTE |
| 2 | **`volume_ok = true`** — alumnos observados ≥ alumnos activos en tablas | Panel F3, barra de volumen al 100%. | ⬜ PENDIENTE |
| 3 | **`reconcile()` en 0 pendientes/huérfanos** | Panel `Admin > Migración LMS`, tabla de reconciliación: todos en 0. | ⬜ PENDIENTE |
| 4 | **`atora_lms_dualwrite = true` y funcionando** | Toggle en el panel muestra "● Activo". Prueba rápida: inscribir un usuario de test y verificar que la matrícula aparece en `atora_enrollments` Y en `_clms_enrolled_courses`. | ⬜ PENDIENTE |
| 5 | **Backup de BD tomado y verificado** | No solo programado — restaurar un dump de prueba o verificar el checksum del archivo. | ⬜ PENDIENTE |

**Ventana recomendada para el flip:** horario de bajo tráfico (madrugada o fin de semana). El flip es instantáneo (toggle de option), pero la vigilancia post-cutover debe poder iniciarse de inmediato.

---

## Registro del flip (completar al ejecutar)

| Campo | Valor |
|---|---|
| **Fecha y hora (UTC)** | — |
| **Ejecutado por** | — |
| **Divergencias en lectores críticos al momento del flip** | — |
| **`volume_ok`** | — |
| **Pendientes en `reconcile()`** | — |
| **`atora_lms_dualwrite`** | — |
| **Backup confirmado** | — |
| **Valor de `atora_lms_cutover_at` registrado** | — |

---

## Post-flip: ventana de vigilancia (F4.3 / D-007)

Vigilar durante **7–14 días** (ver D-007):

- Panel F4 en `Admin > Migración LMS`: divergencias PC en 0, días estables incrementando.
- Log de errores PHP: sin picos atribuibles a la ruta de lectura de tablas.
- Flujos clave a probar manualmente tras el flip:
  - [ ] Matrícula nueva → aparece en "Mis cursos"
  - [ ] Acceso a lección con caducidad configurada → rechazado al expirar
  - [ ] Completar lección → progreso actualizado
  - [ ] Emisión de certificado → funciona
  - [ ] Inscripción en programa → refleja acceso a todos los cursos

### Disparadores de rollback (D-007)

Cualquiera de los siguientes activa el rollback **inmediatamente** (botón "↩ Rollback" en el panel):

1. Una sola divergencia en un lector crítico post-cutover (`pc_*` en el panel F4).
2. Un incidente de acceso o matrícula confirmado (reporte de usuario o ticket de soporte).
3. Pico anómalo en el log de errores PHP atribuible a la ruta de tablas.

El rollback es `atora_lms_read_source = 'legacy'` — sin deploy, instantáneo.
Tras el rollback: diagnosticar, corregir, volver la ventana de paridad a 0, y reiniciar el flip sin reintento el mismo día.
