# Security

## Reporting a vulnerability

Email the maintainer at abdelwahied.fx@gmail.com. Please do not open a public
issue for a security problem.

## What the module guarantees

- **Webhooks are authenticated.** Every webhook body is verified against the Tap
  `hashstring` HMAC-SHA256 signature, keyed with the account secret, using a
  constant-time comparison, before a single field of it is trusted. A missing or
  wrong signature is rejected — absence is never taken as permission.
- **Browser input is never trusted.** The return route ignores the `tap_id`
  query parameter and re-reads the charge from Tap. Return destinations are
  restricted to this site, so a payment flow cannot be turned into an open
  redirect.
- **Secrets stay in the backend.** Keys are write-only in the settings form and
  are never rendered back. A log sanitizer strips any key, token, card number or
  email from every log message and context value.
- **No card data is stored.** The ledger holds no PAN, no token and no payer PII
  beyond the pseudonymous customer id Tap issues.
- **Duplicate charges are stopped in two independent places.** The
  `idempotency_key` is unique in the database (guarding this side, against two
  concurrent checkouts) and so is the Tap `charge_id` (guarding Tap's side, so
  the same charge arriving from a retried webhook, a browser return and the
  reconciliation queue at once can only ever be recorded on one ledger row).
  A one-way state machine makes repeat delivery a no-op on top of both.
- **Forged and flooding requests are bounded.** The webhook and return routes
  are flood-limited, and the webhook counter is registered on verification
  *failures*, so genuine Tap traffic never trips it.

## Operational advice

- Prefer setting the secret keys in `settings.php` per environment over exporting
  them in configuration.
- Serve the site over HTTPS with a valid certificate: Tap will not post webhooks
  to an endpoint with a self-signed certificate.
- Watch the "unresolved payments" status-report line — a number that keeps
  climbing means Tap cannot reach the webhook endpoint.
