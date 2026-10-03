# Contrato — entregas móviles (v1)

Implementado en `6.27.0`. La app debe comprobar `capabilities.assignments === true` en `GET /discovery`; servidores anteriores no tienen estas rutas.

## Reglas comunes

- Header: `Authorization: Bearer <access_token>`.
- Todas las rutas exigen matrícula en el curso de la lección (mismo control que `/lessons/{id}`): sin matrícula → `403`.
- La lección debe tener tarea (tipo de actividad "tarea"); si no → `404 atora_mobile_assignment_not_found`. `GET /lessons/{id}` informa `assignment_available`.
- Fechas en **UTC**, formato `Y-m-d H:i:s`.
- Errores definitivos para la cola sin conexión: `400`, `403`, `404`, `409`, `410`, `422`. Reintentables: errores de red, `429` y `5xx`.

## Flujo

1. `GET /assignments/{lesson_id}` — consigna, límites y entregas propias.
2. Por cada archivo: `POST /uploads/sessions` → `PUT /uploads/{upload_token}` (uno o más fragmentos) → `POST /uploads/{upload_token}/complete`.
3. `POST /assignments/{lesson_id}/submissions` con los `upload_token` ya completos.

Las subidas se atan a la **lección**, no a una entrega previa: así la entrega se crea al final, con sus archivos, y la cola sin conexión puede reanudar cada paso.

## Idempotencia y solo añadir

- `client_event_id` (8–64 caracteres `[A-Za-z0-9_-]`, p. ej. un ULID) identifica el evento; es único por usuario.
- Reenviar el mismo `client_event_id` devuelve la misma entrega con `"replayed": true`, sin duplicar. Usarlo en otra lección → `409`.
- Cada intento es una fila nueva; nada se sobrescribe. `attempt` lo asigna el servidor (el valor que envía el cliente es informativo).
- El docente revisa el intento más reciente en SpeedGrader; el historial completo está en `atora_assignment_submissions`.

## Fechas: doble marca

- `client_submitted_at`: cuándo lo hizo el estudiante (informativa; SpeedGrader la muestra como "Realizada sin conexión el …").
- `server_received_at`: cuándo llegó. `is_late` = `server_received_at > due_at`, calculado por el servidor.
- Una tarea vencida se acepta y queda `is_late: true` (la web, en cambio, bloquea reintentos después del vencimiento).

## Consultar la tarea

`GET /atora-mobile/v1/assignments/{lesson_id}`

Respuesta 200:

```json
{
  "assignment": {
    "lesson_id": 12,
    "course_id": 3,
    "title": "Ensayo final",
    "instructions_html": "<p>Sube tu ensayo en PDF.</p>",
    "due_at": "2026-10-20 23:59:00",
    "allow_resubmission": true,
    "attempts_allowed": null,
    "attempts_used": 1,
    "group_mode": false,
    "can_submit": true,
    "accepted_files": {
      "extensions": ["pdf", "doc", "docx", "odt", "txt", "rtf", "zip", "jpg", "jpeg", "png"],
      "mime_types": ["application/pdf", "..."],
      "max_bytes": 10485760,
      "max_files": 5
    }
  },
  "submissions": [
    {
      "id": 123,
      "lesson_id": 12,
      "attempt": 1,
      "status": "submitted",
      "body_text": "texto opcional",
      "files": [{ "filename": "tarea.pdf", "mime_type": "application/pdf", "bytes": 12345 }],
      "client_submitted_at": "2026-10-01 12:34:56",
      "server_received_at": "2026-10-03 09:00:10",
      "due_at": "2026-10-02 23:59:00",
      "is_late": true,
      "grade": 88,
      "feedback": "Buen ensayo.",
      "review_status": "graded"
    }
  ]
}
```

- `attempts_allowed`: `null` = sin límite; `1` si la tarea no admite reenvíos.
- `grade` y `feedback` solo vienen en el intento más reciente y solo si el docente los publicó (misma regla que la web).
- `group_mode: true` → `can_submit: false`: las tareas grupales se entregan desde la web.

## Crear intento de entrega

`POST /atora-mobile/v1/assignments/{lesson_id}/submissions`

```json
{
  "client_event_id": "01J...ULID",
  "attempt": 1,
  "body_text": "texto opcional",
  "files": [
    { "upload_token": "tok_..." }
  ],
  "client_submitted_at": "2026-10-01T12:34:56Z"
}
```

Respuesta 200:

```json
{
  "submission": {
    "id": 123,
    "lesson_id": 12,
    "attempt": 1,
    "status": "submitted",
    "body_text": "texto opcional",
    "files": [{ "filename": "tarea.pdf", "mime_type": "application/pdf", "bytes": 12345 }],
    "client_submitted_at": "2026-10-01 12:34:56",
    "server_received_at": "2026-10-03 09:00:10",
    "due_at": "2026-10-02 23:59:00",
    "is_late": true
  },
  "replayed": false
}
```

Errores: `400` client_event_id inválido · `404` upload_token inexistente o de otro usuario · `409` archivo no completado o ya usado, tarea sin reenvíos, evento usado en otra lección · `422` entrega vacía, demasiados archivos, tarea grupal · `429` límite de frecuencia.

## Iniciar subida reanudable

`POST /atora-mobile/v1/uploads/sessions`

```json
{
  "lesson_id": 12,
  "filename": "tarea.pdf",
  "mime_type": "application/pdf",
  "total_bytes": 12345,
  "chunk_size": 1048576
}
```

Respuesta 200:

```json
{
  "upload": {
    "upload_token": "tok_...",
    "expires_at": "2026-10-03 15:00:10",
    "received_bytes": 0,
    "chunk_size": 1048576
  }
}
```

- Tipos y tamaño: la misma lista y límite que la entrega web. Tipo no permitido → `422`.
- `chunk_size` se ajusta a 64 KB–5 MB; usar el valor devuelto.
- La sesión caduca a las 6 horas; las vencidas se borran.

## Subir un fragmento

`PUT /atora-mobile/v1/uploads/{upload_token}`

- `Content-Range: bytes <start>-<end>/<total>`
- `Content-Type: application/octet-stream`
- Body: bytes del fragmento.

Respuesta 200:

```json
{ "received_bytes": 1048576, "status": "open" }
```

- Los fragmentos van en orden: `start` debe ser `received_bytes`. Si no → `409` con `data.received_bytes`; el cliente reanuda desde ahí.
- Token de otro usuario → `404`. Sesión caducada → `410`.

## Completar subida

`POST /atora-mobile/v1/uploads/{upload_token}/complete`

Respuesta 200:

```json
{ "status": "complete", "received_bytes": 12345 }
```

- Faltan bytes → `409`. El servidor valida el **tipo real** del contenido; si no coincide con un formato permitido → `422` y el archivo se borra.
