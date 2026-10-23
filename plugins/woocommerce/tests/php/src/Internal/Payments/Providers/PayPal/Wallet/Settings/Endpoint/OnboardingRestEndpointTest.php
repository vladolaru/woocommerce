<?php
/**
 * Tests for the onboarding REST endpoint.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\OnboardingProfile;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\OnboardingRestEndpoint;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The flags the onboarding screens read, under the JavaScript names the settings app uses.
 *
 * The `canUseFastlane` flag is no longer part of the endpoint response.
 *
 * @group paypal-wallet
 */
class OnboardingRestEndpointTest extends WalletTestCase {

	/**
	 * Build the endpoint over a profile with the given flags.
	 *
	 * @return OnboardingRestEndpoint
	 */
	private function create_endpoint(): OnboardingRestEndpoint {
		return new OnboardingRestEndpoint(
			new OnboardingProfile(
				true,  // Casual selling.
				false, // Vaulting.
				false, // Card payments.
				false, // Digital wallets.
				true,  // Subscriptions.
				false, // Skip the payment methods step.
				true   // Pay Later.
			)
		);
	}

	/**
	 * @testdox Should hand the wallet flags to the settings app under their JavaScript names.
	 */
	public function test_details_carry_the_wallet_flags(): void {
		$data = $this->create_endpoint()->get_details()->get_data();

		$flags = $data['flags'];
		$this->assertTrue( $flags['canUseCasualSelling'] );
		$this->assertFalse( $flags['canUseVaulting'] );
		$this->assertFalse( $flags['canUseCardPayments'] );
		$this->assertFalse( $flags['canUseDigitalWallets'] );
		$this->assertTrue( $flags['canUseSubscriptions'] );
		$this->assertFalse( $flags['shouldSkipPaymentMethods'] );
		$this->assertTrue( $flags['canUsePayLater'] );
	}

	/**
	 * @testdox Should not hand a canUseFastlane flag to the settings app.
	 */
	public function test_details_carry_no_fastlane_flag(): void {
		$data = $this->create_endpoint()->get_details()->get_data();

		$this->assertArrayNotHasKey( 'canUseFastlane', $data['flags'] );
	}
}
