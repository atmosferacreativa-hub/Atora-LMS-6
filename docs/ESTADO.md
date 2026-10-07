# Estado de comprobación por función

Qué está comprobado y dónde. Se actualiza en cada versión del plugin o de la app.

- **Teléfono**: lo recorrió el titular en un teléfono real con la APK. Desde la Fase 4 solo se prueba en teléfono una vez, al cerrar la fase, con la lista de `atora-mobile/docs/PRUEBA-TELEFONO.md`.
- **Pantalla (CI)**: recorrido Maestro en emulador Android contra un WordPress temporal del CI (`wp atora seed-e2e`).
- **Integración**: PHPUnit con WordPress (`tests/integration`).
- **Unitaria**: PHPUnit sin WordPress o Jest en la app.

Última actualización: **plugin 6.32.0 · app 0.9.0**.

> No hay registro escrito de qué se recorrió en teléfono antes de esta tabla: la columna "Teléfono" queda "por confirmar" hasta la prueba al cierre de la Fase 4, que incluye la lista acumulada de las fases 0 a 3.

## App (estudiante)

| Función | Desde | Teléfono | Pantalla (CI) | Integración (plugin) | Unitaria |
|---|---|---|---|---|---|
| Iniciar y cerrar sesión, sesión por usuario | 0.1–0.3 | por confirmar | sí (`login`) | sí | Jest |
| Cursos, currículo, lección con varios videos | 0.3–0.4.1 | por confirmar | sí (`leccion`) | sí (`MobileAuditMultiVideoTest`) | Jest |
| Descargas y visor PDF / imágenes sin conexión | 0.4.0 | por confirmar | — | — | Jest |
| Posición del video y cola sin conexión | 0.4.0 | por confirmar | sí (`sin-conexion`: aviso, curso desde la caché, mensaje en cola) | sí | Jest |
| Sincronización incremental (`/sync/changes`) | 0.4.0–0.5.3 | por confirmar | — | sí (`MobileSyncChangesTest`) | Jest |
| Entregas de tareas con cola sin conexión | 0.3.0 | por confirmar | sí (`tarea`, con conexión) | sí | Jest |
| Quiz con borrador local y entrega | 0.5.0–0.5.2 | por confirmar | sí (`quiz`) | sí | Jest |
| Notas, rúbrica por criterio, Mi evolución, certificados | 0.5.0–0.5.3 | por confirmar | — | sí (`MobileGradesCertificatesTest`, `GradeZeroAverageTest`) | Jest |
| Mensajes y Avisos | 0.6.0 | por confirmar | sí (`mensajes`) | sí (`InboxTest`, `MobileOrganizeTest`) | Jest |
| Agenda y Hoy del estudiante | 0.6.0 | por confirmar | — | sí (`MobileOrganizeTest`) | Jest |
| Notificaciones al teléfono (Expo) | 0.6.0 | por confirmar | — (requiere servicio externo) | sí (cola y token) | Jest |
| Asistente de la lección ("Preguntar"), aviso de IA, sin conexión deshabilitado | 0.9.0 | pendiente (cierre de Fase 5, filas 31–33) | sí (`estudiante-asistente`, proveedor simulado) | `AiAssistantTest` | Jest (conversación solo en la sesión, límite; el indicio no está en pantallas del estudiante) |

## App (docente, Fase 4)

| Función | Desde | Teléfono | Pantalla (CI) | Integración (plugin) | Unitaria |
|---|---|---|---|---|---|
| Hoy del docente | 0.7.0 | pendiente (cierre de fase) | sí (`docente-hoy`) | `TeacherApiTest` | — |
| Cursos y estudiantes con riesgo y motivo, búsqueda, ficha, Escribir | 0.7.0 | pendiente (cierre de fase) | sí (`docente-estudiantes`) | `TeacherApiTest`, `StudentRiskServiceTest` | Jest (señal de riesgo) |
| Aviso al grupo (y el estudiante lo recibe en Avisos) | 0.7.0 | pendiente (cierre de fase) | sí (`docente-aviso`) | `TeacherApiTest` | Jest (aviso y cola) |
| Calificar con rúbrica: PDF, decimal, borrador, publicar, el estudiante ve la nota | 0.8.0 | pendiente (cierre de fase) | sí (`docente-calificar`) | `TeacherApiTest`, `GradeDraftNoLeakTest` | Jest (bandas y total = servidor; borrador local) |
| Conflicto entre dos docentes (409) | 0.8.0 | pendiente (cierre de fase) | sí (`docente-calificar-409`) | `GradeRevisionConflictTest`, `TeacherApiTest` | — |
| Tarea grupal calificada desde el teléfono da la nota a todos | 0.8.0 | pendiente (cierre de fase) | — (datos sembrados, sin recorrido propio) | `GroupAssignmentMobileTest` | — |
| Borrador de calificación tras cerrar la app o perder señal | 0.8.0 | pendiente (cierre de fase) | sí (`docente-borrador-recuperado`, 0.8.1) | — | Jest |
| Sugerencia de calificación con IA: pedir, ver, "Usar todo"/"Usar", marca hasta editar | 0.9.0 | pendiente (cierre de Fase 5, filas 34–37) | sí (`docente-sugerencia-ia`, proveedor simulado) | `AiGradingSuggestionTest` | Jest (rellena sin enviar, consulta cada 3 s hasta 2 min) |

