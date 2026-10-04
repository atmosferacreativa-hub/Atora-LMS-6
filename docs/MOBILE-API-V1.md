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
| PUT | `/lessons/{id}/position` | Bearer + matrícula (si no, 404) | Guardar posición de reproducción (6.28.0) |
| GET | `/sync/changes?cursor=` | Bearer | Cambios desde el cursor, solo de los cursos matriculados (6.28.0) |
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
