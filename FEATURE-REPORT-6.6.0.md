# FEATURE-REPORT-6.6.0.md

## ATORA LMS 6.6.0 — CRM académico: planes de seguimiento sobre el calendario del docente

**Rama:** `feature/6.6.0-planes-seguimiento`, base `6.5.9`+ (última build de seguridad verificada).
**Fecha:** 2026-08-24.

---

## 1. Resumen

Este sprint conecta dos piezas que ya existían por separado — el calendario académico
(`modules/calendar/`) y el tablero de seguimiento CRM (`Student_Followup_Service`) — con un
motor de "planes de seguimiento": el docente programa CUÁNDO revisar a sus estudiantes en
riesgo, y el sistema resuelve QUIÉN corresponde en ese momento, consultando el tablero en vivo,
nunca una lista congelada al crear el plan.

Principio central de la OT, verificado estructuralmente (no solo por convención): **marcar un
contacto como "hecho" nunca mueve la etapa de un estudiante en el tablero de seguimiento**. La
tabla que registra contactos (`atora_followup_contacts`) no tiene columna de etapa; el único
método capaz de cambiar una etapa (`Student_Followup_Service::move_followup()`) no es llamado
desde ningún archivo de este sprint — confirmado por grep y por el check estático 04.

## 2. Paquetes entregados

| Paquete | Contenido | Commit |
|---|---|---|
| PT-1 | Modelo de datos: `atora_followup_plans`, `atora_followup_occurrence_state`, `atora_followup_contacts`, columna `followup_plan_id` en `atora_calendar_events` | `23d135f` |
| PT-2 | `Followup_Recurrence::expand()` + `Followup_Plan_Resolver` (resolución dinámica, fusión de ocurrencias) | `e39f471` |
| PT-3 / PT-4 backend | `Followup_Plan_Service` (CRUD, plantillas, ocurrencias, contactos), `Followup_Plans_REST_Controller` | `ba4c5f7` |
| PT-5 | Notificación diaria al docente vía `CLMS_Academic_Messaging_Bridge` | `36d8f0b` |
| PT-4 frontend | Calendario mensual, asistente de 4 pasos, panel lateral (`followup-plans.php/.js/.css`), menú admin | `4e002da` |
| Tests | `tests/6.6.0/run_static_checks.py` (10 checks) | `f63d5fa` |
| Deuda técnica | 4 entradas nuevas en `docs/DEUDA-TECNICA.md` | `429ce5d` |
| Release | Bump de versión + changelog/upgrade notice | `574e0ab` |

## 3. Decisiones autónomas registradas (§0.7 de la OT)

- **`section_ids`/`stage_filter` como LONGTEXT-JSON**, no tabla pivote — siguiendo el patrón ya
  existente de `atora_sections.schedule_json` para configuración escalar por fila, reservando
  pivotes reales para joins genuinos (no es el caso aquí).
- **Cierre de período académico (PT-1.3)** reutiliza `atora_sections.status`/`end_date`
  (`STATUS_CLOSED`) — sin señal nueva. Un plan con varias secciones sigue activo mientras
  cualquiera de ellas siga abierta.
- **`recurrence_rule`**: no existía ningún parser previo en todo el árbol (confirmado por grep;
  la columna era write-only). Se construyó `Followup_Recurrence::expand()` desde cero, subconjunto
  pequeño con sabor RRULE — documentado como brecha llenada, no duplicación.
- **Registro de contacto**: no había un mecanismo de intervención por-estudiante reutilizable en
  `Student_Followup_Service` (su único write path por estudiante es `move_followup()`, exactamente
  lo que este sprint no debe tocar) — se creó `atora_followup_contacts`, sin columna de etapa.
- **Canal de notificación (PT-5.1)**: `modules/calendar/` no tiene ningún `Messaging_Router::send()`
  existente que extender — se usó `CLMS_Academic_Messaging_Bridge`, el punto de integración real
  de cada señal académica anterior.
- **Ubicación de la UI (PT-4.1)**: página admin oculta nueva (`atora-followup-plans`), no una
  extensión in-place del shortcode `[atora_calendar]` — para no arriesgar el código compartido
  que ese shortcode ya sirve a instalaciones que no adoptan planes (regla §0.5).
