<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Service;

use Drupal\Component\Utility\UrlHelper;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Decides whether a URL is somewhere this site may send a browser.
 *
 * A payment flow is an open-redirect waiting to happen: the site takes a URL,
 * keeps it across a trip to a third party, and then sends the customer there.
 * If an attacker can put their own address in, they get a redirect that starts
 * on a domain the customer trusts and ends on one they do not — right after a
 * payment, which is exactly when a convincing phishing page pays off.
 *
 * So the rule is simple and has no exceptions: a return URL is a path on this
 * site, or an absolute URL whose host is this site's. Anything else is refused
 * at the point it is accepted, not at the point it is used, because by then the
 * customer is already mid-redirect.
 *
 * @internal
 *   Injected as a service.
 */
final class InternalUrlValidator {

  /**
   * Constructs an InternalUrlValidator.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   Supplies the host this site is being served on.
   */
  public function __construct(private readonly RequestStack $requestStack) {}

  /**
   * Whether a URL points somewhere on this site.
   *
   * @param string $url
   *   The URL or path to check.
   *
   * @return bool
   *   TRUE when the browser may be sent there.
   */
  public function isInternal(string $url): bool {
    $url = trim($url);

    if ($url === '') {
      return FALSE;
    }

    // A protocol-relative URL (//evil.example) is external but does not look
    // it to a naive check, and UrlHelper::isExternal() knows that.
    if (!UrlHelper::isExternal($url)) {
      // Reject anything that is not a rooted path, so a stored value can never
      // be resolved against an unexpected base.
      return str_starts_with($url, '/') && !str_starts_with($url, '//');
    }

    $request = $this->requestStack->getCurrentRequest();

    if ($request === NULL) {
      // No request means no host to compare against; refuse rather than guess.
      return FALSE;
    }

    return UrlHelper::externalIsLocal($url, $request->getSchemeAndHttpHost());
  }

}
