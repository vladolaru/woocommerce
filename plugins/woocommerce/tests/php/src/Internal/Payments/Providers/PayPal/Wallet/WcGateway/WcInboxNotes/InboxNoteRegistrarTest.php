<?php
/**
 * Tests for the inbox note registrar.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcInboxNotes
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcInboxNotes;

use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcInboxNotes\InboxNote;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcInboxNotes\InboxNoteAction;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcInboxNotes\InboxNoteInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcInboxNotes\InboxNoteRegistrar;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The registrar keeps the WooCommerce inbox in step with the configured notes: it adds a note that is enabled and
 * missing, removes one that is disabled, and does nothing during AJAX requests. The notes are real WooCommerce Admin
 * notes, and the one the tests use is deleted again.
 *
 * @group paypal-wallet
 */
class InboxNoteRegistrarTest extends WalletTestCase {

	private const NOTE_NAME = 'ppcp-test-inbox-note';
	private const BASE_NAME = 'woocommerce-paypal-payments/woocommerce-paypal-payments.php';

	/**
	 * Start without a note of the test's name.
	 */
	public function setUp(): void {
		parent::setUp();

		Notes::delete_notes_with_name( self::NOTE_NAME );
	}

	/**
	 * Delete the note the test created.
	 */
	public function tearDown(): void {
		try {
			Notes::delete_notes_with_name( self::NOTE_NAME );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * A configured note.
	 *
	 * @param bool $is_enabled Whether the note is enabled.
	 * @return InboxNote
	 */
	private function make_note( bool $is_enabled ): InboxNote {
		return new InboxNote(
			'Working capital',
			'Content of the note.',
			Note::E_WC_ADMIN_NOTE_INFORMATIONAL,
			self::NOTE_NAME,
			Note::E_WC_ADMIN_NOTE_UNACTIONED,
			$is_enabled,
			new InboxNoteAction( 'learn_more', 'Learn More', 'https://example.com/learn-more', Note::E_WC_ADMIN_NOTE_UNACTIONED, true )
		);
	}

	/**
	 * Store a note of the test's name, the way an earlier request would have.
	 */
	private function store_existing_note(): void {
		$note = new Note();
		$note->set_title( 'Stored title' );
		$note->set_content( 'Stored content.' );
		$note->set_type( Note::E_WC_ADMIN_NOTE_INFORMATIONAL );
		$note->set_name( self::NOTE_NAME );
		$note->set_source( self::BASE_NAME );
		$note->save();
	}

	/**
	 * The notes of the test's name.
	 *
	 * @return int[] The note IDs.
	 */
	private function stored_note_ids(): array {
		return Notes::load_data_store()->get_notes_with_name( self::NOTE_NAME );
	}

	/**
	 * GIVEN the current request is a WordPress AJAX request
	 * WHEN the inbox notes are registered
	 * THEN registration exits early and no configured note is inspected or persisted.
	 *
	 * @testdox Should skip the notes during an AJAX request.
	 */
	public function test_register_skips_notes_during_ajax_request(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );

		$inbox_note = $this->mock( InboxNoteInterface::class );
		$inbox_note->shouldNotReceive( 'name' );
		$inbox_note->shouldNotReceive( 'is_enabled' );

		( new InboxNoteRegistrar( array( $inbox_note ), self::BASE_NAME ) )->register();

		$this->assertSame( array(), $this->stored_note_ids(), 'No note should be stored' );
	}

	/**
	 * GIVEN a non-AJAX admin request with a note that already exists and is enabled
	 * WHEN the inbox notes are registered
	 * THEN the existing note is looked up and processing short-circuits without creating a new note.
	 *
	 * @testdox Should leave an enabled note that already exists alone.
	 */
	public function test_register_leaves_an_existing_enabled_note_alone(): void {
		$this->store_existing_note();
		$existing_ids = $this->stored_note_ids();
		$this->assertCount( 1, $existing_ids, 'The note should exist before the registration' );

		$inbox_note = $this->mock( InboxNoteInterface::class );
		$inbox_note->shouldReceive( 'name' )->once()->andReturn( self::NOTE_NAME );
		$inbox_note->shouldReceive( 'is_enabled' )->andReturn( true );
		$inbox_note->shouldNotReceive( 'title' );

		( new InboxNoteRegistrar( array( $inbox_note ), self::BASE_NAME ) )->register();

		$this->assertSame( $existing_ids, $this->stored_note_ids(), 'The stored note should be the same one, and no second note should exist' );
		$this->assertSame( 'Stored title', Notes::get_note_by_name( self::NOTE_NAME )->get_title(), 'The stored note should not be rewritten' );
	}

	/**
	 * @testdox Should store an enabled note that does not exist yet, with its content, source and action.
	 */
	public function test_register_stores_a_missing_enabled_note(): void {
		( new InboxNoteRegistrar( array( $this->make_note( true ) ), self::BASE_NAME ) )->register();

		$this->assertCount( 1, $this->stored_note_ids() );
		$note = Notes::get_note_by_name( self::NOTE_NAME );
		$this->assertInstanceOf( Note::class, $note );
		$this->assertSame( 'Working capital', $note->get_title() );
		$this->assertSame( 'Content of the note.', $note->get_content() );
		$this->assertSame( Note::E_WC_ADMIN_NOTE_INFORMATIONAL, $note->get_type() );
		$this->assertSame( Note::E_WC_ADMIN_NOTE_UNACTIONED, $note->get_status() );
		$this->assertSame( self::BASE_NAME, $note->get_source() );

		$actions = $note->get_actions();
		$this->assertCount( 1, $actions );
		$this->assertSame( 'learn_more', $actions[0]->name );
		$this->assertSame( 'Learn More', $actions[0]->label );
		$this->assertSame( 'https://example.com/learn-more', $actions[0]->query );
		$this->assertSame( Note::E_WC_ADMIN_NOTE_UNACTIONED, $actions[0]->status );
	}

	/**
	 * @testdox Should delete a note that is disabled while it is stored in the inbox.
	 */
	public function test_register_deletes_a_disabled_note(): void {
		$this->store_existing_note();
		$this->assertCount( 1, $this->stored_note_ids(), 'The note should exist before the registration' );

		( new InboxNoteRegistrar( array( $this->make_note( false ) ), self::BASE_NAME ) )->register();

		$this->assertSame( array(), $this->stored_note_ids() );
	}

	/**
	 * @testdox Should store nothing for a disabled note that is not in the inbox.
	 */
	public function test_register_stores_no_disabled_note(): void {
		( new InboxNoteRegistrar( array( $this->make_note( false ) ), self::BASE_NAME ) )->register();

		$this->assertSame( array(), $this->stored_note_ids() );
	}
}
