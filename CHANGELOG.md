# CHANGELOG — ATORA LMS

## 6.31.1 (2026-10-06)

### Fix — control de revisión al calificar (auditoría externa de la Fase 4)

Orden `docs/ordenes/ORDEN-FASE-5.md`, Bloque A.

- **La revisión es obligatoria.** `POST /teacher/submissions/{id}/grade` sin `expected_revision` → **400** `atora_grade_revision_required`; SpeedGrader web siempre la envía y, sin ella (página vieja en caché), pide recargar. Se eliminó el camino sin revisión del servicio: no tenía llamadas internas legítimas (solo SpeedGrader y la API, que ahora la exigen).
- **Un guardado fallido no consume la revisión.** Se reclama antes de moderar y publicar (como en 6.31.0), pero si cualquier paso posterior falla (moderación, publicación o la base no guardó el estado y la nota decididos), la revisión vuelve a la anterior con comparar y reemplazar: solo si sigue siendo la reclamada. Un reintento con la misma `expected_revision` funciona.
- **Primer guardado atómico.** Leer, comparar y escribir la revisión se hace bajo un bloqueo de MySQL por entrega (`GET_LOCK`): dos primeros guardados simultáneos (revisión 0, sin meta) no pueden ganar ambos. Si el bloqueo está ocupado más de 10 s, 409 `atora_grade_busy`.
- **Concurrencia real**: `scripts/e2e-concurrent-grade.sh` manda dos guardados por HTTP a la vez, con la misma revisión, contra el WordPress temporal del CI de la app: exactamente un 200 y un 409, la revisión sube una vez y queda una sola evaluación de rúbrica. `wp atora seed-e2e` siembra la entrega "Concurrencia" para esa prueba.

**TESTS**: `GradeRevisionConflictTest` (sin revisión → 400; moderación que falla y error de base de datos tras el reclamo no cambian la revisión y el reintento funciona; primer guardado: gana uno) — fallan con 6.31.0. `TeacherApiTest` (la API sin revisión → 400). Las pruebas que guardaban por SpeedGrader envían ahora la revisión.

## 6.31.0 (2026-10-06)

### Fase 4 — Docente (plugin)

Orden: `docs/ordenes/ORDEN-FASE-4.md`, Bloque 3.

**Servicio de guardado.** `ATORA_Grading_Save_Service::save( $submission_id, $actor_id, $input )` es la única puerta para calificar: SpeedGrader web y la app. Extracción pura de `handle_speedgrade_save()` (commit propio): el cuerpo es el mismo y lee `$input` (sin barras) en lugar de `$_POST`; `handle_speedgrade_save()` queda como envoltura (nonce y `$_POST`). Las pruebas de SpeedGrader existentes pasan sin cambios.

**Acceso: `ATORA_Teacher_Scope`.** Una sola regla para SpeedGrader web y `/teacher/*`: puede ver y calificar quien esté asignado a una sección del curso (`atora_section_teachers`), sea autor del curso o de la lección, pueda editar lo ajeno o tenga una delegación vigente, o cumpla la regla anterior (es una suma: nadie pierde acceso por las condiciones nuevas). Con varias instituciones activas, el administrador y quien edita lo ajeno solo alcanzan los cursos de su institución, resuelta desde el curso. La cola de SpeedGrader incluye los cursos de las secciones asignadas. `scripts/audit-teacher-scope-access.php` (solo lectura) lista quién perdería acceso; con una sola institución no puede perder nadie.

**Concurrencia.** Meta `_clms_submission_grade_revision`, que sube en cada guardado (comparar y sumar en una sola consulta). SpeedGrader web envía la revisión que mostró; si otro docente guardó después: "Otro docente guardó esta entrega; recarga para ver su versión", sin guardar. La API responde **409** con la versión actual.

**Riesgo: `ATORA_Student_Risk_Service`.** Combina early-warning (entregas vencidas) con el resumen de notas (nota acumulada, actividades pendientes): nivel `alto`/`medio`/`bajo` y motivos legibles ("2 entregas vencidas", "nota acumulada 48/100"). Sin notas no es riesgo por nota; un 0 sí. Lo usan SpeedGrader ("Riesgo académico") y `/teacher/*`.

**Historial de entregas web.** Cada entrega por el formulario web escribe una fila nueva en `atora_assignment_submissions` (solo añadir, `source: web`, ids de tabla, marca de tardía); el post `clms_submission` sigue siendo el que califica SpeedGrader. Migración idempotente de las existentes como intento 1 (`web-migrated-{post}`), por lotes en segundo plano al actualizar y con `wp atora submissions migrate-web`; las copias grupales no se migran.

**Todos los intentos en SpeedGrader.** Lista de intentos con su fecha (y "realizada sin conexión el…" si viene del dispositivo), origen (web o app) y tardía; se puede ver cada uno. Se califica el intento elegido, por defecto el último; queda en `_clms_submission_graded_attempt` y en la auditoría de la rúbrica.

**Tareas grupales en la app.** `GET /assignments/{id}` devuelve el grupo, sus integrantes, quién entregó y cuándo, y los intentos del grupo; ya no "no disponible". La entrega desde la app vale para el grupo (entrega maestra, mismo camino que la web); los intentos se cuentan sobre la maestra. La nota de la maestra llega a todos con estado, rúbrica y ajustes individuales (6.30.1).

**API del docente** (`atora-mobile/v1`, token + rol docente → si no 403; recurso fuera de alcance → 404; 503 ante error de base de datos; sin caché): `GET /teacher/today`, `GET /teacher/courses`, `GET /teacher/courses/{id}/students`, `GET /teacher/students/{id}?course=`, `GET /teacher/submissions` (sobre `get_submission_inbox_data()`, la más antigua primero, sin copias grupales, filtro de tardías), `GET /teacher/submissions/{id}` (intentos, archivos con enlace firmado y temporal, rúbrica con niveles y bandas, grupo, revisión), `POST /teacher/submissions/{id}/grade` (por el servicio de guardado; idempotente por `client_event_id`; 409 ante revisión vieja), `POST /teacher/announcements` (aviso al hilo Avisos de cada estudiante del curso o sección). `/discovery`: `teacher`, `teacher_grading`, `group_assignments`. Detalle en `docs/MOBILE-API-V1.md`.

**TESTS** (fallan con 6.30.2): `TeacherScopeTest` (sección, autoría, extraño sin acceso; administrador de otra institución), `GradeRevisionConflictTest` (el segundo docente no pisa), `StudentRiskServiceTest`, `WebSubmissionHistoryTest` (fila por intento web; migración no duplica), `SpeedGraderAttemptsTest`, `GroupAssignmentMobileTest` (grupo, entrega por el grupo, borrador invisible, nota a todos con ajuste), `TeacherApiTest` (403/404, misma nota, auditoría y aviso por API y SpeedGrader, 409, decimales, borrador/publicada, idempotencia, aviso al curso).

## 6.30.2 (2026-10-06)

Cierre pendiente antes de la Fase 4 (orden `docs/ordenes/ORDEN-FASE-4.md`, Bloque 1).

### Fix — listeners de `clms_submission_graded` con argumentos cruzados (media)
El hook pasa `(submission_id, student_id, status, grade, feedback)`.
- `CLMS_Dashboard` y `CLMS_Assessment_Engine` lo escuchaban con el método de `clms_submission_created` `(submission_id, lesson_id, user_id)`: tomaban el estudiante como lección y el estado como usuario, y no borraban nada. Ahora usan `invalidate_cache_from_graded_submission( $submission_id, $student_id )`.
- `CLMS_Analytics::record_submission_graded()` guardaba lección, usuario, estado y nota cruzados. Ahora lee el orden real y toma la lección de la entrega. **Los eventos de analítica son fiables desde 6.30.2**; los anteriores no se reescriben (ver `docs/ESTADO.md`).
- Revisados también los listeners de `clms_submission_grade_draft_saved` y `clms_grade_published`: ya estaban en orden.

### Mensajes del docente en la web
- La página **Mensajes y seguimiento** muestra las conversaciones del buzón (incluido **Avisos**) con **lo enviado y lo recibido**, en orden cronológico, y permite responder. Lee y responde por `ATORA_Mobile_Messages_Controller` (mismas tablas, reglas de participante, avisos sin respuesta, límite e idempotencia que la app). Abrir un hilo lo marca leído: **un solo contador** con la app. Antes solo listaba lo recibido.

### Pruebas de pantalla
- **`wp atora seed-e2e`**: datos fijos para los recorridos de la app en un WordPress temporal del CI: curso con sección (docente asignado, estudiante matriculado), lección con 3 MP4 locales, tarea con una entrega sin calificar, quiz de 3 preguntas y un mensaje del docente. Reutiliza lo que ya existe (se puede correr dos veces). Se niega sin `--yes` o la constante `ATORA_E2E`.

### Documentación
- `docs/ESTADO.md`: por función, si está comprobada en teléfono, en pruebas de pantalla, de integración o unitarias. Datos históricos fiables desde qué versión. Las auditorías de borradores y de ceros no aplican al demo (solo cuentas de prueba); se corren antes de actualizar la primera instalación con estudiantes reales.
- `docs/ordenes/ORDEN-FASE-4.md`: la orden de la Fase 4.

**TESTS**: `GradedHookListenersTest` (panel y analítica fallan con 6.30.1), `TeacherWebMessagesTest` (falla con 6.30.1: el docente no veía lo que envió; respuesta web por el mismo envío que la app).

## 6.30.1 (2026-10-05)

### Fix — un borrador de calificación llegaba al estudiante (alta, académica)

Contradecía la regla de 6.29.0 (la nota solo se ve publicada). Al pulsar "Guardar borrador" en SpeedGrader se disparaba `clms_submission_graded`: el estudiante recibía un aviso y un mensaje con la nota ("Nota actual: 85/100") y, en tareas grupales, la copia de cada integrante quedaba publicada (`graded`) sin puntajes por criterio.

- Un guardado sin publicar (`in_review`, `submitted`, `pending`; también "enviar a moderación") dispara el hook nuevo **`clms_submission_grade_draft_saved`**, con los mismos argumentos. `clms_submission_graded` queda para la nota publicada o la entrega devuelta para corregir. Una sola regla elige el hook: `CLMS_Student_Grade_Visibility::grade_saved_hook()`, usada por el motor de evaluación, SpeedGrader, el puente del libro de notas por lotes y las copias grupales. La regla se carga antes que el loader.
- Listeners que también reaccionan al borrador (internos, no publican nada al estudiante): grupos (pasa el borrador a las copias como borrador), auditoría del libro de notas (acción `grade_draft_saved`) y el borrado de caché de ruta de aprendizaje, `CLMS_Grading` y progreso (para que una nota que vuelve a borrador deje de verse). Solo a la publicación: avisos, mensajes, libro de notas, memoria del estudiante, analítica, gamificación, ciclo de retroalimentación, sincronización de quizzes, bloqueo de escala, certificados y puente académico.
- **Grupales**: las copias de cada integrante heredan el estado de la maestra (borrador o publicada) y sus puntajes por criterio, con `clms_group_grade_overrides` aplicado.
- **Script** `scripts/audit-draft-grade-leaks.php` (solo lectura): A) entregas en borrador cuyo estudiante recibió la nota; B) ya publicadas pero vistas antes como borrador; C) integrantes de grupo que hoy ven una nota no publicada.

