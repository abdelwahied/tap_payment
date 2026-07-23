<?php

declare(strict_types=1);

namespace Drupal\tap_payment\Dto;

use Drupal\tap_payment\Exception\InvalidPaymentRequestException;

/**
 * The payer, reduced to the fields Tap documents as required.
 *
 * Tap's charge schema marks exactly `first_name` and `email` as required
 * inside the customer object; everything else here is optional and is sent
 * only when a caller supplies it. Nothing further is collected, because a
 * payment gateway that stores more about a person than the payment needs is a
 * breach waiting for a reason.
 *
 * @api
 *   Public and stable since 1.0.0.
 *
 * @see https://developers.tap.company/reference/create-a-charge
 */
final class Customer {

  /**
   * Constructs a Customer.
   *
   * @param string $firstName
   *   The payer's first name. Required by Tap.
   * @param string $email
   *   The payer's email address. Required by Tap.
   * @param string|null $lastName
   *   The payer's last name, when known.
   * @param string|null $middleName
   *   The payer's middle name, when known.
   * @param string|null $phoneCountryCode
   *   The dialling code with no leading plus, e.g. `966`.
   * @param string|null $phoneNumber
   *   The subscriber number without the dialling code.
   * @param string|null $tapCustomerId
   *   An existing `cus_…` identifier, when the payer is already known to Tap.
   *
   * @throws \Drupal\tap_payment\Exception\InvalidPaymentRequestException
   *   When a required field is empty or the email is not an address.
   */
  public function __construct(
    public readonly string $firstName,
    public readonly string $email,
    public readonly ?string $lastName = NULL,
    public readonly ?string $middleName = NULL,
    public readonly ?string $phoneCountryCode = NULL,
    public readonly ?string $phoneNumber = NULL,
    public readonly ?string $tapCustomerId = NULL,
  ) {
    if (trim($firstName) === '') {
      throw new InvalidPaymentRequestException('The customer first name is required.');
    }

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === FALSE) {
      throw new InvalidPaymentRequestException('The customer email address is not valid.');
    }

    if (($phoneCountryCode === NULL) !== ($phoneNumber === NULL)) {
      throw new InvalidPaymentRequestException('A customer phone number needs both a country code and a number.');
    }
  }

  /**
   * Whether a phone number was supplied.
   *
   * @return bool
   *   TRUE when both halves of the number are present.
   */
  public function hasPhone(): bool {
    return $this->phoneCountryCode !== NULL && $this->phoneNumber !== NULL;
  }

}
