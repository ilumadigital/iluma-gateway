#!/usr/bin/env bash
# Read-only VPS checks. Never starts payment workers or migrations.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."
COMPOSE=(docker compose -f compose.vps.yml)
test -f /etc/iluma-gateway/mysql-root.password || { echo 'STOP: missing private MySQL root secret'; exit 1; }
test -f /etc/iluma-gateway/iluma-gateway.env || { echo 'STOP: missing private Gateway ENV'; exit 1; }
"${COMPOSE[@]}" config --quiet
"${COMPOSE[@]}" ps
"${COMPOSE[@]}" exec -T php php bin/check-source-integrity.php
"${COMPOSE[@]}" exec -T php php bin/manage.php doctor-http
for path in /ilm-admin/ /ilm-admin/admin.css /ilm-admin/admin.js; do
  code="$(curl -sS --max-time 10 -o /dev/null -w '%{http_code}' -H 'Host: gateway.iluma.gr' "http://127.0.0.1:5561$path")"
  [[ "$code" == 200 ]] || { echo "FAIL: $path HTTP=$code"; exit 1; }
done
for path in / /README.md /src/Config.php /database/registry.sql /public/admin.php; do
  code="$(curl -sS --max-time 10 -o /dev/null -w '%{http_code}' -H 'Host: gateway.iluma.gr' "http://127.0.0.1:5561$path")"
  [[ "$code" == 404 ]] || { echo "FAIL: leaked $path HTTP=$code"; exit 1; }
done
echo "PASS: internal VPS checks; test signed Nexi and webhooks separately"
