# ILUMA Gateway: Hostinger Git auto-deployment

Hostinger Git deployments commonly place the whole repository in public_html.
Only the public/ directory should ideally be internet-facing. This repository
includes a root .htaccess denying direct access to source and routing explicitly
allowed requests. This workaround must be tested on the real server; it is not
a replacement for a dedicated public/ document root on a hardened VPS.

## Private config placement

When the Git destination is:
    /home/ACCOUNT/domains/gateway.iluma.gr/public_html/

Place the actual configuration here:
    /home/ACCOUNT/domains/gateway.iluma.gr/private/iluma-gateway.env

Use 0700 on private/ and 0600 on iluma-gateway.env, readable only by the
PHP runtime user. Never add this file to Git or public_html. The loader also
works when Git is deployed into a subfolder of public_html by finding its
nearest public_html ancestor. If Hostinger's open_basedir setting denies
access to this private path, request an approved hosting configuration
or use a VPS; never move the secrets into public_html to bypass it.

Copy variables from .env.example and update all DB DSNs/users/passwords,
MASTER_KEY_B64 and the fixed webhook URLs. Keep APP_ENV=development
and PRODUCTION_ENABLED=0; public admin login requires password plus TOTP.

IMPORTANT: Do not append /api/v1 to NEXI_STAGING_BASE or
NEXI_PRODUCTION_BASE. The API client already appends /api/v1.
Nexi Sandbox and Production API keys are not ENV variables here: save
them separately through protected Admin -> Nexi Settings, encrypted in
the Registry database.

## Verify access controls before entering credentials

Verify that .htaccess is present at the Git deployment root.
Check these URLs using curl, in a browser or via an external monitor:
    https://gateway.iluma.gr/
    https://gateway.iluma.gr/README.md
    https://gateway.iluma.gr/src/Config.php
    https://gateway.iluma.gr/database/registry.sql
    https://gateway.iluma.gr/public/admin.php
    https://gateway.iluma.gr/.env.example
    https://gateway.iluma.gr/admin.php
All must return 404, never source content. Check encoded paths,
backup extensions, direct PHP files and alternate hosts too. If any
file can be downloaded, disable public access and correct web routing.
Hostinger uses Apache/LiteSpeed-compatible .htaccess on Web/Cloud
plans; Nginx deployments must use their own server-level rules.

## CLI checks, administrator and MFA

From a private SSH session in the deployed project folder, run:
    php -v
    php bin/manage.php doctor
    php tests/run.php

The doctor checks 6 Registry tables, 15 Staging tables, 15 Production
tables, the master key format, pinned Nexi endpoints and the production
lock. It does not reveal secrets or call Nexi.

The admin uses https://gateway.iluma.gr/ilm-admin (also /ilm-admin/) on the
same Git deployment. Do not create a second hostname. The login requires email and password (Argon2id). A Google Authenticator-
compatible TOTP confirmation is required for sensitive writes and CSV exports.
The session uses CSRF protection, login lockout and an IP-based throttle. Optional
VPN/Zero Trust access to /ilm-admin* is defense in depth and strongly
recommended for a payment administration panel, but the login page no
longer depends on ADMIN_ACCESS_VERIFIED. The old ADMIN_HOST and
ADMIN_ACCESS_VERIFIED variables are ignored by the admin route.

Create the first administrator via
the SSH terminal (replace the email address):
    read -rsp 'Admin password (at least 16 chars): ' ADMIN_INITIAL_PASSWORD; echo
    export ADMIN_INITIAL_PASSWORD
    php bin/manage.php create-admin admin@example.com
    unset ADMIN_INITIAL_PASSWORD

The CLI prints a one-time Base32 TOTP secret. Enter it manually into
your authenticator and never copy it to public logs or Git. Log in at https://gateway.iluma.gr/ilm-admin with email and password; MFA is requested when confirming a sensitive action.
Do not disable the login rate limit, account lockout or TOTP verification.

## Sandbox onboarding

Inside protected Admin select STAGING, open Nexi Settings, and save
your Sandbox API Key. Use Test Connection (read-only Nexi GET
/api/v1/payment_methods) before attempting any Sandbox checkout.
Generate a separate iRadios Staging API Key/Secret and store them
only in the iRadios backend.

