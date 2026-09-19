# Auditoría de integración — ATORA LMS (6.26 + Mobile API)

Fecha del análisis: **2026-09-12** (timezone local: `America/Caracas`)  
Modo: **solo análisis** (sin merges, sin commits, sin cambios fuera de este informe)

---

## 1) Resumen ejecutivo

**Hallazgos principales**

- **`main` hoy declara `6.22.0`**, pero **solo existen tags `v6.21.0` y `v6.21.1`**. La versión `6.22.0` está “en rama” (main) **sin tag publicado**.
- **No existe `6.26.0`** en este repositorio: no hay rama, tag, commit ni ZIP con `6.26.0` y tampoco aparece en el historial (`git log -S "6.26.0"` vacío).
- Los avances posteriores a `6.22.0` están **aislados en ramas remotas**:
  - `origin/feat/speedgrader-2-moderation` (declara `6.23.0`)
  - `origin/feat/academic-library-mvp` (declara `6.24.0`)
  - `origin/feat/credential-engine-phase-1` (declara `6.25.0` e incluye además “Academic Library”)
  - `origin/feat/mobile-api-v1` (Mobile API v1, mantiene `6.22.0`)
- El problema “**login OK pero cursos vacíos en móvil**” es consistente con el código actual de Mobile API v1: el endpoint `/courses` usa **solo tablas** (`LMS_Enrollment_Service::get_user_enrollments()` por defecto `status='active'`) y **no respeta el router** `LMS_Read_Router`/legacy (`CLMS_Helper::get_user_enrolled_courses()`).

**Recomendación**

- Integración propuesta como **`6.26.x`** (no `7.0.0`): todos los cambios identificados son **aditivos** (tablas nuevas, endpoints nuevos, servicios nuevos) y **no eliminan** contratos/almacenamiento legacy en el árbol actual.
- Base recomendada: **`origin/main` @ `1d6c70b1ece2321c482281ca026992142b82c23a`**.
- Veredicto global (con la evidencia disponible): **LISTO CON CONDICIONES** (condiciones: resolver compatibilidad legacy/tablas en Mobile API y consolidar versión+schema+tests antes de generar ZIP).

---

## 2) Estado real del repositorio

**Repositorio detectado**

- Ruta: `/Users/imac/Desktop/atora-lms`
- Remote: `origin` → `https://github.com/atmosferacreativa-hub/Atora-LMS-6.git`

**Rama activa / sincronización**

- Rama activa: `main`
- HEAD: `1d6c70b1ece2321c482281ca026992142b82c23a` (2026-09-10)
- Upstream: `origin/main`
- Ahead/behind: `0/0` (`git rev-list --left-right --count main...origin/main` → `0 0`)
- Working tree: **limpio** (sin archivos modificados ni nuevos antes de crear este informe)

**Ramas locales**

- Existen múltiples ramas locales **sin upstream** (p. ej. `feature/6.10.0-*`, `fix/6.5.*`, etc.). En esta auditoría se consideran “históricas/locales” porque no siguen una rama remota.

**Tags**

- Disponibles local y remotamente tras `git fetch --all --prune --tags`:
  - `v6.21.1`
  - `v6.21.0`

**Repositorio móvil**

- No existe carpeta hermana `atora-mobile` junto a este repo (no encontrada en el directorio padre de `/Users/imac/Desktop/atora-lms`).

---

## 3) Ubicación real de 6.26.0

**Resultado**

- `6.26.0` **no existe en Git**:
  - No aparece en el working tree: `rg "\\b6\\.26\\.0\\b"` sin resultados.
  - No aparece en ramas locales/remotas por nombre: `git branch -a | rg "6\\.26"` sin resultados.
  - No aparece en el historial: `git log --all -S "6.26.0"` vacío; `git log --all -G "Version:\\s*6\\.26"` vacío.
  - No existe tag remoto: `git ls-remote --tags origin | rg "6\\.2[2-6]"` sin resultados.
  - No existe ZIP en `dist/` con 6.26: solo `atora-lms-6.21.0.zip` y `atora-lms-6.21.1.zip`.

**Conclusión**

- “6.26.0” es, con la evidencia actual, **una versión objetivo** (de integración) pero **no un artefacto existente** en este repositorio.

