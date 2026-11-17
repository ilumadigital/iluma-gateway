# iRadios production billing recovery on the VPS

This runbook restores the new application at https://stage.iradios.gr.
The application remains APP_ENV=staging; its Gateway tenant is production.
Gateway runs on the VPS. hPanel Git redeploy is retired for this installation.

## What each check proves

- /ilm-admin/ HTTP 200 proves HTTP/PHP access, not database/payment readiness.
- production-billing.py (without flags) audits schema, catalog, credential
  presence and the schema-scoped runtime user's DML. It makes no changes.
- The iRadios gateway-readiness.js diagnostic authenticates an actual HMAC GET
  with the configured production key/secret and checks production prerequisites.
  The authentication layer records only its normal nonce/last-used timestamp.
  It does not call Nexi, create a customer/order or deliver a synthetic webhook.
- A real payment still needs provider confirmation and the signed payment.paid
  webhook before iRadios grants anything. No browser return/GET grants access.
- The production timer is separate from the retained staging timer. Its
  --no-charge option never invokes automatic MIT charges, regardless of flags.

## 1. Deploy reviewed code, before any database change

Merge only the unified, reviewed Gateway release after CI passes; merge iRadios #153 into staging afterward.
On the VPS, fast-forward the Gateway checkout to the reviewed main SHA.
Do not overwrite the local iluma-g-dev.sh or unreviewed local changes.

    cd /srv/iluma-gateway/app
    git -c safe.directory="$PWD" pull --ff-only origin main
    sudo bash deploy/vps/deploy-code.sh

This script builds PHP and web, runs offline tests without the private ENV or
network, validates mounted configuration in the new image, recreates PHP/web
and compares their running image IDs with the images just built. It does not
restart MySQL, change ENV, migrate, or start any worker.

## 2. Audit both tenants and production prerequisites

    sudo python3 deploy/vps/production-billing.py  # read-only even while production locked
    sudo python3 deploy/vps/align-tenants.py      # read-only tenant parity

Exit 1 with a JSON plan means prerequisites are missing. Review missing_tables,
missing_columns, missing_products, missing_dml, occupied_tables and blockers.
Catalog conflicts and incompatible existing columns/indexes/foreign keys are
refused, not silently repaired. All required SKUs are scoped to iradiosgr.

This bootstrap is for the pre-launch production database observed during the
incident. If any financial/customer table contains records, STOP and prepare
a separate reviewed upgrade. Do not delete those records to pass the guard.

## 3. Choose exactly ONE migration path after off-host backup and restore drill

The 2026-11-17 VPS audit established: registry 6 tables, staging 22, production
15. Production lacks eight optional billing tables; staging lacks winback_claims.
The iradiosgr staging catalog is missing L-WINBACK-199; a separate
iluma-internal ILUMA-TEST-100 production product must be preserved.
All audited production financial tables are empty, routing columns already
exist in production, and the production app has required DML without DDL.

**Do not run both bootstrap scripts.** For this audited two-tenant drift,
choose only `deploy/vps/align-tenants.py --apply --verified-offsite-restore` after checking its read-only
report and obtaining approval. It adds missing approved tables/columns/indexes
to both isolated tenants and missing scoped SKUs, never copies financial rows.
Legacy or unexpected catalog prices MUST STOP; no automatic price updates.

```bash
cd /srv/iluma-gateway/app
sudo python3 deploy/vps/align-tenants.py   # read-only: currently expected to report drift
# STOP HERE UNTIL off-host encrypted backups + fresh isolated MySQL restore are verified.
# After explicit operator approval only:
# sudo python3 deploy/vps/align-tenants.py --apply --verified-offsite-restore
```

The apply script creates fresh LOCAL backups but does NOT itself prove off-host
recovery or isolated restore. The operator must independently verify both for
the current snapshot before any write. Recheck schema, indexes, foreign keys,
SKU scope, historical row counts and production DML afterward. On partial
MySQL DDL failure, stop and audit: do not blindly replay or roll back SQL.

The alternative production-only `production-billing.py --apply` has an
integrated isolated restore verification but is NOT the selected operation
here because it does not repair the missing staging winback table. It must
not be run in addition to the tenant alignment script.

