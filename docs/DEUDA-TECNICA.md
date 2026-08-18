# Deuda técnica — Sprint 6.3.0

Documento vivo. Cada entrada anota qué se encontró, por qué no se resolvió
en este sprint, y una propuesta para cuándo se aborde.

---

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
