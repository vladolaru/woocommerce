<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin;

use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Internal\Admin\Events;
use Automattic\WooCommerce\Internal\Admin\Notes\WooCommercePayments;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use WC_Unit_Test_Case;

/**
 * Tests for the Events class.
 */
class EventsTest extends WC_Unit_Test_Case {

	/**
	 * @testdox The daily cleanup deletes the stored inbox notes of the removed WooPayments promotion surfaces: $note_name.
	 * @testWith ["wc-admin-payments-remind-me-later"]
	 *           ["wc-admin-payments-more-info-needed"]
	 *
	 * The two payments notes came from PaymentsRemindMeLater and PaymentsMoreInfoNeeded, whose classes, cleanup calls and
	 * the remind-me-later note's wc-pay-welcome-page link target were removed; a stored copy would stay in the inbox.
	 *
	 * @param string $note_name Stored note name.
	 */
	public function test_daily_cleanup_deletes_removed_payments_notes( string $note_name ): void {
		$note = new Note();
		$note->set_name( $note_name );
		$note->set_title( 'Removed payments note' );
		$note->set_content( 'Content of a note whose surface was removed.' );
		$note->save();
		$this->assertNotFalse( Notes::get_note_by_name( $note_name ), 'The note is stored before the cleanup.' );

		$this->get_events()->delete_deprecated_notes();

		$this->assertFalse( Notes::get_note_by_name( $note_name ) );
	}

	/**
	 * @testdox The daily job deletes a visible WooPayments note while the feature is on.
	 */
	public function test_daily_cleanup_deletes_visible_payments_note_while_feature_is_on(): void {
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$this->store_visible_payments_note();

		$this->get_events()->delete_inapplicable_notes();

		$this->assertFalse( Notes::get_note_by_name( WooCommercePayments::NOTE_NAME ) );
	}

	/**
	 * @testdox The daily job keeps the visible WooPayments note while the feature is off.
	 */
	public function test_daily_cleanup_keeps_visible_payments_note_while_feature_is_off(): void {
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'no' );
		$note = $this->store_visible_payments_note();

		$events = $this->get_events();
		$events->delete_inapplicable_notes();
		$events->delete_deprecated_notes();

		$stored_note = Notes::get_note_by_name( WooCommercePayments::NOTE_NAME );
		$this->assertInstanceOf( Note::class, $stored_note );
		$this->assertSame( $note->get_id(), $stored_note->get_id() );
	}

	/**
	 * Store a visible copy regardless of whether the extension's files are present.
	 *
	 * @return Note
	 */
	private function store_visible_payments_note(): Note {
		Notes::delete_notes_with_name( WooCommercePayments::NOTE_NAME );
		$note = WooCommercePayments::get_note();
		$note->set_status( Note::E_WC_ADMIN_NOTE_UNACTIONED );
		$note->save();
		$this->assertInstanceOf( Note::class, Notes::get_note_by_name( WooCommercePayments::NOTE_NAME ) );
		return $note;
	}

	/**
	 * Get the daily events runner with both cleanup methods exposed.
	 *
	 * @return object
	 */
	private function get_events(): object {
		return new class() extends Events {
			/**
			 * Make the protected constructor reachable from the test.
			 */
			public function __construct() { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- Widens the parent's protected constructor.
				parent::__construct();
			}

			/**
			 * Run the cleanup for notes that no longer apply.
			 */
			public function delete_inapplicable_notes(): void {
				$this->possibly_delete_notes();
			}

			/**
			 * Run the deprecated-notes cleanup.
			 */
			public function delete_deprecated_notes(): void {
				$this->possibly_delete_deprecated_notes();
			}
		};
	}
}
