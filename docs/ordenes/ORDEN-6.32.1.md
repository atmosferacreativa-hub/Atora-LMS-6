# Orden 6.32.1 — Control central de la IA (antes de la Fase 6)

Fase 5 recibida. Antes de la Fase 6, saca el plugin 6.32.1 con esto:

1. Control central de la IA. Todo pasa por `class-ai-manager.php` (`chat`, `chat_with_meta`, embeddings,
   transcripción): registro en `atora_ai_usage`, límites, tope mensual de la academia y el filtro de
   datos personales. Hoy solo el asistente y la sugerencia lo aplican, y hay al menos 13 módulos que
   llaman a la IA por su cuenta (alertas, mensajería, sentimiento, retroalimentación, plan de mejora,
   exámenes, copilotos, quick wins, ruta de aprendizaje, asistente docente, ajustes…). Cada llamada
   declara su función (`feature`) para el panel de consumo.
2. Datos personales: ningún prompt lleva nombre, correo ni identificadores de estudiantes. Revisa en
   especial los borradores de mensajes de `class-messaging.php` (líneas ~1035 y ~1082) y las alertas.
   Si un texto necesita dirigirse al estudiante por su nombre, se usa un marcador ("{{nombre}}") que se
   reemplaza después de recibir la respuesta, nunca antes de enviarla.
3. Proveedor simulado: además de la constante `ATORA_AI_FAKE`, que no se active nunca si
   `wp_get_environment_type()` es `production`.
4. Pruebas: una llamada desde un módulo cualquiera queda registrada y cuenta para el tope; al
   alcanzarse el tope, todos los módulos se detienen con un error claro; ningún prompt de ningún
   módulo contiene el nombre ni el correo del estudiante de prueba.
5. 6.32.1, changelog, `ESTADO.md`, etiqueta y ZIP. Sin cambios en la app ni APK.
