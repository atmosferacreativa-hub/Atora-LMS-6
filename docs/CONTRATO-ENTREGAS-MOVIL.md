# Contrato previsto — entregas móviles (v1)

Nota: en `6.26.5` solo existe el **esquema**. Los endpoints se implementan en `6.27.0`.

## Autenticación

- Header: `Authorization: Bearer <access_token>`

## Idempotencia

- El cliente debe enviar `client_event_id` (UUID/ulid/hex) por evento.
- El servidor debe responder de forma idempotente: reenviar el mismo evento no duplica filas.

## Crear intento de entrega

`POST /atora-mobile/v1/assignments/{lesson_id}/submissions`

Body (JSON):

```json
{
  "client_event_id": "01J...ULID",
  "attempt": 1,
  "body_text": "texto opcional",
  "files": [
    { "upload_token": "tok_...", "filename": "tarea.pdf", "mime_type": "application/pdf", "bytes": 12345 }
  ],
  "client_submitted_at": "2026-09-21T12:34:56Z"
}
```

Respuesta 200:

```json
{
  "submission": {
    "id": 123,
    "status": "submitted",
    "server_received_at": "2026-09-21 12:35:10",
    "due_at": "2026-09-22 23:59:59",
    "is_late": false
  }
}
```

## Iniciar sesión de subida reanudable

`POST /atora-mobile/v1/uploads/sessions`

Body (JSON):

```json
{
  "submission_id": 123,
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
    "expires_at": "2026-09-21 13:35:10",
    "received_bytes": 0
  }
}
```

## Subir un chunk

`PUT /atora-mobile/v1/uploads/{upload_token}`

- Headers:
  - `Content-Range: bytes <start>-<end>/<total>`
  - `Content-Type: application/octet-stream`
- Body: bytes del chunk.

Respuesta 200:

```json
{ "received_bytes": 1048576, "status": "open" }
```

## Completar subida

`POST /atora-mobile/v1/uploads/{upload_token}/complete`

Respuesta 200:

```json
{ "status": "complete" }
```

