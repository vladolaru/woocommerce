<?php
/**
 * Tests for the settings model.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Data
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Data;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsModel;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\DataSanitizer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The bool getters of the settings model accept every shape the stored option has been seen to hold.
 *
 * The getters used to return the stored value as it was, which is a TypeError on PHP 8 when an option holds the classic
 * WooCommerce "yes" or "no" (after a legacy migration, a direct database write, or an import tool that re-serializes
 * options without their types). They now go through `DataSanitizer::sanitize_bool()`, which accepts every on and off
 * shape and falls back to the declared default for a missing key.
 *
 * Core reads no value of `save_card_details` and `payment_level_processing`, so those two keys are tested as stored
 * format: a stored value must come back unchanged from a load and a save, and a missing key holds its default.
 *
 * @group paypal-wallet
 */
class SettingsModelTest extends WalletTestCase {

	private const OPTION = 'woocommerce-ppcp-data-settings';

	/**
	 * Build a model over an option that holds exactly the value under test, so every other field falls back to its
	 * declared default and the getter under test is isolated from the rest.
	 *
	 * @param string $key   The settings key under test.
	 * @param mixed  $value The stored value for that key.
	 * @return SettingsModel
	 */
	private function build_model_with( string $key, $value ): SettingsModel {
		$this->set_wallet_option( self::OPTION, array( $key => $value ) );

		return new SettingsModel( new DataSanitizer(), 'wc-' );
	}

	/**
	 * Build a model over an empty option, so every field holds its declared default.
	 *
	 * @return SettingsModel
	 */
	private function build_model_with_empty_option(): SettingsModel {
		$this->set_wallet_option( self::OPTION, array() );

		return new SettingsModel( new DataSanitizer(), 'wc-' );
	}

	/**
	 * A stored value in a shape that has been seen in production (or is a boundary of the boolean filter) and the bool
	 * it must read as.
	 *
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public function data_bool_like_values(): array {
		return array(
			// Real booleans round-trip unchanged.
			'real bool true'               => array( true, true ),
			'real bool false'              => array( false, false ),

			// Legacy classic WooCommerce storage: the shape that produced the TypeError.
			'legacy string yes'            => array( 'yes', true ),
			'legacy string no'             => array( 'no', false ),

			// Numeric strings, common in form-posted settings.
			'string 1'                     => array( '1', true ),
			'string 0'                     => array( '0', false ),

			// Word forms the boolean filter accepts.
			'string true'                  => array( 'true', true ),
			'string false'                 => array( 'false', false ),
			'string on'                    => array( 'on', true ),
			'string off'                   => array( 'off', false ),

			// An empty or unrecognised string reads as false instead of a fatal error.
			'empty string'                 => array( '', false ),
			'unrecognised string is falsy' => array( 'maybe', false ),

			// Integers.
			'integer 1'                    => array( 1, true ),
			'integer 0'                    => array( 0, false ),
		);
	}

	/**
	 * Regression test for a TypeError seen in production: a stored option that holds the legacy "yes" or "no" string instead of
	 * a real bool made this getter throw "Return value must be of type bool, string returned" on PHP 8.
	 *
	 * @testdox Should read a stored value of any known shape as a bool for the save PayPal and Venmo setting.
	 *
	 * @dataProvider data_bool_like_values
	 *
	 * @param mixed $stored_value Any of the shapes the option has been seen to hold.
	 * @param bool  $expected     Expected getter return value.
	 */
	public function test_get_save_paypal_and_venmo_coerces_stored_value( $stored_value, bool $expected ): void {
		$model = $this->build_model_with( 'save_paypal_and_venmo', $stored_value );

		$this->assertSame( $expected, $model->get_save_paypal_and_venmo() );
	}

	/**
	 * @testdox Should read a stored value of any known shape as a bool for the authorize only setting.
	 *
	 * @dataProvider data_bool_like_values
	 *
	 * @param mixed $stored_value The stored value.
	 * @param bool  $expected     Expected getter return value.
	 */
	public function test_get_authorize_only_coerces_stored_value( $stored_value, bool $expected ): void {
		$model = $this->build_model_with( 'authorize_only', $stored_value );

		$this->assertSame( $expected, $model->get_authorize_only() );
	}

	/**
	 * @testdox Should read a stored value of any known shape as a bool for the capture virtual orders setting.
	 *
	 * @dataProvider data_bool_like_values
	 *
	 * @param mixed $stored_value The stored value.
	 * @param bool  $expected     Expected getter return value.
	 */
	public function test_get_capture_virtual_orders_coerces_stored_value( $stored_value, bool $expected ): void {
		$model = $this->build_model_with( 'capture_virtual_orders', $stored_value );

		$this->assertSame( $expected, $model->get_capture_virtual_orders() );
	}

	/**
	 * @testdox Should read a stored value of any known shape as a bool for the instant payments only setting.
	 *
	 * @dataProvider data_bool_like_values
	 *
	 * @param mixed $stored_value The stored value.
	 * @param bool  $expected     Expected getter return value.
	 */
	public function test_get_instant_payments_only_coerces_stored_value( $stored_value, bool $expected ): void {
		$model = $this->build_model_with( 'instant_payments_only', $stored_value );

		$this->assertSame( $expected, $model->get_instant_payments_only() );
	}

