<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcomeApplyException;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\ProviderInterface;
use Automattic\WooCommerce\Internal\Payments\ProviderOperationEffectApplierInterface;
use Automattic\WooCommerce\Internal\Payments\ProviderOutcomeMetadataMapperInterface;
use Automattic\WooCommerce\Internal\Payments\ProviderPostLifecycleEffectApplierInterface;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsHtmlUtils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLegacyRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectApplier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectPlan;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentMethodDetailsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProviderGatewayAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeWooPaymentsHttpClient;
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
	 * The System Under Test.
	 *
	 * @var PaymentProcessingService
	 */
	private $sut;

	/**
	 * Order payment store.
	 *
	 * @var OrderPaymentLock
	 */
	private $store;

	/**
	 * Persistence profile used by the recording WooPayments provider.
	 *
	 * @var ProviderPersistenceVocabularyInterface
	 */
	private ProviderPersistenceVocabularyInterface $persistence_vocabulary;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut                    = wc_get_container()->get( PaymentProcessingService::class );
		$this->store                  = wc_get_container()->get( OrderPaymentLock::class );
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
	 * @testdox Should hand back a completed checkout with no redirect, so the gateway sends the shopper to the order's own order-received URL.
	 *
	 * The gateway's `format_checkout_result()` falls back to `$order->get_checkout_order_received_url()`
	 * whenever the outcome carries no explicit redirect. This is the URL the shopper's browser is sent to
	 * next; the following test proves what happens once it actually loads that URL.
	 */
	public function test_process_checkout_completed_redirects_to_order_received_url(): void {
		$order    = $this->create_woopayments_order( '10.00' );
		$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_cart_redirect', '', 'pm_cart_redirect', 'cus_cart_redirect' ) );

		$outcome = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_cart_redirect' ), $provider );
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'pi_cart_redirect', $outcome->get_provider_payment_id() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_CHECKOUT_REDIRECT, $outcome->get_data() );
		$this->assertSame( '', $outcome->get_redirect_url() );
	}

	/**
	 * @testdox Should let core's cart-clearing hook empty a matching cart once the shopper's browser loads a completed checkout's redirect URL.
	 *
	 * Oracle: WooPayments client 11.1.0 `class-wc-payment-gateway-wcpay.php:2229-2231` empties the cart
	 * itself inside `process_payment()`, before returning its success result. Native does not: its
	 * redirect (proved above) only points at the order-received URL, and the cart is cleared later, once
	 * the shopper's browser actually loads that URL and core's `wc_clear_cart_after_payment()`
	 * (`includes/wc-cart-functions.php:175-231`, hooked on `template_redirect`) reads its `order-received`
	 * query var and matching `key`. `WooPaymentsTokenizedCartSessionController` is registered as it would
	 * be in production to prove native adds no filter that blocks this for a standard (non-tokenized)
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

		$arbiter = $this->getMockBuilder( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_builtin_owner' ) )
			->getMock();
		$arbiter->method( 'is_builtin_owner' )->willReturn( true );
		$tokenized_cart_controller = new \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenizedCartSessionController();
		$tokenized_cart_controller->init( $arbiter );
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
	 * @testdox Should let core's cart-clearing hook empty the cart through the order-awaiting-payment session branch once the order is paid.
	 *
	 * Oracle: `includes/wc-cart-functions.php:198-207` (`wc_clear_cart_after_payment()`), the branch the
	 * Store API's checkout flow relies on instead of the order-received query var branch: it reads
	 * `WC()->session->order_awaiting_payment`, set by `WC_Checkout::process_checkout()`
	 * (`includes/class-wc-checkout.php:1163`) when the order is created, and clears the cart only once
	 * that order's status is no longer `pending`, `failed`, or `cancelled`.
	 */
	public function test_order_awaiting_payment_session_branch_clears_the_cart_once_the_order_is_paid(): void {
		$product = \WC_Helper_Product::create_simple_product();
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		unset( $GLOBALS['wp']->query_vars['order-received'] );

		$order = $this->create_woopayments_order( '10.00' );
		$order->set_cart_hash( WC()->cart->get_cart_hash() );
		$order->set_status( 'processing' );
		$order->save();

		WC()->session->set( 'order_awaiting_payment', $order->get_id() );

		// See the note on the previous test: only wc_clear_cart_after_payment() is under test here.
		remove_action( 'template_redirect', 'redirect_canonical' );
		remove_action( 'template_redirect', 'wc_template_redirect' );
		try {
			do_action( 'template_redirect' );
		} finally {
			WC()->session->set( 'order_awaiting_payment', 0 );
		}

		$this->assertTrue( WC()->cart->is_empty(), "A paid order tracked via the session's order_awaiting_payment must clear the shopper's matching cart." );
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
	 * @testdox A failed outcome flagged to preserve the order status records meta and note without failing the order.
	 */
	public function test_process_checkout_preserves_order_status_for_flagged_failed_outcome(): void {
		$order    = $this->create_woopayments_order( '10.00' );
		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_FAILED,
				'pi_blocked_test',
				'',
				'',
				'',
				array(
					PaymentOutcome::DATA_ERROR_CODE => 'wcpay_blocked_by_fraud_rule',
					PaymentOutcome::DATA_PRESERVE_ORDER_STATUS => true,
					PaymentOutcome::DATA_META       => array(
						'_wcpay_fraud_outcome_status' => 'block',
						'_intention_status'           => 'canceled',
					),
					PaymentOutcome::DATA_NOTE       => 'A payment was blocked by risk filters.',
				)
			)
		);

		$outcome = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_test' ), $provider );
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status(), 'The shopper-facing checkout result must still be a failure.' );
		$this->assertSame( 'pi_blocked_test', $outcome->get_provider_payment_id() );
		$this->assertSame( 'pending', $order->get_status(), 'A blocked payment must not fail the order; the merchant decides whether to cancel.' );
		$this->assertSame( 'block', $order->get_meta( '_wcpay_fraud_outcome_status', true ) );
		$this->assertSame( 'canceled', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( 'pi_blocked_test', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( '', (string) $order->get_transaction_id(), 'A blocked attempt must not claim the order transaction id.' );

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertContains( 'A payment was blocked by risk filters.', wp_list_pluck( $notes, 'content' ) );
	}

	/**
	 * @testdox A WooPayments card decline fails the order with its intent id, exactly one note, and the allow fraud meta.
	 *
	 * Outcome shape matches the recorded REC-1 `generic_decline` envelope (`Fixtures/rec-1-intention-declines.json`)
	 * as `WooPaymentsProviderGatewayAdapterTest::test_native_charge_decline_envelope_maps_each_card_code` proves
	 * the real adapter produces it. Client 11.1.0 citations: order `failed` (`class-wc-payment-gateway-wcpay.php:1324-1328`),
	 * intent id kept on the failed order (`:1331-1333`), exactly one failed-payment note (`:1359-1361`, `:1400`),
	 * fraud meta box `allow` for a `card_error` decline (`:1372`). A plain checkout decline is not the card-testing
	 * or rate-limiter refusal path (F2); native matches the client here.
	 */
	public function test_woopayments_card_decline_fails_order_with_intent_note_and_allow_meta(): void {
		$order           = $this->create_woopayments_order( '10.01' );
		$note_service    = wc_get_container()->get( WooPaymentsOrderNoteService::class );
		$note_candidates = $note_service->format_checkout_payment_failed_note_candidates(
			$order,
			'Error: Your card was declined.',
			'The bank did not return any further details with this decline.',
			'card_error',
			'card_declined'
		);
		$provider        = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_FAILED,
				'pi_3UJTiNBzWlxcwgpP0GauBpTM',
				'',
				'',
				'',
				array(
					PaymentOutcome::DATA_ERROR_CODE       => 'card_declined',
					PaymentOutcome::DATA_ERROR_MESSAGE    => 'Error: Your card was declined.',
					PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE => 'Error: Your card was declined.',
					PaymentOutcome::DATA_NOTE             => $note_candidates[0],
					PaymentOutcome::DATA_NOTE_EQUIVALENTS => $note_candidates,
					PaymentOutcome::DATA_META             => array( '_wcpay_fraud_meta_box_type' => 'allow' ),
				)
			)
		);

		$outcome = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_rec1' ), $provider );
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'pi_3UJTiNBzWlxcwgpP0GauBpTM', $outcome->get_provider_payment_id() );
		$this->assertSame( 'failed', $order->get_status() );
		$this->assertSame( 'pi_3UJTiNBzWlxcwgpP0GauBpTM', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( '', (string) $order->get_meta( '_charge_id', true ), 'A declined charge has no charge id.' );
		$this->assertSame( 'allow', $order->get_meta( '_wcpay_fraud_meta_box_type', true ) );

		$notes                = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$failed_payment_notes = array_values(
			array_filter(
				$notes,
				static fn( $note ): bool => str_contains( $note->content, '<strong>failed</strong> to complete with the following message:' )
			)
		);
		$this->assertCount( 1, $failed_payment_notes, 'The order must carry exactly one failed-payment note (WooCommerce core\'s own status-transition note is separate).' );
		$this->assertFalse(
			$this->is_order_payment_lock_held_for( $order, $this->persistence_vocabulary, $provider->last_idempotency_key ),
			'A failed attempt must release the order payment lock.'
		);
	}

	/**
	 * @testdox A preserve-status failed outcome for an already-paid order is skipped by the late-failure guard.
	 */
	public function test_preserve_status_failed_outcome_does_not_overwrite_paid_order(): void {
		$order = $this->create_woopayments_order( '10.00' );
		$order->payment_complete( 'pi_paid_first' );
		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertTrue( $order->is_paid() );

		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_FAILED,
				'pi_blocked_late',
				'',
				'',
				'',
				array(
					PaymentOutcome::DATA_ERROR_CODE => 'wcpay_blocked_by_fraud_rule',
					PaymentOutcome::DATA_PRESERVE_ORDER_STATUS => true,
					PaymentOutcome::DATA_META       => array(
						'_wcpay_fraud_outcome_status' => 'block',
						'_intention_status'           => 'canceled',
					),
					PaymentOutcome::DATA_NOTE       => 'A payment was blocked by risk filters.',
				)
			)
		);

		$this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_test' ), $provider );
		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertTrue( $order->is_paid(), 'A late blocked outcome must not disturb a paid order.' );
		$this->assertSame( '', (string) $order->get_meta( '_wcpay_fraud_outcome_status', true ), 'Block meta must not overwrite a paid order.' );
		$this->assertNotSame( 'canceled', (string) $order->get_meta( '_intention_status', true ) );
		$this->assertNotContains( 'A payment was blocked by risk filters.', wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
	}

	/**
	 * @testdox A provider exception during a refund fails the refund with the exception message.
	 */
	public function test_refund_provider_exception_fails_with_the_exception_message(): void {
		$order = $this->create_woopayments_order( '10.00' );
		$this->create_local_refund( $order, 2.5, 'Adjustment' );

		// phpcs:disable Squiz.Commenting, Squiz.Classes.ClassFileName.NoMatch
		$provider = new class( new PaymentOutcome( PaymentOutcome::STATUS_FAILED ) ) extends RecordingProvider {
			public function refund( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
				unset( $context, $idempotency_key );
				throw new Exception( 'Processor unavailable.' );
			}
		};
		// phpcs:enable Squiz.Commenting, Squiz.Classes.ClassFileName.NoMatch

		$result = $this->sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 2.5, 'Adjustment' ), $provider );

		$this->assertWPError( $result );
		$this->assertSame( 'order_payment_refund_failed', $result->get_error_code(), 'An exception without an error code fails with the default refund code.' );
		$this->assertSame( 'Processor unavailable.', $result->get_error_message() );
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
			$this->store,
			wc_get_container()->get( OrderPaymentLifecycleService::class )
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
	 * @testdox Should return a redirect result without completing the order for redirect outcomes.
	 *
	 * Oracle: WooPayments client 11.1.0 `class-wc-payments-order-service.php:417-426`: a
	 * `requires_action` intent for a non-offline method with no error calls `mark_payment_started()`,
	 * which leaves the order at its pending status and writes no charge id.
	 */
	public function test_process_checkout_returns_redirect_without_completing_order(): void {
		$order    = $this->create_woopayments_order( '15.00' );
		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_REQUIRES_REDIRECT,
				'pi_redirect',
				'https://example.test/redirect',
				'pm_redirect',
				'',
				array(
					'meta' => array(
						'_intention_status' => 'requires_action',
					),
				)
			)
		);

		$outcome = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_redirect' ), $provider );
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_REQUIRES_REDIRECT, $outcome->get_status() );
		$this->assertSame( 'pi_redirect', $outcome->get_provider_payment_id() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_CHECKOUT_REDIRECT, $outcome->get_data() );
		$this->assertSame( 'https://example.test/redirect', $outcome->get_redirect_url() );
		$this->assertSame( 'pm_redirect', $outcome->get_payment_method_id() );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( 'pi_redirect', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'requires_action', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( '', $order->get_meta( '_charge_id', true ) );
	}

	/**
	 * @testdox Should return the checkout outcome while applying lifecycle changes.
	 */
	public function test_process_checkout_outcome_returns_outcome_after_lifecycle_application(): void {
		$order   = $this->create_woopayments_order( '15.00' );
		$outcome = new PaymentOutcome(
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
		);

		$provider = new RecordingProvider( $outcome );
		$result   = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_requires_action' ), $provider );
		$order    = wc_get_order( $order->get_id() );

		$this->assertSame( $outcome, $result );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( 'pi_requires_action', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'requires_action', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( 'pm_requires_action', $order->get_meta( '_payment_method_id', true ) );
		$this->assertSame( 'cus_requires_action', $order->get_meta( '_stripe_customer_id', true ) );
		$this->assertSame( 'ch_requires_action', $order->get_meta( '_charge_id', true ) );
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
			 * Get the provider persistence profile.
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
					 * Get the order meta key holding a kept charge idempotency key: this provider keeps none.
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
		$this->assertSame( '', $order->get_meta( '_intent_id', true ), 'WooPayments intent meta must not be written for providers with their own profile vocabulary.' );
	}

	/**
	 * @testdox A successful charge whose lifecycle application throws must persist the payment reference, log, and hand the failure back with the outcome.
	 */
	public function test_process_checkout_outcome_keeps_order_reconcilable_when_apply_throws(): void {
		$order   = $this->create_woopayments_order( '10.00' );
		$outcome = new PaymentOutcome(
			PaymentOutcome::STATUS_COMPLETED,
			'pi_post_charge',
			'',
			'pm_post_charge',
			'cus_post_charge'
		);

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

		remove_all_filters( 'woocommerce_logging_class' );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $outcome, $exception->get_outcome(), 'A post-charge apply failure must hand back the successful outcome, not a downgraded one.' );
		$this->assertSame( 'Simulated lifecycle failure after a successful charge.', $exception->get_failure()->getMessage() );
		$this->assertSame( 'pi_post_charge', $order->get_transaction_id(), 'The provider payment reference must be persisted so the charge stays reconcilable.' );
		$this->assertTrue( $exception->was_reconciliation_context_persisted(), 'The exception must report that the reference was saved.' );

		$this->assertCount( 1, $fake_logger->error_calls, 'A post-charge apply failure must be logged at error level.' );
		$context = $fake_logger->error_calls[0]['context'];
		$this->assertSame( 'order-payments', $context['source'] );
		$this->assertSame( $order->get_id(), $context['order_id'] );
		$this->assertSame( 'pi_post_charge', $context['payment_reference'] );
	}

	/**
	 * @testdox A failed charge whose lifecycle application throws must rethrow rather than swallow the failure.
	 */
	public function test_process_checkout_outcome_rethrows_when_apply_throws_for_failed_outcome(): void {
		$order   = $this->create_woopayments_order( '10.00' );
		$outcome = new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			'',
			'',
			'',
			'',
			array( 'error_message' => 'Declined.' )
		);

		$sut      = $this->build_sut_with_lifecycle( $this->create_throwing_lifecycle_service() );
		$provider = new RecordingProvider( $outcome );

		$this->expectException( RuntimeException::class );

		$sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_failed' ), $provider );
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
		$lock_token = $this->store->claim( $order, $this->persistence_vocabulary, 'next_operation', 'payment operation' );
		$this->assertNotNull( $lock_token, 'The next operation must be able to claim the lock.' );
		$this->store->release( $order, $this->persistence_vocabulary, $lock_token );
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
	 * @testdox A post-charge apply failure must not overwrite a transaction reference the order already carries.
	 */
	public function test_process_checkout_outcome_does_not_overwrite_existing_transaction_reference(): void {
		$order = $this->create_woopayments_order( '10.00' );
		$order->set_transaction_id( 'pi_existing_reference' );
		$order->save();

		$outcome = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_post_charge', '', 'pm_post_charge' );

		$sut      = $this->build_sut_with_lifecycle( $this->create_throwing_lifecycle_service() );
		$provider = new RecordingProvider( $outcome );

		$exception = $this->expect_outcome_apply_exception(
			static fn() => $sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_post_charge' ), $provider )
		);
		$order     = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $outcome, $exception->get_outcome() );
		$this->assertSame( 'pi_existing_reference', $order->get_transaction_id(), 'An existing transaction reference must be preserved.' );
		$this->assertFalse( $exception->was_reconciliation_context_persisted(), 'The exception must report that the reference was not saved.' );
	}

	/**
	 * @testdox Provider effects are applied before payment completion hooks run.
	 */
	public function test_process_checkout_outcome_applies_provider_effects_before_lifecycle(): void {
		$order           = $this->create_woopayments_order( '10.00' );
		$outcome         = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_effect_ordering', '', 'pm_effect_ordering' );
		$provider        = new class( $outcome ) extends RecordingProvider implements ProviderOperationEffectApplierInterface {
			/**
			 * Apply provider effects before the generic lifecycle.
			 *
			 * @param PaymentOperationContext $context   Payment context.
			 * @param PaymentOutcome          $outcome   Provider outcome.
			 * @param string                  $operation Operation name.
			 * @return PaymentOutcome
			 */
			public function apply_operation_effects( PaymentOperationContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				$context->get_order()->update_meta_data( '_provider_effect_operation', $operation );
				$context->get_order()->save_meta_data();

				return $outcome;
			}
		};
		$observed_effect = '';
		$observer        = static function ( int $order_id ) use ( &$observed_effect ): void {
			$completed_order = wc_get_order( $order_id );
			$observed_effect = $completed_order instanceof WC_Order
				? (string) $completed_order->get_meta( '_provider_effect_operation', true )
				: '';
		};
		add_action( 'woocommerce_payment_complete', $observer );

		try {
			$result = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_effect_ordering' ), $provider );
		} finally {
			remove_action( 'woocommerce_payment_complete', $observer );
		}

		$this->assertSame( $outcome, $result );
		$this->assertSame( 'charge', $observed_effect );
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
		add_filter(
			'woocommerce_woopayments_related_subscriptions_for_order',
			static function ( array $subscriptions, WC_Order $filtered_order ) use ( $order, $subscription, &$credential_sync_calls, &$display_sync_calls, $sequence ): array {
				if ( $order->get_id() !== $filtered_order->get_id() ) {
					return $subscriptions;
				}

				if ( 'Link (WooPayments)' === $filtered_order->get_payment_method_title() ) {
					++$display_sync_calls;
					$sequence[] = 'display-sync';
				} else {
					++$credential_sync_calls;
					$sequence[] = 'credential-sync';
				}
				return array( $subscription );
			},
			10,
			2
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
			remove_all_filters( 'woocommerce_woopayments_related_subscriptions_for_order' );
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
		$this->assertSame( '', $order->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
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
		$this->assertSame( '', $order->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
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
		$this->assertSame( '', $order->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
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
	 * @testdox Successful charges stay reconcilable and are handed back when provider effect application throws.
	 */
	public function test_process_checkout_outcome_keeps_provider_success_when_effect_application_throws(): void {
		$order    = $this->create_woopayments_order( '10.00' );
		$outcome  = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_effect_failure', '', 'pm_effect_failure' );
		$provider = new class( $outcome ) extends RecordingProvider implements ProviderOperationEffectApplierInterface {
			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double always throws.
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
			fn() => $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_effect_failure' ), $provider )
		);
		$order     = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $outcome, $exception->get_outcome() );
		$this->assertSame( 'Provider effect write failed.', $exception->get_failure()->getMessage() );
		$this->assertSame( 'pi_effect_failure', $order->get_transaction_id() );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ), 'The reconciliation context must reach the stored order before the failure is handed back.' );
	}

	/**
	 * @testdox Referenced customer-action outcomes stay reconcilable and are handed back when provider effect application throws.
	 */
	public function test_process_checkout_outcome_keeps_referenced_customer_action_when_effect_application_throws(): void {
		$order    = $this->create_woopayments_order( '10.00' );
		$outcome  = new PaymentOutcome(
			PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION,
			'pi_action_effect_failure',
			'#wcpay-confirm-pi:1:secret:nonce',
			'pm_action_effect_failure'
		);
		$provider = new class( $outcome ) extends RecordingProvider implements ProviderOperationEffectApplierInterface {
			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double always throws.
			/**
			 * Fail provider effect application after a referenced provider response.
			 *
			 * @param PaymentOperationContext $context   Payment context.
			 * @param PaymentOutcome          $outcome   Provider outcome.
			 * @param string                  $operation Operation name.
			 * @return PaymentOutcome
			 * @throws RuntimeException Always.
			 */
			public function apply_operation_effects( PaymentOperationContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				unset( $context, $outcome, $operation );
				throw new RuntimeException( 'Provider display effect write failed.' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};

		$exception = $this->expect_outcome_apply_exception(
			fn() => $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_action_effect_failure' ), $provider )
		);
		$order     = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $outcome, $exception->get_outcome() );
		$this->assertSame( 'pi_action_effect_failure', $order->get_transaction_id() );
		$this->assertSame( 'pi_action_effect_failure', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'pm_action_effect_failure', $order->get_meta( '_payment_method_id', true ) );
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
	 * @testdox Recovery logging failures cannot replace a referenced provider outcome.
	 */
	public function test_process_checkout_outcome_keeps_provider_result_when_recovery_logging_throws(): void {
		$order    = $this->create_woopayments_order( '10.00' );
		$outcome  = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_logger_recovery_failure', '', 'pm_logger_recovery_failure' );
		$provider = new class( $outcome ) extends RecordingProvider implements ProviderOperationEffectApplierInterface {
			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double always throws.
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
		$logger   = $this->create_throwing_logger();
		add_filter(
			'woocommerce_logging_class',
			static function () use ( $logger ) {
				return $logger;
			}
		);

		try {
			$exception = $this->expect_outcome_apply_exception(
				fn() => $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_logger_recovery_failure' ), $provider )
			);
		} finally {
			remove_all_filters( 'woocommerce_logging_class' );
		}

		$this->assertSame( $outcome, $exception->get_outcome() );
		$this->assertSame( 'Provider effect write failed.', $exception->get_failure()->getMessage(), 'The logging failure must not replace the original failure.' );
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
	 * @testdox Should preserve a provider supplied empty checkout redirect.
	 */
	public function test_process_checkout_preserves_empty_checkout_redirect_override(): void {
		$order    = $this->create_woopayments_order( '15.00' );
		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_PENDING_ASYNC,
				'',
				'',
				'',
				'',
				array( 'checkout_redirect' => '' )
			)
		);

		$outcome = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), $provider );

		$this->assertSame( PaymentOutcome::STATUS_PENDING_ASYNC, $outcome->get_status() );
		$this->assertSame( '', $outcome->get_data()[ PaymentOutcome::DATA_CHECKOUT_REDIRECT ] ?? null );
	}

	/**
	 * @testdox Should not call the provider while an order operation is locked.
	 */
	public function test_process_checkout_returns_failure_when_order_operation_is_locked(): void {
		$order = $this->create_woopayments_order( '10.00' );
		$key   = wp_generate_uuid4();
		$this->hold_order_payment_lock( $order, $this->persistence_vocabulary, $key );

		$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_test' ) );

		$outcome = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_test' ), $provider );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( '', $outcome->get_provider_payment_id() );
		$this->assertSame( 0, $provider->charge_calls );
		$this->clear_order_payment_lock( $order, $this->persistence_vocabulary );
	}

	/**
	 * @testdox Should not call the provider while any order operation is locked.
	 */
	public function test_process_checkout_returns_failure_when_any_order_operation_is_locked(): void {
		$order = $this->create_woopayments_order( '10.00' );
		$this->hold_order_payment_lock( $order, $this->persistence_vocabulary, 'pi_existing' );

		$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_test' ) );

		$outcome = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_test' ), $provider );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( '', $outcome->get_provider_payment_id() );
		$this->assertSame( 0, $provider->charge_calls );
		$this->clear_order_payment_lock( $order, $this->persistence_vocabulary );
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
	 * @testdox Should not charge an order put on hold with a payment on record after this request loaded it.
	 */
	public function test_process_checkout_does_not_charge_an_order_put_on_hold_after_it_was_loaded(): void {
		$order = $this->create_woopayments_order( '10.00' );
		$order->set_transaction_id( 'pi_held' );
		$order->update_meta_data( '_intent_id', 'pi_held' );
		$order->save();
		$loaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $loaded );

		// Another request records the manual-capture authorization of the same intent: the order goes on hold, unpaid.
		$order->set_status( 'on-hold' );
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
		remove_all_filters( 'woocommerce_logging_class' );
		$order = wc_get_order( $order->get_id() );

		$this->assertSame( 0, $provider->charge_calls, 'An authorization held for capture must not be charged again.' );
		$refusals = array_values(
			array_filter(
				$fake_logger->entries,
				static fn( array $entry ): bool => \WC_Log_Levels::WARNING === $entry['level'] && 'order_status_changed' === ( $entry['context']['reason'] ?? null )
			)
		);
		$this->assertCount( 1, $refusals, 'The refused charge must be logged once.' );
		$this->assertSame( sprintf( 'Checkout charged nothing: order %d changed before this request claimed its payment lock.', $order->get_id() ), $refusals[0]['message'] );
		$this->assertSame( 'order-payments', $refusals[0]['context']['source'] );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'A payment operation is already in progress for this order.', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_MESSAGE ] ?? null );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status(), 'The refusal must leave the held order as it is.' );
		$this->assertFalse( get_transient( $this->persistence_vocabulary->get_order_lock_key( $order ) ), 'The refusal must release the lock.' );
	}

	/**
	 * @testdox Should not charge an order whose payment reference changed after this request loaded it: $_dataName.
	 *
	 * @dataProvider provide_payment_reference_changes
	 *
	 * @param string $transaction_id Transaction ID another request records.
	 * @param string $intent_id      Payment intent another request records.
	 */
	public function test_process_checkout_does_not_charge_an_order_whose_payment_reference_changed( string $transaction_id, string $intent_id ): void {
		// An earlier attempt left an intent waiting for the shopper; checkout keeps the first transaction ID.
		$order = $this->create_woopayments_order( '10.00' );
		$order->set_transaction_id( 'pi_started' );
		$order->update_meta_data( '_intent_id', 'pi_started' );
		$order->save();
		$loaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $loaded );

		$order->set_transaction_id( $transaction_id );
		$order->update_meta_data( '_intent_id', $intent_id );
		$order->save();

		$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_second' ) );
		$outcome  = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $loaded, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_second' ), $provider );

		$this->assertSame( 0, $provider->charge_calls, 'A payment another request started must not be paid again.' );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'A payment operation is already in progress for this order.', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_MESSAGE ] ?? null );
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
		$kept_key = (string) $after_first->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $first_outcome->get_status() );
		$this->assertSame( 'failed', $after_first->get_status() );
		$this->assertSame( '', $after_first->get_meta( '_intent_id', true ) );
		$this->assertSame( array( $kept_key ), $sent_keys->getArrayCopy(), 'The ambiguous failure must keep the key it sent.' );

		$second_outcome = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $second_view, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_second' ), $provider );

		$this->assertSame( array( $kept_key ), $sent_keys->getArrayCopy(), 'A submission loaded before the ambiguous attempt must not send a charge.' );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $second_outcome->get_status() );
		$this->assertSame( 'A payment operation is already in progress for this order.', $second_outcome->get_data()[ PaymentOutcome::DATA_ERROR_MESSAGE ] ?? null );
		$this->assertFalse( get_transient( $this->persistence_vocabulary->get_order_lock_key( $order ) ), 'The refusal must release the lock.' );

		$retry = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $retry );
		$this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $retry, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_first' ), $provider );

		$this->assertSame( array( $kept_key, $kept_key ), $sent_keys->getArrayCopy(), 'A submission that loads the order after the ambiguous attempt replays its key.' );
	}

	/**
	 * @testdox A new-card submission refused under the kept key completes the order from the earlier request's intent ($status) and charges nothing.
	 *
	 * The first submission's charge gets a 502, so the order keeps its key and the ambiguity record. The shopper resubmits
	 * with a new card: Stripe refuses the kept key with an idempotency_error, the intents list shows the earlier request's
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
		$http_client            = new FakeWooPaymentsHttpClient();
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
		$this->assertSame( '', $paid->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertSame( '', $paid->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META, true ) );
	}

	/**
	 * @testdox A webhook that pays the order between submissions stops the next submission under the lock before it sends anything.
	 *
	 * The first submission's charge gets a 502, so the order keeps its key and the ambiguity record. The next submission
	 * loads the order; before it claims the lock, the earlier request's `payment_intent.succeeded` arrives and the webhook
	 * finds the order by its metadata alone (`WooPaymentsEventIngestor::get_order_from_event_object_metadata()`), so the
	 * order is paid. Under the lock the order is read again, found paid, and nothing is sent: no charge under the kept key
	 * and no lookup.
	 */
	public function test_process_checkout_charges_nothing_when_a_webhook_paid_the_order_between_submissions(): void {
		$order                  = $this->create_woopayments_order( '10.00' );
		$http_client            = new FakeWooPaymentsHttpClient();
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
		$this->assertNotSame( '', $resubmit->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );

		wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsEventIngestor::class )->process(
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
		$http_client            = new FakeWooPaymentsHttpClient();
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
		$this->assertNotSame( '', $kept->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, true ) );
		$this->assertIsArray( $kept->get_meta( WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META, true ) );
		$this->assertCount( 3, $http_client->requests, 'A failed lookup must not charge.' );

		$this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $kept, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_new' ), $provider );
		$paid    = wc_get_order( $order->get_id() );
		$charges = array_values( array_filter( $http_client->requests, static fn( array $request ): bool => 'POST' === $request['method'] ) );

		$this->assertSame( 'completed', $paid->get_status() );
		$this->assertSame( 'pi_new_card', $paid->get_meta( '_intent_id', true ) );
		$this->assertCount( 4, $charges, 'Two refused sends under the kept key, then exactly one charge of the new card.' );
		$this->assertNotSame( $charges[0]['headers']['Idempotency-Key'], $charges[3]['headers']['Idempotency-Key'], 'The new card is charged under a fresh key.' );
	}

	/**
	 * @testdox Should not charge an order another submission changed since this request loaded it: $_dataName.
	 *
	 * @dataProvider provide_unpaid_changes_by_another_submission
	 *
	 * @param string $status   Status another submission writes.
	 * @param string $kept_key Charge idempotency key another submission keeps on the order.
	 */
	public function test_process_checkout_does_not_charge_an_order_another_submission_changed( string $status, string $kept_key ): void {
		$order  = $this->create_woopayments_order( '10.00' );
		$loaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $loaded );

		$order->set_status( $status );
		$order->update_meta_data( WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, $kept_key );
		$order->save();

		$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_second' ) );
		$outcome  = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $loaded, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_second' ), $provider );

		$this->assertSame( 0, $provider->charge_calls, 'Another submission is at work on this order.' );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'A payment operation is already in progress for this order.', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_MESSAGE ] ?? null );
	}

	/**
	 * @testdox Should still refuse, without a charge, an order another submission changed when the refusal's log line throws $thrown.
	 *
	 * The logger is WooCommerce's own, so the line runs the woocommerce_logger_log_message filter (WC_Logger::log()).
	 *
	 * @testWith ["RuntimeException"]
	 *           ["Error"]
	 *
	 * @param string $thrown Class the filter throws.
	 */
	public function test_process_checkout_refusal_for_a_changed_order_survives_a_throwing_log_filter( string $thrown ): void {
		$order  = $this->create_woopayments_order( '10.00' );
		$loaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $loaded );
		$order->set_status( 'failed' );
		$order->save();
		$throws = 0;
		add_filter(
			'woocommerce_logger_log_message',
			static function ( $message ) use ( $thrown, &$throws ) {
				if ( false !== strpos( (string) $message, 'changed before this request claimed its payment lock' ) ) {
					++$throws;
					throw new $thrown( 'Log write failed.' );
				}

				return $message;
			}
		);

		$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_second' ) );
		$outcome  = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $loaded, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_second' ), $provider );

		$this->assertSame( 1, $throws, 'The refusal line was written once.' );
		$this->assertSame( 0, $provider->charge_calls, 'Another submission is at work on this order.' );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'A payment operation is already in progress for this order.', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_MESSAGE ] ?? null );
	}

	/**
	 * Unpaid changes another submission makes between this request's load and its claim.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provide_unpaid_changes_by_another_submission(): array {
		return array(
			'status only'                => array( 'failed', '' ),
			'kept charge key only'       => array( 'pending', 'key_unknown_outcome' ),
			'status and kept charge key' => array( 'failed', 'key_unknown_outcome' ),
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
	 * Payment references another request records between this request's load and its claim.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provide_payment_reference_changes(): array {
		return array(
			'new transaction ID' => array( 'pi_other', 'pi_started' ),
			'new payment intent' => array( 'pi_started', 'pi_other' ),
		);
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
	 * @testdox Should return true when the provider refund succeeds.
	 */
	public function test_process_refund_returns_true_when_provider_succeeds(): void {
		$order = $this->create_woopayments_order( '10.00' );
		$this->create_local_refund( $order, 2.50, 'Adjustment' );
		$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED ) );

		$result = $this->sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 2.50, 'Adjustment' ), $provider );

		$this->assertTrue( $result );
		$this->assertSame( 1, $provider->refund_calls );
		// Client 11.1.0 sends a UUID v4 per refund request (class-wc-payments-api-client.php:2690, 3114-3119).
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $provider->last_idempotency_key );
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

		remove_all_filters( 'woocommerce_logging_class' );

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
	 * @testdox Two equal-amount partial refunds must reach the provider with distinct idempotency keys.
	 */
	public function test_two_equal_amount_partial_refunds_use_distinct_idempotency_keys(): void {
		$order = $this->create_woopayments_order( '10.00' );

		$first_refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 2.50,
				'reason'         => 'Adjustment',
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $first_refund );

		// The provider links the processed refund via `_wcpay_refund_id`, mirroring the live
		// synchronous and webhook paths. Resolution must skip that linked refund so the second
		// refund instance resolves to its own row and not the first one.
		$provider     = new RecordingProvider( $this->successful_refund_outcome( 're_first' ) );
		$first_result = $this->sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 2.50, 'Adjustment' ), $provider );
		$first_key    = $provider->last_idempotency_key;

		$second_refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 2.50,
				'reason'         => 'Adjustment',
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $second_refund );

		$second_provider = new RecordingProvider( $this->successful_refund_outcome( 're_second' ) );
		$second_result   = $this->sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 2.50, 'Adjustment' ), $second_provider );
		$second_key      = $second_provider->last_idempotency_key;

		$this->assertTrue( $first_result );
		$this->assertTrue( $second_result );
		$this->assertSame( 1, $provider->refund_calls, 'The first refund must reach the provider.' );
		$this->assertSame( 1, $second_provider->refund_calls, 'The second refund must reach the provider.' );
		$this->assertNotSame(
			$first_key,
			$second_key,
			'Two distinct refunds of the same amount and reason must use different idempotency keys so the provider does not replay the first refund.'
		);
	}

	/**
	 * @testdox A retried refund sends a new idempotency key even when an older manual refund of the same amount exists.
	 *
	 * Client 11.1.0 `process_refund()` never sets a caller key on the refund request
	 * (class-wc-payment-gateway-wcpay.php:2970-2976), so every refund call sends its own
	 * `Idempotency-Key` UUID (class-wc-payments-api-client.php:2690).
	 */
	public function test_retried_refund_sends_a_new_idempotency_key(): void {
		$order = $this->create_woopayments_order( '10.00' );
		$this->backdate_refund( $this->create_local_refund( $order, 2.50, 'Adjustment' ) );

		$failed_refund    = $this->create_local_refund( $order, 2.50, 'Adjustment' );
		$failing_provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_FAILED, '', '', '', '', array( PaymentOutcome::DATA_ERROR_CODE => 'temporary_error' ) ) );
		$first_result     = $this->sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 2.50, 'Adjustment' ), $failing_provider );
		// WooCommerce deletes the refund row when the gateway refund fails (wc-order-functions.php:675-680).
		$failed_refund->delete( true );

		$this->create_local_refund( $order, 2.50, 'Adjustment' );
		$retry_provider = new RecordingProvider( $this->successful_refund_outcome( 're_retry' ) );
		$retry_result   = $this->sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 2.50, 'Adjustment' ), $retry_provider );

		$this->assertWPError( $first_result );
		$this->assertTrue( $retry_result );
		$this->assertNotSame( '', $failing_provider->last_idempotency_key );
		$this->assertNotSame(
			$failing_provider->last_idempotency_key,
			$retry_provider->last_idempotency_key,
			'Like the client, each refund call sends its own key, so a retry is never answered with the earlier failure.'
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
	 * (class-wc-payment-gateway-wcpay.php:3003-3007). Native refuses first so no money moves; the
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
	 * @testdox A refund outcome adopts an equivalent note and backfills its provider-neutral structural identity.
	 */
	public function test_process_refund_adopts_equivalent_note_and_backfills_structural_identity(): void {
		$identity    = 'refund:re_structural:created_successful';
		$native_note = 'A refund of $10.99 was successfully processed. (<code>re_structural</code>)';
		$locale_note = 'A refund of 10,99 $ was successfully processed. (<code>re_structural</code>)';
		$order       = $this->create_woopayments_order( '10.99' );
		$refund      = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 10.99,
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$order->add_order_note( $locale_note );

		$effect_data = array(
			PaymentOutcome::DATA_REFUND_NOTE             => $native_note,
			PaymentOutcome::DATA_REFUND_NOTE_IDENTITY    => $identity,
			PaymentOutcome::DATA_REFUND_NOTE_EQUIVALENTS => array( $native_note, $locale_note ),
			PaymentOutcome::DATA_REFUND_NOTE_IDENTITY_META_KEY => '_test_provider_note_identity',
		);
		$provider    = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 're_structural', '', '', '', $effect_data ) );

		$result = $this->sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 10.99 ), $provider );
		$order  = wc_get_order( $order->get_id() );

		$this->assertTrue( $result );
		$this->assertSame( 1, $provider->refund_calls, 'The structural note reconciliation must not issue a second provider refund.' );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertCount( 1, $order->get_refunds() );
		$refund_notes = array_values(
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
				static fn( $note ): bool => str_contains( $note->content, 're_structural' )
			)
		);
		$this->assertCount( 1, $refund_notes );
		$this->assertSame( $locale_note, $refund_notes[0]->content );
		$this->assertSame( hash( 'sha256', $identity ), get_comment_meta( $refund_notes[0]->id, '_test_provider_note_identity', true ) );
	}

	/**
	 * @testdox Mixed equivalent-note values are filtered while valid structural identity remains usable.
	 */
	public function test_process_refund_filters_mixed_equivalent_notes_before_structural_adoption(): void {
		$identity    = 'refund:re_mixed_equivalents:created_successful';
		$native_note = 'Native refund note. (<code>re_mixed_equivalents</code>)';
		$locale_note = 'Localized refund note. (<code>re_mixed_equivalents</code>)';
		$order       = $this->create_woopayments_order( '3.25' );
		$refund      = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 3.25,
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$order->add_order_note( $locale_note );

		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_COMPLETED,
				're_mixed_equivalents',
				'',
				'',
				'',
				array(
					'refund_note'                   => $native_note,
					'refund_note_identity'          => $identity,
					'refund_note_equivalents'       => array( $native_note, 42, array( '_arbitrary_comment_meta' => 'injected' ), $locale_note ),
					'refund_note_identity_meta_key' => '_test_provider_note_identity',
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
				static fn( $note ): bool => str_contains( $note->content, 're_mixed_equivalents' )
			)
		);
		$this->assertCount( 1, $refund_notes );
		$this->assertSame( $locale_note, $refund_notes[0]->content );
		$this->assertSame( hash( 'sha256', $identity ), get_comment_meta( $refund_notes[0]->id, '_test_provider_note_identity', true ) );
		$this->assertSame( '', get_comment_meta( $refund_notes[0]->id, '_arbitrary_comment_meta', true ) );
	}

	/**
	 * @testdox Numeric equivalent-note values are not coerced into persisted-note content matches.
	 */
	public function test_process_refund_does_not_coerce_numeric_equivalent_note_candidates(): void {
		$identity    = 'refund:re_numeric_equivalent:created_successful';
		$native_note = 'Native refund note. (<code>re_numeric_equivalent</code>)';
		$order       = $this->create_woopayments_order( '3.50' );
		$refund      = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 3.50,
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$coercion_trap_note_id = $order->add_order_note( '42' );

		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_COMPLETED,
				're_numeric_equivalent',
				'',
				'',
				'',
				array(
					'refund_note'                   => $native_note,
					'refund_note_identity'          => $identity,
					'refund_note_equivalents'       => array( 42 ),
					'refund_note_identity_meta_key' => '_test_provider_note_identity',
				)
			)
		);

		$result = $this->sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 3.50 ), $provider );

		$this->assertTrue( $result );
		$native_notes = array_values(
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
				static fn( $note ): bool => $native_note === $note->content
			)
		);
		$this->assertCount( 1, $native_notes );
		$this->assertSame( hash( 'sha256', $identity ), get_comment_meta( $native_notes[0]->id, '_test_provider_note_identity', true ) );
		$this->assertSame( array(), get_comment_meta( $coercion_trap_note_id ) );
	}

	/**
	 * @testdox Malformed structural refund-note values fall back without creating comment metadata.
	 * @dataProvider malformed_refund_note_identity_data
	 *
	 * @param mixed $identity          Structural note identity.
	 * @param mixed $identity_meta_key Structural note identity meta key.
	 */
	public function test_process_refund_does_not_coerce_malformed_structural_note_values( $identity, $identity_meta_key ): void {
		$note   = 'Existing exact refund note. (<code>re_malformed_structure</code>)';
		$order  = $this->create_woopayments_order( '2.75' );
		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 2.75,
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$note_id = $order->add_order_note( $note );

		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_COMPLETED,
				're_malformed_structure',
				'',
				'',
				'',
				array(
					'refund_note'                   => $note,
					'refund_note_identity'          => $identity,
					'refund_note_equivalents'       => array( $note ),
					'refund_note_identity_meta_key' => $identity_meta_key,
				)
			)
		);

		$result = $this->sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 2.75 ), $provider );

		$this->assertTrue( $result );
		$this->assertCount( 1, array_filter( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), static fn( $order_note ): bool => $note === $order_note->content ) );
		$this->assertSame( array(), get_comment_meta( $note_id ) );
	}

	/**
	 * Provide malformed structural refund-note values.
	 *
	 * @return array<string,array{mixed,mixed}>
	 */
	public function malformed_refund_note_identity_data(): array {
		return array(
			'empty identity'             => array( '', '_test_provider_note_identity' ),
			'array identity'             => array( array( 'refund:re_malformed_structure:created_successful' ), '_test_provider_note_identity' ),
			'scalar non-string identity' => array( 42, '_test_provider_note_identity' ),
			'empty meta key'             => array( 'refund:re_malformed_structure:created_successful', '' ),
			'array meta key'             => array( 'refund:re_malformed_structure:created_successful', array( '_arbitrary_comment_meta' ) ),
			'scalar non-string meta key' => array( 'refund:re_malformed_structure:created_successful', true ),
		);
	}

	/**
	 * @testdox Missing or malformed refund-note equivalents retain exact-content fallback without adding identity metadata.
	 * @dataProvider malformed_refund_note_equivalents_data
	 *
	 * @param bool  $include_equivalents Whether to include the equivalents field.
	 * @param mixed $equivalent_notes    Equivalent-note field value.
	 */
	public function test_process_refund_falls_back_when_equivalent_notes_are_absent_or_malformed( bool $include_equivalents, $equivalent_notes ): void {
		$note        = 'Existing exact partial-structure refund note. (<code>re_partial_structure</code>)';
		$order       = $this->create_woopayments_order( '2.25' );
		$refund      = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 2.25,
				'refund_payment' => false,
			)
		);
		$effect_data = array(
			PaymentOutcome::DATA_REFUND_NOTE          => $note,
			PaymentOutcome::DATA_REFUND_NOTE_IDENTITY => 'refund:re_partial_structure:created_successful',
			PaymentOutcome::DATA_REFUND_NOTE_IDENTITY_META_KEY => '_test_provider_note_identity',
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$note_id = $order->add_order_note( $note );

		if ( $include_equivalents ) {
			$effect_data[ PaymentOutcome::DATA_REFUND_NOTE_EQUIVALENTS ] = $equivalent_notes;
		}
		$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 're_partial_structure', '', '', '', $effect_data ) );

		$result = $this->sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 2.25 ), $provider );

		$this->assertTrue( $result );
		$this->assertCount( 1, array_filter( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), static fn( $order_note ): bool => $note === $order_note->content ) );
		$this->assertSame( array(), get_comment_meta( $note_id ) );
	}

	/**
	 * Provide absent and malformed refund-note equivalents.
	 *
	 * @return array<string,array{bool,mixed}>
	 */
	public function malformed_refund_note_equivalents_data(): array {
		return array(
			'absent equivalents'    => array( false, null ),
			'non-array equivalents' => array( true, 'not-an-array' ),
		);
	}

	/**
	 * @testdox A legacy refund-note-only outcome retains exact-content deduplication without adding identity metadata.
	 */
	public function test_process_refund_legacy_note_only_outcome_retains_exact_content_deduplication(): void {
		$note   = 'Legacy exact refund note. (<code>re_legacy_note</code>)';
		$order  = $this->create_woopayments_order( '1.50' );
		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => 1.50,
				'refund_payment' => false,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$note_id  = $order->add_order_note( $note );
		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_COMPLETED,
				're_legacy_note',
				'',
				'',
				'',
				array( PaymentOutcome::DATA_REFUND_NOTE => $note )
			)
		);

		$result = $this->sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 1.50 ), $provider );

		$this->assertTrue( $result );
		$this->assertCount( 1, array_filter( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), static fn( $order_note ): bool => $note === $order_note->content ) );
		$this->assertSame( array(), get_comment_meta( $note_id ) );
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

		remove_all_filters( 'woocommerce_logging_class' );

		$this->assertTrue( $result, 'Provider success must remain successful while the local failure is made reconcilable.' );
		$this->assertCount( 1, $fake_logger->error_calls, 'The invalid post-provider target must emit one reconciliation error log.' );
		$context = $fake_logger->error_calls[0]['context'];
		$this->assertSame( 'refund', $context['operation'] );
		$this->assertSame( 're_missing_target', $context['payment_reference'] );
		$this->assertFalse( $context['reconciliation_persisted'] );
	}

	/**
	 * @testdox A post-charge apply failure is logged with its class and code, never its message.
	 */
	public function test_post_charge_apply_failure_log_leaves_out_the_failure_message(): void {
		$order     = $this->create_woopayments_order( '10.00' );
		$lifecycle = new class() extends OrderPaymentLifecycleService {
			/**
			 * Throw a provider's platform text, as a provider effect that calls its platform can.
			 *
			 * @param WC_Order                               $order               Order object.
			 * @param PaymentLifecycleEvent                  $event               Lifecycle event.
			 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
			 * @throws RuntimeException Always.
			 */
			public function apply_unlocked( WC_Order $order, PaymentLifecycleEvent $event, ProviderPersistenceVocabularyInterface $persistence_vocabulary ): void {
				unset( $order, $event, $persistence_vocabulary );
				throw new RuntimeException( 'No such customer: shopper@example.com, see https://pay.example.test/r?key=sk_test_leak123', 9 );
			}
		};
		$lifecycle->init( $this->store );
		$sut         = $this->build_sut_with_lifecycle( $lifecycle );
		$provider    = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_apply_failure', '', 'pm_apply_failure' ) );
		$fake_logger = $this->create_fake_logger();
		add_filter(
			'woocommerce_logging_class',
			function () use ( $fake_logger ) {
				return $fake_logger;
			}
		);

		$this->expect_outcome_apply_exception(
			static fn() => $sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_apply_failure' ), $provider )
		);

		remove_all_filters( 'woocommerce_logging_class' );

		$apply_logs = array_values(
			array_filter(
				$fake_logger->error_calls,
				static fn( array $call ): bool => 0 === strpos( $call['message'], 'Payment provider operation returned a reconcilable outcome' )
			)
		);
		$this->assertCount( 1, $apply_logs );
		$this->assertSame( RuntimeException::class, $apply_logs[0]['context']['exception_class'] );
		$this->assertSame( 9, $apply_logs[0]['context']['exception_code'] );
		$this->assertSame( 'pi_apply_failure', $apply_logs[0]['context']['payment_reference'] );
		$written = (string) wp_json_encode( $fake_logger->error_calls );
		foreach ( array( 'No such customer', 'shopper@example.com', 'pay.example.test', 'sk_test_leak123' ) as $provider_text ) {
			$this->assertStringNotContainsString( $provider_text, $written );
		}
	}

	/**
	 * @testdox A post-charge apply failure on an order that already has a different transaction ID logs both IDs.
	 */
	public function test_process_checkout_outcome_logs_a_transaction_id_mismatch_when_apply_throws(): void {
		$order = $this->create_woopayments_order( '10.00' );
		$order->set_transaction_id( 'pi_first_payment' );
		$order->save();

		$sut         = $this->build_sut_with_lifecycle( $this->create_throwing_lifecycle_service() );
		$provider    = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_second_payment', '', 'pm_second_payment' ) );
		$fake_logger = $this->create_fake_logger();
		add_filter(
			'woocommerce_logging_class',
			function () use ( $fake_logger ) {
				return $fake_logger;
			}
		);

		$this->expect_outcome_apply_exception(
			static fn() => $sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_second_payment' ), $provider )
		);

		remove_all_filters( 'woocommerce_logging_class' );

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
				'payment_reference'       => 'pi_second_payment',
				'existing_transaction_id' => 'pi_first_payment',
			),
			$mismatch_logs[0]['context']
		);
	}

	/**
	 * @testdox Should preserve provider refund error codes.
	 */
	public function test_process_refund_preserves_provider_error_code(): void {
		$order    = $this->create_woopayments_order( '10.00' );
		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_FAILED,
				'',
				'',
				'',
				'',
				array(
					'error_code'    => 'uncaptured-payment',
					'error_message' => 'This payment is not captured yet.',
				)
			)
		);
		$this->create_local_refund( $order, 2.50, 'Adjustment' );

		$result = $this->sut->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 2.50, 'Adjustment' ), $provider );

		$this->assertWPError( $result );
		$this->assertSame( 'uncaptured-payment', $result->get_error_code() );
		$this->assertSame( 'This payment is not captured yet.', $result->get_error_message() );
	}

	/**
	 * @testdox Capture and cancel should use the shared order claim.
	 */
	public function test_capture_and_cancel_use_shared_order_claim(): void {
		$order = $this->create_woopayments_order( '10.00' );
		$this->hold_order_payment_lock( $order, $this->persistence_vocabulary, 'pi_existing' );

		$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_test' ) );

		$capture_outcome = $this->sut->capture( PaymentOperationContext::for_capture( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), $provider );
		$cancel_outcome  = $this->sut->cancel( PaymentOperationContext::for_cancel( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), $provider );

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $capture_outcome->get_status() );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $cancel_outcome->get_status() );
		$this->assertSame( 0, $provider->capture_calls );
		$this->assertSame( 0, $provider->cancel_calls );

		$this->clear_order_payment_lock( $order, $this->persistence_vocabulary );
	}

	/**
	 * @testdox A refund refused by the order payment lock should log one warning naming the holder; a free lock logs nothing.
	 *
	 * WooPayments 11.1.0 takes no lock on refunds (WC_Payments_Utils::is_order_locked() guards only
	 * intent-driven status updates), so this refusal is native-only and must be visible in the logs.
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
	 * @testdox Failed captures should leave authorized orders on hold.
	 */
	public function test_capture_failure_preserves_authorized_order_status(): void {
		$order = $this->create_woopayments_order( '10.00' );
		$order->set_transaction_id( 'pi_capture' );
		$order->update_meta_data( '_intent_id', 'pi_capture' );
		$order->update_meta_data( '_intention_status', 'requires_capture' );
		$order->save();
		$order->update_status( 'on-hold' );

		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_FAILED,
				'pi_capture',
				'',
				'',
				'',
				array( 'note' => 'Capture failed note.' )
			)
		);

		$outcome = $this->sut->capture( PaymentOperationContext::for_capture( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), $provider );
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 1, $provider->capture_calls );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( 'requires_capture', $order->get_meta( '_intention_status', true ) );
		$this->assertOrderHasNoteContaining( $order, 'Capture failed note.' );
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
					PaymentOutcome::DATA_META      => array( '_intention_status' => 'canceled' ),
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
	 * @testdox Failed authorization cancellations should preserve the order status and authorization state.
	 */
	public function test_cancel_failure_preserves_authorized_order_status(): void {
		$order = $this->create_woopayments_order( '10.00' );
		$order->set_transaction_id( 'pi_cancel' );
		$order->update_meta_data( '_intent_id', 'pi_cancel' );
		$order->update_meta_data( '_intention_status', 'requires_capture' );
		$order->update_meta_data( '_wcpay_transaction_fee', '0.65' );
		$order->update_meta_data( '_wcpay_net', '9.35' );
		$order->save();
		$order->update_status( 'on-hold' );

		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_FAILED,
				'pi_cancel',
				'',
				'',
				'',
				array( 'note' => 'Cancellation failed note.' )
			)
		);

		$outcome = $this->sut->cancel( PaymentOperationContext::for_cancel( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID ), $provider );
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 1, $provider->cancel_calls );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( 'requires_capture', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( '0.65', $order->get_meta( '_wcpay_transaction_fee', true ) );
		$this->assertSame( '9.35', $order->get_meta( '_wcpay_net', true ) );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_META_TO_DELETE, $outcome->get_data() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE_TYPE, $outcome->get_data() );
		$this->assertOrderHasNoteContaining( $order, 'Cancellation failed note.' );
	}

	/**
	 * @testdox Should support non-Stripe redirect providers through neutral outcomes.
	 */
	public function test_process_checkout_supports_non_stripe_redirect_provider(): void {
		$order    = $this->create_woopayments_order( '12.00' );
		$provider = new NonStripeProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_REQUIRES_REDIRECT,
				'remote_payment_123',
				'https://offline-provider.example/pay/123'
			)
		);

		$outcome = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, 'offline_redirect_provider' ), $provider );
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_REQUIRES_REDIRECT, $outcome->get_status() );
		$this->assertSame( 'remote_payment_123', $outcome->get_provider_payment_id() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_CHECKOUT_REDIRECT, $outcome->get_data() );
		$this->assertSame( 'https://offline-provider.example/pay/123', $outcome->get_redirect_url() );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( 'remote_payment_123', $order->get_meta( '_intent_id', true ) );
	}

	/**
	 * @testdox Should support non-Stripe asynchronous providers without card data.
	 */
	public function test_process_checkout_supports_non_stripe_pending_async_provider(): void {
		$order    = $this->create_woopayments_order( '12.00' );
		$provider = new NonStripeProvider( new PaymentOutcome( PaymentOutcome::STATUS_PENDING_ASYNC, 'remote_pending_123' ) );

		$outcome = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, 'offline_redirect_provider' ), $provider );
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_PENDING_ASYNC, $outcome->get_status() );
		$this->assertSame( 'remote_pending_123', $outcome->get_provider_payment_id() );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( 'processing', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( '', $order->get_meta( '_payment_method_id', true ) );
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
					__( 'Payment authorization was successfully <strong>cancelled</strong> (<a>%1$s</a>).', 'woocommerce-payments' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- The fixture emulates the legacy plugin catalog.
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
	 * @testdox Should support zero-total checkout without a provider-specific payment operation.
	 */
	public function test_process_checkout_supports_non_stripe_zero_total_provider_without_charge(): void {
		$order    = $this->create_woopayments_order( '0.00' );
		$provider = new NonStripeProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'remote_should_not_be_used' ) );

		$outcome = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, 'offline_redirect_provider' ), $provider );

		$this->assertSame( PaymentOutcome::STATUS_NO_EXTERNAL_PAYMENT, $outcome->get_status() );
		$this->assertSame( '', $outcome->get_provider_payment_id() );
		$this->assertSame( 0, $provider->charge_calls );
	}

	/**
	 * @testdox Should call setup-capable providers for zero-total checkout when a payment credential is present.
	 */
	public function test_process_checkout_calls_setup_capable_provider_for_zero_total_checkout_with_credential(): void {
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

		$outcome = $this->sut->process_checkout_outcome( PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'pm_zero' ), $provider );
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'seti_zero', $outcome->get_provider_payment_id() );
		$this->assertSame( 1, $provider->charge_calls );
		$this->assertSame( 'seti_zero', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'pm_zero', $order->get_meta( '_payment_method_id', true ) );
	}

	/**
	 * @testdox Should call setup-capable providers for zero-total checkout when a saved payment token is present.
	 */
	public function test_process_checkout_calls_setup_capable_provider_for_zero_total_checkout_with_saved_token(): void {
		$order    = $this->create_woopayments_order( '0.00' );
		$provider = new RecordingProvider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_COMPLETED,
				'seti_saved',
				'',
				'pm_saved',
				'cus_saved',
				array(
					'meta' => array(
						'_intention_status' => 'succeeded',
					),
				)
			),
			true
		);

		$outcome = $this->sut->process_checkout_outcome(
			PaymentOperationContext::for_checkout(
				$order,
				WooPaymentsPersistenceVocabulary::GATEWAY_ID,
				'',
				array( 'payment_token' => '123' )
			),
			$provider
		);
		$order   = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'seti_saved', $outcome->get_provider_payment_id() );
		$this->assertSame( 1, $provider->charge_calls );
		$this->assertSame( 'seti_saved', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'pm_saved', $order->get_meta( '_payment_method_id', true ) );
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
		add_filter(
			'woocommerce_woopayments_related_subscriptions_for_order',
			static function ( array $subscriptions, WC_Order $filtered_order ) use ( $order, $subscription ): array {
				return $order->get_id() === $filtered_order->get_id() ? array( $subscription ) : $subscriptions;
			},
			10,
			2
		);
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

		try {
			$this->sut->process_checkout_outcome( $context, $provider );
		} finally {
			remove_action( 'woocommerce_order_status_completed', $status_observer, 1 );
			remove_action( 'woocommerce_payment_complete', $payment_complete_observer, 1 );
			remove_all_filters( 'woocommerce_woopayments_related_subscriptions_for_order' );
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
		$this->assertSame( $use_saved_token ? '' : 'succeeded', $order->get_meta( '_intention_status', true ), 'No intent, no intention status (V615: client order 51 has none).' );
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
	 * Scheduled contexts use the same saved-token marker and order-attached token routing as native renewals.
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
		$token_service->init( $details_service, new StaticWooPaymentsRuntimeArbiter( true ), wc_get_container()->get( WooPaymentsApiClient::class ), wc_get_container()->get( WooPaymentsCustomerService::class ), wc_get_container()->get( WooPaymentsAccountService::class ) );
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_account_country', 'get_mode' ) )
			->getMock();
		$account_service->method( 'get_account_country' )->willReturn( 'US' );
		$account_service->method( 'get_mode' )->willReturn( 'test' );
		$legacy_runtime = $this->getMockBuilder( WooPaymentsLegacyRuntime::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_logger' ) )
			->getMock();
		$legacy_runtime->method( 'get_logger' )->willReturn( null );
		$effect_applier = new WooPaymentsOrderEffectApplier();
		$effect_applier->init(
			$token_service,
			new WooPaymentsOrderDataService(),
			$account_service,
			new WooPaymentsOrderNoteService(),
			new WooPaymentsPaymentMethodRegistry()
		);
		$api_client       = new class() extends \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient {
			/**
			 * Tell the adapter to use its native SetupIntent transport.
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
		$ambiguity_service->init( $api_client );
		$adapter = new WooPaymentsProviderGatewayAdapter();
		$adapter->init(
			$legacy_runtime,
			$api_client,
			$customer_service,
			wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentRequestBuilder::class ),
			$account_service,
			new WooPaymentsOrderDataService(),
			new WooPaymentsOrderNoteService(),
			wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSettingsService::class ),
			$ambiguity_service
		);
		$provider = new WooPaymentsProvider();
		$provider->init(
			$adapter,
			$api_client,
			$account_service,
			null,
			$effect_applier
		);

		return $provider;
	}

	/**
	 * Build a real WooPayments provider that returns a held-for-review PaymentIntent response.
	 *
	 * The adapter isolates only its remote transport; the production provider, effect applier, and payment lifecycle remain in use.
	 *
	 * @param array<string,string> $ruleset_results Fired fraud-rule results.
	 * @return WooPaymentsProvider
	 */
	private function review_payment_intent_provider( array $ruleset_results ): WooPaymentsProvider {
		$adapter  = new class( $ruleset_results ) extends WooPaymentsProviderGatewayAdapter {
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
		$provider = new WooPaymentsProvider();
		$provider->init(
			$adapter,
			wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient::class ),
			wc_get_container()->get( WooPaymentsAccountService::class ),
			null,
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
		$api_client      = new class( $sent_keys ) extends WooPaymentsApiClient {
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
			 * Use the native transport.
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
			 * @throws WooPaymentsApiException On the first charge.
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				unset( $request_data );
				$this->sent_keys[] = $idempotency_key;
				if ( 1 === count( $this->sent_keys ) ) {
					throw new WooPaymentsApiException( 'Error: Upstream provider unavailable.', 'api_connection_error', 502, 'api_error' );
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
		$legacy_runtime = $this->getMockBuilder( WooPaymentsLegacyRuntime::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_logger' ) )
			->getMock();
		$legacy_runtime->method( 'get_logger' )->willReturn( null );
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_ambiguous' );
		$ambiguity_service = new \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsChargeAmbiguityService();
		$ambiguity_service->init( $api_client );
		$adapter = new WooPaymentsProviderGatewayAdapter();
		$adapter->init(
			$legacy_runtime,
			$api_client,
			$customer_service,
			wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentRequestBuilder::class ),
			$account_service,
			new WooPaymentsOrderDataService(),
			new WooPaymentsOrderNoteService(),
			wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSettingsService::class ),
			$ambiguity_service
		);
		$provider = new WooPaymentsProvider();
		$provider->init(
			$adapter,
			$api_client,
			$account_service,
			null,
			wc_get_container()->get( WooPaymentsOrderEffectApplier::class )
		);

		return $provider;
	}

	/**
	 * Build a real WooPayments provider on the real API client, with only the platform's HTTP answers faked.
	 *
	 * @param FakeWooPaymentsHttpClient $http_client Platform answers, in order.
	 * @return WooPaymentsProvider
	 */
	private function timeout_transport_provider( FakeWooPaymentsHttpClient $http_client ): WooPaymentsProvider {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_account_country', 'get_mode', 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->method( 'get_account_country' )->willReturn( 'US' );
		$account_service->method( 'get_mode' )->willReturn( 'test' );
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );
		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Skip the backoff between transport retries.
			 *
			 * @param int $backoff_microseconds Backoff.
			 */
			protected function sleep_before_retry( int $backoff_microseconds ): void {
				unset( $backoff_microseconds );
			}
		};
		$api_client->init( $http_client, $account_service );
		$legacy_runtime = $this->getMockBuilder( WooPaymentsLegacyRuntime::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_logger' ) )
			->getMock();
		$legacy_runtime->method( 'get_logger' )->willReturn( null );
		$customer_service = $this->getMockBuilder( WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_order' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_order' )->willReturn( 'cus_timeout' );
		$ambiguity_service = new \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsChargeAmbiguityService();
		$ambiguity_service->init( $api_client );
		$adapter = new WooPaymentsProviderGatewayAdapter();
		$adapter->init(
			$legacy_runtime,
			$api_client,
			$customer_service,
			wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentRequestBuilder::class ),
			$account_service,
			new WooPaymentsOrderDataService(),
			new WooPaymentsOrderNoteService(),
			wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSettingsService::class ),
			$ambiguity_service
		);
		$provider = new WooPaymentsProvider();
		$provider->init(
			$adapter,
			$api_client,
			$account_service,
			null,
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
		$adapter  = new class( $result, $sequence ) extends WooPaymentsProviderGatewayAdapter {
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

				return ( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_lifecycle_title', '', 'pm_lifecycle_title' ) )->with_effect_plan( WooPaymentsOrderEffectPlan::for_payment_intent( $this->result, $is_recurring ) );
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
		$provider = new WooPaymentsProvider();
		$provider->init(
			$adapter,
			wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient::class ),
			wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService::class ),
			null,
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
	 * response in Providers/WooPayments/Fixtures/rec-t3-basic-card.json (entries[0].response.body); the Link wallet type
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
			$this->store,
			$lifecycle_service
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
			 * Always throw to simulate a lifecycle application failure after a successful charge.
			 *
			 * @param WC_Order                               $order               Order object.
			 * @param PaymentLifecycleEvent                  $event               Lifecycle event.
			 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
			 * @throws RuntimeException Always, to drive the post-charge failure path.
			 */
			public function apply_unlocked( WC_Order $order, PaymentLifecycleEvent $event, ProviderPersistenceVocabularyInterface $persistence_vocabulary ): void {
				// Avoid parameter not used PHPCS errors.
				unset( $order, $event, $persistence_vocabulary );
				throw new RuntimeException( 'Simulated lifecycle failure after a successful charge.' );
			}
		};
		$lifecycle->init( $this->store );

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

	/**
	 * Create a fake WC logger whose error method throws.
	 *
	 * @return object Throwing fake logger.
	 */
	private function create_throwing_logger(): object {
		$logger = $this->create_fake_logger();

		// phpcs:disable Squiz.Commenting, Squiz.Classes.ClassFileName.NoMatch
		return new class( $logger ) implements \WC_Logger_Interface {
			private object $logger;

			public function __construct( object $logger ) {
				$this->logger = $logger;
			}

			public function add( $handle, $message, $level = \WC_Log_Levels::NOTICE ) {
				return $this->logger->add( $handle, $message, $level );
			}

			public function log( $level, $message, $context = array() ) {
				$this->logger->log( $level, $message, $context );
			}

			public function emergency( $message, $context = array() ) {
				$this->logger->emergency( $message, $context );
			}

			public function alert( $message, $context = array() ) {
				$this->logger->alert( $message, $context );
			}

			public function critical( $message, $context = array() ) {
				$this->logger->critical( $message, $context );
			}

			public function notice( $message, $context = array() ) {
				$this->logger->notice( $message, $context );
			}

			public function debug( $message, $context = array() ) {
				$this->logger->debug( $message, $context );
			}

			public function info( $message, $context = array() ) {
				$this->logger->info( $message, $context );
			}

			public function warning( $message, $context = array() ) {
				$this->logger->warning( $message, $context );
			}

			public function error( $message, $context = array() ) {
				unset( $message, $context );
				throw new RuntimeException( 'Recovery logger failed.' );
			}
		};
		// phpcs:enable Squiz.Commenting, Squiz.Classes.ClassFileName.NoMatch
	}
}
