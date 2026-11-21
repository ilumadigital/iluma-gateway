# iRadios Gateway tenant return routing / direct-play cutover

## Invariant (user browser, not financial webhook)

Nexi HPP sends all result/cancel browser traffic to Gateway's
/checkout/result and /checkout/cancel with an opaque order ID. Gateway finds
the originating order in the corresponding tenant database and issues 303.

- Gateway staging tenant + Nexi Sandbox -> stage.iradios.gr
- Gateway production tenant + Nexi Live -> play.iradios.gr

This applies to success/result, cancel and "return to merchant", including
legacy production orders with iradios_stage_routed=1. Routes remain product-
specific: Listener -> /premium, Broadcaster/ADD -> /backoffice/pro-features,
Boost -> /backoffice/boost, Advertising -> /backoffice/agency/campaigns.

Legacy stage-routed production *financial outbox/webhook delivery* is
unchanged and independently controlled by IRADIOS_LEGACY_STAGE_TO_PLAY_ENABLED.
That flag must not be turned on merely to fix the browser redirect. Changing
historic webhook destination requires separate compatibility verification:
old signed production payments might already have been consumed by stage.

## Gateway private environment: direct-play checkout cutover

Edit the protected, file-mounted Gateway config (not iRadios .env):

    sudoedit /etc/iluma-gateway/iluma-gateway.env

Ensure these values each occur exactly once:

    APP_ENV=production
    PRODUCTION_ENABLED=1
    IRADIOS_PRODUCTION_PLAY_ENABLED=1
    IRADIOS_PRODUCTION_WEBHOOK=https://play.iradios.gr/api/gateway/webhook
    IRADIOS_STAGING_WEBHOOK=https://stage.iradios.gr/api/gateway/webhook
    IRADIOS_PLAY_MIT_EXECUTION_APPROVED=0
    IRADIOS_LEGACY_STAGE_TO_PLAY_ENABLED=0

If the legacy production-to-stage checkout gate remains enabled in the
private config, disable it separately only after direct-play readiness is
confirmed:

    IRADIOS_STAGE_PRODUCTION_ENABLED=0

Do not alter STAGING_DSN, PRODUCTION_DSN, HMAC signing keys, Nexi Sandbox
or Live credential records, global APP_ENV, or Nexi endpoints. The
production MIT execution flags are separate from checkout and must not be
enabled to fix browser redirects. The existing HPP rejection repair flags
are separate and need no changes.

Required Nexi base URLs, unchanged:

    NEXI_STAGING_BASE=https://xpaysandbox.nexigroup.com/api/phoenix-0.0/psp
    NEXI_PRODUCTION_BASE=https://xpay.nexigroup.com/api/phoenix-0.0/psp

## Safety / deployment

Shared Gateway runtime is ALWAYS built from Git main at
/srv/iluma-gateway/app. A push or merge does not deploy. Its deploy script
REBUILDS COMMON containers affecting both staging and production.

After separate explicit operator approval to deploy the shared Gateway
containers, and with backup / deployment window:

    cd /srv/iluma-gateway/app
    sudo ./iluma-g-dev.sh

First validate the mounted private environment (offline/read-only):

    sudo docker exec -i iluma_gateway-php-1 php bin/iradios-return-doctor.php

Expected: every check PASS; GATEWAY_RETURN_PREFLIGHT: PASS;
FINANCIAL_WRITES: 0; NEXI_REQUESTS: 0.

Validate application readiness without creating a checkout:

- iRadios play backend must be configured with the HMAC production tenant
  credentials, not staging credentials.
- Gateway production catalog, schema and production webhook destination must
  be validated via the signed /api/v1/billing/readiness preflight.
- Run a staging Sandbox cancellation/return flow to stage.iradios.gr.
- For live flow, test CANCEL / return-to-merchant with a newly-created HPP
  and verify play.iradios.gr; confirm provider has no capture.
- For paid production transactions, do not initiate an unapproved charge.
  Complete separate UAT and authorization before making a real purchase.
- Check pending signed outgoing events and the worker status following the
  cutover. Do not reroute legacy stage-routed financial webhooks without an
  independently approved migration.

Read-only live database routing audit (no customer records printed):

    sudo docker exec -i iluma_gateway-php-1 php bin/iradios-return-doctor.php

No migrations, production DB writes, or Nexi requests are made by this doctor.
Changes to the protected environment apply to newly-started containers/CLI
processes; do not assume an already-running process has reloaded the file.

## Code/CI tests

Run php tests/run.php or GitHub PHP checks CI. The return matrix verifies
both tenant origins, both result/cancel routes, all relevant SKUs, both
stage-routing flags and both legacy webhook forwarding flags. Existing
financial webhook selection remains untouched.
