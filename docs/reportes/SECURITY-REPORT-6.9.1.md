# SECURITY REPORT — Atora LMS 6.9.1

## 1. Executive Summary

```
Version: 6.9.1
Base: 6.9.0
Branch: fix/6.9.1-rubrics-listing-scope
Status: SINGLE-FINDING PATCH, SELF-DISCOVERED
```

This is a standalone addendum, not part of the 6.5.5–6.5.9 external-audit closure
narrative documented in `SECURITY-AUDIT.md`. The finding came from a routine
post-6.7.0 technical-debt review (direct code reading, not a formal pentest),
was verified against the actual current codebase before any fix was written, and
was fixed the same session it was found.

## 2. Finding

| # | Área | Hallazgo | Archivo |
|---|------|----------|---------|
| PT-1 (MEDIUM) | Rúbricas | `GET /rubrics` (listado) permitía a cualquier instructor ver las rúbricas de TODOS los instructores, no solo las propias | `includes/rest/class-rest-extensions-controller.php` |

**Severidad:** MEDIUM, no HIGH — las rúbricas son estructura de evaluación
(criterios, puntaje máximo), no contienen datos de estudiantes ni información
personal. El impacto real es exposición de contenido pedagógico de otro
instructor, no un IDOR sobre datos sensibles.

## 3. Root cause

`get_rubrics()` **ya tenía** código de scoping por autor — no era un endpoint
completamente abierto, como una primera lectura superficial podría sugerir. El
bug estaba en el criterio del bypass: usaba `CLMS_Access::can_access_admin()`,
que solo verifica la capability `clms_access_admin`. Esa capability la tiene el
rol `instructor` **por defecto** (`includes/class-access.php`, bloque
`$instructor_caps`) — significa "puede entrar al panel ATORA", no "puede ver
contenido de otros instructores". El resultado: la condición `if (!$can_access_admin)`
nunca se activaba para un instructor normal, y el filtro por autor nunca se
aplicaba en la práctica.

Los endpoints hermanos del mismo archivo (`get_rubric()`, `update_rubric()`,
`delete_rubric()`, todos por ID individual) verifican propiedad real vía
`current_user_can_manage_post_resource()` — un criterio distinto y correcto.
Exactamente el patrón "un endpoint de la familia tiene el gate bueno, el
hermano no" que la serie de sprints de seguridad de este proyecto (6.5.x) ya
identificó como el tipo de bug más probable de pasarse por alto.

## 4. Fix

Reemplazado el criterio de bypass por `edit_others_lm_courses` OR
`manage_options` — el mismo criterio de "alcance amplio, ve contenido ajeno"
que el resto del LMS ya usa (`tests/LMS/LMSCourseVisibilityTest.php`,
`tests/LMS/LMSWpPostIdBindingTest.php`), no uno inventado para este parche.
Confirmado leyendo `class-access.php` directamente que el rol `instructor` no
tiene ninguna de las dos por defecto (`edit_others_lm_courses => false`
explícito en su bloque de capabilities) — el fix cierra el hallazgo realmente,
no solo cambia el nombre de la capability que falla de la misma manera.

## 5. Evidence gate

| Ítem | Estado |
|---|---|
| Archivo modificado | `includes/rest/class-rest-extensions-controller.php` — `get_rubrics()`, 8 líneas |
| Test | `tests/6.9.1/run_static_checks.py` (3 checks) |
| Verificación de sentido del test | Corrido contra el código pre-fix (`git stash`): 2 de 3 checks fallan exactamente como se esperaba (todavía llama a `can_access_admin()`, todavía no verifica `edit_others_lm_courses`) |
| `php -l` / `phpcs` / `phpunit` | NO EJECUTADOS — sin intérprete PHP en este entorno, mismo disclosure que toda sesión anterior |
| Regresión a otros endpoints de rúbricas | Ninguna — `get_rubric()`/`update_rubric()`/`delete_rubric()`/`create_rubric()` no fueron tocados |

## 6. Empaquetado

- **Archivo:** `dist/atora-lms-6.9.1.zip`
- **SHA-256:** `8af378d8a4e1eef7d05f847b4dbb3141e1957373399d43bef721a5d33ffacfcd`
- **Auditoría del contenido extraído:** sin archivos de desarrollo en la raíz;
  cadena de versión consistente en `6.9.1`; el fix (`edit_others_lm_courses`)
  presente en el `class-rest-extensions-controller.php` empaquetado; escaneo de
  patrones de credenciales sin hallazgos.

## 7. Residual risk

Ninguno nuevo introducido. El hallazgo en sí queda cerrado para el caso
`instructor`. No se auditó el resto de `class-rest-extensions-controller.php`
en profundidad más allá de las rúbricas — si aparece otro hallazgo del mismo
patrón en ese archivo (transcripciones, peer review, otros recursos por
post_id), es candidato a su propia revisión puntual, no asumido cubierto por
este parche.
