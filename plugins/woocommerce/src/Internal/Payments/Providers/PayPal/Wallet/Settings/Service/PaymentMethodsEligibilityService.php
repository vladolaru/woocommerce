<?php
/**
 * Payment Methods eligibility service.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Axo\Gateway\AxoGateway;

/**
 * Manages eligibility checks for various PayPal Commerce features.
 */
class PaymentMethodsEligibilityService {

	/**
	 * PayPal Country (or Woo Country if not onboarded)
	 */
	private string $merchant_country;

	/**
	 * Whether Axo is eligible.
	 *
	 * @var callable
	 */
	private $axo_eligible;

	public function __construct(
		string $merchant_country,
		callable $axo_eligible
	) {
		$this->merchant_country = $merchant_country;
		$this->axo_eligible     = $axo_eligible;
	}

	/**
	 * Returns all eligibility checks as callables.
	 *
	 * @return array<string, callable>
	 */
	public function get_eligibility_checks(): array {
		return array(
			AxoGateway::ID => fn() => call_user_func( $this->axo_eligible ),
			'venmo'        => fn() => $this->merchant_country === 'US',
		);
	}
}
