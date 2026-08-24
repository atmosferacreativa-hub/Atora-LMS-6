# Deuda técnica — Sprints 6.3.0 y 6.4.0

## Resumen de cierre — 6.4.0 (Mensajería académica)

22 commits, `atora_academic_routing_enabled` en `false` por defecto
(cero mensajes nuevos hasta activarlo). Puntos que quedan abiertos,
todos ya documentados en su sección correspondiente más abajo:

- **PT-3.5 omitido** — `improvement_plan_assigned` no tiene acción de
  "asignar" que enganchar; la plantilla queda catalogada para cuando
  se construya esa persistencia.
- **Coordinador de sección sin UI** — el concepto existe
  (`Section_Service::get_coordinator()`) pero asignarlo hoy requiere
  un INSERT directo en la tabla.
- **Plantilla de email del recordatorio de inactividad pendiente de
  crear** en Email Engine (`atora_student_inactive`) — las variables
  ya se pasan correctas, falta el contenido.
- **Doble cooldown de inactividad** corriendo en paralelo a propósito
  (mismo criterio que la paridad de F4 en 6.3.0) — comparar antes de
  retirar el mecanismo viejo.
- **No se pudo correr `php -l`, phpcs ni phpunit** — sin intérprete
  PHP disponible en el entorno de esta sesión. Se verificó balance de
  llaves/paréntesis en los 23 archivos PHP tocados como sustituto
  parcial; la matriz de prueba manual y la prueba de campo con
  teléfono real de la OT quedan pendientes de un entorno WordPress
  real.

## PT-3.5 (6.4.0) — `improvement_plan_assigned` omitido: no hay acción de "asignar" que enganchar