This development foundation is NOT production ready. No live payments,
recurring charges, refunds or real money operations are authorized.
Real Nexi UAT, iRadios webhook integration, penetration testing and
a PCI scope assessment remain prerequisites.

## Admin path checks

- Visit https://gateway.iluma.gr/ilm-admin and verify the Email / Password / MFA login form loads.
- Login needs email and password; sensitive actions then require Google Authenticator confirmation.
- Set an optional VPN or Zero Trust gate around /ilm-admin* if available for extra protection.
- The root path / and direct /public/admin.php must stay 404. No new DNS subdomain is required.

## HTTP Internal server error — safe first-line checks

The PHP bootstrap / API intentionally hides stack traces from public responses.
If you see **Internal server error**, do not change the master encryption key,
recreate customers/payments, enable live payments or repeat a Nexi checkout.

From the deployed **main** source directory in a private Hostinger SSH shell:

```bash
php -v
php -l public/admin.php
php -l public/index.php
php -l src/EnvLoader.php
php bin/manage.php doctor-http
php tests/run.php
```

`doctor-http` prints only PHP extension, env-file access, session directory,
staging/registry database and schema **status**. It does **not** show API keys,
DB passwords, file paths, hostnames, customer records or payment data, and does
not contact Nexi. If a check fails, inspect the **private Hostinger PHP error
log** for the exact exception. Do not publish unredacted stack traces.

The private env parser is strict. If you set the optional sandbox renewal flags,
use only these exact names (both flags for each product must be `0` until UAT):

```env
MIT_SANDBOX_EXECUTION_ENABLED=0
MIT_SANDBOX_UAT_APPROVED=0
BROADCASTER_MIT_SANDBOX_EXECUTION_ENABLED=0
BROADCASTER_MIT_SANDBOX_UAT_APPROVED=0
```

After a source deployment, the **staging** Broadcaster tables are separate and
must be installed explicitly only on the staging database, before starting
Broadcaster checkout. Never run the migration against production:

```bash
php bin/manage.php migrate-staging-broadcaster
php bin/manage.php audit-staging-broadcaster
```

`APP_ENV=staging` is required for those two commands; if your shared gateway
uses `APP_ENV=development` or `production`, do not override it on a live
application process to work around the guard. Use an isolated staging runtime.
Invoice/receipt issuance and AADE/myDATA reporting are handled **manually by ILUMA**.

## When CLI doctor prints nothing or admin returns 500

Run the following inside the deployed project directory:

    php -v
    php -l bin/manage.php
    php -d display_errors=1 -d display_startup_errors=1 bin/manage.php doctor 2>&1
    echo "EXIT CODE: $?"

Hostinger's default SSH PHP version can differ from the site's PHP version.
You can check PHP 8.4 explicitly with /opt/alt/php84/usr/bin/php -v and
run /opt/alt/php84/usr/bin/php bin/manage.php doctor if installed. The
configuration loader now prints restricted errors to STDERR for CLI.
If it reports permissions, ensure the private env file is chmod 600.
Do not enable display_errors on the public website, show secrets, or move
private env files into public_html. For web HTTP 500, inspect Hostinger
PHP error logs; .htaccess server failures may not reach PHP.

## Troubleshooting web 500 even when CLI doctor is OK

The admin login page no longer connects to MySQL until login is submitted.
If it still says Internal server error, consult the domain PHP error log.
If login appears but POST fails, confirm that web PHP also has pdo_mysql,
sodium, mbstring and a readable private env. CLI PHP 8.4 working does not
prove PHP-FPM configuration. Run (never paste any passwords):

    /opt/alt/php84/usr/bin/php bin/manage.php doctor

The doctor now checks that the first enabled administrator's stored MFA secret
can be decrypted with the current MASTER_KEY_B64. If this fails, do not
regenerate the master key randomly; investigate the historical key securely.

## SwiftUI-style admin and action-based MFA

The responsive admin uses a light, native-app-inspired layout with notification
cards and a confirmation sheet. Login requires email and password. A Google
Authenticator modal appears only after the administrator clicks on saving or
replacing a Nexi API key, creating/disabling an application API key, or
exporting CSV. Changes and exports are **performed only after** successful
server-side TOTP verification. The step-up check applies even if a client
bypasses JavaScript; five failed MFA attempts cause a 15-minute session lock.
No TOTP is needed just to view the dashboard or check the Nexi connection.
The optional VPN/Zero Trust gate remains recommended as extra protection.

