<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentNotes;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the OrderPaymentNotes class.
 */
class OrderPaymentNotesTest extends WC_Unit_Test_Case {

	/**
	 * Comment-meta key a test provider stores note identities under.
	 */
	private const IDENTITY_META_KEY = '_test_provider_note_identity';

	/**
	 * The System Under Test.
	 *
	 * @var OrderPaymentNotes
	 */
	private OrderPaymentNotes $sut;

	/**
	 * Order the notes are on.
	 *
	 * @var WC_Order
	 */
	private WC_Order $order;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut   = new OrderPaymentNotes();
		$this->order = wc_create_order();
		$this->order->save();
	}

	/**
	 * @testdox A note is found by its identity whatever its text reads.
	 */
	public function test_find_by_identity_finds_the_note_carrying_the_identity(): void {
		$this->order->add_order_note( 'Another note.' );
		$note_id = (int) $this->order->add_order_note( 'Payment note.', 0, false, array( self::IDENTITY_META_KEY => hash( 'sha256', 'payment:1' ) ) );

		$this->assertSame( $note_id, $this->sut->find_by_identity( $this->order, 'payment:1', self::IDENTITY_META_KEY ) );
		$this->assertSame( 0, $this->sut->find_by_identity( $this->order, 'payment:2', self::IDENTITY_META_KEY ) );
		$this->assertSame( 0, $this->sut->find_by_identity( $this->order, 'payment:1', '_other_provider_note_identity' ) );
	}

	/**
	 * @testdox An empty identity or meta key finds no note.
	 */
	public function test_find_by_identity_without_identity_or_key_finds_nothing(): void {
		$this->order->add_order_note( 'Payment note.', 0, false, array( self::IDENTITY_META_KEY => hash( 'sha256', '' ) ) );

		$this->assertSame( 0, $this->sut->find_by_identity( $this->order, '', self::IDENTITY_META_KEY ) );
		$this->assertSame( 0, $this->sut->find_by_identity( $this->order, 'payment:1', '' ) );
	}

	/**
	 * @testdox A note is found by its text or by one of its other renderings.
	 */
	public function test_find_by_content_matches_the_note_or_an_equivalent(): void {
		$note_id = (int) $this->order->add_order_note( 'Plugin rendering.' );

		$this->assertSame( $note_id, $this->sut->find_by_content( $this->order, 'Plugin rendering.' ) );
		$this->assertSame( $note_id, $this->sut->find_by_content( $this->order, 'Core rendering.', array( 'Plugin rendering.' ) ) );
		$this->assertSame( 0, $this->sut->find_by_content( $this->order, 'Core rendering.' ) );
	}

	/**
	 * @testdox Finding a note writes nothing.
	 */
	public function test_finding_a_note_writes_nothing(): void {
		$note_id = (int) $this->order->add_order_note( 'Payment note.', 0, false, array( self::IDENTITY_META_KEY => hash( 'sha256', 'payment:1' ) ) );
		$before  = get_comment_meta( $note_id );

		$this->sut->find_by_identity( $this->order, 'payment:1', self::IDENTITY_META_KEY );
		$this->sut->find_by_content( $this->order, 'Payment note.' );

		$this->assertSame( $before, get_comment_meta( $note_id ) );
	}

	/**
	 * @testdox Recording an identity tags the note once, and the note is then found by it.
	 */
	public function test_record_identity_tags_the_note_once(): void {
		$note_id = (int) $this->order->add_order_note( 'Payment note.' );

		$this->sut->record_identity( $note_id, 'payment:1', self::IDENTITY_META_KEY );
		$this->sut->record_identity( $note_id, 'payment:1', self::IDENTITY_META_KEY );
		$this->sut->record_identity( $note_id, 'payment:2', self::IDENTITY_META_KEY );

		$this->assertSame( array( hash( 'sha256', 'payment:1' ), hash( 'sha256', 'payment:2' ) ), get_comment_meta( $note_id, self::IDENTITY_META_KEY, false ) );
		$this->assertSame( $note_id, $this->sut->find_by_identity( $this->order, 'payment:2', self::IDENTITY_META_KEY ) );
	}

	/**
	 * @testdox Recording an empty identity, under an empty key or on no note writes nothing.
	 */
	public function test_record_identity_without_identity_key_or_note_writes_nothing(): void {
		$note_id = (int) $this->order->add_order_note( 'Payment note.' );

		$this->sut->record_identity( $note_id, '', self::IDENTITY_META_KEY );
		$this->sut->record_identity( $note_id, 'payment:1', '' );
		$this->sut->record_identity( 0, 'payment:1', self::IDENTITY_META_KEY );

		$this->assertSame( array(), get_comment_meta( $note_id ) );
	}
}
