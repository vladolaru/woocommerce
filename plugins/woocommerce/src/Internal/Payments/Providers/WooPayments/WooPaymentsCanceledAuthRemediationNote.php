<?php
/**
 * WooPaymentsCanceledAuthRemediationNote class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Admin\Notes\DataStore as NotesDataStore;
use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;

/**
 * Inbox note pointing merchants with affected orders to the canceled-authorization fee remediation tool.
 *
 * Ports client 11.1.0 `includes/notes/class-wc-payments-notes-canceled-auth-remediation.php`.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsCanceledAuthRemediationNote {

	/**
	 * Note name.
	 *
	 * @var string
	 */
	public const NOTE_NAME = 'wc-payments-notes-canceled-auth-remediation';

	/**
	 * WooCommerce Tools page, relative to the admin URL.
	 *
	 * @var string
	 */
	public const NOTE_TOOLS_URL = 'admin.php?page=wc-status&tab=tools';

	/**
	 * Canceled-authorization fee remediation service.
	 *
	 * @var WooPaymentsCanceledAuthorizationFeeRemediationService
	 */
	private WooPaymentsCanceledAuthorizationFeeRemediationService $remediation_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsCanceledAuthorizationFeeRemediationService $remediation_service Remediation service.
	 */
	final public function init( WooPaymentsCanceledAuthorizationFeeRemediationService $remediation_service ): void {
		$this->remediation_service = $remediation_service;
	}

	/**
	 * Add the note when the background check found affected orders and remediation has not run.
	 *
	 * The first call schedules the background check and adds nothing; a later admin page load adds the note once the
	 * check has recorded its result.
	 *
	 * @return void
	 */
	public function possibly_add_note(): void {
		if ( $this->remediation_service->is_complete() ) {
			return;
		}

		// The client checks for a running batch before reading this state; reading the state first skips the
		// Action Scheduler query for the states that can never add a note, with the same outcome.
		$state = get_option( WooPaymentsCanceledAuthorizationFeeRemediationService::CHECK_STATE_OPTION_KEY );
		if ( false !== $state && 'has_affected_orders' !== $state ) {
			return;
		}

		if ( $this->remediation_service->is_remediation_running() ) {
			return;
		}

		if ( false === $state ) {
			$this->remediation_service->schedule_affected_orders_check();
			return;
		}

		/**
		 * Notes data store.
		 *
		 * @var NotesDataStore $data_store
		 */
		$data_store = Notes::load_data_store();
		if ( ! empty( $data_store->get_notes_with_name( self::NOTE_NAME ) ) ) {
			return;
		}

		$this->get_note()->save();
	}

	/**
	 * Build the note.
	 *
	 * @return Note
	 */
	public function get_note(): Note {
		$note = new Note();
		$note->set_title( __( 'WooPayments: Fix incorrect order data', 'woocommerce' ) );
		$note->set_content( __( 'Some orders with canceled payment authorizations have incorrect data that may cause negative values in your WooCommerce Analytics. This affects stores using manual capture (authorize and capture separately). Run the fix tool to correct this.', 'woocommerce' ) );
		$note->set_content_data( (object) array() );
		$note->set_type( Note::E_WC_ADMIN_NOTE_WARNING );
		$note->set_name( self::NOTE_NAME );
		$note->set_source( 'woocommerce-payments' );
		$note->add_action(
			'run-remediation-tool',
			__( 'Go to Tools page', 'woocommerce' ),
			admin_url( self::NOTE_TOOLS_URL ),
			Note::E_WC_ADMIN_NOTE_ACTIONED,
			false
		);

		return $note;
	}
}
