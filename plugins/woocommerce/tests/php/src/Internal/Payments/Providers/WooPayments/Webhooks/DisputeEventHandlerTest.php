<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Webhooks;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\EventOrderResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\OtherChargeRecorder;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLockRefusedException;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\RefundRowCapture;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\DisputeEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Tests\Internal\Payments\OrderPaymentLockTestTrait;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\RecordingWcLogger;
use RuntimeException;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the DisputeEventHandler class.
 */
class DisputeEventHandlerTest extends WC_Unit_Test_Case {

	use OrderPaymentLockTestTrait;

	/**
	 * The System Under Test.
	 *
	 * @var DisputeEventHandler
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
		$this->sut = new DisputeEventHandler();
		$this->sut->init(
			new class() extends Client {},
			wc_get_container()->get( WooPaymentsDatabaseCache::class ),
			wc_get_container()->get( WooPaymentsOrderNoteService::class ),
			wc_get_container()->get( OrderPaymentLock::class ),
			wc_get_container()->get( WooPaymentsPersistenceVocabulary::class ),
			wc_get_container()->get( EventOrderResolver::class ),
			wc_get_container()->get( OtherChargeRecorder::class )
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
	 * @testdox A charge.dispute.closed event escapes its status in the closed note it writes.
	 *
	 * @dataProvider provider_dispute_closed_note_escapes_status
	 *
	 * @param string $status  Dispute status carrying markup.
	 * @param string $note    Fragment that finds the closed note.
	 */
	public function test_dispute_closed_note_escapes_status( string $status, string $note ): void {
		$order = $this->create_disputable_order();
		$order->update_meta_data( '_charge_id', 'ch_123' );
		$order->save();

		// A Stripe Dispute, as charge.dispute.closed carries it; client 11.1.0 reads charge, status and id
		// (class-wc-payments-webhook-processing-service.php:748-754).
		$this->sut->process(
			'charge.dispute.closed',
			array(
				'id'     => 'dp_escape',
				'charge' => 'ch_123',
				'status' => $status,
			)
		);

		$notes = $this->find_order_note( wc_get_order( $order->get_id() ), $note );
		$this->assertCount( 1, $notes );
		$this->assertStringNotContainsString( '<script>', $notes[0]->content, 'The raw script tag must not appear in the closed note.' );
		$this->assertStringContainsString( '&lt;script&gt;', $notes[0]->content, 'The status must be HTML-escaped in the closed note.' );
	}

	/**
	 * Closed dispute statuses carrying markup, for each wording.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provider_dispute_closed_note_escapes_status(): array {
		return array(
			'dispute' => array( '<script>alert(1)</script>', 'Dispute has been closed with status' ),
			'inquiry' => array( 'warning_<script>alert(1)</script>', 'Payment inquiry has been closed with status' ),
		);
	}

	/**
	 * @testdox A charge.dispute.created event escapes the reason and the due date in the $_dataName note it writes.
	 *
	 * The reason is a translated label and the due date follows the store's date format, so markup can only come from a
	 * translation or that setting. This pins native's own escaping of both values: client 11.1.0 escapes only the note
	 * template and inserts them raw (class-wc-payments-order-service.php:2455-2504, :2471-2472, :2485-2486).
	 *
	 * @dataProvider provider_dispute_created_note_kinds
	 *
	 * @param string $status Dispute status.
	 * @param string $note   Fragment that finds the created note.
	 */
	public function test_dispute_created_note_escapes_reason_and_due_by( string $status, string $note ): void {
		$order = $this->create_disputable_order();
		$order->update_meta_data( '_charge_id', 'ch_escape' );
		$order->save();
		add_filter(
			'gettext',
			static fn( string $translation, string $text ): string => 'Transaction unauthorized' === $text ? '<b>reason</b>' : $translation,
			10,
			2
		);
		add_filter( 'woocommerce_date_format', static fn(): string => '\<\i\>Y\<\/\i\>' );
		$event           = $this->get_created_event_object( 'dp_escape', $status );
		$event['charge'] = 'ch_escape';

		$this->sut->process( 'charge.dispute.created', $event );

		$notes = $this->find_order_note( wc_get_order( $order->get_id() ), $note );
		$this->assertCount( 1, $notes );
		$this->assertStringNotContainsString( '<b>reason</b>', $notes[0]->content, 'The raw reason markup must not appear in the created note.' );
		$this->assertStringNotContainsString( '<i>', $notes[0]->content, 'The raw due date markup must not appear in the created note.' );
		$this->assertStringContainsString( '&lt;b&gt;reason&lt;/b&gt;', $notes[0]->content, 'The reason must be HTML-escaped in the created note.' );
		$this->assertStringContainsString( '&lt;i&gt;', $notes[0]->content, 'The due date must be HTML-escaped in the created note.' );
	}