- **Colores**: no existe un token literal `--atora-gold` en `assets/admin/atora-admin.css` — se
  usó `--atora-warning`/`--atora-amber-500` como equivalente más cercano ya establecido.

## 4. Verificación

### 4.1 Comandos de la OT

```
php -l (recursivo sobre modules/calendar/, modules/crm-v2/)
phpcs --standard=WordPress modules/calendar/ modules/crm-v2/
vendor/bin/phpunit
```

**NO EJECUTADOS** — no hay intérprete PHP disponible en este entorno de trabajo, consistente con
cada sprint anterior de este proyecto (disclosed en 6.5.9 a 6.5.13). En su lugar:

- **Balance de llaves/paréntesis** por conteo, sobre los 9 archivos PHP nuevos/modificados de este
  sprint más los archivos ya existentes tocados: todos cuadran (braces/parens iguales). Los 5
  archivos del árbol con desbalance detectado por este método naive (`class-lms-migrator.php`,
  `uninstall.php`, tres archivos de test de sprints previos) **no pertenecen a este sprint** y no
  fueron tocados — son falsos positivos conocidos del conteo naive contra comentarios/strings con
  paréntesis sueltos, igual que en sprints anteriores.
- **`tests/6.6.0/run_static_checks.py`**: 10/10 PASS. Verificado significativo corriendo el mismo
  script contra un worktree de `main` (pre-6.6.0) — falla duro ahí porque los archivos ni existen,
  confirmando que los checks realmente dependen del código de este sprint y no son tautológicos.

### 4.2 Matriz manual de la OT

| Escenario | Resultado del rastreo de código |
|---|---|
| Aplicar "Chequeo semanal" a una sección con estudiantes en riesgo → vista previa correcta, ocurrencias generadas | `Followup_Plan_Resolver::resolve_for_definition()` + `Followup_Recurrence::expand_weekly()` trazados manualmente contra la plantilla — lógica correcta. **No ejecutado contra una base de datos real.** |
| Estudiante se recupera entre creación del plan y la ocurrencia → no aparece en esa ocurrencia | `resolve_for_definition()` consulta `Student_Followup_Service::get_board()` en cada llamada, sin caché — check estático 01 confirma ausencia de `transient`/`wp_cache`. Lógicamente correcto; **no ejecutado**. |
| Marcar contactado en el panel lateral → se registra el contacto, la etapa del estudiante no cambia | Verificado estructuralmente: `mark_contacted()` solo escribe en `atora_followup_contacts` (sin columna de etapa) y no referencia `move_followup()`/`Student_Followup_Service` en absoluto — check estático 04. **No ejecutado end-to-end.** |
| Arrastrar una ocurrencia a otro día → reprograma sin romper el resto de la serie | `reschedule_occurrence()` actualiza una sola fila por `id`, exige `followup_plan_id` no vacío; el resto de las filas de la serie, materializadas independientemente, no se tocan — check estático 06. **No ejecutado en un calendario real ni probado el drag del lado FullCalendar.** |
| Pausar un plan durante un receso → no genera ocurrencias, reanuda igual al reactivar | `pause_plan()`/`resume_plan()` trazados: pausar solo cambia `active=0`; reanudar vuelve a extender la ventana de ocurrencias sin tocar el historial (`atora_followup_contacts`/`atora_followup_occurrence_state` no se borran nunca). **No ejecutado.** |
| Dos planes coinciden en fecha y sección → un solo bloque, lista combinada | `Followup_Plan_Resolver::merge_resolutions()` deduplica por `user_id` — check estático 03. La fusión en la vista de calendario (agrupar por fecha+sección antes de llamar a merge) vive en `followup-plans.js`/`get_calendar_events()`; **no ejecutado en navegador**. |
| Ocurrencia sin destinatarios → sin notificación al docente | `on_followup_occurrences_due_today()` corta con `continue` antes de cualquier `Messaging_Router::send()` cuando `students` está vacío — check estático 09. **No ejecutado contra el cron real.** |
| Uso completo desde un teléfono real → sin scroll horizontal, ninguna acción frustrante | CSS mobile-first con objetivos de 44px, `overflow-x: hidden` en el wrap, sheets deslizables desde abajo en mobile. **No verificado visualmente en un dispositivo ni en un navegador — ningún entorno con WordPress corriendo disponible en esta sesión.** |

