<?php
/**
 * Payment Methods eligibility service.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayWithCrypto\PWCGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition\FeaturesDefinition;

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
	 * If alternative payment methods are eligible.
	 *
	 * @var bool
	 */
	private bool $is_apm_eligible;

	/**
	 * Array of Merchant capabilities (true: enabled / false: disabled)
	 *
	 * @var array
	 */
	private array $merchant_capabilities;

	/**
	 * Constructor.
	 *
	 * @param string $merchant_country      The merchant country code.
	 * @param array  $merchant_capabilities The merchant capabilities, keyed by feature ID.
	 * @param bool   $is_apm_eligible       Whether alternative payment methods are eligible for the store.
	 */
	public function __construct(
		string $merchant_country,
		array $merchant_capabilities = array(),
		bool $is_apm_eligible = false
	) {
		$this->merchant_country      = $merchant_country;
		$this->merchant_capabilities = $merchant_capabilities;
		$this->is_apm_eligible       = $is_apm_eligible;
	}

	/**
	 * Returns all eligibility checks as callables.
	 *
	 * @return array<string, callable>
	 */
	public function get_eligibility_checks(): array {
		return array(
			'venmo'        => fn() => 'US' === $this->merchant_country,
			PWCGateway::ID => fn() => $this->has_pwc_capability() && $this->is_apm_eligible,
		);
	}

	/**
	 * Whether the Pay with Crypto capability is enabled.
	 *
	 * @return bool
	 */
	private function has_pwc_capability(): bool {
		return $this->merchant_capabilities[ FeaturesDefinition::FEATURE_PAY_WITH_CRYPTO ] ?? false;
	}
}