	/**
	 * Dispute statuses for the two created notes and the fragment that finds each.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provider_dispute_created_note_kinds(): array {
		return array(
			'dispute' => array( 'needs_response', 'Payment has been disputed for' ),
			'inquiry' => array( 'warning_needs_response', 'A payment inquiry has been raised for' ),
		);
	}

	/**
	 * @testdox The amount in a charge.dispute.created note follows the WooPayments extension's explicit-price rule.
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
	public function test_formatted_dispute_amount_follows_the_woopayments_extension_explicit_price_rule( bool $core_multi_currency, array $enabled_currencies, ?string $plugin_flag, ?bool $filter_result, string $order_currency, bool $expected_default, string $expected ): void {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', $enabled_currencies );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.9' );
		null === $plugin_flag ? delete_option( '_wcpay_feature_customer_multi_currency' ) : update_option( '_wcpay_feature_customer_multi_currency', $plugin_flag );
		$arbiter   = $this->getMockBuilder( MultiCurrencyRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_builtin_owner' ) )
			->getMock();
		$container = wc_get_container();
		// Keep the real feature definition working if FeaturesController registers it while the mock is in place.
		$arbiter->init( $container->get( WooPaymentsRuntimeArbiter::class ), $container->get( LegacyProxy::class ), $container->get( MultiCurrencyFeatureController::class ) );
		$arbiter->method( 'is_builtin_owner' )->willReturn( $core_multi_currency );
		wc_get_container()->replace( MultiCurrencyRuntimeArbiter::class, $arbiter );
		$defaults = array();
		add_filter(
			'wcpay_multi_currency_should_output_explicit_price',
			static function ( bool $current_default ) use ( &$defaults, $filter_result ): bool {
				$defaults[] = $current_default;
				return $filter_result ?? $current_default;
			}
		);
		$order = $this->create_disputable_order();
		$order->set_currency( $order_currency );
		$order->update_meta_data( '_charge_id', 'ch_amount' );
		$order->save();
		$event           = $this->get_created_event_object( 'dp_amount', 'needs_response' );
		$event['charge'] = 'ch_amount';
		$event['amount'] = 5000;

		$this->sut->process( 'charge.dispute.created', $event );

		$notes = $this->find_order_note( wc_get_order( $order->get_id() ), 'Payment has been disputed for' );
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'disputed for ' . $expected . ' with reason', html_entity_decode( wp_strip_all_tags( $notes[0]->content ) ) );
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
	 * @testdox A won dispute close on an order already refunded in full leaves it refunded, not completed.
	 *
	 * @dataProvider provider_won_close_on_refunded_order
	 *
	 * @param callable $make_refund Brings the order into that state.
	 */
	public function test_won_close_on_a_refunded_order_leaves_it_refunded( callable $make_refund ): void {
		$order = $this->create_disputable_order();
		$order->update_meta_data( '_charge_id', 'ch_refunded' );
		$order->save();
		$make_refund( $order );

		// A Stripe Dispute, as charge.dispute.closed carries it; client 11.1.0 reads charge, status and id
		// (class-wc-payments-webhook-processing-service.php:748-754).
		$this->sut->process(
			'charge.dispute.closed',
			array(
				'id'     => 'dp_refunded',
				'charge' => 'ch_refunded',
				'status' => 'won',
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'refunded', $order->get_status() );
		$this->assertNotEmpty( $this->find_order_note( $order, 'The order was not marked as completed because it has already been fully refunded.' ) );
	}

	/**
	 * Orders refunded in full before a won dispute close.
	 *
	 * @return array<string,array{0:callable}>
	 */
	public function provider_won_close_on_refunded_order(): array {
		return array(
			'refunded status'           => array(
				static function ( WC_Order $order ): void {
					$order->set_status( 'refunded' );
					$order->save();
				},
			),
			'on hold, refunded in full' => array(
				static function ( WC_Order $order ): void {
					wc_create_refund(
						array(
							'order_id'       => $order->get_id(),
							'amount'         => 10.00,
							'refund_payment' => false,
						)
					);
					wc_get_order( $order->get_id() )->update_status( 'on-hold' );
				},
			),
		);
	}

	/**
	 * @testdox Two disputes on one charge each get their own note and record; closing one keeps the hold, closing the last completes the order.
	 */
	public function test_sibling_disputes_on_one_charge(): void {
		$order = $this->create_disputable_order();
		$order->update_meta_data( '_charge_id', 'ch_multi' );
		$order->save();
		$created           = $this->get_created_event_object( 'dp_b1', 'needs_response' );
		$created['charge'] = 'ch_multi';

		$this->sut->process( 'charge.dispute.created', $created );
		$this->sut->process( 'charge.dispute.created', array_merge( $created, array( 'id' => 'dp_b2' ) ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( array( 'dp_b1', 'dp_b2' ), $order->get_meta( '_wcpay_open_dispute_ids', true ) );
		$this->assertNotEmpty( $this->find_order_note( $order, '(Dispute ID: dp_b1)' ) );
		$this->assertNotEmpty( $this->find_order_note( $order, '(Dispute ID: dp_b2)' ) );

		// A Stripe Dispute, as charge.dispute.closed carries it; client 11.1.0 reads charge, status and id
		// (class-wc-payments-webhook-processing-service.php:748-754).
		$this->sut->process(
			'charge.dispute.closed',
			array(
				'id'     => 'dp_b1',
				'charge' => 'ch_multi',
				'status' => 'won',
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( array( 'dp_b2' ), $order->get_meta( '_wcpay_open_dispute_ids', true ) );
		$this->assertNotEmpty( $this->find_order_note( $order, 'The order was not marked as completed because 1 other dispute on this payment is still open.' ) );

		// A Stripe Dispute, as charge.dispute.closed carries it; client 11.1.0 reads charge, status and id
		// (class-wc-payments-webhook-processing-service.php:748-754).
		$this->sut->process(
			'charge.dispute.closed',
			array(
				'id'     => 'dp_b2',
				'charge' => 'ch_multi',
				'status' => 'won',
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
	 * Fed the recorded dispute object (`Fixtures/platform-dispute-created-events.json`, pair
	 * `accept_case_created`).
	 */
	public function test_replayed_created_webhook_does_not_duplicate_record(): void {
		$dispute = $this->load_recorded_dispute_object( 'accept_case_created' );
		$order   = $this->create_disputable_order();
		$order->set_transaction_id( (string) $dispute['payment_intent'] );
		$order->update_meta_data( '_intent_id', (string) $dispute['payment_intent'] );
		$order->update_meta_data( '_charge_id', (string) $dispute['charge'] );
		$order->save();

		$this->sut->process( 'charge.dispute.created', $dispute );
		$this->sut->process( 'charge.dispute.created', $dispute );

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

		$event              = $this->get_created_event_object( 'dp_lock_contention', 'needs_response' );
		$event['charge']    = 'ch_lock_contention';
		$order_payment_lock = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary         = new WooPaymentsPersistenceVocabulary();

		$this->assertNotNull( $order_payment_lock->claim( $order, $vocabulary, 'pi_lock_holder', 'payment operation' ) );

		try {
			try {
				$this->sut->process( 'charge.dispute.created', $event );
				$this->fail( 'A dispute webhook must fail for retry while a lifecycle event holds the order payment lock.' );
			} catch ( OrderPaymentLockRefusedException $exception ) {
				// The reliability service retries this exception for every event type (WebhookReliabilityServiceTest).
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
		$order_payment_lock = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary         = new WooPaymentsPersistenceVocabulary();
		$this->assertNotNull( $order_payment_lock->claim( $order, $vocabulary, 'pi_lock_holder', 'payment operation' ) );

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
		$store = new class() extends OrderPaymentLock {
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
			public function claim( WC_Order $order, ProviderPersistenceVocabularyInterface $vocabulary, ?string $reference, string $operation ): ?string {
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
			public function release( WC_Order $order, ProviderPersistenceVocabularyInterface $vocabulary, string $lock_token ): void {
				unset( $order, $vocabulary, $lock_token );
			}
		};
		wc_get_container()->replace( OrderPaymentLock::class, $store );
		$handler = new DisputeEventHandler();
		$handler->init(
			new class() extends Client {},
			wc_get_container()->get( WooPaymentsDatabaseCache::class ),
			wc_get_container()->get( WooPaymentsOrderNoteService::class ),
			wc_get_container()->get( OrderPaymentLock::class ),
			wc_get_container()->get( WooPaymentsPersistenceVocabulary::class ),
			wc_get_container()->get( EventOrderResolver::class ),
			wc_get_container()->get( OtherChargeRecorder::class )
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
		$handler = new DisputeEventHandler();
		$handler->init(
			new class() extends Client {
				/**
				 * Fail as the platform does when it cannot answer.
				 *
				 * @param string $dispute_id Dispute ID.
				 * @return array<string,mixed>
				 * @throws ApiException For every dispute ID the platform would look up.
				 */
				public function get_dispute_summary( string $dispute_id ): array {
					if ( '' !== $dispute_id ) {
						throw new ApiException( 'Service unavailable.', 'wcpay_server_error', 503 );
					}

					return array();
				}
			},
			wc_get_container()->get( WooPaymentsDatabaseCache::class ),
			wc_get_container()->get( WooPaymentsOrderNoteService::class ),
			wc_get_container()->get( OrderPaymentLock::class ),
			wc_get_container()->get( WooPaymentsPersistenceVocabulary::class ),
			wc_get_container()->get( EventOrderResolver::class ),
			wc_get_container()->get( OtherChargeRecorder::class )
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
	 * @testdox A $event_type event on a charge ID carrying markup writes a note whose link holds the ID percent-encoded, with no injected element.
	 *
	 * Provider charge IDs (ch_, py_) are left as they are by the encoding, so the note stays the WooPayments extension's.
	 *
	 * @dataProvider provider_dispute_note_events
	 *
	 * @param string              $event_type Dispute event type.
	 * @param array<string,mixed> $dispute    Dispute object fields of the event.
	 * @param string              $note       Fragment that finds the note.
	 */
	public function test_dispute_note_link_encodes_a_malformed_charge_id( string $event_type, array $dispute, string $note ): void {
		$charge_id = 'ch_x"><script>alert(1)</script><a href="https://example.test/';
		$order     = $this->create_disputable_order();
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->save();

		$this->sut->process( $event_type, array_merge( $dispute, array( 'charge' => $charge_id ) ) );

		$notes = $this->find_order_note( wc_get_order( $order->get_id() ), $note );
		$this->assertCount( 1, $notes );
		$this->assertStringNotContainsString( '<script', $notes[0]->content );
		$this->assertStringNotContainsString( 'example.test/"', $notes[0]->content );
		$this->assertStringContainsString( 'id=' . rawurlencode( $charge_id ) . '"', $notes[0]->content );
	}

	/**
	 * Dispute events that write a note linking to the disputed charge.
	 *
	 * Stripe Dispute objects with the fields client 11.1.0 reads for each event (class-wc-payments-webhook-processing-service.php:
	 * created :699-710, closed :748-754, updated :794-806); the test adds the charge.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:string}>
	 */
	public function provider_dispute_note_events(): array {
		return array(
			'created' => array(
				'charge.dispute.created',
				array(
					'id'               => 'dp_markup',
					'status'           => 'needs_response',
					'amount'           => 1000,
					'reason'           => 'fraudulent',
					'evidence_details' => array( 'due_by' => 1893456000 ),
				),
				'Payment has been disputed',
			),
			'closed'  => array(
				'charge.dispute.closed',
				array(
					'id'     => 'dp_markup',
					'status' => 'won',
				),
				'Dispute has been closed',
			),
			'updated' => array(
				'charge.dispute.updated',
				array(
					'id'     => 'dp_markup',
					'status' => 'under_review',
				),
				'Payment dispute has been updated',
			),
		);
	}

	/**
	 * @testdox A $status close replayed onto the closed note the WooPayments extension wrote changes nothing.
	 *
	 * Client 11.1.0 writes the note in generate_dispute_closed_note() (class-wc-payments-order-service.php:2516-2558) with
	 * compose_dispute_url() (:2681-2690), and skips the close when a note with the same text exists (:619-623,
	 * order_note_exists() at :810-823).
	 *
	 * @dataProvider woopayments_extension_closed_note_provider
	 *
	 * @param string $status Dispute status.
	 */
	public function test_woopayments_extension_era_closed_note_suppresses_replayed_close( string $status ): void {
		$order = $this->create_disputable_order();
		$order->set_status( 'on-hold' );
		$order->update_meta_data( '_charge_id', 'ch_cutover' );
		$order->update_meta_data( '_wcpay_payment_transaction_id', 'txn_cutover' );
		$order->save();
		$order->add_order_note( $this->get_woopayments_extension_note_without_dispute_id( 'charge.dispute.closed', $status, 'ch_cutover' ) . ' (Dispute ID: dp_cutover)' );

		// A Stripe Dispute, as charge.dispute.closed carries it; client 11.1.0 reads charge, status and id, then fetches the
		// dispute summary (class-wc-payments-webhook-processing-service.php:748-781). Amount and currency are read only by
		// native, for a lost close whose summary cannot be fetched.
		$this->sut->process(
			'charge.dispute.closed',
			array(
				'id'       => 'dp_cutover',
				'charge'   => 'ch_cutover',
				'status'   => $status,
				'amount'   => 1000,
				'currency' => 'usd',
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertCount( 1, $this->find_order_note( $order, 'Dispute has been closed with status ' . $status ) );
		$this->assertCount( 0, $order->get_refunds() );
	}

	/**
	 * Closed notes the WooPayments extension wrote.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function woopayments_extension_closed_note_provider(): array {
		return array(
			'won'  => array( 'won' ),
			'lost' => array( 'lost' ),
		);
	}

	/**
	 * @testdox A created event replayed onto the created note the WooPayments extension wrote changes nothing, and the note gets the dispute's note identity.
	 *
	 * Client 11.1.0 writes the note in generate_dispute_created_note() (class-wc-payments-order-service.php:2455-2504) with
	 * compose_dispute_url() (:2681-2690), escaping the text around the link (WC_Payments_Utils::esc_interpolated_html()),
	 * and skips the hold when a note with the same text exists (:590-594).
	 */
	public function test_woopayments_extension_era_created_note_suppresses_replayed_created(): void {
		$order = $this->create_disputable_order();
		$order->update_meta_data( '_charge_id', 'ch_cutover_created' );
		$order->update_meta_data( '_wcpay_payment_transaction_id', 'txn_cutover_created' );
		$order->save();
		$event             = $this->get_created_event_object( 'dp_cutover_created', 'needs_response' );
		$event['charge']   = 'ch_cutover_created';
		$extension_note_id = $order->add_order_note( $this->get_woopayments_extension_note_without_dispute_id( 'charge.dispute.created', 'needs_response', 'ch_cutover_created' ) . ' (Dispute ID: dp_cutover_created)' );

		$this->sut->process( 'charge.dispute.created', $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( '', $order->get_meta( '_wcpay_open_dispute_ids', true ) );
		$this->assertCount( 1, $this->find_order_note( $order, 'Payment has been disputed' ) );
		$this->assertSame(
			hash( 'sha256', 'dispute:dp_cutover_created|needs_response|created_dispute' ),
			get_comment_meta( $extension_note_id, '_wc_woopayments_note_identity', true ),
			'The extension-era note gets the dispute note identity, so a later write finds it whatever its text reads.'
		);
	}

	/**
	 * @testdox A dispute update replayed onto the update note the WooPayments extension wrote adds no second note.
	 *
	 * Client 11.1.0 writes the note in process_webhook_dispute_updated() (class-wc-payments-webhook-processing-service.php:825-845)
	 * and adds it only when no note with the same text exists (:847-849).
	 */
	public function test_woopayments_extension_era_update_note_suppresses_replayed_update(): void {
		$order = $this->create_disputable_order();
		$order->update_meta_data( '_charge_id', 'ch_cutover_updated' );
		$order->update_meta_data( '_wcpay_payment_transaction_id', 'txn_cutover_updated' );
		$order->save();
		$order->add_order_note( $this->get_woopayments_extension_note_without_dispute_id( 'charge.dispute.updated', 'under_review', 'ch_cutover_updated' ) . ' (Dispute ID: dp_cutover_updated)' );

		// A Stripe Dispute, as charge.dispute.updated carries it; client 11.1.0 reads id and charge
		// (class-wc-payments-webhook-processing-service.php:794-806), and native also reads its status.
		$this->sut->process(
			'charge.dispute.updated',
			array(
				'id'     => 'dp_cutover_updated',
				'charge' => 'ch_cutover_updated',
				'status' => 'under_review',
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertCount( 1, $this->find_order_note( $order, 'Payment dispute has been updated' ) );
	}

	/**
	 * @testdox A $event_type event for a second dispute on the charge applies over the first dispute's note that the WooPayments extension wrote without the dispute ID.
	 *
	 * Client 11.1.0 matches a dispute note by its exact text (order_note_exists(), class-wc-payments-order-service.php:810-823),
	 * and the text carries the dispute ID since 11.0.0 (#11991, #11993). A note from an earlier version is not matched, and
	 * no open-dispute record (added in 11.1.0, #11997) names its dispute.
	 *
	 * @dataProvider provider_second_dispute_over_a_note_without_the_dispute_id
	 *
	 * @param string              $event_type        Dispute event type.
	 * @param array<string,mixed> $dispute           Second dispute's object fields; the test adds the charge.
	 * @param string              $initial_status    Order status left by the first dispute.
	 * @param string              $expected_status   Order status after the second dispute's event.
	 * @param string|string[]     $expected_open_ids Open-dispute record after the event ('' when there is none).
	 * @param int                 $expected_refunds  Local refunds after the event.
	 * @param string              $note              Fragment that finds both disputes' notes.
	 */
	public function test_second_disputes_event_applies_over_a_note_without_the_dispute_id( string $event_type, array $dispute, string $initial_status, string $expected_status, $expected_open_ids, int $expected_refunds, string $note ): void {
		$order = $this->create_disputable_order();
		$order->set_status( $initial_status );
		$order->update_meta_data( '_charge_id', 'ch_two_disputes' );
		$order->save();
		$order->add_order_note( $this->get_woopayments_extension_note_without_dispute_id( $event_type, (string) $dispute['status'], 'ch_two_disputes' ) );

		$this->sut->process( $event_type, array_merge( $dispute, array( 'charge' => 'ch_two_disputes' ) ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( $expected_status, $order->get_status() );
		$this->assertSame( $expected_open_ids, $order->get_meta( '_wcpay_open_dispute_ids', true ) );
		$this->assertCount( $expected_refunds, $order->get_refunds() );
		$this->assertCount( 2, $this->find_order_note( $order, $note ) );
		$this->assertCount( 1, $this->find_order_note( $order, '(Dispute ID: dp_second)' ) );
	}

	/**
	 * Second disputes on a charge whose first dispute's note carries no dispute ID.
	 *
	 * Stripe Dispute objects with the fields client 11.1.0 reads for each event (class-wc-payments-webhook-processing-service.php:
	 * created :699-710, closed :748-781, updated :794-806). The closed ones add amount and currency, which only native reads:
	 * for a lost close whose dispute summary cannot be fetched.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:string,3:string,4:string|string[],5:int,6:string}>
	 */
	public function provider_second_dispute_over_a_note_without_the_dispute_id(): array {
		$closed = array(
			'id'       => 'dp_second',
			'amount'   => 1000,
			'currency' => 'usd',
		);

		return array(
			'closed, won'  => array( 'charge.dispute.closed', array_merge( $closed, array( 'status' => 'won' ) ), 'on-hold', 'completed', '', 0, 'Dispute has been closed with status won' ),
			'closed, lost' => array( 'charge.dispute.closed', array_merge( $closed, array( 'status' => 'lost' ) ), 'on-hold', 'refunded', '', 1, 'Dispute has been closed with status lost' ),
			'created'      => array( 'charge.dispute.created', $this->get_created_event_object( 'dp_second', 'needs_response' ), 'completed', 'on-hold', array( 'dp_second' ), 0, 'Payment has been disputed' ),
			'updated'      => array(
				'charge.dispute.updated',
				array(
					'id'     => 'dp_second',
					'status' => 'under_review',
				),
				'on-hold',
				'on-hold',
				'',
				0,
				'Payment dispute has been updated',
			),
		);
	}

	/**
	 * Build a dispute note as the WooPayments extension wrote it, without the dispute ID it appends since 11.0.0.
	 *
	 * Client 11.1.0: created generate_dispute_created_note() (class-wc-payments-order-service.php:2455-2504), closed
	 * generate_dispute_closed_note() (:2516-2558), updated process_webhook_dispute_updated()
	 * (class-wc-payments-webhook-processing-service.php:825-845). The created note is the one get_created_event_object() makes.
	 *
	 * @param string $event_type Dispute event type.
	 * @param string $status     Dispute status, for a closed note.
	 * @param string $charge_id  Disputed charge ID.
	 * @return string
	 */
	private function get_woopayments_extension_note_without_dispute_id( string $event_type, string $status, string $charge_id ): string {
		if ( 'charge.dispute.created' === $event_type ) {
			return 'Payment has been disputed for ' . wc_price( 10.0, array( 'currency' => 'USD' ) ) . ' with reason &quot;Transaction unauthorized&quot;. <a href="' . $this->get_woopayments_extension_dispute_url( $charge_id ) . '" target="_blank" rel="noopener noreferrer">Response due by ' . date_i18n( wc_date_format(), 1893456000 ) . '</a>.';
		}

		if ( 'charge.dispute.closed' === $event_type ) {
			return 'Dispute has been closed with status ' . $status . '. See <a href="' . $this->get_woopayments_extension_dispute_url( $charge_id ) . '" target="_blank" rel="noopener noreferrer">dispute overview</a> for more details.';
		}

		return 'Payment dispute has been updated. See <a href="' . add_query_arg( array( 'id' => $charge_id ), admin_url( 'admin.php?page=wc-admin&path=/payments/transactions/details' ) ) . '">dispute overview</a> for more details.';
	}

	/**
	 * Build the dispute details URL as the WooPayments extension put it in its created and closed dispute notes.
	 *
	 * Client 11.1.0 class-wc-payments-order-service.php:2681-2690 (compose_dispute_url()).
	 *
	 * @param string $charge_id Disputed charge ID.
	 * @return string
	 */
	private function get_woopayments_extension_dispute_url( string $charge_id ): string {
		return add_query_arg(
			array(
				'page' => 'wc-admin',
				'path' => rawurlencode( '/payments/transactions/details' ),
				'id'   => $charge_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * @testdox A charge.dispute.created event on a charge recorded on an order another gateway paid adds one note, keeps the status and logs one line.
	 *
	 * Client 11.1.0 holds the charge's order whatever its gateway (class-wc-payments-webhook-processing-service.php:712-724);
	 * here the dispute is recorded, because the charge is not the order's payment. The dispute carries its `payment_intent`
	 * (https://docs.stripe.com/api/disputes/object#dispute_object-payment_intent).
	 */
	public function test_dispute_created_on_an_order_paid_by_another_gateway_is_recorded(): void {
		$order                   = $this->create_order_paid_by_another_gateway( 'bacs', 'ch_bacs_created' );
		$event                   = $this->get_created_event_object( 'dp_bacs_created', 'needs_response' );
		$event['charge']         = 'ch_bacs_created';
		$event['payment_intent'] = 'pi_bacs_created';
		$logger                  = RecordingWcLogger::install();

		$this->sut->process( 'charge.dispute.created', $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( '', $order->get_meta( '_wcpay_open_dispute_ids', true ) );
		$this->assertCount( 0, $this->find_order_note( $order, 'Payment has been disputed' ) );
		$record_notes = $this->find_order_note( $order, 'was opened on WooPayments charge' );
		$this->assertCount( 1, $record_notes );
		$this->assertStringContainsString( 'dp_bacs_created', $record_notes[0]->content );
		$this->assertStringContainsString( 'ch_bacs_created', $record_notes[0]->content );
		$record_lines = array_keys(
			array_filter(
				$logger->lines,
				static fn( array $line ): bool => 'warning' === $line[0] && 'other charge recorded: order ' . $order->get_id() . ', charge.dispute.created, charge ch_bacs_created, order payment method bacs' === $line[1]
			)
		);
		$this->assertCount( 1, $record_lines );
		$this->assertSame( 'woopayments', $logger->contexts[ $record_lines[0] ]['source'] );
	}

	/**
	 * @testdox A charge.dispute.updated event on a charge recorded on an order another gateway paid adds one note naming the update, and keeps the status.
	 *
	 * Client 11.1.0 notes the update on the charge's order whatever its gateway
	 * (class-wc-payments-webhook-processing-service.php:794-853).
	 */
	public function test_dispute_updated_on_an_order_paid_by_another_gateway_is_recorded(): void {
		$order                   = $this->create_order_paid_by_another_gateway( 'bacs', 'ch_bacs_updated' );
		$event                   = $this->get_created_event_object( 'dp_bacs_updated', 'under_review' );
		$event['charge']         = 'ch_bacs_updated';
		$event['payment_intent'] = 'pi_bacs_updated';

		$this->sut->process( 'charge.dispute.updated', $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertCount( 0, $this->find_order_note( $order, 'Payment dispute has been updated. See' ) );
		$record_notes = $this->find_order_note( $order, 'Payment dispute has been updated: the dispute' );
		$this->assertCount( 1, $record_notes );
		$this->assertStringContainsString( 'under_review', $record_notes[0]->content );
	}

	/**
	 * @testdox A dispute created event retried after the dispute closed ($status) leaves the order off hold with no open dispute.
	 * @testWith ["won", "dp_created_retry_won", "needs_response"]
	 *           ["lost", "dp_created_retry_lost", "needs_response"]
	 *           ["prevented", "dp_created_retry_prevented", "needs_response"]
	 *           ["warning_closed", "dp_created_retry_inquiry", "warning_needs_response"]
	 *
	 * The first created delivery fails writing its note, after the hold, the close lands, and the store's retry of the created
	 * event runs last. Stripe closes a dispute as won, lost or prevented and an inquiry as warning_closed
	 * (https://docs.stripe.com/api/disputes/object#dispute_object-status).
	 *
	 * @param string $status         Closing status.
	 * @param string $dispute_id     Dispute ID.
	 * @param string $created_status Status the created event carries.
	 */
	public function test_dispute_created_retried_after_the_close_does_not_reopen_it( string $status, string $dispute_id, string $created_status ): void {
		$order = $this->create_disputable_order();
		$order->update_meta_data( '_charge_id', 'ch_created_retry' );
		$order->save();
		$created    = array_merge( $this->get_created_event_object( $dispute_id, $created_status ), array( 'charge' => 'ch_created_retry' ) );
		$thrown     = 0;
		$throw_once = static function ( array $note_data ) use ( &$thrown, $dispute_id ): array {
			if ( false !== strpos( (string) ( $note_data['comment_content'] ?? '' ), $dispute_id ) && 0 === $thrown++ ) {
				throw new RuntimeException( 'Note write failed.' );
			}

			return $note_data;
		};
		add_filter( 'woocommerce_new_order_note_data', $throw_once );

		$caught = null;
		try {
			$this->sut->process( 'charge.dispute.created', $created );
		} catch ( RuntimeException $exception ) {
			$caught = $exception;
		}
		remove_filter( 'woocommerce_new_order_note_data', $throw_once );
		// A Stripe Dispute, as charge.dispute.closed carries it (https://docs.stripe.com/api/disputes/object): a lost close
		// refunds its amount.
		$this->sut->process(
			'charge.dispute.closed',
			array(
				'id'       => $dispute_id,
				'charge'   => 'ch_created_retry',
				'status'   => $status,
				'amount'   => 1000,
				'currency' => 'usd',
			)
		);
		$this->sut->process( 'charge.dispute.created', $created );

		$this->assertInstanceOf( RuntimeException::class, $caught, 'The first created delivery fails.' );
		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertNotSame( 'on-hold', $order->get_status() );
		$this->assertSame( '', $order->get_meta( '_wcpay_open_dispute_ids', true ) );
	}

	/**
	 * @testdox A lost dispute close retried after an error left its refund behind records one refund.
	 *
	 * wc_create_refund() deletes the refund it saved only when a hook throws an Exception (includes/wc-order-functions.php:749-754),
	 * so an Error from a refund hook leaves the row, the closed note is never written, and the store retries the event.
	 */
	public function test_lost_dispute_close_retried_after_an_error_left_its_refund_records_one_refund(): void {
		wc_get_container()->get( RefundRowCapture::class )->register();
		$order = $this->create_disputable_order();
		$order->set_status( 'on-hold' );
		$order->update_meta_data( '_charge_id', 'ch_lost_retry' );
		$order->update_meta_data( '_wcpay_open_dispute_ids', array( 'dp_lost_retry' ) );
		$order->save();
		$handler = new DisputeEventHandler();
		$handler->init(
			new class() extends Client {
				/**
				 * Answer with no summary, so the event's own amount is refunded.
				 *
				 * @param string $dispute_id Dispute ID.
				 * @return array<string,mixed>
				 */
				public function get_dispute_summary( string $dispute_id ): array {
					unset( $dispute_id );

					return array();
				}
			},
			wc_get_container()->get( WooPaymentsDatabaseCache::class ),
			wc_get_container()->get( WooPaymentsOrderNoteService::class ),
			wc_get_container()->get( OrderPaymentLock::class ),
			wc_get_container()->get( WooPaymentsPersistenceVocabulary::class ),
			wc_get_container()->get( EventOrderResolver::class ),
			wc_get_container()->get( OtherChargeRecorder::class )
		);
		// A Stripe Dispute, as charge.dispute.closed carries it (https://docs.stripe.com/api/disputes/object): a lost dispute of
		// 4.00 on a 10.00 order.
		$event      = array(
			'id'       => 'dp_lost_retry',
			'charge'   => 'ch_lost_retry',
			'status'   => 'lost',
			'amount'   => 400,
			'currency' => 'usd',
		);
		$thrown     = 0;
		$throw_once = static function () use ( &$thrown ): void {
			if ( 0 === $thrown++ ) {
				throw new \Error( 'Refund hook failed.' );
			}
		};
		add_action( 'woocommerce_order_partially_refunded', $throw_once );

		$caught = null;
		try {
			$handler->process( 'charge.dispute.closed', $event );
		} catch ( \Error $error ) {
			$caught = $error;
		}
		$handler->process( 'charge.dispute.closed', $event );
		remove_action( 'woocommerce_order_partially_refunded', $throw_once );

		$this->assertInstanceOf( \Error::class, $caught, 'The first delivery fails after the refund row is saved.' );
		$order   = wc_get_order( $order->get_id() );
		$refunds = $order->get_refunds();
		$this->assertCount( 1, $refunds );
		$this->assertSame( '4.00', wc_format_decimal( $refunds[0]->get_amount(), 2 ) );
		$this->assertCount( 1, $this->find_order_note( $order, 'Dispute has been closed with status' ) );
	}

	/**
	 * @testdox A lost charge.dispute.closed on a charge recorded on an order another gateway paid adds no refund, fetches nothing and keeps the status.
	 */
	public function test_lost_dispute_on_another_charge_adds_no_refund(): void {
		$order   = $this->create_order_paid_by_another_gateway( 'bacs', 'ch_bacs_lost' );
		$fetched = array();
		$handler = new DisputeEventHandler();
		$handler->init(
			new class( $fetched ) extends Client {
				/**
				 * Dispute IDs the handler asked a summary for.
				 *
				 * @var array<int,string>
				 */
				private array $fetched;

				/**
				 * Constructor.
				 *
				 * @param array<int,string> $fetched Receives the dispute IDs asked for.
				 */
				public function __construct( array &$fetched ) {
					$this->fetched = &$fetched;
				}

				/**
				 * Record the dispute ID asked for.
				 *
				 * @param string $dispute_id Dispute ID.
				 * @return array<string,mixed>
				 */
				public function get_dispute_summary( string $dispute_id ): array {
					$this->fetched[] = $dispute_id;

					return array();
				}
			},
			wc_get_container()->get( WooPaymentsDatabaseCache::class ),
			wc_get_container()->get( WooPaymentsOrderNoteService::class ),
			wc_get_container()->get( OrderPaymentLock::class ),
			wc_get_container()->get( WooPaymentsPersistenceVocabulary::class ),
			wc_get_container()->get( EventOrderResolver::class ),
			wc_get_container()->get( OtherChargeRecorder::class )
		);

		$handler->process(
			'charge.dispute.closed',
			array(
				'id'             => 'dp_bacs_lost',
				'charge'         => 'ch_bacs_lost',
				'payment_intent' => 'pi_bacs_lost',
				'status'         => 'lost',
				'amount'         => 1000,
				'currency'       => 'usd',
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertCount( 0, $order->get_refunds() );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( array(), $fetched );
		$record_notes = $this->find_order_note( $order, 'was closed with status lost' );
		$this->assertCount( 1, $record_notes );
	}

	/**
	 * Create an order another gateway paid, holding a WooPayments charge recorded on it.
	 *
	 * @param string $payment_method Order payment method.
	 * @param string $charge_id      Recorded charge ID.
	 * @return \WC_Order
	 */
	private function create_order_paid_by_another_gateway( string $payment_method, string $charge_id ): \WC_Order {
		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->set_payment_method( $payment_method );
		$order->set_total( '10.00' );
		$order->set_status( 'processing' );
		$order->set_date_paid( time() );
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->save();

		return $order;
	}

	/**
	 * @testdox A charge.dispute.created event on the charge that paid the order holds it after the merchant changed its payment method.
	 *
	 * The event's `payment_intent` is the Stripe Dispute field (https://docs.stripe.com/api/disputes/object#dispute_object-payment_intent).
	 */
	public function test_dispute_created_on_the_orders_own_charge_holds_it_whatever_its_payment_method(): void {
		$order = $this->create_disputable_order();
		$order->set_payment_method( 'bacs' );
		$order->update_meta_data( '_charge_id', 'ch_disputed' );
		$order->save();
		$event                   = $this->get_created_event_object( 'dp_own_charge', 'needs_response' );
		$event['charge']         = 'ch_disputed';
		$event['payment_intent'] = 'pi_disputed';

		$this->sut->process( 'charge.dispute.created', $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( array( 'dp_own_charge' ), $order->get_meta( '_wcpay_open_dispute_ids', true ) );
		$this->assertCount( 1, $this->find_order_note( $order, 'Payment has been disputed' ) );
	}

	/**
	 * Create an order suitable for dispute processing, in the state a WooPayments payment leaves it: paid, with the intent as its transaction ID.
	 *
	 * @return \WC_Order
	 */
	private function create_disputable_order(): \WC_Order {
		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_total( '10.00' );
		$order->set_status( 'processing' );
		$order->set_transaction_id( 'pi_disputed' );
		$order->update_meta_data( '_intent_id', 'pi_disputed' );
		$order->save();

		return $order;
	}

	/**
	 * Build a dispute created event object fixture.
	 *
	 * A Stripe Dispute, as charge.dispute.created carries it; client 11.1.0 reads id, charge, reason, amount,
	 * evidence_details.due_by and status (class-wc-payments-webhook-processing-service.php:699-710). Tests add the charge.
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
		$fixture = file_get_contents( __DIR__ . '/../Fixtures/platform-dispute-created-events.json' );
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
