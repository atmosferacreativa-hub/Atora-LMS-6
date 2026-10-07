# Progreso — Fase 6 (Publicación)

Orden: `docs/ordenes/ORDEN-FASE-6.md`. Reanudar: leer este archivo y la orden, comprobar en GitHub y seguir desde "Paso actual".

## Paso actual

**Bloque C (docs y permisos) y Bloque D (app 1.0.0)**. Plugin: B y C hechos en `feat/6.33.0-publicacion` (sin PR aún). App: rama `feat/1.0.0-publicacion` con i18n es/en completo (prueba de catálogo), idioma en Yo, eliminar cuenta, certificado PDF. Falta: docs de privacidad y tiendas, permisos, accesibilidad, rendimiento, errores (Sentry), compilación de producción, cuentas de revisión, ficha, recorridos `cambio-idioma`, `eliminar-cuenta`, `certificado-pdf`, cierre.

## Bloques

- [x] **A** — 6.32.1 ya publicado (v6.32.1, PR #67): control central en `CLMS_AI_Manager` (chat, chat_with_meta, embeddings, transcripción, post_json) con registro por `feature`, límites, tope mensual y filtro de datos personales; marcador `{{nombre}}`; simulado nunca en `production`; `AiCentralControlTest`. Comprobado contra la lista del Bloque A: cumple.
- [x] **B** (plugin) — certificado PDF, plantilla con 3 firmas, QR, `/verificar/{codigo}`, regeneración con fecha original, API `format=pdf` + `certificate_pdf`. `CertificatePdfTest` (6). Pendiente app: descargar y abrir el PDF (Bloque D).
- [x] **C** (plugin) — `POST /account/deletion-request`, aviso al administrador, pantalla de solicitudes (anonimizar / eliminar), página pública `/eliminar-cuenta/` con enlace por correo. `AccountDeletionTest` (5).
- [ ] **C** (app y docs) — Yo → Eliminar mi cuenta (hecho), política de privacidad, datos de tiendas, permisos.
- [ ] **D** — app 1.0.0: i18n es/en, accesibilidad, rendimiento, errores, compilación de producción, cuentas de revisión, ficha de tienda, recorridos nuevos.
- [ ] Cierre: 6.33.0 + 1.0.0, changelog, ESTADO, PRUEBA-TELEFONO, etiquetas, ZIP, compilación de prueba y de producción (AAB, IPA) sin enviar.

## Ramas y PR

| Repo | Rama | Estado |
|---|---|---|
| Atora-LMS-6 | `feat/6.33.0-publicacion` | WIP, sin PR |
| atora-mobile | `feat/1.0.0-publicacion` | WIP, sin PR |

## Decisiones menores

1. Librería de PDF: **Dompdf 3** (PHP puro; solo `mbstring` y `dom`). QR: **chillerlan/php-qrcode 5** (SVG, sin GD). Empaquetadas en `lib/packages` (Composer propio en `lib/`, sin chequeo de plataforma); `vendor/` sigue siendo solo de desarrollo.
2. Imágenes del certificado (logo y firmas) se aplanan sobre blanco y se pasan como JPEG: con PNG, Dompdf usa Imagick y en algunos servidores (también el local) Imagick falla con error fatal.
3. Código de verificación = UUID de la credencial institucional (`atora_credentials`, 122 bits aleatorios, con revocación de dos personas). Los códigos anteriores (20 caracteres) siguen verificando.
4. `/verificar/` siempre activa (el QR del PDF la necesita); el ajuste antiguo de verificación pública solo afectaba al shortcode, que ahora muestra lo mismo que la página.
5. Revocación solicitada y aún sin decidir: se muestra "Válido" (no está revocado todavía). Sustituido: solo "Sustituido".
6. PDF guardado en `uploads/atora-private/certificates` (con `.htaccess` que niega el acceso directo), regenerado si cambia la plantilla.
7. Eliminación: anonimizar es la opción recomendada (conserva entregas, notas y actas sin datos personales); los certificados ya emitidos siguen verificables con el nombre impreso (registro del título). Los administradores no se eliminan desde la pantalla. Plazo informado: 30 días.
8. i18n: el texto en español es la clave (`t('…')`); `tk()` marca claves en mapas. Prueba con el compilador de TypeScript: falla si queda texto visible sin `t()` o una clave sin inglés. Idioma: el del teléfono (español si es español; si no, inglés) o el elegido en Yo. Los textos que vienen del servidor (títulos, motivos de riesgo, errores) llegan en el idioma de la academia.
9. Compilación de pruebas (`ATORA_E2E`): "idioma del teléfono" = español, porque el emulador del CI está en inglés y los recorridos buscan textos en español.
10. Se quitaron restos de desarrollo de la app publicada: la tarjeta de programa con "Diplomado en Comunicación" fijo y las direcciones internas (LAN/localhost) en la configuración de la academia (solo en desarrollo).
