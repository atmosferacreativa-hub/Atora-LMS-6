#!/usr/bin/env bash
set -euo pipefail

# Crea páginas frontend mínimas para una demo usable.
#
# - cuenta     → login form (shortcode) y respeta redirect_to
# - dashboard  → shortcode [clms_dashboard]
# - mis cursos → shortcode [clms_my_courses]
#
# Reutiliza páginas existentes (ej. panel-estudiante) y nunca sobrescribe su
# contenido: solo crea lo que falta. No usa el slug "cursos" porque ahí vive el
# archivo público del CPT lm_course (catálogo).
#
# También guarda el option clms_frontend_pages para que el plugin resuelva URLs
# de forma determinística.
#
# DRY_RUN=1 muestra qué haría sin escribir nada.

WP=${WP:-"wp --allow-root"}
DRY_RUN=${DRY_RUN:-0}

echo "== Bootstrap demo pages ==" >&2

# Devuelve el ID de la primera página publicada que coincida con algún slug.
find_page() {
  local slug id
  for slug in "$@"; do
    id=$($WP post list --post_type=page --post_status=publish --name="$slug" --field=ID --format=ids | awk '{print $1}')
    if [ -n "$id" ]; then
      echo "$id"
      return
    fi
  done
}

# ensure_page <slug-a-crear> <título> <contenido> [slugs existentes a reutilizar...]
ensure_page() {
  local slug="$1"
  local title="$2"
  local content="$3"
  shift 3

  local existing
  existing=$(find_page "$@" "$slug")
  if [ -n "$existing" ]; then
    echo "  reutiliza página $existing para '$slug'" >&2
    echo "$existing"
    return
  fi

  if [ "$DRY_RUN" = "1" ]; then
    echo "  [dry-run] crearía /$slug/ ($title)" >&2
    echo 0
    return
  fi

  local id
  id=$($WP post create --post_type=page --post_status=publish --post_title="$title" --post_name="$slug" --post_content="$content" --porcelain)
  echo "  creada página $id en /$slug/" >&2
  echo "$id"
}

DASHBOARD_ID=$(ensure_page "dashboard" "Mi panel" "[clms_dashboard]" "mi-panel" "panel-estudiante" "panel")
COURSES_ID=$(ensure_page "mis-cursos" "Mis cursos" "[clms_my_courses]")

# El login form de /cuenta/ redirige al dashboard real (puede no ser /dashboard/).
DASHBOARD_PATH="/dashboard/"
if [ "$DASHBOARD_ID" != "0" ]; then
  DASHBOARD_URL=$($WP post url "$DASHBOARD_ID")
  DASHBOARD_PATH="/${DASHBOARD_URL#*://*/}"
fi
ACCOUNT_ID=$(ensure_page "cuenta" "Cuenta" "[clms_login_form redirect=\"$DASHBOARD_PATH\" dashboard_url=\"$DASHBOARD_PATH\" dashboard_label=\"Entrar al panel\"]" "mi-perfil" "perfil")

echo "Páginas: cuenta=$ACCOUNT_ID dashboard=$DASHBOARD_ID cursos=$COURSES_ID" >&2

if [ "$DRY_RUN" = "1" ]; then
  echo "== DRY RUN: no se escribió nada ==" >&2
  exit 0
fi

echo "Guardando option clms_frontend_pages…" >&2
$WP eval "
  update_option('clms_frontend_pages', array(
    'dashboard' => (int) ${DASHBOARD_ID},
    'profile'   => (int) ${ACCOUNT_ID},
    'courses'   => (int) ${COURSES_ID},
  ), false);
  echo 'ok\n';
" >/dev/null

echo "Permalinks:"
echo "- Cuenta: $($WP post url "${ACCOUNT_ID}")"
echo "- Dashboard: $($WP post url "${DASHBOARD_ID}")"
echo "- Mis cursos: $($WP post url "${COURSES_ID}")"

echo "== OK =="
