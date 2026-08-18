# SECTION-DESIGN — Rediseño de cohortes/secciones (atora-lms)

> Generado automáticamente por Etapa 1. Las decisiones DC-1…DC-4 venían
> pre-aprobadas en `SECTION-DECISIONS.md`; este documento queda como
> diagnóstico de referencia y no detiene la implementación.

---

## 1. El problema: modelo plano de cohorte

### Entidad central: `CLMS_Cohort_Service` (`includes/cohorts/class-cohort-service.php`)

Post type `lm_cohort` con meta arrays planos a nivel de cohorte:

| Meta key | Tipo | Problema |
|---|---|---|
| `_clms_cohort_course_ids` | `int[]` (WP post IDs) | N cursos por cohorte |
| `_clms_cohort_teacher_ids` | `int[]` (user IDs) | M profesores — sin scoping por curso |
| `_clms_cohort_student_ids` | `int[]` (user IDs) | K estudiantes — sin scoping por profesor |
| `_clms_cohort_student_status_map` | `array<user_id, status>` | Un estado por alumno, no por curso |

**Consecuencia directa:** con `teachers=[X,Y]` y `courses=[A,B]`, el modelo
implica que X e Y imparten A y B a todos los alumnos. Es imposible expresar
"X imparte A al Grupo 1 y Y imparte B al Grupo 2".

### Solapamiento: instructor por curso

Existe un segundo mecanismo de asignación docente en el post meta de `lm_course`:

| Meta key | Consumidores | Conflicto |
|---|---|---|
| `_clms_course_teacher_ids` | `trait-metabox-course-ui.php:942`, `class-instructor.php:640`, `class-ui-teacher-sections.php:93`, `class-academic-report-service.php:1590`, `class-academic-wizard-step-renderer.php:449,586` | Compite con `_clms_cohort_teacher_ids` |

El `class-ui-teacher-sections.php` (perfil público del docente) muestra cursos
donde el profesor aparece en `_clms_course_teacher_ids` — sin relación con la
cohorte. El gradebook (`class-gradebook-renderer.php:56`) y speedgrader
(`trait-admin-menu-academic-ops.php:17`) filtran por `cohort_id` de cohorte,
pero la resolución docente usa la meta del curso. **Dos verdades paralelas.**

---

## 2. Consumidores que necesitan ser cableados a secciones

| Archivo | Línea | Uso actual | Cambio requerido |
|---|---|---|---|
| `class-gradebook-grid-service.php` | 72–75 | `filter_students_by_cohort()` usa `get_cohort_student_ids()` | Añadir `section_id` como filtro alternativo |
| `class-gradebook-renderer.php` | 41–59 | Dropdown cohorte en filtros | Añadir dropdown sección |
| `class-admin-operations-service.php` | 241–281 | Filtra submissions por cohort → courses + students | Añadir filtro por sección |
| `trait-admin-menu-academic-ops.php` | 17–241 | Speedgrader filtra por `cohort_id` | Añadir filtro `section_id` |
| `class-metabox-cohort.php` | — | Sin panel de secciones | Añadir panel de secciones asociadas |
| `modules/lms/views/cohort.php` | — | Vista de cohorte por curso vía REST | Sin cambio inmediato (la vista lee matrícula nativa) |

---

## 3. Modelo propuesto (DC-1 aplicada)

### Tablas

```
atora_sections
├── id              BIGINT UNSIGNED PK AUTO_INCREMENT
├── wp_course_id    BIGINT UNSIGNED NOT NULL   ← WP post ID de lm_course
│                   (nota: DC-1 dice atora_courses.id; usamos WP post ID
│                    para compatibilidad con el modelo legacy y evitar
│                    dependencia del estado de migración de atora_courses)
├── cohort_id       BIGINT UNSIGNED DEFAULT NULL   ← WP post ID de lm_cohort (nullable)
├── title           VARCHAR(200)
├── schedule_json   LONGTEXT NULL
├── capacity        INT UNSIGNED DEFAULT 0
├── status          VARCHAR(20) DEFAULT 'active'   ← scheduled|active|closed|cancelled
├── start_date      DATE NULL
├── end_date        DATE NULL
├── meta_json       LONGTEXT NULL   ← {source, needs_review, …}
├── created_at      DATETIME
└── updated_at      DATETIME

atora_section_teachers
├── id          BIGINT UNSIGNED PK AUTO_INCREMENT
├── section_id  BIGINT UNSIGNED NOT NULL
├── user_id     BIGINT UNSIGNED NOT NULL
├── role        VARCHAR(20) DEFAULT 'lead'   ← lead|assistant|guest (DC-2)
└── created_at  DATETIME
UNIQUE KEY (section_id, user_id)

atora_section_students
├── id          BIGINT UNSIGNED PK AUTO_INCREMENT
├── section_id  BIGINT UNSIGNED NOT NULL
├── user_id     BIGINT UNSIGNED NOT NULL
├── status      VARCHAR(30) DEFAULT 'active'
├── created_at  DATETIME
└── updated_at  DATETIME
UNIQUE KEY (section_id, user_id)
```

