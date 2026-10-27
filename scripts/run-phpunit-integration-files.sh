#!/usr/bin/env bash
set -uo pipefail

failures=0
executed=0

# Un proceso PHPUnit por archivo evita contaminación de estado global entre tests
# y permite incluir pruebas que modifican esquema (rollback) sin afectar otras.
while IFS= read -r -d '' test_file; do
  executed=$((executed + 1))
  echo "::group::Integration PHPUnit $test_file"
  log=$(mktemp)
  if vendor/bin/phpunit --colors=never -c phpunit.integration.xml "$test_file" > "$log" 2>&1; then
    status=0
  else
    status=$?
    failures=$((failures + 1))
  fi
  cat "$log"
  echo "::endgroup::"

  if (( status != 0 )); then
    echo "::error file=$test_file::Integration PHPUnit failed in isolated file (exit $status)"
    # El motivo también como anotación: se lee por la API sin descargar los registros.
    detail=$(sed -n '/^There \(was\|were\) [0-9]* \(failure\|error\)/,/^\(FAILURES\|ERRORS\)!/p' "$log" | grep -v '^$' | head -c 3500 | sed ':a;N;$!ba;s/%/%25/g;s/\r/%0D/g;s/\n/%0A/g')
    if [ -z "$detail" ]; then
      detail=$(tail -n 25 "$log" | head -c 3500 | sed ':a;N;$!ba;s/%/%25/g;s/\r/%0D/g;s/\n/%0A/g')
    fi
    echo "::error file=$test_file,title=Motivo::$detail"
  fi
  rm -f "$log"
done < <(find tests/integration -type f -name '*Test.php' -print0 | sort -z)

echo "Integration PHPUnit files executed: $executed; failing files: $failures"
(( failures == 0 ))

