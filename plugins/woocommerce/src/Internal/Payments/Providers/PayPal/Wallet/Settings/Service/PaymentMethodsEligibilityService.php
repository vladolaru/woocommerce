<?php
/**
 * Payment Methods eligibility service.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service;

/**
 * Manages eligibility checks for various PayPal Commerce features.
 */
class PaymentMethodsEligibilityService {

	/**
	 * PayPal Country (or Woo Country if not onboarded)
	 *
	 * @var string
	 */
	private string $merchant_country;

	/**
	 * Constructor.
	 *
	 * @param string $merchant_country The merchant country code.
	 */
	public function __construct(
		string $merchant_country
	) {
		$this->merchant_country = $merchant_country;
	}

	/**
	 * Returns all eligibility checks as callables.
	 *
	 * @return array<string, callable>
	 */
	public function get_eligibility_checks(): array {
		return array(
			'venmo' => fn() => 'US' === $this->merchant_country,
		);
	}
}
