<?php
/**
 * WooPaymentsAdminNotesController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Throwable;

/**
 * Adds the WooPayments inbox notes that the client evaluates on every admin page load.
 *
 * Ports client 11.1.0 `WC_Payments::add_woo_admin_notes()` (`includes/class-wc-payments.php:374,1595-1621`).
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsAdminNotesController implements RegisterHooksInterface {

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * Secure checkout note.
	 *
	 * @var WooPaymentsSetHttpsForCheckoutNote
	 */
	private WooPaymentsSetHttpsForCheckoutNote $https_note;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter       $arbiter    Runtime owner arbiter.
	 * @param WooPaymentsSetHttpsForCheckoutNote $https_note Secure checkout note.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter, WooPaymentsSetHttpsForCheckoutNote $https_note ): void {
		$this->arbiter    = $arbiter;
		$this->https_note = $https_note;
	}

	/**
	 * Register the admin note hook.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		add_action( 'admin_init', array( $this, 'add_woo_admin_notes' ) );
	}

	/**
	 * Add the WooPayments inbox notes whose conditions currently hold.
	 *
	 * AJAX requests are skipped to keep them fast, as in the client.
	 *
	 * @internal
	 */
	public function add_woo_admin_notes(): void {
		if ( wp_doing_ajax() ) {
			return;
		}

		try {
			$this->https_note->possibly_add_note();
		} catch ( Throwable $exception ) {
			$this->log_exception( $exception );
		}
	}

	/**
	 * Log a note failure without breaking the admin page.
	 *
	 * @param Throwable $exception Failure.
	 */
	private function log_exception( Throwable $exception ): void {
		wc_get_logger()->error(
			'Failed to add a native WooPayments inbox note.',
			array(
				'source'    => 'woopayments',
				'exception' => $exception->getMessage(),
			)
		);
	}
}
