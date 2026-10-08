# Orden — correcciones antes de las tiendas: plugin 6.33.2 · app 1.0.1

Punto de partida: plugin 6.33.1 y app 1.0.0 (Fase 6 cerrada, nada enviado a las tiendas).
Son 9 problemas: 3 de una auditoría externa (ya verificados en el código) y 6 encontrados por el titular
en su teléfono Android. Todos van antes de enviar nada a las tiendas.

## Reglas
- PR, CI, changelog y documentación en el mismo PR que sube la versión; ZIP verificado del plugin.
- Cada arreglo con una prueba que falla antes y pasa después.
- No mover etiquetas ya publicadas. Esta entrega es 6.33.2 y 1.0.1.
- Prohibido esconder el teclado (hideKeyboard) o saltarse un paso para que una prueba de pantalla pase.
  Si una prueba falla, se arregla la app, no la prueba.
- Decide lo menor y anótalo; solo para si el código contradice la orden.

## Continuidad si se agota el uso
1. Actualiza PROGRESO-1.0.1.md al terminar cada punto y antes de tareas largas: punto actual, ramas y
   último commit, PR y CI, lo que falta. Commit y push.
2. Si el uso está por agotarse: commit y push de la rama en curso (WIP, sin fusionar), actualiza el
   progreso y detente.
3. Si el entorno lo permite, programa la reanudación 5 h 10 min después con: "Lee
   docs/ordenes/PROGRESO-1.0.1.md y docs/ordenes/ORDEN-1.0.1.md y continúa". Si no, indica la hora exacta.

## 1. Matrícula por programa — plugin 6.33.2 (alta)
Un estudiante inscrito en un programa ve sus cursos, pero al abrir uno recibe "no disponible": la lista
usa los cursos del programa y el acceso solo mira la matrícula al curso (authorize_course_id en la API,
user_can_access_course en la web).
- Servicio único ATORA_Course_Access_Service::can_access( user_id, course ): tiene acceso quien tenga
  matrícula vigente en el curso o una inscripción vigente (no caducada) en un programa que lo contenga.
- Lo usan la web, la API móvil (cursos, lecciones, tareas, quizzes, notas, certificados, sincronización)
  y el catálogo. Nunca se lista un curso al que luego no se puede entrar.
- Al agregar un curso a un programa, los inscritos en el programa tienen acceso de inmediato.
- Script de solo lectura para el demo con los estudiantes afectados, y comando
  `wp atora reconcile-program-access` que lo corrija.
- Pruebas: inscrito en el programa → entra a todos sus cursos, también a uno agregado después;
  programa caducado → no entra; sin programa ni matrícula → no entra.

## 2. Bajas de sesión enviadas a otra academia — app 1.0.1 (alta)
Las bajas pendientes se guardan sin la URL de la academia: tras cerrar sesión sin red en A y entrar en B,
la app puede enviar las credenciales de A al servidor de B.
- Cada baja pendiente guarda la URL de su academia y se reintenta solo contra esa academia.
- Nunca se envían credenciales de una academia a otra.
- Un 401 de la academia de origen descarta el pendiente; un error de red lo conserva.
- Prueba: cerrar sesión sin red en A, entrar en B, reconectar → la baja va a A y nada de A llega a B.

## 3. El teclado tapa los campos de texto — app 1.0.1 (alta)
Con Expo SDK 54 la app es de borde a borde y la pantalla ya no se acomoda sola.
- Usar react-native-keyboard-controller (KeyboardAwareScrollView o equivalente) en todas las pantallas
  con campos: login, elegir academia, tarea, mensajes, asistente de IA, calificar (comentarios por
  criterio y general) y eliminar cuenta.
- El campo con el foco siempre visible encima del teclado, con margen.
- Tocar fuera del campo cierra el teclado. En el login, "Siguiente" pasa a la contraseña y "Listo"
  inicia sesión.
- En mensajes y en el asistente, la caja de escritura queda pegada al teclado.

## 4. Videos de Drive no se ven dentro de la app — app 1.0.1 (alta)
Funcionaban en la 0.3.1 y volvieron a fallar: hay que abrirlos en el navegador. El servidor envía bien
embed_url y provider = google_drive; el problema está en la app.
- Reproducir con un video público real de Drive del demo y comparar 0.3.1, 0.6.0, 0.9.0 y 1.0.0 para
  encontrar el cambio que lo rompió.
- Revisar: contenedor de teclado o scroll alrededor del reproductor (el punto 3 no debe empeorarlo),
  edge-to-edge, androidLayerType, cookies de terceros del WebView (thirdPartyCookiesEnabled) y dominios
  de Google bloqueados por onShouldStartLoadWithRequest (registrar cada URL bloqueada).
- Corregir con el cambio más pequeño y explicar la causa en el reporte.
- Recorrido de pantalla leccion-drive con un video público real de Drive: captura que muestre imagen en
  el reproductor, no solo el botón "Abrir en el navegador". Si el CI no tiene acceso a Drive, queda como
  prueba obligatoria en PRUEBA-TELEFONO.md.

## 5. URL de la academia mal escrita — app 1.0.1 (alta)
La app aceptó `https;//academia.atmosferacreativa.com`.
- Normalizar antes de guardar: corregir `https;//`, `https//`, `http:/`, espacios, mayúsculas y barra
  final; agregar `https://` si falta.
- Si después no es una URL válida o no responde a /wp-json/atora-mobile/v1/discovery, mostrar
  "No encontramos una academia en esa dirección" y no guardarla.

## 6. Pantalla gris — app 1.0.1 (media)
La pantalla de login se ve apagada, como si el fondo oscuro de la ventana de academia quedara encima al
cerrarla. Comprobar y corregir.

## 7. Lectura sin bloqueo — plugin 6.33.2 (media)
read_consistent() en class-atora-grading-save-service.php lee aunque no obtenga el bloqueo. Si no lo
obtiene, devolver 409 atora_grade_busy (reintentable), como el guardado. La app reintenta una vez tras 1 s.

## 8. Reserva de IA sin comprobar — plugin 6.33.2 (media)
reserve() en class-ai-usage-service.php devuelve insert_id sin verificar el insert. Si falla, 503 y no
se llama al proveedor.

## 9. Pantalla de inicio — app 1.0.1 (menor)
Pantalla de inicio en blanco, igual que el ícono.

## Pruebas de pantalla
1. Quitar todos los pasos hideKeyboard de los recorridos existentes.
2. Comprobar que el campo con el foco es visible mientras se escribe, en login, tarea, mensajes y calificar.
3. Recorridos nuevos: academia-url (escribe `https;//…` y se corrige), leccion-drive (punto 4) y
   programa-acceso (inscrito solo en el programa entra a un curso del programa).

## Cierre
- Plugin 6.33.2, app 1.0.1, versionCode siguiente, changelog, docs/ESTADO.md, etiquetas nuevas.
- PRUEBA-TELEFONO.md con filas nuevas: teclado en cada pantalla con campos, video de Drive, URL mal
  escrita y acceso por programa.
- APK de prueba y AAB de producción de la 1.0.1. Nada se envía a las tiendas.

## Reporte final (máximo 15 líneas)
Para cada uno de los 9 puntos: hecho o no y cómo se probó. Causa del fallo de Drive. Cuántos estudiantes
del demo tenían el problema de acceso por programa. Enlaces a APK, AAB, ZIP y capturas.
