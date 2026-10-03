# FEATURE-REPORT-6.11.0.md

## ATORA LMS 6.11.0 — Contacts-core, ficha de estudiante, vista de coordinador

**Rama:** `feature/6.11.0-contacts-core-coordinador`, base `feature/6.10.0-insignias-estudiante` (6.10.0).
**Fecha:** 2026-08-25.

---

## 1. Resumen

Sprint derivado de una auditoría de deuda técnica que señaló tres consecuencias
concretas de que el sprint 6.5.0 (extracción de contacts-core) nunca se ejecutara:
(a) `modules/crm/` seguía siendo la única implementación real de las primitivas de
contacto, con crm-v2 y ~15 archivos más dependiendo de ella directamente; (b) no
existía ninguna ficha de estudiante con línea de tiempo de intervenciones; (c) la
vista de coordinador no tenía datos reales debajo, y ni siquiera existía una forma de
asignar un coordinador salvo un INSERT manual en la base de datos. El usuario decidió
resolver las tres cosas en un único sprint, con conocimiento explícito de que no hay
entorno PHP en vivo en esta sesión para probarlo.

## 2. Paquetes entregados

| Paquete | Contenido | Alcance |
|---|---|---|
| PT-1 | `CLMS_Contacts_Core_Service` — extracción de 7 primitivas de `\ATORA\CRM\CRM` (v1) por delegación | Sin tocar ningún call site existente |
| PT-2 | Ficha de estudiante con timeline de intervenciones + registro real de alertas "en riesgo" | Nuevo |
| PT-3 | Asignación de coordinador de sección + `collect_coordinator_items()` en "Hoy" | Nuevo |
| Tests | `tests/6.11.0/run_static_checks.py` (11 checks) | — |

## 3. Decisiones de diseño registradas

### 3.1 Extracción por delegación, no por reescritura de consumidores

