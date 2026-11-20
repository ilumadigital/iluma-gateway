# Rejected HPP repair — iRadios / ILUMA Gateway

This feature closes only an original payment whose Nexi HPP creation was
documentedly rejected. It never unlocks an arbitrary unknown, paid, active,
verification-pending, or past-due mandate.

## Boundaries

- The Gateway runs one shared production PHP runtime. Select the tenant
  explicitly via the CLI argument staging or production; APP_ENV is never
  the tenant selector. Nexi Sandbox and Nexi Live stay isolated.
- Scope to the iradiosgr registry application, matching customer, original
  product, currency and account-specific idempotency key.
- Mandatory: payment unknown and older than two minutes, HPP HTTP 400 log,
  no hosted page/token/operation/confirmation, and no payment attempts,
  webhooks, refunds, outgoing events, active agreement or renewal cycles.
- Mandatory: fresh Nexi GET of the exact original order returns HTTP 404.
  Network errors, 200/401/403/5xx, mismatches or ambiguous states do not
  release any locks. No Nexi POST, renewal, HPP creation or refund is sent.
- Financial history is retained. Only the verified payment becomes failed;
  the associated pending mandate is cancelled and marked deactivated.
- A production-tenant payment historically routed to staging is held for
  explicit manual review. Production auto-cleanup never touches it.
- No database migrations are required.

## Commands (after approved Gateway shared-VPS deployment)

Run inside the existing PHP container; the Gateway VPS does NOT have a
Compose file at /srv/iluma-gateway/app. These commands are examples
and the APPLY commands require an additional production write approval.

Production default READ-ONLY / Nexi GET only:

    sudo docker exec -i iluma_gateway-php-1 php bin/reconcile-rejected-hpp.php production --limit=50

Focused production DRY RUN:

    sudo docker exec -i iluma_gateway-php-1 php bin/reconcile-rejected-hpp.php production --account-suffix=5520cba5

Staging-tenant dry run:

    sudo docker exec -i iluma_gateway-php-1 php bin/reconcile-rejected-hpp.php staging --limit=50

Sandbox staging apply (after approved sandbox UAT):

    sudo docker exec -i iluma_gateway-php-1 php bin/reconcile-rejected-hpp.php staging --account-suffix=XXXXXXXX --apply --confirm-staging

Default-OFF production write gate, only after separate explicit authorization
and backup of the production tenant:

    IRADIOS_PRODUCTION_REJECTED_HPP_REPAIR_ENABLED=1

Single-target production apply, only AFTER production write approval:

    sudo docker exec -i iluma_gateway-php-1 php bin/reconcile-rejected-hpp.php production --account-suffix=5520cba5 --apply --confirm-live

For confirmed historical stage-routed production records, separately review
and add --include-legacy-stage-routed. This never selects the staging DB.

Automatic production repair requires another explicit OFF-by-default gate:

    IRADIOS_PRODUCTION_REJECTED_HPP_REPAIR_ENABLED=1
    IRADIOS_PRODUCTION_REJECTED_HPP_AUTO_ENABLED=1

Only direct-play production records are eligible for automatic reconciliation.
The worker calls the existing provider GET, never a new checkout.

## Rollout

1. Offline PHP 8.3/8.4 CI and disposable production-schema MySQL tests.
2. Manual staging-tenant + Nexi Sandbox UAT.
3. Production DRY RUN of account suffix 5520cba5; inspect routing and
   all financial evidence. No mutation.
4. Separate explicit approval for merging this Gateway PR and deploying the
   SHARED VPS runtime; no silent restart of shared containers.
5. Separate explicit approval for the production write gate and one
   targeted APPLY. Verify recurring status and financial audit afterward.
6. Independent approval for scheduled production auto cleanup.

The shared Gateway deploy via sudo ./iluma-g-dev.sh rebuilds common
containers. A Git push does not deploy anything and this is not a staging
only action. The iRadios MongoDB billingRepairProduction.js is a separate
repair scope and cannot release a Gateway MySQL recurring mandate.
