# FEATURE-REPORT-6.9.0.md

## ATORA LMS 6.9.0 — Componentes compartidos, búsqueda persistente y "Actividad"

**Rama:** `feature/6.9.0-ui-compartida`, base `6.8.0`.
**Fecha:** 2026-08-25.

---

## 1. Resumen

Este sprint extrajo el panel/fila que el calendario académico (6.6.0), el comercial (6.7.0) y
"Hoy" (6.8.0) habían construido cada uno por su cuenta, en dos componentes compartidos
(`Followup_List_Row`, `Followup_Panel`), retrofiteó los dos usos existentes para que los
consuman en vez de duplicar marcado, y construyó dos features nuevas sobre esa misma base:
búsqueda federada persistente y un feed de "Actividad". El resultado observable para quien ya
usa el sistema: mismo aspecto, mismo comportamiento, más rápido de mejorar hacia adelante.

## 2. Paquetes entregados

| Paquete | Contenido | Commit |
|---|---|---|
| PT-1 | `CLMS_UI_List_Row`/`CLMS_UI_Followup_Panel` (PHP+JS+CSS), `tokens.css`, retrofit del panel de calendario y de "Hoy" | `1c6b1f7` |
| PT-2 | Búsqueda persistente y federada (`CLMS_UI_Search_Service`, `search.js`) | `9dfd62d` |
| PT-3 | Feed de "Actividad" (`CLMS_UI_Activity_Feed_Service`) | `17b0010` |
| PT-4 | Verificación del "loop" único de acción | `6580b6c` |
| Tests | `tests/6.9.0/run_static_checks.py` (14 checks) | `741b744` |
| Release | Bump de versión + changelog/upgrade notice | `74a8ee7` |

## 3. Decisiones autónomas registradas (§0.7)

- **`tokens.css` — dos capas, no una elegida sobre la otra**: no existe ningún `theme.json` en
  este repositorio (es un plugin). Los valores de marca (ink/cream/atora-blue/atora-gold) solo
  aparecen hoy como estilos inline en las plantillas de bloques de un sitio específico
  (`class-ui-editor-templates.php`). Se declaran como tokens de marca por defecto del plugin
  (`--atora-brand-*`), **sin reemplazar** los tokens operativos (`--atora-blue-700`,
  `--atora-warning`, etc.) que `assets/admin/atora-admin.css` ya estableció y que la urgencia
  alta/media/baja de tres sprints de paneles ya usa en producción — remapear esa semántica a
  colores de marca hubiera sido una regresión visual real, algo que §0.5 prohíbe explícitamente.
- **Ubicación de los componentes**: `includes/ui/` (convención ya existente para elementos
  transversales de interfaz, confirmada por `class-ui-admin-builder.php` et al.), pero los
  assets JS/CSS compartidos viven en `assets/shared/`, no `includes/ui/assets/` — corrigiendo mi
  propia decisión inicial al notar que ya había establecido en 6.8.0 que `includes/` nunca sirve
  un archivo estático directamente en este plugin.
- **`AtoraUI.Panel` no hace fetch**: el componente compartido solo renderiza y delega acciones vía
  `onAction(actionId, itemId, scope)` — cada pantalla que lo abre decide qué REST llamar. Evita
  que el componente "sepa" de endpoints específicos, cumpliendo la regla de extracción sin
  acoplar capas que no deberían conocerse.
- **Retrofit real, no cosmético**: `followup-plans.js` dejó de construir el marcado de filas a
  mano (`renderStudents()` eliminado); el shell del panel en `followup-plans.php` pasó a ser una
  sola llamada a `CLMS_UI_Followup_Panel::render_shell()`. El asistente de 4 pasos
  (`#atora-fu-wizard`) se dejó **deliberadamente** sin tocar — es un formulario multi-paso, no
  una lista de personas con una acción, y forzarlo al shape del panel hubiera sido la clase de
  reescritura que §0.2 prohíbe.
- **Búsqueda académica y comercial reutilizan exactamente los mecanismos de alcance ya
  existentes**: `Section_Service::get_sections_by_teacher()`/`get_section_student_ids()` para
  estudiantes/secciones (mismo par de llamadas que `followup-plans.php` y `Teacher_Digest_Service`
  ya usan); `Contact_Service::search_contacts()` sin ninguna capa de permisos encima, porque ese
  método ya aplica `get_scope_user_ids()` internamente en cada llamada — agregar un filtro propio
  hubiera sido exactamente la "segunda capa de permisos" que la OT prohíbe.
- **Sin ficha dedicada de estudiante/sección**: verificado por grep exhaustivo, no existe en el
  plugin — los resultados de búsqueda y los ítems de "Actividad" académicos caen al hub de
  estudiantes en vez de una ficha puntual (el caso comercial, contacto, sí tiene una ficha real y
  la usa). Documentado en `docs/DEUDA-TECNICA.md`, no ocultado.
