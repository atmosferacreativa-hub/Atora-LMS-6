# FEATURE-REPORT-6.8.0.md

## ATORA LMS 6.8.0 — "Hoy": una sola pantalla de entrada, cruzando todo lo que ya existe

**Rama:** `feature/6.8.0-vista-hoy`, base `6.7.0`.
**Fecha:** 2026-08-24.

---

## 1. Resumen

Este sprint no construye ningún dato nuevo — cruza lo que 6.4.0 (digest docente), 6.6.0 (planes académicos), 6.7.0 (planes comerciales) y el CRM (tareas) ya producían, y responde una sola pregunta: qué necesita atención ahora, ordenado por urgencia real. Cero modelo de datos propio, cero cambio de comportamiento en ninguna pantalla existente — un agregador de solo lectura y una pantalla nueva que consume ese agregador.

## 2. Paquetes entregados

| Paquete | Contenido | Commit |
|---|---|---|
| PT-1 | `CLMS_Today_Aggregator_Service` — recolección por fuente (followup académico/comercial, digest docente, tareas) | `8d2a06b` |
| PT-2 | `sort_by_urgency()` — orden de 5 niveles mapeado a alta/media/baja | `5a3861a` |
| PT-3 | Página admin `atora-hoy`, shortcode `[atora_hoy]`, deep-link al panel de ocurrencia | `7f29b79` |
| PT-4 | "Hoy" como landing por defecto para docente/vendedor, admin sin cambios | `00bdf77` |
| PT-5 | Diferido explícitamente (coordinador) — solo documentación | `96dc821` |
| Tests | `tests/6.8.0/run_static_checks.py` (14 checks) | `a45a70f` |
| Release | Bump de versión + changelog/upgrade notice | `34efc0f` |

## 3. Decisiones autónomas registradas (§0.7)

