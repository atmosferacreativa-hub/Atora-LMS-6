# Sincronización offline — reglas

Estas reglas son decisiones de producto y son vinculantes para el código (servidor y cliente).

## Progreso: monótono

- Un progreso completado **nunca** se descompleta por una sincronización.
- El servidor guarda `completed_at` (autoridad) y `client_completed_at` (informativa).
- La idempotencia se garantiza con `client_event_id` único por usuario.

## Entregas: solo añadir

- Cada intento es **una fila nueva**.
- Ninguna entrega se sobrescribe: el servidor agrega, y las revisiones quedan auditables.
- La idempotencia se garantiza con `client_event_id` único por usuario.

## Contenido: autoridad del servidor

- La versión del servidor siempre reemplaza a la local.
- `revision` viaja en los endpoints móviles para decidir qué volver a descargar.

## Fechas: doble marca de tiempo

- Se guardan ambas marcas: cliente (`client_*`) y servidor (`server_*`).
- `is_late` lo calcula el servidor contra `due_at` usando `server_received_at`.
- La marca del cliente se muestra al docente como información, no como autoridad.

## Descargas automáticas

- Solo se descargan automáticamente contenidos livianos (texto, guías, definiciones).
- El video siempre requiere decisión explícita del estudiante.

