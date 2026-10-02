<?php
/**
 * StripeBillingException class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * A Stripe Billing failure the module handles itself, told apart by its code.
 *
 * @since 11.2.0
 * @internal
 */
class StripeBillingException extends RuntimeException {

	/**
	 * The amount is below the platform's minimum for the currency (client 11.1.0 `Amount_Too_Small_Exception`).
	 */
	public const AMOUNT_TOO_SMALL = 'amount_too_small';

	/**
	 * The customer already has subscriptions in another currency (client 11.1.0 `Cannot_Combine_Currencies_Exception`).
	 */
	public const CANNOT_COMBINE_CURRENCIES = 'cannot_combine_currencies';

	/**
	 * Failure code, one of the class constants.
	 *
	 * @var string
	 */
	private string $error_code;

	/**
	 * Failure details: `minimum_amount` and `currency` for an amount below the minimum, `currency` for mixed currencies.
	 *
	 * @var array<string,mixed>
	 */
	private array $data;

	/**
	 * Constructor.
	 *
	 * @param string              $message    Message.
	 * @param string              $error_code Failure code, one of the class constants.
	 * @param array<string,mixed> $data       Failure details.
	 * @param \Throwable|null     $previous   Previous exception.
	 */
	public function __construct( string $message, string $error_code, array $data = array(), ?\Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );
		$this->error_code = $error_code;
		$this->data       = $data;
	}

	/**
	 * Get the failure code.
	 *
	 * @return string
	 */
	public function get_error_code(): string {
		return $this->error_code;
	}

	/**
	 * Get the failure details.
	 *
	 * @return array<string,mixed>
	 */
	public function get_data(): array {
		return $this->data;
	}
}
