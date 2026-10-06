<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin;

use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Internal\Admin\Events;
use WC_Unit_Test_Case;

/**
 * Tests for the Events class.
 */
class EventsTest extends WC_Unit_Test_Case {

	/**
	 * @testdox The daily cleanup deletes the stored inbox notes of the removed WooPayments promotion surfaces: $note_name.
	 * @testWith ["wc-admin-woocommerce-payments"]
	 *           ["wc-admin-payments-remind-me-later"]
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

		$events = new class() extends Events {
			/**
			 * Make the protected constructor reachable from the test.
			 */
			public function __construct() { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- Widens the parent's protected constructor.
				parent::__construct();
			}

			/**
			 * Run the deprecated-notes cleanup.
			 */
			public function delete_deprecated_notes(): void {
				$this->possibly_delete_deprecated_notes();
			}
		};
		$events->delete_deprecated_notes();

		$this->assertFalse( Notes::get_note_by_name( $note_name ) );
	}
}
