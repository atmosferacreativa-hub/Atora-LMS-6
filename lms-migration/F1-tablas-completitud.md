# F1 — Completitud de las tablas `atora_*` (pre-cutover)

> Orden de trabajo para el agente `atora-lms-migrator`. Objetivo de la fase:
> que `LMS_Migrator::migrate_all()` deje las tablas propias **completas, con
> datos históricos fieles y sin pérdida de información**, de modo que en F3/F4
> sea posible voltear la lectura sin regresiones visibles para el alumno.
>
> **No** se cambia ninguna ruta de lectura en F1. usermeta sigue siendo
> autoritativa hasta F4. Todo es aditivo, idempotente y por lotes.

Trabaja una tarea a la vez y reporta al cerrar cada una.

---

## F1.0 — Línea base y reconciliación honesta

**Objetivo:** saber con números reales cuánto falta, antes de tocar migradores.

**Archivos:** `modules/lms/class-lms-migrator.php` (`get_status()`), nuevo método de reconciliación.

**Cambios:**
- Arreglar `get_status()`: hoy el denominador usa solo `publish`
  (`wp_count_posts()->publish`) pero el migrador procesa `publish/draft/private`.
  Igualar numerador y denominador (mismos estados en ambos lados).
- Añadir `LMS_Migrator::reconcile()` que devuelva, sin escribir nada:
  - cursos/lecciones CPT sin fila en tablas (huérfanos legacy → tabla),
  - filas en tablas sin CPT correspondiente (huérfanos tabla → legacy),
  - matrículas en usermeta sin fila en `atora_enrollments` y viceversa,
  - usuarios con `progress` en usermeta y 0 filas en `atora_lesson_progress`.

**Criterios de aceptación:**
- `get_status()` nunca devuelve `is_complete = true` si quedan pendientes reales.
- `reconcile()` corre en un sitio con datos sin escribir nada y los conteos
  cuadran con una verificación SQL manual.

---

## F1.1 — Backfill de `atora_lesson_progress` (la brecha crítica)

**Objetivo:** poblar el progreso por lección histórico. Hoy `migrate_all()`
**no** lo hace: solo copia `progress_pct` agregado a la matrícula. Como
`get_progress()` cuenta filas de `atora_lesson_progress`, sin esto **todos los
alumnos verían 0% tras el cutover**.

**Archivos:** `modules/lms/class-lms-migrator.php` (nuevo
`migrate_lesson_progress()`), `migrate_all()`.

**Cambios:**
- Localizar primero (con `Grep`) cómo guarda el sistema legacy la completación
  por lección (meta de usuario tipo `_clms_lesson_*_completed` / array de
  lecciones completadas por curso — **confirmar el formato real en el código,
  no asumir**).
- `migrate_lesson_progress(int $limit, int $offset)`: por cada lección completada
  en legacy, hacer upsert en `atora_lesson_progress` con `status='completed'`,
  `completed_at` **real** (de la meta si existe; si no, dejar `NULL`, nunca `NOW()`),
  resolviendo `lesson_id`/`course_id`/`wp_lesson_id`.
- Añadirla a `migrate_all()` **después** de cursos y matrículas.
- Tras el backfill de un curso/usuario, recalcular `progress_pct` de la matrícula
  desde las filas reales (reutilizar la lógica de `recalculate_progress`).

**Criterios de aceptación:**
- Para un alumno que completó un curso en legacy, tras correr la migración
  `get_progress()` devuelve el % correcto (no 0).
- Idempotente: correr dos veces no duplica (UNIQUE `(user_id, lesson_id)`).
- Ninguna fila nueva tiene `completed_at = NOW()` salvo que sea realmente ahora.

---

## F1.2 — Preservar fechas históricas en `migrate_enrollments()`

**Objetivo:** dejar de destruir el historial. Hoy `migrate_enrollments()` pone
`enrolled_at`, `completed_at` y `last_activity` en `NOW()`, arruinando cohortes
y retención del CRM.

**Archivos:** `modules/lms/class-lms-migrator.php` (`migrate_enrollments()`).

**Cambios:**
- Resolver `enrolled_at` real por orden de prioridad: meta de matrícula legacy →
  fecha del pedido WooCommerce asociado (`order_id`) → `user_registered`.
  Documentar la fuente usada en `meta_json` (`enrolled_at_source`).
- `completed_at`: la fecha real de completación si existe; si no se conoce, `NULL`.
- `last_activity`: última fecha conocida de actividad; si no, igual a `enrolled_at`.

**Criterios de aceptación:**
- Una matrícula migrada conserva una `enrolled_at` plausible y anterior a `NOW()`
  cuando el dato existe en origen.
- `meta_json.enrolled_at_source` indica de dónde salió la fecha.

---

## F1.3 — Migrar caducidad de acceso (`expires_at`)

**Objetivo:** no perder el control de caducidad. La columna `expires_at` existe
pero el migrador no la rellena; la expiración vive en usermeta
(`USER_COURSE_ACCESS_EXPIRY_META`, ver `trait-helper-academic.php`).

