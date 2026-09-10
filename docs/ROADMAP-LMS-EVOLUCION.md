# Roadmap técnico — Evolución del LMS (rolling 6 meses)

Última actualización: 2026-09-10  
Base: ATORA LMS `6.14.1`

## Estado actual (implementado en 6.14.0)
- Epic 1 (MVP): Group Assessment (grupos por curso + entregas grupales + propagación + overrides + CSV + REST).
- Epic 2 (MVP): Rubrics v2 (pesos, escalas por curso con lock tras primera nota, holística, ejemplares, presets).
- Epic 3 (MVP): Early Warning (entregas perdidas + notificación interna + REST + pantalla admin).

**Pendiente (roadmap):** analítica de riesgo completa, coevaluación avanzada, portafolios, interoperabilidad (Classroom/Microsoft), H5P, hardening y QA ampliado.

## Objetivo
Evolucionar ATORA LMS en ciclos continuos, priorizando (1) evaluación colaborativa, (2) calidad y auditabilidad de la evaluación, (3) analítica académica y alertas tempranas, y (4) evidencia de aprendizaje (portafolios), sin comprometer compatibilidad ni performance.

## Supuestos y límites del roadmap
- Este roadmap define *epics → historias → tareas* a nivel técnico. No reemplaza descubrimiento funcional, UX, ni QA institucional.
- La implementación debe respetar compatibilidad histórica `CLMS_`/`clms_` (metas, slugs y hooks) y migraciones versionadas (ver `includes/class-clms-db-migration.php`).
- Los módulos ya existentes se reutilizan cuando aplica (p.ej. `modules/analytics/`, `modules/google/`).

## Decisiones de producto que deben cerrarse (bloquean alcance final)
1. Colaboración: ¿*Group Assessment* es “core” y debe aplicarse a tareas/lecciones desde la primera iteración?
2. Integraciones: ¿se prioriza **Google Classroom**, **Microsoft 365/Teams**, ambas, o ninguna en el próximo semestre?
3. Portafolio: ¿es feature “core” (ruta de egreso) o “diferenciador” para más adelante?
4. Escalas: ¿se estandariza escala institucional (0–20/0–100/letras) o se deja por curso/docente?
5. Privacidad: ¿anonimato por defecto en coevaluación (ciega/no ciega) y políticas de visibilidad de portafolios (privado/interno/público)?

**Decisiones cerradas para 6.14.0 (MVP)**
- Group Assessment: **sí** es core (integrado en el core; no feature flag).
- Integraciones: **no** en este ciclo (se reevalúa luego).
- Escala: se decide **al inicio del curso** y se bloquea tras la primera calificación.
- Idiomas: **solo ES** por ahora (translate global después).

## Arquitectura (líneas guía)
- **Capa de datos:** preferir tabla(s) para relaciones de alta cardinalidad (grupos↔usuarios↔curso↔lección) y auditoría; usar CPT/meta cuando el volumen sea bajo o el modelo sea editorial.
- **Compatibilidad:** no romper `clms_submission`, `clms_peer_review`, `clms_rubric` ni el gradebook; extender con nuevos campos/entidades.
- **Permisos:** cada epic debe traer capacidades (caps) claras para admin/docente/estudiante y validación en REST/AJAX.
- **Observabilidad:** eventos y logs mínimos para auditoría (quién cambió grupos, quién calificó, cuándo).

---

## Timeline (6 meses, rolling)

### Mes 0 — Preparación (1–2 semanas)
- [ ] Definir alcance del ciclo: features obligatorias, métricas de éxito y “no-goals”.
- [ ] Matriz de roles/permisos objetivo (admin, coordinación, docente, auxiliar, estudiante).
- [ ] Datos de prueba representativos y pruebas de carga mínimas (usuarios, cursos/cohortes, entregas masivas).
- [ ] Checklist de despliegue y rollback (DB migrations, feature flags, backups).

### Mes 1–2 — Pedagogía core
- Epic 1: Evaluación por grupos (*Group Assessment*)
- Epic 2: Rúbricas analíticas mejoradas (Rubrics v2)

### Mes 2–3 — Escala institucional
- Epic 3: Learning Analytics & Early Warning (risk + alerting)

