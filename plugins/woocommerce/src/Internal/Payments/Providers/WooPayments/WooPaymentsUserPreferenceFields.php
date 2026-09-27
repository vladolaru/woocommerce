<?php
/**
 * WooPaymentsUserPreferenceFields class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Registers the user meta that keeps each admin list's hidden columns.
 *
 * The keys are the client's (WC_Payments::add_user_data_fields()), so a choice made under the
 * plugin carries over. The admin reads them from the preloaded current user and writes them
 * through the users REST route, so this loads on admin and REST requests only.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsUserPreferenceFields implements RegisterHooksInterface {

	/**
	 * Register the user data fields filter.
	 */
	public function register() {
		add_filter( 'woocommerce_admin_get_user_data_fields', array( $this, 'handle_woocommerce_admin_get_user_data_fields' ) );
	}

	/**
	 * Handle the woocommerce_admin_get_user_data_fields filter.
	 *
	 * @internal
	 *
	 * @param mixed $user_data_fields Registered user data field names.
	 * @return mixed
	 */
	public function handle_woocommerce_admin_get_user_data_fields( $user_data_fields ) {
		if ( ! is_array( $user_data_fields ) ) {
			return $user_data_fields;
		}

		return array_merge(
			$user_data_fields,
			array(
				'wc_payments_transactions_hidden_columns',
				'wc_payments_transactions_blocked_hidden_columns',
				'wc_payments_transactions_uncaptured_hidden_columns',
				'wc_payments_payouts_hidden_columns',
				'wc_payments_disputes_hidden_columns',
				'wc_payments_documents_hidden_columns',
			)
		);
	}
}
