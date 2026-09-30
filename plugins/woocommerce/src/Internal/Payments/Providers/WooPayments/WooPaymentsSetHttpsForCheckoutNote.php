<?php
/**
 * WooPaymentsSetHttpsForCheckoutNote class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Admin\Notes\DataStore as NotesDataStore;
use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;

/**
 * Inbox note asking the merchant to serve checkout over HTTPS.
 *
 * Ports client 11.1.0 `includes/notes/class-wc-payments-notes-set-https-for-checkout.php`.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsSetHttpsForCheckoutNote {

	/**
	 * Note name.
	 *
	 * @var string
	 */
	public const NOTE_NAME = 'wc-payments-notes-set-https-for-checkout';

	/**
	 * Documentation URL of the note action.
	 *
	 * @var string
	 */
	public const NOTE_DOCUMENTATION_URL = 'https://woocommerce.com/document/ssl-and-https/#woocommerce-force-ssl-setting';

	/**
	 * Add the note when HTTPS is neither forced on checkout nor used by the site.
	 *
	 * @return void
	 */
	public function possibly_add_note(): void {
		if ( 'yes' === get_option( 'woocommerce_force_ssl_checkout' ) || wc_site_is_https() ) {
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
		$note->set_title( __( 'Enable secure checkout', 'woocommerce' ) );
		$note->set_content( __( 'Enable HTTPS on your checkout pages to display all available payment methods and protect your customers data.', 'woocommerce' ) );
		$note->set_content_data( (object) array() );
		$note->set_type( Note::E_WC_ADMIN_NOTE_INFORMATIONAL );
		$note->set_name( self::NOTE_NAME );
		$note->set_source( 'woocommerce-payments' );
		$note->add_action(
			self::NOTE_NAME,
			__( 'Read more', 'woocommerce' ),
			self::NOTE_DOCUMENTATION_URL,
			Note::E_WC_ADMIN_NOTE_UNACTIONED,
			true
		);

		return $note;
	}
}
