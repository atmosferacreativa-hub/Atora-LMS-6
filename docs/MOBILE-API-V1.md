# ATORA Mobile API v1

Namespace: `/wp-json/atora-mobile/v1`

## Seguridad

- HTTPS obligatorio salvo entorno WordPress `local` o `ATORA_DEV_MODE`.
- Access tokens opacos de 15 minutos.
- Refresh tokens opacos de 30 días.
- Rotación del refresh token en cada renovación.
- Revocación inmediata por sesión.
- Máximo de cinco sesiones activas por usuario.
- Solo se almacenan hashes de los secretos.
- Login limitado por combinación de usuario e IP resuelta por `ATORA_Client_IP`.
- Las rutas académicas fuerzan ownership mediante la matrícula del usuario actual.

## Endpoints

| Método | Ruta | Autenticación | Uso |
|---|---|---|---|
| GET | `/discovery` | Pública | Detectar instancia, versión y capacidades |
| POST | `/auth/login` | Pública, limitada | Iniciar sesión y emitir tokens |
| POST | `/auth/refresh` | Refresh token | Rotar la sesión |
| POST | `/auth/logout` | Bearer | Revocar la sesión actual |
| GET | `/me` | Bearer | Perfil del usuario |
| GET | `/dashboard` | Bearer | Resumen y cursos del estudiante |
| GET | `/courses` | Bearer | Matrículas del estudiante |
| GET | `/courses/{id}` | Bearer + matrícula | Curso, progreso y currículo (cada lección con `has_video` y `video_thumbnail_url`) |
| GET | `/programs` | Bearer | Programas del estudiante |
| GET | `/programs/{id}` | Bearer + matrícula | Programa y sus cursos |
| GET | `/lessons/{id}` | Bearer + matrícula | Contenido de la lección (`quiz_available`, `assignment_available`, `video_thumbnail_url`; desde 6.28.0 `video_downloadable`, `video_bytes`, `resume_position_seconds` y, en cada recurso, `downloadable`, `bytes`, `updated_at`) |
| PUT | `/lessons/{id}/position` | Bearer + matrícula (si no, 404) | Guardar posición de reproducción (6.28.0); `video_key` opcional (6.28.2) |
| GET | `/sync/changes?cursor=` | Bearer | Cambios desde el cursor, solo de los cursos matriculados (6.28.0) |
| GET | `/grades` | Bearer | Resumen de notas por curso y por programa (6.29.0) |
| GET | `/courses/{id}/grades` | Bearer + matrícula | Nota por actividad (6.29.0) |
| GET | `/certificates` | Bearer | Certificados obtenidos con enlace firmado (6.29.0) |
| GET | `/certificates/{course\|program}/{id}/document?expires=&sig=` | Bearer del mismo usuario + firma vigente | Documento HTML del certificado (6.29.0) |
| GET, POST | `/lessons/{id}/quiz` | Bearer + matrícula | Consultar y responder la evaluación |
| POST | `/lessons/{id}/complete` | Bearer + matrícula | Completar lección |
| GET | `/assignments/{lesson_id}` | Bearer + matrícula | Consigna, límites y entregas propias (6.27.0) |
| POST | `/assignments/{lesson_id}/submissions` | Bearer + matrícula | Crear intento de entrega (6.27.0) |
| POST | `/uploads/sessions` | Bearer + matrícula | Iniciar subida reanudable (6.27.0) |
| PUT | `/uploads/{upload_token}` | Bearer, dueño del token | Subir fragmento con `Content-Range` (6.27.0) |
| POST | `/uploads/{upload_token}/complete` | Bearer, dueño del token | Cerrar subida y validar tipo real (6.27.0) |

Entregas: contrato completo en `docs/CONTRATO-ENTREGAS-MOVIL.md`; reglas de sincronización en `docs/SINCRONIZACION-OFFLINE.md`. `GET /discovery` declara `capabilities.assignments: true` desde 6.27.0.

## Sincronización incremental (6.28.0)

`GET /sync/changes` sin cursor devuelve el estado completo resumido (cada curso matriculado y sus lecciones publicadas); con `cursor`, solo lo que cambió desde entonces. Siempre devuelve `next_cursor` (opaco). Más de 200 elementos: `has_more: true` y se pide la página siguiente con el `next_cursor` recibido.

