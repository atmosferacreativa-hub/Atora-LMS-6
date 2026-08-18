# DECISIONS — Migración LMS ATORA (F1–F6)

> Registro de decisiones de arquitectura y alcance de la migración del LMS.
> El agente `atora-lms-migrator` lee este archivo: una decisión sin resolver
> (estado `PENDIENTE`) es señal de **detenerse y escalar**, no de improvisar.
>
> Formato por entrada: contexto → opciones → **Decisión** → consecuencias.
> Estados: `PENDIENTE` · `DECIDIDA` · `REVISAR`.
> Al decidir: cambia el estado, rellena **Decisión**, firma con fecha e iniciales.
>
> **Este es el archivo canónico.** Cada decisión aparece una sola vez. Si una
> entrada está duplicada en el repo, esta versión manda.

---

## D-000 · Estado final de la arquitectura
**Estado:** DECIDIDA — 2026-06-15

**Decisión:** Opción A. Las tablas `atora_*` son la única fuente de verdad de
matrícula, progreso (curso y lección), calificación de curso y caducidad de
acceso. Los CPT `lm_course` / `lm_lesson` / `lm_program` se conservan **solo**
como capa de autoría (Gutenberg), permalinks y plantillas del tema Atora Studio;
se proyectan desde las tablas y nunca se leen para resolver matrícula o progreso.
No se desacopla de `wp_posts` en esta ronda.

**Consecuencias:** el cutover cambia rutas de *lectura de datos*, no el editor ni
el routing. Cualquier propuesta de eliminar los CPT entra en otra fase (no F1–F6).

---

## D-001 · ¿Los programas (`lm_program`) entran en F1? *(bloquea F1.4)*
**Estado:** DECIDIDA — 2026-06-15

**Decisión:** Opción A. Los programas/diplomados entran en F1. Se crean las tablas
`atora_programs` y `atora_program_enrollments` y se escriben los migradores
`migrate_programs()` y `migrate_program_enrollments()` con las mismas reglas de
idempotencia y fechas históricas de F1.1–F1.3.

**Consecuencias:**
- F1.4 está desbloqueado y es parte de esta ronda de migración.
- `reconcile()` incluye un check de programas/matrículas a programa.
- Mientras usermeta sea fuente de verdad (hasta F4), el compatibility layer
  sincroniza altas/bajas de `_clms_enrolled_programs` hacia `atora_program_enrollments`.

---

## D-002 · Política de quizzes, submissions, gradebook y certificados *(bloquea F1.6)*
**Estado:** DECIDIDA — 2026-06-15

**Decisión:** Migrar todo a tablas propias. Estamos en fase de desarrollo; eliminar la
mayor cantidad de legacy posible produce un software más limpio y profesional. No hay
restricción de conservar datos históricos de alumnos actuales (entorno pre-producción).

| Entidad | Decisión |
|---|---|
| Quizzes (definición/configuración) | ☑ Migra a `atora_quizzes` |
| Submissions (intentos del alumno) | ☑ Migra a `atora_quiz_submissions` |
| Gradebook (calificaciones finales) | ☑ Migra a `atora_gradebook` |
| Certificados emitidos | ☑ Migra a `atora_certificates` |

**Consecuencias:**
- F1.6 crea cuatro tablas nuevas y cuatro migradores.
- El CPT `lm_quiz` (y variantes) queda como capa de autoría (igual que cursos/lecciones).
- La lectura post-cutover sale exclusivamente de las tablas `atora_*`.
- Los campos `wp_quiz_id` / `wp_submission_id` permiten trazar el registro al CPT origen.

---

## D-003 · Representación de taxonomías/categorías de curso *(afecta F1.5)*
**Estado:** DECIDIDA — 2026-06-15 (revisada de A → B el mismo día)

**Decisión:** Opción B. Tabla pivote `atora_course_terms`.
El software se comercializará y requerirá múltiples taxonomías (categorías, nivel,
modalidad, área de conocimiento, etc.) con filtrado eficiente por término en SQL.
Una columna `meta_json.categories[]` no escala para eso.