---

## 4) Qué contiene `main`

`origin/main` (HEAD: `1d6c70b1...`, 2026-09-10) declara:

- Plugin header `Version:`: `6.22.0` (`atora_lms.php`)
- Constante `ATORA_LMS_VERSION`: `6.22.0` (`atora_lms.php`)
- `readme.txt` `Stable tag`: `6.22.0`
- Changelog en `readme.txt`: sección `= 6.22.0 =` (Gradebook institucional)

Contenido funcional destacable (según `readme.txt` y commits):

- **Gradebook institucional** (core académico institucional + API administrativa aislada bajo `/clms/v1/gradebook/institutional`).
- CI con “security gate”, suite PHPUnit, y smoke test de WordPress (ver `.github/workflows/ci.yml`).

---

## 5) Inventario de versiones (6.22 → 6.26) y relación

### Tabla de referencias (declaraciones de versión)

| Referencia | Rama/commit | Versión declarada | Estado | Fuente |
|---|---|---:|---|---|
| Plugin header `Version:` | `origin/main` (`1d6c70b`) | 6.22.0 | vigente en `main` | `atora_lms.php` |
| `ATORA_LMS_VERSION` | `origin/main` (`1d6c70b`) | 6.22.0 | vigente en `main` | `atora_lms.php` |
| `Stable tag` | `origin/main` (`1d6c70b`) | 6.22.0 | vigente en `main` | `readme.txt` |
| Plugin header `Version:` | `origin/feat/speedgrader-2-moderation` (`3bcd755`) | 6.23.0 | solo rama | `atora_lms.php` |
| `Stable tag` | `origin/feat/speedgrader-2-moderation` (`3bcd755`) | 6.23.0 | solo rama | `readme.txt` |
| Plugin header `Version:` | `origin/feat/academic-library-mvp` (`d5f0081`) | 6.24.0 | solo rama | `atora_lms.php` |
| `Stable tag` | `origin/feat/academic-library-mvp` (`d5f0081`) | 6.24.0 | solo rama | `readme.txt` |
| Plugin header `Version:` | `origin/feat/credential-engine-phase-1` (`8759867`) | 6.25.0 | solo rama | `atora_lms.php` |
| `Stable tag` | `origin/feat/credential-engine-phase-1` (`8759867`) | 6.25.0 | solo rama | `readme.txt` |
| Mobile API v1 | `origin/feat/mobile-api-v1` (`5bad3e9`) | 6.22.0 | solo rama | `atora_lms.php`, `docs/MOBILE-API-V1.md` |
| 6.26.0 | n/a | 6.26.0 | **no existe** | búsquedas (`rg`, `git log -S`, `git ls-remote`, `dist/`) |

### Relación entre 6.22, 6.23, 6.24, 6.25, 6.26

- `6.22.0` = estado de `main` actual.
- `6.23.0` (SpeedGrader 2 moderation) nace desde `origin/main` (merge-base = `origin/main`), **solo suma** funcionalidad.
- `6.24.0` (Academic Library MVP) nace desde `origin/main` (merge-base = `origin/main`), **solo suma** funcionalidad.
- `6.25.0` (Credential engine) nace desde `origin/main` (merge-base = `origin/main`) y **ya incluye** el set de “Academic Library” (se ve en los archivos `includes/library/*` y en el changelog).
- `6.26.0` no existe: por coherencia semántica sería la **integración** de (SpeedGrader moderation) + (Academic Library) + (Credential engine) + (Mobile API), con un **solo bump** de versión.

---

## 6) Mapa de ramas y dependencias (remoto)

Base común actual:

- `origin/main` → `1d6c70b1ece2321c482281ca026992142b82c23a` (declara 6.22.0)

Ramas clave y su relación con `origin/main` (ahead/behind):

- `origin/feat/mobile-api-v1` @ `5bad3e9...` → behind `0`, ahead `10`, merge-base = `origin/main`
- `origin/feat/speedgrader-2-moderation` @ `3bcd755...` → behind `0`, ahead `17`, merge-base = `origin/main`
- `origin/feat/academic-library-mvp` @ `d5f0081...` → behind `0`, ahead `19`, merge-base = `origin/main`
- `origin/feat/credential-engine-phase-1` @ `8759867...` → behind `0`, ahead `36`, merge-base = `origin/main`

