<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLockRefusedException;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDisputeCacheService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDisputeEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLegacyRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Tests\Internal\Payments\OrderPaymentLockTestTrait;
use ReflectionClass;
use RuntimeException;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsDisputeEventHandler class.
 */
class WooPaymentsDisputeEventHandlerTest extends WC_Unit_Test_Case {

	use OrderPaymentLockTestTrait;

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsDisputeEventHandler
	 */
	private $sut;

	/**
	 * Original option values restored after each test.
	 *
	 * @var array<string,mixed>
	 */
	private array $original_options = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		foreach ( array( 'woocommerce_currency', '_wcpay_feature_customer_multi_currency', 'wcpay_multi_currency_enabled_currencies', 'wcpay_multi_currency_exchange_rate_eur', 'wcpay_multi_currency_manual_rate_eur' ) as $option_name ) {
			$this->original_options[ $option_name ] = get_option( $option_name, null );
		}
		$this->sut = new WooPaymentsDisputeEventHandler();
		$this->sut->init(
			wc_get_container()->get( WooPaymentsLegacyRuntime::class ),
			new class() extends WooPaymentsApiClient {},
			wc_get_container()->get( WooPaymentsDisputeCacheService::class )
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->original_options as $option_name => $option_value ) {
			null === $option_value ? delete_option( $option_name ) : update_option( $option_name, $option_value );
		}
		$this->original_options = array();
		$this->reset_container_replacements();
		parent::tearDown();
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
	 * @testdox Dispute amounts follow the client explicit-price rule.
	 *
	 * Source: client 11.1.0 class-wc-payments-webhook-processing-service.php:726-730 (dispute amount) and
	 * class-wc-payments-explicit-price-formatter.php:55-74,167-190 (Multi-Currency on with a second currency, then the filter).
	 *
	 * @dataProvider explicit_price_rule_provider
	 *
	 * @param bool        $core_multi_currency Whether core Multi-Currency owns the runtime.
	 * @param string[]    $enabled_currencies  Enabled currencies option.
	 * @param string|null $plugin_flag         Stale WooPayments `_wcpay_feature_customer_multi_currency` value, or null when absent.
	 * @param bool|null   $filter_result       Value the filter returns, or null for no filter.
	 * @param string      $order_currency      Order currency.
	 * @param bool        $expected_default    Default the filter must receive.
	 * @param string      $expected            Expected plain-text amount.
	 */
	public function test_formatted_dispute_amount_follows_the_client_explicit_price_rule( bool $core_multi_currency, array $enabled_currencies, ?string $plugin_flag, ?bool $filter_result, string $order_currency, bool $expected_default, string $expected ): void {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', $enabled_currencies );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.9' );
		null === $plugin_flag ? delete_option( '_wcpay_feature_customer_multi_currency' ) : update_option( '_wcpay_feature_customer_multi_currency', $plugin_flag );
		$arbiter   = $this->getMockBuilder( MultiCurrencyRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_core_register' ) )
			->getMock();
		$container = wc_get_container();
		// Keep the real feature definition working if FeaturesController registers it while the mock is in place.
		$arbiter->init( $container->get( WooPaymentsRuntimeArbiter::class ), $container->get( LegacyProxy::class ), $container->get( MultiCurrencyFeatureController::class ) );
		$arbiter->method( 'should_core_register' )->willReturn( $core_multi_currency );
		wc_get_container()->replace( MultiCurrencyRuntimeArbiter::class, $arbiter );
		$defaults = array();
		add_filter(
			'wcpay_multi_currency_should_output_explicit_price',
			static function ( bool $current_default ) use ( &$defaults, $filter_result ): bool {
				$defaults[] = $current_default;
				return $filter_result ?? $current_default;
			}
		);
		$order = wc_create_order();
		$order->set_currency( $order_currency );

		$amount = $this->invoke_private( 'get_formatted_dispute_amount', array( $order, 5000 ) );

		$this->assertSame( $expected, html_entity_decode( wp_strip_all_tags( $amount ) ) );
		$this->assertSame( array( $expected_default ), $defaults, 'The filter must run once per amount with the client default.' );
	}

