# FEATURE-REPORT-6.7.0.md

## ATORA LMS 6.7.0 — CRM comercial: planes de seguimiento reutilizando el motor académico

**Rama:** `feature/6.7.0-planes-comerciales`, base `6.6.0`.
**Fecha:** 2026-08-24.

---

## 1. Resumen

Este sprint apunta el motor de planes de seguimiento construido en 6.6.0
(`Followup_Plan_Service`, `Followup_Plan_Resolver`, `Followup_Recurrence`)
al pipeline comercial, sin construir un segundo motor. La regla central de
la OT — "reutilizar antes que construir" — se cumplió generalizando la
capa de origen de datos (de dónde salen las etapas y quién está en cuál)
detrás de una interfaz de dominio, dejando intacto todo lo demás:
recurrencia, resolución dinámica, fusión de ocurrencias, exclusión
puntual, y el principio de que marcar contactado nunca mueve una etapa.

## 2. Paquetes entregados

| Paquete | Contenido | Commit |
|---|---|---|
| PT-1 | `Followup_Domain_Provider` (interfaz) + `Academic_Domain_Provider`/`Commercial_Domain_Provider`; columnas `domain`/`domain_config` en `atora_followup_plans` | `c6f5d57` |
| PT-2 | 4 plantillas comerciales ancladas a `Deal_Service::get_stages()` | `dfdfe51` |
| PT-3/PT-4/PT-5 | Color de urgencia por score, indicación de secuencia activa (solo lectura), selector de dominio, asistente domain-aware | `c4e07e9` |
| Tests | `tests/6.7.0/run_static_checks.py` (14 checks) | `ca8c0b8` |
| Deuda técnica | 5 entradas nuevas en `docs/DEUDA-TECNICA.md` | `6df035b` |
| Release | Bump de versión + changelog/upgrade notice | `a4f3d6f` |

## 3. Decisiones autónomas registradas (§0.7)

- **Interfaz formal, no duck typing**: se encontró un precedente real en
  el plugin (`Provider_Interface` de `email-engine`, métodos estáticos +
  `implements`) — `Followup_Domain_Provider` sigue exactamente ese
  patrón, sin inventar una convención nueva.
- **Alcance comercial sin "sección"** (PT-1.5): `section_ids` vacío en
  modo por-etapa significa "toda la cartera del vendedor dueño del plan"
  (`Commercial_Domain_Provider` acota por `assigned_to`); un `section_ids`
  no vacío sin `stage_filter` significa selección manual de contactos
  (plantilla "Cuenta clave"). No se renombró la columna — documentado en
  el docblock del proveedor, evitando tocar los muchos call sites de
  6.6.0 sin beneficio real más allá del nombre.
- **PT-1.3 (cierre de sección) solo aplica a `domain === 'academic'`** —
  el pipeline comercial no tiene una señal de "cierre de período" que
  reutilizar; inventar una estaba fuera de lo que la OT pedía.
- **"Quincenal" = `DAILY;INTERVAL=14`**, no un `WEEKLY;INTERVAL=2` nuevo
  — el motor de recurrencia no soporta intervalos semanales y §0.2
  prohíbe tocarlo este sprint.
- **Score en vivo, nunca la columna cacheada**: `Commercial_Domain_Provider`
  llama a `Scoring_Service::calculate_score()` (recalcula desde las
  señales crudas) en cada resolución, nunca lee `atora_contacts.
  conversion_score` (que solo se actualiza en el batch de
  `recalculate_all()`) — coherente con el principio de "nada congelado".
- **Coordinación con secuencias, estrictamente de solo lectura**: se
  reutilizó `Sequence_Service::get_contact_enrollments()`/`get_steps()`
  tal cual existían; no se agregó ninguna llamada de escritura sobre una
  secuencia. El filtro "excluir en secuencia activa" está apagado por
  default y, aun activado, el contacto sigue contando en
  `filtered_out_count` — ningún contacto pierde visibilidad sin que el
  vendedor lo decida explícitamente.
- **Buscador de contactos de "Cuenta clave"**: se reutilizó el endpoint
  ya existente `GET /contacts/search` (`Contact_Service::search_contacts()`)
  en vez de construir uno nuevo.
- **Gate de la página ampliado**: `render_followup_plans_page()` pasó de
  exigir solo `can_access_academic_calendar()` a un nuevo
  `can_access_followup_plans()` que también acepta `clms_access_crm_view`
  — sin esto, un vendedor sin ningún rol académico no podría llegar a
  sus propios planes comerciales.

## 4. Verificación

### 4.1 Comandos de la OT

