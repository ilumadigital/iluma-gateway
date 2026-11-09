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
MASTER_KEY_B64 and the fixed webhook URLs. Keep APP_ENV=development,
PRODUCTION_ENABLED=0 and ADMIN_ACCESS_VERIFIED=0 until hardening is complete.

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
All must return 404, never source content. Check encoded paths,
backup extensions, direct PHP files and alternate hosts too. If any
file can be downloaded, disable public access and correct web routing.
Hostinger uses Apache/LiteSpeed-compatible .htaccess on Web/Cloud
plans; Nginx deployments must use their own server-level rules.

## CLI checks, administrator and MFA

From a private SSH session in the deployed project folder, run:
    php bin/manage.php doctor
    php tests/run.php

The doctor checks 6 Registry tables, 15 Staging tables, 15 Production
tables, the master key format, pinned Nexi endpoints and the production
lock. It does not reveal secrets or call Nexi.

The admin hostname admin.gateway.iluma.gr must map to the same protected
document root and be behind an actual VPN or Zero Trust access policy.
Changing ADMIN_ACCESS_VERIFIED=1 in the private file is only an
acknowledgement that this external access control has been independently
verified; it does not create access protection on its own.

After verifying the external gate, create the first administrator via
the SSH terminal (replace the email address):
    read -rsp 'Admin password (at least 16 chars): ' ADMIN_INITIAL_PASSWORD; echo
    export ADMIN_INITIAL_PASSWORD
    php bin/manage.php create-admin admin@example.com
    unset ADMIN_INITIAL_PASSWORD

The CLI prints a one-time Base32 TOTP secret. Enter it manually into
your authenticator and never copy it to public logs or Git. Log in only
at the protected admin hostname with password and the MFA code.

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
