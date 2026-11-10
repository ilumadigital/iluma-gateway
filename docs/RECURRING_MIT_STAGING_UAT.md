# Nexi XPay Greece recurring MIT — staging billing acceptance

**Never treat OneClick/CIT as an automatic charge mandate.** Automatic renewal
uses a separate MIT_SCHEDULED contract with explicit, versioned consent.
Card data and CVV remain exclusively at Nexi; ILUMA Gateway saves only provider
contract identifiers, consent timestamps, state and payment ledger entries.

## Release and migration

Deploy the Gateway `main` first, then run from its Hostinger root:

```bash
/opt/alt/php84/usr/bin/php bin/manage.php migrate-staging-saved-cards
/opt/alt/php84/usr/bin/php bin/manage.php migrate-staging-recurring
/opt/alt/php84/usr/bin/php bin/manage.php recurring-doctor-staging
/opt/alt/php84/usr/bin/php bin/work.php staging
```

**Keep these in the private Gateway env until all real sandbox UAT is passed:**

```dotenv
MIT_SANDBOX_EXECUTION_ENABLED=0
MIT_SANDBOX_UAT_APPROVED=0
PRODUCTION_ENABLED=0
```

No `POST /orders/mit` can occur while either is 0. Merely running the
recurring schema migration creates no payment and cannot enable the scheduler.
The existing Gateway Hostinger cron runs `php bin/work.php staging` every minute
and handles read-only contract verification, provider deactivation, and HMAC
delivery independently of the charge flags.

## Initial recurring payment and opt-in

- User explicitly checks "Αυτόματη χρέωση 3,99 € ανά μήνα" in iRadios.
- The iRadios backend sends server-signed checkout with `auto_renew:true` and
  `recurring_consent_version:iradios-mit-monthly-v1`.
- Gateway validates application, environment, scope, immutable 399 cents EUR,
  exact consent version and no contradictory CIT OneClick choices.
- It persists the pending mandate against the account and customer; Nexi
  HPP receives a new `MIT_SCHEDULED` contract ID, `contractFrequency:28`,
  and a provider contract expiry. This frequency is a provider MINIMUM
  spacing for calendar months, not a charge every 28 days.
- After captured first payment + HMAC webhook, Gateway independently queries
  `GET /contracts/customers/{customerId}`. The mandate becomes active only
  when the exact merchant contract is verified as `MIT_SCHEDULED`.
- The client can see status and cancel from Profile > Billing. No legacy
  OneClick card is automatically upgraded into an MIT mandate.

## Automatic charge execution (UAT gated, never production)

After explicitly verifying an active sandbox MIT contract, run a single
**isolated test subscription** end-to-end. Test the first HPP payment/SCA,
contract verification, callback & webhook; confirm that account has exactly
one paid monthly period. Test `GET /api/v1/recurring/status` and cancellation.

Only following verified Nexi merchant permission and hands-on UAT of first
payment, one `POST /orders/mit`, result reconciliation, subscriber webhook,
and cancellation, may an authorized operator set both flags to 1 in the
**staging private environment**. This is an explicit financial change.

The first card-on-file/recurring contracts use compact, opaque 18-character
merchant contract IDs. Older 27-character saved contracts remain recognized
for user-initiated CIT payments and cancellation. Test the Nexi HPP response
for exact provider acceptance; a previously recorded unknown checkout
must be reconciled before any replacement payment.

The worker:
1. Finds active, verified mandates past `next_due_at`.
2. Atomically creates **one** `payments` record and one
   `recurring_cycles` record for exact (mandate, due date).
3. Makes the single Nexi `POST /orders/mit`. On timeout or provider
   error, it records `unknown`, never repeats POST.
4. Reconciles that immutable order by GET. It emits a signed
   `payment.paid` with `billing_origin:automatic_renewal`,
   `external_user_id` and `cycle_due_at` only after captured funds.
5. iRadios validates the HMAC, account, amount, environment and linked
   paid enrollment, then atomically adds the next paid BillingOrder.
