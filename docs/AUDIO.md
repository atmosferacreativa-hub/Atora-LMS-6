# Audio en la lección (6.37.0)

## Qué se acepta

| Formato | Extensión | MIME aceptados | Estado |
|---|---|---|---|
| MP3 | `.mp3` | `audio/mpeg` | Recomendado |
| AAC en MP4 | `.m4a` | `audio/mp4`, `audio/x-m4a`, `audio/aac` | Recomendado |
| Ogg (Vorbis u Opus) | `.ogg` | `audio/ogg` | Aceptado con aviso |
| WAV | `.wav` | — | **No se acepta** |

- Se comprueban extensión, MIME real del contenido (lo que lee WordPress, no el que declara el navegador), tamaño
  (100 MB por defecto; opción `atora_audio_max_mb` o filtro del mismo nombre) y que sea un adjunto de la biblioteca.
- Si un audio no pasa la validación al guardar la lección, la lección se guarda **sin ese archivo** y el editor
  muestra el motivo.
- No hay conversión en el servidor. Si un archivo no se reproduce en un dispositivo, hay que subirlo de nuevo en
  MP3 o M4A.

## Matriz de compatibilidad (declarada, no comprobada)

Aceptar un archivo no garantiza que se reproduzca: depende del códec y del dispositivo. Esta tabla resume la
compatibilidad conocida de cada formato; **no** es el resultado de una prueba en esos dispositivos. Las columnas
"Comprobado" se llenan solo con pruebas reales.

| Plataforma | MP3 | M4A (AAC) | OGG | Comprobado |
|---|---|---|---|---|
| Chrome, Edge, Firefox (escritorio y Android) | sí | sí | sí | no |
| Safari en macOS | sí | sí | parcial (según versión) | no |
| Safari e iOS (iPhone, iPad), y app en iOS | sí | sí | **puede fallar** | no |
| App ATORA en Android | sí | sí | sí | no (emulador pendiente) |

La API y la web marcan OGG con `compatibility.status = "limited"` y un aviso.

## Para el estudiante

- Web: controles del navegador (pausa, avance), botones −15 s / +15 s, velocidad 0,75× a 2×, la posición se recuerda
  en ese navegador; duración, tamaño y formato visibles; descarga; transcripción desplegable.
- App (1.2.0): mismos controles, posición sincronizada con el servidor (cola de posiciones, clave `audio-{adjunto}`),
  descarga para escuchar sin conexión con el tamaño visible antes de descargar.

## API móvil

Cada recurso `type = "audio"` de `GET lessons/{id}` añade: `duration_seconds`, `audio_format`, `compatibility`
(`status` y `note`), `transcript`, `position_key` y `resume_position_seconds`. `PUT lessons/{id}/position` acepta
`video_key = position_key` de un audio de la lección. Capacidad `lesson_audio` en `/discovery`.

## Fuera de este alcance

Grabación dentro de ATORA, transcripción automática y conversión de formatos en el servidor.
