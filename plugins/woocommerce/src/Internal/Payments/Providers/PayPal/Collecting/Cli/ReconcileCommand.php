<?php
/**
 * ReconcileCommand class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Cli;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Reconcile\Reconciler;
use WP_CLI;

/**
 * The `wp wc paypal-wallet reconcile` command: settle the held PayPal wallet orders and check onboarding now.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class ReconcileCommand {

	/**
	 * The reconciler.
	 *
	 * @var Reconciler
	 */
	private Reconciler $reconciler;

	/**
	 * Constructor.
	 *
	 * @param Reconciler $reconciler The reconciler.
	 */
	public function __construct( Reconciler $reconciler ) {
		$this->reconciler = $reconciler;
	}

	/**
	 * Read each held PayPal wallet order's capture from PayPal and settle it, then complete onboarding when PayPal reports it complete.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wc paypal-wallet reconcile
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function reconcile( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );

		foreach ( $this->describe( $this->reconciler->run() ) as $line ) {
			WP_CLI::line( $line ); // @phpstan-ignore class.notFound (WP-CLI is not installed when PHPStan runs.)
		}
		WP_CLI::success( 'Reconciled the PayPal wallet.' ); // @phpstan-ignore class.notFound (WP-CLI is not installed when PHPStan runs.)
	}

	/**
	 * The summary as lines: the order IDs by outcome, and the onboarding outcome.
	 *
	 * @param array $summary The reconcile summary.
	 * @return string[]
	 */
	public function describe( array $summary ): array {
		$lines = array();
		foreach ( $summary as $outcome => $value ) {
			$lines[] = ucfirst( (string) $outcome ) . ': ' . ( is_array( $value ) ? ( array() === $value ? 'none' : implode( ', ', array_map( 'strval', $value ) ) ) : (string) $value );
		}

		return $lines;
	}
}