6. On confirmed failed MIT, it marks the mandate `past_due`, stops
   future automatic debits; no repeated unapproved retries.
7. Cancellation immediately changes mandate status to `cancelled`,
   nulls next charge and the worker deactivates Nexi provider contract.
   Previously confirmed paid periods stay active until their expiry.

## Mandatory negative cases

- No consent, wrong consent version, production app, double CIT/MIT choice:
  fail BEFORE payment creation.
- Unverified/unknown contract: never charge.
- Duplicate worker cron execution: max one payment per mandate and cycle,
  enforced by UNIQUE(mandate_id,cycle_due_at).
- Provider POST timeout, GET 404, unknown captured amount: no automatic
  second POST, no Premium grant.
- Cancel before due: no new payment; provider contract deactivated.
- Missing/blocked iRadios webhook: durable outbox retries exact event, no
  second charge.
- First payment only: MIT disabled by default; previously saved CIT card
  never implies recurring consent.

## Billing profile and invoice status

iRadios serves payment history through account-scoped HMAC Gateway lookup,
not user-controlled Gateway IDs. The Invoices tab intentionally remains
empty: no fabricated invoice, PDF or tax receipt exists yet.


## Gateway Admin: MIT subscriptions and safe future-date expiry preview

Previously, the Admin **Συνδρομές** page only read the legacy
`subscriptions` SQL table, while Nexi recurring subscriptions live in
`recurring_mandates`. The new staging admin now lists:

- Subscriber (customer email/name), owning application, plan and monthly VAT
  inclusive price; verified MIT state, paid-through UTC timestamp displayed
  in Athens time and next scheduled charge.
- MIT detail page with masked provider contract ID, consent and verification
  timestamps, cancellation state, initial HPP order and each signed recurring
  payment cycle with direct links to financial history.
- Dashboard Nexi MIT counts **separate** from legacy subscription counts.
- No production recurring admin data, card details, provider API calls or
  automatic charges performed by admin reads.

A customer having a paid Nexi HPP order and active `MIT_SCHEDULED`
contract will appear at
`https://gateway.iluma.gr/ilm-admin/?p=subscriptions`
when STAGING is selected. The first payment belongs to the initial HPP
and therefore recurring_cycles may remain empty until the first automatic
renewal.

### Read-only rehearsal for a 10 Dec 2026 renewal

Once deployed in the Gateway Hostinger project root:

```bash
/opt/alt/php84/usr/bin/php bin/manage.php recurring-doctor-staging
/opt/alt/php84/usr/bin/php bin/manage.php recurring-preview-staging 2026-12-10T18:21:00Z test@iluma.gr
```

Athens local time is UTC+02:00 in December, so 18:21Z = 20:21
Athens. Always take actual `next_due_at` from the admin; replace the
preview date if the particular sandbox account has a different cycle.

Preview **only runs SQL SELECT**. It prints whether the as-of clock has
reached the due date and at least 28 days since initial captured payment.
It never creates payments, changes entitlement dates, alters the provider
mandate, activates either financial feature flag or contacts Nexi.

Do not run arbitrary UPDATEs against `paid_through_at` or `next_due_at`
to force a real second charge. The live MIT_SCHEDULED provider contract
requires `contractFrequency=28` days. A database date adjustment cannot
override the Nexi contract and may cause rejection or an accidental
duplicate charge. The scheduler independently checks:
- Verified active mandate without cancellation
- Actual current time beyond next due, with next due matching paid through
- At least 28 full days from the latest *captured* paid transaction
- Unique ledger reservation per (mandate, original due date)
- BOTH staging UAT/charge flags enabled after external approval

With either flag set to 0, the real worker will not charge anything, even
after the actual expiry. This is intentional safety gating until a real
controlled Nexi MIT capture, signed iRadios webhook, idempotency and
cancellation test are confirmed. Do not claim that a virtual-date preview
proves successful automatic Nexi charging.