Verify that both /ilm-admin/admin.css and /ilm-admin/admin.js return HTTP 200.
The admin CSP permits scripts only from 'self'. Do not enable live payments.

## Sandbox authorization versus captured payment

A successful Hosted Payment Page can return an AUTHORIZATION operation with
`authorizedAmount=100`, `capturedAmount=0` and no CAPTURE. This is **not** a
confirmed payment and must never unlock iRadios Premium. XPay Greece
supports `paymentSession.captureType=IMPLICIT` to automatically confirm
approved card payments, even when the demo terminal defaults to EXPLICIT.
`src/NexiClient.php` now supplies that field **only for Staging checkouts**;
Production remains unchanged and disabled. This changes only future HPP
sessions; existing authorized orders are NOT captured retroactively.
An outstanding Sandbox authorization can be manually captured through the
provider's supported accounting process or allowed to expire; never edit
local payment rows directly to 'paid'. Read the provider order again after
a successful capture. Do not create duplicate checkouts to resolve an
existing intent. For a separate new sandbox test, use a new purchase intent
and an unused idempotency key.

## Observed implicit-capture response: AUTHORIZATION / EXECUTED

The XPay Greece Sandbox has returned the following on a completed implicit
checkout (example test order): `authorizedAmount=100`, `capturedAmount=100`,
`lastOperationType=AUTHORIZATION`, operation `AUTHORIZATION / EXECUTED`.
An older PaymentState implementation required an additional CAPTURE operation
and incorrectly returned `unknown`. The updated classifier recognizes this
**specific provider-confirmed case** as paid only when the GET /orders response
matches the expected order ID, exact order amount/currency, exact captured
amount and successful operation amount/currency. An AUTHORIZED-only operation
or `capturedAmount=0` remains unknown. Refund, cancel and void must not be
reclassified as newly paid.

After deploying this fix, rerun the read-only provider reconciliation for the
existing observed order; it will update the Staging database and queue an
iRadios event only if the provider still confirms a captured payment. The
iRadios event delivery worker must be configured and verified separately.
Never manually update the payment status in the database.


## Critical: staging payment delivery to iRadios (worker / cron)

**Do not create another payment when Gateway says `paid` but its outgoing
iRadios event is `pending`.** The existing signed `payment.paid` event must
be delivered to the matching MongoDB BillingOrder.

To process the existing staging outbox once, from the deployed **Gateway**
project directory (not the iRadios server):

```bash
cd "$HOME/domains/gateway.iluma.gr/public_html"
/opt/alt/php84/usr/bin/php bin/work.php staging
```

Adapt the directory or PHP 8.4 executable if the Hostinger account differs.
This call does not create a payment or touch production; it reconciles existing
orders and attempts authenticated delivery of queued events. Refresh the
existing payment details in Gateway admin after execution:

- `delivered`, HTTP 2xx: iRadios acknowledged the event. Refresh the
  existing iRadios Premium page or re-login to update the account.
- `pending`, attempts `0`: worker did not reach this event. Check that the
  PHP CLI executes successfully, has the private env, and cron is installed.
- `pending`, attempts `>0`, HTTP `401`: check matching staging HMAC secret,
  not payment prices or cards. Disable superseded API credentials only after
  validating the intended active secret.
- HTTP `403` or `503`: check Cloudflare/Nginx protection or iRadios
  webhook correlation respectively. Never disable signature validation.
- HTTP `0`: transport/TLS timeout or blocked outbound connection. Review
  private worker logs; do not print secrets.

A proper Hostinger **cron job** must also run the staging worker **every
minute**, using a path approved for that account, for example:

```cron
* * * * * /opt/alt/php84/usr/bin/php /home/ACCOUNT/domains/gateway.iluma.gr/public_html/bin/work.php staging
```

Configure the command in Hostinger **Cron Jobs** for the Gateway account,
replacing `ACCOUNT` with the actual hosting account name. Do not create a
production cron unless separately approved and live is enabled; keep
`PRODUCTION_ENABLED=0` during sandbox tests. PHP CLI errors should go to
a private hosting log, not a world-accessible path or response.

