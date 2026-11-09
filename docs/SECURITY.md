# Security and release gates

## What is already implemented

- Separate staging/production tenant databases and credentials (plus registry DB)
- Production fail-closed with explicit configuration gate, and pinned official Nexi base URLs
- HMAC-SHA256 authenticated backend requests with timestamp, nonce and durable replay unique constraint
- Application-bound, environment-bound access; server-authoritative product amounts
- No PAN/CVV storage or merchant-side card form; provider-hosted card entry
- Secrets encrypted using libsodium secretbox; random 256-bit application secret generated with `random_bytes`
- Admin Argon2id password hashing, step-up TOTP for sensitive actions and CSV exports, CSRF, idle expiration and account lockout
- Test connection uses read-only `GET /payment_methods`
- Raw Nexi notification never considered proof of capture; verified provider order lookup required
- Durable payment ledger and transactional outgoing-event outbox; no automatic payment retry on transport uncertainty
- Payment log timeline generated from recorded events only
- Credentials are masked after saving; no secrets in audit logs

## Before any internet-facing deployment

1. Run as isolated unprivileged FPM users; deploy source outside webroot; no public database ports. Enforce TLS 1.2+, HSTS and secure ciphers.
2. Admin now uses `https://gateway.iluma.gr/ilm-admin` with password-only login and Google Authenticator confirmation for Nexi credentials, application API key changes and CSV exports; account lockout and rate limiting remain. Restrict it behind an additional **VPN or Zero Trust policy** for production, especially for payment administration. The optional edge policy is not provided by this code.
3. Add rate-limit zones in Nginx/edge and intrusion detection for API, webhook, and admin login; restrict provider notification origin where supported, but do not depend on source IP alone.
4. Put secrets in KMS/secrets manager or host process environment, not in Git or public disk. Restrict CLI printing and logs. Establish master-key and credential rotation/recovery procedures.
5. Implement admin role separation, two-person approval for refunds, contract deactivation, production credential changes and production API-key disable; current minimal admin supports only one administrator role.
6. Add explicit key versioning and overlap for rotation, per-environment outbound webhook signing keys, and TOTP replay-window prevention.
7. Add database encryption at rest, encrypted backups, retention controls, restore drills, GDPR privacy/erasure policies compatible with immutable transaction records.
8. Confirm provider callback authentication and order response mapping against **merchant-specific** sandbox fixtures; current mapper deliberately rejects uncertain statuses.
9. Build negative/security/integration tests for HMAC/body tampering, race conditions, duplicate concurrent requests, expiry, callbacks, worker crash, backpressure, refund arithmetic, and unknown payment states.
10. Conduct independent penetration test and PCI DSS scope assessment. Hosted card fields reduce PCI exposure but do not automatically eliminate compliance obligations.
11. Enable CSP/iframe integration only after review; current HPP redirects to the official Nexi page.
12. Obtain explicit ILUMA release approval after real sandbox UAT; only then consider setting both `APP_ENV=production` and `PRODUCTION_ENABLED=1` in secured production runtime.

## Hard exclusions

No successful test or live charge has been executed in this environment. No Nexi API key or merchant subscription capability was supplied. Live charging, refunds and recurring billing must not be considered ready.
