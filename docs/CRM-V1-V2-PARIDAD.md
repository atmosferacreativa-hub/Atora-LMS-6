# CRM v1 vs v2 — Paridad y riesgo de retiro

> Documento de decisión para PT-5.2. **No se retira nada en este
> sprint** — `modules/crm/` (v1) sigue intacto. Esto es para que 6.4.0
> decida con información real, no una propuesta de cómo hacerlo.

## El hallazgo que cambia el marco de la decisión

**v2 no es un reemplazo independiente de v1 — depende de él en
runtime.** `modules/crm-v2/` no reimplementa primitivas núcleo del
CRM; llama directamente a métodos estáticos de `\ATORA\CRM\CRM` (v1)
para ellas. Si el código de v1 se borrara, v2 fallaría con error fatal
en el primer uso de cualquiera de estos:

| Método estático de v1 | Se usa desde v2 en |
|---|---|
| `CRM::can_access_crm()` | `class-crm-v2-app.php:511`, `rest/class-crm-rest-controller.php:108` |
| `CRM::can_manage_crm()` | `class-crm-v2-app.php:524`, `class-crm-v2.php:80,1318`, `rest/class-crm-rest-controller.php:120`, `rest/class-crm-draft-rest-controller.php:73`, `trait-crm-v2-segmenter.php:357` |
| `CRM::has_global_contact_scope()` | `rest/class-crm-rest-controller.php:187`, `services/class-contact-service.php:648` |
| `CRM::get_accessible_contact_user_ids()` | `class-crm-v2.php:1325`, `rest/class-crm-rest-controller.php:162`, `services/class-contact-service.php:628`, `trait-crm-v2-segmenter.php:364` |
| `CRM::log_activity()` | `class-crm-v2.php:673,723`, `trait-crm-v2-pipeline.php:249,299`, `services/class-activity-service.php:84` |
| `CRM::add_tag()` / `CRM::remove_tag()` | `class-crm-v2.php:765,815`, `trait-crm-v2-pipeline.php:341,391` |
| `CRM::get_timeline()` | `services/class-contact-service.php:215` |
| `CRM::sync_email_queue_to_conversation()` | `class-crm-v2.php:914`, `trait-crm-v2-pipeline.php:490`, `services/class-crm-email-service.php:642` |

**Consecuencia práctica:** "retirar v1" no puede significar "borrar
`modules/crm/`" sin antes portar estas primitivas a v2 (o a un módulo
compartido) y reapuntar cada uno de los call sites de arriba. Es un
proyecto de refactorización, no una eliminación de código muerto.

## 1. Funcionalidad de v1 sin equivalente claro en v2

| Funcionalidad de v1 | Ubicación | Estado en v2 |
|---|---|---|
| **Exportación CSV** (contactos, message_log, email_queue, con mapeo de columnas y streaming) | `trait-crm-export-admin.php` | Cero código de exportación CSV en todo `modules/crm-v2/` (verificado por grep). No existe en v2. |
| **Endpoints REST de message-log** (`/crm/messages/log`, `/messages/log/summary`, filtros por canal/estado) | `trait-crm-rest.php:40-70,243-444` | Sin equivalente. El Inbox de v2 muestra `atora_conversations`, no el registro crudo de intentos de entrega de `atora_message_log`/`atora_message_queue`. |
| **Ingesta de mensajes entrantes WhatsApp/Telegram → conversación** (resolución de teléfono/chat-id, detección de coincidencias ambiguas) | `trait-crm-events-messaging.php:155-457,564-807` | v2 no escucha `atora/whatsapp/message_received` ni `atora/telegram/message_received` — solo LEE las conversaciones que v1 escribe. |
| **Puente de sincronización email/mensaje → conversación** | `trait-crm-admin-sync.php:90-572` | v2 delega en v1 para esto (ver tabla de arriba), no tiene lógica propia. |
| **Cron de auto-etiquetado** (`atora_crm_autotag_cron`: `#inactive-30d`, `#high-engagement`, `#potential-churn`) | `trait-crm-access.php:246-250`, `trait-crm-contacts.php:428-493` | El `Scoring_Service` de v2 calcula un score más rico pero solo vía `recalculate_all()`, **sin cron/scheduling encontrado** y sin auto-aplicar tags. El segmentador de v2 (`trait-crm-v2-segmenter.php:284,308`) sigue usando las tags de v1 como criterio de filtro — es decir, la UI de segmentación de v2 depende de que el cron de v1 siga corriendo. |
| **UI legacy**: notas con pin, búsqueda de contactos, dashboard de 3 tabs con desglose de identidad de email | `modules/crm/views/admin.php` (1,316 líneas) | v2 tiene su propio contacto 360° y pipeline, pero el tab de message-log/identidad de email no tiene equivalente. |
| **`mark_email_opened()` / `mark_message_read()`** (override manual preservando timestamp existente) | `trait-crm-admin-sync.php:90-175` | Sin equivalente en v2. |
| **`get_contact_user_ids_by_tag()` / `get_contact_user_ids_by_course()`** | `trait-crm-contacts.php:319-426` | v2 no las reimplementa; su propio scoping llama de vuelta a `get_accessible_contact_user_ids()` de v1. |

