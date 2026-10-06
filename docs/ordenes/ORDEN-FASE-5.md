# Orden Fase 5 — IA · plugin 6.31.1 → 6.32.0 · app 0.8.1 → 0.9.0

**Punto de partida:** plugin 6.31.0 y app 0.8.0. El titular probó la APK 0.8.0 en teléfono el 6 oct y la aprobó. Antes de la IA, se cierran las observaciones de una auditoría externa de la Fase 4 (Bloque A). Guardar esta orden en `docs/ordenes/ORDEN-FASE-5.md`.

## Objetivo

La IA aparece en la app en dos lugares, sin reemplazar a nadie:

1. **Estudiante:** un asistente dentro de la lección que responde con el contenido del curso.
2. **Docente:** al calificar una entrega abierta, una **sugerencia** de nota y de devolución por criterio de la rúbrica, con un **indicio** de texto generado por IA. La IA nunca pone la nota: el docente la revisa, la cambia y la confirma.

## Decisiones del titular (vigentes)

- Sin APK intermedias: una sola `preview` al cerrar la fase. Las pruebas de pantalla en CI (WordPress temporal) son obligatorias y sustituyen la prueba manual durante la fase.
- La IA sugiere, no califica. Solo aplica a entregas abiertas, no a quizzes.
- La presencia de la IA en la app es importante, pero no puede disparar costos: límites por usuario y por academia desde el primer día.
- El agente decide lo menor y lo anota; solo para si el código contradice la orden.

## Reglas

- PR, CI, etiqueta, changelog y documentación en el mismo PR que sube la versión; ZIP verificado. Cada función con una prueba que falla antes.
- Rutas en `atora-mobile/v1` con `authorize`, no-caché y capacidad en `/discovery` (`ai_assistant`, `ai_grading_suggestion`), declaradas **solo si hay un proveedor configurado y la función está activada** en la academia.
- **Las pruebas nunca llaman a un proveedor real.** Proveedor simulado en PHPUnit y en el WordPress del CI, con respuestas fijas grabadas.
- Los servicios de IA existentes se reutilizan; no se crea un segundo cliente de proveedores.

## Continuidad si se agota el uso (tokens o límite de la sesión)

El trabajo de esta fase es largo y puede cortarse por límite de uso. La regla es que un corte nunca pierda trabajo ni obligue al titular a explicar dónde quedó.

1. **Guardar progreso siempre, no solo al final.** Al terminar cada bloque, y antes de cualquier tarea larga (CI completo, compilación de APK), actualizar `docs/ordenes/PROGRESO-FASE-5.md` en `Atora-LMS-6` con:
   - bloque y paso actual;
   - ramas abiertas y su último commit;
   - PR abiertos y estado del CI;
   - lo que falta del bloque, en una lista;
   - decisiones menores tomadas hasta ahora.
   Hacer commit y push de ese archivo para que también quede en GitHub.
2. **Al detectar que el uso se está por agotar** (aviso de límite o cuota cercana): dejar el trabajo en un estado limpio (commit y push de la rama en curso, aunque sea WIP, sin fusionar), actualizar `PROGRESO-FASE-5.md` y detenerse.
3. **Reinicio automático a las 5 horas y 10 minutos.** Si el entorno permite programar una tarea o un recordatorio para reanudar, programarla para **5 h 10 min** después del corte, con la instrucción: "Lee `docs/ordenes/PROGRESO-FASE-5.md` y `docs/ordenes/ORDEN-FASE-5.md` y continúa desde el paso indicado". Si el entorno no lo permite, decirlo explícitamente en el último mensaje, con la hora exacta en que puede reanudarse.
4. **Al reanudar**, sea por la tarea programada o porque el titular escribe "continúa": leer primero `PROGRESO-FASE-5.md`, comprobar en GitHub que el estado coincide (ramas, PR, CI), y seguir desde el paso indicado sin rehacer lo ya fusionado.

---

## Bloque A — Plugin 6.31.1 y app 0.8.1: proteger el guardado de calificaciones (antes que la IA)

Una auditoría externa de la Fase 4 encontró tres fallos en el control de revisión al calificar. Dos están **verificados en el código**:
- `ATORA_Grading_Save_Service::save()` llama a `claim_revision()` (cerca de la línea 365) **antes** de moderación y publicación (línea 380 en adelante);
- `class-mobile-teacher-controller.php` solo pasa `expected_revision` si viene en la petición.

