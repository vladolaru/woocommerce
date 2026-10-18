<?php
/**
 * Handles running payment method specs
 */

namespace Automattic\WooCommerce\Internal\Admin\RemoteFreeExtensions;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Internal\Admin\RemoteFreeExtensions\DefaultFreeExtensions;
use Automattic\WooCommerce\Admin\RemoteSpecs\RemoteSpecsEngine;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;

/**
 * Remote Payment Methods engine.
 * This goes through the specs and gets eligible payment methods.
 */
class Init extends RemoteSpecsEngine {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_updated', array( __CLASS__, 'delete_specs_transient' ) );
	}

	/**
	 * Go through the specs and run them.
	 *
	 * @param array $allowed_bundles Optional array of allowed bundles to be returned.
	 * @return array
	 */
	public static function get_extensions( $allowed_bundles = array() ) {
		$locale = get_user_locale();

		$specs           = self::get_specs();
		$results         = EvaluateExtension::evaluate_bundles( $specs, $allowed_bundles );
		$specs_to_return = $results['bundles'];
		$specs_to_save   = null;

		$plugins = array_filter(
			$results['bundles'],
			function( $bundle ) {
				return count( $bundle['plugins'] ) > 0;
			}
		);

		if ( empty( $plugins ) ) {
			// When no plugins are visible, replace it with defaults and save for 3 hours.
			$specs_to_save   = DefaultFreeExtensions::get_all();
			$specs_to_return = EvaluateExtension::evaluate_bundles( $specs_to_save, $allowed_bundles )['bundles'];
		} elseif ( count( $results['errors'] ) > 0 ) {
			// When suggestions is not empty but has errors, save it for 3 hours.
			$specs_to_save = $specs;
		}

		// When plugins is not empty but has errors, save it for 3 hours.
		if ( count( $results['errors'] ) > 0 ) {
			self::log_errors( $results['errors'] );
		}

		if ( $specs_to_save ) {
			RemoteFreeExtensionsDataSourcePoller::get_instance()->set_specs_transient( array( $locale => $specs_to_save ), 3 * HOUR_IN_SECONDS );
		}

		return self::remove_woopayments_from_bundles( $specs_to_return );
	}

	/**
	 * Leave the WooPayments plugin out of the bundles while the WooPayments feature is on: the built-in WooPayments is
	 * offered instead, and the remote specs still list the extension.
	 *
	 * The default bundles follow the same rule (DefaultFreeExtensions::get_all()).
	 *
	 * @param array $bundles Evaluated bundles.
	 * @return array
	 */
	private static function remove_woopayments_from_bundles( array $bundles ): array {
		if ( ! wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->is_builtin_enabled() ) {
			return $bundles;
		}

		foreach ( $bundles as $index => $bundle ) {
			if ( empty( $bundle['plugins'] ) || ! is_array( $bundle['plugins'] ) ) {
				continue;
			}

			$bundles[ $index ]['plugins'] = array_values(
				array_filter(
					$bundle['plugins'],
					static function ( $plugin ): bool {
						return ! is_object( $plugin ) || 'woocommerce-payments' !== ( $plugin->key ?? '' );
					}
				)
			);
		}

		return $bundles;
	}

	/**
	 * Delete the specs transient.
	 */
	public static function delete_specs_transient() {
		RemoteFreeExtensionsDataSourcePoller::get_instance()->delete_specs_transient();
	}

	/**
	 * Get specs or fetch remotely if they don't exist.
	 */
	public static function get_specs() {
		if ( 'no' === get_option( 'woocommerce_show_marketplace_suggestions', 'yes' ) ) {
			return DefaultFreeExtensions::get_all();
		}
		$specs = RemoteFreeExtensionsDataSourcePoller::get_instance()->get_specs_from_data_sources();

		// Fetch specs if they don't yet exist.
		if ( false === $specs || ! is_array( $specs ) || 0 === count( $specs ) ) {
			return DefaultFreeExtensions::get_all();
		}

		return $specs;
	}
}
