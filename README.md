# Tap Payment

[![CI](https://github.com/abdelwahied/tap_payment/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/abdelwahied/tap_payment/actions/workflows/ci.yml)
[![Latest release](https://img.shields.io/github/v/release/abdelwahied/tap_payment?sort=semver)](https://github.com/abdelwahied/tap_payment/releases/latest)
[![License](https://img.shields.io/github/license/abdelwahied/tap_payment)](LICENSE.txt)
[![Drupal](https://img.shields.io/badge/Drupal-%5E10.3%20%7C%7C%20%5E11-blue.svg)](https://www.drupal.org)
[![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%208.3-blue.svg)](https://www.php.net)

> **Compatibility:** Drupal `^10.3 || ^11`, PHP `>= 8.3`. **Version:** 1.0.0.

Accept payments through [Tap Payments](https://tap.company/) in Drupal 10.3+ and
Drupal 11, using Tap's documented hosted checkout.

The module is a **payment gateway plugin API any module can drive** — not a
Drupal Commerce add-on. Commerce and Webform integrations ship as optional
submodules built on the same public service, and so can yours.

## What it is, in one paragraph

A calling module hands the payment service an amount, a customer and a URL to
come back to. The service creates a Tap charge, records it in a local ledger,
and returns the hosted-page URL to redirect the payer to. Tap confirms the
outcome by a signed webhook — the authoritative source — and the module
verifies that signature, updates the ledger through a one-way state machine, and
dispatches an event other modules subscribe to. No card data ever touches the
site.

## Why the design is shaped this way

- **A browser is never believed.** The redirect back from Tap carries a
  `tap_id`; the module ignores it and re-reads the charge from the API instead.
  The webhook is the truth, and only after its `hashstring` signature verifies.
- **Repeat and out-of-order delivery are harmless.** The same outcome can arrive
  from the browser and the webhook, in either order, more than once. A one-way
  state machine means whichever arrives first wins and the rest are no-ops — a
  captured payment can never be un-captured by a late notification.
- **Duplicate charges are prevented on both sides.** A database-unique
  idempotency key stops two concurrent checkouts creating two ledger rows, and
  the same key is sent to Tap as `reference.idempotent`, which makes Tap return
  the original charge rather than opening a second one. As a second, independent
  layer the Tap `charge_id` is unique in the database too, so even a mishandled
  key cannot record one Tap charge on two rows.
- **Secrets never leave the backend.** Keys are write-only in the settings form,
  are never rendered back, and a log sanitizer strips any key, token, card or
  email that might otherwise reach a log.
- **A future Tap API version is one class.** Everything version-specific lives
  behind an adapter; the public service, the events and the ledger do not name a
  single Tap field.

## Requirements

- Drupal 10.3+ or 11
- PHP 8.3+
- A Tap account and its secret key

## Setup

1. Enable the module: `drush en tap_payment`.
2. Visit **Administration → Configuration → Web services → Tap Payment**
   (`/admin/config/services/tap-payment`).
3. Choose the environment and paste the matching secret key. Tap has no separate
   sandbox host — the environment is decided by which key you use.
4. In the Tap dashboard, no webhook URL needs configuring: the module sends its
   own webhook and return URLs with every charge.

For a site that exports configuration to version control, set the keys per
environment in `settings.php` rather than in the exported configuration:

```php
$config['tap_payment.settings']['live_secret_key'] = getenv('TAP_LIVE_SECRET_KEY');
```

## Taking a payment from your own module

```php
use Drupal\tap_payment\Dto\Customer;
use Drupal\tap_payment\Dto\Money;
use Drupal\tap_payment\Dto\PaymentRequest;

$session = \Drupal::service('tap_payment.payment')->createPayment(new PaymentRequest(
  money: new Money('10.500', 'KWD'),
  customer: new Customer(firstName: 'Ada', email: 'ada@example.com'),
  returnUrl: '/thank-you',
  contextModule: 'my_module',
  contextId: (string) $order_id,
));

// Send the payer to Tap.
return new TrustedRedirectResponse($session->redirectUrl());
```

Then subscribe to `TapPaymentEvents::PAYMENT_CAPTURED` to fulfil the order. See
[API.md](API.md) for the full surface and [ARCHITECTURE.md](ARCHITECTURE.md) for
how the pieces fit.

## Submodules

- **tap_payment_custom** — a ready-made payment form and completion page, with no
  other dependency. It is also the worked example of driving the public API.
- **tap_payment_commerce** — an off-site payment gateway for Drupal Commerce.
  Requires `drupal/commerce`.
- **tap_payment_webform** — takes a payment when a webform is submitted. Requires
  `drupal/webform`.

## Reconciliation

Tap gives a webhook three delivery attempts and then stops, and a payer may
never return to the browser. Cron re-reads any payment still open past Tap's
transaction-expiry window, so a captured payment is never missed. The status
report shows how many payments are still unresolved — a number that keeps
growing means Tap cannot reach the webhook endpoint.

## Testing

```bash
phpcs --standard=Drupal,DrupalPractice --extensions=php,module,inc,install,yml \
  modules/custom/tap_payment
phpunit modules/custom/tap_payment
```

No test touches the network: the HTTP client is replaced with a scriptable stub,
and every fixture is copied verbatim from Tap's own documentation.

## Documentation

| | |
| --- | --- |
| [API.md](API.md) | Full public API reference, with the stability contract |
| [ARCHITECTURE.md](ARCHITECTURE.md) | How the layers fit, and the endpoint-to-Tap-documentation map |
| [CHANGELOG.md](CHANGELOG.md) | What changed, and when |
| [UPGRADING.md](UPGRADING.md) | Version and compatibility policy, and upgrade steps |
| [CONTRIBUTING.md](CONTRIBUTING.md) | How to work on it, and how to extend it |
| [RELEASING.md](RELEASING.md) | The release checklist for maintainers |
| [SECURITY.md](SECURITY.md) | Reporting a vulnerability, and what the module guarantees |

## License

GPL-2.0-or-later. See [LICENSE.txt](LICENSE.txt).