Nota sobre `origin/feat/institutional-gradebook-core`:

- Tiene historia distinta (behind `1`, ahead `20` respecto a `origin/main`), pero el **snapshot es idéntico** a `origin/main` (`git diff origin/main..origin/feat/institutional-gradebook-core` vacío). Es consistente con un squash merge a `main`.

---

## 7) Inventario de funcionalidades por rama

### `origin/main` (6.22.0)

- Gradebook institucional (core + API administrativa aislada).
- CI y security gate configurados.

### `origin/feat/speedgrader-2-moderation` (6.23.0)

Cambios observables (diff vs `origin/main`):

- Nuevos servicios/políticas de moderación en SpeedGrader 2.
- Nueva tabla: `atora_grade_moderations` (ver `modules/class-v5-installer.php`).
- `uninstall.php` agrega `atora_grade_moderations`.
- Tests nuevos bajo `tests/SpeedGrade/*`.

### `origin/feat/academic-library-mvp` (6.24.0)

- Biblioteca académica MVP:
  - Tablas nuevas: `atora_library_items`, `atora_library_versions`, `atora_library_links`, `atora_library_events`.
- Nuevos servicios/política y controller REST en `includes/library/*`.
- `uninstall.php` agrega tablas de biblioteca.
- Tests nuevos bajo `tests/Library/*`.

### `origin/feat/credential-engine-phase-1` (6.25.0)

- Motor de credenciales institucionales:
  - Tablas nuevas: `atora_credentials`, `atora_credential_revocations`, `atora_credential_events`.
  - QR local (`assets/vendor/qrcodegen.js`, `assets/js/credential-qr.js`).
- Incluye además “Academic Library” (archivos `includes/library/*` y changelog `6.24.0` presentes).
- `uninstall.php` agrega tablas de credenciales y biblioteca.
- Tests nuevos bajo `tests/Credentials/*`.

### `origin/feat/mobile-api-v1` (Mobile API v1, aún 6.22.0)

- Endpoints REST bajo `atora-mobile/v1`:
  - discovery, login/refresh/logout, me, dashboard, courses, course, lesson, quiz submit, complete lesson.
- Tokens opacos con rotación y revocación:
  - Persistencia en `wp_usermeta` (`_atora_mobile_sessions_v1`) + transients para rate limiting.
- Extensión del motor de quiz para “safe read” y “mobile submit” (cambios en `includes/class-quiz.php`).

---

## 8) Matriz de archivos solapados (enfoque de integración)

Leyenda (comparado contra `origin/main`): `same` = sin cambios, `M` = modificado, `A` = agregado.

| Archivo | 6.23 (speedgrader) | 6.24 (library) | 6.25 (credentials) | móvil | 6.26 | Riesgo |
|---|---|---|---|---|---|---|
| `atora_lms.php` | M | M | M | M | n/a | Conflicto mecánico (bump versión + include Mobile) |
| `readme.txt` | M | M | M | same | n/a | Conflicto mecánico (Stable tag + Changelog final) |
| `README.md` | same | same | same | same | n/a | Bajo |
| `modules/class-v5-installer.php` | M | M | M | same | n/a | Conflicto semántico (SCHEMA_VERSION + nuevas tablas) |
| `includes/loader/trait-loader-module-groups.php` | M | M | M | same | n/a | Conflicto mecánico probable (inserciones en arrays) |
| `tests/bootstrap.php` | M | M | M | same | n/a | Mecánico (versión de bootstrap) |
| `uninstall.php` | M | M | M | same | n/a | Mecánico/semántico (lista de tablas) |
| `modules/lms/class-lms-course-service.php` | same | same | same | same | n/a | Bajo |
| `modules/lms/class-lms-enrollment-service.php` | same | same | same | same | n/a | Alto (por impacto en móvil: autorización/matrículas) |
| `includes/helper/trait-helper-academic.php` | same | same | same | same | n/a | Alto (es el “canónico” para legacy/tablas) |
| `includes/class-quiz.php` | same | same | same | M | n/a | Mecánico (aislado, pero sensible a autorización) |
| `includes/mobile/class-mobile-rest-controller.php` | same | same | same | A | n/a | Medio (nuevo, pero debe alinearse con router legacy/tablas) |
| `includes/mobile/class-mobile-token-service.php` | same | same | same | A | n/a | Medio (nuevo; seguridad y almacenamiento) |

