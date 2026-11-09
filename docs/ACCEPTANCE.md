# Acceptance matrix — testing facts only

| Requirement | Current evidence | Release gate |
|---|---|---|
| PHP syntax | `php -l` executed on source | CI lint on deployment image |
| crypto helpers | `php tests/run.php` isolated tests | Independent security review |
| stage/prod isolation | 3 separate DB schemas and explicit credential environment | Two DB roles + automated cross-environment negative test |
| amount check | server-side product catalog cents | Stage checkout with valid and forged prices |
| HMAC & replay | header verifier + nonce unique index | Race/tamper/replay tests against real MySQL |
| Nexi sandbox checkout | adapter implementation | Actual test HPP transaction + 3DS scenario |
| Nexi callback trust | securityToken and server-side lookup | Real notification + duplicate/reordered events |
| no double charge | unique local idempotency + single initial provider call | Network interruption / concurrent retry test |
| outbox delivery | DB outbox and worker | Stop iRadios recipient and verify eventual replay |
| recurring | no live implementation | Consent, MIT contract creation, renewal, 4 retries, unknown state tests |
| refunds | schema only | Authorized test refund + net finance recon |
| Apple Pay/Google Pay | pending merchant verification | Wallet and recurring capability validation |
| admin security | Argon2id + TOTP + sessions + CSRF | Real Zero Trust and external pen test |
| production live | disabled | Formal release sign-off plus independent penetration test |

No connected production systems, real cards, or real Nexi requests were used in preparing this artifact. Passing PHP unit tests is not evidence of successful provider or production integration.
