# Tap Payment

[![CI](https://github.com/abdelwahied/tap_payment/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/abdelwahied/tap_payment/actions/workflows/ci.yml)
[![Latest release](https://img.shields.io/github/v/release/abdelwahied/tap_payment?sort=semver)](https://github.com/abdelwahied/tap_payment/releases/latest)
[![License](https://img.shields.io/github/license/abdelwahied/tap_payment)](LICENSE.txt)
[![Drupal](https://img.shields.io/badge/Drupal-%5E10.3%20%7C%7C%20%5E11-blue.svg)](https://www.drupal.org)
[![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%208.3-blue.svg)](https://www.php.net)

> **Compatibility:** Drupal `^10.3 || ^11`, PHP `>= 8.3`. **Version:** 1.1.0.

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

$url = $session->redirectUrl();

if ($url === NULL) {
  // No hosted page to send anyone to. Inspect $session->transaction->getState()
  // before deciding what to tell the payer: this covers a payment that is
  // already captured, one this call rejoined, and one Tap declined outright —
  // and they do not deserve the same message. `tap_payment_custom`'s
  // PaymentForm::reportMissingRedirect() is a worked example.
  return $this->handleNoRedirect($session);
}

// Send the payer to Tap.
return new TrustedRedirectResponse($url);
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

## Protecting the standalone payment form

The form in `tap_payment_custom` is public, and every submission costs an
outbound call to Tap. Three things bound it, all configurable at
**Configuration → Web services → Tap payments → Standalone form**.

### Throttling is per payer, not per address

Behind carrier-grade NAT, an office, a school or a university, hundreds of
legitimate payers share one public address. An IP-only limit tight enough to
stop abuse there is tight enough to lock all of them out on a busy afternoon —
so the limit is checked against three buckets, and a request has to be within
all of them:

| Bucket | Identifier | Applies when |
| --- | --- | --- |
| Session or account | The signed-in user, or the session | A session exists — always for a signed-in payer |
| Email | The submitted address, **hashed** | An address was entered |
| Client address | The client IP, allowance multiplied | Always |

The address bucket is deliberately the loosest: it bounds a whole network rather
than one person. The email is hashed before it is counted, so no payer's address
is ever written to the flood backend.

| Setting | Default | Meaning |
| --- | --- | --- |
| `flood_limit` | 10 | Payment starts per payer per window |
| `flood_window` | 3600 | The window, in seconds |
| `flood_ip_multiplier` | 10 | How many payers' worth one address may carry |
| `throttle_by_session` | true | Whether the session bucket applies |
| `throttle_by_email` | true | Whether the email bucket applies |

Attempts are counted **on submission only**. Counting on page build would let a
crawler that never submits anything exhaust a real payer's allowance.

### Duplicate submissions rejoin, they do not fail

The form derives an idempotency key from what was submitted — the amount, the
currency, the payer's details, and, **when one is available**, the signed-in
account or the current anonymous session. An identical submission within
`idempotency_lifetime` (900 seconds by default) resolves to the same key, so:

- a double-clicked Pay button opens one charge, not two;
- a browser retry or a reloaded confirmation lands on the payment already
  started, at the hosted page the payer left;
- a network retry is safe, because Tap honours the same
  `reference.idempotent` and returns the original charge.

A repeat submission is never shown an error for being a repeat: it is sent back
to its payment, or told the payment is already under way. After the window, the
same payer buying the same thing again gets a new charge, which is what they
meant. A payment that Tap *declines* is a different matter and is reported as
the failure it is — see [What the payer is told](#what-the-payer-is-told).

A lock is held around the call itself, so two requests that arrive in the same
instant cannot both open a charge; the second waits and rejoins the first.

### What the payer is told

When Tap returns no hosted page there is no single reason, and the form does not
pretend otherwise. It reports on the state of the charge:

| Situation | What the payer sees |
| --- | --- |
| The charge is already captured | *Status* — this payment has already been completed |
| A repeat submission on a payment still under way | *Status* — your payment is already being processed |
| Tap declined, failed, cancelled or timed out the charge | **Error** — the payment was not completed, your card was not charged |
| A new charge came back with nowhere to send the payer | **Error** — the payment could not be started |

The distinction matters: a declined card is not "being processed", and a payer
told to wait for a confirmation email that will never arrive is worse off than
one told plainly that the payment failed.

### Security notes

- **An account or session contributes to the key when one exists.** For a
  signed-in payer that is their account; for an anonymous payer it is the
  current session, if one has already been started. This raises the accuracy of
  duplicate detection — particularly for anonymous payers sharing an address —
  but it is **not** a guarantee that two browsers can never derive the same key.
  An anonymous visitor with no session yet contributes nothing here, so on that
  path the key rests on the submitted details and the amount alone.
- **No card data ever reaches this site.** The payer enters it on Tap's hosted
  page; the module sees a charge id and a status.
- **Nothing sensitive is logged.** The module logs payment created, payment
  failed, throttle exceeded, duplicate detected and key reused — never a token,
  an authorization header, a key or a card. See `LogSanitizer`.
- **A misconfigured amount or currency never reaches Tap.** It is refused while
  the form is built, and the payer is told payments are unavailable rather than
  shown an API error.

### Upgrading an existing site

Nothing has to be migrated: no database update, no configuration change, and no
edit to any integration. The whole `@api` surface — `TapPaymentInterface`, the
gateway plugin type, the value objects, the events, the
`tap_payment_transaction` entity and the `tap_payment.settings` configuration
object — is unchanged, as is webhook processing. Every class touched by this
work is marked `@internal`.

**Configuration needs no manual migration**, but do run `drush updatedb`. The
six new keys are read through `FormSettings`, which treats `0` or an absent key
as "use the module's own default", so the form behaves identically whether or
not they are stored. What they change is the exported file: a site that
installed the module before these keys existed would otherwise export something
a site installing today does not, forever.

`tap_payment_custom_update_10001()` closes that gap. It copies the module's own
`config/install` file into the active configuration, **writing a key only when
that key is absent** — so anything an administrator chose is left alone,
including the values that look like absence (`flood_limit: 0` is a deliberate
"use the default"; `throttle_by_email: false` is a deliberate "do not count that
bucket"). Running it twice changes nothing the second time, and a run
interrupted half way is finished off rather than restarted by the next one.

Note that it writes the shipped `0`, not the resolved `10` or `900`. Freezing
today's default into your configuration would opt the site out of every later
change to that default, which is the opposite of what the zero is for.

**Webform and Commerce integrations need no changes**, and get no new
behaviour: throttling and derived idempotency keys are wired into the standalone
form only. A Webform or Commerce payment still passes no idempotency key, so
`TapPaymentService` still mints a UUID per submission exactly as before, and
neither entry point is throttled.

**Payment links stay valid.** No route path, name or parameter changed, and
already-issued Tap hosted URLs live at Tap. If anything, a stale attempt is now
more likely to be *resumed* than replaced — that is what the idempotency key
does.

What does change, all of it on the standalone form:

| Difference | What an existing site sees |
| --- | --- |
| The form is throttled | A payer who could previously submit without limit is refused after `flood_limit` starts per window — 10 per hour by default — with "Too many payment attempts." |
| Repeats rejoin | An identical submission within `idempotency_lifetime` is handed the payment it already started instead of opening a second charge. |
| Two new messages | A charge Tap has already captured, and a repeat submission on a payment still under way, are now reported separately from a failure. See [What the payer is told](#what-the-payer-is-told). The wording for a declined charge and for a charge that could not be started is unchanged in meaning. |

The wrapper CSS classes are unaffected: `buildForm()` adds to the render array
Drupal hands it, including on the "payments are not available" path, so the
form's own class is present in every state.

Two smaller differences with no integration impact: submissions now serialise on
a lock named after the idempotency key, so a concurrent duplicate waits up to 30
seconds and rejoins rather than opening a second charge; and an email longer
than the 254 characters Tap documents is refused locally instead of costing a
round trip.

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
