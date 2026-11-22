<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin;

use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Admin\WCAdminHelper;
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
	 * @testdox The daily job keeps a $label of the WooPayments note while the feature is on.
	 *
	 * Catches an unconditional delete of every stored copy while the feature is on, which would remove the merchant's
	 * dismissal or completed action too.
	 *
	 * @dataProvider merchant_handled_payments_note_provider
	 *
	 * @param string $label  Kind of copy.
	 * @param bool   $delete Whether the merchant dismissed the copy.
	 * @param string $status Status the merchant left the copy in.
	 */
	public function test_daily_cleanup_keeps_merchant_handled_payments_note_while_feature_is_on( string $label, bool $delete, string $status ): void {
		unset( $label );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$note = $this->store_visible_payments_note();
		$note->set_is_deleted( $delete );
		$note->set_status( $status );
		$note->save();

		$this->get_events()->delete_inapplicable_notes();

		$stored_note = Notes::get_note( $note->get_id() );
		$this->assertInstanceOf( Note::class, $stored_note );
		$this->assertSame( $delete, $stored_note->get_is_deleted() );
		$this->assertSame( $status, $stored_note->get_status() );
	}

	/**
	 * Copies of the note the merchant has handled.
	 *
	 * @return array<string,array{string,bool,string}>
	 */
	public function merchant_handled_payments_note_provider(): array {
		return array(
			'dismissed copy' => array( 'dismissed copy', true, Note::E_WC_ADMIN_NOTE_UNACTIONED ),
			'completed copy' => array( 'completed copy', false, Note::E_WC_ADMIN_NOTE_ACTIONED ),
		);
	}

	/**
	 * @testdox A dismissed WooPayments note stays dismissed after the cleanup when the feature is turned off again.
	 *
	 * Catches a dismissal that does not survive the cleanup: a cleanup that hard-deletes the dismissed copy would offer
	 * the note again once the feature is off.
	 */
	public function test_dismissed_payments_note_stays_dismissed_when_feature_is_turned_off_after_cleanup(): void {
		$this->make_store_eligible_for_payments_note();
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$note = $this->store_visible_payments_note();
		$note->set_is_deleted( true );
		$note->save();

		$this->get_events()->delete_inapplicable_notes();
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'no' );
		WooCommercePayments::possibly_add_note();

		$this->assertSame( array( (string) $note->get_id() ), array_map( 'strval', Notes::load_data_store()->get_notes_with_name( WooCommercePayments::NOTE_NAME ) ) );
		$this->assertTrue( Notes::get_note( $note->get_id() )->get_is_deleted() );
	}

	/**
	 * @testdox The daily job adds the WooPayments note to an eligible store while the feature is off.
	 *
	 * Catches the note dropped from the daily job's list of notes to add, which would stop it from ever appearing.
	 */
	public function test_daily_job_adds_payments_note_while_feature_is_off(): void {
		$this->make_store_eligible_for_payments_note();
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'no' );
		Notes::delete_notes_with_name( WooCommercePayments::NOTE_NAME );
		$this->http_responder = static function () {
			return new \WP_Error( 'test_http_blocked' );
		};

		$this->get_events()->add_notes();

		$this->assertInstanceOf( Note::class, Notes::get_note_by_name( WooCommercePayments::NOTE_NAME ) );
	}

	/**
	 * Put the store within the WooPayments note's country and age limits, with marketing suggestions allowed.
	 */
	private function make_store_eligible_for_payments_note(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( WCAdminHelper::WC_ADMIN_TIMESTAMP_OPTION, time() - 2 * WEEK_IN_SECONDS );
		update_option( 'woocommerce_show_marketplace_suggestions', 'yes' );
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
	 * Get the daily events runner with its add and cleanup methods exposed.
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
			 * Run the daily add for notes.
			 */
			public function add_notes(): void {
				$this->possibly_add_notes();
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