La sugerencia de IA del Bloque 3 se guarda por este mismo camino, así que esto va primero.

### A.1 La API móvil exige la revisión
- `POST /teacher/submissions/{id}/grade` sin `expected_revision` → **400** (`atora_grade_revision_required`).
- SpeedGrader web también la envía siempre (decisión de la Fase 4).
- El camino sin revisión (`null`) queda solo para llamadas internas del servidor que lo necesiten. Listarlas en el reporte; si no hay ninguna legítima, eliminarlo.

### A.2 Un guardado fallido no consume revisión
- Si falla cualquier paso posterior al reclamo (validación, moderación, publicación, error de base de datos), la revisión vuelve a su valor anterior de forma atómica (comparar y reemplazar: solo si sigue siendo la reclamada), o se reclama solo al confirmar el guardado. Elegir y documentar.
- El primer guardado de una entrega sin la meta de revisión (revisión 0) también debe ser atómico: dos primeros guardados simultáneos no pueden ganar ambos.

**Pruebas:**
- moderación que falla → la revisión no cambia y un reintento con la misma `expected_revision` funciona;
- error de base de datos simulado tras el reclamo → igual;
- primer guardado concurrente sin meta → uno gana, el otro recibe 409.

### A.3 Concurrencia real
Prueba de integración con **dos peticiones simultáneas de verdad** (dos procesos PHP o dos llamadas HTTP en paralelo contra el WordPress del CI) con la misma `expected_revision`: exactamente una responde 200 y la otra 409, y queda una sola fila de auditoría.

### A.4 App 0.8.1: el borrador recuperado conserva su revisión
- El borrador local guarda la revisión con la que se empezó.
- Al recuperarlo, si la revisión del servidor es distinta, se muestra la versión del otro docente junto al borrador, y el guardado sigue enviando **la revisión original** (lo que provoca el 409), hasta que el docente elija expresamente "Reemplazar con mi borrador". Solo entonces se envía la revisión actual.
- Jest: recuperar un borrador viejo y guardar sin confirmar → se envía la revisión original; tras confirmar → la actual.
- Recorrido de pantalla `docente-borrador-recuperado`: borrador local, otro docente califica, se recupera el borrador, aparece el conflicto.

### A.5 Lista de prueba en teléfono
Agregar o precisar en `docs/PRUEBA-TELEFONO.md`:
- borrador invisible → publicación visible, con igualdad en SpeedGrader web;
- recuperación de borrador después de que otro docente calificó;
- conflicto entre dos docentes;
- calificación grupal con un ajuste individual;
- PDF y borrador local tras perder señal o cerrar la app.

Cierre: plugin 6.31.1 (ZIP) y app 0.8.1, etiquetas, changelog y `docs/ESTADO.md`. **Sin APK.**

---

## Bloque 0 — Paso 0 de la IA (lectura, sin código)

Leer:
- `includes/class-ai-manager.php` (proveedores: OpenAI, Anthropic, Gemini, DeepSeek; `chat_with_meta`, embeddings);
- `includes/class-student-assistant.php` (`ajax_chat`, `build_system_prompt`, `check_rate_limit` por IP);
- `includes/class-ai-knowledge-base.php`;
- `includes/class-ai-grading.php` (`evaluate()`, que ya devuelve `ai_detected` y `ai_detection_note`);
- `includes/grading/trait-grading-ai-review.php`;
- `includes/class-ai-log.php` y `includes/class-ai-settings-service.php`.

Responder en el reporte:
1. ¿El asistente y la evaluación pueden llamarse sin una petición AJAX (sin `$_POST` ni nonce de pantalla)? Si no, qué hay que extraer.
2. ¿El asistente usa la base de conocimiento del curso (embeddings) o solo el texto de la lección?
3. ¿Qué registra `class-ai-log.php` (usuario, curso, tokens, costo, proveedor)? ¿Hay hoy algún límite que no sea por IP?
4. ¿Cómo usa SpeedGrader web la revisión con IA hoy, y qué guarda?
5. ¿Qué datos del estudiante se envían hoy al proveedor (nombre, correo, texto de la entrega)?

