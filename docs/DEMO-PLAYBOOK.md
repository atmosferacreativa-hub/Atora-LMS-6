# Playbook demo (no romper prospecto)

Este repo es el plugin **`atora-lms`**. La portada, menús, CTAs y textos de header/footer suelen vivir en **ATORA Theme** y/o en la **BD** del sitio WordPress. Este playbook cubre lo que se puede asegurar desde el plugin y los scripts de apoyo.

## Objetivo (cierre verificable)

- Sin `href="#"` en CTAs visibles.
- Sin enlaces del menú/pie que devuelvan 404.
- Sin strings de plantilla (“Meridian”, “de prueba”, etc.) en HTML público.
- Login usable: “Cuenta/Ingresar” no manda a `wp-login.php` crudo si existe `/cuenta/`.

## Bootstrap rápido (WP-CLI)

Crear páginas frontend mínimas y registrar el mapeo en `clms_frontend_pages`:

```bash
WP="wp --allow-root" ./scripts/bootstrap-demo-pages.sh
```

Esto crea/actualiza:

- `/cuenta/` con `[clms_login_form]`
- `/dashboard/` con `[clms_dashboard]`
- `/cursos/` con `[clms_my_courses]`

## Seed de datos de laboratorio (WP-CLI)

Para un entorno tipo “lab” reproducible (usuarios demo + curso + matrícula):

```bash
WP="wp --allow-root" ./scripts/seed-lab.sh
```

## Auditoría de enlaces/strings (HTTP)

Genera un reporte en Markdown con:

- links `#`
- links internos 404
- hits de strings prohibidos

```bash
node scripts/demo-audit.mjs --base https://tu-demo.com
node scripts/demo-audit.mjs --base https://tu-demo.com --paths / /cursos/ /dashboard/ /cuenta/
node scripts/demo-audit.mjs --base https://tu-demo.com --forbid Meridian WooCommerce "de prueba" --json demo-audit.json
```

Salida por defecto: `demo-audit-report.md`.

