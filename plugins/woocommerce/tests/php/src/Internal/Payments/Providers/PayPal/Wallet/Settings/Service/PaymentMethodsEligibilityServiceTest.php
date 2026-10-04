<?php
/**
 * Tests for the payment methods eligibility rules (ported from the extension's PaymentMethodsEligibilityServiceTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Axo\Gateway\AxoGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\PaymentMethodsEligibilityService;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Which payment methods a merchant is eligible for, by country, capabilities and module availability.
 *
 * The cases cover the wallet methods only (Venmo); the local payment methods and Pay with Crypto are not part of the wallet.
 *
 * @group paypal-wallet
 */
class PaymentMethodsEligibilityServiceTest extends WalletTestCase {

	/**
	 * @testdox Should offer Fastlane when its module check passes (fastlane).
	 */
	public function test_axo_active(): void {
		$this->assertTrue( $this->create_service()->get_eligibility_checks()[ AxoGateway::ID ]() );
	}

	/**
	 * @testdox Should not offer Fastlane when its module check fails (fastlane).
	 */
	public function test_axo_inactive(): void {
		$this->assertFalse( $this->create_service( 'US', false )->get_eligibility_checks()[ AxoGateway::ID ]() );
	}

	/**
	 * @testdox Should offer Venmo to US merchants (wallet).
	 */
	public function test_venmo_us(): void {
		$this->assertTrue( $this->create_service( 'US' )->get_eligibility_checks()['venmo']() );
	}

	/**
	 * @testdox Should not offer Venmo outside the US (wallet).
	 */
	public function test_venmo_not_us(): void {
		$this->assertFalse( $this->create_service( 'CA' )->get_eligibility_checks()['venmo']() );
	}

	/**
	 * Build the service.
	 *
	 * @param string $country_code The merchant country.
	 * @param bool   $axo_enabled  Whether the Fastlane module check passes.
	 * @return PaymentMethodsEligibilityService
	 */
	private function create_service(
		string $country_code = 'US',
		bool $axo_enabled = true
	): PaymentMethodsEligibilityService {
		return new PaymentMethodsEligibilityService(
			$country_code,
			fn() => $axo_enabled
		);
	}
}
