<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentNotes;
use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\TransportLog;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentRequestBuilder;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLevel3Service;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOutcomeMetadataMapper;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcomeApplyException;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\ProviderInterface;
use Automattic\WooCommerce\Internal\Payments\ProviderOperationEffectApplierInterface;
use Automattic\WooCommerce\Internal\Payments\ProviderOutcomeMetadataMapperInterface;
use Automattic\WooCommerce\Internal\Payments\ProviderPostLifecycleEffectApplierInterface;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsHtmlUtils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectApplier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectPlan;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentMethodDetailsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentOperationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\PaymentMethodRegistry;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeHttpClient;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\RecordingWcLogger;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\SubscriptionRelationshipsProbe;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use Exception;
use RuntimeException;
use WC_Order;
use WC_Order_Refund;
use WC_Payment_Token_CC;
use WC_Unit_Test_Case;

/**
 * Tests for the PaymentProcessingService class.
 */
class PaymentProcessingServiceTest extends WC_Unit_Test_Case {

	use OrderPaymentLockTestTrait;

	/**
	 * A provider platform's error text: it can hold an email, a URL or a key, so no log may carry it.
	 */
	public const PROVIDER_FAILURE_TEXT = 'No such customer: shopper@example.com, see https://pay.example.test/r?key=sk_test_leak123';

	/**
	 * A refund note already on the order, which a malformed note structure must find by its text alone.
	 */
	private const FALLBACK_REFUND_NOTE = 'Existing exact refund note. (<code>re_malformed_structure</code>)';

	/**
	 * The System Under Test.
	 *
	 * @var PaymentProcessingService
	 */
	private $sut;

	/**
	 * Order payment lock.
	 *
	 * @var OrderPaymentLock
	 */
	private $order_payment_lock;

	/**
	 * Persistence vocabulary used by the recording WooPayments provider.
	 *
	 * @var ProviderPersistenceVocabularyInterface
	 */
	private ProviderPersistenceVocabularyInterface $persistence_vocabulary;

