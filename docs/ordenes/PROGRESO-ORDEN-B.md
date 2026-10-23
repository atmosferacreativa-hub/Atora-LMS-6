# Progreso — Orden B (SCORM · plugin 6.35.0 · app 1.1.0)

- Inicio: 2026-10-23
- Estado: en curso

## Bloque 0 — Paso 0 (lectura)

- [x] Lectura de `includes/library/`, `modules/h5p/` y modelo de lección.
- [x] Decisión documentada: dónde viven paquetes SCORM y cómo se enlazan a una lección.

Decisiones (Bloque 0):
- **Fuente de verdad**: cada paquete SCORM vive como ítem de Biblioteca (`atora_library_items.resource_type = scorm`).
- **Versionado**: cada subida crea una fila nueva en `atora_library_versions` con `attachment_id` (ZIP) + `metadata_json` (manifiesto parseado, edición 1.2/2004, organizaciones, SCO inicial, etc.).
- **Archivos extraídos**: se descomprime el ZIP a `wp-content/uploads/atora-library/scorm/<institution_id>/<item_id>/v<version_number>/` con reglas anti-ejecución (p.ej. `.htaccess`/`web.config`/`index.html`) y solo extensiones permitidas.
- **Enlace a lección**: se implementa **tipo de lección** `scorm` usando `_clms_lesson_type = scorm` y meta `_clms_scorm_library_item_id` + `_clms_scorm_library_version_id` (0 = seguir la última versión). Esto evita romper el esquema actual de `_clms_lesson_resources`.

## Bloque 1 — Paquetes en la biblioteca (plugin)

- [ ] Subida ZIP + validación `imsmanifest.xml` + detección de versión.
- [ ] Descompresión segura (zip slip, symlink, extensiones, límites, directorio).
- [ ] Ítem `scorm` con versionado y compatibilidad de intentos anteriores.
- [ ] Enlazado a lección (tipo o recurso).
- [ ] Pruebas (manifiestos válidos/ inválidos + seguridad + versionado).

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