### Desviación documentada respecto a DC-1

DC-1 especifica `course_id (atora_courses.id)`. La implementación usa
`wp_course_id` (WP post ID de `lm_course`) por dos razones:

1. **Independencia de la migración**: no todos los cursos están migrados a
   `atora_courses`; requerir ese ID bloquearía la creación de secciones para
   cursos no migrados.
2. **Consistencia**: todo el ecosistema de cohortes, metaboxes, gradebook y
   speedgrader trabaja con WP post IDs. Usar `atora_courses.id` requeriría
   JOINs o lookups adicionales en cada punto de integración.

Si en el futuro se quiere el FK formal hacia `atora_courses`, basta renombrar
la columna y añadir un lookup en `Section_Service`; la interfaz pública no
cambia.

---

## 4. Instructor efectivo (DC-2)

La resolución del instructor de un alumno en un curso pasa a:
`Section_Service::get_effective_instructor($user_id, $wp_course_id)`

Algoritmo:
1. Buscar secciones del alumno para ese curso en `atora_section_students`.
2. Si hay sección, devolver el `user_id` del `lead` de esa sección.
3. Fallback: leer `_clms_course_teacher_ids[0]` del curso (compat legacy).

`_clms_course_teacher_ids` y `_clms_cohort_teacher_ids` se deprecan como
fuentes primarias pero **no se eliminan** (capa de compatibilidad).

---

## 5. Regla de matrícula (DC-3)

`Section_Service::add_student()`:
- Llama `CLMS_Helper::enroll_user_in_course($user_id, $wp_course_id)`.
- Luego inserta/actualiza `atora_section_students`.
- La matrícula es el prerequisito, no la consecuencia: si enroll falla, el
  alumno no queda en la sección.

`Section_Service::remove_student()`:
- Solo borra de `atora_section_students`.
- NO llama a ninguna API de baja de matrícula.

---

## 6. Proyector de cohortes (DC-4)

`Section_Projector::project_from_cohort($cohort_id)`:
- Para cada `wp_course_id` en `_clms_cohort_course_ids`:
  - Crea una sección si no existe ya (detectado por `meta_json.source`).
  - Asigna todos los `_clms_cohort_teacher_ids` como `lead` (si 1 profesor)
    o como `lead` el primero y `assistant` los demás.
  - Si hay 2+ profesores: marca `meta_json.needs_review = true`.
  - Asigna todos los `_clms_cohort_student_ids` como roster.
  - Marca `meta_json.source = "projected_from_cohort_{cohort_id}"`.
- La cohorte original no se modifica.
- Idempotente: detecta secciones ya proyectadas por el campo `source`.

---

## 7. Archivos de implementación

| Archivo (nuevo/modificado) | Descripción |
|---|---|
| `modules/lms/class-lms-section-service.php` (nuevo) | `ATORA\LMS\Section_Service` — CRUD + queries |
| `modules/lms/class-lms-section-projector.php` (nuevo) | `ATORA\LMS\Section_Projector` — proyector de cohortes |
| `modules/class-v5-installer.php` (edit) | Añadir 3 tablas + bump SCHEMA_VERSION |
| `atora_lms.php` (edit) | Cargar los 2 nuevos archivos |
| `includes/gradebook/class-gradebook-grid-service.php` (edit) | Filtro por `section_id` |
| `includes/admin-menu/trait-admin-menu-academic-ops.php` (edit) | Filtro sección en speedgrader |
| `includes/class-metabox-cohort.php` (edit) | Panel de secciones en metabox cohorte |

---

_Decisiones: ver `docs/cohorts/SECTION-DECISIONS.md`._