---

## 9) Matriz de tablas y migraciones (sin ejecutar migraciones)

### Base (`origin/main` / 6.22.0)

- Gradebook institucional ya introduce su set de tablas (no enumeradas aquí completamente).
- `V5_Installer::SCHEMA_VERSION` en `modules/class-v5-installer.php` = `6.22.0-institutional-gradebook`.

### SpeedGrader moderation (`origin/feat/speedgrader-2-moderation` / 6.23.0)

- Tabla nueva: `{$wpdb->prefix}atora_grade_moderations`
- `uninstall.php` agrega: `atora_grade_moderations`
- `SCHEMA_VERSION` cambia a: `6.23.0-speedgrader-moderation`

### Academic Library (`origin/feat/academic-library-mvp` / 6.24.0)

- Tablas nuevas:
  - `atora_library_items`
  - `atora_library_versions`
  - `atora_library_links`
  - `atora_library_events`
- `uninstall.php` agrega las 4 tablas.
- `SCHEMA_VERSION` cambia a: `6.24.0-academic-library`

### Credential engine (`origin/feat/credential-engine-phase-1` / 6.25.0)

- Tablas nuevas:
  - `atora_credentials`
  - `atora_credential_revocations`
  - `atora_credential_events`
  - (incluye también las 4 de Academic Library)
- `uninstall.php` agrega tablas de credenciales y biblioteca.
- `SCHEMA_VERSION` cambia a: `6.25.0-credential-engine`

### Mobile API v1 (`origin/feat/mobile-api-v1`)

- **No agrega tablas**: usa `wp_usermeta` (`_atora_mobile_sessions_v1`) y transients (`atora_mobile_login_*`) para sesión/rate-limit.

**Riesgos de integración de esquema**

- `SCHEMA_VERSION` es **un único string global**: integrar múltiples features exige decidir un **valor final único** (p. ej. `6.26.0-integration-mobile`) y actualizar el test `tests/Gradebook/InstitutionalGradebookSchemaTest.php` en consecuencia.

---

## 10) Diagnóstico: “login OK pero cursos no aparecen en móvil”

**Evidencia en código (Mobile API v1)**

- `/courses` y `/dashboard` llaman:
  - `ATORA_Mobile_REST_Controller::prepare_enrollments()` → `\ATORA\LMS\LMS_Enrollment_Service::get_user_enrollments( $user_id )`
  - `LMS_Enrollment_Service::get_user_enrollments()` filtra por defecto `status = 'active'` y **solo lee `atora_enrollments`** (tablas).
- Autorización en `/courses/{id}`, `/lessons/{id}` y quiz:
  - Depende de `LMS_Enrollment_Service::get_enrollment()` (solo `status='active'` y solo tablas).

**Por qué falla en instalaciones legacy o mixtas**

- El “router” canónico para decidir fuente de lectura es `\ATORA\LMS\LMS_Read_Router` (`atora_lms_read_source`):
  - `CLMS_Helper::get_user_enrolled_courses()` respeta el router: si `tables` → usa tabla; si `legacy` → usa `wp_usermeta`.
- Mobile API v1 **no usa** `CLMS_Helper::get_user_enrolled_courses()` ni evalúa `LMS_Read_Router::source()`.
- Resultado típico:
  - Si `atora_lms_read_source = legacy` (o si la migración de matrículas a tablas no se completó), `atora_enrollments` puede estar vacío → móvil devuelve lista vacía.
  - Incluso en modo tablas, `get_user_enrollments()` por defecto excluye `completed` → cursos completados no aparecen.

---

## 11) Evaluación de la solución propuesta (sin aplicarla)

Propuesta evaluada:

- combinar matrículas `active` y `completed` de tablas;
- utilizar el helper canónico para obtener matrículas compatibles;
- convertir `wp_post_id` a `course_id` mediante `get_by_wp_post()`;
- deduplicar cursos;
- centralizar autorización compatible (tablas + legacy);
- conservar ownership;
- no permitir acceso por enumeración directa de IDs.

