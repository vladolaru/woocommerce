<?php
/**
 * Tests for the payment methods eligibility rules.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition\FeaturesDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\PaymentMethodsEligibilityService;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Which payment methods a merchant is eligible for, by country, capabilities and module availability.
 *
 * The cases cover Venmo and Pay with Crypto, the two payment methods with an eligibility rule.
 *
 * @group paypal-wallet
 */
class PaymentMethodsEligibilityServiceTest extends WalletTestCase {

	/**
	 * @testdox Should offer Venmo to US merchants.
	 */
	public function test_venmo_us(): void {
		$this->assertTrue( $this->create_service( 'US' )->get_eligibility_checks()['venmo']() );
	}

	/**
	 * @testdox Should not offer Venmo outside the US.
	 */
	public function test_venmo_not_us(): void {
		$this->assertFalse( $this->create_service( 'CA' )->get_eligibility_checks()['venmo']() );
	}

	/**
	 * @testdox Should offer Pay with Crypto only with the merchant's Pay with Crypto capability and APM eligibility.
	 * @testWith [true, true, true]
	 *           [false, true, false]
	 *           [true, false, false]
	 *           [false, false, false]
	 *
	 * @param bool $capability The merchant's Pay with Crypto capability.
	 * @param bool $apm        Whether alternative payment methods are eligible for the store.
	 * @param bool $expected   Whether Pay with Crypto is offered.
	 */
	public function test_pay_with_crypto( bool $capability, bool $apm, bool $expected ): void {
		$service = new PaymentMethodsEligibilityService( 'US', array( FeaturesDefinition::FEATURE_PAY_WITH_CRYPTO => $capability ), $apm );

		$this->assertSame( $expected, $service->get_eligibility_checks()['ppcp-pwc']() );
	}

	/**
	 * @testdox Should not offer Pay with Crypto when the capabilities and APM eligibility are not given.
	 */
	public function test_pay_with_crypto_defaults_to_not_eligible(): void {
		$this->assertFalse( $this->create_service( 'US' )->get_eligibility_checks()['ppcp-pwc']() );
	}

	/**
	 * Build the service.
	 *
	 * @param string $country_code The merchant country.
	 * @return PaymentMethodsEligibilityService
	 */
	private function create_service( string $country_code = 'US' ): PaymentMethodsEligibilityService {
		return new PaymentMethodsEligibilityService( $country_code );
	}
}
