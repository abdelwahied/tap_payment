# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/), and the module aims for
[Semantic Versioning](https://semver.org/).

## [Unreleased]

Nothing yet.

## 1.0.0

Initial release.

### Added

- A payment gateway plugin API any Drupal module can drive, independent of
  Drupal Commerce: the `#[PaymentGateway]` attribute, `PaymentGatewayInterface`,
  `PaymentGatewayBase` and `PaymentGatewayManager`.
- `TapPaymentInterface` (`tap_payment.payment`): create, verify and look up
  payments through one service.
- Tap Payments hosted checkout over the documented `POST /v2/charges` and
  `GET /v2/charges/{id}`, with webhook confirmation verified by the documented
  `hashstring` HMAC-SHA256 signature.
- An API-version adapter layer (`TapApiAdapterInterface`, `TapV2Adapter`,
  `AdapterRegistry`) so a future Tap API version needs no change above it.
- A `tap_payment_transaction` content-entity ledger with a database-unique
  idempotency key, storing no card data or payer PII beyond Tap's customer id.
- A one-way `PaymentStateMachine` making repeat and out-of-order webhook and
  return delivery idempotent.
- Six lifecycle events for other modules to subscribe to.
- Reconciliation via cron and a queue worker for payments Tap's webhook never
  reported.
- A three-field settings form (environment, sandbox key, live key) with
  write-only secret storage, and a runtime status report.
- A log sanitizer that strips keys, tokens, cards and emails from every log
  message and context value.
- Submodules: `tap_payment_custom` (standalone form), `tap_payment_commerce`
  (Commerce off-site gateway), `tap_payment_webform` (Webform handler).
