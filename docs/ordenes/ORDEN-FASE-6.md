# Orden Fase 6 — Publicación · plugin 6.32.1 → 6.33.0 · app 1.0.0

**Punto de partida:** plugin 6.32.0 y app 0.9.0 (Fase 5 cerrada). Guardar esta orden en `docs/ordenes/ORDEN-FASE-6.md` y llevar el avance en `docs/ordenes/PROGRESO-FASE-6.md`.

## Objetivo

Dejar ATORA Mobile listo para publicarse en Google Play y en la App Store como **1.0.0**, con el certificado institucional resuelto y sin pendientes que las tiendas rechacen.

## Decisiones del titular (vigentes)

- Sin APK intermedias: una sola compilación de prueba al cerrar la fase. Las pruebas de pantalla en CI son obligatorias.
- El agente decide lo menor y lo anota; solo para si el código contradice la orden o si una acción requiere cuentas, pagos o datos legales del titular.
- Una sola app para todas las academias: el estudiante elige su academia (URL) al entrar, como hoy.

## Reglas

- PR, CI, etiqueta, changelog y documentación en el mismo PR que sube la versión; ZIP verificado del plugin.
- Cada función o corrección con una prueba que falla antes.
- Rutas nuevas en `atora-mobile/v1` con `authorize`, no-caché y capacidad en `/discovery`.
- **Nada se envía a una tienda sin la aprobación explícita del titular.** El agente prepara todo; el titular sube o aprueba el envío.

## Continuidad si se agota el uso

1. Actualizar `docs/ordenes/PROGRESO-FASE-6.md` al terminar cada bloque y antes de tareas largas (CI completo, compilaciones): bloque y paso actual, ramas y último commit, PR y estado del CI, lo que falta, decisiones tomadas. Commit y push.
2. Si el uso está por agotarse: commit y push de la rama en curso (WIP, sin fusionar), actualizar el progreso y detenerse.
3. Si el entorno permite programar tareas, programar la reanudación **5 h 10 min** después del corte con: "Lee `docs/ordenes/PROGRESO-FASE-6.md` y `docs/ordenes/ORDEN-FASE-6.md` y continúa desde el paso indicado". Si no, indicar en el último mensaje la hora exacta para reanudar.
4. Al reanudar: leer el progreso, comprobar en GitHub que coincide y seguir sin rehacer lo fusionado.

---

## Bloque A — Plugin 6.32.1: control central de la IA (si no está hecho)

Si la 6.32.1 ya se publicó, comprobar que cumple esto y pasar al Bloque B.

1. Todo pasa por `class-ai-manager.php` (`chat`, `chat_with_meta`, embeddings, transcripción): registro en `atora_ai_usage`, límites, tope mensual de la academia y filtro de datos personales. Cada llamada declara su `feature`. Cubre los módulos que hoy llaman a la IA por su cuenta: alertas, mensajería, sentimiento, retroalimentación, plan de mejora, exámenes, copilotos, quick wins, ruta de aprendizaje, asistente docente, ajustes.
2. Ningún prompt lleva nombre, correo ni identificadores de estudiantes; si el texto debe dirigirse al estudiante por su nombre, se usa un marcador que se reemplaza después de recibir la respuesta.
3. El proveedor simulado nunca se activa si `wp_get_environment_type()` es `production`.
4. Pruebas: cualquier módulo registra uso y respeta el tope; ningún prompt contiene el nombre o el correo del estudiante de prueba.

## Bloque B — Plugin 6.33.0: certificado institucional

Reemplaza el certificado HTML provisional de la 0.5.0.

1. **PDF generado en el servidor** con una librería PHP sin dependencias de sistema (por ejemplo, Dompdf o mPDF; elegir la que funcione en hosting compartido sin extensiones extra, y anotar la elección).
2. **Plantilla institucional configurable** por academia: logo, colores, textos, campos (estudiante, curso o programa, horas, fecha, nota si aplica) y hasta **tres firmas** (imagen de la firma, nombre y cargo).
3. **Código único y QR de verificación:**
   - cada certificado recibe un código no adivinable;
   - el QR apunta a una página pública `/verificar/{codigo}` que muestra si es válido, a quién se emitió (nombre tal como figura en el certificado), qué curso o programa y la fecha;
   - un certificado revocado muestra "revocado", sin datos adicionales;
   - la página de verificación no expone correo ni ningún otro dato.
4. Los certificados existentes se regeneran en PDF bajo demanda la primera vez que se pidan, conservando su fecha de emisión.
5. La app descarga el PDF (ya no el HTML) y lo abre con el visor de PDF existente, también sin conexión.
6. Pruebas: el PDF se genera con las tres firmas; el QR lleva a la verificación correcta; un código inventado da "no encontrado"; uno revocado da "revocado"; la verificación no expone el correo.

## Bloque C — Requisitos de las tiendas (plugin 6.33.0 y app)

