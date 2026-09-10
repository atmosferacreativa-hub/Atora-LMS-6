#!/usr/bin/env bash
#
# security-gate.sh — controles estáticos bloqueantes para ATORA LMS.
#
# Este script evita regresiones verificables sin sustituir una auditoría
# dinámica de WordPress ni una revisión humana de autorización.
#
# Uso:
#   bash scripts/security-gate.sh

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

fail() {
	printf 'SECURITY GATE: FAIL — %s\n' "$1" >&2
	exit 1
}

pass() {
	printf 'SECURITY GATE: PASS — %s\n' "$1"
}

command -v git >/dev/null 2>&1 || fail "git no está disponible"
[[ -f atora_lms.php ]] || fail "falta atora_lms.php"
[[ -f readme.txt ]] || fail "falta readme.txt"
[[ -f .distignore ]] || fail "falta .distignore"

plugin_version="$(sed -nE 's/^ \* Version:[[:space:]]*([^[:space:]]+).*/\1/p' atora_lms.php | head -n1)"
constant_version="$(sed -nE "s/.*define\( 'ATORA_LMS_VERSION', '([^']+)' \).*/\1/p" atora_lms.php | head -n1)"
stable_version="$(sed -nE 's/^Stable tag:[[:space:]]*([^[:space:]]+).*/\1/p' readme.txt | head -n1)"

[[ -n "$plugin_version" ]] || fail "no se pudo leer la versión del plugin"
[[ "$plugin_version" == "$constant_version" ]] || fail "Version y ATORA_LMS_VERSION no coinciden"
[[ "$plugin_version" == "$stable_version" ]] || fail "Version y Stable tag no coinciden"
pass "versiones sincronizadas ($plugin_version)"

for required in 	tests/Security/AtoraSecurityRateLimitTest.php 	tests/Security/ClientIpTest.php 	tests/Security/RateLimiterConcurrencyTest.php 	tests/Security/SecurityMaintenanceTest.php 	tests/Security/TokenCryptoTest.php 	tests/Security/TwoFaRateLimitTest.php 	tests/CRM/CRMRestPermissionsTest.php 	tests/Groups/AdminPostAuthorizationTest.php 	tests/H5P/ContentControllerOwnershipTest.php 	tests/LMS/EnrollmentAjaxOwnershipTest.php 	tests/LMS/GradeAppealOwnershipTest.php 	tests/LMS/LMSCourseOwnershipTest.php 	tests/LMS/LMSCourseVisibilityTest.php 	tests/Rest/WebhookPermissionsTest.php
do
	[[ -f "$required" ]] || fail "falta prueba crítica: $required"
done
pass "contrato mínimo de pruebas críticas presente"

for excluded in 	.git 	.github 	tests 	docs 	phpunit.xml 	composer.json 	composer.lock 	vendor 	scripts 	.env 	.env.*
do
	grep -Fxq "$excluded" .distignore || fail ".distignore no excluye: $excluded"
done
pass "artefactos de desarrollo y secretos excluidos de distribución"

tracked_forbidden="$(git ls-files | grep -E '(^|/)(\.env($|\.)|id_(rsa|dsa|ecdsa|ed25519)$|.*\.(pem|p12|pfx|key)$|wp-config\.php$|debug\.log$|error_log$)' || true)"
[[ -z "$tracked_forbidden" ]] || fail "archivos sensibles rastreados por git:
$tracked_forbidden"
pass "sin archivos sensibles rastreados"

secret_hits="$(
	git grep -I -n -E -- 		'-----BEGIN (RSA |DSA |EC |OPENSSH |PGP )?PRIVATE KEY-----|AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9]{36,}|xox[baprs]-[A-Za-z0-9-]{20,}' 		':(exclude)tests/**' 		':(exclude)*.md' 		':(exclude)composer.lock' 		|| true
)"
[[ -z "$secret_hits" ]] || fail "posible secreto de alta confianza:
$secret_hits"
pass "sin secretos de alta confianza en producción"

dev_mode_hits="$(git grep -I -n -E -- 'define[[:space:]]*\\([[:space:]]*['"'"'"]ATORA_DEV_MODE['"'"'"][[:space:]]*,[[:space:]]*true' -- '*.php' | grep -Ev ':[0-9]+:[[:space:]]*(//|#|\\*)' || true)"
[[ -z "$dev_mode_hits" ]] || fail "ATORA_DEV_MODE está forzado a true:
$dev_mode_hits"
pass "modo de desarrollo no forzado"

printf 'SECURITY GATE: OK\n'
