# Sincronización offline — reglas

Estas reglas son decisiones de producto y son vinculantes para el código (servidor y cliente).

## Progreso: monótono

- Un progreso completado **nunca** se descompleta por una sincronización.
- El servidor guarda `completed_at` (autoridad) y `client_completed_at` (informativa).
- La idempotencia se garantiza con `client_event_id` único por usuario.

## Posición de reproducción: gana la más reciente (6.28.0)

- Una fila por usuario y lección.
- Gana la marca más reciente según `client_recorded_at`, **aunque la posición sea menor**: el estudiante puede volver atrás a repasar. Lo monótono es la lección completada, no la posición.
- Una marca más antigua que llega tarde no pisa a una más nueva. Reenviar el mismo `client_event_id` no cambia nada.
- Una marca más de 5 minutos en el futuro se acota, para que un reloj adelantado no fije la posición para siempre.
- En el cliente es un evento que **se reemplaza**: por lección solo se envía la última posición, nunca una lista.

## Entregas: solo añadir

- Cada intento es **una fila nueva**.
- Ninguna entrega se sobrescribe: el servidor agrega, y las revisiones quedan auditables.
- La idempotencia se garantiza con `client_event_id` único por usuario.

## Contenido: autoridad del servidor

- La versión del servidor siempre reemplaza a la local.
- `revision` viaja en los endpoints móviles para decidir qué volver a descargar.
- Desde 6.27.4 `revision` sube con cada cambio hecho en el editor de WordPress; desde 6.28.0 también con cambios de recursos, videos, consignas y fechas (huella `content_hash`).
- `GET /sync/changes` (6.28.0) dice qué cambió desde el último cursor; la app pide solo eso.

## Fechas: doble marca de tiempo

- Se guardan ambas marcas: cliente (`client_*`) y servidor (`server_*`).
- `is_late` lo calcula el servidor contra `due_at` usando `server_received_at`.
- La marca del cliente se muestra al docente como información, no como autoridad.

## Descargas automáticas

- Solo se descargan automáticamente contenidos livianos (texto, guías, definiciones).
- El video siempre requiere decisión explícita del estudiante.