Gateway also attempts immediate per-payment delivery after an authenticated
Nexi notification or a reconciled result/cancel return. The durable outbox
and scheduled cron are still required for Cloudflare/network retries. Neither
mechanism initiates a new provider charge or grants account entitlements
without iRadios' HMAC verification.


## Nexi OneClick CIT — card-on-file staging sandbox only (2026-11-10)

**OneClick CIT is NOT recurring MIT.** Nexi retains the encrypted payment
instrument against its provider contract. ILUMA Gateway stores only a random
merchant-issued `contract_id`, explicit consent version and timestamp,
Nexi-confirmed contract status and the exact payment/customer correlation.
Neither service stores PAN, expiry date or CVV. User-initiated CIT renewals
still go through Nexi Hosted Payment Page (3DS can be requested). No automated
renewal charge or MIT endpoint is enabled.

### Install schema (Gateway Hostinger SSH, staging only)

Deploy Gateway `main` and, from its project root, execute ONCE (idempotent):

```bash
/opt/alt/php84/usr/bin/php bin/manage.php migrate-staging-saved-cards
/opt/alt/php84/usr/bin/php bin/work.php staging
```

Ensure `APP_ENV` is not production. This creates one isolated table in the
**staging tenant**. Production tenant schema and billing states stay intact.
A rollback requires revoking any active provider contracts first; do not drop
financial/consent records just to undo a deployment.

### Consent checkout

1. iRadios sends `save_card:true` plus fixed
   `card_consent_version:"iradios-stored-card-v1"` only after the subscriber
   explicitly checks the opt-in. Unchecked creates an ordinary 3.99 EUR HPP
   and never creates a contract.
2. Gateway persists a `pending` consent scoped to the account,
   application, staging environment and payment; Nexi HPP gets
   `paymentSession.recurrence = {action:"CONTRACT_CREATION", contractId,
   contractType:"CIT"}`.
3. Only after captured `payment.paid` does Gateway call
   `GET /contracts/customers/{providerCustomerId}` and validate the exact
   CIT `contractId` before marking the stored method `active`.
   A network timeout never confirms storage. Staging worker retries the
   read-only verification; payment event delivery is independent.
4. Subsequent payment: user must explicitly click "Pay with stored card".
   Backend HMAC requests `use_saved_card:true`; Gateway selects an
   existing verified, non-revoked CIT and starts a **new** Nexi HPP with
   `action:"SUBSEQUENT_PAYMENT"` and the same contract. It still requires
   user interaction and may require 3DS.
5. `POST /api/v1/payment-methods/status` and `.../revoke` are HMAC
   server-to-server routes scoped by Gateway application + external user.
   Revocation disables future use immediately and attempts
   `POST /contracts/{contractId}/deactivation` at Nexi; failures are retried
   by staging worker. No browser sees the provider contract ID.

### Acceptance test

- No opt-in: successful normal HPP, saved card remains `none`.
- Opt-in + sandbox card: HPP, provider paid, signed webhook, provider contract
  verified and saved card transitions from `pending` to `active`.
- New manual renewal with saved card: Nexi OneClick HPP asks for appropriate
  authentication but not full card re-entry; paid event opens a new iRadios
  subscription period only after signed webhook.
- Revoke: state `revocation_pending` until Nexi acknowledges; after `revoked`,
  subsequent CIT calls are denied. Do not auto-charge or infer a successful
  provider deactivation from a timeout.
- Keep `PRODUCTION_ENABLED=0`. A separate MIT mandate with pricing, interval,
  cancellation, retry/dunning, tax and SCA review is necessary before automatic
  charges may be enabled.

## 2026-11-11: one canonical Admin and safe Hostinger redeployment

Only `public/admin.php` is the public entrypoint for `/ilm-admin` and
`/ilm-admin/`. It is intentionally a small wrapper loading the **one** private
`src/AdminController.php` implementation. No release-numbered admin files,
duplicate public controllers or alternate admin routes are permitted. This
keeps the login/session/MFA/HTML code out of the web-facing entrypoint, which
previously suffered repeated loss of a specific PHP block on Hostinger.
The payment API remains independently served by `public/index.php`.

**Important:** This refactor limits damage from a stale text edit targeting
the old monolithic `public/admin.php`, but it does NOT prevent an outside
process or deployment from rewriting the wrapper. A repeated mismatch of
`git hash-object public/admin.php` against `git rev-parse HEAD:public/admin.php`
still requires Hostinger file-write attribution. Do not attribute this to
Google Authenticator, CSRF or the payment cron: none writes PHP source files.
Verify the exact deployed Git snapshot after manual Hostinger Git redeploy.

