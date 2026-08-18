# Inventario del menú de administración — PT-4

> Entregable de PT-4.1. **No se ha tocado ningún código de menú todavía** —
> este documento es el punto de parada explícito que pide la OT antes de
> avanzar con la consolidación (PT-4.2 en adelante).

## Metodología

Se buscó cada llamada a `add_menu_page()` y `add_submenu_page()` en todo
`includes/`, `modules/` y `atora_lms.php` — no solo en
`includes/admin-menu/`, porque varios módulos registran sus propias
páginas vía el hook puente `do_action( 'atora_lms_admin_menu' )`
(disparado en `trait-admin-menu-hubs.php:906`). Para cada registro se
verificó: el archivo/clase que lo registra, el callback que renderiza,
el slug padre, y si aparece en la lista de `remove_submenu_page()` de
`cleanup_atora_submenus()` (hubs.php:912-937) — que oculta una página del
sidebar sin eliminar su registro.

## Corrección al conteo de "58"

El número real depende de si el feature flag `clms_crm_v2_enabled` está
activo (por defecto está en `false`):

| Estado | Llamadas a add_*_page() | Slugs únicos |
|---|---|---|
| CRM v2 desactivado (default) | 54 | **43** |
| CRM v2 activado | 66 | **55** |

El "58" citado en la OT probablemente contaba en un punto intermedio.
Se reporta el rango real en vez de un número único, porque es
configuration-dependent.

**8 de los slugs "duplicados" no son los 3 clusters ya conocidos** — es
un patrón sistémico (ver sección propia) que afecta a 8 módulos más.

---

## Cluster 1 — Analytics ×3 (confirmado)

| Slug | Archivo:línea | Clase | Renderiza | Padre | Estado | Módulo |
|---|---|---|---|---|---|---|
| `atora-analytics-dashboard` | `includes/admin-menu/trait-admin-menu-hubs.php:820-827` | `CLMS_Admin_Menu_Hubs_Trait::register_admin_pages()` | `render_analytics_dashboard_page()` → `modules/analytics/views/dashboard.php` | `clms-dashboard` | **VIVO — es el real/actual.** Visible en sidebar | analytics |
| `atora-analytics` | `modules/analytics/class-analytics-engine.php:583-599` | `CLMS_Analytics_Engine::register_admin_menu()`, hook `atora_lms_admin_menu` | mismo archivo: `modules/analytics/views/dashboard.php` | `clms-dashboard` | **Huérfano del sidebar** (removido explícitamente por `cleanup_atora_submenus()`), duplicado puro — mismo view file que el anterior | analytics |
| `clms-analytics` | `includes/admin-menu/trait-admin-menu-hubs.php:844` (hidden) | hubs trait | `render_analytics_page()` (`trait-admin-menu-main-pages-academic.php:534`) — vista distinta, más antigua, basada en roles | `''` (oculta) | **Legacy genuino** — código diferente, no solo alias | analytics |

**Veredicto:** `atora-analytics-dashboard` es el real. `atora-analytics`
es un duplicado puro (mismo archivo) de la auto-registración del módulo
— candidato a eliminar. `clms-analytics` es legacy de verdad (otra
implementación) — candidato a redirect.

---

## Cluster 2 — "3 puntos de entrada" corregido a 2

`atora-admin` **no es un slug de menú** — es solo el handle de un
`wp_enqueue_style`/script (`trait-admin-menu-hubs.php:658,668,686`).
Nunca aparece en un `add_menu_page`/`add_submenu_page`. Se retira de este
cluster.

| Slug | Archivo:línea | Renderiza | Padre | Estado | Módulo |
|---|---|---|---|---|---|
| `clms-dashboard` | `trait-admin-menu-hubs.php:723-731` | `render_escritorio_page()` — panel principal | top-level (icono, posición 25) | **VIVO — el entry point real** | core/admin |
| `clms-control-center` | `trait-admin-menu-hubs.php:841` (hidden) | `render_control_center_page()` — overview comercial/analítico | `''` (oculta) | **VIVO pero intencionalmente oculta** — enlazada desde tarjetas de navegación y un botón, no es código muerto | core/admin |
| ~~`atora-admin`~~ | — | — | — | No es una página — retirado del inventario de duplicación | — |

**Veredicto:** solo hay un punto de entrada real (`clms-dashboard`) más
una vista secundaria legítima y oculta (`clms-control-center`). No hay
triplicación aquí — se corrige el hallazgo original de la OT.

---