**Archivos:** `modules/lms/class-lms-migrator.php` (`migrate_enrollments()`),
`includes/class-lms-compatibility-layer.php` (sync).

**Cambios:**
- En `migrate_enrollments()`, leer el mapa de expiración del usuario y volcar la
  fecha de caducidad por curso a `atora_enrollments.expires_at`.
- Asegurar que el compatibility layer también sincronice `expires_at` cuando el
  acceso cambie en legacy (para que no diverja durante la transición).

**Criterios de aceptación:**
- Un curso con caducidad en usermeta tiene la misma `expires_at` en la tabla.
- Un curso sin caducidad queda `NULL` (acceso perpetuo), no con fecha espuria.

---

## F1.4 — Tablas y migración de **programas** (`lm_program`)

**Objetivo:** los programas/diplomados son hoy 100% legacy (no hay tabla).
Decisión a confirmar con el humano antes de codificar: *¿se migran en esta ronda
o se posponen?* Si se migran:

**Archivos:** `modules/class-v5-installer.php` (nuevas tablas, bloque aditivo
"Fase 11b"), `modules/lms/class-lms-migrator.php` (nuevos migradores).

**Cambios:**
- `dbDelta` para `atora_programs` (espejo de los campos relevantes de `lm_program`,
  con `wp_post_id` UNIQUE) y `atora_program_enrollments`
  (`user_id, program_id, wp_program_id, status, expires_at, enrolled_at, ...`,
  UNIQUE `(user_id, program_id)`).
- `migrate_programs()` y `migrate_program_enrollments()` desde
  `_clms_enrolled_programs` y metas asociadas, con las mismas reglas de fechas e
  idempotencia de F1.1–F1.3.

**Criterios de aceptación:**
- Conteos de programas y de matrículas a programa cuadran legacy ↔ tabla.
- Borrar la migración de programas no rompe nada del resto de F1 (módulo aislado).

---

## F1.5 — Taxonomías / categorías de curso

**Objetivo:** las categorías son taxonomías de CPT; no hay representación en
tabla. Para que el catálogo pueda servirse desde tablas en el futuro hace falta
mapearlas.

**Archivos:** `modules/class-v5-installer.php`,
`modules/lms/class-lms-migrator.php`, `modules/lms/class-lms-course-service.php`.

**Cambios:**
- Decidir representación (columna `category_slug` + `meta_json.categories[]`, o
  tabla pivote `atora_course_terms`). Preferir lo más simple que cubra el catálogo.
- Backfill desde las taxonomías existentes del CPT.

**Criterios de aceptación:**
- Para un curso con N categorías en CPT, la tabla refleja las mismas N.

---

## F1.6 — Política explícita para quizzes, submissions, gradebook y certificados

**Objetivo:** evitar ambigüedad. En **Opción A** es legítimo que estos sigan en
CPT/usermeta referenciados por `wp_post_id`, **pero hay que decidirlo y dejarlo
escrito**, porque definen si un alumno migrado conserva sus notas y diplomas.

**Archivos:** `docs/lms-migration/DECISIONS.md` (nuevo), y si se opta por migrar,
los migradores correspondientes.

**Cambios:**
- Registrar la decisión por entidad (quiz / submission / gradebook / certificado
  emitido): *se queda en legacy referenciado* **o** *se migra a tabla*.
- Para lo que se quede en legacy: confirmar que la lectura post-cutover lo resuelve
  vía `wp_post_id` sin depender de la matrícula en usermeta.

**Criterios de aceptación:**
- `DECISIONS.md` cubre las cuatro entidades sin huecos.
- No queda ninguna entidad cuya fuente de verdad sea ambigua tras F1.

---

## F1.7 — Runner robusto (cron + admin) y reporte de cierre de fase

**Objetivo:** poder correr la migración completa sin timeouts y dejar evidencia.

**Archivos:** `modules/lms/class-lms-rest-controller.php`,
`modules/lms/class-lms-migration-admin.php`, `modules/lms/views/migration-admin.php`.

**Cambios:**
- Runner por lotes con continuación (cron + botón admin) que recorra todos los
  migradores de F1 hasta `reconcile()` en cero pendientes.
- La vista admin muestra `get_status()` + `reconcile()` con los huérfanos en 0.

**Criterios de aceptación de la FASE F1 (gate para pasar a F2):**
- `reconcile()` reporta **0 huérfanos** en cursos, lecciones, matrículas y progreso.
- Un alumno de muestra tiene en tablas: mismos cursos, mismo % de progreso, mismas
  fechas y misma caducidad que en legacy.
- Toda la migración es repetible sin duplicar y sin un solo `NOW()` en datos
  históricos.
- Cero cambios en rutas de lectura: el frontend sigue leyendo de usermeta.

---

### Fuera de alcance de F1 (no lo toques aquí)
Doble escritura (F2), cambio del lector canónico / shadow-read (F3), cutover (F4),
congelar legacy (F5) y desmantelar usermeta (F6). Tampoco CRM, email ni features
académicas nuevas.
