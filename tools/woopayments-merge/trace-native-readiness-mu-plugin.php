<?php
/**
 * Local-only native readiness trace probe for WooPayments merge verification.
 */

add_filter( 'woocommerce_native_payments_enabled', '__return_true' );

$GLOBALS['native_payments_readiness_trace'] = array();

$native_payments_readiness_frame_matches = static function ( array $frame ): bool {
	$file = (string) ( $frame['file'] ?? '' );

	return false !== strpos( $file, '/Payments/NativePaymentsGatewayRegistry.php' )
		|| false !== strpos( $file, '/Payments/Providers/WooPayments/' )
		|| false !== strpos( $file, '/Internal/Jetpack/JetpackConnection.php' );
};

$native_payments_readiness_stack = static function () use ( $native_payments_readiness_frame_matches ): array {
	$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
	$lines  = array();

	foreach ( array_slice( $frames, 1, 18 ) as $index => $frame ) {
		if ( ! $native_payments_readiness_frame_matches( $frame ) ) {
			continue;
		}

		$callable = ( $frame['class'] ?? '' ) . ( $frame['type'] ?? '' ) . ( $frame['function'] ?? '' );
		$file     = $frame['file'] ?? '<internal>';
		$line     = $frame['line'] ?? 0;
		$lines[]  = sprintf( '#%02d %s:%d %s', $index, $file, $line, $callable );
	}

	return $lines;
};

$native_payments_record_readiness = static function ( string $label, array $data = array() ) use ( $native_payments_readiness_stack ): void {
	$GLOBALS['native_payments_readiness_trace'][] = array(
		'label'      => $label,
		'data'       => $data,
		'did_actions' => array(
			'plugins_loaded'     => did_action( 'plugins_loaded' ),
			'before_wc_init'     => did_action( 'before_woocommerce_init' ),
			'woocommerce_init'   => did_action( 'woocommerce_init' ),
			'init'               => did_action( 'init' ),
		),
		'stack'      => $native_payments_readiness_stack(),
	);
};

add_filter(
	'option_wcpay_account_data',
	static function ( $value ) use ( $native_payments_record_readiness ) {
		$data = is_array( $value ) && isset( $value['data'] ) && is_array( $value['data'] ) ? $value['data'] : array();
		$native_payments_record_readiness(
			'option_wcpay_account_data',
			array(
				'has_account_id'       => isset( $data['account_id'] ) && '' !== (string) $data['account_id'],
				'has_test_key'         => isset( $data['test_publishable_key'] ) && '' !== (string) $data['test_publishable_key'],
				'has_live_key'         => isset( $data['live_publishable_key'] ) && '' !== (string) $data['live_publishable_key'],
				'payments_enabled'     => $data['payments_enabled'] ?? null,
				'details_submitted'    => $data['details_submitted'] ?? null,
			)
		);

		return $value;
	}
);

add_filter(
	'option_woocommerce_woocommerce_payments_settings',
	static function ( $value ) use ( $native_payments_record_readiness ) {
		$settings = is_array( $value ) ? $value : array();
		$native_payments_record_readiness(
			'option_woocommerce_woocommerce_payments_settings',
			array(
				'test_mode' => $settings['test_mode'] ?? null,
				'enabled'   => $settings['enabled'] ?? null,
			)
		);

		return $value;
	}
);

add_filter(
	'option_jetpack_options',
	static function ( $value ) use ( $native_payments_record_readiness ) {
		$options = is_array( $value ) ? $value : array();
		$native_payments_record_readiness(
			'option_jetpack_options',
			array(
				'has_id'          => isset( $options['id'] ) && is_numeric( $options['id'] ) && (int) $options['id'] > 0,
				'master_user_set' => ! empty( $options['master_user'] ),
			)
		);

		return $value;
	}
);

add_action(
	'plugins_loaded',
	static function () use ( $native_payments_record_readiness ): void {
		$native_payments_record_readiness( 'plugins_loaded' );
	},
	PHP_INT_MAX
);

add_action(
	'init',
	static function () use ( $native_payments_record_readiness ): void {
		$native_payments_record_readiness( 'init' );
	},
	PHP_INT_MAX
);

register_shutdown_function(
	static function (): void {
		if ( defined( 'STDERR' ) ) {
			fwrite( STDERR, "[native-payments readiness trace]\n" . wp_json_encode( $GLOBALS['native_payments_readiness_trace'], JSON_PRETTY_PRINT ) . "\n" );
		}
	}
);