Expected iRadios product catalog: 17 SKUs, all EUR and enabled, with
L-WINBACK-199=199 cents, B-PREM=9900, ADD-DSP=2790, ADD-ADS=890,
ADD-AN=690. ADV-BUDGET=100 is the catalog sentinel for separately
validated quotes, NOT a universal 1-euro campaign charge.

## 4. Deploy iRadios and verify its actual credential pair

    cd /srv/iradios/staging/app
    sudo ./ilumadev.sh
    sudo docker exec iradios_stage-backend-1 node scripts/gateway-readiness.js

Require AUTHENTICATED_GATEWAY: OK, PRODUCTION_READINESS: READY and every check
OK. This confirms signed Gateway prerequisites, NOT merchant/terminal authorization. Do not print ENV, request headers, API keys or secrets. A 401/403 requires
credential/configuration investigation, not regenerating credentials blindly.

Reload the Broadcaster billing screen without clicking checkout. The four
recurring-status requests must complete successfully for authorized resources.
Existing unknown local orders must recover through the signed lookup of their
original request key; never delete locks or replay checkout to clear them.

## 5. Install the separate production worker only after readiness

Confirm no other production scheduler is active. Keep the existing staging
timer; this service uses a different lock, marker and production command.
No ordinary "compose up" enables either worker profile.

    cd /srv/iluma-gateway/app
    sudo install -m 0644 deploy/vps/iluma-gateway-production-worker.service /etc/systemd/system/
    sudo install -m 0644 deploy/vps/iluma-gateway-production-worker.timer /etc/systemd/system/
    sudo install -m 0600 /dev/null /etc/iluma-gateway/production-worker.enabled
    sudo systemctl daemon-reload
    sudo systemctl start iluma-gateway-production-worker.service
    sudo systemctl show iluma-gateway-production-worker.service -p Result -p ExecMainStatus

Require Result=success and ExecMainStatus=0, then:

    sudo systemctl enable --now iluma-gateway-production-worker.timer
    sudo systemctl list-timers --all --no-pager 'iluma-gateway*'

The worker reconciles existing payments, delivers signed events and verifies
provider agreements. It can make provider status/contract calls and update
existing ledgers; it is not a read-only diagnostic. It cannot execute chargeDue.
Do not change MIT execution/UAT flags as a workaround for a readiness failure.

## 6. One controlled payment, only after pending/unknown attempts are resolved

Choose one agreed product/account and create one payment. On timeout or unknown
state, stop: inspect the original payment in Gateway and Nexi, then reconcile.
Never retry with a new key simply because the UI did not update.
Verify all three: exact amount/status in the Gateway ledger, matching confirmed
Nexi transaction, and acknowledged signed webhook plus the correct iRadios
account/station entitlement. A successful redirect is not proof of payment.
Automatic renewals require a separate reviewed UAT and rollout after this.

## Preserved boundaries

No change to the legacy play.iradios.gr webhook or origin. Existing production
payment routing defaults to iradios_stage_routed=0; new stage payments pin the
route and originating credential. No Mailpit or Admin SMTP change.
No application APP_ENV change. No automatic migration on a web request.

## Exposed-secret rotation plan

Do not paste secrets into chat or CLI output. Before real transactions:
1. Inventory the exposed provider, application, SMTP, database and JWT secrets.
   Check provider activity independently, preserve audit evidence and securely
   retain the original encryption key and recoverable database backups.
2. Rotate provider credentials through the provider's secure console and update
   the encrypted Gateway entry through its approved administration path. Verify
   the provider configuration without initiating payment.
3. Create a replacement production application credential using a secure
   operator flow; update the iRadios private ENV and recreate its backend.
   Verify the signed readiness diagnostic with the new pair. Retain an old
   credential only as long as existing marked payments/outbox require its
   signature; handle pending events before revocation. Review incoming webhook
   verification during the transition; do not break outstanding payment events.
4. Rotate database/SMTP credentials with their consumers together. JWT rotation
   invalidates sessions and must be announced operationally. Verify Admin 2FA
   uses real SMTP while normal staging mail still uses Mailpit.
5. MASTER_KEY_B64 must NOT be replaced directly. Prepare a separate tested
   re-encryption migration covering every encrypted column (including registry
   application/provider/admin MFA secrets and tenant payment security tokens),
   verify decryption under the replacement key, and coordinate writer downtime,
   database commit and runtime switch. Keep recovery material protected until
   restore verification completes. This PR does not rotate or expose the key.