## Cluster 3 — CRM ×3 (confirmado, y peor de lo descrito)

| Slug | Archivo:línea | Clase | Renderiza | Padre | Estado | Módulo |
|---|---|---|---|---|---|---|
| `atora-crm-v2` | `trait-admin-menu-hubs.php:756-763` | hubs trait, cap `clms_access_crm_view` | `render_crm_v2_page()` → `CRM_V2_App::render_page()` si v2 activo, si no cae a legacy | `clms-dashboard` (visible, "CRM") | **VIVO — el real/actual** | crm |
| `atora-crm` (registro A) | `trait-admin-menu-hubs.php:856` (hidden legacy) | hubs trait | `render_crm_v2_page()` detecta `page=atora-crm` y fuerza `render_crm_legacy_fallback()` | `''` (oculta) | Alias legacy intencional | crm |
| `atora-crm` (registro B) | `modules/crm/trait-crm-access.php:257` → `modules/crm/trait-crm-export-admin.php:263-276`, hook `atora_lms_admin_menu` | módulo CRM | requiere `modules/crm/views/admin.php` directamente, sin pasar por la lógica de fallback | `clms-dashboard` (visible, cap `read`) | **⚠️ Colisión real — requiere verificación en runtime antes de tocar.** Mismo slug, dos padres distintos (`''` vs `clms-dashboard`), dos render paths distintos. El registro B corre después (el hook dispara al final de `register_admin_pages()`) — no está confirmado si esto duplica salida o la sobreescribe silenciosamente | crm |
| `clms-crm-hub` | `trait-admin-menu-hubs.php:857` (hidden) | hubs trait | `render_crm_hub_page()` (`trait-admin-menu-widgets-and-hubs.php:1324`) — rama legacy/v2 propia, distinta de `render_crm_v2_page` | `''` (oculta) | Alias legacy, superseded por `atora-crm-v2` | crm |
| `atora-crm-comercial`, `atora-crm-academico`, `atora-crm-v2-pipeline-sales`, `atora-crm-v2-pipeline-academic`, `atora-crm-v2-inbox`, `atora-crm-v2-contacts`, `atora-crm-v2-calendar`, `atora-crm-v2-campaigns`, `atora-crm-v2-reports`, `atora-crm-academico-reports`, `atora-crm-v2-settings`, `atora-crm-v2-duplicates` | `trait-admin-menu-hubs.php:860-871` | hubs trait, dentro de `if ($this->is_crm_v2_enabled())` | todas enrutan por `render_crm_v2_page()` con sub-vista | `''` (oculta) | **Huérfanas condicionalmente** — con el flag v2 en su default (`false`) estas 12 nunca se registran. Solo viven si el sitio activa v2 | crm |

**Veredicto:** `atora-crm-v2` es el real. `atora-crm` tiene una doble
registración sin resolver — primer punto a arreglar en PT-4 (recomendado:
eliminar el registro de `modules/crm/trait-crm-access.php` y dejar que
la lógica de alias legacy del hub sea la única fuente de verdad).
`clms-crm-hub` es solo legacy.

---

## Patrón sistémico de doble registro (más allá de los 3 clusters conocidos)

**8 slugs más** se registran dos veces — una vez oculta (`parent=''`)
dentro de `register_admin_pages()` del hub, y otra vez por el propio
módulo vía el hook puente `atora_lms_admin_menu` (con `clms-dashboard`
como padre, luego ocultada del sidebar por `cleanup_atora_submenus()`).
En los 8 casos ambos registros apuntan **al mismo archivo de vista** —
no son páginas distintas, es scaffolding residual de cuando los módulos
se cableaban directo en el hub, antes de que cada módulo tuviera su
propia auto-registración.

| Slug | Registro en el hub (oculto) | Registro del módulo (puente) | ¿Mismo archivo? | Módulo |
|---|---|---|---|---|
| `atora-emails` | hubs.php:847 | `modules/email-engine/class-email-engine.php:929` | Sí | email-engine |
| `atora-newsletter` | hubs.php:848 | `modules/newsletter/class-newsletter.php:615` | Sí | newsletter |
| `atora-messaging` | hubs.php:849 | `modules/messaging/class-messaging-router.php:1016` | Sí | messaging |
| `atora-automations` | hubs.php:850 | `modules/automation/class-automation-engine.php:1728` | Sí | automation |
| `atora-webhooks` | hubs.php:851 | `modules/automation/class-outbound-webhooks.php:231` | Sí | webhooks |
| `atora-security` | hubs.php:852 | `modules/security/class-security.php:58` | Sí | security |
| `atora-affiliates` | hubs.php:853 | `modules/affiliates/class-affiliates.php:67` | Sí | affiliates |
| `atora-calendar` | hubs.php:791-798 (directo, visible) | `modules/calendar/class-calendar.php:730` | Sí | calendar |

