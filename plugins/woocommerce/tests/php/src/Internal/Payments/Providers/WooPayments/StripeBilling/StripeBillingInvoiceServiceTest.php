<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\TransportLog;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingApi;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingInvoiceService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingProductService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingSubscriptionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\SubscriptionsController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeHttpClient;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\SubscriptionDouble;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use PHPUnit\Framework\MockObject\MockObject;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Stripe Billing invoices on orders and subscriptions.
 *
 * Expectations follow client 11.1.0 (`tests/unit/subscriptions/test-class-wc-payments-invoice-service.php` and
 * `includes/subscriptions/class-wc-payments-invoice-service.php`); platform answers are the local platform recordings
 * in `Fixtures/platform-stripe-billing-api.json` and `Fixtures/platform-invoice-events.json`. Request bodies are written from the
 * client's array literals, with `test_mode` first as the client's `request()` adds it (`class-wc-payments-api-client.php:2635-2640`).
 */
class StripeBillingInvoiceServiceTest extends WC_Unit_Test_Case {

	private const BILLING_FIXTURE = __DIR__ . '/../Fixtures/platform-stripe-billing-api.json';
	private const EVENTS_FIXTURE  = __DIR__ . '/../Fixtures/platform-invoice-events.json';

	/**
	 * Blog and account the recordings were made on.
	 */
	private const RECORDED_BLOG_ID    = 4;
	private const RECORDED_ACCOUNT_ID = 'acct_1TrY2nBzWlxcwgpP';

	/**
	 * Stripe objects of the recorded main chain: the subscription and the parent invoice `charge_invoice` paid out of band.
	 */
	private const MAIN_SUBSCRIPTION_ID = 'sub_1UM1VrBzWlxcwgpP6A3GwGLe';
	private const MAIN_INVOICE_ID      = 'in_1UM1VrBzWlxcwgpPgrIwNSlu';

	/**
	 * Stripe objects of the recorded test-clock chain, whose invoice events carry this subscription item.
	 */
	private const CLOCK_SUBSCRIPTION_ID = 'sub_1UM1XLBzWlxcwgpPwEbcmZJt';
	private const CLOCK_ITEM_ID         = 'si_VMl3Lmi1aDKj2h';

	/**
	 * The Stripe product the recorded subscription items are billed under.
	 */
	private const RECORDED_PRODUCT_ID = 'prod_VMl10VL0VK371N';

	/**
	 * The System Under Test.
	 *
	 * @var StripeBillingInvoiceService
	 */
	private $sut;

	/**
	 * Fake platform transport.
	 *
	 * @var FakeHttpClient
	 */
	private FakeHttpClient $http_client;

	/**
	 * Whether the Stripe Billing toggle is on.
	 *
	 * @var bool
	 */
	private bool $stripe_billing_enabled = true;

	/** @var bool Current store payment mode. */
	private bool $test_mode = true;

	/** @var bool Whether a migration is in progress. */
	private bool $migrating = false;

	/** @var StripeBillingApi The platform client used by the service. */
	private StripeBillingApi $api;

	/**
	 * Subscriptions controller stand-in that records the payment method copied to a subscription.
	 *
	 * @var SubscriptionsController&MockObject
	 */
	private $subscriptions_controller;