```json
{
  "items": [
    { "type": "lesson", "id": 12, "course_id": 3, "revision": 8, "removed": false },
    { "type": "course", "id": 3, "course_id": 3, "revision": 5, "removed": false },
    { "type": "enrollment", "id": 7, "course_id": 7, "active": false }
  ],
  "has_more": false,
  "next_cursor": "eyJ2IjoxLCJpZCI6NDJ9",
  "full": false,
  "reset": false,
  "enrolled_course_ids": [3]
}
```

- Respuesta compacta: identificadores y revisiones. La app pide después el detalle (`/courses/{id}`, `/lessons/{id}`) de lo que cambió.
- `removed: true`: la lección o el curso se despublicó, se envió a la papelera o se borró. Borrar su contenido y sus descargas locales.
- Varios guardados del mismo objeto llegan como un solo elemento con la revisión actual.
- `reset: true`: el cursor es más antiguo que lo que conserva el servidor (90 días) o ilegible. La respuesta es el estado completo (`full: true`); la app reemplaza su índice local.
- `enrolled_course_ids`: matrículas vigentes en cada respuesta. Cubre también las caducidades y las matrículas legacy, que no generan evento; un curso que sale de la lista se borra localmente.
- Cambios detectados: todo lo que se edita en el editor de WordPress (texto, título, orden, estado, recursos, videos, consignas, fechas, tipo de actividad) y lo que se edita por la API de tablas. Las preguntas de evaluaciones no forman parte del registro.

## Descargas (6.28.0)

- Recurso `downloadable: true` solo si es un adjunto de la biblioteca de la academia o una URL del mismo dominio que apunta a un archivo. Google Drive, YouTube, Vimeo y otros sitios: `false` ("Solo con conexión").
- `bytes` y `updated_at` vienen del adjunto (`null` si no se conocen). Un `updated_at` distinto al guardado indica "Actualización disponible".
- Video: `video_downloadable: true` solo para MP4 directo de la academia; `video_bytes` cuando el archivo es un adjunto.

## Posición de reproducción (6.28.0)

`PUT /lessons/{id}/position` con `position_seconds`, `duration_seconds`, `client_event_id` (8–64 caracteres `[A-Za-z0-9_-]`) y `client_recorded_at` (ISO 8601).

```json
{ "applied": true, "replayed": false, "position": { "position_seconds": 754, "duration_seconds": 1800, "client_recorded_at": "2026-10-04T15:02:11.000Z" } }
```

Gana la marca más reciente según `client_recorded_at` (regla en `docs/SINCRONIZACION-OFFLINE.md`). Una marca más antigua responde `applied: false` con la posición vigente; reenviar el mismo `client_event_id` responde `replayed: true`. `GET /lessons/{id}` devuelve `resume_position_seconds`.

`GET /discovery` declara `capabilities.sync_changes`, `capabilities.playback_position` y `capabilities.resource_downloads`.

## Login

```json
{
  "login": "estudiante@example.com",
  "password": "secreto",
  "device_name": "iPhone de Carlos"
}
```

La contraseña solo se usa para autenticar contra WordPress y nunca se almacena en la sesión móvil.

## Autorización

```http
Authorization: Bearer {access_token}
```

## Renovación

```json
{
  "refresh_token": "{refresh_token}",
  "device_name": "iPhone de Carlos"
}
```

La renovación invalida access y refresh tokens anteriores de esa sesión.

## Miniaturas de video (6.27.1)

`video_thumbnail_url` se resuelve en este orden: miniatura manual del editor de la lección → YouTube → Vimeo (en caché; la primera vez se consulta en segundo plano) → imagen destacada de la lección → portada del curso. Google Drive y MP4 directo no tienen miniatura desde el servidor: la app puede generar una localmente. Nunca viene vacía si el curso tiene portada.


## Varios videos por lección (6.28.2)

`GET /lessons/{id}` suma `videos[]`: la misma lista que muestra la web, en el orden del editor y con el límite de videos de la lección.

```json
{
  "key": "a5016cd483f3",
  "title": "Introducción",
  "description": "",
  "source": "youtube",
  "url": "https://www.youtube.com/watch?v=…",
  "embed_url": "",
  "provider": "youtube",
  "thumbnail_url": "https://img.youtube.com/vi/…/hqdefault.jpg",
  "downloadable": false,
  "bytes": null,
  "resume_position_seconds": 0
}
```

