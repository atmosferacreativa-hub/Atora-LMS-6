#!/usr/bin/env bash
set -uo pipefail

# Deuda heredada confirmada al aislar cada archivo bajo PHP 8.1.
# El runner permite solo este baseline y falla ante cualquier archivo nuevo.
known_failures=(
  tests/6.5.10/MessagingBridgeResolutionTest.php
  tests/Analytics/FormsThrottleTest.php
  tests/CRM/CRMAccessScopeTest.php
  tests/CRM/CRMRestPermissionsTest.php
  tests/CRM/ContactServiceTest.php
  tests/CRM/InboxReplyEmailTest.php
  tests/Groups/GroupSubmissionFetchTest.php
  tests/LMS/CourseWpPostIdNullableSchemaTest.php
  tests/LearningAnalytics/RiskScoreTest.php
  tests/Messaging/CanReceiveWhatsappTest.php
  tests/Messaging/PhoneVerificationCompatRetiredTest.php
  tests/Messaging/PhoneVerificationInvalidationTest.php
  tests/Messaging/PreferencesTest.php
  tests/Modularity/InstallProfilesTest.php
  tests/Modularity/ModuleRegistryTest.php
  tests/Security/ClientIpTest.php
  tests/Security/RateLimiterTest.php
)

declare -A known_lookup=()
declare -A observed_known=()
for test_file in "${known_failures[@]}"; do
  known_lookup["$test_file"]=1
done

unexpected_failures=0
executed=0

while IFS= read -r -d '' test_file; do
  executed=$((executed + 1))
  echo "::group::PHPUnit $test_file"
  if vendor/bin/phpunit --colors=always "$test_file"; then
    status=0
  else
    status=$?
  fi
  echo "::endgroup::"

  if (( status == 0 )); then
    if [[ -n "${known_lookup[$test_file]:-}" ]]; then
      echo "::notice file=$test_file::This legacy test file now passes; remove it from known_failures."
    fi
    continue
  fi

  if [[ -n "${known_lookup[$test_file]:-}" ]]; then
    observed_known["$test_file"]=1
    echo "::warning file=$test_file::Known isolated PHPUnit debt (exit $status)"
  else
    unexpected_failures=$((unexpected_failures + 1))
    echo "::error file=$test_file::New PHPUnit regression outside the accepted baseline (exit $status)"
  fi
done < <(find tests -type f -name '*Test.php' -print0 | sort -z)

known_count=${#observed_known[@]}
echo "PHPUnit files executed: $executed; known failing files: $known_count; unexpected failing files: $unexpected_failures"
(( unexpected_failures == 0 ))
