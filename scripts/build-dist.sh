#!/usr/bin/env bash
#
# build-dist.sh — PT-7.3 (sprint 6.5.1)
#
# Genera un ZIP de distribución del plugin usando .distignore (raíz del
# repo) como lista de exclusión. No requiere nada más que rsync/zip,
# ya instalados en cualquier máquina de desarrollo o CI habitual.
#
# Uso:
#   ./scripts/build-dist.sh [version]
#
# Si no se pasa versión, la toma de la cabecera "Version:" de
# atora_lms.php. El resultado queda en dist/atora-lms-<version>.zip —
# revisa que no contenga .git ni carpetas de desarrollo antes de
# entregarlo (criterio de aceptación de PT-7).

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

VERSION="${1:-}"
if [ -z "$VERSION" ]; then
	VERSION=$(grep -m1 '^ \* Version:' atora_lms.php | sed -E 's/.*Version:\s*//' | tr -d '[:space:]')
fi
if [ -z "$VERSION" ]; then
	echo "No se pudo determinar la versión. Pásala como argumento: ./scripts/build-dist.sh 6.5.1" >&2
	exit 1
fi

DIST_DIR="$ROOT_DIR/dist"
STAGE_DIR="$DIST_DIR/atora-lms"
ZIP_PATH="$DIST_DIR/atora-lms-${VERSION}.zip"

rm -rf "$STAGE_DIR" "$ZIP_PATH"
mkdir -p "$STAGE_DIR"

rsync -a --exclude-from="$ROOT_DIR/.distignore" --exclude="dist" ./ "$STAGE_DIR/"

( cd "$DIST_DIR" && zip -rq "atora-lms-${VERSION}.zip" atora-lms )
rm -rf "$STAGE_DIR"

echo "Listo: $ZIP_PATH"