	/**
	 * Customer service the last real-customer provider resolves customers with.
	 *
	 * @var WooPaymentsCustomerService|null
	 */
	private ?WooPaymentsCustomerService $customers_over_transport = null;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut                    = wc_get_container()->get( PaymentProcessingService::class );
		$this->order_payment_lock     = wc_get_container()->get( OrderPaymentLock::class );
		$this->persistence_vocabulary = new WooPaymentsPersistenceVocabulary();
	}

	/**
	 * @testdox Should call the provider with a per-attempt key and complete the order for completed outcomes.
	 */
	public function test_process_checkout_completes_order_for_completed_outcome(): void {
		$order    = $this->create_woopayments_order( '10.00' );
		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_COMPLETED,
				'pi_test',
				'',
				'pm_test',
				'cus_test',
				array(
					'meta' => array(
						'_charge_id'        => 'ch_test',
						'_intention_status' => 'succeeded',
					),
				)
			)
		);

		$outcome = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_test' ), $provider );
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'pi_test', $outcome->get_provider_payment_id() );
		$this->assertSame( 'pm_test', $outcome->get_payment_method_id() );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$provider->last_idempotency_key,
			'The provider must receive a fresh per-attempt key, not a derived one.'
		);
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( 'pi_test', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'pm_test', $order->get_meta( '_payment_method_id', true ) );
		$this->assertSame( 'ch_test', $order->get_meta( '_charge_id', true ) );
	}

	/**
	 * @testdox Should let core's cart-clearing hook empty a matching cart once the shopper's browser loads a completed checkout's redirect URL.
	 *
	 * WooPayments client 11.1.0 `class-wc-payment-gateway-wcpay.php:2229-2231` empties the cart
	 * itself inside `process_payment()`, before returning its success result. The runtime does not: its
	 * redirect (proved above) only points at the order-received URL, and the cart is cleared later, once
	 * the shopper's browser actually loads that URL and core's `wc_clear_cart_after_payment()`
	 * (`includes/wc-cart-functions.php:175-231`, hooked on `template_redirect`) reads its `order-received`
	 * query var and matching `key`. `WooPaymentsTokenizedCartSessionController` is registered as it would
	 * be in production to prove the runtime adds no filter that blocks this for a standard (non-tokenized)
	 * checkout — only a tokenized-product order-received request gets that filter (see that class's own
	 * test coverage).
	 */
	public function test_process_checkout_completed_redirect_url_clears_the_cart_on_next_load(): void {
		$product = \WC_Helper_Product::create_simple_product();
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$order = $this->create_woopayments_order( '10.00' );
		$order->set_cart_hash( WC()->cart->get_cart_hash() );
		$order->save();
		$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_cart_clear', '', 'pm_cart_clear', 'cus_cart_clear' ) );

		$outcome = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_cart_clear' ), $provider );
		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_CHECKOUT_REDIRECT, $outcome->get_data() );
		$this->assertSame( '', $outcome->get_redirect_url() );
		$redirect = $order->get_checkout_order_received_url();

		$tokenized_cart_controller = new \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenizedCartSessionController();
		$tokenized_cart_controller->init( new \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutAddressNormalizer() );
		$tokenized_cart_controller->register();

		// Parse the `order-received` id and `key` from the redirect URL itself (not `get_order_key()`),
		// exactly as WordPress's query parser would when the shopper's browser requests it.
		$query_args = array();
		parse_str( (string) wp_parse_url( $redirect, PHP_URL_QUERY ), $query_args );

		$GLOBALS['wp']->query_vars['order-received'] = (int) ( $query_args['order-received'] ?? 0 );
		$_GET['key']                                 = $query_args['key'] ?? '';

		// wc_template_redirect() and redirect_canonical() both call header()/exit for concerns
		// unrelated to cart clearing (404 guarding, canonical URL matching) that a synthetic
		// request outside a real front-end page load cannot satisfy; only wc_clear_cart_after_payment()
		// is under test here.
		remove_action( 'template_redirect', 'redirect_canonical' );
		remove_action( 'template_redirect', 'wc_template_redirect' );
		try {
			do_action( 'template_redirect' );
		} finally {
			unset( $GLOBALS['wp']->query_vars['order-received'], $_GET['key'] );
		}

		$this->assertTrue( WC()->cart->is_empty(), "The shopper's matching cart must be empty once their browser loads the completed order's redirect URL." );
	}

	/**
	 * @testdox A failed checkout attempt can be followed by a fresh successful attempt.
	 */
	public function test_failed_checkout_attempt_can_be_followed_by_a_fresh_successful_attempt(): void {
		$order                  = $this->create_woopayments_order( '10.00' );
		$failed_outcome         = new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			'pi_failed',
			'',
			'pm_failed',
			'',
			array(
				'meta' => array(
					'_intention_status' => 'requires_payment_method',
				),
			)
		);
		$success_outcome        = new PaymentOutcome(
			PaymentOutcome::STATUS_COMPLETED,
			'pi_succeeded',
			'',
			'pm_succeeded',
			'',
			array(
				'meta' => array(
					'_charge_id'        => 'ch_succeeded',
					'_intention_status' => 'succeeded',
				),
			)
		);
		$provider               = new class( $failed_outcome, $success_outcome ) extends RecordingProvider {
			/**
			 * Outcomes returned in call order.
			 *
			 * @var PaymentOutcome[]
			 */
			private array $outcomes;

			/**
			 * Attempt keys received by the provider.
			 *
			 * @var string[]
			 */
			public array $idempotency_keys = array();

			/**
			 * Constructor.
			 *
			 * @param PaymentOutcome ...$outcomes Outcomes returned in call order.
			 */
			public function __construct( PaymentOutcome ...$outcomes ) {
				parent::__construct( $outcomes[0] );
				$this->outcomes = $outcomes;
			}

			/**
			 * Charge an order through the provider.
			 *
			 * @param PaymentOperationContext $context         Payment context.
			 * @param string                  $idempotency_key Per-attempt idempotency key.
			 * @return PaymentOutcome
			 */
			public function charge( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
				unset( $context );
				$this->idempotency_keys[]   = $idempotency_key;
				$this->last_idempotency_key = $idempotency_key;
				$outcome                    = $this->outcomes[ $this->charge_calls ];
				++$this->charge_calls;

				return $outcome;
			}
		};
		$payment_complete_calls = 0;
		$observer               = static function ( int $order_id ) use ( $order, &$payment_complete_calls ): void {
			if ( $order->get_id() === $order_id ) {
				++$payment_complete_calls;
			}
		};
		add_action( 'woocommerce_payment_complete', $observer );

		try {
			$first_outcome = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_failed' ), $provider );
			$this->assertFalse( $this->is_order_payment_lock_held_for( $order, $this->persistence_vocabulary, $provider->idempotency_keys[0] ), 'The failed attempt must release the order payment lock.' );

			$second_outcome = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_succeeded' ), $provider );
			$this->assertFalse( $this->is_order_payment_lock_held_for( $order, $this->persistence_vocabulary, $provider->idempotency_keys[1] ), 'The successful attempt must release the order payment lock.' );
		} finally {
			remove_action( 'woocommerce_payment_complete', $observer );
		}

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 2, $provider->charge_calls );
		$this->assertCount( 2, $provider->idempotency_keys );
		foreach ( $provider->idempotency_keys as $idempotency_key ) {
			$this->assertMatchesRegularExpression(
				'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
				$idempotency_key,
				'Every checkout attempt must receive a nonempty UUID-v4 key.'
			);
		}
		$this->assertNotSame( $provider->idempotency_keys[0], $provider->idempotency_keys[1], 'A retry must not reuse the failed attempt key.' );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $first_outcome->get_status() );
		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $second_outcome->get_status() );
		$this->assertSame( 'pi_succeeded', $second_outcome->get_provider_payment_id() );
		$this->assertSame( 1, $payment_complete_calls, 'The two attempts must apply exactly one paid effect.' );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( 'pi_succeeded', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'pm_succeeded', $order->get_meta( '_payment_method_id', true ) );
		$this->assertSame( 'ch_succeeded', $order->get_meta( '_charge_id', true ) );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );
	}

	/**
	 * @testdox A payment-method-only outcome that did not fail writes its mapped meta and deletes its keys, and leaves the order's status, transaction ID, paid date and notes as they were ($_dataName).
	 *
	 * The runtime half of a checkout operation that takes no payment, such as replacing the payment method of a recurring
	 * order; client 11.3.0 makes no completion call there (class-wc-payment-gateway-wcpay.php:2497-2500, :1998-2002).
	 *
	 * @dataProvider payment_method_only_outcomes
	 *
	 * @param string $status    Outcome status.
	 * @param string $reference Provider reference.
	 */
	public function test_process_checkout_applies_no_lifecycle_to_a_payment_method_only_outcome( string $status, string $reference ): void {
		$order = $this->create_woopayments_order( '10.00' );
		$order->update_meta_data( '_method_stale', 'stale' );
		$order->save();

		$outcome = new PaymentOutcome(
			$status,
			$reference,
			'',
			'method_saved',
			'customer_saved',
			array(
				PaymentOutcome::DATA_PAYMENT_METHOD_ONLY => true,
				PaymentOutcome::DATA_META_TO_DELETE      => array( '_method_stale' ),
				PaymentOutcome::DATA_NOTE                => 'Payment note.',
			)
		);

		$provider = new class( $outcome ) extends RecordingProvider {
			/**
			 * Map the outcome to the order meta a provider keeps for a saved payment method.
			 *
			 * @param PaymentOutcome $outcome Provider outcome.
			 * @return array<string,string>
			 */
			public function get_outcome_meta( PaymentOutcome $outcome ): array {
				return array(
					'_method_customer' => $outcome->get_customer_id(),
					'_method_id'       => $outcome->get_payment_method_id(),
				);
			}
		};

		$payment_complete_calls = 0;
		$observer               = static function () use ( &$payment_complete_calls ): void {
			++$payment_complete_calls;
		};
		add_action( 'woocommerce_payment_complete', $observer );

		$result = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'method_saved' ), $provider );
		$order  = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $status, $result->get_status() );
		$this->assertSame( 'method_saved', $order->get_meta( '_method_id', true ) );
		$this->assertSame( 'customer_saved', $order->get_meta( '_method_customer', true ) );
		$this->assertSame( '', $order->get_meta( '_method_stale', true ), 'The outcome deletes the keys it names.' );
		$this->assertSame( OrderStatus::PENDING, $order->get_status() );
		$this->assertSame( '', $order->get_transaction_id() );
		$this->assertNull( $order->get_date_paid() );
		$this->assertSame( array(), wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertSame( 0, $payment_complete_calls );
	}

	/**
	 * Payment-method-only outcomes that did not fail.
	 *
	 * @return array<string,array{string,string}>
	 */
	public function payment_method_only_outcomes(): array {
		return array(
			'completed'                => array( PaymentOutcome::STATUS_COMPLETED, 'ref_method' ),
			'requires customer action' => array( PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION, 'ref_method' ),
			'no external payment'      => array( PaymentOutcome::STATUS_NO_EXTERNAL_PAYMENT, '' ),
		);
	}

	/**
	 * @testdox A failed outcome records its meta and one note, keeps the intent off the transaction ID and releases the lock; it fails the order unless flagged to keep the status or as payment-method-only ($_dataName).
	 *
	 * Client 11.1.0 fails a declined order (`class-wc-payment-gateway-wcpay.php:1324-1328`) and keeps the intent ID on it
	 * (`:1331-1333`) with one failed-payment note (`:1359-1361`); a fraud block leaves the status for the merchant.
	 *
	 * @dataProvider failed_checkout_outcomes
	 *
	 * @param string               $intent_id       Provider payment ID.
	 * @param array<string,mixed>  $data            Outcome data.
	 * @param string               $note            Note the outcome carries.
	 * @param string               $expected_status Order status after the checkout.
	 * @param array<string,string> $expected_meta   Order meta after the checkout.
	 */
	public function test_process_checkout_records_a_failed_outcome_and_fails_the_order_unless_flagged( string $intent_id, array $data, string $note, string $expected_status, array $expected_meta ): void {
		$order    = $this->create_woopayments_order( '10.00' );
		$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_FAILED, $intent_id, '', '', '', $data + array( PaymentOutcome::DATA_NOTE => $note ) ) );

		$outcome = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_test' ), $provider );
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status(), 'The shopper-facing checkout result must still be a failure.' );
		$this->assertSame( $intent_id, $outcome->get_provider_payment_id() );
		$this->assertSame( $expected_status, $order->get_status() );
		$this->assertSame( $intent_id, $order->get_meta( '_intent_id', true ) );
		foreach ( $expected_meta as $key => $value ) {
			$this->assertSame( $value, (string) $order->get_meta( $key, true ), "Unexpected {$key}." );
		}
		$this->assertSame( '', (string) $order->get_transaction_id(), 'A failed attempt must not claim the order transaction id.' );
		$this->assertCount( 1, array_keys( wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ), $note, true ), 'The order must carry the outcome note once.' );
		$this->assertFalse( $this->is_order_payment_lock_held_for( $order, $this->persistence_vocabulary, $provider->last_idempotency_key ), 'A failed attempt must release the order payment lock.' );
	}

	/**
	 * Failed checkout outcomes, flagged to preserve the order status and not, with the order each leaves.
	 *
	 * @return array<string,array{string,array<string,mixed>,string,string,array<string,string>}>
	 */
	public function failed_checkout_outcomes(): array {
		return array(
			'fraud block, status preserved' => array(
				'pi_blocked_test',
				array(
					PaymentOutcome::DATA_ERROR_CODE => 'wcpay_blocked_by_fraud_rule',
					PaymentOutcome::DATA_PRESERVE_ORDER_STATUS => true,
					WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY => array(
						'_wcpay_fraud_outcome_status' => 'block',
						'_intention_status'           => 'canceled',
					),
				),
				'A payment was blocked by risk filters.',
				'pending',
				array(
					'_wcpay_fraud_outcome_status' => 'block',
					'_intention_status'           => 'canceled',
				),
			),
			'card decline'                  => array(
				'pi_declined_test',
				array(
					PaymentOutcome::DATA_ERROR_CODE    => 'card_declined',
					PaymentOutcome::DATA_ERROR_MESSAGE => 'Error: Your card was declined.',
					WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY => array( '_wcpay_fraud_meta_box_type' => 'allow' ),
				),
				'A payment of 10.00 failed to complete.',
				'failed',
				array(
					'_wcpay_fraud_meta_box_type' => 'allow',
					'_charge_id'                 => '',
				),
			),
			'payment-method-only decline'   => array(
				'seti_declined_test',
				array(
					PaymentOutcome::DATA_PAYMENT_METHOD_ONLY => true,
					PaymentOutcome::DATA_ERROR_CODE => 'card_declined',
				),
				'A payment of 10.00 failed to complete.',
				'pending',
				array(),
			),
		);
	}

	/**
	 * @testdox A refund the provider fails ($_dataName) returns an error with the provider's code, or the default refund code, and its message.
	 *
	 * @testWith [true, "order_payment_refund_failed", "Processor unavailable."]
	 *           [false, "uncaptured-payment", "This payment is not captured yet."]
	 *
	 * @param bool   $throws           Whether the provider throws, rather than answering with a failed outcome.
	 * @param string $expected_code    Error code of the returned error.
	 * @param string $expected_message Error message of the returned error.
	 */
	public function test_refund_provider_exception_fails_with_the_exception_message( bool $throws, string $expected_code, string $expected_message ): void {
		$order = $this->create_woopayments_order( '10.00' );
		$this->create_local_refund( $order, 2.5, 'Adjustment' );

		$failed = new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			'',
			'',
			'',
			'',
			array(
				PaymentOutcome::DATA_ERROR_CODE    => 'uncaptured-payment',
				PaymentOutcome::DATA_ERROR_MESSAGE => 'This payment is not captured yet.',
			)
		);
		// phpcs:disable Squiz.Commenting, Squiz.Classes.ClassFileName.NoMatch
		$provider = $throws
			? new class( $failed ) extends RecordingProvider {
				public function refund( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
					unset( $context, $idempotency_key );
					throw new Exception( 'Processor unavailable.' );
				}
			}
			: new RecordingProvider( $failed );
		// phpcs:enable Squiz.Commenting, Squiz.Classes.ClassFileName.NoMatch

		$result = $this->sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 2.5, 'Adjustment' ), $provider );

		$this->assertWPError( $result );
		$this->assertSame( $expected_code, $result->get_error_code() );
		$this->assertSame( $expected_message, $result->get_error_message() );
	}

	/**
	 * @testdox Provider throwables emit one structured operation log with idempotency correlation.
	 * @dataProvider provider_failure_operations
	 *
	 * @param string $operation Provider operation.
	 */
	public function test_provider_throwable_emits_one_structured_operation_log( string $operation ): void {
		$order = $this->create_woopayments_order( '10.00' );
		// A provider's platform writes both the message and the code; either can hold an email, a URL or a key.
		$exception = new class( 'No such customer: shopper@example.com, see https://pay.example.test/r?key=sk_test_leak123', 7 ) extends RuntimeException {
			/**
			 * Get the provider error code.
			 *
			 * @return string
			 */
			public function get_error_code(): string {
				return 'https://pay.example.test/code';
			}
		};

		// phpcs:disable Squiz.Commenting, Squiz.Classes.ClassFileName.NoMatch
		$provider = new class( new PaymentOutcome( PaymentOutcome::STATUS_FAILED ), $exception ) extends RecordingProvider {
			private \Throwable $exception;

			public function __construct( PaymentOutcome $outcome, \Throwable $exception ) {
				parent::__construct( $outcome );
				$this->exception = $exception;
			}

			public function charge( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
				unset( $context );
				// Recorded so the test can prove the log correlates to the exact key the
				// provider received — every key is minted per call, not derivable.
				$this->last_idempotency_key = $idempotency_key;
				throw $this->exception;
			}

			public function refund( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
				unset( $context );
				$this->last_idempotency_key = $idempotency_key;
				throw $this->exception;
			}

			public function capture( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
				unset( $context );
				$this->last_idempotency_key = $idempotency_key;
				throw $this->exception;
			}

			public function cancel( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
				unset( $context );
				$this->last_idempotency_key = $idempotency_key;
				throw $this->exception;
			}
		};
		// phpcs:enable Squiz.Commenting, Squiz.Classes.ClassFileName.NoMatch

		$logger = $this->create_fake_logger();
		add_filter(
			'woocommerce_logging_class',
			function () use ( $logger ) {
				return $logger;
			}
		);
		$sut = new PaymentProcessingService();
		$sut->init(
			$this->order_payment_lock,
			wc_get_container()->get( OrderPaymentLifecycleService::class ),
			wc_get_container()->get( OrderPaymentNotes::class )
		);

		switch ( $operation ) {
			case 'charge':
				$outcome                  = $sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_failure' ), $provider );
				$expected_idempotency_key = $provider->last_idempotency_key;
				$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $expected_idempotency_key );
				$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
				$this->assertSame( 'https://pay.example.test/code', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] ?? null, 'The failed outcome carries the error code the exception provides.' );
				break;

			case 'refund':
				$this->create_local_refund( $order, 2.5, 'Adjustment' );
				$result                   = $sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 2.5, 'Adjustment' ), $provider );
				$expected_idempotency_key = $provider->last_idempotency_key;
				$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $expected_idempotency_key );
				$this->assertWPError( $result );
				$this->assertSame( 'https://pay.example.test/code', $result->get_error_code(), 'The refund fails with the error code the exception provides.' );
				break;

			case 'capture':
				$outcome                  = $sut->capture( PaymentOperationContext::for_capture( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 4.25 ), $provider );
				$expected_idempotency_key = $provider->last_idempotency_key;
				$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $expected_idempotency_key );
				$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
				$this->assertSame( 'https://pay.example.test/code', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] ?? null, 'The failed outcome carries the error code the exception provides.' );
				break;

			default:
				$outcome                  = $sut->cancel( PaymentOperationContext::for_cancel( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), $provider );
				$expected_idempotency_key = $provider->last_idempotency_key;
				$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $expected_idempotency_key );
				$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
				$this->assertSame( 'https://pay.example.test/code', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] ?? null, 'The failed outcome carries the error code the exception provides.' );
		}

		$this->assertCount( 1, $logger->error_calls );
		$this->assertSame( 'Payment provider operation threw an exception.', $logger->error_calls[0]['message'] );
		$this->assertSame(
			array(
				'source'          => 'order-payments',
				'operation'       => $operation,
				'order_id'        => $order->get_id(),
				'idempotency_key' => $expected_idempotency_key,
				'exception_class' => get_class( $exception ),
				'exception_code'  => 7,
			),
			$logger->error_calls[0]['context']
		);
		$written = (string) wp_json_encode( $logger->error_calls );
		foreach ( array( 'No such customer', 'shopper@example.com', 'pay.example.test', 'sk_test_leak123' ) as $provider_text ) {
			$this->assertStringNotContainsString( $provider_text, $written );
		}
	}

	/**
	 * Provider operations that normalize throwables.
	 *
	 * @return array<string,array{string}>
	 */
	public static function provider_failure_operations(): array {
		return array(
			'charge'  => array( 'charge' ),
			'refund'  => array( 'refund' ),
			'capture' => array( 'capture' ),
			'cancel'  => array( 'cancel' ),
		);
	}

	/**
	 * @testdox A $_dataName outcome is returned as the provider's own object and leaves the order pending with its meta.
	 *
	 * WooPayments client 11.1.0 `class-wc-payments-order-service.php:417-426`: a
	 * `requires_action` intent for a non-offline method with no error calls `mark_payment_started()`,
	 * which leaves the order at its pending status and writes no charge id.
	 *
	 * @dataProvider non_final_checkout_outcomes
	 *
	 * @param PaymentOutcome       $outcome           Provider outcome.
	 * @param string               $payment_method_id Payment method ID the checkout carries.
	 * @param array<string,string> $expected_meta     Order meta after the checkout.
	 */
	public function test_process_checkout_returns_a_non_final_outcome_without_completing_order( PaymentOutcome $outcome, string $payment_method_id, array $expected_meta ): void {
		$order    = $this->create_woopayments_order( '15.00' );
		$provider = new RecordingProvider( $outcome );

		$result = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, $payment_method_id ), $provider );
		$order  = wc_get_order( $order->get_id() );

		$this->assertSame( $outcome, $result, 'The service must hand back the provider outcome itself.' );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pending', $order->get_status() );
		foreach ( $expected_meta as $key => $value ) {
			$this->assertSame( $value, $order->get_meta( $key, true ), "Unexpected {$key}." );
		}
	}

	/**
	 * Outcomes that leave a checkout waiting on the shopper or the provider, with the order meta each leaves.
	 *
	 * @return array<string,array{PaymentOutcome,string,array<string,string>}>
	 */
	public function non_final_checkout_outcomes(): array {
		return array(
			'redirect'        => array(
				new PaymentOutcome( PaymentOutcome::STATUS_REQUIRES_REDIRECT, 'pi_redirect', 'https://example.test/redirect', 'pm_redirect', '', array( 'meta' => array( '_intention_status' => 'requires_action' ) ) ),
				'pm_redirect',
				array(
					'_intent_id'        => 'pi_redirect',
					'_intention_status' => 'requires_action',
					'_charge_id'        => '',
				),
			),
			'customer action' => array(
				new PaymentOutcome(
					PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION,
					'pi_requires_action',
					'#wcpay-confirm-pi:1:secret:nonce',
					'pm_requires_action',
					'cus_requires_action',
					array(
						'meta' => array(
							'_charge_id'             => 'ch_requires_action',
							'_wcpay_intent_currency' => 'usd',
						),
					)
				),
				'pm_requires_action',
				array(
					'_intent_id'          => 'pi_requires_action',
					'_intention_status'   => 'requires_action',
					'_payment_method_id'  => 'pm_requires_action',
					'_stripe_customer_id' => 'cus_requires_action',
					'_charge_id'          => 'ch_requires_action',
				),
			),
			'pending async'   => array(
				new PaymentOutcome( PaymentOutcome::STATUS_PENDING_ASYNC, 'remote_pending_123' ),
				'',
				array(
					'_intention_status'  => 'processing',
					'_payment_method_id' => '',
				),
			),
		);
	}

	/**
	 * @testdox Should persist checkout outcome metadata from the provider mapper.
	 */
	public function test_process_checkout_outcome_uses_provider_outcome_metadata_mapper(): void {
		$order    = $this->create_woopayments_order( '12.00' );
		$outcome  = new PaymentOutcome(
			PaymentOutcome::STATUS_COMPLETED,
			'remote_payment_123',
			'',
			'remote_method_123',
			'remote_customer_123'
		);
		$provider = new class( $outcome ) extends RecordingProvider implements ProviderOutcomeMetadataMapperInterface {

			/**
			 * Get the provider/gateway ID.
			 *
			 * @return string
			 */
			public function get_id(): string {
				return 'offline_redirect_provider';
			}

			/**
			 * Get the provider persistence vocabulary.
			 *
			 * @return ProviderPersistenceVocabularyInterface
			 */
			public function get_persistence_vocabulary(): ProviderPersistenceVocabularyInterface {
				return new class() implements ProviderPersistenceVocabularyInterface {

					/**
					 * Get the order payment lock key.
					 *
					 * @param WC_Order $order Order object.
					 * @return string
					 */
					public function get_order_lock_key( WC_Order $order ): string {
						return 'offline_provider_processing_' . $order->get_id();
					}

					/**
					 * Get the lock sentinel value.
					 *
					 * @return string
					 */
					public function get_lock_sentinel(): string {
						return '-1';
					}

					/**
					 * Get the lock time-to-live in seconds.
					 *
					 * @return int
					 */
					public function get_lock_ttl_seconds(): int {
						return 300;
					}

					/**
					 * Get the processed refund link meta key.
					 *
					 * @return string
					 */
					public function get_processed_refund_link_meta_key(): string {
						return '_offline_provider_refund_id';
					}

					/**
					 * Get preserved order/refund meta keys.
					 *
					 * @return string[]
					 */
					public function get_preserved_payment_meta_keys(): array {
						return array( '_offline_intent_id', '_offline_method_id', '_offline_customer_id', '_offline_status' );
					}

					/**
					 * Get the order meta key holding the provider payment.
					 *
					 * @return string
					 */
					public function get_payment_reference_meta_key(): string {
						return '_offline_intent_id';
					}

					/**
					 * Get the order meta key holding an unresolved charge idempotency key: this provider stores none.
					 *
					 * @return string
					 */
					public function get_charge_idempotency_key_meta_key(): string {
						return '';
					}

					/**
					 * Get the order meta key holding open dispute IDs: this provider records none.
					 *
					 * @return string
					 */
					public function get_open_dispute_ids_meta_key(): string {
						return '';
					}

					/**
					 * Get the note identity meta key: this provider gives its notes no identities.
					 *
					 * @return string
					 */
					public function get_note_identity_meta_key(): string {
						return '';
					}

				};
			}

			/**
			 * Map a neutral outcome to provider order meta.
			 *
			 * @param PaymentOutcome $outcome Provider outcome.
			 * @return array<string,string>
			 */
			public function get_outcome_meta( PaymentOutcome $outcome ): array {
				return array(
					'_offline_customer_id' => $outcome->get_customer_id(),
					'_offline_intent_id'   => $outcome->get_provider_payment_id(),
					'_offline_method_id'   => $outcome->get_payment_method_id(),
					'_offline_status'      => 'offline-' . $outcome->get_status(),
				);
			}

			/**
			 * Map a failed capture outcome to provider order meta.
			 *
			 * @param PaymentOutcome $outcome Provider outcome.
			 * @return array<string,string>
			 */
			public function get_failed_capture_or_cancel_outcome_meta( PaymentOutcome $outcome ): array {
				return array( '_offline_status' => 'offline-capture-failed' );
			}
		};

		$this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, 'offline_redirect_provider', 'remote_method_123' ), $provider );
		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'remote_payment_123', $order->get_meta( '_offline_intent_id', true ) );
		$this->assertSame( 'remote_method_123', $order->get_meta( '_offline_method_id', true ) );
		$this->assertSame( 'remote_customer_123', $order->get_meta( '_offline_customer_id', true ) );
		$this->assertSame( 'offline-completed', $order->get_meta( '_offline_status', true ) );
		$this->assertSame( '', $order->get_meta( '_intent_id', true ), 'WooPayments intent meta must not be written for providers with their own persistence vocabulary.' );
	}

	/**
	 * @testdox A charge stays reconcilable when local work throws after the provider answered: the reference is saved, one error log names the failure's class and code but never its text, and the provider's own outcome is handed back ($_dataName).
	 * @dataProvider reconcilable_apply_failures
	 *
	 * @param string               $status        Provider outcome status.
	 * @param string               $failure_point Where local work throws: 'lifecycle', 'effects', or 'lifecycle after effects replaced the outcome'.
	 * @param array<string,string> $expected_meta Order meta the saved reconciliation context carries.
	 * @param array<string,mixed>  $data          Outcome data.
	 */
	public function test_process_checkout_outcome_keeps_order_reconcilable_when_apply_throws( string $status, string $failure_point, array $expected_meta, array $data = array() ): void {
		$order   = $this->create_woopayments_order( '10.00' );
		$outcome = new PaymentOutcome( $status, 'pi_post_charge', PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION === $status ? '#wcpay-confirm-pi:1:secret:nonce' : '', 'pm_post_charge', 'cus_post_charge', $data );

		$provider = new class( $outcome, $failure_point ) extends RecordingProvider implements ProviderOperationEffectApplierInterface {
			/**
			 * Where local work throws.
			 *
			 * @var string
			 */
			private string $failure_point;

			/**
			 * Build the provider double.
			 *
			 * @param PaymentOutcome $outcome       Provider outcome.
			 * @param string         $failure_point Where local work throws.
			 */
			public function __construct( PaymentOutcome $outcome, string $failure_point ) {
				parent::__construct( $outcome );
				$this->failure_point = $failure_point;
			}

			/**
			 * Throw a provider's platform text from the effects, or hand back a replaced outcome.
			 *
			 * @param PaymentOperationContext $context   Payment context.
			 * @param PaymentOutcome          $outcome   Provider outcome.
			 * @param string                  $operation Operation name.
			 * @return PaymentOutcome
			 * @throws RuntimeException When the effects are the failure point.
			 */
			public function apply_operation_effects( PaymentOperationContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				unset( $context, $operation );
				if ( 'effects' === $this->failure_point ) {
					throw new RuntimeException( PaymentProcessingServiceTest::PROVIDER_FAILURE_TEXT, 9 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A test double's fixed text, never output.
				}

				return 'lifecycle' === $this->failure_point
					? $outcome
					: new PaymentOutcome( $outcome->get_status(), $outcome->get_provider_payment_id(), '', $outcome->get_payment_method_id(), $outcome->get_customer_id(), array( 'effect' => 'replaced' ) );
			}
		};

		$sut         = $this->build_sut_with_lifecycle( $this->create_throwing_lifecycle_service() );
		$fake_logger = $this->create_fake_logger();
		add_filter(
			'woocommerce_logging_class',
			function () use ( $fake_logger ) {
				return $fake_logger;
			}
		);

		$exception = $this->expect_outcome_apply_exception(
			static fn() => $sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_post_charge' ), $provider )
		);

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $outcome, $exception->get_outcome(), 'An apply failure must hand back the provider outcome, not a downgraded or replaced one.' );
		$this->assertSame( self::PROVIDER_FAILURE_TEXT, $exception->get_failure()->getMessage() );
		$this->assertSame( 'pi_post_charge', $order->get_transaction_id(), 'The provider payment reference must be persisted so the charge stays reconcilable.' );
		foreach ( $expected_meta as $key => $value ) {
			$this->assertSame( $value, $order->get_meta( $key, true ), "The reconciliation context must reach the stored order: {$key}." );
		}
		$this->assertTrue( $exception->was_reconciliation_context_persisted(), 'The exception must report that the reference was saved.' );

		$this->assertCount( 1, $fake_logger->error_calls, 'An apply failure after the provider answered must be logged once at error level.' );
		$this->assertSame( 0, strpos( $fake_logger->error_calls[0]['message'], 'Payment provider operation returned a reconcilable outcome' ) );
		$context = $fake_logger->error_calls[0]['context'];
		$this->assertSame( 'order-payments', $context['source'] );
		$this->assertSame( $order->get_id(), $context['order_id'] );
		$this->assertSame( 'pi_post_charge', $context['payment_reference'] );
		$this->assertSame( RuntimeException::class, $context['exception_class'] );
		$this->assertSame( 9, $context['exception_code'] );
		$written = (string) wp_json_encode( $fake_logger->error_calls );
		foreach ( array( 'No such customer', 'shopper@example.com', 'pay.example.test', 'sk_test_leak123' ) as $provider_text ) {
			$this->assertStringNotContainsString( $provider_text, $written );
		}
	}

	/**
	 * Where local work throws after the provider answered, for outcomes the service must keep.
	 *
	 * @return array<string,array{0:string,1:string,2:array<string,string>,3?:array<string,mixed>}>
	 */
	public function reconcilable_apply_failures(): array {
		return array(
			'meta write throws on a payment-method-only outcome' => array(
				PaymentOutcome::STATUS_COMPLETED,
				'lifecycle',
				array(
					'_intent_id'         => 'pi_post_charge',
					'_payment_method_id' => 'pm_post_charge',
				),
				array( PaymentOutcome::DATA_PAYMENT_METHOD_ONLY => true ),
			),
			'lifecycle throws on a charge'       => array( PaymentOutcome::STATUS_COMPLETED, 'lifecycle', array( '_intention_status' => 'succeeded' ) ),
			'effects throw on a charge'          => array( PaymentOutcome::STATUS_COMPLETED, 'effects', array( '_intention_status' => 'succeeded' ) ),
			'effects throw on a customer action' => array(
				PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION,
				'effects',
				array(
					'_intent_id'         => 'pi_post_charge',
					'_payment_method_id' => 'pm_post_charge',
				),
			),
			'replaced outcome, lifecycle throws' => array( PaymentOutcome::STATUS_COMPLETED, 'lifecycle after effects replaced the outcome', array( '_intention_status' => 'succeeded' ) ),
		);
	}

	/**
	 * @testdox The order payment lock should be released when applying a $operation outcome throws.
	 * @dataProvider provide_operations_whose_outcome_application_throws
	 *
	 * @param string $operation Operation to run: checkout, capture, cancel, or refund.
	 * @param string $status    Provider outcome status.
	 * @param string $reference Provider payment reference.
	 */
	public function test_order_payment_lock_is_released_when_outcome_application_throws( string $operation, string $status, string $reference ): void {
		$order = $this->create_woopayments_order( '10.00' );
		if ( 'refund' === $operation ) {
			wc_create_refund(
				array(
					'order_id'       => $order->get_id(),
					'amount'         => 2.50,
					'reason'         => 'Adjustment',
					'refund_payment' => false,
				)
			);
		}

		$provider = new class( new PaymentOutcome( $status, $reference ) ) extends RecordingProvider implements ProviderOperationEffectApplierInterface {
			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double always throws.
			/**
			 * Fail local outcome application after the provider answered.
			 *
			 * @param PaymentOperationContext $context   Payment context.
			 * @param PaymentOutcome          $outcome   Provider outcome.
			 * @param string                  $operation Operation name.
			 * @return PaymentOutcome
			 * @throws RuntimeException Always.
			 */
			public function apply_operation_effects( PaymentOperationContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				unset( $context, $outcome, $operation );
				throw new RuntimeException( 'Local outcome application failed.' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};

		$thrown = null;
		try {
			switch ( $operation ) {
				case 'checkout':
					$this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_apply_throws' ), $provider );
					break;
				case 'capture':
					$this->sut->capture( PaymentOperationContext::for_capture( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), $provider );
					break;
				case 'cancel':
					$this->sut->cancel( PaymentOperationContext::for_cancel( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), $provider );
					break;
				case 'refund':
					$this->sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 2.50, 'Adjustment' ), $provider );
					break;
			}
		} catch ( RuntimeException $exception ) {
			$thrown = $exception;
		}

		$this->assertInstanceOf( RuntimeException::class, $thrown, 'The application failure must reach the caller.' );
		$this->assertFalse( get_transient( $this->persistence_vocabulary->get_order_lock_key( $order ) ), 'A throwing outcome application must release the order payment lock.' );
		$lock_token = $this->order_payment_lock->claim( $order, $this->persistence_vocabulary, 'next_operation', 'payment operation' );
		$this->assertNotNull( $lock_token, 'The next operation must be able to claim the lock.' );
		$this->order_payment_lock->release( $order, $this->persistence_vocabulary, $lock_token );
	}

	/**
	 * Operations whose local outcome application throws out of the service.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function provide_operations_whose_outcome_application_throws(): array {
		return array(
			'failed checkout'     => array( 'checkout', PaymentOutcome::STATUS_FAILED, '' ),
			'successful checkout' => array( 'checkout', PaymentOutcome::STATUS_COMPLETED, 'pi_apply_throws' ),
			'failed capture'      => array( 'capture', PaymentOutcome::STATUS_FAILED, '' ),
			'failed cancel'       => array( 'cancel', PaymentOutcome::STATUS_FAILED, '' ),
			'failed refund'       => array( 'refund', PaymentOutcome::STATUS_FAILED, '' ),
		);
	}

	/**
	 * @testdox A post-charge apply failure must not overwrite a transaction reference the order already carries, and logs both IDs.
	 */
	public function test_process_checkout_outcome_does_not_overwrite_existing_transaction_reference(): void {
		$order = $this->create_woopayments_order( '10.00' );
		$order->set_transaction_id( 'pi_existing_reference' );
		$order->save();

		$outcome = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_post_charge', '', 'pm_post_charge' );

		$sut         = $this->build_sut_with_lifecycle( $this->create_throwing_lifecycle_service() );
		$provider    = new RecordingProvider( $outcome );
		$fake_logger = $this->create_fake_logger();
		add_filter(
			'woocommerce_logging_class',
			function () use ( $fake_logger ) {
				return $fake_logger;
			}
		);

		$exception = $this->expect_outcome_apply_exception(
			static fn() => $sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_post_charge' ), $provider )
		);
		$order     = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $outcome, $exception->get_outcome() );
		$this->assertSame( 'pi_existing_reference', $order->get_transaction_id(), 'An existing transaction reference must be preserved.' );
		$this->assertFalse( $exception->was_reconciliation_context_persisted(), 'The exception must report that the reference was not saved.' );

		$mismatch_logs = array_values(
			array_filter(
				$fake_logger->error_calls,
				static fn( array $call ): bool => 'Payment reconciliation context was not saved: the order already has a different transaction ID.' === $call['message']
			)
		);
		$this->assertCount( 1, $mismatch_logs, 'The transaction ID mismatch must be logged on its own line.' );
		$this->assertSame(
			array(
				'source'                  => 'order-payments',
				'order_id'                => $order->get_id(),
				'payment_reference'       => 'pi_post_charge',
				'existing_transaction_id' => 'pi_existing_reference',
			),
			$mismatch_logs[0]['context']
		);
	}

	/**
	 * @testdox A declined charge whose failure handling throws keeps the declined intent ID and never makes it the transaction ID.
	 *
	 * The provider declines the charge and names the declined intent; a `woocommerce_order_status_failed` callback then
	 * fails with an Error while the order is failed (WC_Order::status_transition() catches only Exception). On a decline
	 * client 11.1.0 stores only `_intent_id` (includes/class-wc-payment-gateway-wcpay.php:1330-1334), never the
	 * transaction ID.
	 */
	public function test_process_checkout_keeps_declined_intent_off_the_transaction_id_when_failure_handling_throws(): void {
		$order       = $this->create_woopayments_order( '10.00' );
		$provider    = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_FAILED,
				'pi_declined',
				'',
				'',
				'',
				array(
					PaymentOutcome::DATA_ERROR_CODE    => 'card_declined',
					PaymentOutcome::DATA_ERROR_MESSAGE => 'Your card was declined.',
				)
			)
		);
		$hook_throws = 0;
		add_action(
			'woocommerce_order_status_failed',
			static function () use ( &$hook_throws ): void {
				++$hook_throws;
				throw new \Error( 'A failed-order hook failed.' );
			}
		);

		$exception = $this->expect_outcome_apply_exception(
			fn() => $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_declined' ), $provider )
		);
		$order     = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 1, $hook_throws, 'Failing the order must reach the throwing hook.' );
		$this->assertSame( 1, $provider->charge_calls, 'Only the declined charge reaches the provider.' );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $exception->get_outcome()->get_status() );
		$this->assertSame( 'pi_declined', $order->get_meta( '_intent_id', true ), 'The declined intent stays traceable.' );
		$this->assertSame( '', $order->get_transaction_id(), 'A declined intent is not a payment the order can claim.' );
	}

	/**
	 * @testdox A declined card on a subscription payment-method change keeps the subscription's status; a checkout order fails: $_dataName.
	 *
	 * On a WooCommerce Subscriptions payment-method change the order is the subscription itself (an on-hold order stands in
	 * for it here). Client 11.1.0 notes the decline and leaves the subscription's status alone
	 * (includes/class-wc-payment-gateway-wcpay.php:1326-1328); failing it moves an active subscription to pending, which
	 * stops its renewals. On a change the subscription also keeps its own payment method and customer; a checkout order
	 * keeps the request's, which client 11.1.0 writes before the request (:1672-1677). The decline is the platform's
	 * recorded answer for test card 4000 0000 0000 0002 (Fixtures/platform-setup-intent-declines.json, generic_decline).
	 *
	 * @dataProvider provider_declined_setup_intent_requests
	 *
	 * @param array<string,bool> $provider_data           Checkout provider data, as the gateway builds it.
	 * @param string             $expected_status         Order status after the decline.
	 * @param string             $expected_payment_method Order's _payment_method_id after the decline.
	 * @param string             $expected_customer       Order's _stripe_customer_id after the decline.
	 */
	public function test_declined_setup_intent_keeps_the_status_of_a_subscription_being_changed( array $provider_data, string $expected_status, string $expected_payment_method, string $expected_customer ): void {
		$order = $this->create_woopayments_order( '0.00' );
		$order->set_status( 'on-hold' );
		$order->update_meta_data( '_payment_method_id', 'pm_subscription_card' );
		$order->update_meta_data( '_stripe_customer_id', 'cus_subscription' );
		$order->save();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local test fixture.
		$recording              = json_decode( (string) file_get_contents( __DIR__ . '/Providers/WooPayments/Fixtures/platform-setup-intent-declines.json' ), true );
		$entry                  = current( array_filter( $recording['entries'], static fn( array $recorded ): bool => 'generic_decline' === $recorded['pair'] ) );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( $this->json_transport_response( (int) $entry['response']['http_status'], $entry['response']['body'] ) );

		$outcome = $this->sut->process_checkout_outcome(
			PaymentOperationContext::for_checkout(
				$order,
				WooPaymentsPersistenceVocabulary::GATEWAY_ID,
				'pm_card_visa_chargeDeclined',
				array(
					'payment_token'       => 'new',
					'save_payment_method' => true,
				),
				$provider_data
			),
			$this->timeout_transport_provider( $http_client )
		);
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( array( 'POST /sites/123/wcpay/setup_intents' ), array_map( static fn( array $request ): string => $request['method'] . ' ' . $request['path'], $http_client->requests ) );
		$this->assertSame( $expected_status, $order->get_status() );
		$this->assertSame( array( $expected_payment_method, $expected_customer ), array( $order->get_meta( '_payment_method_id', true ), $order->get_meta( '_stripe_customer_id', true ) ) );
		$this->assertCount(
			1,
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
				static fn( $note ): bool => false !== strpos( wp_strip_all_tags( $note->content ), 'failed to complete with the following message: Error: Your card was declined.' )
			),
			'The decline is noted either way.'
		);
	}

	/**
	 * A $0 request that saves a new card, as a subscription payment-method change and as a checkout.
	 *
	 * @return array<string,array{array<string,bool>,string,string,string}>
	 */
	public function provider_declined_setup_intent_requests(): array {
		return array(
			'payment-method change' => array(
				array(
					WooPaymentsIntentRequestBuilder::PROVIDER_DATA_RECURRING_PAYMENT => true,
					WooPaymentsIntentRequestBuilder::PROVIDER_DATA_SUBSCRIPTION_PAYMENT_METHOD_CHANGE => true,
				),
				'on-hold',
				'pm_subscription_card',
				'cus_subscription',
			),
			'checkout'              => array( array(), 'failed', 'pm_card_visa_chargeDeclined', 'cus_timeout' ),
		);
	}

	/**
	 * @testdox A callback that throws while a subscription's payment-method change is sent keeps the subscription's status; a checkout order fails; both get the payment-failed note: $_dataName.
	 *
	 * An extension's wcpay_metadata_from_order callback runs while the SetupIntent request is built, so its exception
	 * escapes the charge before anything is sent. Client 11.1.0 catches any exception there, skips update_status() on a
	 * payment-method change (includes/class-wc-payment-gateway-wcpay.php:1272, :1326-1328) and notes the failure with the
	 * exception's message either way (:1354-1400).
	 *
	 * @testWith ["payment-method change", true, "on-hold"]
	 *           ["checkout", false, "failed"]
	 *
	 * @param string $label           Which request it is.
	 * @param bool   $is_change       Whether the request changes a subscription's payment method.
	 * @param string $expected_status Order status after the failure.
	 */
	public function test_throwing_callback_keeps_the_status_of_a_subscription_being_changed( string $label, bool $is_change, string $expected_status ): void {
		unset( $label );
		$order = $this->create_woopayments_order( '0.00' );
		$order->set_status( 'on-hold' );
		$order->save();
		$http_client = new FakeHttpClient();
		add_filter(
			'wcpay_metadata_from_order',
			static function () {
				throw new \Exception( 'An extension failed.' );
			}
		);

		$outcome = $this->sut->process_checkout_outcome(
			PaymentOperationContext::for_checkout(
				$order,
				WooPaymentsPersistenceVocabulary::GATEWAY_ID,
				'pm_card_visa',
				array(
					'payment_token'       => 'new',
					'save_payment_method' => true,
				),
				$is_change ? array(
					WooPaymentsIntentRequestBuilder::PROVIDER_DATA_RECURRING_PAYMENT => true,
					WooPaymentsIntentRequestBuilder::PROVIDER_DATA_SUBSCRIPTION_PAYMENT_METHOD_CHANGE => true,
				) : array()
			),
			$this->timeout_transport_provider( $http_client )
		);
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( array(), $http_client->requests, 'The callback throws before the request is sent.' );
		$this->assertSame( $expected_status, $order->get_status() );
		$this->assertCount(
			1,
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
				static fn( $note ): bool => false !== strpos( wp_strip_all_tags( $note->content ), 'failed to complete with the following message: An extension failed.' )
			),
			'The failure is noted with the exception\'s message.'
		);
	}

	/**
	 * @testdox A charge that throws on an order already carrying the WooPayments extension's payment-failed note, in another wording, adds no second failure note.
	 *
	 * The note the provider adds for a thrown charge carries every wording of the same note, so the lifecycle recognises the
	 * one the extension wrote in its own text domain (client 11.1.0 class-wc-payment-gateway-wcpay.php:1354-1400).
	 */
	public function test_thrown_charge_recognises_the_woopayments_extension_failure_note(): void {
		// The German string is synthetic test input; it only has to differ from WooCommerce's own wording.
		$translation_filter = static function ( string $translation, string $text, string $domain ): string {
			if ( 'woocommerce-payments' !== $domain ) {
				return $translation;
			}

			return 'A payment of %1$s <strong>failed</strong> to complete with the following message: <code>%2$s</code>.' === $text
				? 'Eine Zahlung von %1$s ist <strong>fehlgeschlagen</strong> mit der Meldung: <code>%2$s</code>.'
				: $translation;
		};
		add_filter( 'gettext', $translation_filter, 10, 3 );

		try {
			$order      = $this->create_woopayments_order( '0.00' );
			$candidates = wc_get_container()->get( WooPaymentsOrderNoteService::class )->format_checkout_payment_failed_note_candidates( $order, 'An extension failed.', '', '', '' );
			$this->assertGreaterThan( 1, count( $candidates ), 'The two text domains must render the note differently.' );
			$extension_note = end( $candidates );
			$order->add_order_note( $extension_note );
			add_filter(
				'wcpay_metadata_from_order',
				static function () {
					throw new \Exception( 'An extension failed.' );
				}
			);

			$this->sut->process_checkout_outcome(
				PaymentOperationContext::for_checkout(
					$order,
					WooPaymentsPersistenceVocabulary::GATEWAY_ID,
					'pm_card_visa',
					array(
						'payment_token'       => 'new',
						'save_payment_method' => true,
					)
				),
				$this->timeout_transport_provider( new FakeHttpClient() )
			);

			$failure_notes = array_values(
				array_filter(
					wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
					static fn( $note ): bool => false !== strpos( wp_strip_all_tags( (string) $note->content ), 'An extension failed' )
				)
			);
			$this->assertCount( 1, $failure_notes, 'The extension\'s note is the only failure note.' );
			$this->assertSame( $extension_note, $failure_notes[0]->content );
		} finally {
			remove_filter( 'gettext', $translation_filter, 10 );
		}
	}

	/**
	 * @testdox A payment sends the payer's own customer, never one left on the order, and refreshes nothing before it: $_dataName.
	 *
	 * Client 11.1.0 resolves the customer from the order's user at checkout, order-pay and payment-method change
	 * (includes/class-wc-payment-gateway-wcpay.php:1541-1584) and never reads the order's _stripe_customer_id there; a
	 * scheduled renewal sends the renewal order's customer with no refresh and nothing stored on the user
	 * (includes/compat/subscriptions/trait-wc-payment-gateway-wcpay-subscriptions.php:421-424). The key is shared with the
	 * WooCommerce Stripe Gateway, so it can hold another plugin's customer or an earlier payer's.
	 *
	 * @dataProvider provider_payments_with_a_customer_on_the_order
	 *
	 * @param string             $total             Order total.
	 * @param string             $order_customer_id Customer ID left on the order.
	 * @param array<string,bool> $provider_data     Checkout provider data, as the gateway or the renewal builds it.
	 * @param string             $expected_path     The platform request the payment sends.
	 * @param string             $expected_customer The customer that request carries.
	 */
	public function test_payment_sends_the_payers_customer_and_not_the_one_on_the_order( string $total, string $order_customer_id, array $provider_data, string $expected_path, string $expected_customer ): void {
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		update_user_option( $user_id, WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, 'cus_user' );
		$token = new WC_Payment_Token_CC();
		$token->set_token( 'pm_saved' );
		$token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2040' );
		$token->set_user_id( $user_id );
		$token->save();
		$order = $this->create_woopayments_order( $total );
		$order->set_customer_id( $user_id );
		$order->update_meta_data( '_stripe_customer_id', $order_customer_id );
		$order->add_payment_token( $token );
		$order->save();
		$is_renewal   = ! empty( $provider_data['scheduled_subscription_payment'] );
		$payment_data = $is_renewal ? array( 'payment_token' => (string) $token->get_id() ) : array( 'payment_token' => 'new' );
		if ( ! $is_renewal ) {
			$payment_data['save_payment_method'] = 0.0 === (float) $total;
		}
		$http_client = new FakeHttpClient();
		// Shape: the platform's own failure as a WordPress REST error (code, message, data.status; WP_REST_Server::error_to_response()). Any request after the payment gets it.
		$http_client->response = $this->json_transport_response( 500, array( 'code' => 'wcpay_server_error' ) );
		// Shape: the intent the platform returns, reduced to the fields this path reads: a confirmed card PaymentIntent
		// (Fixtures/platform-intention-basic-card.json, basic_card_usd_create_and_confirm) or a succeeded SetupIntent
		// (Fixtures/platform-3ds-challenge-results.json, my_account_setup_intent_challenge_completed).
		$http_client->responses = array(
			$this->json_transport_response(
				200,
				array(
					'id'             => 0.0 === (float) $total ? 'seti_payer' : 'pi_payer',
					'object'         => 0.0 === (float) $total ? 'setup_intent' : 'payment_intent',
					'status'         => 'succeeded',
					'amount'         => 1000,
					'currency'       => 'usd',
					'customer'       => $expected_customer,
					'payment_method' => 'pm_saved',
					'metadata'       => array( 'order_id' => (string) $order->get_id() ),
				)
			),
		);

		$this->sut->process_checkout_outcome(
			PaymentOperationContext::for_checkout(
				$order,
				WooPaymentsPersistenceVocabulary::GATEWAY_ID,
				$is_renewal ? '' : 'pm_card_visa',
				$payment_data,
				$provider_data
			),
			$this->timeout_transport_provider( $http_client, true )
		);

		$this->assertSame( 'POST ' . $expected_path, $http_client->requests[0]['method'] . ' ' . $http_client->requests[0]['path'] );
		$this->assertSame( $expected_customer, json_decode( (string) $http_client->requests[0]['body'], true )['customer'] ?? null );
		$this->assertSame( array(), array_values( array_filter( array_column( $http_client->requests, 'path' ), static fn( string $path ): bool => false !== strpos( $path, '/customers' ) ) ), 'No customer is updated, deleted or created before the payment.' );
		$this->assertSame( 'cus_user', get_user_option( WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, $user_id ), "The user's customer stays theirs." );
	}

	/**
	 * Orders carrying a customer that is not the payer's, or a renewal's own.
	 *
	 * @return array<string,array{string,string,array<string,bool>,string,string}>
	 */
	public function provider_payments_with_a_customer_on_the_order(): array {
		return array(
			'checkout, a WooCommerce Stripe Gateway customer left on the order' => array( '10.00', 'cus_StripeGatewayLeftover', array(), '/sites/123/wcpay/intentions', 'cus_user' ),
			'order-pay, an earlier payer\'s customer on a reassigned order'      => array( '10.00', 'cus_earlier_payer', array(), '/sites/123/wcpay/intentions', 'cus_user' ),
			'payment-method change, a leftover customer on the subscription'    => array(
				'0.00',
				'cus_StripeGatewayLeftover',
				array(
					WooPaymentsIntentRequestBuilder::PROVIDER_DATA_RECURRING_PAYMENT => true,
					WooPaymentsIntentRequestBuilder::PROVIDER_DATA_SUBSCRIPTION_PAYMENT_METHOD_CHANGE => true,
				),
				'/sites/123/wcpay/setup_intents',
				'cus_user',
			),
			'scheduled renewal, the subscription\'s customer'                   => array( '10.00', 'cus_subscription', array( 'scheduled_subscription_payment' => true ), '/sites/123/wcpay/intentions', 'cus_subscription' ),
		);
	}

	/**
	 * @testdox A renewal whose customer the platform does not find is charged on the user's own customer: $_dataName.
	 *
	 * Here a renewal sends the customer on the renewal order, as client 11.2.0 does
	 * (includes/compat/subscriptions/trait-wc-payment-gateway-wcpay-subscriptions.php:421-424). A WooCommerce Stripe Gateway
	 * customer left on the subscription is not on the WooPayments account, so the platform answers resource_missing. The
	 * user's own stored customer is then used, as client 11.2.0 does (includes/class-wc-payment-gateway-wcpay.php:5449-5465);
	 * the successful renewal stores it on the renewal order and, through the saved token, on the subscription, so the next
	 * renewal sends it first. A user with no other customer is test_saved_card_renewal_with_a_missing_customer_creates_no_customer().
	 *
	 * @dataProvider provider_renewals_with_a_missing_customer
	 *
	 * @param string   $renewal_customer_id Customer on the renewal order and the subscription.
	 * @param string[] $expected_trail      Platform requests, in order.
	 * @param string   $expected_customer   Customer the retried charge carries, stored on the user and the renewal order.
	 * @param bool     $is_paid             Whether the retried charge pays the renewal.
	 */
	public function test_renewal_with_a_missing_customer_uses_the_users_customer( string $renewal_customer_id, array $expected_trail, string $expected_customer, bool $is_paid ): void {
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		update_user_option( $user_id, WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, 'cus_user' );
		$token = new WC_Payment_Token_CC();
		$token->set_token( 'pm_saved' );
		$token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2040' );
		$token->set_user_id( $user_id );
		$token->save();
		$subscription = $this->create_woopayments_order( '10.00' );
		$subscription->set_customer_id( $user_id );
		$subscription->update_meta_data( '_stripe_customer_id', $renewal_customer_id );
		$subscription->add_payment_token( $token );
		$subscription->save();
		$renewal = $this->create_woopayments_order( '10.00' );
		$renewal->set_customer_id( $user_id );
		$renewal->update_meta_data( '_stripe_customer_id', $renewal_customer_id );
		$renewal->add_payment_token( $token );
		$renewal->save();
		$http_client = new FakeHttpClient();
		// Shape: Stripe's resource_missing error with its 404 (https://docs.stripe.com/error-codes/resource-missing), which the platform passes on, as WooPaymentsPaymentOperationServiceTest::stripe_no_such_customer() models it.
		$http_client->responses = array(
			$this->json_transport_response(
				404,
				array(
					'error' => array(
						'type'    => 'invalid_request_error',
						'code'    => 'resource_missing',
						'param'   => 'customer',
						'message' => "No such customer: '$renewal_customer_id'",
					),
				)
			),
		);
		if ( in_array( 'POST /sites/123/wcpay/customers', $expected_trail, true ) ) {
			// Shape: Stripe's Customer object (https://docs.stripe.com/api/customers/object), reduced to the id client 11.1.0
			// reads from it (includes/wc-payment-api/class-wc-payments-api-client.php:1377-1383).
			$http_client->responses[] = $this->json_transport_response( 200, array( 'id' => $expected_customer ) );
		}
		if ( $is_paid ) {
			// Shape: the PaymentIntent the platform returns for a confirmed card charge (Fixtures/platform-intention-basic-card.json, basic_card_usd_create_and_confirm), reduced to the fields this path reads.
			$http_client->responses[] = $this->json_transport_response(
				200,
				array(
					'id'             => 'pi_renewal',
					'object'         => 'payment_intent',
					'status'         => 'succeeded',
					'amount'         => 1000,
					'currency'       => 'usd',
					'customer'       => $expected_customer,
					'payment_method' => 'pm_saved',
					'metadata'       => array( 'order_id' => (string) $renewal->get_id() ),
				)
			);
		} else {
			// Shape: Stripe's invalid_request_error object (https://docs.stripe.com/api/errors), with the message Stripe
			// returned when a test store renewed a saved card on a newly created customer.
			$http_client->responses[] = $this->json_transport_response(
				400,
				array(
					'error' => array(
						'type'    => 'invalid_request_error',
						'message' => "The PaymentMethod pm_saved does not belong to the Customer you supplied $expected_customer. Please use this PaymentMethod with the Customer that it belongs to instead.",
					),
				)
			);
		}
		WooCommerceSubscriptionsDoubles::load_order_subscriptions();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ] = array( $renewal->get_id() => array( 'renewal' => array( $subscription->get_id() ) ) );

		try {
			$outcome = $this->sut->process_checkout_outcome(
				PaymentOperationContext::for_checkout(
					$renewal,
					WooPaymentsPersistenceVocabulary::GATEWAY_ID,
					'',
					array( 'payment_token' => (string) $token->get_id() ),
					array( 'scheduled_subscription_payment' => true )
				),
				$this->timeout_transport_provider( $http_client, true )
			);
		} finally {
			unset( $GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ] );
		}

		$this->assertSame( $is_paid ? PaymentOutcome::STATUS_COMPLETED : PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( $is_paid ? OrderStatus::COMPLETED : OrderStatus::FAILED, wc_get_order( $renewal->get_id() )->get_status(), 'The renewal has no shippable items, so a paid one completes.' );
		$this->assertSame( $expected_trail, array_map( static fn( array $request ): string => $request['method'] . ' ' . $request['path'], $http_client->requests ) );
		$this->assertSame( $renewal_customer_id, json_decode( (string) $http_client->requests[0]['body'], true )['customer'] ?? null );
		$this->assertSame( $expected_customer, json_decode( (string) end( $http_client->requests )['body'], true )['customer'] ?? null );
		$this->assertSame( $expected_customer, get_user_option( WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, $user_id ) );
		$this->assertSame( $expected_customer, wc_get_order( $renewal->get_id() )->get_meta( '_stripe_customer_id', true ) );
		$this->assertSame(
			$is_paid ? $expected_customer : $renewal_customer_id,
			wc_get_order( $subscription->get_id() )->get_meta( '_stripe_customer_id', true ),
			$is_paid ? 'The next renewal sends this customer first.' : 'A failed renewal syncs nothing to the subscription.'
		);
	}

	/**
	 * @testdox A renewal retried after its recovered charge timed out resends the user's customer under the kept key, so Stripe replays it rather than charging again.
	 *
	 * The first attempt finds the leftover Stripe Gateway customer missing and retries with the user's customer under the
	 * recovery key, which times out after Stripe may have charged. Client 11.1.0 stores the replacement customer on the order
	 * before that retry (includes/class-wc-payment-gateway-wcpay.php:5430). Without it, the next WooCommerce Subscriptions
	 * retry reads the leftover customer again and sends a different body under the kept key, which Stripe refuses; the
	 * renewal then retires the key and charges again under a fresh one.
	 */
	public function test_renewal_retry_after_an_ambiguous_recovered_charge_resends_the_same_customer(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		update_user_option( $user_id, WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, 'cus_user' );
		$token = new WC_Payment_Token_CC();
		$token->set_token( 'pm_saved' );
		$token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2040' );
		$token->set_user_id( $user_id );
		$token->save();
		$renewal = $this->create_woopayments_order( '10.00' );
		$renewal->set_customer_id( $user_id );
		$renewal->update_meta_data( '_stripe_customer_id', 'cus_StripeGatewayLeftover' );
		$renewal->add_payment_token( $token );
		$renewal->save();
		$http_client = new FakeHttpClient();
		// Shapes, in order: Stripe's resource_missing error with its 404 (https://docs.stripe.com/error-codes/resource-missing),
		// as WooPaymentsPaymentOperationServiceTest::stripe_no_such_customer() models it; the platform's 502 when its own call to
		// Stripe failed, as WooPaymentsPaymentOperationServiceTest::platform_bad_gateway() models it; the PaymentIntent the
		// platform returns for a confirmed card charge (Fixtures/platform-intention-basic-card.json), reduced to the fields read.
		$http_client->responses = array(
			$this->json_transport_response(
				404,
				array(
					'error' => array(
						'type'    => 'invalid_request_error',
						'code'    => 'resource_missing',
						'param'   => 'customer',
						'message' => "No such customer: 'cus_StripeGatewayLeftover'",
					),
				)
			),
			$this->json_transport_response(
				502,
				array(
					'code'    => 'wcpay_request_failure',
					'message' => 'Error: cURL error when connecting to Stripe (see error properties for details).',
					'data'    => array( 'status' => 502 ),
				)
			),
			$this->json_transport_response(
				200,
				array(
					'id'             => 'pi_renewal',
					'object'         => 'payment_intent',
					'status'         => 'succeeded',
					'amount'         => 1000,
					'currency'       => 'usd',
					'customer'       => 'cus_user',
					'payment_method' => 'pm_saved',
					'metadata'       => array( 'order_id' => (string) $renewal->get_id() ),
				)
			),
		);
		$provider               = $this->timeout_transport_provider( $http_client, true );
		$renew                  = fn( WC_Order $order ): PaymentOutcome => $this->sut->process_checkout_outcome(
			PaymentOperationContext::for_checkout(
				$order,
				WooPaymentsPersistenceVocabulary::GATEWAY_ID,
				'',
				array( 'payment_token' => (string) $token->get_id() ),
				array( 'scheduled_subscription_payment' => true )
			),
			$provider
		);

		$first = $renew( $renewal );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $first->get_status() );
		$this->assertSame( 'cus_user', wc_get_order( $renewal->get_id() )->get_meta( '_stripe_customer_id', true ), 'The renewal order holds the customer the timed-out retry sent.' );

		$retry = $renew( wc_get_order( $renewal->get_id() ) );

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $retry->get_status() );
		$this->assertCount( 3, $http_client->requests );
		$this->assertSame( $http_client->requests[1]['headers']['Idempotency-Key'] ?? null, $http_client->requests[2]['headers']['Idempotency-Key'] ?? null, 'The retry resends under the kept recovery key.' );
		$this->assertSame( 'cus_user', json_decode( (string) $http_client->requests[2]['body'], true )['customer'] ?? null, 'The same body, so Stripe replays the earlier result.' );
	}

	/**
	 * A WooCommerce Stripe Gateway customer left on the subscription, and the user's own customer gone from the platform.
	 *
	 * @return array<string,array{string,string[],string,bool}>
	 */
	public function provider_renewals_with_a_missing_customer(): array {
		return array(
			'a Stripe Gateway customer on the subscription' => array(
				'cus_StripeGatewayLeftover',
				array( 'POST /sites/123/wcpay/intentions', 'POST /sites/123/wcpay/intentions' ),
				'cus_user',
				true,
			),
		);
	}

	/**
	 * @testdox A saved-card renewal whose customer is missing fails with the platform's error and creates no customer when the user has no other customer: $_dataName.
	 *
	 * A new customer has none of the user's saved cards, so client 11.2.0 rethrows the original error instead of creating
	 * one (includes/class-wc-payment-gateway-wcpay.php:5449-5458; tests/unit/test-class-wc-payment-gateway-wcpay-subscriptions.php:699-727).
	 *
	 * @dataProvider provider_users_without_another_customer
	 *
	 * @param string $user_customer_id Customer stored on the user, empty for none.
	 */
	public function test_saved_card_renewal_with_a_missing_customer_creates_no_customer( string $user_customer_id ): void {
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		if ( '' !== $user_customer_id ) {
			update_user_option( $user_id, WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, $user_customer_id );
		}
		$token = new WC_Payment_Token_CC();
		$token->set_token( 'pm_saved' );
		$token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2040' );
		$token->set_user_id( $user_id );
		$token->save();
		$renewal = $this->create_woopayments_order( '10.00' );
		$renewal->set_customer_id( $user_id );
		$renewal->update_meta_data( '_stripe_customer_id', 'cus_stale' );
		$renewal->add_payment_token( $token );
		$renewal->save();
		$http_client = new FakeHttpClient();
		// Shapes: Stripe's resource_missing error with its 404 (https://docs.stripe.com/error-codes/resource-missing), as
		// WooPaymentsPaymentOperationServiceTest::stripe_no_such_customer() models it; a Customer object for a create
		// (https://docs.stripe.com/api/customers/object), which must not be asked for.
		$http_client->responses = array(
			$this->json_transport_response(
				404,
				array(
					'error' => array(
						'type'    => 'invalid_request_error',
						'code'    => 'resource_missing',
						'param'   => 'customer',
						'message' => "No such customer: 'cus_stale'",
					),
				)
			),
			$this->json_transport_response( 200, array( 'id' => 'cus_recreated' ) ),
		);

		$outcome = $this->sut->process_checkout_outcome(
			PaymentOperationContext::for_checkout(
				$renewal,
				WooPaymentsPersistenceVocabulary::GATEWAY_ID,
				'',
				array( 'payment_token' => (string) $token->get_id() ),
				array( 'scheduled_subscription_payment' => true )
			),
			$this->timeout_transport_provider( $http_client, true )
		);

		$this->assertSame( array( 'POST /sites/123/wcpay/intentions' ), array_map( static fn( array $request ): string => $request['method'] . ' ' . $request['path'], $http_client->requests ), 'No customer is created and nothing is retried.' );
		$this->assertSame( '' !== $user_customer_id ? $user_customer_id : false, get_user_option( WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, $user_id ), "The user's stored customer is unchanged." );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'resource_missing', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] ?? null, "The platform's error stands." );
		$this->assertSame( OrderStatus::FAILED, wc_get_order( $renewal->get_id() )->get_status() );
	}

	/**
	 * Users with no customer other than the missing one, as client 11.2.0's provider_absent_or_stale_user_customer() lists them.
	 *
	 * @return array<string,array{string}>
	 */
	public function provider_users_without_another_customer(): array {
		return array(
			'absent' => array( '' ),
			'stale'  => array( 'cus_stale' ),
		);
	}

	/**
	 * @testdox A renewal retried on the user's customer gives that customer to its subscription holding the charged card, and a refused retry to none: $_dataName.
	 *
	 * Client 11.2.0 replaces the missing customer on the renewal's subscriptions only after the retry returns, and only on
	 * those whose card is the one charged (includes/compat/subscriptions/trait-wc-payment-gateway-wcpay-subscriptions.php:1433-1448;
	 * tests/unit/test-class-wc-payment-gateway-wcpay-subscriptions.php:614-663, 729-743); a refused retry changes no subscription.
	 * The renewal order keeps the customer the retry sent, so a later retry under the kept key sends the same body.
	 *
	 * @dataProvider provider_retried_renewal_subscription_repairs
	 *
	 * @param array<string,mixed> $retry_response   Platform answer to the retried charge.
	 * @param int                 $retry_code       HTTP status of that answer.
	 * @param string              $expected_status  Renewal outcome status.
	 * @param string              $expected_charged Customer the subscription holding the charged card carries afterwards.
	 */
	public function test_renewal_retry_repairs_the_subscription_holding_the_charged_card( array $retry_response, int $retry_code, string $expected_status, string $expected_charged ): void {
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		update_user_option( $user_id, WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, 'cus_user' );
		$token = new WC_Payment_Token_CC();
		$token->set_token( 'pm_charged' );
		$token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2040' );
		$token->set_user_id( $user_id );
		$token->save();
		$orders = array();
		foreach ( array( 'subscription', 'renewal' ) as $name ) {
			$order = $this->create_woopayments_order( '10.00' );
			$order->set_customer_id( $user_id );
			$order->update_meta_data( '_stripe_customer_id', 'cus_stale' );
			$order->add_payment_token( $token );
			$order->save();
			$orders[ $name ] = $order;
		}
		$http_client = new FakeHttpClient();
		// Shapes: Stripe's resource_missing error with its 404 (https://docs.stripe.com/error-codes/resource-missing), as
		// WooPaymentsPaymentOperationServiceTest::stripe_no_such_customer() models it; then the provider's row.
		$http_client->responses = array(
			$this->json_transport_response(
				404,
				array(
					'error' => array(
						'type'    => 'invalid_request_error',
						'code'    => 'resource_missing',
						'param'   => 'customer',
						'message' => "No such customer: 'cus_stale'",
					),
				)
			),
			$this->json_transport_response( $retry_code, $retry_response + array( 'metadata' => array( 'order_id' => (string) $orders['renewal']->get_id() ) ) ),
		);
		WooCommerceSubscriptionsDoubles::load();
		$related = array( $orders['subscription']->get_id() );
		$GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ]   = array( $orders['renewal']->get_id() => array( 'renewal' => $related ) );
		$GLOBALS[ WooCommerceSubscriptionsDoubles::RENEWAL_SUBSCRIPTIONS ] = array( $orders['renewal']->get_id() => $related );

		try {
			$outcome = $this->sut->process_checkout_outcome(
				PaymentOperationContext::for_checkout(
					$orders['renewal'],
					WooPaymentsPersistenceVocabulary::GATEWAY_ID,
					'',
					array( 'payment_token' => (string) $token->get_id() ),
					array( 'scheduled_subscription_payment' => true )
				),
				$this->timeout_transport_provider( $http_client, true )
			);
		} finally {
			unset( $GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ], $GLOBALS[ WooCommerceSubscriptionsDoubles::RENEWAL_SUBSCRIPTIONS ] );
		}

		$subscription = wc_get_order( $orders['subscription']->get_id() );
		$this->assertSame( $expected_status, $outcome->get_status() );
		$this->assertSame( 'cus_user', json_decode( (string) end( $http_client->requests )['body'], true )['customer'] ?? null, "The retry sends the user's customer." );
		$this->assertSame( $expected_charged, $subscription->get_meta( '_stripe_customer_id', true ), 'The subscription holding the charged card.' );
		$this->assertSame( 'cus_user', wc_get_order( $orders['renewal']->get_id() )->get_meta( '_stripe_customer_id', true ), 'The renewal keeps the customer the retry sent.' );
	}

	/**
	 * Platform answers to a renewal's retry on the user's customer.
	 *
	 * Shapes: the PaymentIntent the platform returns for a confirmed card charge (Fixtures/platform-intention-basic-card.json),
	 * reduced to the fields this path reads, succeeded or processing as client 11.2.0's tests return it; and Stripe's
	 * invalid_request_error object (https://docs.stripe.com/api/errors) with client 11.2.0's refusal message
	 * (tests/unit/test-class-wc-payment-gateway-wcpay-subscriptions.php:742).
	 *
	 * @return array<string,array{array<string,mixed>,int,string,string}>
	 */
	public function provider_retried_renewal_subscription_repairs(): array {
		$intent = array(
			'id'             => 'pi_renewal',
			'object'         => 'payment_intent',
			'amount'         => 1000,
			'currency'       => 'usd',
			'customer'       => 'cus_user',
			'payment_method' => 'pm_charged',
		);

		return array(
			'retry succeeded'  => array( $intent + array( 'status' => 'succeeded' ), 200, PaymentOutcome::STATUS_COMPLETED, 'cus_user' ),
			'retry processing' => array( $intent + array( 'status' => 'processing' ), 200, PaymentOutcome::STATUS_AUTHORIZED, 'cus_user' ),
			'retry refused'    => array(
				array(
					'error' => array(
						'type'    => 'invalid_request_error',
						'message' => 'The PaymentMethod does not belong to the Customer you supplied.',
					),
				),
				400,
				PaymentOutcome::STATUS_FAILED,
				'cus_stale',
			),
		);
	}

	/**
	 * @testdox The payer's customer is refreshed from the order at shutdown, after the payment, and a failed refresh is only logged.
	 *
	 * Client 11.1.0 refreshes an existing customer in a shutdown callback that only logs a failure
	 * (includes/class-wc-payment-gateway-wcpay.php:1554-1583), so a platform error on the update cannot fail the payment.
	 */
	public function test_customer_refresh_runs_at_shutdown_and_a_failure_does_not_fail_the_payment(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		$logger  = RecordingWcLogger::install();
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		update_user_option( $user_id, WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, 'cus_user' );
		$order = $this->create_woopayments_order( '10.00' );
		$order->set_customer_id( $user_id );
		$order->save();
		$http_client = new FakeHttpClient();
		// Shapes, in order: the PaymentIntent the platform returns for a confirmed card charge
		// (Fixtures/platform-intention-basic-card.json), reduced to the fields read; the platform's own failure as a WordPress
		// REST error (code, message, data.status; WP_REST_Server::error_to_response()).
		$http_client->responses = array(
			$this->json_transport_response(
				200,
				array(
					'id'             => 'pi_refresh',
					'object'         => 'payment_intent',
					'status'         => 'succeeded',
					'amount'         => 1000,
					'currency'       => 'usd',
					'customer'       => 'cus_user',
					'payment_method' => 'pm_card_visa',
					'metadata'       => array( 'order_id' => (string) $order->get_id() ),
				)
			),
			$this->json_transport_response(
				500,
				array(
					'code'    => 'wcpay_server_error',
					'message' => 'The platform is unavailable.',
				)
			),
		);
		$provider               = $this->timeout_transport_provider( $http_client, true );

		$outcome = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_card_visa' ), $provider );

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( array( 'POST /sites/123/wcpay/intentions' ), array_map( static fn( array $request ): string => $request['method'] . ' ' . $request['path'], $http_client->requests ) );
		$this->assertSame( 10, has_action( 'shutdown', array( $this->customers_over_transport, 'refresh_queued_customers' ) ) );

		$this->customers_over_transport->refresh_queued_customers();

		$this->assertCount(
			1,
			array_filter( $logger->lines, static fn( array $line ): bool => 'error' === $line[0] && 'woopayments' === $line[2] && str_starts_with( $line[1], 'Failed to update customer during shutdown: ' ) ),
			'The failed refresh is logged, as client 11.1.0 logs it (includes/class-wc-payment-gateway-wcpay.php:1581-1582).'
		);
		$this->assertSame( 'POST /sites/123/wcpay/customers/cus_user', end( $http_client->requests )['method'] . ' ' . end( $http_client->requests )['path'] );
		$this->assertSame( 'cus_user', get_user_option( WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, $user_id ) );
		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
	}

	/**
	 * @testdox A guest order paid on order-pay is charged on the payer's customer, a logged-in shopper's own when there is one: $_dataName.
	 *
	 * Client 11.1.0 takes the logged-in user as the payer when the order has none (includes/class-wc-payment-gateway-wcpay.php:1542-1544),
	 * so a shopper who pays a guest order's link logged in pays on their own customer, with their saved card if they pick
	 * one, and the shutdown refresh names them in the customer's description (:1551). A guest stays on the session's customer.
	 *
	 * @dataProvider provider_guest_order_payers
	 *
	 * @param bool   $logged_in             Whether a shopper with a stored customer and a saved card is logged in.
	 * @param bool   $with_saved_card       Whether the shopper picks their saved card instead of entering a new one.
	 * @param string $expected_customer     Customer the charge and the shutdown refresh carry.
	 * @param string $expected_description  Description the shutdown refresh sends.
	 */
	public function test_order_pay_charges_a_guest_order_on_the_payers_customer( bool $logged_in, bool $with_saved_card, string $expected_customer, string $expected_description ): void {
		$shopper_id = self::factory()->user->create(
			array(
				'role'       => 'customer',
				'user_login' => 'guest-order-payer',
			)
		);
		update_user_option( $shopper_id, WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, 'cus_shopper' );
		$token = $this->create_saved_card( $shopper_id, 'pm_shopper_saved' );
		$order = $this->create_woopayments_order( '10.00' );
		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_last_name( 'Lovelace' );
		$order->save();
		WC()->session->set( WooPaymentsCustomerService::CUSTOMER_ID_SESSION_KEY, 'cus_session' );
		$expected_payment_method = $with_saved_card ? 'pm_shopper_saved' : 'pm_card_visa';
		$http_client             = new FakeHttpClient();
		// Shape: the platform's own failure as a WordPress REST error (code, message, data.status; WP_REST_Server::error_to_response()). Any request after the expected ones gets it.
		$http_client->response = $this->json_transport_response( 500, array( 'code' => 'wcpay_server_error' ) );
		// Shapes, in order: the PaymentIntent the platform returns for a confirmed card charge
		// (Fixtures/platform-intention-basic-card.json, basic_card_usd_create_and_confirm), reduced to the fields this path
		// reads; Stripe's Customer object (https://docs.stripe.com/api/customers/object), reduced to its id, for the refresh.
		$http_client->responses = array(
			$this->json_transport_response(
				200,
				array(
					'id'             => 'pi_guest_order',
					'object'         => 'payment_intent',
					'status'         => 'succeeded',
					'amount'         => 1000,
					'currency'       => 'usd',
					'customer'       => $expected_customer,
					'payment_method' => $expected_payment_method,
					'metadata'       => array( 'order_id' => (string) $order->get_id() ),
				)
			),
			$this->json_transport_response( 200, array( 'id' => $expected_customer ) ),
		);
		$gateway                = new WooPaymentsGateway();
		$gateway->init( $this->sut, $this->timeout_transport_provider( $http_client, true ) );
		wp_set_current_user( $logged_in ? $shopper_id : 0 );
		// As the order-pay form posts it.
		$_POST['woocommerce_pay']                       = '1';
		$_POST['payment_method']                        = WooPaymentsPersistenceVocabulary::GATEWAY_ID;
		$_POST['wc-woocommerce_payments-payment-token'] = $with_saved_card ? (string) $token->get_id() : 'new';
		if ( ! $with_saved_card ) {
			$_POST['wcpay-payment-method'] = 'pm_card_visa';
		}

		try {
			$result = $gateway->process_payment( $order->get_id() );

			$this->assertSame( 'success', $result['result'] ?? '' );
			$this->assertSame( 'POST /sites/123/wcpay/intentions', $http_client->requests[0]['method'] . ' ' . $http_client->requests[0]['path'] );
			$body = json_decode( (string) $http_client->requests[0]['body'], true );
			$this->assertSame( $expected_customer, $body['customer'] ?? null );
			$this->assertSame( $expected_payment_method, $body['payment_method'] ?? null );
			$this->assertCount( 1, $http_client->requests, 'No customer is looked up, created or updated before the charge.' );
			$order = wc_get_order( $order->get_id() );
			$this->assertTrue( $order->is_paid() );
			$this->assertSame( $with_saved_card ? array( $token->get_id() ) : array(), array_values( $order->get_payment_tokens() ), 'The saved card is attached to the order, as client 11.1.0 attaches it (gw:2071-2072).' );

			$this->customers_over_transport->refresh_queued_customers();

			$this->assertSame( 'POST /sites/123/wcpay/customers/' . $expected_customer, $http_client->requests[1]['method'] . ' ' . $http_client->requests[1]['path'] );
			$this->assertSame( $expected_description, json_decode( (string) $http_client->requests[1]['body'], true )['description'] ?? null );
			$this->assertSame( 'cus_shopper', get_user_option( WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, $shopper_id ) );
		} finally {
			wp_set_current_user( 0 );
			WC()->session->set( WooPaymentsCustomerService::CUSTOMER_ID_SESSION_KEY, null );
			$_POST = array();
		}
	}

	/**
	 * A logged-in shopper with a saved card or a new one, and a guest.
	 *
	 * @return array<string,array{bool,bool,string,string}>
	 */
	public function provider_guest_order_payers(): array {
		return array(
			'a logged-in shopper with their saved card' => array( true, true, 'cus_shopper', 'Name: Ada Lovelace, Username: guest-order-payer' ),
			'a logged-in shopper with a new card'       => array( true, false, 'cus_shopper', 'Name: Ada Lovelace, Username: guest-order-payer' ),
			'a guest with a new card'                   => array( false, false, 'cus_session', 'Name: Ada Lovelace, Guest' ),
		);
	}

	/**
	 * @testdox Order-pay refuses a saved card that is not the logged-in payer's and sends nothing to the platform: $_dataName.
	 *
	 * Client 11.1.0 accepts only the logged-in user's card (includes/class-payment-information.php:331-349), since core
	 * checks only the order key and nonce when the order-pay form is posted (WC_Form_Handler::pay_action()). The logged-in
	 * user must also be the payer: another user's card on a user's order is refused before any platform call.
	 *
	 * @dataProvider provider_saved_cards_not_the_logged_in_payers
	 *
	 * @param bool   $order_has_user      Whether the order belongs to a user other than the shopper.
	 * @param string $card_owner          Whose saved card is posted: the shopper, the order's user, or a third user.
	 * @param bool   $logged_in           Whether the shopper is logged in.
	 * @param bool   $with_payment_method Whether a new payment method is posted with the saved card.
	 */
	public function test_order_pay_refuses_a_saved_card_that_is_not_the_logged_in_payers( bool $order_has_user, string $card_owner, bool $logged_in, bool $with_payment_method ): void {
		$shopper_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$owner_id   = self::factory()->user->create( array( 'role' => 'customer' ) );
		$other_id   = self::factory()->user->create( array( 'role' => 'customer' ) );
		update_user_option( $shopper_id, WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, 'cus_shopper' );
		update_user_option( $owner_id, WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, 'cus_owner' );
		update_user_option( $other_id, WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, 'cus_other' );
		$card_owners = array(
			'shopper'    => $shopper_id,
			'order user' => $owner_id,
			'third user' => $other_id,
		);
		$token       = $this->create_saved_card( $card_owners[ $card_owner ], 'pm_not_the_payers' );
		$order       = $this->create_woopayments_order( '10.00' );
		$order->set_customer_id( $order_has_user ? $owner_id : 0 );
		$order->save();
		$http_client = new FakeHttpClient();
		// Shape: the platform's own failure as a WordPress REST error (code, message, data.status; WP_REST_Server::error_to_response()). No request is expected.
		$http_client->response = $this->json_transport_response( 500, array( 'code' => 'wcpay_server_error' ) );
		$gateway               = new WooPaymentsGateway();
		$gateway->init( $this->sut, $this->timeout_transport_provider( $http_client, true ) );
		wp_set_current_user( $logged_in ? $shopper_id : 0 );
		$_POST['woocommerce_pay']                       = '1';
		$_POST['payment_method']                        = WooPaymentsPersistenceVocabulary::GATEWAY_ID;
		$_POST['wc-woocommerce_payments-payment-token'] = (string) $token->get_id();
		if ( $with_payment_method ) {
			$_POST['wcpay-payment-method'] = 'pm_card_visa';
		}

		try {
			$result = $gateway->process_payment( $order->get_id() );

			$this->assertNotSame( 'success', $result['result'] ?? '' );
			$this->assertSame( array(), $http_client->requests );
			$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
			if ( ! $with_payment_method ) {
				$this->assertSame(
					array( 'Invalid or missing payment details. Please ensure the provided payment method is correctly entered.' ),
					array_column( wc_get_notices( 'error' ), 'notice' ),
					'The checkout refuses the card before any payment work.'
				);
			}
		} finally {
			wp_set_current_user( 0 );
			$_POST = array();
			wc_clear_notices();
		}
	}

	/**
	 * Saved cards a request may not use: the shopper's own on another user's order, a third user's on a guest order, and
	 * the order's user's card posted by another logged-in user or a logged-out visitor, alone or with a new payment method.
	 *
	 * @return array<string,array{bool,string,bool,bool}>
	 */
	public function provider_saved_cards_not_the_logged_in_payers(): array {
		return array(
			"the shopper's own card on another user's order" => array( true, 'shopper', true, false ),
			"another user's card on a guest order" => array( false, 'third user', true, false ),
			"the order user's card posted by another logged-in user" => array( true, 'order user', true, false ),
			"the order user's card posted by a logged-out visitor" => array( true, 'order user', false, false ),
			"the order user's card and a new payment method posted by another logged-in user" => array( true, 'order user', true, true ),
		);
	}

	/**
	 * @testdox A guest order whose logged-in payer's customer is missing is recovered as the order's guest, on a new session customer.
	 *
	 * The charge recovery resolves the customer from the order, not from the payer (replace_missing_customer_for_order()), as
	 * the mobile route and renewals need. Client 11.1.0 recreates the payer's customer here (gw:5427-5428); this keeps the
	 * payer's stored customer and charges a new guest customer.
	 */
	public function test_order_pay_recovery_on_a_guest_order_resolves_the_customer_from_the_order(): void {
		$shopper_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		update_user_option( $shopper_id, WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, 'cus_shopper_gone' );
		$order = $this->create_woopayments_order( '10.00' );
		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_last_name( 'Lovelace' );
		$order->save();
		$http_client = new FakeHttpClient();
		// Shape: the platform's own failure as a WordPress REST error (code, message, data.status; WP_REST_Server::error_to_response()). Any request after the expected ones gets it.
		$http_client->response = $this->json_transport_response( 500, array( 'code' => 'wcpay_server_error' ) );
		// Shapes, in order: Stripe's resource_missing error with its 404 (https://docs.stripe.com/error-codes/resource-missing),
		// which the platform passes on, as WooPaymentsPaymentOperationServiceTest::stripe_no_such_customer() models it;
		// Stripe's Customer object (https://docs.stripe.com/api/customers/object), reduced to the id client 11.1.0 reads from
		// it (includes/wc-payment-api/class-wc-payments-api-client.php:1377-1383); the PaymentIntent the platform returns for
		// a confirmed card charge (Fixtures/platform-intention-basic-card.json, basic_card_usd_create_and_confirm), reduced.
		$http_client->responses = array(
			$this->json_transport_response(
				404,
				array(
					'error' => array(
						'type'    => 'invalid_request_error',
						'code'    => 'resource_missing',
						'param'   => 'customer',
						'message' => "No such customer: 'cus_shopper_gone'",
					),
				)
			),
			$this->json_transport_response( 200, array( 'id' => 'cus_recovered' ) ),
			$this->json_transport_response(
				200,
				array(
					'id'             => 'pi_recovered',
					'object'         => 'payment_intent',
					'status'         => 'succeeded',
					'amount'         => 1000,
					'currency'       => 'usd',
					'customer'       => 'cus_recovered',
					'payment_method' => 'pm_card_visa',
					'metadata'       => array( 'order_id' => (string) $order->get_id() ),
				)
			),
		);
		$gateway                = new WooPaymentsGateway();
		$gateway->init( $this->sut, $this->timeout_transport_provider( $http_client, true ) );
		wp_set_current_user( $shopper_id );
		$_POST['woocommerce_pay']                       = '1';
		$_POST['payment_method']                        = WooPaymentsPersistenceVocabulary::GATEWAY_ID;
		$_POST['wc-woocommerce_payments-payment-token'] = 'new';
		$_POST['wcpay-payment-method']                  = 'pm_card_visa';

		try {
			$result = $gateway->process_payment( $order->get_id() );

			$this->assertSame( 'success', $result['result'] ?? '' );
			$this->assertSame(
				array( 'POST /sites/123/wcpay/intentions', 'POST /sites/123/wcpay/customers', 'POST /sites/123/wcpay/intentions' ),
				array_map( static fn( array $request ): string => $request['method'] . ' ' . $request['path'], $http_client->requests )
			);
			$this->assertSame( 'cus_shopper_gone', json_decode( (string) $http_client->requests[0]['body'], true )['customer'] ?? null );
			$this->assertSame( 'Name: Ada Lovelace, Guest', json_decode( (string) $http_client->requests[1]['body'], true )['description'] ?? null );
			$this->assertSame( 'cus_recovered', json_decode( (string) $http_client->requests[2]['body'], true )['customer'] ?? null );
			$this->assertSame( 'cus_shopper_gone', get_user_option( WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, $shopper_id ), "The payer's stored customer is not replaced." );
			$this->assertSame( 'cus_recovered', WC()->session->get( WooPaymentsCustomerService::CUSTOMER_ID_SESSION_KEY ) );
			$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
		} finally {
			wp_set_current_user( 0 );
			WC()->session->set( WooPaymentsCustomerService::CUSTOMER_ID_SESSION_KEY, null );
			$_POST = array();
		}
	}

	/**
	 * @testdox Optional post-lifecycle provider effects run after payment completion hooks.
	 */
	public function test_process_checkout_outcome_applies_post_lifecycle_provider_effects_after_lifecycle(): void {
		$order    = $this->create_woopayments_order( '10.00' );
		$sequence = new \ArrayObject();
		$outcome  = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_post_lifecycle', '', 'pm_post_lifecycle' );
		$provider = new class( $outcome, $sequence ) extends RecordingProvider implements ProviderOperationEffectApplierInterface, ProviderPostLifecycleEffectApplierInterface {
			/**
			 * Observed lifecycle sequence.
			 *
			 * @var \ArrayObject<int,string>
			 */
			private \ArrayObject $sequence;

			/**
			 * Constructor.
			 *
			 * @param PaymentOutcome $outcome  Provider outcome.
			 * @param \ArrayObject   $sequence Observed lifecycle sequence.
			 */
			public function __construct( PaymentOutcome $outcome, \ArrayObject $sequence ) {
				parent::__construct( $outcome );
				$this->sequence = $sequence;
			}

			/**
			 * Record the pre-lifecycle provider effect.
			 *
			 * @param PaymentOperationContext $context   Payment context.
			 * @param PaymentOutcome          $outcome   Provider outcome.
			 * @param string                  $operation Operation name.
			 * @return PaymentOutcome
			 */
			public function apply_operation_effects( PaymentOperationContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				$this->sequence[] = 'pre:' . $operation;
				$context->get_order()->set_payment_method_title( 'WooPayments' );
				$context->get_order()->save();

				return $outcome;
			}

			/**
			 * Record and apply the post-lifecycle provider effect.
			 *
			 * @param PaymentOperationContext $context   Payment context.
			 * @param PaymentOutcome          $outcome   Provider outcome.
			 * @param string                  $operation Operation name.
			 */
			public function apply_post_lifecycle_effects( PaymentOperationContext $context, PaymentOutcome $outcome, string $operation ): void {
				unset( $outcome );
				$this->sequence[] = 'post:' . $operation;
				$context->get_order()->set_payment_method_title( 'Visa credit card' );
				$context->get_order()->save();
			}
		};
		$observer = static function ( int $order_id ) use ( $sequence ): void {
			$completed_order = wc_get_order( $order_id );
			$sequence[]      = 'lifecycle:' . ( $completed_order instanceof WC_Order ? $completed_order->get_payment_method_title() : '' );
		};
		add_action( 'woocommerce_payment_complete', $observer );

		try {
			$result = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_post_lifecycle' ), $provider );
		} finally {
			remove_action( 'woocommerce_payment_complete', $observer );
		}
		$order = wc_get_order( $order->get_id() );

		$this->assertSame( $outcome, $result );
		$this->assertSame( array( 'pre:charge', 'lifecycle:WooPayments', 'post:charge' ), $sequence->getArrayCopy() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'Visa credit card', $order->get_payment_method_title() );
	}

	/**
	 * @testdox A completed PaymentIntent exposes its card title to synchronous lifecycle observers for direct checkout and scheduled renewal.
	 * @dataProvider completed_payment_intent_context_data
	 *
	 * @param bool $scheduled_subscription_payment Whether this is a scheduled subscription renewal.
	 */
	public function test_completed_payment_intent_exposes_card_title_to_synchronous_lifecycle_observers( bool $scheduled_subscription_payment ): void {
		$order                 = $this->create_woopayments_order( '10.00' );
		$unrelated_order       = $this->create_woopayments_order( '10.00' );
		$subscription          = $this->create_woopayments_order( '10.00' );
		$result                = $this->completed_link_payment_intent_result();
		$sequence              = new \ArrayObject();
		$provider              = $this->completed_payment_intent_provider( $result, $sequence );
		$context               = $this->completed_payment_intent_context( $order, $scheduled_subscription_payment );
		$filter_calls          = 0;
		$credential_sync_calls = 0;
		$display_sync_calls    = 0;
		add_filter(
			'wcpay_payment_request_payment_method_title_suffix',
			static function ( string $suffix ) use ( &$filter_calls, $sequence ): string {
				++$filter_calls;
				$sequence[] = 'suffix';
				return $suffix;
			}
		);
		WooCommerceSubscriptionsDoubles::load_order_subscriptions();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ] = new SubscriptionRelationshipsProbe(
			static function ( int $order_id ) use ( $order, $subscription, &$credential_sync_calls, &$display_sync_calls, $sequence ): array {
				if ( $order->get_id() !== $order_id ) {
					return array();
				}

				if ( 'Link (WooPayments)' === wc_get_order( $order_id )->get_payment_method_title() ) {
					++$display_sync_calls;
					$sequence[] = 'display-sync';
				} else {
					++$credential_sync_calls;
					$sequence[] = 'credential-sync';
				}
				return array( 'renewal' => array( $subscription->get_id() ) );
			}
		);
		$observed = array();
		$observer = static function ( int $order_id ) use ( $order, &$observed, $sequence ): void {
			if ( $order->get_id() !== $order_id ) {
				return;
			}

			$reloaded = wc_get_order( $order_id );
			if ( ! $reloaded instanceof WC_Order ) {
				return;
			}

			$observed[] = array(
				'id'                   => $reloaded->get_id(),
				'payment_method'       => $reloaded->get_payment_method(),
				'payment_method_title' => $reloaded->get_payment_method_title(),
				'last4'                => $reloaded->get_meta( 'last4', true ),
				'card_brand'           => $reloaded->get_meta( '_card_brand', true ),
			);
			$sequence[] = 'lifecycle:' . $reloaded->get_payment_method_title();
		};
		add_action( 'woocommerce_payment_complete', $observer );

		try {
			$this->sut->process_checkout_outcome( $context, $provider );
		} finally {
			remove_action( 'woocommerce_payment_complete', $observer );
			remove_all_filters( 'wcpay_payment_request_payment_method_title_suffix' );
			unset( $GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ] );
		}

		$order           = wc_get_order( $order->get_id() );
		$unrelated_order = wc_get_order( $unrelated_order->get_id() );

		$this->assertSame(
			array(
				array(
					'id'                   => $order instanceof WC_Order ? $order->get_id() : 0,
					'payment_method'       => WooPaymentsPersistenceVocabulary::GATEWAY_ID,
					'payment_method_title' => 'Link (WooPayments)',
					'last4'                => '',
					'card_brand'           => '',
				),
			),
			$observed
		);
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'Link (WooPayments)', $order->get_payment_method_title() );
		$this->assertSame( 1, $filter_calls );
		$this->assertSame( $scheduled_subscription_payment ? 1 : 0, $credential_sync_calls );
		$this->assertSame( 1, $display_sync_calls );
		$this->assertSame(
			$scheduled_subscription_payment
				? array( 'transport:scheduled', 'credential-sync', 'suffix', 'display-sync', 'lifecycle:Link (WooPayments)', 'finalization' )
				: array( 'transport:direct', 'suffix', 'display-sync', 'lifecycle:Link (WooPayments)', 'finalization' ),
			$sequence->getArrayCopy()
		);
		$this->assertSame( '', $order->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertInstanceOf( WC_Order::class, $subscription );
		$this->assertSame( 'Link (WooPayments)', $subscription->get_payment_method_title() );
		$this->assertInstanceOf( WC_Order::class, $unrelated_order );
		$this->assertSame( 'pending', $unrelated_order->get_status() );
		$this->assertSame( '', $unrelated_order->get_payment_method_title() );
	}

	/**
	 * Provide direct-checkout and scheduled-renewal contexts for the synchronous observer regression.
	 *
	 * @return array<string,array{bool}>
	 */
	public function completed_payment_intent_context_data(): array {
		return array(
			'direct checkout'   => array( false ),
			'scheduled renewal' => array( true ),
		);
	}

	/**
	 * @testdox A completed card PaymentIntent exposes its Visa title and card metadata to synchronous lifecycle observers for direct checkout and scheduled renewal.
	 * @dataProvider completed_payment_intent_context_data
	 *
	 * @param bool $scheduled_subscription_payment Whether this is a scheduled subscription renewal.
	 */
	public function test_completed_card_payment_intent_exposes_card_title_and_metadata_to_synchronous_lifecycle_observers( bool $scheduled_subscription_payment ): void {
		$order = $this->create_woopayments_order( '10.00' );
		$order->set_payment_method( 'cheque' );
		$order->save();
		$sequence = new \ArrayObject();
		$provider = $this->completed_payment_intent_provider( $this->completed_card_payment_intent_result(), $sequence );
		$context  = $this->completed_payment_intent_context( $order, $scheduled_subscription_payment );
		$observed = array();
		$observer = static function ( int $order_id ) use ( $order, &$observed, $sequence ): void {
			if ( $order->get_id() !== $order_id ) {
				return;
			}

			$reloaded = wc_get_order( $order_id );
			if ( ! $reloaded instanceof WC_Order ) {
				return;
			}

			$observed[] = array(
				'payment_method'       => $reloaded->get_payment_method(),
				'payment_method_title' => $reloaded->get_payment_method_title(),
				'last4'                => $reloaded->get_meta( 'last4', true ),
				'card_brand'           => $reloaded->get_meta( '_card_brand', true ),
			);
			$sequence[] = 'lifecycle:' . $reloaded->get_payment_method_title();
		};
		add_action( 'woocommerce_payment_complete', $observer );

		try {
			$this->sut->process_checkout_outcome( $context, $provider );
		} finally {
			remove_action( 'woocommerce_payment_complete', $observer );
		}

		$order = wc_get_order( $order->get_id() );

		$this->assertSame(
			array(
				array(
					'payment_method'       => WooPaymentsPersistenceVocabulary::GATEWAY_ID,
					'payment_method_title' => 'Visa credit card',
					'last4'                => '4242',
					'card_brand'           => 'visa',
				),
			),
			$observed
		);
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $order->get_payment_method() );
		$this->assertSame( 'Visa credit card', $order->get_payment_method_title() );
		$this->assertSame( '4242', $order->get_meta( 'last4', true ) );
		$this->assertSame( 'visa', $order->get_meta( '_card_brand', true ) );
		$this->assertSame(
			$scheduled_subscription_payment
				? array( 'transport:scheduled', 'lifecycle:Visa credit card', 'finalization' )
				: array( 'transport:direct', 'lifecycle:Visa credit card', 'finalization' ),
			$sequence->getArrayCopy()
		);
		$this->assertSame( '', $order->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ) );
	}

	/**
	 * @testdox An authorized card PaymentIntent exposes its Visa title to the on-hold emails the lifecycle sends.
	 *
	 * Client 11.1.0 sets the card title before update_order_status_from_intent() for every payment, so the emails the
	 * status change sends render it (class-wc-payment-gateway-wcpay.php:2157-2195).
	 */
	public function test_authorized_card_payment_intent_exposes_card_title_to_on_hold_observers(): void {
		$order = $this->create_woopayments_order( '10.00' );
		$order->set_payment_method( 'cheque' );
		$order->save();
		// A manual-capture intent carries its uncaptured charge as the recorded platform answer does (Providers/WooPayments/Fixtures/platform-manual-capture.json entries[0].response.body).
		$result                                   = $this->completed_card_payment_intent_result();
		$result['status']                         = 'requires_capture';
		$result['charges']['data'][0]['captured'] = false;
		$provider                                 = $this->completed_payment_intent_provider( $result, new \ArrayObject() );
		$observed                                 = array();
		$observer                                 = static function ( int $order_id ) use ( $order, &$observed ): void {
			if ( $order->get_id() !== $order_id ) {
				return;
			}

			$reloaded = wc_get_order( $order_id );
			if ( $reloaded instanceof WC_Order ) {
				$observed[] = $reloaded->get_payment_method_title();
			}
		};
		add_action( 'woocommerce_order_status_on-hold', $observer );

		try {
			$this->sut->process_checkout_outcome( $this->completed_payment_intent_context( $order, false ), $provider );
		} finally {
			remove_action( 'woocommerce_order_status_on-hold', $observer );
		}

		$this->assertSame( array( 'Visa credit card' ), $observed );
	}

	/**
	 * @testdox Completed express PaymentIntents store the title "$expected_title" when the suffix filter returns $label, through the payment lifecycle.
	 * @dataProvider malformed_payment_request_suffix_data
	 *
	 * @param string $label          Case label.
	 * @param mixed  $suffix         Filter return value.
	 * @param string $expected_title Stored payment method title.
	 */
	public function test_completed_express_payment_intents_ignore_malformed_title_suffix_filter_returns_through_payment_lifecycle( string $label, $suffix, string $expected_title ): void {
		unset( $label );
		$order           = $this->create_woopayments_order( '10.00' );
		$sequence        = new \ArrayObject();
		$provider        = $this->completed_payment_intent_provider( $this->completed_link_payment_intent_result(), $sequence );
		$filter_calls    = 0;
		$lifecycle_calls = 0;
		add_filter(
			'wcpay_payment_request_payment_method_title_suffix',
			static function () use ( $suffix, &$filter_calls, $sequence ) {
				++$filter_calls;
				$sequence[] = 'suffix';
				return $suffix;
			}
		);
		$observer = static function ( int $order_id ) use ( $order, &$lifecycle_calls, $sequence ): void {
			if ( $order->get_id() !== $order_id ) {
				return;
			}

			++$lifecycle_calls;
			$reloaded   = wc_get_order( $order_id );
			$sequence[] = 'lifecycle:' . ( $reloaded instanceof WC_Order ? $reloaded->get_payment_method_title() : '' );
		};
		add_action( 'woocommerce_payment_complete', $observer );

		try {
			$this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_malformed_suffix' ), $provider );
		} finally {
			remove_action( 'woocommerce_payment_complete', $observer );
			remove_all_filters( 'wcpay_payment_request_payment_method_title_suffix' );
		}

		$order = wc_get_order( $order->get_id() );

		$this->assertSame( 1, $filter_calls );
		$this->assertSame( 1, $lifecycle_calls );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( $expected_title, $order->get_payment_method_title() );
		$this->assertSame( array( 'transport:direct', 'suffix', 'lifecycle:' . $expected_title, 'finalization' ), $sequence->getArrayCopy() );
		$this->assertSame( '', $order->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ) );
	}

	/**
	 * Provide unusual public suffix filter returns: a malformed one keeps the default, an empty one removes the suffix
	 * as on the client (class-wc-payment-gateway-wcpay.php:2735-2740), where '__return_false' is the usual way to drop it.
	 *
	 * @return array<string,array{string,mixed,string}>
	 */
	public function malformed_payment_request_suffix_data(): array {
		return array(
			'array'                 => array( 'an array', array( 'unexpected' ), 'Link (WooPayments)' ),
			'non-stringable object' => array( 'an object', new \stdClass(), 'Link (WooPayments)' ),
			'false'                 => array( 'false', false, 'Link' ),
			'null'                  => array( 'null', null, 'Link' ),
			'empty string'          => array( 'an empty string', '', 'Link' ),
			'number'                => array( 'a number', 5, 'Link (5)' ),
		);
	}

	/**
	 * @testdox Recovery metadata mapping failures cannot replace a referenced provider outcome.
	 */
	public function test_process_checkout_outcome_keeps_provider_result_when_recovery_mapping_throws(): void {
		$order    = $this->create_woopayments_order( '10.00' );
		$outcome  = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_profile_recovery_failure', '', 'pm_profile_recovery_failure' );
		$provider = new class( $outcome ) extends RecordingProvider implements ProviderOperationEffectApplierInterface {
			/**
			 * Number of recovery mapping calls.
			 *
			 * @var int
			 */
			public int $outcome_meta_calls = 0;

			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double always throws.
			/**
			 * Fail recovery metadata mapping.
			 *
			 * @param PaymentOutcome $outcome Provider outcome.
			 * @return array<string,string>
			 * @throws RuntimeException Always.
			 */
			public function get_outcome_meta( PaymentOutcome $outcome ): array {
				unset( $outcome );
				++$this->outcome_meta_calls;
				throw new RuntimeException( 'Recovery metadata mapping failed.' );
			}

			/**
			 * Fail provider effect application after the remote payment succeeded.
			 *
			 * @param PaymentOperationContext $context   Payment context.
			 * @param PaymentOutcome          $outcome   Provider outcome.
			 * @param string                  $operation Operation name.
			 * @return PaymentOutcome
			 * @throws RuntimeException Always.
			 */
			public function apply_operation_effects( PaymentOperationContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				unset( $context, $outcome, $operation );
				throw new RuntimeException( 'Provider effect write failed.' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};

		$exception = $this->expect_outcome_apply_exception(
			fn() => $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_profile_recovery_failure' ), $provider )
		);

		$this->assertSame( $outcome, $exception->get_outcome() );
		$this->assertSame( 'Provider effect write failed.', $exception->get_failure()->getMessage(), 'The recovery failure must not replace the original failure.' );
		$this->assertSame( 1, $provider->outcome_meta_calls );
	}

	/**
	 * @testdox Successful captures stay reconcilable when provider effect application throws.
	 */
	public function test_capture_keeps_provider_success_when_effect_application_throws(): void {
		$order    = $this->create_woopayments_order( '10.00' );
		$outcome  = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_capture_effect_failure' );
		$provider = new class( $outcome ) extends RecordingProvider implements ProviderOperationEffectApplierInterface {
			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double always throws.
			/**
			 * Fail provider effect application after the remote capture succeeded.
			 *
			 * @param PaymentOperationContext $context   Payment context.
			 * @param PaymentOutcome          $outcome   Provider outcome.
			 * @param string                  $operation Operation name.
			 * @return PaymentOutcome
			 * @throws RuntimeException Always.
			 */
			public function apply_operation_effects( PaymentOperationContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				unset( $context, $outcome, $operation );
				throw new RuntimeException( 'Provider capture effect write failed.' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};

		$result = $this->sut->capture( PaymentOperationContext::for_capture( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), $provider );
		$order  = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $outcome, $result );
		$this->assertSame( 'pi_capture_effect_failure', $order->get_transaction_id() );
	}

	/**
	 * @testdox A $operation the order payment lock refuses while it holds $held_value calls no provider, fails, and logs one warning naming the refused operation and the holder.
	 *
	 * Client 11.1.0 takes no lock at checkout (class-wc-payment-gateway-wcpay.php:1251-1268), so every checkout refusal here
	 * is one the client would have allowed.
	 *
	 * @testWith ["checkout", "0b4c2a9e-6f1d-4c3b-9a7e-2d5f8c1e4b6a"]
	 *           ["checkout", "pi_existing"]
	 *           ["capture", "pi_existing"]
	 *           ["cancel", "pi_existing"]
	 *
	 * @param string $operation  Refused operation.
	 * @param string $held_value Lock value of the operation holding the lock: another checkout's key, or a payment reference.
	 */
	public function test_process_checkout_returns_failure_when_order_operation_is_locked( string $operation, string $held_value ): void {
		$order  = $this->create_woopayments_order( '10.00' );
		$logger = $this->create_fake_logger();
		add_filter(
			'woocommerce_logging_class',
			function () use ( $logger ) {
				return $logger;
			}
		);
		$token = $this->order_payment_lock->claim( $order, $this->persistence_vocabulary, $held_value, 'payment status update' );
		$this->assertNotNull( $token );

		$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_test' ) );
		try {
			switch ( $operation ) {
				case 'checkout':
					$outcome = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_test' ), $provider );
					break;
				case 'capture':
					$outcome = $this->sut->capture( PaymentOperationContext::for_capture( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), $provider );
					break;
				default:
					$outcome = $this->sut->cancel( PaymentOperationContext::for_cancel( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), $provider );
			}
		} finally {
			$this->order_payment_lock->release( $order, $this->persistence_vocabulary, $token );
		}

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( '', $outcome->get_provider_payment_id() );
		$this->assertSame( 0, $provider->charge_calls + $provider->capture_calls + $provider->cancel_calls, 'A refused operation must not reach the provider.' );
		$this->assertCount( 1, $logger->entries );
		$this->assertSame( 'warning', $logger->entries[0]['level'] );
		$this->assertMatchesRegularExpression( '/^order payment lock refused: order ' . $order->get_id() . ', refused ' . $operation . ', held by payment status update for \\d+s$/', $logger->entries[0]['message'] );
	}

	/**
	 * @testdox Should not charge a second submission of an order that a first submission paid after the second loaded it.
	 */
	public function test_process_checkout_does_not_charge_an_order_paid_after_it_was_loaded(): void {
		$order       = $this->create_woopayments_order( '10.00' );
		$second_view = wc_get_order( $order->get_id() );
		$third_view  = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $second_view );
		$this->assertInstanceOf( WC_Order::class, $third_view );

		// The first submission completes after the others passed the gateway's checks but before they claim the lock.
		// Each later submission is its own request with its own order object, which the re-check reads again in place.
		$first = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_first' ) );
		$this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_first' ), $first );

		$second  = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_second' ) );
		$outcome = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $second_view, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_second' ), $second );
		$third   = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $third_view, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_second' ), $second );
		$order   = wc_get_order( $order->get_id() );

		$this->assertSame( 0, $second->charge_calls, 'An order paid while this request waited for the lock must not be charged again.' );
		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertTrue( $outcome->get_data()[ PaymentOutcome::DATA_ORDER_PAID_BY_ANOTHER_REQUEST ] ?? false );
		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $third->get_status() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_CHECKOUT_REDIRECT, $third->get_data() );
		$this->assertSame( '', $third->get_redirect_url() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pi_first', $order->get_transaction_id() );
		$this->assertFalse( get_transient( $this->persistence_vocabulary->get_order_lock_key( $order ) ), 'The refusal must release the lock.' );
	}

	/**
	 * @testdox Should still charge an order that already had its status and payment when this request loaded it: $status.
	 *
	 * A subscription payment-method change re-runs checkout on a held or paid entity, and the gateway's own checks
	 * decide those before the lock; only a change since the load means another request got there first.
	 *
	 * @testWith ["on-hold"]
	 *           ["processing"]
	 *
	 * @param string $status Order status when this request loaded the order.
	 */
	public function test_process_checkout_charges_an_order_unchanged_since_it_was_loaded( string $status ): void {
		$order = $this->create_woopayments_order( '10.00' );
		$order->set_transaction_id( 'pi_existing' );
		$order->update_meta_data( '_intent_id', 'pi_existing' );
		$order->set_status( $status );
		$order->save();
		$loaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $loaded );

		$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_existing' ) );
		$this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $loaded, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_new' ), $provider );

		$this->assertSame( 1, $provider->charge_calls, 'The re-check under the lock must only refuse changes made after the load.' );
	}

	/**
	 * @testdox Should not charge a second submission after the first one's charge failed with an unknown outcome, and a later submission replays the first key.
	 *
	 * The first submission's charge gets a 502, so its outcome is unknown: the order goes to failed and keeps the charge
	 * key, with no payment reference. A second submission loaded before the first ran must not charge under a new key.
	 */
	public function test_process_checkout_does_not_charge_after_an_earlier_submission_failed_with_an_unknown_outcome(): void {
		$order       = $this->create_woopayments_order( '10.00' );
		$second_view = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $second_view );
		$sent_keys = new \ArrayObject();
		$provider  = $this->ambiguous_first_charge_provider( $sent_keys );

		$first_outcome = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_first' ), $provider );
		$after_first   = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $after_first );
		$unresolved_key = (string) $after_first->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $first_outcome->get_status() );
		$this->assertSame( 'failed', $after_first->get_status() );
		$this->assertSame( '', $after_first->get_meta( '_intent_id', true ) );
		$this->assertSame( array( $unresolved_key ), $sent_keys->getArrayCopy(), 'The ambiguous failure must keep the key it sent.' );

		$second_outcome = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $second_view, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_second' ), $provider );

		$this->assertSame( array( $unresolved_key ), $sent_keys->getArrayCopy(), 'A submission loaded before the ambiguous attempt must not send a charge.' );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $second_outcome->get_status() );
		$this->assertSame( 'A payment operation is already in progress for this order.', $second_outcome->get_data()[ PaymentOutcome::DATA_ERROR_MESSAGE ] ?? null );
		$this->assertFalse( get_transient( $this->persistence_vocabulary->get_order_lock_key( $order ) ), 'The refusal must release the lock.' );

		$retry = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $retry );
		$this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $retry, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_first' ), $provider );

		$this->assertSame( array( $unresolved_key, $unresolved_key ), $sent_keys->getArrayCopy(), 'A submission that loads the order after the ambiguous attempt replays its key.' );
	}

	/**
	 * @testdox A new-card submission refused under the unresolved key completes the order from the earlier request's intent ($status) and charges nothing.
	 *
	 * The first submission's charge gets a 502, so the order keeps its key and the ambiguity record. The shopper resubmits
	 * with a new card: Stripe refuses the unresolved key with an idempotency_error, the intents list shows the earlier request's
	 * intent holding the money, and the lifecycle applies it as the late answer. The shopper lands on order-received with
	 * the "We prevented multiple payments" flag.
	 *
	 * @testWith ["succeeded", "completed"]
	 *           ["requires_capture", "on-hold"]
	 *
	 * @param string $status       Status of the earlier request's intent.
	 * @param string $order_status Order status the lifecycle writes.
	 */
	public function test_process_checkout_pays_the_order_from_the_earlier_request_after_a_new_card_refusal( string $status, string $order_status ): void {
		$order                  = $this->create_woopayments_order( '10.00' );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			$this->json_transport_response(
				502,
				array(
					'code'    => 'wcpay_request_failure',
					'message' => 'Error: cURL error when connecting to Stripe (see error properties for details).',
					'data'    => array( 'status' => 502 ),
				)
			),
			$this->json_transport_response(
				400,
				array(
					'error' => array(
						'type'    => 'idempotency_error',
						'message' => 'Keys for idempotent requests can only be used with the same parameters they were first used with.',
					),
				)
			),
			$this->json_transport_response(
				200,
				array(
					'object'   => 'list',
					'data'     => array(
						array(
							'id'             => 'pi_earlier',
							'object'         => 'payment_intent',
							'status'         => $status,
							'amount'         => 1000,
							'currency'       => 'usd',
							'created'        => time() - 60,
							'customer'       => 'cus_timeout',
							'payment_method' => 'pm_earlier',
							'metadata'       => array(
								'order_id'  => (string) $order->get_id(),
								'order_key' => $order->get_order_key(),
							),
						),
					),
					'has_more' => false,
				)
			),
		);
		$provider               = $this->timeout_transport_provider( $http_client );

		$this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_first' ), $provider );
		$resubmit = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $resubmit );
		$outcome = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $resubmit, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_new' ), $provider );
		$paid    = wc_get_order( $order->get_id() );

		$this->assertCount( 3, $http_client->requests, 'The new card must not be charged.' );
		$this->assertNotSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'pi_earlier', $outcome->get_provider_payment_id() );
		$this->assertStringContainsString( 'wcpay_previous_successful_intent=yes', (string) ( $outcome->get_data()[ PaymentOutcome::DATA_CHECKOUT_REDIRECT ] ?? '' ) );
		$this->assertSame( $order_status, $paid->get_status() );
		$this->assertSame( 'pi_earlier', $paid->get_meta( '_intent_id', true ) );
		$this->assertSame( 'pm_earlier', $paid->get_meta( '_payment_method_id', true ) );
		$this->assertSame( '', $paid->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( '', $paid->get_meta( WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META, true ) );
	}

	/**
	 * @testdox A webhook that pays the order between submissions stops the next submission under the lock before it sends anything.
	 *
	 * The first submission's charge gets a 502, so the order keeps its key and the ambiguity record. The next submission
	 * loads the order; before it claims the lock, the earlier request's `payment_intent.succeeded` arrives and the webhook
	 * finds the order by its metadata alone (`EventOrderResolver::find_order_for_intent_event()`), so the
	 * order is paid. Under the lock the order is read again, found paid, and nothing is sent: no charge under the unresolved key
	 * and no lookup.
	 */
	public function test_process_checkout_charges_nothing_when_a_webhook_paid_the_order_between_submissions(): void {
		$order                  = $this->create_woopayments_order( '10.00' );
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			$this->json_transport_response(
				502,
				array(
					'code'    => 'wcpay_request_failure',
					'message' => 'Error: cURL error when connecting to Stripe (see error properties for details).',
					'data'    => array( 'status' => 502 ),
				)
			),
		);
		$provider               = $this->timeout_transport_provider( $http_client );
		$this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_first' ), $provider );
		$resubmit = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $resubmit );
		$this->assertNotSame( '', $resubmit->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ) );

		wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\EventIngestor::class )->process(
			array(
				'id'   => 'evt_paid_between_submissions',
				'type' => 'payment_intent.succeeded',
				'data' => array(
					'object' => array(
						'id'             => 'pi_earlier',
						'object'         => 'payment_intent',
						'status'         => 'succeeded',
						'amount'         => 1000,
						'currency'       => 'usd',
						'customer'       => 'cus_timeout',
						'payment_method' => 'pm_first',
						'metadata'       => array(
							'order_id'  => (string) $order->get_id(),
							'order_key' => $order->get_order_key(),
						),
						'charges'        => array(
							'data' => array(
								array(
									'id'             => 'ch_earlier',
									'payment_method' => 'pm_first',
								),
							),
						),
					),
				),
			)
		);
		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid(), 'The webhook pays the order from its metadata.' );

		$outcome = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $resubmit, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_new' ), $provider );

		$this->assertCount( 1, $http_client->requests, 'Nothing is sent after the webhook paid the order.' );
		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertTrue( $outcome->get_data()[ PaymentOutcome::DATA_ORDER_PAID_BY_ANOTHER_REQUEST ] ?? false );
		$this->assertSame( 'pi_earlier', wc_get_order( $order->get_id() )->get_meta( '_intent_id', true ) );
	}

	/**
	 * @testdox A submission whose lookup fails is refused without a charge and leaves the order status, and the next submission charges the new card once.
	 *
	 * The order is pending again before the resubmit, as a Store API checkout leaves it, so a refusal that changed the
	 * status would show.
	 */
	public function test_process_checkout_refuses_when_the_lookup_fails_and_charges_once_on_the_next_submission(): void {
		$order                  = $this->create_woopayments_order( '10.00' );
		$idempotency_error      = $this->json_transport_response(
			400,
			array(
				'error' => array(
					'type'    => 'idempotency_error',
					'message' => 'Keys for idempotent requests can only be used with the same parameters they were first used with.',
				),
			)
		);
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			$this->json_transport_response(
				502,
				array(
					'code'    => 'wcpay_request_failure',
					'message' => 'Error: cURL error when connecting to Stripe (see error properties for details).',
					'data'    => array( 'status' => 502 ),
				)
			),
			$idempotency_error,
			$this->json_transport_response(
				500,
				array(
					'error' => array(
						'type'    => 'api_error',
						'message' => 'An unknown error occurred',
					),
				)
			),
			$idempotency_error,
			$this->json_transport_response(
				200,
				array(
					'object'   => 'list',
					'data'     => array(),
					'has_more' => false,
				)
			),
			$this->json_transport_response(
				200,
				array(
					'id'             => 'pi_new_card',
					'status'         => 'succeeded',
					'amount'         => 1000,
					'currency'       => 'usd',
					'customer'       => 'cus_timeout',
					'payment_method' => 'pm_new',
				)
			),
		);
		$provider               = $this->timeout_transport_provider( $http_client );
		$this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_first' ), $provider );
		$pending = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $pending );
		$pending->set_status( 'pending' );
		$pending->save();

		$refused = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $pending, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_new' ), $provider );
		$kept    = wc_get_order( $order->get_id() );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $refused->get_status() );
		$this->assertSame( 'wcpay_charge_lookup_failed', $refused->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] ?? null );
		$this->assertSame( 'pending', $kept->get_status() );
		$this->assertNotSame( '', $kept->get_meta( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertIsArray( $kept->get_meta( WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META, true ) );
		$this->assertCount( 3, $http_client->requests, 'A failed lookup must not charge.' );

		$this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $kept, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_new' ), $provider );
		$paid    = wc_get_order( $order->get_id() );
		$charges = array_values( array_filter( $http_client->requests, static fn( array $request ): bool => 'POST' === $request['method'] ) );

		$this->assertSame( 'completed', $paid->get_status() );
		$this->assertSame( 'pi_new_card', $paid->get_meta( '_intent_id', true ) );
		$this->assertCount( 4, $charges, 'Two refused sends under the unresolved key, then exactly one charge of the new card.' );
		$this->assertNotSame( $charges[0]['headers']['Idempotency-Key'], $charges[3]['headers']['Idempotency-Key'], 'The new card is charged under a fresh key.' );
	}

	/**
	 * @testdox Should not charge an order another request changed since this request loaded it, and log why once: $_dataName.
	 *
	 * @dataProvider provide_unpaid_changes_by_another_submission
	 *
	 * @param string $loaded_reference Transaction ID and intent the order carries when this request loads it, or ''.
	 * @param string $status           Status another request writes, or '' for none.
	 * @param string $unresolved_key   Charge idempotency key another request stores on the order, or '' for none.
	 * @param string $transaction_id   Transaction ID another request records, or '' for none.
	 * @param string $intent_id        Payment intent another request records, or '' for none.
	 * @param string $reason           Refusal reason the warning carries.
	 */
	public function test_process_checkout_does_not_charge_an_order_another_submission_changed( string $loaded_reference, string $status, string $unresolved_key, string $transaction_id, string $intent_id, string $reason ): void {
		$order = $this->create_woopayments_order( '10.00' );
		if ( '' !== $loaded_reference ) {
			$order->set_transaction_id( $loaded_reference );
			$order->update_meta_data( '_intent_id', $loaded_reference );
			$order->save();
		}
		$loaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $loaded );

		if ( '' !== $status ) {
			$order->set_status( $status );
		}
		if ( '' !== $unresolved_key ) {
			$order->update_meta_data( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, $unresolved_key );
		}
		if ( '' !== $transaction_id ) {
			$order->set_transaction_id( $transaction_id );
		}
		if ( '' !== $intent_id ) {
			$order->update_meta_data( '_intent_id', $intent_id );
		}
		$order->save();

		$provider    = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_second' ) );
		$fake_logger = $this->create_fake_logger();
		add_filter(
			'woocommerce_logging_class',
			function () use ( $fake_logger ) {
				return $fake_logger;
			}
		);
		$outcome = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $loaded, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_second' ), $provider );
		$order   = wc_get_order( $order->get_id() );

		$this->assertSame( 0, $provider->charge_calls, 'Another request is at work on this order.' );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'A payment operation is already in progress for this order.', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_MESSAGE ] ?? null );
		$refusals = array_values( array_filter( $fake_logger->entries, static fn( array $entry ): bool => \WC_Log_Levels::WARNING === $entry['level'] ) );
		$this->assertCount( 1, $refusals, 'The refused charge must be logged once.' );
		$this->assertSame( $reason, $refusals[0]['context']['reason'] ?? null );
		$this->assertSame( sprintf( 'Checkout charged nothing: order %d changed before this request claimed its payment lock.', $order->get_id() ), $refusals[0]['message'] );
		$this->assertSame( 'order-payments', $refusals[0]['context']['source'] );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( '' === $status ? 'pending' : $status, $order->get_status(), 'The refusal must leave the order as the other request left it.' );
		$this->assertFalse( get_transient( $this->persistence_vocabulary->get_order_lock_key( $order ) ), 'The refusal must release the lock.' );
	}

	/**
	 * Unpaid changes another request makes between this request's load and its claim, with the reason each is refused for.
	 *
	 * @return array<string,array{string,string,string,string,string,string}>
	 */
	public function provide_unpaid_changes_by_another_submission(): array {
		return array(
			'put on hold with a payment on record' => array( 'pi_held', 'on-hold', '', '', '', 'order_status_changed' ),
			'unresolved charge key only'           => array( '', '', 'key_unknown_outcome', '', '', 'unresolved_charge_key_changed' ),
			'status and unresolved charge key'     => array( '', 'failed', 'key_unknown_outcome', '', '', 'order_status_changed' ),
			'new transaction ID'                   => array( 'pi_started', '', '', 'pi_other', '', 'payment_reference_changed' ),
			'new payment intent'                   => array( 'pi_started', '', '', '', 'pi_other', 'payment_reference_changed' ),
		);
	}

	/**
	 * @testdox Should charge with the order as read under the lock, and leave the caller's order object showing it.
	 */
	public function test_process_checkout_charges_with_the_order_read_under_the_lock(): void {
		$order  = $this->create_woopayments_order( '10.00' );
		$loaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $loaded );

		// Written by another request after this one loaded the order; no payment field changes, so the charge goes ahead.
		$order->update_meta_data( '_written_after_load', 'fresh' );
		$order->save();

		$provider = new class( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_fresh' ) ) extends RecordingProvider {
			/**
			 * Meta value the charge saw on its order.
			 *
			 * @var string
			 */
			public string $seen_meta = '';

			/**
			 * Record what the charge's order shows, then charge.
			 *
			 * @param PaymentOperationContext $context         Payment context.
			 * @param string                  $idempotency_key Idempotency key.
			 * @return PaymentOutcome
			 */
			public function charge( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
				$this->seen_meta = (string) $context->get_order()->get_meta( '_written_after_load', true );

				return parent::charge( $context, $idempotency_key );
			}
		};
		$this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $loaded, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_fresh' ), $provider );

		$this->assertSame( 1, $provider->charge_calls );
		$this->assertSame( 'fresh', $provider->seen_meta, 'The charge must see what the read under the lock returned.' );
		$this->assertSame( 'fresh', $loaded->get_meta( '_written_after_load', true ), 'The caller continues with the order as read under the lock.' );
		$this->assertTrue( $loaded->has_status( wc_get_is_paid_statuses() ), 'The caller sees the outcome applied on the same order.' );
	}

	/**
	 * @testdox Should complete zero-total checkout without calling the provider.
	 */
	public function test_process_checkout_completes_zero_total_without_provider_call(): void {
		$order    = $this->create_woopayments_order( '0.00' );
		$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_should_not_be_used' ) );

		$outcome = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_test' ), $provider );
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_NO_EXTERNAL_PAYMENT, $outcome->get_status() );
		$this->assertSame( '', $outcome->get_provider_payment_id() );
		$this->assertSame( 0, $provider->charge_calls );
		$this->assertSame( 'completed', $order->get_status() );
	}

	/**
	 * @testdox Should return true for zero-amount refunds without calling the provider.
	 */
	public function test_process_refund_zero_amount_returns_true_without_provider_call(): void {
		$order    = $this->create_woopayments_order( '10.00' );
		$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED ) );

		$result = $this->sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 0.00 ), $provider );

		$this->assertTrue( $result );
		$this->assertSame( 0, $provider->refund_calls );
	}

	/**
	 * @testdox A $amount refund context carries the amount once for the runtime and once in the payment data, and the provider receives it.
	 * @dataProvider refund_amount_provider
	 *
	 * @param float $amount Refund amount.
	 */
	public function test_process_refund_reads_the_amount_the_payment_data_carries( float $amount ): void {
		$order   = $this->create_woopayments_order( '10.00' );
		$context = PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, $amount, 'Adjustment' );
		$this->assertSame( $amount, $context->get_amount() );
		$this->assertSame( $context->get_amount(), $context->get_payment_data()['amount'] ?? null );
		$this->create_local_refund( $order, $amount, 'Adjustment' );

		// phpcs:disable Squiz.Commenting, Squiz.Classes.ClassFileName.NoMatch
		$provider = new class( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED ) ) extends RecordingProvider {
			public ?PaymentOperationContext $refund_context = null;

			public function refund( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
				$this->refund_context = $context;

				return parent::refund( $context, $idempotency_key );
			}
		};
		// phpcs:enable Squiz.Commenting, Squiz.Classes.ClassFileName.NoMatch

		$this->assertTrue( $this->sut->process_refund( $context, $provider ) );
		$this->assertSame( 1, $provider->refund_calls );
		$this->assertInstanceOf( PaymentOperationContext::class, $provider->refund_context );
		$this->assertSame( $amount, $provider->refund_context->get_amount() );
		$this->assertSame( $amount, $provider->refund_context->get_payment_data()['amount'] ?? null );
	}

	/**
	 * Refund amounts a merchant can enter.
	 *
	 * @return array<string,array{0:float}>
	 */
	public static function refund_amount_provider(): array {
		return array(
			'smallest unit' => array( 0.01 ),
			'partial'       => array( 2.5 ),
			'fractional'    => array( 7.25 ),
			'full'          => array( 10.0 ),
		);
	}

	/**
	 * @testdox A successful refund should retain its provider identity when deferred local effects throw.
	 */
	public function test_process_refund_keeps_provider_identity_when_effect_application_throws(): void {
		$order  = $this->create_woopayments_order( '10.00' );
		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 2.50,
				'reason'         => 'Adjustment',
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );

		$provider = new class( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 're_effect_failure' ) ) extends RecordingProvider implements ProviderOperationEffectApplierInterface {
			/**
			 * Applied operation names.
			 *
			 * @var string[]
			 */
			public array $effect_operations = array();

			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double always throws.
			/**
			 * Fail local effect application after the provider refund succeeded.
			 *
			 * @param PaymentOperationContext $context   Payment context.
			 * @param PaymentOutcome          $outcome   Provider outcome.
			 * @param string                  $operation Operation name.
			 * @return PaymentOutcome
			 * @throws RuntimeException Always.
			 */
			public function apply_operation_effects( PaymentOperationContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				unset( $context, $outcome );
				$this->effect_operations[] = $operation;
				throw new RuntimeException( 'Local refund effect failed.' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};

		$result = $this->sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 2.50, 'Adjustment' ), $provider );
		$order  = wc_get_order( $order->get_id() );
		$refund = wc_get_order( $refund->get_id() );

		$this->assertTrue( $result, 'A local effect failure must not report the completed provider refund as failed.' );
		$this->assertSame( array( 'refund' ), $provider->effect_operations );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$this->assertSame( 're_effect_failure', $refund->get_meta( '_wcpay_refund_id', true ), 'The exact refund row must retain the provider refund identity.' );
		$this->assertSame( '', $order->get_transaction_id(), 'A refund ID must not replace the parent payment transaction ID.' );
	}

	/**
	 * @testdox Reconciliation after a local effect failure never moves a refund row already linked to another provider refund.
	 */
	public function test_process_refund_reconciliation_keeps_an_existing_provider_refund_link(): void {
		$order  = $this->create_woopayments_order( '10.00' );
		$refund = $this->create_local_refund( $order, 2.50, 'Adjustment' );
		$refund->update_meta_data( '_wcpay_refund_id', 're_A' );
		$refund->save_meta_data();

		$provider = new class( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 're_B' ) ) extends RecordingProvider implements ProviderOperationEffectApplierInterface {
			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double always throws.
			/**
			 * Fail local effect application after the provider refund succeeded.
			 *
			 * @param PaymentOperationContext $context   Payment context.
			 * @param PaymentOutcome          $outcome   Provider outcome.
			 * @param string                  $operation Operation name.
			 * @return PaymentOutcome
			 * @throws RuntimeException Always.
			 */
			public function apply_operation_effects( PaymentOperationContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				unset( $context, $outcome, $operation );
				throw new RuntimeException( 'Local refund effect failed.' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};

		$fake_logger = $this->create_fake_logger();
		add_filter(
			'woocommerce_logging_class',
			function () use ( $fake_logger ) {
				return $fake_logger;
			}
		);

		$result = $this->sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 2.50, 'Adjustment' ), $provider );

		$this->assertTrue( $result, 'The provider refund succeeded, so the call must report success.' );
		$this->assertSame( 1, $provider->refund_calls );
		$this->assertSame( 're_A', wc_get_order( $refund->get_id() )->get_meta( '_wcpay_refund_id', true ), 'A row linked to another provider refund must keep that link.' );
		$this->assertCount( 1, $fake_logger->error_calls );
		$context = $fake_logger->error_calls[0]['context'];
		$this->assertSame( 'refund', $context['operation'] );
		$this->assertSame( 're_B', $context['payment_reference'] );
		$this->assertFalse( $context['reconciliation_persisted'] );
	}

	/**
	 * @testdox Two refunds of the same order, amount and reason reach the provider with distinct idempotency keys ($_dataName).
	 *
	 * Client 11.1.0 `process_refund()` never sets a caller key on the refund request
	 * (class-wc-payment-gateway-wcpay.php:2970-2976), so every refund call sends its own
	 * `Idempotency-Key` UUID (class-wc-payments-api-client.php:2690). A retry is then never answered with the earlier
	 * failure, even when the order's refund rows look the same at both calls.
	 *
	 * @dataProvider equal_refund_pairs
	 *
	 * @param bool $retry_after_failure Whether the first refund fails and WooCommerce deletes its row before an equal retry.
	 */
	public function test_two_equal_amount_partial_refunds_use_distinct_idempotency_keys( bool $retry_after_failure ): void {
		$order = $this->create_woopayments_order( '10.00' );
		if ( $retry_after_failure ) {
			$this->backdate_refund( $this->create_local_refund( $order, 2.50, 'Adjustment' ) );
		}

		// The provider links the processed refund via `_wcpay_refund_id`, mirroring the live synchronous and webhook paths.
		// The second refund resolves to its own row because that row is the order's newest refund when the second call reads it.
		$first_refund   = $this->create_local_refund( $order, 2.50, 'Adjustment' );
		$first_provider = new RecordingProvider(
			$retry_after_failure
				? new PaymentOutcome( PaymentOutcome::STATUS_FAILED, '', '', '', '', array( PaymentOutcome::DATA_ERROR_CODE => 'temporary_error' ) )
				: $this->successful_refund_outcome( 're_first' )
		);
		$first_result   = $this->sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 2.50, 'Adjustment' ), $first_provider );
		if ( $retry_after_failure ) {
			// WooCommerce deletes the refund row when the gateway refund fails (wc-order-functions.php:675-680).
			$first_refund->delete( true );
		}

		$this->create_local_refund( $order, 2.50, 'Adjustment' );
		$second_provider = new RecordingProvider( $this->successful_refund_outcome( 're_second' ) );
		$second_result   = $this->sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 2.50, 'Adjustment' ), $second_provider );

		if ( $retry_after_failure ) {
			$this->assertWPError( $first_result );
		} else {
			$this->assertTrue( $first_result );
		}
		$this->assertTrue( $second_result );
		$this->assertSame( 1, $first_provider->refund_calls, 'The first refund must reach the provider.' );
		$this->assertSame( 1, $second_provider->refund_calls, 'The second refund must reach the provider.' );
		$this->assertNotSame( '', $first_provider->last_idempotency_key );
		$this->assertNotSame(
			$first_provider->last_idempotency_key,
			$second_provider->last_idempotency_key,
			'Each refund call must send its own key, so the provider neither replays the first refund nor answers a retry with its failure.'
		);
	}

	/**
	 * Pairs of equal refunds: both kept, or a failed one whose row is deleted before an equal retry.
	 *
	 * @return array<string,array{bool}>
	 */
	public function equal_refund_pairs(): array {
		return array(
			'two kept refunds'                => array( false ),
			'a retry after a deleted failure' => array( true ),
		);
	}

	/**
	 * @testdox A refund links the newest refund row, never an older manual refund of the same amount and reason.
	 *
	 * Client 11.1.0 links the provider refund to the order's newest refund
	 * (class-wc-payment-gateway-wcpay.php:3003, class-wc-payments-utils.php:1080-1096 orders by ID descending)
	 * and writes `_wcpay_refund_id` on that row only (class-wc-payments-order-service.php:1943).
	 */
	public function test_process_refund_links_the_newest_refund_row(): void {
		$order         = $this->create_woopayments_order( '10.00' );
		$manual_refund = $this->backdate_refund( $this->create_local_refund( $order, 2.50, 'Adjustment' ) );
		$refund        = $this->create_local_refund( $order, 2.50, 'Adjustment' );

		$result = $this->sut->process_refund(
			PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 2.50, 'Adjustment' ),
			new RecordingProvider( $this->successful_refund_outcome( 're_newest' ) )
		);

		$this->assertTrue( $result );
		$this->assertSame( 're_newest', wc_get_order( $refund->get_id() )->get_meta( '_wcpay_refund_id', true ), 'The refund created for this call must carry the provider refund ID.' );
		$this->assertSame( '', wc_get_order( $manual_refund->get_id() )->get_meta( '_wcpay_refund_id', true ), 'An older manual refund must stay unlinked.' );
	}

	/**
	 * @testdox A refund links the row that was newest when it took the order lock, not one created during the provider call.
	 */
	public function test_process_refund_links_the_row_that_existed_before_the_provider_call(): void {
		$order  = $this->create_woopayments_order( '10.00' );
		$refund = $this->create_local_refund( $order, 2.50, 'Adjustment' );

		// phpcs:disable Squiz.Commenting, Squiz.Classes.ClassFileName.NoMatch
		$provider = new class( $this->successful_refund_outcome( 're_this_call' ) ) extends RecordingProvider {
			/** @var WC_Order_Refund|null */
			public $concurrent_refund = null;

			public function refund( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
				// A merchant's manual refund lands while the provider request is in flight.
				$this->concurrent_refund = wc_create_refund(
					array(
						'order_id'       => $context->get_order()->get_id(),
						'amount'         => 1.00,
						'reason'         => 'Manual',
						'refund_payment' => false,
					)
				);

				return parent::refund( $context, $idempotency_key );
			}
		};
		// phpcs:enable Squiz.Commenting, Squiz.Classes.ClassFileName.NoMatch

		$result = $this->sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 2.50, 'Adjustment' ), $provider );

		$this->assertTrue( $result );
		$this->assertInstanceOf( WC_Order_Refund::class, $provider->concurrent_refund );
		$this->assertSame( 're_this_call', wc_get_order( $refund->get_id() )->get_meta( '_wcpay_refund_id', true ), 'The row created for this call must carry the provider refund ID.' );
		$this->assertSame( '', wc_get_order( $provider->concurrent_refund->get_id() )->get_meta( '_wcpay_refund_id', true ), 'A refund row created during the provider call must stay unlinked.' );
	}

	/**
	 * @testdox A refund with no refund row on the order is refused before the provider call, writes nothing, and releases the lock.
	 *
	 * Client 11.1.0 sends the refund first and then returns `wcpay_edit_order_refund_not_found`
	 * (class-wc-payment-gateway-wcpay.php:3003-3007). The runtime refuses first so no money moves; the
	 * WooPayments gateway maps the neutral code to the client's.
	 */
	public function test_process_refund_without_a_refund_row_is_refused_before_the_provider_call(): void {
		$order    = $this->create_woopayments_order( '10.00' );
		$provider = new RecordingProvider( $this->successful_refund_outcome( 're_no_row' ) );

		$result = $this->sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 2.50, 'Adjustment' ), $provider );

		$this->assertSame( 0, $provider->refund_calls, 'A refund with no row to link must never reach the provider.' );
		$this->assertWPError( $result );
		$this->assertSame( 'order_payment_refund_not_found', $result->get_error_code() );
		$this->assertSame( 'A refund cannot be found for order: ' . $order->get_id(), $result->get_error_message() );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( '', $order->get_meta( '_wcpay_refund_status', true ), 'No refund metadata is written when the row is missing.' );
		$this->assertCount( 0, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertFalse( get_transient( $this->persistence_vocabulary->get_order_lock_key( $order ) ), 'The refusal must release the order payment lock.' );
	}

	/**
	 * @testdox Should persist provider refund metadata on the matching WC refund.
	 */
	public function test_process_refund_persists_provider_refund_metadata(): void {
		$order  = $this->create_woopayments_order( '10.00' );
		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 2.50,
				'reason'         => 'Adjustment',
				'refund_payment' => false,
			)
		);

		$this->assertInstanceOf( WC_Order_Refund::class, $refund );

		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_COMPLETED,
				're_native',
				'',
				'',
				'',
				array(
					'order_meta'  => array(
						'_wcpay_refund_status' => 'successful',
					),
					'refund_meta' => array(
						'_wcpay_refund_id'             => 're_native',
						'_wcpay_refund_transaction_id' => 'txn_refund',
					),
					'refund_note' => 'A refund of $2.50 was successfully processed using WooPayments. Reason: Adjustment. (<code>re_native</code>)',
				)
			)
		);

		$result = $this->sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 2.50, 'Adjustment' ), $provider );
		$order  = wc_get_order( $order->get_id() );
		$refund = wc_get_order( $refund->get_id() );

		$this->assertTrue( $result );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$this->assertSame( 'successful', $order->get_meta( '_wcpay_refund_status', true ) );
		$this->assertSame( 're_native', $refund->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( 'txn_refund', $refund->get_meta( '_wcpay_refund_transaction_id', true ) );
		$this->assertOrderHasNoteContaining( $order, 'A refund of' );
		$this->assertOrderHasNoteContaining( $order, 'was successfully processed using WooPayments' );
		$this->assertOrderHasNoteContaining( $order, 'Adjustment' );
		$this->assertOrderHasNoteContaining( $order, 're_native' );
	}

	/**
	 * @testdox A refund whose note hook throws has already saved the order's refund status and the refund's metadata.
	 */
	public function test_process_refund_saves_refund_metadata_before_the_note(): void {
		$order = $this->create_woopayments_order( '10.00' );
		// An earlier refund attempt on the order failed.
		$order->update_meta_data( '_wcpay_refund_status', 'failed' );
		$order->save();
		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 2.50,
				'reason'         => 'Adjustment',
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$note        = 'A refund of $2.50 was successfully processed using WooPayments. Reason: Adjustment. (<code>re_native</code>)';
		$provider    = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_COMPLETED,
				're_native',
				'',
				'',
				'',
				array(
					PaymentOutcome::DATA_ORDER_META  => array( '_wcpay_refund_status' => 'successful' ),
					PaymentOutcome::DATA_REFUND_META => array(
						'_wcpay_refund_id'             => 're_native',
						'_wcpay_refund_transaction_id' => 'txn_refund',
					),
					PaymentOutcome::DATA_REFUND_NOTE => $note,
					PaymentOutcome::DATA_REFUND_NOTE_IDENTITY => 'refund:re_native:created_successful',
					PaymentOutcome::DATA_REFUND_NOTE_EQUIVALENTS => array( $note ),
				)
			)
		);
		$hook_throws = 0;
		$throwing    = static function () use ( &$hook_throws ): void {
			++$hook_throws;
			throw new RuntimeException( 'A note hook failed.' );
		};
		add_action( 'woocommerce_order_note_added', $throwing );

		try {
			$result = $this->sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 2.50, 'Adjustment' ), $provider );
		} finally {
			remove_action( 'woocommerce_order_note_added', $throwing );
		}
		// Read past the order caches: an unsaved change on a cached order object must not pass for a saved one.
		$order  = wc_get_container()->get( OrderPaymentLifecycleService::class )->get_fresh_order_from_data_store( $order );
		$refund = wc_get_order( $refund->get_id() );

		$this->assertTrue( $result, 'The platform refunded, so the refund succeeds although its note hook failed.' );
		$this->assertSame( 1, $hook_throws, 'The refund note reached the throwing hook.' );
		$this->assertSame( 'successful', $order->get_meta( '_wcpay_refund_status', true ) );
		$this->assertSame( 'txn_refund', $refund->get_meta( '_wcpay_refund_transaction_id', true ) );
	}

	/**
	 * @testdox Malformed equivalent-note values are dropped before a refund note is matched, so only a real string rendering is adopted ($_dataName).
	 * @dataProvider malformed_refund_note_equivalents
	 *
	 * @param string  $reference     Provider refund ID.
	 * @param string  $existing_note Note already on the order.
	 * @param mixed[] $equivalents   Equivalent-note values the provider sends.
	 * @param string  $expected_note The one refund note that carries the identity afterwards.
	 */
	public function test_process_refund_filters_mixed_equivalent_notes_before_structural_adoption( string $reference, string $existing_note, array $equivalents, string $expected_note ): void {
		$identity = "refund:{$reference}:created_successful";
		$order    = $this->create_woopayments_order( '3.25' );
		$refund   = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 3.25,
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$existing_note_id = $order->add_order_note( $existing_note );

		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_COMPLETED,
				$reference,
				'',
				'',
				'',
				array(
					'refund_note'             => "Native refund note. (<code>{$reference}</code>)",
					'refund_note_identity'    => $identity,
					'refund_note_equivalents' => $equivalents,
				)
			)
		);

		$result = $this->sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 3.25 ), $provider );
		$order  = wc_get_order( $order->get_id() );

		$this->assertTrue( $result );
		$this->assertInstanceOf( WC_Order::class, $order );
		$refund_notes = array_values(
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
				static fn( $note ): bool => str_contains( $note->content, $reference )
			)
		);
		$this->assertCount( 1, $refund_notes );
		$this->assertSame( $expected_note, $refund_notes[0]->content );
		$this->assertSame( hash( 'sha256', $identity ), get_comment_meta( $refund_notes[0]->id, $provider->get_persistence_vocabulary()->get_note_identity_meta_key(), true ) );
		$this->assertSame( '', get_comment_meta( $refund_notes[0]->id, '_arbitrary_comment_meta', true ) );
		if ( $existing_note !== $expected_note ) {
			$this->assertSame( array(), get_comment_meta( $existing_note_id ), 'A note that only a coerced value would match must stay untouched.' );
		}
	}

	/**
	 * Equivalent-note lists holding values that are not strings, with the note each must leave carrying the identity.
	 *
	 * @return array<string,array{string,string,mixed[],string}>
	 */
	public function malformed_refund_note_equivalents(): array {
		return array(
			'a mixed list adopts the localized note' => array(
				're_mixed_equivalents',
				'Localized refund note. (<code>re_mixed_equivalents</code>)',
				array( 'Native refund note. (<code>re_mixed_equivalents</code>)', 42, array( '_arbitrary_comment_meta' => 'injected' ), 'Localized refund note. (<code>re_mixed_equivalents</code>)' ),
				'Localized refund note. (<code>re_mixed_equivalents</code>)',
			),
			// The note reading '42' is the trap: a value cast to a string would match it.
			'a numeric candidate matches no note'    => array(
				're_numeric_equivalent',
				'42',
				array( 42 ),
				'Native refund note. (<code>re_numeric_equivalent</code>)',
			),
		);
	}

	/**
	 * @testdox A refund note with a malformed identity or malformed equivalents falls back to its exact text and writes no comment meta ($_dataName).
	 * @dataProvider malformed_refund_note_structures
	 *
	 * @param mixed $identity            Structural note identity.
	 * @param bool  $include_equivalents Whether the equivalents field is sent.
	 * @param mixed $equivalents         Equivalents field value.
	 */
	public function test_process_refund_does_not_coerce_malformed_structural_note_values( $identity, bool $include_equivalents, $equivalents ): void {
		$order  = $this->create_woopayments_order( '2.75' );
		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 2.75,
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$note_id = $order->add_order_note( self::FALLBACK_REFUND_NOTE );

		$data = array(
			PaymentOutcome::DATA_REFUND_NOTE          => self::FALLBACK_REFUND_NOTE,
			PaymentOutcome::DATA_REFUND_NOTE_IDENTITY => $identity,
		);
		if ( $include_equivalents ) {
			$data[ PaymentOutcome::DATA_REFUND_NOTE_EQUIVALENTS ] = $equivalents;
		}
		$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 're_malformed_structure', '', '', '', $data ) );

		$result = $this->sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 2.75 ), $provider );

		$this->assertTrue( $result );
		$this->assertCount( 1, array_filter( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), static fn( $order_note ): bool => self::FALLBACK_REFUND_NOTE === $order_note->content ) );
		$this->assertSame( array(), get_comment_meta( $note_id ) );
	}

	/**
	 * Malformed note structures: an identity that is not a non-empty string, or equivalents that are absent or not a list.
	 *
	 * @return array<string,array{mixed,bool,mixed}>
	 */
	public function malformed_refund_note_structures(): array {
		return array(
			'empty identity'         => array( '', true, array( self::FALLBACK_REFUND_NOTE ) ),
			'list as identity'       => array( array( 'refund:re_malformed_structure:created_successful' ), true, array( self::FALLBACK_REFUND_NOTE ) ),
			'number as identity'     => array( 42, true, array( self::FALLBACK_REFUND_NOTE ) ),
			'no equivalents'         => array( 'refund:re_malformed_structure:created_successful', false, null ),
			'equivalents not a list' => array( 'refund:re_malformed_structure:created_successful', true, 'not-an-array' ),
		);
	}

	/**
	 * @testdox An invalid exact refund target after provider success must enter reconciliation instead of failing silently.
	 */
	public function test_process_refund_logs_reconciliation_when_resolved_refund_disappears_after_provider_success(): void {
		$order  = $this->create_woopayments_order( '10.00' );
		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 2.50,
				'reason'         => 'Adjustment',
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );

		$provider = new class( $this->successful_refund_outcome( 're_missing_target' ), $refund->get_id() ) extends RecordingProvider implements ProviderOperationEffectApplierInterface {
			/**
			 * Exact local refund ID to remove after provider transport.
			 *
			 * @var int
			 */
			private int $refund_id;

			/**
			 * Constructor.
			 *
			 * @param PaymentOutcome $outcome   Successful provider outcome.
			 * @param int            $refund_id Exact local refund ID.
			 */
			public function __construct( PaymentOutcome $outcome, int $refund_id ) {
				parent::__construct( $outcome );
				$this->refund_id = $refund_id;
			}

			/**
			 * Remove the exact local target after provider transport, before generic effects are applied.
			 *
			 * @param PaymentOperationContext $context   Payment context.
			 * @param PaymentOutcome          $outcome   Provider outcome.
			 * @param string                  $operation Operation name.
			 * @return PaymentOutcome
			 */
			public function apply_operation_effects( PaymentOperationContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				unset( $context, $operation );
				$refund = wc_get_order( $this->refund_id );
				if ( $refund instanceof WC_Order_Refund ) {
					$refund->delete( true );
				}

				return $outcome;
			}
		};

		$fake_logger = $this->create_fake_logger();
		add_filter(
			'woocommerce_logging_class',
			function () use ( $fake_logger ) {
				return $fake_logger;
			}
		);

		$result = $this->sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 2.50, 'Adjustment' ), $provider );

		$this->assertTrue( $result, 'Provider success must remain successful while the local failure is made reconcilable.' );
		$this->assertCount( 1, $fake_logger->error_calls, 'The invalid post-provider target must emit one reconciliation error log.' );
		$context = $fake_logger->error_calls[0]['context'];
		$this->assertSame( 'refund', $context['operation'] );
		$this->assertSame( 're_missing_target', $context['payment_reference'] );
		$this->assertFalse( $context['reconciliation_persisted'] );
	}

	/**
	 * @testdox A refund refused by the order payment lock should log one warning naming the holder; a free lock logs nothing.
	 *
	 * WooPayments 11.1.0 takes no lock on refunds (WC_Payments_Utils::is_order_locked() guards only
	 * intent-driven status updates); the payments runtime refuses it, so the refusal must be visible in the logs.
	 */
	public function test_refund_refused_by_the_order_payment_lock_logs_one_warning(): void {
		$order  = $this->create_woopayments_order( '10.00' );
		$logger = $this->create_fake_logger();
		add_filter(
			'woocommerce_logging_class',
			function () use ( $logger ) {
				return $logger;
			}
		);

		$refused_refund  = null;
		$refusal_entries = array();
		// phpcs:disable Squiz.Commenting, Squiz.Classes.ClassFileName.NoMatch
		$provider = new class( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_lock_refusal' ) ) extends RecordingProvider {
			/** @var callable|null */
			public $during_capture = null;

			public function capture( PaymentOperationContext $context, string $operation_key ): PaymentOutcome {
				if ( null !== $this->during_capture ) {
					( $this->during_capture )();
				}

				return parent::capture( $context, $operation_key );
			}
		};
		// phpcs:enable Squiz.Commenting, Squiz.Classes.ClassFileName.NoMatch
		$provider->during_capture = function () use ( &$refused_refund, &$refusal_entries, $order, $provider, $logger ) {
			$logged_before   = count( $logger->entries );
			$refused_refund  = $this->sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 1.00, 'Requested during capture' ), $provider );
			$refusal_entries = array_slice( $logger->entries, $logged_before );
		};

		$this->create_local_refund( $order, 2.50, 'Adjustment' );
		$free_refund      = $this->sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 2.50, 'Adjustment' ), $provider );
		$logged_when_free = $logger->entries;
		$this->sut->capture( PaymentOperationContext::for_capture( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), $provider );

		$this->assertTrue( $free_refund );
		$this->assertSame( array(), $logged_when_free, 'A refund that claims a free lock must log nothing.' );
		$this->assertWPError( $refused_refund );
		$this->assertSame( 'order_payment_refund_locked', $refused_refund->get_error_code() );
		$this->assertSame( 1, $provider->refund_calls, 'The refused refund must not reach the provider.' );
		$this->assertCount( 1, $refusal_entries, 'The refused refund must write exactly one log line.' );
		$this->assertSame( 'warning', $refusal_entries[0]['level'] );
		$this->assertMatchesRegularExpression(
			'/^order payment lock refused: order ' . $order->get_id() . ', refused refund, held by capture for \d+s$/',
			$refusal_entries[0]['message']
		);
	}

	/**
	 * @testdox Failed captures persist the provider capture-failure metadata.
	 */
	public function test_capture_failure_uses_provider_outcome_metadata_mapper(): void {
		$order = $this->create_woopayments_order( '10.00' );
		$order->set_transaction_id( 'pi_mapper_capture_failure' );
		$order->save();

		$provider = new class( new PaymentOutcome( PaymentOutcome::STATUS_FAILED, 'pi_mapper_capture_failure' ) ) extends RecordingProvider implements ProviderOutcomeMetadataMapperInterface {
			/**
			 * Map a neutral outcome to provider order meta.
			 *
			 * @param PaymentOutcome $outcome Provider outcome.
			 * @return array<string,string>
			 */
			public function get_outcome_meta( PaymentOutcome $outcome ): array {
				unset( $outcome );

				return array( '_mapper_capture_state' => 'mapped' );
			}

			/**
			 * Map a failed capture outcome to provider order meta.
			 *
			 * @param PaymentOutcome $outcome Provider outcome.
			 * @return array<string,string>
			 */
			public function get_failed_capture_or_cancel_outcome_meta( PaymentOutcome $outcome ): array {
				unset( $outcome );

				return array( '_mapper_capture_state' => 'authorization-active' );
			}
		};

		$this->sut->capture( PaymentOperationContext::for_capture( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), $provider );
		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'authorization-active', $order->get_meta( '_mapper_capture_state', true ) );
	}

	/**
	 * @testdox A failed $operation should leave the authorized order on hold with its authorization state, fees and the failure note.
	 * @testWith ["capture", "Capture failed note."]
	 *           ["cancel", "Cancellation failed note."]
	 *
	 * @param string $operation Operation: capture or cancel.
	 * @param string $note      Failure note the provider outcome carries.
	 */
	public function test_authorization_operation_failure_preserves_authorized_order_status( string $operation, string $note ): void {
		$order = $this->create_woopayments_order( '10.00' );
		$order->set_transaction_id( 'pi_auth' );
		$order->update_meta_data( '_intent_id', 'pi_auth' );
		$order->update_meta_data( '_intention_status', 'requires_capture' );
		$order->update_meta_data( '_wcpay_transaction_fee', '0.65' );
		$order->update_meta_data( '_wcpay_net', '9.35' );
		$order->save();
		$order->update_status( 'on-hold' );

		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_FAILED,
				'pi_auth',
				'',
				'',
				'',
				array( 'note' => $note )
			)
		);

		$outcome = 'capture' === $operation
			? $this->sut->capture( PaymentOperationContext::for_capture( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), $provider )
			: $this->sut->cancel( PaymentOperationContext::for_cancel( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), $provider );
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 1, 'capture' === $operation ? $provider->capture_calls : $provider->cancel_calls );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( 'requires_capture', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( '0.65', $order->get_meta( '_wcpay_transaction_fee', true ) );
		$this->assertSame( '9.35', $order->get_meta( '_wcpay_net', true ) );
		$this->assertOrderHasNoteContaining( $order, $note );
	}

	/**
	 * @testdox An expired-authorization capture failure moves the order to failed with the expired note.
	 */
	public function test_capture_expired_authorization_fails_the_order(): void {
		$order = $this->create_woopayments_order( '10.00' );
		$order->set_transaction_id( 'pi_capture_expired' );
		$order->update_meta_data( '_intent_id', 'pi_capture_expired' );
		$order->update_meta_data( '_intention_status', 'requires_capture' );
		$order->save();
		$order->update_status( 'on-hold' );

		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_FAILED,
				'pi_capture_expired',
				'',
				'',
				'',
				array(
					PaymentOutcome::DATA_NOTE      => 'Payment authorization has <strong>expired</strong>.',
					PaymentOutcome::DATA_NOTE_TYPE => PaymentLifecycleEvent::NOTE_TYPE_CAPTURE_EXPIRED,
					WooPaymentsOutcomeMetadataMapper::OUTCOME_META_KEY => array( '_intention_status' => 'canceled' ),
				)
			)
		);

		$outcome = $this->sut->capture( PaymentOperationContext::for_capture( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), $provider );
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'failed', $order->get_status(), 'An expired authorization must fail the order like the charge.expired webhook.' );
		$this->assertSame( 'canceled', $order->get_meta( '_intention_status', true ) );
		$this->assertOrderHasNoteContaining( $order, 'expired' );
	}

	/**
	 * @testdox A failed $operation whose local application throws leaves the authorization the provider still holds as the order records it.
	 *
	 * The platform refuses the operation and the re-fetched intent still requires capture, so nothing moved. A note hook then
	 * throws while the failure is applied. Client 11.1.0 writes nothing after such a throw: the capture failure adds its note
	 * before it saves `requires_capture` (includes/class-wc-payments-order-service.php:491-508, :2889-2895), and the cancel
	 * failure adds its note before it saves the re-fetched status (includes/class-wc-payment-gateway-wcpay.php:4104-4130).
	 *
	 * @dataProvider provide_refused_authorization_operations
	 *
	 * @param string              $operation Operation: capture or cancel.
	 * @param array<string,mixed> $refusal   Platform answer to the operation.
	 */
	public function test_failed_authorization_operation_keeps_the_authorization_when_applying_it_throws( string $operation, array $refusal ): void {
		$order = $this->create_woopayments_order( '10.00' );
		$order->set_transaction_id( 'pi_auth' );
		$order->update_meta_data( '_intent_id', 'pi_auth' );
		$order->update_meta_data( '_intention_status', 'requires_capture' );
		$order->save();
		$order->update_status( 'on-hold' );

		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			$refusal,
			// The re-fetch after a failed capture or cancel (client 11.1.0 includes/class-wc-payment-gateway-wcpay.php:4014, :4084);
			// the fields are those of a PaymentIntent (https://docs.stripe.com/api/payment_intents/object).
			$this->json_transport_response(
				200,
				array(
					'id'       => 'pi_auth',
					'object'   => 'payment_intent',
					'status'   => 'requires_capture',
					'amount'   => 1000,
					'currency' => 'usd',
				)
			),
		);

		$provider    = $this->timeout_transport_provider( $http_client );
		$hook_throws = 0;
		add_action(
			'woocommerce_order_note_added',
			static function () use ( &$hook_throws ): void {
				++$hook_throws;
				throw new RuntimeException( 'A note hook failed.' );
			}
		);
		$fake_logger = $this->create_fake_logger();
		add_filter(
			'woocommerce_logging_class',
			function () use ( $fake_logger ) {
				return $fake_logger;
			}
		);

		$outcome = 'capture' === $operation
			? $this->sut->capture( PaymentOperationContext::for_capture( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), $provider )
			: $this->sut->cancel( PaymentOperationContext::for_cancel( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), $provider );
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 1, $hook_throws, 'Applying the failure must reach the throwing note hook.' );
		$this->assertCount( 2, $http_client->requests, 'The operation and the re-fetch reach the platform.' );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( 'pi_auth', $order->get_transaction_id() );
		$this->assertSame( 'requires_capture', $order->get_meta( '_intention_status', true ), 'The provider still holds the authorization.' );
		$apply_logs = array_values(
			array_filter(
				$fake_logger->error_calls,
				static fn( array $call ): bool => 0 === strpos( $call['message'], 'Payment provider operation returned a reconcilable outcome' )
			)
		);
		$this->assertCount( 1, $apply_logs );
		$this->assertFalse( $apply_logs[0]['context']['reconciliation_persisted'] );
		$this->assertSame( 'failed_outcome', $apply_logs[0]['context']['reconciliation_skipped'] ?? null, 'The log line says the save was skipped for a failed outcome.' );
	}

	/**
	 * Capture and cancel refusals that leave the authorization in place.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>}>
	 */
	public function provide_refused_authorization_operations(): array {
		return array(
			// Stripe's decline passed through by the platform, parsed by client 11.1.0
			// includes/wc-payment-api/class-wc-payments-api-client.php:2852-2871 (error.code, error.message, error.type).
			'declined capture' => array(
				'capture',
				$this->json_transport_response(
					402,
					array(
						'error' => array(
							'type'         => 'card_error',
							'code'         => 'card_declined',
							'decline_code' => 'generic_decline',
							'message'      => 'Your card was declined.',
						),
					)
				),
			),
			// Stripe's lock_timeout (https://docs.stripe.com/error-codes#lock-timeout), parsed at the same client lines.
			'refused cancel'   => array(
				'cancel',
				$this->json_transport_response(
					429,
					array(
						'error' => array(
							'type'    => 'invalid_request_error',
							'code'    => 'lock_timeout',
							'message' => 'This object cannot be accessed right now because another API request or Stripe process is currently accessing it.',
						),
					)
				),
			),
		);
	}

	/**
	 * @testdox A failed capture of a payment held for review marks the fraud box review_failed.
	 *
	 * The merchant approves a held-for-review payment and the platform refuses the capture; the re-fetched intent still
	 * requires capture. Client 11.1.0 mark_payment_capture_failed() sets the fraud meta box type to review_failed when the
	 * order's fraud outcome is review (includes/class-wc-payments-order-service.php:502-504). The refusal is Stripe's decline
	 * as the platform passes it on (parsed by includes/wc-payment-api/class-wc-payments-api-client.php:2852-2871).
	 */
	public function test_failed_capture_of_a_payment_held_for_review_marks_the_fraud_box_review_failed(): void {
		$order = $this->create_authorized_order_for_failed_operations();
		$order->update_meta_data( '_wcpay_fraud_outcome_status', 'review' );
		$order->update_meta_data( '_wcpay_fraud_meta_box_type', 'review' );
		$order->save();
		$http_client            = new FakeHttpClient();
		$http_client->responses = array( $this->declined_capture_response( 'Your card was declined.' ), $this->still_capturable_intent_response() );

		$outcome = $this->sut->capture( PaymentOperationContext::for_capture( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), $this->timeout_transport_provider( $http_client ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'review_failed', $order->get_meta( '_wcpay_fraud_meta_box_type', true ) );
		$this->assertSame( 'requires_capture', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( 'on-hold', $order->get_status() );
	}

	/**
	 * @testdox A capture refused as below the platform minimum notes the minimum amount after the platform message.
	 *
	 * The merchant removes items from an authorized order until its total is below the minimum and clicks Capture charge.
	 * Client 11.1.0 appends "The minimum amount to capture is %s." to the message it notes
	 * (includes/class-wc-payment-gateway-wcpay.php:3998-4011, :4051). The refusal is HAND-BUILT in the platform's top-level
	 * amount_too_small shape that client 11.1.0 reads (includes/wc-payment-api/class-wc-payments-api-client.php:2845-2851).
	 */
	public function test_capture_refused_below_the_minimum_notes_the_minimum_amount(): void {
		$order                  = $this->create_authorized_order_for_failed_operations();
		$http_client            = new FakeHttpClient();
		$http_client->responses = array(
			$this->json_transport_response(
				400,
				array(
					'code'    => 'amount_too_small',
					'message' => 'Amount must be at least $0.50 usd',
					'data'    => array(
						'status'         => 400,
						'minimum_amount' => 50,
						'currency'       => 'usd',
					),
				)
			),
			$this->still_capturable_intent_response(),
		);

		$this->sut->capture( PaymentOperationContext::for_capture( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), $this->timeout_transport_provider( $http_client ) );

		$notes = self::failure_note_texts( $order );
		$this->assertCount( 1, $notes );
		$this->assertStringEndsWith( 'Amount must be at least $0.50 usd The minimum amount to capture is $0.50 USD.', $notes[0] );
	}

	/**
	 * @testdox Each distinct capture or cancel failure on the same authorization gets its own note, and a repeated one is noted once.
	 *
	 * The merchant clicks Capture charge, which fails, tries again and it fails with another message, tries a third time
	 * with the second message again, then clicks Cancel authorization, which fails too. Client 11.1.0 skips a capture
	 * failure note only when the order has one with the same text (includes/class-wc-payments-order-service.php:496-500)
	 * and adds every cancel failure note (includes/class-wc-payment-gateway-wcpay.php:4104-4127); two identical cancel
	 * failures would get one note here. The refusals are Stripe errors as the platform passes them on
	 * (https://docs.stripe.com/error-codes#lock-timeout).
	 */
	public function test_each_distinct_capture_or_cancel_failure_is_noted(): void {
		$order                  = $this->create_authorized_order_for_failed_operations();
		$http_client            = new FakeHttpClient();
		$lock_timeout           = $this->json_transport_response(
			429,
			array(
				'error' => array(
					'type'    => 'invalid_request_error',
					'code'    => 'lock_timeout',
					'message' => 'This object cannot be accessed right now because another API request or Stripe process is currently accessing it.',
				),
			)
		);
		$http_client->responses = array(
			$this->declined_capture_response( 'Your card was declined.' ),
			$this->still_capturable_intent_response(),
			$lock_timeout,
			$this->still_capturable_intent_response(),
			$lock_timeout,
			$this->still_capturable_intent_response(),
			$lock_timeout,
			$this->still_capturable_intent_response(),
		);
		$provider               = $this->timeout_transport_provider( $http_client );

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$this->sut->capture( PaymentOperationContext::for_capture( wc_get_order( $order->get_id() ), WooPaymentsPersistenceVocabulary::GATEWAY_ID ), $provider );
		}
		$this->sut->cancel( PaymentOperationContext::for_cancel( wc_get_order( $order->get_id() ), WooPaymentsPersistenceVocabulary::GATEWAY_ID ), $provider );

		$this->assertCount( 8, $http_client->requests, 'Every attempt reaches the platform.' );
		$notes  = self::failure_note_texts( $order );
		$listed = implode( PHP_EOL, $notes );
		$this->assertCount( 3, $notes, $listed );
		$this->assertCount( 1, preg_grep( '/^A capture of .* Your card was declined\.$/', $notes ), $listed );
		$this->assertCount( 1, preg_grep( '/^A capture of .* currently accessing it\.$/', $notes ), $listed );
		$this->assertCount( 1, preg_grep( '/^Canceling authorization failed to complete with the following message: Error: This object cannot be accessed/', $notes ), $listed );
	}

	/**
	 * Create an on-hold order whose WooPayments authorization still requires capture.
	 *
	 * @return WC_Order
	 */
	private function create_authorized_order_for_failed_operations(): WC_Order {
		$order = $this->create_woopayments_order( '0.30' );
		$order->set_transaction_id( 'pi_auth' );
		$order->update_meta_data( '_intent_id', 'pi_auth' );
		$order->update_meta_data( '_intention_status', 'requires_capture' );
		$order->save();
		$order->update_status( 'on-hold' );

		return $order;
	}

	/**
	 * Stripe's card decline as the platform passes it on, parsed by client 11.1.0
	 * includes/wc-payment-api/class-wc-payments-api-client.php:2852-2871 (error.code, error.message, error.type).
	 *
	 * @param string $message Decline message.
	 * @return array<string,mixed>
	 */
	private function declined_capture_response( string $message ): array {
		return $this->json_transport_response(
			402,
			array(
				'error' => array(
					'type'         => 'card_error',
					'code'         => 'card_declined',
					'decline_code' => 'generic_decline',
					'message'      => $message,
				),
			)
		);
	}

	/**
	 * The re-fetch after a failed capture or cancel (client 11.1.0 includes/class-wc-payment-gateway-wcpay.php:4014, :4084):
	 * the intent still requires capture; the fields are those of a PaymentIntent (https://docs.stripe.com/api/payment_intents/object).
	 *
	 * @return array<string,mixed>
	 */
	private function still_capturable_intent_response(): array {
		return $this->json_transport_response(
			200,
			array(
				'id'       => 'pi_auth',
				'object'   => 'payment_intent',
				'status'   => 'requires_capture',
				'amount'   => 30,
				'currency' => 'usd',
			)
		);
	}

	/**
	 * Get the order's capture and cancel failure notes, tags stripped, oldest first.
	 *
	 * @param WC_Order $order Order.
	 * @return string[]
	 */
	private static function failure_note_texts( WC_Order $order ): array {
		$texts = array();
		foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
			$text = wp_strip_all_tags( (string) $note->content );
			if ( false !== strpos( $text, 'failed to complete' ) ) {
				$texts[] = $text;
			}
		}

		return array_reverse( $texts );
	}

	/**
	 * @testdox A $operation that releases the authorization of an order already paid leaves the order paid, with one note and one warning naming the event and the amount.
	 *
	 * The late-failure guard keeps skipping the status change on a paid order, as
	 * client 11.1.0 does through order_prepared_for_processing() (includes/class-wc-payments-order-service.php:2747-2754,
	 * capture expiry at :522-525), but the skipped event is now recorded. The cancel comes from the payment details REST
	 * route, and the expired capture from the completed transition, on an order the merchant already marked paid; the
	 * provider answers are PaymentIntents (https://docs.stripe.com/api/payment_intents/object) for the 10.00 authorization.
	 * A second delivery of the same event logs again and adds no second note.
	 *
	 * @dataProvider provide_late_releases_of_paid_orders
	 *
	 * @param string           $operation  Operation: capture or cancel.
	 * @param array<int,mixed> $answers    Platform answers to one operation.
	 * @param string           $event_type Lifecycle event the operation maps to.
	 * @param string           $phrase     What the note says happened to the amount.
	 */
	public function test_late_release_of_a_paid_order_is_recorded_without_changing_the_order( string $operation, array $answers, string $event_type, string $phrase ): void {
		$order = $this->create_woopayments_order( '10.00' );
		$order->set_transaction_id( 'pi_paid_auth' );
		$order->update_meta_data( '_intent_id', 'pi_paid_auth' );
		$order->update_meta_data( '_intention_status', 'requires_capture' );
		$order->save();
		$order->update_status( 'processing' );

		$http_client            = new FakeHttpClient();
		$http_client->responses = array_merge( $answers, $answers );
		$provider               = $this->timeout_transport_provider( $http_client );
		$fake_logger            = $this->create_fake_logger();
		add_filter(
			'woocommerce_logging_class',
			function () use ( $fake_logger ) {
				return $fake_logger;
			}
		);

		foreach ( array( 1, 2 ) as $delivery ) {
			unset( $delivery );
			'capture' === $operation
				? $this->sut->capture( PaymentOperationContext::for_capture( wc_get_order( $order->get_id() ), WooPaymentsPersistenceVocabulary::GATEWAY_ID ), $provider )
				: $this->sut->cancel( PaymentOperationContext::for_cancel( wc_get_order( $order->get_id() ), WooPaymentsPersistenceVocabulary::GATEWAY_ID ), $provider );
		}
		remove_all_filters( 'woocommerce_logging_class' );
		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'processing', $order->get_status(), 'The guard still keeps the paid status.' );
		$notes = array_values(
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
				static fn( $note ): bool => false !== strpos( (string) $note->content, $phrase )
			)
		);
		$this->assertCount( 1, $notes, 'One note records the skipped event, however often it arrives.' );
		$this->assertStringContainsString( '10.00', (string) $notes[0]->content, 'The note names the authorized amount.' );
		$warnings = array_values(
			array_filter(
				$fake_logger->entries,
				static fn( array $entry ): bool => 'warning' === $entry['level'] && 'order-payments' === ( $entry['context']['source'] ?? '' ) && ( $entry['context']['event_type'] ?? '' ) === $event_type
			)
		);
		$this->assertCount( 2, $warnings, 'Each skipped delivery leaves one warning line.' );
		$this->assertSame( '10.00', $warnings[0]['context']['amount'] ?? null );
		$this->assertSame( 'USD', $warnings[0]['context']['currency'] ?? null );
		$this->assertStringContainsString( $event_type, $warnings[0]['message'] );
	}

	/**
	 * Operations that release the authorization of a paid order: a cancel the platform confirms, and a capture refused
	 * because the authorization expired (the re-fetched intent is canceled, client 11.1.0
	 * includes/class-wc-payment-gateway-wcpay.php:4014).
	 *
	 * @return array<string,array{string,array<int,mixed>,string,string}>
	 */
	public function provide_late_releases_of_paid_orders(): array {
		$canceled_intent = $this->json_transport_response(
			200,
			array(
				'id'       => 'pi_paid_auth',
				'object'   => 'payment_intent',
				'status'   => 'canceled',
				'amount'   => 1000,
				'currency' => 'usd',
			)
		);

		return array(
			'cancel'          => array( 'cancel', array( $canceled_intent ), PaymentLifecycleEvent::STATUS_CANCELED, 'was canceled, so that amount was released' ),
			'capture expired' => array(
				'capture',
				array(
					// Stripe's refusal to capture an expired authorization (https://docs.stripe.com/error-codes#charge-expired-for-capture), passed through by the platform.
					$this->json_transport_response(
						400,
						array(
							'error' => array(
								'type'    => 'invalid_request_error',
								'code'    => 'charge_expired_for_capture',
								'message' => 'The charge has expired and can no longer be captured.',
							),
						)
					),
					$canceled_intent,
				),
				PaymentLifecycleEvent::STATUS_CAPTURE_EXPIRED,
				'expired before it was captured, so that amount was never collected',
			),
		);
	}

	/**
	 * @testdox Should apply canceled provider outcomes to the canceled order lifecycle.
	 */
	public function test_cancel_applies_canceled_lifecycle_state(): void {
		$order = $this->create_woopayments_order( '12.00' );
		$order->update_meta_data( '_wcpay_transaction_fee', '0.65' );
		$order->update_meta_data( '_wcpay_net', '11.35' );
		$order->save();
		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_CANCELED,
				'pi_canceled',
				'',
				'',
				'',
				array(
					PaymentOutcome::DATA_META_TO_DELETE => array( '_wcpay_transaction_fee', '_wcpay_net' ),
					PaymentOutcome::DATA_NOTE           => 'Authorization cancellation success note.',
					PaymentOutcome::DATA_NOTE_TYPE      => 'capture_canceled',
				)
			)
		);

		$outcome = $this->sut->cancel( PaymentOperationContext::for_cancel( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), $provider );
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_CANCELED, $outcome->get_status() );
		$this->assertSame( 'cancelled', $order->get_status() );
		$this->assertSame( 'canceled', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( 'pi_canceled', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( '', $order->get_meta( '_wcpay_transaction_fee', true ) );
		$this->assertSame( '', $order->get_meta( '_wcpay_net', true ) );
		$this->assertOrderHasNoteContaining( $order, 'Authorization cancellation success note.' );
	}

	/**
	 * @testdox A German WooPayments extension cancellation note is not duplicated by the built-in cancellation lifecycle.
	 */
	public function test_cancel_deduplicates_german_woopayments_extension_note_through_builtin_effects(): void {
		// The note markup is client 11.1.0 includes/class-wc-payments-order-service.php:2313-2329; the German string is synthetic test input.
		$translation_filter = static function ( string $translation, string $text, string $domain ): string {
			if ( 'woocommerce-payments' !== $domain ) {
				return $translation;
			}

			return 'Payment authorization was successfully <strong>cancelled</strong> (<a>%1$s</a>).' === $text
				? 'Die Zahlungsautorisierung wurde erfolgreich <strong>storniert</strong> (<a>%1$s</a>).'
				: $translation;
		};
		add_filter( 'gettext', $translation_filter, 10, 3 );
		switch_to_locale( 'de_DE' );

		try {
			$order = $this->create_woopayments_order( '12.00' );
			$order->set_status( 'on-hold' );
			$order->update_meta_data( '_charge_id', 'ch_canceled_de' );
			$order->update_meta_data( '_wcpay_transaction_fee', '0.65' );
			$order->update_meta_data( '_wcpay_net', '11.35' );
			$order->save();
			$note_service    = wc_get_container()->get( WooPaymentsOrderNoteService::class );
			$transaction_url = $note_service->transaction_url( 'pi_canceled_de', 'ch_canceled_de' );
			$plugin_note     = sprintf(
				WooPaymentsHtmlUtils::escape_interpolated_html(
					/* translators: %1$s: transaction ID, %2$s: transaction URL. */
					__( 'Payment authorization was successfully <strong>cancelled</strong> (<a>%1$s</a>).', 'woocommerce-payments' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- The fixture emulates the WooPayments extension catalog.
					array(
						'strong' => '<strong>',
						'a'      => '<a href="%2$s" target="_blank" rel="noopener noreferrer">',
					)
				),
				'pi_canceled_de',
				$transaction_url
			);
			$order->add_order_note( $plugin_note );

			$provider_outcome = new PaymentOutcome( PaymentOutcome::STATUS_CANCELED, 'pi_canceled_de' );
			$effect_applier   = wc_get_container()->get( WooPaymentsOrderEffectApplier::class );
			$provider         = new class( $provider_outcome, $effect_applier ) extends RecordingProvider implements ProviderOperationEffectApplierInterface {
				/**
				 * WooPayments effect applier.
				 *
				 * @var WooPaymentsOrderEffectApplier
				 */
				private WooPaymentsOrderEffectApplier $effect_applier;

				/**
				 * Constructor.
				 *
				 * @param PaymentOutcome                $outcome        Provider outcome.
				 * @param WooPaymentsOrderEffectApplier $effect_applier WooPayments effect applier.
				 */
				public function __construct( PaymentOutcome $outcome, WooPaymentsOrderEffectApplier $effect_applier ) {
					parent::__construct( $outcome );
					$this->effect_applier = $effect_applier;
				}

				/**
				 * Apply the real WooPayments cancellation effect plan.
				 *
				 * @param PaymentOperationContext $context   Payment context.
				 * @param PaymentOutcome          $outcome   Provider outcome.
				 * @param string                  $operation Operation name.
				 * @return PaymentOutcome
				 */
				public function apply_operation_effects( PaymentOperationContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
					$this->assert_cancel_operation( $operation );

					return $this->effect_applier->apply(
						$context,
						$outcome,
						WooPaymentsOrderEffectPlan::for_cancel(
							array(
								'id'     => 'pi_canceled_de',
								'status' => 'canceled',
							)
						)
					);
				}

				/**
				 * Guard the fixture against use outside cancellation.
				 *
				 * @param string $operation Operation name.
				 */
				private function assert_cancel_operation( string $operation ): void {
					if ( 'cancel' !== $operation ) {
						throw new RuntimeException( 'Unexpected provider operation in cancellation fixture.' );
					}
				}
			};

			$outcome = $this->sut->cancel( PaymentOperationContext::for_cancel( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), $provider );
			$order   = wc_get_order( $order->get_id() );

			$this->assertInstanceOf( WC_Order::class, $order );
			$matching_notes = array_values(
				array_filter(
					wc_get_order_notes(
						array(
							'order_id' => $order->get_id(),
							'type'     => 'any',
						)
					),
					static fn( object $note ): bool => str_contains( (string) $note->content, 'pi_canceled_de' )
				)
			);

			$this->assertSame( PaymentOutcome::STATUS_CANCELED, $outcome->get_status() );
			$this->assertSame( 'cancelled', $order->get_status() );
			$this->assertSame( 'canceled', $order->get_meta( '_intention_status', true ) );
			$this->assertSame( '', $order->get_meta( '_wcpay_transaction_fee', true ) );
			$this->assertSame( '', $order->get_meta( '_wcpay_net', true ) );
			$this->assertCount( 1, $matching_notes );
			$this->assertSame( $plugin_note, $matching_notes[0]->content );
		} finally {
			remove_filter( 'gettext', $translation_filter, 10 );
			restore_current_locale();
		}
	}

	/**
	 * @testdox A held-for-review checkout on an order carrying the held-for-review note the WooPayments extension wrote adds no second note.
	 *
	 * Client 11.1.0 writes the note in generate_fraud_held_for_review_note() (class-wc-payments-order-service.php:2342-2361)
	 * through generate_fraud_note() (:2408-2441); its link, compose_transaction_url() (class-wc-payments-utils.php:1051-1071),
	 * adds status_is=review and type_is=order_note.
	 */
	public function test_review_intent_recognises_the_woopayments_extension_held_for_review_note(): void {
		$order          = $this->create_woopayments_order( '12.00' );
		$extension_url  = add_query_arg(
			array(
				'page'      => 'wc-admin',
				'path'      => rawurlencode( '/payments/transactions/details' ),
				'id'        => 'pi_review_replay',
				'status_is' => 'review',
				'type_is'   => 'order_note',
			),
			admin_url( 'admin.php' )
		);
		$extension_note = '&#x26D4; A payment of ' . wc_price( 12.00, array( 'currency' => 'USD' ) ) . ' was <strong>held for review</strong> by the following risk filters:<br>&#8226; Place in review if the AVS verification fails<br><br><a href="' . $extension_url . '" target="_blank" rel="noopener noreferrer">View more details</a>.';
		$order->add_order_note( $extension_note );

		$this->sut->process_checkout_outcome(
			PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_review_replay' ),
			$this->review_payment_intent_provider( array( 'avs_verification' => 'review' ) )
		);

		$held_notes = array_values(
			array_filter(
				wc_get_order_notes(
					array(
						'order_id' => $order->get_id(),
						'type'     => 'any',
					)
				),
				static fn( object $note ): bool => str_contains( (string) $note->content, 'held for review' )
			)
		);
		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );
		$this->assertCount( 1, $held_notes );
		$this->assertSame( $extension_note, $held_notes[0]->content );
	}

	/**
	 * @testdox Replayed held-for-review intents repair rule evidence without duplicating equivalent notes.
	 */
	public function test_review_intent_replay_repairs_rule_evidence_and_deduplicates_content_sensitive_notes(): void {
		$translation_filter = static function ( string $translation, string $text, string $domain ): string {
			if ( 'woocommerce-payments' !== $domain ) {
				return $translation;
			}

			return '&#x26D4; A payment of %1$s was <strong>held for review</strong> by the following risk filters:<br>%2$s<br><br><a>View more details</a>.' === $text
				? '&#x26D4; Eine Zahlung von %1$s wurde von den folgenden Risikofiltern <strong>zur Überprüfung zurückgehalten</strong>:<br>%2$s<br><br><a>Weitere Details anzeigen</a>.'
				: $translation;
		};
		add_filter( 'gettext', $translation_filter, 10, 3 );
		switch_to_locale( 'de_DE' );

		try {
			$order          = $this->create_woopayments_order( '12.00' );
			$note_service   = wc_get_container()->get( WooPaymentsOrderNoteService::class );
			$initial_rules  = array( 'avs_verification' => 'review' );
			$plugin_note    = $note_service->format_fraud_held_for_review_note_candidates( $order, 'pi_review_replay', 'ch_review_replay', $initial_rules )[1];
			$initial_result = '{"avs_verification":"review"}';
			$order->add_order_note( $plugin_note );

			$this->sut->process_checkout_outcome(
				PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_review_replay' ),
				$this->review_payment_intent_provider( $initial_rules )
			);
			$order = wc_get_order( $order->get_id() );

			$this->assertInstanceOf( WC_Order::class, $order );
			$this->assertSame( 'on-hold', $order->get_status() );
			$this->assertSame( $initial_result, $order->get_meta( '_wcpay_fraud_ruleset_results', true ) );
			$this->assertCount(
				1,
				array_filter(
					wc_get_order_notes(
						array(
							'order_id' => $order->get_id(),
							'type'     => 'any',
						)
					),
					static fn( object $note ): bool => str_contains( (string) $note->content, 'pi_review_replay' )
				),
				'The seeded WooPayments-catalog rendering must satisfy the equivalent note identity.'
			);

			$this->sut->process_checkout_outcome(
				PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_review_replay' ),
				$this->review_payment_intent_provider( $initial_rules )
			);
			$order = wc_get_order( $order->get_id() );

			$this->assertInstanceOf( WC_Order::class, $order );
			$this->assertSame( $initial_result, $order->get_meta( '_wcpay_fraud_ruleset_results', true ) );
			$this->assertCount(
				1,
				array_filter(
					wc_get_order_notes(
						array(
							'order_id' => $order->get_id(),
							'type'     => 'any',
						)
					),
					static fn( object $note ): bool => str_contains( (string) $note->content, 'pi_review_replay' )
				),
				'An identical replay must not add another held-for-review note.'
			);

			$order->update_meta_data( '_wcpay_fraud_ruleset_results', 'stale evidence' );
			$order->save();
			$changed_rules  = array(
				'avs_verification' => 'review',
				'address_mismatch' => 'block',
			);
			$changed_result = '{"avs_verification":"review","address_mismatch":"block"}';
			$this->sut->process_checkout_outcome(
				PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_review_replay' ),
				$this->review_payment_intent_provider( $changed_rules )
			);
			$order = wc_get_order( $order->get_id() );

			$this->assertInstanceOf( WC_Order::class, $order );
			$this->assertSame( $changed_result, $order->get_meta( '_wcpay_fraud_ruleset_results', true ) );
			$this->assertCount(
				2,
				array_filter(
					wc_get_order_notes(
						array(
							'order_id' => $order->get_id(),
							'type'     => 'any',
						)
					),
					static fn( object $note ): bool => str_contains( (string) $note->content, 'pi_review_replay' )
				),
				'Changed filter evidence must produce a distinct content-sensitive held-for-review note.'
			);
		} finally {
			remove_filter( 'gettext', $translation_filter, 10 );
			restore_current_locale();
		}
	}

	/**
	 * @testdox Should call setup-capable providers for zero-total checkout with a payment method ID "$payment_method_id" or a saved token "$saved_token".
	 * @testWith ["pm_zero", ""]
	 *           ["", "123"]
	 *
	 * @param string $payment_method_id Payment method ID the checkout carries, or empty.
	 * @param string $saved_token       Saved payment token ID the checkout carries, or empty.
	 */
	public function test_process_checkout_calls_setup_capable_provider_for_zero_total_checkout_with_credential( string $payment_method_id, string $saved_token ): void {
		$order    = $this->create_woopayments_order( '0.00' );
		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_COMPLETED,
				'seti_zero',
				'',
				'pm_zero',
				'cus_zero',
				array(
					'meta' => array(
						'_intention_status' => 'succeeded',
					),
				)
			),
			true
		);

		$outcome = $this->sut->process_checkout_outcome(
			PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, $payment_method_id, array( 'payment_token' => $saved_token ) ),
			$provider
		);
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'seti_zero', $outcome->get_provider_payment_id() );
		$this->assertSame( 1, $provider->charge_calls );
		$this->assertSame( 'seti_zero', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'pm_zero', $order->get_meta( '_payment_method_id', true ) );
	}

	/**
	 * @testdox A zero-total recurring SetupIntent exposes actual card identity before lifecycle for new and saved cards.
	 * @dataProvider zero_total_recurring_card_context_data
	 *
	 * @param bool $use_saved_token Whether the checkout uses an existing saved token.
	 */
	public function test_zero_total_recurring_setup_intent_exposes_card_identity_to_synchronous_lifecycle_observers( bool $use_saved_token ): void {
		$user_id      = self::factory()->user->create();
		$order        = $this->create_woopayments_order( '0.00' );
		$subscription = $this->create_woopayments_order( '0.00' );
		$order->set_customer_id( $user_id );
		$order->set_payment_method_title( 'Card' );
		$order->save();
		$subscription->set_customer_id( $user_id );
		$subscription->set_payment_method_title( 'Card' );
		$subscription->save();
		$details       = array(
			'id'   => 'pm_free_trial',
			'type' => 'card',
			'card' => array(
				'brand'     => 'visa',
				'network'   => 'visa',
				'funding'   => 'credit',
				'last4'     => '4242',
				'exp_month' => 12,
				'exp_year'  => 2030,
			),
		);
		$details_reads = new \ArrayObject( array( 0 ) );
		$token         = null;
		if ( $use_saved_token ) {
			$token = new WC_Payment_Token_CC();
			$token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
			$token->set_user_id( $user_id );
			$token->set_token( 'pm_free_trial' );
			$token->set_card_type( 'visa' );
			$token->set_last4( '4242' );
			$token->set_expiry_month( '12' );
			$token->set_expiry_year( '2030' );
			$token->save();
			$order->add_payment_token( $token );
			$order->save();
		}
		$provider = $this->completed_setup_intent_provider( $details, $details_reads );
		$context  = PaymentOperationContext::for_checkout(
			$order,
			WooPaymentsPersistenceVocabulary::GATEWAY_ID,
			$use_saved_token ? '' : 'pm_free_trial',
			$use_saved_token ? array( 'payment_token' => (string) $token->get_id() ) : array(),
			array( 'recurring_payment' => true )
		);
		$observed = array();
		WooCommerceSubscriptionsDoubles::load_order_subscriptions();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ] = array( $order->get_id() => array( 'renewal' => array( $subscription->get_id() ) ) );
		$capture_observer_state    = static function ( int $order_id, string $hook ) use ( $order, $subscription, &$observed ): void {
			if ( $order->get_id() !== $order_id ) {
				return;
			}

			$reloaded_order        = wc_get_order( $order_id );
			$reloaded_subscription = wc_get_order( $subscription->get_id() );
			if ( ! $reloaded_order instanceof WC_Order || ! $reloaded_subscription instanceof WC_Order ) {
				return;
			}

			$order_token_ids        = $reloaded_order->get_payment_tokens();
			$subscription_token_ids = $reloaded_subscription->get_payment_tokens();
			$order_active_token_id  = end( $order_token_ids );
			$order_active_token     = false === $order_active_token_id ? null : \WC_Payment_Tokens::get( (int) $order_active_token_id );
			$observed[]             = array(
				'hook'                        => $hook,
				'order_gateway'               => $reloaded_order->get_payment_method(),
				'order_title'                 => $reloaded_order->get_payment_method_title(),
				'order_last4'                 => $reloaded_order->get_meta( 'last4', true ),
				'order_card_brand'            => $reloaded_order->get_meta( '_card_brand', true ),
				'order_details'               => json_decode( (string) $reloaded_order->get_meta( '_wcpay_payment_method_details', true ), true ),
				'order_raw_details'           => $reloaded_order->get_meta( '_wcpay_raw_payment_method_details', true ),
				'order_payment_method'        => $reloaded_order->get_meta( '_payment_method_id', true ),
				'order_customer'              => $reloaded_order->get_meta( '_stripe_customer_id', true ),
				'order_token_ids'             => $order_token_ids,
				'active_token_id'             => $order_active_token instanceof \WC_Payment_Token ? $order_active_token->get_id() : 0,
				'active_token_provider'       => $order_active_token instanceof \WC_Payment_Token ? $order_active_token->get_token() : '',
				'subscription_gateway'        => $reloaded_subscription->get_payment_method(),
				'subscription_title'          => $reloaded_subscription->get_payment_method_title(),
				'subscription_tokens'         => $subscription_token_ids,
				'subscription_payment_method' => $reloaded_subscription->get_meta( '_payment_method_id', true ),
				'subscription_customer'       => $reloaded_subscription->get_meta( '_stripe_customer_id', true ),
				'subscription_last4'          => $reloaded_subscription->get_meta( 'last4', true ),
				'subscription_card_brand'     => $reloaded_subscription->get_meta( '_card_brand', true ),
				'subscription_details'        => $reloaded_subscription->get_meta( '_wcpay_payment_method_details', true ),
				'subscription_raw_details'    => $reloaded_subscription->get_meta( '_wcpay_raw_payment_method_details', true ),
			);
		};
		$status_observer           = static function ( int $order_id ) use ( $capture_observer_state ): void {
			$capture_observer_state( $order_id, 'status' );
		};
		$payment_complete_observer = static function ( int $order_id ) use ( $capture_observer_state ): void {
			$capture_observer_state( $order_id, 'payment_complete' );
		};
		add_action( 'woocommerce_order_status_completed', $status_observer, 1 );
		add_action( 'woocommerce_payment_complete', $payment_complete_observer, 1 );
		// The shopper who picks their saved card is logged in.
		wp_set_current_user( $user_id );

		try {
			$this->sut->process_checkout_outcome( $context, $provider );
		} finally {
			wp_set_current_user( 0 );
			remove_action( 'woocommerce_order_status_completed', $status_observer, 1 );
			remove_action( 'woocommerce_payment_complete', $payment_complete_observer, 1 );
			unset( $GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ] );
		}

		$order        = wc_get_order( $order->get_id() );
		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertInstanceOf( WC_Order::class, $subscription );
		$expected_token_ids = $order->get_payment_tokens();
		$expected_token_id  = end( $expected_token_ids );
		$this->assertSame(
			array(
				array(
					'hook'                        => 'status',
					'order_gateway'               => WooPaymentsPersistenceVocabulary::GATEWAY_ID,
					'order_title'                 => 'Visa credit card',
					'order_last4'                 => '4242',
					'order_card_brand'            => 'visa',
					'order_details'               => array(
						'type' => 'card',
						'card' => $details['card'],
					),
					'order_raw_details'           => '',
					'order_payment_method'        => 'pm_free_trial',
					'order_customer'              => 'cus_free_trial',
					'order_token_ids'             => $expected_token_ids,
					'active_token_id'             => $expected_token_id,
					'active_token_provider'       => 'pm_free_trial',
					'subscription_gateway'        => WooPaymentsPersistenceVocabulary::GATEWAY_ID,
					'subscription_title'          => 'Visa credit card',
					'subscription_tokens'         => $expected_token_ids,
					'subscription_payment_method' => 'pm_free_trial',
					'subscription_customer'       => 'cus_free_trial',
					'subscription_last4'          => '',
					'subscription_card_brand'     => '',
					'subscription_details'        => '',
					'subscription_raw_details'    => '',
				),
				array(
					'hook'                        => 'payment_complete',
					'order_gateway'               => WooPaymentsPersistenceVocabulary::GATEWAY_ID,
					'order_title'                 => 'Visa credit card',
					'order_last4'                 => '4242',
					'order_card_brand'            => 'visa',
					'order_details'               => array(
						'type' => 'card',
						'card' => $details['card'],
					),
					'order_raw_details'           => '',
					'order_payment_method'        => 'pm_free_trial',
					'order_customer'              => 'cus_free_trial',
					'order_token_ids'             => $expected_token_ids,
					'active_token_id'             => $expected_token_id,
					'active_token_provider'       => 'pm_free_trial',
					'subscription_gateway'        => WooPaymentsPersistenceVocabulary::GATEWAY_ID,
					'subscription_title'          => 'Visa credit card',
					'subscription_tokens'         => $expected_token_ids,
					'subscription_payment_method' => 'pm_free_trial',
					'subscription_customer'       => 'cus_free_trial',
					'subscription_last4'          => '',
					'subscription_card_brand'     => '',
					'subscription_details'        => '',
					'subscription_raw_details'    => '',
				),
			),
			$observed
		);
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $order->get_payment_method() );
		$this->assertSame( 'pm_free_trial', $order->get_meta( '_payment_method_id', true ) );
		$this->assertSame( 'cus_free_trial', $order->get_meta( '_stripe_customer_id', true ) );
		// Client 11.1.0 creates no intent for a $0 order paid with a saved card (gw:1688).
		$this->assertSame( $use_saved_token ? '' : 'seti_free_trial', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( $use_saved_token ? '' : 'succeeded', $order->get_meta( '_intention_status', true ), 'No intent, no intention status.' );
		$this->assertSame( 'Visa credit card', $subscription->get_payment_method_title() );
		$this->assertSame( 'pm_free_trial', $subscription->get_meta( '_payment_method_id', true ) );
		$this->assertSame( 'cus_free_trial', $subscription->get_meta( '_stripe_customer_id', true ) );
		$this->assertSame( '', $subscription->get_meta( 'last4', true ) );
		$this->assertSame( '', $subscription->get_meta( '_card_brand', true ) );
		$this->assertSame( '', $subscription->get_meta( '_wcpay_payment_method_details', true ) );
		$this->assertSame( 1, $details_reads[0] );
		$this->assertCount( 1, $order->get_payment_tokens() );
		$this->assertCount( 1, $subscription->get_payment_tokens() );
		$this->assertStringContainsString( '"type":"card"', (string) $order->get_meta( '_wcpay_payment_method_details', true ) );
		// Without an intent the client pushes the order's billing details to the saved method itself (gw:1708-1716).
		$update_jobs = as_get_scheduled_actions(
			array(
				'hook'   => 'wcpay_update_saved_payment_method',
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);
		$this->assertCount( $use_saved_token ? 1 : 0, $update_jobs );
	}

	/**
	 * Provide new-card and existing-saved-card zero-total SetupIntent contexts.
	 *
	 * @return array<string,array{bool}>
	 */
	public function zero_total_recurring_card_context_data(): array {
		return array(
			'new card'            => array( false ),
			'existing saved card' => array( true ),
		);
	}

	/**
	 * Build a successful provider refund outcome that links the WC refund via `_wcpay_refund_id`.
	 *
	 * This mirrors the live WooPayments synchronous and webhook paths, both of which stamp the
	 * processed `WC_Order_Refund` with `_wcpay_refund_id`.
	 *
	 * @param string $provider_refund_id Provider refund ID to link.
	 * @return PaymentOutcome
	 */
	private function successful_refund_outcome( string $provider_refund_id ): PaymentOutcome {
		return new PaymentOutcome(
			PaymentOutcome::STATUS_COMPLETED,
			$provider_refund_id,
			'',
			'',
			'',
			array(
				'order_meta'  => array( '_wcpay_refund_status' => 'successful' ),
				'refund_meta' => array( '_wcpay_refund_id' => $provider_refund_id ),
			)
		);
	}

	/**
	 * Build a checkout context for a completed PaymentIntent lifecycle test.
	 *
	 * Scheduled contexts use the same saved-token marker and order-attached token routing as subscription renewals.
	 *
	 * @param WC_Order $order                        Payment order.
	 * @param bool     $scheduled_subscription_payment Whether this is a scheduled subscription renewal.
	 * @return PaymentOperationContext
	 */
	private function completed_payment_intent_context( WC_Order $order, bool $scheduled_subscription_payment ): PaymentOperationContext {
		if ( ! $scheduled_subscription_payment ) {
			return PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_lifecycle_title' );
		}

		$user_id = self::factory()->user->create();
		$token   = new WC_Payment_Token_CC();
		$token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$token->set_user_id( $user_id );
		$token->set_token( 'pm_scheduled_renewal' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->save();
		$order->set_customer_id( $user_id );
		$order->add_payment_token( $token );
		$order->save();

		return PaymentOperationContext::for_checkout(
			$order,
			WooPaymentsPersistenceVocabulary::GATEWAY_ID,
			'pm_lifecycle_title',
			array(
				'payment_token'       => (string) $token->get_id(),
				'save_payment_method' => false,
			),
			array(
				'scheduled_subscription_payment'    => true,
				'saved_payment_method_display_name' => $token->get_display_name(),
			)
		);
	}

	/**
	 * Build a real WooPayments SetupIntent provider with a counted payment-method detail seam.
	 *
	 * @param array<string,mixed>   $payment_method_details Canonical provider payment-method details.
	 * @param \ArrayObject<int,int> $details_reads Payment-method detail read counter.
	 * @return WooPaymentsProvider
	 */
	private function completed_setup_intent_provider( array $payment_method_details, $details_reads ): WooPaymentsProvider {
		$details_service = new class( $payment_method_details, $details_reads ) extends WooPaymentsPaymentMethodDetailsService {
			/** @var array<string,mixed> */
			private array $payment_method_details;

			/** @var \ArrayObject<int,int> */
			private \ArrayObject $details_reads;

			/**
			 * @param array<string,mixed>   $payment_method_details Canonical provider payment-method details.
			 * @param \ArrayObject<int,int> $details_reads Payment-method detail read counter.
			 */
			public function __construct( array $payment_method_details, $details_reads ) {
				$this->payment_method_details = $payment_method_details;
				$this->details_reads          = $details_reads;
			}

			/**
			 * @param string $payment_method_id Provider payment-method ID.
			 * @return array<string,mixed>
			 */
			public function get_payment_method_details( string $payment_method_id ): array {
				++$this->details_reads[0];

				return 'pm_free_trial' === $payment_method_id ? $this->payment_method_details : array();
			}
		};
		$token_service   = new WooPaymentsTokenService();
		$token_service->init( $details_service, wc_get_container()->get( Client::class ), wc_get_container()->get( WooPaymentsCustomerService::class ), wc_get_container()->get( WooPaymentsAccountService::class ), wc_get_container()->get( WooPaymentsOrderDataService::class ), wc_get_container()->get( PaymentMethodRegistry::class ) );
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_account_country', 'get_mode' ) )
			->getMock();
		$account_service->init( wc_get_container()->get( LegacyProxy::class ), wc_get_container()->get( WooPaymentsDatabaseCache::class ) );
		$account_service->method( 'get_account_country' )->willReturn( 'US' );
		$account_service->method( 'get_mode' )->willReturn( 'test' );
		$effect_applier = new WooPaymentsOrderEffectApplier();
		$effect_applier->init(
			$token_service,
			new WooPaymentsOrderDataService(),
			$account_service,
			wc_get_container()->get( WooPaymentsOrderNoteService::class ),
			new PaymentMethodRegistry(),
			wc_get_container()->get( WooPaymentsActionSchedulerService::class )
		);
		$api_client       = new class() extends \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client {
			/**
			 * Tell the adapter its API transport is available for the SetupIntent.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Return the isolated successful SetupIntent transport response.
			 *
			 * @param array<string,mixed> $request_data SetupIntent request data.
			 * @param string              $idempotency_key Request idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_setup_intention( array $request_data, string $idempotency_key ): array {
				unset( $request_data, $idempotency_key );

				return array(
					'id'             => 'seti_free_trial',
					'status'         => 'succeeded',
					'customer'       => 'cus_free_trial',
					'payment_method' => 'pm_free_trial',
				);
			}
		};
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_free_trial' );
		$ambiguity_service = new \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsChargeAmbiguityService();
		$ambiguity_service->init( $api_client, wc_get_container()->get( WooPaymentsLogger::class ) );
		$refund_ambiguity_service = new \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRefundAmbiguityService();
		$refund_ambiguity_service->init( $api_client, wc_get_container()->get( WooPaymentsLogger::class ) );
		$payment_operations = new WooPaymentsPaymentOperationService();
		$payment_operations->init(
			$api_client,
			$customer_service,
			wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentRequestBuilder::class ),
			$account_service,
			wc_get_container()->get( WooPaymentsOrderNoteService::class ),
			wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudRulesetService::class ),
			$ambiguity_service,
			$refund_ambiguity_service,
			wc_get_container()->get( WooPaymentsLogger::class ),
			wc_get_container()->get( WooPaymentsLevel3Service::class )
		);
		$provider = new WooPaymentsProvider();
		$provider->init(
			$payment_operations,
			$api_client,
			$account_service,
			new PaymentMethodRegistry(),
			$effect_applier
		);

		return $provider;
	}

	/**
	 * Build a real WooPayments provider that returns a held-for-review PaymentIntent response.
	 *
	 * The adapter isolates only its remote transport; the production provider, effect applier, and payment lifecycle remain in use.
	 * The intent carries the fraud outcome and the JSON-encoded fired filters in its metadata, where client 11.1.0 reads them
	 * (class-wc-payments-order-service.php:2957-2959). Its other fields, id, status, currency and the charge's id, currency
	 * and payment_method_details, are ones the client's deserializers read too (wc-payment-api/class-wc-payments-api-client.php:
	 * 2392-2435, :3046-3076). Native adds none; the fields those deserializers require and native does not read (created,
	 * amount, client_secret, charges.total_count) are left out.
	 *
	 * @param array<string,string> $ruleset_results Fired fraud-rule results.
	 * @return WooPaymentsProvider
	 */
	private function review_payment_intent_provider( array $ruleset_results ): WooPaymentsProvider {
		$payment_operations = new class( $ruleset_results ) extends WooPaymentsPaymentOperationService {
			/**
			 * Fired fraud-rule results returned by the isolated transport.
			 *
			 * @var array<string,string>
			 */
			private array $ruleset_results;

			/**
			 * Constructor.
			 *
			 * @param array<string,string> $ruleset_results Fired fraud-rule results.
			 */
			public function __construct( array $ruleset_results ) {
				$this->ruleset_results = $ruleset_results;
			}

			/**
			 * Return a review PaymentIntent without making a remote transport request.
			 *
			 * @param PaymentOperationContext $context         Payment context.
			 * @param string                  $idempotency_key Charge idempotency key.
			 * @return PaymentOutcome
			 */
			public function charge( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
				unset( $context, $idempotency_key );

				$result = array(
					'id'       => 'pi_review_replay',
					'status'   => 'requires_capture',
					'currency' => 'usd',
					'metadata' => array(
						'fraud_outcome'         => 'review',
						'fraud_ruleset_results' => wp_json_encode( $this->ruleset_results ),
					),
					'charges'  => array(
						'data' => array(
							array(
								'id'                     => 'ch_review_replay',
								'currency'               => 'usd',
								'payment_method_details' => array( 'type' => 'card' ),
							),
						),
					),
				);

				return ( new PaymentOutcome( PaymentOutcome::STATUS_AUTHORIZED, 'pi_review_replay', '', 'pm_review_replay' ) )->with_effect_plan( WooPaymentsOrderEffectPlan::for_payment_intent( $result, false ) );
			}
		};
		$provider           = new WooPaymentsProvider();
		$provider->init(
			$payment_operations,
			wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client::class ),
			wc_get_container()->get( WooPaymentsAccountService::class ),
			new PaymentMethodRegistry(),
			wc_get_container()->get( WooPaymentsOrderEffectApplier::class )
		);

		return $provider;
	}

	/**
	 * Build a real WooPayments provider whose transport answers the first charge with a 502, so its outcome is unknown.
	 *
	 * Later charges succeed. Every idempotency key sent is recorded.
	 *
	 * @param \ArrayObject $sent_keys Idempotency keys sent to the platform, in order.
	 * @return WooPaymentsProvider
	 */
	private function ambiguous_first_charge_provider( \ArrayObject $sent_keys ): WooPaymentsProvider {
		$api_client      = new class( $sent_keys ) extends Client {
			/**
			 * Idempotency keys sent.
			 *
			 * @var \ArrayObject<int,string>
			 */
			private \ArrayObject $sent_keys;

			/**
			 * Constructor.
			 *
			 * @param \ArrayObject $sent_keys Idempotency keys sent.
			 */
			public function __construct( \ArrayObject $sent_keys ) {
				$this->sent_keys = $sent_keys;
			}

			/**
			 * Report the API transport as available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Answer the first charge with a 502 and later ones with a succeeded intent.
			 *
			 * @param array<string,mixed> $request_data    Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 * @throws ApiException On the first charge.
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				unset( $request_data );
				$this->sent_keys[] = $idempotency_key;
				if ( 1 === count( $this->sent_keys ) ) {
					throw new ApiException( 'Error: Upstream provider unavailable.', 'api_connection_error', 502, 'api_error' );
				}

				return array(
					'id'     => 'pi_replayed',
					'status' => 'succeeded',
				);
			}
		};
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_account_country', 'get_mode' ) )
			->getMock();
		$account_service->method( 'get_account_country' )->willReturn( 'US' );
		$account_service->method( 'get_mode' )->willReturn( 'test' );
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_ambiguous' );
		$ambiguity_service = new \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsChargeAmbiguityService();
		$ambiguity_service->init( $api_client, wc_get_container()->get( WooPaymentsLogger::class ) );
		$refund_ambiguity_service = new \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRefundAmbiguityService();
		$refund_ambiguity_service->init( $api_client, wc_get_container()->get( WooPaymentsLogger::class ) );
		$payment_operations = new WooPaymentsPaymentOperationService();
		$payment_operations->init(
			$api_client,
			$customer_service,
			wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentRequestBuilder::class ),
			$account_service,
			wc_get_container()->get( WooPaymentsOrderNoteService::class ),
			wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudRulesetService::class ),
			$ambiguity_service,
			$refund_ambiguity_service,
			wc_get_container()->get( WooPaymentsLogger::class ),
			wc_get_container()->get( WooPaymentsLevel3Service::class )
		);
		$provider = new WooPaymentsProvider();
		$provider->init(
			$payment_operations,
			$api_client,
			$account_service,
			new PaymentMethodRegistry(),
			wc_get_container()->get( WooPaymentsOrderEffectApplier::class )
		);

		return $provider;
	}

	/**
	 * Build a real WooPayments provider on the real API client, with only the platform's HTTP answers faked.
	 *
	 * @param FakeHttpClient $http_client       Platform answers, in order.
	 * @param bool           $real_customers    Whether the real customer service resolves customers over the same transport.
	 * @return WooPaymentsProvider
	 */
	private function timeout_transport_provider( FakeHttpClient $http_client, bool $real_customers = false ): WooPaymentsProvider {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_account_country', 'get_mode', 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->method( 'get_account_country' )->willReturn( 'US' );
		$account_service->method( 'get_mode' )->willReturn( 'test' );
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );
		$api_client = new class() extends Client {
			/**
			 * Skip the backoff between transport retries.
			 *
			 * @param int $backoff_microseconds Backoff.
			 */
			protected function sleep_before_retry( int $backoff_microseconds ): void {
				unset( $backoff_microseconds );
			}
		};
		$api_client->init( $http_client, $account_service, wc_get_container()->get( TransportLog::class ) );
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order', 'get_or_create_customer_id_for_subscription_payment_method_change' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_timeout' );
		$customer_service->method( 'get_or_create_customer_id_for_subscription_payment_method_change' )->willReturn( 'cus_timeout' );
		if ( $real_customers ) {
			$customer_service = new WooPaymentsCustomerService();
			$customer_service->init( $api_client, $account_service, new \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSessionService() );
			$this->customers_over_transport = $customer_service;
		}
		$ambiguity_service = new \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsChargeAmbiguityService();
		$ambiguity_service->init( $api_client, wc_get_container()->get( WooPaymentsLogger::class ) );
		$refund_ambiguity_service = new \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRefundAmbiguityService();
		$refund_ambiguity_service->init( $api_client, wc_get_container()->get( WooPaymentsLogger::class ) );
		$payment_operations = new WooPaymentsPaymentOperationService();
		$payment_operations->init(
			$api_client,
			$customer_service,
			wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentRequestBuilder::class ),
			$account_service,
			wc_get_container()->get( WooPaymentsOrderNoteService::class ),
			wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudRulesetService::class ),
			$ambiguity_service,
			$refund_ambiguity_service,
			wc_get_container()->get( WooPaymentsLogger::class ),
			wc_get_container()->get( WooPaymentsLevel3Service::class )
		);
		$provider = new WooPaymentsProvider();
		$provider->init(
			$payment_operations,
			$api_client,
			$account_service,
			new PaymentMethodRegistry(),
			wc_get_container()->get( WooPaymentsOrderEffectApplier::class )
		);

		return $provider;
	}

	/**
	 * Build a JSON transport response.
	 *
	 * @param int                 $code HTTP status.
	 * @param array<string,mixed> $body Decoded body.
	 * @return array<string,mixed>
	 */
	private function json_transport_response( int $code, array $body ): array {
		return array(
			'response' => array( 'code' => $code ),
			'headers'  => array( 'content-type' => 'application/json; charset=UTF-8' ),
			'body'     => (string) wp_json_encode( $body ),
		);
	}

	/**
	 * Build a real WooPayments provider with only the remote charge transport isolated.
	 *
	 * @param array<string,mixed> $result   Expanded completed PaymentIntent response.
	 * @param \ArrayObject        $sequence Lifecycle sequence recorder.
	 * @return WooPaymentsProvider
	 */
	private function completed_payment_intent_provider( array $result, \ArrayObject $sequence ): WooPaymentsProvider {
		$payment_operations = new class( $result, $sequence ) extends WooPaymentsPaymentOperationService {
			/**
			 * Expanded PaymentIntent transport result.
			 *
			 * @var array<string,mixed>
			 */
			private array $result;

			/**
			 * Lifecycle sequence recorder.
			 *
			 * @var \ArrayObject<int,string>
			 */
			private \ArrayObject $sequence;

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $result   Expanded completed PaymentIntent response.
			 * @param \ArrayObject        $sequence Lifecycle sequence recorder.
			 */
			public function __construct( array $result, \ArrayObject $sequence ) {
				$this->result   = $result;
				$this->sequence = $sequence;
			}

			/**
			 * Return a completed PaymentIntent without making a remote transport request.
			 *
			 * @param PaymentOperationContext $context         Payment context.
			 * @param string                  $idempotency_key Charge idempotency key.
			 * @return PaymentOutcome
			 */
			public function charge( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
				$is_recurring = true === ( $context->get_provider_data()['scheduled_subscription_payment'] ?? false );
				if ( $is_recurring && ! \WC_Payment_Tokens::get( (int) ( $context->get_payment_data()['payment_token'] ?? 0 ) ) instanceof WC_Payment_Token_CC ) {
					throw new RuntimeException( 'Scheduled renewal fixture requires its persisted saved card.' );
				}

				$context->get_order()->update_meta_data( self::CHARGE_IDEMPOTENCY_KEY_META, $idempotency_key );
				$context->get_order()->save_meta_data();
				$this->sequence[] = $is_recurring ? 'transport:scheduled' : 'transport:direct';

				$status = 'requires_capture' === $this->result['status'] ? PaymentOutcome::STATUS_AUTHORIZED : PaymentOutcome::STATUS_COMPLETED;

				return ( new PaymentOutcome( $status, 'pi_lifecycle_title', '', 'pm_lifecycle_title' ) )->with_effect_plan( WooPaymentsOrderEffectPlan::for_payment_intent( $this->result, $is_recurring ) );
			}

			/**
			 * Finalize the stored charge idempotency key after the payment lifecycle.
			 *
			 * @param WC_Order       $order   Payment order.
			 * @param PaymentOutcome $outcome Completed charge outcome.
			 */
			public function finalize_charge_idempotency_key( WC_Order $order, PaymentOutcome $outcome ): void {
				parent::finalize_charge_idempotency_key( $order, $outcome );
				$this->sequence[] = 'finalization';
			}
		};
		$provider           = new WooPaymentsProvider();
		$provider->init(
			$payment_operations,
			wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client::class ),
			wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService::class ),
			new PaymentMethodRegistry(),
			wc_get_container()->get( WooPaymentsOrderEffectApplier::class )
		);

		return $provider;
	}

	/**
	 * Build the expanded completed card PaymentIntent returned by the provider for card-title lifecycle tests.
	 *
	 * @return array<string,mixed>
	 */
	private function completed_card_payment_intent_result(): array {
		return array(
			'id'       => 'pi_lifecycle_title',
			'status'   => 'succeeded',
			'currency' => 'usd',
			'charges'  => array(
				'data' => array(
					array(
						'id'                     => 'ch_lifecycle_title',
						'currency'               => 'usd',
						'amount'                 => 1000,
						'application_fee_amount' => 35,
						'balance_transaction'    => array( 'id' => 'txn_lifecycle_title' ),
						'payment_method_details' => array(
							'type' => 'card',
							'card' => array(
								'brand'         => 'visa',
								'display_brand' => 'visa',
								'last4'         => '4242',
								'funding'       => 'credit',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Build the expanded completed Link PaymentIntent returned by the provider for title-suffix lifecycle tests.
	 *
	 * @return array<string,mixed>
	 *
	 * A Link PaymentIntent with its latest charge expanded, reduced to the fields the effect plan reads. The envelope
	 * (charges.data, application_fee_amount, the expanded balance_transaction) follows the recorded platform intention
	 * response in Providers/WooPayments/Fixtures/platform-intention-basic-card.json (entries[0].response.body); the Link wallet type
	 * follows client 11.1.0 class-wc-payment-gateway-wcpay.php:2700-2745. Other fields are omitted on purpose.
	 */
	private function completed_link_payment_intent_result(): array {
		return array(
			'id'       => 'pi_lifecycle_title',
			'status'   => 'succeeded',
			'currency' => 'usd',
			'charges'  => array(
				'data' => array(
					array(
						'id'                     => 'ch_lifecycle_title',
						'currency'               => 'usd',
						'amount'                 => 1000,
						'application_fee_amount' => 35,
						'balance_transaction'    => array( 'id' => 'txn_lifecycle_title' ),
						'payment_method_details' => array(
							'type' => 'card',
							'card' => array( 'wallet' => array( 'type' => 'link' ) ),
						),
					),
				),
			),
		);
	}

	/**
	 * Save a WooPayments card for a user.
	 *
	 * @param int    $user_id           Owner.
	 * @param string $payment_method_id Stripe payment method ID the token holds.
	 * @return WC_Payment_Token_CC
	 */
	private function create_saved_card( int $user_id, string $payment_method_id ): WC_Payment_Token_CC {
		$token = new WC_Payment_Token_CC();
		$token->set_token( $payment_method_id );
		$token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2040' );
		$token->set_user_id( $user_id );
		$token->save();

		return $token;
	}

	/**
	 * Create a WooPayments order for processing tests.
	 *
	 * @param string $total Order total.
	 * @return WC_Order
	 */
	private function create_woopayments_order( string $total ): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_currency( 'USD' );
		$order->set_total( $total );
		$order->save();

		return $order;
	}

	/**
	 * Create a local refund row without refunding through a gateway.
	 *
	 * @param WC_Order $order  Parent order.
	 * @param float    $amount Refund amount.
	 * @param string   $reason Refund reason.
	 * @return WC_Order_Refund
	 */
	private function create_local_refund( WC_Order $order, float $amount, string $reason ): WC_Order_Refund {
		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => $amount,
				'reason'         => $reason,
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );

		return $refund;
	}

	/**
	 * Date a refund one day back, as a merchant's earlier manual refund would be.
	 *
	 * @param WC_Order_Refund $refund Refund row.
	 * @return WC_Order_Refund
	 */
	private function backdate_refund( WC_Order_Refund $refund ): WC_Order_Refund {
		$refund->set_date_created( time() - DAY_IN_SECONDS );
		$refund->save();

		return $refund;
	}

	/**
	 * Assert that an order has a note containing the expected text.
	 *
	 * @param WC_Order $order    Order object.
	 * @param string   $expected Expected note content.
	 */
	private function assertOrderHasNoteContaining( WC_Order $order, string $expected ): void {
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'type'     => 'any',
			)
		);

		foreach ( $notes as $note ) {
			if ( str_contains( $note->content, $expected ) ) {
				$this->addToAssertionCount( 1 );
				return;
			}
		}

		$this->fail( "Missing order note containing: {$expected}" );
	}

	/**
	 * Build a PaymentProcessingService wired to a specific lifecycle service.
	 *
	 * @param OrderPaymentLifecycleService $lifecycle_service Lifecycle service to inject.
	 * @return PaymentProcessingService
	 */
	private function build_sut_with_lifecycle( OrderPaymentLifecycleService $lifecycle_service ): PaymentProcessingService {
		$sut = new PaymentProcessingService();
		$sut->init(
			$this->order_payment_lock,
			$lifecycle_service,
			wc_get_container()->get( OrderPaymentNotes::class )
		);

		return $sut;
	}

	/**
	 * Run a checkout whose outcome cannot be applied and return the failure it hands back.
	 *
	 * @param callable $checkout Checkout to run.
	 * @return PaymentOutcomeApplyException
	 */
	private function expect_outcome_apply_exception( callable $checkout ): PaymentOutcomeApplyException {
		$thrown = null;
		try {
			$checkout();
		} catch ( PaymentOutcomeApplyException $exception ) {
			$thrown = $exception;
		}

		$this->assertInstanceOf( PaymentOutcomeApplyException::class, $thrown, 'A durable provider outcome that cannot be applied must be handed back with the failure.' );

		return $thrown;
	}

	/**
	 * Create a lifecycle service that always throws when applying an outcome.
	 *
	 * @return OrderPaymentLifecycleService
	 */
	private function create_throwing_lifecycle_service(): OrderPaymentLifecycleService {
		$lifecycle = new class() extends OrderPaymentLifecycleService {
			/**
			 * Always throw a provider's platform text, as a provider effect that calls its platform can, after the charge.
			 *
			 * @param WC_Order                               $order               Order object.
			 * @param PaymentLifecycleEvent                  $event               Lifecycle event.
			 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
			 * @throws RuntimeException Always, to drive the post-charge failure path.
			 */
			public function apply_under_lock( WC_Order $order, PaymentLifecycleEvent $event, ProviderPersistenceVocabularyInterface $persistence_vocabulary ): string {
				// Avoid parameter not used PHPCS errors.
				unset( $order, $event, $persistence_vocabulary );
				throw new RuntimeException( PaymentProcessingServiceTest::PROVIDER_FAILURE_TEXT, 9 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A test double's fixed text, never output.
			}

			/**
			 * Always throw, as apply_under_lock() does here.
			 *
			 * @param WC_Order            $order          Order object.
			 * @param array<string,mixed> $meta_to_update Order meta to update.
			 * @param array<int,mixed>    $meta_to_delete Order meta keys to delete.
			 * @throws RuntimeException Always, to drive the post-charge failure path.
			 */
			public function apply_meta_under_lock( WC_Order $order, array $meta_to_update, array $meta_to_delete ): void {
				unset( $order, $meta_to_update, $meta_to_delete );
				throw new RuntimeException( PaymentProcessingServiceTest::PROVIDER_FAILURE_TEXT, 9 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A test double's fixed text, never output.
			}
		};
		$lifecycle->init( $this->order_payment_lock, wc_get_container()->get( OrderPaymentNotes::class ) );

		return $lifecycle;
	}

	/**
	 * Create a fake WC logger that records every entry, and error calls separately, injected via the woocommerce_logging_class filter.
	 *
	 * @return object Fake logger that tracks log entries and error calls.
	 */
	private function create_fake_logger(): object {
		// phpcs:disable Squiz.Commenting, Squiz.Classes.ClassFileName.NoMatch
		return new class() implements \WC_Logger_Interface {
			public array $error_calls = array();

			public array $entries = array();

			public function add( $handle, $message, $level = \WC_Log_Levels::NOTICE ) {
				unset( $handle ); // Avoid parameter not used PHPCS errors.
				$this->log( $level, $message );
				return true;
			}

			public function log( $level, $message, $context = array() ) {
				$this->entries[] = array(
					'level'   => $level,
					'message' => $message,
					'context' => $context,
				);
			}

			public function emergency( $message, $context = array() ) {
				$this->log( \WC_Log_Levels::EMERGENCY, $message, $context );
			}

			public function alert( $message, $context = array() ) {
				$this->log( \WC_Log_Levels::ALERT, $message, $context );
			}

			public function critical( $message, $context = array() ) {
				$this->log( \WC_Log_Levels::CRITICAL, $message, $context );
			}

			public function notice( $message, $context = array() ) {
				$this->log( \WC_Log_Levels::NOTICE, $message, $context );
			}

			public function debug( $message, $context = array() ) {
				$this->log( \WC_Log_Levels::DEBUG, $message, $context );
			}

			public function info( $message, $context = array() ) {
				$this->log( \WC_Log_Levels::INFO, $message, $context );
			}

			public function warning( $message, $context = array() ) {
				$this->log( \WC_Log_Levels::WARNING, $message, $context );
			}

			public function error( $message, $context = array() ) {
				$this->log( \WC_Log_Levels::ERROR, $message, $context );
				$this->error_calls[] = array(
					'message' => $message,
					'context' => $context,
				);
			}
		};
		// phpcs:enable Squiz.Commenting, Squiz.Classes.ClassFileName.NoMatch
	}
}
