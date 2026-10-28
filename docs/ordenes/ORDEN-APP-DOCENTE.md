# Orden — App del docente (plugin 6.40.0 · app 1.3.0)

Progreso en `PROGRESO-APP-DOCENTE.md`. Alias: "punto 7" de la lista acordada el 2026-10-27.

## Reglas
Las de siempre: PR por bloque con CI en verde; cada función con una prueba que falla antes y pasa después; nada de
pruebas satisfechas por texto; e2e solo contra el WordPress temporal del CI; nada a las tiendas; no mover etiquetas;
evidencias (doc, código, prueba, real) por separado. El docente sigue entrando en la misma app (cambia la interfaz
tras el inicio de sesión).

## Paso 0 — Lo que hay hoy (lectura del código, 2026-10-28)
- App: Hoy (por calificar, en riesgo, agenda), Cursos → Estudiantes → Ficha, Calificar (rúbrica, borrador, 409,
  sugerencia de IA), Avisos al curso o sección, Mensajes.
- **El docente no ve el contenido de sus cursos en la app**: no hay lista de lecciones; `GET /lessons/{id}` exige
  matrícula, así que tampoco puede abrir una lección (ni la tarjeta de clase en vivo con su enlace de anfitrión).
- **No existe asistencia presencial en ningún lado** (ni web ni app): `atora_attendance` solo la llenan Zoom, Meet y
  Teams, aunque la planificación híbrida crea lecciones de encuentro presencial. La regla de racha de ausencias
  (`CLMS_Attendance_Academic_Bridge`) ya existe y no recibe datos presenciales.
- Grabación de clases en vivo y planificación híbrida: solo en la web (la app autentica únicamente en
  `atora-mobile/v1`, cada controlador valida su token).

## Bloque 1 — Contenido del curso
- `GET /teacher/courses/{id}/lessons`: lecciones en orden con tipo, estado (publicada u oculta), fecha de entrega,
  entregas por calificar, clase en vivo y si es encuentro presencial.
- El docente abre cualquier lección de sus cursos en vista de estudiante (sin contar como actividad de estudiante).
- Acciones: publicar u ocultar una lección; cambiar la fecha de entrega de una tarea (con aviso opcional al curso).
  Sin conexión no se ofrecen (piden confirmación del servidor).

## Bloque 2 — Asistencia presencial
- Sesión presencial por lección y fecha (una lección presencial de la planificación o "clase de hoy" en el curso).
- Lista rápida: todos presentes y marcar excepciones (tarde, ausente, justificado con nota).
- Sin conexión: se toma en el aula y sale por la cola con `client_event_id`; reintentos y dos teléfonos no duplican;
  la última edición gana con registro de quién y cuándo.
- Resumen por estudiante en la ficha; la racha de ausencias pasa al seguimiento (regla existente).
- Web: la misma asistencia visible y editable por el docente.

## Bloque 3 — Clases en vivo del docente
- En Hoy y en el contenido: clases del día con «Iniciar (anfitrión)» cuando abre la ventana.
- Después de la clase: publicar la grabación (enlace) o dejar "sin grabación", y ver la asistencia del proveedor.

## Bloque 4 — Planificación híbrida en la app
- Rutas móviles sobre `ATORA_Hybrid_Plan_Service` (mismos permisos: el asistente prepara, no publica).
- Lista por curso, crear y editar los seis pasos, guardado local y en el servidor con revisión (409), vista previa y
  publicar; reintentar no duplica lecciones.

## Cierre
6.40.0 y app 1.3.0, changelog, ESTADO, filas nuevas en `PRUEBA-TELEFONO.md`, ZIP verificado y APK de QA interna.
Etiquetas: el titular.
