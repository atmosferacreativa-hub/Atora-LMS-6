# Progreso — Orden B (SCORM · plugin 6.35.0 · app 1.1.0)

- Inicio: 2026-10-23
- Estado: en curso

## Bloque 0 — Paso 0 (lectura)

- [x] Lectura de `includes/library/`, `modules/h5p/` y modelo de lección.
- [x] Decisión documentada: dónde viven paquetes SCORM y cómo se enlazan a una lección.

Decisiones (Bloque 0):
- **Tipo `scorm` en Biblioteca sin romper lo existente**:
  - Se agrega `scorm` de forma aditiva a `includes/library/class-academic-library-policy.php::RESOURCE_TYPES`.
  - No se cambia el significado de tipos existentes (`document`, `video`, etc.) ni el esquema de versionado: SCORM usa las mismas tablas (`atora_library_items` + `atora_library_versions`) y solo extiende validaciones y metadata (en `metadata_json`).
- **Archivos extraídos (ruta y servido)**:
  - Se descomprime el ZIP a `wp-content/uploads/atora-library/scorm/<institution_id>/<item_id>/v<version_number>/`.
  - Se sirve como archivos estáticos desde la carpeta de uploads (mismo origen), con protección anti-ejecución (`.htaccess`/`web.config`/`index.html`) y un allowlist de extensiones al extraer.
- **Reuso de `modules/h5p/`**:
  - No se reutiliza su modelo (H5P no usa Biblioteca ni extracción “segura” de ZIP a storage versionado compartible).
  - No se reutiliza su tracking/xAPI ni su REST.
  - Se toma solo como referencia conceptual de “contenido empaquetado”, pero SCORM se implementa como feature nueva basada en Biblioteca + tablas propias de intentos/CMI.
- **Enlace paquete ↔ lección**:
  - Se enlaza como **recurso dentro de la lección** (se extiende `_clms_lesson_resources` con `type=scorm`), porque hoy no existe UI activa para `_clms_lesson_type` y el editor ya soporta recursos.
  - El recurso `scorm` guarda como mínimo `library_item_id` y `library_version_id` (0 = seguir la última versión).
  - La API móvil normaliza el recurso (como los demás) para que la app lo abra con un viewer SCORM.
- **Tablas nuevas (Bloque 2: intentos y CMI)**:
  - Se agregan en el instalador (dbDelta) sin tocar tablas existentes.
  - Esquema propuesto (nombres sujetos a ajuste mínimo al implementarlo):
    - `atora_scorm_attempts`: un intento por usuario+lección+versión, con estado (incomplete/completed/passed/failed), puntaje, tiempos (session/total), `suspend_data`/`lesson_location`, y timestamps.
    - `atora_scorm_cmi`: almacenamiento de valores CMI por intento (`attempt_id`, `cmi_key`, `cmi_value`, timestamps) o bien `cmi_json` por intento si resulta más eficiente; se decide al implementar para mantener compatibilidad con consultas de informe docente.

## Bloque 1 — Paquetes en la biblioteca (plugin)

- [x] Subida ZIP + validación `imsmanifest.xml` + detección de versión.
- [x] Descompresión segura (zip slip, symlink, extensiones, límites, directorio).
- [x] Ítem `scorm` con versionado (cada versión instala en `vN/`, no pisa versiones anteriores).
- [x] Enlazado a lección (recurso `type=scorm` en `_clms_lesson_resources`).
- [x] Pruebas (manifiestos válidos/ inválidos + seguridad ZIP + recurso SCORM).

PR: #90 (merge 2026-10-24).

## Bloque 2 — Reproductor y seguimiento (plugin)

- [x] Reproductor web con adaptador API (1.2 y 2004) + navegación multi-SCO.
- [x] Persistencia CMI por intento y reanudación.
- [x] Commits por lotes + resiliencia red + cierre ventana.
- [x] Integración académica (completitud + notas + intentos).
- [x] Informe docente.
- [x] Pruebas API 1.2/2004 + persistencia + permisos.
- [x] Paquetes de prueba con licencia compatible.

PR: #92 (merge 2026-10-24).

## Bloque 2.1 — Arreglos

- [x] Nunca retroceder por commits fuera de orden (intento/score/success).
- [x] `start_attempt` valida que el paquete esté enlazado a la lección.
- [x] `.htaccess` de protección corregido + migración idempotente.
- [x] Límite de intentos por lección (`_clms_scorm_max_attempts`) con 409 cuando se agota.
- [x] Subida/versionado SCORM: requiere `manage_options` o `atora_upload_scorm` (default admin).
- [x] Documentación de seguridad/limitaciones en `docs/SCORM.md`.

PR: #94 (merge 2026-10-24).

## Bloque 3 — App 1.1.0

- [x] Plugin: API SCORM móvil (`atora-mobile/v1/scorm/*`) + commits idempotentes.
- [x] WebView con adaptador API + API móvil.
- [x] Sin conexión (descarga, reproducción local, cola commits, no retroceder).
- [x] Versionado de paquete descargado (checksum de la versión).
- [x] Pruebas Jest + recorridos pantalla (`scorm-online`, `scorm-sin-conexion`).
- [ ] Cierre app 1.1.0 (tag, APK/AAB, docs).

PRs:
- Plugin seed E2E SCORM (para flows): #98 (merge 2026-10-24).
- Plugin API móvil SCORM: #96 (merge 2026-10-24).
- App (visor + offline + flows): atora-mobile#33 (merge 2026-10-24).

## Cierre plugin — 6.35.0

- [ ] Versión 6.35.0 + changelog + `docs/ESTADO.md`.
- [ ] `docs/SCORM.md`.
- [ ] Tag `v6.35.0` + ZIP verificado + release.