	/**
	 * @testdox Should read a stored value of any known shape as a bool for the contact module setting.
	 *
	 * @dataProvider data_bool_like_values
	 *
	 * @param mixed $stored_value The stored value.
	 * @param bool  $expected     Expected getter return value.
	 */
	public function test_get_enable_contact_module_coerces_stored_value( $stored_value, bool $expected ): void {
		$model = $this->build_model_with( 'enable_contact_module', $stored_value );

		$this->assertSame( $expected, $model->get_enable_contact_module() );
	}

	/**
	 * @testdox Should read a stored value of any known shape as a bool for the Pay Now setting.
	 *
	 * @dataProvider data_bool_like_values
	 *
	 * @param mixed $stored_value The stored value.
	 * @param bool  $expected     Expected getter return value.
	 */
	public function test_get_enable_pay_now_coerces_stored_value( $stored_value, bool $expected ): void {
		$model = $this->build_model_with( 'enable_pay_now', $stored_value );

		$this->assertSame( $expected, $model->get_enable_pay_now() );
	}

	/**
	 * @testdox Should read a stored value of any known shape as a bool for the logging setting.
	 *
	 * @dataProvider data_bool_like_values
	 *
	 * @param mixed $stored_value The stored value.
	 * @param bool  $expected     Expected getter return value.
	 */
	public function test_get_enable_logging_coerces_stored_value( $stored_value, bool $expected ): void {
		$model = $this->build_model_with( 'enable_logging', $stored_value );

		$this->assertSame( $expected, $model->get_enable_logging() );
	}

	/**
	 * @testdox Should read a stored value of any known shape as a bool for the stay updated setting.
	 *
	 * @dataProvider data_bool_like_values
	 *
	 * @param mixed $stored_value The stored value.
	 * @param bool  $expected     Expected getter return value.
	 */
	public function test_get_stay_updated_coerces_stored_value( $stored_value, bool $expected ): void {
		$model = $this->build_model_with( 'stay_updated', $stored_value );

		$this->assertSame( $expected, $model->get_stay_updated() );
	}

	/**
	 * The two keys core does not read: the stored value is part of the stored format, so a load and a save must hand it
	 * back exactly as it was stored, whatever its shape.
	 *
	 * @testdox Should keep a stored save_card_details value as it is when the model saves.
	 *
	 * @dataProvider data_bool_like_values
	 *
	 * @param mixed $stored_value Any of the shapes the option has been seen to hold.
	 */
	public function test_save_card_details_keeps_its_stored_value_through_a_save( $stored_value ): void {
		$this->build_model_with( 'save_card_details', $stored_value )->save();

		$stored = get_option( self::OPTION );

		$this->assertIsArray( $stored );
		$this->assertArrayHasKey( 'save_card_details', $stored );
		$this->assertSame( $stored_value, $stored['save_card_details'] );
	}

	/**
	 * @testdox Should keep a stored payment_level_processing value as it is when the model saves.
	 *
	 * @dataProvider data_bool_like_values
	 *
	 * @param mixed $stored_value Any of the shapes the option has been seen to hold.
	 */
	public function test_payment_level_processing_keeps_its_stored_value_through_a_save( $stored_value ): void {
		$this->build_model_with( 'payment_level_processing', $stored_value )->save();

		$stored = get_option( self::OPTION );

		$this->assertIsArray( $stored );
		$this->assertArrayHasKey( 'payment_level_processing', $stored );
		$this->assertSame( $stored_value, $stored['payment_level_processing'] );
	}

	/**
	 * Each field has its own declared default. They are what the model falls back to when the option is empty, which is
	 * what a migration tool that clears or recreates the option leaves behind.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function data_declared_defaults(): array {
		return array(
			'authorize_only defaults to false'         => array( 'get_authorize_only', false ),
			'capture_virtual_orders defaults to false' => array( 'get_capture_virtual_orders', false ),
			'save_paypal_and_venmo defaults to false'  => array( 'get_save_paypal_and_venmo', false ),
			'instant_payments_only defaults to false'  => array( 'get_instant_payments_only', false ),
			'enable_contact_module defaults to true'   => array( 'get_enable_contact_module', true ),
			'enable_pay_now defaults to false'         => array( 'get_enable_pay_now', false ),
			'enable_logging defaults to false'         => array( 'get_enable_logging', false ),
			'stay_updated defaults to true'            => array( 'get_stay_updated', true ),
		);
	}

	/**
	 * @testdox Should fall back to the declared default of the setting when the stored option is empty.
	 *
	 * @dataProvider data_declared_defaults
	 *
	 * @param string $getter   The bool getter under test.
	 * @param bool   $expected The declared default of the field.
	 */
	public function test_getter_returns_declared_default_when_option_is_empty( string $getter, bool $expected ): void {
		$model = $this->build_model_with_empty_option();

		$this->assertSame( $expected, $model->$getter() );
	}

	/**
	 * @testdox Should keep the declared defaults of save_card_details and payment_level_processing when the stored option is empty.
	 */
	public function test_stored_format_keys_hold_their_defaults_when_option_is_empty(): void {
		$data = $this->build_model_with_empty_option()->to_array();

		$this->assertFalse( $data['save_card_details'] );
		$this->assertTrue( $data['payment_level_processing'] );
	}
}
