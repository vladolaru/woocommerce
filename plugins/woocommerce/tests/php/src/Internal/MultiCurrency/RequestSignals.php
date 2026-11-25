<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\Jetpack\Constants;

/**
 * Sets the WordPress request signals the Multi-Currency controllers read, for the length of one call.
 */
final class RequestSignals {

	/**
	 * Run a callback during a wp-admin, AJAX or cron request, or before plugins_loaded fired.
	 *
	 * A signal left out keeps the test request's own value.
	 *
	 * @param array<string,bool> $signals  Signals: admin, ajax, cron, plugins_loaded.
	 * @param callable           $callback Work to run.
	 * @return mixed The callback's result.
	 */
	public static function run( array $signals, callable $callback ) {
		global $wp_actions, $current_screen;

		$previous_screen      = $current_screen;
		$plugins_loaded_count = $wp_actions['plugins_loaded'] ?? null;
		if ( $signals['admin'] ?? false ) {
			set_current_screen( 'edit-page' );
		}
		if ( $signals['ajax'] ?? false ) {
			add_filter( 'wp_doing_ajax', '__return_true' );
		}
		if ( $signals['cron'] ?? false ) {
			add_filter( 'wp_doing_cron', '__return_true' );
			Constants::set_constant( 'DOING_CRON', true );
		}
		if ( false === ( $signals['plugins_loaded'] ?? true ) ) {
			unset( $wp_actions['plugins_loaded'] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored below.
		}

		try {
			return $callback();
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_doing_cron', '__return_true' );
			Constants::clear_single_constant( 'DOING_CRON' );
			if ( null !== $plugins_loaded_count ) {
				$wp_actions['plugins_loaded'] = $plugins_loaded_count; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the count taken above.
			}
			$current_screen = $previous_screen; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the screen taken above.
		}
	}
}
