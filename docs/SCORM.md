# SCORM (plugin)

## Resumen

Atora LMS soporta paquetes **SCORM 1.2** y **SCORM 2004 (3.ª/4.ª edición)** como recurso de la **Biblioteca académica**.

- Cada paquete se sube como un ítem de Biblioteca con `resource_type=scorm`.
- Cada versión se instala en una carpeta propia (`vN/`), sin pisar versiones anteriores.
- La reproducción corre en el mismo dominio (uploads), con un reproductor web standalone.

## Instalación del paquete

- Al crear una versión SCORM, el ZIP se valida (manifest) y se extrae de forma segura.
- Se instala en `wp-content/uploads/atora-library/scorm/<institution_id>/<item_id>/v<version_number>/`.
- La carpeta extraída se “endurece” con `index.html` y `.htaccess` para negar ejecución de scripts.

## Permisos para subir/versionar

Subir o versionar paquetes SCORM requiere:

- `manage_options`, o
- la capacidad `atora_upload_scorm`.

Por defecto `atora_upload_scorm` se otorga solo al rol Administrador.

Importante: el contenido SCORM se sirve en el **mismo origen** (misma instalación). Por seguridad, solo se deben subir
paquetes de confianza.

## Enlazar un paquete a una lección

Las lecciones enlazan el paquete desde `_clms_lesson_resources` con un recurso `type=scorm`:

- `library_item_id` obligatorio.
- `library_version_id`:
  - `0` = usa la última versión publicada (`current_version_id` del ítem).
  - `>0` = fija una versión específica.

El runtime (REST `atora/v1/scorm/attempts/start`) solo permite abrir un paquete si está enlazado en esa lección.

## Intentos (límite)

Meta por lección:

- `_clms_scorm_max_attempts`: `0` = sin límite.

Si se llega al límite y no hay un intento abierto, `start_attempt` responde `409 atora_scorm_no_attempts`.

## Estado del paquete

- Intento nuevo: solo si el ítem de la Biblioteca está `published`; si no, `404 atora_scorm_not_available`.
- `archived`: los intentos ya abiertos se pueden reanudar y terminar; no se crean nuevos.
- `draft` y `review`: nunca se lanzan para estudiantes (el docente o el administrador pueden previsualizar).

## Seguimiento, completitud y notas

- Los datos CMI se guardan por intento. Estados (`lesson_status`, `completion_status`, `success_status`) y puntaje
  **nunca retroceden**, ni en la fila del intento ni en los valores CMI, aunque los commits lleguen fuera de orden.
- Completitud de la lección por lección (`_clms_scorm_completion_rule`: `completed`, `passed` o `both`; por defecto
  la opción global `atora_scorm_completion_rule`).
- El puntaje va al libro de notas con la regla de visibilidad existente (borrador/publicado). Qué intento cuenta:
  `best`, `last` o `first` (por lección, `_clms_scorm_attempt_grading`; por defecto la opción global `atora_scorm_attempt_grading`).
- Informe docente: `atora/v1/scorm/report` (estado, puntaje, tiempo, intentos e interacciones por estudiante).

## App móvil

- Rutas `atora-mobile/v1/scorm/*` (capacidad `scorm` en `/discovery`): inicio o reanudación, valores, commit
  idempotente por `client_event_id` con `device_time`, enlace de descarga firmado (HMAC) y temporal.
- Cada intento solo lo lee y escribe su dueño (404 para cualquier otro usuario).
- La app reproduce el paquete descargado sin conexión, encola los commits y los sincroniza al volver la red.
  El token de la sesión nunca llega al JavaScript del paquete.

## Limitaciones conocidas

- El puntaje lo reporta el propio paquete desde el navegador (así funciona SCORM). No debe usarse como evaluación de alto
  impacto o con requerimientos de anti-trampa.
- SCORM en iPhone todavía no está comprobado (ver `docs/ESTADO.md`).
