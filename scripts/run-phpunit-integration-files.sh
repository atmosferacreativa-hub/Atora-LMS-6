#!/usr/bin/env bash
set -uo pipefail

failures=0
executed=0

# Un proceso PHPUnit por archivo evita contaminación de estado global entre tests
# y permite incluir pruebas que modifican esquema (rollback) sin afectar otras.
while IFS= read -r -d '' test_file; do
  executed=$((executed + 1))
  echo "::group::Integration PHPUnit $test_file"
  if vendor/bin/phpunit --colors=always -c phpunit.integration.xml "$test_file"; then
    status=0
  else
    status=$?
    failures=$((failures + 1))
  fi
  echo "::endgroup::"

  if (( status != 0 )); then
    echo "::error file=$test_file::Integration PHPUnit failed in isolated file (exit $status)"
  fi
done < <(find tests/integration -type f -name '*Test.php' -print0 | sort -z)

echo "Integration PHPUnit files executed: $executed; failing files: $failures"
(( failures == 0 ))

