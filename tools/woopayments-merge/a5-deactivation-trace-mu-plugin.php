<?php
/**
 * Local-only A5 deactivation trace helper.
 *
 * Copy this file into a target wp-env container's `wp-content/mu-plugins/`
 * directory only while diagnosing the mandatory cutover proof. Remove it after
 * the probe.
 *
 * @package WooCommerce\Tools\WooPaymentsMerge
 */

add_action(
	'deactivated_plugin',
	static function ( $plugin, $network_deactivating ): void {
		if ( 'woocommerce-payments/woocommerce-payments.php' !== $plugin ) {
			return;
		}

		$frames = array_map(
			static function ( array $frame ): string {
				$function = isset( $frame['function'] ) ? (string) $frame['function'] : '';
				$class    = isset( $frame['class'] ) ? (string) $frame['class'] : '';
				$type     = isset( $frame['type'] ) ? (string) $frame['type'] : '';
				$file     = isset( $frame['file'] ) ? (string) $frame['file'] : '';
				$line     = isset( $frame['line'] ) ? (int) $frame['line'] : 0;

				return $class . $type . $function . ' @ ' . $file . ':' . $line;
			},
			array_slice( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ), 0, 12 ) // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Local ignored diagnostic helper.
		);

		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Local ignored diagnostic helper.
			wp_json_encode(
				array(
					'a5_trace'             => 'woopayments_deactivated',
					'network_deactivating' => (bool) $network_deactivating,
					'frames'               => $frames,
				)
			)
		);
	},
	10,
	2
);

add_action(
	'admin_notices',
	static function (): void {
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Local ignored diagnostic helper.
			wp_json_encode(
				array(
					'a5_trace'        => 'admin_notices',
					'request_status'  => isset( $_GET['wc_woopayments_cutover_status'] ) ? sanitize_key( wp_unslash( $_GET['wc_woopayments_cutover_status'] ) ) : '',
					'admin_init_done' => did_action( 'admin_init' ),
				)
			)
		);
	},
	PHP_INT_MAX
);
