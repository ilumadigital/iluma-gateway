# iRadios listener winback — Nexi Greece staging only

## Product and financial contracts

- Existing `L-PREM`: **399 cents EUR including VAT** per month.
- New first-time returning-listener `L-WINBACK-199`: **199 cents EUR including VAT**, for one verified first month only.
- The one-time offer is available after the latest signed, fully paid Premium period **and configured grace** end, for exactly **30 days**, and never for an active Premium account.
- The HMAC-signed iRadios backend supplies the verified expiry date; the browser can neither set a price nor provide its own expiry.
- Gateway cross-verifies prior captured L-PREM history for that exact Gateway customer (same `iradios-stage-{mongoId}`), and refuses a conflicting active/pending MIT contract. Cancel prior MIT in Profile Billing before buying again.
- `winback_claims` has `UNIQUE(application_id,customer_id)`. The claim is reserved to a specific payment and redeemed **only** when `Payments::reconcile` confirms captured payment from Nexi; retries are allowed after confirmed terminal failed/canceled outcomes, never while status is unknown/pending.
- For explicit MIT consent on the discounted initial payment, the new Nexi HPP charges **199 cents** while each later calendar-month MIT charge **399 cents** with the normal **L-PREM** product ID and SKU. Same 28-day provider spacing, single cycle ledger, webhook signature verification and provider UAT gates apply.
- No Nexi merchant key or live secret is ever put in Git, the frontend, or a URL.

## Deployment order — IMPORTANT

**1. Gateway:** merge/deploy Gateway main to Hostinger, then run only in the Hostinger Gateway project:

```bash
cd "$HOME/domains/gateway.iluma.gr/public_html"
/opt/alt/php84/usr/bin/php bin/manage.php migrate-staging-winback
/opt/alt/php84/usr/bin/php tests/run.php
```

The migration is idempotent, checks `APP_ENV` is not `production`, creates **only** the staging claim table, and installs **only** the one staging iRadios app SKU. It never triggers a provider charge.

**2. iRadios:** merge/deploy staging on its existing VPS:

```bash
cd /srv/iradios/staging/app
sudo ./ilumadev.sh
```

**3. Visual-only preview** (use while signed into stage as any account; preview deliberately works even when the account hasn't expired):

- Top ticker: https://stage.iradios.gr/?winback_preview=1
- Premium offer page: https://stage.iradios.gr/premium?winback_preview=1

Both previews contain clear STAGING labels and cannot initiate checkout. They do not mark the offer redeemed, adjust Premium expiry, manipulate Mongo timestamps or call Nexi.

**4. Actual checkout** only after an eligible account really expires, with verified previous paid Premium, no currently active entitlement, a cancelled old MIT mandate and a fully prepared Gateway DB/catalog.

## Sandbox safety / limitations

```dotenv
MIT_SANDBOX_EXECUTION_ENABLED=0
MIT_SANDBOX_UAT_APPROVED=0
PRODUCTION_ENABLED=0
```

Keep these flags OFF pending separate provider MIT test signoff; the first promo purchase can use sandbox HPP and create an MIT contract, but **subsequent unattended charges remain disabled**. Live payments and production promotion are NOT implemented or enabled here.

For operators: STAGING-only `bin/manage.php recurring-preview-staging YYYY-MM-DDTHH:MM:SSZ [email]` performs virtual-time **read-only** eligibility checks for a future MIT cycle; it does not prove Nexi charged a card. Never backdate a real signed Nexi mandate to bypass provider contract frequency.

Known limitation: an existing legacy account whose earlier Premium payment does not exist in the Gateway verified financial ledger is not eligible for this discount automatically; manual grant history is deliberately insufficient proof for a payment-based offer.
