# Orden — Clases en vivo (Zoom, Google Meet, Microsoft Teams) · plugin 6.39.0 · app 1.2.0

Alias anteriores: "E Streaming" (lista vieja), "F" (lista ejecutada). Progreso en `PROGRESO-CLASES-EN-VIVO.md`.

**Alcance honesto:** ATORA integra proveedores externos (crea la reunión, da el enlace, registra asistencia y
grabación). No es transmisión propia. No anunciarlo como tal.

## Reglas
Las de siempre: PR por bloque con CI en verde; cada función con una prueba que falla antes y pasa después; nada de
pruebas satisfechas por texto; proveedor simulado en las pruebas (nunca cuentas reales); no mover etiquetas; nada a
las tiendas; las evidencias (doc, código, prueba, real) se informan por separado.

## Paso 0 — Hallazgos (lectura del código, 2026-10-27)
1. **Reunión duplicada en cada guardado.** `save_metabox()` rearma la sesión desde el formulario sin el
   `meeting_id` guardado, así que cada vez que se guarda la lección crea otra reunión en Zoom o Meet.
2. **Firma de Zoom mal calculada.** Zoom firma `v0:{x-zm-request-timestamp}:{cuerpo}`; el código firma solo el
   cuerpo: ningún webhook real pasa. Tampoco responde la validación de URL (`endpoint.url_validation`), sin la que
   Zoom no acepta el webhook, ni comprueba vigencia ni repetición.
3. **Fin de reunión de Zoom no encuentra la lección** si hay más de 5 lecciones en vivo (`posts_per_page => 5`).
4. **Zona horaria.** La hora de inicio se guarda como hora local del docente pero se interpreta como UTC al abrir
   el enlace, en los recordatorios y en la tabla de sesiones: en Caracas, todo corre 4 horas.
5. **El enlace sigue abierto** después de terminada la clase; el docente recibe el enlace de participante.
6. **Recordatorio de 1 hora nunca sale** cuando ya salió el de 24 horas (la consulta excluye esas lecciones); al
   reprogramar no se reinician; se envían en un bucle síncrono a todos los estudiantes.
7. **Meet marca la sesión terminada en la primera consulta** apenas empieza la clase: la asistencia queda parcial.
8. **Teams** solo publica avisos en un canal (webhook entrante): no crea reuniones, no hay asistencia ni grabación.
9. **Grabación**: solo un booleano; el enlace de Zoom se emite en un hook que nadie guarda; el estudiante no ve
   grabaciones; la app no tiene nada de clases en vivo.

## Bloque 1 — Correcciones (1–7)
- La reunión se crea una sola vez; reprogramar actualiza la existente (o la recrea si el proveedor no permite
  editar) sin duplicar.
- Zoom: firma `v0:ts:cuerpo`, vigencia de 5 min, cada evento una vez, validación de URL.
- Fin de reunión por la tabla de sesiones (`provider`, `external_id`).
- Hora de inicio: se guarda también en UTC (`start_utc`) calculada con la zona de la sesión; todo lo usa.
- Enlace: abre 10 min antes y cierra al terminar (+15 min); al docente se le da el enlace de anfitrión cuando existe.
- Recordatorios: 24 h y 1 h independientes, se reinician al reprogramar, salen por la cola de correo.
- Cierre de sesión (asistencia definitiva) solo cuando terminó la clase.

## Bloque 2 — Grabación a decisión del docente
Modo por sesión:
- **Sin grabación**: no se pide grabar al proveedor. El docente puede agregar una grabación después si quiere.
- **La subo después**: no se graba en el proveedor; el docente sube un video o pega un enlace cuando quiera.
- **Enlace automático del proveedor**: Zoom (grabación en la nube automática; el enlace se guarda al recibir
  `recording.completed`), Teams (grabación automática; el enlace se busca al terminar), Meet (la grabación la inicia
  quien organiza —la API de Calendar no puede activarla—; ATORA guarda el enlace si existe).
- El docente puede quitar o cambiar la grabación en cualquier momento. El estudiante matriculado la ve en la lección
  (web y app). El acceso al archivo del proveedor depende de cómo esté compartido allí: se informa en la pantalla.

## Bloque 3 — Microsoft Teams (Microsoft Graph)
- Credenciales por academia (inquilino, aplicación, secreto, organizador) en ajustes; nunca en el repositorio.
- Crear la reunión en línea (`/users/{organizador}/onlineMeetings`) con grabación automática según el modo.
- Asistencia: informes de asistencia al terminar; grabación: `recordings` de la reunión.
- Probado solo con un proveedor simulado (respuestas de Graph falsas); sin cuenta real.

## Bloque 4 — App (1.2.0)
- En la lección: tarjeta de clase en vivo con proveedor, fecha en la zona del teléfono, cuenta regresiva, botón
  «Unirse» (abre la app del proveedor o el navegador) solo cuando está abierta, y la grabación cuando exista.
- Recorrido Maestro con WordPress temporal.

## Cierre
6.39.0 (y app 1.2.0 con audio), changelog, `docs/CLASES-EN-VIVO.md`, ESTADO, ZIP verificado; etiquetas las crea
el titular.
