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


## Subscription ownership: iRadios controls billing, Gateway executes payments

iRadios owns plan terms, prices, subscription lifecycle, next-billing dates, renewal decisions, service access and business statistics. ILUMA Gateway owns signed payment execution, Nexi communications, idempotency, payment confirmation, a financial ledger, and signed notifications. Nexi is the PSP and stores sensitive card data. iRadios and ILUMA must not store raw PAN or CVV; metadata (brand, masked last 4, expiry when supplied) is permitted with appropriate controls.

For **monthly off-session charging**, Nexi Greece requires a provider-side credential/token reference (called a recurrence contractId) at first consented payment and a subsequent `POST /orders/mit` call. The contractId is **not** a Nexi business subscription or billing scheduler. iRadios remains the owner of renewal dates. This payment-method vaulting/MIT capability is not yet implemented or merchant-validated in ILUMA; there is **no production-ready automatic renewal endpoint**. If the customer authenticates and pays each month, ordinary Hosted Payment Pages do not need a saved-card contract, but that is not automatic renewal.

### CLI-only first-month Staging checkout, Listener Premium

The current iRadios plan is L-PREM at EUR 3.99 including VAT. To test the first month independently of the unimplemented iRadios backend, after deploying this change run:

    /opt/alt/php84/usr/bin/php bin/manage.php sandbox-subscription staging listener-002

This registers the Staging plan only if missing, creates the local staging customer, subscription row and first-cycle invoice/payment, then launches an ordinary Nexi Sandbox HPP **without any recurrence fields or Nexi contract creation**. Only a fully confirmed captured payment marks the first-cycle ledger and local subscription mirror active. `auto_renew` remains 0 and no future charges are scheduled. Reusing the same test reference always returns the existing result and cannot generate another order.

The previous `listener-001` Staging intent returned HTTP 400 during the provider-side contract creation experiment, followed by a read-only GET order 404. It remains an unknown historical local intent and **must not be retried or rewritten with a new order**. The new test reference represents a **separate** first-month checkout after the provider request shape was changed. This test proves only the first month, not automatic MIT renewal.

After payment, reconcile its actual `order_id` using read-only Nexi GET /orders and the Gateway's `Payments::reconcile('staging',orderId)`. Then check the local mirror:

    /opt/alt/php84/usr/bin/php bin/manage.php sandbox-subscription-status staging listener-002

It reads only the staging database, never calls Nexi again, and shows `payment_status`, `subscription_state`, `period_end_at`, and disabled `auto_renew`.

Until the iRadios backend is implemented, the Gateway's outgoing `payment.paid` webhook can remain pending/401; do not enable customer benefits or presume that iRadios has received it. The Gateway mirror is not the iRadios source of truth. Do not enable live payments or claim automatic renewals pass.
