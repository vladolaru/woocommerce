<?php
/**
 * WooPaymentsAdminNotesController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Throwable;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;

/**
 * Adds the WooPayments inbox notes whose conditions hold on each admin page load: secure checkout, Link by Stripe and the
 * canceled-authorization fee remediation.
 *
 * Ports client 11.1.0 `WC_Payments::add_woo_admin_notes()` (`includes/class-wc-payments.php:374,1595-1621`).
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsAdminNotesController implements RegisterHooksInterface {

	/**
	 * Secure checkout note.
	 *
	 * @var WooPaymentsSetHttpsForCheckoutNote
	 */
	private WooPaymentsSetHttpsForCheckoutNote $https_note;

	/**
	 * Link by Stripe note.
	 *
	 * @var WooPaymentsSetUpLinkNote
	 */
	private WooPaymentsSetUpLinkNote $link_note;

	/**
	 * Canceled-authorization fee remediation note.
	 *
	 * @var WooPaymentsCanceledAuthRemediationNote
	 */
	private WooPaymentsCanceledAuthRemediationNote $canceled_auth_remediation_note;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsSetHttpsForCheckoutNote     $https_note                     Secure checkout note.
	 * @param WooPaymentsSetUpLinkNote               $link_note                      Link by Stripe note.
	 * @param WooPaymentsCanceledAuthRemediationNote $canceled_auth_remediation_note Canceled-authorization fee remediation note.
	 */
	final public function init(
		WooPaymentsSetHttpsForCheckoutNote $https_note,
		WooPaymentsSetUpLinkNote $link_note,
		WooPaymentsCanceledAuthRemediationNote $canceled_auth_remediation_note
	): void {
		$this->https_note                     = $https_note;
		$this->link_note                      = $link_note;
		$this->canceled_auth_remediation_note = $canceled_auth_remediation_note;
	}

	/**
	 * Register the admin note hook.
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'add_woo_admin_notes' ) );
	}

	/**
	 * Add the WooPayments inbox notes whose conditions currently hold.
	 *
	 * AJAX requests are skipped to keep them fast.
	 *
	 * @internal
	 */
	public function add_woo_admin_notes(): void {
		if ( wp_doing_ajax() ) {
			return;
		}

		foreach ( array( $this->https_note, $this->link_note, $this->canceled_auth_remediation_note ) as $note ) {
			try {
				$note->possibly_add_note();
			} catch ( Throwable $exception ) {
				wc_get_container()->get( WooPaymentsLogger::class )->log_throwable( 'Failed to add a WooPayments inbox note.', $exception, array( 'note' => $note::NOTE_NAME ) );
			}
		}
	}
}
