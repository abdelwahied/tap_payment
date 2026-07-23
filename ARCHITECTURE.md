# Architecture

## Layers

```
                 TapPaymentInterface  (public @api)
                          │
                 TapPaymentService  ── ledger (TapTransaction entity)
                          │            state machine
                          │            events
              PaymentGatewayManager
                          │
                     TapGateway  (the #[PaymentGateway] plugin)
                    ╱     │      ╲
   TapApiClientInterface  │       WebhookProcessor (hashstring verification)
      (HTTP transport)    │
                   TapApiAdapterInterface  ← AdapterRegistry ← tap_payment.api_version
                          │
                     TapV2Adapter  (knows Tap's v2 field names)
```

Each arrow is a seam a substitute can be dropped into. The tests replace
`TapApiClientInterface` with a scriptable stub and leave everything else real;
that is the same seam a proxy or a recorded-cassette client would use.

**No layer above the adapter names a Tap field.** Paths, JSON keys and the
webhook signature's field order all live in `TapV2Adapter`. Supporting a future
Tap API version is a new adapter class plus a change to the
`tap_payment.api_version` parameter — the service, the events, the entity and
every integration keep working.

## The two flows

**Creating a payment** (caller → Tap): validate the request and the return URL →
find or insert the ledger row (unique idempotency key) → build the charge
through the adapter → POST through the client → map the response → record it →
dispatch `PAYMENT_CREATED` → return the hosted URL.

**Confirming a payment** (Tap → site): the webhook lands on `/tap-payment/webhook`
→ decode → dispatch `WEBHOOK_RECEIVED` → verify the `hashstring` against the
account secret → dispatch `WEBHOOK_VERIFIED` → bound the timestamp → find the
ledger row → take a per-charge lock → check the outcome belongs to this
transaction (charge id and amount) → ask the state machine whether the move is
legal → apply it → dispatch the lifecycle event. The customer's browser return
runs the same `applyOutcome`, so whichever arrives first wins.

## Why these choices

- **String amounts everywhere.** Tap's webhook signature is computed over the
  amount rendered to the currency's own decimals (`3.000` for KWD, `2.00` for
  SAR). A float that drifts by one unit in the last place rejects a real
  webhook, so the amount is a string in the DTO, in the entity column, and in
  the signature pre-image.
- **`UNKNOWN` is not final.** Tap can report it as an outcome, but the word
  describes the registry's certainty, not the payment's fate. It stays open so
  the reconciliation queue can resolve it later, instead of being written off as
  a failure.
- **Idempotency lives in the schema, not in a query, and in two layers.** A
  "does this exist yet" check before insert is a race two concurrent checkouts
  can both win; a unique index cannot be raced. `idempotency_key` is unique to
  guard this side, and `charge_id` is unique to guard Tap's — so even a
  mis-generated key cannot let one Tap charge be recorded on two rows. The
  `charge_id` column is nullable until Tap issues a charge, and every supported
  database treats NULLs as distinct in a unique index, so any number of
  not-yet-created rows coexist. On a collision the service defers to the row
  that already holds the charge rather than raising.
- **Replay defence is the state machine, not a nonce table.** A captured payment
  cannot be captured twice, so replaying a genuine webhook changes nothing. The
  timestamp window is wide on purpose — an asynchronous method such as Fawry
  confirms days later, and a tight window would reject real money.

## Endpoint-to-documentation map

Every capability is derived from a specific section of Tap's official
documentation. Nothing is inferred from a community example.

| Capability | Tap documentation |
|------------|-------------------|
| Base URL, `Authorization: Bearer sk_…`, test vs live by key prefix | [Get started](https://developers.tap.company/docs/get-started) |
| `POST /v2/charges` — required `amount, currency, customer, source, redirect` | [Create a Charge](https://developers.tap.company/reference/create-a-charge) |
| `GET /v2/charges/{id}` and the return-trip `tap_id` re-query | [Retrieve a Charge](https://developers.tap.company/reference/retrieve-a-charges), [Redirect](https://developers.tap.company/docs/redirect) |
| Hosted page via `src_all` / `src_card`; `transaction.url`; redirect flow | [Charges](https://developers.tap.company/reference/charges), [Payment methods](https://developers.tap.company/docs/payment-methods) |
| Charge states (`INITIATED … CAPTURED … VOID … UNKNOWN`) and response codes | [Charges](https://developers.tap.company/reference/charges), [Response codes](https://developers.tap.company/reference/charge-response-codes) |
| Webhook via `post.url`; three delivery attempts | [Webhook](https://developers.tap.company/docs/webhook) |
| `hashstring` = `hash_hmac('sha256', 'x_id…x_amount…x_currency…x_gateway_reference…x_payment_reference…x_status…x_created…', secret)`, amount at currency precision | [Webhook — validate the hashstring](https://developers.tap.company/docs/webhook) |
| Idempotency via `reference.idempotent`, valid 24h | [Idempotency](https://developers.tap.company/docs/idempotency) |
| Hosted-page language via `lang_code` header | [Create a Charge](https://developers.tap.company/reference/create-a-charge) |

## Reported gaps and deviations

1. **`PaymentAuthorized` event omitted.** The requested flow is the hosted
   Charge; `AUTHORIZED` belongs to Tap's Authorize API, which is out of scope.
   The six events that actually fire are documented instead of shipping a dead
   one.
2. **No API-version negotiation exposed.** Tap does not publish one — the version
   is in the URL path and echoed as `api_version: "V2"`. The adapter layer
   covers future versions; no version setting is added to the UI.
3. **Public key omitted.** Documented, but only for the browser SDKs; unused by
   every endpoint this module calls.