**Veredicto de la solución**: **correcta en dirección**, pero incompleta si no cubre el caso “mixto” (cursos en tablas pero matrículas legacy, o viceversa).

**Implementación final recomendada (diseño)**

- Crear un “lector canónico” para móvil que devuelva **course_ids (tabla)**:
  1) Obtener matrículas canónicas (respeta router):
     - usar `CLMS_Helper::get_user_enrolled_courses($user_id)` para obtener IDs legacy (WP course posts) cuando aplique.
  2) Mapear WP course post IDs → `atora_courses.id`:
     - `LMS_Course_Service::get_by_wp_post( $wp_post_id )` y extraer `id`.
  3) Si la fuente ya es tablas:
     - usar un query que incluya `status IN ('active','completed')` (o dos llamadas) para no ocultar cursos completados.
  4) Centralizar autorización para endpoints `/courses/{id}`, `/lessons/{id}`, quizzes:
     - `user_can_access_course_id($user_id, $course_id)` debe aceptar matrícula por tablas **o** por mapping desde legacy.
  5) Mantener defensa anti-enumeración:
     - la API debe seguir aceptando/retornando **solo `course_id` (tabla)**; nunca exponer `wp_post_id` en rutas.

---

## 12) Seguridad (Mobile API v1)

**Controles presentes (según `docs/MOBILE-API-V1.md` y código)**

- HTTPS obligatorio salvo `wp_get_environment_type() === 'local'` o `ATORA_DEV_MODE`.
- Tokens opacos:
  - Access TTL 15 minutos, Refresh TTL 30 días (`ATORA_Mobile_Token_Service`).
  - Hash de secretos almacenado en usermeta (`wp_hash()`), no secretos en claro.
  - Rotación: refresh revoca sesión y emite una nueva.
  - Revocación: por token o por sesión.
  - Máximo 5 sesiones activas por usuario.
- Rate limit de login:
  - 8 intentos / 15 minutos por combinación login + IP hash (`transients`).
- Ownership:
  - Endpoints de curso/lección exigen matrícula (aunque hoy solo por tablas).
- Sanitización:
  - Salida saneada en `prepare_user()`, `safe_course()`, `content_html` con `wp_kses_post`.

**Riesgos / gaps a considerar**

- El control de matrícula “solo tablas” es **más estricto** (bloquea legítimos legacy) pero al corregirse debe mantenerse la misma protección anti-IDOR.
- No hay evidencia de rate limiting en refresh ni en endpoints de lectura (posible hardening futuro, no necesariamente bloqueante).
- Los tokens incluyen `user_id` en claro (`user.session.secret`), pero siguen siendo opacos por el secreto aleatorio y el hash server-side; esto es aceptable si se mantienen TTLs y rotación.

---

## 13) Pruebas y CI (inventario; no ejecutado)

El workflow `.github/workflows/ci.yml` define, entre otros:

- Lint + Composer validate/install (matriz PHP 8.1–8.4).
- `security-gate`:
  - `composer audit --locked`
  - `bash scripts/security-gate.sh`
  - suite de regresión de seguridad/autorización por PHPUnit.
- `lms-migrator-tests` con gates F4 (`ReadRouterTest`, `CutoverReadyGateTest`, etc.).
- `wordpress-smoke` contra WordPress 6.4 y 6.8 (instala WP en `/tmp/wordpress` y activa plugin).

**Nota**: No se declara que ninguna prueba haya pasado en este análisis porque no se ejecutaron.

---

## 14) Recomendación 6.26.x vs 7.0.0

**Recomendar `6.26.x`**

- Los cambios detectados son aditivos: nuevas tablas, nuevos endpoints, nuevos servicios.
- No se observa eliminación del storage legacy ni ruptura deliberada de contratos públicos en `main`.
- La Mobile API es v1 inicial y puede integrarse manteniendo compatibilidad.

**No recomendar `7.0.0` con la evidencia actual**

- No hay evidencia de ruptura real (remoción de endpoints/hooks, cambio irreversible de Cutover F4, eliminación de legacy).

---

## 15) Plan exacto de integración (propuesto; no ejecutado)

### Base

- Crear rama de integración desde: `origin/main` @ `1d6c70b1ece2321c482281ca026992142b82c23a`.

