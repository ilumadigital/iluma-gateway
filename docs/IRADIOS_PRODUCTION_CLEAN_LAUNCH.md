# iRadios production clean commercial launch (zero test entitlements)

## Isolation and accounting contract

- Gateway Admin login defaults to **PRODUCTION** after MFA; the visible workspace switch still permits STAGING. This is a session UI preference, **not** a change to Nexi routing.
- The new `deploy/vps/clean-production-launch.py` is a **read-only** gate for a genuinely empty new operational production ledger. It never deletes, mutates or conceals historical payment records.
- Existing production tenant has one paid L-PREM and three unknown orders, with stage-routed origin credentials, plus archived/cancelled and pending mandates. The EUR 3.99 external refund is not recorded in the Gateway refunds table. These must be reconciled before opening a blank ledger. A database SQL delete is **not** a Nexi refund or mandate cancellation.
- Legacy iRadios staging-routed production payments may have receipts, refund obligations and future signed outbox deliveries. Preserve the original `order_id`, source credential, route pin and event history in a restricted, independently restorable archive; do not move it into a new live ledger as spendable funds.
- Do **not** truncate `iluma_production` (multi-application tenant), remove users/stations, drop databases, disable foreign keys or replay webhook events to reach zero.
- `iluma_staging` remains isolated; its sandbox/testing rows must not be copied into the live ledger.

## Read-only VPS preflight

```bash
cd /srv/iluma-gateway/app
sudo python3 deploy/vps/clean-production-launch.py --assert-clean
```

It prints aggregate counters only. A blocked audit is the **correct** result until old paid/unknown orders, refunds, provider contracts, webhooks and accounting archives are reconciled. Do not change flags to bypass the report.

## Required controlled preparation before data-reset implementation

1. Identify exactly which Nexi orders were real captures and whether a refund, void, mandate deactivation or customer entitlement survives. Resolve unknown orders with Nexi **without posting a new charge**.
2. Retain proof of the provider's results and stop every production writer, timer and checkout endpoint. Verify no unacknowledged signed event or future renewal points at the old tenant.
3. Take encrypted off-host backups of all three Gateway databases and test an isolated restore; preserve old orders/credentials/outbox for subsequent provider or regulatory inquiries.
4. Review the scope **per application ID** and require an approved, idempotent tenant migration/archival script with rollback; no generic deletion of a shared tenant's `payments` or `subscriptions`.
5. On the new clean operational ledger, require `--assert-clean` PASS with zero orders, mandates, contracts, outbox and funding scopes, while product catalog/credentials remain configured.
6. Enable new play routing only after SSL, signed webhook, operational production authentication and iRadios data/rights audit. Production recurring MIT needs Nexi merchant authorization and single-cycle UAT; advertising needs isolated signed funding and Mongo transaction UAT.
7. Keep a single current prod worker and preserve legacy order-routing reconciliation in a separate reviewed process. Never reissue an old paid event as new revenue.

## Release boundary

This code prepares safety checks and the Admin default, **not** authorization to reset ledger or process MIT charges. The go-live decision also needs the iRadios independent production baseline gate.