**Condición de parada:** si el asistente o la evaluación están atados a AJAX, la primera tarea es extraerlos a servicios (`ATORA_AI_Assistant_Service`, `ATORA_AI_Grading_Suggestion_Service`), con la web usándolos sin cambio de comportamiento y las pruebas actuales pasando sin modificarlas. Extracción pura en commit propio.

---

## Bloque 1 — Plugin 6.32.0

### 1.1 Límites y registro de uso (antes que todo lo demás)

1. Tabla `atora_ai_usage`: institución, usuario, función (`assistant`, `grading_suggestion`), proveedor, modelo, tokens de entrada y salida, costo estimado, fecha, resultado (ok, error, límite).
2. **Límites configurables en ajustes:**
   - por estudiante: preguntas por día (por defecto 30);
   - por docente: sugerencias por día (por defecto 100);
   - por academia: tope mensual de costo estimado (por defecto vacío, sin tope).
   Al alcanzar un límite: 429 con un mensaje claro y la hora de reinicio. Al llegar al 80 % del tope mensual, aviso al administrador por el buzón.
3. Reemplazar el límite por IP del asistente web por estos límites por usuario. La web y la app comparten el mismo contador.
4. Pantalla de administración simple: uso del mes por función y los 10 usuarios con más consumo.

### 1.2 Privacidad

1. Al proveedor **no** se envían nombre, correo ni identificadores del estudiante: solo el texto necesario (pregunta, contenido de la lección, entrega, rúbrica). Reemplazar nombres por "el estudiante" en los prompts.
2. Ajuste por academia para activar o desactivar cada función por separado. Por defecto, ambas desactivadas hasta que el administrador las active.
3. Texto de aviso visible en la primera uso del asistente: "Las respuestas las genera una IA y pueden contener errores. No compartas datos personales."

### 1.3 Asistente del estudiante

`POST /ai/assistant` con `lesson_id` (o `course_id`), `message`, `history` (máximo las últimas 6 intervenciones) y `client_event_id`.
- Verifica matrícula y que la lección esté publicada.
- Contexto: contenido de la lección y, si existe, la base de conocimiento del curso.
- **Sin respuestas de evaluaciones:** el prompt indica explícitamente no resolver quizzes ni tareas abiertas del curso, sino orientar; si la lección tiene un quiz o tarea, su enunciado se incluye para que el asistente lo reconozca.
- Respuesta sin streaming, con tiempo límite de 30 segundos y error claro si se excede.
- Registra uso. No guarda la conversación en el servidor (solo el registro de uso).

### 1.4 Sugerencia de calificación (docente)

1. **Asíncrona**, porque puede tardar:
   - `POST /teacher/submissions/{id}/ai-suggestion` crea un trabajo en cola (Action Scheduler o cron, como las notificaciones) y responde `202` con `job_id`;
   - `GET /teacher/ai-suggestions/{job_id}` devuelve el estado (`pending`, `done`, `failed`) y el resultado.
2. Mismo `ATORA_Teacher_Scope` que calificar. Solo entregas de tareas abiertas, nunca quizzes.
3. **Resultado:**
   - por criterio: puntaje sugerido (dentro de su rango, decimales permitidos), nivel o banda con la regla de `class-rubric-level-bands.php`, y justificación breve;
   - devolución general sugerida;
   - **indicio de IA:** `ai_likelihood` (bajo, medio, alto) y una explicación en una línea.
4. **El indicio de IA es solo una señal para el docente:**
   - nunca se muestra al estudiante;
   - nunca baja la nota sugerida automáticamente;
   - siempre lleva el texto "Indicio no concluyente. Verifica con el estudiante antes de decidir."
5. Se guarda como **sugerencia** (meta propia de la entrega, con modelo, fecha y docente que la pidió), separada de la calificación. Guardar o publicar sigue pasando solo por `ATORA_Grading_Save_Service`, con lo que el docente decidió.
6. La auditoría registra si la nota final se guardó **con o sin sugerencia previa** y la diferencia entre lo sugerido y lo guardado, para poder evaluar la utilidad de la función.
7. SpeedGrader web muestra la misma sugerencia (mismo servicio) con el botón "Usar sugerencia", que rellena el formulario sin guardar.

### 1.5 Pruebas

