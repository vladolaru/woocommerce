<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface;

use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface\InboxNote;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletBootstrap;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Tests for the Inbox note that asks the merchant to set up PayPal Wallet.
 *
 * @group paypal-wallet
 */
class InboxNoteTest extends WalletTestCase {
	use HoldsWalletState;

	/**
	 * Remove the note again; the notes table is not part of the rolled-back state on every setup.
	 */
	public function tearDown(): void {
		try {
			InboxNote::possibly_delete_note();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * The notes saved under the note's name.
	 *
	 * @return int[]
	 */
	private function note_ids(): array {
		return Notes::load_data_store()->get_notes_with_name( InboxNote::NOTE_NAME );
	}

	/**
	 * @testdox Should name the note wc-paypal-wallet-setup-required and carry the task's text and one Complete setup action to the settings URL.
	 */
	public function test_the_note_carries_the_copy_and_the_action(): void {
		$note = InboxNote::get_note();

		$this->assertInstanceOf( Note::class, $note );
		$this->assertSame( 'wc-paypal-wallet-setup-required', $note->get_name() );
		$this->assertSame( 'Set up PayPal Wallet', $note->get_title() );
		$this->assertSame( 'You received an order paid with PayPal Wallet. Connect PayPal Wallet to receive the payment.', $note->get_content() );
		$this->assertSame( Note::E_WC_ADMIN_NOTE_WARNING, $note->get_type() );

		$actions = $note->get_actions();
		$this->assertCount( 1, $actions );
		$this->assertSame( 'complete-setup', $actions[0]->name );
		$this->assertSame( 'Complete setup', $actions[0]->label );
		$this->assertSame( PayPalWalletBootstrap::get_settings_url(), $actions[0]->query );
	}

	/**
	 * @testdox Should add the note once, however often it is asked to.
	 */
	public function test_adds_the_note_once(): void {
		$this->set_collecting();
		$this->set_first_order( 7 );

		InboxNote::possibly_add();
		InboxNote::possibly_add();

		$this->assertCount( 1, $this->note_ids() );
		$this->assertSame( Note::E_WC_ADMIN_NOTE_UNACTIONED, Notes::get_note_by_name( InboxNote::NOTE_NAME )->get_status() );
	}

	/**
	 * @testdox Should not add the note without a first order, or for a first-party connected store.
	 * @testWith ["no_first_order"]
	 *           ["first_party"]
	 *
	 * @param string $scenario The scenario.
	 */
	public function test_does_not_add_the_note_when_nothing_is_owed( string $scenario ): void {
		if ( 'first_party' === $scenario ) {
			$this->set_first_party_connected();
			$this->set_first_order( 7 );
		}

		InboxNote::possibly_add();

		$this->assertSame( array(), $this->note_ids() );
	}

	/**
	 * @testdox Should action the note once the store is connected through the platform or first-party, and leave it while collecting.
	 * @testWith ["collecting", "unactioned"]
	 *           ["platform", "actioned"]
	 *           ["first_party", "actioned"]
	 *
	 * @param string $state    The connection state.
	 * @param string $expected The note's status afterwards.
	 */
	public function test_actions_the_note_when_setup_completes( string $state, string $expected ): void {
		$this->set_collecting();
		$this->set_first_order( 7 );
		InboxNote::possibly_add();
		if ( 'platform' === $state ) {
			$this->set_platform_connected();
		} elseif ( 'first_party' === $state ) {
			$this->set_first_party_connected();
		}

		InboxNote::possibly_action();

		$this->assertSame( $expected, Notes::get_note_by_name( InboxNote::NOTE_NAME )->get_status() );
	}

	/**
	 * @testdox Should do nothing when the note was never added.
	 */
	public function test_actioning_without_a_note_does_nothing(): void {
		$this->set_platform_connected();

		InboxNote::possibly_action();

		$this->assertSame( array(), $this->note_ids() );
	}

	/**
	 * The notes-table queries that run while a callback does its work.
	 *
	 * @param callable $work The work.
	 * @return int
	 */
	private function notes_queries_during( callable $work ): int {
		global $wpdb;
		$count  = 0;
		$filter = static function ( $sql ) use ( &$count, $wpdb ) {
			if ( false !== strpos( (string) $sql, $wpdb->prefix . 'wc_admin_notes' ) ) {
				++$count;
			}
			return $sql;
		};
		add_filter( 'query', $filter );
		try {
			$work();
		} finally {
			remove_filter( 'query', $filter );
		}

		return $count;
	}

	/**
	 * @testdox Should record that the note was added, and then add, check and action without any notes query until the store connects.
	 */
	public function test_the_note_state_option_spares_the_notes_table(): void {
		$this->set_collecting();
		$this->set_first_order( 7 );

		InboxNote::possibly_add();

		$this->assertSame( Options::NOTE_ADDED, ( new Options() )->note_state() );
		$this->assertSame(
			0,
			$this->notes_queries_during(
				static function (): void {
					InboxNote::possibly_add();
					InboxNote::possibly_action();
				}
			),
			'While collecting, a note that was added needs no query'
		);

		$this->set_platform_connected();
		InboxNote::possibly_action();

		$this->assertSame( Options::NOTE_ACTIONED, ( new Options() )->note_state() );
		$this->assertSame(
			0,
			$this->notes_queries_during(
				static function (): void {
					InboxNote::possibly_add();
					InboxNote::possibly_action();
				}
			),
			'Once actioned, nothing queries the notes table'
		);
	}

	/**
	 * @testdox Should do nothing, and run no query, when connected with no note ever added.
	 */
	public function test_actioning_without_a_recorded_note_runs_no_query(): void {
		$this->set_platform_connected();
		$this->set_first_order( 7 );

		$queries = $this->notes_queries_during(
			static function (): void {
				InboxNote::possibly_action();
			}
		);

		$this->assertSame( 0, $queries );
		$this->assertSame( '', ( new Options() )->note_state() );
	}
}
