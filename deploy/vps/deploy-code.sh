#!/usr/bin/env bash
# Apply reviewed checkout code to PHP/web only. No schema or worker changes.
set -Eeuo pipefail
cd /srv/iluma-gateway/app
[[ "$(id -u)" == 0 ]] || { echo "Run with sudo" >&2; exit 1; }
git -c safe.directory="$PWD" diff --quiet
git -c safe.directory="$PWD" diff --cached --quiet
exec 9>/run/lock/iluma-gateway-deploy-code.lock
flock -n 9 || { echo "STOP: Gateway deployment already running"; exit 1; }
compose=(docker compose -f compose.vps.yml)
revision=$(git -c safe.directory="$PWD" rev-parse HEAD)
echo "CHECKOUT: $revision"
"${compose[@]}" build php web
php_image=$(docker image inspect iluma-gateway-php:local --format '{{.Id}}')
web_image=$(docker image inspect iluma_gateway-web:latest --format '{{.Id}}')
# Offline tests do not see the private ENV and cannot call a provider.
docker run --rm --network none "$php_image" php tests/run.php
# Check the new runtime against mounted config BEFORE replacing the service.
"${compose[@]}" run --rm --no-deps -T php php -r '
require "/srv/iluma-gateway/app/src/EnvLoader.php";
try {
 if (!\Iluma\Gateway\EnvLoader::load("/srv/iluma-gateway/app")) exit(1);
 echo "NEW IMAGE CONFIG: OK\n";
} catch (\Throwable $e) { fwrite(STDERR,"STOP: new runtime configuration rejected\n"); exit(1); }
'
"${compose[@]}" up -d --no-deps --no-build --pull never --force-recreate php web
php_container=$("${compose[@]}" ps -q php)
web_container=$("${compose[@]}" ps -q web)
[[ -n "$php_container" && -n "$web_container" ]]
[[ "$(docker inspect "$php_container" --format '{{.Image}}')" == "$php_image" ]]
[[ "$(docker inspect "$web_container" --format '{{.Image}}')" == "$web_image" ]]
for attempt in {1..30}; do
  status=$(curl --silent --max-time 2 --output /dev/null --write-out '%{http_code}' \
    -H 'Host: gateway.iluma.gr' http://127.0.0.1:5561/ilm-admin/ || true)
  if [[ "$status" == 200 ]]; then
    echo "DEPLOYED: $revision; running PHP/web image IDs verified; Admin HTTP 200"
    echo "Billing readiness is a separate check; no schema or worker changed."
    exit 0
  fi
  sleep 1
done
echo "STOP: Gateway HTTP health failed after deployment; inspect runtime before any checkout" >&2
exit 1
