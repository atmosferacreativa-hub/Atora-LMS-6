# FEATURE-REPORT-6.10.0.md

## ATORA LMS 6.10.0 — Insignias individuales de estudiante, retiro del leaderboard

**Rama:** `feature/6.10.0-insignias-estudiante`, base `6.9.1`.
**Fecha:** 2026-08-25.

---

## 1. Resumen

Sprint derivado directamente de una decisión de producto tomada sobre un hallazgo del
inventario de deudas técnicas (revisión post-6.7.0): el ranking público de estudiantes
(`[atora_leaderboard]`) se elimina por completo — competía con la filosofía de "seguimiento,
no competencia" ya establecida en el CRM académico — y se reemplaza por un sistema de cinco
insignias individuales y NO comparativas (sobresaliente/destacado/aplicado/regular/en atención),
calculadas por curso a partir de puntualidad, promedio e interacción, mostradas directamente en
el panel de estudiante ya existente, sin shortcode ni pantalla nueva.

## 2. Paquetes entregados

| Paquete | Contenido | Commit |
|---|---|---|
| PT-1 | Retiro completo del leaderboard (shortcode, requires, tool MCP) | `e467df2` |
| PT-2 | `CLMS_Student_Badge_Service` — 5 insignias por curso, integradas al panel de estudiante | `0b2405c` |
| Tests | `tests/6.10.0/run_static_checks.py` (11 checks) | `3a6a323` |
| Release | Bump de versión + changelog/upgrade notice | `5485d1a` |

## 3. Decisiones autónomas registradas

- **Qué se elimina y qué no**: el usuario pidió "desaparecer el sistema de rankings" —
  interpretado estrictamente como el leaderboard comparativo público (`[atora_leaderboard]`, el
  tool MCP `get_leaderboard`). El panel de puntos/nivel personal que el estudiante ya veía en su
  propio dashboard (`build_gamification_panel()`) **no es comparativo** — nunca compara a un
  estudiante contra otro — así que se dejó intacto, junto con todo `CLMS_Gamification_Core`/
  `CLMS_Gamification_Rules`/`CLMS_Gamification_Summary_Service`.
- **Los tres ejes se derivan de datos reales ya trackeados, no de tracking nuevo**: promedio e
  interacción salen de `CLMS_Academic_Status_Service::get_student_course_status()` (el mismo
  objeto que el dashboard de estudiante ya calcula por curso); interacción se definió como
  lecciones completadas / total de lecciones (participación real, no una métrica inventada).
  Puntualidad es el único cálculo genuinamente nuevo (`get_on_time_rate_for_student()`), pero
  reutiliza EXACTAMENTE el criterio de "a tiempo" ya establecido en
  `CLMS_Academic_Report_Service::count_late_submissions_by_course()` (comparar `post_date_gmt`
  de la entrega contra `_clms_due_date` de la lección, hasta las 23:59:59), solo recortado a un
  estudiante en vez de agregado por curso.
- **Ejes sin datos se excluyen del puntaje, no se puntúan como cero**: un curso sin fechas límite
  nunca tendría puntualidad — ese eje se excluye y los pesos restantes se renormalizan, en vez de
  arrastrar el puntaje combinado hacia abajo por un eje que estructuralmente no aplica.
- **Sin ningún dato en ningún eje → "aplicado" (neutro), no "en atención"**: juzgar con cero
  información como si fuera bajo desempeño sería punitivo, no informativo — un estudiante recién
  inscrito no debería ver la insignia más baja el primer día.
- **Integración vía el filtro modular ya existente (`dashboard_course_cards`)**, sin shortcode
  — instrucción explícita del usuario ("nos evitamos el tema del shortcode"). Se agregó un solo
  campo aditivo (`course_id`) a `get_course_cards()` para que el filtro pueda correlacionar cada
  card con su curso sin depender de que el orden de arrays coincida — el resto de ese método no
  se tocó.
- **CSS reutiliza los tokens `--clms-*` ya definidos** en `assets/css/admin.css` (el sistema de
  tokens de ESTA vista específica, distinta de los `--atora-*` de 6.6.0-6.9.0 — el panel de
  estudiante nunca migró a esos, y forzar esa migración como efecto colateral de una insignia
  hubiera sido un cambio de alcance mucho mayor, no pedido). Ningún color hexadecimal nuevo.
- **Registro de módulos actualizado**: la descripción de `gamification` ya no menciona
  ranking/leaderboard y describe con precisión lo que el módulo hace hoy — cierra parcialmente el
  hallazgo de metadata imprecisa señalado en el inventario de deudas (§2 de esa auditoría).

## 4. Verificación

### 4.1 Comandos

```
find . -name "*.php" -exec php -l {} \;
vendor/bin/phpcs --standard=WordPress includes/gamification/ includes/dashboard/
vendor/bin/phpunit
```

**NO EJECUTADOS** — sin intérprete PHP en este entorno, mismo disclosure que toda sesión
anterior. En su lugar:

- **Balance de llaves/paréntesis** sobre los 9 archivos PHP y 1 CSS tocados: todos cuadran.
- **`tests/6.10.0/run_static_checks.py`**: 11/11 PASS. Verificado significativo contra el tip
  exacto de 6.9.1 — los checks de retiro del leaderboard fallan correctamente (todavía presente
  ahí) y los de insignias fallan duro (el servicio no existe).