- **"Hoy" y "Actividad" nunca se mezclan**: comparten una página y una entrada de menú
  (`?view=actividad`), pero se calculan por separado — "Hoy" descarta ocurrencias con
  `uncontacted <= 0`, "Actividad" lee `mark_contacted()`'s propio registro — la exclusión mutua
  es estructural, no una regla de filtrado adicional que pueda desincronizarse.
- **Bug real encontrado y corregido durante el trabajo**: al escribir
  `Followup_Plan_Service::get_recent_contacts_for_user()`, una primera versión llamaba a
  `get_userdata()` incondicionalmente sobre el `user_id` de `atora_followup_contacts` — pero esa
  columna es un WP `user_id` real solo para ocurrencias académicas; para las comerciales (desde
  6.7.0) es un `contact_id` de `atora_contacts`. Habría devuelto un nombre vacío para todo
  "contacto marcado" comercial en el feed de Actividad. Corregido con una rama explícita por
  dominio antes de comitear.
- **Bug de CSS real encontrado y corregido**: un comentario de documentación que escribí en
  `followup-plans.css` contenía el substring literal `*/` (al concatenar fragmentos de nombres de
  clase como `.atora-ui-panel-*/.atora-ui-row-*`), cerrando el comentario CSS antes de tiempo y
  dejando varias líneas de prosa como contenido CSS inválido. Detectado por un conteo de
  aperturas/cierres de comentario tras la edición, no asumido correcto — corregido antes de seguir.

## 4. Verificación

### 4.1 Comandos de la OT

```
find . -name "*.php" -exec php -l {} \;
vendor/bin/phpcs --standard=WordPress includes/ui/ modules/crm-v2/
vendor/bin/phpunit
```

**NO EJECUTADOS** — no hay intérprete PHP disponible en este entorno, consistente con cada
sprint anterior. En su lugar:

- **Balance de llaves/paréntesis** sobre los 14 archivos PHP y 5 archivos CSS tocados este
  sprint: todos cuadran (incluida una re-verificación después de corregir el bug de comentario
  CSS descrito arriba).
- **`node --check`** sobre los 4 archivos JS tocados/nuevos (`followup-panel.js`, `search.js`,
  `today.js`, `followup-plans.js`): sintaxis válida en los cuatro.
- **`tests/6.9.0/run_static_checks.py`**: 14/14 PASS. Verificado significativo contra el tip
  exacto de 6.8.0 (la base de esta rama) — falla duro, ninguno de los archivos del componente
  compartido existe todavía ahí.

### 4.2 Matriz manual de la OT

| Escenario | Resultado del rastreo de código |
|---|---|
| Abrir panel desde calendario académico, comercial, "Hoy" y búsqueda | Calendario/"Hoy" abren `AtoraUI.Panel` (confirmado por grep de ambos call sites); búsqueda enlaza directo a la ficha/hub (comportamiento correcto para ese caso, no un panel — ver PT-4). **No ejecutado visualmente.** |
| Buscar un estudiante fuera del alcance del docente | `search_students()` solo consulta `WP_User_Query` con `include` acotado a los `student_ids` de las secciones del docente — un estudiante fuera de esas secciones nunca entra al conjunto de IDs consultado, no es un filtro post-consulta. **No ejecutado contra datos reales.** |
| Marcar contactado desde un resultado de búsqueda | Los resultados de búsqueda no tienen acción "marcar contactado" en sí (enlazan a la ficha/panel donde esa acción vive) — una vez marcado ahí, `get_due_occurrences_for_user()` deja de contarlo (sale de "Hoy") y `get_recent_contacts_for_user()` lo recoge (entra a "Actividad") en la siguiente carga de cada pantalla, sin caché de por medio. **No ejecutado.** |
| Estudiante recuperado tras intervención | `Activity_Service::get_recent_stage_improvements_for_user()` detecta la transición vía `atora_contact_activities` (que `move_followup()` ya escribe sin cambios) y la muestra con tono positivo (borde verde, mismo token `--atora-success`). **No ejecutado visualmente.** |
| Uso completo desde teléfono | CSS mobile-first en los tres archivos nuevos (search/tokens/followup-panel), 44px, sin scroll horizontal. **No verificado visualmente en un dispositivo — sin entorno WordPress en vivo disponible en esta sesión, mismo disclosure que 6.6.0-6.8.0.** |
| Retrofit de calendario 6.6.0/6.7.0 | Verificado por lectura de diff que el comportamiento (abrir, cerrar, marcar contactado, excluir, saltar, posponer, lote) permanece idéntico — mismas llamadas REST, mismos endpoints, solo el mecanismo de renderizado cambió. **No verificado visualmente contra el "antes" — no hay forma de tomar una captura de pantalla en este entorno.** |