En vez de mover el código de las primitivas y reescribir los ~40 call sites
existentes de `CRM::` (24 en crm-v2, 15+ fuera de ambos CRMs — frontend profile,
enrollment-manager, metabox-program, automation, email-engine, messaging,
forms-builder, admin-menu, ui-search), la implementación real se movió a
`includes/contacts-core/class-contacts-core-service.php`
(`CLMS_Contacts_Core_Service`, registrada en el grupo `'core'` del loader, sin
condicionamiento a ningún módulo) y los métodos estáticos de
`modules/crm/trait-crm-access.php`/`trait-crm-contacts.php` se convirtieron en
delegados de una línea. Esto cumple el objetivo original ("que academic no se
enganche a `modules/crm/`") sin arriesgar ninguno de los call sites existentes —
todos siguen funcionando exactamente igual.

**Primitivas migradas (7):** `can_access_crm`, `can_manage_crm`,
`has_global_contact_scope`, `get_accessible_contact_user_ids`, `get_contact`,
`log_activity`, `get_timeline`.

**`upsert_contact` deliberadamente NO se migró** — a diferencia de lo planificado,
la investigación de código encontró que su resolución de contacto por
teléfono/WhatsApp (`find_contact_id_by_phone`/`normalize_contact_phone`, en
`trait-crm-events-messaging.php`) está entrelazada con el pipeline de ingestión de
mensajes entrantes de v1; migrarla habría significado arrastrar esa maquinaria
completa por una sola primitiva que este sprint no necesitaba de verdad.
`log_activity()` sí necesita crear un contacto cuando no existe — para eso usa
`CLMS_Contacts_Core_Service::ensure_contact_for_user()`, una versión deliberadamente
más simple (sin matching por teléfono, sin sync de user_meta) que basta para ese
único uso interno.

`add_tag`/`remove_tag`/`get_tags` (usados solo por automation/forms-builder, sin
relación con lo que este sprint construye) y
`sync_email_queue_to_conversation`/`sync_message_queue_to_conversation` (plumbing de
colas de email/mensajería, no primitivas de "contacto") se dejaron en v1 sin tocar.

Un detalle preservado deliberadamente al mover `get_accessible_contact_user_ids()`:
el guard original `class_exists('\CLMS_Helper') && method_exists('\CLMS_Helper',
'module')` antes de resolver `$messaging` vía `clms_core()` no tiene una relación
obvia con esa llamada, pero se copió tal cual (no se "corrigió") para no introducir
una diferencia de comportamiento no pedida por este sprint.

Las tablas (`atora_contacts`, `atora_contact_activities`, `atora_contact_notes`) se
crean de forma incondicional en `modules/class-v5-installer.php::install()` — no
dependen de que el módulo `crm` esté activo — así que el servicio neutro puede
usarlas siempre, sin migración de esquema nueva.

### 3.2 Ficha de estudiante y timeline de intervenciones

- `CLMS_Contacts_Core_Service::get_timeline_for_student()` / `log_academic_note()` —
  conveniencias nuevas sobre las primitivas migradas.
- `CLMS_Academic_Messaging_Bridge::on_at_risk_signal()` (ya existía desde 6.4.0, se
  dispara en las alertas diarias de IA) ahora también registra una entrada
  `academic_at_risk_alert` en el timeline — antes "en riesgo" era puramente efímero
  (cálculo en vivo, sin persistencia; `docs/DEUDA-TECNICA.md` lo señalaba
  explícitamente). El registro respeta el mismo guard de "cero cambio de
  comportamiento hasta que se active `Messaging_Router::is_academic_routing_enabled()`"
  que ya regía el resto de ese puente desde 6.4.0 — no se cambió esa regla.
- Página nueva `admin.php?page=atora-student-profile` (oculta, sin entrada de menú
  visible): resumen académico del curso puntual + timeline completo + formulario
  para nota manual. Gate de capacidad: mismo criterio corregido en 6.9.1 para
  `get_rubrics()` — `edit_others_lm_courses`/`manage_options`, o el usuario es el
  docente/coordinador efectivo de ESE estudiante en ESE curso
  (`Section_Service::get_effective_instructor()`/`get_effective_coordinator()`).
- El link "Ver ficha" desde el hub de estudiantes que planeaba el diseño original
  **no se construyó**: al investigar el código se confirmó que `atora-students-hub`
  es un hub de enlaces (como `clms-academic-content`), no una tabla de estudiantes —
  no hay ninguna fila por estudiante a la que agregarle una row action. En cambio, se
  corrigió el enlace `button_url` que la notificación de "en riesgo" ya construía
  (antes apuntaba a `clms-academic-content&student_id=X`, un parámetro que esa
  página nunca leyó — enlace muerto en la práctica) para que apunte a la ficha real.
  Un punto de entrada de navegación regular queda pendiente (ver §7).

### 3.3 Vista de coordinador con datos reales

- `Section_Service::get_allowed_teacher_roles()` ganó `'coordinator'` — antes
  `add_teacher()` degradaba en silencio cualquier rol fuera de
  `lead|assistant|guest` a `lead`, así que ni siquiera había un método utilizable
  para asignarlo (confirmado en la investigación, no solo "falta UI").
- `Section_Service::set_coordinator()` (nuevo): garantiza un único coordinador por
  sección (borra cualquier fila `coordinator` existente antes de insertar la nueva),
  coherente con que `get_coordinator()` usa `LIMIT 1` sin `ORDER BY`.
- UI de asignación: un formulario nuevo y aditivo
  (`admin.php?page=atora-section-coordinator`), enlazado desde una columna nueva en
  la tabla de secciones ya existente del metabox de cohorte. Deliberadamente **no**
  se tocó el guardado del metabox de cohorte en sí (guarda profesores como postmeta
  a nivel de cohorte, no fila por fila en `atora_section_teachers`) — mezclar ambos
  flujos hubiera arriesgado ese código existente sin necesidad.
- `CLMS_Today_Aggregator_Service::collect_coordinator_items()` (nuevo), mismo shape
  confirmado de `collect_academic_digest_items()`. **Fuente de datos: no se
  recalculan en vivo los estados académicos de cada estudiante del roster** — eso
  hubiera significado loopear `get_student_course_status()` (costoso) por cada
  estudiante de cada sección en cada carga de "Hoy", contra el propio principio de
  "sin caché" del agregador. En cambio, cuenta cuántas entradas
  `academic_at_risk_alert` (§3.2) se registraron en los últimos 7 días para
  estudiantes de las secciones que el usuario coordina —
  `CLMS_Contacts_Core_Service::count_recent_activity_for_users()`, una sola consulta
  con JOIN, no N+1. Esto es directamente lo que la extracción de la pieza A hace
  posible.
- **Explícitamente deferido, no construido en este sprint**: promedio agregado por
  sección, conteo de entregas pendientes por sección — requerirían agregación nueva
  que no existe ni de forma barata hoy.

## 4. Verificación

Sin intérprete PHP en este entorno (consistente con todos los sprints previos):
balance de llaves/paréntesis en los 13 archivos tocados/nuevos, y
`tests/6.11.0/run_static_checks.py` (11 checks), 11/11 PASS. Verificado significativo
contra un worktree del tip pre-sprint (`feature/6.10.0-insignias-estudiante`): el
check 01 falla con `FileNotFoundError` porque `class-contacts-core-service.php` no
existe ahí — confirma que los checks realmente distinguen el estado antes/después.

Durante la escritura del test se detectó y corrigió un falso positivo propio: el
comentario que documentaba por qué el nuevo registro del loader no tiene
`condition` contenía la palabra literal `'condition'` entre comillas, lo que
disparaba el propio check que busca esa clave. Se corrigió reescribiendo el
comentario para no usar el nombre literal de la clave (mismo patrón de falso
positivo ya visto en 6.9.1 y 6.10.0).

### Matriz manual (no ejecutada — sin entorno WordPress en vivo)

| Escenario | Resultado esperado por rastreo de código |
|---|---|
| Cualquier call site existente de `CRM::` (crm-v2, frontend profile, automation, etc.) | Sigue funcionando idéntico — delegación transparente, sin cambio de firma ni de comportamiento. |
| Cron de alertas de IA con routing académico desactivado (default) | No se registra nada en el timeline — mismo guard que ya regía el resto del puente. |
| Cron de alertas de IA con routing académico activado | Se persiste `academic_at_risk_alert` en el timeline del estudiante, con o sin destinatarios de notificación. |
| Admin visita la ficha de un estudiante sin ser su docente/coordinador ni tener `edit_others_lm_courses` | `wp_die()` — no ve nada. |
| Coordinador visita "Hoy" sin tener ninguna sección asignada | `user_has_coordinator_access()` es `false` — no se agrega ningún ítem, sin error. |
| Admin asigna un coordinador ya asignado a otra sección | Se borra su fila `coordinator` anterior en la sección vieja solo si vuelve a asignarlo ahí explícitamente — `set_coordinator()` opera por sección, no globalmente; puede ser coordinador de varias secciones a la vez. |

## 5. Regresión de seguridad

- Ningún endpoint REST nuevo. Las dos páginas admin nuevas son formularios POST con
  nonce (`wp_nonce_field`/`check_admin_referer`), gateadas por capacidad antes de
  cualquier lectura o escritura.
- La ficha de estudiante reutiliza el criterio de alcance corregido en 6.9.1
  (`edit_others_lm_courses`/`manage_options`, no `clms_access_admin` a secas) — no
  reintroduce el bug que ese fix cerró.
- La extracción de contacts-core no cambia ningún criterio de autorización existente
  — es una copia fiel de la lógica (incluye el criterio PT-1.3 de 6.5.1 sobre qué
  capacidades otorgan alcance global de contactos, sin modificarlo).

## 6. Compatibilidad hacia atrás

- Ningún cambio de esquema de base de datos — todo se apoya en tablas ya existentes.
- Ningún call site existente de `CRM::` fue tocado.
- `Section_Service::add_teacher()` gana un rol permitido más (`coordinator`) —
  aditivo, no cambia el comportamiento para `lead`/`assistant`/`guest`.
- El `button_url` de la notificación "en riesgo" cambia de destino (antes apuntaba a
  un enlace muerto) — comportamiento estrictamente mejor, no un cambio disruptivo.

## 7. Pendiente / fuera de este cierre

- **Verificación visual con datos reales** — ninguna de las dos páginas nuevas ni el
  nuevo ítem de "Hoy" se probaron en un navegador. Recomendado antes de producción,
  mismo disclosure que 6.10.0.
- **Punto de entrada de navegación regular a la ficha de estudiante** — hoy solo se
  llega desde el link de la notificación "en riesgo" o por URL directa; no hay un
  listado de estudiantes desde el que navegar. Candidato para un sprint futuro si se
  usa activamente.
- **Promedio agregado / entregas pendientes por sección** en la vista de coordinador
  — deferido explícitamente (§3.3), requeriría agregación nueva.
- **`set_coordinator()`/`remove_teacher()` para quitar coordinador** puede clobberar
  otro rol que el mismo usuario tuviera en esa sección (p. ej. `lead`) — limitación
  del esquema existente (una fila = un rol por usuario por sección), no introducida
  por este sprint, mencionada por transparencia.
- Del inventario de deudas original: reportes cruzados académico/comercial, barrido
  más amplio de estilos hardcodeados, unificación `Task_Service`/planes de
  seguimiento — ninguno tocado, fuera del alcance acordado para este sprint.

## 8. Empaquetado

- **Build**: `./scripts/build-dist.sh 6.11.0` → `dist/atora-lms-6.11.0.zip`.
- **Auditoría del ZIP extraído**: sin archivos de desarrollo en la raíz (sin
  `tests/`, `.git`, `dist/`, `docs/`, `scripts/`, ni `FEATURE-REPORT-*.md`);
  `Version:`/`ATORA_LMS_VERSION`/`Stable tag:` consistentes en `6.11.0`;
  `includes/contacts-core/class-contacts-core-service.php` y los dos traits nuevos de
  `admin-menu/` presentes.
- **Escaneo de secretos**: sin llaves AWS/Stripe, sin bloques PEM; el único match de
  `password=` es el mismo falso positivo preexistente ya documentado en sprints
  anteriores (concatenación de querystring en JS del flujo de inscripción).
- **SHA-256**: `53dedc8701754218e0efe017ab28ccd464ba9893b090ebbc96966fee13c3d1e5`