### Orden recomendado (por riesgo y dependencia)

1) Integrar **Mobile API v1** (como feature aislada) y **corregir compatibilidad legacy/tablas** antes de unir más schema.
2) Integrar **SpeedGrader moderation** (agrega tabla + servicios; impacto en gradebook).
3) Integrar **Academic Library MVP** (tablas + REST).
4) Integrar **Credential engine** (incluye library; si se integra library antes, resolver duplicación/identidad de archivos).
5) Consolidar:
   - bump final de versión (objetivo `6.26.0` o `6.26.1` según política interna),
   - `Stable tag`,
   - `SCHEMA_VERSION`,
   - test de schema (`InstitutionalGradebookSchemaTest`),
   - lista de tablas en `uninstall.php`.

### Estrategia sugerida (merge vs cherry-pick)

- Mobile API v1: **cherry-pick o merge** (ambas viables; es autocontenida pero toca `atora_lms.php` y `includes/class-quiz.php`).
- SpeedGrader / Library / Credentials: preferible **merge por componente** o PR squash por componente, pero dejando la consolidación de versión/schema para un commit final controlado.

### Gates / rollback

- Gate 1 (pre-merge): revisión de autorización móvil (anti-IDOR) + compatibilidad legacy/tablas.
- Gate 2 (post-merge): tests `lms-migrator-tests` + suite de seguridad.
- Rollback seguro: revert por componente (si se integró por merges/PRs separados).

---

## 16) Orden de commits recomendado (macro)

1) Commit A: Mobile API v1 + autorización canónica (legacy/tablas) + listar active/completed.
2) Commit B: SpeedGrader moderation (schema + loader + tests).
3) Commit C: Academic Library MVP (schema + loader + tests).
4) Commit D: Credential engine phase 1 (schema + loader + tests + assets).
5) Commit E: Consolidación release (versión 6.26.x, stable tag, SCHEMA_VERSION final, documentación).

---

## 17) Archivos que probablemente requieran resolución manual

- `atora_lms.php` (bump versión + registro de Mobile API).
- `readme.txt` (Stable tag + Changelog combinado).
- `modules/class-v5-installer.php` (SCHEMA_VERSION único + dbDelta de nuevas tablas).
- `uninstall.php` (unión de tablas a borrar).
- `includes/loader/trait-loader-module-groups.php` (unión de servicios/REST controllers).
- `tests/bootstrap.php` y `tests/Gradebook/InstitutionalGradebookSchemaTest.php` (versión/schema final).

---

## 18) Pruebas obligatorias antes del ZIP (lista mínima)

- `scripts/security-gate.sh` (version consistency + checks de repo).
- `vendor/bin/phpunit`:
  - `tests/Security` y lista de regresión de autorización (como en CI).
  - `tests/LMS/*` gates de Cutover F4.
  - suites nuevas: `tests/SpeedGrade/*`, `tests/Library/*`, `tests/Credentials/*`, `tests/Rest/MobileTokenServiceTest.php`.
- `wordpress-smoke` (instalación WP limpia + activación plugin).
- Verificación del ZIP con `.distignore` (sin `.git`, sin `vendor`, sin carpetas de desarrollo).

---

## 19) Información o archivos faltantes

- No hay `atora-mobile` repo disponible localmente para validar contrato cliente ↔ API.
- No existe artefacto/tag/ZIP de `6.26.0` para comparar contra una “versión real” histórica.
- Para cerrar el diagnóstico móvil con certeza absoluta faltan datos de entorno:
  - valor real de `atora_lms_read_source` en producción,
  - si `atora_courses` está poblada (migración de cursos),
  - dónde viven las matrículas legacy (meta keys y forma exacta) en la instancia afectada.

---

## Veredicto final

**LISTO CON CONDICIONES**

Condiciones bloqueantes antes de declarar “LISTO”:

1) Ajustar Mobile API para leer matrículas de manera canónica (legacy/tablas) y listar también `completed`.
2) Consolidar versión (`6.26.x`), `Stable tag`, `SCHEMA_VERSION` y tests asociados en un único cambio de release.
3) Ejecutar gates de CI críticos (seguridad + migrator + smoke WP) en la rama de integración.

