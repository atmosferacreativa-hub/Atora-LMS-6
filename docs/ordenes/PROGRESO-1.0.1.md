# Progreso — correcciones 6.33.2 · 1.0.1

Orden: `docs/ordenes/ORDEN-1.0.1.md`. Reanudar: leer este archivo y la orden, comprobar en GitHub y seguir desde "Paso actual".

## Paso actual

**Orden cerrada (2026-10-08).** Plugin **v6.33.2** (PR #71, release con ZIP, SHA-256 `d6ee6ac3d45a18befeb9c7ac2231c026549db8d483cc1be24e087b54786b375d`); app **v1.0.1** (PR #29). CI: plugin 11/11; e2e de la app 20/20 + concurrencia (run 37745840067, capturas: artefacto `capturas-70`) y otra vez en el PR contra el plugin publicado. APK `preview` (versionCode 17) https://expo.dev/artifacts/eas/_nKWKXOZK6rKN7iEpNi12EZrcCJK4HWhFeHF08bIxzI.apk · AAB `production` (versionCode 18) https://expo.dev/artifacts/eas/U_VqbVf4gqLUYOpeY-p-A50SOkKPq7YLWb70rEHRTf0.aab, sin enviar. Pendiente del titular: filas 48–52 de `PRUEBA-TELEFONO.md` (la 50, video de Drive, obligatoria) y correr `scripts/program-access-report.php` en el demo (6.31.0).

## Puntos

- [x] 1 — Matrícula por programa (plugin): `ATORA_Course_Access_Service`, web + API + mensajes; matrícula automática al agregar un curso; `wp atora reconcile-program-access` y `scripts/program-access-report.php` (solo lectura). `ProgramCourseAccessTest` (4).
- [x] 2 — Bajas de sesión por academia: cada pendiente con su `academy`, enviado solo ahí (`apiRequest` con `baseUrl`); Jest (cerrar sin red en A, entrar en B, reconectar → todo a A, nada a B).
- [x] 3 — Teclado: react-native-keyboard-controller (`KeyboardProvider`, `KeyboardScroll` con 24 pt, tocar fuera cierra; chats con la caja pegada; login Siguiente/Listo). Recorridos sin `hideKeyboard` que comprueban el campo visible.
- [x] 4 — Drive: iframe `youtube.googleapis.com` bloqueado por la lista de dominios (Android aplica `onShouldStartLoadWithRequest` a iframes). `allowDriveNavigation` + registro de bloqueos; Jest; recorrido `leccion-drive` con video público real (seed).
- [x] 5 — URL: `normalizeAcademyUrl` (Jest, 21 casos); solo se guarda si `/discovery` responde; recorrido `academia-url`.
- [x] 6 — Pantalla gris: comprobado en el CI (Android 14) con la 1.0.0: no se reproduce (colores iguales antes/después). Arreglo: la ventana de academia deja de ser `Modal` nativo (otra ventana con atenuación) y es una capa de la pantalla; capturas antes/después en `academia-url`; fila en PRUEBA-TELEFONO.
- [x] 7 — Lectura sin bloqueo → 409 `atora_grade_busy` reintentable (`GradeRevisionConflictTest`); la app reintenta una vez tras 1 s (`busyRetry`, Jest)
- [x] 8 — Reserva de IA comprobada: insert fallido → 503, sin llamar al proveedor (`AiUsageReservationTest`)
- [x] 9 — Inicio blanco (`expo-splash-screen` #FFFFFF; Jest lee app.json).
- [x] Pruebas de pantalla: sin hideKeyboard (cada campo comprobado visible); academia-url, leccion-drive y programa-acceso; 20/20.
- [x] Cierre (6.33.2, 1.0.1, etiquetas, ZIP, APK, AAB)

## Ramas y PR

| Repo | Rama | Estado |
|---|---|---|
| Atora-LMS-6 | `fix/6.33.2-correcciones` | fusionada (PR #71), `v6.33.2` |
| atora-mobile | `fix/1.0.1-correcciones` | fusionada (PR #29), `v1.0.1` |

## Decisiones menores

1. `CLMS_Helper::user_is_enrolled_in_course()` sigue siendo matrícula directa (comercio, inscripción y gestión la usan para no duplicar); `user_can_access_course()` y los puntos que deciden la entrada (lección, drip, quiz, entregas, certificados, plantillas, catálogo, Drive, asistente) usan el servicio.
2. Al agregar un curso a un programa, los inscritos vigentes quedan matriculados (como `enroll_user_in_program`, con la caducidad del programa); esto puede mandar el mensaje de bienvenida del curso, igual que al inscribirse.
3. El comando, sin `--yes`, es el informe de solo lectura. El demo corre 6.31.0 y no tengo acceso al servidor (no hay host ni usuario en la configuración): `scripts/program-access-report.php` (solo lectura, funciona en 6.31) lo corre el titular con `wp eval-file`; el número de afectados del demo queda pendiente de esa corrida.
4. Punto 2: los pendientes guardados por la 1.0.0 no tienen academia y se descartan sin enviarse (no se puede saber a cuál pertenecían; mandarlos a la actual podría filtrar credenciales). Esa sesión vieja vence sola en 30 días.
5. Punto 3: `react-native-keyboard-controller` 1.18.5 necesita `react-native-reanimated`; se instalaron las versiones de SDK 54 (`~4.1.1`, `react-native-worklets` 0.5.1). Búsqueda de estudiantes (campo arriba) y el quiz también usan el contenedor nuevo.
6. Punto 4: la configuración del WebView y las dependencias son idénticas en 0.3.1, 0.6.0, 0.9.0 y 1.0.0: no hubo un cambio de la app que lo rompiera. Cambió el reproductor de Drive (ahora un iframe de `youtube.googleapis.com`), y la lista de dominios de la app, que nunca incluyó `googleapis.com`, lo bloqueó. Video de prueba: "Taller-Derecho-Constitucional.mp4" del Drive del titular (público, 3,7 MB).
7. Punto 6: no se reprodujo en el emulador (Android 14); probable en Android 15 con borde a borde obligatorio. Queda la fila del teléfono.
8. Punto 3, teclado: en el emulador del CI `KeyboardAwareScrollView` (y los eventos de campo enfocado de la librería) no movían la pantalla al pasar de usuario a contraseña; `KeyboardAvoidingView` sí funciona. `KeyboardScroll` se rehízo con `KeyboardAvoidingView` + `ScrollView` + medición del campo enfocado (`TextInput.State`), con el cálculo en un módulo puro probado con Jest.
9. Teclado, lo que mostraron las vueltas del e2e (cada falla, un arreglo de la app, nunca de la prueba): (1–2) la contraseña quedaba bajo el teclado tras "Siguiente" → `KeyboardScroll` mide el campo enfocado; (3) el aviso y el asistente seguían tapados (KeyboardAvoidingView de la librería no acomodaba pantallas dentro de la navegación) → `useKeyboardInset` (margen = lo que el teclado tapa de la vista); además conflicto de calificación cierra el teclado y la búsqueda de estudiantes abre al primer toque; (4–5) intermitencias: el evento de React Native no siempre llegaba y una segunda medición caía entre "cerrado" y "abierto" (margen 616) → dos fuentes de eventos y nunca medir con el teclado cerrado. El registro del dispositivo confirmó el borde del teclado en 405 por ambas fuentes.