- `provider`: `google_drive` (con `embed_url`), `youtube`, `vimeo` o `direct`. Solo `direct` de la academia puede ser `downloadable`.
- `key` es estable: no cambia al reordenar. Si la misma URL aparece dos veces, la segunda lleva `-2`.
- Compatibilidad: `video_url`, `video_embed_url`, `video_provider`, `video_thumbnail_url`, `video_downloadable` y `resume_position_seconds` siguen describiendo el **primer** video.
- El currículo de `/courses/{id}` suma `video_count`.
- `PUT /lessons/{id}/position` acepta `video_key`. Sin él, la posición es la del primer video (apps anteriores). Una clave que no es de la lección responde 404.
- Errores de base de datos al guardar posición, subidas o entregas responden **503**: reintentar.
- `GET /discovery` declara `capabilities.multi_video`.


## Notas y certificados (6.29.0)

**Regla:** el estudiante nunca ve en la app una nota que no vería en la web. Toda nota sale de `CLMS_Student_Grades_Service` y de `CLMS_Student_Grade_Visibility`: una nota guardada en SpeedGrader sin publicar (`in_review`) no aparece ni cuenta en el promedio.

- `GET /grades` → `courses[]` (`course_id`, `title`, `final_grade` (`null` = sin notas; `0` = nota cero, distintos desde 6.29.5), `passing_grade`, `progress`, `status`: `not_started`, `in_progress`, `at_risk`, `approved`, `not_approved`; desde 6.29.1 `graded_count` y `last_graded_at`, solo de notas liberadas), `programs[]` (`final_grade`, `progress`, `courses`) y `generated_at`.
- `GET /courses/{id}/grades` → `course` (igual que arriba) y `activities[]` (`lesson_id`, `title`, `kind`: `assignment`/`quiz`/`activity`, `weight_label`, `weight`, `grade` o `null`, `status`: `graded`, `in_review`, `needs_revision`, `completed`, `pending`, `has_feedback`, `graded_at`).
- `GET /assignments/{lesson_id}`: cada intento suma `rubric` (`rows[]` con `name`, `competency`, `score`, `max`, `level` (desde 6.29.2; desde 6.29.3 con las bandas de SpeedGrader: la etiqueta del nivel si el puntaje es exacto, "entre X y Y" si cae entre dos niveles, "por debajo de X" bajo el primero; puntaje decimal sin truncar; vacío si ninguna banda lo contiene), `feedback`; `strengths`, `reinforce`, `recommendation`) solo con la nota liberada, e `in_review: true` mientras está en revisión.
- `GET /certificates` → `certificates[]` (`type`, `id`, `course_id`, `title`, `status`: `issued`, `available`, `revoked`, `issued_at`, `certificate_code`, `download_url`, `download_expires_at`). El enlace vence a los 15 minutos y solo sirve con el token del mismo usuario; pedir la lista otra vez da uno nuevo. El documento es HTML (provisional) para guardarlo y verlo sin conexión.
- `GET /discovery` declara `capabilities.grades` y `capabilities.certificates`.

## Mensajes, agenda, Hoy y notificaciones (6.30.0)

**Buzón propio.** Mensajes y avisos viven en tablas (`atora_message_threads`, `atora_message_participants`, `atora_messages`), ya no en las metas `clms_internal_messages` (tope 100) y `clms_notifications` (tope 50). `CLMS_Messaging` y `CLMS_Notifications` conservan su API pública. Cada usuario tiene un hilo `system` llamado **Avisos**: cada aviso lleva su `kind` (`submission_graded`, `lesson_published`, `course_access`, `early_warning`…) y su enlace. Los avisos del personal (`early_warning`, `learning_analytics`, `submission_created`) nunca llegan a un estudiante. **Un solo contador de no leídos** (mensajes + avisos) para la campana web, el panel, el buzón docente y la app. Retención: los avisos leídos de más de 180 días se borran (cron diario); los mensajes de conversación no.

