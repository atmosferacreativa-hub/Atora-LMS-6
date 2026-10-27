# Clases en vivo (6.39.0)

ATORA **integra** Zoom, Google Meet y Microsoft Teams: crea la reunión en la cuenta de la academia, entrega el enlace
a quien corresponde, registra asistencia y publica la grabación. **No transmite video**: la clase ocurre en el
proveedor. También se acepta un enlace cargado a mano (cualquier servicio).

## Configuración (Comunicación → Zoom / Meet / Teams)

| Proveedor | Qué se necesita | Notas |
|---|---|---|
| Zoom | Server-to-Server OAuth (Account ID, Client ID, Client secret) y el secret token del webhook | Webhook: `/wp-json/atora/v1/webhooks/zoom` (eventos `meeting.started`, `meeting.ended`, `recording.completed`). Responde la validación de URL. |
| Google Meet | La conexión de Google Calendar del módulo de calendario | La grabación la inicia quien organiza: la API de Calendar no puede activarla. |
| Microsoft Teams | Inquilino, aplicación, secreto y organizador (usuario de Microsoft 365) | Permisos de aplicación en Entra ID: `OnlineMeetings.ReadWrite.All`, `OnlineMeetingArtifact.Read.All`, `OnlineMeetingRecording.Read.All`, y una *application access policy* de Teams sobre el organizador. |

Los secretos no se muestran después de guardarlos; un campo vacío conserva el valor y «borrar» lo elimina.

## Cómo funciona

- **Una reunión por clase.** Guardar la lección no crea otra; reprogramar actualiza la existente.
- **Hora.** Se guarda la hora local de la clase con su zona y también en UTC. La web la muestra en la zona de quien
  mira (o la de la clase); la app, en la del teléfono.
- **Enlace.** Se entrega de 10 minutos antes del inicio a 15 minutos después del final, solo a quien tiene acceso a
  la lección. El docente recibe el enlace de anfitrión cuando el proveedor lo da.
- **Recordatorios.** 24 h y 1 h antes, por la cola de correo; se reinician si la clase se reprograma.
- **Asistencia.** Al terminar la clase: Zoom y Teams entregan el informe; ≥ 80 % del tiempo presente, ≥ 50 % tarde,
  menos ausente. Los invitados sin cuenta quedan registrados con su nombre.

## Grabación: decide el docente

| Modo | Qué pasa |
|---|---|
| Sin grabación | No se pide grabar. Puede agregar una grabación después si quiere. |
| La subo después | Sube un video (MP4, WebM, MOV o M4V; se valida el contenido) o pega un enlace. |
| Enlace automático del proveedor | Zoom graba en la nube y el enlace llega con `recording.completed`; Teams graba solo y el enlace se busca al terminar; en Meet se guarda si existe. |

El docente puede cambiar o quitar la grabación en cualquier momento; el proveedor no reemplaza lo que el docente puso
o quitó. Si la grabación vive en el proveedor, abrirla depende de cómo esté compartida allí (la lección lo avisa).

## Evidencia

- Pruebas: `tests/integration/LiveClassesTest.php`, con todas las llamadas HTTP simuladas.
- Sin validación con cuentas reales de Zoom, Google ni Microsoft 365.