### Mes 3–4 — Evidencia de aprendizaje
- Epic 5: E-portfolios
- Epic 4 (paralelo si hay capacidad): Coevaluación mejorada (delta sobre lo existente)

### Mes 4–5 — Interoperabilidad
- Epic 6: Google Classroom (bidireccional) **si aplica**
- Epic 7: Microsoft (Entra SSO + Teams/Outlook) **si aplica**

### Mes 5–6 — Contenido interactivo + hardening
- Epic 8: H5P (embed + tracking + auto-scoring) **si aplica**
- Hardening: rendimiento, permisos, UX docente, exportes, documentación operativa.

---

## Epics (detalle técnico)

### Epic 1 — Evaluación por grupos / Group Assessment (crítico)
**Resultado:** docente crea grupos por lección/actividad; una entrega representa al grupo; calificación y feedback se propagan a todos los miembros, con opción de “contribución individual”.
**Estimación:** 3–4 semanas (≈600–800 líneas netas, según UI y auditoría).

**Estado:** MVP implementado en `6.14.0` (gestión por curso, entrega grupal, propagación, overrides y REST). Pendiente: UI en contexto de lección, contribución individual, QA ampliado.

**Historias**
- [ ] Como docente, creo/edito grupos en el contexto de una lección (UI + API) y asigno estudiantes.
- [ ] Como estudiante, entrego una vez por el grupo (con bloqueo/consenso configurable).
- [ ] Como docente, califico una sola entrega grupal y ATORA replica nota/feedback a cada integrante.
- [ ] Como coordinación, audito cambios de membresía y quién entregó/qué cambió.
- [ ] Como docente, opcionalmente registro contribución individual (self/peer report o log de actividad) para ajustar nota individual.

**Tareas**
- [x] Datos: definir modelo (tablas `wp_clms_groups`, `wp_clms_group_members`, `wp_clms_group_submissions`, `wp_clms_group_grade_overrides`, `wp_clms_group_audit_log`).
- [x] Migración: extender `includes/class-clms-db-migration.php` con `dbDelta()` y sincronización de `clms_db_schema_version`.
- [x] Backend: extender flujo de `clms_submission` para soportar “submission tipo grupo” y mapping a miembros.
- [ ] Gradebook: ajustar “propagación de nota” (sin duplicar cálculos) y evitar double-grading.
- [ ] UI docente: builder de grupos en metabox/lección (o pantalla dedicada) + validaciones.
- [ ] UI estudiante: pantalla de entrega que muestre grupo, estado, y “quién entregó”.
- [x] Permisos: checks en REST para gestión de grupos y overrides.
- [x] Reportes: export de calificaciones grupales e individuales (CSV mínimo).
- [ ] QA: casos límite (cambio de grupo post-entrega, miembros sin entrega, retiro/abandono, reintentos).

**Dependencias**
- E2E con evaluación existente (manual/rúbrica/peer review), `clms_submission`, engine de calificación.

---

### Epic 2 — Rúbricas analíticas mejoradas (crítico)
**Resultado:** rúbricas con pesos por criterio, escalas configurables, ejemplares/benchmarks, holística vs analítica, versionado y presets reutilizables.
**Estimación:** 2–3 semanas (≈400–600 líneas netas, según versionado/presets).

**Estado:** MVP implementado en `6.14.0` (pesos + escalas + holística + ejemplares + presets). Pendiente: versionado/auditoría de rúbricas y QA ampliado.

**Historias**
- [ ] Como docente, asigno pesos por criterio y el total se normaliza automáticamente.
- [ ] Como docente, elijo una escala (0–4, 0–5, 0–100, letras) y la UI/gradebook se ajusta.
- [ ] Como docente, guardo ejemplares por nivel/criterio para consistencia de calificación.
- [ ] Como institución, conservo historial de versiones y puedo auditar qué rúbrica aplicó a qué cohorte.
- [ ] Como docente, uso presets institucionales para acelerar creación.

