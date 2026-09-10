# ATORA LMS

ATORA LMS es un sistema de gestión del aprendizaje modular para WordPress. Reúne creación de cursos y programas, matrículas, progreso, evaluación con rúbricas, SpeedGrade, certificados, seguimiento académico, clases en vivo, integraciones, mensajería y herramientas de crecimiento.

> Estado (versión 6.20.0): sprint de estabilización pre-producción S0-S4 cerrado — bloqueantes de CI/loader, autorización y CSRF en Grupos, integridad de datos (transacciones, validación de override, migración segura), rendimiento de Alertas Tempranas (N×M→batch, timezone) e inyección de fórmulas CSV corregidos, cada uno con test automatizado ejecutado contra `composer test` (0 regresiones sobre 442 tests). **Pendiente antes de producción pública:** verificación manual en una instalación WordPress + MySQL real (onboarding, CRM, permalinks, creación de cursos) — toda la verificación de este sprint corrió contra PHPUnit con mocks, no contra WordPress en vivo.

## Requisitos

- WordPress 6.4 o superior.
- PHP 8.1 o superior.
- MySQL o MariaDB compatibles con la versión de WordPress instalada.
- HTTPS para integraciones OAuth, webhooks y servicios externos.

El mínimo efectivo del ecosistema completo lo define ATORA LMS: PHP 8.1. ATORA Theme mantiene el mismo requisito.

## Arquitectura

El plugin es responsable de la lógica académica y comercial:

- cursos, programas, lecciones y matrículas;
- progreso, evaluaciones, rúbricas y calificaciones;
- seguimiento de estudiantes y panel Hoy;
- certificados, seguridad y permisos;
- CRM, comercio, automatización, email y afiliados;
- Google, clases en vivo, mensajería, API y webhooks.

ATORA Theme es responsable de la presentación, los layouts, las plantillas y los presets. ATORA Studio es el sitio y la marca del ecosistema; no forma parte del runtime de WordPress.

Se conserva la compatibilidad histórica con clases, hooks, metadatos y slugs `CLMS_`/`clms_`. No deben renombrarse de manera masiva sin una migración versionada y pruebas de regresión.

## Perfiles de instalación

- **Docente:** núcleo académico mínimo para enseñar.
- **Institución:** docencia, seguimiento, analítica, IA y comunicación.
- **Creadores:** capacidades académicas y comerciales.
- **Academia:** ecosistema completo.

Los módulos pueden activarse o desactivarse después de aplicar un perfil.

## Instalación para desarrollo

1. Clona este repositorio dentro de `wp-content/plugins/atora-lms`.
2. Instala las dependencias de pruebas con `composer install`.
3. Activa **ATORA LMS** desde WordPress.
4. Completa el asistente de instalación y selecciona un perfil.
5. Guarda los enlaces permanentes o ejecuta `wp rewrite flush`.
6. Mantén `ATORA_DEV_MODE` desactivado salvo en un entorno local controlado.

No uses datos, credenciales OAuth ni claves de proveedores reales en una instalación de prueba.

## Pruebas

```bash
composer install
composer test
```

La automatización de GitHub aplica como barreras obligatorias:

- validación de Composer;
- sintaxis PHP en PHP 8.1, 8.2, 8.3 y 8.4;
- instalación limpia de WordPress 6.4 y 6.8 con PHP 8.1 y MySQL 8;
- activación del plugin, verificación del bootstrap y creación de un curso de humo;
- construcción e inspección del ZIP de distribución.

La suite PHPUnit heredada también se ejecuta completa como diagnóstico. Actualmente contiene deuda previa de bootstrap, aislamiento y dobles de WordPress; por eso sus fallos quedan visibles, pero no sustituyen ni bloquean las comprobaciones dinámicas anteriores. Debe estabilizarse antes de convertirla en barrera obligatoria.

Estas comprobaciones son una barrera técnica mínima. No reemplazan la matriz funcional manual para administrador, docente, estudiante, migraciones, WooCommerce, correo, IA, Google, clases en vivo y permisos REST.

## Distribución

```bash
./scripts/build-dist.sh
```

El resultado se genera en `dist/atora-lms-<version>.zip`. Consulta [docs/EMPAQUETADO.md](docs/EMPAQUETADO.md) antes de publicar una versión.

## Roadmaps

- Evolución del LMS (rolling 6 meses): [docs/ROADMAP-LMS-EVOLUCION.md](docs/ROADMAP-LMS-EVOLUCION.md)

## Seguridad y datos

La desinstalación conserva los datos por defecto. El borrado profundo solo se ejecuta cuando el administrador activa expresamente la opción correspondiente.

No publiques archivos `.env`, credenciales, logs, bases de datos, copias de seguridad ni configuraciones locales de agentes.

## Repositorios relacionados

- ATORA Theme: https://github.com/atmosferacreativa-hub/atora-theme
- ATORA Studio: https://github.com/atmosferacreativa-hub/atora-studio

Sitio del proyecto: https://atora.studio
