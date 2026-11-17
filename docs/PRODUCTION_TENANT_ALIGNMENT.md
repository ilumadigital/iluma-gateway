# Gateway tenant parity and controlled iRadios production rollout (2026-11-17)

## Root causes fixed

- The VPS provisioner previously granted **SELECT only** on iluma_production to
  iluma_production_app. Normal production product and payment INSERT/UPDATE
  consequently failed with MySQL error 1142. The app role now gets
  SELECT/INSERT/UPDATE/DELETE, but **no** CREATE/ALTER/DROP/GRANT.
- Production used to have only the 15 baseline tenant tables, while important
  listener, broadcaster, saved card, winback, advertising and MIT tables were
  installed only in staging. The two **tenant** DBs should have identical
  structures; the third DB, iluma_registry, remains the shared authentication
  registry. **Do not merge tenants or copy transaction records.**
- The only VPS systemd worker ran php bin/work.php staging. The optional
  production worker and timer now use a distinct service and lock and are
  deliberately NOT enabled by installation.

## Critical deployment rule

Git push is not deploy. Confirm which server currently serves
gateway.iluma.gr. When it is on Hostinger, deploy manually from Git in
hPanel. When the active Gateway is the isolated VPS Docker deployment,
update/rebuild that running deployment from the reviewed merged commit.
**Never activate two independent payment workers against divergent ledgers.**
Do not run this VPS-specific script on Hostinger shared hosting.

## VPS: inspect first, then explicitly apply

Run as the operator with SSH/sudo access, in a maintenance window, after
freezing *new* Gateway checkout creation (not existing Nexi notifications).
Verify a restorable off-host copy of all financial ledgers first.
No commands below contact Nexi or start charges.

~~~bash
cd /srv/iluma-gateway/app
git status --short
git pull --ff-only origin main   # AFTER approved PR merge
sudo python3 deploy/vps/align-tenants.py       # READ-ONLY; may report drift
sudo python3 deploy/vps/align-tenants.py --apply
sudo python3 deploy/vps/provision-db.py
sudo python3 deploy/vps/align-tenants.py       # Must print PASS
sudo docker compose -f compose.vps.yml build php web
sudo docker compose -f compose.vps.yml up -d --no-deps php web
sudo bash deploy/vps/check-readiness.sh
~~~

The --apply step itself calls backup-db.py BEFORE any SQL writes. Backup
archives still need off-host encrypted copies. The script installs missing
reviewed CREATE TABLE structures on both tenants, adds the two routing columns
and index to both payments tables, and creates/updates *only* the approved
iRadios fixed-price SKU catalog. Approved four broadcaster legacy prices may
advance to current prices; unknown existing prices stop instead of overwrite.
It then compares table/column/index/foreign-key metadata and canonical SKU
prices between staging and production. If an old export has incompatible
definitions, it **stops** for a reviewed migration: no drop/replace/force
operations. Historical payments, cards, consent, mandates, customers, webhooks,
refunds and outbox are never copied between tenants.

If anything fails, **do not** enable production checkout/MIT or repeat
unknown Nexi POST operations. Read the reported mismatch and reconcile it.

## Route new iRadios at stage.iradios.gr through Nexi Production

Only after DB parity, product audit, a verified production merchant key,
production API credential and signed production webhook checks have passed:

Edit **only the private Gateway** /etc/iluma-gateway/iluma-gateway.env:

~~~dotenv
APP_ENV=production
PRODUCTION_ENABLED=1
IRADIOS_STAGE_PRODUCTION_ENABLED=1
IRADIOS_STAGE_PRODUCTION_WEBHOOK=https://stage.iradios.gr/api/gateway/webhook
MIT_PRODUCTION_EXECUTION_ENABLED=0
MIT_PRODUCTION_UAT_APPROVED=0
BROADCASTER_MIT_PRODUCTION_EXECUTION_ENABLED=0
BROADCASTER_MIT_PRODUCTION_UAT_APPROVED=0
~~~

Keep IRADIOS_PRODUCTION_WEBHOOK pointed to play.iradios.gr for legacy
production transactions. Verify NEXI_PRODUCTION_BASE is exactly
https://xpay.nexigroup.com/api/phoenix-0.0/psp and the registry-stored Nexi
PRODUCTION API key is valid. Do NOT put the key in chat/Git. Configure the
iRadios backend to use its dedicated **production Gateway** API key/secret
and production-environment requests. The staging web application's hostname
is **not** itself the payment environment.

Restart the live Gateway PHP runtime to reload the private environment.
On VPS, verify the rebuilt php/web images are running with new settings.
Do not assume that updating the .env file or pushing Git reloads containers.

## Independent production reconciliation / webhook delivery

After backup restore check, successful production HPP capture, signed
stage.iradios.gr webhook receipt, entitlement grant, and old worker
deactivation verification:

~~~bash
cd /srv/iluma-gateway/app
sudo install -m 0644 deploy/vps/iluma-gateway-production-worker.service /etc/systemd/system/
sudo install -m 0644 deploy/vps/iluma-gateway-production-worker.timer /etc/systemd/system/
sudo systemctl daemon-reload
# Existing cutover file /etc/iluma-gateway/worker.enabled must already exist.
sudo systemctl start iluma-gateway-production-worker.service  # one-shot smoke check
sudo journalctl -u iluma-gateway-production-worker.service -n 80 --no-pager
sudo systemctl enable --now iluma-gateway-production-worker.timer
sudo systemctl list-timers --all --no-pager 'iluma-gateway*'
~~~

If Gateways remain on Hostinger rather than the isolated VPS, run exactly
one correctly configured production CLI cron there instead; do not use
the VPS systemd files.

The production worker services previously captured events, reconciles
unknown payment states through provider GET, verifies provider contracts
and delivers signed outbox retries. It **does not** initiate MIT unless
the specific approval and execution flags are both 1.

## Live MIT is a separate, consent-sensitive release gate

Do NOT set UAT_APPROVED=1 merely to make the screen say ENABLED. A real
cardholder's initial HPP/SCA payment, exact MIT_SCHEDULED provider contract,
next-due timing, a **single** controlled provider POST /orders/mit,
confirmation by GET, signed customer entitlement, cancellation and duplicate
worker/idempotency tests must be approved with the production merchant.
Only after that evidence exists and a narrow test account is selected may
the private Gateway variables be changed to:

~~~dotenv
MIT_PRODUCTION_UAT_APPROVED=1
MIT_PRODUCTION_EXECUTION_ENABLED=1
BROADCASTER_MIT_PRODUCTION_UAT_APPROVED=1
BROADCASTER_MIT_PRODUCTION_EXECUTION_ENABLED=1
~~~

**Warning:** the production MIT worker scans all eligible due mandates,
up to five per class each run. Turning these flags on is NOT limited to a
single test customer. Keep them 0 until the production mandate population
has been audited, to prevent unintended repeat card charges.

## Rollback

Immediately stop the production timer and set the two EXECUTION_ENABLED
flags to 0 to stop future MIT attempts; do not delete payment rows or
reset MySQL volumes. If a production payment was already posted, reconcile
that order and signed events with the authoritative ledger before any DNS
or database rollback. A Git rollback alone cannot reverse captured money.
