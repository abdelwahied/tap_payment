# Contributing

## Ground rules

- Follow the official Tap documentation, and only it. Never invent an API field,
  a signature scheme or a status value; if the documentation is ambiguous, stop
  and ask rather than guess. A wrong guess about a payment status or a signature
  is the one class of bug this module exists to avoid.
- Keep the layers separate. HTTP lives in `TapApiClient`; Tap's field names live
  in `TapV2Adapter`; business logic lives in the service. No `\Drupal::` static
  calls in `src/`, no service locator, dependency injection throughout.
- Nothing sensitive in a log or an exception. New code that logs goes through
  `LogSanitizer`; new exception messages carry codes and identifiers, never
  keys, tokens, cards or payer details.

## Adding another payment provider

Write a class implementing
`Plugin\PaymentGateway\PaymentGatewayInterface`, mark it with the
`#[PaymentGateway]` attribute, and it is discovered automatically. Nothing above
the plugin changes.

## Supporting a new Tap API version

Implement `Api\Adapter\TapApiAdapterInterface`, tag the service
`tap_payment_api_adapter`, and point `tap_payment.api_version` at it. The public
service, the events and the entity are untouched.

## Before opening a merge request

```bash
phpcs --standard=Drupal,DrupalPractice --extensions=php,module,inc,install,yml \
  modules/custom/tap_payment
phpunit modules/custom/tap_payment
```

Both must be clean. Tests must not touch the network — script the HTTP client
stub in `tests/modules/tap_payment_test`, and take any Tap response fixture
verbatim from the official documentation.
