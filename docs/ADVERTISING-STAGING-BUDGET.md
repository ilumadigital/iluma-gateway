# iRadios GR Advertising Budget Checkout — Staging only

Development PR #17. This is a staging-only extension, not a production payment feature.

- Special SKU: `ADV-BUDGET` only.
- The calling iRadios staging backend must authenticate with the existing per-application HMAC credential and idempotency key.
- `customer_id` must match `iradios-stage-<24 hex>`; `campaign_id` and `quote_id` must each be 24 lowercase hex characters.
- `amount_cents` must be a JSON integer in the permitted range; `currency` must be `EUR`.
- Recurring mandates, card-on-file, saved cards and arbitrary redirect destinations are forbidden for advertising.
- Gateway `ADVERTISING_STAGING_CHECKOUT_ENABLED=0` is the default. The operator must independently opt in with `=1` only after the staging tenant schema/product, tax policy, exact HMAC application credential and Nexi Sandbox preflight have been verified. The flag does not enable production advertising: `ADV-BUDGET` still requires `environment=staging` and `slug=iradiosgr` signed credentials. The shared Gateway web runtime may have `APP_ENV=production`; this does not make staging checkout a live Nexi transaction.
- Other fixed-price product SKUs remain on their original checkout validation.
- Payment scope is recorded atomically with `payments` in `advertising_budget_payments`; the quote ID and Gateway payment ID are unique and immutable.
- Provider-verified paid/failed/canceled events add `campaign_id`, `quote_id` and `external_user_id` to the signed outgoing event.
- Browser redirects are navigational and never credit budget. Signed events and Nexi authoritative reconciliation remain the source of truth.
- The paid event is processed and credited by the paired iRadios staging-only Advertising v2 handler.
- Staging-only database migration: `database/migrations/2026-11-staging-advertising-budget.sql`. Apply **manually** after a database backup, confirming the iRadios application ID and the correct staging tenant database. It has not been applied. **Required preflight:** Verify `applications.slug=iradiosgr` in the separate `iluma_registry`, then match its numeric ID to exactly one enabled `L-PREM` product in `iluma_staging`. Replace the migration’s deliberately `NULL` `@iradios_staging_application_id` with that verified ID before execution. Confirm exactly one `ADV-BUDGET` product for that ID afterwards. Leaving the variable `NULL` performs no product insertion; do not deploy checkout in that state. The migration must never provision other applications.
- Tests: `php tests/run.php` and `php tests/advertising_budget.php`; GitHub Actions runs PHP 8.3 and 8.4.
- The Gateway production host handles real payment traffic. **Do not merge/deploy PR #17 to production without a separate payment security review, provider sandbox UAT, rollback plan and user approval.**
- Manual Gateway deployment is via Hostinger hPanel after a Git merge. Git push alone is not a deployment.
- Both iRadios and Gateway currently keep the full prepaid CPM billing path disabled pending economic policy and atomic event settlement.

Order: business tax policy → source validation → manual staging migration → approved Gateway rollout → iRadios staging deployment → Nexi sandbox UAT → Mailpit and ledger tests. No payment operations or migrations have run from this branch.
