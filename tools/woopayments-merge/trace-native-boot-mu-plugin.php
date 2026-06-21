<?php
/**
 * Local-only native boot trace probe for WooPayments merge verification.
 */

add_filter( 'woocommerce_native_payments_enabled', '__return_true' );

$GLOBALS['native_payments_boot_trace'] = array(
	'template_filter_count' => 0,
	'gettext_count'         => 0,
	'option_template_count' => 0,
	'currency_filter_count' => 0,
	'option_currency_count' => 0,
);

$native_payments_trace_log = static function ( string $label ): void {
	$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
	$lines  = array();

	foreach ( array_slice( $frames, 1, 14 ) as $index => $frame ) {
		$callable = ( $frame['class'] ?? '' ) . ( $frame['type'] ?? '' ) . ( $frame['function'] ?? '' );
		$file     = $frame['file'] ?? '<internal>';
		$line     = $frame['line'] ?? 0;
		$lines[]  = sprintf( '#%02d %s:%d %s', $index, $file, $line, $callable );
	}

	$output = "[native-payments boot trace] {$label}\n" . implode( "\n", $lines ) . "\n";

	if ( defined( 'STDERR' ) ) {
		fwrite( STDERR, $output );
	}

	error_log( $output );
};

add_filter(
	'template',
	static function ( $template ) use ( $native_payments_trace_log ) {
		++$GLOBALS['native_payments_boot_trace']['template_filter_count'];

		if ( $GLOBALS['native_payments_boot_trace']['template_filter_count'] <= 8 ) {
			$native_payments_trace_log( 'template filter #' . $GLOBALS['native_payments_boot_trace']['template_filter_count'] . ' value=' . (string) $template );
		}

		return $template;
	},
	0
);

add_filter(
	'pre_option_template',
	static function ( $pre_option ) use ( $native_payments_trace_log ) {
		++$GLOBALS['native_payments_boot_trace']['option_template_count'];

		if ( $GLOBALS['native_payments_boot_trace']['option_template_count'] <= 8 ) {
			$native_payments_trace_log( 'pre_option_template #' . $GLOBALS['native_payments_boot_trace']['option_template_count'] );
		}

		return $pre_option;
	},
	0
);

add_filter(
	'pre_option_woocommerce_currency',
	static function ( $pre_option ) use ( $native_payments_trace_log ) {
		++$GLOBALS['native_payments_boot_trace']['option_currency_count'];

		if ( $GLOBALS['native_payments_boot_trace']['option_currency_count'] <= 8 ) {
			$native_payments_trace_log( 'pre_option_woocommerce_currency #' . $GLOBALS['native_payments_boot_trace']['option_currency_count'] );
		}

		return $pre_option;
	},
	0
);

add_filter(
	'woocommerce_currency',
	static function ( $currency ) use ( $native_payments_trace_log ) {
		++$GLOBALS['native_payments_boot_trace']['currency_filter_count'];

		if ( $GLOBALS['native_payments_boot_trace']['currency_filter_count'] <= 8 ) {
			$native_payments_trace_log( 'woocommerce_currency #' . $GLOBALS['native_payments_boot_trace']['currency_filter_count'] . ' value=' . (string) $currency );
		}

		return $currency;
	},
	0
);

add_filter(
	'gettext',
	static function ( $translation, $text, $domain ) use ( $native_payments_trace_log ) {
		if ( 'woocommerce' !== $domain ) {
			return $translation;
		}

		++$GLOBALS['native_payments_boot_trace']['gettext_count'];

		if ( $GLOBALS['native_payments_boot_trace']['gettext_count'] <= 10 ) {
			$native_payments_trace_log( 'gettext #' . $GLOBALS['native_payments_boot_trace']['gettext_count'] . ' text=' . (string) $text );
		}

		return $translation;
	},
	0,
	3
);

register_shutdown_function(
	static function (): void {
		$error = error_get_last();
		if ( null === $error ) {
			return;
		}

		$output = "[native-payments boot trace] shutdown\n"
			. 'error=' . wp_json_encode( $error ) . "\n"
			. 'current_filter=' . wp_json_encode( $GLOBALS['wp_current_filter'] ?? array() ) . "\n"
			. 'counts=' . wp_json_encode( $GLOBALS['native_payments_boot_trace'] ) . "\n";

		if ( defined( 'STDERR' ) ) {
			fwrite( STDERR, $output );
		}

		error_log( $output );
	}
);