**TESTS**: `GradeDraftNoLeakTest` (falla con 6.30.0): un borrador no crea aviso ni mensaje ni cambia el promedio, publicar sí; copias grupales en borrador, luego publicadas con el ajuste individual y los puntajes por criterio.

## 6.30.0 (2026-10-05)

### Fase 3 — Organizarse (plugin)

**Buzón propio** (decisión A del titular: el hilo "Avisos" reemplaza a `CLMS_Notifications`).
- Tablas nuevas `atora_message_threads` (tipo `direct`/`course`/`system`, curso, asunto, último mensaje), `atora_message_participants` (rol, último leído, silenciado) y `atora_messages` (autor, `kind`, título, cuerpo, enlace, lección, `client_event_id` único por autor, `legacy_id`, `dedupe_key`, `read_at`), con `institution_id`. Esquema `6.30.0-inbox`.
- `CLMS_Messaging` y `CLMS_Notifications` conservan su API pública (`send_message`, `get_messages`, `get_threads`, `get_unread_count`, `mark_message_read`; `add_notification`, `get_notifications`, `mark_notification_read`, `mark_all_read`) pero escriben y leen en las tablas. Lo escrito por una persona (mensaje manual, aviso del docente al curso) va a la conversación remitente ↔ destinatario y se puede responder; lo automático va al hilo **Avisos** del usuario con su `kind` y su enlace. Sin tope de 100/50; dos escrituras simultáneas ya no se pisan.
- **Un solo contador** de no leídos (mensajes + avisos): campana web, panel del estudiante, buzón del docente y app.
- Se elimina la copia automática de cada mensaje como aviso (`mirror_notification` se ignora).
- Lo que se disparaba al crear un aviso sigue igual: `atora/notification_added` (Teams) y los filtros `clms_modularity_messaging_raw_item` / `_item` (el tercer argumento ahora es un arreglo vacío en lugar de la meta del destinatario). Correo, WhatsApp y Telegram cuelgan de los eventos de dominio, no del aviso: sin cambios.
- Avisos del personal (`early_warning`, `learning_analytics`, `submission_created`): solo a usuarios con permisos docentes; nunca a un estudiante.
- Retención: cron diario borra los avisos **leídos** de más de 180 días. Los mensajes de conversación no se borran.
- **Migración** de `clms_internal_messages` y `clms_notifications`: idempotente (uuid → `legacy_id`), por lotes en segundo plano al actualizar y `wp atora inbox migrate`. Las metas viejas no se borran. Las notificaciones `message_*` (copias de un mensaje) no se migran. Los ids viejos siguen sirviendo para marcar como leído.
- **Acceso directo a las metas, pasado a la API**: `includes/commerce/class-commerce-notifications.php` (escritura de respaldo en `clms_notifications` y la "limpieza" que la reescribía a 90 días/50 ítems tras cada aviso). En el tema Meridian 3.0.10 no había accesos.

**API móvil** (`atora-mobile/v1`, con `authorize`, sin caché y en `capabilities`):
- `GET /messages/threads`, `GET /messages/threads/{id}`, `POST /messages` (idempotente por `client_event_id`, límite 20/min con `ATORA_Rate_Limiter`, solo texto), `POST /messages/threads/{id}/read`, `GET /messages/unread-count`, `GET /messages/recipients`. Hilo ajeno 404; destinatario no permitido 403; entre estudiantes no.
- `GET /agenda?from=&to=` (máximo 62 días): eventos del calendario del usuario o de sus cursos, fechas límite de tareas y quizzes y clases en vivo, en ISO 8601 con desfase, con enlace interno; solo cursos matriculados y lecciones publicadas (`CLMS_Agenda_Service`).
- `GET /today`: Hoy según el rol. El caso del estudiante está en el servicio (`CLMS_Today_Aggregator_Service::get_student_today()`): continuar la última lección, entregas y evaluaciones de 7 días, vencidas sin entregar, mensajes sin leer y notas nuevas.
- Notificaciones al teléfono: tabla `atora_mobile_push_tokens`; `POST /devices`, `DELETE /devices/{id}`; `GET/PUT /notification-preferences` (mensajes, avisos, notas, fechas límite). Envío por cola (Action Scheduler o WP-Cron), nunca en la petición; título genérico sin contenido; reintento con espera; `DeviceNotRegistered` borra el token. Token de acceso de Expo opcional en Ajustes → Canales.
- Recordatorio diario de fechas límite (próximas 24 h, sin entregar), un aviso `deadline_reminder` por lección.

**TESTS**: `InboxTest` (API pública igual, sin copia automática, contador único, mismo hook al crear un aviso, filtros de mensajería, avisos automáticos con `kind` y dedupe, avisos del personal, migración sin duplicar, retención) y `MobileOrganizeTest` (envío idempotente y respuesta del docente, 404/403, Avisos fijo y contador, agenda solo de cursos matriculados y lecciones publicadas, Hoy del estudiante, cola que no envía en la petición y borra el token inválido, preferencias, recordatorio único, cabeceras de no-caché).

## 6.29.5 (2026-10-05)

### Fix — el promedio confundía una nota de cero con "sin notas" (alta, académica)

**Puede cambiar notas finales que hoy ven estudiantes y docentes**, y con ellas la elegibilidad de certificados y el estado "en riesgo". Antes de actualizar un sitio con datos reales, correr `scripts/audit-zero-grade-averages.php` (solo lectura, fuera del ZIP).

- El motor (`CLMS_Assessment_Engine::build_course_gradebook()`) y el resumen propio de `CLMS_Grading` decidían qué combinar con `quiz_average > 0` / `assignment_average > 0`: quiz 0 + tarea 100 daba 100, y "sin notas" se guardaba como 0. Ahora ambos usan `CLMS_Grade_Average::combine()`: se combina por **cantidad de notas existentes**; `null` = sin notas, `0` = cero. Quiz no intentado o tarea no calificada no cuentan.
- El cálculo propio de `CLMS_Grading` (sin motor) pasa a `build_summary_from_lessons()` y aplica también la regla de nota liberada de 6.29.0, que ese camino no aplicaba.
- Mismo patrón corregido en: riesgo académico (`get_risk_level`, `build_risk_indicators`: un 0 ahora es riesgo; "sin notas" no), riesgo del resumen (`get_course_risk_level`: sin notas ya no es "en_riesgo"), gradebook (`build_student_summary`: sin notas no es riesgo alto), analítica de aprendizaje, informes académicos (un 0 entra al promedio del grupo), certificado de programa (un curso en 0 cuenta en el promedio), promedios del panel del estudiante y de analítica (un curso sin notas no entra como 0), competencias (con notas en 0 es "en desarrollo"), revisión entre pares (la nota del docente existe aunque sea 0), asistente docente y panel docente ("Sin datos" solo sin notas). Pantallas de progreso y académica del administrador muestran "—" sin notas.
- API móvil: `/grades` y `/courses/{id}/grades` devuelven `null` sin notas y `0` con nota cero (antes un curso con solo quizzes en 0 salía como `null`).
- Al actualizar se suben las versiones de caché `gradebook` y `analytics`: todos los resúmenes se recalculan.
- **Escala**: la nota global sigue siendo entera 0–100; decisión documentada en `docs/ESCALA-NOTAS.md` con los lugares donde se trunca o redondea.
- **TESTS**: `GradeZeroAverageTest` (quiz 0 + tarea 100 → 50; solo quizzes en 0 → 0; sin notas → `null`; quiz no intentado + tarea 80 → 80; motor y `CLMS_Grading` dan lo mismo; `/grades` separa `null` y `0`; riesgo con 0 y sin notas). Con el código de 6.29.4 fallan 4 de 6 (100 en vez de 50; 0 en vez de `null`; `/grades` y riesgo); los otros dos ya daban el número correcto y quedan como resguardo.

## 6.29.4 (2026-10-05)

- **Fix — SpeedGrader truncaba el puntaje de rúbrica al reabrir una entrega** (alta): `CLMS_Rubric_Panel_Renderer` cargaba el puntaje guardado con `absint()` en el `value` de `rubric_scores[]`; una entrega con 3,5 se mostraba con 3 y, al guardarla sin cambios (p. ej. para editar un comentario), se reenviaba 3 y la nota bajaba. Ahora el campo lleva el puntaje guardado tal cual, con sus decimales (`CLMS_Rubric_Level_Bands::score_field_value()`).
- **Nivel resaltado al reabrir**: usaba coincidencia exacta con `absint`; ahora usa las bandas de umbral de 6.29.3 (`CLMS_Rubric_Level_Bands::active_points()`), igual que al escribir en el campo, y muestra de entrada la pista "entre X y Y" / "por debajo de X".
- **Revisión del resto del plugin**: el único `absint()`/`(int)` sobre un puntaje de rúbrica que alimenta un formulario era este. La nota global 0–100 es entera en todo el sistema por diseño (campo `step=1` en SpeedGrader, `(int) round()` al guardar, `absint()` en el motor de evaluación y en el guardado del gradebook); no se cambia aquí.
- **Diagnóstico**: `scripts/audit-rubric-decimal-truncation.php` (solo lectura, `wp eval-file`) lista guardados seguidos de una misma entrega en `atora_rubric_evaluations` donde un criterio pasó de X,d a floor(X,d).
- **TESTS**: `SpeedGraderDecimalScoreTest` (integración): una entrega con 3,5 se renderiza con 3,5, resalta el nivel de 3 con "entre…", y guardada sin cambios sigue en 3,5. Falla con 6.29.3 (3,0). `RubricLevelBandsTest` y `RubricLevelDescribeTest` sin cambios, en verde.

## 6.29.3 (2026-10-04)

- **Fix — el nivel de la rúbrica no seguía la regla de SpeedGrader** (6.29.2): buscaba coincidencia exacta y convertía el puntaje con `(int)`/`absint()`, así 3,5 sobre niveles de 3 y 4 daba el nivel de 3. Las bandas de umbral de SpeedGrader (6.26.5) pasan a `CLMS_Rubric_Level_Bands` (`build()` sin cambios de comportamiento; `CLMS_Rubric_Panel_Renderer::build_level_bands()` delega en él) y `describe()` lee un puntaje igual que el panel: nivel exacto, "entre X y Y" o "por debajo de X". `rubric.rows[].level` usa ese helper con el puntaje decimal.
- **TESTS**: `RubricLevelBandsTest` sin cambios y en verde; `RubricLevelDescribeTest` nuevo (3,5 → "entre…", exacto → su nivel, mismas bandas que el panel); `MobileGradesCertificatesTest::test_rubric_level_follows_speedgrader_bands` (falla con 6.29.2: 3,5 daba "Suficiente").

## 6.29.2 (2026-10-04)

