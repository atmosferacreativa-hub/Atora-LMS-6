# Orden C — Comunicación (Email Engine, Newsletter, Automatización) · plugin 6.36.0

Punto de partida: plugin 6.35.0. Guarda esta orden como docs/ordenes/ORDEN-C-COMUNICACION.md y lleva el avance en
docs/ordenes/PROGRESO-ORDEN-C.md. Ejecútala completa, bloque por bloque, sin esperar mi confirmación.

## Reglas
- PR por bloque, CI completo en verde antes de fusionar, php -l en cada archivo tocado.
- Cada función con una prueba que falla antes y pasa después. Ninguna prueba se satisface con comentarios, texto
  ni lectura del código fuente como texto.
- No mover etiquetas. Nada se envía a correos reales en pruebas: proveedor simulado y buzón de captura
  (Mailpit en el CI).
- Decide lo menor y anótalo; para solo si el código contradice la orden.
- Cada reporte de CI lista las pruebas o recorridos ejecutados con su resultado y el enlace al run.
- Continuidad: si el uso se agota, rama subida (WIP), PROGRESO al día y hora de reanudación; si el entorno lo
  permite, programa la reanudación 5 h 10 min después.

## Bloque 0 — Paso 0 (lectura)
Lee los módulos de Email Engine, Newsletter y Automatización, sus tablas, cron y REST, y cómo se cruzan con
contacts-core y CRM v2. Anota en PROGRESO:
- el mapa de envíos actual (quién encola, quién envía, qué proveedor, qué registra);
- la lista completa de disparadores de automatización, marcando los duplicados heredados del v1;
- dónde se guardan las listas y los suscriptores frente a los contactos del CRM.
Condición de parada: si hay dos colas de envío independientes, propón cuál queda antes de tocar código.

## Bloque 1 — Email Engine
1. Una sola cola de envío para todo el plugin (transaccionales, newsletter, automatizaciones):
   - prioridad: transaccional antes que masivo;
   - reintentos con espera creciente;
   - límite por minuto y por hora configurable por proveedor;
   - bloqueo (GET_LOCK) para que dos cron no envíen lo mismo;
   - idempotencia por mensaje.
2. Webhooks de todos los proveedores soportados con verificación de firma (no solo SendGrid y Mailgun):
   - rechazar sin firma o con firma inválida;
   - protección contra repetición (timestamp + id);
   - registrar rebotes, quejas y bajas en el contacto, y suprimir los envíos a esas direcciones.
3. Plantillas:
   - biblioteca de plantillas base (bienvenida, matrícula, recordatorio de tarea, nota publicada, certificado,
     recuperación de cuenta, newsletter);
   - editor con bloques y vista previa en escritorio y móvil;
   - variables validadas (una variable desconocida es error al guardar, no texto vacío al enviar);
   - texto plano generado automáticamente.
4. Panel de entregabilidad:
   - enviados, entregados, rebotes, quejas, aperturas y clics por día y por proveedor;
   - verificador DNS del dominio remitente (SPF, DKIM, DMARC) con instrucciones de corrección;
   - envío de prueba.
5. Pruebas: firma válida e inválida y repetición por proveedor; reintento y límite; dos workers no duplican;
   una dirección con rebote duro no recibe más; variable desconocida rechazada.

## Bloque 2 — Newsletter
1. Listas unificadas con contacts-core: un suscriptor es un contacto. Migración idempotente de los suscriptores
   actuales, sin duplicar contactos (por email normalizado). Script de solo lectura previo con el conteo.
2. Segmentos resueltos en la base de datos (consulta paginada), nunca cargando todos los suscriptores en memoria:
   por lista, etiqueta, curso, programa, institución, estado de matrícula, scoring del CRM y actividad.
3. Envío por lotes a través de la cola del Bloque 1, con pausa, reanudación y cancelación; un envío no se manda dos
   veces a la misma dirección aunque el cron se cuelgue a la mitad.
4. Doble opt-in configurable, enlace de baja en un clic (List-Unsubscribe y List-Unsubscribe-Post), página de
   preferencias y registro del consentimiento (fecha, IP, origen).
5. Métricas por envío: entregados, aperturas, clics por enlace, bajas, rebotes. Prueba A/B de asunto opcional.
6. Pruebas: segmento con 10.000 contactos sembrados sin pasar un límite de memoria fijado en la prueba; reanudación
   tras corte sin duplicados; baja en un clic; doble opt-in.

## Bloque 3 — Automatización
1. Un solo conjunto de disparadores (sin los duplicados del v1), con nombre y payload documentados en
   docs/AUTOMATIZACION.md. Migración de los flujos existentes que usen los disparadores eliminados.
2. Motor:
   - pasos de espera (tiempo y hasta una fecha), condiciones con ramas sí/no, acciones (email, etiqueta,
     tarea al docente, webhook, mensaje en el buzón, cambiar etapa del CRM);
   - salida al cumplir un objetivo.
3. Protecciones:
   - un contacto no entra dos veces en el mismo flujo si no está permitido;
   - detección de bucles (un flujo que dispara otro que dispara el primero);
   - límite de acciones por contacto y por día;
   - reanudación tras fallo desde el último paso confirmado;
   - registro por contacto de qué paso ejecutó y cuándo.
4. Editor visual en el administrador: lienzo con pasos y ramas, validación antes de activar, modo prueba con un
   contacto, versionado del flujo (los contactos en curso siguen la versión con la que entraron).
5. Plantillas de flujo: bienvenida a un curso, recordatorio de tarea por vencer, estudiante en riesgo (usa el
   servicio de riesgo existente), recuperación de inactivos, felicitación por certificado.
6. Pruebas: espera y reanudación por cron; ramas; bucle bloqueado; no reingreso; fallo a la mitad y reanudación sin
   repetir acciones; versionado con contactos en curso.

## Cierre
- 6.36.0, changelog, docs/ESTADO.md, docs/EMAIL.md, docs/NEWSLETTER.md, docs/AUTOMATIZACION.md.
- Etiqueta v6.36.0, ZIP verificado con inspect-dist.py y release en GitHub con SHA-256.
- Reporte final (máx. 15 líneas): por bloque, qué se hizo y cómo se probó; proveedores con firma verificada;
  resultado de la prueba de 10.000 contactos (tiempo y memoria); disparadores eliminados y migrados; enlaces.

