# ILM Admin Control Center — deployment and UAT (2026-11-17)

The feature adds operator-managed applications, API keys, per-tenant products,
app-specific HTTPS routes, environment health and one-euro checkout diagnostics.

## Safety boundaries
- Authenticated operators with CSRF and fresh TOTP for all writes.
- Staging / Production use independent tenant databases and API credentials.
- New apps begin without payment routing and require a configured HTTPS
  webhook URL and return path in each environment before external checkout.
- App routes are stored in the existing environment-scoped settings table;
  no migration for this feature.
- Existing iRadios webhooks and stage-to-production rules remain fixed.
- Hardcoded iRadios Listener/Broadcaster/Boost/advertising SKUs are shown in
  the Products view but are protected from uncoordinated catalog changes.
- App webhooks must be same-origin HTTPS, use public pinned DNS addresses,
  verify TLS and never follow redirects.
- Disabling apps does not delete transaction history. Existing paid amounts
  remain immutable; active contracts do not get silently repriced.
- The internal test app cannot issue external API keys or iRadios entitlements.
  It uses the fixed SKU ILUMA-TEST-100 and returns to Gateway Test Center.

## Before deploying
1. Review the PR and CI results. Obtain reliable production backup and UAT.
2. Merge the approved PR into main.
3. Manually redeploy from Git in Hostinger hPanel for gateway.iluma.gr. A
   GitHub push alone does not deploy the public Gateway.
4. Verify /ilm-admin, prod and stage databases, Nexi provider configuration,
   and existing iRadios checkout, signatures, returns and outbox delivery.
5. Do not change APP_ENV or PRODUCTION_ENABLED to bypass missing schema or
   merchant approval. The admin does not override production release gates.

## ILUMA terminal test procedure
1. Select PRODUCTION and open System Health. Production must include the
   payments route columns from the 2026-11-16 migration and base tables.
2. Open Test Center and choose Prepare test. This writes only an internal app
   and a one-euro product, without Nexi requests or payment attempts.
3. Confirm the one-euro charge and complete the fresh TOTP step-up.
4. Open the authenticated Nexi Hosted Payment Page and pay with a real card.
5. Capture the order ID, verify payment.paid after authoritative Nexi GET,
   and check the Nexi Merchant Portal for the ILUMA merchant / terminal ID.
6. If appropriate under the merchant contract, reverse/refund the amount
   through the established merchant process. Refund execution is not part
   of this new control center.

## Remaining production responsibilities
- Products are queried from the selected tenant DB, not seed/default prices.
- New generic apps must implement signed Gateway event verification at their
  configured webhook URL and expose a compatible return page.
- Confirm Sandbox UAT and signed event delivery before allowing Production.
- Coordinate API key rotation with pending outbox events.
- iRadios locked pricing still needs coordinated Gateway and app integration
  work before it can become dynamically editable without contract risk.
- Worker runtime, merchant terminal identity and versioned SQL migrations
  require separate verified infrastructure/merchant evidence.