**Nota aparte:** `modules/automation/class-automation-engine.php` llama a
`CRM::get_contact_id_by_user()` (líneas 545-546, 853-854, 874-875,
950-951) tras un `method_exists()` — ese método **no existe en ningún
lado del código**, confirmado por grep completo del repo. Hoy no
truena porque el guard lo evita en silencio, pero es una integración
automatización↔CRM a medio terminar, independiente de la decisión v1/v2.

## 2. Tablas de base de datos

### Tablas que usa v1
`atora_contacts`, `atora_contact_tags`, `atora_contact_activities`,
`atora_contact_notes`, `atora_conversations`,
`atora_conversation_messages`, más lectura/escritura de
`atora_email_queue` (de `email-engine`), `atora_message_queue`/
`atora_message_log` (de `messaging`) y lectura de
`atora_user_engagement` (de `analytics`) para el cron de auto-tags.

### Tablas que usa v2
**Propias de v2** (creadas por `DB_Service::get_schema_statements()`):
`atora_crm_deals`, `atora_crm_student_followups`, `atora_crm_tasks`,
`atora_crm_campaigns`, `atora_crm_campaign_recipients`,
`atora_crm_contact_fields`, `atora_crm_contact_field_values`.

**Compartidas/extendidas por v2** (no las crea, pero las modifica o
consulta): `atora_contacts` (v2 le agrega ~16 columnas en runtime vía
`ensure_runtime_columns()` — `contact_type`, `timezone`,
`contact_hash`, `contact_owner`, `company_id`, `conversion_score`,
`engagement_score`, `total_points`, `life_time_value`, campos geo,
etc.), `atora_contact_tags`, `atora_contact_notes`,
`atora_contact_activities`, `atora_conversations`,
`atora_conversation_messages`, `atora_email_queue`.

**Exclusivas de v2** (conceptos que v1 no tiene): `atora_companies`,
`atora_email_sequences` + sus 3 tablas relacionadas,
`atora_email_suppression`, `atora_crm_lists`,
`atora_contact_list_pivot`, `atora_url_store`, `atora_url_clicks`.

### Riesgo de solapamiento (lo crítico para planear el retiro)

| Tabla | Riesgo de retiro |
|---|---|
| `atora_contacts` | **Alto.** v2 extiende el schema y lee/escribe las mismas filas, pero no tiene lógica propia de dedup/resolución de identidad por teléfono/email — depende del `upsert_contact()` de v1. |
| `atora_contact_tags/notes/activities` | **Alto — ya existe doble escritura hoy.** v1 y v2 escriben directo a estas tablas por caminos de código *distintos* (ver hooks abajo). Cualquier retiro debe primero consolidar a un solo camino de escritura. |
| `atora_conversations`, `atora_conversation_messages` | **Alto.** v1 es el único escritor (puente de sync + webhooks entrantes); v2 solo lee. Sin v1, el Inbox de v2 se queda sin datos nuevos. |
| `atora_message_queue`, `atora_message_log`, `atora_user_engagement` | Bajo en BD, pero de ahí viene el hueco funcional del dashboard/CSV de message-log (§1). |

## 3. Hooks públicos de v1 y quién depende de ellos

### Hooks que v1 dispara

| Hook | Dónde | Payload |
|---|---|---|
| `atora/crm/activity_logged` | `trait-crm-contacts.php:184-190` | `(int $contact_id, string $activity_type, array $data, int $user_id)` |
| `atora/crm/tag_added` | `trait-crm-contacts.php:258` | `(int $user_id, string $tag_name, int $contact_id)` |
| `atora/crm/tag_removed` | `trait-crm-contacts.php:293` | `(int $user_id, string $tag_name, int $contact_id)` |
| `atora_crm_can_access_user` (filter) | `trait-crm-access.php:56` | `(bool, int $user_id, array $allowed_caps)` |
| `atora_crm_export_should_exit` (filter) | `trait-crm-export-admin.php:243` | `(bool)` |

**El riesgo más alto de todo el informe:** `atora/crm/tag_added` /
`atora/crm/tag_removed` los consume
`modules/automation/class-automation-engine.php:140-141` para dos
disparadores de automatización (`tag_added`/`tag_removed`). Esto
funciona hoy porque `CRM_V2::add_tag()`/`remove_tag()` delegan en el
método de v1 que sí dispara el hook. **Pero v2 tiene además un segundo
camino de escritura de tags, independiente:**
`Contact_Service::add_tag()` (`services/class-contact-service.php:833-870`,
basado en contact_id), que **no dispara el hook** — solo registra
actividad. Es decir: hoy mismo hay dos caminos para etiquetar un
contacto en v2, y las automatizaciones por tag solo funcionan si se usa
el que termina llamando a v1. Si v1 se retira y sobrevive el camino de
`Contact_Service`, las automatizaciones por tag dejan de dispararse en
silencio, a menos que se le agregue el hook ahí también.

