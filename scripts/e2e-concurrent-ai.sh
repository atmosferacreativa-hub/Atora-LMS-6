#!/usr/bin/env bash
# Prueba de concurrencia real (6.33.1, E.5): 10 preguntas al asistente A LA VEZ,
# por HTTP, con un límite de 3 por día. El proveedor simulado tarda 1,5 s, así
# que las 10 están en curso al mismo tiempo. Deben pasar exactamente 3 (200) y
# rechazarse 7 (429).
#
# Uso (WordPress temporal con `wp atora seed-e2e` y ATORA_AI_FAKE):
#   API=http://localhost:8080/wp-json/atora-mobile/v1 WP="wp --allow-root" scripts/e2e-concurrent-ai.sh
set -euo pipefail

API="${API:-http://localhost:8080/wp-json/atora-mobile/v1}"
WP="${WP:-wp}"
PASSWORD="${PASSWORD:-atora-e2e-2026}"
OUT="$(mktemp -d)"

TOKEN="$(curl -fsS -X POST "$API/auth/login" -H 'content-type: application/json' \
  -d "{\"login\":\"estudiante_e2e\",\"password\":\"$PASSWORD\",\"device_name\":\"concurrencia-ia\"}" \
  | python3 -c 'import sys,json; print(json.load(sys.stdin)["session"]["access_token"])')"

LESSON="$($WP eval '
$post = get_page_by_path( "leccion-e2e-videos", OBJECT, "lm_lesson" );
$row  = $post ? \ATORA\LMS\LMS_Course_Service::get_lesson_by_wp_post( $post->ID ) : null;
echo (int) ( $row["id"] ?? 0 );')"
STUDENT="$($WP user get estudiante_e2e --field=ID)"

# Límite 3, cuenta del día en cero, simulado lento. Se restaura al salir.
$WP eval "
update_option( 'atora_ai_limits_e2e_backup', get_option( ATORA_AI_Usage_Service::LIMITS_OPTION, array() ), false );
ATORA_AI_Usage_Service::set_limits( array( 'student_daily' => 3 ) );
update_option( 'atora_ai_fake_delay_ms', 1500, false );
global \$wpdb; \$wpdb->query( \$wpdb->prepare( \"DELETE FROM {\$wpdb->prefix}atora_ai_usage WHERE user_id = %d\", $STUDENT ) );"
restore() {
  $WP eval "
  update_option( ATORA_AI_Usage_Service::LIMITS_OPTION, get_option( 'atora_ai_limits_e2e_backup', array() ), false );
  delete_option( 'atora_ai_limits_e2e_backup' );
  delete_option( 'atora_ai_fake_delay_ms' );
  global \$wpdb; \$wpdb->query( \$wpdb->prepare( \"DELETE FROM {\$wpdb->prefix}atora_ai_usage WHERE user_id = %d\", $STUDENT ) );" || true
}
trap restore EXIT

for i in $(seq 1 10); do
  curl -sS -o "$OUT/$i.json" -w '%{http_code}' -X POST "$API/ai/assistant" \
    -H "Authorization: Bearer $TOKEN" -H 'content-type: application/json' \
    -d "{\"lesson_id\":$LESSON,\"message\":\"Pregunta $i\",\"history\":[],\"client_event_id\":\"concurrencia-ia-$i-$(date +%s%N)\"}" > "$OUT/$i.code" &
done
wait

OK=0; LIMIT=0; OTHER=""
for i in $(seq 1 10); do
  case "$(cat "$OUT/$i.code")" in
    200) OK=$((OK + 1)) ;;
    429) LIMIT=$((LIMIT + 1)) ;;
    *) OTHER="$OTHER $(cat "$OUT/$i.code")" ;;
  esac
done
USED="$($WP eval "global \$wpdb; echo (int) \$wpdb->get_var( \$wpdb->prepare( \"SELECT COUNT(*) FROM {\$wpdb->prefix}atora_ai_usage WHERE user_id = %d AND result = 'ok'\", $STUDENT ) );")"

echo "Lección $LESSON · 200: $OK · 429: $LIMIT · otras:${OTHER:- ninguna} · usos ok registrados: $USED"
if [ "$OK" -ne 3 ] || [ "$LIMIT" -ne 7 ] || [ "$USED" -ne 3 ]; then
  echo "::error::Se esperaban exactamente 3 respuestas 200, 7 respuestas 429 y 3 usos registrados."
  cat "$OUT"/*.json; exit 1
fi
echo "OK: con límite 3, de 10 llamadas simultáneas pasaron exactamente 3."
