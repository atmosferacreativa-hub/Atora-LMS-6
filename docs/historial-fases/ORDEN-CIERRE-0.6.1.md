# Cierre 6.30.1 · 0.6.1 — histórico de avance

> Registro histórico. El texto original de esta orden no llegó a la sesión de trabajo; el titular indicó (2026-10-05) dejarla definida a partir de lo que piden la Fase 4 y sus decisiones, como histórico de avance. Este archivo dice qué abarca el cierre y en qué estado quedó cada punto.

**Lugar en el plan:** puente entre la Fase 3 (6.30.0 / 0.6.0, cerrada) y la Fase 4 Docente (6.31.0 / 0.7.0 / 0.8.0). La Fase 4 exige como punto de partida 6.30.1 y 0.6.1 con las pruebas automáticas de pantallas funcionando en CI.

## Alcance

### Plugin 6.30.1

| # | Punto | Origen | Estado |
|---|---|---|---|
| 1 | Un borrador de calificación no llega al estudiante: hook nuevo `clms_submission_grade_draft_saved`; listeners clasificados; copias grupales heredan estado y puntajes por criterio con ajustes individuales | Decisión 1 del titular (2026-10-05), hallazgo del Paso 0 de la Fase 4 | **Hecho** (ver CHANGELOG 6.30.1) |
| 2 | Prueba que falla con el código anterior | Reglas de siempre | **Hecho**: `tests/integration/GradeDraftNoLeakTest.php` falla con 6.30.0 y pasa con 6.30.1 |
| 3 | Script de solo lectura para el demo: entregas en borrador cuyo estudiante recibió la nota | Decisión 1 | **Hecho**: `scripts/audit-draft-grade-leaks.php` (apartados A, B, C). Local: 0 / 0 / 0; detección comprobada con una fuga simulada. **Falta correrlo en el demo.** |
| 4 | PR, CI, etiqueta `v6.30.1`, ZIP | Reglas de siempre | Ver informe de avance |

### App 0.6.1

| # | Punto | Estado |
|---|---|---|
| 5 | Recorridos de pantalla automáticos en CI (emulador Android, con capturas guardadas por ejecución), empezando por `login` y los recorridos del estudiante existentes (Hoy, Aprender, Rendir, Mensajes, Agenda) | **Pendiente.** Hoy el CI de la app (`.github/workflows/ci.yml`) solo corre tipos, Jest y expo-doctor; no hay Maestro ni Detox |
| 6 | Cuentas de prueba fijas en el demo para los recorridos (estudiante; luego docente) | **Pendiente** |
| 7 | 0.6.1, `versionCode` siguiente, changelog, README, etiqueta, APK | **Pendiente** |

## Fuera del cierre (decididos para la 6.31.0, Fase 4)

- `ATORA_Grading_Save_Service`: extracción pura de `handle_speedgrade_save()` en un commit propio, con las pruebas actuales de SpeedGrader sin modificar; los cambios de comportamiento van en commits aparte.
- `ATORA_Teacher_Scope` (web y app): suma de asignación a sección (`atora_section_teachers`), autoría de curso o lección, permiso de editar lo ajeno y delegación vigente; administrador limitado a su institución resuelta desde el curso. Antes de fusionar, script de solo lectura con los usuarios que perderían acceso.
- Concurrencia: meta `_clms_submission_grade_revision`; 409 en la app y aviso "Otro docente guardó esta entrega; recarga para ver su versión" en SpeedGrader web.

## Pendiente de decisión

- Tres listeners de `clms_submission_graded` leen mal los argumentos (anterior a 6.30.1, no corregido): `CLMS_Dashboard` y `CLMS_Assessment_Engine::invalidate_cache_from_submission()` toman el id del estudiante como lección; `CLMS_Analytics::record_submission_graded()` registra lección y usuario cruzados.
