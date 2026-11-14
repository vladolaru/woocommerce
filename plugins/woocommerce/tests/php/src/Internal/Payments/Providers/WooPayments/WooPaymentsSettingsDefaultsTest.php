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
	 * @testdox Should expose the complete WooPayments 11.1.0 form-field defaults table, in order, and each default by its key.
	 */
	public function test_all_returns_the_complete_pinned_form_field_defaults_table(): void {
		// Literal defaults from the WooPayments 11.1.0 gateway form fields.
		$expected = array(
			'enabled'                           => 'no',
			'manual_capture'                    => 'no',
			'saved_cards'                       => 'yes',
			'test_mode'                         => 'no',
			'enable_logging'                    => 'no',
			'payment_request_button_type'       => 'default',
			'payment_request_button_theme'      => 'dark',
			'payment_request_button_height'     => '44',
			'payment_request_button_label'      => 'Buy now',
			'payment_request_button_locations'  => array( 'product', 'cart', 'checkout' ),
			'upe_enabled_payment_method_ids'    => array( 'card' ),
			'payment_request_button_size'       => 'medium',
			'platform_checkout_custom_message'  => 'By placing this order, you agree to our [terms] and understand our [privacy_policy].',
			'express_checkout_product_methods'  => array( 'payment_request', 'woopay', 'amazon_pay' ),
			'express_checkout_cart_methods'     => array( 'payment_request', 'woopay', 'amazon_pay' ),
			'express_checkout_checkout_methods' => array( 'payment_request', 'woopay', 'amazon_pay' ),
		);

		$this->assertSame( $expected, WooPaymentsSettingsDefaults::all() );
		foreach ( $expected as $key => $default ) {
			$this->assertSame( $default, WooPaymentsSettingsDefaults::get( $key ), "The {$key} default should match WooPayments 11.1.0." );
		}
	}
}
