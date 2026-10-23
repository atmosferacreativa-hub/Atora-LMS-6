# Orden B — SCORM · plugin 6.35.0 · app 1.1.0

Punto de partida: plugin 6.34.0, app 1.0.2. Guarda esta orden como docs/ordenes/ORDEN-B-SCORM.md y
lleva el avance en docs/ordenes/PROGRESO-ORDEN-B.md. Ejecútala completa, bloque por bloque, sin esperar
mi confirmación.

## Reglas
- PR por bloque, CI completo en verde antes de fusionar. `php -l` en cada archivo tocado.
- Cada función con una prueba que falla antes y pasa después. Ninguna prueba se satisface con
  comentarios o texto agregado solo para que pase.
- Prohibido esconder el teclado o saltarse pasos en las pruebas de pantalla.
- No mover etiquetas. Versiones finales: plugin 6.35.0 y app 1.1.0.
- Decide lo menor y anótalo; para solo si el código contradice la orden.
- Continuidad: si el uso se agota, rama subida (WIP, sin fusionar), PROGRESO-ORDEN-B.md al día, y la
  hora en que puedes seguir. Si el entorno lo permite, programa la reanudación 5 h 10 min después.

## Objetivo
Paquetes SCORM 1.2 y SCORM 2004 (3.ª y 4.ª edición) como recurso de la biblioteca académica:
reutilizables entre cursos, versionables, con reproductor en la web y en la app (también sin
conexión), seguimiento de avance y nota hacia el libro de notas.

## Bloque 0 — Paso 0 (lectura)
Lee `includes/library/` (servicio, política, REST: versiones, estados, enlaces), `modules/h5p/`
(cómo resolvió contenido empaquetado, seguimiento y almacenamiento) y el modelo de lección
(tipos y recursos). Decide y anota dónde viven los paquetes y cómo se enlazan a una lección.
Condición de parada: si la biblioteca no admite un tipo de recurso nuevo sin romper lo existente,
propón el diseño antes de escribir código.

## Bloque 1 — Paquetes en la biblioteca (plugin)
1. Subir un ZIP SCORM desde el administrador y desde la biblioteca: validar `imsmanifest.xml`,
   detectar versión (1.2 / 2004), leer organizaciones, ítems y SCO de inicio.
2. **Seguridad al descomprimir:**
   - rechazar rutas con `..`, rutas absolutas y enlaces simbólicos (zip slip);
   - lista de extensiones permitidas; nunca ejecutar PHP ni otros scripts de servidor (bloquear con
     reglas del servidor y servir con tipos MIME correctos);
   - límites de tamaño total, cantidad de archivos y tasa de compresión (bombas zip);
   - descompresión en un directorio propio fuera de rutas ejecutables.
3. Cada paquete es un ítem de tipo `scorm` de la biblioteca: subir una versión nueva crea una versión
   (v1.0 → v1.1) sin romper los intentos ya registrados con la anterior; el curso elige si sigue la
   última versión o una fija.
4. Enlazar un paquete a una lección (tipo de lección SCORM o recurso SCORM dentro de una lección).
5. Pruebas: manifiestos 1.2 y 2004 válidos e inválidos; zip slip, symlink, PHP dentro del ZIP y bomba
   zip rechazados; versión nueva sin romper intentos anteriores.

## Bloque 2 — Reproductor y seguimiento (plugin)
1. Reproductor web: el SCO se carga en un iframe del mismo origen con el adaptador de API
   (`window.API` para 1.2, `window.API_1484_11` para 2004) implementado en JavaScript, con
   navegación entre SCO si el paquete tiene varios.
2. Datos CMI guardados por intento y por usuario: estado de lección o finalización y éxito, puntaje,
   tiempo de sesión y total, ubicación (`location`), `suspend_data`, interacciones y objetivos.
   Reanudar desde donde quedó.
3. Commits por lotes al servidor, con reintento si se cae la red y sin perder datos al cerrar la
   ventana (`sendBeacon` o equivalente).
4. **Integración académica:**
   - completar el SCORM marca la lección como completada (regla configurable: finalizado, aprobado o
     ambos);
   - el puntaje va al libro de notas como una actividad más, con la regla de visibilidad de notas
     (borrador/publicado) y el cálculo de promedio existentes;
   - intentos permitidos y qué intento cuenta (último, mejor, primero), configurables.
5. Informe para el docente: por estudiante, estado, puntaje, tiempo, intentos e interacciones.
6. Pruebas: llamadas de la API 1.2 y 2004 (Initialize/Commit/Terminate, GetValue/SetValue, códigos de
   error); persistencia y reanudación; completar marca la lección; la nota llega al libro con la regla
   de visibilidad; un usuario no ve ni escribe intentos ajenos.
7. Paquetes de prueba: usa paquetes SCORM públicos de ejemplo (los de rusticisoftware/scormcloud
   golf examples u otros con licencia que permita incluirlos en las pruebas), uno 1.2 y uno 2004.

## Bloque 3 — App 1.1.0
1. La lección SCORM se abre en la app en un WebView con el mismo adaptador de API, conectado a la API
   móvil (rutas nuevas en `atora-mobile/v1`, con authorize, no-caché y capacidad `scorm` en
   `/discovery`).
2. **Sin conexión:**
   - descargar el paquete por decisión del estudiante (muestra el tamaño), con la cuota y la
     preferencia "solo Wi-Fi" existentes;
   - reproducirlo desde los archivos locales;
   - los commits CMI van a la cola de envíos con la fecha del dispositivo y se sincronizan al
     reconectar; el servidor conserva siempre el estado más avanzado y nunca retrocede una
     finalización.
3. Se descarga una versión concreta del paquete; si hay una nueva, se ofrece actualizar sin perder el
   intento en curso.
4. Pruebas: Jest (adaptador de API, cola CMI, regla de no retroceder); recorrido de pantalla
   `scorm-online` y `scorm-sin-conexion` (avanza sin red, reconecta y el docente ve el avance).
5. Cierre app: 1.1.0, `versionCode` siguiente, changelog, `PRUEBA-TELEFONO.md` con filas SCORM,
   etiqueta, APK de prueba y AAB. Nada se envía a las tiendas.

## Cierre plugin
6.35.0, changelog, `docs/ESTADO.md`, documentación de uso para administradores (subir, versionar,
enlazar, informes) en `docs/SCORM.md`, etiqueta `v6.35.0`, ZIP verificado y release en GitHub.

## Reporte final (máximo 15 líneas)
Por bloque: hecho y cómo se probó. Qué ediciones SCORM pasan las pruebas. Qué casos de seguridad se
rechazan. Enlaces al release, APK, AAB y capturas.

