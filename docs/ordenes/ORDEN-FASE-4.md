# Orden Fase 4 — Docente · plugin 6.30.2 → 6.31.0 · app 0.6.1 → 0.8.0

**Punto de partida:** plugin 6.30.1 (borrador sin filtración, publicado), app 0.6.0. Demo sin estudiantes reales.

## Decisiones del titular (vigentes para las fases 4, 5 y 6)

1. **Sin pruebas en teléfono durante la fase.** El titular prueba la APK una sola vez, al cerrar la fase completa. No generar APK intermedias: solo una `preview` al final de la fase.
2. **Por eso, las pruebas de pantalla en CI son obligatorias** y sustituyen la prueba manual durante la fase. Corren contra un **WordPress temporal dentro del CI** (Docker), nunca contra el demo.
3. Las auditorías de borradores y de notas en cero **no aplican** al demo (solo cuentas de prueba). Anotarlo en `docs/ESTADO.md`. Los scripts quedan para la primera instalación con estudiantes reales y deben correrse antes de actualizarla.
4. Nota final entera de 0 a 100; los puntajes por criterio conservan decimales. No se cambia.
5. Las órdenes viven en `docs/ordenes/` de `Atora-LMS-6`. Esta se guarda ahí como `ORDEN-FASE-4.md`.

## Reglas

- PR contra `main`, CI en verde, etiqueta, changelog y documentación en el mismo PR que sube la versión. ZIP verificado del plugin en cada versión.
- Cada corrección o función lleva una prueba que falla con el código anterior.
- Rutas en `atora-mobile/v1` con `authorize`, no-caché y capacidad en `/discovery`. 503 ante error de base de datos.
- Calificar desde el teléfono sale del **mismo servicio** que SpeedGrader.
- Si el código contradice esta orden, parar y reportar. Si una decisión es reversible y de bajo impacto, decidir, anotarlo en el reporte y seguir: no detener la fase por eso.
- Reportes cortos al cerrar cada bloque (máximo 8 líneas). El reporte final, al cerrar la fase.

---

## Bloque 1 — Plugin 6.30.2 (lo que faltó del cierre)

1. **Listeners con argumentos cruzados:** la caché del panel, la del motor de evaluación y la analítica leen en el orden real de `clms_submission_graded`. Revisar también los listeners de `clms_submission_grade_draft_saved` y `clms_grade_published`. Prueba que falla antes. Los datos históricos de analítica no se reescriben: anotar en `docs/ESTADO.md` desde qué versión son fiables.
2. **Mensajes del docente en la web:** la página muestra los hilos completos (enviados y recibidos), con las mismas tablas, servicio y contador que la app.
3. **`docs/ESTADO.md`:** por función, si está comprobada en teléfono, en pruebas de pantalla o solo en pruebas unitarias. Se actualiza en cada versión.

## Bloque 2 — App 0.6.1: pruebas de pantalla en CI (en paralelo con el Bloque 3)

1. Maestro, emulador Android (API 34, x86_64, KVM) en GitHub Actions.
2. WordPress + MySQL en Docker dentro del job, con el plugin en la versión de `main` y datos sembrados por `wp atora seed-e2e` (crear el comando):
   - un curso con 2 lecciones, una con 3 videos MP4 cortos locales y una tarea;
   - un quiz de 3 preguntas;
   - un estudiante y un docente asignado a la sección;
   - una entrega del estudiante sin calificar;
   - un mensaje del docente al estudiante.
3. APK compilada en el CI con un perfil `e2e` (`usesCleartextTraffic` solo en ese perfil).
4. Recorridos: `login`, `leccion`, `tarea`, `quiz`, `mensajes`, `sin-conexion`. Una captura por pantalla como artefacto.
5. Si pasa de 25 minutos: completo al etiquetar, y `login` + `leccion` en cada PR.
6. Demostrar que sirve: un PR de prueba que rompe el arranque debe fallar (se cierra sin fusionar).
7. `docs/PRUEBA-TELEFONO.md` en `atora-mobile`: la lista única que el titular recorrerá al cerrar la fase.
8. Versión 0.6.1 y etiqueta. **Sin APK preview.**

## Bloque 3 — Plugin 6.31.0

### 3.1 Servicio de guardado (extracción pura, commit propio)

`ATORA_Grading_Save_Service::save( int $submission_id, int $actor_id, array $input )`, con el diseño ya aprobado:
- el cuerpo de `handle_speedgrade_save()` se mueve tal cual;
- `handle_speedgrade_save()` queda como envoltura (nonce, `$_POST` → `$input`);
- las pruebas actuales de SpeedGrader pasan **sin modificarlas**.

Los cambios de comportamiento van en commits aparte.

### 3.2 Acceso: `ATORA_Teacher_Scope`

- Una sola regla para SpeedGrader web y para `/teacher/*`.
- Puede calificar quien cumpla cualquiera de estas condiciones:
  - asignado a la sección (`atora_section_teachers`);
  - autor del curso o de la lección;
  - permiso de editar lo ajeno;
  - delegación vigente.
- El administrador queda limitado a su institución, resuelta desde el curso.
- Script de solo lectura: quién perdería acceso con la regla nueva. En el demo se espera vacío; si no lo está, reportarlo antes de fusionar.

### 3.3 Concurrencia

- Meta `_clms_submission_grade_revision`, que sube en cada guardado.
- La web y la app la envían; ante una revisión vieja, 409 con la versión actual.
- SpeedGrader web muestra "Otro docente guardó esta entrega; recarga para ver su versión".

