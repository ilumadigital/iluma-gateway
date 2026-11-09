# ILUMA Payment Gateway — Custom PHP (development foundation)

**Αυτό είναι εκτελέσιμο development foundation, όχι ολοκληρωμένο ή πιστοποιημένο production payment gateway.** Εστιάζει στον ασφαλή βασικό κορμό, σε προσαρμοσμένο ελληνικό backoffice, στο sandbox HPP flow και στο επαληθευόμενο payment ledger. Κανένα πραγματικό credential δεν έχει χρησιμοποιηθεί, καμία χρέωση δεν έχει πραγματοποιηθεί.

## Στοιχεία συστήματος

| Component | Implementation | Status |
|---|---|---|
| Custom PHP 8.3+, PSR-4 style autoload | `src/bootstrap.php`, `src/*` | Implemented |
| Data isolation | registry, staging, production MariaDB/MySQL DBs | Implemented schema; no live DB instantiated here |
| HMAC application API | Signature, timestamp, nonce, replay protection | Implemented, requires integration tests |
| Greek admin | Analytics, payments, logs, customers, subscriptions, keys, Nexi credential storage | Implemented baseline, not full feature parity |
| Nexi Sandbox checkout | Hosted Payment Page (`POST /orders/hpp`) | Code present, awaiting sandbox key/UAT |
| Nexi payment lookup | `GET /orders/{orderId}` + conservative outcome mapping | Code present, awaiting sandbox UAT |
| Nexi callbacks | securityToken + order lookup + dedup | Code present, awaiting integration UAT |
| Signed outgoing iRadios events | Durable outbox + cron worker | Code present; receiving iRadios endpoint not implemented |
| Recurring MIT, cancellation, product sync | Disabled until Nexi merchant behavior validated | NOT IMPLEMENTED |
| Refund accounting, granular analytics | Refund schema, CSV and basic dashboard; advanced analytics/refund execution missing | NOT COMPLETE |
| Apple Pay, Google Pay, embedded XPay Build | Merchant activation and integration required | NOT IMPLEMENTED |
| Production payments | Locked by default | NOT ENABLED |
| Automated tests | Isolated security/provider-state tests | Offline tests only |


## GitHub and developer workflow

The repository tracks **source and safe examples only**. `.gitignore` excludes `.env` / `.env.*` (but keeps `.env.example`), credentials, private keys, local database snapshots, logs, runtime state, dependencies (`vendor/`) and local archives. `.editorconfig` and `.gitattributes` normalize source formatting and line endings.

- Branch: `main`. Use a feature branch and pull request for future changes to payment or security logic. Do not store live credentials in GitHub Actions secrets unless required for a carefully controlled workflow.
- CI (`.github/workflows/ci.yml`) runs Composer metadata validation, PHP syntax checks and **offline-only** tests on PHP 8.3 and 8.4. It performs **no** Nexi network calls, checkout, deployment, migration or production unlock.
- Local checks: `composer validate --no-check-publish` (if Composer is available), `find src public bin tests -name '*.php' -print0 | xargs -0 -n1 php -l`, and `php tests/run.php`.
- Never commit `.env`, `.pem`, `.key`, production database dumps, API tokens or TOTP seeds. If a secret is accidentally published, revoke and rotate it immediately: removing a file from a later commit does not erase it from Git history.
- `PRODUCTION_ENABLED=0` remains the required default. A green CI status **does not** mean the system is production-ready; Nexi Sandbox UAT, external security assessment and a separate production release approval are still required.

## 1. System requirements

- PHP 8.3+ CLI + FPM with `pdo_mysql`, `sodium`, `curl`, `json`, `openssl` extensions; MySQL 8 / compatible recent MariaDB with strict mode, Nginx, TLS.
- Three **separate** databases and DB users. Grant each runtime only minimum permissions. Registry contains admin users and encrypted API credentials; tenant DB contains money records.
- An independent secrets manager or protected process environment supplying all variables in `.env.example`. For Hostinger, the restricted loader in `src/EnvLoader.php` reads only a private file outside the webroot (see `docs/HOSTINGER.md`).
- Dedicated `gateway.iluma.gr` with protected admin at `https://gateway.iluma.gr/ilm-admin`, optional additional Zero Trust/VPN gate for `/ilm-admin*` and valid HTTPS certificates.

## 2. Installation