**Esquema decidido:**
```sql
CREATE TABLE atora_course_terms (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  course_id   BIGINT UNSIGNED NOT NULL,           -- atora_courses.id
  taxonomy    VARCHAR(100)    NOT NULL,            -- 'lm_course_level', 'lm_course_category', …
  term_slug   VARCHAR(200)    NOT NULL,
  term_name   VARCHAR(200)    NOT NULL DEFAULT '',
  UNIQUE KEY uq_course_tax_slug (course_id, taxonomy, term_slug),
  KEY idx_taxonomy_slug (taxonomy, term_slug)
) ENGINE=InnoDB;
```

**Consecuencias:**
- La decisión previa (A) que escribía `meta_json.categories[]` **queda reemplazada**.
  `meta_json.categories` se depreca; `level` permanece como columna de dificultad
  (no de taxonomía). Pueden coexistir como caché denormalizado durante la transición,
  pero `atora_course_terms` es la fuente autoritativa.
- `migrate_course_categories()` se reescribe para hacer upsert en `atora_course_terms`.
- `LMS_Course_Service::get_all()` añade un parámetro `taxonomy`/`term_slug` con JOIN.
- Las taxonomías futuras de `lm_course` se recogen llamando a `wp_get_post_terms()`
  por todas las taxonomías registradas.

---

## D-004 · Origen de fecha de matrícula histórica *(afecta F1.2)*
**Estado:** DECIDIDA — 2026-06-15

**Decisión:** Confirmado el orden de prioridad implementado en `resolve_enrollment_date()`:
1. `_clms_enrollment_dates[$wp_course_id]` (usermeta, hora local del sitio → GMT).
2. `user_registered` (wp_users, ya en GMT).
3. Último recurso: `current_time('mysql', true)` marcado con
   `enrolled_at_source = 'fallback_now_orphan_user'` para ser auditable.

La fecha de pedido WooCommerce se descartó: `order_id` no siempre existe en usermeta
y la meta de matrícula es la fuente más directa y fiable.

**Consecuencias:** una pequeña fracción de matrículas antiguas sin meta de fecha y sin
`user_registered` tendrá `enrolled_at = NOW()` marcado explícitamente. Auditable y
enriquecible en el futuro.

---

## D-005 · Arquitectura de doble escritura *(F2)*
**Estado:** DECIDIDA — 2026-06-16

**Decisión:** Opción B — Fachada write-through (`LMS_Write_Facade`).
Toda escritura runtime de matrícula, progreso de curso, progreso de lección, nota
(gradebook), certificado emitido y caducidad de acceso pasa por la fachada, que
escribe en tablas y, si `atora_lms_dualwrite = true`, refleja en legacy a través de
la API legacy canónica (nunca `update_user_meta` a mano), con guarda de reentrada
estática `LMS_Write_Facade::$syncing`. La dirección legacy → tablas sigue cubierta
por `ATORA_LMS_Compatibility_Layer`.

**Motivo:** concentra la corrección en un solo camino (elimina el problema de
"dos rutas que mantener sincronizadas") y convierte F5 ("congelar legacy") en un
único interruptor: apagar `atora_lms_dualwrite` + quitar listeners del compat layer.

**Consecuencias:**
- El flag `atora_lms_dualwrite` (default `false`) gobierna solo el reflejo a legacy;
  la escritura a tablas ocurre siempre. Se activa desde Admin → Migración LMS.
- Agujeros legacy → tablas cerrados: `ajax_unenroll_user()` dispara
  `clms_user_unenrolled`; `repair_enrollments()` dispara `clms_user_enrolled`.
- La guarda de reentrada es obligatoria y se verifica con test (conteo de
  invocaciones con Brain\Monkey).

---

## D-006 · Criterio de paridad para autorizar el cutover *(F3 → F4)*
**Estado:** DECIDIDA — 2026-06-16

