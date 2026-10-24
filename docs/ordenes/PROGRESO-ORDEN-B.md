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

- [ ] Reproductor web con adaptador API (1.2 y 2004) + navegación multi-SCO.
- [ ] Persistencia CMI por intento y reanudación.
- [ ] Commits por lotes + resiliencia red + cierre ventana.
- [ ] Integración académica (completitud + notas + intentos).
- [ ] Informe docente.
- [ ] Pruebas API 1.2/2004 + persistencia + permisos.
- [ ] Paquetes de prueba con licencia compatible.

## Bloque 3 — App 1.1.0

- [ ] WebView con adaptador API + API móvil.
- [ ] Sin conexión (descarga, reproducción local, cola commits, no retroceder).
- [ ] Versionado de paquete descargado.
- [ ] Pruebas Jest + recorridos pantalla (`scorm-online`, `scorm-sin-conexion`).
- [ ] Cierre app 1.1.0 (tag, APK/AAB, docs).

## Cierre plugin — 6.35.0

- [ ] Versión 6.35.0 + changelog + `docs/ESTADO.md`.
- [ ] `docs/SCORM.md`.
- [ ] Tag `v6.35.0` + ZIP verificado + release.