- `GET /messages/threads?cursor=` → `avisos` (el hilo Avisos, siempre presente; la app lo fija arriba), `threads[]` (`id`, `type`: `direct`, `title`, `course_id`, `unread`, `can_reply`, `last_message_at`, `last_message`: `id`, `kind`, `preview`, `mine`), `next_cursor` y `unread` (contador único).
- `GET /messages/threads/{id}?before=` → `thread` y `messages[]` (30, del más nuevo al más viejo: `id`, `thread_id`, `kind`, `author` {`id`, `name`} o `null` en avisos, `mine`, `title`, `body`, `link` {`type`: `lesson`/`assignment`/`quiz`/`course`, `id`, `course_id`} o `null`, `client_event_id`, `created_at`, `read`), `next_before`. Hilo ajeno: **404**.
- `POST /messages` `{ thread_id }` o `{ recipient_id, course_id? }` + `{ body, client_event_id }` → `201 { message, replayed:false }`; el mismo `client_event_id` responde `200 { replayed:true }` con el mismo mensaje. Solo texto (máx. 4000). Estudiante: responde en sus hilos y escribe a sus docentes de curso; entre estudiantes **403**; responder Avisos **403**. Personal: reglas de `current_user_can_message_student()`. Límite de frecuencia: 20 por minuto (**429**).
- `GET /messages/recipients` → a quién puede escribir el usuario (estudiante: sus docentes y cursos).
- `POST /messages/threads/{id}/read` `{ upto? }` → `{ unread }`. `GET /messages/unread-count` → `{ unread }`.
- `GET /agenda?from=&to=` (fechas `Y-m-d` o ISO 8601; máximo **62 días**, si no **400**; por defecto hoy + 14) → `items[]` ordenados: `type` (`event`, `assignment_due`, `quiz_due`, `live_class`), `title`, `starts_at`/`ends_at` (ISO 8601 con desfase), `course` {`id`, `title`}, `link`, `done`. Solo cursos matriculados y publicados, solo lecciones publicadas.
- `GET /today` → estudiante: `continue` {`course`, `lesson`, `progress`}, `upcoming[]` (tareas y quizzes de los próximos 7 días sin entregar), `overdue[]` (vencidos en los últimos 14 días), `unread_messages`, `new_grades[]` (`course_id`, `graded_count`, `last_graded_at` de los últimos 7 días). Personal: `role: staff`, `items[]` (el Hoy web) y `unread_messages`.
- `POST /devices` `{ token: "ExponentPushToken[…]", platform }` → `{ id }`; `DELETE /devices/{id}` (solo los propios). La app borra su token al cerrar sesión.
- `GET/PUT /notification-preferences` → `{ preferences: { messages, notices, grades, deadlines } }` (por defecto todas activas).
- Notificaciones al teléfono: se encolan (Action Scheduler o WP-Cron), **nunca** se envían dentro de la petición. Contenido: título genérico ("Nuevo mensaje", "Nota publicada", "Nuevo aviso") y `data` {`type`: `message`/`notice`, `category`, `thread_id`, `message_id`, `kind`, `link`}; nunca el cuerpo ni la nota. Reintento con espera creciente (5 intentos); un token `DeviceNotRegistered` se borra. Token de acceso de Expo opcional en Ajustes → Canales.
- `GET /discovery` declara `capabilities.messages`, `agenda`, `today` y `push_notifications`.
- Migración: al actualizar se agenda en segundo plano (lotes de 50 usuarios), o `wp atora inbox migrate [--batch=<n>] [--restart]`. Idempotente; las metas viejas no se borran en esta versión; las notificaciones `message_*` (copias automáticas de un mensaje) no se migran.

## Docente (6.31.0)

**Acceso.** Toda ruta `/teacher/*` exige el token móvil **y** un rol docente (`lms_instructor`, `lms_instructor_assistant`, `lms_coordinator`, `administrator`); si no, **403** (un estudiante recibe 403 en todas). Cada curso, estudiante o entrega pasa por `ATORA_Teacher_Scope`, la misma regla que SpeedGrader web: asignado a una sección del curso (`atora_section_teachers`), autor del curso o de la lección, permiso de editar lo ajeno o delegación vigente; con varias instituciones activas, el administrador solo alcanza los cursos de su institución (resuelta desde el curso). Fuera de alcance: **404**. Ids de curso y lección: los de tabla; de entrega y estudiante: los de WordPress. Error de base de datos: **503**. Sin caché, como toda la API.