1. Provision three databases `iluma_registry`, `iluma_staging`, `iluma_production` and corresponding least-privilege users.
2. Run `database/registry.sql` on the registry, and independently run `database/tenant.sql` on **each tenant database**. These SQL files are baseline migrations and are not a versioned migration engine.
3. Configure all environment variables from `.env.example` in PHP-FPM service config / secret store AND worker service. Generate `MASTER_KEY_B64` from secure random 32-byte key and never put it in public webroot.
4. Keep `PRODUCTION_ENABLED=0` and `APP_ENV=development`. Configure `NEXI_STAGING_BASE` as the documented sandbox URL and `NEXI_PRODUCTION_BASE` as the documented live URL; configuration rejects endpoint swapping.
5. Install the sample `deploy/nginx.conf.example` only **after hardening**. Update FPM socket paths, add TLS, authenticated edge/VPN enforcement and rate limits. `ADMIN_ACCESS_VERIFIED=1` must only be set when the edge has been enforced and tested.
6. Create an initial admin in terminal: `ADMIN_INITIAL_PASSWORD='long-strong-password' php bin/manage.php create-admin admin@example.org`. Store the one-time Base32 seed in a TOTP authenticator (the command also prints a provisioning URI). Clear shell history, never log it.
7. Register the iRadios application API secret: `php bin/manage.php create-key staging`. Put the printed API Key and Secret **only** into the iRadios staging backend secure config. The key cannot be recovered afterwards.
8. Add a fixed-price staging product, e.g. `php bin/manage.php product staging premium-monthly 499` (499 cents = €4.99). This is a **catalog configuration example**; the source specification does not supply actual product pricing. Verify prices with iRadios before using.
9. Enter the Nexi Sandbox key through protected admin → Nexi Settings (or build a controlled CLI secret bootstrap). Run the non-charging Test Connection.
10. Call staging `POST /api/v1/payments/checkout` from the iRadios backend with the HMAC protocol in `docs/API-INTEGRATION.md`.
11. Run a cron every minute for `php bin/work.php staging` as a non-root account. Worker reconciles uncertain payments, delivers signed outbox events, and cleans stale nonces.
12. Run offline tests: `php tests/run.php` and lint checks. Then perform real sandbox UAT with approved Nexi test credentials before considering live.

## Directory map

- `public/index.php`: public API, hosted result page, Nexi notifications; returns neutral 404 otherwise.
- `public/admin.php` + `public/admin.css`: protected Greek admin interface.
- `src/Security.php`: encrypt/decrypt, HMAC signatures, nonce, request validation, MFA.
- `src/Payments.php`: payment intent + idempotency, conservative Nexi status reconciliation and audit timeline.
- `src/NexiClient.php`: host-pinned XPay Greece HPP and API lookup.
- `src/Worker.php`: outbox dispatcher and read-only reconciliation.
- `database/*.sql`: schema (registry and tenant separation).
- `bin/manage.php`: admin, API key and catalog initialization.
- `bin/work.php`: periodic reconciliation and delivery.
- `docs/*`: integration, security and acceptance checklist.

## Tests and access boundaries

The admin environment switch changes only which DB is viewed; it never switches API charging contexts. A production key points to the production DB and production Nexi credentials and is blocked by the release gate. The gateway never grants Premium entitlement; only iRadios may perform that action on a verified paid event.

## Official Nexi XPay Greece reference (verification 2026-11-09)

- API reference: https://developer.nexigroup.com/xpaygreece/en-EU/api/
- HPP API: https://developer.nexigroup.com/xpaygreece/en-EU/api/payment-api-v1/
- Hosted page architecture: https://developer.nexigroup.com/xpaygreece/en-EU/docs/hosted-payment-page/
- Notification schema: https://developer.nexigroup.com/xpaygreece/en-EU/api/notification-api-v1/
- XPay Build: https://developer.nexigroup.com/xpaygreece/en-EU/docs/build-functionality/
- Tokenized/recurring: https://developer.nexigroup.com/xpaygreece/en-EU/docs/tokenization-payments/

The official API reference states sandbox base `https://xpaysandbox.nexigroup.com/api/phoenix-0.0/psp`, production base `https://xpay.nexigroup.com/api/phoenix-0.0/psp`, HPP `POST /api/v1/orders/hpp` and status `GET /api/v1/orders/{orderId}`. Merchant-specific eligibility and capture semantics must be verified before enabling live charges.

## Hostinger Git auto-deployment

The tracked root `.htaccess` restricts access when Git deploys the entire repo to Hostinger `public_html`. See [Hostinger deployment guide](docs/HOSTINGER.md) to set the private ENV path, verify inaccessible source files, check database connectivity with `php bin/manage.php doctor`, and create the first MFA administrator. The public document root remains a security limitation: validate edge routing before storing credentials or opening admin access. Production charges remain disabled.

### Admin dashboard URL

Admin is served at **https://gateway.iluma.gr/ilm-admin** (with or without trailing slash), not a second subdomain. The login form is accessible with email, password and Google Authenticator-compatible TOTP; unauthenticated visitors cannot view payment data. Failed-attempt lockout and per-IP throttling are enforced. Additional VPN/Zero Trust protection for `/ilm-admin*` is recommended. The old `ADMIN_HOST` and `ADMIN_ACCESS_VERIFIED` variables are no longer enforced by the admin route.

## Admin visual refresh and MFA confirmation

The admin at **https://gateway.iluma.gr/ilm-admin** now uses a responsive,
SwiftUI-inspired style. Email + password opens the dashboard; a Google
Authenticator sheet appears only after initiating a sensitive action (Nexi
key save/replace, application key creation/deactivation, or CSV export).
These operations execute **only after server-side TOTP validation** and CSRF
checks. Optional extra network access controls are recommended for payment
operations. Production payments remain disabled.
