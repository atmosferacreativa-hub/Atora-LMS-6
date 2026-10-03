#!/usr/bin/env bash
set -euo pipefail

# Crea páginas frontend mínimas para una demo usable.
#
# - /cuenta/    → login form (shortcode) y respeta redirect_to
# - /dashboard/ → shortcode [clms_dashboard]
# - /cursos/    → shortcode [clms_my_courses]
#
# También guarda el option clms_frontend_pages para que el plugin resuelva URLs
# de forma determinística.

WP=${WP:-"wp --allow-root"}

echo "== Bootstrap demo pages =="

ensure_page() {
  local slug="$1"
  local title="$2"
  local content="$3"

  local existing
  existing=$($WP post list --post_type=page --post_status=publish,draft,private --name="$slug" --field=ID --format=ids | awk '{print $1}')

  if [ -n "$existing" ]; then
    $WP post update "$existing" --post_status=publish --post_title="$title" --post_content="$content" >/dev/null
    echo "$existing"
    return
  fi

  local id
  id=$($WP post create --post_type=page --post_status=publish --post_title="$title" --post_name="$slug" --post_content="$content" --porcelain)
  echo "$id"
}

ACCOUNT_ID=$(ensure_page "cuenta" "Cuenta" "[clms_login_form redirect=\"/dashboard/\" dashboard_url=\"/dashboard/\" dashboard_label=\"Entrar al panel\"]")
DASHBOARD_ID=$(ensure_page "dashboard" "Mi panel" "[clms_dashboard]")
COURSES_ID=$(ensure_page "cursos" "Mis cursos" "[clms_my_courses]")

echo "Páginas: cuenta=$ACCOUNT_ID dashboard=$DASHBOARD_ID cursos=$COURSES_ID"

echo "Guardando option clms_frontend_pages…"
$WP eval "
  update_option('clms_frontend_pages', array(
    'dashboard' => (int) ${DASHBOARD_ID},
    'profile'   => (int) ${ACCOUNT_ID},
    'courses'   => (int) ${COURSES_ID},
  ), false);
  echo 'ok\n';
" >/dev/null

echo "Permalinks:"
echo "- Cuenta: $($WP post url ${ACCOUNT_ID})"
echo "- Dashboard: $($WP post url ${DASHBOARD_ID})"
echo "- Cursos: $($WP post url ${COURSES_ID})"

echo "== OK =="