- `GET /teacher/today` → `to_grade` {`count`, `oldest[]` (las 5 más antiguas, como en la cola)}, `at_risk` {`count`, `items[]` {`student`, `course`, `risk`}}, `today[]` (clases, eventos y fechas límite del día de sus cursos, con la forma de `/agenda`), `unread_messages`.
- `GET /teacher/courses` → `items[]` {`id`, `title`, `students`, `pending_submissions`, `sections[]` {`id`, `title`, `students`}} (un docente asignado por sección ve solo sus secciones).
- `GET /teacher/courses/{id}/students?page=&search=&section=` → `items[]` {`id`, `name`, `progress`, `final_grade` (o `null` sin notas), `last_access`, `risk` {`level`: `bajo`/`medio`/`alto`, `label`, `reasons[]` ("2 entregas vencidas", "nota acumulada 48/100")}}, `page`, `per_page` (20), `total`, `next_page`. Búsqueda por nombre sin acentos.
- `GET /teacher/students/{id}?course=` → `student`, `course`, `summary` (la fila anterior), `grades[]` (por actividad, lo que ve el estudiante), `submissions[]`, `alerts[]` (early-warning), `pending`. Estudiante que no es del curso: **404**.
- `GET /teacher/submissions?status=&course=&lesson=&cursor=` → cola sobre `get_submission_inbox_data()`, **de la más antigua a la más nueva**, sin las copias grupales (se califica la maestra). `status`: `pending` (por defecto), `draft`, `graded`, `late`, `all`. `items[]` {`id`, `student`, `course`, `lesson`, `status`, `status_label`, `is_late`, `group`, `submitted_at`}, `total`, `next_cursor`.
- `GET /teacher/submissions/{id}` → `submission` {`id`, `revision`, `student`, `course`, `lesson`, `status`, `grade`, `feedback`, `graded_attempt`, `attempts[]` {`attempt`, `source` (`web`/`mobile`), `server_received_at`, `client_submitted_at` ("realizada sin conexión el…"), `is_late`, `body_text`, `files[]` {`id`, `filename`, `mime_type`, `bytes`, `url`, `expires_at`}}, `rubric` {`id`, `title`, `total_points`, `criteria[]` {`index`, `name`, `description`, `max_points`, `weight`, `levels[]`, `bands[]` (las de SpeedGrader), `score` (decimal o `null`), `level` (nivel o "entre X y Y"), `feedback`}} o `null`, `group` {`id`, `name`, `members[]`, `submitted_by`} o `null`, `moderated`}.
- `GET /teacher/submissions/{id}/files/{file_id}?expires=&sig=` → el archivo. Enlace firmado para ese docente, esa entrega y ese archivo; vence a los 15 minutos (**403** vencido o alterado).
- `POST /teacher/submissions/{id}/grade` `{ scores: [{index, score, feedback}], feedback, grade?, publish, attempt?, expected_revision, client_event_id }` → pasa por **`ATORA_Grading_Save_Service`**, el mismo guardado de SpeedGrader (rango y 2 decimales por criterio, moderación, borrador o publicación, auditoría en `atora_rubric_evaluations`, caché, aviso al estudiante y hooks). `publish: false` guarda borrador (`in_review`, el estudiante no lo ve); `true` publica (`graded`). `grade` es la nota final entera 0–100, opcional (no se deriva de la rúbrica: la app ofrece "copiar % de la rúbrica"). Responde `200 { result {submission_id, status, grade, feedback, revision, attempt}, submission, replayed }`. **Idempotente**: el mismo `client_event_id` devuelve lo ya guardado sin volver a guardar. **Revisión obligatoria** (6.31.1): sin `expected_revision` → **400** `atora_grade_revision_required`. Un guardado que falla no consume la revisión. **Concurrencia**: si `expected_revision` ya no es la actual (otro docente guardó después), **409** `{ code: atora_grade_revision_conflict, message, data: { status, current_revision, submission } }` con la versión actual y sin escribir nada. Errores de validación: **422** con el mensaje de SpeedGrader.
- `POST /teacher/announcements` `{ course_id, section_id?, title?, body, client_event_id }` → `201 { course_id, section_id, recipients }`. Llega al hilo **Avisos** de cada estudiante del curso o de la sección (`kind: course_announcement`), con los mismos hooks que cualquier aviso. Idempotente por `client_event_id`; límite 10 por hora (**429**).
- `GET /discovery` declara `capabilities.teacher`, `teacher_grading` y `group_assignments`.

**Tareas grupales (estudiante).** `GET /assignments/{lesson_id}` de una tarea grupal devuelve `group` {`id`, `name`, `members[]`, `submitted_by`, `submitted_at`} y los intentos del grupo; `can_submit` sigue la regla de la web. `POST /assignments/{lesson_id}/submissions` entrega por el grupo (entrega maestra); sin grupo asignado, **422**. La nota de la maestra llega a cada integrante con su estado, sus puntajes por criterio y los ajustes individuales.

**Historial de entregas web.** Cada entrega hecha desde la web también escribe una fila por intento en `atora_assignment_submissions` (`source: web`), como las móviles. Las existentes se migran como intento 1 (segundo plano al actualizar, o `wp atora submissions migrate-web`), sin duplicar.
