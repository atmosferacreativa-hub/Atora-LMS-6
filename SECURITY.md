# SECURITY.md

## Política de reporte
Si detectas una vulnerabilidad en ATORA LMS, repórtala de forma privada al equipo mantenedor del repositorio por el canal interno acordado.

- No publiques exploits ni detalles sensibles en issues públicos.
- Incluye pasos de reproducción, impacto esperado y versión afectada.
- Adjunta evidencia mínima (payload/request/response) sin exponer datos personales reales.

## Alcance
Esta política cubre el plugin ATORA LMS en este repositorio local, especialmente:

- Endpoints REST (`includes/rest/*`)
- Handlers AJAX (`includes/class-*.php`)
- Persistencia de configuración (opciones/meta)
- Flujos de matrícula/acceso y evaluación asistida

Fuera de alcance:

- Infraestructura del hosting administrada por cada institución.
- Integraciones de terceros no incluidas en el repositorio.

## Principios de mitigación en ATORA
Para cambios de seguridad, mantener estas reglas:

1. Toda operación sensible debe validar `nonce + capability + contexto`.
2. No usar capacidades genéricas (`edit_posts`) para operaciones LMS sensibles.
3. No exponer secretos en respuestas REST/AJAX ni en logs.
4. Sanitizar y validar entradas recursivas cuando haya payloads JSON complejos.
5. Entregar cada corrección con validación reproducible (lint + tests/smoke).

## Tiempos objetivo
- Confirmación inicial: dentro de 3 días hábiles.
- Evaluación de impacto y plan: dentro de 7 días hábiles.
- Corrección o mitigación inicial: según severidad y riesgo de explotación.

## Gate bloqueante de seguridad

Cada pull request y cada push a `main` debe superar el job `Security gate` de GitHub Actions. El gate verifica:

1. Dependencias Composer sin vulnerabilidades conocidas mediante `composer audit --locked`.
2. Sincronización de la versión entre la cabecera del plugin, la constante y `Stable tag`.
3. Presencia del contrato mínimo de pruebas de seguridad, permisos y ownership.
4. Ausencia de archivos sensibles rastreados y de secretos de alta confianza.
5. Exclusión de fuentes de desarrollo, pruebas y secretos del ZIP comercial.
6. Ejecución bloqueante de la suite de seguridad y autorización.

El gate es una condición necesaria, no una certificación de seguridad. Antes de un piloto institucional siguen siendo obligatorias las pruebas dinámicas en un WordPress representativo, la revisión de roles y una prueba documentada de respaldo/restauración.

## Protección recomendada de `main`

En GitHub, configurar una ruleset o branch protection que:

- exija pull request;
- exija el check `Security gate` y los demás jobs de CI;
- impida omitir los checks, incluso a administradores;
- invalide aprobaciones cuando cambie el código;
- bloquee force-push y eliminación de la rama;
- requiera resolver conversaciones antes del merge.

Esta configuración vive en GitHub y no puede imponerse únicamente desde el código del plugin.
