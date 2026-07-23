# Releasing

A step-by-step checklist for cutting a release of the Tap Payment module.
It assumes no prior knowledge of the project: follow it top to bottom.

Throughout, `X.Y.Z` is the version being released (for example `1.0.0`).

## 1. Prepare

- [ ] Work from a clean checkout of the branch you are releasing from
      (`git status` shows nothing uncommitted).
- [ ] Confirm you are on the intended branch (`main` for the current major).

## 2. Run the test suite

Tests run inside a Drupal site with the module in `web/modules/custom/` and the
optional `drupal/commerce` and `drupal/webform` projects installed (the
submodule tests need them). Functional tests need a served, installed site.
From that site's root:

```bash
SIMPLETEST_BASE_URL="http://127.0.0.1:8081" \
SIMPLETEST_DB="sqlite://localhost/db.sqlite" \
BROWSERTEST_OUTPUT_DIRECTORY="$PWD/web/sites/simpletest/browser_output" \
  ./vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  web/modules/custom/tap_payment
```

- [ ] Every test passes. (Deprecations come only from Commerce/Webform/Address
      and core test infrastructure; the module's own code emits none.)
- [ ] No Tap API call is made — the suite replaces the HTTP client with a stub.

CI runs the same suite across PHP 8.3/8.5 and Drupal 10.3/11 on every push; a
green run on the release commit is the authoritative check.

## 3. Run the coding-standards check

```bash
./vendor/bin/phpcs --standard=Drupal,DrupalPractice \
  --extensions=php,module,inc,install,yml,js \
  web/modules/custom/tap_payment
```

- [ ] Zero errors and zero warnings.

## 4. Review the changelog

- [ ] [CHANGELOG.md](CHANGELOG.md) has an entry for `X.Y.Z` describing every
      notable change since the last release.
- [ ] The entry is dated and the "Unreleased" heading (if any) is moved down.

## 5. Update documentation

- [ ] [README.md](README.md) examples still match the code.
- [ ] [API.md](API.md) and [ARCHITECTURE.md](ARCHITECTURE.md) list the current
      public surface and the endpoint-to-Tap-documentation map.
- [ ] [UPGRADING.md](UPGRADING.md) has a section for `X.Y.Z` if any manual step
      is needed (none for a patch or minor).
- [ ] Version references in prose and badges are correct.

## 6. Verify composer metadata

```bash
composer validate --strict --no-check-all
```

- [ ] Passes.
- [ ] `name`, `description`, `license`, `keywords`, `require`, `require-dev`,
      `suggest` (Commerce, Webform) and `authors` are accurate.
- [ ] No `repositories`, path repositories or VCS repositories are present.

## 7. Verify the README examples

- [ ] Every service ID (`tap_payment.payment`, …), route name
      (`tap_payment.settings`, `tap_payment.webhook`, `tap_payment.return`, …),
      event name and configuration key named in the docs exists in the code.
- [ ] The `PaymentRequest` / `Money` / `Customer` constructor signatures in the
      README sample match the DTOs.

## 8. Security spot-check

- [ ] `grep -rn 'sk_test\|sk_live' web/modules/custom/tap_payment --include='*.php'`
      returns nothing outside `tests/fixtures`.
- [ ] After a deliberately failed charge, the Drupal log contains no secret key.

## 9. Tag the release

Drupal.org and Composer both read the tag as the version, so it must match
`X.Y.Z` with no `v` prefix for Drupal.org contrib.

```bash
git tag -a X.Y.Z -m "Tap Payment X.Y.Z"
```

- [ ] Tag created on the reviewed commit.

## 10. Push the tag and publish notes

```bash
git push origin X.Y.Z
```

- [ ] Tag pushed. CI runs against the tag.
- [ ] Create the release on the hosting platform using the `X.Y.Z` CHANGELOG
      entry as the notes.
- [ ] Confirm the packaged archive installs cleanly on a fresh site, and that
      the submodules enable with Commerce and Webform present.

## Notes for a repository split

This module currently lives alongside others in one repository. When it is moved
to its own repository, copy `.github/workflows/ci.yml` and
`.github/workflows/reusable-drupal-module.yml` into it and change the module
path in the reusable workflow from `modules/custom/tap_payment` to the
repository root.
