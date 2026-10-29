# ORDEN · Foros (base)

## Paso 0 · Estado actual

### “Foro” en lecciones hoy

En ATORA LMS existe el tipo/etiqueta de actividad `foro` en lecciones (`lm_activity_type`), pero **no hay**:

- tablas ni modelo de datos de foro/tema/respuesta;
- endpoints REST para foros;
- UI web o móvil para publicar temas/respuestas;
- moderación (fijar/cerrar/ocultar/aceptar);
- reportes.

Es decir: hoy “foro” funciona solo como **clasificación de actividad** en el contenido de una lección.

### Matrícula (quién participa)

En el producto actual, la participación en experiencias de aprendizaje se resuelve por las tablas v5 y servicios de LMS:

- Curso: `\ATORA\LMS\LMS_Enrollment_Service` (matrícula) y `\ATORA\LMS\LMS_Course_Service` (mapeo WP `lm_course` → tabla v5 `atora_courses`).
- Programa: `atora_program_enrollments` (v5).
- Cohorte: `atora_cohort_members` (v5).

### Alcance docente (quién modera)

El alcance del docente se resuelve en `includes/teacher/class-teacher-scope.php` (no tocar):

- `ATORA_Teacher_Scope::has_teacher_role( $user_id )`
- `ATORA_Teacher_Scope::can_access_course( $user_id, $wp_course_id )` para validar que el curso está en su alcance (secciones activas, rol, y multi-institución/tenant).

### Delegación a instructor asistente

La delegación se resuelve por `includes/delegation/class-delegation-service.php`:

- `ATORA_Delegation_Service::covers( $assistant_id, $wp_course_id, 'content' )`
- `ATORA_Delegation_Service::covers( $assistant_id, $wp_course_id, 'grade' )`

Para foros, la moderación debe permitirse si:

- el usuario es admin (`manage_options`) en su institución; o
- el usuario es docente con alcance sobre el curso; o
- el usuario es asistente con delegación vigente (content o grade) sobre el curso.