### 4.3 Prueba de campo (requisito explícito de la OT)

> "un docente real usando la búsqueda para encontrar un estudiante puntual sin haber usado el
> sistema antes ese día, y revisando 'Actividad' al final."

**NO REALIZADA** — mismo motivo disclosed en cada reporte anterior de esta serie: no hay entorno
WordPress en vivo ni un docente disponible en esta sesión de trabajo.

**Recomendación:** de las cuatro pruebas de campo pendientes acumuladas (6.6.0, 6.7.0, 6.8.0,
6.9.0), esta es la más rápida de ejecutar en conjunto con las anteriores — la búsqueda y
"Actividad" son features menores en superficie de interacción que "Hoy" o los planes de
seguimiento, así que agregarlas a la misma sesión de staging no debería requerir tiempo
adicional significativo.

## 5. Regresión de seguridad

**Esta es la sección más crítica del sprint, dado que PT-2 (búsqueda) es explícitamente una
superficie nueva con datos potencialmente sensibles.** Verificado explícitamente: `search_students()`
nunca ejecuta ninguna consulta si `user_has_academic_access()` es falso; `WP_User_Query` se acota con
`include` a los `student_ids` de las secciones del docente, no una búsqueda global filtrada después;
`search_contacts()` reutiliza `Contact_Service::search_contacts()` sin ninguna capa de permisos
adicional, confiando en su `get_scope_user_ids()` interno ya auditado en sprints de seguridad
anteriores. Ningún endpoint nuevo (`clms/v1/search`) acepta parámetros que puedan alterar el
alcance — el único input del usuario es el término de búsqueda, sanitizado con
`sanitize_text_field()`. **No se ejecutó un escaneo de seguridad dedicado ni una prueba de
penetración manual contra el endpoint de búsqueda** — dado que es la primera superficie de
búsqueda cross-dominio de todo el proyecto, se recomienda explícitamente incluirla como
prioridad en la próxima revisión de seguridad general, no como un ítem más de la lista.

## 6. Compatibilidad hacia atrás (§0.5)

- Ningún cambio de esquema de base de datos — verificado, `modules/class-v5-installer.php` no
  fue tocado en absoluto este sprint.
- El calendario académico/comercial y "Hoy" mantienen las mismas llamadas REST, los mismos
  endpoints, el mismo comportamiento de negocio — solo cambió el mecanismo de renderizado del
  panel (verificado por lectura de diff, no por captura visual — ver §4.2).
- `Task_Service`, `Followup_Plan_Service`, `Teacher_Digest_Service`, `Deal_Service`,
  `Activity_Service`: ningún método de escritura existente fue modificado — solo se agregaron
  métodos de lectura puntuales nuevos, verificado por checks estáticos 11/12.
- El único elemento visible nuevo en pantallas ya existentes es el ícono de búsqueda — aditivo,
  no reemplaza ni oculta nada.

## 7. Pendiente / fuera de este cierre

- Prueba de campo con docente real (§4.3) — la cuarta pendiente acumulada de esta serie.
- `php -l`/`phpcs`/`phpunit` reales, en un entorno con PHP disponible.
- Verificación visual/manual en navegador y dispositivo móvil real — particularmente importante
  este sprint dado que el retrofit del panel no puede confirmarse visualmente idéntico sin ella.
- Auditoría de seguridad dedicada al endpoint de búsqueda (§5) — recomendada como prioridad, no
  solo como parte de una revisión general.
- Ver `docs/DEUDA-TECNICA.md`: ausencia de ficha de estudiante/sección (afecta búsqueda y
  Actividad), asistente de 4 pasos deliberadamente fuera del retrofit.
- **Fuera de alcance explícito de la OT**: atajo de teclado tipo `Cmd+K`, filtros/reportes
  avanzados en "Actividad", cualquier gamificación (rachas, insignias, puntos — decisión ya
  tomada en la conversación de origen), vista de coordinador (sigue dependiendo de 6.5.0).

## 8. Empaquetado

`FEATURE-REPORT-*.md` ya estaba excluido de `.distignore` desde 6.6.0 — no fue necesario ningún
cambio antes de construir el ZIP.

- **Archivo:** `dist/atora-lms-6.9.0.zip`
- **SHA-256:** `7b4fa914fc6a11c3a78876fd5f47e9ad88064bfe5c1b36f09cc8d5407258bb14`
- **Auditoría del contenido extraído:** sin archivos de desarrollo en la raíz del paquete;
  cadenas de versión consistentes en `6.9.0`; `assets/shared/` (tokens, panel, búsqueda) y los
  cuatro archivos PHP nuevos de `includes/ui/`/`includes/today/` presentes; escaneo de patrones
  de credenciales sin hallazgos reales — mismo match ya disclosed en 6.6.0-6.8.0 (variable de
  formulario JS existente, no una credencial embebida).
