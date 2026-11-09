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
