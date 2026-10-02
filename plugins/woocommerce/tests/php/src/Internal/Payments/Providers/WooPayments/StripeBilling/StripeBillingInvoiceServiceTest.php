<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingApi;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingInvoiceService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingProductService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingSubscriptionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\WooPaymentsStripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeWooPaymentsHttpClient;
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
 * in `Fixtures/rec-t63-billing-api.json` and `Fixtures/rec-t63-invoice-events.json`. Request bodies are written from the
 * client's array literals, with `test_mode` first as the client's `request()` adds it (`class-wc-payments-api-client.php:2635-2640`).
 */
class StripeBillingInvoiceServiceTest extends WC_Unit_Test_Case {

	private const BILLING_FIXTURE = __DIR__ . '/../Fixtures/rec-t63-billing-api.json';
	private const EVENTS_FIXTURE  = __DIR__ . '/../Fixtures/rec-t63-invoice-events.json';

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
	 * @var FakeWooPaymentsHttpClient
	 */
	private FakeWooPaymentsHttpClient $http_client;

	/**
	 * Whether the Stripe Billing toggle is on.
	 *
	 * @var bool
	 */
	private bool $stripe_billing_enabled = true;

	/**
	 * Card gateway stand-in that records the payment method copied to a subscription.
	 *
	 * @var NativeWooPaymentsGateway&MockObject
	 */
	private $gateway;

	/**
	 * Set up the service over a fake transport, connected to the recorded account in test mode.
	 */
	public function setUp(): void {
		parent::setUp();
		WooCommerceSubscriptionsDoubles::load();

		$this->http_client          = new FakeWooPaymentsHttpClient();
		$this->http_client->blog_id = self::RECORDED_BLOG_ID;

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'is_test_mode_onboarding_enabled', 'get_account_id', 'is_dev_mode_enabled', 'get_gateway_setting' ) )
			->getMock();
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );
		$account_service->method( 'is_test_mode_onboarding_enabled' )->willReturn( true );
		$account_service->method( 'get_account_id' )->willReturn( self::RECORDED_ACCOUNT_ID );

		$api_client = new WooPaymentsApiClient();
		$api_client->init( $this->http_client, $account_service );
		$api = new StripeBillingApi();
		$api->init( $api_client );
		$logger = new StripeBillingLogger();
		$logger->init( $account_service );

		$product_service = new StripeBillingProductService();
		$product_service->init( $api, $account_service, $logger );

		$module = $this->createMock( WooPaymentsStripeBillingModule::class );
		$module->method( 'is_stripe_billing_enabled' )->willReturnCallback( fn() => $this->stripe_billing_enabled );

		$this->gateway = $this->getMockBuilder( NativeWooPaymentsGateway::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'update_failing_payment_method' ) )
			->getMock();
		wc_get_container()->replace( NativeWooPaymentsGateway::class, $this->gateway );

		$this->sut = new StripeBillingInvoiceService();
		$this->sut->init( $api, $api_client, $module, $logger );

		$subscription_service = new StripeBillingSubscriptionService();
		$subscription_service->init( $api, $this->createMock( WooPaymentsCustomerService::class ), $product_service, $this->sut, $logger );
		wc_get_container()->replace( StripeBillingSubscriptionService::class, $subscription_service );
	}

	/**
	 * Clear the container replacements and the registries the doubles read.
	 */
	public function tearDown(): void {
		try {
			$this->reset_container_replacements();
			unset(
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
		$this->gateway->expects( $this->never() )->method( 'update_failing_payment_method' );

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

	/**
	 * @testdox An invoice the platform reports as already paid is tolerated; any other refusal is passed on (client maybe_record_invoice_payment).
	 * @testWith [400, "Invoice is already paid.", false]
	 *           [500, "Invoice is already paid.", true]
	 *           [400, "You cannot combine currencies on a single customer. This customer has an active subscription, subscription schedule, discount, quote, or invoice item with currency usd.", true]
	 *
	 * @param int    $http_status HTTP status of the refusal.
	 * @param string $message     Platform message.
	 * @param bool   $expect_failure Whether the refusal is passed on.
	 */
	public function test_tolerates_only_an_invoice_already_paid( int $http_status, string $message, bool $expect_failure ): void {
		// The platform's flattened Stripe error shape, as recorded in `error_combine_currencies`.
		$this->http_client->responses[] = $this->make_response(
			$http_status,
			array(
				'code'    => 'invalid_request_error',
				'message' => $message,
				'data'    => array( 'status' => $http_status ),
			)
		);

		$order = \WC_Helper_Order::create_order();
		$this->create_stripe_billed_subscription( $order, 'parent' );

		$failure = null;
		try {
			$this->sut->maybe_record_invoice_payment( $order->get_id() );
		} catch ( \RuntimeException $exception ) {
			$failure = $exception;
		}

		$this->assertSame( $expect_failure, null !== $failure );
		$this->assertSame( 1, $this->http_client->request_count );
	}

	/**
	 * @testdox A manual subscription becomes automatic with the payment method that paid the order (client test_maybe_record_invoice_payment manual renewal).
	 */
	public function test_turns_a_manual_subscription_automatic_with_the_order_payment_method(): void {
		$this->queue_recorded_responses( 'charge_invoice' );
		$order        = \WC_Helper_Order::create_order();
		$subscription = $this->create_stripe_billed_subscription( $order, 'renewal', array( '_requires_manual_renewal' => 'true' ) );
		$copied       = array();
		$this->gateway->expects( $this->once() )
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

		$this->sut->validate_invoice( $invoice['lines']['data'], $invoice['discounts'], $subscription );

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

		$this->sut->validate_invoice( $invoice['lines']['data'], $invoice['discounts'], $subscription );

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

		$this->sut->validate_invoice( $invoice['lines']['data'], $invoice_discount_ids, $subscription );

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
			$this->sut->validate_invoice( $invoice['lines']['data'], $invoice['discounts'], $subscription );
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
			$this->sut->validate_invoice( $invoice['lines']['data'], $invoice['discounts'], $subscription );
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