```
find . -name "*.php" -exec php -l {} \;
vendor/bin/phpcs --standard=WordPress modules/crm-v2/
vendor/bin/phpunit
```

**NO EJECUTADOS** — no hay intérprete PHP disponible en este entorno de
trabajo (confirmado de nuevo explícitamente en esta sesión), consistente
con cada sprint anterior de este proyecto. En su lugar:

- **Balance de llaves/paréntesis** por conteo sobre los 15 archivos
  PHP/JS/CSS nuevos o modificados este sprint: todos cuadran. Los 5
  archivos del árbol con desbalance detectado por este método naive
  (`class-lms-migrator.php`, `uninstall.php`, tres tests de sprints
  previos) **no pertenecen a este sprint** — son los mismos falsos
  positivos conocidos (paréntesis sueltos en comentarios/strings)
  disclosed en cada sprint anterior.
- **`node --check`** sobre `followup-plans.js` (Node sí está disponible
  en este entorno, a diferencia de PHP): sintaxis válida, confirmado con
  el intérprete real, no solo balance de llaves.
- **`tests/6.7.0/run_static_checks.py`**: 14/14 PASS. Verificado
  significativo de dos formas: (1) contra un worktree de `main`
  (pre-6.6.0) — falla duro, los archivos no existen; (2) contra el tip
  exacto de 6.6.0 del que parte esta rama — el check 01 falla
  correctamente (el resolver todavía llamaba a `Student_Followup_Service`
  directamente) y el check 02 falla duro (los proveedores de dominio no
  existían). También se corrió `tests/6.6.0/run_static_checks.py` contra
  el código de 6.7.0: 7 de 10 checks siguen pasando sin tocar nada; los 3
  que fallan (01, 02, 07) son exactamente los que PT-1/PT-2 relocalizaron
  a propósito (documentado en el docstring de `tests/6.7.0/`) — `tests/
  6.6.0/` se dejó intacto como registro histórico, sin editar.

### 4.2 Matriz manual de la OT

| Escenario | Resultado del rastreo de código |
|---|---|
| Plan académico existente de 6.6.0 tras el despliegue | La migración agrega `domain` con `DEFAULT 'academic'` y `domain_config` nullable — `normalize_plan_row()` decodifica ambos con fallback seguro. `Followup_Plan_Resolver::resolve_recipients()` lee `plan['domain']` y despacha a `Academic_Domain_Provider`, cuyo código es el mismo roster+etapa de 6.6.0 movido sin reescribir. **No ejecutado contra una base de datos real.** |
| Aplicar "Deals estancados" a una cartera con deals parados | `Commercial_Domain_Provider::build_entities()` descarta cualquier deal con `days_stalled < min_stalled_days` (5 por default) antes de incluirlo — lógicamente correcto por rastreo de código. **No ejecutado.** |
| Deal cambia de etapa entre creación del plan y la ocurrencia | `resolve_entities_in_stages()` llama a `Deal_Service::get_board()` en cada invocación, sin ningún transient/caché de por medio (confirmado por el check estático 02, análogo al principio ya verificado en 6.6.0). **No ejecutado.** |
| Contacto con score en caída | El color del bloque de calendario y el badge del panel se recalculan en cada carga vía `Scoring_Service::calculate_score()` — nunca una copia guardada (check estático 07). **No ejecutado visualmente.** |
| Contacto en secuencia activa aparece en una ocurrencia | `resolve_sequence_info()` se adjunta a `meta.sequence` de cada entidad sin excluirla, salvo que `domain_config.exclude_active_sequence` esté explícitamente activado — verificado por checks 08/09. **No ejecutado en un panel real.** |
| Marcar contactado sobre alguien en secuencia activa | `mark_contacted()` (sin cambios desde 6.6.0, reverificado por el check 06) no referencia `Sequence_Service` en absoluto — la secuencia no puede verse afectada porque el código que la tocaría no está en la ruta de ejecución. **No ejecutado.** |
| Filtro "excluir en secuencia" activado | `build_entities()` incrementa `filtered_out_count` y hace `continue` (no agrega la entidad a la lista) cuando el filtro está activo y la secuencia está activa — el panel lateral (JS) muestra ese conteo como aviso. **No ejecutado en navegador.** |
| Uso completo desde teléfono | El CSS del selector de dominio, badges de score y el buscador de contactos siguen el mismo estándar de 44px/AA/sin-scroll-horizontal que 6.6.0. **No verificado visualmente en un dispositivo — sin entorno WordPress en vivo disponible en esta sesión, mismo disclosure que 6.6.0.** |