**Tareas**
- [x] Modelo: extender `clms_rubric` (CPT + meta) con `scale_type`, `is_holistic`, pesos por criterio y ejemplares.
- [x] UI admin: extender builder en `includes/class-rubric.php` (pesos, escalas, holística, benchmarks) + preset UI.
- [x] Cálculo: actualizar servicios de grading para pesos + normalización (impacta `includes/grading/`).
- [ ] Versionado: estrategia (snapshot por meta + “rubric_version_id”) o duplicado controlado del CPT con relación padre/hijo.
- [x] Presets: catálogo (CPT `clms_rubric_preset`) + permisos.
- [x] Migración: compatibilidad con rúbricas existentes sin pesos explícitos (default = pesos iguales).
- [ ] QA: pruebas con escalas mixtas, rounding, y compatibilidad con SpeedGrade.

**Dependencias**
- Epic 1 (si group assessment usa rúbrica).

---

### Epic 3 — Learning Analytics & Early Warning (crítico)
**Resultado:** scoring de riesgo por estudiante/curso, engagement, alertas predictivas y export a BI.
**Estimación:** 5–7 semanas (≈1.000–1.500 líneas netas, según UI/exportes/reglas).

**Nota:** existe `modules/analytics/class-analytics-engine.php`, pero hoy está orientado a métricas de email/engagement global. Este epic agrega *learning analytics* (académico) y alertas operativas.

**Estado:** Early Warning MVP implementado en `6.14.0` (solo “entregas perdidas”). Pendiente: risk scoring, fuentes de actividad, dashboard completo y export BI.

**MVP 6.14.0 (completado)**
- [x] Tabla `wp_atora_early_warning` + migración.
- [x] Cron diario `atora_early_warning_daily_cron` (escaneo de entregas perdidas).
- [x] Notificación interna a docente(s) (anti-spam básico).
- [x] REST `GET /wp-json/atora/v1/early-warning?course_id=...`.
- [x] Pantalla admin “Alertas tempranas”.

**Historias**
- [ ] Como coordinación, veo lista priorizada de estudiantes en riesgo (por curso/cohorte/docente).
- [ ] Como docente, recibo alertas por inactividad y entregas perdidas con acciones sugeridas.
- [ ] Como institución, exporto dataset para BI (CSV/JSON) con métricas clave.

**Tareas**
- [ ] Modelo: tabla(s) tipo `wp_atora_student_analytics` (por `user_id`, `course_id`, `risk_score`, `last_activity`, `trend`, `alert_type`, timestamps).
- [ ] Motor: calculador de señales (inactividad, missing submissions, caída de notas, no lectura de mensajes).
- [ ] Alerting: reglas + canales (panel, email, opcional webhooks/Teams) con anti-spam (cooldown).
- [ ] REST: endpoints académicos (p.ej. `/atora/v1/academics/early-warning`) con filtros (curso/cohorte/periodo).
- [ ] UI: dashboard simple (tabla + filtros + drill-down).
- [ ] Export: CSV (mínimo) y contrato de esquema (BI-friendly).
- [ ] QA: performance (batch jobs), consistencia de timestamps/timezones, y permisos.

**Dependencias**
- Identificar fuentes de “actividad” (lecciones, mensajes, submissions, calendario).

---

### Epic 4 — Coevaluación sofisticada (importante; delta sobre lo existente)
**Nota:** ya existe `includes/class-peer-review.php` con asignación, inbox, training y agregación. Este epic agrega calibración, coherencia, anonimato, auditoría y reportes.
**Estimación:** 2–3 semanas (≈400–600 líneas netas; depende de reportes y anonimato).

**Historias**
- [ ] Como docente, activo calibración (todos evalúan un ejemplar) y el sistema calcula “calibration score”.
- [ ] Como docente, veo incoherencias (desviación vs promedio/docente) y puedo intervenir.
- [ ] Como institución, audito quién evaluó a quién, cuándo, con modo ciego/no ciego.

**Tareas**
- [ ] Datos: agregar metadatos/campos para `calibration_score`, `consistency_check`, `is_blind`, `review_audit_log`.
- [ ] Flujo: training obligatorio antes de habilitar reviews reales (si aplica).
- [ ] Reportes: mapa reviewer↔reviewee + distribución de notas y desviaciones.
- [ ] Permisos/privacidad: ocultar identidad según modo; controles anti-abuso.
- [ ] QA: fairness (asignación), anonimato y edge cases (no completan reviews).

---

