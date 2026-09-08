<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSettingsDefaults;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPayments settings defaults.
 */
class WooPaymentsSettingsDefaultsTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should return each WooPayments 10.8.0 form-field default.
	 *
	 * @dataProvider form_field_defaults_provider
	 * @param string $key Setting key.
	 * @param mixed  $expected Expected default value.
	 */
	public function test_get_returns_the_pinned_form_field_default( string $key, $expected ): void {
		if ( ! class_exists( WooPaymentsSettingsDefaults::class ) ) {
			$this->fail( 'WooPaymentsSettingsDefaults should provide the pinned WooPayments form-field defaults.' );
		}

		$this->assertSame( $expected, WooPaymentsSettingsDefaults::get( $key ), "The {$key} default should match WooPayments 10.8.0." );
	}

	/**
	 * @testdox Should expose the complete WooPayments 10.8.0 form-field defaults table.
	 */
	public function test_all_returns_the_complete_pinned_form_field_defaults_table(): void {
		if ( ! class_exists( WooPaymentsSettingsDefaults::class ) ) {
			$this->fail( 'WooPaymentsSettingsDefaults should provide the complete pinned WooPayments form-field defaults table.' );
		}

		$expected = array();
		foreach ( $this->form_field_defaults_provider() as $default ) {
			$expected[ $default[0] ] = $default[1];
		}

		$this->assertSame( $expected, WooPaymentsSettingsDefaults::all() );
	}

	/**
	 * Provide literal defaults from the WooPayments 10.8.0 gateway form fields.
	 *
	 * @return array<string,array{string,mixed}>
	 */
	public function form_field_defaults_provider(): array {
		return array(
			'enabled'                           => array( 'enabled', 'no' ),
			'manual capture'                    => array( 'manual_capture', 'no' ),
			'saved cards'                       => array( 'saved_cards', 'yes' ),
			'test mode'                         => array( 'test_mode', 'no' ),
			'debug log'                         => array( 'enable_logging', 'no' ),
			'payment request button type'       => array( 'payment_request_button_type', 'default' ),
			'payment request button theme'      => array( 'payment_request_button_theme', 'dark' ),
			'payment request button height'     => array( 'payment_request_button_height', '44' ),
			'payment request button label'      => array( 'payment_request_button_label', 'Buy now' ),
			'payment request button locations'  => array( 'payment_request_button_locations', array( 'product', 'cart', 'checkout' ) ),
			'enabled payment methods'           => array( 'upe_enabled_payment_method_ids', array( 'card' ) ),
			'payment request button size'       => array( 'payment_request_button_size', 'medium' ),
			'platform checkout custom message'  => array( 'platform_checkout_custom_message', 'By placing this order, you agree to our [terms] and understand our [privacy_policy].' ),
			'express checkout product methods'  => array( 'express_checkout_product_methods', array( 'payment_request', 'woopay', 'amazon_pay' ) ),
			'express checkout cart methods'     => array( 'express_checkout_cart_methods', array( 'payment_request', 'woopay', 'amazon_pay' ) ),
			'express checkout checkout methods' => array( 'express_checkout_checkout_methods', array( 'payment_request', 'woopay', 'amazon_pay' ) ),
		);
	}
}