### 3.4 Riesgo: `ATORA_Student_Risk_Service`

- Combina early-warning (entregas vencidas) con el riesgo del resumen de notas.
- Devuelve nivel y motivos legibles ("2 entregas vencidas", "nota acumulada 48/100").
- Lo usan la web y `/teacher/*`.

### 3.5 Heredados

- **Historial de entregas web:** cada intento web escribe una fila en `atora_assignment_submissions` (solo-añadir). Migración idempotente de las existentes como intento 1.
- **Todos los intentos en SpeedGrader:** lista de intentos con fecha (y la del dispositivo si existe); se califica el elegido, por defecto el último.
- **Tareas grupales en la app:** el estudiante ve su grupo, quién entregó, y puede entregar si la regla lo permite. La nota de la maestra va a todos los integrantes, respetando estado, rúbrica y ajustes individuales.

### 3.6 Rutas del docente

| Método | Ruta | Contenido |
|---|---|---|
| GET | `/teacher/today` | Por calificar (cantidad y las 5 más antiguas), estudiantes en riesgo, clases y fechas límite del día, mensajes sin leer |
| GET | `/teacher/courses` | Cursos y secciones con cantidad de estudiantes y de entregas pendientes |
| GET | `/teacher/courses/{id}/students` | Paginado: avance, nota acumulada, último acceso, riesgo con motivos |
| GET | `/teacher/students/{id}?course=` | Ficha: avance, notas, entregas, alertas |
| GET | `/teacher/submissions?status=&course=&lesson=&cursor=` | Cola de entregas |
| GET | `/teacher/submissions/{id}` | Intentos, archivos con enlace firmado y temporal, texto, rúbrica con bandas, fechas del cliente y del servidor, grupo, revisión |
| POST | `/teacher/submissions/{id}/grade` | Puntajes y comentarios por criterio (decimales), comentario general, nota final opcional, `publish`, intento, `expected_revision`, `client_event_id`. Por `ATORA_Grading_Save_Service`. Idempotente |
| POST | `/teacher/announcements` | Aviso a un curso o sección por el buzón |

### 3.7 Pruebas

- La API y SpeedGrader con los mismos datos dan la misma nota, la misma auditoría y el mismo aviso.
- Un docente sin el curso → 404. Un estudiante en `/teacher/*` → 403.
- 409 ante revisión vieja.
- Decimales conservados por criterio.
- Borrador invisible para el estudiante; publicada, visible.
- Grupal: misma nota y mismo estado para todos.
- Las migraciones no duplican.

Cierre: 6.31.0, changelog, `MOBILE-API-V1.md`, etiqueta, ZIP.

## Bloque 4 — App 0.7.0 (docente: ver y comunicar)

- Hoy del docente.
- Cursos y estudiantes con riesgo (color **y** texto con el motivo) y búsqueda.
- Ficha del estudiante con "Escribir".
- Aviso al grupo, con cola sin conexión.
- Recorridos nuevos: `docente-hoy`, `docente-estudiantes`, `docente-aviso`.
- Etiqueta. **Sin APK.**

## Bloque 5 — App 0.8.0 (calificar)

- **Cola** filtrable, la más antigua primero, con contador.
- **Pantalla de calificación:**
  - visor de texto, PDF e imágenes, y otros formatos con la app del sistema;
  - intentos y fecha "realizada sin conexión" si aplica;
  - rúbrica táctil: tocar un nivel pone su puntaje, ajustable con decimales, con nivel o banda calculados con la misma regla que la web;
  - comentario por criterio, total, nota final (con "copiar % de la rúbrica") y comentario general;
  - **Borrador** o **Publicar**, con confirmación antes de publicar;
  - "Siguiente entrega".
- **Sin conexión:** calificar exige conexión; el borrador local se conserva si se cae la señal o se cierra la app.
- **409:** mostrar la versión del otro docente y no sobrescribir sin confirmación.
- **Grupales:** indicar el grupo y que la nota se aplica a todos.
- **Jest:** borrador local; total y banda iguales al servidor (mismos casos que `RubricLevelBandsTest`).
- **Recorridos:** `docente-calificar` (puntúa, publica, y el estudiante ve la nota) y `docente-calificar-409`.
- Etiqueta 0.8.0 y **la única APK `preview` de la fase**.

---

## Hecho cuando (lo verifica el titular en teléfono, una sola vez, al cerrar la fase)

1. El docente ve su Hoy con entregas por calificar.
2. Ve la lista de un curso con el riesgo y su motivo, y abre la ficha de un estudiante.
3. Envía un aviso al curso y el estudiante lo recibe.
4. Califica una entrega con PDF usando un decimal y la guarda como borrador: el estudiante no la ve.
5. La publica: el estudiante ve nota, niveles y comentarios, y recibe el aviso.
6. SpeedGrader web muestra exactamente lo mismo.
7. Dos docentes sobre la misma entrega: el segundo recibe el conflicto.
8. Una tarea grupal calificada desde el teléfono da la nota a todos.
9. Además, la lista acumulada de `docs/PRUEBA-TELEFONO.md` de las fases 0 a 3.

## Reporte final (máximo 15 líneas)

Versiones y etiquetas; enlace a las capturas de los recorridos; enlace a la APK; qué decisiones menores tomó el agente sin preguntar; qué está comprobado solo en CI.