	/**
	 * Store states with the client's dispute amount outcome.
	 *
	 * @return array<string,array{0:bool,1:string[],2:?string,3:?bool,4:string,5:bool,6:string}>
	 */
	public function explicit_price_rule_provider(): array {
		return array(
			'Multi-Currency on with a second currency'     => array( true, array( 'USD', 'EUR' ), null, null, 'USD', true, '$50.00 USD' ),
			'Multi-Currency on, foreign-currency order'    => array( true, array( 'USD', 'EUR' ), null, null, 'EUR', true, '€50.00 EUR' ),
			'Multi-Currency on, stale plugin flag off'     => array( true, array( 'USD', 'EUR' ), '0', null, 'USD', true, '$50.00 USD' ),
			'Multi-Currency on, single currency'           => array( true, array( 'USD' ), null, null, 'EUR', false, '€50.00' ),
			'Multi-Currency off, stale enabled currencies' => array( false, array( 'USD', 'EUR' ), '1', null, 'USD', false, '$50.00' ),
			'filter forces the code while Multi-Currency is off' => array( false, array( 'USD', 'EUR' ), null, true, 'USD', false, '$50.00 USD' ),
			'filter removes the code while Multi-Currency is on' => array( true, array( 'USD', 'EUR' ), null, false, 'USD', true, '$50.00' ),
		);
	}

