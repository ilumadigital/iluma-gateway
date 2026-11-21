#!/usr/bin/env bash
# Read-only identity check: the PHP source in an image or running container
# must match the reviewed Git HEAD, including post-incident operational tools.
# Does not read ENV, SQL, provider credentials, or network endpoints.
set -Eeuo pipefail
cd /srv/iluma-gateway/app
[[ "$(id -u)" == "0" ]] || { echo "STOP: run as root" >&2; exit 1; }
files=(
  bin/audit-pending-outbox.php
  bin/check-webhook-signing.php
  bin/iradios-return-doctor.php
  bin/reconcile-rejected-hpp.php
  bin/vps-ledger-audit.php
  bin/manage.php
  bin/work.php
  src/AccountDeletion.php
  src/AdvertisingBudget.php
  src/Config.php
  src/EnvLoader.php
  src/NexiClient.php
  src/Payments.php
  src/RejectedHppRepair.php
  src/SavedCards.php
  src/Winback.php
  src/Worker.php
  src/RecurringMandates.php
  src/BroadcasterMandates.php
  tests/run.php
)
paths=()
expected=""
for file in "${files[@]}"; do
  git -c safe.directory="$PWD" cat-file -e "HEAD:$file" || {
    echo "STOP: reviewed Git commit is missing $file" >&2; exit 1;
  }
  digest=$(git -c safe.directory="$PWD" show "HEAD:$file" | sha256sum | cut -d ' ' -f 1)
  full="/srv/iluma-gateway/app/$file"
  paths+=("$full")
  expected+="$digest  $full"$'\n'
done
expected="${expected%$'\n'}"
if [[ "${1:-}" == "--image" && "${2:-}" != "" && "$#" == 2 ]]; then
  observed=$(docker run --rm --network none "$2" sha256sum "${paths[@]}") || {
    echo "STOP: required file missing from newly built PHP image" >&2; exit 1;
  }
elif [[ "$#" == 0 ]]; then
  container=$(docker compose -f compose.vps.yml ps -q php)
  [[ -n "$container" ]] || { echo "STOP: PHP container missing" >&2; exit 1; }
  observed=$(docker exec "$container" sha256sum "${paths[@]}") || {
    echo "STOP: required file missing from active PHP container" >&2; exit 1;
  }
else
  echo "Usage: bash deploy/vps/check-source-match.sh [--image IMAGE_ID]" >&2
  exit 2
fi
if [[ "$expected" != "$observed" ]]; then
  echo "STOP: Gateway PHP source does not match reviewed Git HEAD" >&2
  exit 1
fi
echo "PASS: Gateway required source files match Git HEAD (no secrets read)"
