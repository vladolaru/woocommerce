<?php
/**
 * WooPaymentsSettingsDefaults class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * The WooPayments gateway settings' form defaults: a key never saved reads as its default.
 *
 * They are the WooPayments extension's, which writes the same settings option (client 11.1.0
 * `includes/class-wc-payment-gateway-wcpay.php:379-549`, `get_form_fields()`).
 *
 * @since 11.2.0
 * @internal
 */
final class WooPaymentsSettingsDefaults {

	/**
	 * Get a WooPayments form-field default.
	 *
	 * @since 11.2.0
	 *
	 * @param string $key Gateway setting key.
	 * @return mixed The default, or null when the key has none.
	 */
	public static function get( string $key ) {
		$defaults = self::get_defaults();

		return $defaults[ $key ] ?? null;
	}

	/**
	 * Get every WooPayments form-field default.
	 *
	 * @since 11.2.0
	 *
	 * @return array<string,mixed> WooPayments form-field defaults.
	 */
	public static function all(): array {
		return self::get_defaults();
	}

	/**
	 * Get the form defaults, with their labels translated for the current locale.
	 *
	 * @return array<string,mixed> WooPayments form-field defaults.
	 */
	private static function get_defaults(): array {
		return array(
			'enabled'                           => 'no',
			'manual_capture'                    => 'no',
			'saved_cards'                       => 'yes',
			'test_mode'                         => 'no',
			'enable_logging'                    => 'no',
			'payment_request_button_type'       => 'default',
			'payment_request_button_theme'      => 'dark',
			'payment_request_button_height'     => '44',
			'payment_request_button_label'      => __( 'Buy now', 'woocommerce' ),
			'payment_request_button_locations'  => array( 'product', 'cart', 'checkout' ),
			'upe_enabled_payment_method_ids'    => array( 'card' ),
			'payment_request_button_size'       => 'medium',
			'platform_checkout_custom_message'  => __( 'By placing this order, you agree to our [terms] and understand our [privacy_policy].', 'woocommerce' ),
			'express_checkout_product_methods'  => array( 'payment_request', 'woopay', 'amazon_pay' ),
			'express_checkout_cart_methods'     => array( 'payment_request', 'woopay', 'amazon_pay' ),
			'express_checkout_checkout_methods' => array( 'payment_request', 'woopay', 'amazon_pay' ),
		);
	}
}
