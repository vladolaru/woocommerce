<?php
/**
 * Payment Methods eligibility service.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Axo\Gateway\AxoGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\BancontactGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\BlikGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\EPSGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\IDealGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\MultibancoGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\MyBankGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\P24Gateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\PWCGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\TrustlyGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition\FeaturesDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\OXXOGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\PayUponInvoice\PayUponInvoiceGateway;

/**
 * Manages eligibility checks for various PayPal Commerce features.
 */
class PaymentMethodsEligibilityService {

	/**
	 * PayPal Country (or Woo Country if not onboarded)
	 */
	private string $merchant_country;

	/**
	 * If alternative payment methods are eligible.
	 */
	private bool $is_apm_eligible;

	/**
	 * Array of Merchant capabilities (true: enabled / false: disabled)
	 */
	private array $merchant_capabilities;

	/**
	 * Whether Axo is eligible.
	 *
	 * @var callable
	 */
	private $axo_eligible;

	public function __construct(
		string $merchant_country,
		bool $is_apm_eligible,
		array $merchant_capabilities,
		callable $axo_eligible
	) {
		$this->merchant_country      = $merchant_country;
		$this->is_apm_eligible       = $is_apm_eligible;
		$this->merchant_capabilities = $merchant_capabilities;
		$this->axo_eligible          = $axo_eligible;
	}

	/**
	 * Returns all eligibility checks as callables.
	 *
	 * @return array<string, callable>
	 */
	public function get_eligibility_checks(): array {
		return array(
			BancontactGateway::ID     => fn() => $this->is_apm_eligible,
			BlikGateway::ID           => fn() => $this->is_apm_eligible,
			EPSGateway::ID            => fn() => $this->is_apm_eligible,
			IDealGateway::ID          => fn() => $this->is_apm_eligible,
			MyBankGateway::ID         => fn() => $this->is_apm_eligible,
			P24Gateway::ID            => fn() => $this->is_apm_eligible,
			TrustlyGateway::ID        => fn() => $this->is_apm_eligible,
			MultibancoGateway::ID     => fn() => $this->is_apm_eligible,
			OXXOGateway::ID                  => fn() => $this->is_mexico_merchant() && $this->is_apm_eligible,
			PWCGateway::ID            => fn() => $this->has_pwc_capability() && $this->is_apm_eligible,
			PayUponInvoiceGateway::ID => fn() => $this->merchant_country === 'DE',
			AxoGateway::ID            => fn() => call_user_func( $this->axo_eligible ),
			'venmo'                   => fn() => $this->merchant_country === 'US',
		);
	}

	/**
	 * Whether merchant country is mexico.
	 *
	 * @return bool
	 */
	private function is_mexico_merchant(): bool {
		return $this->merchant_country === 'MX';
	}

	/**
	 * Whether Pay With Crypto capability is enabled.
	 *
	 * @return bool
	 */
	private function has_pwc_capability(): bool {
		return $this->merchant_capabilities[ FeaturesDefinition::FEATURE_PAY_WITH_CRYPTO ] ?? false;
	}
}
