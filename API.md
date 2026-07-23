# Tap Payment — public API

Everything on this page is marked `@api` in the code and is stable within a
major version. Anything marked `@internal` may change without notice; do not
depend on it.

**Version 1.0.0 establishes this initial public API contract.** From 1.0.0
onward, no `@api` class, interface, service ID, plugin ID, event name or
documented configuration key is removed or changed incompatibly except in a new
major version, as described in [UPGRADING.md](UPGRADING.md).

## The service

Inject `Drupal\tap_payment\TapPaymentInterface` (service id
`tap_payment.payment`).

### `createPayment(PaymentRequest $request, string $gatewayId = 'tap'): PaymentSession`

Creates a Tap charge, records it, and returns where to send the payer. Safe to
call twice with the same idempotency key — the second call returns the first
payment. Throws `InvalidPaymentRequestException` (including for a return URL that
points off this site), `ConfigurationException`, or `ApiException`.

### `verifyPayment(TapTransactionInterface $transaction): TapTransactionInterface`

Re-reads the charge from Tap and updates the ledger. This is the call that turns
"the payer came back" into a fact; it contacts Tap every time. Throws
`ApiException` when Tap cannot be reached.

### `loadByChargeId`, `loadByIdempotencyKey`, `loadByContext`

Find transactions by Tap charge id, by idempotency key, or by the
`(contextModule, contextId)` an integration recorded.

## Value objects

All are `final`, immutable, and validate on construction.

| Class | Purpose |
|-------|---------|
| `Dto\Money` | An amount (a decimal **string**, never a float) and an ISO 4217 currency. |
| `Dto\Customer` | The payer, reduced to Tap's required `first_name` and `email` plus optional fields. |
| `Dto\PaymentRequest` | Everything a caller decides: money, customer, return/cancel URLs, source, description, idempotency key, references, metadata, language. |
| `Dto\Payment` | A Tap charge reduced to what the module acts on. Carries no card, token or PII. |
| `Dto\PaymentSession` | What `createPayment` returns: the ledger row and Tap's answer. Call `redirectUrl()`. |

`PaymentRequest::SOURCE_ALL` and `SOURCE_CARD` are the documented hosted-page
sources; any other Tap `source.id` string is accepted too.

## Enums

- `Enum\PaymentState` — every documented charge status. `isSuccessful()` is true
  only for `Captured`; `isFinal()` is false for `Initiated`, `InProgress` and
  `Unknown`; `fromStatus()` returns `null` for anything undocumented rather than
  guessing.
- `Enum\Environment` — `Sandbox` / `Production`, and the key prefix each issues.

## Events

Dispatch names are on `Event\TapPaymentEvents`. Subscribe to react without
modifying this module.

| Constant | Event class | When |
|----------|-------------|------|
| `PAYMENT_CREATED` | `PaymentCreatedEvent` | A charge exists; the payer is about to be redirected. Not paid yet. |
| `PAYMENT_CAPTURED` | `PaymentCapturedEvent` | The money was taken. The only success signal; fires once. |
| `PAYMENT_FAILED` | `PaymentFailedEvent` | Failed, declined, restricted or timed out. |
| `PAYMENT_CANCELLED` | `PaymentCancelledEvent` | Cancelled, abandoned or voided. |
| `WEBHOOK_RECEIVED` | `WebhookReceivedEvent` | A webhook arrived — unauthenticated. Monitoring only. |
| `WEBHOOK_VERIFIED` | `WebhookVerifiedEvent` | A webhook's signature matched. |

A payment lifecycle subscriber runs inside the request that discovered the
outcome — usually Tap's webhook call, which Tap retries only twice. Do slow work
on a queue, not in a subscriber.

## Extension points

- **Payment gateway plugin** — implement
  `Plugin\PaymentGateway\PaymentGatewayInterface` (extend `PaymentGatewayBase`)
  and mark the class with the `#[PaymentGateway]` attribute to add another
  provider. Every layer above the plugin is provider-agnostic.
- **API version adapter** — implement `Api\Adapter\TapApiAdapterInterface` and
  tag the service `tap_payment_api_adapter` to support a new Tap API version.
  Select it with the `tap_payment.api_version` container parameter.
- **HTTP transport** — implement `Api\TapApiClientInterface` to route Tap traffic
  through a proxy or a recorded fixture.

## What is deliberately *not* here

- No `PaymentAuthorized` event. The prompt listed one, but the implemented flow
  is the hosted **Charge**; an `AUTHORIZED` state exists only in Tap's separate
  Authorize API, which this module does not use. Adding a dead event would be
  worse than omitting it.
- No public-key setting. Tap issues a public key for its browser SDKs; no
  endpoint this module calls accepts one.
- No custom webhook signature. Verification is exactly Tap's documented
  `hash_hmac('sha256', …)` over the documented field order — nothing invented.

See the endpoint-to-documentation table in [ARCHITECTURE.md](ARCHITECTURE.md).