`CLMS_Improvement_Plan_Service` nunca persiste un plan — cada llamador
(SpeedGrade, dashboard del estudiante, academic-status-service, etc.)
lo recalcula al vuelo cada vez que se necesita mostrar. No hay tabla,
postmeta, ni hook de "esto se acaba de asignar". Conectar una
notificación aquí requeriría primero construir esa persistencia (una
tabla o postmeta nueva, un botón/acción admin explícita de "asignar
plan") — eso es "construir", no "conectar lo que ya existe", que es el
encuadre de este sprint. Decisión confirmada: se omite 3.5 en 6.4.0.
La plantilla de WhatsApp queda catalogada en
`docs/PLANTILLAS-WHATSAPP.md` para no perder el trabajo de diseño
cuando 6.5.0 (o un sprint posterior) construya la persistencia.

## PT-3.4 (6.4.0) — Sin UI para asignar coordinador de sección

`Section_Service::get_coordinator()`/`get_effective_coordinator()`
reutilizan `atora_section_teachers` con `role = 'coordinator'`, pero no
existe ninguna pantalla de admin para insertar esa fila — hoy requiere
un INSERT directo en la tabla. Es intencional para este sprint (el
foco es conectar la notificación, no construir gestión de roles), pero
sin una UI, en la práctica ningún sitio va a tener coordinadores
asignados. Candidato obvio para 6.5.0: un selector en la pantalla de
gestión de secciones existente, mismo patrón que ya se usa para
asignar `lead`/`assistant`/`guest`.

## PT-3.4 (6.4.0) — `at_risk_flagged` usa las alertas de IA existentes, no un evento propio

No existe un evento de "marcar en riesgo" en el código (confirmado por
auditoría: solo hay un cálculo en vivo de `'en_riesgo'`, nunca
persistido). Se usa como señal real
`clms_ai_inactivity_alert_generated`/`clms_ai_low_grade_alert_generated`
(el cron diario `CLMS_AI_Alerts`, ya existente) — son el disparador más
cercano a "un estudiante fue señalado por un problema" que hay hoy.
Esto significa que `at_risk_flagged` hereda la cadencia y los criterios
de `CLMS_AI_Alerts` (diaria, umbral de inactividad + umbral de nota
baja) en vez de ser un concepto propio — si en 6.5.0 se construye un
verdadero sistema de flagging con seguimiento de intervenciones (ver
"fuera de alcance" de la OT de 6.4.0), este puente debería
re-engancharse ahí en vez de a las alertas de IA.

## PT-2.2 (6.4.0) — Doble cooldown en el recordatorio de inactividad, a propósito

Con el enrutamiento académico activo, `CLMS_Student_Inactivity_Reminder_Service`
ahora tiene DOS mecanismos de cooldown corriendo en paralelo para el
mismo envío:

1. **`META_LAST_SENT . $course_id`** (usermeta, existía desde 6.3.0) —
   se sigue escribiendo en `run_daily_check()` sin importar qué camino
   de envío se use.
2. **`dedupe_key` del router** (`"inactive_{$student_id}_{$course_id}"`,
   `dedupe_window_minutes = cooldown_hours * 60`) — nuevo, solo aplica
   cuando `should_use_router()` es true.

**Por qué no se unificó ya:** mismo criterio que la paridad de F4 en
6.3.0 — correr ambos un ciclo y comparar antes de confiar en uno solo.
Si el router demuestra que su dedupe cubre exactamente los mismos casos
que el meta legacy (ni más falso-negativo, ni menos), retirar el
`META_LAST_SENT` en 6.5.0 y dejar solo el `dedupe_key`. Si diverge,
investigar por qué antes de tocar nada.

**Cómo verificarlo:** comparar `atora_message_queue` (filtrando por
`template = 'atora_student_inactive'`) contra el usermeta
`_clms_last_inactivity_reminder_{course_id}` de una muestra de
estudiantes, durante al menos una semana con el flag activo en un
entorno de prueba.

## PT-2.3 (6.4.0) — Plantilla de email `atora_student_inactive` pendiente de crear en Email Engine

El canal email del router (`dispatch_channel()` en
`Messaging_Router`, caso `'email'`) no usa `CLMS_Email::send()` como el
camino legacy — encola vía `\ATORA\EmailEngine\Email_Queue::enqueue()`,
que resuelve el contenido por `template_key` dentro del sistema de
plantillas de Email Engine, no por el `subject`/`body` que
`send_reminder_via_router()` calcula. Esas variables (`subject`,
`headline`, `body`, `button_text`, `button_url`, `footer_note`) SÍ se
pasan en `variables`, listas para que una plantilla de Email Engine con
`template_key = 'atora_student_inactive'` las use — pero esa plantilla
no existe todavía, es contenido a crear desde el admin de Email Engine,
no código. Sin ella, el canal de respaldo a email caerá a lo que Email
Queue haga por defecto ante una plantilla ausente (a verificar/crear
antes de activar el flag en producción).

---

# Deuda técnica — Sprint 6.3.0

Documento vivo. Cada entrada anota qué se encontró, por qué no se resolvió
en este sprint, y una propuesta para cuándo se aborde.

---

## PT-5.3 — Resumen para 6.4.0 (cierre del sprint)

Tres piezas de deuda quedan documentadas y listas para la próxima
ronda de planeación:

1. **Patrón de doble registro de menú** (PT-4.2) — resuelto en este
   sprint para los 8 slugs + calendario + CRM que se encontraron, con
   una red en `WP_DEBUG` (`CLMS_Menu_Debug_Guard`) para detectar si
   reaparece. Causa histórica documentada más abajo.
2. **Vocabulario institucional** (PT-3.4) — el helper y las claves
   están completos y probados; casi no hay superficie visible bajo
   `institucional` que lo necesite hoy, porque PT-4.4.3 ya oculta
   Crecimiento (donde vive el vocabulario comercial real) por defecto.
3. **Flag `clms_crm_v2_enabled`** — ver `docs/CRM-V1-V2-PARIDAD.md`
   §4: default `false`, sin migración automática, y `V5_Modules::
   load_crm()` carga v1 y v2 en cada request sin importar el flag. El
   hallazgo más importante de ese documento — v2 depende de v1 en
   runtime para primitivas núcleo (acceso, logging, tags) — cambia por
   completo el marco de cualquier futura decisión de retiro.

## PT-1 — Gate de cutover AJAX no compartía lógica con el panel (resuelto en este sprint)

Antes de este sprint, `ajax_toggle_read_source()` solo verificaba
`atora_lms_dualwrite`, mientras que el panel mostraba un gate más estricto
(paridad + volumen) calculado aparte en la vista. Un usuario con
`manage_options` podía forzar el flip llamando la acción AJAX directamente
aunque el panel mostrara el gate en rojo. Se corrigió centralizando el gate
en `LMS_Parity::cutover_ready()`, usado tanto por el panel como por el
endpoint AJAX y el comando WP-CLI.

## PT-1 — Bug de filtro de status en `get_access_expiry_by_wp_id()` (resuelto en este sprint)

`LMS_Enrollment_Service::get_access_expiry_by_wp_id()` no filtraba por
`status IN ('active', 'completed')`, a diferencia de sus métodos hermanos
(`is_enrolled_by_wp_id()`, `get_enrolled_wp_course_ids()`). Podía devolver
la caducidad de una fila `unenrolled` como si siguiera vigente. Corregido
para usar el mismo filtro. Ver test de regresión en
`tests/LMS/CutoverF4Test.php::test_get_access_expiry_by_wp_id_query_filters_by_status`.

---

## PT-6.3 — 17 tablas faltaban en la lista de borrado opt-in de uninstall.php (resuelto)

Verificación de `uninstall.php` contra el código real (grep de
`$wpdb->prefix . 'tabla'` y `{$wpdb->prefix}tabla` en todo `includes/`
y `modules/`, comparado contra el array `$atora_tables`): 16 tablas
reales (más una detección falsa de `usermeta`, descartada) no estaban
en la lista de `DROP TABLE`, así que un "Eliminar datos al desinstalar"
opt-in las dejaba huérfanas en la base de datos. La mayoría son de CRM
v2 (`atora_crm_campaigns`, `atora_crm_deals`, `atora_crm_tasks`, etc.)
y del motor de secuencias de email (`atora_email_sequences` y sus
tablas relacionadas) — no son nuevas de este sprint, el desfase venía
de antes. Se corrigió: el array pasó de 52 a 69 tablas. La única tabla
genuinamente nueva desde 6.2.1 (`atora_lms_parity_reads`, de F3.2 en el
sprint anterior de migración LMS) también estaba huérfana y quedó
incluida.

## PT-3.4 — Poca superficie visible bajo institucional que realmente necesite el vocabulario

Al aplicar `atora_profile_label()` en los puntos visibles (orden de
prioridad de la OT: menú → encabezados CRM/comercio → columnas de
listados → dashboard docente), se encontró que **el hallazgo de
PT-4.4.3 ya resuelve la mayor parte del problema por sí solo**: el
perfil `institucional` desactiva `crm`/`commerce`/`affiliates` por
defecto, y desde PT-4.4.3 el hub "Crecimiento" que los agrupa
desaparece por completo del sidebar cuando ninguno de los tres está
activo. La vocabulario comercial ("Contactos", "Producto", "Cliente")
vive dentro de `modules/crm/views/admin.php`, `modules/crm-v2/*` y las
vistas de comercio — exactamente los archivos que la propia OT dice no
tocar ("No tocar cadenas en vistas de módulos que el perfil
institucional desactiva — es trabajo perdido").

Se revisó si el CPT núcleo `lm_cohort` (`includes/class-cpt.php`,
siempre activo, parte de Academia) debía renombrarse a "Sección" — se
decidió que no: sus labels ya dicen "Cohorte" a secas (académico), no
"Cohorte comercial" (el término específico del ejemplo de la OT, que
parece referirse a un concepto de agrupación del lado comercial/CRM,
no al CPT académico). Renombrarlo sin evidencia de que sea el término
correcto habría sido un cambio de UI visible para TODOS los perfiles
(academia incluido) sin justificación clara — violaría la regla de
cero cambio de comportamiento por defecto.

**Lo que sí se aplicó:** las 5 claves nuevas del mapa (`enrollment`,
`program`, `section`, `teacher`, `coordinator`, PT-3.4.2) y el test de
PT-3.4.3 (`tests/Modularity/ProfileLabelsTest.php`) verificando que
`academia`/`corporativo` devuelven el default y solo `institucional`
cambia. El helper queda listo y probado para cuando alguien identifique
un string hardcodeado específico que sí valga la pena envolver.

## PT-4.4.3 — Excepciones deliberadas al conteo estricto de 8 entradas

La estructura final tiene 8 hubs de primer nivel (Panel, Academia,
Estudiantes, Docentes, Comunicación, Crecimiento, Informes, Ajustes)
más dos excepciones documentadas, no accidentales:

- **`atora-modules`** se deja visible en el sidebar además de enlazada
  desde Ajustes — es el único punto desde el que se revierte una
  desactivación de módulos (PT-4.4.4). Ocultarla y que algo fallara en
  el hub Ajustes dejaría la instalación sin salida.
- **`atora-onboarding`** y **`clms-template-parts`** no se tocaron —
  el primero es temporal (se auto-remueve al completar el wizard), el
  segundo es una utilidad no mencionada en el alcance de PT-4 (gestor
  de header/footer, sin relación con los 19 módulos).

Los hubs nuevos (Estudiantes, Docentes, Comunicación, Crecimiento,
Informes) son deliberadamente simples — grid de tarjetas
icono+título+descripción+enlace sin queries de stats en vivo
(`render_simple_hub_page()`), a diferencia de Academia/Comercio/
Ajustes que sí las tienen. Decisión confirmada por el solicitante dado
que no hay entorno WP real disponible para verificar renderizado antes
de entregar.

## PT-4.4 — `clms-email-hub` ("Marketing") no estaba en el inventario PT-4.1

Al gatear las entradas de CRM/Comercio/IA/Analytics se encontró una
quinta entrada de menú registrada directamente por
`trait-admin-menu-hubs.php` (línea ~767, "Marketing", cap
`crm_manage_campaigns`, `render_email_hub_page()`) que el inventario
PT-4.1 no capturó. No se gateó en este pase — no está claro sin leer
`render_email_hub_page()` a fondo si conceptualmente pertenece a `crm`,
`email-engine` o `automation` (probablemente combina piezas de los
tres). Queda pendiente: añadirla al inventario y decidir su slug antes
de gatearla.

## PT-4.3.2 — "Cluster de puntos de entrada" no tenía duplicación real que resolver

La OT de cierre pedía redirigir "el otro" de los 2 candidatos que el
inventario PT-4.1 corrigió (de 3 a 2). Pero el inventario ya documentó
que `clms-control-center` no es un duplicado de `clms-dashboard`: es un
dashboard de overview comercial/analítico distinto, enlazado
deliberadamente desde tarjetas de navegación y un botón — no código
muerto ni una copia. Redirigirlo habría eliminado esa funcionalidad sin
necesidad real. **Decisión (confirmada):** no se aplica ninguna
redirección aquí. `clms-dashboard` sigue siendo el único punto de
entrada de primer nivel; `clms-control-center` sigue como vista
secundaria oculta e intencional. Si en el futuro se decide fusionar su
contenido dentro del panel principal, es una decisión de producto, no
una limpieza de duplicados.

## PT-4.2 — Métodos render_atora_*_page() huérfanos en trait-admin-menu-hubs.php

Al colapsar el doble registro de `atora-emails`, `atora-newsletter`,
`atora-messaging`, `atora-automations`, `atora-webhooks`, `atora-security`
y `atora-affiliates` (7 commits `[MENU]`), los 7 métodos wrapper
`render_atora_*_page()` en `trait-admin-menu-hubs.php` (líneas ~1700-1727,
cada uno un `require_hidden_module_view()` de una línea) quedaron sin
ningún `add_submenu_page()` que los invoque. No se eliminaron en este
sprint (regla "un paquete = un commit" enfocado solo en el registro, no
en limpieza de código muerto derivado). Candidatos a retirar en 6.4.0
junto con el resto de la limpieza de menú.

## PT-4.2 — Historia del patrón de doble registro (para 5.3)

El patrón (copia oculta en el hub + auto-registro del módulo vía el
puente `atora_lms_admin_menu`) es scaffolding de cuando los módulos v5
se cableaban directo en `trait-admin-menu-hubs.php` y luego se les dio
su propio hook de auto-registro sin retirar la entrada original del
hub. `atora-calendar` es el caso más antiguo e interesante: el módulo
ya tenía protección contra el doble registro (`register_admin_menu()`
comprueba `$submenu['clms-dashboard']` antes de registrar), pero nadie
notó que esa protección lo dejaba permanentemente inerte porque el hub
siempre se registra primero — probablemente la razón por la que nadie
lo "arregló" antes: en apariencia funcionaba (la página cargaba), solo
que servida por el registro equivocado.

## PT-3 — Ambigüedad de la OT sobre automation/webhooks/mcp en el perfil institucional

La OT lista explícitamente qué módulos SÍ trae `institucional` y qué
módulos NO trae ("sin crm, commerce, affiliates, newsletter, email-engine
comercial, live-streaming"), pero no menciona `automation`, `webhooks` ni
`mcp` en ninguna de las dos listas. Se optó por excluirlos del perfil
institucional (encajan en el espíritu "sin módulos comerciales/de
crecimiento" del perfil), documentado en
`CLMS_Install_Profiles::INSTITUCIONAL_BASE`. Si esto no es lo que se
quería, es un cambio de una línea en esa constante — no requiere tocar
nada más.

## PT-3 — Onboarding: `atora_onboarding_step` guardado con la numeración vieja

Insertar el paso de perfil como paso 1 (OT 3.2) renumeró los 4 pasos
existentes a 2-5. Un admin que estuviera literalmente a mitad del wizard
en el momento exacto de actualizar a 6.3.0 vería el paso guardado
(`atora_onboarding_step`, ej. "3" = antes "Primer contacto") apuntar
ahora a un paso distinto ("Email"). Es una ventana de impacto muy
acotada (onboarding sin completar + actualización de versión simultánea)
y no se migró la option por no justificar la complejidad frente al
riesgo real. Si aparece como problema real, la migración es trivial:
sumar 1 a `atora_onboarding_step` una sola vez en el upgrade path si
`atora_onboarding_complete !== '1'`.

## PT-2 — Slugs con implementación duplicada (decisión de scope, no deuda a resolver)

Cinco slugs del registro de módulos (`security`, `messaging`, `analytics`,
`commerce`, `gamification`) tienen dos implementaciones separadas en el
código: una antigua bajo `includes/` (system A del loader, plomería
básica que ya existía antes de los módulos v5) y otra bajo `modules/`
(el módulo "real" que el toggle de PT-2 apaga). Ejemplos: `CLMS_Messaging`
(mensajería in-app básica) vs. `ATORA\Messaging\Messaging_Router`
(integración WhatsApp/Telegram); `CLMS_Analytics` vs.
`ATORA\Analytics\Analytics_Engine`.

**Decisión (confirmada con el solicitante del sprint):** el toggle de
PT-2 gatea únicamente el lado `modules/`. El lado `includes/` queda
siempre activo — es tratado como plomería núcleo, no como "el módulo"
que un perfil institucional/corporativo elegiría apagar. `security` ya
es `core` en el registro así que esto es irrelevante para ese slug en
particular; para los otros cuatro, la descripción de cada módulo en
`CLMS_Module_Registry::get_modules()` deja esto explícito.

**Por qué queda anotado igual:** si en el futuro se decide que ambos
lados deberían fusionarse (p.ej. que `CLMS_Analytics` desaparezca y todo
viva en `modules/analytics`), ese es un trabajo de unificación de
arquitectura fuera de alcance de este sprint — no un bug a corregir.

---

## PT-2 — Tres sistemas de carga de módulos independientes

El loader documentado (`trait-loader-module-groups.php` +
`module_condition_passes()`) solo gobierna los 84 módulos bajo `includes/`
(grupos `core`/`experience`/`integrations`/`ai`). Los módulos comerciales
que un "modo institucional" necesita poder apagar — `affiliates`, `crm`,
`crm-v2`, `newsletter`, `security`, `licensing`, `calendar`,
`live-streaming`, `analytics`, `messaging`, `automation` — se cargan por un
segundo sistema completamente distinto, `ATORA\V5_Modules::boot()`
(`modules/class-v5-modules.php`), un orquestador escrito a mano con checks
de contexto (`is_admin`/`is_ajax`/`is_cron`/...) por módulo, sin
descriptor de dependencias ni condición reutilizable. Existe además un
tercer patrón: llamadas sueltas a `atora_lms_require_module()` en
`atora_lms.php` (LMS núcleo, webhooks, MCP, gamification, onboarding).

**Por qué no se resolvió de raíz:** unificar los tres sistemas en un solo
loader data-driven es un cambio de arquitectura, no una extensión — fuera
del alcance "cero cambios de comportamiento por defecto" de este sprint.

**Propuesta:** el registro de módulos de PT-2 debe interceptar los tres
puntos de entrada (condición en `module_condition_passes()`, guard al
inicio de cada `V5_Modules::load_*()`, guard en cada
`atora_lms_require_module()` suelto) en vez de asumir un único choke
point. Una unificación real de los tres sistemas queda para una versión
futura, cuando se pueda dedicar un sprint completo a esa refactorización.

---

## Nomenclatura `clms_` / `atora_`

4.779 ocurrencias de `clms_` contra 2.172 de `atora_`; 140 clases `CLMS_`
contra 87 con namespace `ATORA\`. Ambos prefijos conviven desde la
migración de marca y se usan indistintamente según la antigüedad del
archivo.

**Por qué no se unifica ahora:** riesgo alto (miles de referencias en
hooks, opciones de BD, meta keys — algunas seguramente usadas por
integraciones externas o SQL directo) frente a beneficio bajo comparado
con el resto de este sprint.

**Propuesta para una versión futura:**
1. Capa de compatibilidad de hooks: `add_action('atora_x', fn(...$a) => do_action('clms_x', ...$a))` en ambas direcciones para los hooks públicos documentados, sin tocar los emisores originales.
2. Alias de clase (`class_alias('CLMS_Foo', 'ATORA\Foo')`) para las clases con mayor superficie pública, permitiendo que código externo migre a su ritmo.
3. Nunca renombrar meta keys/opciones de BD en un solo paso — requeriría migración de datos, no solo de código.
4. Congelar el prefijo nuevo (`atora_`) para todo código nuevo desde ya (ya es la convención de facto en los módulos recientes), sin tocar el código existente.

---

## `modules/commerce/` vacío

Confirmado (PT-5.1, auditoría inicial del sprint): el directorio solo
contiene `ABANDONED-CART-TEST-RESULTS.md`, sin código. El Commerce real
vive en `includes/commerce/`. Pendiente de resolver en el paquete PT-5 de
este mismo sprint (mover el `.md` a `docs/` y eliminar el directorio).

## `modules/crm/` vs `modules/crm-v2/`

No se toca en este sprint (fuera de alcance explícito, salvo el
inventario de paridad). Ver `docs/CRM-V1-V2-PARIDAD.md`.

## Catálogo público vs curriculum completo (LMS de tablas)

PT-2.4 (6.5.2): la auditoría sugirió que un curso `published` podría
mostrar solo datos de catálogo (título, descripción, precio) a
cualquiera, y el curriculum completo solo a matriculados —
`GET /courses/{id}` hoy devuelve ambos juntos sin distinción, para
cualquier usuario logueado.

**Por qué no se implementa ahora:** verificado — no existe ese
contrato en ningún lado del sistema hoy. Lo único parecido es
`is_free_preview` por lección individual
(`LMS_Course_Service::sanitize_lesson_data()`), que es un flag de
"esta lección puntual es de muestra", no una regla sistemática de
"todo el curriculum es privado salvo matrícula". Inventar la
distinción ahora cambiaría el contrato de la API pública del catálogo
sin el ciclo de prueba que eso merece — fuera de alcance de un sprint
de seguridad.

**Propuesta para 6.6.0:** decidir si `GET /courses/{id}` debe separar
`course` (catálogo, siempre visible si `published`) de `curriculum`
(completo solo si hay matrícula activa o `is_free_preview` por
lección), y si el LMS legado de posts ya resuelve esto de otra forma
que debería alinearse en vez de duplicarse.

## Rama de compatibilidad de verificación de teléfono — retirada (6.5.2)

PT-4.1 (6.5.1) introdujo `atora_phone_verified_hash` y, para no
romper cuentas verificadas bajo el esquema anterior, trataba
`verified=1` con hash vacío como verificado igual (retrocompatible).

PT-3 (6.5.2): confirmado con el responsable del proyecto que ninguna
instalación real llegó a operar bajo el esquema anterior a 6.5.1 —
no había nadie a quien esa rama estuviera protegiendo. Se retiró
directamente en `Preferences::is_phone_verified()`; `verified=1` sin
huella ahora se trata como no verificado, sin excepción. No queda
fecha de retiro pendiente porque no se dejó nada por retirar después
— esta entrada es solo el registro de que existió y por qué se fue.

## `wp_post_id` de lecciones — mismo patrón de PT-1 (6.5.3), sin exposición REST hoy

PT-1 (6.5.3) blindó `atora_courses.wp_post_id` porque
`create_course()`/`update_course()` lo aceptaban del payload sin
filtrar. `LMS_Course_Service::upsert_lesson()`
(`sanitize_lesson_data()`) tiene exactamente el mismo patrón para
`atora_lessons.wp_post_id` — lo toma de `$data['wp_post_id']` sin
validar contra el post real.

**Por qué no se toca en este sprint:** verificado — a diferencia de
cursos, no hay ninguna ruta REST que exponga `upsert_lesson()`
directamente (`class-lms-rest-controller.php` no tiene un endpoint de
creación/edición de lecciones, solo `/lessons/{id}/complete`). El
método solo se llama desde el migrador hoy. Sin superficie de ataque
actual, no es parte del hallazgo que ordena este sprint.

**Propuesta:** si en algún momento se expone un CRUD de lecciones por
REST, aplicar el mismo tratamiento — filtrar `wp_post_id` del payload
y crear el equivalente de `link_to_legacy_post()` para lecciones antes
de exponer la ruta, no después.

## `wp_post_id NOT NULL DEFAULT 0` en `atora_quiz_submissions` — a propósito, no es el mismo caso que cursos/lecciones/programas

PT-2 (6.5.3) migró `atora_courses.wp_post_id`; PT-6 (6.5.4) hizo lo
mismo para `atora_lessons` y `atora_programs` — las tres comparten
`V5_Installer::migrate_wp_post_id_nullable_columns()`. **Resuelto**,
ya no aplica la nota anterior de esta entrada para esas tres tablas.

`atora_quiz_submissions` (línea ~932) sigue con
`wp_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0` — verificado (PT-6.3,
6.5.4) que es intencional, no un descuido: el propio comentario de la
tabla dice "migra CPT clms_submission", y hoy no existe ningún punto
de escritura nativa de esa tabla — solo `LMS_Migrator` la escribe,
siempre con un `wp_post_id` real tomado del CPT que migra. A
diferencia de cursos/lecciones/programas, una entrega de examen no
tiene un concepto de "creación nativa sin CPT asociado" en el sistema
actual — el campo cumple una función de vínculo obligatorio, no
opcional.

**Si esto cambia:** si en algún momento se agrega un flujo de envío
de examen 100% nativo (sin pasar por el CPT `clms_submission`), ahí sí
aplicaría la misma migración
(`ALTER TABLE ... MODIFY COLUMN wp_post_id BIGINT UNSIGNED NULL DEFAULT NULL`
+ `UPDATE ... SET wp_post_id = NULL WHERE wp_post_id = 0`), siguiendo
el mismo patrón de `V5_Installer::migrate_column_nullable()`.

## `LMS_Migrator::migrate_programs()` — mismo patrón de "confiar en que la fila ya existe" que PT-3.1 (6.5.3)

PT-3.1 (6.5.3) corrigió `migrate_courses()` para que, al encontrar un
`wp_post_id` ya presente en `atora_courses`, compare el
`instructor_id` de esa fila contra el autor/instructor real del CPT y
registre la discrepancia en vez de confiar ciegamente. `migrate_programs()`
(línea ~428) tiene el mismo `LEFT JOIN ... WHERE pg.id IS NULL` +
mismo tipo de doble-chequeo por existencia, sin la verificación
nueva.

**Por qué no se corrige ahora:** el diagnóstico verificado de este
sprint (auditoría) habla específicamente de "cursos" — `atora_courses`
/ `lm_course`. Programas comparten el patrón pero no son parte del
hallazgo confirmado.

**Propuesta:** si `atora_programs` queda expuesto a escritura por REST
con el mismo nivel de exposición que `atora_courses` (verificar si ya
lo está antes de asumir que no), aplicar el mismo tratamiento de
PT-1/PT-3.1 (6.5.3): filtrar `wp_post_id` del CRUD genérico, un
`link_to_legacy_post()` equivalente, y la verificación de discrepancia
de instructor en el migrador de programas.

## Contador de intentos duplicado — WhatsApp (`Preferences`) y Telegram (`Telegram_Bot`)

PT-3.2 (6.5.4): el tope de intentos fallidos de vinculación de
Telegram (`Telegram_Bot::link_attempts_locked()` /
`register_link_attempt_failure()` / `reset_link_attempts()`) es una
implementación paralela a `Preferences::verify_phone_code()` (PT-5,
6.5.1) — mismo propósito (limitar fuerza bruta sobre un código de un
solo uso), lógica casi idéntica, dos lugares distintos.

**Por qué no se extrajo a un helper compartido ahora:** evaluado
(regla 3.2 de la OT) — la semántica de "contra quién se cuenta" es
distinta. WhatsApp conoce el usuario objetivo desde el inicio
(`verify_phone_code( $user_id, $code )`) y cuenta intentos contra ese
usuario. Telegram no sabe a qué chat_id apunta un código hasta
resolverlo, así que el contador queda atado al usuario de WP que está
probando códigos (`get_current_user_id()`), no a un código puntual.
Generalizar ambos casos en un solo helper implicaba tocar
`Preferences::verify_phone_code()`, ya probado y en producción desde
6.5.1 — riesgo de regresión que no valía la pena en un sprint de
hardening de menor riesgo (la propia OT lo encuadra así).

**Propuesta:** si aparece un tercer canal con el mismo patrón, ahí sí
vale la pena extraer `Verification_Attempt_Guard` (o nombre similar)
aceptando explícitamente la clave de conteo (user_id objetivo O
user_id actuante) como parámetro, y migrar los tres a la vez con su
propia suite de regresión.

## `atora_telegram_chat_id` en usermeta — candidata a eliminación futura

PT-3.3 (6.5.9): `usermeta` dejó de ser fuente de lectura para
cualquier decisión del sistema — CRM (`trait-crm-events-messaging.php`,
`trait-crm-v2-pipeline.php`, `class-crm-v2.php`) migró a
`Telegram_Bot::get_user_by_chat()`/`get_chat_id_for_user()`, que
consultan `atora_telegram_links` (la fuente de verdad real, con
`UNIQUE KEY` sobre `chat_id`). `Telegram_Bot::link_account()` sigue
escribiendo `atora_telegram_chat_id` en paralelo, únicamente por
compatibilidad hacia atrás, por si algo externo al árbol de este
plugin todavía la lee.

**Por qué no se borra ahora:** la OT de este sprint (PT-3.3) es
explícita: no eliminar la escritura este sprint. Confirmado por grep
exhaustivo (`grep -rn "atora_telegram_chat_id" --include="*.php" .`)
que, fuera de esta escritura de compatibilidad y del test que la
cubre, el único otro consumidor es el migrador histórico
(`modules/class-v5-installer.php`), que la lee como *origen* de la
migración hacia la tabla — no una lectura de decisión del sistema.

**Propuesta:** una vez confirmado (auditoría externa o telemetría) que
ningún integrador/tema/plugin de terceros lee este meta directamente,
eliminar la línea `update_user_meta(...)` en
`Telegram_Bot::link_account()` y, opcionalmente, una migración de
limpieza que borre el meta de `wp_usermeta` para todos los usuarios.

## `recurrence_rule` — formato propio creado en 6.6.0, sin parser previo que reutilizar

PT-2 (6.6.0): la OT pedía reutilizar "el mismo formato que
`modules/calendar` ya usa" para `recurrence_rule` — pero un grep
exhaustivo del árbol confirmó que ningún código existente en
`modules/calendar/` jamás expande/interpreta esa columna; es
write-only (solo `sanitize_text_field()` al guardar). No había nada
que reutilizar. Se creó `Followup_Recurrence::expand()`
(`modules/crm-v2/services/class-followup-recurrence.php`) con un
subconjunto pequeño, con sabor RRULE (ya que el nombre del campo evoca
RFC 5545): `WEEKLY;BYDAY=...`, `DAILY;INTERVAL=N`, y dos extensiones
propias de Atora sin equivalente en RRULE real —
`INTENSIFY_DAYS=N` (agrega ocurrencias extra los últimos N días antes
de una fecha de referencia, para la plantilla "Antes del cierre") y
`FIXED;DATES=...` (fechas fijas sin patrón, para "Solo hitos").

**Por qué no se generalizó a RRULE completo:** hubiera sido
sobre-ingeniería para las necesidades actuales (4 plantillas, todas
cubiertas por el subconjunto) y el propio `modules/calendar` no tiene
ningún consumidor esperando un RRULE completo — no hay nada con qué
ser compatible todavía.

**Propuesta:** si un sprint futuro necesita expandir `recurrence_rule`
para el calendario base (eventos manuales recurrentes, no solo planes
de seguimiento), evaluar en ese momento si migrar
`Followup_Recurrence::expand()` a una librería RRULE estándar vale la
pena, o si el subconjunto actual basta y solo se documenta como el
formato oficial del campo.

## `INTENSIFY_DAYS` — heurística simplificada para "Antes del cierre"

PT-3.1 (6.6.0): la plantilla "Antes del cierre" pide intensificar la
frecuencia cerca de `end_date`. La implementación actual
(`INTENSIFY_DAYS=N` en `Followup_Recurrence::expand_weekly()`) agrega
ocurrencias fijas los jueves dentro de los últimos N días antes de la
fecha de referencia, en vez de un algoritmo de intensificación
configurable (p. ej. día intensificado elegible por el docente, o
frecuencia creciente por escalones).

**Por qué no se generalizó ahora:** la OT no especifica el
comportamiento exacto de "intensificar", solo que debe existir la
opción; el criterio más simple que cumple el objetivo (agregar más
contacto cerca del cierre) se implementó y se documentó en el
docblock del método. Nada indica que el docente necesite elegir el
día intensificado en la primera versión.

**Propuesta:** si retroalimentación real de campo pide otro día u otro
patrón de intensificación, ajustar `expand_weekly()` o exponer el día
intensificado como una opción más del asistente (paso 3), sin cambiar
el formato de `recurrence_rule` en sí (`INTENSIFY_DAYS=N` puede
convivir con un parámetro adicional si hace falta).

## Sin afordancia de UI para "ver estudiantes actualmente excluidos" de una ocurrencia

PT-4.7 (6.6.0): excluir un estudiante puntual de una ocurrencia
(`Followup_Plan_Service::exclude_student_from_occurrence()`) funciona
y persiste correctamente, pero el panel lateral (PT-4.4) no muestra
una lista separada de "estudiantes excluidos de esta ocurrencia" —
un estudiante excluido simplemente deja de aparecer en la lista de
destinatarios, sin forma visual de revertir la exclusión desde la UI
actual (solo es reversible editando la fila de
`atora_followup_occurrence_state` directamente).

**Por qué no se construyó ahora:** no estaba en el alcance explícito
de PT-4.7 (que solo pide poder excluir, no pide una UI de
"deshacer"), y agregarla hubiera significado un quinto estado visual
en un panel que el §UX exige mantener simple ("el docente no
configura, elige").

**Propuesta:** si el uso real muestra que los docentes excluyen por
error y necesitan revertir, agregar una sección colapsable "N
estudiantes excluidos de este día" al panel lateral, con un botón de
"volver a incluir" por estudiante — reutilizando
`get_excluded_students()`, que ya existe y ya se usa para el filtro.
