# Progreso — Fase 5 (IA)

Orden: `docs/ordenes/ORDEN-FASE-5.md`. Al reanudar: leer este archivo, comprobar en GitHub ramas, PR y CI, y seguir desde "Paso actual" sin rehacer lo fusionado.

## Paso actual

**Bloque 1 — plugin 6.32.0: PR #65 en CI. Bloque 2 — app 0.9.0: código, Jest y recorridos listos en `feat/0.9.0-ia`, falta PR, CI completo (tras fusionar 6.32.0), etiqueta y APK.** Bloque A cerrado: plugin `v6.31.1` (ZIP sha256 b6efbf90…) y app `v0.8.1` (PR #25, sin APK). Bloque 0 (Paso 0) respondido.

## Ramas y PR

| Repo | Rama | Estado |
|---|---|---|
| Atora-LMS-6 | `feat/6.32.0-ia` | PR #65 (CI) → fusionar, etiqueta v6.32.0, ZIP |
| atora-mobile | `feat/0.9.0-ia` | pusheada; PR tras fusionar 6.32.0 (el CI de la app usa el plugin de main) |

## Falta

- [x] 6.32.0: pruebas (asistente con proveedor simulado y límite 429; no matriculado 404; estudiante en sugerencia 403; prompt sin nombre ni correo; sugerencia no cambia nota ni avisa; indicio fuera de rutas del estudiante; recorte de puntajes; función desactivada → 404 y sin capacidad); seed/CI con `ATORA_AI_FAKE`; changelog, MOBILE-API-V1, ESTADO; PR #65. **Falta**: CI verde, fusionar, etiqueta, ZIP.
- [ ] App 0.9.0 (código hecho): botón Preguntar en la lección; sugerencia al calificar; Jest; recorridos `estudiante-asistente` y `docente-sugerencia-ia`; changelog, README, PRUEBA-TELEFONO; etiqueta y **APK preview**.

## Respuestas del Paso 0 (Bloque 0)

1. El asistente estaba atado a AJAX (`ajax_chat`: `$_POST`, nonce, `wp_send_json`): extraído a `ATORA_AI_Assistant_Service` (commit propio, sin cambio de comportamiento). `CLMS_AI_Grading::evaluate()` ya se podía llamar sin AJAX, pero evalúa con criterios en lenguaje natural y una sola nota: la sugerencia por criterio de rúbrica es un servicio nuevo (`ATORA_AI_Grading_Suggestion_Service`) sobre el mismo cliente (`CLMS_AI_Manager::chat_with_meta`).
2. El asistente usa la base de conocimiento del curso (embeddings con respaldo por palabras), no el texto de la lección; la app agrega el contenido de la lección.
3. `CLMS_AI_Log` guarda en una opción (tope de registros) usuario, rol, proveedor, modelo, tokens, costo estimado y contexto; no había límite por usuario y día: solo el limitador por usuario/IP de 15 cada 5 min del asistente y el horario por rol de los copilotos.
4. SpeedGrader web pide una revisión IA (`request_ai_assessment`, publicación solo si el nivel de automatización lo permite) y guarda `_clms_ai_review_*` (nota y devolución sugeridas) con la acción "aceptar borrador IA".
5. Al proveedor iban el nombre del estudiante (prompt del asistente y carga de la revisión IA de SpeedGrader) y el texto de la entrega; ahora solo el texto necesario.

## Decisiones menores

1. Revisión: se reclama bajo `GET_LOCK` por entrega (atómico entre procesos, también el primer guardado) y se **devuelve** con comparar y reemplazar si el guardado falla después. Error de base de datos: se detecta verificando que el estado y la nota decididos quedaron guardados (los avisos de otros listeners no cuentan).
2. Se eliminó el camino sin revisión del servicio: solo lo usaban SpeedGrader y la API, que ahora la exigen. La web sin el campo (página vieja) pide recargar.
3. La prueba de concurrencia real vive en el plugin (`scripts/e2e-concurrent-grade.sh`) y la corre el CI de la app, que es el que tiene el WordPress con HTTP. En local, la carrera del primer guardado no se pudo provocar con el código anterior (ventana muy corta); el diseño nuevo la elimina por construcción.
4. El interruptor por función gobierna la app y `/discovery`; el asistente web conserva su interruptor de copiloto (no se apaga de golpe en academias que ya lo usan), pero comparte el límite diario y el registro de uso.
5. El aviso de IA en el asistente web es una línea siempre visible (no solo la primera vez).
6. Sin el módulo `ai` activo, las funciones no se declaran (no hay gestor que atienda); el seed E2E lo activa.
7. `GET /teacher/submissions/{id}` suma `ai_suggestion_available` para que la app muestre el botón solo en tareas abiertas.
8. En el CI de la app, el cron (sugerencia asíncrona) se dispara dentro del contenedor cada 3 s: el sitio vive en la URL del emulador y el cron por petición no llega.
9. La conversación del asistente vive en memoria (no en disco) y se borra al cerrar sesión; el aviso de IA "primera vez" sí se recuerda en el teléfono.
