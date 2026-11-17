# Commercial iRadios at stage.iradios.gr

The legacy play.iradios.gr Stripe application is a different system and is
not updated by this project. The new iRadios may use Nexi Production via
Gateway while APP_ENV remains staging in its isolated VPS.

## Mandatory Gateway release order (authoritative 2026-11-17 VPS)

1. Review and merge the single unified Gateway PR after passing combined CI.
   Do not separately merge the conflicting production-worker changes in #28/#30.
2. Deploy to the confirmed VPS origin at 185.230.64.89 with the reviewed
   SHA, after checking the host reverse proxy. Do not touch legacy play.iradios.gr.
3. Run production billing + tenant parity audits READ-ONLY while checkout remains
   locked. The runtime production SQL account already has DML without DDL.
4. Verify complete encrypted off-host backups AND isolated restore of the current
   three-database snapshot before any SQL write.
5. Select ONE reviewed migration according to actual drift; for the audited
   state, prefer tenant alignment, not two overlapping bootstrap tools.
   No existing payment/customer/mandate/card rows may be copied or deleted.
6. Verify exact scoped iRadios catalog, HMAC credential, merchant/terminal and
   Nexi production endpoint. Only after these prerequisites, configure private
   Gateway production flags with all MIT execution/UAT flags still 0.
7. Deploy iRadios #153 to stage.iradios.gr with APP_ENV=staging and Mailpit
   unchanged; require signed authenticated READY.
8. Configure the independent production webhook/reconciliation worker as
   `php bin/work.php production --no-charge`; verify exactly one production
   timer. Keep the existing staging worker.
9. Real payment UAT requires one explicit operator approval per charge and
   Nexi/Gateway/webhook/iRadios entitlement correlation. MIT requires separate
   consent, due-cycle checks and an independent UAT authorization.

Canonical runbook: `docs/PRODUCTION_BILLING_RECOVERY.md`.

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
