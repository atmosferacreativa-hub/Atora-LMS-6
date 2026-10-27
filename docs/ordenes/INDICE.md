# Índice de órdenes

Las órdenes nuevas se nombran por **tema** (`ORDEN-<TEMA>.md` y `PROGRESO-<TEMA>.md`), no por letra. Las letras
quedan solo como alias históricos: hubo dos listas con letras distintas (en una, "C" era Analíticas; en la que se
ejecutó, "C" es Comunicación), así que una letra sola no identifica una orden.

## Evidencias

Cada estado distingue cuatro evidencias, que no se sustituyen entre sí:

- **Doc**: la orden o la documentación está en el repo.
- **Código**: el cambio está fusionado en `main`.
- **Prueba**: pruebas automáticas (PHPUnit, Jest, recorridos Maestro en emulador) en verde.
- **Real**: validado en un teléfono real o con cuentas reales del proveedor. Solo lo marca quien lo ejecutó.

Un documento fusionado no es una función terminada.

## Cerradas

| Orden | Alias | Versión | Doc | Código | Prueba | Real |
|---|---|---|---|---|---|---|
| `ORDEN-FASE-4/5/6`, `ORDEN-1.0.1`, `ORDEN-1` | — | plugin ≤ 6.33.3, app ≤ 1.0.2 | sí | sí | sí | parcial (ver `ESTADO.md`) |
| `ORDEN-A-CRM` (CRM) | A | 6.34.0 | sí | sí | sí | no |
| `ORDEN-B-SCORM` (SCORM) | B | plugin 6.35.0 · app 1.1.0 | sí | plugin sí; app en el PR atora-mobile#34 | sí (emulador Android) | no (iOS nunca ejecutado) |

## Pendientes, en orden de ejecución

| # | Orden (archivo) | Alias anteriores | Versión prevista | Estado |
|---|---|---|---|---|
| 1 | `ORDEN-ANALITICAS` — riesgo académico único y panel institucional | "C Analíticas" (lista vieja), "D" (lista ejecutada) | 6.36.0 | por escribir |
| 2 | `ORDEN-AUDIO-PLANIFICACION` — audio en la lección y planificación híbrida (web y app 1.2.0) | — | 6.37.0 · app 1.2.0 | por escribir |
| 3 | `ORDEN-C-COMUNICACION` — Email Engine, Newsletter, Automatización | "C" (lista ejecutada) | 6.38.0 (antes 6.36.0) | escrita, bloques pendientes |
| 4 | `ORDEN-CLASES-EN-VIVO` — Zoom, Meet y Microsoft Teams; grabación a decisión del docente | "E Streaming" (lista vieja), "F" (lista ejecutada) | 6.39.0 | por escribir |

**Pospuestas** (no se ejecutan hasta nueva decisión):

| Orden | Alias anteriores | Notas |
|---|---|---|
| `ORDEN-AFILIADOS` | "D" (lista vieja), "E" (lista ejecutada) | Hallazgos confirmados en el código (2026-10-27): verificación y marca de "procesado" no atómicas y sin índice único por pedido; el pedido se marca procesado aunque `create_commission()` falle; sin tratamiento de reembolsos ni cancelaciones; "pagado" no transfiere. No anunciar como cerrado. |
| `ORDEN-LICENCIAS` | "I" | Hallazgo confirmado: `Licensing::init()` borra y reprograma el cron en cada carga, así que con servidor configurado la comprobación nunca corre. Distribución manual por ZIP mientras tanto. |
| `ORDEN-CALIDAD` | "G" | Plugin Check, rendimiento con 1.500 y 10.000 usuarios, desinstalación. |

## Validación real pendiente (no marcar sin ejecutarla)

- Lista completa de `atora-mobile/docs/PRUEBA-TELEFONO.md` (sin recorrer desde la 1.0.2).
- iOS: nunca ejecutado, ni en CI ni en un aparato.
- SCORM exportado de Rise o Storyline; apertura de un paquete de ~100 MB.
- Clases en vivo con cuentas reales de cada proveedor.
- Pagos y reembolsos reales de WooCommerce (afiliados).
- Servidor de licencias desplegado.
