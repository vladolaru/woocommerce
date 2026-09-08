<?php
/**
 * WooPaymentsSettingsDefaults class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * WooPayments gateway form-field defaults.
 *
 * Defaults are copied from the WooPayments client repository's `10.8.0` tag,
 * `includes/class-wc-payment-gateway-wcpay.php::get_form_fields()`.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
final class WooPaymentsSettingsDefaults {

	/**
	 * WooPayments 10.8.0 gateway form-field defaults.
	 *
	 * @var array<string,mixed>
	 */
	private const DEFAULTS = array(
		'enabled'                                  => 'no',
		'manual_capture'                           => 'no',
		'saved_cards'                              => 'yes',
		'test_mode'                                => 'no',
		'enable_logging'                           => 'no',
		'payment_request_button_type'              => 'default',
		'payment_request_button_theme'             => 'dark',
		'payment_request_button_height'            => '44',
		'payment_request_button_label'             => 'Buy now',
		'payment_request_button_locations'         => array( 'product', 'cart', 'checkout' ),
		'upe_enabled_payment_method_ids'           => array( 'card' ),
		'payment_request_button_size'              => 'medium',
		'platform_checkout_custom_message'         => 'By placing this order, you agree to our [terms] and understand our [privacy_policy].',
		'express_checkout_product_methods'         => array( 'payment_request', 'woopay', 'amazon_pay' ),
		'express_checkout_cart_methods'            => array( 'payment_request', 'woopay', 'amazon_pay' ),
		'express_checkout_checkout_methods'        => array( 'payment_request', 'woopay', 'amazon_pay' ),
	);

	/**
	 * Get a WooPayments form-field default.
	 *
	 * @since 11.2.0
	 *
	 * @param string $key Gateway setting key.
	 * @return mixed The source default, or null when the plugin has no form-field default.
	 */
	public static function get( string $key ) {
		return self::DEFAULTS[ $key ] ?? null;
	}

	/**
	 * Get every WooPayments form-field default.
	 *
	 * @since 11.2.0
	 *
	 * @return array<string,mixed> WooPayments form-field defaults.
	 */
	public static function all(): array {
		return self::DEFAULTS;
	}
}
