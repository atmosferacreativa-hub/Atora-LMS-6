# Orden A — CRM: retirar el v1 y dejar el v2 completo · plugin 6.34.0

Punto de partida: plugin 6.33.3. Guarda esta orden como docs/ordenes/ORDEN-A-CRM.md y lleva el avance
en docs/ordenes/PROGRESO-ORDEN-A.md. Ejecútala completa, bloque por bloque, sin esperar mi confirmación.

## Reglas
- PR por bloque contra `main`, con CI completo en verde antes de fusionar. El CI es la referencia.
- `php -l` en cada archivo tocado antes de cada commit.
- Cada cambio de comportamiento con una prueba que falla antes y pasa después.
- Los movimientos de código (bloque 1) van en commits propios, sin cambios de comportamiento, y las
  pruebas existentes pasan sin modificarlas.
- No mover etiquetas publicadas. Una sola versión al final: 6.34.0.
- Decide lo menor y anótalo. Para solo si el código contradice la orden.
- Continuidad: si el uso se agota, commit y push de la rama en curso (WIP, sin fusionar), actualiza
  PROGRESO-ORDEN-A.md y detente. Si el entorno lo permite, programa la reanudación 5 h 10 min después.

## Contexto verificado
- `docs/CRM-V1-V2-PARIDAD.md` documenta que el v2 depende del v1 en tiempo de ejecución: permisos
  (`can_access_crm`, `can_manage_crm`), alcance (`has_global_contact_scope`,
  `get_accessible_contact_user_ids`), `log_activity`, `add_tag`/`remove_tag`, `get_timeline` y
  `sync_email_queue_to_conversation`.
- Además usan `\ATORA\CRM\CRM` (v1) desde fuera del CRM: contacts-core, búsqueda, menús (navegación,
  páginas académicas, widgets), perfil del frontend, matrícula de programa por AJAX, gestor de
  matrículas, automatización y `class-v5-modules.php`.
- v1 y v2 comparten las mismas tablas (`atora_contacts`, etiquetas, actividades, notas,
  conversaciones). No hay migración de datos: se mueve código.

## Bloque 0 — Multisitio declarado no compatible
- Bloquear la activación en red con un mensaje claro.
- Si se activa en un sitio dentro de una red, aviso en el administrador de que no está soportado.
- Declararlo en `readme.txt` y en `docs/ESTADO.md`.
- Prueba: la activación en red se rechaza.

## Bloque 1 — Primitivas compartidas (movimiento de código, sin cambios de comportamiento)
1. Crear un servicio compartido (por ejemplo, `ATORA\Contacts\Contacts_Primitives` en
   `includes/contacts-core/`) con todas las funciones del v1 que hoy usan el v2 y el resto del plugin.
2. Reapuntar cada llamada (las del v2 y las de los archivos externos) al servicio nuevo.
3. Mientras exista el v1, sus métodos delegan en el servicio para no duplicar lógica.
4. Las pruebas actuales pasan sin modificarlas.

## Bloque 2 — Portar al v2 lo que solo tiene el v1
1. Exportación CSV de contactos, registro de mensajes y cola de correo, con mapeo de columnas y
   streaming (sin cargar todo en memoria).
2. Registro de mensajes enviados y su API (`/crm/messages/log`, `/summary`, filtros por canal y estado)
   en el v2, con las mismas reglas de permiso.
3. Mensajes entrantes de WhatsApp y Telegram hacia la conversación (hooks
   `atora/whatsapp/message_received` y `atora/telegram/message_received`), con la resolución de
   teléfono o chat y la detección de coincidencias ambiguas.
4. Etiquetado automático diario (`#inactive-30d`, `#high-engagement`, `#potential-churn`) programado
   desde el v2, junto con `Scoring_Service::recalculate_all()` en una tarea programada.
5. Marcar correo abierto y mensaje leído manualmente, conservando la fecha existente.
6. Pruebas de cada punto.

## Bloque 3 — Eliminar el CRM v1
1. Comprobar con grep que no queda ninguna referencia a `\ATORA\CRM\CRM` ni a `modules/crm/`.
2. Borrar `modules/crm/` y su registro en el sistema de módulos. Las tareas programadas del v1 se
   desprograman al actualizar.
3. Las páginas de menú del v1 redirigen a las equivalentes del v2 (sin enlaces rotos).
4. Prueba de humo: activar el plugin, abrir cada pantalla del CRM v2 y llamar a cada ruta REST del CRM
   sin errores fatales.

## Bloque 4 — Arreglar la integración con la automatización
`modules/automation/class-automation-engine.php` llama a `CRM::get_contact_id_by_user()`, que no
existe (un `method_exists()` lo oculta y esas acciones no hacen nada). Implementarlo en el servicio
compartido y quitar el `method_exists()`. Prueba: una automatización que etiqueta o mueve de etapa a
un contacto funciona de verdad.

## Bloque 5 — CRM v2 completo
1. Campos personalizados por contacto (texto, número, fecha, lista, casilla), usables en segmentos,
   importación, exportación y automatizaciones.
2. Importación CSV con mapeo de columnas, vista previa, validación, opción de actualizar existentes y
   reporte de errores; por lotes para archivos grandes.
3. Deduplicación: detección por correo y teléfono, y fusión que conserva actividad, notas, etiquetas,
   negocios y conversaciones de ambos.
4. Permisos por rol (administrador, comercial, coordinador académico, docente con alcance a sus
   estudiantes) y por institución. Ningún usuario ve contactos de otra institución.
5. Registro de auditoría: quién creó, editó, fusionó, exportó o borró contactos.
6. Pruebas por servicio del v2 (contactos, negocios, tareas, listas, campañas, secuencias, seguimiento,
   scoring, reportes). Meta: cada servicio con al menos sus casos principales y de permisos.

## Cierre
- 6.34.0, changelog, `docs/ESTADO.md` (CRM v1 retirado; qué queda en beta si algo no llegó), etiqueta
  `v6.34.0`, ZIP verificado y release en GitHub.
- Actualizar `docs/CRM-V1-V2-PARIDAD.md` como histórico: retiro completado.

## Reporte final (máximo 15 líneas)
Por bloque: hecho o no y cómo se probó. Cuántas llamadas al v1 se reapuntaron. Qué del v1 no se portó
y por qué. Cobertura de pruebas del CRM antes y después. Enlace al release y SHA-256 del ZIP.