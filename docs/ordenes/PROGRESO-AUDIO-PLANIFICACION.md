# Progreso — Audio y planificación híbrida (plugin 6.37.0 · app 1.2.0)

Orden: `docs/ordenes/ORDEN-AUDIO-PLANIFICACION.md`. Inicio: 2026-10-27.

## A. Audio
- [x] A1 plugin: validación (MP3/M4A/OGG, MIME real, tamaño, adjunto), transcripción, aviso en el editor,
  reproductor web (velocidad, ±15 s, posición en el navegador, duración/tamaño/formato, descarga, transcripción),
  API móvil (duración, formato, compatibilidad, transcripción, posición), matriz en `docs/AUDIO.md`, seed E2E.
  Evidencia: `tests/Media/LessonAudioUnitTest.php`, `tests/integration/LessonAudioTest.php`.
- [ ] A2 app 1.2.0: reproductor nativo, descarga sin conexión, posición sincronizada, recorrido Maestro.
- [ ] Carga desde el teléfono (app docente).

## B. Planificación híbrida
- [ ] B0 lectura: permisos reales de asistente y coordinador; servicios de creación de lecciones.
- [ ] B1 asistente sin IA (web).
- [ ] B2 IA opcional.
- [ ] B3 app (docente desde el teléfono).

## Validación real
- No ejecutada: matriz de compatibilidad sin pruebas en dispositivos.
