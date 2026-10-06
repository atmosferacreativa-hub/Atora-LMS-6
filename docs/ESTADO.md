# Estado de comprobación por función

Qué está comprobado y dónde. Se actualiza en cada versión del plugin o de la app.

- **Teléfono**: lo recorrió el titular en un teléfono real con la APK. Desde la Fase 4 solo se prueba en teléfono una vez, al cerrar la fase, con la lista de `atora-mobile/docs/PRUEBA-TELEFONO.md`.
- **Pantalla (CI)**: recorrido Maestro en emulador Android contra un WordPress temporal del CI (`wp atora seed-e2e`).
- **Integración**: PHPUnit con WordPress (`tests/integration`).
- **Unitaria**: PHPUnit sin WordPress o Jest en la app.

Última actualización: **plugin 6.31.0 · app 0.6.1**.

> No hay registro escrito de qué se recorrió en teléfono antes de esta tabla: la columna "Teléfono" queda "por confirmar" hasta la prueba al cierre de la Fase 4, que incluye la lista acumulada de las fases 0 a 3.

## App (estudiante)

| Función | Desde | Teléfono | Pantalla (CI) | Integración (plugin) | Unitaria |
|---|---|---|---|---|---|
| Iniciar y cerrar sesión, sesión por usuario | 0.1–0.3 | por confirmar | pendiente (`login`, 0.6.1) | sí | Jest |
| Cursos, currículo, lección con varios videos | 0.3–0.4.1 | por confirmar | pendiente (`leccion`, 0.6.1) | sí (`MobileAuditMultiVideoTest`) | Jest |
| Descargas y visor PDF / imágenes sin conexión | 0.4.0 | por confirmar | — | — | Jest |
| Posición del video y cola sin conexión | 0.4.0 | por confirmar | pendiente (`sin-conexion`, 0.6.1) | sí | Jest |
| Sincronización incremental (`/sync/changes`) | 0.4.0–0.5.3 | por confirmar | — | sí (`MobileSyncChangesTest`) | Jest |
| Entregas de tareas con cola sin conexión | 0.3.0 | por confirmar | pendiente (`tarea`, 0.6.1) | sí | Jest |
| Quiz con borrador local y entrega | 0.5.0–0.5.2 | por confirmar | pendiente (`quiz`, 0.6.1) | sí | Jest |
| Notas, rúbrica por criterio, Mi evolución, certificados | 0.5.0–0.5.3 | por confirmar | — | sí (`MobileGradesCertificatesTest`, `GradeZeroAverageTest`) | Jest |
| Mensajes y Avisos | 0.6.0 | por confirmar | pendiente (`mensajes`, 0.6.1) | sí (`InboxTest`, `MobileOrganizeTest`) | Jest |
| Agenda y Hoy del estudiante | 0.6.0 | por confirmar | — | sí (`MobileOrganizeTest`) | Jest |
| Notificaciones al teléfono (Expo) | 0.6.0 | por confirmar | — (requiere servicio externo) | sí (cola y token) | Jest |

## Plugin (web)

| Función | Desde | Integración | Notas |
|---|---|---|---|
| Un borrador de calificación no llega al estudiante; copias grupales heredan estado y rúbrica | 6.30.1 | `GradeDraftNoLeakTest` | |
| Listeners de `clms_submission_graded` con argumentos en orden | 6.30.2 | `GradedHookListenersTest` | Ver "Datos históricos" |
| Mensajes del docente en la web: hilos completos, mismo buzón y contador que la app | 6.30.2 | `TeacherWebMessagesTest` | |
| `wp atora seed-e2e` (datos de las pruebas de pantalla) | 6.30.2 | probado a mano en WordPress local; lo usa el CI de la app | Se niega sin `--yes` o `ATORA_E2E` |
| Servicio de guardado compartido (SpeedGrader y app) | 6.31.0 | `SpeedGraderRubricEvaluationTest`, `SpeedGraderDecimalScoreTest` (sin cambios), `TeacherApiTest` | |
| Alcance docente (`ATORA_Teacher_Scope`) | 6.31.0 | `TeacherScopeTest`, `TeacherApiTest` | Auditoría de acceso: local 0; demo con una institución → 0 por construcción (sin correr en el demo) |
| Conflicto entre docentes (revisión, 409) | 6.31.0 | `GradeRevisionConflictTest`, `TeacherApiTest` | |
| Riesgo con motivos | 6.31.0 | `StudentRiskServiceTest` | |
| Historial de entregas web y migración | 6.31.0 | `WebSubmissionHistoryTest` | Local: 1 entrega migrada; segunda pasada 0 |
| Intentos en SpeedGrader | 6.31.0 | `SpeedGraderAttemptsTest` | |
| Tareas grupales en la app (API) | 6.31.0 | `GroupAssignmentMobileTest` | |
| API del docente `/teacher/*` | 6.31.0 | `TeacherApiTest` | Pantallas: app 0.7.0 y 0.8.0 |

## Datos históricos

- **Analítica (`clms_analytics_event_log`, evento `submission_graded`)**: hasta 6.30.1 el evento guardaba lección, usuario, estado y nota cruzados (el listener leía los argumentos en otro orden). **Los eventos son fiables desde 6.30.2.** Los anteriores no se reescriben.
- **Caché del panel del estudiante y del libro de notas tras calificar**: hasta 6.30.1 el listener propio no borraba nada (otros listeners sí borraban la caché del libro de notas). Desde 6.30.2 se borra por la vía correcta. No deja datos persistentes erróneos.

## Auditorías y el demo

- El demo (`demo.atora.studio`) solo tiene cuentas de prueba: las auditorías de **borradores** (`scripts/audit-draft-grade-leaks.php`, 6.30.1) y de **notas en cero** (`scripts/audit-zero-grade-averages.php`, 6.29.5) **no aplican** al demo.
- Quedan para la **primera instalación con estudiantes reales**: correrlas **antes** de actualizarla.
