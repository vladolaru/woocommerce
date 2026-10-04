<?php
/**
 * Tests for the payment settings model (core-only characterization: the extension has no test for it).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Data
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Data;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\PaymentSettings;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * What the payment settings model stores and what it leaves alone.
 *
 * The extension writes `fastlane_display_watermark` into the same option, so the key must survive a load and a save by
 * core even though core has no reader for it. `AbstractDataModel::load()` keeps only the keys that `get_defaults()` lists,
 * so the Fastlane cut removed the getter and kept the default and the one setter (onboarding still writes the value).
 *
 * Case kinds: every case is a "wallet" case.
 *
 * @group paypal-wallet
 */
class PaymentSettingsTest extends WalletTestCase {

	private const OPTION = 'woocommerce-ppcp-data-payment';

	/**
	 * A fresh model that reads the option as it is now.
	 *
	 * @return PaymentSettings
	 */
	private function load_model(): PaymentSettings {
		return new PaymentSettings();
	}

	/**
	 * @testdox Should start with Venmo, Pay Later and the PayPal logo switched off (wallet).
	 */
	public function test_defaults_are_off(): void {
		$sut = $this->load_model();

		$this->assertFalse( $sut->get_venmo_enabled() );
		$this->assertFalse( $sut->get_paylater_enabled() );
		$this->assertFalse( $sut->get_paypal_show_logo() );
		$this->assertFalse( $sut->is_method_enabled( 'venmo' ) );
		$this->assertFalse( $sut->is_method_enabled( 'pay-later' ) );
	}

	/**
	 * @testdox Should keep the Venmo and Pay Later states and the logo choice across a save and a reload (wallet).
	 */
	public function test_wallet_states_survive_a_save_and_reload(): void {
		$this->set_wallet_option( self::OPTION, array() );
		$sut = $this->load_model();

		$sut->toggle_method_state( 'venmo', true );
		$sut->toggle_method_state( 'pay-later', true );
		$sut->set_paypal_show_logo( true );
		$sut->save();

		$reloaded = $this->load_model();
		$this->assertTrue( $reloaded->is_method_enabled( 'venmo' ) );
		$this->assertTrue( $reloaded->is_method_enabled( 'pay-later' ) );
		$this->assertTrue( $reloaded->get_paypal_show_logo() );
	}

	/**
	 * @testdox Should keep a Fastlane watermark value the extension stored when core changes another setting and saves (wallet).
	 */
	public function test_a_stored_fastlane_watermark_round_trips_through_save(): void {
		$this->set_wallet_option(
			self::OPTION,
			array(
				'venmo_enabled'              => false,
				'fastlane_display_watermark' => true,
			)
		);

		$sut = $this->load_model();
		$this->assertTrue( $sut->to_array()['fastlane_display_watermark'], 'The key is part of the model data' );

		$sut->toggle_method_state( 'venmo', true );
		$sut->save();

		$stored = get_option( self::OPTION );
		$this->assertTrue( $stored['fastlane_display_watermark'], 'A key the extension wrote is saved back unchanged' );
		$this->assertTrue( $stored['venmo_enabled'] );
	}

	/**
	 * @testdox Should list the Fastlane watermark key with its default in the stored data, so a store that never had it still saves it (wallet).
	 */
	public function test_the_fastlane_watermark_key_is_part_of_the_stored_format(): void {
		$this->set_wallet_option( self::OPTION, array() );

		$sut = $this->load_model();
		$sut->save();

		$stored = get_option( self::OPTION );
		$this->assertArrayHasKey( 'fastlane_display_watermark', $stored );
		$this->assertFalse( $stored['fastlane_display_watermark'] );
	}

	/**
	 * @testdox Should drop a stored key that is not a model key on the next save, which is why the Fastlane watermark default stays (wallet).
	 */
	public function test_a_stored_key_outside_the_defaults_is_dropped_on_save(): void {
		$this->set_wallet_option(
			self::OPTION,
			array(
				'venmo_enabled'              => true,
				'some_foreign_key'           => 'kept?',
				'fastlane_display_watermark' => true,
			)
		);

		$sut = $this->load_model();
		$sut->save();

		$stored = get_option( self::OPTION );
		$this->assertArrayNotHasKey( 'some_foreign_key', $stored );
		$this->assertArrayHasKey( 'fastlane_display_watermark', $stored );
	}

	/**
	 * @testdox Should store the enabled state of a gateway the wallet does not register under its WooCommerce gateway option (wallet).
	 */
	public function test_a_foreign_gateway_state_lands_in_the_gateway_option(): void {
		$this->set_wallet_option( 'woocommerce_ppcp-axo-gateway_settings', array() );
		$sut = $this->load_model();

		$sut->toggle_method_state( 'ppcp-axo-gateway', true );

		$this->assertTrue( $sut->is_method_enabled( 'ppcp-axo-gateway' ) );
		$this->assertSame( 'yes', get_option( 'woocommerce_ppcp-axo-gateway_settings' )['enabled'] );

		$sut->toggle_method_state( 'ppcp-axo-gateway', false );

		$this->assertFalse( $sut->is_method_enabled( 'ppcp-axo-gateway' ) );
		$this->assertSame( 'no', get_option( 'woocommerce_ppcp-axo-gateway_settings' )['enabled'] );
	}

	/**
	 * @testdox Should write the Fastlane watermark through its one setter and keep no getter for it (wallet).
	 */
	public function test_fastlane_watermark_has_a_setter_and_no_getter(): void {
		$sut = $this->load_model();
		$this->assertFalse( $sut->to_array()['fastlane_display_watermark'] );

		$sut->set_fastlane_display_watermark( true );

		$this->assertTrue( $sut->to_array()['fastlane_display_watermark'] );
		$this->assertFalse( method_exists( $sut, 'get_fastlane_display_watermark' ) );
	}

	/**
	 * @testdox Should apply a Fastlane watermark value that arrives through from_array() (wallet, through the one setter).
	 */
	public function test_from_array_sets_the_fastlane_watermark(): void {
		$sut = $this->load_model();

		$sut->from_array( array( 'fastlane_display_watermark' => true ) );

		$this->assertTrue( $sut->to_array()['fastlane_display_watermark'] );
	}
}
