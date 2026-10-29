# Compatibilidad Plugin – Tema – App

Corte: 2026-10-29. **Publicada** = etiqueta `vX.Y.Z` en GitHub y paquete verificado. **Desarrollo** = solo en `main`
o en un PR abierto; no se instala en producción.

| | ATORA LMS (plugin) | Atora Meridian (tema) | ATORA Mobile (app) |
|---|---|---|---|
| Última publicada | **6.40.1** | 3.3.0 en `main`, sin etiqueta | **1.3.0** |
| En desarrollo | 6.41.0 (roles, foto de perfil, pedidos por institución, foros) | — | 1.4.0 (pre-calificación del asistente, foto de perfil) |

## Requisitos entre piezas

| Pieza | Requiere | Con una versión anterior |
|---|---|---|
| App 1.3.0 (docente: curso, asistencia, clase en vivo, planificación) | LMS **6.40.0**; **6.40.1** para ver en Hoy una clase que sigue después de medianoche | Las pantallas nuevas del docente responden «No encontrado» |
| App 1.2.0 (audio, clase en vivo) | LMS 6.39.0 | La app no muestra esos datos |
| App 1.4.0 (desarrollo) | LMS **6.41.0**: rutas `/me/avatar`, `pregrade`, cola «Por validar» | No compatible con 6.40.1 para esas funciones; no se publica como compatible con 6.40.x |
| Tema 3.2.1 / 3.3.0 | LMS 6.35.0 o superior (identidad de la academia y navegación por rol del plugin) | Colores y navegación por rol incompletos |
| LMS 6.41.0 (desarrollo) | WordPress 6.4+, PHP 8.1+; WooCommerce opcional (pedidos por institución) | — |

Requisitos de plataforma: WordPress 6.4 o superior y PHP 8.1 o superior para el ecosistema completo (el tema por sí
solo declara PHP 7.4, pero el plugin manda).

## Despliegue con varias instituciones

Desde 6.41.0 los pedidos de WooCommerce se aíslan por institución dentro de un mismo WordPress (bloque 4 de Roles):
el Auxiliar administrativo solo ve y gestiona los pedidos de su institución. Con 6.40.1 o anterior, varias
instituciones en un mismo WordPress **no** tienen aislamiento comercial: usar un WordPress por institución.

Validación real pendiente: pedidos con WooCommerce real (CI no instala WooCommerce) y pruebas en teléfono (en curso
por el titular; resultados en `atora-mobile/docs/PRUEBA-TELEFONO.md`). iOS no se ha ejecutado en CI.

Versiones de desarrollo que no se publicaron: [VERSIONES.md](VERSIONES.md).
