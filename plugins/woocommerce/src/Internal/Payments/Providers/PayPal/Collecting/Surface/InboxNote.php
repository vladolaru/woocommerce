<?php
/**
 * InboxNote class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface;

use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\NoteTraits;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Admin\Notes\NotesUnavailableException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletBootstrap;

defined( 'ABSPATH' ) || exit;

/**
 * The Inbox note that asks the merchant to set up PayPal Wallet once an order was paid with it.
 *
 * Added once, when the first wallet order arrives or, for a store that already has one, on the next admin request. It is
 * marked actioned when the store is connected, first-party or through the platform.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class InboxNote {
	use NoteTraits;

	/**
	 * Name of the note for use in the database.
	 *
	 * @since 11.3.0
	 */
	public const NOTE_NAME = 'wc-paypal-wallet-setup-required';

	/**
	 * Get the note.
	 *
	 * @since 11.3.0
	 *
	 * @return Note
	 */
	public static function get_note() {
		$note = new Note();
		$note->set_title( SetUpPayPalWalletTask::title_text() );
		$note->set_content( SetUpPayPalWalletTask::content_text() );
		$note->set_content_data( (object) array() );
		$note->set_type( Note::E_WC_ADMIN_NOTE_WARNING );
		$note->set_name( self::NOTE_NAME );
		$note->set_source( 'woocommerce-admin' );
		$note->add_action( 'complete-setup', __( 'Complete setup', 'woocommerce' ), PayPalWalletBootstrap::get_settings_url() );

		return $note;
	}

	/**
	 * Whether the note should exist: a wallet order exists and the store is not connected yet.
	 *
	 * @since 11.3.0
	 *
	 * @return bool
	 */
	public static function is_applicable() {
		return ( new Options() )->first_order_id() > 0 && ! self::is_connected();
	}

	/**
	 * Add the note, once, when it applies.
	 *
	 * The note-state option records that the note was added, so a later call runs no query. Until it is set, the notes
	 * table is asked once whether a note of that name already exists; one left actioned by an earlier connection is
	 * brought back, because the note applies only to a store that is not connected.
	 *
	 * @since 11.3.0
	 */
	public static function possibly_add(): void {
		if ( ! self::is_applicable() || '' !== ( new Options() )->note_state() ) {
			return;
		}

		try {
			$existing = Notes::get_note_by_name( self::NOTE_NAME );
			if ( ! $existing instanceof Note ) {
				self::possibly_add_note();
			} elseif ( Note::E_WC_ADMIN_NOTE_ACTIONED === $existing->get_status() ) {
				// The note applies, so the store is not connected: an actioned note is left from an earlier connection.
				$existing->set_status( Note::E_WC_ADMIN_NOTE_UNACTIONED );
				$existing->save();
			}
		} catch ( NotesUnavailableException $exception ) {
			return;
		}

		update_option( Options::NOTE_STATE, Options::NOTE_ADDED, true );
	}

	/**
	 * Mark the note actioned once the store is connected.
	 *
	 * Does nothing, and runs no query, unless the note-state option says a note waits and the store is connected.
	 *
	 * @since 11.3.0
	 */
	public static function possibly_action(): void {
		if ( Options::NOTE_ADDED !== ( new Options() )->note_state() || ! self::is_connected() ) {
			return;
		}

		try {
			$note = Notes::get_note_by_name( self::NOTE_NAME );
			if ( $note instanceof Note && Note::E_WC_ADMIN_NOTE_ACTIONED !== $note->get_status() ) {
				$note->set_status( Note::E_WC_ADMIN_NOTE_ACTIONED );
				$note->save();
			}
		} catch ( NotesUnavailableException $exception ) {
			return;
		}

		update_option( Options::NOTE_STATE, Options::NOTE_ACTIONED, true );
	}

	/**
	 * Whether the store is connected, first-party or through the platform.
	 *
	 * @return bool
	 */
	private static function is_connected(): bool {
		return in_array( ( new ConnectionState() )->resolve(), array( ConnectionState::CONNECTED, ConnectionState::PLATFORM_CONNECTED ), true );
	}
}
