# CHANGELOG — ATORA LMS

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
