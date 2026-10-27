# Progreso — Analíticas y riesgo académico (plugin 6.36.0)

Orden: `docs/ordenes/ORDEN-ANALITICAS.md`. Inicio: 2026-10-27.

## Bloque 0 — Lectura
- [x] Hallazgos anotados en la orden: dos reglas de riesgo con umbrales distintos, riesgo para recién inscritos,
  la app no registraba actividad, `Analytics_Engine` llamaba "riesgo" a la participación con el correo.
- Además: `CLMS_Grading::get_course_risk_level()` (`al_dia|en_riesgo|pendiente`) es un estado de curso de
  respaldo que solo se usa si el servicio de estado académico no está disponible; no se toca.

## Bloque 1 — Regla canónica
- [x] `ATORA_Academic_Risk_Rule` (regla pura + reunión de señales en un solo lugar).
- [x] `CLMS_Academic_Status_Service` y `ATORA_Student_Risk_Service` delegan en ella; las entregas vencidas
  ahora cuentan también en el estado académico.
- Evidencia: `tests/Academic/AcademicRiskRuleTest.php` (unitaria), `AcademicRiskConsistencyTest`,
  `StudentRiskServiceTest` (integración).

## Bloque 2 — Actividad desde la app
- [x] Peticiones autenticadas de la app registran acceso global y por curso (máx. una escritura cada 5 min).
- Evidencia: `AcademicRiskConsistencyTest::test_app_requests_count_as_activity`.

## Bloque 3 — Separar lo comercial
- [ ] Pendiente.

## Bloque 4 — Panel institucional y consistencia
- [ ] Pendiente.

## Cierre
- [ ] 6.36.0.

## Validación real
- No ejecutada. Pendiente: revisar en un sitio con estudiantes reales cuántos cambian de nivel (script de solo
  lectura en el cierre).
