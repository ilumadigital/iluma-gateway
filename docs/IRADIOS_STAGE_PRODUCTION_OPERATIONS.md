# Commercial iRadios at stage.iradios.gr

The legacy play.iradios.gr Stripe application is a different system and is
not updated by this project. The new iRadios may use Nexi Production via
Gateway while APP_ENV remains staging in its isolated VPS.

## Mandatory Gateway release order

1. Production merchant approval and QA sign-off; APP_ENV=production and
   PRODUCTION_ENABLED=1 on Gateway, including production worker scheduling.
2. Verified off-host production database backup and restoration drill.
3. Apply database/migrations/20261116_iradios_stage_production_routing.sql
   manually to iluma_production ONLY (not staging).
4. Prepare the production tenant's broadcaster_mandates, broadcaster_cycles,
   broadcaster_boost_scopes, advertising_budget_scopes, recurring_mandates,
   recurring_cycles, saved_card_agreements and winback_claims tables using
   reviewed, adapted migrations. The Gateway NEVER auto-DDLs production.
5. Provision an independent iradiosgr PRODUCTION app credential and audit the
   production product catalog: L-PREM 399 cents, L-WINBACK-199 199 cents,
   all broadcaster and Boost SKUs, ADV-BUDGET sentinel 100 cents EUR.
6. Configure Gateway PRIVATE environment (do not add secrets to Git):
   ```dotenv
   IRADIOS_STAGE_PRODUCTION_ENABLED=1
   IRADIOS_STAGE_PRODUCTION_WEBHOOK=https://stage.iradios.gr/api/gateway/webhook
   ```
   Keep IRADIOS_PRODUCTION_WEBHOOK untouched for unrelated/old payments.
7. Deploy Gateway manually from Git via Hostinger hPanel. Verify production
   Nexi provider and the signed worker/outbox end to end before live charges.

## Transaction safety and reversibility

New iradiosgr production payments save both immutable route marker and originating
API credential ID before their one provider POST. Return and webhook are based
on that row, never browser parameters; unrelated production payments continue
their former destinations. Even disabling new commercial checkout must not
disable delivery of previously paid events. Do not revoke the originating
credential until its pending outbox is empty. A redirect/GET alone NEVER activates
Premium or campaign funds: only signed, correlated payment.paid events do.
Production MIT execution requires separate explicit UAT and execution flags,
not merely a saved card or consent.
