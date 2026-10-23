# Progreso — Orden 1 (plugin 6.33.3 + app 1.0.2)

- Inicio: 2026-10-23
- Estado: en curso

## Parte A — Plugin 6.33.3

- [x] Rama `release/6.33.3` existe en GitHub.
- [x] PR abierto contra `main`: https://github.com/atmosferacreativa-hub/Atora-LMS-6/pull/73
- [x] CI completo en verde (incluye WordPress 7.1.3).
- [x] Merge a `main`.
- [x] Tag `v6.33.3` (sin mover tags existentes).
- [x] ZIP `dist/atora-lms-6.33.3.zip` generado y verificado (`inspect-dist.py`).
- [x] Release en GitHub con ZIP adjunto: https://github.com/atmosferacreativa-hub/Atora-LMS-6/releases/tag/v6.33.3
- [x] Rama remota borrada.

## Parte B — App 1.0.2 (material zoom)

- [x] Seed del plugin para e2e (`_clms_lesson_resources` con PDF + imagen): PR https://github.com/atmosferacreativa-hub/Atora-LMS-6/pull/74 (merge a `main`).
- [x] PDF: zoom gestos + botones (+/−/ajustar) + persistencia de zoom + scroll horizontal.
- [x] Imágenes: visor fullscreen con zoom/doble toque/arrastre.
- [x] Texto lección: control tamaño de letra (A−/A+) persistente + respeta tamaño del sistema.
- [x] Giro: visor PDF/imágenes permite horizontal.
- [x] Certificados heredan el visor.
- [x] Tests Jest (font size + config viewer).
- [x] Recorrido pantalla Maestro `material-zoom` agregado.
- [x] Versión 1.0.2 + versionCode + changelog/README + PRUEBA-TELEFONO.
- [ ] PR app 1.0.2 abierto y CI en verde: https://github.com/atmosferacreativa-hub/atora-mobile/pull/30
- [x] PR app 1.0.2 abierto y CI en verde: https://github.com/atmosferacreativa-hub/atora-mobile/pull/30
- [x] Merge a `main`.
- [x] Tag `v1.0.2`.
- [x] APK + AAB generados en GitHub Actions y adjuntos al release: https://github.com/atmosferacreativa-hub/atora-mobile/releases/tag/v1.0.2
