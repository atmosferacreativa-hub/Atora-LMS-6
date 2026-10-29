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
| 1 | `ORDEN-ANALITICAS` — riesgo académico único y panel institucional | "C Analíticas" (lista vieja), "D" (lista ejecutada) | 6.36.0 | **cerrada en código y pruebas**; sin validación real |
| 2 | `ORDEN-AUDIO-PLANIFICACION` — audio en la lección y planificación híbrida (web y app 1.2.0) | — | 6.37.0 · app 1.2.0 | plugin publicado en 6.37.0; app 1.2.0 con audio; planificador nativo en app 1.3.0 (#43, orden 5); sin validación real |
| 3 | `ORDEN-C-COMUNICACION` — Email Engine, Newsletter, Automatización | "C" (lista ejecutada) | publicada en 6.39.0 (planeada 6.38.0) | **cerrada en código y pruebas** (#116–#119); sin validación real con proveedores |
| 4 | `ORDEN-CLASES-EN-VIVO` — Zoom, Meet y Microsoft Teams; grabación a decisión del docente | "E Streaming" (lista vieja), "F" (lista ejecutada) | 6.39.0 · app 1.2.0 | plugin **cerrado en código y pruebas** (#120); app 1.2.0 (#38); sin validación con cuentas reales |
| 5 | `ORDEN-APP-DOCENTE` — contenido, asistencia presencial, clase en vivo y planificación híbrida en la app del docente | "7" (lista del titular) | 6.40.0 · app 1.3.0 | plugin **cerrado en código y pruebas** (#122–#125); app: #40–#43; sin validación en teléfono real |
| 6 | `ORDEN-ROLES` — Instructor asistente (pre-calificación), Control académico (nota final ajustada), Auxiliar administrativo | "8" (lista del titular) | 6.41.0 · app 1.4.0 | en curso |
| 7 | `ORDEN-PERFIL` — foto propia sin Gravatar, iniciales, saludo | "9" | por definir | por escribir |
| 8 | `ORDEN-PERIODOS` — período académico, cierre de ciclo, cursos anteriores plegados, historial, inicio de período, portada y color | "10" | por definir | por escribir |
| 9 | `ORDEN-FOROS` — foros por curso, programa y cohorte con moderación | "11" | por definir | por escribir |

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
