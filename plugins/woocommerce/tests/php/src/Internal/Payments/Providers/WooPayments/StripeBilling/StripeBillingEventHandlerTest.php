<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFrontendCurrenciesController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyFrontendProjectionService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyGeolocationService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyLocalizationService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyProjectionServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRequestContext;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRuntimeServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOperationalQueueService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingApi;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingInvoiceService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingProductService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingSubscriptionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\WooPaymentsStripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsEventIngestor;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceProfile;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeWooPaymentsHttpClient;
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
 * local platform recordings in `Fixtures/rec-t63-invoice-events.json`; platform answers are the recordings in
 * `Fixtures/rec-t63-billing-api.json`. Request bodies are written from the client's array literals, with `test_mode` first
 * as the client's `request()` adds it (`class-wc-payments-api-client.php:2635-2640`).
 */
class StripeBillingEventHandlerTest extends WC_Unit_Test_Case {

	private const EVENTS_FIXTURE  = __DIR__ . '/../Fixtures/rec-t63-invoice-events.json';
	private const BILLING_FIXTURE = __DIR__ . '/../Fixtures/rec-t63-billing-api.json';

	/**
	 * Card declines recorded from `POST intentions` on the local platform; their charges stand in for the failed renewal charge, which was not read.
	 */
	private const DECLINES_FIXTURE = __DIR__ . '/../Fixtures/rec-1-intention-declines.json';

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
	 * @var FakeWooPaymentsHttpClient
	 */
	private FakeWooPaymentsHttpClient $http_client;

	/**
	 * Subscription service the module's status hooks call.
	 *
	 * @var StripeBillingSubscriptionService
	 */
	private StripeBillingSubscriptionService $subscription_service;

	/**
	 * Log lines written, as level, message and source.
	 *
	 * @var array<int,array{0:string,1:string,2:string}>
	 */
	private array $log_lines = array();

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

