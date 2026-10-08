# Progreso — Fase 6 (Publicación)

Orden: `docs/ordenes/ORDEN-FASE-6.md`. Reanudar: leer este archivo y la orden, comprobar en GitHub y seguir desde "Paso actual".

## Paso actual

**Cierre de la fase.** Bloque E hecho (E.1–E.6) en `fix/6.33.1-auditoria` (plugin) y `fix/1.0.0-auditoria` (app). Sigue: plugin 6.33.1 (versión, changelog, ESTADO, PR, CI, etiqueta, ZIP, release) → app (PR, CI completo contra el plugin nuevo, changelog, PRUEBA-TELEFONO, etiqueta `v1.0.0` movida, APK de prueba y AAB de producción sin enviar; IPA pendiente de la cuenta de Apple).

Antes del Bloque E quedó publicado: plugin `v6.33.0` (ZIP ea8a5468…), app `v1.0.0` (APK preview vc15, AAB vc16; sin enviar).

## Bloques

- [x] **A** — 6.32.1 ya publicado (v6.32.1, PR #67): control central en `CLMS_AI_Manager` (chat, chat_with_meta, embeddings, transcripción, post_json) con registro por `feature`, límites, tope mensual y filtro de datos personales; marcador `{{nombre}}`; simulado nunca en `production`; `AiCentralControlTest`. Comprobado contra la lista del Bloque A: cumple.
- [x] **B** (plugin) — certificado PDF, plantilla con 3 firmas, QR, `/verificar/{codigo}`, regeneración con fecha original, API `format=pdf` + `certificate_pdf`. `CertificatePdfTest` (6). Pendiente app: descargar y abrir el PDF (Bloque D).
- [x] **C** (plugin) — `POST /account/deletion-request`, aviso al administrador, pantalla de solicitudes (anonimizar / eliminar), página pública `/eliminar-cuenta/` con enlace por correo. `AccountDeletionTest` (5).
- [x] **C** (app y docs) — Yo → Eliminar mi cuenta; `docs/POLITICA-PRIVACIDAD-APP.md` y `docs/TIENDAS-DATOS.md` (app); permisos: se bloquean `READ/WRITE_EXTERNAL_STORAGE` y `SYSTEM_ALERT_WINDOW`; iOS sin textos de permiso (no usa cámara, fotos, ubicación…), cifrado exento.
- [x] **D** — app 1.0.0. Hecho: i18n es/en (535 textos, prueba de catálogo), idioma en Yo, certificado PDF en la app, accesibilidad (etiquetas, 58 objetivos llevados a 44 pt, prueba de contraste, títulos sin cortes), errores con Sentry (sin datos personales, desactivable por academia en "App móvil" del plugin; apagado sin DSN). Falta: rendimiento, compilación de producción, cuentas de revisión, ficha y capturas, recorridos nuevos.
- [x] **E** — E.1 [x] E.2 [x] E.3 [x] E.4 [x] E.5 [x] E.6 [x]
- [ ] Cierre (tras E; IPA pendiente de la cuenta de Apple): 6.33.0 + 1.0.0, changelog, ESTADO, PRUEBA-TELEFONO, etiquetas, ZIP, compilación de prueba y de producción (AAB, IPA) sin enviar.

## Ramas y PR

| Repo | Rama | Estado |
|---|---|---|
| Atora-LMS-6 | `feat/6.33.0-publicacion` | fusionada (PR #68), `v6.33.0` |
| atora-mobile | `feat/1.0.0-publicacion` | fusionada (PR #27), `v1.0.0` |

## Decisiones menores

1. Librería de PDF: **Dompdf 3** (PHP puro; solo `mbstring` y `dom`). QR: **chillerlan/php-qrcode 5** (SVG, sin GD). Empaquetadas en `lib/packages` (Composer propio en `lib/`, sin chequeo de plataforma); `vendor/` sigue siendo solo de desarrollo.
2. Imágenes del certificado (logo y firmas) se aplanan sobre blanco y se pasan como JPEG: con PNG, Dompdf usa Imagick y en algunos servidores (también el local) Imagick falla con error fatal.
3. Código de verificación = UUID de la credencial institucional (`atora_credentials`, 122 bits aleatorios, con revocación de dos personas). Los códigos anteriores (20 caracteres) siguen verificando.
4. `/verificar/` siempre activa (el QR del PDF la necesita); el ajuste antiguo de verificación pública solo afectaba al shortcode, que ahora muestra lo mismo que la página.
5. Revocación solicitada y aún sin decidir: se muestra "Válido" (no está revocado todavía). Sustituido: solo "Sustituido".
6. PDF guardado en `uploads/atora-private/certificates` (con `.htaccess` que niega el acceso directo), regenerado si cambia la plantilla.
7. Eliminación: anonimizar es la opción recomendada (conserva entregas, notas y actas sin datos personales); los certificados ya emitidos siguen verificables con el nombre impreso (registro del título). Los administradores no se eliminan desde la pantalla. Plazo informado: 30 días.
8. i18n: el texto en español es la clave (`t('…')`); `tk()` marca claves en mapas. Prueba con el compilador de TypeScript: falla si queda texto visible sin `t()` o una clave sin inglés. Idioma: el del teléfono (español si es español; si no, inglés) o el elegido en Yo. Los textos que vienen del servidor (títulos, motivos de riesgo, errores) llegan en el idioma de la academia.
9. Compilación de pruebas (`ATORA_E2E`): "idioma del teléfono" = español, porque el emulador del CI está en inglés y los recorridos buscan textos en español.
10. Se quitaron restos de desarrollo de la app publicada: la tarjeta de programa con "Diplomado en Comunicación" fijo y las direcciones internas (LAN/localhost) en la configuración de la academia (solo en desarrollo).
11. Reporte de errores: sin saber aún si la academia lo permite (al arrancar), no se envía nada. Se activa al crear la cuenta de Sentry y poner `EXPO_PUBLIC_SENTRY_DSN` (acción del titular); la subida de mapas de código queda apagada (`SENTRY_DISABLE_AUTO_UPLOAD`).
12. Accesibilidad: prueba estática con el compilador de TypeScript (botón sin texto → etiqueta; área táctil ≥ 44 pt estimada desde el `StyleSheet`, o `hitSlop`). El verde de "Calificación publicada" pasó a #17724A (contraste 5,9:1).
13. Imágenes del certificado (reemplaza la decisión 2): PNG sin alfa aplanado con GD sobre el **color de fondo de la hoja** (nuevo campo de la plantilla); sin GD, Imagick dentro de try/catch. El QR conserva un margen blanco a propósito (lectura).
14. Rendimiento: medido en el CI con un emulador de 2 GB y red 3G (`modo=gama-baja`): arranque en frío mediana 1,27 s (umbral 4 s). La lista de 100 lecciones tenía 16,8 % de cuadros lentos → el curso pasó a `FlatList` virtualizada; se vuelve a medir.
15. Versiones: EAS con numeración remota (`appVersionSource: remote`), inicializada desde `versionCode` 15 / `buildNumber` 3; iOS solo teléfono (`supportsTablet: false`) para no exigir capturas de iPad.
16. Cuentas de revisión: `wp atora review-accounts` (idempotente); el titular lo corre en el demo (no tengo acceso al servidor del demo).
17. Sugerencia de IA: si la cola no la toma en 15 s, la ejecuta la consulta (reclamo atómico, nunca dos veces). Apareció en el CI y en producción afecta a hostings con WP-Cron desactivado.
18. La lista de 100 lecciones se virtualizó; en el emulador (dibujo por software) los cuadros lentos no bajaron (17 % → 24 %), así que la cifra mide sobre todo el emulador; queda la comprobación en un teléfono real (fila 42).
20. E.3: la sugerencia guarda intento y hash del contenido (sha256 de "intento|texto" del intento); "de otro intento" = intento distinto del que se mira o contenido cambiado. La app compara además con el intento elegido en pantalla.
21. E.4: la consulta (GET) y la recarga de SpeedGrader nunca generan. Pasados 15 s sin cola, se dispara aparte con una petición asíncrona firmada a `admin-post.php?action=atora_ai_run_job` (`wp_hash`, sin sesión; se exceptuó de la redirección de estudiantes/visitantes fuera de wp-admin) (sin `spawn_cron()`: su candado frenaba el cron externo). Filtro `atora_ai_kick_url` para hostings que no se alcanzan a sí mismos; el WordPress del CI (`ATORA_E2E`) lo apunta a `localhost`, porque su URL es la del emulador — sin esto el recorrido `docente-sugerencia-ia` falló (comprobado en local: sin filtro queda `pending`, con filtro `done` en < 4 s). Reemplaza la decisión 17. La app recuerda el `job_id` por entrega en la caché (se borra al cerrar sesión) y lo retoma al volver (hasta 30 min).
22. E.5: la reserva es una fila `reserved` en `atora_ai_usage` creada bajo `GET_LOCK` por academia; costo estimado = promedio de las últimas 20 llamadas bien terminadas de esa función (0 sin historial; filtro `atora_ai_reservation_estimate`). Las caducadas se marcan `expired`. Prueba HTTP real `scripts/e2e-concurrent-ai.sh` (en el CI de la app): antes del arreglo pasaban 10 de 10; ahora 3. El simulado acepta un retardo (`atora_ai_fake_delay_ms`, solo pruebas).
23. E.6: además de lo pedido apareció un hueco: la revocación pendiente usaba el token de acceso (15 min) y, si la red volvía más tarde, el servidor respondía 401 y la sesión (30 días) seguía viva con su token de avisos. `/auth/logout` acepta ahora el token de renovación en el cuerpo y la app lo usa. Los tokens de avisos anteriores a 6.33.1 (sin sesión) siguen hasta que la app los vuelva a registrar (lo hace al abrir).
19. Versiones del Bloque E: el plugin sale como **6.33.1** (la 6.33.0 ya está publicada con ZIP y las academias deben poder actualizar); la app sigue en **1.0.0** (nunca llegó a una tienda), con nuevo número de compilación, y su etiqueta `v1.0.0` se mueve al cierre.

