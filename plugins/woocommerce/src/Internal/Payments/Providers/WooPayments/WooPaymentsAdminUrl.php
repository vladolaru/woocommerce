<?php
/**
 * WooPaymentsAdminUrl class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the WooPayments admin page URLs the extension's links use (`admin.php?page=wc-admin&path=/payments/...`), from
 * checkout, webhooks and admin: order notes, the order page's transaction link and inbox note buttons.
 *
 * The links keep the extension's shape because stored notes outlive a switch back to it; the built-in WooPayments
 * redirects them to its own pages.
 *
 * @since 11.2.0
 * @internal
 */
final class WooPaymentsAdminUrl {

	/**
	 * Get the URL of a WooPayments admin page in the extension's WC Admin path shape.
	 *
	 * @param string               $path  WC Admin path, for example `/payments/transactions/details`.
	 * @param array<string,string> $query Query parameters to append to the path.
	 * @return string Fully qualified WC Admin URL.
	 */
	public static function get_wc_admin_path_url( string $path, array $query = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => 'wc-admin',
					'path' => $path,
				),
				$query
			),
			admin_url( 'admin.php' )
		);
	}
}
