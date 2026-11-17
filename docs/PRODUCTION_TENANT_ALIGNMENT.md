# Tenant parity — unified release operational notes

The original #30 tenant-alignment proposal and #28 production recovery overlap.
Use one reviewed unified Gateway release, not two independent merges or migrations.

2026-11-17 read-only VPS observations: production 15 tables, staging 22,
registry 6; absent in production: seven staging-only billing tables plus
winback_claims; winback_claims is absent in staging too. Production already has
iradios_stage_routed and origin_credential_id, and the production runtime role
already has SELECT/INSERT/UPDATE/DELETE but no DDL. Production has one
iluma-internal ILUMA-TEST-100 SKU; it must remain unchanged. Financial
tables inspected in production contain zero rows. The iRadios staging catalog
has current approved prices but lacks L-WINBACK-199.

The selected schema update is the reviewed `align-tenants.py` against both
tenants. Audit without flags first; `--apply --verified-offsite-restore` is an explicit DDL/DML operation,
permitted ONLY after verified encrypted off-host backups, isolated MySQL restore
of the current snapshot, approved diff and maintenance window. It creates a
new local backup, but does NOT substitute for an off-host restore drill.
Existing/legacy unexpected prices are blockers; no automatic price updates.
Do NOT also run `production-billing.py --apply`.

`iluma_registry` is a separate shared registry. Never mix it with either
tenant. Never copy payments, subscriptions, mandates, cards, tokens, customer
data, webhook/outbox rows or consents between tenants.

After SQL alignment, check table/column/index/FK parity, DML, 17 scoped
iradiosgr SKU prices, signed production readiness and production provider
merchant/terminal separately. Keep live payments and both MIT flags gated.

The public Gateway origin is the VPS 185.230.64.89, but keep a deployed SHA
and verify actual proxy/runtime after deploying; Git push alone does not deploy.

Canonical operator runbook: `docs/PRODUCTION_BILLING_RECOVERY.md`.