	/**
	 * @testdox An existing dispute note gets the unified identity backfilled instead of a second note.
	 */
	public function test_existing_dispute_note_backfills_unified_identity(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->save();

		$note           = 'Legacy dispute note';
		$dispute_id     = 'dp_legacy';
		$status         = 'needs_response';
		$note_type      = 'created_dispute';
		$legacy_note_id = $order->add_order_note( $note );

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
	 * @testdox New dispute identities suppress changed-content replay and run the side effect once.
	 */
	public function test_new_dispute_identity_suppresses_replay_and_runs_side_effect_once(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->save();

		$dispute_id       = 'dp_new';
		$status           = 'needs_response';
		$note_type        = 'created_dispute';
		$before_add_calls = 0;
		$before_add       = static function () use ( &$before_add_calls ): void {
			++$before_add_calls;
		};

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
	 * @testdox A replayed created webhook does not duplicate the open-dispute record, note, or on-hold hold.
	 *
	 * Client `os:591-594`: `mark_payment_dispute_created()` dedupes on the note's
	 * own identity, so a replay writes neither a second note nor a second
	 * `on-hold` transition.
	 *
	 * T.3 Task 2 (`plan-task-t3.md`): fed the REC-DC recorded dispute object
	 * (`Fixtures/rec-t3-dispute-created-events.json`, pair `accept_case_created`) instead of the
	 * hand-built `get_created_event_object()` fixture.
	 */
	public function test_replayed_created_webhook_does_not_duplicate_record(): void {
		$order   = $this->create_disputable_order();
		$dispute = $this->load_recorded_dispute_object( 'accept_case_created' );

		$this->invoke_private(
			'process_dispute_created',
			array( $order, $dispute, 'ch_multi', 'txn_multi' )
		);
		$order = wc_get_order( $order->get_id() );
		$this->invoke_private(
			'process_dispute_created',
			array( $order, $dispute, 'ch_multi', 'txn_multi' )
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( array( $dispute['id'] ), $order->get_meta( '_wcpay_open_dispute_ids', true ) );
		$this->assertCount( 1, $this->find_order_note( $order, 'Payment has been disputed' ), 'A replayed created webhook must leave exactly one created note.' );
		$this->assertSame( 'on-hold', $order->get_status(), 'A replayed created webhook must keep the order on-hold.' );
	}

	/**
	 * @testdox A dispute created webhook refused by a held order payment lock writes nothing, and a second delivery after the lock is released applies it.
	 */
	public function test_created_webhook_refused_by_the_order_payment_lock_applies_on_a_second_delivery(): void {
		$order = $this->create_disputable_order();
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->update_meta_data( '_charge_id', 'ch_lock_contention' );
		$order->save();

		$event           = $this->get_created_event_object( 'dp_lock_contention', 'needs_response' );
		$event['charge'] = 'ch_lock_contention';
		$payment_store   = wc_get_container()->get( OrderPaymentStore::class );
		$vocabulary      = new WooPaymentsPersistenceVocabulary();

		$this->assertNotNull( $payment_store->claim_order_payment_lock_for_operation( $order, $vocabulary, 'pi_lock_holder', 'payment operation' ) );

		try {
			try {
				$this->sut->process( 'charge.dispute.created', $event );
				$this->fail( 'A dispute webhook must fail for retry while a lifecycle event holds the order payment lock.' );
			} catch ( OrderPaymentLockRefusedException $exception ) {
				// The reliability service retries this exception for every event type (WooPaymentsWebhookReliabilityServiceTest).
				$this->assertSame( $order->get_id(), $exception->get_order_id() );
			}
		} finally {
			$this->clear_order_payment_lock( $order, $vocabulary );
		}

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertSame( 'processing', $order->get_status(), 'A contended dispute webhook must not silently change the order state.' );
		$this->assertSame( '', $order->get_meta( '_wcpay_open_dispute_ids', true ), 'A contended dispute webhook must not write an open-dispute record.' );
		$this->assertCount( 0, $this->find_order_note( $order, 'Payment has been disputed' ), 'A contended dispute webhook must not add its note before retry.' );

		$this->sut->process( 'charge.dispute.created', $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status(), 'The retried dispute webhook must restore the dispute hold.' );
		$this->assertSame( array( 'dp_lock_contention' ), $order->get_meta( '_wcpay_open_dispute_ids', true ), 'The retried dispute webhook must persist its open-dispute record.' );
		$this->assertCount( 1, $this->find_order_note( $order, 'Payment has been disputed' ), 'The retried dispute webhook must add exactly one note.' );
	}

	/**
	 * @testdox A $event_type delivery refused by a held order payment lock writes nothing to the order.
	 * @dataProvider dispute_events_after_created
	 *
	 * @param string $event_type Dispute event type.
	 */
	public function test_dispute_event_after_created_is_refused_by_a_held_order_payment_lock( string $event_type ): void {
		$order = $this->create_disputable_order();
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_status( 'on-hold' );
		$order->update_meta_data( '_charge_id', 'ch_locked_close' );
		$order->update_meta_data( '_wcpay_open_dispute_ids', array( 'dp_locked_close' ) );
		$order->save();
		$payment_store = wc_get_container()->get( OrderPaymentStore::class );
		$vocabulary    = new WooPaymentsPersistenceVocabulary();
		$this->assertNotNull( $payment_store->claim_order_payment_lock_for_operation( $order, $vocabulary, 'pi_lock_holder', 'payment operation' ) );

		try {
			$this->sut->process(
				$event_type,
				array(
					'id'     => 'dp_locked_close',
					'charge' => 'ch_locked_close',
					'status' => 'won',
				)
			);
			$this->fail( 'The delivery must be refused while another operation holds the order payment lock.' );
		} catch ( OrderPaymentLockRefusedException $exception ) {
			$this->assertSame( $order->get_id(), $exception->get_order_id() );
		} finally {
			$this->clear_order_payment_lock( $order, $vocabulary );
		}

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( array( 'dp_locked_close' ), $order->get_meta( '_wcpay_open_dispute_ids', true ) );
		$dispute_notes = array_filter(
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
			static fn( $note ): bool => false !== stripos( $note->content, 'dispute' )
		);
		$this->assertCount( 0, $dispute_notes );
	}

	/** @return array<string,array{string}> */
	public static function dispute_events_after_created(): array {
		return array(
			'closed'  => array( 'charge.dispute.closed' ),
			'updated' => array( 'charge.dispute.updated' ),
		);
	}

	/**
	 * @testdox A dispute close re-reads the order under the lock, so a sibling dispute opened just before it stays open and keeps the hold.
	 */
	public function test_dispute_closed_keeps_a_sibling_dispute_opened_before_its_lock(): void {
		$order = $this->create_disputable_order();
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_status( 'on-hold' );
		$order->update_meta_data( '_charge_id', 'ch_sibling' );
		$order->update_meta_data( '_wcpay_open_dispute_ids', array( 'dp_first' ) );
		$order->save();
		$store = new class() extends OrderPaymentStore {
			/**
			 * Whether the concurrent dispute has been recorded.
			 *
			 * @var bool
			 */
			public bool $sibling_recorded = false;

			/**
			 * Record a sibling dispute, as a concurrent dispute created delivery would under the lock, then grant the claim.
			 *
			 * @param WC_Order                               $order     Order being locked.
			 * @param ProviderPersistenceVocabularyInterface $vocabulary   Persistence profile.
			 * @param string|null                            $reference Payment reference.
			 * @param string                                 $operation Operation claiming the lock.
			 * @return string|null
			 */
			public function claim_order_payment_lock_for_operation( WC_Order $order, ProviderPersistenceVocabularyInterface $vocabulary, ?string $reference, string $operation ): ?string {
				unset( $vocabulary, $reference, $operation );
				if ( ! $this->sibling_recorded ) {
					$this->sibling_recorded = true;
					// A separate request loads its own copy of the order.
					$writer = new WC_Order( $order->get_id() );
					$writer->update_meta_data( '_wcpay_open_dispute_ids', array( 'dp_first', 'dp_sibling' ) );
					$writer->save();
				}

				return 'test_lock_token';
			}

			/**
			 * Release nothing: the claim above holds no lock.
			 *
			 * @param WC_Order                               $order      Order being unlocked.
			 * @param ProviderPersistenceVocabularyInterface $vocabulary    Persistence profile.
			 * @param string                                 $lock_token Claim token.
			 */
			public function release_order_payment_lock( WC_Order $order, ProviderPersistenceVocabularyInterface $vocabulary, string $lock_token ): void {
				unset( $order, $vocabulary, $lock_token );
			}
		};
		wc_get_container()->replace( OrderPaymentStore::class, $store );
		$handler = new WooPaymentsDisputeEventHandler();
		$handler->init(
			wc_get_container()->get( WooPaymentsLegacyRuntime::class ),
			new class() extends WooPaymentsApiClient {},
			wc_get_container()->get( WooPaymentsDisputeCacheService::class )
		);

		$handler->process(
			'charge.dispute.closed',
			array(
				'id'     => 'dp_first',
				'charge' => 'ch_sibling',
				'status' => 'won',
			)
		);

		$this->assertTrue( $store->sibling_recorded, 'The close must claim the order payment lock.' );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( array( 'dp_sibling' ), $order->get_meta( '_wcpay_open_dispute_ids', true ) );
		$this->assertSame( 'on-hold', $order->get_status(), 'The sibling dispute keeps the hold.' );
	}

	/**
	 * @testdox A dispute close keeps a site's own '__return_false' on the order emails it silences while it runs.
	 */
	public function test_dispute_closed_keeps_the_sites_own_email_filters(): void {
		$order = $this->create_disputable_order();
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_status( 'on-hold' );
		$order->update_meta_data( '_charge_id', 'ch_site_filter' );
		$order->save();
		add_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false' );

		try {
			$this->sut->process(
				'charge.dispute.closed',
				array(
					'id'     => 'dp_site_filter',
					'charge' => 'ch_site_filter',
					'status' => 'won',
				)
			);

			$this->assertSame( 10, has_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false' ), 'The site disabled this email; the close must leave that in place.' );
		} finally {
			remove_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false' );
		}
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * @testdox A lost partial $currency dispute whose summary cannot be fetched refunds the disputed amount from the event ($expected), not the whole order.
	 * @testWith ["USD", 300, "3.00"]
	 *           ["JPY", 300, "300.00"]
	 *
	 * @param string $currency Order and dispute currency.
	 * @param int    $amount   Dispute amount in the currency's minor units, as Stripe sends it.
	 * @param string $expected Refund amount.
	 */
	public function test_lost_partial_dispute_refunds_the_event_amount_when_the_summary_fetch_fails( string $currency, int $amount, string $expected ): void {
		$order = $this->create_disputable_order();
		$order->set_currency( $currency );
		$order->set_total( 'JPY' === $currency ? '1000' : '10.00' );
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_status( 'on-hold' );
		$order->update_meta_data( '_charge_id', 'ch_partial_lost' );
		$order->update_meta_data( '_wcpay_open_dispute_ids', array( 'dp_partial_lost' ) );
		$order->save();
		$handler = new WooPaymentsDisputeEventHandler();
		$handler->init(
			wc_get_container()->get( WooPaymentsLegacyRuntime::class ),
			new class() extends WooPaymentsApiClient {
				/**
				 * Fail as the platform does when it cannot answer.
				 *
				 * @param string $dispute_id Dispute ID.
				 * @return array<string,mixed>
				 * @throws WooPaymentsApiException For every dispute ID the platform would look up.
				 */
				public function get_dispute_summary( string $dispute_id ): array {
					if ( '' !== $dispute_id ) {
						throw new WooPaymentsApiException( 'Service unavailable.', 'wcpay_server_error', 503 );
					}

					return array();
				}
			},
			wc_get_container()->get( WooPaymentsDisputeCacheService::class )
		);

		// A Stripe Dispute object, the charge.dispute.closed event's data.object: amount and currency are the fields the
		// platform's summary copies into disputed_amount and currency (wpcom class-dispute-service.php:454-455).
		$handler->process(
			'charge.dispute.closed',
			array(
				'id'       => 'dp_partial_lost',
				'charge'   => 'ch_partial_lost',
				'status'   => 'lost',
				'amount'   => $amount,
				'currency' => strtolower( $currency ),
			)
		);

		$refunds = wc_get_order( $order->get_id() )->get_refunds();
		$this->assertCount( 1, $refunds );
		$this->assertSame( $expected, wc_format_decimal( $refunds[0]->get_amount(), 2 ) );
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
	 * @testdox A charge.dispute.created event holds the order its charge resolves to, whatever the order's payment method.
	 *
	 * Client 11.1.0 looks the order up with order_from_charge_id() and no gateway check
	 * (class-wc-payments-webhook-processing-service.php:712-724), then records the dispute and holds the order.
	 */
	public function test_dispute_created_applies_to_an_order_of_another_gateway(): void {
		$order = $this->create_disputable_order();
		$order->set_payment_method( 'bacs' );
		$order->update_meta_data( '_charge_id', 'ch_bacs_created' );
		$order->save();
		$event           = $this->get_created_event_object( 'dp_bacs_created', 'needs_response' );
		$event['charge'] = 'ch_bacs_created';

		$this->sut->process( 'charge.dispute.created', $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( array( 'dp_bacs_created' ), $order->get_meta( '_wcpay_open_dispute_ids', true ) );
		$this->assertCount( 1, $this->find_order_note( $order, 'Payment has been disputed' ) );
	}

	/**
	 * @testdox A charge.dispute.updated event on an order paid by another gateway keeps its status, adds one note and logs the mismatch.
	 *
	 * Client 11.1.0 notes the update on the charge's order whatever its gateway
	 * (class-wc-payments-webhook-processing-service.php:712-724).
	 */
	public function test_dispute_updated_on_an_order_paid_by_another_gateway_logs_the_mismatch(): void {
		$order = $this->create_disputable_order();
		$order->set_payment_method( 'bacs' );
		$order->update_meta_data( '_charge_id', 'ch_bacs_updated' );
		$order->save();
		$event           = $this->get_created_event_object( 'dp_bacs_updated', 'needs_response' );
		$event['charge'] = 'ch_bacs_updated';
		$logger          = RecordingWcLogger::install();

		$this->sut->process( 'charge.dispute.updated', $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertCount( 1, $this->find_order_note( $order, 'Payment dispute has been updated' ) );
		$mismatch_lines = array_keys(
			array_filter(
				$logger->lines,
				static fn( array $line ): bool => 'warning' === $line[0] && 0 === strpos( $line[1], 'order payment method mismatch: order ' . $order->get_id() . ', ' )
			)
		);
		$this->assertCount( 1, $mismatch_lines );
		$context = $logger->contexts[ $mismatch_lines[0] ];
		$this->assertSame( 'woopayments', $context['source'] );
		$this->assertSame( $order->get_id(), $context['order_id'] );
		$this->assertSame( 'charge.dispute.updated', $context['applied_operation'] );
		$this->assertSame( 'bacs', $context['payment_method'] );
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
	 * Load one recorded REC-DC dispute object (the webhook's `data.object`) by fixture pair key.
	 *
	 * @param string $pair REC-DC fixture pair key.
	 * @return array<string,mixed>
	 */
	private function load_recorded_dispute_object( string $pair ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$fixture = file_get_contents( __DIR__ . '/Fixtures/rec-t3-dispute-created-events.json' );
		$this->assertIsString( $fixture );
		$decoded = json_decode( $fixture, true );
		$this->assertIsArray( $decoded );

		foreach ( $decoded['entries'] as $entry ) {
			if ( is_array( $entry ) && ( $entry['pair'] ?? '' ) === $pair ) {
				return $entry['body']['data']['object'];
			}
		}

		$this->fail( "REC-DC fixture has no entry for pair '$pair'." );
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