**Decisión:** El cutover de lectura (F4) solo se autoriza cuando el shadow-read de
F3 cumpla, simultáneamente:

1. **Umbral por lector:**
   - *Lectores críticos* — matrícula a curso (`get_user_enrolled_courses`,
     `is_user_enrolled`), caducidad de acceso y progreso de curso: **0
     divergencias**. Sin excepción.
   - *Lectores no críticos* — estimación de % por lección y progreso granular:
     se admite un residuo documentado, **nunca** en acceso ni en matrícula.
2. **Ventana de observación:** **14 días corridos** con tráfico real y 0
   divergencias en lectores críticos, que cubran al menos un ciclo de actividad
   típico de la academia (incluida la franja de mayor matrícula).
3. **Volumen mínimo significativo:** al menos **una lectura observada por cada
   alumno activo** en la ventana, para que "0 divergencias" no sea consecuencia de
   falta de tráfico.
   - **Parámetro a fijar:** censo de alumnos activos = `_____` (rellenar con el
     número real; hasta entonces el panel usa "≥1 lectura por usuario con matrícula
     activa en `atora_enrollments`").

Si cualquiera de las tres condiciones no se cumple, la ventana se reinicia tras
corregir la causa de la divergencia; no se "redondea" hacia el cutover.

**Precondición:** la caducidad de acceso (lector crítico) debe espejarse tabla→legacy
**antes** de abrir la ventana, o este gate es insatisfacible. Ver F2.3 (cierre de F2).

**Consecuencias:**
- F3 instrumenta el log de paridad y el panel con el contador frente a las tres
  condiciones (verde solo si las tres se cumplen).
- F4 se redacta recién con el reporte de paridad real que produzca F3; esta decisión
  es el gate, no el cutover.
- El cálculo de la fuente sombra nunca puede afectar la respuesta real (try/catch;
  jamás propaga excepción al lector autoritativo).

---

## D-007 · Reservada para F4 — estrategia de rollback del cutover
**Estado:** PENDIENTE (se aborda al cerrar F3)

> Apunte: definir el procedimiento exacto de vuelta atrás (toggle
> `atora_lms_read_source = legacy`), qué métricas se vigilan tras el flip y durante
> cuántos días se mantiene la doble escritura como red antes de F5.

---

## Registro de implementación

### F1.7 — Runner robusto (2026-06-15)

| Componente | Cambio |
|---|---|
| `LMS_Migrator::migrate_all()` | Cursores de continuación en `wp_options` (`atora_lms_migration_cursors`). Las funciones paginadas ya no comienzan siempre en offset 0. |
| `LMS_Migrator` (helpers) | `get_migration_cursors()`, `save_migration_cursors()`, `advance_cursor()`. |
| `LMS_REST_Controller::run_migration()` | Respuesta incluye `status` con `reconcile`, `pending_total`, `is_complete`. |
| `ATORA_LMS_Migration_Admin` | Cron `atora_lms_migration_cron` (cada 5 min) auto-detenible. Toggle AJAX. |
| Vista admin | Tabla reconcile() en vivo, loop JS hasta 0 pendientes (50 iter. máx.), toggle cron. |
| `bin/run-migration.php` | Loop WP-CLI hasta `pending_total = 0`, tope 50 iter., detección de estancamiento. |

### F1.4–F1.6 — Migradores de entidades (2026-06-15)

| Tarea | Decisión | Estado |
|---|---|---|
| F1.4 — tablas + migradores de programas | D-001 = A | ✅ `migrate_programs()`, `migrate_program_enrollments()` |
| F1.5 — tabla `atora_course_terms` + migrador | D-003 = B | ✅ `upsert_course_terms()`, `migrate_course_terms()` |
| F1.6 — migradores quizzes/submissions/gradebook/certs | D-002 | ✅ 4 métodos nuevos |
| `migrate_all()` con todos los migradores | D-001+D-002+D-003 | ✅ 10 migradores con cursores |
| `reconcile()` con programas/gradebook/certs | D-001+D-002 | ✅ 12 checks (4 nuevos) |

### F2 — Doble escritura (2026-06-16)

| Tarea | Decisión | Estado |
|---|---|---|
| F2.0 — flag `atora_lms_dualwrite` + guarda de reentrada | D-005 = B | ✅ `LMS_Write_Facade::$syncing` |
| F2.1 — cerrar agujeros legacy→tabla (metabox, maintenance) | D-005 | ✅ disparan `clms_user_(un)enrolled` |
| F2.2 — dirección tabla→legacy vía fachada | D-005 | ✅ métodos `mirror_*_to_legacy` |
| F2.3 — cobertura entidades D-002 en ambos sentidos | D-002 | ✅ `mirror_access_expiry_to_legacy()` (F2.3a) |
| F2.4 — `reconcile()` extendido + cron de reconciliación | D-001+D-002 | ✅ `atora_lms_reconcile_check` → `atora_lms_reconcile_result` |

### F2.3 — Cierre de F2 (2026-06-16)

| Tarea | Estado |
|---|---|
| F2.3a — espejo caducidad de acceso tabla→legacy | ✅ `mirror_access_expiry_to_legacy()` en facade + compat layer |
| F2.3b — deduplicar `DECISIONS.md` en el repo | ✅ única copia canónica en `lms-migration/`; `docs/lms-migration/` sincronizada |
| F2.3c — versión plugin → `6.0.8` en header/constante/readme/changelog | ✅ |

### F3 — Lectura de paridad / shadow-read (2026-06-16)

| Tarea | Archivos | Estado |
|---|---|---|
| F3.0 — `LMS_Read_Router` + flag `atora_lms_read_source` | `modules/lms/class-lms-read-router.php` | ✅ |
| F3.1 — lectores de tabla (`get_enrolled_wp_course_ids`, `is_enrolled_by_wp_id`, `get_access_expiry_by_wp_id`, `get_enrolled_wp_program_ids`) | `modules/lms/class-lms-enrollment-service.php` | ✅ |
| F3.2 — shadow hooks en 4 lectores canónicos + tabla `atora_lms_parity_log` | `includes/helper/trait-helper-academic.php`, `modules/lms/class-lms-parity.php` | ✅ |
| F3.3 — panel de paridad en admin + export CSV | `modules/lms/views/migration-admin.php`, `class-lms-migration-admin.php` | ✅ |
| Flag en `legacy` — F3 NO ejecuta el cutover | — | ✅ |

---

### Bitácora
| Fecha | ID | Cambio |
|---|---|---|
| 2026-06-15 | D-000 | Decidida: Opción A |
| 2026-06-15 | D-001 | Decidida: Opción A (programas entran en F1.4) |
| 2026-06-15 | D-002 | Decidida: migrar todo a tablas (quizzes/submissions/gradebook/certs) |
| 2026-06-15 | D-003 | Decidida: Opción B (tabla pivote `atora_course_terms`) |
| 2026-06-15 | D-004 | Decidida: enrollment_date_meta → user_registered → fallback_now |
| 2026-06-15 | F1.7 | Runner con cursores de continuación + cron auto-detenible |
| 2026-06-15 | F1.4–1.6 | 8 migradores nuevos; `migrate_all()` y `reconcile()` (12 checks) |
| 2026-06-16 | D-005 | Decidida: Opción B (fachada write-through) |
| 2026-06-16 | D-006 | Decidida: 0 divergencias críticas, ventana 14 días, volumen ≥1 lectura/alumno activo |
| 2026-06-16 | F2 | Fachada write-through, espejo bidireccional, reconcile extendido + cron |
| 2026-06-16 | F2.3 | Cierre: espejo caducidad tabla→legacy, DECISIONS.md deduplicado, versión → 6.0.8 |
| 2026-06-16 | F3 | Shadow-read: LMS_Read_Router, 4 lectores de tabla, LMS_Parity log, panel admin |
| 2026-06-16 | D-007 | Abierta: estrategia de rollback del cutover (se cierra al terminar F3) |
