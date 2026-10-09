<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLockRefusedException;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\TransientRowLock;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsEarlyFraudWarningEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Tests\Internal\Payments\OrderPaymentLockTestTrait;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsEarlyFraudWarningEventHandler class.
 */
class WooPaymentsEarlyFraudWarningEventHandlerTest extends WC_Unit_Test_Case {

	use OrderPaymentLockTestTrait;

	/**
	 * @testdox Should persist a valid created warning before adding its private merchant note.
	 */
	public function test_persists_created_warning_and_adds_private_note(): void {
		$order        = $this->create_woopayments_order();
		$note_service = new class() extends WooPaymentsOrderNoteService {
			/**
			 * Whether the warning was already persisted when note insertion began.
			 *
			 * @var bool
			 */
			public bool $saw_persisted_warning = false;

			/**
			 * {@inheritDoc}
			 *
			 * @param WC_Order      $order            Order object.
			 * @param string        $note             Note content.
			 * @param string        $identity         Stable private note identity.
			 * @param string[]      $equivalent_notes Equivalent note renderings.
			 * @param callable|null $before_add       Callback invoked before insertion.
			 */
			public function add_note_once( WC_Order $order, string $note, string $identity = '', array $equivalent_notes = array(), ?callable $before_add = null ): bool {
				$persisted_order             = wc_get_order( $order->get_id() );
				$this->saw_persisted_warning = $persisted_order instanceof WC_Order && 'efw_123' === ( $persisted_order->get_meta( '_wcpay_early_fraud_warning', true )['efw_id'] ?? '' );

				return parent::add_note_once( $order, $note, $identity, $equivalent_notes, $before_add );
			}
		};
		$logger       = RecordingWcLogger::install();
		$handler      = new WooPaymentsEarlyFraudWarningEventHandler();
		$handler->init( null, null, $note_service );

		$handler->process(
			'radar.early_fraud_warning.created',
			array(
				'charge'     => 'ch_early_warning',
				'id'         => 'efw_123',
				'actionable' => true,
				'created'    => 123,
				'fraud_type' => 'made_with_stolen_card',
			)
		);

		$fresh_order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $fresh_order );
		$this->assertSame(
			array(
				'efw_id'         => 'efw_123',
				'efw_actionable' => true,
				'efw_type'       => 'made_with_stolen_card',
				'created'        => 123,
			),
			$fresh_order->get_meta( '_wcpay_early_fraud_warning', true )
		);
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'Made with stolen card', $notes[0]->content );
		$this->assertTrue( $note_service->saw_persisted_warning );
		$this->assertCount( 0, array_filter( $logger->lines, static fn( array $line ): bool => 0 === strpos( $line[1], 'order payment method mismatch:' ) ), 'A WooPayments order logs no gateway mismatch.' );
	}

	/**
	 * @testdox Should support only the two exact early fraud warning event types.
	 */
	public function test_supports_only_exact_early_fraud_warning_event_types(): void {
		$handler = new WooPaymentsEarlyFraudWarningEventHandler();

		$this->assertTrue( $handler->is_supported_event( 'radar.early_fraud_warning.created' ) );
		$this->assertTrue( $handler->is_supported_event( 'radar.early_fraud_warning.updated' ) );
		$this->assertFalse( $handler->is_supported_event( 'radar.early_fraud_warning' ) );
		$this->assertFalse( $handler->is_supported_event( 'radar.early_fraud_warning.deleted' ) );
	}

	/**
	 * @testdox A warning whose fields arrive as other scalar types is stored with the client's casts, not refused.
	 *
	 * Client 11.1.0 casts actionable, fraud_type and created as it stores them (class-wc-payments-webhook-processing-service.php:900).
	 */
	public function test_casts_scalar_fields_of_another_type_as_the_client_does(): void {
		$order   = $this->create_woopayments_order();
		$handler = new WooPaymentsEarlyFraudWarningEventHandler();
		$handler->init();

		$handler->process(
			'radar.early_fraud_warning.created',
			array(
				'charge'     => 'ch_early_warning',
				'id'         => 'efw_cast',
				'actionable' => 1,
				'created'    => '1700000000',
				'fraud_type' => 7,
			)
		);

		$warning = wc_get_order( $order->get_id() )->get_meta( '_wcpay_early_fraud_warning', true );
		$this->assertTrue( $warning['efw_actionable'] );
		$this->assertSame( 1700000000, $warning['created'] );
		$this->assertSame( '7', $warning['efw_type'] );
	}

	/**
	 * @testdox Should reject malformed early fraud warning objects before order lookup.
	 *
	 * @dataProvider malformed_event_object_provider
	 *
	 * @param array<string,mixed> $event_object Malformed provider object.
	 */
	public function test_rejects_malformed_event_objects_before_order_lookup( array $event_object ): void {
		$handler = new WooPaymentsEarlyFraudWarningEventHandler();
		$handler->init();

		$this->expectException( \InvalidArgumentException::class );
		$handler->process( 'radar.early_fraud_warning.created', $event_object );
	}

	/**
	 * @testdox Should leave an update without compatible created evidence unchanged.
	 */
	public function test_update_without_stored_warning_is_a_successful_no_op(): void {
		$order   = $this->create_woopayments_order();
		$handler = new WooPaymentsEarlyFraudWarningEventHandler();
		$handler->init();

		$handler->process(
			'radar.early_fraud_warning.updated',
			array(
				'charge'     => 'ch_early_warning',
				'id'         => 'efw_123',
				'actionable' => false,
				'created'    => 123,
			)
		);

		$fresh_order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $fresh_order );
		$this->assertSame( '', $fresh_order->get_meta( '_wcpay_early_fraud_warning', true ) );
		$this->assertCount( 0, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * @testdox Should update stored evidence and add a resolved note.
	 */
	public function test_update_replaces_stored_warning_and_records_a_resolved_note(): void {
		$order   = $this->create_woopayments_order();
		$handler = new WooPaymentsEarlyFraudWarningEventHandler();
		$handler->init();
		$handler->process(
			'radar.early_fraud_warning.created',
			array(
				'charge'     => 'ch_early_warning',
				'id'         => 'efw_123',
				'actionable' => true,
				'created'    => 123,
			)
		);
		$handler->process(
			'radar.early_fraud_warning.updated',
			array(
				'charge'     => 'ch_early_warning',
				'id'         => 'efw_123',
				'actionable' => false,
				'created'    => 123,
			)
		);

		$fresh_order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $fresh_order );
		$this->assertSame( false, $fresh_order->get_meta( '_wcpay_early_fraud_warning', true )['efw_actionable'] );
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertCount( 2, $notes );
		$this->assertStringContainsString( 'no longer actionable', $notes[0]->content );
	}

	/**
	 * @testdox Should converge identical warning content under different warning IDs.
	 */
	public function test_identical_content_with_a_different_warning_id_does_not_duplicate_notes(): void {
		$order   = $this->create_woopayments_order();
		$handler = new WooPaymentsEarlyFraudWarningEventHandler();
		$handler->init();
		foreach ( array( 'efw_first', 'efw_second' ) as $warning_id ) {
			$handler->process(
				'radar.early_fraud_warning.created',
				array(
					'charge'     => 'ch_early_warning',
					'id'         => $warning_id,
					'actionable' => true,
					'created'    => 123,
					'fraud_type' => 'misc',
				)
			);
		}

		$this->assertCount( 1, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * @testdox Should add a new note when the visible early fraud warning reason changes.
	 */
	public function test_changed_reason_has_a_distinct_content_sensitive_note(): void {
		$order   = $this->create_woopayments_order();
		$handler = new WooPaymentsEarlyFraudWarningEventHandler();
		$handler->init();
		foreach ( array( 'made_with_lost_card', 'made_with_stolen_card' ) as $fraud_type ) {
			$handler->process(
				'radar.early_fraud_warning.created',
				array(
					'charge'     => 'ch_early_warning',
					'id'         => 'efw_123',
					'actionable' => true,
					'created'    => 123,
					'fraud_type' => $fraud_type,
				)
			);
		}

		$this->assertCount( 2, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * @testdox Should preserve compatible reordered evidence on an update.
	 */
	public function test_update_accepts_reordered_compatible_stored_evidence(): void {
		$order = $this->create_woopayments_order();
		$order->update_meta_data(
			'_wcpay_early_fraud_warning',
			array(
				'created'        => 123,
				'efw_type'       => '',
				'efw_id'         => 'efw_123',
				'efw_actionable' => true,
			)
		);
		$order->save();
		$handler = new WooPaymentsEarlyFraudWarningEventHandler();
		$handler->init();

		$handler->process(
			'radar.early_fraud_warning.updated',
			array(
				'charge'     => 'ch_early_warning',
				'id'         => 'efw_123',
				'actionable' => false,
				'created'    => 123,
			)
		);

		$this->assertSame( false, wc_get_order( $order->get_id() )->get_meta( '_wcpay_early_fraud_warning', true )['efw_actionable'] );
	}

	/**
	 * @testdox Should leave malformed stored evidence unchanged when an update arrives.
	 *
	 * @dataProvider malformed_stored_warning_provider
	 *
	 * @param mixed $stored_warning Malformed stored warning evidence.
	 */
	public function test_update_with_malformed_stored_warning_is_a_successful_no_op( $stored_warning ): void {
		$order = $this->create_woopayments_order();
		$order->update_meta_data( '_wcpay_early_fraud_warning', $stored_warning );
		$order->save();
		$handler = new WooPaymentsEarlyFraudWarningEventHandler();
		$handler->init();

		$handler->process(
			'radar.early_fraud_warning.updated',
			array(
				'charge'     => 'ch_early_warning',
				'id'         => 'efw_123',
				'actionable' => false,
				'created'    => 123,
			)
		);

		$this->assertSame( $stored_warning, wc_get_order( $order->get_id() )->get_meta( '_wcpay_early_fraud_warning', true ) );
		$this->assertCount( 0, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * @testdox Should reject a warning whose charge matches no order as a retryable failure.
	 */
	public function test_rejects_unmatched_orders(): void {
		$handler = new WooPaymentsEarlyFraudWarningEventHandler();
		$handler->init();

		$this->expectException( \RuntimeException::class );
		$handler->process(
			'radar.early_fraud_warning.created',
			array(
				'charge'     => 'ch_unknown',
				'id'         => 'efw_123',
				'actionable' => true,
				'created'    => 123,
			)
		);
	}

	/**
	 * Client 11.1.0 resolves the order from the charge with no gateway check (class-wc-payments-webhook-processing-service.php:867-901)
	 * and stores the warning; here the warning is recorded, because the charge is not the order's payment. The warning carries
	 * its `payment_intent` (https://docs.stripe.com/api/radar/early_fraud_warnings/object#early_fraud_warning_object-payment_intent).
	 *
	 * @testdox Should record a warning raised on a charge recorded on an order another gateway paid with one note and one log line, store nothing, and ignore its update.
	 */
	public function test_records_a_warning_on_an_order_paid_by_another_gateway(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_payment_method( 'cheque' );
		$order->set_status( 'processing' );
		$order->set_date_paid( time() );
		$order->update_meta_data( '_charge_id', 'ch_early_warning' );
		$order->save();
		$order_id = $order->get_id();
		$logger   = RecordingWcLogger::install();
		$handler  = new WooPaymentsEarlyFraudWarningEventHandler();
		$handler->init();
		$event                   = $this->valid_event_object();
		$event['payment_intent'] = 'pi_cheque';

		$handler->process( 'radar.early_fraud_warning.created', $event );
		$handler->process( 'radar.early_fraud_warning.updated', array_replace( $event, array( 'actionable' => false ) ) );

		$fresh_order = wc_get_order( $order_id );
		$this->assertInstanceOf( WC_Order::class, $fresh_order );
		$this->assertSame( '', $fresh_order->get_meta( '_wcpay_early_fraud_warning', true ) );
		$this->assertSame( 'processing', $fresh_order->get_status() );
		$notes = array_values(
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order_id ) ),
				static fn( $note ): bool => false !== stripos( (string) $note->content, 'fraud warning' )
			)
		);
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'An early fraud warning (<code>efw_123</code>) was raised on WooPayments charge', $notes[0]->content );
		$this->assertFalse( (bool) $notes[0]->customer_note );
		$record_lines = array_keys(
			array_filter(
				$logger->lines,
				static fn( array $line ): bool => 'warning' === $line[0] && 'other charge recorded: order ' . $order_id . ', radar.early_fraud_warning.created, charge ch_early_warning, order payment method cheque' === $line[1]
			)
		);
		$this->assertCount( 1, $record_lines );
		$this->assertSame( 'woopayments', $logger->contexts[ $record_lines[0] ]['source'] );
		$this->assertCount( 1, array_filter( $logger->lines, static fn( array $line ): bool => 0 === strpos( $line[1], 'other charge recorded:' ) ) );
		$this->assertCount( 0, array_filter( $logger->lines, static fn( array $line ): bool => 0 === strpos( $line[1], 'order payment method mismatch:' ) ) );
	}

	/**
	 * The event's `payment_intent` is the Stripe Early Fraud Warning field
	 * (https://docs.stripe.com/api/radar/early_fraud_warnings/object#early_fraud_warning_object-payment_intent).
	 *
	 * @testdox Should store a warning on the charge that paid the order after the merchant changed its payment method.
	 */
	public function test_stores_a_warning_on_the_orders_own_charge_whatever_its_payment_method(): void {
		$order = $this->create_woopayments_order();
		$order->set_payment_method( 'cheque' );
		$order->set_status( 'processing' );
		$order->set_transaction_id( 'pi_early_warning' );
		$order->update_meta_data( '_intent_id', 'pi_early_warning' );
		$order->save();
		$event                   = $this->valid_event_object();
		$event['payment_intent'] = 'pi_early_warning';
		$handler                 = new WooPaymentsEarlyFraudWarningEventHandler();
		$handler->init();

		$handler->process( 'radar.early_fraud_warning.created', $event );

		$fresh_order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $fresh_order );
		$this->assertSame(
			array(
				'efw_id'         => 'efw_123',
				'efw_actionable' => true,
				'efw_type'       => 'made_with_stolen_card',
				'created'        => 123,
			),
			$fresh_order->get_meta( '_wcpay_early_fraud_warning', true )
		);
		$warning_notes = array_filter(
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
			static fn( $note ): bool => false !== strpos( (string) $note->content, 'Made with stolen card' )
		);
		$this->assertCount( 1, $warning_notes );
	}

	/**
	 * @testdox Should fail retryably when another payment operation owns the order lock.
	 */
	public function test_rejects_lock_contention(): void {
		$order      = $this->create_woopayments_order();
		$store      = new OrderPaymentLock();
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$store->init( new TransientRowLock() );
		$this->assertNotNull( $store->claim( $order, $vocabulary, 'other_operation', 'payment operation' ) );
		$handler = new WooPaymentsEarlyFraudWarningEventHandler();
		$handler->init( $store, $vocabulary );

		try {
			// The reliability service retries this exception for every event type.
			$this->expectException( OrderPaymentLockRefusedException::class );
			$handler->process(
				'radar.early_fraud_warning.created',
				array(
					'charge'     => 'ch_early_warning',
					'id'         => 'efw_123',
					'actionable' => true,
					'created'    => 123,
				)
			);
		} finally {
			$this->clear_order_payment_lock( $order, $vocabulary );
		}
	}

	/**
	 * @testdox Should recheck the charge after claiming the payment lock.
	 */
	public function test_rejects_a_fresh_read_charge_mismatch(): void {
		$order   = $this->create_woopayments_order();
		$store   = new class( $order ) extends OrderPaymentLock {
			/**
			 * Order whose charge changes during lock acquisition.
			 *
			 * @var WC_Order
			 */
			private WC_Order $order;

			/**
			 * Set the order whose charge should change.
			 *
			 * @param WC_Order $order Order fixture.
			 */
			public function __construct( WC_Order $order ) {
				$this->order = $order;
			}

			/**
			 * {@inheritDoc}
			 *
			 * @param WC_Order                                                                         $order     Order being locked.
			 * @param \Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface $vocabulary  Provider persistence vocabulary.
			 * @param string|null                                                                      $reference Payment reference being processed.
			 * @param string                                                                           $operation Operation claiming the lock.
			 */
			public function claim( WC_Order $order, \Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface $vocabulary, ?string $reference, string $operation ): ?string {
				$this->order->update_meta_data( '_charge_id', 'ch_changed_after_lock' );
				$this->order->save();
				return 'test_lock_token';
			}

			/**
			 * {@inheritDoc}
			 *
			 * @param WC_Order                                                                         $order     Order being unlocked.
			 * @param \Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface $vocabulary   Provider persistence vocabulary.
			 * @param string                                                                           $lock_token Token the claim returned.
			 */
			public function release( WC_Order $order, \Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface $vocabulary, string $lock_token ): void {}
		};
		$handler = new WooPaymentsEarlyFraudWarningEventHandler();
		$handler->init( $store, new WooPaymentsPersistenceVocabulary() );

		$this->expectException( \RuntimeException::class );
		$handler->process(
			'radar.early_fraud_warning.created',
			array(
				'charge'     => 'ch_early_warning',
				'id'         => 'efw_123',
				'actionable' => true,
				'created'    => 123,
			)
		);
	}

	/**
	 * @testdox Should apply an update when a created warning appears while waiting for the order lock.
	 */
	public function test_update_uses_warning_created_during_lock_acquisition(): void {
		$order   = $this->create_woopayments_order();
		$store   = new class( $order ) extends OrderPaymentLock {
			/**
			 * Order whose warning appears during lock acquisition.
			 *
			 * @var WC_Order
			 */
			private WC_Order $order;

			/**
			 * Set the order whose warning should appear.
			 *
			 * @param WC_Order $order Order fixture.
			 */
			public function __construct( WC_Order $order ) {
				$this->order = $order;
			}

			/**
			 * {@inheritDoc}
			 *
			 * @param WC_Order                                                                         $order     Order being locked.
			 * @param \Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface $vocabulary  Provider persistence vocabulary.
			 * @param string|null                                                                      $reference Payment reference being processed.
			 * @param string                                                                           $operation Operation claiming the lock.
			 */
			public function claim( WC_Order $order, \Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface $vocabulary, ?string $reference, string $operation ): ?string {
				$claimed = parent::claim( $order, $vocabulary, $reference, $operation );
				if ( null !== $claimed ) {
					$this->order->update_meta_data(
						'_wcpay_early_fraud_warning',
						array(
							'efw_id'         => 'efw_123',
							'efw_actionable' => true,
							'efw_type'       => 'made_with_stolen_card',
							'created'        => 123,
						)
					);
					$this->order->save();
				}

				return $claimed;
			}
		};
		$handler = new WooPaymentsEarlyFraudWarningEventHandler();
		$store->init( new TransientRowLock() );
		$handler->init( $store, new WooPaymentsPersistenceVocabulary() );

		$handler->process(
			'radar.early_fraud_warning.updated',
			array(
				'charge'     => 'ch_early_warning',
				'id'         => 'efw_123',
				'actionable' => false,
				'created'    => 456,
				'fraud_type' => 'made_with_stolen_card',
			)
		);

		$fresh_order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $fresh_order );
		$this->assertSame(
			array(
				'efw_id'         => 'efw_123',
				'efw_actionable' => false,
				'efw_type'       => 'made_with_stolen_card',
				'created'        => 456,
			),
			$fresh_order->get_meta( '_wcpay_early_fraud_warning', true )
		);
		$this->assertStringContainsString( 'no longer actionable', wc_get_order_notes( array( 'order_id' => $order->get_id() ) )[0]->content );
	}

	/**
	 * @testdox Should release the lock when note persistence throws.
	 */
	public function test_releases_the_lock_after_a_note_failure(): void {
		$order   = $this->create_woopayments_order();
		$store   = new class() extends OrderPaymentLock {
			/**
			 * Whether the order lock was released.
			 *
			 * @var bool
			 */
			public bool $unlocked = false;

			/**
			 * {@inheritDoc}
			 *
			 * @param WC_Order                                                                         $order     Order being locked.
			 * @param \Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface $vocabulary  Provider persistence vocabulary.
			 * @param string|null                                                                      $reference Payment reference being processed.
			 * @param string                                                                           $operation Operation claiming the lock.
			 */
			public function claim( WC_Order $order, \Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface $vocabulary, ?string $reference, string $operation ): ?string {
				return 'test_lock_token';
			}

			/**
			 * {@inheritDoc}
			 *
			 * @param WC_Order                                                                         $order     Order being unlocked.
			 * @param \Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface $vocabulary   Provider persistence vocabulary.
			 * @param string                                                                           $lock_token Token the claim returned.
			 */
			public function release( WC_Order $order, \Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface $vocabulary, string $lock_token ): void {
				$this->unlocked = true;
			}
		};
		$notes   = new class() extends WooPaymentsOrderNoteService {
			/**
			 * {@inheritDoc}
			 *
			 * @param string $charge_id  Provider charge ID.
			 * @param bool   $actionable Whether the warning is actionable.
			 * @param string $fraud_type Provider warning reason.
			 */
			public function format_early_fraud_warning_note_candidates( string $charge_id, bool $actionable, string $fraud_type ): array {
				return array( 'Early fraud warning note.' );
			}

			/**
			 * {@inheritDoc}
			 *
			 * @param WC_Order      $order            Order object.
			 * @param string        $note             Note content.
			 * @param string        $identity         Stable private note identity.
			 * @param string[]      $equivalent_notes Equivalent note renderings.
			 * @param callable|null $before_add       Callback invoked before insertion.
			 */
			public function add_note_once( WC_Order $order, string $note, string $identity = '', array $equivalent_notes = array(), ?callable $before_add = null ): bool {
				throw new \RuntimeException( 'Note persistence failed.' );
			}
		};
		$handler = new WooPaymentsEarlyFraudWarningEventHandler();
		$handler->init( $store, new WooPaymentsPersistenceVocabulary(), $notes );

		try {
			$handler->process(
				'radar.early_fraud_warning.created',
				array(
					'charge'     => 'ch_early_warning',
					'id'         => 'efw_123',
					'actionable' => true,
					'created'    => 123,
				)
			);
			$this->fail( 'Expected note persistence failure.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'Note persistence failed.', $exception->getMessage() );
		}

		$this->assertTrue( $store->unlocked );
		$this->assertSame( 'efw_123', wc_get_order( $order->get_id() )->get_meta( '_wcpay_early_fraud_warning', true )['efw_id'] );
	}

	/**
	 * @testdox Should fail retryably when note insertion returns false without a persisted equivalent.
	 */
	public function test_rejects_a_non_throwing_note_insertion_failure(): void {
		$order      = $this->create_woopayments_order();
		$store      = new OrderPaymentLock();
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$notes      = new class() extends WooPaymentsOrderNoteService {
			/**
			 * {@inheritDoc}
			 *
			 * @param WC_Order      $order            Order object.
			 * @param string        $note             Note content.
			 * @param string        $identity         Stable private note identity.
			 * @param string[]      $equivalent_notes Equivalent note renderings.
			 * @param callable|null $before_add       Callback invoked before insertion.
			 */
			public function add_note_once( WC_Order $order, string $note, string $identity = '', array $equivalent_notes = array(), ?callable $before_add = null ): bool {
				return false;
			}
		};
		$store->init( new TransientRowLock() );
		$handler = new WooPaymentsEarlyFraudWarningEventHandler();
		$handler->init( $store, $vocabulary, $notes );

		$failure = null;
		try {
			$handler->process( 'radar.early_fraud_warning.created', $this->valid_event_object() );
		} catch ( \RuntimeException $exception ) {
			$failure = $exception;
		}

		$this->assertInstanceOf( \RuntimeException::class, $failure );
		$this->assertSame( 'Could not persist early fraud warning note for ID: efw_123', $failure->getMessage() );
		$this->assertFalse( $this->is_order_payment_lock_held_for( $order, $vocabulary, 'early_fraud_warning_efw_123' ) );
		$this->assertCount( 0, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * @testdox Should release the lock and avoid the note when order persistence throws.
	 */
	public function test_releases_the_lock_after_an_order_save_failure(): void {
		$order   = $this->create_woopayments_order();
		$store   = new class() extends OrderPaymentLock {
			/**
			 * Whether the order lock was released.
			 *
			 * @var bool
			 */
			public bool $unlocked = false;

			/**
			 * {@inheritDoc}
			 *
			 * @param WC_Order                                                                         $order     Order being locked.
			 * @param \Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface $vocabulary  Provider persistence vocabulary.
			 * @param string|null                                                                      $reference Payment reference being processed.
			 * @param string                                                                           $operation Operation claiming the lock.
			 */
			public function claim( WC_Order $order, \Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface $vocabulary, ?string $reference, string $operation ): ?string {
				return 'test_lock_token';
			}

			/**
			 * {@inheritDoc}
			 *
			 * @param WC_Order                                                                         $order     Order being unlocked.
			 * @param \Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface $vocabulary   Provider persistence vocabulary.
			 * @param string                                                                           $lock_token Token the claim returned.
			 */
			public function release( WC_Order $order, \Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface $vocabulary, string $lock_token ): void {
				$this->unlocked = true;
			}
		};
		$thrower = static function ( WC_Order $saving_order ) use ( $order ): void {
			if ( $order->get_id() === $saving_order->get_id() ) {
				throw new \RuntimeException( 'Order persistence failed.' );
			}
		};
		$handler = new WooPaymentsEarlyFraudWarningEventHandler();
		$handler->init( $store, new WooPaymentsPersistenceVocabulary() );
		add_action( 'woocommerce_before_order_object_save', $thrower );

		$failure = null;
		try {
			$handler->process( 'radar.early_fraud_warning.created', $this->valid_event_object() );
		} catch ( \RuntimeException $exception ) {
			$failure = $exception;
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $thrower );
		}

		$this->assertInstanceOf( \RuntimeException::class, $failure );
		$this->assertSame( 'Could not persist early fraud warning ID: efw_123', $failure->getMessage() );
		$this->assertTrue( $store->unlocked );
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertStringNotContainsString( 'Payment has received an early fraud warning', implode( ' ', wp_list_pluck( $notes, 'content' ) ) );
	}

	/**
	 * @testdox Should mutate only the matching order on the current multisite blog.
	 * @group multisite
	 */
	public function test_processing_is_isolated_to_the_current_multisite_blog(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$main_site_id = get_current_blog_id();
		$main_order   = $this->create_woopayments_order( 'ch_shared_warning' );
		$subsite_id   = self::factory()->blog->create();
		$this->assertIsInt( $subsite_id );
		switch_to_blog( $subsite_id );

		try {
			$this->install_woocommerce_tables_for_current_site();
			$subsite_order = $this->create_woopayments_order( 'ch_shared_warning' );
			$handler       = new WooPaymentsEarlyFraudWarningEventHandler();
			$handler->init();
			$handler->process( 'radar.early_fraud_warning.created', $this->valid_event_object( 'ch_shared_warning' ) );

			$this->assertSame( $subsite_id, get_current_blog_id() );
			$this->assertSame( 'efw_123', wc_get_order( $subsite_order->get_id() )->get_meta( '_wcpay_early_fraud_warning', true )['efw_id'] );
			restore_current_blog();
			$this->assertSame( $main_site_id, get_current_blog_id() );
			$this->assertSame( '', wc_get_order( $main_order->get_id() )->get_meta( '_wcpay_early_fraud_warning', true ) );
		} finally {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}
			wpmu_delete_blog( $subsite_id, true );
		}
	}

	/**
	 * @testdox Should preserve the calling multisite blog when processing and lock cleanup both throw.
	 * @group multisite
	 */
	public function test_throwing_handler_and_lock_cleanup_preserve_the_current_multisite_blog(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}

		$subsite_id = self::factory()->blog->create();
		$this->assertIsInt( $subsite_id );
		switch_to_blog( $subsite_id );

		try {
			$this->install_woocommerce_tables_for_current_site();
			$this->create_woopayments_order();
			$store   = new class() extends OrderPaymentLock {
				/**
				 * {@inheritDoc}
				 *
				 * @param WC_Order                                                                         $order     Order being locked.
				 * @param \Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface $vocabulary  Provider persistence vocabulary.
				 * @param string|null                                                                      $reference Payment reference being processed.
				 * @param string                                                                           $operation Operation claiming the lock.
				 */
				public function claim( WC_Order $order, \Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface $vocabulary, ?string $reference, string $operation ): ?string {
					return 'test_lock_token';
				}

				/**
				 * {@inheritDoc}
				 *
				 * @param WC_Order                                                                         $order     Order being unlocked.
				 * @param \Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface $vocabulary   Provider persistence vocabulary.
				 * @param string                                                                           $lock_token Token the claim returned.
				 */
				public function release( WC_Order $order, \Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface $vocabulary, string $lock_token ): void {
					throw new \RuntimeException( 'Lock cleanup failed.' );
				}
			};
			$notes   = new class() extends WooPaymentsOrderNoteService {
				/**
				 * {@inheritDoc}
				 *
				 * @param string $charge_id  Provider charge ID.
				 * @param bool   $actionable Whether the warning is actionable.
				 * @param string $fraud_type Provider warning reason.
				 */
				public function format_early_fraud_warning_note_candidates( string $charge_id, bool $actionable, string $fraud_type ): array {
					return array( 'Early fraud warning note.' );
				}

				/**
				 * {@inheritDoc}
				 *
				 * @param WC_Order      $order            Order object.
				 * @param string        $note             Note content.
				 * @param string        $identity         Stable private note identity.
				 * @param string[]      $equivalent_notes Equivalent note renderings.
				 * @param callable|null $before_add       Callback invoked before insertion.
				 */
				public function add_note_once( WC_Order $order, string $note, string $identity = '', array $equivalent_notes = array(), ?callable $before_add = null ): bool {
					throw new \RuntimeException( 'Note persistence failed.' );
				}
			};
			$handler = new WooPaymentsEarlyFraudWarningEventHandler();
			$handler->init( $store, new WooPaymentsPersistenceVocabulary(), $notes );

			try {
				$handler->process( 'radar.early_fraud_warning.created', $this->valid_event_object() );
				$this->fail( 'Expected lock cleanup failure.' );
			} catch ( \RuntimeException $exception ) {
				$this->assertSame( 'Lock cleanup failed.', $exception->getMessage() );
			}

			$this->assertSame( $subsite_id, get_current_blog_id() );
		} finally {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}
			wpmu_delete_blog( $subsite_id, true );
		}
	}

	/**
	 * @testdox Should recognize the client's exact persisted note as the same warning across cutover.
	 */
	public function test_seeded_plugin_catalog_note_deduplicates_during_cutover(): void {
		$order           = $this->create_woopayments_order();
		$note_service    = new WooPaymentsOrderNoteService();
		$transaction_url = $note_service->transaction_url( '', 'ch_early_warning' );
		$client_note     = 'Payment has received an early fraud warning with reason &quot;Made with stolen card&quot;. <a href="' . $transaction_url . '" class="wcpay-efw-refund-link" target="_blank" rel="noopener noreferrer">Refunding the payment now</a> can prevent a dispute. See <a href="' . $transaction_url . '" target="_blank" rel="noopener noreferrer">payment details</a> for more information.';
		$order->add_order_note( $client_note );
		$handler = new WooPaymentsEarlyFraudWarningEventHandler();
		$handler->init( null, null, $note_service );
		$handler->process( 'radar.early_fraud_warning.created', $this->valid_event_object() );

		$this->assertCount( 1, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * Malformed early fraud warning object cases.
	 *
	 * @return array<string,array{array<string,mixed>}>
	 */
	public function malformed_event_object_provider(): array {
		return array(
			'missing charge'           => array(
				array(
					'id'         => 'efw_123',
					'actionable' => true,
					'created'    => 123,
				),
			),
			'empty charge'             => array(
				array(
					'charge'     => '',
					'id'         => 'efw_123',
					'actionable' => true,
					'created'    => 123,
				),
			),
			'non-string charge'        => array(
				array(
					'charge'     => array(),
					'id'         => 'efw_123',
					'actionable' => true,
					'created'    => 123,
				),
			),
			'missing warning ID'       => array(
				array(
					'charge'     => 'ch_early_warning',
					'actionable' => true,
					'created'    => 123,
				),
			),
			'empty warning ID'         => array(
				array(
					'charge'     => 'ch_early_warning',
					'id'         => '',
					'actionable' => true,
					'created'    => 123,
				),
			),
			'non-string warning ID'    => array(
				array(
					'charge'     => 'ch_early_warning',
					'id'         => array(),
					'actionable' => true,
					'created'    => 123,
				),
			),
			'missing state'            => array(
				array(
					'charge'  => 'ch_early_warning',
					'id'      => 'efw_123',
					'created' => 123,
				),
			),
			'non-scalar state'         => array(
				array(
					'charge'     => 'ch_early_warning',
					'id'         => 'efw_123',
					'actionable' => array( true ),
					'created'    => 123,
				),
			),
			'missing created time'     => array(
				array(
					'charge'     => 'ch_early_warning',
					'id'         => 'efw_123',
					'actionable' => true,
				),
			),
			'non-numeric created time' => array(
				array(
					'charge'     => 'ch_early_warning',
					'id'         => 'efw_123',
					'actionable' => true,
					'created'    => 'yesterday',
				),
			),
			'negative created time'    => array(
				array(
					'charge'     => 'ch_early_warning',
					'id'         => 'efw_123',
					'actionable' => true,
					'created'    => -1,
				),
			),
			'non-string reason'        => array(
				array(
					'charge'     => 'ch_early_warning',
					'id'         => 'efw_123',
					'actionable' => true,
					'created'    => 123,
					'fraud_type' => array(),
				),
			),
		);
	}

	/**
	 * Malformed stored early fraud warning cases.
	 *
	 * @return array<string,array{mixed}>
	 */
	public function malformed_stored_warning_provider(): array {
		return array(
			'scalar'       => array( 'not-an-array' ),
			'missing key'  => array(
				array(
					'efw_id'         => 'efw_123',
					'efw_actionable' => true,
					'efw_type'       => '',
				),
			),
			'extra key'    => array(
				array(
					'efw_id'         => 'efw_123',
					'efw_actionable' => true,
					'efw_type'       => '',
					'created'        => 123,
					'charge'         => 'ch_early_warning',
				),
			),
			'wrong ID'     => array(
				array(
					'efw_id'         => 123,
					'efw_actionable' => true,
					'efw_type'       => '',
					'created'        => 123,
				),
			),
			'wrong state'  => array(
				array(
					'efw_id'         => 'efw_123',
					'efw_actionable' => 'true',
					'efw_type'       => '',
					'created'        => 123,
				),
			),
			'wrong reason' => array(
				array(
					'efw_id'         => 'efw_123',
					'efw_actionable' => true,
					'efw_type'       => array(),
					'created'        => 123,
				),
			),
			'wrong time'   => array(
				array(
					'efw_id'         => 'efw_123',
					'efw_actionable' => true,
					'efw_type'       => '',
					'created'        => -1,
				),
			),
		);
	}

	/**
	 * Create a WooPayments order matched by the early warning charge.
	 *
	 * @param string $charge_id Provider charge ID.
	 * @return WC_Order
	 */
	private function create_woopayments_order( string $charge_id = 'ch_early_warning' ): WC_Order {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->save();

		return $order;
	}

	/**
	 * Build a valid early fraud warning object.
	 *
	 * @param string $charge_id Provider charge ID.
	 * @return array{charge:string,id:string,actionable:bool,created:int,fraud_type:string}
	 */
	private function valid_event_object( string $charge_id = 'ch_early_warning' ): array {
		return array(
			'charge'     => $charge_id,
			'id'         => 'efw_123',
			'actionable' => true,
			'created'    => 123,
			'fraud_type' => 'made_with_stolen_card',
		);
	}

	/**
	 * Install WooCommerce's site-scoped tables for a multisite fixture.
	 */
	private function install_woocommerce_tables_for_current_site(): void {
		\WC_Install::create_tables();
		( new \ActionScheduler_StoreSchema() )->register_tables( true );
		( new \ActionScheduler_LoggerSchema() )->register_tables( true );
	}
}