- Con el proveedor simulado: el asistente responde, registra uso y respeta el límite (429 al pasarse).
- Un estudiante no matriculado → 404. Un estudiante en las rutas de sugerencia → 403.
- El prompt enviado al proveedor no contiene el nombre ni el correo del estudiante.
- La sugerencia nunca cambia la calificación guardada ni dispara avisos al estudiante.
- El indicio de IA no aparece en ninguna respuesta de rutas del estudiante.
- Puntajes sugeridos fuera de rango se recortan al rango del criterio.
- Con la función desactivada, `/discovery` no la declara y las rutas responden 404.

Cierre: 6.32.0, changelog, `MOBILE-API-V1.md`, `docs/ESTADO.md`, etiqueta, ZIP.

---

## Bloque 2 — App 0.9.0

### 2.1 Asistente en la lección (estudiante)

- Botón "Preguntar" en la lección, visible solo si el servidor declara `ai_assistant`.
- Hoja de conversación con las preguntas y respuestas de esa sesión (se guardan solo en el teléfono, se borran al cerrar sesión).
- Aviso de IA la primera vez.
- Sin conexión: el botón aparece deshabilitado con "Necesitas conexión para usar el asistente". Las preguntas **no** se encolan.
- Límite alcanzado: mensaje con la hora de reinicio.

### 2.2 Sugerencia al calificar (docente)

- En la pantalla de calificación: botón "Sugerencia de IA", visible solo si el servidor declara `ai_grading_suggestion` y la entrega es una tarea abierta.
- Mientras se genera: estado "Generando…" consultando el trabajo cada 3 segundos, máximo 2 minutos; el docente puede seguir calificando a mano.
- Resultado en un panel aparte:
  - puntaje y justificación por criterio;
  - devolución sugerida;
  - indicio de IA con su aviso.
  Botones "Usar todo" o "Usar" por criterio: rellenan el borrador local; **nada se guarda ni se publica sin la acción del docente.**
- Lo usado queda marcado como "sugerido por IA" en el formulario hasta que el docente lo edita.

### 2.3 Pruebas

- **Jest:** rellenar el formulario desde la sugerencia sin enviar nada; el indicio no aparece en ninguna pantalla del estudiante.
- **Recorridos de pantalla** (proveedor simulado en el WordPress del CI):
  - `estudiante-asistente`: pregunta y respuesta en una lección;
  - `docente-sugerencia-ia`: pide la sugerencia, usa un criterio, publica, y el estudiante ve la nota sin rastro del indicio.

Cierre: 0.9.0, `versionCode` siguiente, changelog, README (plugin mínimo 6.32.0), `docs/PRUEBA-TELEFONO.md` con las filas de la Fase 5, etiqueta y **la única APK `preview` de la fase**.

---

## Acciones del titular (al cerrar la fase)

1. En el demo, configurar un proveedor de IA (clave de API) y activar las dos funciones.
2. Definir los límites que quiere para la demo; los valores por defecto sirven para probar.

## Hecho cuando (lo verifica el titular en teléfono, al cerrar la fase)

0. Los puntos del Bloque A.5 en `PRUEBA-TELEFONO.md`, con dos docentes del mismo curso.

1. El estudiante pregunta en una lección y recibe una respuesta basada en ese contenido.
2. Si pide la respuesta de un quiz del curso, el asistente orienta sin resolverlo.
3. Al pasar el límite diario, ve el mensaje con la hora de reinicio.
4. El docente pide una sugerencia, la ve con la justificación por criterio y el indicio de IA, usa parte y publica con sus cambios.
5. El estudiante ve la nota y la devolución publicadas, sin ninguna mención al indicio de IA.
6. En el panel de administración aparece el consumo del mes.
7. Con las funciones desactivadas, los botones desaparecen de la app.

## Reporte final (máximo 15 líneas)

Bloque A: qué camino interno usaba la llamada sin revisión, qué estrategia se eligió para no consumir revisiones al fallar, y el resultado de la prueba de concurrencia real. Respuestas del Paso 0. Si hubo que extraer servicios. Qué datos se dejaron de enviar al proveedor. Costo estimado por pregunta y por sugerencia con el proveedor por defecto. Enlace a capturas y a la APK. Decisiones menores tomadas sin preguntar.
