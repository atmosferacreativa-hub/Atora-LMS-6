# Progreso — correcciones 6.33.2 · 1.0.1

Orden: `docs/ordenes/ORDEN-1.0.1.md`. Reanudar: leer este archivo y la orden, comprobar en GitHub y seguir desde "Paso actual".

## Paso actual

App: 3 (teclado), 4 (Drive), 5 (URL), 6 (pantalla gris), 9 (inicio blanco). Ramas `fix/6.33.2-correcciones` (plugin) y `fix/1.0.1-correcciones` (app).

## Puntos

- [x] 1 — Matrícula por programa (plugin): `ATORA_Course_Access_Service`, web + API + mensajes; matrícula automática al agregar un curso; `wp atora reconcile-program-access` y `scripts/program-access-report.php` (solo lectura). `ProgramCourseAccessTest` (4).
- [x] 2 — Bajas de sesión por academia: cada pendiente con su `academy`, enviado solo ahí (`apiRequest` con `baseUrl`); Jest (cerrar sin red en A, entrar en B, reconectar → todo a A, nada a B).
- [ ] 3 — Teclado (app)
- [ ] 4 — Videos de Drive (app)
- [ ] 5 — URL de la academia (app)
- [ ] 6 — Pantalla gris (app)
- [x] 7 — Lectura sin bloqueo → 409 `atora_grade_busy` reintentable (`GradeRevisionConflictTest`); la app reintenta una vez tras 1 s (`busyRetry`, Jest)
- [x] 8 — Reserva de IA comprobada: insert fallido → 503, sin llamar al proveedor (`AiUsageReservationTest`)
- [ ] 9 — Pantalla de inicio blanca (app)
- [ ] Pruebas de pantalla (sin hideKeyboard; foco visible; academia-url, leccion-drive, programa-acceso)
- [ ] Cierre (6.33.2, 1.0.1, etiquetas, ZIP, APK, AAB)

## Ramas y PR

| Repo | Rama | Estado |
|---|---|---|
| Atora-LMS-6 | `fix/6.33.2-correcciones` | en curso |
| atora-mobile | `fix/1.0.1-correcciones` | en curso |

## Decisiones menores

1. `CLMS_Helper::user_is_enrolled_in_course()` sigue siendo matrícula directa (comercio, inscripción y gestión la usan para no duplicar); `user_can_access_course()` y los puntos que deciden la entrada (lección, drip, quiz, entregas, certificados, plantillas, catálogo, Drive, asistente) usan el servicio.
2. Al agregar un curso a un programa, los inscritos vigentes quedan matriculados (como `enroll_user_in_program`, con la caducidad del programa); esto puede mandar el mensaje de bienvenida del curso, igual que al inscribirse.
3. El comando, sin `--yes`, es el informe de solo lectura. El demo corre 6.31.0 y no tengo acceso al servidor (no hay host ni usuario en la configuración): `scripts/program-access-report.php` (solo lectura, funciona en 6.31) lo corre el titular con `wp eval-file`; el número de afectados del demo queda pendiente de esa corrida.
4. Punto 2: los pendientes guardados por la 1.0.0 no tienen academia y se descartan sin enviarse (no se puede saber a cuál pertenecían; mandarlos a la actual podría filtrar credenciales). Esa sesión vieja vence sola en 30 días.
