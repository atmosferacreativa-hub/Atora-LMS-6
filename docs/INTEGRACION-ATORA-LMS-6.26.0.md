# Integración ATORA LMS 6.26.0

Fecha: **2026-09-12**  
Rama: `integration/6.26.0` (track: `origin/integration/6.26.0`)  
Base esperada: `1d6c70b1ece2321c482281ca026992142b82c23a`

---

## 1) Objetivo

Integrar en una sola línea de historia, sin pérdida:

1. `origin/main` (Gradebook institucional).
2. `origin/feat/speedgrader-2-moderation`.
3. `origin/feat/academic-library-mvp`.
4. `origin/feat/credential-engine-phase-1`.
5. `origin/feat/mobile-api-v1`.
6. Corrección canónica de matrículas legacy/tablas para Mobile API.
7. Consolidación de versión `6.26.0` y gates estáticos disponibles.

---

## 2) SHAs originales (referencias remotas)

- `origin/main`: `1d6c70b1ece2321c482281ca026992142b82c23a`
- `origin/integration/6.26.0` (inicio): `1d6c70b1ece2321c482281ca026992142b82c23a`
- `origin/feat/speedgrader-2-moderation`: `3bcd7557857d19f8e92b10b89f0ac038e94fef70`
- `origin/feat/academic-library-mvp`: `d5f0081fffe7c29e470bbc0f4ace403e0c94ac1c`
- `origin/feat/credential-engine-phase-1`: `8759867c5ac5bd2b8e59581d29aef96adc0164a6`
- `origin/feat/mobile-api-v1`: `5bad3e913aef97864c3a39cea5861bd8ba3bb5d6`

---

## 3) Merges realizados (sin squash)

1) SpeedGrader 2:

- Merge commit: `f21a136` (merge `origin/feat/speedgrader-2-moderation`)

2) Biblioteca académica:

- Merge commit: `15e446c` (merge `origin/feat/academic-library-mvp`)

3) Credenciales:

- Merge commit: `66e3d87` (merge `origin/feat/credential-engine-phase-1`)

4) Mobile API v1:

- Merge commit: `ff00e4c` (merge `origin/feat/mobile-api-v1`)

---

## 4) Conflictos encontrados y resolución

### Merge `origin/feat/academic-library-mvp`

Archivos con conflicto:

- `atora_lms.php`
- `modules/class-v5-installer.php`
- `readme.txt`
- `tests/Gradebook/InstitutionalGradebookSchemaTest.php`
- `tests/bootstrap.php`

Resolución aplicada:

- Se preservaron simultáneamente:
  - Gradebook institucional (base `main`),
  - SpeedGrader 2 (moderación),
  - Biblioteca académica (policy/service/rest + tablas + tests).
- Se estableció la versión efectiva del árbol a `6.24.0` en ese punto (luego se consolidó a `6.26.0`).
- Se mantuvieron las tablas de ambos componentes (no se reemplazó un bloque por otro).

---

## 5) Corrección de matrículas para Mobile API (legacy + tablas)

Problema atacado:

- El cliente móvil puede autenticar (login OK) pero ver lista de cursos vacía cuando:
  - las matrículas viven en `wp_usermeta` (legacy), o
  - el router F4 está en `legacy`, o
  - existen matrículas `completed` que no deben ocultarse.

Cambio principal:

- `includes/mobile/class-mobile-rest-controller.php` ahora construye un índice canónico de matrículas que:
  - incluye `active` y `completed` de tablas;
  - incorpora matrículas legacy vía `CLMS_Helper::get_user_enrolled_courses()` y mapea `wp_post_id → course_id` con `LMS_Course_Service::get_by_wp_post()`;
  - deduplica por `course_id`;
  - centraliza autorización de curso y la aplica a `course`, `lesson`, `quiz`, `submit_quiz` (vía `quiz_context`) y `complete_lesson`;
  - evita otorgar acceso por enumeración directa de IDs.

Pruebas agregadas:

- `tests/Rest/MobileEnrollmentCompatibilityTest.php` (stubs mínimos + cobertura de:
  - tablas `active`,
  - tablas `completed`,
  - legacy,
  - deduplicación,
  - denegación sin matrícula,
  - fallback por `wp_post_id`,
  - denegación en `lesson` y `complete_lesson` sin matrícula).

---

## 6) Tablas consolidadas (resumen)

Nuevas/clave por integración:

- SpeedGrader moderation:
  - `atora_grade_moderations`
- Biblioteca académica:
  - `atora_library_items`
  - `atora_library_versions`
  - `atora_library_links`
  - `atora_library_events`
- Credenciales:
  - `atora_credentials`
  - `atora_credential_revocations`
  - `atora_credential_events`

Nota:

- La versión del esquema (`modules/class-v5-installer.php`) se consolidó a `6.26.0-integrated-schema` para forzar `create_tables()` en upgrades (necesario para instalaciones que vengan de `6.25.0-credential-engine` y aún no tengan `atora_grade_moderations`).

---

## 7) Pruebas / gates ejecutados localmente

Ejecutadas:

- `bash scripts/security-gate.sh` → **PASS**.

No ejecutadas (limitación del entorno local):

- `composer validate`, `composer install`, `composer audit` (**composer no disponible**).
- PHPUnit completo / suites críticas (**php / phpunit no disponibles**).
- Lint PHP (`php -l`) (**php no disponible**).
- Smoke test WordPress / WP-CLI (**wp-cli no disponible**).

Acción requerida:

- Depender de GitHub Actions tras el push para ejecutar la matriz oficial.

---

## 8) Riesgos restantes

- Hasta que GitHub Actions corra, no hay confirmación de:
  - compatibilidad runtime real de WordPress,
  - ausencia de errores de sintaxis PHP,
  - regresiones en suites completas (seguridad, F4, smoke, etc.).

---

## 9) Rollback (rama `integration/6.26.0`)

Rollback recomendado por componente (sin reescritura de historia):

- Revertir merges en orden inverso con `git revert -m 1 <merge_sha>`:
  - Mobile: `ff00e4c`
  - Credenciales: `66e3d87`
  - Biblioteca: `15e446c`
  - SpeedGrader: `f21a136`

Notas:

- Revertir un merge no borra datos; sí revierte código. Si la rama ya fue desplegada en un entorno, evaluar el impacto de tablas existentes (no se deben borrar automáticamente).

---

## 10) Instalación en beta (pasos sugeridos)

1. Checkout: `integration/6.26.0`.
2. Ejecutar la matriz de CI (o esperar GitHub Actions tras push).
3. Activar plugin en un WordPress de staging con backup de DB.
4. Verificar:
   - endpoints móviles bajo `/wp-json/atora-mobile/v1`,
   - listados de cursos para usuarios legacy/tablas,
   - acceso a `lesson`, `quiz`, `complete_lesson` solo con matrícula.