- **Ubicación y convención de archivo**: se encontró `includes/dashboard/` como el precedente real para servicios transversales sin un módulo dueño único (`CLMS_Teacher_Dashboard_Data`, `CLMS_Teacher_Priority_Queue_Service`), registrados en el mismo loader declarativo (`get_module_groups()`). `Today_Aggregator_Service` sigue exactamente ese patrón — sin inventar un segundo mecanismo de bootstrap.
- **Detección de rol**: el codebase ya tenía DOS helpers de detección de rol distintos (`get_role_context_for_user()` en admin-menu, `get_frontend_role_context_for_user()` en frontend), ninguno responde exactamente "¿tiene este usuario datos del motor de planes de seguimiento en este dominio?". Se reutilizaron las capacidades exactas ya establecidas como canónicas para ESE problema específico por 6.6.0/6.7.0 (`can_access_academic_calendar()`'s lista, `clms_access_crm_view`), duplicadas en el agregador con el mismo criterio ya usado en `followup-plans.php`.
- **`get_due_occurrences_for_user()`** se agregó a `Followup_Plan_Service` (no a un archivo nuevo) porque la lógica que necesitaba reutilizar (`Followup_Plan_Resolver::resolve_recipients()` + `get_contacted_students()`) ya vivía ahí conceptualmente en el REST controller (`get_calendar_events()`), que no es reutilizable como lectura PHP porque está atado a `WP_REST_Request`.
- **`Teacher_Digest_Service`**: dos adiciones — un wrapper público puro de una línea (mismo patrón que `get_db_stats()` en 6.5.10) y una lectura genuinamente nueva (`get_stale_submission_count_for_teacher()`, umbral de 48h que el método existente no exponía). El método protegido original no se tocó.
- **`Task_Service` no se tocó en absoluto** — `list_tasks()` ya soportaba exactamente los filtros necesarios (`assigned_to`, `status`, `date_from`, `date_to`).
- **Mapeo de 5 niveles a 3 valores de urgencia**: tier 1-2 → 'alta', 3-4 → 'media', 5 → 'baja'. Verificado estructuralmente monotónico (check estático 07) — garantiza el criterio de aceptación de PT-2 por construcción, no por suerte en un caso de prueba puntual.
- **Deep-link real a la ocurrencia**: se descubrió que los enlaces `admin.php?page=atora-followup-plans&event_id=N` construidos desde 6.6.0 (puente de mensajería académica) nunca abrían el panel automáticamente — un gap real de 6.6.0, no introducido por este sprint, pero que PT-3.2 exigía cerrar para que "Hoy" fuera de verdad "un clic a la acción". Se agregó lectura de `?event_id=` en `followup-plans.js`.
- **Landing por rol**: se encontró y reutilizó el único mecanismo existente (`login_redirect` + `get_login_hub_url_for_user()`), separando el bucket 'admin'/'instructor' que antes compartían destino, para que el admin quedara explícitamente sin cambios (PT-4.2).
- **`atora-hoy.css`** vive en `assets/admin/` (no `includes/today/assets/`) — `includes/` nunca sirvió un asset estático directamente en todo este plugin; se prefirió no romper esa convención.

## 4. Verificación

### 4.1 Comandos de la OT

```
find . -name "*.php" -exec php -l {} \;
vendor/bin/phpcs --standard=WordPress includes/today/ includes/academic/class-teacher-digest-service.php
vendor/bin/phpunit
```

**NO EJECUTADOS** — no hay intérprete PHP disponible en este entorno, consistente con cada sprint anterior. En su lugar:

- **Balance de llaves/paréntesis** sobre los 7 archivos PHP tocados este sprint: todos cuadran.
- **`node --check`** sobre `followup-plans.js` (Node sí disponible): sintaxis válida.
- **`tests/6.8.0/run_static_checks.py`**: 14/14 PASS. Verificado significativo corriendo el mismo script contra el worktree del tip exacto de 6.7.0 (la base de esta rama): falla duro — los archivos del agregador no existen todavía, la entrada del loader no está.

### 4.2 Matriz manual de la OT

| Escenario | Resultado del rastreo de código |
|---|---|
| Docente con estudiantes en riesgo y entregas sin calificar | `collect_followup_items()` (tier 1/2 si vencido/riesgo) y `collect_academic_digest_items()` (tier 3) construyen items independientes; `sort_by_urgency()` los mezcla correctamente por tier. **No ejecutado contra datos reales.** |
| Vendedor con deals estancados | `collect_followup_items()` no distingue dominio para la consulta (scopeada por `user_id` en la tabla); sus tareas vencidas aparecen vía `collect_task_items()`. **No ejecutado.** |
| Rol mixto docente + vendedor | `get_today()` no separa por dominio en ningún punto — una sola lista, un solo `sort_by_urgency()`. **No ejecutado.** |
| Día sin nada pendiente | `render_items_html()` devuelve el bloque de estado vacío positivo cuando `$items` está vacío — verificado por check estático 09. **No ejecutado visualmente.** |
| Clic en un ítem de riesgo académico | URL construida como `admin.php?page=atora-followup-plans&event_id=N`; `followup-plans.js` ahora lee ese parámetro y llama a `openPanel()` en el bootstrap — verificado por check estático 11. **No ejecutado en navegador.** |
| Digest diario de WhatsApp/email | `button_url` cambiado a `atora-hoy`; verificado que la plantilla aprobada usa la URL como variable posicional, no texto fijo. **No ejecutado un envío real.** |
| Admin entra al panel | `get_login_hub_url_for_user()` mantiene el bloque `'admin' === $role` intacto, apuntando a `clms-dashboard` — separado explícitamente del bloque de instructor/vendedor (check estático 12). **No ejecutado un login real.** |
| Uso desde teléfono | CSS mobile-first, sin JS para el contenido principal (server-rendered, carga rápida por diseño), reutiliza los mismos tokens `--atora-*` que 6.6.0/6.7.0. **No verificado visualmente en un dispositivo — sin entorno WordPress en vivo disponible en esta sesión.** |

### 4.3 Prueba de campo (requisito explícito de la OT)

> "un docente real con datos reales de riesgo y entregas pendientes, abriendo 'Hoy' por primera vez sin instrucciones. Si no entiende de inmediato qué hacer primero, el ordenamiento o el copy se ajustan antes de cerrar el sprint."

**NO REALIZADA** — mismo motivo disclosed en los reportes de 6.6.0 y 6.7.0: no hay entorno WordPress en vivo ni un docente disponible en esta sesión de trabajo.

**Recomendación:** dado que "Hoy" ahora es la landing por defecto para docentes/vendedores, esta prueba de campo es más urgente que las pendientes de 6.6.0/6.7.0 — un ordenamiento o copy que no se entienda de inmediato afecta a TODO docente/vendedor que inicie sesión, no solo a quien decide visitar una pantalla opcional. Priorizar esta validación en staging antes de considerar el sprint cerrado en producción.

## 5. Regresión de seguridad

Ningún archivo de este sprint toca las superficies endurecidas en 6.5.x. El agregador es estrictamente de lectura (verificado por check estático 03 — ningún método de escritura de ningún servicio consultado aparece en el código); la página admin usa cap `'read'` (igual que "Panel", el resto de páginas visibles del menú principal) y no expone ningún dato que el usuario no pudiera ya ver navegando a las pantallas fuente correspondientes — "Hoy" no amplía superficie de acceso, solo la reorganiza. **No se ejecutó un escaneo de seguridad dedicado** — recomendado incluir en la próxima revisión general.

## 6. Compatibilidad hacia atrás (§0.5)

- Ninguna tabla ni columna nueva — verificado por check estático 14 (el instalador de esquema no fue tocado).
- `Followup_Plan_Service`, `Teacher_Digest_Service`, `Task_Service`, `Deal_Service`: ninguno cambió su comportamiento existente — solo se agregaron métodos de lectura nuevos (o wrappers) sin tocar cuerpos de métodos ya en producción, verificado por checks 04/05/06.
- El panel docente, el CRM y los calendarios de 6.6.0/6.7.0 siguen accesibles exactamente igual — "Hoy" se suma como entrada de menú visible ("☀️ Hoy") y como landing por rol, sin remover ni ocultar nada existente.
- El único cambio de comportamiento observable para un usuario existente es el destino de aterrizaje post-login para docentes/vendedores (intencional, PT-4.1) y el destino del botón del digest diario (intencional, PT-3.4) — ambos explícitamente pedidos por la OT, no efectos secundarios.

## 7. Pendiente / fuera de este cierre

- Prueba de campo con docente real (§4.3) — más urgente que las de sprints anteriores dado que "Hoy" ahora es landing por defecto.
- `php -l`/`phpcs`/`phpunit` reales, en un entorno con PHP disponible.
- Verificación visual/manual en navegador y dispositivo móvil real.
- Auditoría de seguridad dedicada.
- Ver `docs/DEUDA-TECNICA.md`: vista de coordinador diferida (depende de 6.5.0, nunca construido — estructura ya preparada para esa tercera fuente), digest de inactividad/quiz sin distinción de antigüedad (solo grading la tiene), enlace de tarea sin contacto cayendo a un hub general en vez de una vista de detalle que no existe todavía.
- **Fuera de alcance explícito de la OT**: resumen semanal/ritual de hábito, micro-momentos de logro y racha de cumplimiento, vista del estudiante — todos mencionados como sprints futuros separados, ninguno evaluado ni iniciado.

## 8. Empaquetado

`FEATURE-REPORT-*.md` ya estaba excluido de `.distignore` desde 6.6.0 — no fue necesario ningún cambio antes de construir el ZIP.