GitHub merges do not deploy to Hostinger. Use Hostinger hPanel → Websites →
gateway.iluma.gr → Git → **Redeploy from Git**, using the reviewed main branch.
Deploy a complete snapshot, not a mixture of old and new PHP files. For stale
OPcache, use Hostinger PHP controls/support to invalidate/reset the cache; do
not create another versioned PHP entrypoint.

From the actual Git checkout at Hostinger, after manual redeploy, run:

```bash
cd "$HOME/domains/gateway.iluma.gr/public_html"
bash bin/check-hostinger-deploy.sh --live
php bin/manage.php doctor-http
```

Preflight checks Git source integrity, all PHP syntax, the single admin route,
and unauthenticated login and payments-page views. `--live` requires an
HTTP 200 response and `X-ILUMA-Admin-Entry: canonical` for
`https://gateway.iluma.gr/ilm-admin/?p=payments`. These checks are read-only.
If Git metadata is not included in the Hostinger deployment, the Git integrity
check cannot run: verify the exact deployed commit in hPanel, and run PHP lint
and HTTP checks separately.

A signed-out HTTP 200 is not proof of an authenticated payments query.
Sign in and check payments, subscriptions, and dashboard; review private PHP
errors if any fail. Do not change MySQL credentials, master keys, customer data,
payment states or Nexi transactions as a workaround. Never blindly use
`git reset --hard` on a live host.

GitHub CI prevents new duplicate versioned admin PHP files and tests the
one public/admin.php controller before merge.


## 2026-11-14: recurring AdminController.php corruption / HTTP 500

### Observed incident; cron correlation is not attribution

The Hostinger LiteSpeed web runtime repeatedly returned HTTP 500 with
"PHP Parse error: Unmatched '}' in src/AdminController.php on line 16".
A targeted Git restore recovered the correct blob, but the file was modified
again within approximately a minute. The observed diff deleted a block of PHP
after the canonical admin entry header. The deployed commit was 5d8a8df
and the clean canonical Admin Git blob was
7ebf87690f7411589eb5e11214b76f8ff577ab33.

The staging worker also runs every minute, but correlation is NOT proof that
it writes the file. Its inspected runtime code does DB operations and
authenticated outgoing HTTPS; the entrypoint uses a temporary flock lock.
Do NOT permanently disable the Hostinger cron: it delivers confirmed events.

### Safe read-only integrity checks

From the Hostinger SSH checkout, run:

    php bin/check-source-integrity.php
    php -l src/AdminController.php
    git diff -- src/AdminController.php
    git status --short
    bash bin/check-hostinger-deploy.sh --live

The independent check-source-integrity.php checks the file even when the PHP
controller cannot parse, without Git metadata, a database, ENV, or Nexi.
It compares the actual source to a pinned, reviewed Git blob. After approved
Admin controller changes, update SourceIntegrity::ADMIN_GIT_BLOB in the same
PR: CI fails when the expected blob does not match the reviewed source.

The worker now checks that fingerprint at startup and shutdown. When it sees
a mismatch it logs ILUMA SOURCE INTEGRITY ALERT with the actual blob hash,
UTC mtime and inode. This is DETECTION ONLY: it does not repair source,
alter financial records, or block pending signed payment event delivery.
Clean checks do not prove that the source will remain unmodified.

Missing CLI sodium produces an explicit failure instead of leaving signed
events pending silently. Verify the exact PHP runtime used by Hostinger cron:

    /opt/alt/php84/usr/bin/php -r 'echo extension_loaded("sodium") ? "SODIUM OK\n" : "SODIUM MISSING\n";'
    /opt/alt/php84/usr/bin/php bin/manage.php doctor-http

Enable sodium in the actual worker runtime if it is missing. Do not change
credentials, API secrets, prices or payment states to compensate.

### Preserve evidence before targeted restoration

Capture stat, git diff, PHP error log and deployment timeline before restore:

    stat -c '%n inode=%i owner=%U mode=%a mtime=%y ctime=%z' src/AdminController.php
    git diff -- src/AdminController.php

