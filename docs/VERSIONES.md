# Versiones publicadas y no publicadas — ATORA LMS

Una versión **publicada** tiene etiqueta `vX.Y.Z` en GitHub y un ZIP verificado contra el commit etiquetado.
Las versiones de esta lista **no publicadas** existieron solo en `main` (desarrollo): no tienen etiqueta, no se
distribuyó su ZIP y ningún sitio debe tenerlas instaladas. Su contenido está completo en la versión publicada que
las sigue. No se etiquetan a posteriori.

| Versión | Estado | Commit en `main` | Incluida en | Motivo |
|---|---|---|---|---|
| 6.36.0 | **no publicada** (desarrollo) | `004f1f2` (2026-10-27) | 6.39.0 | Analíticas. El mismo día se fusionaron 6.37.0 y 6.39.0 y la etiqueta no se creó antes de que `main` avanzara. |
| 6.37.0 | **no publicada** (desarrollo) | `49deedd` (2026-10-27) | 6.39.0 | Audio y planificación híbrida. Mismo motivo. |
| 6.38.0 | **no existe** | — | 6.39.0 | Comunicación se planeó como 6.38.0, pero se fusionó después de Clases en vivo y no hubo un punto de `main` con solo Comunicación. |
| 6.39.0 | publicada | `a60f56c` | — | — |
| 6.40.0 | publicada | `8708d46` | — | — |
| 6.40.1 | publicada | `ce7ba24` | — | — |

Para actualizar un sitio desde 6.35.0 o anterior se instala la última publicada: las migraciones de 6.36.0 y 6.37.0
corren igual (son idempotentes y van en cadena).