### 4.3 Prueba de campo (requisito explícito de la OT)

> "un docente real (no técnico si es posible) aplicando una plantilla y registrando dos contactos
> desde su teléfono, sin instrucciones previas. Si se traba en algún paso, ese paso se rediseña
> antes de dar el sprint por cerrado."

**NO REALIZADA.** No hay entorno WordPress en vivo ni un docente disponible en esta sesión de
trabajo. Esto es una limitación real del sprint, no un detalle menor — la OT la marca como
condición explícita antes de dar el sprint por cerrado, y esa condición no se cumple con este
informe. Se disclosea aquí honestamente en lugar de fabricar una validación que no ocurrió,
siguiendo la convención establecida en todos los sprints anteriores de este proyecto.

**Recomendación:** antes de considerar 6.6.0 verdaderamente cerrado en producción, correr esta
prueba de campo con al menos un docente real en un entorno de staging, y tratar cualquier paso
donde se trabe como un defecto de UX a corregir, no como retroalimentación opcional.

## 5. Regresión de seguridad

Ningún archivo tocado en este sprint pertenece a las superficies endurecidas en 6.5.x (2FA,
verificación de teléfono/Telegram, límites de intentos, IP confiable, ownership de recursos CRM).
El nuevo controlador REST (`Followup_Plans_REST_Controller`) sigue el mismo patrón de
`permission_callback` vía `CRM_REST_Controller::can_access()`/`can_manage()` que el resto de
`modules/crm-v2/rest/`, y cada acción de escritura sobre una ocurrencia (`require_owned_occurrence()`)
verifica que el evento pertenezca al docente autenticado antes de tocarlo. **No ejecutado un
escaneo de seguridad dedicado para este sprint** — se recomienda incluirlo en la próxima revisión
de seguridad general del proyecto en lugar de tratarlo como cerrado aquí.

## 6. Compatibilidad hacia atrás (§0.5)

- La migración solo agrega tablas nuevas y una columna `NULL`able — ninguna instalación pierde
  datos ni deja de arrancar (verificado por check estático 08 e inspección directa del ALTER).
- Un evento de calendario creado manualmente (`followup_plan_id IS NULL`) nunca es alcanzado por
  `skip_occurrence()`/`reschedule_occurrence()` — ambos exigen `followup_plan_id` no vacío antes
  de actuar (checks 05/06).
- El shortcode `[atora_calendar]` y el tablero de seguimiento existente no fueron modificados en
  absoluto — la UI de este sprint vive en una página admin nueva y separada.

## 7. Pendiente / fuera de este cierre

- Prueba de campo con docente real (§4.3) — bloqueante para considerar el sprint verdaderamente
  cerrado según el propio criterio de la OT.
- `php -l`/`phpcs`/`phpunit` reales, en un entorno con PHP disponible.
- Verificación visual/manual en navegador y dispositivo móvil real.
- Auditoría de seguridad dedicada a los nuevos endpoints REST.
- Ver `docs/DEUDA-TECNICA.md` para las brechas identificadas y explícitamente diferidas
  (formato de `recurrence_rule`, heurística `INTENSIFY_DAYS`, afordancia de "deshacer exclusión").

## 8. Empaquetado

Antes de construir el ZIP se agregó `FEATURE-REPORT-*.md` a `.distignore`, siguiendo la lección de
sprints anteriores (excluir el nombre del reporte antes de empaquetar, no después).

- **Archivo:** `dist/atora-lms-6.6.0.zip`
- **SHA-256:** `8a8eb53770c47e84d073cec450ea946baa07c50f5f8c357e20cb37e8183fecac`
- **Auditoría del contenido extraído:** sin archivos de desarrollo (`tests/`, `docs/`, `.git*`,
  `scripts/`, `.claude/`) en la raíz del paquete; cadenas de versión (`Version:`,
  `ATORA_LMS_VERSION`, `Stable tag`) consistentes en `6.6.0`; los 8 archivos nuevos/modificados de
  `followup-plans` presentes; escaneo de patrones de credenciales (claves AWS/Stripe, bloques
  PEM, asignaciones `password = "..."`) sin hallazgos reales — el único match fue una variable de
  formulario JS ya existente (`&password=`) de una función de envío de contraseña de acceso a
  matrícula, no una credencial embebida.
