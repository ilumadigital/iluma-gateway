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
if ! git diff --quiet HEAD -- .htaccess public src bin tests; then
  echo 'FAIL: deployed tracked source differs from Git HEAD'
  echo 'Inspect safely with: git status --short'
  exit 1
fi
if [[ -n "$(git ls-files --others --exclude-standard -- public src bin)" ]]; then
  echo 'FAIL: unexpected untracked application code in deployed directories'
  echo 'Inspect safely with: git status --short'
  exit 1
fi

grep -Fq 'RewriteRule ^ilm-admin/?$ public/admin_20261111_r3.php [L,QSA]' .htaccess || {
  echo 'FAIL: admin route does not use reviewed release'
  exit 1
}
grep -Fq 'admin_20261111_r3\.php' .htaccess || {
  echo 'FAIL: Hostinger internal entrypoint is not whitelisted'
  exit 1
}

count=0
while IFS= read -r -d '' file; do
  if ! php -d display_errors=1 -l "$file" >/dev/null 2>&1; then
    echo "FAIL: PHP syntax: $file"
    php -d display_errors=1 -l "$file" 2>&1 || true
    exit 1
  fi
  count=$((count + 1))
done < <(find src public bin tests -type f -name '*.php' -print0)
echo "PASS: PHP syntax ($count files)"

for entrypoint in public/admin.php public/admin_20261111_r3.php; do
  if ! output="$(GATEWAY_HOST=gateway.iluma.gr php -d display_errors=1 -r '
    $_SERVER["HTTP_HOST"]="gateway.iluma.gr";
    $_SERVER["REQUEST_URI"]="/ilm-admin";
    $_SERVER["REQUEST_METHOD"]="GET";
    require $argv[1];
  ' -- "$entrypoint" 2>&1)"; then
    echo "FAIL: admin login smoke test: $entrypoint"
    exit 1
  fi
  if [[ "$output" != *"Σύνδεση διαχειριστή"* ]] ||
     [[ "$output" == *"Internal server error"* ]] ||
     [[ "$output" == *"Parse error"* ]] ||
     [[ "$output" == *"Fatal error"* ]]; then
    echo "FAIL: admin login not rendered: $entrypoint"
    exit 1
  fi
done
echo 'PASS: both admin login entrypoints render without database'
echo 'PASS: tracked source matches Git HEAD and the admin release route'
echo 'READ-ONLY: no payment, DB mutation, production unlock or Nexi request'
