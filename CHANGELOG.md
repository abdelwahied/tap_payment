# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/), and the module aims for
[Semantic Versioning](https://semver.org/).

## [Unreleased]

Nothing yet.

## [1.2.0] — 2026-08-17

### Added

- **Drupal 12 compatibility.** `core_version_requirement` on the module and all
  three submodules, and the Composer `drupal/core` and `drupal/core-dev`
  constraints, now accept `^12` alongside the existing `^10.3 || ^11`.

### Changed

- Status-report severities are resolved through
  `DeprecationHelper::backwardsCompatibleCall()`, so Drupal 11.2 and later
  receive the `RequirementSeverity` enum while Drupal 10.3 keeps the
  `REQUIREMENT_*` constants it still defines. The procedural
  `hook_requirements()` and the object-oriented implementation read the same
  service, so both paths stay identical.

### Notes

- No behavioural or public API change. Validated on Drupal 11.4.4; Drupal 12
  and Drupal 10.3 compatibility is established by static analysis, as no
  runtime for either was available.


## [1.1.0] — 2026-07-26

Hardening for the standalone payment form in `tap_payment_custom`: it is a
public endpoint that spends money and calls a third-party API on request, and it
had no throttling, no duplicate protection and no lock. Plus an expanded
settings page and two payer-facing message fixes.

**Nothing marked `@api` changed.** Every class touched in this release is
`@internal`. Existing configuration, payment links, Webform and Commerce
integrations, API consumers and webhook processing are unaffected — see
*Backward compatibility* below.

### Added

- `tap_payment_custom.throttle` (`PaymentThrottle`) — bounds how often one payer
  may start a payment. Checked against three buckets that must all pass:
  session or account, payer email, and client IP with its allowance multiplied.
- `tap_payment_custom.idempotency_keys` (`IdempotencyKeyFactory`) — derives a
  stable key from the amount, the currency, the payer's details, a coarse time
  bucket and, when one exists, the account or session; plus a generation counter
  so a payer who finished one payment can immediately make another.
- Six configuration keys on `tap_payment_custom.settings`: `flood_limit`,
  `flood_window`, `flood_ip_multiplier`, `throttle_by_session`,
  `throttle_by_email` and `idempotency_lifetime`, with matching schema and
  fields on the standalone form's settings page.
- Four container parameters holding the shipped defaults:
  `tap_payment.pay_flood_limit` (10), `tap_payment.pay_flood_window` (3600),
  `tap_payment.pay_flood_ip_multiplier` (10) and
  `tap_payment.pay_idempotency_lifetime` (900).
- A "Getting started with Tap" help section on the main settings page: where the
  secret key lives, this site's webhook address ready to paste, and why there is
  deliberately no public-key or webhook-secret field.
- `tap_payment_custom_update_10001()`, so a site that installed the module
  before the six settings above existed ends up with the configuration a fresh
  install has, rather than a permanently different `config:export`.

### Changed

Two behavioural differences on an existing site, both confined to the standalone
form:

1. **The form is now throttled.** A site that previously accepted unlimited
   submissions refuses the 11th payment start from one payer within an hour,
   with "Too many payment attempts." Raise `flood_limit` on the settings page if
   your traffic needs it.
2. **A repeat submission rejoins instead of charging again.** Within
   `idempotency_lifetime` (900 seconds by default) an identical submission
   resolves to the same key and is handed the payment it already started. After
   the window, the same payer buying the same thing again gets a new charge.
   Already-issued Tap hosted URLs are never invalidated by this — a stale
   attempt is now *more* likely to be resumed than replaced.

Two new messages appear where one used to: a charge Tap has already captured
("This payment has already been completed"), and a repeat submission on a
payment still under way ("Your payment is already being processed").

Two smaller changes with no integration impact: submissions serialise on a lock
named after the idempotency key (a concurrent duplicate waits up to 30 seconds
and rejoins rather than opening a second charge), and an email longer than the
254 characters Tap documents is refused locally instead of costing a round trip.

### Fixed

- **A double-clicked Pay button opened two charges.** `TapPaymentService` and Tap
  both already honoured an idempotency key, but `PaymentForm` supplied none, so
  the service minted a fresh UUID on every submission and all of it was inert.
  The service itself was not changed.
- **A declined payment told the payer it was being processed.**
  `PaymentSession::redirectUrl()` returns `NULL` both for a payment that has
  already finished and for a brand-new charge Tap refused on the spot — the
  adapter maps `DECLINED` and `FAILED` to states rather than throwing, so a
  refusal arrives as an ordinary answer. The form showed one reassuring status
  message for all of it, sending a payer whose card was declined away to wait
  for a confirmation email that would never arrive. The four outcomes are now
  reported separately.
- **The "payments are not available" state dropped the form's CSS classes.**
  `FormBuilder::retrieveForm()` attaches the form's own classes to the render
  array *before* calling `buildForm()`, so returning a fresh array discarded
  them and any theme or script hooked to the form wrapper stopped matching in
  that state. `buildForm()` now adds to the array it is given on every path.
- A misconfigured amount or currency threw out of `buildForm()` and gave the
  payer an exception page. It is now logged, and the payer is told payments are
  unavailable rather than shown a stack trace.

### Security

- **The public payment form is rate limited.** Every submission costs an
  outbound API call, and nothing bounded them. The limit is deliberately *not*
  IP-only: behind carrier-grade NAT, an office, a school or a university,
  hundreds of legitimate payers share one address, so an IP-tight limit locks
  all of them out. The per-address bucket is therefore the loosest of the three
  and bounds a network rather than a person.
- **No payer address reaches the flood backend.** The email bucket is keyed on a
  hash, so the flood table never stores an address in the clear.
- **Attempts are counted on submission only.** Counting during a form build
  would let a crawler that never submits anything exhaust a real payer's
  allowance.
- **Concurrent duplicate submissions cannot both open a charge.** A lock named
  after the derived idempotency key serialises them; the second rejoins the
  first rather than billing again.
- **External links on the settings page carry `rel="noopener noreferrer"`.**
  Without it a page opened in a new tab can navigate the administration page it
  came from through `window.opener`.
- **No credential is stored that the module never sends.** The settings page
  deliberately offers no public-key field (Tap's public key is for browser SDKs)
  and no webhook-secret field (Tap issues none — webhooks are signed with the
  account secret key). Both are explained instead.

### Documentation

- A "Protecting the standalone payment form" section in the README covering the
  three throttling buckets, the duplicate-submission contract, what the payer is
  told for each outcome, and what changes when an existing site upgrades.
- The README no longer claims the idempotency key carries a browser identity. An
  account or session contributes **when one exists**; an anonymous visitor with
  no session yet contributes nothing, so on that path the key rests on the
  submitted details and the amount alone. The same overstatement was corrected in
  the `IdempotencyKeyFactory` class documentation.
- This changelog gained the `[1.0.1]` entry, which shipped on 2026-07-23 but was
  never recorded here.

### Tests

- `PaymentThrottleTest` (kernel) — the three buckets, their configuration, the
  hashing of the email, and the derived key's stability, uniqueness and time
  bucketing.
- `PaymentAbuseTest` (functional) — a repeat submission must not charge twice, a
  different payer must get their own payment, a throttled submission must never
  reach Tap, and an unusable amount must be refused locally.
- `PaymentOutcomeMessagesTest` (functional) — one test per outcome when Tap
  returns no hosted page, plus the form's CSS classes in every state. Each was
  confirmed to fail against the pre-fix code.
- `SettingsUpdateTest` (kernel) — the update path: an upgraded site ends up
  byte-identical to a fresh one, administrator values survive, and the hook is
  idempotent and safe to resume.
- `ReadmeAccuracyTest` (unit) — the defaults, settings and messages the README
  documents are checked against the code and configuration that ship, so the
  documentation fails a build rather than rotting quietly.
- The suite now runs **213 tests / 1309 assertions**.

### Backward compatibility

Verified for this release:

- **Configuration** — no manual migration. Every new key resolves through
  `FormSettings`, which reads `0` (or an absent key) as "use the module's own
  default". `tap_payment.settings` is untouched and stored secret keys are
  preserved. Run `drush updatedb` as for any release.
- **Payment links** — no route path, name or parameter changed. The return URL
  is still `tap_payment_custom.complete`, the webhook is still
  `/tap-payment/webhook`, and the return route still addresses a transaction by
  UUID.
- **Webform** — no changes required. `TapPaymentWebformHandler` changed only two
  property visibilities (`private` → `protected`) in a `final` class. Webform
  submissions still pass no idempotency key and are not throttled, as before.
- **Commerce** — no changes required, for the same reason: `TapOffsite` and
  `TapOffsiteForm` changed only property visibility.
- **API consumers** — no changes. `PaymentRequest` gained only docblock text and
  a `@phpstan-param`; its constructor signature and validation are unchanged.
  `TapPaymentService`, the class behind `TapPaymentInterface`, is not touched at
  all. The additions to `tap_payment.services.yml` are new parameters only.
- **Webhook processing** — unchanged. Nothing under `src/Webhook/` or
  `src/Controller/` was modified; signature verification and outcome application
  are byte-identical.

## [1.0.1] — 2026-07-23

### Fixed

- Serialization safety: injected services on the settings form, transaction
  list builder and reconciliation queue worker are now `protected` and no
  longer `readonly`, matching the `DependencySerializationTrait` contract on
  PHP 8.3.
- Documented that `TapPaymentInterface::verifyPayment()` can throw
  `ConfigurationException`, matching its real behaviour.

No functional or public API changes.

## [1.0.0] — 2026-07-23

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
