<?php
/**
 * WooPaymentsCustomerDataEraser class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

defined( 'ABSPATH' ) || exit;

/**
 * Personal-data eraser for the WooPayments data stored on a WordPress user.
 *
 * Registered on every request whatever the native payments tier: the customer IDs and the cached payment-method list
 * outlive the connection, so they can exist on an available store (disconnected, or run by the WooPayments plugin) and
 * on a disabled one (the kill switch) as well as on a connected or active store (owner decision N-316). The callbacks
 * are static and need no service, so registering them loads nothing until WordPress builds its eraser list.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsCustomerDataEraser {

	/**
	 * Personal-data eraser identifier registered with WordPress.
	 */
	public const ERASER_ID = 'woocommerce-payments-customer';

	/**
	 * Add the eraser to WordPress's personal-data eraser list.
	 *
	 * @internal
	 *
	 * @param mixed $erasers Existing erasers.
	 * @return mixed
	 */
	public static function add_eraser( $erasers ) {
		if ( ! is_array( $erasers ) ) {
			return $erasers;
		}

		$erasers[ self::ERASER_ID ] = array(
			'eraser_friendly_name' => __( 'WooPayments Customer Data', 'woocommerce' ),
			'callback'             => array( self::class, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Erase the WooPayments customer IDs and the cached payment-method list of the user with this email address.
	 *
	 * The deprecated, live and test customer-ID user options are deleted in the per-site and the network-wide scope,
	 * since network saved cards stores the ID network-wide.
	 *
	 * @internal
	 *
	 * @param mixed $email_address Email address being erased.
	 * @param mixed $page          Pagination page (unused; all data fits one page).
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase( $email_address, $page = 1 ): array {
		unset( $page );

		$result = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);

		$user = is_string( $email_address ) ? get_user_by( 'email', $email_address ) : false;
		if ( ! $user ) {
			return $result;
		}

		$option_keys = array(
			WooPaymentsCustomerService::DEPRECATED_CUSTOMER_ID_OPTION,
			WooPaymentsCustomerService::LIVE_CUSTOMER_ID_OPTION,
			WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION,
		);

		foreach ( $option_keys as $option_key ) {
			if ( false === get_user_option( $option_key, $user->ID ) ) {
				continue;
			}

			$removed_local  = delete_user_option( $user->ID, $option_key );
			$removed_global = delete_user_option( $user->ID, $option_key, true );

			if ( $removed_local || $removed_global ) {
				$result['items_removed'] = true;
			}
		}

		if ( delete_user_meta( $user->ID, WooPaymentsTokenService::CACHED_PAYMENT_METHODS_META_KEY ) ) {
			$result['items_removed'] = true;
		}

		return $result;
	}
}
