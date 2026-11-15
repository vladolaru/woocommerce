<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFrontendCurrenciesController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\DisplayService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\GeolocationService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\LocalizationService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\OrderContextService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\RequestContext;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilder;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\TransportLog;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLockRefusedException;
use Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\OrderHelper;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeeDetailsNoteController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingApi;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingInvoiceService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingProductService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingSubscriptionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\OtherChargeRecorder;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\PaymentIntentEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\RefundEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDetailsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderAdminActionsController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Tests\Internal\MultiCurrency\ServiceFactories;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeHttpClient;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\ProviderTextLogAssertions;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\RecordingWcLogger;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\SubscriptionDouble;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use InvalidArgumentException;
use RuntimeException;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Stripe Billing invoice events: renewals paid and failed at Stripe, and the upcoming invoice check.
 *
 * Expectations follow client 11.1.0 (`tests/unit/subscriptions/test-class-wc-payments-subscriptions-event-handler.php` and
 * `includes/subscriptions/class-wc-payments-subscriptions-event-handler.php`, "event handler" below). Event bodies are the
 * local platform recordings in `Fixtures/platform-invoice-events.json`; platform answers are the recordings in
 * `Fixtures/platform-stripe-billing-api.json`. Request bodies are written from the client's array literals, with `test_mode` first
 * as the client's `request()` adds it (`class-wc-payments-api-client.php:2635-2640`).
 */
class StripeBillingEventHandlerTest extends WC_Unit_Test_Case {

	use ProviderTextLogAssertions;

	private const EVENTS_FIXTURE  = __DIR__ . '/../Fixtures/platform-invoice-events.json';
	private const BILLING_FIXTURE = __DIR__ . '/../Fixtures/platform-stripe-billing-api.json';

	/**
	 * Card declines recorded from `POST intentions` on the local platform; their charges stand in for the failed renewal charge, which was not read.
	 */
	private const DECLINES_FIXTURE = __DIR__ . '/../Fixtures/platform-intention-declines.json';

	/**
	 * Blog and account the recordings were made on, and the Stripe product the recorded subscription items are billed under.
	 */
	private const RECORDED_BLOG_ID    = 4;
	private const RECORDED_ACCOUNT_ID = 'acct_1TrY2nBzWlxcwgpP';
	private const RECORDED_PRODUCT_ID = 'prod_VMl10VL0VK371N';

	/**
	 * The recorded test-clock subscription that renewed: its item, parent invoice, and the renewal invoice, intent, charge and transaction.
	 */
	private const CLOCK_SUBSCRIPTION_ID   = 'sub_1UM1XLBzWlxcwgpPwEbcmZJt';
	private const CLOCK_ITEM_ID           = 'si_VMl3Lmi1aDKj2h';
	private const CLOCK_PARENT_INVOICE_ID = 'in_1UM1XLBzWlxcwgpP07gQmTQd';
	private const RENEWAL_INVOICE_ID      = 'in_1UM1XzBzWlxcwgpPNhLoAcln';
	private const RENEWAL_INTENT_ID       = 'pi_3UM1YABzWlxcwgpP0GYqv6LC';
	private const RENEWAL_CHARGE_ID       = 'ch_3UM1YABzWlxcwgpP0Fazmyr5';
	private const RENEWAL_TRANSACTION_ID  = 'txn_3UM1YABzWlxcwgpP0tCiQoKI';
	private const RENEWAL_CUSTOMER_ID     = 'cus_VMl3pvnqTAX638';

	/**
	 * The recorded test-clock subscription whose renewal failed, and its open invoice.
	 */
	private const FAILING_SUBSCRIPTION_ID = 'sub_1UM1XOBzWlxcwgpP3eeyov4u';
	private const FAILED_INVOICE_ID       = 'in_1UM1Y0BzWlxcwgpPefRU4SSy';
	private const FAILED_CHARGE_ID        = 'ch_3UM1YGBzWlxcwgpP1SAxlxl8';

	/**
	 * The System Under Test.
	 *
	 * @var StripeBillingEventHandler
	 */
	private $sut;

	/**
	 * Fake platform transport.
	 *
	 * @var FakeHttpClient
	 */
	private FakeHttpClient $http_client;

	/**
	 * Subscription service the module's status hooks call.
	 *
	 * @var StripeBillingSubscriptionService
	 */
	private StripeBillingSubscriptionService $subscription_service;

	/**
	 * Log lines written, as level, message and source, once for each log handler.
	 *
	 * @var array<int,array{0:string,1:string,2:string}>
	 */
	private array $log_lines = array();

	/**
	 * Log messages written, as message and the class of the log handler that received it.
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	private array $handled_log_messages = array();

	/**
	 * Multi-Currency controller whose frontend price hooks a test registered, removed on tear down.
	 *
	 * @var MultiCurrencyFrontendCurrenciesController|null
	 */
	private ?MultiCurrencyFrontendCurrenciesController $format_controller = null;

