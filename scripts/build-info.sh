#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_FILE="${ROOT_DIR}/build-info.json"
DIRTY_MODE="auto"

cd "${ROOT_DIR}"

while [ $# -gt 0 ]; do
  case "$1" in
    --out)
      OUT_FILE="$2"
      shift 2
      ;;
    --dirty)
      DIRTY_MODE="$2"
      shift 2
      ;;
    *)
      echo "Uso: $0 [--out /ruta/build-info.json] [--dirty auto|true|false]" >&2
      exit 2
      ;;
  esac
done

version="$(awk -F'Version:' '/\\* Version:/{gsub(/^[[:space:]]+/,"",$2); gsub(/[[:space:]]+$/,"",$2); print $2; exit 0}' atora_lms.php)"
commit="$(git rev-parse HEAD 2>/dev/null || true)"
commit_short="$(git rev-parse --short HEAD 2>/dev/null || true)"
branch="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || true)"
tag="$(git describe --tags --exact-match 2>/dev/null || true)"

dirty=false
if [ "${DIRTY_MODE}" = "true" ]; then
  dirty=true
elif [ "${DIRTY_MODE}" = "false" ]; then
  dirty=false
else
  if ! git diff --quiet 2>/dev/null || ! git diff --cached --quiet 2>/dev/null; then
    dirty=true
  fi
fi

built_at="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"

export ATORA_BUILDINFO_VERSION="${version}"
export ATORA_BUILDINFO_COMMIT="${commit}"
export ATORA_BUILDINFO_COMMIT_SHORT="${commit_short}"
export ATORA_BUILDINFO_BRANCH="${branch}"
export ATORA_BUILDINFO_TAG="${tag}"
export ATORA_BUILDINFO_BUILT_AT="${built_at}"
export ATORA_BUILDINFO_DIRTY="${dirty}"
export ATORA_BUILDINFO_OUT_FILE="${OUT_FILE}"

python3 - <<PY
import json
import os
from pathlib import Path

out_file = Path(os.environ["ATORA_BUILDINFO_OUT_FILE"])
data = {
  "version": os.environ.get("ATORA_BUILDINFO_VERSION", ""),
  "commit": os.environ.get("ATORA_BUILDINFO_COMMIT", ""),
  "commit_short": os.environ.get("ATORA_BUILDINFO_COMMIT_SHORT", ""),
  "branch": os.environ.get("ATORA_BUILDINFO_BRANCH", ""),
  "tag": os.environ.get("ATORA_BUILDINFO_TAG", ""),
  "built_at": os.environ.get("ATORA_BUILDINFO_BUILT_AT", ""),
  "dirty": os.environ.get("ATORA_BUILDINFO_DIRTY", "false") == "true",
}
out_file.write_text(json.dumps(data, indent=2, sort_keys=False) + "\\n", encoding="utf-8")
print(str(out_file))
PY
