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

Fuera de alcance por ahora:

- Infraestructura de empaquetado/release
- CI/CD externo
- Integraciones de terceros no incluidas en el repo

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
