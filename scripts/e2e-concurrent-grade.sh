#!/usr/bin/env bash
# Prueba de concurrencia real (6.31.1): dos docentes guardan la misma entrega
# A LA VEZ, por HTTP, con la misma revisión. Debe responder exactamente un 200
# y un 409, y quedar una sola evaluación de rúbrica registrada.
#
# Uso (WordPress temporal con `wp atora seed-e2e`):
#   API=http://localhost:8080/wp-json/atora-mobile/v1 WP="wp --allow-root" scripts/e2e-concurrent-grade.sh
set -euo pipefail

API="${API:-http://localhost:8080/wp-json/atora-mobile/v1}"
WP="${WP:-wp}"
PASSWORD="${PASSWORD:-atora-e2e-2026}"
OUT="$(mktemp -d)"

token() {
  curl -fsS -X POST "$API/auth/login" -H 'content-type: application/json' \
    -d "{\"login\":\"$1\",\"password\":\"$PASSWORD\",\"device_name\":\"concurrencia\"}" \
    | python3 -c 'import sys,json; print(json.load(sys.stdin)["session"]["access_token"])'
}

T1="$(token docente_e2e)"
T2="$(token docente2_e2e)"

SUB="$(curl -fsS "$API/teacher/submissions?status=all" -H "Authorization: Bearer $T1" \
  | python3 -c 'import sys,json; print([i["id"] for i in json.load(sys.stdin)["items"] if i["lesson"]["title"]=="Concurrencia"][0])')"
REV="$(curl -fsS "$API/teacher/submissions/$SUB" -H "Authorization: Bearer $T1" \
  | python3 -c 'import sys,json; print(json.load(sys.stdin)["submission"]["revision"])')"
BEFORE="$($WP eval "global \$wpdb; echo (int) \$wpdb->get_var( \$wpdb->prepare( \"SELECT COUNT(*) FROM {\$wpdb->prefix}atora_rubric_evaluations WHERE wp_submission_id = %d\", $SUB ) );")"

body() {
  echo "{\"scores\":[{\"index\":0,\"score\":$2,\"feedback\":\"$1\"}],\"feedback\":\"$1\",\"grade\":$3,\"publish\":false,\"expected_revision\":$REV,\"client_event_id\":\"concurrencia-$1-$(date +%s%N)\"}"
}

# Las dos peticiones salen al mismo tiempo.
curl -sS -o "$OUT/a.json" -w '%{http_code}' -X POST "$API/teacher/submissions/$SUB/grade" \
  -H "Authorization: Bearer $T1" -H 'content-type: application/json' -d "$(body docente1 8 80)" > "$OUT/a.code" &
curl -sS -o "$OUT/b.json" -w '%{http_code}' -X POST "$API/teacher/submissions/$SUB/grade" \
  -H "Authorization: Bearer $T2" -H 'content-type: application/json' -d "$(body docente2 4 40)" > "$OUT/b.code" &
wait

A="$(cat "$OUT/a.code")"; B="$(cat "$OUT/b.code")"
AFTER="$($WP eval "global \$wpdb; echo (int) \$wpdb->get_var( \$wpdb->prepare( \"SELECT COUNT(*) FROM {\$wpdb->prefix}atora_rubric_evaluations WHERE wp_submission_id = %d\", $SUB ) );")"
NEW_REV="$($WP eval "echo (int) get_post_meta( $SUB, '_clms_submission_grade_revision', true );")"

echo "Entrega $SUB, revisión $REV → $NEW_REV · respuestas: $A y $B · evaluaciones: $BEFORE → $AFTER"
CODES="$(printf '%s\n%s\n' "$A" "$B" | sort | tr '\n' ' ')"
if [ "$CODES" != "200 409 " ]; then
  echo "::error::Se esperaba exactamente un 200 y un 409; llegó: $CODES"; cat "$OUT"/*.json; exit 1
fi
if [ "$((AFTER - BEFORE))" -ne 1 ]; then
  echo "::error::Se esperaba una sola evaluación nueva; hubo $((AFTER - BEFORE))"; exit 1
fi
if [ "$NEW_REV" -ne "$((REV + 1))" ]; then
  echo "::error::La revisión debía subir una vez ($REV → $((REV + 1))); quedó en $NEW_REV"; exit 1
fi
echo "OK: un guardado ganó, el otro recibió 409, una sola evaluación."
