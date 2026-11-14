# ILUMA Gateway migration to the existing iRadios VPS

Status: preparation only. Nothing is installed or imported until the operator
executes the steps. No DNS, iRadios, payments, SQL or Hostinger changes are
made by a Git commit.

VPS observations, 2026-11-14: 185.230.64.89, 7.8 GiB RAM (6.5 GiB
available), 77 GB free disk, host Nginx on 80/443, Certbot installed,
existing iRadios frontend localhost 5510, backend localhost 5511,
MongoDB and Mailpit in isolated iradios_stage Compose, backend HTTP 200.

## Architecture and security

Gateway has its OWN Compose project (iluma_gateway), independent of iRadios.
Host Nginx routes gateway.iluma.gr to 127.0.0.1:5561; an internal web
container routes explicit endpoints to PHP-FPM. MySQL 8.4 is accessible only
inside the Gateway Docker network, with three distinct tenant databases:
iluma_registry, iluma_staging, iluma_production. Production remains disabled
and its application SQL user is SELECT-only.

Application PHP is copied into immutable images, read-only container rootfs
and cap_drop ALL. PHP and worker run as UID/GID 33. Neither has a writable
host source mount. Private configuration is mounted read-only at the precise
path expected by src/EnvLoader.php. MySQL has a persistent named Docker
volume (iluma_gateway_mysql_data). Worker timer is deliberately DISABLED
until after cutover; it runs the one-shot staging worker every minute.

Never change /srv/iradios/staging/app, its compose.stage.yml, iradios-stage
Nginx site, ports 5510/5511, Mongo, uploads or sudo ./ilumadev.sh.
Do not use docker compose down -v, docker system prune --volumes,
git reset --hard, or overwrite the existing Nginx config.

## Phase 1: source, exports and VPS readiness

Keep the three phpMyAdmin exports OUTSIDE GitHub and public_html.
Back up the current iRadios VPS and preserve original SQL archives off-server.
Check 185.230.64.89, disk, RAM, Nginx, certbot, Docker and port 5561 again.
Check whether the old exports are full structure+data, not partial.

On VPS prepare directories, then clone reviewed Gateway main:

    sudo install -d -m 0700 /root/gateway-migration
    sudo install -d -m 0700 /etc/iluma-gateway
    sudo mkdir -p /srv/iluma-gateway
    sudo git clone --branch main https://github.com/ilumadigital/iluma-gateway.git /srv/iluma-gateway/app
    cd /srv/iluma-gateway/app
    git log -1 --oneline
    docker compose -f compose.vps.yml config --quiet

Never put private exports or credentials inside the Git checkout.
For ongoing deployments use a separate unprivileged deployment account and
ensure only that identity can update host source/release files.

## Phase 2: original master key, new DB credentials

You must securely copy the ORIGINAL Hostinger private configuration file
from the shared-hosting private directory, outside public_html:
    $HOME/domains/gateway.iluma.gr/private/iluma-gateway.env

Copy by encrypted SSH/SFTP to:
    /root/gateway-migration/old-gateway.env

Ensure root owner and mode 0600. ZIP of public_html alone is not enough.
The MASTER_KEY_B64 value MUST BE PRESERVED BYTE-FOR-BYTE: it encrypts
stored API credentials, Nexi secrets and administrator MFA secrets.
Do not create a new key, paste it in chat or commit it.

