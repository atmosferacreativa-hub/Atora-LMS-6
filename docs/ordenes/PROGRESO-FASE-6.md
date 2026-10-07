# Progreso — Fase 6 (Publicación)

Orden: `docs/ordenes/ORDEN-FASE-6.md`. Reanudar: leer este archivo y la orden, comprobar en GitHub y seguir desde "Paso actual".

## Paso actual

**Bloque B — certificado en PDF (6.33.0)**, rama `feat/6.33.0-publicacion` (Atora-LMS-6). Código escrito (plantilla, PDF, verificación, API `format=pdf`); faltan pruebas.

## Bloques

- [x] **A** — 6.32.1 ya publicado (v6.32.1, PR #67): control central en `CLMS_AI_Manager` (chat, chat_with_meta, embeddings, transcripción, post_json) con registro por `feature`, límites, tope mensual y filtro de datos personales; marcador `{{nombre}}`; simulado nunca en `production`; `AiCentralControlTest`. Comprobado contra la lista del Bloque A: cumple.
- [ ] **B** — certificado PDF, plantilla, QR, `/verificar/{codigo}`, regeneración, app descarga PDF.
- [ ] **C** — eliminación de cuenta (API, admin, página pública), política de privacidad, datos de tiendas, permisos.
- [ ] **D** — app 1.0.0: i18n es/en, accesibilidad, rendimiento, errores, compilación de producción, cuentas de revisión, ficha de tienda, recorridos nuevos.
- [ ] Cierre: 6.33.0 + 1.0.0, changelog, ESTADO, PRUEBA-TELEFONO, etiquetas, ZIP, compilación de prueba y de producción (AAB, IPA) sin enviar.

## Ramas y PR

| Repo | Rama | Estado |
|---|---|---|
| Atora-LMS-6 | `feat/6.33.0-publicacion` | WIP, sin PR |
| atora-mobile | — | por crear |

## Decisiones menores

1. Librería de PDF: **Dompdf 3** (PHP puro; solo `mbstring` y `dom`). QR: **chillerlan/php-qrcode 5** (SVG, sin GD). Empaquetadas en `lib/packages` (Composer propio en `lib/`, sin chequeo de plataforma); `vendor/` sigue siendo solo de desarrollo.
2. Imágenes del certificado (logo y firmas) se aplanan sobre blanco y se pasan como JPEG: con PNG, Dompdf usa Imagick y en algunos servidores (también el local) Imagick falla con error fatal.
3. Código de verificación = UUID de la credencial institucional (`atora_credentials`, 122 bits aleatorios, con revocación de dos personas). Los códigos anteriores (20 caracteres) siguen verificando.
4. `/verificar/` siempre activa (el QR del PDF la necesita); el ajuste antiguo de verificación pública solo afectaba al shortcode, que ahora muestra lo mismo que la página.
5. Revocación solicitada y aún sin decidir: se muestra "Válido" (no está revocado todavía). Sustituido: solo "Sustituido".
6. PDF guardado en `uploads/atora-private/certificates` (con `.htaccess` que niega el acceso directo), regenerado si cambia la plantilla.
