# iRadiosGR → ILUMA Gateway API (backend only)

**Προσοχή:** Οι παρακάτω κλήσεις υλοποιούνται από το **iRadios backend**. Δεν επιτρέπεται έκθεση API Key / Secret σε HTML, browser, mobile bundle ή δημόσιο JavaScript.

## Environment mapping

| Source application | Gateway credentials | Nexi | Database |
|---|---|---|---|
| https://stage.iradios.gr | staging key + secret | XPay Sandbox | iluma_staging |
| https://play.iradios.gr | production key + secret | XPay Live | iluma_production |

Το environment δεν υπάρχει στο request body. Καθορίζεται αποκλειστικά από το καταχωρημένο API key. Η εναλλαγή περιβάλλοντος στο backoffice αφορά μόνο την προβολή.

## Signature protocol version 1

Για κάθε server-to-server αίτημα:

- `X-Iluma-Key`: `ilg_` + 48 hex characters (generated once)
- `X-Iluma-Timestamp`: Unix epoch seconds, max ±300 seconds clock skew
- `X-Iluma-Nonce`: 16 cryptographically random bytes, lowercase hex (32 chars), never reused for that credential
- `X-Iluma-Signature`: lowercase hex HMAC-SHA256 of the exact canonical bytes
- `Idempotency-Key`: required on checkout; unique per purchase intent and stable across retry

Canonical string (LF `\n`, no trailing LF):

```text
UPPERCASE_HTTP_METHOD
RAW_URL_PATH_WITHOUT_QUERY
UNIX_TIMESTAMP
NONCE_HEX
SHA256_HEX_OF_RAW_HTTP_BODY
```

The signed request body must be sent byte-for-byte unchanged; do not re-serialize it after signing. For GET requests, the body is empty. Prefer no query parameters. The timestamp, nonce and key are strictly checked; reuse of nonce is rejected by a unique database constraint.

### PHP signing example (server-side only)

```php
<?php
$body = json_encode([
  'customer_id'=>'iradios-user-42','product_sku'=>'premium-monthly',
  'name'=>'Sample User','email'=>'example@example.org',
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
$path='/api/v1/payments/checkout';
$timestamp=(string)time();$nonce=bin2hex(random_bytes(16));
$canonical="POST\n".$path."\n".$timestamp."\n".$nonce."\n".hash('sha256',$body);
$signature=hash_hmac('sha256',$canonical,$gatewaySecret);
// HTTPS POST https://gateway.iluma.gr/api/v1/payments/checkout
// Headers: X-Iluma-Key, X-Iluma-Timestamp, X-Iluma-Nonce,
//          X-Iluma-Signature, Idempotency-Key, Content-Type: application/json
```

`$gatewaySecret` is the **raw hex string** printed at credential creation. Do not decode it into binary before HMAC, unless the protocol is intentionally versioned and changed on both sides.

## Checkout

```http
POST /api/v1/payments/checkout
Content-Type: application/json
Idempotency-Key: iRADIOS-purchase-unique-2026-0001
```

```json
{"customer_id":"iradios-user-42","product_sku":"premium-monthly","email":"example@example.org","name":"Sample User"}
```

Price/currency are loaded from the gateway's product catalog. User-supplied amount cannot override catalog price. Products must be pre-registered by an administrator (`bin/manage.php product ...`); the payment gateway must not trust the amount from the browser.

Expected response for successfully initialized sandbox Hosted Payment Page:

```json
{"payment_id":"...","order_id":"...","status":"redirect","amount_cents":499,"currency":"EUR","checkout_url":"https://...nexigroup.com/..."}
```

An `unknown` status means a provider request may or may not have succeeded. **Do not retry with a new idempotency key** for the same intent. Poll read-only status instead.

## Status

`GET /api/v1/payments/{payment_id}` returns the stored state (`pending`, `redirect`, `unknown`, `paid`, `failed`, `canceled`). For production enablement, validate all statuses and provider operation type/capture behavior with the actual merchant contract.

## Outgoing events → iRadios

The worker POSTs a JSON message to a fixed, environment-specific backend URL. The payload contains `event_id`, `event_type`, `payment_id`, `order_id`, `external_user_id`, `product_sku`, `amount_cents`, `currency`, `environment`, and `occurred_at`. The worker adds `X-Iluma-Timestamp`, `X-Iluma-Nonce`, `X-Iluma-Signature`, `X-Iluma-Event-Id`. The signature canonicalization matches inbound signature protocol, except the path is the **iRadios webhook endpoint path**. The same active environment secret is used (separate signing secrets recommended before production).

The iRadios backend must validate signed events, reject replayed nonces, deduplicate by `event_id`, and only unlock Premium after receiving a `payment.paid` event. The gateway does not manage customer access. The iRadios endpoint/verification code is NOT supplied in this repository because modifying that repository was explicitly prohibited by the brief.

## Not yet enabled

`POST /api/v1/subscriptions/renew`, `POST /api/v1/subscriptions/cancel`, `POST /api/v1/subscriptions/sync`, `POST /api/v1/payment-methods/replace` return HTTP 501 until additional provider-contract behavior and integration tests are verified. They are intentionally **fail-closed**, not mock success endpoints.


## CLI-only Staging subscription pilot (L-PREM)

Source: user-supplied iRadios price list. Listener Premium (L-PREM) is EUR 3.99 per calendar month including 24% VAT, intended for recurring renewal.

On the Hostinger Gateway CLI, after Git deployment, use the PHP 8.4 binary:

    /opt/alt/php84/usr/bin/php bin/manage.php sandbox-subscription staging listener-001

This creates only a **Staging** plan at 399 cents (existing prices are never overwritten), a test customer, a pending subscription, first billing cycle and checkout via Nexi Greece Sandbox HPP. The request includes recurrence CONTRACT_CREATION, MIT_SCHEDULED, a unique contractId, contractFrequency 28, and IMPLICIT capture. The 28 days are the provider minimum spacing, not an implemented renewal scheduler. Use official Nexi test cards only. A repeated test reference is idempotent and does not create a second checkout. If the result is unknown, do not create another reference to retry the same purchase.

After completing the hosted test, reconcile its returned order_id using Payments::reconcile('staging', orderId). Only a confirmed full paid capture changes the local subscription to active and the first billing cycle to paid. The period ends one calendar month after confirmation. This does not enable automatic renewal.

Then verify the provider contract and view local status with:

    /opt/alt/php84/usr/bin/php bin/manage.php sandbox-subscription-status staging listener-001

This performs the Nexi read-only GET /contracts/customers/{customerId}, checks the exact expected contract, and marks it locally verified only after a confirmed paid initial cycle. If the provider does not expose the contract, the pilot cannot certify recurrence.

Admin STAGING > Subscriptions displays the test subscription with Auto Renewal = No, deliberately. Subsequent MIT charges, consent UI/storage, cancellation, expiry enforcement, entitlement propagation to iRadios, and production rollout are NOT implemented. The currently unimplemented iRadios webhook receiver may return 401 and is independent from this pilot.
