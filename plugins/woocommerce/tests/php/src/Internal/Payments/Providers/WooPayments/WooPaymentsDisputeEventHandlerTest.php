<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDisputeCacheService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDisputeEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLegacyRuntime;
use ReflectionClass;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsDisputeEventHandler class.
 */
class WooPaymentsDisputeEventHandlerTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsDisputeEventHandler
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new WooPaymentsDisputeEventHandler();
		$this->sut->init(
			wc_get_container()->get( WooPaymentsLegacyRuntime::class ),
			new class() extends WooPaymentsApiClient {},
			wc_get_container()->get( WooPaymentsDisputeCacheService::class )
		);
	}

	/**
	 * Invoke a private method on the System Under Test.
	 *
	 * @param string       $method Method name.
	 * @param array<mixed> $args   Method arguments.
	 * @return mixed
	 */
	private function invoke_private( string $method, array $args ) {
		$reflection = new ReflectionClass( $this->sut );
		$method     = $reflection->getMethod( $method );
		$method->setAccessible( true );

		return $method->invokeArgs( $this->sut, $args );
	}

	/**
	 * @testdox Should escape the webhook dispute status in a closed dispute note.
	 */
	public function test_dispute_closed_note_escapes_status(): void {
		$note = $this->invoke_private(
			'get_dispute_closed_note',
			array( 'ch_123', '<script>alert(1)</script>', false, 'txn_1' )
		);

		$this->assertStringNotContainsString( '<script>', $note, 'The raw script tag must not appear in the closed dispute note.' );
		$this->assertStringContainsString( '&lt;script&gt;', $note, 'The status must be HTML-escaped in the closed dispute note.' );
	}

	/**
	 * @testdox Should escape the webhook dispute status in a closed inquiry note.
	 */
	public function test_dispute_closed_inquiry_note_escapes_status(): void {
		$note = $this->invoke_private(
			'get_dispute_closed_note',
			array( 'ch_123', '<script>alert(1)</script>', true, 'txn_1' )
		);

		$this->assertStringNotContainsString( '<script>', $note, 'The raw script tag must not appear in the closed inquiry note.' );
		$this->assertStringContainsString( '&lt;script&gt;', $note, 'The status must be HTML-escaped in the closed inquiry note.' );
	}

	/**
	 * @testdox Should escape the webhook reason and due date in a created dispute note.
	 */
	public function test_dispute_created_note_escapes_reason_and_due_by(): void {
		$note = $this->invoke_private(
			'get_dispute_created_note',
			array( 'ch_123', '$10.00', '<b>reason</b>', '<i>due</i>', false, 'txn_1' )
		);

		$this->assertStringNotContainsString( '<b>reason</b>', $note, 'The raw reason markup must not appear in the created dispute note.' );
		$this->assertStringNotContainsString( '<i>due</i>', $note, 'The raw due date markup must not appear in the created dispute note.' );
		$this->assertStringContainsString( '&lt;b&gt;reason&lt;/b&gt;', $note, 'The reason must be HTML-escaped in the created dispute note.' );
		$this->assertStringContainsString( '&lt;i&gt;due&lt;/i&gt;', $note, 'The due date must be HTML-escaped in the created dispute note.' );
	}

	/**
	 * @testdox Should escape the webhook reason and due date in a created inquiry note.
	 */
	public function test_dispute_created_inquiry_note_escapes_reason_and_due_by(): void {
		$note = $this->invoke_private(
			'get_dispute_created_note',
			array( 'ch_123', '$10.00', '<b>reason</b>', '<i>due</i>', true, 'txn_1' )
		);

		$this->assertStringNotContainsString( '<b>reason</b>', $note, 'The raw reason markup must not appear in the created inquiry note.' );
		$this->assertStringNotContainsString( '<i>due</i>', $note, 'The raw due date markup must not appear in the created inquiry note.' );
		$this->assertStringContainsString( '&lt;b&gt;reason&lt;/b&gt;', $note, 'The reason must be HTML-escaped in the created inquiry note.' );
		$this->assertStringContainsString( '&lt;i&gt;due&lt;/i&gt;', $note, 'The due date must be HTML-escaped in the created inquiry note.' );
	}

	/**
	 * @testdox Should not double-escape the pre-formatted amount markup in a created dispute note.
	 */
	public function test_dispute_created_note_preserves_amount_markup(): void {
		$amount = wc_price( 10.0, array( 'currency' => 'USD' ) );

		$note = $this->invoke_private(
			'get_dispute_created_note',
			array( 'ch_123', $amount, 'Fraudulent', 'June 1, 2026', false, 'txn_1' )
		);

		$this->assertStringContainsString( $amount, $note, 'The pre-formatted price HTML must be preserved without double-escaping.' );
	}

	/**
	 * @testdox Should preserve the legacy encoded path shape in dispute URLs.
	 */
	public function test_dispute_url_preserves_encoded_legacy_path(): void {
		$url = $this->invoke_private( 'get_dispute_url', array( 'ch_123', 'txn_123' ) );

		$this->assertStringContainsString( 'path=%2Fpayments%2Ftransactions%2Fdetails', $url );
		$this->assertStringContainsString( 'id=ch_123', $url );
		$this->assertStringContainsString( 'transaction_id=txn_123', $url );
	}

	/**
	 * @testdox Should include an explicit currency code in dispute amounts when multiple currencies are enabled.
	 */
	public function test_formatted_dispute_amount_includes_currency_code_when_multiple_currencies_are_enabled(): void {
		$previous_store_currency     = get_option( 'woocommerce_currency' );
		$previous_enabled_currencies = get_option( 'wcpay_multi_currency_enabled_currencies' );

		try {
			update_option( 'woocommerce_currency', 'USD' );
			update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP', 'EUR' ) );

			$order = wc_create_order();
			$order->set_currency( 'USD' );

			$amount = $this->invoke_private( 'get_formatted_dispute_amount', array( $order, 5000 ) );

			$this->assertStringContainsString( '$50.00 USD', wp_strip_all_tags( html_entity_decode( $amount ) ), 'Dispute amounts should match the extension explicit-currency note format.' );
		} finally {
			update_option( 'woocommerce_currency', $previous_store_currency );
			update_option( 'wcpay_multi_currency_enabled_currencies', $previous_enabled_currencies );
		}
	}

	/**
	 * @testdox Legacy dispute markers backfill the unified identity onto the existing note.
	 */
	public function test_legacy_dispute_marker_backfills_unified_identity(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->save();

		$note              = 'Legacy dispute note';
		$dispute_id        = 'dp_legacy';
		$status            = 'needs_response';
		$note_type         = 'created_dispute';
		$legacy_note_id    = $order->add_order_note( $note );
		$legacy_marker_key = '_wc_native_woopayments_dispute_note_' . md5( $dispute_id . '|' . $status . '|' . $note_type );
		$order->update_meta_data( $legacy_marker_key, 'yes' );
		$order->save_meta_data();

		$this->assertFalse(
			$this->invoke_private(
				'add_dispute_order_note_once',
				array( $order, $note, $dispute_id, $status, $note_type )
			)
		);
		$this->assertSame(
			hash( 'sha256', 'dispute:' . $dispute_id . '|' . $status . '|' . $note_type ),
			get_comment_meta( $legacy_note_id, '_wc_woopayments_note_identity', true )
		);
	}

	/**
	 * @testdox New dispute identities suppress changed-content replay without legacy marker writes.
	 */
	public function test_new_dispute_identity_suppresses_replay_and_runs_side_effect_once(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->save();

		$dispute_id        = 'dp_new';
		$status            = 'needs_response';
		$note_type         = 'created_dispute';
		$before_add_calls  = 0;
		$before_add        = static function () use ( &$before_add_calls ): void {
			++$before_add_calls;
		};
		$legacy_marker_key = '_wc_native_woopayments_dispute_note_' . md5( $dispute_id . '|' . $status . '|' . $note_type );

		$this->assertTrue(
			$this->invoke_private(
				'add_dispute_order_note_once',
				array( $order, 'New dispute note', $dispute_id, $status, $note_type, $before_add )
			)
		);
		$this->assertFalse(
			$this->invoke_private(
				'add_dispute_order_note_once',
				array( $order, 'Translated dispute note', $dispute_id, $status, $note_type, $before_add )
			)
		);

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertCount( 1, $notes );
		$this->assertSame( 1, $before_add_calls );
		$this->assertSame( '', $order->get_meta( $legacy_marker_key, true ) );
		$this->assertSame(
			hash( 'sha256', 'dispute:' . $dispute_id . '|' . $status . '|' . $note_type ),
			get_comment_meta( $notes[0]->id, '_wc_woopayments_note_identity', true )
		);
	}

	/**
	 * @testdox A won dispute close on an already refunded order keeps the refunded status instead of completing it.
	 */
	public function test_dispute_closed_keeps_refunded_status_on_fully_refunded_order(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->set_total( '10.00' );
		$order->set_status( 'refunded' );
		$order->save();

		$this->invoke_private(
			'process_dispute_closed',
			array(
				$order,
				array(
					'id'     => 'dp_refunded',
					'status' => 'won',
				),
				'ch_refunded',
				'txn_refunded',
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'refunded', $order->get_status() );
		$this->assertNotEmpty( $this->find_order_note( $order, 'The order was not marked as completed because it has already been fully refunded.' ) );
	}

	/**
	 * @testdox A won dispute close moves an over-refunded on-hold order to refunded, not completed.
	 */
	public function test_dispute_closed_moves_over_refunded_order_to_refunded(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->set_total( '10.00' );
		$order->save();

		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 10.00,
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );

		$order = wc_get_order( $order->get_id() );
		$order->update_status( 'on-hold' );

		$this->invoke_private(
			'process_dispute_closed',
			array(
				$order,
				array(
					'id'     => 'dp_overref',
					'status' => 'won',
				),
				'ch_overref',
				'txn_overref',
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'refunded', $order->get_status() );
		$this->assertNotEmpty( $this->find_order_note( $order, 'The order was not marked as completed because it has already been fully refunded.' ) );
	}

	/**
	 * @testdox A won dispute close on an unrefunded order still completes it.
	 */
	public function test_dispute_closed_completes_unrefunded_order(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->set_total( '10.00' );
		$order->set_status( 'on-hold' );
		$order->save();

		$this->invoke_private(
			'process_dispute_closed',
			array(
				$order,
				array(
					'id'     => 'dp_plain',
					'status' => 'won',
				),
				'ch_plain',
				'txn_plain',
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'completed', $order->get_status() );
	}

	/**
	 * @testdox A created dispute is recorded in the order's open-dispute-ids meta.
	 */
	public function test_dispute_created_records_open_dispute_id(): void {
		$order = $this->create_disputable_order();

		$this->invoke_private(
			'process_dispute_created',
			array( $order, $this->get_created_event_object( 'dp_a1', 'needs_response' ), 'ch_multi', 'txn_multi' )
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( array( 'dp_a1' ), $order->get_meta( '_wcpay_open_dispute_ids', true ) );
		$this->assertSame( 'on-hold', $order->get_status() );
	}

	/**
	 * @testdox Two disputes on one charge each get their own note and open-dispute record.
	 */
	public function test_two_sibling_disputes_are_both_recorded(): void {
		$order = $this->create_disputable_order();

		$this->invoke_private(
			'process_dispute_created',
			array( $order, $this->get_created_event_object( 'dp_b1', 'needs_response' ), 'ch_multi', 'txn_multi' )
		);
		$this->invoke_private(
			'process_dispute_created',
			array( $order, $this->get_created_event_object( 'dp_b2', 'needs_response' ), 'ch_multi', 'txn_multi' )
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( array( 'dp_b1', 'dp_b2' ), $order->get_meta( '_wcpay_open_dispute_ids', true ) );
		$this->assertNotEmpty( $this->find_order_note( $order, '(Dispute ID: dp_b1)' ) );
		$this->assertNotEmpty( $this->find_order_note( $order, '(Dispute ID: dp_b2)' ) );
	}

	/**
	 * @testdox Closing one of two disputes keeps the hold and notes the sibling still open.
	 */
	public function test_closing_first_sibling_dispute_keeps_hold(): void {
		$order = $this->create_disputable_order();

		$this->invoke_private(
			'process_dispute_created',
			array( $order, $this->get_created_event_object( 'dp_c1', 'needs_response' ), 'ch_multi', 'txn_multi' )
		);
		$this->invoke_private(
			'process_dispute_created',
			array( $order, $this->get_created_event_object( 'dp_c2', 'needs_response' ), 'ch_multi', 'txn_multi' )
		);

		$order = wc_get_order( $order->get_id() );
		$this->invoke_private(
			'process_dispute_closed',
			array(
				$order,
				array(
					'id'     => 'dp_c1',
					'status' => 'won',
				),
				'ch_multi',
				'txn_multi',
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( array( 'dp_c2' ), $order->get_meta( '_wcpay_open_dispute_ids', true ) );
		$this->assertNotEmpty( $this->find_order_note( $order, 'The order was not marked as completed because 1 other dispute on this payment is still open.' ) );
	}

	/**
	 * @testdox Closing the last open dispute completes the order and clears the record.
	 */
	public function test_closing_last_sibling_dispute_completes_order(): void {
		$order = $this->create_disputable_order();

		$this->invoke_private(
			'process_dispute_created',
			array( $order, $this->get_created_event_object( 'dp_d1', 'needs_response' ), 'ch_multi', 'txn_multi' )
		);
		$this->invoke_private(
			'process_dispute_created',
			array( $order, $this->get_created_event_object( 'dp_d2', 'needs_response' ), 'ch_multi', 'txn_multi' )
		);

		$order = wc_get_order( $order->get_id() );
		$this->invoke_private(
			'process_dispute_closed',
			array(
				$order,
				array(
					'id'     => 'dp_d1',
					'status' => 'won',
				),
				'ch_multi',
				'txn_multi',
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->invoke_private(
			'process_dispute_closed',
			array(
				$order,
				array(
					'id'     => 'dp_d2',
					'status' => 'won',
				),
				'ch_multi',
				'txn_multi',
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( '', $order->get_meta( '_wcpay_open_dispute_ids', true ) );
	}

	/**
	 * @testdox A replayed created webhook does not duplicate the open-dispute record.
	 */
	public function test_replayed_created_webhook_does_not_duplicate_record(): void {
		$order = $this->create_disputable_order();

		$this->invoke_private(
			'process_dispute_created',
			array( $order, $this->get_created_event_object( 'dp_e1', 'needs_response' ), 'ch_multi', 'txn_multi' )
		);
		$order = wc_get_order( $order->get_id() );
		$this->invoke_private(
			'process_dispute_created',
			array( $order, $this->get_created_event_object( 'dp_e1', 'needs_response' ), 'ch_multi', 'txn_multi' )
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( array( 'dp_e1' ), $order->get_meta( '_wcpay_open_dispute_ids', true ) );
	}

	/**
	 * @testdox A closed note written by a pre-suffix plugin version suppresses the replayed close.
	 */
	public function test_plugin_era_closed_note_suppresses_replayed_close(): void {
		$order = $this->create_disputable_order();
		$order->set_status( 'on-hold' );
		$order->save();

		$plugin_note = $this->invoke_private(
			'get_dispute_closed_note',
			array( 'ch_cutover', 'won', false, 'txn_cutover' )
		);
		$order->add_order_note( $plugin_note );

		$this->invoke_private(
			'process_dispute_closed',
			array(
				$order,
				array(
					'id'     => 'dp_cutover',
					'status' => 'won',
				),
				'ch_cutover',
				'txn_cutover',
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertCount( 1, $this->find_order_note( $order, 'Dispute has been closed with status won' ) );
	}

	/**
	 * @testdox A created note written by a pre-suffix plugin version suppresses the replayed created event.
	 */
	public function test_plugin_era_created_note_suppresses_replayed_created(): void {
		$order = $this->create_disputable_order();

		$event       = $this->get_created_event_object( 'dp_cutover_created', 'needs_response' );
		$plugin_note = $this->invoke_private(
			'get_dispute_created_note',
			array(
				'ch_cutover_created',
				$this->invoke_private( 'get_formatted_dispute_amount', array( $order, 1000 ) ),
				$this->invoke_private( 'get_dispute_reason_description', array( 'fraudulent' ) ),
				$this->invoke_private( 'get_dispute_due_by_date', array( 1893456000 ) ),
				false,
				'txn_cutover_created',
			)
		);
		$order->add_order_note( $plugin_note );

		$this->invoke_private(
			'process_dispute_created',
			array( $order, $event, 'ch_cutover_created', 'txn_cutover_created' )
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( '', $order->get_meta( '_wcpay_open_dispute_ids', true ) );
	}

	/**
	 * Create an order suitable for dispute processing.
	 *
	 * @return \WC_Order
	 */
	private function create_disputable_order(): \WC_Order {
		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->set_total( '10.00' );
		$order->set_status( 'processing' );
		$order->save();

		return $order;
	}

	/**
	 * Build a dispute created event object fixture.
	 *
	 * @param string $dispute_id Dispute ID.
	 * @param string $status     Dispute status.
	 * @return array<string,mixed>
	 */
	private function get_created_event_object( string $dispute_id, string $status ): array {
		return array(
			'id'               => $dispute_id,
			'status'           => $status,
			'amount'           => 1000,
			'reason'           => 'fraudulent',
			'evidence_details' => array( 'due_by' => 1893456000 ),
		);
	}

	/**
	 * Find an order note containing the given text.
	 *
	 * @param \WC_Order $order Order object.
	 * @param string    $text  Note text fragment.
	 * @return array<int,object>
	 */
	private function find_order_note( \WC_Order $order, string $text ): array {
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );

		return array_values(
			array_filter(
				$notes,
				static function ( $note ) use ( $text ): bool {
					return false !== strpos( (string) $note->content, $text );
				}
			)
		);
	}
}