- Durante la escritura del test se detectaron y corrigieron dos falsos positivos propios: la
  palabra "leaderboard" apareciendo en el docblock explicativo del nuevo servicio (no una consulta
  real), y "ranking" apareciendo en la descripción del módulo precisamente para aclarar que YA NO
  existe ("sin ranking comparativo"). Ambos corregidos ajustando el criterio de búsqueda del test,
  no el código de producto.

### 4.2 Matriz manual

| Escenario | Resultado del rastreo de código |
|---|---|
| `[atora_leaderboard]` embebido en una página existente | Deja de renderizar (shortcode ya no registrado) — disclosed explícitamente en el Upgrade Notice de `readme.txt`, no un efecto secundario silencioso. **No ejecutado.** |
| Estudiante con entregas a tiempo y buen promedio | `get_badge_for_course()` combina los 3 ejes con pesos iguales (1/3 cada uno, filtrable), mapea a "sobresaliente"/"destacado" según el puntaje ≥90/≥75. **No ejecutado con datos reales.** |
| Estudiante recién inscrito, sin entregas | Los 3 ejes devuelven `has_data: false` → insignia "aplicado" (neutro), verificado por check estático 08. **No ejecutado.** |
| Curso sin fechas límite en ninguna lección | `get_on_time_rate_for_student()` devuelve `null` (sin base para calcular) → el eje se excluye del puntaje combinado, los otros dos se renormalizan. **No ejecutado.** |
| Dos estudiantes del mismo curso | Cada uno recibe su insignia de forma completamente independiente — no hay ninguna consulta que los compare entre sí (verificado por check estático 05, ausencia de patrones ORDER BY/ranking). **No ejecutado.** |
| Panel de estudiante, vista general | La insignia aparece como un segundo `<span class="clms-sd-badge">` junto al badge de estado ya existente, mismo componente visual, tokens ya establecidos. **No verificado visualmente — sin entorno WordPress en vivo disponible en esta sesión, mismo disclosure que toda la serie 6.6.0-6.9.0.** |

### 4.3 Prueba de campo

Este sprint no tiene un requisito explícito de prueba de campo en su origen (no fue una OT
formal con esa sección) — pero, consistente con la honestidad de todo este trabajo, se deja
constancia: **no se verificó visualmente en un navegador ni con un estudiante real** que la
insignia se vea bien, que el texto sea claro, o que el criterio de puntuación se sienta justo en
la práctica. Dado que este feature toca directamente la experiencia emocional de un estudiante
("en atención" puede sentirse duro si el copy/posicionamiento no se cuida), se recomienda
explícitamente una revisión visual antes de considerar esto verdaderamente cerrado — más aún que
en sprints puramente operativos.

## 5. Regresión de seguridad

Ningún endpoint REST nuevo, ninguna superficie de escritura nueva — `CLMS_Student_Badge_Service`
es estrictamente de lectura, calculado a demanda dentro de una página ya protegida por
`is_user_logged_in()` (el dashboard de estudiante). El tool MCP `get_leaderboard` removido
reducía superficie, no la aumentaba. No se ejecutó un escaneo dedicado — no aplica dado que no
hay superficie nueva que auditar.

## 6. Compatibilidad hacia atrás

- **Cambio de comportamiento deliberado y disclosed**: `[atora_leaderboard]` deja de renderizar.
  Es el único.
- `get_course_cards()` gana un campo aditivo (`course_id`) — no cambia ningún campo existente.
- `get_student_course_status()` (método existente, muy consumido) no fue tocado — verificado por
  check estático 07.
- Ningún cambio de esquema de base de datos.

## 7. Pendiente / fuera de este cierre

- Verificación visual con un estudiante real (§4.3) — recomendada explícitamente antes de dar
  esto por cerrado en producción, dado el componente emocional de la insignia "en atención".
- `php -l`/`phpcs`/`phpunit` reales.
- Del inventario de deudas original que motivó este sprint: quedan pendientes el fix de
  `get_rubrics()` (ya cerrado en 6.9.1, previo a este sprint), y todo lo demás — `contacts-core`
  (6.5.0), reportes cruzados académico/comercial, vista de coordinador, barrido más amplio de
  estilos hardcodeados en otras vistas, duplicación de prefijos `clms_`/`atora_`, unificación
  `Task_Service`/motor de planes de seguimiento — ninguno de esos se tocó en este sprint,
  consistente con el alcance acordado (solo la decisión de gamificación).

## 8. Empaquetado

Ningún cambio a `.distignore` necesario — `FEATURE-REPORT-*.md` ya excluido desde 6.6.0.

- **Build**: `./scripts/build-dist.sh 6.10.0` → `dist/atora-lms-6.10.0.zip`.
- **Auditoría del ZIP extraído**: sin archivos de desarrollo en la raíz (sin `tests/`, `.git`,
  `dist/`, `docs/`, `scripts/`, ni `FEATURE-REPORT-*.md`); `Version:`/`ATORA_LMS_VERSION`/
  `Stable tag:` consistentes en `6.10.0`; `class-student-badge-service.php` presente en
  `includes/gamification/`; `class-atora-gamification-public.php` confirmado ausente; sin
  referencias residuales a `atora_leaderboard`/`ATORA_Gamification_Public`/`get_leaderboard`
  salvo el aviso disclosed en el Upgrade Notice y comentarios explicativos.
- **Escaneo de secretos**: sin llaves AWS/Stripe, sin bloques PEM; el único match de
  `password=` es un falso positivo preexistente (concatenación de querystring en JS del flujo
  de inscripción, no un secreto hardcodeado).
- **SHA-256**: `f7a7ce195a3e51d6521cb9a0b4ea49a2e997775b20f3b35c4a19ce69938df1d0`