`atora/crm/activity_logged`, `atora_crm_can_access_user` y
`atora_crm_export_should_exit` no tienen consumidores fuera de
`modules/crm/` — son infraestructura interna de v1 hoy.

### Hooks que v1 escucha (`trait-crm-access.php:237-244`)

```
user_register, atora/security/registration_saved, woocommerce_thankyou,
clms_user_enrolled_in_course, atora/forms/submitted,
atora/email/webhook_event, atora/whatsapp/message_received,
atora/telegram/message_received
```

Todos disparados por OTROS módulos (WooCommerce, seguridad, LMS,
formularios, email-engine, mensajería), con v1 como único listener que
los convierte en altas/actualizaciones de contacto. **v2 no registra
ningún listener en estos hooks.** Si v1 no corre, ningún registro,
compra, matrícula, envío de formulario o mensaje de WhatsApp/Telegram
entrante crea o actualiza un contacto — la base de datos de contactos
de v2 se queda estancada, porque v2 no tiene ingesta propia.

## 4. Estado real del flag `clms_crm_v2_enabled`

- **Default: `false`** en absolutamente todos los `get_option()` que lo
  leen (`atora_lms.php:202`, `trait-admin-menu-hubs.php:1095,1871`,
  `class-crm-v2-app.php:497`, `trait-settings-render.php:832`).
- **Getter canónico:** `CLMS_Settings::is_crm_v2_enabled()`, con filtro
  `atora/crm_v2/enabled` disponible pero sin nadie enganchado a él.
- **Sin migración automática.** Grep completo de
  `update_option( 'clms_crm_v2_enabled'` en instaladores/upgraders:
  cero resultados. Es puramente manual — un admin lo activa a mano en
  Ajustes → ATORA → CRM.
- **Consecuencia práctica:** por default, **lo que ve producción hoy
  es la UI de v1** (`render_crm_v2_page()` cae a
  `render_crm_legacy_fallback()`, que hace `require` directo de
  `modules/crm/views/admin.php`, cuando el flag está apagado o cuando
  el slug pedido es un alias legacy).
- **¿`V5_Modules::load_crm()` carga v1 incondicionalmente?** **Sí,
  confirmado** (`modules/class-v5-modules.php:218-230`): tanto
  `CRM::init()` (v1) como `CRM_V2_App::init()` (v2) corren en **cada
  request**, sin importar el flag. El flag solo decide cuál se
  *renderiza*; los hooks, cron, AJAX y REST de v1 están siempre
  activos, y — por la sección 1 — la lógica interna de v2 depende de
  que ese runtime de v1 siga corriendo.

## 5. Observaciones de calidad/seguridad en v1 (señaladas, no auditadas a fondo)

- **Sin SQL sin preparar encontrado** — todo uso dinámico de `$wpdb`
  en `modules/crm/` pasa por `prepare()`, incluidas cláusulas `IN(...)`
  con arrays de placeholders. Señal positiva.
- **Código huérfano:** `CRM_Export_Admin_Trait::register_admin_menu()`
  ya no está enganchada a ningún hook desde el commit `[MENU]` de PT-4.3.3
  de este mismo sprint (el hub absorbió el renderizado). Candidato a
  limpieza trivial, no bloquea nada.
- **Guard de método inexistente en producción:** las 4 llamadas de
  `automation` a `CRM::get_contact_id_by_user()` (método que nunca
  existió) — independiente de la decisión v1/v2, vale la pena
  corregir en algún momento.
- **Emails "sombra" para leads solo-por-canal**
  (`build_shadow_contact_email()`, formato `channel-{hash}@atora.local`)
  — cualquier lógica de dedup que v2 construya a futuro debe conocer
  esta convención si deja de depender de v1.
- **Colisiones de teléfono ambiguas se descartan en silencio** — se
  registran en `atora_message_log` pero ninguna vista del admin las
  muestra; solo quedan en el log crudo.
- **El riesgo estructural más grande no es una línea de código, es la
  arquitectura de doble escritura**: v1 y v2 escriben a las mismas
  tablas de tags/notas/actividad por caminos distintos, y solo uno de
  esos caminos dispara los hooks de los que depende `automation`. Esto
  ya es así HOY, con v1 activo — no es algo que el retiro introduzca,
  pero cualquier plan de retiro debería resolver esta inconsistencia
  primero, o arriesga romper automatizaciones que hoy funcionan por
  casualidad de cuál camino de código se llamó.

## Conclusión para 6.4.0

Retirar `modules/crm/` no es una eliminación de código legacy — es un
proyecto de migración: portar ~8 primitivas núcleo (acceso, logging,
tags, timeline, sync de conversaciones) a v2 o a un módulo compartido,
decidir qué pasa con 6 features sin equivalente (sobre todo CSV export
y el dashboard de message-log), resolver la doble escritura de
tags/notas/actividad, y solo entonces apagar los 8 listeners de
ingesta de v1 sin perder la creación de contactos por registro/compra/
matrícula/formulario/WhatsApp/Telegram. Ninguna de estas piezas es
grande por sí sola, pero juntas son varios sprints, no un "borrar
directorio".