		$this->http_client          = new FakeWooPaymentsHttpClient();
		$this->http_client->blog_id = self::RECORDED_BLOG_ID;

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'is_test_mode_onboarding_enabled', 'get_account_id', 'is_dev_mode_enabled', 'get_gateway_setting' ) )
			->getMock();
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );
		$account_service->method( 'is_test_mode_onboarding_enabled' )->willReturn( true );
		$account_service->method( 'get_account_id' )->willReturn( self::RECORDED_ACCOUNT_ID );
		$account_service->method( 'get_gateway_setting' )->willReturnCallback( fn( string $key ) => 'enable_logging' === $key ? 'yes' : '' );

		$api_client = new WooPaymentsApiClient();
		$api_client->init( $this->http_client, $account_service );
		$api = new StripeBillingApi();
		$api->init( $api_client );
		$logger = new StripeBillingLogger();
		$logger->init( $account_service );

		$product_service = new StripeBillingProductService();
		$product_service->init( $api, $account_service, $logger );

		$module = $this->createMock( WooPaymentsStripeBillingModule::class );
		$module->method( 'is_stripe_billing_enabled' )->willReturn( true );
		$invoice_service = new StripeBillingInvoiceService();
		$invoice_service->init( $api, $api_client, $module, $logger );

		$this->subscription_service = new StripeBillingSubscriptionService();
		$this->subscription_service->init( $api, $this->createMock( WooPaymentsCustomerService::class ), $product_service, $invoice_service, $logger );
		wc_get_container()->replace( StripeBillingSubscriptionService::class, $this->subscription_service );

		$this->sut = new StripeBillingEventHandler();
		$this->sut->init( $invoice_service, $this->subscription_service, $api_client, wc_get_container()->get( WooPaymentsEventIngestor::class ), $account_service, $logger );

		add_action( 'woocommerce_subscription_status_on-hold', array( $this->subscription_service, 'handle_subscription_status_on_hold' ) );
		add_action( 'woocommerce_subscription_status_on-hold_to_active', array( $this->subscription_service, 'reactivate_subscription' ) );
		add_action( 'woocommerce_subscription_status_cancelled', array( $this->subscription_service, 'cancel_subscription' ) );

		add_filter(
			'woocommerce_logger_log_message',
			function ( $message, $level, $context ) {
				$this->log_lines[] = array( (string) $level, (string) $message, (string) ( $context['source'] ?? '' ) );
				return $message;
			},
			10,
			3
		);
	}

	/**
	 * Clear the registries the doubles read.
	 */
	public function tearDown(): void {
		try {
			if ( null !== $this->format_controller ) {
				foreach ( array( 'woocommerce_currency', 'wc_get_price_decimals', 'wc_get_price_decimal_separator', 'wc_get_price_thousand_separator', 'woocommerce_price_format', 'option_woocommerce_currency_pos', 'woocommerce_order_get_total', 'woocommerce_get_formatted_order_total', 'woocommerce_thankyou_order_id', 'woocommerce_cart_hash', 'woocommerce_shipping_method_add_rate_args', 'before_woocommerce_pay', 'woocommerce_account_view-order_endpoint' ) as $hook ) {
					remove_all_filters( $hook, 900 );
				}
				$this->format_controller = null;
			}
			delete_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION );
			remove_all_filters( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED );
			wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->invalidate();
			unset(
				$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ],
				$GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ],
				$GLOBALS[ WooCommerceSubscriptionsDoubles::RENEWAL_SUBSCRIPTIONS ],
				$GLOBALS[ WooCommerceSubscriptionsDoubles::DUPLICATE_SITE ],
				$GLOBALS[ WooCommerceSubscriptionsDoubles::RENEWAL_ORDER_ERROR ]
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
		$subscription = $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID, array( '_wcpay_pending_invoice_id' => self::FAILED_INVOICE_ID ) );
		$transitions  = $this->record_subscription_transitions();
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
		$this->assertCount( 1, $this->get_notes_containing( $order, 'A test payment of' ), 'A test-mode renewal gets the test wording.' );
		$this->assertTrue(
			as_has_scheduled_action(
				WooPaymentsOperationalQueueService::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION,
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
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		update_option( 'active_plugins', array() );
		wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->invalidate();
		$this->assertTrue( wc_get_container()->get( MultiCurrencyRuntimeArbiter::class )->should_core_register() );

		$localization = wc_get_container()->get( MultiCurrencyLocalizationService::class );
		$usd          = new MultiCurrencyCurrency( $localization, 'USD', 1.0, true );
		$enabled      = array(
			'USD'          => $usd,
			$selected_code => new MultiCurrencyCurrency( $localization, $selected_code, 0.8, false ),
		);
		$state        = new MultiCurrencyState( $enabled, $enabled, $usd, $enabled[ $selected_code ] );
		$builder      = new class( $state ) extends MultiCurrencyStateBuilder {
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
			wc_get_container()->get( MultiCurrencyProjectionServiceFactory::class ),
			wc_get_container()->get( MultiCurrencyRuntimeServiceFactory::class )
		);
		$controller->set_frontend_projection_service( new MultiCurrencyFrontendProjectionService( $builder, $localization, new MultiCurrencyGeolocationService( $localization, static fn() => 'US' ) ) );
		$controller->set_request_context(
			new class() extends MultiCurrencyRequestContext {
				/**
				 * The webhook request registers the frontend price hooks.
				 *
				 * @return bool
				 */
				public function should_register_frontend_hooks(): bool {
					return true;
				}
			}
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
		} catch ( WooPaymentsApiException $exception ) {
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
		wc_get_container()->get( OrderPaymentStore::class )->claim_order_payment_lock( $order, new WooPaymentsPersistenceProfile(), 'pi_rec63CheckoutInFlight' );
		$this->queue_response( 200, $this->get_renewal_intent() );

		try {
			$this->sut->handle_event( $this->get_event( 'invoice_paid_renewal' ) );
			$this->fail( 'The unpaid renewal order must fail the event.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( RuntimeException::class, get_class( $exception ), 'Neither malformed data nor a Stripe Billing refusal.' );
		}

		$this->assertTrue( wc_get_order( $order->get_id() )->needs_payment() );
		$this->assertCount( 1, $this->get_requests(), 'Nothing is reported to the platform for an unpaid renewal.' );
	}

	/**
	 * @testdox An event with missing data, or about a subscription or renewal this store cannot find or create, is refused as malformed and logged with its ID: $expected_message
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
	public function test_malformed_event_is_refused_and_logged( string $pair, array $object_changes, string $subscription_id, bool $renewal_order_fails, string $expected_message ): void {
		if ( '' !== $subscription_id ) {
			$this->create_subscription( $subscription_id );
		}
		$GLOBALS[ WooCommerceSubscriptionsDoubles::RENEWAL_ORDER_ERROR ] = $renewal_order_fails;
		$event = $this->get_event( $pair, $object_changes );

		try {
			$this->sut->handle_event( $event );
			$this->fail( 'The event must be refused.' );
		} catch ( InvalidArgumentException $exception ) {
			$this->assertSame( $expected_message, $exception->getMessage() );
		}

		$this->assertContains( array( 'error', sprintf( 'WooPayments webhook event %1$s (%2$s) was refused: %3$s', $event['id'], $event['type'], $expected_message ), 'native-payments-webhook' ), $this->log_lines );
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
	 * @testdox An upcoming invoice that lacks an item of the subscription is refused as malformed and logged, after the Stripe subscription read only.
	 *
	 * The client throws `Rest_Request_Exception` here (`class-wc-payments-invoice-service.php:443`), which its webhook controller answers with 500; neither is retried.
	 */
	public function test_upcoming_invoice_missing_a_subscription_item_is_refused(): void {
		$this->create_subscription( self::CLOCK_SUBSCRIPTION_ID, array( '_schedule_next_payment' => '2026-11-02 08:08:15' ), 'active', 'si_rec63NotOnInvoice' );
		$this->queue_billing( 'get_subscription' );
		$event = $this->get_event( 'invoice_upcoming' );

		try {
			$this->sut->handle_event( $event );
			$this->fail( 'The event must be refused.' );
		} catch ( InvalidArgumentException $exception ) {
			$this->assertSame( 'The WooPayments invoice items do not match WC subscription items.', $exception->getMessage() );
		}

		$this->assertContains( array( 'error', sprintf( 'WooPayments webhook event %s (invoice.upcoming) was refused: The WooPayments invoice items do not match WC subscription items.', $event['id'] ), 'native-payments-webhook' ), $this->log_lines );
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
	 * @testdox An upcoming invoice moves the next payment date to the end of the Stripe period and repairs the Stripe items (client test_handle_invoice_upcoming_active, event handler :100-106).
	 *
	 * The Stripe subscription answer is the recorded `get_subscription` of the main chain; only its `current_period_end` (2026-11-02 08:08:15 UTC) is read.
	 * The repair payload is the client's `format_item_price_data()` (`class-wc-payments-subscription-service.php:337-358`): 59.80 for two is 2990 cents a unit.
	 */
	public function test_upcoming_invoice_aligns_the_next_payment_and_repairs_the_items(): void {
		$subscription = $this->create_subscription( self::CLOCK_SUBSCRIPTION_ID, array( '_schedule_next_payment' => '2026-10-30 00:00:00' ), 'active', self::CLOCK_ITEM_ID, 2, '59.80' );
		$this->queue_billing( 'get_subscription', 'update_subscription_item' );

		$this->sut->handle_event( $this->get_event( 'invoice_upcoming' ) );

		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertSame( '2026-11-02 08:08:15', $subscription->get_meta( '_schedule_next_payment', true ) );
		$this->assertCount( 1, $this->get_notes_containing( $subscription, 'Next automatic payment scheduled for November 2, 2026 8:08 am.' ) );
		$this->assertSame(
			array(
				array( 'GET', '/sites/4/wcpay/subscriptions/' . self::CLOCK_SUBSCRIPTION_ID . '?test_mode=1', null ),
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
	 * Get the notes of an order or subscription that contain a text.
	 *
	 * @param WC_Order $order Order or subscription.
	 * @param string   $text  Text the notes contain.
	 * @return array<int,string>
	 */
	private function get_notes_containing( WC_Order $order, string $text ): array {
		$notes = array_map( static fn( $note ) => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );

		return array_values( array_filter( $notes, static fn( string $note ) => false !== strpos( $note, $text ) ) );
	}
}