Los 8 están en la lista de remoción de `cleanup_atora_submenus()`
(hubs.php:920-929), así que hoy no se ven duplicados en el sidebar —
pero la doble llamada a `add_submenu_page()` sigue en el código fuente.
**Recomendación:** dejar el registro del módulo como fuente de verdad
(ya es dueño de su propio view file) y eliminar la copia-sombra del hub.

También hay un caso de auto-duplicación dentro del propio hub:
`clms-ai-hub` se registra dos veces desde `trait-admin-menu-hubs.php`
(línea 811-818 visible y línea 837 oculta), mismo callback en ambos
casos — probablemente inofensivo pero igualmente una llamada literal
duplicada a colapsar en una sola.

---

## Resto del inventario, por módulo

### core/admin (plomería, dashboard principal, ajustes, licencia, toggle de módulos)

| Slug | Archivo:línea | Renderiza | Padre | Estado |
|---|---|---|---|---|
| `clms-settings-hub` | hubs.php:802-809 | `render_settings_hub_page()` | `clms-dashboard` | Vivo, visible ("Ajustes") |
| `clms-settings` (registro A) | hubs.php:843 (hidden) | `render_settings_page()` → `CLMS_Settings` | `''` | Vivo vía URL, enlazado desde Ajustes |
| `clms-settings` (registro B) | `includes/settings/trait-settings-core.php:18-24` | `render_page()` | `null` | **No-op guardado**: se salta si el hub ya registró `clms-settings` (`is_settings_page_registered()`). Código muerto hoy, solo fallback |
| `clms-control-center` | ver Cluster 2 | | | |
| `clms-maintenance` | hubs.php:846 | `render_maintenance_page()` | `''` | Vivo vía URL, `manage_options` |
| `clms-my-profile` | hubs.php:833 | `render_my_profile_page()` | `''` | Vivo vía URL |
| `atora-modules` | `includes/modularity/class-module-admin-page.php:27-34` | toggle de módulos (PT-2/PT-3, añadido este sprint) | `clms-dashboard` | **Vivo, nuevo este sprint** — no es una anomalía |
| `atora-onboarding` | `includes/onboarding/class-onboarding-wizard.php:39-46` | wizard de 5 pasos | `clms-dashboard` | Vivo, condicional (se auto-remueve al completar) |
| `atora-license` | `modules/licensing/class-licensing.php:308-316` | `render_page()` | `clms-dashboard` | **Vivo y visible** — no está en la lista de remoción |
| `clms-template-parts` | `includes/ui/class-ui-template-parts.php:357-364` | gestor de header/footer | `clms-dashboard` | Vivo, visible, cap `edit_theme_options` |

### academic

| Slug | Archivo:línea | Renderiza | Padre | Estado |
|---|---|---|---|---|
| `clms-academic-hub` | hubs.php:745-752 | `render_academic_hub_page()` | `clms-dashboard` | Vivo, visible ("Academia") |
| `clms-academic-content` | hubs.php:838 | `render_academic_content_page()` | `''` | Vivo vía URL |
| `clms-instructor-profile` | hubs.php:834 | `render_instructor_profile_page()` | `''` | Vivo vía URL, cap docente |
| `clms-academic-wizard` | `includes/academic/class-academic-admin-tools.php:34-41` | `render_wizard_page()` | `''` | Vivo vía URL |
| `clms-academic-reports` | `includes/academic/class-academic-admin-tools.php:43-50` | `render_reports_page()` | `''` | Vivo vía URL |

### gradebook

| Slug | Archivo:línea | Renderiza | Padre | Estado |
|---|---|---|---|---|
| `clms-gradebook` | hubs.php:835 | `render_gradebook_page()` | `''` | Vivo vía URL, con CSS/JS propio (confirma uso activo) |
| `clms-speedgrader` | hubs.php:836 | `render_speedgrader_page()` | `''` | Vivo vía URL |

### commerce

