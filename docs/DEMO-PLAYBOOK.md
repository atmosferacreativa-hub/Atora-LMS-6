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
DRY_RUN=1 WP="wp --allow-root" ./scripts/bootstrap-demo-pages.sh   # revisar primero
WP="wp --allow-root" ./scripts/bootstrap-demo-pages.sh
```

Reutiliza páginas existentes sin tocar su contenido y solo crea lo que falta:

- dashboard: `mi-panel` / `panel-estudiante` / `panel`, o crea `/dashboard/` con `[clms_dashboard]`
- cuenta: `mi-perfil` / `perfil`, o crea `/cuenta/` con `[clms_login_form]` apuntando al dashboard real
- mis cursos: crea `/mis-cursos/` con `[clms_my_courses]`

No usa `/cursos/`: es el archivo público del CPT `lm_course` (catálogo).

Nota: si no existe una página `/dashboard/`, WordPress redirige `/dashboard/` a `/wp-admin/` (comportamiento core).

## Enlaces y textos del tema (fuera del plugin)

En demo.atora.studio, todo lo siguiente sale de **ATORA Theme** (`meridian-*`) o del contenido de la portada, no del plugin:

- Footer "Sistema de tema Meridian" y rail del header "…listo para WooCommerce".
- Botón Carrito, enlaces Tienda/Carrito/Podcast, y `atoraTheme.accountUrl` → `wp-login.php`.
- Mini-menú legal: `/privacy-policy/` y `/terms/` (en el sitio existe `/terminos-y-condiciones/`).
- CTAs `href="#"` en la portada: "Solicitar una demo", "Hablemos", "Ver la academia de prueba" (destinos sugeridos: `/contacto/`, `/academia/`).
- Ítem de menú "Programa de prueba" (`/programas/programa-de-prueba/`).

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

