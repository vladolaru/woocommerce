<?php
/**
 * Tests for the onboarding profile model (core-only characterization: the extension has no test for it).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Data
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Data;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\OnboardingProfile;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The read-only server flags and the stored wizard progress of the onboarding profile.
 *
 * Case kinds: every case is a "wallet" case. The profile has no Fastlane flag since the Fastlane cut.
 *
 * @group paypal-wallet
 */
class OnboardingProfileTest extends WalletTestCase {

	private const OPTION = 'woocommerce-ppcp-data-onboarding';

	/**
	 * Build a profile with the given flags.
	 *
	 * @return OnboardingProfile
	 */
	private function create_profile(): OnboardingProfile {
		return new OnboardingProfile(
			true,  // Casual selling.
			false, // Vaulting.
			false, // Card payments.
			false, // Digital wallets.
			true,  // Subscriptions.
			false, // Skip the payment methods step.
			true   // Pay Later.
		);
	}

	/**
	 * @testdox Should report the wallet flags the container passes in (wallet).
	 */
	public function test_flags_report_the_values_passed_in(): void {
		$flags = $this->create_profile()->get_flags();

		$this->assertTrue( $flags['can_use_casual_selling'] );
		$this->assertFalse( $flags['can_use_vaulting'] );
		$this->assertFalse( $flags['can_use_card_payments'] );
		$this->assertFalse( $flags['can_use_digital_wallets'] );
		$this->assertTrue( $flags['can_use_subscriptions'] );
		$this->assertFalse( $flags['should_skip_payment_methods'] );
		$this->assertTrue( $flags['can_use_pay_later'] );
	}

	/**
	 * @testdox Should keep the wizard progress across a save and a reload, and never store the server flags (wallet).
	 */
	public function test_progress_is_stored_and_flags_are_not(): void {
		$this->set_wallet_option( self::OPTION, array() );
		$sut = $this->create_profile();

		$sut->set_completed( true );
		$sut->set_step( 3 );
		$sut->set_products( array( 'EXPRESS_CHECKOUT' ) );
		$sut->save();

		$reloaded = $this->create_profile();
		$this->assertTrue( $reloaded->get_completed() );
		$this->assertSame( 3, $reloaded->get_step() );
		$this->assertSame( array( 'EXPRESS_CHECKOUT' ), $reloaded->get_products() );

		$stored = get_option( self::OPTION );
		foreach ( array_keys( $stored ) as $key ) {
			$this->assertStringStartsNotWith( 'can_use_', $key, 'Flags are read-only server data, not stored keys' );
		}
		$this->assertArrayNotHasKey( 'should_skip_payment_methods', $stored );
	}

	/**
	 * @testdox Should leave a stored key the model does not list out of the saved data (wallet).
	 */
	public function test_unknown_stored_keys_are_not_written_back(): void {
		$this->set_wallet_option(
			self::OPTION,
			array(
				'completed'        => true,
				'can_use_fastlane' => true,
			)
		);

		$sut = $this->create_profile();
		$sut->save();

		$stored = get_option( self::OPTION );
		$this->assertTrue( $stored['completed'] );
		$this->assertArrayNotHasKey( 'can_use_fastlane', $stored );
	}

	/**
	 * @testdox Should not report a Fastlane flag to the settings app (wallet).
	 */
	public function test_flags_carry_no_fastlane_flag(): void {
		$flags = $this->create_profile()->get_flags();

		$this->assertArrayNotHasKey( 'can_use_fastlane', $flags );
	}
}
