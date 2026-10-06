# Informe de avance — 2026-10-05 · camino a la Fase 4 (Docente)

Para consultar y decidir lo que sigue. Histórico del cierre en [ORDEN-CIERRE-0.6.1.md](ORDEN-CIERRE-0.6.1.md).

## 1. Dónde estamos

| | Versión | Estado |
|---|---|---|
| Plugin | **6.30.1** | Borrador de calificación ya no llega al estudiante. PR #59 (ver §4 para CI, etiqueta y ZIP) |
| App | **0.6.0** | Sin cambios desde la Fase 3. 0.6.1 (pruebas de pantalla en CI) pendiente |
| Fase 4 | — | Paso 0 hecho (solo lectura). Decisiones del titular tomadas. Sin código todavía |

## 2. Paso 0 de la Fase 4: respuestas

1. **`handle_speedgrade_save()` no se puede llamar sin formulario**: lee `$_POST` y exige el nonce de la pantalla. Hay que extraer `ATORA_Grading_Save_Service`.
2. **Un guardado de SpeedGrader hace:** valida cada criterio entre 0 y el máximo, con 2 decimales como máximo (acepta coma), y la nota final entera de 0 a 100 → aplica moderación (bloquea la publicación directa en ciclos institucionales; `lock_version` propio) → pone el estado (`save_draft` = `in_review`, `publish` = `graded`) → `CLMS_Assessment_Engine::publish_submission_grade()`: meta, auditoría, borrado de caché y recálculo de la nota del curso, y hooks → fila inmutable en `atora_rubric_evaluations` (puntos en entero, decimales en `snapshot_json`).
3. **Entregas grupales:** una entrega "maestra" por grupo y lección (`_clms_submission_group_master=1`, sin dueño, `submitted_by`) y una copia por integrante. Al calificar la maestra, la nota pasa a las copias con `clms_group_grade_overrides`.
4. **early-warning:** un solo tipo de alerta, `missed_submission` (gravedad de 50 a 100), en la tabla `atora_early_warning`; REST `atora/v1/early-warning?course_id` con `user_can_manage_lms(curso)`. La tabla no guarda institución.

## 3. Decisiones del titular (2026-10-05)

1. **Borrador filtrado** → en 6.30.1. **Hecho.**
2. **`ATORA_Teacher_Scope`**, una sola regla para la web y la app:
   - puede calificar quien esté asignado a la sección, sea autor del curso o la lección, pueda editar lo ajeno o tenga delegación vigente;
   - el administrador queda limitado a su institución, que se resuelve desde el curso.
   - Antes de fusionar, un script de solo lectura con quién pierde acceso: si la lista no está vacía, va primero al titular. → 6.31.0.
3. **Concurrencia:** meta `_clms_submission_grade_revision`; la app recibe 409 y SpeedGrader web avisa "Otro docente guardó esta entrega; recarga para ver su versión". → 6.31.0.
4. **Servicio de guardado:** diseño aprobado. Primero la extracción pura en su propio commit, con las pruebas actuales sin modificar; los cambios de comportamiento van en commits aparte.

## 4. Hecho en 6.30.1

- Hook `clms_submission_grade_draft_saved`; una sola regla (`CLMS_Student_Grade_Visibility::grade_saved_hook()`) decide para los 4 emisores.
- **Listeners de `clms_submission_graded`:**
  - Reaccionan también al borrador: grupos, auditoría del libro de notas y 3 cachés (ruta de aprendizaje, `CLMS_Grading` y progreso).
  - Solo a la publicación: avisos, mensajes, libro de notas, memoria del estudiante, analítica, gamificación, retroalimentación, quizzes, bloqueo de escala, certificados y puente académico.