## Plugin (web)

| Función | Desde | Integración | Notas |
|---|---|---|---|
| Un borrador de calificación no llega al estudiante; copias grupales heredan estado y rúbrica | 6.30.1 | `GradeDraftNoLeakTest` | |
| Listeners de `clms_submission_graded` con argumentos en orden | 6.30.2 | `GradedHookListenersTest` | Ver "Datos históricos" |
| Mensajes del docente en la web: hilos completos, mismo buzón y contador que la app | 6.30.2 | `TeacherWebMessagesTest` | |
| `wp atora seed-e2e` (datos de las pruebas de pantalla) | 6.30.2 | probado a mano en WordPress local; lo usa el CI de la app | Se niega sin `--yes` o `ATORA_E2E` |
| Servicio de guardado compartido (SpeedGrader y app) | 6.31.0 | `SpeedGraderRubricEvaluationTest`, `SpeedGraderDecimalScoreTest` (sin cambios), `TeacherApiTest` | |
| Alcance docente (`ATORA_Teacher_Scope`) | 6.31.0 | `TeacherScopeTest`, `TeacherApiTest` | Auditoría de acceso: local 0; demo con una institución → 0 por construcción (sin correr en el demo) |
| Conflicto entre docentes (revisión, 409) | 6.31.0 | `GradeRevisionConflictTest`, `TeacherApiTest` | 6.31.1: revisión obligatoria, no se consume al fallar, primer guardado atómico; concurrencia real por HTTP en el CI de la app (`scripts/e2e-concurrent-grade.sh`) |
| Riesgo con motivos | 6.31.0 | `StudentRiskServiceTest` | |
| Historial de entregas web y migración | 6.31.0 | `WebSubmissionHistoryTest` | Local: 1 entrega migrada; segunda pasada 0 |
| Intentos en SpeedGrader | 6.31.0 | `SpeedGraderAttemptsTest` | |
| Tareas grupales en la app (API) | 6.31.0 | `GroupAssignmentMobileTest` | |
| API del docente `/teacher/*` | 6.31.0 | `TeacherApiTest` | Pantallas: app 0.7.0 y 0.8.0 |
| Uso y límites de IA (tabla, 429, tope mensual, aviso 80 %, pantalla de uso) | 6.32.0 | `AiAssistantTest`, `AiGradingSuggestionTest` | Web y app comparten el contador |
| Asistente del estudiante `POST /ai/assistant` | 6.32.0 | `AiAssistantTest` | Proveedor simulado; pantalla: app 0.9.0 |
| Sugerencia de calificación con IA (API y SpeedGrader) | 6.32.0 | `AiGradingSuggestionTest` | Proveedor simulado; botón web "Usar sugerencia" probado a mano en local; pantalla: app 0.9.0 |

## Datos históricos

- **Analítica (`clms_analytics_event_log`, evento `submission_graded`)**: hasta 6.30.1 el evento guardaba lección, usuario, estado y nota cruzados (el listener leía los argumentos en otro orden). **Los eventos son fiables desde 6.30.2.** Los anteriores no se reescriben.
- **Caché del panel del estudiante y del libro de notas tras calificar**: hasta 6.30.1 el listener propio no borraba nada (otros listeners sí borraban la caché del libro de notas). Desde 6.30.2 se borra por la vía correcta. No deja datos persistentes erróneos.

## Auditorías y el demo

- El demo (`demo.atora.studio`) solo tiene cuentas de prueba: las auditorías de **borradores** (`scripts/audit-draft-grade-leaks.php`, 6.30.1) y de **notas en cero** (`scripts/audit-zero-grade-averages.php`, 6.29.5) **no aplican** al demo.
- Quedan para la **primera instalación con estudiantes reales**: correrlas **antes** de actualizarla.
