<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsEventIngestor;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use WC_Order;
use WC_Order_Refund;
use WC_Unit_Test_Case;

/**
 * How a WooPayments order note already on the order is found, so it is written once.
 *
 * A note carries a private identity in comment meta. A refund note is found by that identity, or by its text when
 * the note has no identity yet, in which case the note gets the identity; a note found neither way is added with
 * it. Reading a note that is found by its identity writes nothing. These tests drive the production wiring and
 * observe only the order's notes and their meta.
 */
class WooPaymentsOrderNoteDedupeTest extends WC_Unit_Test_Case {

	/**
	 * Comment-meta key of a note's identity, as stores keep it.
	 */
	private const NOTE_IDENTITY_META_KEY = '_wc_woopayments_note_identity';

	/**
	 * The refund the platform reports.
	 *
	 * @var array<string,mixed>
	 */
	private array $refund = array();

	/**
	 * Webhook events delivered so far, so each delivery is a new event that reports the same payment.
	 *
	 * @var int
	 */
	private int $webhook_deliveries = 0;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->replace_platform();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'woocommerce_woopayments_builtin_enabled' );
		$this->reset_container_replacements();
		$this->reset_container_resolutions();
		parent::tearDown();
	}

	/**
	 * @testdox A refund note found neither by identity nor by text is added once, with its identity.
	 */
	public function test_refund_note_found_neither_way_is_added_with_its_identity(): void {
		$order = $this->create_paid_order();

		$this->refund( $order );

		$notes = $this->get_refund_notes( $order );
		$this->assertCount( 1, $notes );
		$this->assertSame( array( hash( 'sha256', 'refund:re_pin:created_successful' ) ), get_comment_meta( $notes[0]->id, self::NOTE_IDENTITY_META_KEY, false ) );
	}

	/**
	 * @testdox A refund note found by its identity is not added again, whatever its text reads now.
	 */
	public function test_refund_note_found_by_identity_is_not_added_again(): void {
		$order = $this->create_paid_order();
		$this->refund( $order );
		$note = $this->get_refund_notes( $order )[0];
		wp_update_comment(
			array(
				'comment_ID'      => $note->id,
				'comment_content' => 'A refund note an earlier wording wrote (<code>re_pin</code>).',
			)
		);

		$this->refund( $order );

		$notes = $this->get_refund_notes( $order );
		$this->assertCount( 1, $notes );
		$this->assertSame( $note->id, $notes[0]->id );
		$this->assertSame( array( hash( 'sha256', 'refund:re_pin:created_successful' ) ), get_comment_meta( $note->id, self::NOTE_IDENTITY_META_KEY, false ) );
	}

	/**
	 * @testdox A refund note found by its text is not added again, and gets its identity.
	 */
	public function test_refund_note_found_by_text_gets_its_identity(): void {
		$order = $this->create_paid_order();
		$this->refund( $order );
		$note = $this->get_refund_notes( $order )[0];
		delete_comment_meta( $note->id, self::NOTE_IDENTITY_META_KEY );

		$this->refund( $order );

		$notes = $this->get_refund_notes( $order );
		$this->assertCount( 1, $notes );
		$this->assertSame( $note->id, $notes[0]->id );
		$this->assertSame( array( hash( 'sha256', 'refund:re_pin:created_successful' ) ), get_comment_meta( $note->id, self::NOTE_IDENTITY_META_KEY, false ) );
	}

	/**
	 * @testdox Reading a payment note that is found by its identity writes nothing.
	 */
	public function test_reading_a_note_found_by_identity_writes_nothing(): void {
		$order = $this->create_order();
		$order->update_meta_data( '_intent_id', 'pi_pin' );
		$order->save();
		$this->deliver_succeeded_webhook( $order );
		$before = $this->get_notes_with_meta( $order );
		$this->assertNotEmpty( $before );

		$this->deliver_succeeded_webhook( $order );

		$this->assertSame( $before, $this->get_notes_with_meta( $order ) );
	}

	/**
	 * Replace the platform with one that reports $this->refund, and let the built-in WooPayments own the store.
	 */
	private function replace_platform(): void {
		$test = $this;
		$api  = new class( $test ) extends WooPaymentsApiClient {
			/**
			 * The test case holding the refund.
			 *
			 * @var WooPaymentsOrderNoteDedupeTest
			 */
			private WooPaymentsOrderNoteDedupeTest $test;

			/**
			 * Constructor.
			 *
			 * @param WooPaymentsOrderNoteDedupeTest $test Test case.
			 */
			public function __construct( WooPaymentsOrderNoteDedupeTest $test ) {
				$this->test = $test;
			}

			/**
			 * Report the platform as reachable.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Answer a refund with the reported refund.
			 *
			 * @param string      $charge_id       Charge ID.
			 * @param int|null    $amount          Amount.
			 * @param string|null $reason          Reason.
			 * @param string      $source          Source.
			 * @param string      $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function refund_charge( string $charge_id, ?int $amount, ?string $reason, string $source, string $idempotency_key ): array {
				unset( $charge_id, $amount, $reason, $source, $idempotency_key );
				return $this->test->get_reported_refund();
			}
		};
		wc_get_container()->replace( WooPaymentsApiClient::class, $api );
		add_filter( 'woocommerce_woopayments_builtin_enabled', '__return_true' );
		$this->reset_container_resolutions();
	}

	/**
	 * Get the refund the platform reports.
	 *
	 * @return array<string,mixed>
	 */
	public function get_reported_refund(): array {
		return $this->refund;
	}

	/**
	 * Refund 10.00 of the order through the payment runtime and the WooPayments provider, as WooCommerce does.
	 *
	 * @param WC_Order $order Order.
	 */
	private function refund( WC_Order $order ): void {
		$this->refund = array(
			'id'                  => 're_pin',
			'object'              => 'refund',
			'status'              => 'succeeded',
			'amount'              => 1000,
			'currency'            => 'usd',
			'charge'              => 'ch_pin',
			'balance_transaction' => 'txn_pin',
		);
		$row          = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 10.00,
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $row );

		$result = wc_get_container()->get( PaymentProcessingService::class )->process_refund(
			PaymentOperationContext::for_refund( $this->reload( $order ), WooPaymentsPersistenceVocabulary::GATEWAY_ID, 10.00 ),
			wc_get_container()->get( WooPaymentsProvider::class )
		);
		$this->assertTrue( $result );
	}

	/**
	 * Deliver the payment_intent.succeeded webhook for the order's payment.
	 *
	 * @param WC_Order $order Order.
	 */
	private function deliver_succeeded_webhook( WC_Order $order ): void {
		wc_get_container()->get( WooPaymentsEventIngestor::class )->process(
			array(
				'id'   => 'evt_pin_' . ( ++$this->webhook_deliveries ),
				'type' => 'payment_intent.succeeded',
				'data' => array(
					'object' => array(
						'id'             => 'pi_pin',
						'object'         => 'payment_intent',
						'status'         => 'succeeded',
						'amount'         => 5000,
						'currency'       => 'usd',
						'payment_method' => 'pm_card',
						'metadata'       => array(
							'order_id'  => (string) $order->get_id(),
							'order_key' => $order->get_order_key(),
						),
						'charges'        => array(
							'data' => array(
								array(
									'id'             => 'ch_pin',
									'payment_method' => 'pm_card',
									'payment_method_details' => array( 'type' => 'card' ),
								),
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Create an unpaid WooPayments order.
	 *
	 * @return WC_Order
	 */
	private function create_order(): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_currency( 'USD' );
		$order->set_total( '50.00' );
		$order->save();

		return $order;
	}

	/**
	 * Create a WooPayments order paid by one charge.
	 *
	 * @return WC_Order
	 */
	private function create_paid_order(): WC_Order {
		$order = $this->create_order();
		$order->set_status( 'processing' );
		$order->set_transaction_id( 'pi_pin' );
		$order->update_meta_data( '_intent_id', 'pi_pin' );
		$order->update_meta_data( '_charge_id', 'ch_pin' );
		$order->save();

		return $order;
	}

	/**
	 * Read the order again from the store.
	 *
	 * @param WC_Order $order Order.
	 * @return WC_Order
	 */
	private function reload( WC_Order $order ): WC_Order {
		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $reloaded );

		return $reloaded;
	}

	/**
	 * Get the order's notes that name the refund.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int,\stdClass>
	 */
	private function get_refund_notes( WC_Order $order ): array {
		return array_values(
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
				static fn( $note ): bool => str_contains( (string) $note->content, 're_pin' )
			)
		);
	}

	/**
	 * Get every note of the order with all its comment meta, keyed by note ID.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int,array{content:string,meta:array<string,mixed>}>
	 */
	private function get_notes_with_meta( WC_Order $order ): array {
		$notes = array();
		foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
			$notes[ (int) $note->id ] = array(
				'content' => (string) $note->content,
				'meta'    => get_comment_meta( $note->id ),
			);
		}
		ksort( $notes );

		return $notes;
	}
}