	/**
	 * Set up the handler over a fake transport, connected to the recorded account in test mode, with WooPayments logging on.
	 *
	 * The subscription status hooks the module attaches are attached here too, so a change that reached Stripe would show as a request.
	 */
	public function setUp(): void {
		parent::setUp();
		WooCommerceSubscriptionsDoubles::load();
		WooCommerceSubscriptionsDoubles::activate_subscriptions_on_renewal_payment();
		add_filter( 'wcpay_test_mode', '__return_true' );

		$this->http_client          = new FakeHttpClient();
		$this->http_client->blog_id = self::RECORDED_BLOG_ID;

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'is_test_mode_onboarding_enabled', 'get_account_id', 'is_dev_mode_enabled', 'get_gateway_setting' ) )
			->getMock();
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );
		$account_service->method( 'is_test_mode_onboarding_enabled' )->willReturn( true );
		$account_service->method( 'get_account_id' )->willReturn( self::RECORDED_ACCOUNT_ID );
		$account_service->method( 'get_gateway_setting' )->willReturnCallback( fn( string $key ) => 'enable_logging' === $key ? 'yes' : '' );

		$api_client = new Client();
		$api_client->init( $this->http_client, $account_service, wc_get_container()->get( TransportLog::class ) );
		$api = new StripeBillingApi();
		$api->init( $api_client );
		$logger = new WooPaymentsLogger();
		$logger->init( $account_service );

		$product_service = new StripeBillingProductService();
		$product_service->init( $api, $account_service, $logger );

		$module = $this->createMock( StripeBillingModule::class );
		$module->method( 'is_stripe_billing_enabled' )->willReturn( true );
		$invoice_service = new StripeBillingInvoiceService();
		$invoice_service->init( $api, $api_client, $module, $logger );

		$this->subscription_service = new StripeBillingSubscriptionService();
		$this->subscription_service->init( $api, $this->createMock( WooPaymentsCustomerService::class ), $product_service, $invoice_service, $logger, wc_get_container()->get( WooPaymentsActionSchedulerService::class ) );
		wc_get_container()->replace( StripeBillingSubscriptionService::class, $this->subscription_service );

		$this->sut = new StripeBillingEventHandler();
		$this->sut->init( $invoice_service, $this->subscription_service, $api_client, wc_get_container()->get( PaymentIntentEventHandler::class ), $account_service, $logger, wc_get_container()->get( OrderPaymentLifecycleService::class ), wc_get_container()->get( OtherChargeRecorder::class ) );

		add_action( 'woocommerce_subscription_status_on-hold', array( $this->subscription_service, 'handle_subscription_status_on_hold' ) );
		add_action( 'woocommerce_subscription_status_on-hold_to_active', array( $this->subscription_service, 'reactivate_subscription' ) );
		add_action( 'woocommerce_subscription_status_cancelled', array( $this->subscription_service, 'cancel_subscription' ) );

		add_filter(
			'woocommerce_logger_log_message',
			function ( $message, $level, $context, $handler = null ) {
				$this->log_lines[]            = array( (string) $level, (string) $message, (string) ( $context['source'] ?? '' ) );
				$this->handled_log_messages[] = array( (string) $message, is_object( $handler ) ? get_class( $handler ) : '' );
				return $message;
			},
			10,
			4
		);
	}

	/**
	 * Clear the registries the doubles read.
	 */
	public function tearDown(): void {
		try {
			// setUp() and the multi-currency case replace container services that production code resolves.
			$this->reset_container_replacements();
			if ( null !== $this->format_controller ) {
				foreach ( array( 'woocommerce_currency', 'wc_get_price_decimals', 'wc_get_price_decimal_separator', 'wc_get_price_thousand_separator', 'woocommerce_price_format', 'option_woocommerce_currency_pos', 'woocommerce_order_get_total', 'woocommerce_get_formatted_order_total', 'woocommerce_thankyou_order_id', 'woocommerce_cart_hash', 'woocommerce_shipping_method_add_rate_args', 'before_woocommerce_pay', 'woocommerce_account_view-order_endpoint' ) as $hook ) {
					remove_all_filters( $hook, 900 );
				}
				$this->format_controller = null;
			}
			delete_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION );
			wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
			unset(
				$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ],
				$GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ],
				$GLOBALS[ WooCommerceSubscriptionsDoubles::RENEWAL_SUBSCRIPTIONS ],
				$GLOBALS[ WooCommerceSubscriptionsDoubles::DUPLICATE_SITE ],
				$GLOBALS[ WooCommerceSubscriptionsDoubles::RENEWAL_ORDER_ERROR ],
				$GLOBALS[ WooCommerceSubscriptionsDoubles::BEFORE_RELATED_ORDER_FAILURE ]
			);
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox A paid renewal invoice creates the renewal order once and records the payment as any succeeded intent, without pausing or resuming the Stripe subscription (client test_handle_invoice_paid).
	 *
	 * Event handler :146-201: on hold with the on-hold hook removed (:165-167), completed with the reactivation hook removed
	 * (:173-175), pending invoice cleared (:192), then `subscription_context` (`class-wc-payments-invoice-service.php:315`),
	 * the charge's order ID (`:366`) and the transaction's billing details (`:342-345`). The intent answer is the recorded renewal
	 * intent as the platform forwarded it, the same Stripe object `GET intentions/{id}` answers with.
	 */
	public function test_paid_renewal_invoice_records_the_renewal_order(): void {
		$subscription          = $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID, array( '_wcpay_pending_invoice_id' => self::FAILED_INVOICE_ID ) );
		$transitions           = $this->record_subscription_transitions();
		$subscription_payments = did_action( 'woocommerce_subscription_payment_complete' );
		$this->queue_response( 200, $this->get_renewal_intent() );
		$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );

		$this->sut->handle_event( $this->get_event( 'invoice_paid_renewal' ) );

		$orders = $this->get_renewal_orders( self::RENEWAL_INVOICE_ID );
		$this->assertCount( 1, $orders );
		$order = $orders[0];
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( 'woocommerce_payments', $order->get_payment_method() );
		$this->assertSame( self::RENEWAL_INTENT_ID, $order->get_transaction_id() );
		$this->assertSame( self::RENEWAL_INTENT_ID, $order->get_meta( '_intent_id', true ) );
		$this->assertSame( self::RENEWAL_CHARGE_ID, $order->get_meta( '_charge_id', true ) );
		$this->assertSame( self::RENEWAL_CUSTOMER_ID, $order->get_meta( '_stripe_customer_id', true ), 'The client stores the intent customer (`class-wc-payments-order-service.php:1360`).' );
		$this->assertSame( 'USD', $order->get_meta( '_wcpay_intent_currency', true ), 'The client attaches the fetched intent, whose model upper-cases the currency (`class-wc-payments-order-service.php:1414`, `class-wc-payments-api-payment-intention.php:93`); live client renewal 27 stored USD.' );
		$this->assertCount( 1, $this->get_notes_containing( $order, 'A test payment of' ), 'A test-mode renewal gets the test wording.' );
		$this->assertTrue(
			as_has_scheduled_action(
				WooPaymentsFeeDetailsNoteController::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION,
				array(
					'order_id'     => $order->get_id(),
					'intent_id'    => self::RENEWAL_INTENT_ID,
					'is_test_mode' => true,
				),
				'woocommerce_payments'
			),
			'The renewal gets its Fee details note from the job its payment note schedules (sweep row 177, decided).'
		);

		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertSame( 'active', $subscription->get_status() );
		$this->assertSame( '', $subscription->get_meta( '_wcpay_pending_invoice_id', true ) );
		$this->assertSame( array( 'on-hold', 'on-hold_to_active' ), $transitions->list, 'The renewal goes through on hold, as a renewal WooCommerce Subscriptions charges does.' );
		$this->assertSame( 1, did_action( 'woocommerce_subscription_payment_complete' ) - $subscription_payments, 'WooCommerce Subscriptions records the renewal payment on the subscription once.' );
		$this->assertSame(
			array(
				array( 'GET', '/sites/4/wcpay/intentions/' . self::RENEWAL_INTENT_ID . '?test_mode=1', null ),
				array(
					'POST',
					'/sites/4/wcpay/invoices/' . self::RENEWAL_INVOICE_ID,
					array(
						'test_mode'            => true,
						'subscription_context' => 'stripe_billing',
					),
				),
				array(
					'POST',
					'/sites/4/wcpay/charges/' . self::RENEWAL_CHARGE_ID,
					array(
						'test_mode' => true,
						'metadata'  => array( 'order_id' => $order->get_id() ),
					),
				),
				array( 'GET', '/sites/4/wcpay/charges/' . self::RENEWAL_CHARGE_ID . '?test_mode=1', null ),
				array(
					'POST',
					'/sites/4/wcpay/transactions/' . self::RENEWAL_TRANSACTION_ID,
					array(
						'test_mode'           => true,
						'customer_first_name' => 'Rec',
						'customer_last_name'  => 'Renewal',
						'customer_email'      => 'rec-t63-renewal@woo.test',
						'customer_country'    => 'US',
					),
				),
			),
			$this->get_requests(),
			'Neither the on-hold step nor the reactivation reaches the Stripe subscription.'
		);
	}

	/**
	 * @testdox A paid renewal invoice that does not match its renewal holds the renewal with the invoice note once, records the payment, and leaves the subscription as it is, delivered twice.
	 *
	 * Client 11.3.0 class-wc-payments-subscriptions-event-handler.php:163-172 (on hold and the note when the renewal needs
	 * payment and does not match), :200-203 (the intent attached on every delivery), pinned by its
	 * test_handle_invoice_paid_holds_order_when_amount_does_not_match_order_total() and
	 * test_handle_invoice_paid_holds_order_once_when_delivered_twice().
	 */
	public function test_paid_renewal_invoice_that_does_not_match_holds_the_renewal_once(): void {
		$subscription       = $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID, array( '_wcpay_pending_invoice_id' => self::FAILED_INVOICE_ID ) );
		$transitions        = $this->record_subscription_transitions();
		$payments_completed = did_action( 'woocommerce_payment_complete' );
		for ( $delivery = 1; $delivery <= 2; $delivery++ ) {
			$this->queue_response( 200, $this->get_renewal_intent() );
			$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );
		}
		$event = $this->get_event( 'invoice_paid_renewal', array( 'amount_paid' => 2000 ) );

		$this->sut->handle_event( $event );
		$this->sut->handle_event( $event );

		$orders = $this->get_renewal_orders( self::RENEWAL_INVOICE_ID );
		$this->assertCount( 1, $orders );
		$order = $orders[0];
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertFalse( $order->is_paid() );
		$this->assertSame( 0, did_action( 'woocommerce_payment_complete' ) - $payments_completed );
		$this->assertSame( self::RENEWAL_INTENT_ID, $order->get_transaction_id() );
		$this->assertSame( self::RENEWAL_INTENT_ID, $order->get_meta( '_intent_id', true ) );
		$this->assertSame( self::RENEWAL_CHARGE_ID, $order->get_meta( '_charge_id', true ) );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );
		$notes = $this->get_notes_containing( $order, 'The renewal invoice ' . self::RENEWAL_INVOICE_ID . ' collected' );
		$this->assertCount( 1, $notes );
		$this->assertSame( 'The renewal invoice ' . self::RENEWAL_INVOICE_ID . ' collected $20.00 USD, but the order total is $24.90 USD. The order was put <strong>on hold</strong>; review it before fulfilling.', $notes[0] );
		$this->assertCount( 0, $this->get_notes_containing( $order, 'A test payment of' ) );
		$this->assertSame( 'active', wc_get_order( $subscription->get_id() )->get_status() );
		$this->assertSame( array(), $transitions->list, 'The subscription is not put on hold or reactivated.' );
	}

	/**
	 * @testdox A paid renewal invoice without its $missing is refused before any renewal order is created.
	 *
	 * Client 11.3.0 class-wc-payments-subscriptions-event-handler.php:146-147 reads both through get_event_property(),
	 * which throws (:337-349); pinned by its test_handle_invoice_paid_rejects_missing_payment_data().
	 *
	 * @testWith ["amount_paid"]
	 *           ["currency"]
	 *
	 * @param string $missing Missing invoice field.
	 */
	public function test_paid_renewal_invoice_without_its_payment_data_is_refused( string $missing ): void {
		$this->create_subscription( self::CLOCK_SUBSCRIPTION_ID );

		$refusal = null;
		try {
			$this->sut->handle_event( $this->get_event( 'invoice_paid_renewal', array( $missing => null ) ) );
		} catch ( InvalidArgumentException $exception ) {
			$refusal = $exception->getPrevious();
		}

		$this->assertInstanceOf( StripeBillingException::class, $refusal, 'An invoice.paid without its payment data must be refused as invalid event data.' );
		$this->assertSame( StripeBillingException::INVALID_EVENT_DATA, $refusal->get_error_code() );

		$this->assertSame( array(), $this->get_renewal_orders( self::RENEWAL_INVOICE_ID ) );
		$this->assertSame( array(), $this->get_requests() );
	}

	/**
	 * @testdox The invoice of the subscription's first order is ignored even without its payment data.
	 *
	 * Client 11.3.0 checks the parent invoice (class-wc-payments-subscriptions-event-handler.php:141-144) before it reads
	 * the payment data; pinned by its test_handle_invoice_paid_ignores_parent_invoice_without_payment_data().
	 */
	public function test_parent_invoice_without_payment_data_is_ignored(): void {
		$this->create_subscription( self::CLOCK_SUBSCRIPTION_ID );

		$this->sut->handle_event(
			$this->get_event(
				'invoice_paid_parent',
				array(
					'amount_paid' => null,
					'currency'    => null,
				)
			)
		);

		$this->assertSame( array(), $this->get_renewal_orders( self::CLOCK_PARENT_INVOICE_ID ) );
		$this->assertSame( array(), $this->get_requests() );
	}

	/**
	 * @testdox invoice.paid decides on the renewal read again under the order payment lock: $_dataName.
	 *
	 * The invoice is compared with the renewal read under the order payment lock. Client 11.3.0 compares the invoice with the
	 * renewal it then updates (class-wc-payments-subscriptions-event-handler.php:162-198).
	 *
	 * @testWith ["24.90", "30.00", "on-hold", false]
	 *           ["30.00", "24.90", "processing", true]
	 *
	 * @param string $first_total    Renewal total when the event reads it.
	 * @param string $stored_total   Renewal total stored when the order payment lock is claimed.
	 * @param string $expected       Renewal status after the event.
	 * @param bool   $expected_paid  Whether the renewal is paid.
	 */
	public function test_paid_invoice_decides_on_the_renewal_read_under_the_lock( string $first_total, string $stored_total, string $expected, bool $expected_paid ): void {
		$subscription = $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID );
		$renewal      = $this->create_unpaid_renewal_order( $subscription, $first_total );
		$this->change_the_renewal_while_the_intent_is_read( $renewal->get_id(), static fn( WC_Order $other ) => $other->set_total( $stored_total ) );
		$this->queue_response( 200, $this->get_renewal_intent() );
		$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );

		$this->sut->handle_event( $this->get_event( 'invoice_paid_renewal' ) );

		$renewal = wc_get_order( $renewal->get_id() );
		$this->assertSame( $expected, $renewal->get_status() );
		$this->assertSame( $expected_paid, $renewal->is_paid() );
		$this->assertCount( $expected_paid ? 0 : 1, $this->get_notes_containing( $renewal, 'collected $24.90 USD, but the order total is $30.00 USD' ) );
	}

	/**
	 * @testdox A renewal whose invoice does not match and whose intent cannot be read is held through the payment lifecycle: $_dataName.
	 *
	 * Client 11.3.0 holds it with the note (class-wc-payments-subscriptions-event-handler.php:163-172) and notes that the
	 * payment info could not be added (class-wc-payments-invoice-service.php:334-343). The hold is the lifecycle's: the
	 * transaction ID, one hold note however often the invoice arrives, and nothing on a renewal another request paid. That
	 * renewal records another WooPayments payment, so it gets one note that the customer was charged twice, built from the
	 * invoice, in place of the payment-info notes.
	 *
	 * @testWith ["unpaid", "on-hold", 1, 2, 0]
	 *           ["paid by another request while the intent is read", "processing", 0, 0, 1]
	 *
	 * @param string $renewal_state         Renewal state when the intent is read.
	 * @param string $expected              Renewal status after two deliveries.
	 * @param int    $expected_holds        Hold notes.
	 * @param int    $expected_info         Payment-info notes.
	 * @param int    $expected_charged_twice Notes that the customer was charged twice.
	 */
	public function test_paid_invoice_with_an_unreadable_intent_holds_a_mismatched_renewal_through_the_lifecycle( string $renewal_state, string $expected, int $expected_holds, int $expected_info, int $expected_charged_twice ): void {
		$subscription = $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID );
		$renewal      = $this->create_unpaid_renewal_order( $subscription, '30.00' );
		if ( 'unpaid' !== $renewal_state ) {
			$this->change_the_renewal_while_the_intent_is_read( $renewal->get_id(), static fn( WC_Order $other ) => $other->payment_complete( 'pi_other_payment' ) );
		}
		$this->queue_platform_error();
		$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );
		$this->queue_platform_error();
		$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );
		$event = $this->get_event( 'invoice_paid_renewal', array( 'charge' => self::RENEWAL_CHARGE_ID ) );

		$this->sut->handle_event( $event );
		$this->sut->handle_event( $event );

		$renewal = wc_get_order( $renewal->get_id() );
		$this->assertSame( $expected, $renewal->get_status() );
		$this->assertCount( $expected_holds, $this->get_notes_containing( $renewal, 'collected $24.90 USD, but the order total is $30.00 USD' ) );
		$this->assertCount( $expected_info, $this->get_notes_containing( $renewal, "The payment info couldn't be added to the order." ) );
		$this->assertCount( $expected_charged_twice, $this->get_notes_containing( $renewal, 'already paid by payment <code>pi_other_payment</code>, so the customer was charged twice' ) );
		if ( 'unpaid' === $renewal_state ) {
			$this->assertSame( self::RENEWAL_INTENT_ID, $renewal->get_transaction_id() );
			$this->assertSame( self::RENEWAL_INTENT_ID, $renewal->get_meta( '_intent_id', true ) );
			$this->assertSame( self::RENEWAL_CHARGE_ID, $renewal->get_meta( '_charge_id', true ) );
		}
	}

	/**
	 * @testdox A paid invoice for a renewal that is neither paid nor payable records the payment, completes nothing, and notes the invoice once: $_dataName.
	 *
	 * Client 11.3.0 only attaches the intent once the renewal no longer needs payment
	 * (class-wc-payments-subscriptions-event-handler.php:200-203; class-wc-payments-invoice-service.php:334-346), and its
	 * attach writes nothing once the stored intent status is succeeded (class-wc-payments-order-service.php:1818-1822). The
	 * client adds no note; the note is the recorded departure for a renewal neither paid nor payable: it says where the
	 * order screen's refund goes, read after the writes, and, while Stripe Billing keeps charging the subscription (on hold
	 * or active), that it does until the subscription is cancelled. Delivered twice.
	 *
	 * @dataProvider provider_renewals_that_no_longer_need_payment
	 *
	 * @param string              $status              Renewal status.
	 * @param array<string,mixed> $meta                Renewal meta.
	 * @param string              $transaction_id      Renewal transaction ID.
	 * @param string              $subscription_status Subscription status.
	 * @param string|null         $expected_refund     The note's refund instruction, or null for no note.
	 * @param bool                $expected_sentence   Whether the note says Stripe Billing keeps charging.
	 * @param string[]            $expected_record     Stored intent, stored charge and transaction ID after the event.
	 */
	public function test_paid_invoice_records_a_renewal_that_no_longer_needs_payment_and_completes_nothing( string $status, array $meta, string $transaction_id, string $subscription_status, ?string $expected_refund, bool $expected_sentence, array $expected_record ): void {
		$subscription = $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID, array(), $subscription_status );
		$renewal      = $this->create_unpaid_renewal_order( $subscription, '24.90' );
		foreach ( $meta as $key => $value ) {
			$renewal->update_meta_data( $key, $value );
		}
		$renewal->set_transaction_id( $transaction_id );
		$renewal->set_status( $status );
		$renewal->save();
		$this->assertNull( $renewal->get_date_paid() );
		$notes_before = $this->get_note_contents( $renewal );
		$payments     = did_action( 'woocommerce_payment_complete' );
		for ( $delivery = 1; $delivery <= 2; $delivery++ ) {
			$this->queue_response( 200, $this->get_renewal_intent() );
			$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );
		}
		$event = $this->get_event( 'invoice_paid_renewal' );

		$this->sut->handle_event( $event );
		$this->sut->handle_event( $event );

		$renewal = wc_get_order( $renewal->get_id() );
		$this->assertSame( $status, $renewal->get_status() );
		$this->assertSame( 0, did_action( 'woocommerce_payment_complete' ) - $payments );
		$this->assertSame( $expected_record, array( $renewal->get_meta( '_intent_id', true ), $renewal->get_meta( '_charge_id', true ), $renewal->get_transaction_id() ) );
		$new_notes = array_values( array_diff( $this->get_note_contents( $renewal ), $notes_before ) );
		if ( null === $expected_refund ) {
			$this->assertSame( array(), $new_notes );
			return;
		}
		$this->assertCount( 1, $new_notes, 'One note however often the invoice arrives.' );
		$this->assertStringStartsWith( 'Stripe Billing collected renewal invoice <code>' . self::RENEWAL_INVOICE_ID . '</code> for ', $new_notes[0] );
		$this->assertStringContainsString( 'while this order&#039;s status was ' . wc_get_order_status_name( $status ) . '. The order status was not changed. ' . $expected_refund, $new_notes[0] );
		$this->assertStringContainsString( self::RENEWAL_CHARGE_ID, $new_notes[0] );
		$sentence = 'Stripe Billing will keep charging subscription #' . $subscription->get_id() . ' at each renewal until the subscription is cancelled.';
		$this->assertSame( $expected_sentence, str_ends_with( $new_notes[0], ' ' . $sentence ) );
		$this->assertSame( 0, $this->count_log_lines( 'other charge recorded' ), 'A renewal neither paid nor payable on WooPayments records the invoice as its payment.' );
	}

	/**
	 * Renewals that are neither paid nor payable when their invoice is paid.
	 *
	 * @return array<string,array{string,array<string,mixed>,string,string,?string,bool,string[]}>
	 */
	public function provider_renewals_that_no_longer_need_payment(): array {
		$from_order            = 'If the customer should not have been charged, refund the payment from this order.';
		$from_transaction_page = 'If the customer should not have been charged, refund the charge from its WooPayments transaction page, not from this order.';
		$invoice_recorded      = array( self::RENEWAL_INTENT_ID, self::RENEWAL_CHARGE_ID, self::RENEWAL_INTENT_ID );

		return array(
			'held for its invoice, now matching'           => array(
				'on-hold',
				array(
					'_intention_status' => 'succeeded',
					'_intent_id'        => self::RENEWAL_INTENT_ID,
					'_charge_id'        => self::RENEWAL_CHARGE_ID,
				),
				'',
				'active',
				null,
				false,
				array( self::RENEWAL_INTENT_ID, self::RENEWAL_CHARGE_ID, '' ),
			),
			'refunded after its invoice was recorded'      => array(
				'refunded',
				array(
					'_intention_status' => 'succeeded',
					'_intent_id'        => self::RENEWAL_INTENT_ID,
					'_charge_id'        => self::RENEWAL_CHARGE_ID,
				),
				'',
				'active',
				null,
				false,
				array( self::RENEWAL_INTENT_ID, self::RENEWAL_CHARGE_ID, '' ),
			),
			'on hold for another reason'                   => array( 'on-hold', array(), '', 'on-hold', $from_order, true, $invoice_recorded ),
			'cancelled'                                    => array( 'cancelled', array(), '', 'on-hold', $from_order, true, $invoice_recorded ),
			'cancelled, subscription active'               => array( 'cancelled', array(), '', 'active', $from_order, true, $invoice_recorded ),
			'cancelled, subscription pending cancellation' => array( 'cancelled', array(), '', 'pending-cancel', $from_order, false, $invoice_recorded ),
			'cancelled after a declined attempt'           => array(
				'cancelled',
				array(
					'_intent_id'        => 'pi_declined',
					'_intention_status' => 'requires_payment_method',
				),
				'pi_declined',
				'on-hold',
				$from_order,
				true,
				$invoice_recorded,
			),
			'on hold storing another payment'              => array(
				'on-hold',
				array(
					'_intent_id'        => 'pi_held',
					'_charge_id'        => 'ch_held',
					'_intention_status' => 'succeeded',
				),
				'',
				'on-hold',
				$from_transaction_page,
				true,
				array( 'pi_held', 'ch_held', '' ),
			),
		);
	}

	/**
	 * @testdox A paid invoice for a renewal neither paid nor payable follows what the renewal can still refund: $_dataName.
	 *
	 * Delivered twice. A renewal that can no longer refund the invoice's amount, as after a change to Refunded, would refuse
	 * a refund of the invoice's payment on the order screen and on the charge's transaction page alike. So nothing is
	 * written, and the note sends the refund to the transaction page, which then refunds at the platform. A renewal whose
	 * remainder covers the invoice gets the payment recorded and the note that says to refund it from the order.
	 *
	 * @testWith ["refunded by a status change, never paid", "status change", "24.90", "", "refunded", false]
	 *           ["paid by hand, then refunded in full by hand", "paid by hand", "24.90", "24.90", "refunded", false]
	 *           ["part refunded, then cancelled, the remainder below the invoice", "paid by hand", "24.90", "10.00", "cancelled", false]
	 *           ["part refunded, then cancelled, the remainder equal to the invoice", "paid by hand", "34.90", "10.00", "cancelled", true]
	 *           ["part refunded, then cancelled, the remainder above the invoice", "paid by hand", "44.90", "10.00", "cancelled", true]
	 *
	 * @param string $name              Case name.
	 * @param string $produced_by       How the renewal came to be neither paid nor payable after its attempt failed.
	 * @param string $total             Renewal total.
	 * @param string $refund            Amount refunded by hand, without the gateway, or an empty string for none.
	 * @param string $expected_status   Renewal status before and after the invoice.
	 * @param bool   $expected_recorded Whether the invoice's payment is recorded on the renewal.
	 */
	public function test_paid_invoice_on_a_renewal_neither_paid_nor_payable_follows_what_it_can_still_refund( string $name, string $produced_by, string $total, string $refund, string $expected_status, bool $expected_recorded ): void {
		unset( $name );
		$subscription = $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID, array(), 'on-hold' );
		$renewal      = $this->create_unpaid_renewal_order( $subscription, $total );
		$renewal->update_status( 'failed' );
		if ( 'status change' === $produced_by ) {
			// Core's own full refund on a change to Refunded (wc_order_fully_refunded()).
			$renewal->update_status( 'refunded' );
		} else {
			// Marked paid by hand on the order screen, WooPayments kept and no reference typed, then refunded by hand.
			$renewal->update_status( 'processing' );
			wc_create_refund(
				array(
					'order_id'       => $renewal->get_id(),
					'amount'         => $refund,
					'refund_payment' => false,
				)
			);
			$renewal = wc_get_order( $renewal->get_id() );
			if ( 'cancelled' === $expected_status ) {
				$renewal->update_status( 'cancelled' );
			}
		}
		$renewal = wc_get_order( $renewal->get_id() );
		$this->assertSame( $expected_status, $renewal->get_status() );
		$this->assertSame( 'status change' !== $produced_by, null !== $renewal->get_date_paid() );
		$this->assertSame( '', $renewal->get_transaction_id() );
		$this->assertGreaterThan( 0, (float) $renewal->get_total_refunded() );
		$before       = $this->get_payment_record( $renewal );
		$notes_before = $this->get_note_contents( $renewal );
		for ( $delivery = 1; $delivery <= 2; $delivery++ ) {
			$this->queue_response( 200, $this->get_renewal_intent() );
			$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );
		}
		$event = $this->get_event( 'invoice_paid_renewal' );

		$this->sut->handle_event( $event );
		$this->sut->handle_event( $event );

		$renewal   = wc_get_order( $renewal->get_id() );
		$new_notes = array_values( array_diff( $this->get_note_contents( $renewal ), $notes_before ) );
		$this->assertCount( 1, $new_notes, 'One note however often the invoice arrives.' );
		$this->assertStringStartsWith( 'Stripe Billing collected renewal invoice <code>' . self::RENEWAL_INVOICE_ID . '</code> for ', $new_notes[0] );
		$this->assertStringContainsString( '&#36;</span>24.90', $new_notes[0], 'The invoice amount.' );
		$this->assertStringContainsString( '>' . self::RENEWAL_CHARGE_ID . '</a>)', $new_notes[0] );
		$refund_from = $expected_recorded
			? 'If the customer should not have been charged, refund the payment from this order.'
			: 'If the customer should not have been charged, refund the charge from its WooPayments transaction page, not from this order.';
		$this->assertStringContainsString( 'while this order&#039;s status was ' . wc_get_order_status_name( $expected_status ) . '. The order status was not changed. ' . $refund_from . ' Stripe Billing will keep charging subscription #', $new_notes[0] );
		$charge = wc_get_container()->get( WooPaymentsOrderDetailsService::class )->enrich_charge_response( array( 'id' => self::RENEWAL_CHARGE_ID ) );
		if ( $expected_recorded ) {
			$this->assertSame( array( self::RENEWAL_INTENT_ID, self::RENEWAL_CHARGE_ID, self::RENEWAL_INTENT_ID ), array( $renewal->get_meta( '_intent_id', true ), $renewal->get_meta( '_charge_id', true ), $renewal->get_transaction_id() ) );
			$this->assertSame( 0, $this->count_log_lines( 'other charge recorded' ) );
			$this->assertSame( $renewal->get_id(), $charge['order']['id'] ?? null, 'The invoice\'s charge links the renewal, whose refund can cover it.' );
			return;
		}
		$this->assertSame( $before, $this->get_payment_record( $renewal ), 'Nothing is written: no mode, customer, intent, charge, fee or transaction ID.' );
		$this->assertSame( 2, $this->count_log_lines( 'other charge recorded: order ' . $renewal->get_id() . ', invoice.paid, charge ' . self::RENEWAL_CHARGE_ID ), 'One warning line per delivery.' );
		$this->assertSame( array(), $charge['order'], 'The invoice\'s charge links no order, so its transaction page refunds it at the platform.' );
	}

	/**
	 * @testdox A paid invoice for a renewal already in a paid status adds the payment's success note and Fee details job once, and leaves its status: $_dataName.
	 *
	 * Delivered twice. The renewal was paid by another payment, such as its new payment method, before its invoice event.
	 * One that was partly refunded by hand, so that it can no longer refund the invoice's amount, still takes this path.
	 *
	 * @testWith ["processing", ""]
	 *           ["completed", ""]
	 *           ["processing", "10.00"]
	 *
	 * @param string $status Paid renewal status.
	 * @param string $refund Amount refunded by hand, without the gateway, before the invoice, or an empty string for none.
	 */
	public function test_paid_invoice_notes_a_renewal_in_a_paid_status_once( string $status, string $refund ): void {
		$order = $this->create_paid_renewal_order( $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID ) );
		$order->set_status( $status );
		$order->save();
		if ( '' !== $refund ) {
			wc_create_refund(
				array(
					'order_id'       => $order->get_id(),
					'amount'         => $refund,
					'refund_payment' => false,
				)
			);
			$order = wc_get_order( $order->get_id() );
			$this->assertLessThan( 24.90, (float) $order->get_remaining_refund_amount() );
			$this->assertSame( $status, $order->get_status() );
		}
		$payments = did_action( 'woocommerce_payment_complete' );
		$this->queue_response( 200, $this->get_renewal_intent() );
		$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );
		$this->queue_response( 200, $this->get_renewal_intent() );
		$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );
		$event = $this->get_event( 'invoice_paid_renewal' );

		$this->sut->handle_event( $event );
		$this->sut->handle_event( $event );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( $status, $order->get_status() );
		$this->assertSame( 0, did_action( 'woocommerce_payment_complete' ) - $payments );
		$this->assertCount( 1, $this->get_notes_containing( $order, 'A test payment of' ) );
		$this->assertCount(
			1,
			as_get_scheduled_actions(
				array(
					'hook'  => WooPaymentsFeeDetailsNoteController::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION,
					'args'  => array(
						'order_id'     => $order->get_id(),
						'intent_id'    => self::RENEWAL_INTENT_ID,
						'is_test_mode' => true,
					),
					'group' => 'woocommerce_payments',
				),
				'ids'
			)
		);
		$this->assertSame( self::RENEWAL_INTENT_ID, $order->get_meta( '_intent_id', true ) );
		$this->assertSame( array(), $this->get_notes_containing( $order, 'Stripe Billing collected' ) );
		$this->assertSame( 0, $this->count_log_lines( 'other charge recorded' ) );
	}

	/**
	 * @testdox A paid invoice above the total of a failed renewal holds the renewal with its payment recorded and adds no invoice note.
	 *
	 * The renewal still needs payment, so the invoice is its payment: it is held for the mismatch, as client 11.3.0 holds it
	 * (class-wc-payments-subscriptions-event-handler.php:163-172), even though the order could not refund the whole invoice.
	 */
	public function test_paid_invoice_above_a_failed_renewals_total_holds_it_with_its_payment(): void {
		$renewal = $this->create_unpaid_renewal_order( $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID ), '20.00' );
		$renewal->update_status( 'failed' );
		$this->queue_response( 200, $this->get_renewal_intent() );
		$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );

		$this->sut->handle_event( $this->get_event( 'invoice_paid_renewal' ) );

		$renewal = wc_get_order( $renewal->get_id() );
		$this->assertSame( 'on-hold', $renewal->get_status() );
		$this->assertSame( array( self::RENEWAL_INTENT_ID, self::RENEWAL_CHARGE_ID, 'succeeded', self::RENEWAL_INTENT_ID ), array( $renewal->get_meta( '_intent_id', true ), $renewal->get_meta( '_charge_id', true ), $renewal->get_meta( '_intention_status', true ), $renewal->get_transaction_id() ) );
		$this->assertCount( 1, $this->get_notes_containing( $renewal, 'collected $24.90 USD, but the order total is $20.00 USD' ) );
		$this->assertSame( array(), $this->get_notes_containing( $renewal, 'Stripe Billing collected' ) );
		$this->assertSame( 0, $this->count_log_lines( 'other charge recorded' ) );
	}

	/**
	 * @testdox A paid invoice for a renewal that records another payment writes nothing and adds one note naming both: $_dataName.
	 *
	 * Delivered twice. Client 11.3.0 attaches the invoice's intent to every renewal (class-wc-payments-subscriptions-event-handler.php:200-203,
	 * class-wc-payments-invoice-service.php:334-345): it skips on a stored succeeded (class-wc-payments-order-service.php:1818-1822)
	 * and otherwise writes the intent, charge and transaction ID over the order's (:1873-1884), with no note. Here the renewal
	 * keeps what it recorded, and the note is the recorded departure: "charged twice" only when the transaction ID names a
	 * WooPayments payment, "may have paid twice" for another payment method or a typed reference, and, on another
	 * gateway's renewal never paid, where to refund the charge. The note is the other-charge record, with its warning line.
	 *
	 * @dataProvider provider_renewals_that_record_another_payment
	 *
	 * @param string              $status          Renewal status.
	 * @param string              $payment_method  Renewal payment method.
	 * @param string              $transaction_id  Renewal transaction ID.
	 * @param array<string,mixed> $meta            Renewal meta.
	 * @param bool                $paid_date       Whether the renewal has a paid date.
	 * @param bool                $intent_readable Whether the invoice's intent can be read.
	 * @param string              $expected        The note's text after the invoice's charge, amount and invoice.
	 */
	public function test_paid_invoice_on_a_renewal_that_records_another_payment_only_notes_it( string $status, string $payment_method, string $transaction_id, array $meta, bool $paid_date, bool $intent_readable, string $expected ): void {
		$subscription = $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID, array(), 'on-hold' );
		$renewal      = $this->create_unpaid_renewal_order( $subscription, '24.90' );
		$renewal->set_payment_method( $payment_method );
		$renewal->set_payment_method_title( 'woocommerce_payments' === $payment_method ? 'Card' : ( 'cheque' === $payment_method ? 'Check payments' : '' ) );
		$renewal->set_transaction_id( $transaction_id );
		foreach ( $meta as $key => $value ) {
			$renewal->update_meta_data( $key, $value );
		}
		$renewal->set_status( $status );
		$renewal->set_date_paid( $paid_date ? time() - DAY_IN_SECONDS : null );
		$renewal->save();
		$renewal = wc_get_order( $renewal->get_id() );
		$this->assertSame( $paid_date, null !== $renewal->get_date_paid() );
		$before         = $this->get_payment_record( $renewal );
		$notes_before   = $this->get_note_contents( $renewal );
		$hooks_before   = did_action( 'woocommerce_pre_payment_complete' ) + did_action( 'woocommerce_payment_complete_order_status_' . $status );
		$actions_before = $this->count_fee_details_actions( $renewal );
		for ( $delivery = 1; $delivery <= 2; $delivery++ ) {
			if ( $intent_readable ) {
				$this->queue_response( 200, $this->get_renewal_intent() );
			} else {
				$this->queue_platform_error();
			}
			$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );
		}
		$event = $this->get_event( 'invoice_paid_renewal' );

		$this->sut->handle_event( $event );
		$this->sut->handle_event( $event );

		$renewal   = wc_get_order( $renewal->get_id() );
		$new_notes = array_values( array_diff( $this->get_note_contents( $renewal ), $notes_before ) );
		$this->assertCount( 1, $new_notes, 'One note however often the invoice arrives, and no success or payment-info note.' );
		$this->assertStringStartsWith( 'Stripe Billing collected renewal invoice <code>' . self::RENEWAL_INVOICE_ID . '</code> for ', $new_notes[0] );
		$this->assertStringContainsString( '&#36;</span>24.90', $new_notes[0], 'The invoice amount.' );
		$this->assertStringContainsString( '>' . self::RENEWAL_CHARGE_ID . '</a>)', $new_notes[0] );
		$this->assertStringContainsString( $expected, $new_notes[0] );
		$this->assertSame( $before, $this->get_payment_record( $renewal ), 'Nothing the renewal recorded is written over: no mode, customer, intent, charge, fee or transaction ID.' );
		$this->assertSame( $status, $renewal->get_status() );
		$this->assertSame( $hooks_before, did_action( 'woocommerce_pre_payment_complete' ) + did_action( 'woocommerce_payment_complete_order_status_' . $status ), 'Neither completion hook fires.' );
		$this->assertSame( $actions_before, $this->count_fee_details_actions( $renewal ), 'No Fee details job.' );
		$this->assertSame( 2, $this->count_log_lines( 'other charge recorded: order ' . $renewal->get_id() . ', invoice.paid, charge ' . self::RENEWAL_CHARGE_ID ), 'One warning line per delivery.' );
		$charge = wc_get_container()->get( WooPaymentsOrderDetailsService::class )->enrich_charge_response( array( 'id' => self::RENEWAL_CHARGE_ID ) );
		$this->assertSame( array(), $charge['order'], 'The invoice\'s charge links no order, so its transaction page refunds it at the platform.' );
	}

	/**
	 * Renewals that record another payment than the invoice's, with the note each gets.
	 *
	 * @return array<string,array{string,string,string,array<string,mixed>,bool,bool,string}>
	 */
	public function provider_renewals_that_record_another_payment(): array {
		$paid_by     = static fn( string $payment ): string => 'after this order was already paid by payment <code>' . $payment . '</code>, so the customer was charged twice. To refund the second charge, open it on its WooPayments transaction page. A refund from this order goes to the first payment.';
		$reference   = static fn( string $reference ): string => 'after this order was already marked paid with reference <code>' . $reference . '</code>, so the customer may have paid twice. To refund the WooPayments charge, open it on its WooPayments transaction page, not from this order.';
		$paid_with   = static fn( string $title ): string => 'after this order was already paid with ' . $title . ', so the customer may have paid twice. To refund the WooPayments charge, open it on its WooPayments transaction page, not from this order.';
		$retry_paid  = array(
			'_intent_id'             => 'pi_retry',
			'_charge_id'             => 'ch_retry',
			'_intention_status'      => 'succeeded',
			'_wcpay_transaction_fee' => '1.02',
			'_wcpay_net'             => '23.88',
		);
		$woopayments = 'woocommerce_payments';

		return array(
			'paid by a retry'                            => array( 'processing', $woopayments, 'pi_retry', $retry_paid, true, true, $paid_by( 'pi_retry' ) ),
			'paid by a retry, storing a later declined status' => array( 'processing', $woopayments, 'pi_retry', array_merge( $retry_paid, array( '_intention_status' => 'requires_payment_method' ) ), true, true, $paid_by( 'pi_retry' ) ),
			'storing an authorization beside another payment' => array( 'processing', $woopayments, 'pi_other', array_merge( $retry_paid, array( '_intention_status' => 'requires_capture' ) ), true, true, $paid_by( 'pi_other' ) ),
			'paid by a retry, the invoice intent unread' => array( 'processing', $woopayments, 'pi_retry', $retry_paid, true, false, $paid_by( 'pi_retry' ) ),
			'marked paid with a typed reference'         => array( 'processing', $woopayments, 'bank-123', array(), true, true, $reference( 'bank-123' ) ),
			'transaction ID of a payment intent'         => array( 'processing', $woopayments, 'pi_unrecorded', array(), true, true, $paid_by( 'pi_unrecorded' ) ),
			'transaction ID of a charge'                 => array( 'processing', $woopayments, 'ch_unrecorded', array(), true, true, $paid_by( 'ch_unrecorded' ) ),
			'transaction ID of a payment method charge'  => array( 'processing', $woopayments, 'py_unrecorded', array(), true, true, $paid_by( 'py_unrecorded' ) ),
			'transaction ID equal to the stored charge'  => array( 'completed', $woopayments, 'ch_retry', $retry_paid, true, true, $paid_by( 'ch_retry' ) ),
			'transaction ID of a setup intent'           => array( 'processing', $woopayments, 'seti_unrecorded', array(), true, true, $reference( 'seti_unrecorded' ) ),
			'paid another way, no transaction ID'        => array( 'processing', 'other', '', array(), true, true, $paid_with( 'another payment method' ) ),
			'paid by check with its reference'           => array( 'processing', 'cheque', 'other-123', array(), true, true, $paid_with( 'Check payments' ) ),
			'refunded after the retry paid it'           => array( 'refunded', $woopayments, 'pi_retry', $retry_paid, true, true, $paid_by( 'pi_retry' ) ),
			'cancelled, never paid, on another gateway'  => array( 'cancelled', 'cheque', '', array(), false, true, 'while this order&#039;s status was Cancelled. The order status was not changed. If the customer should not have been charged, refund the charge from its WooPayments transaction page, not from this order. Stripe Billing will keep charging subscription #' ),
		);
	}

	/**
	 * @testdox A paid invoice for a subscription that is not put on hold records the payment on its new renewal with a note, and leaves the subscription as it is: $_dataName.
	 *
	 * The hold is only for a subscription WooPayments bills that WooCommerce Subscriptions lets be held. A cancelled one
	 * is refused by Subscriptions (class-wc-subscription.php:373-379), which throws (:490-507); one moved to another
	 * payment method is not WooPayments' to hold, even when its gateway supports suspension. The renewal records the
	 * payment, and its note says where to refund it.
	 *
	 * @testWith ["cancelled, its Stripe cancel failed", "cancelled", "woocommerce_payments", false]
	 *           ["active, Subscriptions refuses the hold", "active", "woocommerce_payments", true]
	 *           ["active, moved to a gateway that supports suspension", "active", "stripe", false]
	 *
	 * @param string $name           Case name.
	 * @param string $status         Subscription status.
	 * @param string $payment_method Subscription payment method.
	 * @param bool   $refuse_hold    Whether Subscriptions' on-hold filter refuses the hold.
	 */
	public function test_paid_invoice_for_a_subscription_that_is_not_held_records_the_payment_with_a_note( string $name, string $status, string $payment_method, bool $refuse_hold ): void {
		unset( $name );
		$subscription = $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID, array(), $status );
		$subscription->set_payment_method( $payment_method );
		$subscription->save();
		if ( $refuse_hold ) {
			add_filter( 'woocommerce_can_subscription_be_updated_to_on-hold', '__return_false' );
		}
		$transitions = $this->record_subscription_transitions();
		$this->queue_response( 200, $this->get_renewal_intent() );
		$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );

		$this->sut->handle_event( $this->get_event( 'invoice_paid_renewal' ) );

		$orders = $this->get_renewal_orders( self::RENEWAL_INVOICE_ID );
		$this->assertCount( 1, $orders );
		$this->assertSame( 'processing', $orders[0]->get_status() );
		$this->assertSame( self::RENEWAL_INTENT_ID, $orders[0]->get_transaction_id() );
		$this->assertSame( self::RENEWAL_CHARGE_ID, $orders[0]->get_meta( '_charge_id', true ), 'A refund from the order reaches the invoice\'s charge.' );
		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertSame( $status, $subscription->get_status() );
		$this->assertSame( $payment_method, $subscription->get_payment_method() );
		$this->assertSame( array(), $transitions->list, 'Not put on hold, so no status hook fires at the other gateway.' );
		$this->assertSame( array(), $this->get_notes_containing( $subscription, 'Unable to change subscription status' ) );
		$this->assertSame( array( $this->get_not_held_note( $subscription, wc_get_order_status_name( $status ) ) ), $this->get_notes_containing( $orders[0], 'Stripe Billing collected renewal invoice' ) );
	}

	/**
	 * @testdox The not-held note names the subscription's status as the invoice found it, before Subscriptions reactivates the subscription on the payment.
	 *
	 * A subscription moved to another payment method while on hold with a failed renewal: recording the payment makes
	 * Subscriptions activate it (class-wc-subscriptions-renewal-order.php:111-119).
	 */
	public function test_not_held_note_names_the_status_found_before_the_payment(): void {
		$subscription = $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID, array(), 'on-hold' );
		$renewal      = $this->create_unpaid_renewal_order( $subscription, '24.90' );
		$renewal->set_status( 'failed' );
		$renewal->save();
		$subscription->set_payment_method( 'stripe' );
		$subscription->save();
		$this->queue_response( 200, $this->get_renewal_intent() );
		$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );

		$this->sut->handle_event( $this->get_event( 'invoice_paid_renewal' ) );

		$renewal      = wc_get_order( $renewal->get_id() );
		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertSame( 'processing', $renewal->get_status() );
		$this->assertSame( 'active', $subscription->get_status(), 'Subscriptions activated it as the payment was recorded.' );
		$this->assertSame( array( $this->get_not_held_note( $subscription, 'On hold' ) ), $this->get_notes_containing( $renewal, 'Stripe Billing collected renewal invoice' ) );
	}

	/**
	 * @testdox A declined invoice for a subscription moved off WooPayments only tries the Stripe cancel again; the cancel $answer: $_dataName.
	 *
	 * No renewal is created, the subscription is not put on hold or cancelled, and no pending invoice is kept for it.
	 *
	 * @testWith ["fails", "_wcpay_subscription_id", true]
	 *           ["succeeds", "_cancelled_wcpay_subscription_id", false]
	 *
	 * @param string $answer          How the cancel ends.
	 * @param string $expected_id_key Meta key that holds the Stripe ID afterwards.
	 * @param bool   $expected_retry  Whether a cancel retry is scheduled.
	 */
	public function test_declined_invoice_for_a_subscription_moved_off_woopayments_only_retries_the_cancel( string $answer, string $expected_id_key, bool $expected_retry ): void {
		$subscription = $this->create_subscription( self::FAILING_SUBSCRIPTION_ID );
		$subscription->set_payment_method( 'stripe' );
		$subscription->save();
		if ( 'succeeds' === $answer ) {
			$this->queue_billing( 'cancel_subscription' );
		} else {
			$this->queue_platform_error();
		}

		$this->sut->handle_event( $this->get_event( 'invoice_payment_failed' ) );

		$this->assertSame( array( array( 'DELETE', '/sites/4/wcpay/subscriptions/' . self::FAILING_SUBSCRIPTION_ID . '?test_mode=1', null ) ), $this->get_requests(), 'No charge read and no invoice update.' );
		$this->assertSame( array(), $this->get_renewal_orders( self::FAILED_INVOICE_ID ) );
		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertSame( 'active', $subscription->get_status() );
		$this->assertSame( '', $subscription->get_meta( '_wcpay_pending_invoice_id', true ) );
		$this->assertSame( self::FAILING_SUBSCRIPTION_ID, $subscription->get_meta( $expected_id_key, true ) );
		$this->assertSame(
			$expected_retry,
			as_has_scheduled_action(
				StripeBillingSubscriptionService::RETRY_CANCEL_ACTION,
				array(
					'subscription_id' => $subscription->get_id(),
					'retry'           => 1,
				),
				'woocommerce_payments'
			)
		);
	}

	/**
	 * @testdox A paid invoice for the failed renewal of a subscription already on hold takes the hold path: the subscription is active again and the renewal gets no not-held note.
	 *
	 * Subscriptions skips a move to the status the subscription already has (class-wc-subscription.php:490), so an on-hold
	 * subscription counts as one that can be put on hold, although its own check allows the move only from active.
	 */
	public function test_paid_invoice_for_a_failed_renewal_of_a_subscription_on_hold_reactivates_it_without_a_note(): void {
		$subscription = $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID, array(), 'on-hold' );
		$renewal      = $this->create_unpaid_renewal_order( $subscription, '24.90' );
		$renewal->set_status( 'failed' );
		$renewal->save();
		$this->queue_response( 200, $this->get_renewal_intent() );
		$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );

		$this->sut->handle_event( $this->get_event( 'invoice_paid_renewal' ) );

		$renewal = wc_get_order( $renewal->get_id() );
		$this->assertSame( 'processing', $renewal->get_status() );
		$this->assertSame( array(), $this->get_notes_containing( $renewal, 'while subscription #' ) );
		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertSame( 'active', $subscription->get_status() );
		$this->assertSame( array(), $this->get_notes_containing( $subscription, 'Unable to change subscription status' ) );
	}

	/**
	 * @testdox After a failed cancel on a payment method change, Stripe's collection of the open invoice reaches the renewal the merchant marked paid another way, which only gets the other-charge note.
	 *
	 * The order-screen edit that marks a failed renewal paid with another method moves the subscription's payment method,
	 * and the cancel fails. The subscription keeps its Stripe ID, so the paid invoice finds the renewal by its invoice ID.
	 */
	public function test_paid_invoice_after_a_failed_cancel_reaches_the_renewal_paid_another_way(): void {
		$subscription = $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID, array(), 'on-hold' );
		$renewal      = $this->create_unpaid_renewal_order( $subscription, '24.90' );
		$renewal->set_payment_method( 'other' );
		$renewal->set_status( 'processing' );
		$renewal->set_date_paid( time() );
		$renewal->save();
		$subscription->set_payment_method( 'other' );
		$subscription->set_status( 'active' );
		$subscription->save();
		$this->queue_platform_error();
		$this->subscription_service->maybe_cancel_subscription( $subscription, 'other' );
		$renewal = wc_get_order( $renewal->get_id() );
		$before  = $this->get_payment_record( $renewal );
		$this->queue_response( 200, $this->get_renewal_intent() );
		$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );

		$this->sut->handle_event( $this->get_event( 'invoice_paid_renewal' ) );

		$renewal = wc_get_order( $renewal->get_id() );
		$this->assertCount( 1, $this->get_notes_containing( $renewal, 'after this order was already paid with another payment method, so the customer may have paid twice.' ) );
		$this->assertSame( $before, $this->get_payment_record( $renewal ), 'The invoice\'s payment is not written over the other one.' );
		$this->assertSame( 'processing', $renewal->get_status() );
		$this->assertSame( self::CLOCK_SUBSCRIPTION_ID, wc_get_order( $subscription->get_id() )->get_meta( '_wcpay_subscription_id', true ) );
	}

	/**
	 * @testdox A paid invoice for a renewal that already records the invoice's payment, or names no payment intent, adds no invoice note: $_dataName.
	 *
	 * @testWith ["the invoice's intent", "pi_3UM1YABzWlxcwgpP0GYqv6LC", true]
	 *           ["the invoice's charge", "ch_3UM1YABzWlxcwgpP0Fazmyr5", true]
	 *           ["another payment, invoice without a payment intent", "pi_retry", false]
	 *
	 * @param string $name           Case name.
	 * @param string $transaction_id Renewal transaction ID.
	 * @param bool   $has_intent     Whether the invoice names a payment intent.
	 */
	public function test_paid_invoice_on_a_renewal_recording_it_adds_no_invoice_note( string $name, string $transaction_id, bool $has_intent ): void {
		unset( $name );
		$renewal = $this->create_paid_renewal_order( $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID ) );
		$renewal->set_transaction_id( $transaction_id );
		$renewal->save();
		if ( $has_intent ) {
			$this->queue_response( 200, $this->get_renewal_intent() );
		}
		$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );

		$this->sut->handle_event( $this->get_event( 'invoice_paid_renewal', $has_intent ? array() : array( 'payment_intent' => null ) ) );

		$renewal = wc_get_order( $renewal->get_id() );
		$this->assertSame( array(), $this->get_notes_containing( $renewal, 'Stripe Billing collected' ) );
		$this->assertSame( 0, $this->count_log_lines( 'other charge recorded' ) );
		$this->assertSame( $has_intent ? self::RENEWAL_INTENT_ID : $transaction_id, $renewal->get_transaction_id() );
	}

	/**
	 * @testdox A paid invoice for a renewal that stores its uncaptured authorization records the invoice's payment in its place and names the authorization once: $_dataName.
	 *
	 * Delivered twice. Today's writes stay, as client 11.3.0's attach overwrites a stored requires_capture
	 * (class-wc-payments-order-service.php:1818-1822, :1873-1884): on a paid renewal the invoice's payment is its capture,
	 * with the capture note and Fee details job. The note is the recorded departure; the sentence that Stripe Billing keeps
	 * charging is added on a renewal neither paid nor payable. The capture over another intent's authorization writes its
	 * warning line on the paid renewal only.
	 *
	 * @testWith ["paid by hand", "processing", "active", false, 1]
	 *           ["on hold, never paid", "on-hold", "on-hold", true, 0]
	 *
	 * @param string $name                Case name.
	 * @param string $status              Renewal status.
	 * @param string $subscription_status Subscription status.
	 * @param bool   $expected_sentence   Whether the note says Stripe Billing keeps charging.
	 * @param int    $expected_captures   Capture notes, Fee details jobs and capture warning lines.
	 */
	public function test_paid_invoice_on_a_renewal_storing_its_authorization_records_the_invoice_and_names_the_authorization( string $name, string $status, string $subscription_status, bool $expected_sentence, int $expected_captures ): void {
		unset( $name );
		$subscription = $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID, array(), $subscription_status );
		$renewal      = $this->create_renewal_storing_an_authorization( $subscription, $status );
		for ( $delivery = 1; $delivery <= 2; $delivery++ ) {
			$this->queue_response( 200, $this->get_renewal_intent() );
			$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );
		}
		$event = $this->get_event( 'invoice_paid_renewal' );

		$this->sut->handle_event( $event );
		$this->sut->handle_event( $event );

		$renewal = wc_get_order( $renewal->get_id() );
		$this->assertSame( $status, $renewal->get_status() );
		$this->assertSame( self::RENEWAL_INTENT_ID, $renewal->get_transaction_id() );
		$this->assertSame( self::RENEWAL_INTENT_ID, $renewal->get_meta( '_intent_id', true ) );
		$this->assertSame( self::RENEWAL_CHARGE_ID, $renewal->get_meta( '_charge_id', true ) );
		$this->assertSame( 'succeeded', $renewal->get_meta( '_intention_status', true ) );
		$this->assertCount( $expected_captures, $this->get_notes_containing( $renewal, 'was <strong>successfully captured</strong>' ) );
		$this->assertSame( $expected_captures, $this->count_fee_details_actions( $renewal ) );
		$this->assertSame( $expected_captures, $this->count_log_lines( 'is treated as the capture of an authorization' ) );
		$notes = $this->get_notes_containing( $renewal, 'Stripe Billing collected' );
		$this->assertCount( 1, $notes );
		$this->assertStringStartsWith( 'This order held WooPayments authorization <a href="', $notes[0] );
		$this->assertStringContainsString( 'pi_authorized</a>, which was not captured. Stripe Billing collected renewal invoice <code>' . self::RENEWAL_INVOICE_ID . '</code> for ', $notes[0] );
		$this->assertStringContainsString( '>' . self::RENEWAL_CHARGE_ID . '</a>) in its place, and this order now records that payment. This store can no longer capture or cancel the authorization', $notes[0] );
		$this->assertSame( $expected_sentence, str_contains( $notes[0], 'Stripe Billing will keep charging subscription #' . $subscription->get_id() . ' at each renewal' ) );
		$this->assertSame( array(), $this->get_notes_containing( $renewal, 'refund the payment from this order' ), 'The authorization note replaces the refund note.' );
		$this->assertSame( 0, $this->count_log_lines( 'other charge recorded' ) );
	}

	/**
	 * @testdox Completing a renewal whose authorization a collected invoice replaced captures nothing.
	 *
	 * The status-change listener finds a settled stored status and no target (WooPaymentsOrderAdminActionsController
	 * get_status_change_target()), so the authorization is not captured on top of the invoice's payment.
	 */
	public function test_completing_a_renewal_whose_authorization_an_invoice_replaced_captures_nothing(): void {
		$renewal = $this->create_renewal_storing_an_authorization( $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID ), 'processing' );
		$this->queue_response( 200, $this->get_renewal_intent() );
		$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );
		$this->sut->handle_event( $this->get_event( 'invoice_paid_renewal' ) );
		wc_get_container()->get( WooPaymentsOrderAdminActionsController::class )->register();
		$platform_requests = 0;
		add_filter(
			'pre_http_request',
			static function ( $response ) use ( &$platform_requests ) {
				++$platform_requests;
				return $response;
			}
		);
		$notes_before = $this->get_note_contents( wc_get_order( $renewal->get_id() ) );

		wc_get_order( $renewal->get_id() )->update_status( 'completed' );

		$renewal = wc_get_order( $renewal->get_id() );
		$this->assertSame( 'completed', $renewal->get_status() );
		$this->assertSame( 0, $platform_requests, 'No capture request.' );
		$this->assertCount( 5, $this->get_requests(), 'Only the invoice event reached the platform.' );
		$this->assertSame( 'succeeded', $renewal->get_meta( '_intention_status', true ) );
		$this->assertSame( array( 'Order status changed from Processing to Completed.' ), array_values( array_diff( $this->get_note_contents( $renewal ), $notes_before ) ), 'No capture or failure note.' );
	}

	/**
	 * @testdox A paid invoice whose intent cannot be read, for a renewal storing its uncaptured authorization, notes once not to capture it and fails for a retry; a retry that reads the intent records the payment.
	 *
	 * The note is saved before the failure, so it stays if no retry reads the intent; nothing else is written until then.
	 * invoice.paid is retried while the Stripe Billing module is loaded (EventIngestor::is_retried_event()), three more
	 * times at most (WebhookReliabilityService::RETRY_DELAYS_SECONDS).
	 */
	public function test_paid_invoice_with_an_unread_intent_on_a_renewal_storing_its_authorization_notes_it_and_fails_for_a_retry(): void {
		$renewal = $this->create_renewal_storing_an_authorization( $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID ), 'processing' );
		$event   = $this->get_event( 'invoice_paid_renewal' );
		for ( $delivery = 1; $delivery <= 3; $delivery++ ) {
			$this->queue_platform_error();
			$failure = null;
			try {
				$this->sut->handle_event( $event );
			} catch ( RuntimeException $exception ) {
				$failure = $exception;
			}
			$this->assertInstanceOf( RuntimeException::class, $failure, 'The delivery fails, so it is retried.' );
			$this->assertNotInstanceOf( OrderPaymentLockRefusedException::class, $failure );

			$stored = wc_get_order( $renewal->get_id() );
			$notes  = $this->get_notes_containing( $stored, 'could not be read' );
			$this->assertCount( 1, $notes, 'One note, saved before the failure, however often the delivery fails.' );
			$this->assertStringStartsWith( 'The payment for Stripe Billing renewal invoice <code>' . self::RENEWAL_INVOICE_ID . '</code> could not be read. Do not capture WooPayments authorization <a href="', $notes[0] );
			$this->assertStringEndsWith( '>pi_authorized</a> on this order. If no later note follows this one, the payment was not recorded on this order.', $notes[0] );
			$this->assertSame( 'pi_authorized', $stored->get_transaction_id() );
			$this->assertSame( 'pi_authorized', $stored->get_meta( '_intent_id', true ) );
			$this->assertSame( 'ch_authorized', $stored->get_meta( '_charge_id', true ) );
			$this->assertSame( 'requires_capture', $stored->get_meta( '_intention_status', true ) );
		}
		$this->assertCount( 3, $this->get_requests(), 'Only the intent reads: nothing is reported to the platform.' );
		$this->assertSame( 0, $this->count_log_lines( 'is treated as the capture of an authorization' ) );
		$this->queue_response( 200, $this->get_renewal_intent() );
		$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );

		$this->sut->handle_event( $event );

		$renewal = wc_get_order( $renewal->get_id() );
		$this->assertSame( self::RENEWAL_INTENT_ID, $renewal->get_transaction_id() );
		$this->assertSame( self::RENEWAL_CHARGE_ID, $renewal->get_meta( '_charge_id', true ) );
		$this->assertSame( 'succeeded', $renewal->get_meta( '_intention_status', true ) );
		$this->assertCount( 1, $this->get_notes_containing( $renewal, 'was <strong>successfully captured</strong>' ) );
		$this->assertCount( 1, $this->get_notes_containing( $renewal, 'This order held WooPayments authorization' ) );
		$this->assertCount( 1, $this->get_notes_containing( $renewal, 'could not be read' ), 'The first note is not repeated.' );
		$this->assertSame( 1, $this->count_log_lines( 'is treated as the capture of an authorization' ) );
	}

	/**
	 * @testdox After a paid invoice, a refund of $_dataName is applied to the payment the renewal records.
	 *
	 * The order screen's refund goes to the stored charge, and its charge.refunded is the order's own when the renewal's
	 * transaction ID names it (paid) or its stored intent does (unpaid); a charge found only by its metadata is recorded on
	 * the order without a refund (RefundEventHandler, EventOrderResolver::is_own_payment()). The event carries the charge's
	 * `payment_intent` and the store's `metadata` (https://docs.stripe.com/api/charges/object).
	 *
	 * @testWith ["the retry's charge, on a renewal a retry paid", "processing", "ch_retry", "pi_retry", 1, 0, "ch_retry"]
	 *           ["the invoice's charge, on a renewal a retry paid", "processing", "ch_3UM1YABzWlxcwgpP0Fazmyr5", "pi_3UM1YABzWlxcwgpP0GYqv6LC", 0, 1, "ch_retry"]
	 *           ["the invoice's charge, on a cancelled renewal", "cancelled", "ch_3UM1YABzWlxcwgpP0Fazmyr5", "pi_3UM1YABzWlxcwgpP0GYqv6LC", 1, 0, "ch_3UM1YABzWlxcwgpP0Fazmyr5"]
	 *
	 * @param string $name            Case name.
	 * @param string $status          Renewal status; a processing renewal was paid by a retry.
	 * @param string $charge_id       Refunded charge.
	 * @param string $intent_id       The charge's payment intent.
	 * @param int    $expected_rows   Refund rows on the renewal.
	 * @param int    $expected_notes  Notes recording a refund on another charge.
	 * @param string $expected_charge Stored charge after both events.
	 */
	public function test_a_refund_after_a_paid_invoice_follows_the_payment_the_renewal_records( string $name, string $status, string $charge_id, string $intent_id, int $expected_rows, int $expected_notes, string $expected_charge ): void {
		unset( $name );
		$renewal = $this->create_unpaid_renewal_order( $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID, array(), 'on-hold' ), '24.90' );
		if ( 'processing' === $status ) {
			$renewal->set_transaction_id( 'pi_retry' );
			$renewal->update_meta_data( '_intent_id', 'pi_retry' );
			$renewal->update_meta_data( '_charge_id', 'ch_retry' );
			$renewal->update_meta_data( '_intention_status', 'succeeded' );
			$renewal->set_date_paid( time() - DAY_IN_SECONDS );
		}
		$renewal->set_status( $status );
		$renewal->save();
		$this->queue_response( 200, $this->get_renewal_intent() );
		$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );
		$this->sut->handle_event( $this->get_event( 'invoice_paid_renewal' ) );

		wc_get_container()->get( RefundEventHandler::class )->process(
			'charge.refunded',
			array(
				'id'             => $charge_id,
				'payment_intent' => $intent_id,
				'status'         => 'succeeded',
				'captured'       => true,
				'amount'         => 2490,
				'currency'       => 'usd',
				'metadata'       => array(
					'order_id'  => (string) $renewal->get_id(),
					'order_key' => $renewal->get_order_key(),
				),
				'refunds'        => array(
					'data' => array(
						array(
							'id'                  => 're_after_invoice',
							'amount'              => 1000,
							'reason'              => 'requested_by_customer',
							'status'              => 'succeeded',
							'balance_transaction' => 'txn_after_invoice',
						),
					),
				),
			)
		);

		$renewal = wc_get_order( $renewal->get_id() );
		$this->assertCount( $expected_rows, $renewal->get_refunds() );
		$this->assertCount( $expected_notes, $this->get_notes_containing( $renewal, 'No refund was added to the order.' ) );
		$this->assertSame( $expected_charge, $renewal->get_meta( '_charge_id', true ) );
	}

	/**
	 * @testdox After a paid invoice replaced a renewal's uncaptured authorization, the authorization's expiry is recorded on the renewal and changes nothing else.
	 *
	 * The expired charge is no longer stored, so the event finds the renewal by the charge's metadata only and records it
	 * (PaymentIntentEventHandler, OtherChargeRecorder); the event carries the charge's `payment_intent` and the store's
	 * `metadata` (https://docs.stripe.com/api/charges/object).
	 */
	public function test_the_replaced_authorizations_expiry_is_only_recorded_on_the_renewal(): void {
		$renewal = $this->create_renewal_storing_an_authorization( $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID ), 'processing' );
		$this->queue_response( 200, $this->get_renewal_intent() );
		$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );
		$this->sut->handle_event( $this->get_event( 'invoice_paid_renewal' ) );
		$renewal      = wc_get_order( $renewal->get_id() );
		$before       = $this->get_payment_record( $renewal );
		$notes_before = $this->get_note_contents( $renewal );

		wc_get_container()->get( PaymentIntentEventHandler::class )->process(
			'charge.expired',
			array(
				'id'             => 'ch_authorized',
				'payment_intent' => 'pi_authorized',
				'metadata'       => array(
					'order_id'  => (string) $renewal->get_id(),
					'order_key' => $renewal->get_order_key(),
				),
			)
		);

		$renewal   = wc_get_order( $renewal->get_id() );
		$new_notes = array_values( array_diff( $this->get_note_contents( $renewal ), $notes_before ) );
		$this->assertCount( 1, $new_notes );
		$this->assertStringContainsString( 'ch_authorized</a>, which does not pay this order, expired.', $new_notes[0] );
		$this->assertSame( $before, $this->get_payment_record( $renewal ) );
		$this->assertSame( 'processing', $renewal->get_status() );
		$this->assertCount( 5, $this->get_requests(), 'The expiry reads nothing from the platform.' );
	}

	/**
	 * @testdox A paid renewal invoice records its intent without comparing the intent's amount with the order total.
	 *
	 * Client 11.3.0 never runs the intent comparison on an invoice payment: invoice.paid completes the renewal itself
	 * (class-wc-payments-subscriptions-event-handler.php:188) and payment_intent.succeeded leaves invoice intents alone
	 * (class-wc-payments-webhook-processing-service.php:711-717).
	 */
	public function test_paid_renewal_invoice_does_not_compare_its_intent_with_the_order_total(): void {
		$this->create_subscription( self::CLOCK_SUBSCRIPTION_ID, array( '_wcpay_pending_invoice_id' => self::FAILED_INVOICE_ID ) );
		$this->queue_response( 200, array_merge( $this->get_renewal_intent(), array( 'amount' => 1 ) ) );
		$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );

		$this->sut->handle_event( $this->get_event( 'invoice_paid_renewal' ) );

		$order = $this->get_renewal_orders( self::RENEWAL_INVOICE_ID )[0];
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertCount( 0, $this->get_notes_containing( $order, 'was put <strong>on hold</strong>' ) );
	}

	/**
	 * @testdox The renewal's test-payment note shows the amount in the order's currency format when the webhook runs as a user who selected another currency.
	 *
	 * The platform's webhook request runs as the connection owner. At R2 that user had selected EUR, and the note read "24,90 $ USD".
	 */
	public function test_renewal_note_amount_keeps_the_order_currency_format_under_another_selection(): void {
		$this->format_prices_in_selected_currency( 'EUR' );
		$this->assertSame( '24,90&nbsp;&#36;', wp_strip_all_tags( wc_price( 24.90, array( 'currency' => 'USD' ) ) ), 'Without the order currency, the selection reformats a USD price.' );
		$this->create_subscription( self::CLOCK_SUBSCRIPTION_ID );
		$this->queue_response( 200, $this->get_renewal_intent() );
		$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );

		$this->sut->handle_event( $this->get_event( 'invoice_paid_renewal' ) );

		$order = $this->get_renewal_orders( self::RENEWAL_INVOICE_ID )[0];
		$notes = $this->get_notes_containing( $order, 'A test payment of' );
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'A test payment of $24.90 was processed', html_entity_decode( wp_strip_all_tags( $notes[0] ), ENT_QUOTES | ENT_HTML5 ) );
	}

	/**
	 * Apply Multi-Currency's frontend price formatting for a selected currency, as a webhook request running as a user with that selection does.
	 *
	 * @param string $selected_code Selected currency code.
	 */
	private function format_prices_in_selected_currency( string $selected_code ): void {
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( 'active_plugins', array() );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		$this->assertTrue( wc_get_container()->get( MultiCurrencyRuntimeArbiter::class )->is_builtin_owner() );

		$localization = wc_get_container()->get( LocalizationService::class );
		$usd          = new MultiCurrencyCurrency( $localization, 'USD', 1.0, true );
		$enabled      = array(
			'USD'          => $usd,
			$selected_code => new MultiCurrencyCurrency( $localization, $selected_code, 0.8, false ),
		);
		$state        = new MultiCurrencyState( $enabled, $enabled, $usd, $enabled[ $selected_code ] );
		$builder      = new class( $state ) extends StateBuilder {
			/** @var MultiCurrencyState */
			private MultiCurrencyState $state;

			/**
			 * @param MultiCurrencyState $state Fixed state.
			 */
			public function __construct( MultiCurrencyState $state ) {
				$this->state = $state;
			}

			/**
			 * @return MultiCurrencyState
			 */
			public function build(): MultiCurrencyState {
				return $this->state;
			}
		};

		$controller = new MultiCurrencyFrontendCurrenciesController();
		$controller->init(
			wc_get_container()->get( MultiCurrencyRuntimeArbiter::class ),
			ServiceFactories::with(
				$this,
				array( 'create_display_service' => new DisplayService( $builder, $localization, new GeolocationService( $localization, static fn() => 'US' ) ) )
			),
			new class() extends RequestContext {
				/**
				 * The webhook request registers the frontend price hooks.
				 *
				 * @return bool
				 */
				public function should_register_frontend_hooks(): bool {
					return true;
				}
			},
			wc_get_container()->get( OrderContextService::class )
		);
		wc_get_container()->replace( MultiCurrencyFrontendCurrenciesController::class, $controller );
		$controller->register();
		$this->format_controller = $controller;
	}

	/**
	 * @testdox The invoice of the subscription's first order is ignored: checkout records that payment (event handler :141-144).
	 */
	public function test_parent_invoice_is_ignored(): void {
		$this->create_subscription( self::CLOCK_SUBSCRIPTION_ID );

		$this->sut->handle_event( $this->get_event( 'invoice_paid_parent' ) );

		$this->assertSame( array(), $this->get_renewal_orders( self::CLOCK_PARENT_INVOICE_ID ) );
		$this->assertSame( array(), $this->get_requests() );
	}

	/**
	 * @testdox A paid invoice that fails after its renewal order exists, then is retried, leaves one paid renewal order with one payment.
	 *
	 * The retry finds the order by invoice ID (event handler :146), so it creates no second order, payment, note or email.
	 */
	public function test_retried_paid_invoice_leaves_one_paid_renewal_order(): void {
		$this->create_subscription( self::CLOCK_SUBSCRIPTION_ID );
		$payments_completed = did_action( 'woocommerce_payment_complete' );
		$this->queue_response( 200, $this->get_renewal_intent() );
		$this->queue_platform_error();

		try {
			$this->sut->handle_event( $this->get_event( 'invoice_paid_renewal' ) );
			$this->fail( 'The failed invoice update must fail the event.' );
		} catch ( ApiException $exception ) {
			$this->assertSame( 500, $exception->get_http_code(), 'A platform failure is passed on as is, so the event is retried.' );
		}

		$this->queue_response( 200, $this->get_renewal_intent() );
		$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );

		$this->sut->handle_event( $this->get_event( 'invoice_paid_renewal' ) );

		$orders = $this->get_renewal_orders( self::RENEWAL_INVOICE_ID );
		$this->assertCount( 1, $orders );
		$this->assertSame( 'processing', $orders[0]->get_status() );
		$this->assertSame( self::RENEWAL_INTENT_ID, $orders[0]->get_transaction_id() );
		$this->assertCount( 1, $this->get_notes_containing( $orders[0], 'A test payment of' ) );
		$this->assertSame( 1, did_action( 'woocommerce_payment_complete' ) - $payments_completed, 'The renewal is paid once, so its emails go once.' );
	}

	/**
	 * @testdox A paid invoice delivered twice leaves one paid renewal order with one payment, and the subscription active.
	 *
	 * The second delivery finds the order by invoice ID (event handler :146) and, as the order no longer needs payment,
	 * does not pay it again or put the subscription on hold (:159).
	 */
	public function test_paid_invoice_delivered_twice_records_one_payment(): void {
		$subscription       = $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID );
		$transitions        = $this->record_subscription_transitions();
		$payments_completed = did_action( 'woocommerce_payment_complete' );
		for ( $delivery = 1; $delivery <= 2; $delivery++ ) {
			$this->queue_response( 200, $this->get_renewal_intent() );
			$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );
		}
		$event = $this->get_event( 'invoice_paid_renewal' );

		$this->sut->handle_event( $event );
		$this->sut->handle_event( $event );

		$orders = $this->get_renewal_orders( self::RENEWAL_INVOICE_ID );
		$this->assertCount( 1, $orders );
		$this->assertSame( 'processing', $orders[0]->get_status() );
		$this->assertSame( self::RENEWAL_INTENT_ID, $orders[0]->get_transaction_id() );
		$this->assertCount( 1, $this->get_notes_containing( $orders[0], 'A test payment of' ) );
		$this->assertSame( 1, did_action( 'woocommerce_payment_complete' ) - $payments_completed, 'The renewal is paid once, so its emails go once.' );
		$this->assertSame( array( 'on-hold', 'on-hold_to_active' ), $transitions->list, 'Only the first delivery puts the subscription on hold.' );
		$this->assertSame( 'active', wc_get_order( $subscription->get_id() )->get_status() );
	}

	/**
	 * @testdox A second delivery of a paid invoice that runs while the first reads the intent leaves one paid renewal order, one payment and the subscription active with no suspension counted.
	 *
	 * The second delivery pays the renewal, which activates the subscription; the first then puts the subscription on hold
	 * from its stale copy and finds the payment already recorded. Client 11.1.0 pays the order again from its stale copy
	 * (event handler :159-184), so WooCommerce Subscriptions runs payment_complete_for_order(), which makes the subscription
	 * active and resets its suspension count (includes/core/class-wc-subscription.php:2087-2117).
	 */
	public function test_paid_invoice_delivered_while_the_first_reads_the_intent_leaves_the_subscription_active(): void {
		$subscription       = $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID );
		$payments_completed = did_action( 'woocommerce_payment_complete' );
		for ( $delivery = 1; $delivery <= 2; $delivery++ ) {
			$this->queue_response( 200, $this->get_renewal_intent() );
			$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );
		}
		$event   = $this->get_event( 'invoice_paid_renewal' );
		$overlap = function ( ...$args ) use ( $event, &$overlap ) {
			// The second delivery runs once, inside the first one's intent read; the filter hands the request params back.
			remove_filter( 'wcpay_api_request_params', $overlap );
			$this->sut->handle_event( $event );
			return $args[0] ?? null;
		};
		add_filter( 'wcpay_api_request_params', $overlap );

		try {
			$this->sut->handle_event( $event );
		} finally {
			remove_filter( 'wcpay_api_request_params', $overlap );
		}

		$orders = $this->get_renewal_orders( self::RENEWAL_INVOICE_ID );
		$this->assertCount( 1, $orders );
		$this->assertSame( 'processing', $orders[0]->get_status() );
		$this->assertSame( 1, did_action( 'woocommerce_payment_complete' ) - $payments_completed, 'The renewal is paid once, so its emails go once.' );
		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertSame( 'active', $subscription->get_status() );
		$this->assertSame( 0, $subscription->get_suspension_count() );
	}

	/**
	 * @testdox A delivery of a paid invoice refused by the order payment lock that another delivery of it holds, after that delivery paid the renewal, passes the refusal on and leaves the subscription active with no suspension counted.
	 *
	 * The other delivery pays the renewal, which activates the subscription, and is still holding the order lock when this
	 * one puts the subscription on hold from its stale copy and is refused.
	 */
	public function test_paid_invoice_refused_after_another_delivery_paid_it_leaves_the_subscription_active(): void {
		$subscription = $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID );
		$order        = WooCommerceSubscriptionsDoubles::create_renewal_order( $subscription );
		$order->set_payment_method( 'woocommerce_payments' );
		$order->update_meta_data( '_wcpay_billing_invoice_id', self::RENEWAL_INVOICE_ID );
		$order->save();
		$payments_completed = did_action( 'woocommerce_payment_complete' );
		for ( $delivery = 1; $delivery <= 2; $delivery++ ) {
			$this->queue_response( 200, $this->get_renewal_intent() );
			$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );
		}
		$event      = $this->get_event( 'invoice_paid_renewal' );
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$lock_token = null;
		$overlap    = function ( ...$args ) use ( $event, $order, $vocabulary, &$lock_token, &$overlap ) {
			// The other delivery runs inside this one's intent read, and then still holds the order lock.
			remove_filter( 'wcpay_api_request_params', $overlap );
			$this->sut->handle_event( $event );
			$lock_token = wc_get_container()->get( OrderPaymentLock::class )->claim( wc_get_order( $order->get_id() ), $vocabulary, self::RENEWAL_INTENT_ID, 'payment status update' );
			return $args[0] ?? null;
		};
		add_filter( 'wcpay_api_request_params', $overlap );

		$refusal = null;
		try {
			$this->sut->handle_event( $event );
		} catch ( OrderPaymentLockRefusedException $exception ) {
			$refusal = $exception;
		} finally {
			remove_filter( 'wcpay_api_request_params', $overlap );
			if ( null !== $lock_token ) {
				wc_get_container()->get( OrderPaymentLock::class )->release( $order, $vocabulary, $lock_token );
			}
		}

		$this->assertNotNull( $lock_token, 'The other delivery holds the order lock when this one records the payment.' );
		$this->assertInstanceOf( OrderPaymentLockRefusedException::class, $refusal, 'The refusal is passed on, so invoice.paid is retried.' );
		$this->assertSame( 1, did_action( 'woocommerce_payment_complete' ) - $payments_completed );
		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertSame( 'active', $subscription->get_status() );
		$this->assertSame( 0, $subscription->get_suspension_count() );
	}

	/**
	 * @testdox With orders stored as posts, a refused delivery whose request still caches the renewal as unpaid reads it past that cache and leaves the subscription active with no suspension counted.
	 *
	 * The other delivery pays the renewal and still holds the order lock; this delivery's post cache keeps the renewal as
	 * it was before that payment. The posts data store reads the order's status through get_post()
	 * (abstract-wc-order-data-store-cpt.php), so only a read that clears that cache sees the payment.
	 */
	public function test_paid_lock_refusal_recovers_from_a_stale_order_post_cache(): void {
		// Keep both order stores in step, so switching the authoritative store back afterwards is allowed.
		add_filter( 'pre_option_' . DataSynchronizer::ORDERS_DATA_SYNC_ENABLED_OPTION, static fn(): string => 'yes' );
		$hpos_was_enabled = OrderUtil::custom_orders_table_usage_is_enabled();
		OrderHelper::toggle_cot_feature_and_usage( false );

		try {
			$subscription = $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID );
			$order        = WooCommerceSubscriptionsDoubles::create_renewal_order( $subscription );
			$order->set_payment_method( 'woocommerce_payments' );
			$order->update_meta_data( '_wcpay_billing_invoice_id', self::RENEWAL_INVOICE_ID );
			$order->save();
			$payments_completed = did_action( 'woocommerce_payment_complete' );
			for ( $delivery = 1; $delivery <= 2; $delivery++ ) {
				$this->queue_response( 200, $this->get_renewal_intent() );
				$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );
			}
			$event        = $this->get_event( 'invoice_paid_renewal' );
			$vocabulary   = new WooPaymentsPersistenceVocabulary();
			$lock_token   = null;
			$pending_post = null;
			// After this delivery's hold, its post cache holds the renewal as it was before the other delivery paid it.
			$restore = function () use ( $order, &$pending_post, &$restore ): void {
				remove_action( 'woocommerce_subscription_status_on-hold', $restore );
				wp_cache_set( $order->get_id(), $pending_post, 'posts' );
			};
			$overlap = function ( ...$args ) use ( $event, $order, $vocabulary, &$lock_token, &$pending_post, &$restore, &$overlap ) {
				remove_filter( 'wcpay_api_request_params', $overlap );
				clean_post_cache( $order->get_id() );
				get_post( $order->get_id() );
				$pending_post = wp_cache_get( $order->get_id(), 'posts' );
				$this->sut->handle_event( $event );
				$lock_token = wc_get_container()->get( OrderPaymentLock::class )->claim( $order, $vocabulary, self::RENEWAL_INTENT_ID, 'payment status update' );
				add_action( 'woocommerce_subscription_status_on-hold', $restore );
				return $args[0] ?? null;
			};
			add_filter( 'wcpay_api_request_params', $overlap );

			$refusal = null;
			try {
				$this->sut->handle_event( $event );
			} catch ( OrderPaymentLockRefusedException $exception ) {
				$refusal = $exception;
			} finally {
				remove_filter( 'wcpay_api_request_params', $overlap );
				remove_action( 'woocommerce_subscription_status_on-hold', $restore );
				if ( null !== $lock_token ) {
					wc_get_container()->get( OrderPaymentLock::class )->release( $order, $vocabulary, $lock_token );
				}
			}

			$this->assertIsObject( $pending_post );
			$this->assertSame( 'wc-pending', $pending_post->post_status, 'The cached renewal is the unpaid one.' );
			$this->assertInstanceOf( OrderPaymentLockRefusedException::class, $refusal, 'The refusal is passed on, so invoice.paid is retried.' );
			$this->assertSame( 1, did_action( 'woocommerce_payment_complete' ) - $payments_completed );
			clean_post_cache( $order->get_id() );
			$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
			$subscription = wc_get_order( $subscription->get_id() );
			$this->assertSame( 'active', $subscription->get_status() );
			$this->assertSame( 0, $subscription->get_suspension_count() );
		} finally {
			OrderHelper::toggle_cot_feature_and_usage( $hpos_was_enabled );
		}
	}

	/**
	 * @testdox A second delivery of a paid invoice that runs while the first reads the intent leaves the subscription on hold when the invoice's renewal is not its last one.
	 *
	 * WooCommerce Subscriptions records a renewal payment on the subscription only for its last renewal
	 * (includes/core/class-wc-subscriptions-renewal-order.php:111-119), so the client's repeated payment leaves the hold too.
	 */
	public function test_paid_invoice_overlap_on_an_older_renewal_leaves_the_subscription_on_hold(): void {
		$subscription = $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID );
		$order        = WooCommerceSubscriptionsDoubles::create_renewal_order( $subscription );
		$order->set_payment_method( 'woocommerce_payments' );
		$order->update_meta_data( '_wcpay_billing_invoice_id', self::RENEWAL_INVOICE_ID );
		$order->save();
		WooCommerceSubscriptionsDoubles::create_renewal_order( $subscription );
		$payments_completed = did_action( 'woocommerce_payment_complete' );
		for ( $delivery = 1; $delivery <= 2; $delivery++ ) {
			$this->queue_response( 200, $this->get_renewal_intent() );
			$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );
		}
		$event   = $this->get_event( 'invoice_paid_renewal' );
		$overlap = function ( ...$args ) use ( $event, &$overlap ) {
			remove_filter( 'wcpay_api_request_params', $overlap );
			$this->sut->handle_event( $event );
			return $args[0] ?? null;
		};
		add_filter( 'wcpay_api_request_params', $overlap );

		try {
			$this->sut->handle_event( $event );
		} finally {
			remove_filter( 'wcpay_api_request_params', $overlap );
		}

		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( 1, did_action( 'woocommerce_payment_complete' ) - $payments_completed );
		$this->assertSame( 'on-hold', wc_get_order( $subscription->get_id() )->get_status() );
	}

	/**
	 * @testdox A failed-payment update for an invoice whose renewal order is already paid changes nothing: the order stays paid and the subscription active.
	 *
	 * Client 11.1.0 calls `payment_failed()` here without a check (event handler :298-304), which fails the subscription's last order.
	 */
	public function test_failed_invoice_update_for_a_paid_renewal_changes_nothing(): void {
		$subscription = $this->create_subscription( self::FAILING_SUBSCRIPTION_ID );
		$order        = WooCommerceSubscriptionsDoubles::create_renewal_order( $subscription );
		$order->set_payment_method( 'woocommerce_payments' );
		$order->update_meta_data( '_wcpay_billing_invoice_id', self::FAILED_INVOICE_ID );
		$order->set_status( 'processing' );
		$order->save();
		$this->queue_response( 200, $this->get_declined_charge() );

		$this->sut->handle_event( $this->get_event( 'invoice_payment_failed' ) );

		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( 'active', $subscription->get_status() );
		$this->assertSame( '', $subscription->get_meta( '_wcpay_pending_invoice_id', true ), 'A paid invoice is not kept for a payment method change.' );
		$this->assertSame( array(), $this->get_notes_containing( $subscription, 'failed' ) );
		$this->assertCount( 1, $this->get_requests(), 'Only the charge is read; nothing is reported to the platform.' );
	}

	/**
	 * @testdox A failed-payment update fails the invoice's own renewal order, not a newer renewal, on WooCommerce Subscriptions $_dataName.
	 * @testWith ["7.9.0 and later", false, "on-hold"]
	 *           ["before 7.9.0", true, "active"]
	 *
	 * WooCommerce Subscriptions deprecated `payment_failed()` in 7.9.0 because it fails the subscription's last order
	 * (includes/core/class-wc-subscription.php:2151-2160); the invoice's order goes to `payment_failed_for_related_order()`.
	 * Before 7.9.0 the invoice's order is failed and noted directly and the subscription is left as it is.
	 *
	 * @param string $version                   WooCommerce Subscriptions version range.
	 * @param bool   $before_related_order_failure Whether WooCommerce Subscriptions predates the related-order failure.
	 * @param string $expected_subscription_status Subscription status after the update.
	 */
	public function test_failed_invoice_update_fails_its_own_renewal_not_a_newer_one( string $version, bool $before_related_order_failure, string $expected_subscription_status ): void {
		unset( $version );
		$GLOBALS[ WooCommerceSubscriptionsDoubles::BEFORE_RELATED_ORDER_FAILURE ] = $before_related_order_failure;
		$subscription = $this->create_subscription( self::FAILING_SUBSCRIPTION_ID );
		$older        = WooCommerceSubscriptionsDoubles::create_renewal_order( $subscription );
		$older->set_payment_method( 'woocommerce_payments' );
		$older->update_meta_data( '_wcpay_billing_invoice_id', self::FAILED_INVOICE_ID );
		$older->save();
		$newer = WooCommerceSubscriptionsDoubles::create_renewal_order( $subscription );
		$newer->set_status( 'completed' );
		$newer->save();
		$this->queue_response( 200, $this->get_declined_charge() );
		$this->queue_billing( 'update_invoice' );

		$this->sut->handle_event( $this->get_event( 'invoice_payment_failed' ) );

		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertSame( 'failed', wc_get_order( $older->get_id() )->get_status() );
		$this->assertSame( 'completed', wc_get_order( $newer->get_id() )->get_status(), 'The newer renewal is untouched.' );
		$this->assertSame( $expected_subscription_status, $subscription->get_status() );
		$this->assertCount( 1, $this->get_notes_containing( $subscription, 'Related order #' . $older->get_id() . ' failed.' ) );
		$this->assertSame( array(), $this->get_notes_containing( $subscription, 'Related order #' . $newer->get_id() . ' failed.' ) );
	}

	/**
	 * @testdox On WooCommerce Subscriptions before 7.9.0, a failed-payment update whose renewal is the subscription's last order goes through payment_failed(), as the WooPayments extension does.
	 */
	public function test_failed_invoice_update_for_the_last_renewal_uses_payment_failed_before_wcs_7_9(): void {
		$GLOBALS[ WooCommerceSubscriptionsDoubles::BEFORE_RELATED_ORDER_FAILURE ] = true;
		$subscription = $this->create_subscription( self::FAILING_SUBSCRIPTION_ID );
		$this->queue_response( 200, $this->get_declined_charge() );
		$this->queue_billing( 'update_invoice' );

		$this->sut->handle_event( $this->get_event( 'invoice_payment_failed' ) );

		$order = $this->get_renewal_orders( self::FAILED_INVOICE_ID )[0];
		$this->assertSame( 'failed', $order->get_status() );
		$this->assertSame( 'on-hold', wc_get_order( $subscription->get_id() )->get_status() );
		$this->assertCount( 1, $this->get_notes_containing( wc_get_order( $subscription->get_id() ), 'Payment failed.' ), 'WooCommerce Subscriptions 7.8.2 notes the failure.' );
	}

	/**
	 * @testdox On WooCommerce Subscriptions before 7.9.0, failing the invoice's renewal does not let WCS's renewal listener fail a newer switch order or suspend the subscription.
	 *
	 * WCS 7.8.2's renewal-order listener calls payment_failed() when a last renewal fails (class-wc-subscriptions-renewal-order.php:86-128),
	 * and payment_failed() fails the last order of any relation, switches included (class-wc-subscription.php:2035-2074, :2313-2345).
	 */
	public function test_failed_invoice_update_before_wcs_7_9_does_not_let_wcs_fail_a_newer_switch_order(): void {
		$GLOBALS[ WooCommerceSubscriptionsDoubles::BEFORE_RELATED_ORDER_FAILURE ] = true;
		WooCommerceSubscriptionsDoubles::load_renewal_order_listener();
		$subscription = $this->create_subscription( self::FAILING_SUBSCRIPTION_ID );
		$renewal      = WooCommerceSubscriptionsDoubles::create_renewal_order( $subscription );
		$renewal->set_payment_method( 'woocommerce_payments' );
		$renewal->update_meta_data( '_wcpay_billing_invoice_id', self::FAILED_INVOICE_ID );
		$renewal->save();
		$switch = wc_create_order();
		$switch->set_status( 'completed' );
		$switch->save();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ][ $switch->get_id() ]['switch'][] = $subscription->get_id();
		$this->queue_response( 200, $this->get_declined_charge() );
		$this->queue_billing( 'update_invoice' );
		$listener = 'WC_Subscriptions_Renewal_Order::maybe_record_subscription_payment';
		add_filter( 'woocommerce_order_status_changed', $listener, 10, 3 );

		try {
			$this->sut->handle_event( $this->get_event( 'invoice_payment_failed' ) );
			$listener_after = has_filter( 'woocommerce_order_status_changed', $listener );
		} finally {
			remove_filter( 'woocommerce_order_status_changed', $listener, 10 );
		}

		$this->assertSame( 10, $listener_after, 'The WCS listener is back after the failure.' );
		$this->assertSame( 'failed', wc_get_order( $renewal->get_id() )->get_status() );
		$this->assertSame( 'completed', wc_get_order( $switch->get_id() )->get_status(), 'The newer switch order is untouched.' );
		$this->assertSame( 'active', wc_get_order( $subscription->get_id() )->get_status() );
	}

	/**
	 * @testdox When the intent cannot be read, the renewal order is paid at once with a note and the IDs a refund needs, and the event completes (client get_and_attach_intent_info_to_order, `class-wc-payments-invoice-service.php:288-299`).
	 */
	public function test_unreadable_intent_pays_the_renewal_order_with_a_note(): void {
		$this->create_subscription( self::CLOCK_SUBSCRIPTION_ID );
		$this->queue_platform_error();
		$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );

		$this->sut->handle_event( $this->get_event( 'invoice_paid_renewal' ) );

		$order = $this->get_renewal_orders( self::RENEWAL_INVOICE_ID )[0];
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( self::RENEWAL_INTENT_ID, $order->get_transaction_id() );
		$this->assertSame( self::RENEWAL_INTENT_ID, $order->get_meta( '_intent_id', true ) );
		$this->assertSame( self::RENEWAL_CHARGE_ID, $order->get_meta( '_charge_id', true ) );
		$this->assertCount( 1, $this->get_notes_containing( $order, "The payment info couldn't be added to the order." ) );
		$this->assertSame( array(), $this->get_notes_containing( $order, 'A test payment of' ) );
		$this->assertCount( 5, $this->get_requests(), 'The invoice, charge and transaction are still updated.' );
	}

	/**
	 * @testdox A renewal order already paid when its invoice event arrives, as after a payment method change, gets the intent recorded without a second payment (event handler :159, :186-189).
	 */
	public function test_renewal_order_paid_before_its_invoice_event_gets_the_intent_recorded(): void {
		$order       = $this->create_paid_renewal_order( $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID ) );
		$transitions = $this->record_subscription_transitions();
		$payments    = did_action( 'woocommerce_payment_complete' );
		$this->queue_response( 200, $this->get_renewal_intent() );
		$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );

		$this->sut->handle_event( $this->get_event( 'invoice_paid_renewal' ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( self::RENEWAL_INTENT_ID, $order->get_meta( '_intent_id', true ) );
		$this->assertSame( self::RENEWAL_CHARGE_ID, $order->get_meta( '_charge_id', true ) );
		$this->assertSame( self::RENEWAL_INTENT_ID, $order->get_transaction_id(), 'The client attach sets the transaction id whatever the order status (`class-wc-payments-order-service.php:1408`); live client renewal 28 carries it.' );
		$this->assertCount( 1, $this->get_notes_containing( $order, 'A test payment of' ) );
		$this->assertSame( 0, did_action( 'woocommerce_payment_complete' ) - $payments );
		$this->assertSame( array(), $transitions->list, 'A paid renewal does not put the subscription on hold.' );
		$this->assertCount( 5, $this->get_requests() );
	}

	/**
	 * @testdox A renewal order already paid whose intent cannot be read gets a note, and the event completes (`class-wc-payments-invoice-service.php:294-297`).
	 */
	public function test_renewal_order_paid_before_its_invoice_event_notes_an_unreadable_intent(): void {
		$order = $this->create_paid_renewal_order( $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID ) );
		$this->queue_platform_error();
		$this->queue_billing( 'update_invoice', 'update_charge', 'get_charge_for_update_transaction', 'update_transaction' );

		$this->sut->handle_event( $this->get_event( 'invoice_paid_renewal' ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertCount( 1, $this->get_notes_containing( $order, "The payment info couldn't be added to the order." ) );
		$this->assertSame( '', $order->get_meta( '_intent_id', true ) );
		$this->assertCount( 5, $this->get_requests() );
	}

	/**
	 * @testdox A renewal order the payment lock keeps unpaid fails the event for a retry, not as malformed data.
	 */
	public function test_renewal_order_left_unpaid_by_the_lock_fails_for_a_retry(): void {
		$subscription = $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID );
		$order        = WooCommerceSubscriptionsDoubles::create_renewal_order( $subscription );
		$order->set_payment_method( 'woocommerce_payments' );
		$order->update_meta_data( '_wcpay_billing_invoice_id', self::RENEWAL_INVOICE_ID );
		$order->save();
		wc_get_container()->get( OrderPaymentLock::class )->claim( $order, new WooPaymentsPersistenceVocabulary(), 'pi_rec63CheckoutInFlight', 'payment operation' );
		$this->queue_response( 200, $this->get_renewal_intent() );

		try {
			$this->sut->handle_event( $this->get_event( 'invoice_paid_renewal' ) );
			$this->fail( 'The unpaid renewal order must fail the event.' );
		} catch ( RuntimeException $exception ) {
			// Neither malformed data nor a Stripe Billing refusal: the lock refusal is retried for every event type.
			$this->assertInstanceOf( OrderPaymentLockRefusedException::class, $exception );
		}

		$this->assertTrue( wc_get_order( $order->get_id() )->needs_payment() );
		$this->assertSame( 'active', wc_get_order( $subscription->get_id() )->get_status(), 'A refused delivery changes nothing, the subscription included; the retry applies it.' );
		$this->assertCount( 1, $this->get_requests(), 'Nothing is reported to the platform for an unpaid renewal.' );
	}

	/**
	 * @testdox An event with missing data, or about a subscription or renewal this store cannot find or create, is refused as malformed without a log line of its own: $expected_message
	 * @dataProvider malformed_events
	 *
	 * Event handler :58-79, :117-155, :211-264 and `get_event_property()` :323-337; the client answers 400 for these (`class-wc-rest-payments-webhook-controller.php:81-83`).
	 *
	 * @param string              $pair                 Recorded event.
	 * @param array<string,mixed> $object_changes       Invoice fields to change, null to remove.
	 * @param string              $subscription_id      Stripe subscription of the store's subscription, or empty for none.
	 * @param bool                $renewal_order_fails  Whether creating the renewal order fails.
	 * @param string              $expected_message     Reason given.
	 */
	public function test_malformed_event_is_refused_without_logging( string $pair, array $object_changes, string $subscription_id, bool $renewal_order_fails, string $expected_message ): void {
		if ( '' !== $subscription_id ) {
			$this->create_subscription( $subscription_id );
		}
		$GLOBALS[ WooCommerceSubscriptionsDoubles::RENEWAL_ORDER_ERROR ] = $renewal_order_fails;
		$event  = $this->get_event( $pair, $object_changes );
		$logger = RecordingWcLogger::install();

		try {
			$this->sut->handle_event( $event );
			$this->fail( 'The event must be refused.' );
		} catch ( InvalidArgumentException $exception ) {
			$this->assertSame( $expected_message, $exception->getMessage() );
		}

		$this->assertSame( array(), $logger->get_errors(), 'The webhook route or the failed-event job that catches the refusal logs it, as on the client.' );
		$this->assertSame( array(), $this->get_requests() );
	}

	/**
	 * Events the client refuses as malformed.
	 *
	 * @return array<string,array{string,array<string,mixed>,string,bool,string}>
	 */
	public static function malformed_events(): array {
		return array(
			'paid invoice of an unknown subscription'     => array( 'invoice_paid_renewal', array(), '', false, 'Cannot find subscription for the incoming "invoice.paid" event.' ),
			'failed invoice of an unknown subscription'   => array( 'invoice_payment_failed', array(), '', false, 'Cannot find subscription for the incoming "invoice.payment_failed" event.' ),
			'upcoming invoice of an unknown subscription' => array( 'invoice_upcoming', array(), '', false, 'Cannot find subscription to handle the "invoice.upcoming" event.' ),
			'paid invoice without a subscription'         => array( 'invoice_paid_renewal', array( 'subscription' => null ), self::CLOCK_SUBSCRIPTION_ID, false, 'subscription not found in array' ),
			'failed invoice without an attempt count'     => array( 'invoice_payment_failed', array( 'attempt_count' => null ), self::FAILING_SUBSCRIPTION_ID, false, 'attempt_count not found in array' ),
			'upcoming invoice without discounts'          => array( 'invoice_upcoming', array( 'discounts' => null ), self::CLOCK_SUBSCRIPTION_ID, false, 'discounts not found in array' ),
			'paid invoice whose renewal cannot be made'   => array( 'invoice_paid_renewal', array(), self::CLOCK_SUBSCRIPTION_ID, true, 'Unable to generate renewal order for subscription on the "invoice.paid" event.' ),
			'failed invoice whose renewal cannot be made' => array( 'invoice_payment_failed', array( 'charge' => null ), self::FAILING_SUBSCRIPTION_ID, true, 'Unable to generate renewal order for subscription to record the incoming "invoice.payment_failed" event.' ),
		);
	}

	/**
	 * @testdox An upcoming invoice that lacks an item of the subscription is refused as malformed without a log line of its own, after the Stripe subscription read only.
	 *
	 * The client throws `Rest_Request_Exception` here (`class-wc-payments-invoice-service.php:443`), which its webhook controller answers with 500; neither is retried.
	 */
	public function test_upcoming_invoice_missing_a_subscription_item_is_refused(): void {
		$this->create_subscription( self::CLOCK_SUBSCRIPTION_ID, array( '_schedule_next_payment' => '2026-11-02 08:08:15' ), 'active', 'si_rec63NotOnInvoice' );
		$this->queue_billing( 'get_subscription' );
		$event  = $this->get_event( 'invoice_upcoming' );
		$logger = RecordingWcLogger::install();

		try {
			$this->sut->handle_event( $event );
			$this->fail( 'The event must be refused.' );
		} catch ( InvalidArgumentException $exception ) {
			$this->assertSame( 'The WooPayments invoice items do not match WC subscription items.', $exception->getMessage() );
		}

		// The invoice service logs the mismatch before refusing, as the client does (`class-wc-payments-invoice-service.php:442-443`); the handler adds no line of its own.
		$this->assertCount( 1, $logger->get_errors() );
		$this->assertCount( 1, $this->get_requests() );
	}

	/**
	 * @testdox On a staging copy, a $pair event is logged and skipped, changing nothing here or at Stripe (event handler :62-72, :122-132, :216-226, :345-356).
	 * @testWith ["invoice_paid_renewal", "invoice.paid", "sub_1UM1XLBzWlxcwgpPwEbcmZJt"]
	 *           ["invoice_upcoming", "invoice.upcoming", "sub_1UM1XLBzWlxcwgpPwEbcmZJt"]
	 *           ["invoice_payment_failed", "invoice.payment_failed", "sub_1UM1XOBzWlxcwgpP3eeyov4u"]
	 *
	 * @param string $pair            Recorded event.
	 * @param string $event_type      Event type.
	 * @param string $subscription_id Stripe subscription the event names.
	 */
	public function test_staging_copy_logs_and_skips_invoice_events( string $pair, string $event_type, string $subscription_id ): void {
		$subscription = $this->create_subscription( $subscription_id );
		$GLOBALS[ WooCommerceSubscriptionsDoubles::DUPLICATE_SITE ] = true;
		$event = $this->get_event( $pair );

		$this->sut->handle_event( $event );

		$this->assertSame( array(), $this->get_requests() );
		$this->assertSame( array(), wc_get_orders( array( 'return' => 'ids' ) ), 'No renewal order is created; the active subscription double is not listed.' );
		$this->assertSame( 'active', wc_get_order( $subscription->get_id() )->get_status() );
		$this->assertContains( array( 'info', "$event_type webhook processing for $subscription_id was skipped. The current site (https://staging.rec-t63.test) is in staging mode. Live site is https://live.rec-t63.test.", 'woopayments' ), $this->log_lines );
	}

	/**
	 * @testdox A failed renewal attempt notes the decline on the subscription and the renewal order, puts the subscription on hold without pausing it at Stripe, and keeps the invoice for a later payment method change (client test_invoice_payment_failed).
	 *
	 * Event handler :236-310; notes :268-292; the context sent is `class-wc-payments-invoice-service.php:315`.
	 */
	public function test_failed_renewal_attempt_is_recorded(): void {
		$subscription = $this->create_subscription( self::FAILING_SUBSCRIPTION_ID );
		$this->queue_response( 200, $this->get_declined_charge() );
		$this->queue_billing( 'update_invoice' );

		$this->sut->handle_event( $this->get_event( 'invoice_payment_failed' ) );

		$subscription = wc_get_order( $subscription->get_id() );
		$order        = $this->get_renewal_orders( self::FAILED_INVOICE_ID )[0];
		$this->assertSame( 'on-hold', $subscription->get_status() );
		$this->assertSame( self::FAILED_INVOICE_ID, $subscription->get_meta( '_wcpay_pending_invoice_id', true ) );
		$this->assertSame( 'woocommerce_payments', $order->get_payment_method() );
		$this->assertCount( 1, $this->get_notes_containing( $subscription, 'WooPayments subscription renewal attempt 1 failed with the following message "The bank did not return any further details with this decline." and failure code <code>card_declined</code>' ) );
		$this->assertCount( 1, $this->get_notes_containing( $order, 'Payment for the order failed with the following message: "The bank did not return any further details with this decline." and failure code <code>card_declined</code>' ) );
		$this->assertSame(
			array(
				array( 'GET', '/sites/4/wcpay/charges/' . self::FAILED_CHARGE_ID . '?test_mode=1', null ),
				array(
					'POST',
					'/sites/4/wcpay/invoices/' . self::FAILED_INVOICE_ID,
					array(
						'test_mode'            => true,
						'subscription_context' => 'stripe_billing',
					),
				),
			),
			$this->get_requests(),
			'Stripe retries the invoice itself, so the subscription is not paused there.'
		);
	}

	/**
	 * @testdox After $attempts failed attempts the subscription is $expected_status; only cancelling reaches Stripe (client test_invoice_payment_failed_max_attempts, event handler :298-304, MAX_RETRIES 4).
	 * @testWith [3, "on-hold", false]
	 *           [4, "cancelled", true]
	 *
	 * @param int    $attempts           Attempts Stripe made.
	 * @param string $expected_status    Subscription status after the event.
	 * @param bool   $cancelled_at_stripe Whether the Stripe subscription is cancelled.
	 */
	public function test_subscription_is_cancelled_after_the_last_attempt( int $attempts, string $expected_status, bool $cancelled_at_stripe ): void {
		$subscription = $this->create_subscription( self::FAILING_SUBSCRIPTION_ID );
		$this->queue_response( 200, $this->get_declined_charge() );
		if ( $cancelled_at_stripe ) {
			$this->queue_billing( 'cancel_subscription' );
		}
		$this->queue_billing( 'update_invoice' );

		$this->sut->handle_event( $this->get_event( 'invoice_payment_failed', array( 'attempt_count' => $attempts ) ) );

		$this->assertSame( $expected_status, wc_get_order( $subscription->get_id() )->get_status() );
		$this->assertSame(
			$cancelled_at_stripe ? array( 'DELETE', '/sites/4/wcpay/subscriptions/' . self::FAILING_SUBSCRIPTION_ID . '?test_mode=1', null ) : null,
			array_values( array_filter( $this->get_requests(), static fn( array $request ) => false !== strpos( $request[1], '/subscriptions/' ) ) )[0] ?? null
		);
	}

	/**
	 * @testdox A failed attempt whose charge cannot be read is noted without the decline message (event handler :236-244, :293-296).
	 */
	public function test_failed_attempt_with_an_unreadable_charge_is_noted_without_a_message(): void {
		$subscription = $this->create_subscription( self::FAILING_SUBSCRIPTION_ID );
		$this->queue_platform_error();
		$this->queue_billing( 'update_invoice' );

		$this->sut->handle_event( $this->get_event( 'invoice_payment_failed' ) );

		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertCount( 1, $this->get_notes_containing( $subscription, 'WooPayments subscription renewal attempt 1 failed.' ) );
		$this->assertSame( array(), $this->get_notes_containing( $this->get_renewal_orders( self::FAILED_INVOICE_ID )[0], 'Payment for the order failed' ) );
		$this->assertSame( 'on-hold', $subscription->get_status() );
	}

	/**
	 * @testdox A platform error reading a failed attempt's charge is logged with its status and code, never its message.
	 *
	 * Client 11.1.0 appends the platform's message (`class-wc-payments-subscriptions-event-handler.php:242`).
	 */
	public function test_unreadable_charge_log_leaves_out_platform_text(): void {
		$subscription = $this->create_subscription( self::FAILING_SUBSCRIPTION_ID );
		// The error envelope client 11.1.0 reads (error.code, error.message, error.type; class-wc-payments-api-client.php:2852-2871).
		$this->queue_response(
			404,
			array(
				'error' => array(
					'code'    => 'resource_missing',
					'message' => "No such customer: 'cus_123'; ask shopper@example.com, see https://pay.example.test/r?key=sk_test_leak123",
					'type'    => 'invalid_request_error',
				),
			)
		);
		$this->queue_billing( 'update_invoice' );
		$logger = RecordingWcLogger::install();

		$this->sut->handle_event( $this->get_event( 'invoice_payment_failed' ) );

		$context = $this->get_logged_context_at_level( $logger, 'error' );
		$this->assertSame( array( 404, 'resource_missing' ), array( $context['http_status'], $context['error_code'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
		$this->assertSame( 'on-hold', wc_get_order( $subscription->get_id() )->get_status() );
	}

	/**
	 * @testdox An upcoming invoice moves the next payment date to the end of the Stripe period and repairs the Stripe items (client test_handle_invoice_upcoming_active, event handler :100-106).
	 *
	 * The Stripe subscription answer is the recorded `get_subscription` of the main chain; only its `current_period_end` (2026-11-02 08:08:15 UTC) is read.
	 */
	public function test_upcoming_invoice_aligns_the_next_payment_and_repairs_the_items(): void {
		$subscription = $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID, array( '_schedule_next_payment' => '2026-10-30 00:00:00' ), 'active', self::CLOCK_ITEM_ID, 2, '59.80' );
		$this->queue_billing( 'get_subscription', 'update_subscription_item' );

		$this->sut->handle_event( $this->get_event( 'invoice_upcoming' ) );

		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertSame( '2026-11-02 08:08:15', $subscription->get_meta( '_schedule_next_payment', true ) );
		$this->assertCount( 1, $this->get_notes_containing( $subscription, 'Next automatic payment scheduled for November 2, 2026 8:08 am.' ) );
		// The repair body is pinned where it is built: StripeBillingInvoiceServiceTest::test_validating_repairs_the_quantity_and_price_of_an_item.
		$this->assertSame(
			array(
				array( 'GET', '/sites/4/wcpay/subscriptions/' . self::CLOCK_SUBSCRIPTION_ID . '?test_mode=1' ),
				array( 'POST', '/sites/4/wcpay/subscriptions/items/' . self::CLOCK_ITEM_ID ),
			),
			array_map( static fn( array $request ): array => array( $request[0], $request[1] ), $this->get_requests() )
		);
	}

	/**
	 * @testdox An upcoming invoice for a $status subscription without a next payment and with end date "$end" makes Stripe $action it (client test_handle_invoice_upcoming_suspended, event handler :84-99).
	 * @testWith ["active", "2027-03-01 00:00:00", "cancel"]
	 *           ["on-hold", "2027-03-01 00:00:00", "suspend"]
	 *           ["active", "", "suspend"]
	 *
	 * @param string $status Subscription status.
	 * @param string $end    Subscription end date, empty for none.
	 * @param string $action What Stripe is asked to do: `cancel` or `suspend`.
	 */
	public function test_upcoming_invoice_without_a_next_payment_stops_stripe_billing( string $status, string $end, string $action ): void {
		$subscription = $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID, array( '_schedule_end' => $end ), $status );
		$this->queue_billing( 'get_subscription', 'cancel' === $action ? 'cancel_subscription' : 'update_subscription' );

		$this->sut->handle_event( $this->get_event( 'invoice_upcoming' ) );

		$requests = $this->get_requests();
		$this->assertCount( 2, $requests );
		$this->assertSame(
			'cancel' === $action
				? array( 'DELETE', '/sites/4/wcpay/subscriptions/' . self::CLOCK_SUBSCRIPTION_ID . '?test_mode=1', null )
				: array(
					'POST',
					'/sites/4/wcpay/subscriptions/' . self::CLOCK_SUBSCRIPTION_ID,
					array(
						'test_mode'        => true,
						'pause_collection' => array( 'behavior' => 'void' ),
					),
				),
			$requests[1]
		);
		$this->assertCount(
			'suspend' === $action ? 1 : 0,
			$this->get_notes_containing( wc_get_order( $subscription->get_id() ), 'Suspended WooPayments Subscription in invoice.upcoming webhook handler because subscription next_payment date is 0.' )
		);
	}

	/**
	 * @testdox An upcoming invoice for a subscription moved off WooPayments that still holds its Stripe ID cancels it again and leaves its schedule alone; the cancel $answer.
	 * @testWith ["succeeds", "_cancelled_wcpay_subscription_id", false]
	 *           ["fails", "_wcpay_subscription_id", true]
	 *
	 * @param string $answer          How the cancel ends.
	 * @param string $expected_id_key Meta key that holds the Stripe ID afterwards.
	 * @param bool   $expected_retry  Whether a cancel retry is scheduled.
	 */
	public function test_upcoming_invoice_for_a_subscription_moved_off_woopayments_cancels_it_again( string $answer, string $expected_id_key, bool $expected_retry ): void {
		$subscription = $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID, array( '_schedule_next_payment' => '2026-10-30 00:00:00' ) );
		$subscription->set_payment_method( 'stripe' );
		$subscription->save();
		if ( 'succeeds' === $answer ) {
			$this->queue_billing( 'cancel_subscription' );
		} else {
			$this->queue_platform_error();
		}

		$this->sut->handle_event( $this->get_event( 'invoice_upcoming' ) );

		$this->assertSame( array( array( 'DELETE', '/sites/4/wcpay/subscriptions/' . self::CLOCK_SUBSCRIPTION_ID . '?test_mode=1', null ) ), $this->get_requests(), 'No read of the Stripe subscription and no item repair.' );
		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertSame( self::CLOCK_SUBSCRIPTION_ID, $subscription->get_meta( $expected_id_key, true ) );
		$this->assertSame( '2026-10-30 00:00:00', $subscription->get_meta( '_schedule_next_payment', true ), 'Stripe\'s period end is not written onto the other payment method\'s schedule.' );
		$this->assertSame( array(), $this->get_notes_containing( $subscription, 'Next automatic payment scheduled for' ) );
		$this->assertSame(
			$expected_retry,
			as_has_scheduled_action(
				StripeBillingSubscriptionService::RETRY_CANCEL_ACTION,
				array(
					'subscription_id' => $subscription->get_id(),
					'retry'           => 1,
				),
				'woocommerce_payments'
			)
		);
	}

	/**
	 * @testdox An upcoming invoice whose pause fails at Stripe notes the failure, not a suspension.
	 */
	public function test_upcoming_invoice_with_a_failed_pause_does_not_note_a_suspension(): void {
		$subscription = $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID, array(), 'on-hold' );
		$this->queue_billing( 'get_subscription' );
		$this->queue_platform_error();

		$this->sut->handle_event( $this->get_event( 'invoice_upcoming' ) );

		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertSame( array(), $this->get_notes_containing( $subscription, 'Suspended WooPayments Subscription in invoice.upcoming webhook handler' ) );
		$this->assertCount( 1, $this->get_notes_containing( $subscription, 'WooPayments could not pause this subscription at Stripe' ) );
	}

	/**
	 * @testdox An upcoming invoice whose Stripe subscription cannot be read fails for a retry before changing anything.
	 *
	 * The client goes on with no Stripe subscription and fails with a type error after noting a wrong date (event handler :82, :102-104).
	 */
	public function test_upcoming_invoice_with_an_unreadable_stripe_subscription_fails_for_a_retry(): void {
		$subscription = $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID, array( '_schedule_next_payment' => '2026-10-30 00:00:00' ) );
		$this->queue_platform_error();

		try {
			$this->sut->handle_event( $this->get_event( 'invoice_upcoming' ) );
			$this->fail( 'The event must fail.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( RuntimeException::class, get_class( $exception ), 'Neither malformed data nor a Stripe Billing refusal.' );
		}

		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertSame( '2026-10-30 00:00:00', $subscription->get_meta( '_schedule_next_payment', true ) );
		$this->assertSame( array(), $this->get_notes_containing( $subscription, 'Next automatic payment scheduled' ) );
	}

	/**
	 * Create a subscription billed by Stripe Billing, with one monthly line item linked to a Stripe subscription item.
	 *
	 * @param string               $wcpay_subscription_id Stripe subscription ID.
	 * @param array<string,string> $meta                  Extra subscription meta.
	 * @param string               $status                Subscription status.
	 * @param string               $wcpay_item_id         Stripe subscription item of the line item.
	 * @param int                  $quantity              Line item quantity.
	 * @param string               $subtotal              Line item subtotal.
	 * @return SubscriptionDouble
	 */
	private function create_subscription( string $wcpay_subscription_id, array $meta = array(), string $status = 'active', string $wcpay_item_id = self::CLOCK_ITEM_ID, int $quantity = 1, string $subtotal = '24.90' ): SubscriptionDouble {
		$product = \WC_Helper_Product::create_simple_product();
		$product->update_meta_data( '_wcpay_product_id_test', self::RECORDED_PRODUCT_ID );
		$product->update_meta_data( '_wcpay_product_id_test_linked_to', self::RECORDED_ACCOUNT_ID );
		$product->save();

		$subscription = new SubscriptionDouble();
		$subscription->set_customer_id( 1 );
		$subscription->set_payment_method( 'woocommerce_payments' );
		$subscription->set_currency( 'USD' );
		$subscription->set_billing_first_name( 'Rec' );
		$subscription->set_billing_last_name( 'Renewal' );
		$subscription->set_billing_email( 'rec-t63-renewal@woo.test' );
		$subscription->set_billing_country( 'US' );
		$meta += array(
			'_wcpay_subscription_id'    => $wcpay_subscription_id,
			'_wcpay_billing_invoice_id' => self::CLOCK_PARENT_INVOICE_ID,
			'_billing_period'           => 'month',
			'_billing_interval'         => '1',
		);
		foreach ( $meta as $key => $value ) {
			$subscription->update_meta_data( $key, $value );
		}

		$item = new \WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( $quantity );
		$item->set_subtotal( $subtotal );
		$item->set_total( $subtotal );
		$item->update_meta_data( '_wcpay_subscription_item_id', $wcpay_item_id );
		$subscription->add_item( $item );
		$subscription->set_total( $subtotal );
		$subscription->save();

		// Registered before the status is set, so status change listeners load it as a subscription.
		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ][] = $subscription->get_id();
		$subscription = wc_get_order( $subscription->get_id() );
		$subscription->set_status( $status );
		$subscription->save();

		return $subscription;
	}

	/**
	 * Create the renewal order of a paid invoice, paid without a payment intent as a payment method change pays it.
	 *
	 * @param SubscriptionDouble $subscription Subscription.
	 * @return WC_Order
	 */
	private function create_paid_renewal_order( SubscriptionDouble $subscription ): WC_Order {
		$order = WooCommerceSubscriptionsDoubles::create_renewal_order( $subscription );
		$order->set_payment_method( 'woocommerce_payments' );
		$order->update_meta_data( '_wcpay_billing_invoice_id', self::RENEWAL_INVOICE_ID );
		$order->payment_complete();

		return $order;
	}

	/**
	 * Create the unpaid renewal order of the recorded renewal invoice.
	 *
	 * @param SubscriptionDouble $subscription Subscription.
	 * @param string             $total        Renewal total.
	 * @return WC_Order
	 */
	private function create_unpaid_renewal_order( SubscriptionDouble $subscription, string $total ): WC_Order {
		$order = WooCommerceSubscriptionsDoubles::create_renewal_order( $subscription );
		$order->set_payment_method( 'woocommerce_payments' );
		$order->set_total( $total );
		$order->update_meta_data( '_wcpay_billing_invoice_id', self::RENEWAL_INVOICE_ID );
		$order->save();

		return $order;
	}

	/**
	 * Change the renewal through another order object, as another request would, while the event reads its intent.
	 *
	 * @param int      $order_id Renewal order ID.
	 * @param callable $change   Change applied to the other object, which is then saved.
	 */
	private function change_the_renewal_while_the_intent_is_read( int $order_id, callable $change ): void {
		$changed = false;
		add_filter(
			'wcpay_api_request_params',
			static function ( $params, $api ) use ( $order_id, $change, &$changed ) {
				if ( ! $changed && false !== strpos( (string) $api, 'intentions/' ) ) {
					$changed = true;
					$other   = wc_get_order( $order_id );
					$change( $other );
					$other->save();
				}
				return $params;
			},
			10,
			2
		);
	}

	/**
	 * Record the subscription status changes WooCommerce Subscriptions announces.
	 *
	 * @return object With a `list` of the changes, such as `on-hold` or `on-hold_to_active`.
	 */
	private function record_subscription_transitions(): object {
		$transitions = new class() {
			/**
			 * Changes announced.
			 *
			 * @var string[]
			 */
			public array $list = array();
		};
		add_action(
			'woocommerce_subscription_status_on-hold',
			static function () use ( $transitions ): void {
				$transitions->list[] = 'on-hold';
			}
		);
		add_action(
			'woocommerce_subscription_status_on-hold_to_active',
			static function () use ( $transitions ): void {
				$transitions->list[] = 'on-hold_to_active';
			}
		);

		return $transitions;
	}

	/**
	 * Get a recorded invoice event, with some invoice fields changed.
	 *
	 * @param string              $pair           Fixture entry.
	 * @param array<string,mixed> $object_changes Invoice fields to change, null to remove.
	 * @return array<string,mixed>
	 */
	private function get_event( string $pair, array $object_changes = array() ): array {
		$event = $this->get_entry( self::EVENTS_FIXTURE, $pair )['body'];
		foreach ( $object_changes as $key => $value ) {
			if ( null === $value ) {
				unset( $event['data']['object'][ $key ] );
			} else {
				$event['data']['object'][ $key ] = $value;
			}
		}

		return $event;
	}

	/**
	 * Get the recorded renewal intent, as the platform forwarded it in `payment_intent.succeeded`.
	 *
	 * @return array<string,mixed>
	 */
	private function get_renewal_intent(): array {
		return $this->get_entry( self::EVENTS_FIXTURE, 'payment_intent_succeeded_with_invoice' )['body']['data']['object'];
	}

	/**
	 * Get a recorded declined card charge (`generic_decline`).
	 *
	 * @return array<string,mixed>
	 */
	private function get_declined_charge(): array {
		return $this->get_entry( self::DECLINES_FIXTURE, 'generic_decline' )['response']['body']['error']['payment_intent']['charges']['data'][0];
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
	 */
	private function queue_billing( string ...$pairs ): void {
		foreach ( $pairs as $pair ) {
			$entry = $this->get_entry( self::BILLING_FIXTURE, $pair );
			$this->queue_response( (int) $entry['response']['http_status'], $entry['response']['body'] );
		}
	}

	/**
	 * Queue a platform failure.
	 */
	private function queue_platform_error(): void {
		$this->queue_response(
			500,
			array(
				'code'    => 'wcpay_server_error',
				'message' => 'Internal Server Error',
				'data'    => array( 'status' => 500 ),
			)
		);
	}

	/**
	 * Queue a JSON platform answer.
	 *
	 * @param int   $http_status HTTP status.
	 * @param mixed $body        Decoded body.
	 */
	private function queue_response( int $http_status, $body ): void {
		$this->http_client->responses[] = array(
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
	 * Get the orders paid by an invoice; the subscription doubles, stored as orders, are left out.
	 *
	 * @param string $invoice_id Invoice ID.
	 * @return WC_Order[]
	 */
	private function get_renewal_orders( string $invoice_id ): array {
		$orders = wc_get_orders(
			array(
				'status'     => 'any',
				'type'       => 'shop_order',
				'meta_key'   => '_wcpay_billing_invoice_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $invoice_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return array_values( array_filter( $orders, static fn( $order ) => ! $order instanceof SubscriptionDouble ) );
	}

	/**
	 * Get the not-held note the recorded renewal invoice adds for a subscription.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @param string   $status_name  Subscription status name the note gives.
	 * @return string
	 */
	private function get_not_held_note( WC_Order $subscription, string $status_name ): string {
		$charge_url = wc_get_container()->get( WooPaymentsOrderNoteService::class )->transaction_url( self::RENEWAL_INTENT_ID, self::RENEWAL_CHARGE_ID );

		return 'Stripe Billing collected renewal invoice <code>' . self::RENEWAL_INVOICE_ID . '</code> for ' . wc_price( 24.9, array( 'currency' => 'USD' ) )
			. ' (WooPayments charge <a href="' . esc_url( $charge_url ) . '" target="_blank" rel="noopener noreferrer">' . self::RENEWAL_CHARGE_ID . '</a>) while subscription #'
			. $subscription->get_order_number() . ' was ' . $status_name . ', and this order now records that payment. If the customer should not have been charged, refund the payment from this order. WooPayments has not confirmed that Stripe Billing stopped billing this subscription, so the customer may be charged again at a later renewal.';
	}

	/**
	 * Get the contents of every note of an order or subscription.
	 *
	 * @param WC_Order $order Order or subscription.
	 * @return array<int,string>
	 */
	private function get_note_contents( WC_Order $order ): array {
		return array_map( static fn( $note ) => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * Get the notes of an order or subscription that contain a text.
	 *
	 * @param WC_Order $order Order or subscription.
	 * @param string   $text  Text the notes contain; never empty.
	 * @return array<int,string>
	 */
	private function get_notes_containing( WC_Order $order, string $text ): array {
		return array_values( array_filter( $this->get_note_contents( $order ), static fn( string $note ) => false !== strpos( $note, $text ) ) );
	}

	/**
	 * Create a renewal storing an uncaptured WooPayments authorization as its payment: the transaction ID is the stored
	 * intent, whose stored status is requires_capture (an authorized order a merchant then moves on by hand).
	 *
	 * @param SubscriptionDouble $subscription Subscription.
	 * @param string             $status       Renewal status.
	 * @return WC_Order
	 */
	private function create_renewal_storing_an_authorization( SubscriptionDouble $subscription, string $status ): WC_Order {
		$order = $this->create_unpaid_renewal_order( $subscription, '24.90' );
		$order->set_transaction_id( 'pi_authorized' );
		$order->update_meta_data( '_intent_id', 'pi_authorized' );
		$order->update_meta_data( '_charge_id', 'ch_authorized' );
		$order->update_meta_data( '_intention_status', 'requires_capture' );
		$order->set_status( $status );
		$order->save();

		return wc_get_order( $order->get_id() );
	}

	/**
	 * Get what a renewal records about its payment.
	 *
	 * @param WC_Order $order Renewal order.
	 * @return array<string,string>
	 */
	private function get_payment_record( WC_Order $order ): array {
		$record = array(
			'transaction_id'       => $order->get_transaction_id(),
			'payment_method'       => $order->get_payment_method(),
			'payment_method_title' => $order->get_payment_method_title(),
		);
		foreach ( array( '_intent_id', '_charge_id', '_intention_status', '_payment_method_id', '_wcpay_mode', '_stripe_customer_id', '_wcpay_transaction_fee', '_wcpay_net', '_wcpay_intent_currency' ) as $key ) {
			$record[ $key ] = (string) $order->get_meta( $key, true );
		}

		return $record;
	}

	/**
	 * Count the Fee details jobs scheduled for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return int
	 */
	private function count_fee_details_actions( WC_Order $order ): int {
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => WooPaymentsFeeDetailsNoteController::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION,
				'group'    => 'woocommerce_payments',
				'per_page' => -1,
			)
		);

		return count( array_filter( $actions, static fn( $action ): bool => $order->get_id() === ( $action->get_args()['order_id'] ?? null ) ) );
	}

	/**
	 * Count the log lines written that contain a text. Each log handler receives every line, so the count is one handler's.
	 *
	 * @param string $text Text the lines contain.
	 * @return int
	 */
	private function count_log_lines( string $text ): int {
		$counts = array( 0 );
		foreach ( $this->handled_log_messages as $line ) {
			if ( false !== strpos( $line[0], $text ) ) {
				$counts[ $line[1] ] = ( $counts[ $line[1] ] ?? 0 ) + 1;
			}
		}

		return max( $counts );
	}
}
