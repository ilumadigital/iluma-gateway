#!/usr/bin/env bash
# Read-only Hostinger release integrity gate. No database writes or Nexi calls.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

command -v php >/dev/null || { echo 'FAIL: PHP CLI missing'; exit 1; }
command -v git >/dev/null || { echo 'FAIL: Git CLI missing'; exit 1; }
php -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' || {
  echo 'FAIL: PHP CLI 8.3+ required'
  exit 1
}
git rev-parse --is-inside-work-tree >/dev/null 2>&1 || {
  echo 'FAIL: not a Git checkout'
  exit 1
}

# Hostinger deployments can leave modified PHP files behind after a green CI.
# Refuse to call a deployment healthy unless critical tracked source matches HEAD.
# The obsolete AdminController.php is deliberately NOT loaded by HTTP.
# Its persistent external corruption must not take the active application down.
# Report legacy drift separately while failing on EVERY active tracked file.
active_drift="$(git diff --name-only HEAD -- .htaccess public src bin tests | grep -v -x 'src/AdminController.php' || true)"
if [[ -n "$active_drift" ]]; then
  echo 'FAIL: deployed tracked source differs from Git HEAD'
  echo 'Inspect safely with: git status --short'
  exit 1
fi
if ! git diff --quiet HEAD -- src/AdminController.php; then
  echo 'WARN: unused legacy AdminController.php was altered outside Git; investigate source writer'
fi
if [[ -n "$(git ls-files --others --exclude-standard -- public src bin)" ]]; then
  echo 'FAIL: unexpected untracked application code in deployed directories'
  echo 'Inspect safely with: git status --short'
  exit 1
fi

test -f src/AdminApplication.php || {
  echo 'FAIL: canonical Admin application is missing'
  exit 1
}
grep -Fq "require dirname(__DIR__) . '/src/AdminApplication.php';" public/admin.php || {
  echo 'FAIL: public/admin.php does not point to the single private controller'
  exit 1
}
if grep -Eq 'class AdminPageView|function requireStepUp' public/admin.php; then
  echo 'FAIL: duplicated Admin code in public entrypoint'
  exit 1
fi

grep -Fq 'RewriteRule ^ilm-admin/?$ public/admin.php [L,QSA]' .htaccess || {
  echo 'FAIL: admin route does not use canonical public/admin.php'
  exit 1
}
if find public -maxdepth 1 -type f -name 'admin_*.php' | grep -q . ||
   find src -maxdepth 1 -type f -name 'AdminEntry_*.php' | grep -q .; then
  echo 'FAIL: duplicate release-numbered admin source files'
  exit 1
fi

# Hostinger's restricted Bash runtime may not expose /dev/fd (process
# substitution). Use portable find -exec; a failed lint exits nonzero.
# The legacy shim is never loaded by HTTP. Its known external rewrite may
# be syntactically damaged; validate every ACTIVE PHP script instead.
find src public bin tests -type f -name '*.php' ! -path 'src/AdminController.php' -exec sh -c '
  for file do
    if ! php -d display_errors=1 -l "$file" >/dev/null 2>&1; then
      echo "FAIL: PHP syntax: $file"
      php -d display_errors=1 -l "$file" 2>&1 || true
      exit 1
    fi
  done
' sh {} +
count="$(find src public bin tests -type f -name '*.php' ! -path 'src/AdminController.php' -print | wc -l | tr -d '[:space:]')"
echo "PASS: PHP syntax ($count files)"
php bin/check-source-integrity.php

for request in '/ilm-admin/' '/ilm-admin/?p=payments'; do
  if ! output="$(GATEWAY_HOST=gateway.iluma.gr php -d display_errors=1 -r '
    $_SERVER["HTTP_HOST"]="gateway.iluma.gr";
    $_SERVER["REQUEST_URI"]=$argv[1];
    $_SERVER["REQUEST_METHOD"]="GET";
    require "public/admin.php";
  ' -- "$request" 2>&1)"; then
    echo "FAIL: admin login smoke test: $request"
    exit 1
  fi
  if [[ "$output" != *"Σύνδεση διαχειριστή"* ]] ||
     [[ "$output" == *"Internal server error"* ]] ||
     [[ "$output" == *"Parse error"* ]] ||
     [[ "$output" == *"Fatal error"* ]] ||
     [[ "$output" == *"Warning:"* ]]; then
    echo "FAIL: admin login not rendered: $request"
    exit 1
  fi
done
echo 'PASS: canonical Admin login and payments route render without database'
echo 'PASS: tracked source matches Git HEAD and the canonical admin route'
echo 'READ-ONLY: no payment, DB mutation, production unlock or Nexi request'

# After a manual Hostinger Git redeploy, verify the actual unsigned web route.
# A successful check does not replace a signed-in database/payment listing test.
if [[ "${1:-}" == "--live" ]]; then
  command -v curl >/dev/null || { echo 'FAIL: curl CLI missing'; exit 1; }
  headers="$(mktemp)"
  trap 'rm -f "$headers"' EXIT
  status="$(curl --ipv4 --silent --show-error --max-time 15 --dump-header "$headers" \
    --output /dev/null --write-out '%{http_code}' \
    'https://gateway.iluma.gr/ilm-admin/?p=payments')" || {
    echo 'FAIL: live admin unreachable'
    exit 1
  }
  if [[ "$status" != "200" ]]; then
    echo "FAIL: live admin HTTP $status"
    exit 1
  fi
  if ! grep -Eiq '^X-ILUMA-Admin-Entry:[[:space:]]*canonical[[:space:]]*$' "$headers"; then
    echo 'FAIL: the live route does not use the canonical Admin controller'
    exit 1
  fi
  echo 'PASS: live signed-out Admin payments route HTTP 200 and canonical controller'
fi