- **API móvil — nivel alcanzado por criterio** (Fase 2, Parte B.3): cada fila de `rubric.rows[]` en `GET /assignments/{lesson_id}` suma `level`, la etiqueta del nivel de la rúbrica cuyos puntos coinciden con el puntaje, la misma regla con la que SpeedGrader marca el nivel elegido. Vacío si el puntaje no coincide con ningún nivel. Sigue apareciendo solo con la nota liberada.
- **TESTS**: `MobileGradesCertificatesTest::test_rubric_only_after_release` comprueba el nivel (fallaba con 6.29.1).

## 6.29.1 (2026-10-04)

- **API móvil**: cada curso de `GET /grades` suma `graded_count` (notas liberadas) y `last_graded_at`, para que la app avise de una calificación nueva sin pedir el detalle de cada curso. Una nota guardada sin liberar no las cambia.
- **TESTS**: `MobileGradesCertificatesTest` lo comprueba con nota liberada y sin liberar.

## 6.29.0 (2026-10-04)

### Fase 2 — Rendir (plugin)

- **Fix — notas no liberadas visibles para el estudiante en la web**: SpeedGrader guarda nota, comentario y rúbrica también con "guardar borrador" (estado `in_review`), y el promedio del curso (`CLMS_Assessment_Engine::build_course_gradebook()` → `CLMS_Grading::get_course_grade_summary()`), el "último comentario y nota" del estado académico, el último comentario del resumen y las "devoluciones recientes" del panel los leían sin mirar el estado. Ahora una sola regla, `CLMS_Student_Grade_Visibility` (nota solo con `graded`; comentario también con `needs_revision`/`returned`), la aplican todos esos caminos y la vista de la tarea. La celda del docente en el gradebook sigue mostrando su borrador. Al actualizar se invalida la caché del gradebook.
- **NEW — `CLMS_Student_Grades_Service`**: notas por actividad (con grupo y peso del esquema, estado `graded`/`in_review`/`needs_revision`/`completed`/`pending`), resumen por curso (nota acumulada del panel, avance de `atora_lms_get_progress()`, estado académico) y por programa. Lo usan las rutas móviles; la web y la app dan el mismo número.
- **NEW — API móvil**: `GET /grades`, `GET /courses/{id}/grades`, `GET /certificates` y `GET /certificates/{course|program}/{id}/document`. En `GET /assignments/{lesson_id}` cada intento suma `rubric` (puntaje y comentario por criterio) solo con la nota liberada, e `in_review` mientras no. `capabilities.grades` y `capabilities.certificates`. Las rutas nuevas heredan el filtro de no-caché de 6.28.1.
- **Certificados (provisional HTML)**: la vista se separa en `resolve_certificate_for_user()` (permisos, requisitos y registro, sin nonce) y `render_certificate_document()` (el mismo HTML); la web y la app usan ambos. La app recibe un enlace firmado (HMAC con usuario, objeto y vencimiento de 15 minutos) que además exige el token del mismo usuario. El formato institucional (logo, firmas, QR verificable) queda para más adelante.
- **Fix**: el certificado ya no avisa si el registro no trae `verification_code`.
- **TESTS**: `tests/integration/MobileGradesCertificatesTest.php` (nota guardada no liberada no aparece ni cuenta en la web; liberada coincide web y app; no se ven notas de otro; rúbrica solo tras liberar; enlace del certificado vencido o de otro usuario → 403; rutas nuevas no cacheables).

## 6.28.2 (2026-10-04)

### Correcciones de la auditoría y varios videos por lección

- **Fix — errores de base de datos en la API móvil**: `$wpdb->query()` devuelve `false` ante un error SQL y se trataba como "0 filas". La posición respondía 200 (la app daba el evento por entregado y la perdía). Ahora un error responde **503** y la app reintenta; el error se registra sin valores (`ATORA_Mobile_Db_Errors`). Mismo patrón corregido en subidas (`advance_upload` lo confundía con fragmento fuera de orden → 409), cierre de subida y cierre de entrega (`update_upload` / `update_submission` ignoraban el fallo → 200). El registro de cambios, que corre dentro del guardado del editor, registra el fallo.
- **NEW — `atora_lms_get_progress( int $user_id, int $wp_course_id ): int`** (`includes/public-api.php`): API estable para el tema. Recibe el ID del **post** del curso y lo traduce al `id` de `atora_courses` antes de `LMS_Enrollment_Service::get_progress()`; mismo porcentaje que la app.
- **NEW — varios videos por lección**: la lista se extrae a `ATORA_Lesson_Videos` y la usan la web (`CLMS_UI_Lesson_Sections::normalize_videos()`) y la API, con el mismo límite (`CLMS_UI_Lesson_Sections::videos_limit()`: esquema de la plantilla + `_clms_lesson_ui_limit_videos`). `/lessons/{id}` devuelve `videos[]` en el orden del editor (`key`, `title`, `description`, `source`, `url`, `embed_url`, `provider`, `thumbnail_url` por video, `downloadable`, `bytes`, `resume_position_seconds`). `key` = 12 caracteres del sha1 de la URL normalizada (YouTube, Vimeo y Drive por identificador), sufijo solo si la URL se repite: reordenar no la cambia. Los campos de un solo video se mantienen con el primero (APK 0.4.0). El currículo suma `video_count`. `capabilities.multi_video`.
- **Posición por video**: `PUT /lessons/{id}/position` acepta `video_key` (404 si no es de la lección). `atora_lesson_positions` suma `video_key` (vacío = primer video) e índice único usuario + lección + video; las filas existentes quedan en el primer video. Para el primero gana la marca más reciente entre su clave y la vacía. Esquema `6.28.2-video-positions`.
- **TESTS**: `tests/integration/MobileAuditMultiVideoTest.php` (503 con un fallo SQL forzado, progreso 2 de 4 = 50 con el ID del post, 3 videos en orden y límite, claves estables al reordenar, posición por video y app anterior, `video_count` y campos de compatibilidad) y casos de error en `tests/Security/MobileAssignmentServiceTest.php`. Fallan con el código de 6.28.1.

## 6.28.1 (2026-10-04)

### Seguridad — API móvil sin caché de página

- **Fix**: con LiteSpeed Cache activo en el demo, `GET /dashboard` respondía `200` desde caché (`x-litespeed-cache: hit`) incluso con un token inventado: cualquier usuario recibía el panel guardado del primero que entró. Afectaba a toda ruta `atora-mobile/v1`.
- Toda respuesta de `atora-mobile/v1` (incluidos errores y 401) sale con `Cache-Control: no-store, private`, `X-LiteSpeed-Cache-Control: no-cache`, `CDN-Cache-Control: no-store` y `Vary: Authorization`; además dispara `litespeed_control_set_nocache` y define `DONOTCACHEPAGE`. No depende de la configuración del plugin de caché.
- **Al actualizar**: purgar la caché del sitio una vez (LiteSpeed → Purgar todo) para descartar lo que ya estaba guardado.
- **TESTS**: `tests/integration/MobileNoCacheTest.php` (falla sin el arreglo).

## 6.28.0 (2026-10-04)

### Fase 1 — Aprender (API móvil)

- **NEW — `GET /sync/changes?cursor=`**: sin cursor, estado completo resumido de los cursos matriculados; con cursor, solo lo que cambió (id, tipo, `revision`, `removed`), las matrículas propias nuevas o terminadas y `enrolled_course_ids` vigente. Siempre `next_cursor`; paginado de a 200; varios guardados del mismo objeto llegan como uno; `reset: true` si el cursor es más antiguo que lo conservado o ilegible.
- **Registro de cambios** `atora_content_changes` (con `institution_id` y, en matrículas, `user_id`): se escribe desde un único punto, la acción `atora/lms/content_revised` que emiten `LMS_Course_Service::update*()` y la sincronización del editor de 6.27.4, más `atora/lms/enrolled` y `atora/lms/unenrolled`. Retención de 90 días (cron diario).
- **Huella `content_hash`** en `atora_lessons`: cambiar solo recursos, videos, consignas, fechas o tipo de actividad también sube `revision`. Las filas existentes la reciben en silencio, sin subir `revision`; la reparación única vuelve a correr una vez para rellenarla.
- **Descargas**: cada recurso de `/lessons/{id}` suma `downloadable` (solo adjuntos de la academia o archivos del mismo dominio), `bytes` y `updated_at`; la lección suma `video_downloadable` (solo MP4 de la academia) y `video_bytes` (`ATORA_Download_Info`).
- **NEW — `PUT /lessons/{id}/position`** y `resume_position_seconds`: tabla `atora_lesson_positions`, una fila por usuario y lección; gana la marca más reciente según `client_recorded_at` aunque sea menor; reenvío idempotente; sin matrícula, 404. Regla añadida a `docs/SINCRONIZACION-OFFLINE.md`.
- **Esquema** `6.28.0-sync-schema`. `GET /discovery` declara `sync_changes`, `playback_position` y `resource_downloads`. `docs/MOBILE-API-V1.md` actualizado.
- **TESTS**: `tests/integration/MobileSyncChangesTest.php` (cursor y paginación, cursos ajenos, colapso y bajas, estado completo paginado, reset por retención, matrícula por usuario, purga, posición: tardía no pisa, reenvío, repaso, ajeno 404) y `tests/Media/DownloadInfoTest.php`.

## 6.27.4 (2026-10-04)

### El editor de WordPress mantiene la tabla al día

- **Fix** — el editor solo escribía el post y sus metas. Verificado en local antes del arreglo: una lección creada después de migrar su curso no entraba a `atora_lessons` (ni al currículo de `/courses/{id}`); los cambios de título, extracto y orden, el paso a borrador y la papelera, de cursos y de lecciones, no llegaban a la tabla; `revision` no subía nunca.
- **Sincronización** (`LMS_Editor_Sync`): en `wp_after_insert_post` (después de las metaboxes) inserta o actualiza la fila con las funciones del migrador y sube `revision` una vez si algo cambió; guardar sin cambios no la sube. El borrado definitivo marca la fila `deleted` (no se elimina, por las referencias de progreso).
- **Migrador**: el armado de filas se extrae a `course_row_from_post()`, `lesson_row_from_post()` y `migrate_course_post()`, sin cambio de comportamiento; la sincronización las reutiliza.
- **Reparación**: una vez tras actualizar, en segundo plano (cron único; opción `atora_lms_editor_sync_repaired`), y con `wp atora lms align-tables [--dry-run]`. Inserta lo que falta, actualiza lo que difiere, marca las filas sin post; repetible, no toca lo que está bien. Cursos: título, slug, descripción, extracto, estado, portada y fecha de publicación; instructor y precio siguen con su propio flujo.
- **TESTS**: `tests/integration/EditorTableSyncTest.php` (WordPress real): lección nueva en el currículo, título/orden con `revision`, guardar sin cambios, borrador/papelera fuera del currículo, borrado definitivo, cambios de curso, reparación selectiva y repetible. Fallan los 7 sin el arreglo.

## 6.27.3 (2026-10-03)

### Portadas de cursos en la app