### Epic 5 — Portafolios digitales / E-portfolios (importante)
**Resultado:** estudiantes curan evidencias (no todo), escriben reflexión, comparten, reciben feedback y se evalúan con rúbrica.
**Estimación:** 4–5 semanas (≈700–900 líneas netas; depende de sharing/export).

**Historias**
- [ ] Como estudiante, creo un portafolio por curso/cohorte y agrego artefactos (submissions) con orden y tags.
- [ ] Como estudiante, escribo reflexiones por artefacto y a nivel portafolio.
- [ ] Como docente/pares, doy feedback al portafolio y puedo evaluarlo con rúbrica.
- [ ] Como estudiante, configuro visibilidad (privado, docentes, público) según política institucional.

**Tareas**
- [ ] Modelo: CPT `clms_portfolio` + tablas para artefactos/feedback **o** tablas dedicadas (según volumen y reporting).
- [ ] UI estudiante: CRUD portafolio + selector de artefactos + editor de reflexión.
- [ ] UI docente: vista de portafolio + feedback + evaluación (rúbrica específica).
- [ ] Permisos: visibilidad y control de acceso (incluye URLs públicas si aplica).
- [ ] Export: PDF/ZIP (nice-to-have) para acreditación.
- [ ] QA: privacidad, caching, y compatibilidad con cambios de matrícula.

**Dependencias**
- Epic 2 (rúbricas v2) si se evaluará portafolio con nuevas escalas/pesos.

---

### Epic 6 — Google Classroom (bidireccional) (condicional)
**Nota:** existe `modules/google/` (Drive/Calendar). Este epic es *Classroom* (rosters, tareas, notas).
**Estimación:** 2–3 semanas (≈300–500 líneas netas; depende de idempotencia y UI de sync).

**Historias**
- [ ] Como docente, importo tareas de Classroom como actividades/lecciones en ATORA.
- [ ] Como coordinación, sincronizo rosters (altas/bajas) y evito duplicados.
- [ ] Como docente, devuelvo notas desde ATORA a Classroom.

**Tareas**
- [ ] OAuth scopes y credenciales institucionales; manejo multi-tenant si aplica.
- [ ] Sync: mapeo `course/classroom_course_id`, `assignment_id`, `submission` y estados.
- [ ] Errores/reintentos: colas y logs (idempotencia).
- [ ] UI: estado de sincronización por curso + “reintentar”.
- [ ] QA: límites de API, paginación y pruebas con sandbox de Google.

---

### Epic 7 — Microsoft (Entra SSO + Teams/Outlook) (condicional)
**Estimación:** 2–3 semanas (≈400–600 líneas netas; depende de Graph + políticas Entra).
**Historias**
- [ ] Como usuario, inicio sesión con Microsoft Entra (OIDC/OAuth) y se aprovisiona cuenta si corresponde.
- [ ] Como docente, recibo alertas relevantes en Teams (no solo email).
- [ ] Como institución, sincronizo calendario de entregas con Outlook.

**Tareas**
- [ ] Entra SSO: flujo OIDC, linking de cuentas, políticas de MFA y logout.
- [ ] Teams: canal de notificaciones (webhooks/Graph) + plantillas.
- [ ] Outlook: calendario por curso/cohorte + actualizaciones.
- [ ] QA: seguridad (tokens, scopes), rate limits y auditoría.

---

### Epic 8 — H5P integration (nice-to-have; condicional)
**Estimación:** ~2 semanas (≈300–500 líneas netas; depende de estrategia de integración).
**Historias**
- [ ] Como docente, embebo H5P en lecciones y queda registrado el progreso.
- [ ] Como institución, capturo eventos xAPI y los uso como señal de engagement.
- [ ] Como docente, si el H5P es auto-scoreable, se refleja en el gradebook.

**Tareas**
- [ ] Integración: decidir “embed externo” vs plugin H5P en WP + puente de datos.
- [ ] Tracking: eventos xAPI y almacenamiento mínimo (agregado por usuario/lección).
- [ ] Auto-scoring: mapping a componente evaluable + validación anti-trampa.
- [ ] QA: compatibilidad móvil, accesibilidad y performance.

---

## Backlog (post-piloto / año 1–2)
- Epic 9: Competency-based grading (ligar entregas→competencias→reportes).
- Epic 10: Rubric AI calibrator (asistente para detectar patrones en calificación y sugerir criterios).
