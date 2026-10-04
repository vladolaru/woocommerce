<?php
/**
 * Tests for the payment methods eligibility rules.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\PaymentMethodsEligibilityService;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Which payment methods a merchant is eligible for, by country, capabilities and module availability.
 *
 * The cases cover Venmo, the only payment method with an eligibility rule.
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
	 * Build the service.
	 *
	 * @param string $country_code The merchant country.
	 * @return PaymentMethodsEligibilityService
	 */
	private function create_service( string $country_code = 'US' ): PaymentMethodsEligibilityService {
		return new PaymentMethodsEligibilityService( $country_code );
	}
}
