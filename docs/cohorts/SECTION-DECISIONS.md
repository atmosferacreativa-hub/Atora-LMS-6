# SECTION-DECISIONS — Rediseño de cohortes/secciones (atora-lms)

> Decisiones de modelado para el agente `atora-cohort-sections`. Mismo contrato que
> la migración: una decisión en `PENDIENTE` = el agente se detiene y escala.
>
> Estas cuatro (DC-1…DC-4) vienen **redactadas como `DECIDIDA`** con la opción
> recomendada, para que el agente haga su diagnóstico (`SECTION-DESIGN.md`) y pase
> directo a la Etapa 2 (implementación) sin frenarse. Ajusta cualquiera antes de
> lanzar si discrepas. Colócalo en `docs/cohorts/` y, al invocar al agente, dile:
> *"DC-1…DC-4 ya resueltas en docs/cohorts/SECTION-DECISIONS.md; haz el diagnóstico
> y procede a Etapa 2."*

---

## DC-1 · ¿Sección en tablas o en CPT/meta?
**Estado:** DECIDIDA — 2026-06-17

**Decisión:** **Tablas.** La sección es una entidad relacional de primera clase:
- `atora_sections` (`id`, `course_id`, `cohort_id` nullable, `title`,
  `schedule_json`, `capacity`, `status`, `start_date`, `end_date`, `meta_json`).
- `atora_section_teachers` (`section_id`, `user_id`, `role`; UNIQUE `(section_id,user_id)`).
- `atora_section_students` (`section_id`, `user_id`, `status`; UNIQUE `(section_id,user_id)`).

**Motivo:** coherente con la dirección tabla-first del proyecto (D-000 y D-003).
Las consultas centrales —"las secciones de este profesor", "las secciones de este
curso", roster por sección— necesitan JOINs eficientes; los meta-arrays planos son
justamente lo que causó el problema actual. El esquema va por `dbDelta` aditivo en
`modules/class-v5-installer.php`, idempotente.

**Consecuencias:** se introduce nuevo esquema `atora_*`; no se toca el esquema de la
migración del LMS. La cohorte `lm_cohort` se conserva como agrupador opcional.

---

## DC-2 · ¿Una sección admite varios profesores?
**Estado:** DECIDIDA — 2026-06-17

**Decisión:** **Sí, con rol.** `atora_section_teachers.role` admite:
- `lead` (titular) — **exactamente uno por sección, obligatorio**.
- `assistant` (asistente/TA) — cero o más.
- `guest` (invitado) — cero o más.

**Motivo:** refleja la realidad (co-docencia, ayudantías) sin forzarla: el caso
simple es un solo `lead`. El "instructor efectivo" de un alumno es el `lead` de su
sección.

**Consecuencias:** la UI de sección permite un titular y profesores adicionales.
La resolución de instructor por curso pasa a leer el `lead` de la sección del alumno,
deprecando `_clms_course_instructor` y los `teacher_ids` de cohorte (con capa de
compatibilidad, no borrado).

---

## DC-3 · ¿Pertenecer a una sección implica matrícula en el curso?
**Estado:** DECIDIDA — 2026-06-17

**Decisión:** **Alta sí, baja no.**
- Añadir un alumno a una sección **garantiza** su matrícula en el curso de esa sección
  (idempotente). Se hace **siempre por la API canónica de matrícula** (p. ej.
  `CLMS_Helper::enroll_user_in_course()` / la fachada `LMS_Write_Facade`), **nunca**
  escribiendo `atora_enrollments` a mano, para respetar la doble escritura de F2 y no
  contaminar la ventana de paridad de F3.
- Quitar a un alumno de una sección **no** lo desmatricula del curso. La baja de
  matrícula es siempre una acción explícita y separada.

**Motivo:** evita el "split-brain" de un alumno asignado a una sección pero sin
matrícula (rompería acceso y gradebook). Mantiene una sola fuente de verdad: estás
matriculado en el curso; la sección define profesor/grupo/horario. La asimetría
alta/baja es la opción segura (nunca revoca acceso por un cambio de grupo).

**Consecuencias:** el `Section_Service` depende de la API de matrícula existente, no
de sus tablas. Mover a un alumno entre secciones del **mismo** curso no toca su
matrícula; moverlo a una sección de **otro** curso lo matricula en el nuevo (sin
quitarlo del anterior).

---

## DC-4 · ¿Proyectar las cohortes existentes a secciones o partir de cero?
**Estado:** DECIDIDA — 2026-06-17

**Decisión:** **Proyectar, best-effort, idempotente y no destructivo.** Para cada
`lm_cohort` existente, crear **una sección por cada curso** de la cohorte, heredando
sus `teacher_ids` como profesores de la sección y sus `student_ids` como roster.
Marcar cada sección generada con `meta_json.source = "projected_from_cohort_{id}"`
para auditoría. La cohorte original se conserva intacta.

**Límite honesto:** el modelo plano actual es ambiguo donde más importa. Si una
cohorte tiene `teachers=[X,Y]` y `courses=[A,B]`, la proyección no sabe quién da qué,
así que asigna **todos los profesores listados a cada sección** y **marca esas
secciones para revisión manual** (`meta_json.needs_review = true`). El proyector
**siembra** la estructura; el humano refina los repartos profesor↔curso↔grupo que el
modelo viejo nunca pudo expresar.

**Motivo:** la academia tiene datos reales; partir de cero perdería las agrupaciones.
La proyección da un punto de partida útil y auditable sin destruir nada.

**Consecuencias:** el proyector es re-ejecutable sin duplicar (UNIQUE keys + flag de
origen). Un reporte lista las secciones `needs_review` para que el equipo académico
las ajuste.

---

### Bitácora
| Fecha | ID | Decisión |
|---|---|---|
| 2026-06-17 | DC-1 | Tablas: `atora_sections` + `_section_teachers` + `_section_students` |
| 2026-06-17 | DC-2 | Varios profesores con rol; un `lead` obligatorio por sección |
| 2026-06-17 | DC-3 | Alta en sección ⇒ matrícula (vía API canónica); baja no desmatricula |
| 2026-06-17 | DC-4 | Proyectar cohortes existentes, best-effort + `needs_review`, no destructivo |