- Las copias grupales heredan el estado y los puntajes por criterio, con los ajustes individuales aplicados.
- Prueba `GradeDraftNoLeakTest`: falla con 6.30.0 y pasa con 6.30.1.
- Script `scripts/audit-draft-grade-leaks.php`: en local da 0 en los tres apartados, y la detección se comprobó con una fuga simulada.
- **CI / etiqueta / ZIP:** _(se completa al cerrar el PR #59)_

## 5. Lo que viene: propuesta y decisiones abiertas

### 5.1 App 0.6.1: pruebas de pantalla en CI (requisito de la Fase 4)

Propuesta:
- **Maestro** en un emulador Android de GitHub Actions (Ubuntu con KVM), con capturas de cada recorrido guardadas como artefacto de la ejecución.
- Recorridos iniciales: `login`, `estudiante-hoy`, `aprender`, `rendir`, `mensajes` y `agenda`.
- **Decisiones necesarias:**
  - **a) Contra qué servidor:**
    - Opción 1: el demo (`demo.atora.studio`), con cuentas de prueba fijas. Es lo más simple, pero depende de los datos del demo.
    - Opción 2: un WordPress efímero dentro del CI con datos sembrados. Es más estable, pero cada ejecución tarda más.
  - **b) De dónde sale la APK de prueba:**
    - Opción 1: compilarla en el CI con `expo prebuild` y Gradle (unos 15-25 min).
    - Opción 2: descargar la última APK de vista previa de EAS.
- Recomendación: demo con cuentas fijas y APK compilada en el CI. Al demo se le puede sembrar lo que haga falta (curso, tarea con PDF, rúbrica, grupo).

### 5.2 Plugin 6.31.0: orden de trabajo propuesto (un PR, commits separados)

1. Extracción pura de `ATORA_Grading_Save_Service`, con las pruebas actuales de SpeedGrader sin tocar.
2. `ATORA_Teacher_Scope` y el script de pérdida de acceso. **Se para aquí si la lista no está vacía.**
3. Revisión y 409, en la web y en la API.
4. Historial de entregas web en `atora_assignment_submissions`, con migración idempotente al intento 1.
5. Intentos en SpeedGrader: lista, visor y calificar el intento elegido.
6. Tareas grupales en la API del estudiante: su grupo, quién entregó y si puede entregar.
7. Rutas `/teacher/*` (today, courses, students, submissions, grade, announcements), con no-caché, capacidades en `/discovery` y 503 ante error de base de datos.
8. Pruebas de B3; `MOBILE-API-V1.md`, changelog, etiqueta y ZIP.

**Pregunta:** ¿empezamos la 6.31.0 del plugin en paralelo a la 0.6.1? El plugin no depende de las pruebas de pantalla; las apps 0.7.0 y 0.8.0 sí.

### 5.3 Riesgos y diferencias con la orden de la Fase 4

- **Nota final entera.** Hoy la nota final es entera de 0 a 100 (`docs/ESCALA-NOTAS.md`) y solo los criterios llevan decimales. "Decimales conservados" se entiende como los decimales por criterio. Si se quiere una nota final con decimales, hay que decidirlo aparte porque afecta los promedios.
- **"Nivel de riesgo" por estudiante.** `early-warning` solo detecta entregas vencidas. Propuesta: combinarlo con el riesgo que ya calcula el resumen de notas (`get_course_risk_level`) y mostrar las dos fuentes en la ficha.
- **Secciones e institución.** `atora_sections` no guarda institución; con la decisión 2, la institución sale del curso. En una instalación con varias instituciones, falta comprobar que cada curso tiene la suya.
- **Volumen de la migración de entregas web:** se medirá en local y se informará antes de correrla en el demo.

### 5.4 Pendientes menores

- **Tres listeners que leen mal los argumentos del hook** (anterior a 6.30.1): `CLMS_Dashboard`, `CLMS_Assessment_Engine::invalidate_cache_from_submission()` y `CLMS_Analytics::record_submission_graded()`. ¿Se corrigen en la 6.31.0?
- **Correr en el demo** `scripts/audit-draft-grade-leaks.php` (requiere acceso del titular al servidor) y decidir qué hacer si aparecen casos en B o C.
- **`RubricsCliMigrationTest`** falla en el entorno local tanto en `main` como en 6.30.1; en el CI limpio debería pasar. Revisar si se repite.