	/**
	 * Set up the service over a fake transport, connected to the recorded account in test mode.
	 */
	public function setUp(): void {
		parent::setUp();
		WooCommerceSubscriptionsDoubles::load();
		WooCommerceSubscriptionsDoubles::load_renewal_detector();

		$this->http_client          = new FakeHttpClient();
		$this->http_client->blog_id = self::RECORDED_BLOG_ID;

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'is_test_mode_onboarding_enabled', 'get_account_id', 'is_dev_mode_enabled', 'get_gateway_setting' ) )
			->getMock();
		$account_service->method( 'is_test_mode_enabled' )->willReturnCallback( fn() => $this->test_mode );
		$account_service->method( 'is_test_mode_onboarding_enabled' )->willReturn( true );
		$account_service->method( 'get_account_id' )->willReturn( self::RECORDED_ACCOUNT_ID );

		$api_client = new Client();
		$api_client->init( $this->http_client, $account_service, wc_get_container()->get( TransportLog::class ) );
		$api = new StripeBillingApi();
		$api->init( $api_client );
		$this->api = $api;
		$logger    = new WooPaymentsLogger();
		$logger->init( $account_service );

		$product_service = new StripeBillingProductService();
		$product_service->init( $api, $account_service, $logger );

		$module = $this->createMock( StripeBillingModule::class );
		$module->method( 'is_migrating' )->willReturnCallback( fn() => $this->migrating );
		$module->method( 'is_stripe_billing_enabled' )->willReturnCallback( fn() => $this->stripe_billing_enabled );

		$this->subscriptions_controller = $this->getMockBuilder( SubscriptionsController::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update_failing_payment_method' ) )
			->getMock();
		wc_get_container()->replace( SubscriptionsController::class, $this->subscriptions_controller );

		$this->sut = new StripeBillingInvoiceService();
		$this->sut->init( $api, $api_client, $module, $logger );

		$subscription_service = new StripeBillingSubscriptionService();
		$subscription_service->init( $api, $this->createMock( WooPaymentsCustomerService::class ), $product_service, $this->sut, $logger, wc_get_container()->get( WooPaymentsActionSchedulerService::class ), $account_service );
		wc_get_container()->replace( StripeBillingSubscriptionService::class, $subscription_service );
	}

	/**
	 * Clear the container replacements and the registries the doubles read.
	 */
	public function tearDown(): void {
		try {
			$this->reset_container_replacements();
			unset(
				$GLOBALS['wcpay_test_renewal_order_ids'],
				$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ],
				$GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ],
				$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_PRODUCT_IDS ]
			);
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Paying the parent order marks the subscription's invoice paid out of band, without charging (client test_maybe_record_invoice_payment, `class-wc-payments-invoice-service.php:207`).
	 */
	public function test_marks_the_invoice_paid_out_of_band_when_the_parent_order_is_paid(): void {
		$this->queue_recorded_responses( 'charge_invoice' );
		$order        = \WC_Helper_Order::create_order();
		$subscription = $this->create_stripe_billed_subscription( $order, 'parent' );
		$this->subscriptions_controller->expects( $this->never() )->method( 'update_failing_payment_method' );

		$this->sut->maybe_record_invoice_payment( $order->get_id() );

		$this->assertSame(
			array(
				array(
					'POST',
					'/sites/4/wcpay/invoices/' . self::MAIN_INVOICE_ID . '/pay',
					array(
						'test_mode'        => true,
						'paid_out_of_band' => 'true',
					),
				),
			),
			$this->get_requests()
		);
		$this->assertFalse( wc_get_order( $subscription->get_id() )->is_manual(), 'An automatic subscription stays automatic.' );
	}

	/**
	 * @testdox The invoice is marked paid only for a parent or renewal order of a Stripe-billed subscription whose order has no invoice yet (client maybe_record_invoice_payment).
	 * @testWith ["renewal", "woocommerce_payments", "sub_1UM1VrBzWlxcwgpP6A3GwGLe", "in_1UM1VrBzWlxcwgpPgrIwNSlu", "", 1]
	 *           ["switch", "woocommerce_payments", "sub_1UM1VrBzWlxcwgpP6A3GwGLe", "in_1UM1VrBzWlxcwgpPgrIwNSlu", "", 0]
	 *           ["parent", "woocommerce_payments", "sub_1UM1VrBzWlxcwgpP6A3GwGLe", "in_1UM1VrBzWlxcwgpPgrIwNSlu", "in_1UM1VrBzWlxcwgpPgrIwNSlu", 0]
	 *           ["parent", "woocommerce_payments_sepa_debit", "sub_1UM1VrBzWlxcwgpP6A3GwGLe", "in_1UM1VrBzWlxcwgpPgrIwNSlu", "", 0]
	 *           ["parent", "woocommerce_payments", "", "in_1UM1VrBzWlxcwgpPgrIwNSlu", "", 0]
	 *           ["parent", "woocommerce_payments", "sub_1UM1VrBzWlxcwgpP6A3GwGLe", "", "", 0]
	 *
	 * @param string $relation                How the order relates to the subscription.
	 * @param string $payment_method          Subscription payment method.
	 * @param string $wcpay_subscription_id   Subscription's Stripe subscription ID.
	 * @param string $subscription_invoice_id Subscription's invoice ID.
	 * @param string $order_invoice_id        Order's invoice ID.
	 * @param int    $expected_requests       Platform requests sent.
	 */
	public function test_marks_only_invoices_of_stripe_billed_subscriptions( string $relation, string $payment_method, string $wcpay_subscription_id, string $subscription_invoice_id, string $order_invoice_id, int $expected_requests ): void {
		$this->queue_recorded_responses( 'charge_invoice' );
		$order = \WC_Helper_Order::create_order();
		$order->update_meta_data( '_wcpay_billing_invoice_id', $order_invoice_id );
		$order->save();
		$this->create_stripe_billed_subscription(
			$order,
			$relation,
			array(
				'_wcpay_subscription_id'    => $wcpay_subscription_id,
				'_wcpay_billing_invoice_id' => $subscription_invoice_id,
			),
			$payment_method
		);

		$this->sut->maybe_record_invoice_payment( $order->get_id() );

		$this->assertSame( $expected_requests, $this->http_client->request_count );
	}

	/**
	 * @testdox Nothing is sent for an unknown order or an order without subscriptions (client test_maybe_record_invoice_payment negative cases).
	 */
	public function test_sends_nothing_for_an_unknown_order_or_one_without_subscriptions(): void {
		$order = \WC_Helper_Order::create_order();

		$this->sut->maybe_record_invoice_payment( 0 );
		$this->sut->maybe_record_invoice_payment( $order->get_id() );

		$this->assertSame( 0, $this->http_client->request_count );
	}

	private const FIRST_INVOICE_HOOK = 'woocommerce_woopayments_retry_stripe_billing_first_invoice';
	private const FAILURE_NOTE       = "WooPayments could not tell Stripe that this subscription's first payment was received, so Stripe stopped billing it and it will not renew. The customer's first payment is not affected. To keep billing this customer, ask them to subscribe again, then cancel this subscription. Stripe subscription ID: " . self::MAIN_SUBSCRIPTION_ID . '.';

	/**
	 * @testdox An inline invoice failure leaves the order paid and schedules the next step without a merchant note.
	 * @testWith ["F1", 400, false]
	 *           ["A1", 503, true]
	 *           ["A1-transport", 0, true]
	 *           ["A2", 429, true]
	 *           ["A10", 503, true]
	 *           ["N1", 400, false]
	 *           ["E1", 200, false]
	 *           ["resumed", 200, false]
	 *           ["unauthorized", 401, true]
	 *           ["forbidden", 403, true]
	 *           ["resumed retry", 503, true]
	 *           ["disconnected", 409, true]
	 *           ["old inline", 503, true]
	 *
	 * @param string $row   Sheet scenario.
	 * @param int    $code  Platform response, or zero for a transport error.
	 * @param bool   $retry Whether one later attempt is due.
	 */
	public function test_first_invoice_at_payment( string $row, int $code, bool $retry ): void {
		$order   = \WC_Helper_Order::create_order();
		$resumed = in_array( $row, array( 'resumed', 'resumed retry', 'old inline' ), true );
		$renewal = 'A10' === $row;
		$age     = $resumed ? 25 * HOUR_IN_SECONDS : 0;
		$order->set_status( $resumed ? 'failed' : 'pending' );
		$order->set_date_created( time() - ( $renewal ? 3 * DAY_IN_SECONDS : $age ) );
		$order->save();
		$subscription = $this->create_stripe_billed_subscription( $order, $renewal ? 'renewal' : 'parent' );
		if ( 'old inline' === $row || $renewal ) {
			$subscription->set_date_created( $order->get_date_created() );
			$subscription->save();
		}
		if ( 'disconnected' === $row ) {
			$this->http_client->connected = false;
		}
		if ( 'E1' === $row ) {
			$subscription->update_status( 'pending-cancel' );
		}
		if ( 'N1' === $row ) {
			$second = $this->create_stripe_billed_subscription( $order, 'parent', array( '_wcpay_billing_invoice_id' => 'in_second' ) );
		}
		if ( $renewal ) {
			$GLOBALS['wcpay_test_renewal_order_ids'][] = $order->get_id();
			add_action( 'woocommerce_renewal_order_payment_complete', array( $this->sut, 'maybe_record_invoice_payment' ), 11 );
		} else {
			add_action( 'woocommerce_order_payment_status_changed', array( $this->sut, 'maybe_record_invoice_payment' ) );
		}

		$this->queue_invoice_answer( $code, 'This invoice is void.' );
		if ( 'N1' === $row ) {
			$this->queue_recorded_responses( 'charge_invoice' );
		}
		$logged = array();
		add_filter(
			'woocommerce_logger_log_message',
			static function ( $message, $level, $context, $handler ) use ( &$logged, $subscription ) {
				if ( $subscription->get_id() === ( $context['subscription_id'] ?? null ) && isset( $context['attempt'] ) ) {
					$logged[ spl_object_id( $handler ) ][] = $context['attempt'];
				}
				return $message;
			},
			10,
			4
		);
		$start = time();
		$order->payment_complete();
		if ( $renewal ) {
			do_action( 'woocommerce_renewal_order_payment_complete', $order->get_id() );
		}
		$this->assertSame( 200 === $code, empty( $logged ) );
		foreach ( $logged as $attempts ) {
			$this->assertSame( array( 1 ), $attempts );
		}
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( array(), preg_grep( '/Error during status transition/', $this->get_order_note_texts( $order ) ) );
		$this->assertNotContains( self::FAILURE_NOTE, $this->get_order_note_texts( $subscription ) );
		if ( 0 === $code ) {
			$this->assertGreaterThan( 0, $this->http_client->request_count );
		} else {
			$this->assertSame( in_array( $row, array( 'E1', 'disconnected' ), true ) ? 0 : ( 'N1' === $row ? 2 : 1 ), $this->http_client->request_count );
		}
		if ( $resumed ) {
			$requests = $this->get_requests();
			$this->assertSame( 'POST', $requests[0][0] );
			$this->assertStringEndsWith( '/invoices/' . self::MAIN_INVOICE_ID . '/pay', $requests[0][1] );
		}
		if ( isset( $second ) ) {
			$this->assertStringEndsWith( '/in_second/pay', $this->http_client->last_path );
			$this->assertNotContains( self::FAILURE_NOTE, $this->get_order_note_texts( $second ) );
		}
		$window = 'old inline' === $row ? $start - $age : $start;
		if ( 200 !== $code ) {
			$this->take_verdict( $order, $subscription, max( $start, $window + 23 * HOUR_IN_SECONDS + 30 * MINUTE_IN_SECONDS ) );
		}
		$this->assert_invoice_schedule( $order, $subscription, $retry && 'old inline' !== $row ? 1 : null, $start + 60 );
	}

	/**
	 * @testdox A delayed first-invoice job uses the paying order's money, its bound invoice and its remaining time.
	 * @testWith ["M1", "requires_capture", "pi_first", 0, 0, 200, 0, null]
	 *           ["M2", "succeeded", "pi_first", 0, 0, 200, 1, null]
	 *           ["M3", "succeeded", "pi_first", 0, 0, 200, 0, null]
	 *           ["M4", "succeeded", "seti_first", 0, 0, 200, 0, null]
	 *           ["B1", "succeeded", "pi_first", 0, 0, 200, 0, null]
	 *           ["A4-1", "succeeded", "pi_first", 1, 0, 503, 1, 600]
	 *           ["A4-2", "succeeded", "pi_first", 2, 0, 503, 1, 3600]
	 *           ["A5", "succeeded", "pi_first", 3, 7200, 503, 1, 72000]
	 *           ["early-last", "succeeded", "pi_first", 2, 77400, 503, 1, 1800]
	 *           ["A6", "succeeded", "pi_first", 1, 82800, 200, 1, null]
	 *           ["A7", "succeeded", "pi_first", 4, 79200, 503, 1, null]
	 *           ["A8", "succeeded", "pi_first", 1, 0, 200, 1, null]
	 *           ["A9", "succeeded", "pi_first", 1, 0, 400, 1, null]
	 *           ["paid zero", "", "", 0, 0, 200, 1, null]
	 *           ["newer order", "succeeded", "pi_first", 3, 7200, 503, 1, 72000]
	 *
	 * @param string   $row      Sheet scenario.
	 * @param string   $status   Stored intent status.
	 * @param string   $intent   Stored intent ID.
	 * @param int      $retry    Attempt number.
	 * @param int      $age      Order age in seconds.
	 * @param int      $code     HTTP response.
	 * @param int      $requests Expected invoice requests.
	 * @param int|null $delay    Expected time to next job, or null.
	 */
	public function test_first_invoice_job( string $row, string $status, string $intent, int $retry, int $age, int $code, int $requests, ?int $delay ): void {
		$now   = time();
		$order = \WC_Helper_Order::create_order();
		$order->set_date_created( $now - $age );
		$order->set_status( 'completed' );
		$order->set_total( 'paid zero' === $row ? '0' : '10' );
		$order->update_meta_data( '_intention_status', $status );
		$order->update_meta_data( '_intent_id', $intent );
		$order->save();
		$subscription = $this->create_stripe_billed_subscription( $order, 'parent', array( '_requires_manual_renewal' => 'A8' === $row ? 'true' : 'false' ) );
		$subscription->set_date_created( $now - ( 'newer order' === $row ? DAY_IN_SECONDS : $age ) );
		$subscription->save();
		if ( 'M3' === $row ) {
			$refund_args = array(
				'order_id' => $order->get_id(),
				'amount'   => 10,
			);
			$refund      = wc_create_refund( $refund_args );
			$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		}
		$this->queue_invoice_answer( $code, 'Invoice is already paid.' );
		$this->subscriptions_controller->expects( 'A8' === $row ? $this->once() : $this->never() )->method( 'update_failing_payment_method' );

		$this->sut->handle_first_invoice_job( $order->get_id(), $subscription->get_id(), 'B1' === $row ? 'in_old' : self::MAIN_INVOICE_ID, $retry );
		$this->assertSame( $requests, $this->http_client->request_count );
		if ( $requests ) {
			$this->assertSame( array( 'POST' ), array_column( $this->get_requests(), 0 ) );
		}
		if ( 503 === $code ) {
			$this->take_verdict( $order, $subscription, $now - $age + 23 * HOUR_IN_SECONDS + 30 * MINUTE_IN_SECONDS );
		}
		$this->assertNotContains( self::FAILURE_NOTE, $this->get_order_note_texts( $subscription ) );
		if ( 'A8' === $row ) {
			$this->assertFalse( wc_get_order( $subscription->get_id() )->is_manual() );
		}
		$this->assert_invoice_schedule( $order, $subscription, null === $delay ? null : ( 'early-last' === $row ? 4 : $retry + 1 ), $now + (int) $delay );
	}

	/**
	 * @testdox A late job relies on Stripe's answer about the first invoice.
	 * @testWith ["active", 0, 0, 0]
	 *           ["incomplete_expired", 0, 1, 0]
	 *           ["canceled", 0, 1, 0]
	 *           ["incomplete", 1, 0, 0]
	 *           ["trialing", 0, 0, 0]
	 *           ["past_due", 0, 0, 0]
	 *           ["paused", 0, 0, 0]
	 *           ["", 0, 0, 1]
	 *           ["read failed", 0, 0, 1]
	 *           ["local cancellation during read", 0, 0, 0]
	 *
	 * @param string $state Stripe subscription status or a failed request.
	 * @param int    $pays  Expected pay requests.
	 * @param int    $notes Expected merchant notes.
	 * @param int    $logs  Expected warnings about the failed state check.
	 */
	public function test_first_invoice_end_state( string $state, int $pays, int $notes, int $logs ): void {
		$order = \WC_Helper_Order::create_order();
		$order->set_date_created( time() - DAY_IN_SECONDS );
		$order->update_meta_data( '_intention_status', 'succeeded' );
		$order->update_meta_data( '_intent_id', 'pi_late' );
		$order->save();
		$subscription = $this->create_stripe_billed_subscription( $order, 'parent', array( '_requires_manual_renewal' => 'true' ) );
		$subscription->set_date_created( $order->get_date_created() );
		$subscription->save();

		if ( 'local cancellation during read' === $state ) {
			add_filter(
				'wcpay_api_request_params',
				static function ( $params ) use ( $subscription ) {
					$subscription->update_status( 'cancelled' );
					return $params;
				}
			);
		}
		$answer                         = array(
			'status'         => 'local cancellation during read' === $state ? 'canceled' : $state,
			'latest_invoice' => self::MAIN_INVOICE_ID,
		);
		$this->http_client->responses[] = $this->make_response( 'read failed' === $state ? 403 : 200, $answer );
		$this->queue_recorded_responses( 'charge_invoice' );
		$warnings = array();
		$this->watch_verdict_warnings( $subscription, $warnings );

		$verdict = array(
			'final' => false,
			'read'  => 3,
		);
		$this->sut->handle_first_invoice_job( $order->get_id(), $subscription->get_id(), self::MAIN_INVOICE_ID, $verdict );

		if ( in_array( $state, array( 'active', 'trialing', 'past_due', 'paused' ), true ) ) {
			$this->assertFalse( wc_get_order( $subscription->get_id() )->is_manual() );
		}
		$requests = $this->get_requests();
		$this->assertCount( 1 + $pays, $requests );
		$this->assertSame( 'GET', $requests[0][0] );
		$this->assertStringContainsString( '/subscriptions/' . self::MAIN_SUBSCRIPTION_ID . '?', $requests[0][1] );
		if ( $pays ) {
			$this->assertSame( 'POST', $requests[1][0] );
			$this->assertStringEndsWith( '/invoices/' . self::MAIN_INVOICE_ID . '/pay', $requests[1][1] );
		}
		$this->assertSame( $notes, count( array_keys( $this->get_order_note_texts( $subscription ), self::FAILURE_NOTE, true ) ) );
		$this->assertSame( 0 === $logs, empty( $warnings ) );
		foreach ( $warnings as $messages ) {
			$this->assertCount( $logs, $messages );
		}
		$this->assert_invoice_schedule( $order, $subscription, null, 0 );
		if ( 'incomplete_expired' === $state ) {
			$this->http_client->responses = array( $this->make_response( 200, $answer ) );
			$this->sut->handle_first_invoice_job( $order->get_id(), $subscription->get_id(), self::MAIN_INVOICE_ID, $verdict );
			$this->assertSame( 1, count( array_keys( $this->get_order_note_texts( $subscription ), self::FAILURE_NOTE, true ) ) );
		}
	}

	/**
	 * @testdox An incomplete subscription permits one more pay. Even a lasting refusal leaves no merchant note.
	 * @testWith [401]
	 *           [400]
	 *
	 * @param int $code Response to the extra pay.
	 */
	public function test_last_first_invoice_attempt_still_open( int $code ): void {
		$order = \WC_Helper_Order::create_order();
		$order->set_date_created( time() - 22 * HOUR_IN_SECONDS );
		$order->update_meta_data( '_intention_status', 'succeeded' );
		$order->update_meta_data( '_intent_id', 'pi_last' );
		$order->save();
		$subscription = $this->create_stripe_billed_subscription( $order, 'parent' );
		$subscription->set_date_created( $order->get_date_created() );
		$subscription->save();
		$this->queue_invoice_answer( 400, 'Refused.' );
		$this->sut->maybe_record_invoice_payment( $order->get_id() );
		$args                           = $this->take_verdict( $order, $subscription, time() + 90 * MINUTE_IN_SECONDS );
		$this->http_client->responses[] = $this->make_response( 200, array( 'status' => 'incomplete' ) );
		$this->queue_invoice_answer( $code, 'Unable to pay this invoice.' );
		$this->sut->handle_first_invoice_job( ...$args );
		$args     = $this->take_verdict( $order, $subscription, time() + DAY_IN_SECONDS, true );
		$warnings = array();
		$this->watch_verdict_warnings( $subscription, $warnings );
		$this->http_client->responses[] = $this->make_response( 200, array( 'status' => 'incomplete' ) );
		$this->sut->handle_first_invoice_job( ...$args );
		$this->assertNotEmpty( $warnings );
		foreach ( $warnings as $messages ) {
			$this->assertCount( 1, $messages );
		}
		$this->assertSame( array( 'POST', 'GET', 'POST', 'GET' ), array_column( $this->get_requests(), 0 ) );
		$this->assertNotContains( self::FAILURE_NOTE, $this->get_order_note_texts( $subscription ) );
		$this->assert_invoice_schedule( $order, $subscription, null, 0 );
	}

	/**
	 * @testdox A lasting pay refusal can come from the store switching modes or the trial update failing after payment.
	 * @testWith [404, "No such invoice."]
	 *           [400, "Invalid trial end."]
	 *
	 * @param int    $code    Error returned by the pay endpoint.
	 * @param string $message Platform error message.
	 */
	public function test_first_invoice_refused( int $code, string $message ): void {
		$order = \WC_Helper_Order::create_order();
		$order->update_meta_data( '_intention_status', 'succeeded' );
		$order->update_meta_data( '_intent_id', 'pi_first' );
		$order->save();
		$subscription = $this->create_stripe_billed_subscription( $order, 'parent' );
		$this->queue_invoice_answer( $code, $message );
		if ( 404 === $code ) {
			$this->test_mode = false;
			$this->queue_invoice_answer( 404, 'No such subscription.' );
			$this->sut->maybe_record_invoice_payment( $order->get_id() );
			$this->assertSame( array( 'POST' ), array_column( $this->get_requests(), 0 ) );
			$this->assertFalse( $this->get_requests()[0][2]['test_mode'] );
			$this->assertNotContains( self::FAILURE_NOTE, $this->get_order_note_texts( $subscription ) );
			$args = $this->take_verdict( $order, $subscription, time() + 23 * HOUR_IN_SECONDS + 30 * MINUTE_IN_SECONDS );
			$this->sut->handle_first_invoice_job( ...$args );
		} else {
			$this->http_client->responses[] = $this->make_response( 200, array( 'status' => 'active' ) );
			$this->sut->handle_first_invoice_job( $order->get_id(), $subscription->get_id(), self::MAIN_INVOICE_ID, 1 );
			$this->assertSame( array( 'POST' ), array_column( $this->get_requests(), 0 ) );
			$args = $this->take_verdict( $order, $subscription, time() + 23 * HOUR_IN_SECONDS + 30 * MINUTE_IN_SECONDS );
			$this->sut->handle_first_invoice_job( ...$args );
		}

		$requests = $this->get_requests();
		$this->assertSame( array( 'POST', 'GET' ), array_column( $requests, 0 ) );
		$this->assertStringEndsWith( '/invoices/' . self::MAIN_INVOICE_ID . '/pay', $requests[0][1] );
		$this->assertStringContainsString( '/subscriptions/' . self::MAIN_SUBSCRIPTION_ID . '?', $requests[1][1] );
		$this->assertNotContains( self::FAILURE_NOTE, $this->get_order_note_texts( $subscription ) );
		$this->assert_invoice_schedule( $order, $subscription, null, 0 );
	}

	/**
	 * @testdox A retry that finds the invoice paid warns once that the deferred trial end may still be missing.
	 */
	public function test_first_invoice_already_paid_after_lost_answer(): void {
		$order = \WC_Helper_Order::create_order();
		$order->update_meta_data( '_intention_status', 'succeeded' );
		$order->update_meta_data( '_intent_id', 'pi_first' );
		$order->save();
		$subscription = $this->create_stripe_billed_subscription( $order, 'parent' );
		$this->queue_invoice_answer( 503, 'Lost pay response.' );
		$this->sut->maybe_record_invoice_payment( $order->get_id() );

		$args = $this->take_pay_retry( $order, $subscription );

		$warnings = array();
		add_filter(
			'woocommerce_logger_log_message',
			static function ( $message, $level, $context, $handler ) use ( &$warnings, $subscription ) {
				if ( 'warning' === $level && $subscription->get_id() === ( $context['subscription_id'] ?? null ) ) {
					$warnings[ spl_object_id( $handler ) ][] = $message;
				}
				return $message;
			},
			10,
			4
		);
		$this->queue_invoice_answer( 400, 'Invoice is already paid.' );
		$this->sut->handle_first_invoice_job( ...$args );
		$this->assert_invoice_schedule( $order, $subscription, null, 0 );
		$this->assertSame( array( 'POST', 'POST' ), array_column( $this->get_requests(), 0 ) );
		$this->assertNotContains( self::FAILURE_NOTE, $this->get_order_note_texts( $subscription ) );
		$this->assertNotEmpty( $warnings );
		foreach ( $warnings as $messages ) {
			$this->assertCount( 1, $messages );
			$this->assertStringContainsString( 'trial end', $messages[0] );
		}
	}

	/**
	 * @testdox An expired first invoice gets its note when the due verdict runs from the queue.
	 */
	public function test_first_invoice_verdict_runs_from_the_queue(): void {
		$order = \WC_Helper_Order::create_order();
		$order->set_date_created( time() - DAY_IN_SECONDS );
		$order->update_meta_data( '_intention_status', 'succeeded' );
		$order->update_meta_data( '_intent_id', 'pi_verdict' );
		$order->save();
		$subscription = $this->create_stripe_billed_subscription( $order, 'parent' );
		$subscription->set_date_created( $order->get_date_created() );
		$subscription->save();
		$this->queue_invoice_answer( 400, 'This invoice is void.' );
		$this->sut->maybe_record_invoice_payment( $order->get_id() );
		$this->assertSame( array( 'POST' ), array_column( $this->get_requests(), 0 ) );
		$this->assertNotContains( self::FAILURE_NOTE, $this->get_order_note_texts( $subscription ) );
		$args = array(
			'order_id'        => $order->get_id(),
			'subscription_id' => $subscription->get_id(),
			'invoice_id'      => self::MAIN_INVOICE_ID,
			'retry'           => array(
				'final' => false,
				'read'  => 0,
			),
		);
		$this->assertTrue( as_has_scheduled_action( self::FIRST_INVOICE_HOOK, $args, 'woocommerce_payments' ) );
		$this->http_client->responses[] = $this->make_response( 200, array( 'status' => 'incomplete_expired' ) );
		add_action( self::FIRST_INVOICE_HOOK, array( $this->sut, 'handle_first_invoice_job' ), 10, 4 );
		$due = as_get_scheduled_actions(
			array(
				'hook'         => self::FIRST_INVOICE_HOOK,
				'group'        => 'woocommerce_payments',
				'status'       => 'pending',
				'date'         => as_get_datetime_object(),
				'date_compare' => '<=',
			),
			'ids'
		);
		$this->assertCount( 1, $due );
		\ActionScheduler_QueueRunner::instance()->process_action( $due[0], 'first invoice' );
		$this->assertSame( 'complete', \ActionScheduler::store()->get_status( $due[0] ) );
		$this->assertSame( array( 'POST', 'GET' ), array_column( $this->get_requests(), 0 ) );
		$this->assertSame( 1, count( array_keys( $this->get_order_note_texts( $subscription ), self::FAILURE_NOTE, true ) ) );
		$this->assert_invoice_schedule( $order, $subscription, null, 0 );
	}

	/**
	 * @testdox A late pay retry leaves the original verdict due at checkout plus 23 hours 30 minutes.
	 */
	public function test_late_first_invoice_retry_preserves_the_verdict(): void {
		$order = \WC_Helper_Order::create_order();
		$order->update_meta_data( '_intention_status', 'succeeded' );
		$order->update_meta_data( '_intent_id', 'pi_late' );
		$order->save();
		$subscription = $this->create_stripe_billed_subscription( $order, 'parent' );
		$now          = time();
		$this->queue_invoice_answer( 503, 'Unavailable.' );
		$this->sut->handle_first_invoice_job( $order->get_id(), $subscription->get_id(), self::MAIN_INVOICE_ID, 0 );
		$pay = $this->take_pay_retry( $order, $subscription );
		$order->set_date_created( $now - DAY_IN_SECONDS );
		$order->save();
		$subscription->set_date_created( $order->get_date_created() );
		$subscription->save();
		$this->queue_invoice_answer( 503, 'Unavailable.' );
		$this->sut->handle_first_invoice_job( ...$pay );
		$this->assertSame( array( 'POST', 'POST' ), array_column( $this->get_requests(), 0 ) );
		$this->take_verdict( $order, $subscription, $now + 23 * HOUR_IN_SECONDS + 30 * MINUTE_IN_SECONDS );
		$this->assert_invoice_schedule( $order, $subscription, null, 0 );
	}

	/**
	 * @testdox A transient or unusable verdict retries at the shared intervals, then logs once or adopts the paid subscription.
	 * @testWith [false, false]
	 *           [false, true]
	 *           [true, false]
	 *
	 * @param bool $last_verdict Last verdict after an incomplete response.
	 * @param bool $recover Whether the last read succeeds.
	 */
	public function test_first_invoice_verdict_read_cadence( bool $last_verdict, bool $recover ): void {
		$order = \WC_Helper_Order::create_order();
		$order->update_meta_data( '_intention_status', 'succeeded' );
		$order->update_meta_data( '_intent_id', 'pi_read' );
		$order->save();
		$subscription = $this->create_stripe_billed_subscription( $order, 'parent', array( '_requires_manual_renewal' => 'true' ) );
		$logs         = array();
		$this->watch_verdict_warnings( $subscription, $logs );
		$args = array(
			$order->get_id(),
			$subscription->get_id(),
			self::MAIN_INVOICE_ID,
			array(
				'final' => $last_verdict,
				'read'  => 0,
			),
		);
		foreach ( array( 60, 600, 3600 ) as $read => $delay ) {
			$this->http_client->responses[] = $this->make_response( 1 === $read ? 200 : 503, array() );
			$this->sut->handle_first_invoice_job( ...$args );
			$this->assertSame( array(), $logs );
			$args = $this->take_verdict( $order, $subscription, time() + $delay, $last_verdict, $read + 1 );
		}
		$this->http_client->responses[] = $this->make_response( $recover ? 200 : 503, array( 'status' => 'active' ) );
		$this->sut->handle_first_invoice_job( ...$args );
		$this->assertSame( $recover, empty( $logs ) );
		foreach ( $logs as $messages ) {
			$this->assertCount( 1, $messages );
		}
		$this->assertSame( ! $recover, wc_get_order( $subscription->get_id() )->is_manual() );
		$this->assertSame( array( 'GET', 'GET', 'GET', 'GET' ), array_column( $this->get_requests(), 0 ) );
		$this->assertNotContains( self::FAILURE_NOTE, $this->get_order_note_texts( $subscription ) );
		$this->assert_invoice_schedule( $order, $subscription, null, 0 );
	}

	/**
	 * @testdox A job superseded before it runs ends quietly. A migration starting during the read also suppresses the note.
	 * @testWith ["toggle", false]
	 *           ["migration", false]
	 *           ["migration", true]
	 *           ["migration during read", true]
	 *           ["replaced invoice", true]
	 *           ["ended", true]
	 *           ["toggle", true]
	 *
	 * @param string $reason Why the first invoice no longer belongs to this job.
	 * @param bool   $verdict Whether this job reads the verdict.
	 */
	public function test_superseded_first_invoice_job( string $reason, bool $verdict ): void {
		$order = \WC_Helper_Order::create_order();
		$order->update_meta_data( '_intention_status', 'succeeded' );
		$order->update_meta_data( '_intent_id', 'pi_superseded' );
		$order->save();
		$subscription = $this->create_stripe_billed_subscription( $order, 'parent' );
		if ( 'ended' === $reason ) {
			$subscription->update_status( 'pending-cancel' );
		} elseif ( 'replaced invoice' === $reason ) {
			$this->sut->set_subscription_invoice_id( $subscription, 'in_replacement' );
		} elseif ( 'migration during read' === $reason ) {
			add_filter(
				'wcpay_api_request_params',
				function ( $params ) {
					$this->migrating = true;
					return $params;
				}
			);
		}
		$this->stripe_billing_enabled   = 'toggle' !== $reason;
		$this->migrating                = 'migration' === $reason;
		$this->http_client->responses[] = $this->make_response( 200, array( 'status' => 'incomplete_expired' ) );
		$this->sut->handle_first_invoice_job(
			$order->get_id(),
			$subscription->get_id(),
			self::MAIN_INVOICE_ID,
			$verdict ? array(
				'final' => false,
				'read'  => 0,
			) : 1
		);
		$this->assertSame( 'migration during read' === $reason ? array( 'GET' ) : array(), array_column( $this->get_requests(), 0 ) );
		$this->assertNotContains( self::FAILURE_NOTE, $this->get_order_note_texts( $subscription ) );
		$this->assert_invoice_schedule( $order, $subscription, null, 0 );
	}

	/**
	 * @testdox A successful checkout adds zero queries to the existing invoice payment path.
	 */
	public function test_first_invoice_success_adds_zero_queries(): void {
		global $wpdb;
		$order        = \WC_Helper_Order::create_order();
		$subscription = $this->create_stripe_billed_subscription( $order, 'parent' );
		$this->queue_recorded_responses( 'charge_invoice' );
		$this->sut->maybe_record_invoice_payment( $order->get_id() );

		$this->queue_recorded_responses( 'charge_invoice' );
		$before = $wpdb->num_queries;
		$paying = wc_get_order( $order->get_id() );
		$this->sut->get_order_invoice_id( $paying );
		$related = wcs_get_subscriptions_for_order( $paying, array( 'order_type' => array( 'parent', 'renewal' ) ) );
		$sub     = reset( $related );
		$this->assertTrue( wc_get_container()->get( StripeBillingSubscriptionService::class )->is_wcpay_subscription( $sub ) );
		$this->api->charge_invoice( $this->sut->get_subscription_invoice_id( $sub ), array( 'paid_out_of_band' => 'true' ) );
		$baseline = $wpdb->num_queries - $before;

		$this->queue_recorded_responses( 'charge_invoice' );
		$before = $wpdb->num_queries;
		$this->sut->maybe_record_invoice_payment( $order->get_id() );
		$this->assertSame( 0, $wpdb->num_queries - $before - $baseline );
		$this->assertSame( array( 'POST', 'POST', 'POST' ), array_column( $this->get_requests(), 0 ) );
		$this->assert_invoice_schedule( $order, $subscription, null, 0 );
	}

	/**
	 * Collect application warnings once per log handler.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @param array    $warnings Captured messages.
	 */
	private function watch_verdict_warnings( WC_Order $subscription, array &$warnings ): void {
		add_filter(
			'woocommerce_logger_log_message',
			static function ( $message, $level, $context, $handler ) use ( &$warnings, $subscription ) {
				if ( $subscription->get_id() === ( $context['subscription_id'] ?? null ) ) {
					$warnings[ spl_object_id( $handler ) ][] = $message;
				}
				return $message;
			},
			10,
			4
		);
	}

	/**
	 * Take the scheduled pay retry, leaving its verdict pending until payment succeeds.
	 *
	 * @param WC_Order $order Paying order.
	 * @param WC_Order $subscription Subscription.
	 * @return array Scheduled arguments.
	 */
	private function take_pay_retry( WC_Order $order, WC_Order $subscription ): array {
		return $this->take_job( $order, $subscription, 1, time() + 60 );
	}

	/**
	 * Take the specified verdict without changing the other jobs for this invoice.
	 *
	 * @param WC_Order $order Paying order.
	 * @param WC_Order $subscription Subscription.
	 * @param int      $due Due time.
	 * @param bool     $last_verdict Last verdict after Stripe still reported incomplete.
	 * @param int      $read Read retry number.
	 * @return array Scheduled arguments.
	 */
	private function take_verdict( WC_Order $order, WC_Order $subscription, int $due, bool $last_verdict = false, int $read = 0 ): array {
		return $this->take_job(
			$order,
			$subscription,
			array(
				'final' => $last_verdict,
				'read'  => $read,
			),
			$due
		);
	}

	/**
	 * Remove a pending job for execution and check the due time recorded by Action Scheduler.
	 *
	 * @param WC_Order  $order Paying order.
	 * @param WC_Order  $subscription Subscription.
	 * @param int|array $retry Pay retry or verdict arguments.
	 * @param int       $due Due time.
	 * @return array Scheduled arguments.
	 */
	private function take_job( WC_Order $order, WC_Order $subscription, $retry, int $due ): array {
		$args = array(
			'order_id'        => $order->get_id(),
			'subscription_id' => $subscription->get_id(),
			'invoice_id'      => self::MAIN_INVOICE_ID,
			'retry'           => $retry,
		);
		$when = as_next_scheduled_action( self::FIRST_INVOICE_HOOK, $args, 'woocommerce_payments' );
		$this->assertIsInt( $when );
		$this->assertEqualsWithDelta( $due, $when, 5 );
		as_unschedule_action( self::FIRST_INVOICE_HOOK, $args, 'woocommerce_payments' );
		return array_values( $args );
	}

	/**
	 * Queue a platform answer for invoice payment.
	 *
	 * @param int    $code    Status, or zero for a transport failure on every attempt.
	 * @param string $message Error text.
	 */
	private function queue_invoice_answer( int $code, string $message ): void {
		if ( 0 === $code ) {
			$this->http_client->response = new \WP_Error( 'http_request_failed', 'Transport unavailable.' );
		} elseif ( 200 === $code ) {
			$this->queue_recorded_responses( 'charge_invoice' );
		} else {
			$error                          = array(
				'code'    => 'invalid_request_error',
				'message' => $message,
				'data'    => array( 'status' => $code ),
			);
			$this->http_client->responses[] = $this->make_response( $code, $error );
		}
	}

	/**
	 * Check every pending first-invoice job and its due time.
	 *
	 * @param WC_Order $order        Paying order.
	 * @param WC_Order $subscription Subscription.
	 * @param int|null $retry        Expected attempt, or none.
	 * @param int      $due          Expected timestamp.
	 */
	private function assert_invoice_schedule( WC_Order $order, WC_Order $subscription, ?int $retry, int $due ): void {
		$query   = array(
			'hook'   => self::FIRST_INVOICE_HOOK,
			'status' => 'pending',
			'group'  => 'woocommerce_payments',
		);
		$actions = array_values( as_get_scheduled_actions( $query ) );
		$this->assertCount( null === $retry ? 0 : 1, $actions );
		if ( null !== $retry ) {
			$expected = array(
				'order_id'        => $order->get_id(),
				'subscription_id' => $subscription->get_id(),
				'invoice_id'      => self::MAIN_INVOICE_ID,
				'retry'           => $retry,
			);
			$this->assertSame( $expected, $actions[0]->get_args() );
			$this->assertEqualsWithDelta( $due, $actions[0]->get_schedule()->get_date()->getTimestamp(), 5 );
		}
	}

	/**
	 * @testdox A manual subscription becomes automatic with the payment method that paid the order (client test_maybe_record_invoice_payment manual renewal).
	 */
	public function test_turns_a_manual_subscription_automatic_with_the_order_payment_method(): void {
		$this->queue_recorded_responses( 'charge_invoice' );
		$order        = \WC_Helper_Order::create_order();
		$subscription = $this->create_stripe_billed_subscription( $order, 'renewal', array( '_requires_manual_renewal' => 'true' ) );
		$copied       = array();
		$this->subscriptions_controller->expects( $this->once() )
			->method( 'update_failing_payment_method' )
			->willReturnCallback(
				function ( $from_subscription, $from_order ) use ( &$copied ) {
					$copied = array( $from_subscription->get_id(), $from_order->get_id() );
				}
			);

		$this->sut->maybe_record_invoice_payment( $order->get_id() );

		$this->assertSame( array( $subscription->get_id(), $order->get_id() ), $copied );
		$saved = wc_get_order( $subscription->get_id() );
		$this->assertFalse( $saved->is_manual() );
		$this->assertSame( 'woocommerce_payments', $saved->get_payment_method() );
	}

	/**
	 * @testdox An invoice whose items and discounts match the subscription changes nothing at Stripe (client test_validate_invoice_with_valid_data).
	 */
	public function test_validating_a_matching_invoice_sends_nothing(): void {
		$invoice      = $this->get_event_object( 'invoice_upcoming' );
		$subscription = $this->create_subscription_with_line_item( 1, '24.90' );

		$this->sut->update_wcpay_subscription_to_match( $invoice['lines']['data'], $invoice['discounts'], $subscription );

		$this->assertSame( 0, $this->http_client->request_count );
	}

	/**
	 * @testdox A different quantity and price are sent to the Stripe subscription item, keeping Stripe's currency and billing cycle (client test_validate_invoice_with_invalid_data, `class-wc-payments-invoice-service.php:243`, `:447-465`).
	 *
	 * The price data is the client's `format_item_price_data()` (`class-wc-payments-subscription-service.php:337-358`, `:877`): 59.80 for two is 2990 cents a unit.
	 */
	public function test_validating_repairs_the_quantity_and_price_of_an_item(): void {
		$this->queue_recorded_responses( 'update_subscription_item' );
		$invoice      = $this->get_event_object( 'invoice_upcoming' );
		$subscription = $this->create_subscription_with_line_item( 2, '59.80' );

		$this->sut->update_wcpay_subscription_to_match( $invoice['lines']['data'], $invoice['discounts'], $subscription );

		$this->assertSame(
			array(
				array(
					'POST',
					'/sites/4/wcpay/subscriptions/items/' . self::CLOCK_ITEM_ID,
					array(
						'test_mode'  => true,
						'quantity'   => 2,
						'price_data' => array(
							'currency'            => 'usd',
							'product'             => self::RECORDED_PRODUCT_ID,
							'unit_amount_decimal' => 2990,
							'recurring'           => array(
								'interval'       => 'month',
								'interval_count' => 1,
							),
						),
					),
				),
			),
			$this->get_requests()
		);
	}

	/**
	 * @testdox The subscription's coupons are sent to Stripe when the invoice's discounts differ, and the returned discount IDs saved (client get_repair_data_for_wcpay_discounts, `class-wc-payments-invoice-service.php:250-255`, `class-wc-payments-subscription-service.php:367-388`).
	 * @testWith [["di_1UM1RecT63Kept"], ["di_1UM1RecT63Kept"], false]
	 *           [[], [], false]
	 *           [["di_1UM1RecT63Kept"], [], true]
	 *           [[], ["di_1UM1RecT63Kept"], true]
	 *           [["di_1UM1RecT63Kept"], ["di_1UM1RecT63Other"], true]
	 *
	 * @param array<int,string> $saved_discount_ids   Discount IDs saved on the subscription.
	 * @param array<int,string> $invoice_discount_ids Discount IDs on the invoice.
	 * @param bool              $expect_repair        Whether the discounts are sent.
	 */
	public function test_validating_repairs_discounts_that_differ( array $saved_discount_ids, array $invoice_discount_ids, bool $expect_repair ): void {
		$entry        = $this->queue_recorded_responses( 'update_subscription' )[0];
		$invoice      = $this->get_event_object( 'invoice_upcoming' );
		$subscription = $this->create_subscription_with_line_item( 1, '24.90', array( '_wcpay_subscription_discount_ids' => $saved_discount_ids ) );
		$this->add_fixed_coupon( $subscription, 'rec63-save5', '5.00' );

		$this->sut->update_wcpay_subscription_to_match( $invoice['lines']['data'], $invoice_discount_ids, $subscription );

		if ( ! $expect_repair ) {
			$this->assertSame( 0, $this->http_client->request_count );
			return;
		}

		$this->assertSame(
			array(
				array(
					'POST',
					'/sites/4/wcpay/subscriptions/' . self::CLOCK_SUBSCRIPTION_ID,
					array(
						'test_mode' => true,
						'discounts' => array(
							array(
								'amount_off' => 500,
								'currency'   => 'USD',
								'duration'   => 'once',
								'name'       => 'Coupon - rec63-save5',
							),
						),
					),
				),
			),
			$this->get_requests()
		);
		$this->assertSame( $entry['response']['body']['discounts'], wc_get_order( $subscription->get_id() )->get_meta( '_wcpay_subscription_discount_ids', true ) );
	}

	/**
	 * @testdox An invoice without an item of the subscription fails without changing anything at Stripe (client get_repair_data_for_wcpay_items).
	 */
	public function test_validating_an_invoice_missing_an_item_fails(): void {
		$invoice = $this->get_event_object( 'invoice_upcoming' );
		$invoice['lines']['data'][0]['subscription_item'] = 'si_VMl3Tua3Z2QiQ7';

		$subscription = $this->create_subscription_with_line_item( 2, '59.80' );

		try {
			$this->sut->update_wcpay_subscription_to_match( $invoice['lines']['data'], $invoice['discounts'], $subscription );
			$this->fail( 'An invoice without the subscription item must fail.' );
		} catch ( StripeBillingException $exception ) {
			$this->assertSame( StripeBillingException::INVOICE_ITEMS_MISMATCH, $exception->get_error_code() );
		}

		$this->assertSame( 0, $this->http_client->request_count );
	}

	/**
	 * @testdox Repairing an invoice for a subscription whose product was deleted fails before any platform call, naming the subscription and the item.
	 */
	public function test_validating_an_invoice_for_a_deleted_product_fails_before_the_platform(): void {
		$invoice      = $this->get_event_object( 'invoice_upcoming' );
		$subscription = $this->create_subscription_with_line_item( 2, '59.80' );
		$items        = $subscription->get_items();
		$item         = reset( $items );
		wc_get_product( $item->get_product_id() )->delete( true );
		$subscription = wc_get_order( $subscription->get_id() );

		try {
			$this->sut->update_wcpay_subscription_to_match( $invoice['lines']['data'], $invoice['discounts'], $subscription );
			$this->fail( 'A subscription item without a product must be refused.' );
		} catch ( StripeBillingException $exception ) {
			$this->assertSame( StripeBillingException::SUBSCRIPTION_PRODUCT_MISSING, $exception->get_error_code() );
			$this->assertStringContainsString( '#' . $subscription->get_id(), $exception->getMessage() );
			$this->assertStringContainsString( '#' . $item->get_id(), $exception->getMessage() );
		}

		$this->assertSame( 0, $this->http_client->request_count );
	}

	/**
	 * @testdox The invoice payment is recorded as Stripe Billing while the toggle is on, else as a legacy WooPayments subscription (client record_subscription_payment_context, `class-wc-payments-invoice-service.php:311-317`).
	 * @testWith [true, "stripe_billing"]
	 *           [false, "legacy_wcpay_subscription"]
	 *
	 * @param bool   $enabled  Whether the Stripe Billing toggle is on.
	 * @param string $expected Context sent.
	 */
	public function test_records_the_subscription_context_of_an_invoice_payment( bool $enabled, string $expected ): void {
		$this->stripe_billing_enabled = $enabled;
		$entry                        = $this->queue_recorded_responses( 'update_invoice' )[0];
		$invoice_id                   = $entry['response']['body']['id'];

		$result = $this->sut->record_subscription_payment_context( $invoice_id );

		$this->assertSame(
			array(
				array(
					'POST',
					'/sites/4/wcpay/invoices/' . $invoice_id,
					array(
						'test_mode'            => true,
						'subscription_context' => $expected,
					),
				),
			),
			$this->get_requests()
		);
		$this->assertSame( $entry['response']['body'], $result, 'The invoice comes back for the charge and transaction updates.' );
	}

	/**
	 * @testdox The order ID is sent to the charge of a paid renewal invoice, and nothing is sent for an invoice without a charge (client update_charge_details, `class-wc-payments-invoice-service.php:359-368`).
	 */
	public function test_sends_the_order_id_to_the_invoice_charge(): void {
		$this->queue_recorded_responses( 'update_charge' );

		$this->sut->update_charge_details( $this->get_event_object( 'invoice_paid_parent' ), 5821 );
		$this->assertSame( 0, $this->http_client->request_count, 'An invoice paid out of band has no charge.' );

		$this->sut->update_charge_details( $this->get_event_object( 'invoice_paid_renewal' ), 5821 );

		$this->assertSame(
			array(
				array(
					'POST',
					'/sites/4/wcpay/charges/ch_3UM1YABzWlxcwgpP0Fazmyr5',
					array(
						'test_mode' => true,
						'metadata'  => array( 'order_id' => 5821 ),
					),
				),
			),
			$this->get_requests()
		);
	}

	/**
	 * @testdox The order's billing name, email and country are sent to the transaction of the invoice charge (client update_transaction_details, `class-wc-payments-invoice-service.php:339-347`).
	 */
	public function test_sends_the_billing_details_to_the_invoice_transaction(): void {
		$this->queue_recorded_responses( 'get_charge_for_update_transaction', 'update_transaction' );
		$order = \WC_Helper_Order::create_order();
		$order->set_billing_first_name( 'Rec' );
		$order->set_billing_last_name( 'Renewal' );
		$order->set_billing_email( 'rec-t63-renewal@woo.test' );
		$order->set_billing_country( 'US' );
		$order->save();

		$this->sut->update_transaction_details( $this->get_event_object( 'invoice_paid_parent' ), $order );
		$this->assertSame( 0, $this->http_client->request_count, 'An invoice paid out of band has no charge.' );

		$this->sut->update_transaction_details( $this->get_event_object( 'invoice_paid_renewal' ), $order );

		$this->assertSame(
			array(
				array( 'GET', '/sites/4/wcpay/charges/ch_3UM1YABzWlxcwgpP0Fazmyr5?test_mode=1', null ),
				array(
					'POST',
					'/sites/4/wcpay/transactions/txn_3UM1YABzWlxcwgpP0tCiQoKI',
					array(
						'test_mode'           => true,
						'customer_first_name' => 'Rec',
						'customer_last_name'  => 'Renewal',
						'customer_email'      => 'rec-t63-renewal@woo.test',
						'customer_country'    => 'US',
					),
				),
			),
			$this->get_requests()
		);
	}

	/**
	 * @testdox A charge without a balance transaction leaves the transaction alone (client update_transaction_details).
	 */
	public function test_skips_the_transaction_of_a_charge_without_one(): void {
		$entry = $this->get_entry( self::BILLING_FIXTURE, 'get_charge_for_update_transaction' );

		$entry['response']['body']['balance_transaction'] = null;
		$this->queue_entry( $entry );

		$this->sut->update_transaction_details( $this->get_event_object( 'invoice_paid_renewal' ), \WC_Helper_Order::create_order() );

		$this->assertSame( 1, $this->http_client->request_count, 'Only the charge is read.' );
	}

	/**
	 * @testdox The order paid by an invoice is found by the invoice ID (client test_get_order_id_by_invoice_id).
	 */
	public function test_finds_the_order_paid_by_an_invoice(): void {
		\WC_Helper_Order::create_order();
		$order = \WC_Helper_Order::create_order();
		$this->sut->set_order_invoice_id( $order, self::MAIN_INVOICE_ID );

		$this->assertSame( $order->get_id(), $this->sut->get_order_id_by_invoice_id( self::MAIN_INVOICE_ID ) );
		$this->assertSame( 0, $this->sut->get_order_id_by_invoice_id( 'in_1UM1Y0BzWlxcwgpPefRU4SSy' ) );
	}

	/**
	 * Create a subscription billed by Stripe Billing on the recorded main chain, related to an order.
	 *
	 * @param WC_Order             $order          Order.
	 * @param string               $relation       How the order relates to the subscription: `parent`, `renewal`, `switch`.
	 * @param array<string,string> $meta           Subscription meta overriding the defaults.
	 * @param string               $payment_method Subscription payment method.
	 * @return SubscriptionDouble
	 */
	private function create_stripe_billed_subscription( WC_Order $order, string $relation, array $meta = array(), string $payment_method = 'woocommerce_payments' ): SubscriptionDouble {
		$subscription = new SubscriptionDouble();
		$subscription->set_payment_method( $payment_method );
		$meta += array(
			'_wcpay_subscription_id'    => self::MAIN_SUBSCRIPTION_ID,
			'_wcpay_billing_invoice_id' => self::MAIN_INVOICE_ID,
			'_requires_manual_renewal'  => 'false',
		);
		foreach ( $meta as $key => $value ) {
			$subscription->update_meta_data( $key, $value );
		}
		$subscription->save();

		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ][]                                     = $subscription->get_id();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ][ $order->get_id() ][ $relation ][] = $subscription->get_id();

		return $subscription;
	}

	/**
	 * Create a monthly subscription on the recorded test-clock chain with one line item linked to its Stripe subscription item.
	 *
	 * The item's product already has its Stripe product, so building the Stripe items sends nothing.
	 *
	 * @param int                 $quantity Item quantity.
	 * @param string              $subtotal Item subtotal.
	 * @param array<string,mixed> $meta     Extra subscription meta.
	 * @return SubscriptionDouble
	 */
	private function create_subscription_with_line_item( int $quantity, string $subtotal, array $meta = array() ): SubscriptionDouble {
		$product = \WC_Helper_Product::create_simple_product();
		$product->update_meta_data( '_wcpay_product_id_test', self::RECORDED_PRODUCT_ID );
		$product->update_meta_data( '_wcpay_product_id_test_linked_to', self::RECORDED_ACCOUNT_ID );
		$product->save();

		$subscription = new SubscriptionDouble();
		$subscription->set_payment_method( 'woocommerce_payments' );
		$subscription->set_currency( 'USD' );
		$meta += array(
			'_wcpay_subscription_id' => self::CLOCK_SUBSCRIPTION_ID,
			'_billing_period'        => 'month',
			'_billing_interval'      => '1',
		);
		foreach ( $meta as $key => $value ) {
			$subscription->update_meta_data( $key, $value );
		}

		$item = new \WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( $quantity );
		$item->set_subtotal( $subtotal );
		$item->set_total( $subtotal );
		$item->update_meta_data( '_wcpay_subscription_item_id', self::CLOCK_ITEM_ID );
		$subscription->add_item( $item );
		$subscription->save();

		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ][] = $subscription->get_id();

		return wc_get_order( $subscription->get_id() );
	}

	/**
	 * Add a fixed cart coupon to a subscription.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @param string   $code         Coupon code.
	 * @param string   $discount     Discount amount.
	 */
	private function add_fixed_coupon( WC_Order $subscription, string $code, string $discount ): void {
		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( $discount );
		$coupon->save();

		$item = new \WC_Order_Item_Coupon();
		$item->set_code( $code );
		$item->set_discount( $discount );
		$subscription->add_item( $item );
		$subscription->save();
	}

	/**
	 * Get the invoice of a recorded invoice event.
	 *
	 * @param string $pair Fixture entry.
	 * @return array<string,mixed>
	 */
	private function get_event_object( string $pair ): array {
		return $this->get_entry( self::EVENTS_FIXTURE, $pair )['body']['data']['object'];
	}

	/**
	 * Get a fixture entry, from the entries or the supporting entries.
	 *
	 * @param string $fixture Fixture file.
	 * @param string $pair    Entry name.
	 * @return array<string,mixed>
	 */
	private function get_entry( string $fixture, string $pair ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local test fixture.
		$recording = json_decode( (string) file_get_contents( $fixture ), true );
		$entries   = array_merge( $recording['entries'], $recording['supporting_entries'] ?? array() );
		$matches   = array_values( array_filter( $entries, static fn( array $entry ) => $pair === $entry['pair'] ) );
		$this->assertNotEmpty( $matches, "Fixture entry $pair is missing." );

		return $matches[0];
	}

	/**
	 * Queue the recorded platform answers of billing fixture entries, in order.
	 *
	 * @param string ...$pairs Fixture entry names.
	 * @return array<int,array<string,mixed>> The entries.
	 */
	private function queue_recorded_responses( string ...$pairs ): array {
		$entries = array();
		foreach ( $pairs as $pair ) {
			$entries[] = $this->get_entry( self::BILLING_FIXTURE, $pair );
			$this->queue_entry( end( $entries ) );
		}

		return $entries;
	}

	/**
	 * Queue the recorded platform answer of a fixture entry.
	 *
	 * @param array<string,mixed> $entry Fixture entry.
	 */
	private function queue_entry( array $entry ): void {
		$this->http_client->responses[] = $this->make_response( (int) $entry['response']['http_status'], $entry['response']['body'] );
	}

	/**
	 * @testdox An invoice payment matches its order when the currency is the same and the amount is within one store price unit per order line: $_dataName.
	 *
	 * Client 11.3.0 WC_Payments_Invoice_Service::does_invoice_payment_match_order_total() (class-wc-payments-invoice-service.php:116-159);
	 * the rows and expected values are its test_does_invoice_payment_match_order_total() provider, on an order of two lines.
	 *
	 * @dataProvider provider_invoice_payments_against_a_two_line_order
	 *
	 * @param float  $order_total      Order total.
	 * @param string $order_currency   Order currency.
	 * @param mixed  $amount_paid      The invoice's amount_paid.
	 * @param string $invoice_currency The invoice's currency.
	 * @param bool   $expected         Whether the invoice matches the order.
	 */
	public function test_invoice_payment_matches_its_order_within_a_price_unit_per_line( float $order_total, string $order_currency, $amount_paid, string $invoice_currency, bool $expected ): void {
		$order = $this->create_order_with_two_lines( $order_total, $order_currency );

		$this->assertSame( $expected, $this->sut->does_invoice_payment_match_order_total( $order, $amount_paid, $invoice_currency ) );
	}

	/**
	 * Invoices compared with an order of two lines, in a store priced with two decimals.
	 *
	 * @return array<string,array{float,string,mixed,string,bool}>
	 */
	public function provider_invoice_payments_against_a_two_line_order(): array {
		return array(
			'exact match'                     => array( 50.0, 'USD', 5000, 'usd', true ),
			'a cent per line below'           => array( 50.0, 'USD', 4998, 'usd', true ),
			'a cent per line above'           => array( 50.0, 'USD', 5002, 'usd', true ),
			'beyond a cent per line below'    => array( 50.0, 'USD', 4997, 'usd', false ),
			'beyond a cent per line above'    => array( 50.0, 'USD', 5003, 'usd', false ),
			'zero-decimal currency'           => array( 50.0, 'JPY', 50, 'jpy', true ),
			'zero-decimal currency, in cents' => array( 50.0, 'JPY', 5000, 'jpy', false ),
			'different currency'              => array( 50.0, 'CAD', 5000, 'usd', false ),
			'missing currency'                => array( 50.0, 'USD', 5000, '', false ),
			'missing amount'                  => array( 0.0, 'USD', null, 'usd', false ),
			'non-numeric amount'              => array( 0.0, 'USD', 'free', 'usd', false ),
		);
	}

	/**
	 * @testdox In a store priced without decimals, an invoice payment matches within one whole unit per order line: $_dataName.
	 *
	 * Client 11.3.0 test_does_invoice_payment_match_order_total_without_decimals() (HUF, a store priced with 0 decimals).
	 *
	 * @dataProvider provider_invoice_payments_in_a_store_without_decimals
	 *
	 * @param float $order_total Order total, in HUF.
	 * @param int   $amount_paid The invoice's amount_paid, in fillér.
	 * @param bool  $expected    Whether the invoice matches the order.
	 */
	public function test_invoice_payment_matches_within_a_whole_unit_per_line_without_decimals( float $order_total, int $amount_paid, bool $expected ): void {
		update_option( 'woocommerce_currency', 'HUF' );
		update_option( 'woocommerce_price_num_decimals', 0 );
		$order = $this->create_order_with_two_lines( $order_total, 'HUF' );

		$this->assertSame( $expected, $this->sut->does_invoice_payment_match_order_total( $order, $amount_paid, 'huf' ) );
	}

	/**
	 * Invoices of a two-line order in a store priced without decimals.
	 *
	 * @return array<string,array{float,int,bool}>
	 */
	public function provider_invoice_payments_in_a_store_without_decimals(): array {
		return array(
			'fractional unit above'        => array( 1000.0, 100040, true ),
			'fractional unit below'        => array( 1269.0, 126873, true ),
			'a unit per line'              => array( 1000.0, 100200, true ),
			'beyond a unit per line'       => array( 1000.0, 100201, false ),
			'beyond a unit per line below' => array( 1000.0, 99799, false ),
		);
	}

	/**
	 * @testdox An invoice payment in a currency other than the store's matches with the decimals that currency is priced in.
	 *
	 * Client 11.3.0 test_does_invoice_payment_match_order_total_uses_multi_currency_decimals().
	 */
	public function test_invoice_payment_in_another_currency_uses_its_price_decimals(): void {
		add_filter(
			'wcpay_huf_format',
			static function ( array $currency_format ): array {
				$currency_format['num_decimals'] = 0;
				return $currency_format;
			}
		);
		$order = $this->create_order_with_two_lines( 1000.0, 'HUF' );

		$this->assertTrue( $this->sut->does_invoice_payment_match_order_total( $order, 100040, 'huf' ) );
	}

	/**
	 * Create an order with a product line and a shipping line, as the client's test helper does.
	 *
	 * @param float  $total    Order total.
	 * @param string $currency Order currency.
	 * @return WC_Order
	 */
	private function create_order_with_two_lines( float $total, string $currency ): WC_Order {
		$order = new WC_Order();
		$order->add_item( new \WC_Order_Item_Product() );
		$order->add_item( new \WC_Order_Item_Shipping() );
		$order->set_currency( $currency );
		$order->set_total( (string) $total );
		$order->save();

		return $order;
	}

	/**
	 * Build a JSON transport response.
	 *
	 * @param int   $http_status HTTP status.
	 * @param mixed $body        Decoded body.
	 * @return array<string,mixed>
	 */
	private function make_response( int $http_status, $body ): array {
		return array(
			'response' => array( 'code' => $http_status ),
			'headers'  => array( 'content-type' => 'application/json; charset=UTF-8' ),
			'body'     => wp_json_encode( $body ),
		);
	}

	/**
	 * Get the platform requests sent, as method, path and decoded body.
	 *
	 * @return array<int,array{0:string,1:string,2:mixed}>
	 */
	private function get_requests(): array {
		return array_map(
			static fn( array $request ) => array( $request['method'], $request['path'], null === $request['body'] ? null : json_decode( (string) $request['body'], true ) ),
			$this->http_client->requests
		);
	}

	/**
	 * Get the texts of an order's notes.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int,string>
	 */
	private function get_order_note_texts( WC_Order $order ): array {
		return array_map( static fn( $note ) => $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}
}
