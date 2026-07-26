# Upgrading

## Introduction

This document explains how to move between versions of the Tap Payment module
and what compatibility you can rely on. It complements
[CHANGELOG.md](CHANGELOG.md), which records what changed in each release;
this file records what you have to *do* about it.

## Version policy

The module follows [Semantic Versioning](https://semver.org/):

- **Patch** releases (`1.0.x`) fix bugs and never change behavior you could
  depend on.
- **Minor** releases (`1.x.0`) add functionality in a backward-compatible way.
  Existing code, configuration and the public API keep working.
- **Major** releases (`2.0.0`, …) may change or remove public API. Every
  breaking change is documented in this file, with a migration path.

The public API surface that this policy protects is the one marked `@api` and
described in [API.md](API.md): the `tap_payment.payment` service and
`TapPaymentInterface`, the payment-gateway plugin type
(`PaymentGatewayInterface`, the `#[PaymentGateway]` attribute), the API-version
adapter (`TapApiAdapterInterface`), the transport (`TapApiClientInterface`), the
value objects, the lifecycle events, the `tap_payment_transaction` entity, and
the `tap_payment.settings` configuration object.

**Tap's own API version is insulated from this policy.** If Tap ships a new API
version, it is added as a new adapter behind `TapApiAdapterInterface` and
selected with the `tap_payment.api_version` container parameter — the module's
public API does not change, so it is not a major release on our side.

## Upgrade process

For a patch or minor release within the same major version:

1. Update the code (`composer update abdelwahied/tap_payment`, or replace the
   module directory).
2. Run database updates: `drush updatedb`.
3. Rebuild caches: `drush cache:rebuild`.

No manual steps are ever required for a patch or minor release. Secret keys,
stored in the `tap_payment.settings` configuration object (or overridden in
`settings.php`), are preserved across updates.

## Version 1.1.0

**No manual steps.** Update the code, run `drush updatedb`, rebuild caches. The
public API is unchanged — every class touched in this release is `@internal` —
and existing configuration, payment links, Webform and Commerce integrations and
webhook processing all continue to work untouched.

`tap_payment_custom_update_10001()` runs during `drush updatedb`. It adds the
release's six new settings keys to `tap_payment_custom.settings` so that a site
upgrading from 1.0.x exports the same configuration a fresh install produces. It
writes a key only when that key is absent, so nothing you have chosen is
overwritten — including a deliberate `0` or `false` — and it is safe to run
again.

Two things behave differently afterwards, both on the standalone payment form in
`tap_payment_custom` and neither requiring action:

- The form is throttled: 10 payment starts per payer per hour by default. If
  your traffic needs more, raise **Payment starts per payer** at
  *Configuration → Web services → Tap payments → Standalone form*.
- A repeat submission within 15 minutes rejoins the payment it already started
  instead of opening a second charge.

Sites that do not enable `tap_payment_custom` are unaffected by both.

## Version 1.0.1

**No upgrade steps are required.** A quality-only patch: serialization safety on
injected services and a corrected `@throws` annotation. No functional or public
API changes.

## Version 1.0.0

This is the first stable release. **No upgrade steps are required** — there is
no earlier version to come from.

The optional submodules (`tap_payment_custom`, `tap_payment_commerce`,
`tap_payment_webform`) are enabled independently and share the core module's
credentials and ledger; enabling one later needs no migration.

## Compatibility policy

- **Drupal**: `^10.3 || ^11`. A minor release will not raise the minimum below
  what a supported Drupal core still receives security coverage for.
- **PHP**: `>= 8.3`, with the `json` and `mbstring` extensions.
- **Drupal Commerce** (`tap_payment_commerce`): `^3`.
- **Webform** (`tap_payment_webform`): `^6.3`.
- Dropping support for a Drupal or PHP version is a breaking change and will
  only happen in a major release, announced here.

Future major versions will document their breaking changes and migration steps
in this file.