- **Fix** — la API móvil tomaba la portada de la columna `thumbnail_url` de `atora_courses`, que solo llenaba el migrador: los cursos creados o con imagen cambiada después de migrar llegaban a la app sin portada o con la vieja.
- **Resolución única** en `ATORA_Course_Cover_Resolver::resolve()`: imagen destacada del post del curso (`large`) → columna de la tabla. La usan lista de cursos, detalle, panel "Hoy" y cursos de un programa; el resolvedor de miniaturas de video la usa como último paso.
- **Sincronización**: añadir, cambiar o quitar la imagen destacada (`_thumbnail_id`) de un `lm_course` actualiza la columna.
- **Reparación**: una vez al actualizar (opción `atora_course_covers_repaired`) se rellena la columna desde la imagen destacada donde difiere; repetible con `wp atora courses repair-covers [--dry-run]`. No toca cursos correctos ni cursos sin imagen destacada.
- **Programas**: sin cambios; ya leían la imagen destacada en vivo.
- **TESTS**: `tests/Media/CourseCoverResolverTest.php`.

## 6.27.2 (2026-10-03)

- **Fix — assets faltantes (404)**: se agregan `modules/security/assets/registration.js`, `modules/security/assets/registration.css` y `modules/analytics/assets/popups.js`, que el registro extendido (`class-extended-registration.php`) y los popups (`class-popups.php`) encolaban sin que existieran (PR #19).
- **TESTS**: `tests/Assets/EnqueuedModuleAssetsTest.php` falla si algún asset encolado por esos dos módulos no existe en disco.

## 6.27.1 (2026-10-03)

### Miniaturas de video en la API móvil

- **NEW** — `video_thumbnail_url` en `GET /lessons/{id}` y en cada lección del currículo de `GET /courses/{id}` (que además informa `has_video`).
- **Resolución** en un único método, `ATORA_Video_Thumbnail_Resolver::resolve()`: miniatura manual del editor de la lección → YouTube (imagen pública por identificador) → Vimeo (oEmbed consultado una vez en segundo plano y guardado 30 días; los fallos, 1 día) → imagen destacada de la lección → portada del curso. Google Drive y MP4 directo caen a los dos últimos. Sin llamadas externas durante la petición; nunca vacío si el curso tiene portada.
- **Refactor**: la elección del video principal de la lección pasa a `resolve_lesson_video_url()` y la usan las dos rutas.
- **TESTS**: `tests/Media/VideoThumbnailResolverTest.php` (orden de resolución, caché de Vimeo, fallos, Drive/MP4, portada).

## 6.27.0 (2026-10-03)

### Entregas de tareas por la API móvil

- **NEW — rutas** `atora-mobile/v1`: `GET /assignments/{lesson_id}`, `POST /assignments/{lesson_id}/submissions`, `POST /uploads/sessions`, `PUT /uploads/{upload_token}` (`Content-Range`) y `POST /uploads/{upload_token}/complete`. `GET /lessons/{id}` informa `assignment_available`; `GET /discovery` declara `capabilities.assignments`.
- **Reglas offline** (`docs/SINCRONIZACION-OFFLINE.md`): cada intento es una fila nueva en `atora_assignment_submissions`; idempotencia por `client_event_id` por usuario; `is_late` con `server_received_at` contra `due_at`, y `client_submitted_at` como dato informativo.
- **SpeedGrader**: cada intento móvil actualiza también el post `clms_submission` (mismo código que la web, enlazado por `wp_post_id`), así el docente lo ve y lo califica. SpeedGrader muestra "Realizada sin conexión el …" y "Recibida después de la fecha límite". Un reenvío desde la web limpia esas marcas.
- **Seguridad**: matrícula con `authorize_course_id` (403); `upload_token` de otro usuario → 404; tipos y tamaño de la lista web (`CLMS_Submission::get_upload_policy()`), con validación del tipo real al completar; fragmentos en orden; sesiones de 6 h con limpieza horaria (`atora_mobile_upload_cleanup`); fragmentos en `uploads/atora-private/mobile-uploads` con acceso web denegado (filtro `atora/mobile/upload_dir`); límite de frecuencia con `ATORA_Rate_Limiter`.
- **Contrato**: `docs/CONTRATO-ENTREGAS-MOVIL.md` deja de ser "previsto"; la subida se ata a la lección (no a una entrega previa), se aceptan entregas vencidas marcadas como tardías, y las tareas grupales se rechazan en móvil (422).
- **TESTS**: `tests/Security/MobileAssignmentServiceTest.php` (idempotencia, solo-añadir, token ajeno, `is_late`, tipo real, fragmentos fuera de orden, sesión caducada, puente fallido) y `tests/Rest/MobileAssignmentRoutesTest.php` (no matriculado, lección sin tarea, capacidad), ambos en la suite de seguridad del CI.

## 6.26.75 (2026-10-03)

### Login frontend (cierre)

- **FIX — enlaces de login restantes**: `single-course-commercial`, `partials/course/section-hero`, `section-hero--centered`, `section-cta`, `single-program` (CTA móvil), `single-lesson` y matrícula por enlace libre usan `CLMS_Frontend_URLs::login_url()`. 6.26.73 había dejado estos fuera.
- **TESTS**: `TemplatesLoginLinksTest` falla si una plantilla vuelve a llamar `wp_login_url()` directamente.

## 6.26.74 (2026-10-03)

### Hotfix — 404 de cursos tras subir el ZIP

- **FIX — activación**: `atora_lms_activate()` hacía `flush_rewrite_rules()` cuando los CPT aún no estaban registrados (la activación corre después de `init`), guardando reglas sin `/cursos/` ni `/programas/`, y marcaba `atora_lms_rewrite_version` como aplicada. Ahora borra esa opción y el flush ocurre en `wp_loaded` del siguiente request, con todo registrado.
- **REWRITE**: `ATORA_LMS_REWRITE_VERSION` → `6.26.74` para que los sitios ya afectados se reparen solos al actualizar.

## 6.26.73 (2026-10-03)

### Login frontend

- **FIX — enlaces de login**: cursos (`single-course`, heros), programas (secciones comerciales y CTA móvil), lecciones bloqueadas, perfil, invitaciones/matrícula por enlace y panel de afiliados usan `CLMS_Frontend_URLs::login_url()` → `/cuenta/?redirect_to=…` cuando existe la página de cuenta. Correos, 2FA y Calendar siguen usando `wp-login.php`.
- **FIX — `[clms_login_form]`**: `?redirect_to=` de la URL tiene prioridad sobre el atributo `redirect`; antes, entrar desde un curso terminaba en el panel en vez de volver al curso.
- **TESTS**: `tests/Frontend/FrontendUrlsLoginTest.php`.

## 6.26.72 (2026-10-02)

### Demo — URLs frontend + matrículas

- **NEW — `Frontend_URLs`**: los enlaces de "Cuenta/Ingresar" y el redirect de `/dashboard/` prefieren `/cuenta/` y `/dashboard/` si existen, en lugar de `wp-login.php`.
- **NEW — matrículas en operaciones académicas**: pantalla de administración con `atora-enrollments-admin.js`.
- **FIX — permalinks**: `ATORA_LMS_REWRITE_VERSION` sube para forzar flush (cursos y programas individuales daban 404 en demo).
- **TOOLING**: `scripts/bootstrap-demo-pages.sh`, `scripts/demo-audit.mjs` y `docs/DEMO-PLAYBOOK.md`.
- **BUILD**: `build-dist.sh` detecta árbol sucio (`dirty`) en vez de forzar `false`; `.distignore` e `inspect-dist.py` excluyen reportes `demo-audit*`.

## 6.26.71 (2026-09-28)

### Hotfix — pilot online + empaquetado

- **FIX — visibilidad de cursos en `trash`**: el curso deja de mostrarse a estudiantes; los administradores lo conservan para diagnóstico.
- **FIX — matrícula bloqueada**: intentar matricularse a un curso en `trash` devuelve 409 y garantiza no escribir en legacy ni en tablas.

## 6.26.7 (2026-09-22)

### Hotfix Lab — estabilidad académica + QA

- **FIX — Gradebook roster en modo `tables`**: el roster y las celdas de SpeedGrader vuelven a resolverse con identidad correcta aun si faltan metadatos legacy de matrícula.
- **FIX — enlaces profundos SpeedGrader**: enlaces canónicos y cortos preservan `submission_id` y retorno sin ser absorbidos por redirecciones del catálogo.
- **TEST — redirect `clms-cohorts`**: el test se ejecuta en un proceso PHP separado para evitar falsos positivos por `exit()` en `maybe_redirect()`.

## 6.26.5 (2026-09-21)

### SpeedGrader + Rúbricas — fiabilidad del dato y trazabilidad

- **FIX — puntajes por criterio**: el número escrito por el docente es la fuente de verdad (no se trunca), con validación de rango y decimales en cliente y servidor.
- **MEJORA — total de rúbrica**: muestra parcial con cuántos criterios faltan y feedback claro cuando hay valores inválidos.
- **FIX — resaltado de nivel**: pasa a regla de umbral (sin “inflación”), con etiqueta “entre X e Y” cuando corresponde y tooltip de franjas por criterio.
- **MEJORA — nota final manual**: muestra el % de la rúbrica como referencia junto al campo de nota final y un botón para copiarlo.
- **MEJORA — auditoría**: registra nota final + total/máximo/% de la rúbrica y revisión usada en la evaluación inmutable.

### Formularios (landing pública)

- **FIX — acentos rotos**: normaliza escapes unicode rotos (`u00e9`/`\u00e9`) al leer/guardar schema y migra schemas existentes una sola vez.
- **FIX — select “Array”**: soporta opciones como strings o pares `{value,label}` y valida server-side que el valor enviado exista en las opciones del schema.

### Sello de versión

- **NUEVO — build info**: expone versión/commit en admin, `GET /discovery` y `wp atora version`; build-info se incluye en el ZIP de distribución y CI lo verifica.

> **Sin etiquetas entre v6.21.1 y v6.26.1.** Las versiones 6.22.0 a 6.26.0 no tienen etiqueta de git; sus entradas vienen del changelog de `readme.txt`. No se crean etiquetas retroactivas.

## 6.26.4 (2026-09-21)

- **Tenancy**: migración y verificación de `academy_id` → `institution_id`, con limpieza de la columna legacy en esquema y rollback.
- **Acceso**: el coordinador se habilita por membresía institucional; capacidades muertas y duplicadas eliminadas.
- **Despliegue**: perfiles small/medium/large con avisos de cupo y límites operativos documentados (tiempos de escala 10k).
- **Tests**: integración real de aislamiento, escala y migración; suites aisladas y estabilizadas. Línea base en `docs/BASELINE-6.26.4.md`.

## 6.26.3 (2026-09-21)

- **Tenancy y delegación**: resolución completa de institución (sin `institution_id` 0), servicios tabulares de cohortes, auditoría y detector.
- **Licencias**: reporte de asientos por institución.
- **Esquema**: columnas e índices completados; `delegations` normalizada.
- **CI**: pipeline completo restaurado con smoke de rollback; pruebas de aislamiento de inquilino, escala de cohortes y delegación.

## 6.26.2 (2026-09-21)

- Mobile API: endpoints de programas (`/programs`) y fallback de evaluaciones desde tablas `atora_quizzes` con persistencia a `atora_quiz_submissions`.

## 6.26.1 (2026-09-21)

- Mobile API: recursos de lección (guías/archivos/enlaces) visibles y descargables en app móvil.
- Onboarding: selector de logo desde la biblioteca de medios + vista previa.

## 6.26.0 (2026-09-12)

- Integración institucional: Gradebook institucional + SpeedGrader 2 (moderación) + Biblioteca académica + Credenciales verificables con QR local.
- Mobile API v1: autenticación con tokens opacos, endpoints estudiantiles, y compatibilidad canónica de matrículas legacy/tablas (incluye `active` y `completed`, deduplicación y autorización centralizada).

## 6.25.0 (2026-09-10)

- Added the institutional credential engine with immutable snapshots and public verification.
- Added local QR generation without third-party data disclosure.
- Added two-person revocation, irreversible revocation, replacement credentials, and tamper-evident audit events.

## 6.24.0 (2026-09-10)

- Biblioteca académica MVP: recursos con identidad estable, versiones inmutables y checksum SHA-256, flujo borrador/revisión/publicación/archivo y bitácora completa.
- Los recursos pueden vincularse con competencias y actividades de evidencia validadas contra su curso; API administrativa aislada bajo `/clms/v1/library`.

## 6.23.0 (2026-09-10)

- SpeedGrader 2: flujo institucional de doble revisión con propuesta docente, aprobación o devolución por autoridad distinta, rúbricas y notas moderadas, bloqueo de cierre con moderaciones pendientes y auditoría en el Gradebook.
- Los cursos sin ciclo institucional activo conservan el flujo de publicación existente.

## 6.22.0 (2026-09-10)

- Gradebook institucional: períodos académicos, escalas versionadas, ciclos de revisión/publicación/cierre, notas oficiales con control de concurrencia, snapshots verificables y rectificaciones con separación de funciones.
- Nueva API administrativa aislada bajo `/clms/v1/gradebook/institutional`; el Gradebook existente por curso conserva su comportamiento.

## 6.21.1 (2026-09-10)

- Migrator: correcciones del Cutover F4 y refuerzo del gate de “ready” con cobertura adicional de PHPUnit.

## 6.21.0 (2026-09-10)

- Sprint de estabilización pre-producción (S0-S5): bloqueantes de CI/loader, autorización y CSRF en Grupos, integridad de datos y rendimiento de Alertas Tempranas, saneamiento de exports CSV, y ampliación de la suite de tests automatizados.
- CI: la versión del smoke test y del ZIP de distribución ahora se resuelve dinámicamente desde `atora_lms.php` en vez de estar hardcodeada.
- Loader: corregido un fallo de activación de `CLMS_Student_Assistant` cuando el módulo de IA está desactivado.
- Grupos: los handlers de crear/guardar miembros/autogenerar/exportar ahora verifican gestión real del curso (no solo `edit_posts`); exportación exige nonce; `guardar miembros` valida que el grupo pertenezca al curso del request.
- Grupos: `set_members_with_options()` es transaccional (rollback ante fallo parcial); `set_override()` valida que el estudiante sea miembro del grupo y que la lección pertenezca a su curso.
- H5P: ownership por-ítem en la API REST de contenido (leer/editar/borrar), listado filtrado por autor salvo administradores.

## 6.20.0 (2026-09-10)

- Epic 7 (Microsoft): SSO con Entra ID (vincular/desvincular cuenta), webhooks de Teams y suscripción iCal de Outlook por curso (token de acceso scoped por curso, sin exponer datos de otros cursos).

## 6.19.0 (2026-09-10)

- H5P: módulo standalone con endpoints REST de gestión de contenido, tracking de intentos (xAPI) y autoscore hacia el libro de calificaciones.

## 6.18.11 (2026-09-10)

- Grupos: hardening de casos límite (cambios de grupo, invalidación de caché, unicidad de membresía por curso).

## 6.18.10 (2026-09-10)

- Grupos: bloquea el envío de una entrega grupal si el estudiante no tiene grupo o asignación válida.

## 6.18.9 (2026-09-10)

- Fix: evita que queden entregas "sombra" huérfanas cuando un estudiante cambia de grupo.

## 6.18.8 (2026-09-10)

- Grupos: vista de estudiante, adjuntos compartidos entre miembros del grupo, y hardening general del módulo.

## 6.18.7 (2026-09-10)

- Fix: evita el recálculo duplicado de notas al calificar la entrega maestra de un grupo.

## 6.18.6 (2026-09-10)

- Rúbricas: snapshot por entrega para mantener versionado consistente en SpeedGrade aunque la rúbrica cambie después.

## 6.18.5 (2026-09-10)

- Learning Analytics: nuevas señales de mensajes/lecturas y hardening del procesamiento batch.

## 6.18.4 (2026-09-10)

- Portfolios: hardening de QA (cambios de inscripción) y caché por request.

## 6.18.3 (2026-09-10)

- Classroom: hardening de QA (retry/backoff en llamadas a la API) y hoja de ruta.

## 6.18.2 (2026-09-10)

- Classroom: push de notas hacia Google Classroom (borrador + devolución).

## 6.18.1 (2026-09-10)

- Classroom: importar coursework de Google Classroom como lecciones (MVP).

## 6.18.0 (2026-09-10)

- Classroom: MVP de mapeo de cursos ATORA↔Google Classroom y sincronización de roster por email.

## 6.17.2 (2026-09-10)

- Portfolios: exportación en ZIP (snapshot) y hardening de acceso privado.

## 6.17.1 (2026-09-10)

- Portfolios: evaluación final con rúbrica (override).

## 6.17.0 (2026-09-10)

- Epic 5 (MVP): Portfolios de estudiante (sin CPT dedicado) + tablas de base de datos nuevas.

## 6.16.1 (2026-09-10)

- Peer review: calibración bloquea revisiones hasta completarse (pass/warn), con fallback seguro.

## 6.16.0 (2026-09-10)

- Epic 4 (MVP): Coevaluación mejorada — calibración por lección, scoring de consistencia (outliers) y reporte admin.
- DB: nueva tabla de auditoría `wp_clms_peer_review_audit_log`.
- Lesson settings: modo ciego + calibración (ejemplar + pauta) disponibles en la metabox de evaluación.

## 6.15.4 (2026-09-10)

- Learning Analytics: incluye “entregas perdidas” en señales y export BI (CSV/JSON) usando `wp_atora_early_warning`.

## 6.15.3 (2026-09-10)

- Learning Analytics: trend/delta por estudiante, nuevos tipos de alerta (high_risk/inactivity/risk_spike) y notificación con contexto (curso + delta).
- Learning Analytics: export BI incluye trend/delta/alert_type (CSV/JSON).
- DB: `wp_atora_student_analytics` agrega columnas de trend/delta para consultas más rápidas.

## 6.15.2 (2026-09-10)

- Learning Analytics: export JSON (admin + REST) con esquema BI-friendly (schema_version=1).
- Learning Analytics: timeline de estudiante (submissions recientes + links a SpeedGrade) y entregas perdidas en el detalle.
- Early Warning: helper público para listar entregas perdidas por estudiante/curso (reusado por Learning Analytics).

## 6.15.1 (2026-09-10)

- Learning Analytics: filtros por cohorte/docente, listado multi-curso, export CSV multi-curso y vista de detalle por estudiante.
- Learning Analytics REST: endpoints por cohorte/docente y scan por cohorte/docente.

## 6.15.0 (2026-09-10)

- Added Learning Analytics (MVP): risk snapshots per student/course (DB table + daily cron + REST + admin dashboard + CSV export).
- Added internal notifications (MVP): alerts teachers when a student reaches high risk (daily anti-spam).

## 6.14.1 (2026-09-10)

- Hardening Group Assessment: enabled “Trabajo en grupo” mode in lesson UI and added group context to the student submission form.
- Improved course group management UI (create groups, assign members, safe preset autogeneration) and added locked-group safe add-only flow.
- Prevented double grading in SpeedGrade by excluding shadow submissions and restricting group lessons to master submissions only.

## 6.14.0 (2026-09-10)

- Added Group Assessment (work in groups): course-level group management, group submissions, grade propagation to all members, per-student overrides, and CSV export.
- Improved Rubrics: per-criterion weights (auto-normalized), configurable scales (0–4, 0–5, 0–20, 0–100, A–F), holistic flag, and exemplars/benchmarks per level. Added shared rubric presets.
- Added Early Warning (MVP): detects missed submissions and sends internal notifications to teachers; includes REST endpoint for course warnings.

## 6.13.3 (2026-09-01)

- Fixed Zoom attendance for students whose connection drops and reconnects mid-class — previously each reconnection was scored separately against the full class duration, so a student present the whole class could be marked "partial" instead of "present." Attendance is now aggregated per person before scoring, and duration is summed instead of overwritten by the last reconnection.
- Version housekeeping: the plugin version constant, header, and changelog now match the actual release history (they had been stuck at 6.11.0 since 6.12.0). This constant also gates cache-busting for enqueued scripts/styles and the data sent to the licensing server, so keeping it accurate matters beyond cosmetics.

## 6.13.2 (2026-09-01)

- Fixed Zoom attendance silently dropping unidentified/guest participants — Zoom now records guests the same way Google Meet already did, instead of discarding them.
- Fixed the schema migration permanently losing the link to who organized a Google Meet session if that session's data was never fully saved — the safety option is now kept (and logged) instead of deleted.
- Fixed Google Drive attachment permissions so a teacher or admin can attach material to their own course even when they aren't personally enrolled in it.

## 6.13.1 (2026-09-01)

- Added the live-class screen for lesson editing (provider selection, schedule, join link, and an attendance panel) — previously there was no admin screen at all for setting up a live class or reviewing who attended.
- Fixed several Google Meet attendance cases: multiple unidentified/guest participants in the same class no longer collapse into one attendance record, and the screen now explains clearly when a Meet class was created outside ATORA and therefore can never have automatic attendance.
- Fixed an open-registration gap: signing in with Google could create new accounts even when self-registration was supposed to be disabled for the Institution profile.
- Fixed a permissions gap letting any logged-in user attach a Google Drive file reference to another student's submission or an unrelated lesson.
- Hardened Google sign-in against abuse (rate limiting, stricter token checks) and against a silent failure if the encryption key protecting connected Google accounts is rotated — the account now shows "needs reauthorization" instead of just failing.
- Fixed the "N consecutive absences" alert using the order attendance was entered instead of the order the classes actually happened, and made it open a follow-up case for a student who never had one instead of only updating existing cases.

## 6.13.0 (2026-09-01)

- Added persistent storage for live-class sessions and attendance (previously only kept as scattered post/user metadata, not queryable or reportable).
- Added Google Meet as a live-class provider alongside Zoom — creation, join links, and attendance tracking (including unidentified guests, shown as such rather than silently ignored).
- Added Google sign-in/registration, Google Calendar sync improvements, and Google Drive integration for course materials and submissions, all using your own Google Cloud project (no shared app to wait on for verification) and the least-privileged Drive access level available.
- Added an automated audit trail (who enrolled whom, who changed a grade, who exported data) and a dedicated Institution profile screen surfacing it alongside cohort and gradebook-export shortcuts.
- Connected attendance to the rest of the platform: repeated absences now surface in the teacher's follow-up queue and in the "Today" panel.
- Encrypted stored Google connection tokens at rest (previously stored in plain text).

## 6.12.0 (2026-09-01)

- Replaced the three installation profiles with four clearer ones — Teacher, Institution, Creators, Academy — each a better fit for a specific kind of school, with no change to existing installs on upgrade.
- REST API routes belonging to a disabled module are no longer registered at all, closing a gap where deactivating a module in the admin screen didn't actually stop its API from responding.
- New installations now only create the database tables their chosen profile needs, instead of always creating every table upfront.

## 6.11.0 (2026-08-25)

- Added a real student intervention timeline: at-risk alerts (inactivity/low-grade) and manual notes from teachers/coordinators are now recorded on a per-student history, viewable from a new student profile screen.
- Added the ability to assign a section coordinator from the existing cohort screen — previously this required a direct database edit and had no admin UI at all.
- "Today" now surfaces a real count of at-risk students for anyone assigned as a section coordinator, instead of showing nothing.
- Internal: extracted the CRM's core contact primitives (access checks, activity log, timeline) into a standalone service that no longer requires the CRM module to be active — existing CRM behavior is unchanged.

## 6.10.0 (2026-08-25)

- Removed the public student leaderboard — competitive ranking doesn't fit the "support, not competition" approach the rest of the platform already follows.
- Added individual student badges per course, shown right on the student's own dashboard (no shortcode, no separate page): Estudiante sobresaliente, destacado, aplicado, regular, or en atención, based on punctuality, grade average, and course participation — each student is judged only against their own work, never compared to classmates.
- No changes to personal points/levels already shown on the student dashboard, or to any other screen.

## 6.9.1 (2026-08-25)

- Fixed: the rubric listing endpoint let any instructor see every other instructor's rubrics, not just their own — the individual-rubric endpoints already scoped correctly, only the list didn't.

## 6.9.0 (2026-08-25)

- Added a persistent search icon, visible in the same place on every ATORA admin screen — find a student, section, or contact directly, scoped to exactly what you'd already see by navigating normally.
- Added "Actividad" (Activity): a simple, calm feed of what you've already resolved this week — contacts marked, students who improved — right next to "Hoy", never mixed into it.
- Extracted the shared panel and list-row components that the academic calendar, commercial calendar, and "Hoy" each used independently — one component now, so future improvements to it apply everywhere at once. Same look, same behavior as before for existing users.
- No changes to the academic followup plans, commercial followup plans, or "Hoy" beyond this internal consolidation — everything works the same or better, never worse.

## 6.8.0 (2026-08-24)

- Added "Hoy" (Today): a single entry screen that crosses academic followup occurrences, commercial followup occurrences, pending grading/inactive-students/quizzes, and overdue/upcoming tasks into one list, ordered by real urgency — no more deciding where to start.
- Every item links directly to the specific action (an occurrence's side panel, a contact, a task) instead of a generic listing you have to search through again.
- "Hoy" is now the default landing page after login for teachers and salespeople; administrators keep their existing flow unchanged.
- The existing daily digest message now links straight to "Hoy" instead of the general students hub.
- A calm, specific message appears when there's nothing urgent — never a blank screen.
- No changes to the academic followup plans, commercial followup plans, teacher panel, or CRM — "Hoy" only reads from them, it doesn't replace any of them.

## 6.7.0 (2026-08-24)

- Added commercial followup plans: the same followup-plans engine built for teachers now serves salespeople on the commercial pipeline, with its own vocabulary and four starter templates (Deals estancados, Nutrir leads fríos, Antes del cierre de mes, Cuenta clave).
- Calendar blocks in the commercial view color by the conversion score of the most urgent contact in that occurrence — same design tokens as the rest of the site, no new palette.
- The side panel now shows each contact's active email-sequence enrollment (if any) so a salesperson always knows a contact is already being worked by an automated sequence — visible by default, never hidden without an explicit opt-in filter, and never altered automatically.
- A simple domain switch (Estudiantes/Ventas) appears only for users who have both academic and commercial plans — everyone else sees exactly what they saw before.
- No changes to existing academic followup plans, the teacher calendar, or the CRM followup board for installs that don't create a commercial plan.

## 6.6.0 (2026-08-24)

- Added followup plans: teachers can set up their own contact rhythm with at-risk students on a visual monthly calendar (Planes de seguimiento).
- Four starter templates ("Chequeo semanal", "Alta atención", "Antes del cierre", "Solo hitos"), editable and saveable as the teacher's own reusable variants.
- Each occurrence resolves its student list live against the existing academic followup board at the moment it's viewed — a plan never freezes a student list, and marking a student as contacted never changes their followup stage.
- Drag an occurrence to reprogram it, pause a plan without losing its history, skip a single occurrence, or exclude one student from one occurrence — all from the same calendar screen, no separate settings page.
- Teachers get a notice on the day of an occurrence with students to review; empty occurrences never send a notice.
- No changes to the existing calendar or followup board behavior for installs that don't create a followup plan.

## 6.5.13 (2026-08-24)

- Fixed course and instructor profile links still returning 404 after 6.5.12's automatic rewrite-rule refresh, on sites running a page-caching plugin.
- The plugin now purges known page-caching plugins (LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround Optimizer) after refreshing its rewrite rules.
- The admin "Purge cache" button now also clears page cache, not just the WordPress object cache.

## 6.5.12 (2026-08-24)

- Fixed routing bootstrap order for course registration.
- Fixed instructor profile rewrite registration timing.
- Ensured structural routing hooks are registered before WordPress `init` processing.
- Preserved controlled one-time rewrite flushing.
- Added hook-order regression tests.
- Preserved 6.5.11 runtime performance improvements.

## 6.5.11 (2026-08-24)

- Restored course permalink routing after 6.5.10.
- Restored instructor profile routing.
- Added controlled one-time rewrite migration behavior.
- Removed expensive admin-menu diagnostics from normal runtime.
- Reduced production log noise.
- Added rewrite and runtime-performance regression checks.

## 6.5.10 (2026-08-24)

- Fixed legacy messaging bridge namespace resolution.
- Fixed invalid external access to protected loader path resolution.
- Ensured required LMS parity tables are created during install/upgrade.
- Improved MySQL/MariaDB utf8mb4 index compatibility.
- Centralized Atora WP-Cron schedule registration.
- Improved schema migration idempotency.

## 6.5.9 (2026-08-22)

- Security: two-factor login verification (the actual POST form used by the login flow, not just its AJAX counterpart) is now rate-limited per pending login, covering both regular and backup codes through a single shared quota.
- Security: the X-Real-IP forwarded header now requires its own explicit proxy authorization, separate from generic trusted-proxy trust, closing a spoofing path under certain reverse-proxy configurations.
- Security: CRM Telegram message attribution now reads exclusively from the same dedicated links table the bot itself uses as its source of truth, instead of legacy user data that could retain an ambiguous assignment after a resolved conflict.
- Hardening: the enrollment access-password limiter and both WhatsApp phone-verification counters (code attempts and code requests) now reserve their rate-limit quota atomically before evaluating an attempt, closing a race that allowed more attempts than intended under concurrent requests.
- Security review: this closes the static-hardening audit round; all findings from an external line-by-line review of 6.5.8 are resolved, verified with concurrency-specific regression tests where applicable.

## 6.5.8 (2026-08-22)

- Security: strengthened proxy/header trust boundaries — private IP ranges are no longer trusted as reverse proxies by default, and Cloudflare's client-IP header now requires its own explicit configuration separate from generic proxy trust.
- Security: Telegram account linking now reads exclusively from its dedicated links table (not legacy user data) as the single source of truth, and re-linking to a new chat can no longer leave an account without any binding if the new chat is already taken.
- Hardening: the Telegram usermeta-to-table migration no longer assigns ambiguous historical links to an arbitrary user; conflicting entries are quarantined for manual review instead.
- Hardening: unified several remaining request counters (enrollment access codes, AI teaching assistant features, AI grading review, two-factor authentication) onto the same atomic, race-resistant limiting service, all failing safely closed if their backend is unavailable.
- Security: public form submissions and API rate limiting now fail safely closed (reject the request) instead of silently allowing unlimited traffic if their backend is unavailable.
- Security review: expanded regression coverage across CRM, LMS ownership, WhatsApp verification, MCP limits, digest locking, and all prior sprint fixes confirmed no regressions.

## 6.5.7 (2026-08-22)

- Security: legacy course/program/lesson listings now scope draft, private, and "all" status requests to the requesting instructor's own content instead of trusting a client-supplied teacher_id or course_id.
- Security: client IP resolution now correctly walks the forwarded-header chain from the trusted-proxy edge inward, closing a way to spoof the reported client IP even behind a trusted proxy.
- Hardening: the affiliate click tracker now uses the same centralized, trusted-proxy-aware IP resolution as the rest of the plugin.
- Hardening: rate-limit tables (public forms, MCP API keys, and a new shared counter used by the AI assistant) are now purged of expired entries on an hourly schedule instead of growing indefinitely.
- Security: Telegram account linking now enforces chat-uniqueness at the database level (a chat can never be linked to two accounts, even under concurrent requests), replacing the previous best-effort application-level check.
- Hardening: the AI teaching assistant's rate limit now uses an atomic, race-resistant counter instead of a read-then-write pattern, and fails closed if the counter is unavailable.

## 6.5.6 (2026-08-21)

- Security: legacy webhook management (register/list/delete outbound webhooks for lesson/course/enrollment/submission/grade/certificate events) now requires full site administration instead of a general content-management capability.
- Security: reading or editing a course's grading scheme (component weights) now requires ownership of that course instead of a general grading capability.
- Security review: full audit pass over the legacy REST API surface (courses, programs, lessons, rubrics, transcriptions, peer review, quizzes, reports, bulk actions, reorder) confirmed existing ownership checks are intact; no regressions found.

## 6.5.5 (2026-08-20)

- Security: public form submissions now resolve the client IP through a centralized, trusted-proxy-aware resolver instead of trusting forwarded headers directly, closing a way to evade or poison the per-form rate limit.
- Security: public form submissions are now validated (form exists, correct type, valid nonce) before any rate-limit counter or other persistent state is created, closing a low-cost storage-exhaustion vector.
- Hardening: the public form rate limiter now uses an atomic, race-resistant counter instead of a read-then-write pattern.
- Hardening: the message digest queue now uses a unique claim token per batch, in addition to the existing claim-based locking, removing any theoretical ambiguity between overlapping claims.
- Security: enrollment access-code/password attempts are now rate-limited per user and link, closing a brute-force gap.
- Security: a Telegram chat can no longer become linked to two different WordPress accounts.

## 6.5.4 (2026-08-20)

- Hardening: the unsubscribe link now requires an explicit confirmation click instead of acting on GET, so scanners and email previews can no longer unsubscribe a user by themselves.
- Hardening: the message digest queue is now claim-based, preventing duplicate summaries from overlapping cron runs, plus retention limits so it can't grow unbounded.
- Hardening: Telegram account linking codes now have much higher entropy and a failed-attempt lockout, matching the WhatsApp verification hardening from 6.5.1.
- Hardening: an MCP API key with the "all" scope no longer gets a higher rate limit than a plain "write" key for write operations.
- Hardening: public forms now throttle repeated submissions per IP per form.
- Hardening: native lessons and programs (without a linked legacy post) can now be created without hitting a database constraint, matching the courses fix from 6.5.3.

## 6.5.3 (2026-08-19)

- Security: `wp_post_id` (the identity bridge to the legacy LMS content) can no longer be written through the generic course create/update REST endpoints, by anyone.
- Security: the courses table now allows multiple native courses without a legacy post link, fixing a schema constraint that previously only allowed one.
- Security: the legacy-to-tables migrator no longer silently trusts an existing table row during the migration window; it now flags instructor mismatches for manual review instead of assuming the row is correct.

## 6.5.2 (2026-08-19)

- Security: instructors can no longer read, edit, view stats, enroll users into, or view the cohort of courses they don't own via the LMS REST API.
- Security: draft and private courses are no longer readable by arbitrary logged-in users, by listing or by direct ID.
- Security: removed a backward-compatibility fallback in phone verification that could have allowed unverified WhatsApp delivery for pre-6.5.1 accounts.

## 6.5.1 (2026-08-18)

- Security: CRM v2 write endpoints now require management permission instead of view-only access.
- Security: fixed a scope check that could grant instructors global visibility over the contacts database instead of their own enrolled students.
- Security: closed a gap in the CRM inbox reply endpoint that allowed sending email to an arbitrary address.
- Security: WhatsApp messages now require a verified phone number, not just consent, across every send path (router, campaigns, CRM pipeline).
- Security: changing a student's phone number now invalidates its previous verification.
- Security: added a rate limit to phone verification code attempts.

## 6.4.0 (2026-08-18)

Sprint de mensajería académica, publicado sin subir la cabecera de versión (el siguiente número publicado fue 6.5.1).

- Router de mensajería académica tras `atora_academic_routing_enabled`, en `false` por defecto (cero mensajes nuevos hasta activarlo).
- Eventos: `assignment_graded` → estudiante; `submission_received` → docente de la sección; `assignment_due_soon` con tope por destinatario; `at_risk_flagged` → docente y coordinador, nunca al estudiante.
- Concepto de coordinador de sección (`Section_Service::get_coordinator()`), todavía sin UI para asignarlo.
- `improvement_plan_assigned` omitido (PT-3.5): no existe una acción de "asignar" que enganchar.
- Catálogo de plantillas de WhatsApp en `docs/PLANTILLAS-WHATSAPP.md`. Detalle en `docs/DEUDA-TECNICA.md`.

## 6.3.0 (2026-08-18)

### Cutover + Modularidad — despliegue institucional

Actualizar desde 6.2.1 no cambia nada por defecto: todos los módulos
quedan activos (perfil `academia`) y la lectura LMS sigue en `legacy`
hasta que un administrador elija explícitamente lo contrario.

**Cutover F4 (lectura LMS desde tablas propias)**

- **NUEVO — `LMS_Parity::cutover_ready()`**: gate programático (dualwrite activo, 0 divergencias en 14 días, reconciliación diaria sin pendientes, las 4 tablas núcleo con filas), usado tanto por el panel de Migración LMS como por el endpoint AJAX del flip.
- **NUEVO — `wp atora lms cutover --status|--run|--rollback`**: comando WP-CLI para despliegues sin acceso al panel admin, reutilizando el mismo gate.
- **MEJORA — endpoint de flip/rollback**: antes solo verificaba `atora_lms_dualwrite` del lado servidor (más débil que el gate mostrado en el panel); ahora usa `cutover_ready()` completo. Cada flip/rollback queda registrado en `atora_lms_cutover_log`.
- **FIX — `LMS_Enrollment_Service::get_access_expiry_by_wp_id()`**: no filtraba por `status IN ('active','completed')` a diferencia de sus lectores hermanos; podía devolver la caducidad de una matrícula `unenrolled`.
- **NUEVO — `docs/CUTOVER-F4.md`**: procedimiento operativo para el equipo que ejecuta el cutover.

**Sistema de modularidad**

- **NUEVO — `CLMS_Module_Registry`**: registro declarativo de 19 módulos (slug, label, dependencias, páginas y shortcodes que aporta). Opción `atora_active_modules`, default = todos activos. Los módulos núcleo (`lms`, `academic`, `gradebook`, `security`) no se pueden desactivar.
- **NUEVO — gate de carga en los 3 sistemas de módulos del plugin**: `CLMS_Loader` (convención `condition => 'module:slug'`), `ATORA\V5_Modules::boot()` (guard por módulo en cada `load_*()`), y las llamadas sueltas de `atora_lms_require_module_if_active()`. Desactivar un módulo detiene su carga real — archivo, clase y hooks — no solo lo oculta.
- **NUEVO — `CLMS_Module_Guard`**: defensa en profundidad — shortcode de un módulo inactivo devuelve vacío (o aviso solo-admin), acceso directo a su página admin da `wp_die()` claro, nunca error fatal.
- **NUEVO — Admin → ATORA → Módulos**: activar/desactivar con validación de dependencias (bloquea desactivar si hay dependientes activos, activa en cascada lo requerido). Desactivar nunca borra datos.
- **NUEVO — perfiles de instalación** (`CLMS_Install_Profiles`): `academia` (todo activo, default), `institucional` (LMS académico puro, sin CRM/comercio/afiliados/newsletter/streaming), `corporativo` (institucional + CRM/automatización/email). Selección como paso 1 del onboarding; cambio posterior desde Módulos con vista previa de qué se activa/desactiva.
- **NUEVO — vocabulario institucional**: helper `atora_profile_label($key, $default)`, activo solo bajo perfil `institucional`.

**Consolidación del menú de administración**

- **FIX — 8 slugs con doble registro** (`atora-emails`, `atora-newsletter`, `atora-messaging`, `atora-automations`, `atora-webhooks`, `atora-security`, `atora-affiliates`, `atora-calendar`): scaffolding residual de cuando los módulos se cableaban directo en el hub de menú. Colapsados a un solo registro cada uno.
- **FIX — CRM mostraba dos entradas "CRM" simultáneas** en el sidebar (un registro legacy del módulo `crm` con cap `read`, coexistiendo con la entrada real `atora-crm-v2`). Consolidado a una sola.
- **FIX — Analytics ×3** (`atora-analytics`, `atora-analytics-dashboard`, `clms-analytics`): redirección 301 centralizada (`CLMS_Legacy_Slug_Redirects`) hacia la entrada real, sin 404 ni "no tienes permitido acceder".
- **NUEVO — menú reorganizado en 8 secciones**: Panel, Academia, Estudiantes, Docentes, Comunicación, Crecimiento (oculto en perfil institucional), Informes, Ajustes. Ninguna página se eliminó — las que dejaron de ser entradas de primer nivel siguen alcanzables por URL directa y desde tarjetas de navegación en su hub nuevo.
- **NUEVO — chequeos en modo `WP_DEBUG`**: slug de menú registrado más de una vez, o página huérfana (sin módulo dueño, o de un módulo inactivo que igual quedó registrada). Panel de diagnóstico visible en Ajustes.
- **NUEVO — `docs/MENU-INVENTARIO.md`**: inventario completo de las páginas admin del plugin.

**Limpieza**

- Eliminado `modules/commerce/` (directorio vacío desde hacía tiempo, sin código).
- `uninstall.php`: 16 tablas custom que faltaban en la lista de borrado opt-in (mayormente de CRM v2 y del motor de secuencias de email, desfase previo a este sprint) — de 52 a 69 tablas.
- `docs/CRM-V1-V2-PARIDAD.md`: documento de decisión para una futura evaluación de retiro de `modules/crm/` (v1) — no se retira nada en este sprint.

---

## 6.2.1 (2026-07-30)

### Blindaje ante despliegues incompletos (hosting compartido / Softaculous)

- **NUEVO — `atora_lms_require_module()`**: reemplaza los `if ( file_exists() ) { require...; init(); }` sueltos del bootstrap, activación y safety-net del menú admin. Si un archivo esperado falta en disco, se registra en vez de fallar en silencio.
- **NUEVO — Aviso en admin**: si algún módulo no cargó por faltar su archivo, se muestra un aviso visible (solo `manage_options`) listando exactamente qué falta, con link al diagnóstico.
- **MEJORA — Build probe** (`?page=clms-dashboard&atora_build_probe=1`): el manifiesto ahora incluye ~28 archivos de módulos cargados condicionalmente, no solo los 6 originales.
- **FIX**: el Onboarding Wizard (`atora-onboarding`) podía mostrar "no tienes permitido acceder a esta página" cuando `includes/onboarding/class-onboarding-wizard.php` faltaba en el servidor tras un deploy incompleto — ahora ese fallo queda visible en vez de silencioso.
- **FIX — `CLMS_Loader::boot_module_group()`**: los módulos con una condición opcional no cumplida (ej. `CLMS_WooCommerce` cuando WooCommerce no está instalado) se registraban en cada request como `[CLMS_LOADER] No se pudo iniciar: CLMS_WooCommerce`, llenando el error_log sin ser un fallo real. Ahora se omiten en silencio.

---

## 6.2.0 (2026-07-18)

### Comercialización — Licencias, actualizaciones y desinstalación limpia

- **NUEVO — Módulo de licencias** (`modules/licensing/`): activación/desactivación de clave contra el servidor de atora-lms.com, verificación semanal por cron con caché de 12h, página Admin → ATORA → Licencia con estado, plan y expiración. El plugin nunca se bloquea sin licencia; solo se condicionan las actualizaciones automáticas.
- **NUEVO — Actualizaciones self-hosted**: integración con el estándar `update_plugins_{hostname}` de WP 5.8+ usando el `Update URI: https://atora-lms.com` ya declarado. Modal "Ver detalles" con changelog remoto. Spec del servidor en `docs/LICENSING-SERVER.md`.
- **NUEVO — `uninstall.php`**: limpieza de crons y transients siempre; borrado completo de las 51 tablas custom, opciones, meta legacy y CPTs solo con opt-in explícito (`atora_lms_delete_data_on_uninstall`, toggle en la página de Licencia). Protege los datos del cliente por defecto.
- **SEGURIDAD — REST**: `GET /courses/{id}/progress` y `POST /lessons/{id}/complete` pasan de `__return_true` a `permission_callback => is_user_logged_in` (defensa en capas; los callbacks ya validaban login y matrícula internamente).
- **FIX — readme.txt**: `Stable tag` sincronizado con la versión real (estaba en 6.0.9).

---

## 6.0.9 (2026-06-16)

### LMS Migration — F3: Lectura de paridad / shadow-read

- **`LMS_Read_Router`** — punto único de resolución de la fuente de lectura (`atora_lms_read_source`: `legacy` | `tables`). En F3 siempre devuelve `legacy`; el cutover real es F4.
- **F3.1 — lectores de tabla** en `LMS_Enrollment_Service`: `get_enrolled_wp_course_ids()`, `is_enrolled_by_wp_id()`, `get_access_expiry_by_wp_id()`, `get_enrolled_wp_program_ids()`. Devuelven los mismos tipos e IDs WP que los lectores legacy.
- **F3.2 — shadow-read instrumentado** en los 4 lectores canónicos de `CLMS_Helper` (`get_user_enrolled_courses`, `user_is_enrolled_in_course`, `get_user_course_access_expiration`, `get_user_enrolled_programs`). Cada uno calcula la fuente sombra (tabla) en try/catch, la compara con la respuesta real (legacy) y, si difieren, registra una fila en `atora_lms_parity_log`. El resultado real no se altera nunca. Throttle de 5 min por (reader, user, course) vía WP object cache.
- **`LMS_Parity`** — tabla `atora_lms_parity_log` (reader, user_id, wp_course_id, digests, summaries, logged_at). Métodos: `ensure_table()`, `get_reader_stats()`, `get_daily_counts()`, `total_divergences()`, `get_recent()`.
- **F3.3 — panel de paridad** en Admin → Migración LMS: 4 cards (una por lector crítico, verde = 0 divergencias), gate D-006 (verde/rojo), tabla de últimas divergencias, botón "Exportar CSV" vía `atora_lms_parity_export`.

---

## 6.0.8 (2026-06-16)

### LMS Migration — F2: Doble escritura simétrica

- **`LMS_Write_Facade`** — fachada write-through única para todas las escrituras runtime de matrícula, progreso de lección, completación de curso, caducidad de acceso, calificación y certificado. Guarda de reentrada estática `LMS_Write_Facade::$syncing` para evitar bucles de espejo.
- **Flag `atora_lms_dualwrite`** — controla si las escrituras en tabla se reflejan en usermeta legacy. Default `false`; activable desde Admin → Migración LMS.
- **Espejo bidireccional** — dirección `legacy→tabla` vía `ATORA_LMS_Compatibility_Layer` (existente); dirección `tabla→legacy` vía métodos `mirror_*_to_legacy()` de la fachada, solo si `dualwrite = true`.
- **F2.3a — Espejo de caducidad de acceso** (`atora/lms/access_expiry_set`): listener añadido al compat layer; `LMS_Write_Facade::mirror_access_expiry_to_legacy()` escribe la meta `_clms_course_access_expiry` en formato local del sitio.
- **Agujeros F2.1 cerrados:** `ajax_unenroll_user()` dispara `clms_user_unenrolled`; `repair_enrollments()` dispara `clms_user_enrolled`.
- **Reconciliación diaria:** cron `atora_lms_reconcile_check` + opción `atora_lms_reconcile_result`; toggle dualwrite vía AJAX en panel de migración.

---

## 6.0.7 (2026-06-15)

### LMS Migration — F1: Migradores de entidades + Runner robusto

- **F1.4 — Programas:** tablas `atora_programs` y `atora_program_enrollments`; migradores `migrate_programs()` y `migrate_program_enrollments()`.
- **F1.5 — Taxonomías:** tabla pivote `atora_course_terms`; migrador `migrate_course_terms()` con upsert idempotente por `(course_id, taxonomy, term_slug)`.
- **F1.6 — Evaluaciones y calificaciones:** 4 tablas (`atora_quizzes`, `atora_quiz_submissions`, `atora_gradebook`, `atora_certificates`) y 4 migradores.
- **F1.7 — Runner con cursores de continuación:** `migrate_all()` pagina con cursores en `wp_options` (`atora_lms_migration_cursors`); cron `atora_lms_migration_cron` cada 5 min, auto-detenible; `reconcile()` con 12 checks; script `bin/run-migration.php` para WP-CLI.

---

## 6.0.0 (2026-05-18)

### Breaking Changes

- **Namespace migration:** `CLMS_Helper::get_user_enrolled_courses()` y `get_user_enrolled_programs()` migrados a `ATORA\LMS\LMS_Enrollment_Service`. Las llamadas a `CLMS_Helper::module()` se reemplazan por `clms_core()`. Si tienes customizaciones propias que usen `CLMS_Helper::`, actualiza las llamadas. Un wrapper de compatibilidad garantiza retro-compatibilidad durante la transición.
- **Menú reorganizado:** Los slugs legacy (`atora-crm`, `clms-crm-hub`, `atora-crm-comercial`, `atora-crm-academico`) ya no aparecen en el sidebar. Están registrados como páginas ocultas accesibles por URL directa. Si tienes bookmarks o links hardcodeados, actualízalos a los nuevos slugs.
- **LMS en tablas propias:** `atora_courses`, `atora_lessons`, `atora_enrollments` y `atora_lesson_progress` son ahora las fuentes primarias del LMS. El usermeta legacy permanece como fallback durante la transición. Ejecutar `POST /wp-json/atora-lms/v1/migration/run` para sincronizar.
- **PHP mínimo:** 8.1 (sin cambio desde 5.x, se documenta explícitamente).

### Nuevas Funcionalidades

#### CRM v2
- **Empresas (Companies):** CRUD completo en `atora_companies` via `Company_Service`. REST: `/atora-crm/v2/companies`.
- **Listas CRM:** `atora_crm_lists` + `atora_contact_list_pivot` para segmentación avanzada.
- **Scoring predictivo:** `Scoring_Service::calculate_score()` — puntuación 0-100 combinando engagement, progreso académico, tasa de apertura de emails y LTV. Cron diario `atora_scoring_cron`.
- **Segmentador mejorado:** Filtros por `conversion_score`, `engagement_score` y `total_points` con operadores `greater_than`, `less_than`, `equals`.
- **Ficha 360 mejorada:** Botón "Resumir contacto (IA)", score de conversión, secuencias activas y ruta de aprendizaje recomendada.

#### Email Engine 2.0
- **Secuencias drip:** Soporte para condiciones `open`/`no_open` con `atora_email_sequence_enrollments`.
- **A/B Testing:** Toggle en Campaign Builder (paso 2) — prueba 2 asuntos, ganador automático por apertura.
- **Suppression list:** Tab "Desuscriptos" en Email Engine admin. Newsletter con filtro JOIN a `atora_email_suppression`.
- **URL Tracking:** `URL_Store_Service` — tracking granular de clics por short_key. Endpoint: `/atora-crm/v2/url/click/{key}`.
- **tracking_id:** Campo en `atora_crm_campaign_recipients` para métricas de apertura/clic por destinatario.
- **Editor visual de email:** 5 bloques contenteditable con toolbar (header, texto, imagen, CTA, pie). Serializa a HTML en `email_html`.

#### Automation Engine 2.0
- **21 triggers activos:** Incluye `cart_abandoned`, `ltv_updated`, `lesson_completed_v2`, `course_completed_v2`, `enrollment_v2`, `badge_earned`, `learning_path_updated`.
- **Log de ejecuciones:** `atora_automation_execution_log` con query via MCP tool `get_automation_log`.
- **Presets corregidos:** `cart_recovery` ahora usa `trigger_type=cart_abandoned` (bug fix desde `form_submitted`).

#### LMS Propio
- **4 tablas:** `atora_courses`, `atora_lessons`, `atora_enrollments`, `atora_lesson_progress` — sin dependencia de `wp_posts`.
- **Migración aditiva:** `LMS_Migrator::migrate_all(30)` o página admin "🗄 Migración LMS" → botón "Ejecutar migración".
- **Compatibility Layer:** Sync automático entre hooks legacy (`clms_user_enrolled`) y tablas propias.
- **Object cache:** `get_curriculum()` con TTL 300s via `wp_cache_get/set` (Redis-compatible).
- **REST API LMS:** 8 endpoints bajo `/atora-lms/v1/` — cursos, lecciones, matrículas, progreso, migración.
- **Vista de cohorte:** `GET /atora-lms/v1/courses/{id}/cohort` + `modules/lms/views/cohort.php` con filtros JS.

#### MCP (Model Context Protocol)
- **16 tools** bajo `/atora/mcp/v1/tools/` — CRM, LMS, Analytics, Automatizaciones.
- **OpenAPI 3.0:** `GET /atora/mcp/v1/openapi.json` — schema generado dinámicamente.
- **Rate limiting:** 100 req/min (read), 20 req/min (write) via transients por clave.
- **API Keys:** CRUD via `ATORA_API_Key_Service` + REST `/atora-lms/v1/api-keys`.

#### Multi-academia (Multi-tenant base)
- **academy_id:** Columna `BIGINT UNSIGNED DEFAULT 0` añadida via `ensure_runtime_columns()` en 12 tablas CRM/LMS.
- **Academy_Context:** `ATORA\CRM_V2\Academy_Context::get_current_academy_id()` con prioridad: request param → usermeta → option global.

#### Gamificación visible
- **Shortcode:** `[atora_leaderboard course_id="X"]` — top 10 con avatar, puntos y barra de progreso.
- **Tabla `clms_badges`:** 12 badges base insertados al activar. `check_badge_criteria()` al final de `record_event()`.
- **Trigger `badge_earned`:** Conectado a Automation Engine.

#### Afiliados
- `export_commissions_csv(array $filters)` — CSV directo a output stream.
- `bulk_mark_paid(array $ids)` — actualización masiva + email de confirmación por afiliado.

#### Webhooks salientes
- **Tabla `atora_webhooks`:** registro de endpoints externos por evento.
- **`ATORA_Webhook_Dispatcher::dispatch()`** — firma HMAC-SHA256 en header `X-ATORA-Signature`.
- **3 hooks activos:** `order.completed`, `enrollment.created`, `certificate.issued`.

#### Analytics Dashboard
- **4 secciones Chart.js:** Email performance, Engagement, Revenue mensual, Retención de cohortes.
- **Exportar CSV** via `ajax_export()` de Analytics_Engine.
- **Menú:** "📊 Analytics" bajo ⚙️ Ajustes.

#### Onboarding Wizard (nuevo en 6.0)
- 4 pasos: academia → email → primer contacto → primera campaña.
- Se muestra solo hasta que `atora_onboarding_complete = '1'`.
- Notice en todas las páginas admin con opción "Lo haré después".

### Mejoras

- **Performance:** Object cache en `get_curriculum()` (5 min TTL). Rate limiting en API Keys.
- **UX:** Menú reorganizado por rol (6 items con cap check). Design tokens CSS globales (`--atora-blue-700`, etc.).
- **Seguridad:** Todos los AJAX handlers verificados con `check_ajax_referer()`. SQL preparado en todos los archivos de servicio. 0 vulnerabilidades críticas o altas en auditoría S18.
- **Refactoring:** `class-crm-v2.php` (1726L) dividido en `trait-crm-v2-pipeline.php` (23 métodos) + `trait-crm-v2-segmenter.php` (20 métodos).
- **Tests:** 30 tests unitarios PHPUnit con bootstrap sin WP real (Brain\Monkey).
- **Documentación:** `SECURITY-AUDIT.md`, `CHANGELOG.md`, scripts de migración.

### Fixes

- Preset `cart_recovery` trigger corregido de `form_submitted` a `cart_abandoned`.
- Hub KPI `kpi-abandoned` ahora usa `/abandoned-carts/summary` (en lugar de `?limit=1&status=active`).
- Labels de Chart.js en `reports-comercial.php` y `reports-academico.php` — eliminados `undefined` en datasets doughnut/line.
- Dataset funnel sin `label:` → añadido `label:'Etapas'` para evitar warning de Chart.js.

---

## 5.27.1 (anterior)

Ver historial de commits.
