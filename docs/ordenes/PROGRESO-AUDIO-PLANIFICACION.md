# Progreso — Audio y planificación híbrida (plugin 6.37.0 · app 1.2.0)

Orden: `docs/ordenes/ORDEN-AUDIO-PLANIFICACION.md`. Inicio: 2026-10-27.

## A. Audio
- [x] A1 plugin (PR #112): validación (MP3/M4A/OGG, MIME real, tamaño, adjunto), transcripción, aviso en el editor,
  reproductor web (velocidad, ±15 s, posición en el navegador, duración/tamaño/formato, descarga, transcripción),
  API móvil (duración, formato, compatibilidad, transcripción, posición), matriz en `docs/AUDIO.md`, seed E2E.
  Evidencia: `tests/Media/LessonAudioUnitTest.php`, `tests/integration/LessonAudioTest.php`.
- [ ] A2 app 1.2.0: reproductor nativo, descarga sin conexión, posición sincronizada, recorrido Maestro (rama
  `block/audio-app`, espera la 1.1.0).
- [ ] Carga desde el teléfono (app docente).

## B. Planificación híbrida
- [x] B0 lectura: el asistente (`lms_instructor_assistant`) publica lecciones propias pero no edita las ajenas
  salvo delegación (`content`); el coordinador no gestiona lecciones. Decisión: el asistente con delegación prepara
  y edita la planificación, no publica ni cambia la evidencia; el coordinador no tiene acceso.
- [x] B1 asistente sin IA (web, también en el navegador del teléfono). PR #114.
- [x] B2 IA opcional (función `hybrid_planner`, apagada por defecto). PR #114.
- [ ] B3 app nativa (docente desde la app): pendiente; hoy se usa desde el navegador del teléfono.

## Validación real
- No ejecutada: matriz de compatibilidad sin pruebas en dispositivos.
