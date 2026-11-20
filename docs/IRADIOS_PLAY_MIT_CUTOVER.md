# iRadios direct-play recurring MIT (Gateway production)

The feature branch prepares **new** production iRadios checkouts for
`play.iradios.gr`; it does not charge, deploy, merge, alter DNS or migrate data.

## Gateway private ENV — exact semantics

- `IRADIOS_PRODUCTION_PLAY_ENABLED=1`: new real Nexi checkouts route
  directly to play, if the Gateway production route preflight passes.
- `IRADIOS_PLAY_MIT_EXECUTION_APPROVED=0`: independent default-off gate for
  direct-play **automatic** MIT charges. Before setting to 1, provider MIT UAT
  must be approved and both Listener and Broadcaster billing paths tested.
- `MIT_PRODUCTION_EXECUTION_ENABLED` and `MIT_PRODUCTION_UAT_APPROVED` still
  gate Listener renewal. Corresponding BROADCASTER MIT flags still independently
  gate Broadcaster renewal. Existing production stage-routing approval alone
  cannot authorize a new play MIT charge.
- The worker `--no-charge` mode remains mandatory for safe deployment smoke
  tests; do not activate a charging worker before approval.

## Provenance

Every **new** production iRadios checkout pins
`iradios_stage_routed` (1=legacy stage; 0=direct play) and
`origin_credential_id`. The signed delivery outbox prefers that original
credential for direct play too. Keep old keys usable until their pending
events are migrated or acknowledged; do not rotate them out mid-flight.

Due-charge selectors accept **only** the initial payment route selected by
the Gateway current play switch. When play activates, legacy stage-routed
agreements are excluded from the direct-play billing worker until an
explicitly reviewed migration. Provider deactivation of canceled mandates
continues for both origins.

## Mandatory production cutover checks

1. Verify Nexi merchant recurring MIT capability; HPP success does not prove it.
2. Reconcile old Nexi production payments already routed to stage, including
   the externally completed 3.99 EUR refund missing from the Gateway refund
   ledger. Never synthesize a Nexi REFUND or erase the paid payment.
3. Confirm listener/broadcaster account and station entitlements match the
   original signed payment identities; historical stage copies grant no
   production rights by default.
4. Rotate previously disclosed credentials with an explicit old-webhook-key
   retention plan; validate real SMTP, SSL and DNS before public activation.
5. Merge and manually deploy reviewed code separately; Git pushes do not
   change the running VPS or Hostinger production services.
