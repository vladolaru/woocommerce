<?php
/**
 * Local-only trace probe for WooCommerce textdomain early-loading notices.
 */

add_action(
	'doing_it_wrong_run',
	static function ( $function_name, $message, $version ): void {
		if ( '_load_textdomain_just_in_time' !== $function_name || false === strpos( $message, 'woocommerce' ) ) {
			return;
		}

		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
		$lines  = array();

		foreach ( array_slice( $frames, 0, 24 ) as $index => $frame ) {
			$callable = ( $frame['class'] ?? '' ) . ( $frame['type'] ?? '' ) . ( $frame['function'] ?? '' );
			$file     = $frame['file'] ?? '<internal>';
			$line     = $frame['line'] ?? 0;
			$lines[]  = sprintf( '#%02d %s:%d %s', $index, $file, $line, $callable );
		}

		$output = "[native-payments trace] {$function_name} {$version}\n{$message}\n" . implode( "\n", $lines ) . "\n";

		if ( defined( 'STDERR' ) ) {
			fwrite( STDERR, $output );
		}

		error_log( $output );
	},
	1,
	3
);
