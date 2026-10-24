<?php
/**
 * Deprecated WooPayments promotion engine stub.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Admin\WCPayPromotion;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Admin\RemoteSpecs\RemoteSpecsEngine;

/**
 * WooPayments Promotion engine.
 *
 * Kept only so third-party references keep resolving; every method is an inert no-op.
 * Also reachable through the `Automattic\WooCommerce\Admin\Features\WcPayPromotion\Init` alias.
 *
 * @deprecated 9.9.0 The WooPayments promotion engine no longer runs. Scheduled for removal in WooCommerce 12.0.0.
 */
class Init extends RemoteSpecsEngine {

	/**
	 * Return the gateway list unchanged.
	 *
	 * @deprecated 9.9.0 The WooPayments promotion engine no longer runs.
	 *
	 * @param array $gateways List of gateway classes.
	 *
	 * @return array The unchanged list of gateway classes.
	 */
	public static function possibly_register_pre_install_wc_pay_promotion_gateway( $gateways ) {
		wc_deprecated_function( __METHOD__, '9.9.0' );

		return $gateways;
	}

	/**
	 * The promotion is never shown.
	 *
	 * @deprecated 9.9.0 The WooPayments promotion engine no longer runs.
	 *
	 * @return boolean Always false.
	 */
	public static function can_show_promotion() {
		wc_deprecated_function( __METHOD__, '9.9.0' );

		return false;
	}

	/**
	 * Return the gateway ordering unchanged.
	 *
	 * @deprecated 9.9.0 The WooPayments promotion engine no longer runs.
	 *
	 * @param array $ordering Existing ordering of the payment gateways.
	 *
	 * @return array The unchanged ordering.
	 */
	public static function set_gateway_top_of_list( $ordering ) {
		wc_deprecated_function( __METHOD__, '9.9.0' );

		return $ordering;
	}

	/**
	 * There is no promotion spec.
	 *
	 * @deprecated 9.9.0 The WooPayments promotion engine no longer runs.
	 *
	 * @param boolean $fetch_from_remote Unused.
	 *
	 * @return false Always false.
	 */
	public static function get_wc_pay_promotion_spec( $fetch_from_remote = true ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Kept for signature compatibility.
		wc_deprecated_function( __METHOD__, '9.9.0' );

		return false;
	}

	/**
	 * There are no promotions.
	 *
	 * @deprecated 9.9.0 The WooPayments promotion engine no longer runs.
	 *
	 * @return array Always an empty list.
	 */
	public static function get_promotions() {
		wc_deprecated_function( __METHOD__, '9.9.0' );

		return array();
	}

	/**
	 * There are no promotions.
	 *
	 * @deprecated 9.9.0 The WooPayments promotion engine no longer runs.
	 *
	 * @return array Always an empty list.
	 */
	public static function get_cached_or_default_promotions() {
		wc_deprecated_function( __METHOD__, '9.9.0' );

		return array();
	}

	/**
	 * The merchant is never reported as WooPay eligible by this engine.
	 *
	 * @deprecated 9.9.0 The WooPayments promotion engine no longer runs.
	 *
	 * @return boolean Always false.
	 */
	public static function is_woopay_eligible() {
		wc_deprecated_function( __METHOD__, '9.9.0' );

		return false;
	}

	/**
	 * Do nothing: there are no specs to delete.
	 *
	 * @deprecated 9.9.0 The WooPayments promotion engine no longer runs.
	 *
	 * @return void
	 */
	public static function delete_specs_transient() {
		wc_deprecated_function( __METHOD__, '9.9.0' );
	}

	/**
	 * There are no specs.
	 *
	 * @deprecated 9.9.0 The WooPayments promotion engine no longer runs.
	 *
	 * @return array Always an empty list.
	 */
	public static function get_specs() {
		wc_deprecated_function( __METHOD__, '9.9.0' );

		return array();
	}

	/**
	 * Do nothing: there are no promotion assets to load.
	 *
	 * @deprecated 9.9.0 The WooPayments promotion engine no longer runs.
	 *
	 * @return void
	 */
	public static function load_payment_method_promotions() {
		wc_deprecated_function( __METHOD__, '9.9.0' );
	}
}
