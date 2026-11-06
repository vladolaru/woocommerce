<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentNotes;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface;
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
	 * Vocabulary of a provider that keeps note identities under IDENTITY_META_KEY.
	 *
	 * @var ProviderPersistenceVocabularyInterface
	 */
	private ProviderPersistenceVocabularyInterface $vocabulary;

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
		$this->sut        = new OrderPaymentNotes();
		$this->vocabulary = $this->vocabulary_with_key( self::IDENTITY_META_KEY );
		$this->order      = wc_create_order();
		$this->order->save();
	}

	/**
	 * @testdox A note is found by its identity whatever its text reads.
	 */
	public function test_find_by_identity_finds_the_note_carrying_the_identity(): void {
		$this->order->add_order_note( 'Another note.' );
		$note_id = (int) $this->order->add_order_note( 'Payment note.', 0, false, array( self::IDENTITY_META_KEY => hash( 'sha256', 'payment:1' ) ) );

		$this->assertSame( $note_id, $this->sut->find_by_identity( $this->order, 'payment:1', $this->vocabulary ) );
		$this->assertSame( 0, $this->sut->find_by_identity( $this->order, 'payment:2', $this->vocabulary ) );
		$this->assertSame( 0, $this->sut->find_by_identity( $this->order, 'payment:1', $this->vocabulary_with_key( '_other_provider_note_identity' ) ) );
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
	 * @testdox Recording an identity tags the note once, and the note is then found by it.
	 */
	public function test_record_identity_tags_the_note_once(): void {
		$note_id = (int) $this->order->add_order_note( 'Payment note.' );

		$this->sut->record_identity( $note_id, 'payment:1', $this->vocabulary );
		$this->sut->record_identity( $note_id, 'payment:1', $this->vocabulary );
		$this->sut->record_identity( $note_id, 'payment:2', $this->vocabulary );

		$this->assertSame( array( hash( 'sha256', 'payment:1' ), hash( 'sha256', 'payment:2' ) ), get_comment_meta( $note_id, self::IDENTITY_META_KEY, false ) );
		$this->assertSame( $note_id, $this->sut->find_by_identity( $this->order, 'payment:2', $this->vocabulary ) );
	}

	/**
	 * @testdox A note added with an identity carries its hash and is then found by it.
	 */
	public function test_add_writes_the_note_with_its_identity(): void {
		$note_id = $this->sut->add( $this->order, 'Payment note.', 'payment:1', $this->vocabulary );

		$this->assertGreaterThan( 0, $note_id );
		$this->assertSame( 'Payment note.', wc_get_order_note( $note_id )->content );
		$this->assertSame( array( hash( 'sha256', 'payment:1' ) ), get_comment_meta( $note_id, self::IDENTITY_META_KEY, false ) );
		$this->assertSame( $note_id, $this->sut->find_by_identity( $this->order, 'payment:1', $this->vocabulary ) );
	}

	/**
	 * @testdox An empty identity or meta key means no identity: nothing is found by it, recorded, or added with the note.
	 *
	 * @testWith ["", "_test_provider_note_identity"]
	 *           ["payment:1", ""]
	 *
	 * @param string $identity          Note identity.
	 * @param string $identity_meta_key Identity meta key.
	 */
	public function test_empty_identity_or_key_means_no_identity( string $identity, string $identity_meta_key ): void {
		$vocabulary = $this->vocabulary_with_key( $identity_meta_key );
		$this->order->add_order_note( 'Tagged note.', 0, false, array( self::IDENTITY_META_KEY => hash( 'sha256', $identity ) ) );
		$plain_id = (int) $this->order->add_order_note( 'Plain note.' );

		$this->assertSame( 0, $this->sut->find_by_identity( $this->order, $identity, $vocabulary ), 'No note is found by an empty identity, even one tagged with its hash.' );

		$this->sut->record_identity( $plain_id, $identity, $vocabulary );
		$this->assertSame( array(), get_comment_meta( $plain_id ), 'No identity is recorded.' );

		$note_id = $this->sut->add( $this->order, 'Payment note.', $identity, $vocabulary );
		$this->assertGreaterThan( 0, $note_id );
		$this->assertSame( array(), get_comment_meta( $note_id ), 'The note is added without an identity.' );
	}

	/**
	 * Build a provider vocabulary that keeps note identities under a key.
	 *
	 * @param string $identity_meta_key Note identity meta key, or '' for none.
	 * @return ProviderPersistenceVocabularyInterface
	 */
	private function vocabulary_with_key( string $identity_meta_key ): ProviderPersistenceVocabularyInterface {
		$vocabulary = $this->createMock( ProviderPersistenceVocabularyInterface::class );
		$vocabulary->method( 'get_note_identity_meta_key' )->willReturn( $identity_meta_key );

		return $vocabulary;
	}
}
