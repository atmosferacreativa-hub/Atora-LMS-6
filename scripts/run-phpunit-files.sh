#!/usr/bin/env bash
set -uo pipefail

failures=0
executed=0

# Un proceso PHPUnit por archivo evita contaminación de funciones globales
# entre Brain Monkey/Patchwork bajo PHP 8.1, sin ocultar ningún fallo.
while IFS= read -r -d '' test_file; do
  executed=$((executed + 1))
  echo "::group::PHPUnit $test_file"
  if vendor/bin/phpunit --colors=always "$test_file"; then
    status=0
  else
    status=$?
    failures=$((failures + 1))
  fi
  echo "::endgroup::"

  if (( status != 0 )); then
    echo "::error file=$test_file::PHPUnit failed in isolated file (exit $status)"
  fi
done < <(find tests -path tests/integration -prune -o -type f -name '*Test.php' -print0 | sort -z)

echo "PHPUnit files executed: $executed; failing files: $failures"
(( failures == 0 ))