| Slug | Archivo:línea | Renderiza | Padre | Estado |
|---|---|---|---|---|
| `clms-commercial-hub` | hubs.php:780-787 | `render_commercial_hub_page()` | `clms-dashboard` | Vivo, visible ("Comercio") |
| `clms-commercial-operations` | hubs.php:839 | `render_commerce_hub_page()` — nombre de función no coincide con el slug | `''` | Vivo vía URL |
| `clms-commerce-hub` | hubs.php:840 | `render_legacy_commerce_hub_alias_page()` | `''` | Alias legacy explícito |
| `clms-commerce-dashboard` | hubs.php:842 | `render_commerce_dashboard_page()` | `''` | Vivo vía URL |

### email-engine, newsletter, messaging, automation, webhooks, security, affiliates, calendar

Cada uno tiene el par hub-oculto + módulo-puente — ver tabla del patrón
sistémico arriba para archivo:línea. Adicionalmente:

| Slug | Estado | Módulo |
|---|---|---|
| `clms-messages` | hubs.php:845, `render_messages_page()`, padre `''`, vivo vía URL | messaging |

### ai

| Slug | Archivo:línea | Renderiza | Padre | Estado |
|---|---|---|---|---|
| `clms-ai-hub` (×2, mismo origen) | hubs.php:811-818 y :837 | `render_ai_hub_page()` (mismo callback en ambos) | `clms-dashboard` / `''` | Doble registro desde el mismo trait — probablemente inofensivo pero a colapsar en uno |
| `clms-ai-settings` | `includes/ai/class-ai-admin.php:85-92` | `render_legacy_wrapper()` | `''` | **Legacy documentado explícitamente** en el docblock de la clase, con redirect activo |
| `clms-ai-exams` | `includes/class-ai-exams.php:538-546` | `render_settings_page()` | `clms-settings` | Vivo vía URL — su padre también está oculto (dos niveles de "oculto") |

### analytics (sub-herramientas forms/popups)

| Slug | Archivo:línea | Renderiza | Padre | Estado |
|---|---|---|---|---|
| `atora-forms` | `modules/analytics/class-forms-builder.php:386-401` | constructor de formularios | `clms-dashboard` | Vivo, visible |
| `atora-popups` | `modules/analytics/class-popups.php:219-234` | gestor de popups | `clms-dashboard` | Vivo, visible |

### lms

| Slug | Archivo:línea | Renderiza | Padre | Estado |
|---|---|---|---|---|
| `atora-lms-migration` | `modules/lms/class-lms-migration-admin.php:61-69` | panel de migración F1-F4 | `clms-dashboard` | Vivo, visible |

### Módulos sin ninguna página de admin registrada

`certificates`, `live-streaming`, `gamification`, `mcp` — ninguno tiene
`add_menu_page`/`add_submenu_page` en su directorio, ni referencia
oculta apuntándoles. A confirmar si es intencional (solo settings,
sin pantalla propia) o si su UI de admin se perdió/nunca se construyó.

### Referencia colgante (código muerto, no es un slug real)

`atora-live-streaming` aparece **una sola vez** en todo el código: en la
lista de remoción de `cleanup_atora_submenus()`
(`trait-admin-menu-hubs.php:922`), llamando
`remove_submenu_page('clms-dashboard', 'atora-live-streaming')`. No
existe ningún `add_submenu_page()` para este slug — es un no-op
inofensivo hoy, resto de una funcionalidad removida o nunca lanzada.
Candidato a limpieza trivial.

---

## Resumen para la consolidación (PT-4.2 en adelante)

1. `atora-admin` no es una página — se retira del marco de duplicación.
2. Analytics ×3 confirmado — `atora-analytics-dashboard` es el real.
3. CRM ×3 confirmado, con una colisión real sin resolver en `atora-crm`
   (dos registros, dos padres, dos render paths) que necesita
   verificación en runtime antes de tocarse.
4. **8 slugs adicionales** con el mismo patrón hub-oculto + módulo-puente,
   no cubiertos por los 3 clusters conocidos de la OT.
5. Una referencia colgante (`atora-live-streaming`) y **4 módulos sin
   página de admin** (`certificates`, `live-streaming`, `gamification`,
   `mcp`) a confirmar con los dueños de cada módulo.
6. El conteo real es **43 slugs únicos** (CRM v2 apagado, el default) o
   **55** (CRM v2 activado) — no un número fijo de 58.

**Este documento está listo para revisión.** PT-4.2 (menú desde el
registro de módulos) y PT-4.3 (estructura de 8 entradas) no se
implementan hasta confirmar el enfoque sobre los puntos 3 y 4 arriba.