Run:

    sudo python3 deploy/vps/prepare-secrets.py /root/gateway-migration/old-gateway.env
    sudo stat -c '%n %a %u:%g' /etc/iluma-gateway/*
    sudo docker compose -f compose.vps.yml build php web
    sudo docker compose -f compose.vps.yml up -d db php web
    sudo docker compose -f compose.vps.yml ps
    sudo python3 deploy/vps/provision-db.py

The preparation creates a new root MySQL secret and three NEW distinct
SQL passwords, keeping all old encrypted content decryptable through
the preserved master key. No API/Nexi key is regenerated. Only the staging
account gets staging DDL permissions; production remains read-only.
The worker is not started.

## Phase 3: inspect and import all three SQL archives

The importer accepts .sql, .sql.gz and archives .zip, .tar.gz or .tgz
containing exactly one SQL member. It REJECTS database selection/creation/
drop and user/grant SQL, nonfull exports and populated target databases.
If a database export contains SQL with CREATE DATABASE or USE statements,
STOP and review it privately instead of bypassing checks.

Example inspection, replacing filenames with YOUR actual ones:

    sudo python3 deploy/vps/import-db.py iluma_registry /root/gateway-migration/registry.sql.zip
    sudo python3 deploy/vps/import-db.py iluma_staging /root/gateway-migration/staging.sql.zip
    sudo python3 deploy/vps/import-db.py iluma_production /root/gateway-migration/production.sql.zip

Record the uncompressed SQL SHA256 printed for each. After confirming DB
identity, table count and source completeness, import into empty targets:

    sudo python3 deploy/vps/import-db.py iluma_registry /root/gateway-migration/registry.sql.zip --apply --sha256 PASTED_INSPECT_HASH
    sudo python3 deploy/vps/import-db.py iluma_staging /root/gateway-migration/staging.sql.zip --apply --sha256 PASTED_INSPECT_HASH
    sudo python3 deploy/vps/import-db.py iluma_production /root/gateway-migration/production.sql.zip --apply --sha256 PASTED_INSPECT_HASH

A failed import may leave partial tables! Never rerun or delete target
databases/volumes blindly. Compare table counts, customer/payment ledgers,
subscriptions, application keys, audit logs, outgoing_events and deliveries
against the source at the SAME snapshot. Check MySQL/MariaDB collation and
type compatibility. User-owned ZIP/TAR.GZ containing PHP files is not a DB dump:
restore code ONLY from the reviewed Git checkout.

    sudo docker compose -f compose.vps.yml exec -T php php bin/manage.php doctor-http
    sudo bash deploy/vps/check-readiness.sh

Do not enable worker or real checkout yet. Safely test password login, TOTP,
existing Nexi order read-only reconciliation and HMAC-authenticated staging
calls without initiating new provider charges.

## Phase 4: TLS, host Nginx and pre-DNS testing

Obtain a valid gateway.iluma.gr TLS certificate on the VPS BEFORE DNS
cutover. With old DNS still pointing to Hostinger, prefer DNS-01 through
your DNS provider. Certbot manual DNS-01 can issue an initial certificate,
but does NOT provide hands-free renewal; arrange DNS API automation with
restricted credentials and verify renewal before long-term operation.

Only after the certificate exists, install a NEW virtual host from
deploy/vps/nginx-host.conf.example into:
    /etc/nginx/sites-available/iluma-gateway

Symlink only this new site to sites-enabled. Never overwrite iradios-stage,
default, or the iRadios Docker stack. Then:

    sudo nginx -t
    sudo systemctl reload nginx
    curl -4 --resolve gateway.iluma.gr:443:185.230.64.89 -I https://gateway.iluma.gr/ilm-admin/

Also check /ilm-admin/admin.css and /ilm-admin/admin.js (HTTP 200),
and /, /README.md, /src/Config.php, /public/admin.php (HTTP 404).
Confirm the iRadios stage origin and localhost backend health are unchanged.
Validate certificate identity, not curl -k.

## Phase 5: cutover without two financial ledgers

The preliminary phpMyAdmin exports are only trial snapshots. If any
transaction/notification/worker delivery occurred since export, they are
NOT FINAL. Before final cutover:
1. Freeze NEW staging checkout attempts (approved app feature/maintenance
   switch) and verify no unfinished Nexi HPP checkout or pending MIT action.
2. Freeze ALL old Gateway writes, not merely its cron, with carefully
   controlled HTTP maintenance for POST/checkout creation/provider callbacks.
   Coordinate late provider notifications/retries and DNS cache TTL.
3. Flush old pending outbox using old worker while source is still writable,
   then STOP old Hostinger worker cron and verify the job has exited.
4. Produce transaction-consistent FINAL exports of all 3 old DBs after
   write freeze. Preserve encrypted off-site backup and checksums.
5. Restore this final authoritative snapshot to CLEAN target databases.
   Trial-restored populated target databases must NOT be imported over.
   Require a separately approved tenant-specific replacement plan/backup.
6. Compare financial records, idempotency request keys, outgoing_events,
   HMAC credentials, MFA, provider order status and staging entitlements.
7. With healthy new host HTTPS, change ONLY gateway.iluma.gr A record to
   185.230.64.89. Check and remove any stale AAAA/other gateway routing.
   Do NOT change unrelated DNS, nameservers or iRadios routes.
8. Prevent stale clients/providers from writing to old DB during propagation.
   Once new is authoritative, install/start ONLY the new worker systemd timer
   and verify delivered existing signed webhook events.
9. Monitor Nexi callbacks, new payment ledger, pending outbox and stage health.

Install worker timer DISABLED during preparation:

    sudo install -m 0644 deploy/vps/iluma-gateway-worker.service /etc/systemd/system/
    sudo install -m 0644 deploy/vps/iluma-gateway-worker.timer /etc/systemd/system/
    sudo systemctl daemon-reload

Only after cutover gates are met:

    sudo systemctl enable --now iluma-gateway-worker.timer
    sudo systemctl list-timers | grep iluma-gateway
    sudo journalctl -u iluma-gateway-worker.service -n 60 --no-pager

The new source must not use shared write-enabled PHP mounts. A worker
requires outbound TLS to both Nexi and stage.iradios.gr. It must not
restart or rebuild the iRadios Compose project.

## Rollback and updates

Before any NEW writes, a DNS rollback may be considered only with new worker
OFF and the old database confirmed authoritative.

AFTER the new Gateway has accepted ANY payment/event/write, DNS-only rollback
is UNSAFE. Freeze writers, back up both ledgers, reconcile Nexi orders and
signed outbox events, and approve a transaction-level rollback plan. Never
mark orders paid manually, drop records or repeat a checkout to repair delivery.

For future source-only releases, review PR/CI, then in /srv/iluma-gateway/app:

    git pull --ff-only origin main
    docker compose -f compose.vps.yml build php web
    docker compose -f compose.vps.yml up -d --no-deps php web
    sudo bash deploy/vps/check-readiness.sh

No iRadios update is required. Never delete the persistent MySQL volume.
