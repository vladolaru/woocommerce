<?php
/**
 * NativePaymentsCliAdapter class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

/**
 * Thin adapter around the global WP-CLI API.
 *
 * @since 11.0.0
 * @internal
 */
class NativePaymentsCliAdapter {

	/**
	 * Tell whether WP-CLI is available.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' );
	}

	/**
	 * Register a WP-CLI command.
	 *
	 * @param string $name    Command name.
	 * @param object $command Command handler.
	 */
	public function add_command( string $name, object $command ): void {
		$callback = array( 'WP_CLI', 'add_command' );
		if ( is_callable( $callback ) ) {
			$callback( $name, $command );
		}
	}

	/**
	 * Write a WP-CLI output line.
	 *
	 * @param string $line Output line.
	 */
	public function line( string $line ): void {
		if ( $this->is_available() ) {
			$callback = array( 'WP_CLI', 'line' );
			if ( is_callable( $callback ) ) {
				$callback( $line );
			}
		}
	}
}
