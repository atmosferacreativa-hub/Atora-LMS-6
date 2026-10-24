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

## Limitaciones conocidas

- El puntaje lo reporta el propio paquete desde el navegador (así funciona SCORM). No debe usarse como evaluación de alto
  impacto o con requerimientos de anti-trampa.