### 4.3 Prueba de campo (requisito explícito de la OT)

> "un vendedor real aplicando 'Deals estancados' y registrando dos
> contactos desde su teléfono, sin instrucciones previas."

**NO REALIZADA**, por el mismo motivo disclosed en el reporte de 6.6.0:
no hay entorno WordPress en vivo ni un vendedor disponible en esta
sesión de trabajo. Se registra honestamente en lugar de fabricar una
validación que no ocurrió.

**Recomendación:** correr esta prueba en staging junto con la prueba de
campo académica pendiente de 6.6.0 antes de considerar cerrado el
motor de planes de seguimiento en su conjunto.

## 5. Regresión de seguridad

Ningún archivo de este sprint toca las superficies endurecidas en 6.5.x.
El endpoint reutilizado `GET /contacts/search` ya estaba gateado por
`CRM_REST_Controller::can_access()`; el gate ampliado de la página
(`can_access_followup_plans()`) solo AGREGA una capacidad ya usada en
otro lugar del admin-menu (`clms_access_crm_view`, hub Crecimiento), no
relaja ninguna verificación existente. Cada acción de escritura sobre
una ocurrencia sigue pasando por `require_owned_occurrence()`/
`require_owned_plan()`, sin cambios. **No se ejecutó un escaneo de
seguridad dedicado para este sprint** — recomendado incluir el motor de
planes de seguimiento completo (académico + comercial) en la próxima
revisión de seguridad general del proyecto.

## 6. Compatibilidad hacia atrás (§0.5)

- La migración solo agrega dos columnas (`domain` con default,
  `domain_config` nullable) — ninguna instalación existente pierde datos.
- `Followup_Plan_Resolver::resolve_for_definition()` extiende su firma
  con parámetros opcionales (`domain = 'academic'`, `domain_config = []`,
  `owner_id = 0`) que preservan exactamente el comportamiento de 6.6.0
  cuando se omiten.
- `Followup_Plan_Service::get_templates()` mantiene su call-shape sin
  argumentos (`domain` default `'academic'`).
- El calendario/asistente/panel del docente no cambia su comportamiento
  visible para una instalación sin planes comerciales — el selector de
  dominio solo se renderiza si el usuario tiene ambas capacidades.

## 7. Pendiente / fuera de este cierre

- Prueba de campo con vendedor real (§4.3) — bloqueante para considerar
  el motor de planes de seguimiento (académico + comercial) verdaderamente
  cerrado, junto con la prueba de campo académica ya pendiente desde 6.6.0.
- `php -l`/`phpcs`/`phpunit` reales, en un entorno con PHP disponible.
- Verificación visual/manual en navegador y dispositivo móvil real.
- Auditoría de seguridad dedicada al motor completo (académico +
  comercial) de planes de seguimiento.
- Ver `docs/DEUDA-TECNICA.md` para las cinco brechas identificadas y
  diferidas explícitamente (recurrencia quincenal aproximada, ausencia
  de señal de cierre comercial, urgencia por score simple en vez de
  compuesta, selector de "Cuenta clave" sin aviso de superposición entre
  planes).
- **Fuera de alcance explícito de la OT** (no evaluado ni iniciado):
  control automático de secuencias desde el motor de planes, unificación
  de `Task_Service` con las ocurrencias de planes, reportes agregados de
  cumplimiento entre varios vendedores, un tercer dominio (p. ej.
  seguimiento de egresados).

## 8. Empaquetado

`FEATURE-REPORT-*.md` ya estaba excluido de `.distignore` desde 6.6.0 —
no fue necesario agregar un patrón nuevo antes de construir el ZIP.

- **Archivo:** `dist/atora-lms-6.7.0.zip`
- **SHA-256:** `91c29623b5d5dd464d8f607cba3a4bef48c58d48107841e8544a4636aa66dfc0`
- **Auditoría del contenido extraído:** sin archivos de desarrollo
  (`tests/`, `docs/`, `.git*`, `scripts/`, `.claude/`, `FEATURE-REPORT-*.md`)
  en la raíz del paquete; cadenas de versión (`Version:`,
  `ATORA_LMS_VERSION`, `Stable tag`) consistentes en `6.7.0`; los tres
  archivos nuevos del dominio (`class-followup-domain-provider-interface.php`,
  `class-academic-domain-provider.php`, `class-commercial-domain-provider.php`)
  presentes; escaneo de patrones de credenciales sin hallazgos reales —
  el único match fue la misma variable de formulario JS ya existente
  (`&password=`) de la contraseña de acceso a matrícula, disclosed
  también en el reporte de 6.6.0.