Then restore ONLY the damaged tracked source from the already-reviewed Git
checkout, never the whole repository:

    git restore --source=HEAD --worktree -- src/AdminController.php
    php -l src/AdminController.php
    php bin/check-source-integrity.php

Never blindly reset --hard, regenerate MASTER_KEY_B64, or build an automatic
self-healing cron that overwrites evidence. The deploy preflight now avoids
Bash process substitution (/dev/fd/63) and uses IPv4 for the live probe:
the hosting server was observed resetting TLS handshakes over IPv6.

### Hostinger must attribute the file writer

Ask Hostinger Support to identify the process behind writes around
2026-11-14 12:41:27 UTC and subsequent changes to
src/AdminController.php: process/PID, effective UID, entry point, Git
deployment hooks, cron scheduler, SSH/SFTP, File Manager, LiteSpeed/PHP and
filesystem audit history. The inode/mtime and one-minute timing do not
prove which process made the edit.

The permanent prevention is to separate deployment identity from the PHP web
and worker identities; the runtime should have no source-tree write access,
and state/logs should be stored outside the source tree. On shared hosting
where PHP, Git deployment and SSH use the same account identity, chmod 444
alone is not reliable protection: a writable parent directory can allow
replacement of the file. If Hostinger cannot provide trustworthy write
isolation and audit history, move the payment gateway to an isolated hardened
VPS with separate release/deploy and runtime users. Review unauthorized
hosting access and credentials with Hostinger.

### Release procedure

1. Merge a reviewed PR after PHP 8.3 and 8.4 CI checks pass.
2. In Hostinger hPanel manually Redeploy from Git for gateway.iluma.gr;
   Git push alone does not deploy to production.
3. Verify the deployed commit and run the standalone source integrity
   check, the deployment preflight and the IPv4 live HTTP smoke test.
4. Check Admin login HTTP 200 and verify an existing signed staging webhook
   event is delivered. Do not create a duplicate checkout for diagnosis.
5. Continue to monitor integrity and ask Hostinger for writer attribution
   if the file is modified again. Green tests are not a root-cause proof.


## 2026-11-14: Active Admin cutover away from repeatedly rewritten filename

The recurring Hostinger file rewrite affects the old src/AdminController.php
path. The canonical Admin implementation now lives in src/AdminApplication.php,
and the only public entrypoint public/admin.php requires that private file
directly. The old AdminController.php is now an UNUSED short compatibility
shim. A source rewrite of ONLY that legacy path must no longer cause the
live /ilm-admin/ route to fail. This is an AVAILABILITY MITIGATION,
NOT proof that the writer is stopped or the hosting account is secure.

The read-only integrity checker monitors ACTIVE AdminApplication source and
reports legacy-source corruption as a warning. The payment worker logs active
and legacy drift independently. The preflight fails on unexpected drift of
active code and only warns on a changed unused legacy shim. None of the
checks repairs files or modifies payment records.

After merging and manually redeploying the main branch in Hostinger hPanel:

    cd "$HOME/domains/gateway.iluma.gr/public_html"
    git rev-parse --short HEAD
    php -l public/admin.php
    php -l src/AdminApplication.php
    php bin/check-source-integrity.php
    bash bin/check-hostinger-deploy.sh --live

If the old file is still rewritten but AdminApplication.php stays intact,
the Admin should remain functional while the writer is investigated. Confirm
the actual web login loads. If the new active code is also modified,
the source writer is NOT contained.

Potential Hostinger malware cleanup: hPanel offers Websites > Manage site >
Security > Malware Scanner > Infected files, including recorded Cleaned or
Deleted actions. Check for AdminController.php and timestamps. Hostinger's
automatic Monarx scanner can clean code it classifies as malicious; false
positive is one plausible explanation for identical recurring changes.
However this cannot be attributed to Monarx without its action history.
If flagged, provide Hostinger the reviewed Git SHA and expected/actual hashes
and request a false-positive investigation, not disabling all protections.

For true prevention, deploy under a distinct identity from the web PHP and
worker runtime, with read-only source files for the runtime and state/logs
stored outside the Git source tree. chmod 444 alone is not sufficient on
shared hosting where the account also controls writable parent directories.
If isolation and filesystem write attribution are unavailable at Hostinger,
migrate to a hardened isolated VPS with separate deploy/runtime users.
Preserve DBs, payment ledger and pending outbox during migration.
