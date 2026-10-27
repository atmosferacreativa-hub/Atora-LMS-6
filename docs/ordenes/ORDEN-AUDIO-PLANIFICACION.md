# Orden — Audio en la lección y planificación híbrida · plugin 6.37.0 · app 1.2.0

Índice: `docs/ordenes/INDICE.md`. Avance: `docs/ordenes/PROGRESO-AUDIO-PLANIFICACION.md`.
Especificación del titular (2026-10-27), con las decisiones tomadas.

## Reglas

- PR por bloque, CI completo en verde antes de fusionar; cada función con una prueba que falla antes.
- Navegador móvil y app nativa se verifican **por separado**: que funcione en uno no garantiza el otro.
- Evidencias separadas: documento, código, prueba automática, validación real (ver el índice).

## Requisito transversal: conexión y guardado

El usuario siempre entiende:
- si su trabajo quedó guardado (borrador local) y si llegó al servidor (publicado o sincronizado): son operaciones
  distintas y se ven distintas;
- si un archivo sigue subiendo o falló, y cómo reintentar sin duplicar;
- qué material está descargado;
- qué entrega o cambio está pendiente de sincronización.

Recuperación de borradores tras interrupciones y cargas interrumpidas, especialmente cuando el docente trabaja
desde el teléfono. No se promete autoría totalmente sin conexión si solo se guardan algunos campos en local.

## A. Audio como recurso de la lección

- Varios audios por lección, con título, descripción y orden.
- Carga desde archivos del teléfono o de la computadora (subir un audio ya grabado).
- Formatos iniciales: **MP3, M4A y OGG** (WAV fuera). Se valida MIME, extensión, tamaño (100 MB por defecto,
  configurable) y autorización.
- **Aceptar el archivo no es garantizar su reproducción**: depende del códec y del dispositivo. Se publica una
  matriz de compatibilidad (navegadores y teléfonos soportados) y la ficha del audio avisa cuando un formato puede
  no reproducirse (por ejemplo OGG en iPhone). La conversión a un formato común **no** se hace en este alcance
  (añade procesamiento y almacenamiento).
- Reproductor accesible: pausa, avance y retroceso, velocidad, recuperación de la posición.
- Tamaño y duración visibles antes de descargar.
- Descarga para escuchar sin conexión y sincronización de la posición cuando corresponda.
- Texto alternativo o transcripción que el docente añade a mano.
- Estados claros de subida, fallo y reintento.

Fuera de este alcance: grabación dentro de ATORA (permisos de micrófono, interrupciones, recuperación),
transcripción automática, conversión en el servidor.

## B. Asistente de planificación híbrida, completo sin IA

Recorrido guiado que termina en una planificación utilizable:

| Paso | Qué completa el docente |
|---|---|
| Objetivo | Qué debe aprender el estudiante |
| Encuentro presencial | Qué se trabajará en el aula |
| Trabajo a distancia | Qué hará antes o después del encuentro |
| Recursos | Lecturas, audios, videos y materiales descargables |
| Evidencia | Qué entregará y con qué criterios se evaluará |
| Acompañamiento | Cómo y cuándo recibirá orientación y retroalimentación |

- Sin IA: crear, guardar, editar, duplicar y publicar.
- No produce solo un texto: crea las lecciones y actividades correspondientes en el curso, reutilizando los
  servicios existentes, con **vista previa antes de confirmar**. Reintentar o publicar dos veces no duplica.
  Se define cómo se actualiza una planificación ya publicada.
- Solo en cursos que el docente tiene asignados.
- IA opcional: proponer actividades, mejorar instrucciones o sugerir criterios. Toda propuesta pasa por revisión
  del docente antes de publicarse; respeta los controles centrales de privacidad, uso y presupuesto; su ausencia
  o fallo no bloquea el flujo manual. Se prueba expresamente con la IA desactivada.
- Roles:
  - Administrador: configura plantillas, permisos y disponibilidad de IA.
  - Docente: planifica y publica en sus cursos.
  - Asistente: prepara o edita según la delegación recibida. Antes de implementar se comprueba qué significa hoy
    "asistente" en los permisos; **no** recibe automáticamente facultad para publicar ni modificar evaluaciones.
  - Coordinador: se comprueba y documenta el alcance existente.

## Prueba de aceptación

Un docente prepara desde su teléfono una semana híbrida, carga un audio, guarda y retoma su planificación,
publica las actividades y un estudiante accede a los recursos compatibles sin conexión. Se repite con la IA
desactivada y se comprueban las acciones autorizadas para administrador, docente y asistente.

La parte automática se cubre con pruebas de integración y recorridos en emulador; la validación en teléfono real
queda registrada aparte y solo la marca quien la ejecuta.