1. **Eliminación de cuenta** (exigida por Google Play y por Apple para apps con inicio de sesión):
   - en la app, Yo → "Eliminar mi cuenta", con confirmación;
   - en el plugin, `POST /account/deletion-request`: registra la solicitud, avisa al administrador de la academia por el buzón y responde con el plazo;
   - el administrador la procesa desde una pantalla de solicitudes (eliminar o anonimizar, conservando lo que la institución deba guardar por ley, como actas de notas, sin datos personales);
   - además, una página web pública en cada academia para pedir la eliminación sin la app (Google Play exige este enlace).
2. **Política de privacidad**: borrador en `docs/POLITICA-PRIVACIDAD-APP.md`, que el titular revisa, con lo que la app guarda en el teléfono, lo que envía al servidor de la academia, el uso de IA (qué se envía y qué no), notificaciones y eliminación de cuenta. Se publicará en atora.studio.
3. **Formulario de seguridad de datos de Google Play y etiquetas de privacidad de Apple**: preparar las respuestas en `docs/TIENDAS-DATOS.md` a partir del código real (qué datos, para qué, si se comparten, si se cifran en tránsito).
4. **Permisos:** revisar que la app solo pide los que usa (notificaciones, archivos para adjuntar, almacenamiento para descargas) y que cada uno tiene su texto de explicación en español e inglés.

## Bloque D — App 1.0.0: calidad para publicar

1. **Idiomas:** todos los textos de la app pasan a un catálogo (i18n). Español e inglés completos; idioma tomado del teléfono, con opción en Yo. Prueba que falla si queda un texto sin traducir en las pantallas principales.
2. **Accesibilidad:**
   - etiquetas para lector de pantalla en botones e íconos;
   - respetar el tamaño de letra del sistema sin que se corten textos en Hoy, lección, tarea, calificar y mensajes;
   - contraste suficiente en el tema;
   - objetivos táctiles de al menos 44 puntos.
3. **Rendimiento en gama baja:**
   - recorrido de pantallas en un emulador con 2 GB de RAM y red lenta simulada;
   - medir arranque en frío y apertura de una lección con 3 videos;
   - si el arranque pasa de 4 s o alguna lista se traba con 100 elementos, corregir (listas virtualizadas, imágenes con tamaño adecuado).
4. **Errores en producción:** reporte de cierres inesperados (por ejemplo, Sentry con su plugin de Expo), sin datos personales, desactivable por academia. Si requiere una cuenta, preparar la configuración y dejar el alta para el titular.
5. **Compilación de producción:** perfil `production` con AAB para Google Play e IPA para iOS, `versionCode` y número de compilación de iOS automáticos, canal de actualizaciones OTA de producción separado de `preview`. iOS: revisar `bundleIdentifier`, íconos, pantalla de inicio y textos de permisos.
6. **Cuenta de revisión para las tiendas:** en el demo, un estudiante y un docente de prueba con contenido real, y en `docs/TIENDAS-REVISION.md` las instrucciones para los revisores (URL de la academia, usuarios, qué probar).
7. **Ficha de tienda:** borradores en `docs/TIENDAS-FICHA.md` (nombre, descripción corta y larga en español e inglés, categoría, novedades) y capturas generadas desde los recorridos de pantalla en los tamaños que piden ambas tiendas.
8. Pruebas de pantalla: sumar `cambio-idioma`, `eliminar-cuenta` y `certificado-pdf`.

Cierre: app **1.0.0**, plugin 6.33.0, changelog, `docs/ESTADO.md`, `PRUEBA-TELEFONO.md` con las filas de la Fase 6, etiquetas, ZIP, y las compilaciones `production` (AAB e IPA) sin enviar.

---

## Acciones del titular (el agente no puede hacerlas)

1. **Cuentas de desarrollador:** Google Play Console (pago único) y Apple Developer Program (pago anual). Para una cuenta de organización, Apple pide número D-U-N-S y Google una verificación de la organización. Conviene iniciarlas al comenzar la fase: la verificación puede tardar días o semanas. Comprobar desde el principio que el registro y el método de pago funcionan para una cuenta basada en Venezuela.
2. Revisar y aprobar la política de privacidad y publicarla en atora.studio.
3. Aprobar la ficha de tienda y las capturas.
4. Configurar en el demo la plantilla del certificado (logo, firmas).
5. Subir las compilaciones o aprobar su envío, y responder a las tiendas si piden cambios.

## Hecho cuando (lo verifica el titular en teléfono, al cerrar la fase)

1. La app aparece en inglés con el teléfono en inglés, y se puede cambiar a español desde Yo.
2. Con letra grande del sistema, Hoy, lección, tarea y calificar se leen sin textos cortados.
3. Un certificado se descarga como PDF con logo y firmas; su QR abre la verificación y dice "válido".
4. "Eliminar mi cuenta" registra la solicitud y el administrador la ve.
5. La compilación de producción instalada en un Android de gama baja arranca y abre una lección sin trabarse.
6. La lista acumulada de `PRUEBA-TELEFONO.md` de las fases 0 a 5 sigue pasando.

## Reporte final (máximo 15 líneas)

Versiones y etiquetas; librería de PDF elegida; tiempos de arranque medidos; idiomas completos; qué falta para enviar a cada tienda (solo acciones del titular); enlaces a capturas, AAB, IPA y documentos de tiendas; decisiones menores tomadas sin preguntar.
