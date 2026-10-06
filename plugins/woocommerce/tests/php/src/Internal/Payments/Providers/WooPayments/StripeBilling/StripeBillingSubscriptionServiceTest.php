<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingApi;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingInvoiceService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingProductService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingSubscriptionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\WooPaymentsStripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceProfile;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeWooPaymentsHttpClient;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\ProviderTextLogAssertions;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\RecordingWcLogger;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\SubscriptionDouble;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use PHPUnit\Framework\MockObject\MockObject;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Stripe subscriptions for WooCommerce Subscriptions subscriptions: creation, lifecycle, supports and payment method changes.
 *
 * Expectations follow client 11.1.0 (`tests/unit/subscriptions/test-class-wc-payments-subscription-service.php`,
 * `tests/unit/test-class-wc-payments-subscription-service-creation-logic.php` and
 * `includes/subscriptions/class-wc-payments-subscription-service.php`); platform answers are the local platform recordings
 * in `Fixtures/rec-t63-billing-api.json`. Request bodies are written from the client's array literals, with `test_mode`
 * first as the client's `request()` adds it (`class-wc-payments-api-client.php:2635-2640`).
 */
class StripeBillingSubscriptionServiceTest extends WC_Unit_Test_Case {

	use ProviderTextLogAssertions;

	/**
	 * User IDs the customer lookup was asked for.
	 *
	 * @var int[]
	 */
	private array $customer_user_ids = array();

	/**
	 * Whether WooPayments debug logging is on.
	 *
	 * @var bool
	 */
	private bool $logging_enabled = false;

	private const BILLING_FIXTURE = __DIR__ . '/../Fixtures/rec-t63-billing-api.json';

	/**
	 * Blog, account and customer the recordings were made on.
	 */
	private const RECORDED_BLOG_ID     = 4;
	private const RECORDED_ACCOUNT_ID  = 'acct_1TrY2nBzWlxcwgpP';
	private const RECORDED_CUSTOMER_ID = 'cus_UsIeTbmGHPc9jY';

	/**
	 * Stripe objects of the recorded main chain: subscription, its item, its parent invoice and the card it was set to.
	 */
	private const MAIN_SUBSCRIPTION_ID = 'sub_1UM1VrBzWlxcwgpP6A3GwGLe';
	private const MAIN_ITEM_ID         = 'si_VMl1spZLBBt5VE';
	private const MAIN_INVOICE_ID      = 'in_1UM1VrBzWlxcwgpPgrIwNSlu';
	private const MAIN_PAYMENT_METHOD  = 'pm_1UJhOFBzWlxcwgpPvcySvyc5';

	/**
	 * Stripe products: the recorded subscription product, the recorded legacy product used for shipping, and the tax and sign-up fee products.
	 */
	private const RECORDED_PRODUCT_ID = 'prod_VMl10VL0VK371N';
	private const SHIPPING_PRODUCT_ID = 'prod_VMl1fRBycUk6wP';
	private const TAX_PRODUCT_ID      = 'prod_VMl1RecT63Vat';
	private const FEE_PRODUCT_ID      = 'prod_VMl1RecT63Fee';

	/**
	 * The System Under Test.
	 *
	 * @var StripeBillingSubscriptionService
	 */
	private $sut;

	/**
	 * Fake platform transport.
	 *
	 * @var FakeWooPaymentsHttpClient
	 */
	private FakeWooPaymentsHttpClient $http_client;

	/**
	 * Customer service stand-in that answers with the recorded customer.
	 *
	 * @var WooPaymentsCustomerService&MockObject
	 */
	private $customer_service;

	/**
	 * Set up the service over a fake transport, connected to the recorded account in test mode, with every Stripe product already created.
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
		$account_service->method( 'get_gateway_setting' )->willReturnCallback( fn( string $key ) => 'enable_logging' === $key && $this->logging_enabled ? 'yes' : '' );

		$api_client = new WooPaymentsApiClient();
		$api_client->init( $this->http_client, $account_service );
		$api = new StripeBillingApi();
		$api->init( $api_client );
		$logger = new WooPaymentsLogger();
		$logger->init( $account_service );

		$product_service = new StripeBillingProductService();
		$product_service->init( $api, $account_service, $logger );

		$module = $this->createMock( WooPaymentsStripeBillingModule::class );
		$module->method( 'is_stripe_billing_enabled' )->willReturn( true );
		$invoice_service = new StripeBillingInvoiceService();
		$invoice_service->init( $api, $api_client, $module, $logger );

		$this->customer_service = $this->createMock( WooPaymentsCustomerService::class );
		$this->customer_service->method( 'get_or_create_customer_id_for_user' )->willReturnCallback(
			function ( int $user_id ): string {
				$this->customer_user_ids[] = $user_id;
				return self::RECORDED_CUSTOMER_ID;
			}
		);

		foreach ( array(
			'shipping'    => self::SHIPPING_PRODUCT_ID,
			'vat'         => self::TAX_PRODUCT_ID,
			'sign_up_fee' => self::FEE_PRODUCT_ID,
		) as $type => $wcpay_product_id ) {
			update_option( '_wcpay_product_id_test_' . $type, $wcpay_product_id );
			update_option( '_wcpay_product_id_test_' . $type . '_linked_to', self::RECORDED_ACCOUNT_ID );
		}

		$this->sut = new StripeBillingSubscriptionService();
		$this->sut->init( $api, $this->customer_service, $product_service, $invoice_service, $logger );
	}

	/**
	 * Clear the registries the doubles read, the edited order global and the change request flag.
	 */
	public function tearDown(): void {
		try {
			unset(
				$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ],
				$GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ],
				$GLOBALS[ WooCommerceSubscriptionsDoubles::RENEWAL_SUBSCRIPTIONS ],
				$GLOBALS[ WooCommerceSubscriptionsDoubles::DUPLICATE_SITE ],
				$GLOBALS['theorder']
			);
			if ( class_exists( 'WC_Subscriptions_Change_Payment_Gateway', false ) ) {
				\WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment = false;
			}
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Checkout creates the Stripe subscription with its items, one-time items, discounts and trial, and stores what Stripe returns (client test_create_subscription, test_prepare_wcpay_subscription_data).
	 *
	 * Payload from client `class-wc-payments-subscription-service.php:827-861` (data), `:444` (source), `:870-907` (items),
	 * `:965-996` (sign-up fee), `:367-388` (discounts), `:836-838` (trial); endpoint `class-wc-payments-api-client.php:1703-1708`.
	 * The recorded answer gets the subscription's item ID and a discount, since the recording sent neither.
	 */
	public function test_creates_the_stripe_subscription_and_stores_its_ids(): void {
		$trial_end    = time() + WEEK_IN_SECONDS;
		$subscription = $this->create_subscription( array( '_schedule_trial_end' => gmdate( 'Y-m-d H:i:s', $trial_end ) ) );
		$line_item    = $this->get_line_item( $subscription );
		$shipping     = $this->add_shipping( $subscription, '2.19' );
		$tax          = $this->add_tax( $subscription, '1.50', '0.40' );
		$this->add_fixed_coupon( $subscription, 'rec63-save5', '5.00' );
		$this->set_sign_up_fee( $line_item->get_product_id(), '7.50' );
		$subscription = wc_get_order( $subscription->get_id() );

		$entry = $this->get_entry( 'create_subscription' );
		$entry['response']['body']['items']['data'][0]['metadata']['wc_item_id'] = (string) $line_item->get_id();
		$entry['response']['body']['discounts']                                  = array( 'di_1UM1RecT63Kept' );
		$this->queue_entry( $entry );

		$this->sut->create_subscription( $subscription );

		$this->assertSame(
			array(
				array(
					'POST',
					'/sites/4/wcpay/subscriptions',
					array(
						'test_mode'         => true,
						'customer'          => self::RECORDED_CUSTOMER_ID,
						'items'             => array(
							array(
								'metadata'   => array( 'wc_item_id' => $line_item->get_id() ),
								'quantity'   => 2,
								'price_data' => $this->price( self::RECORDED_PRODUCT_ID, 2490, true ),
							),
							array(
								'metadata'   => array(
									'wc_item_id' => $shipping->get_id(),
									'method'     => 'Flat rate',
								),
								'price_data' => $this->price( self::SHIPPING_PRODUCT_ID, 219, true ),
							),
							array(
								'metadata'   => array(
									'wc_item_id'  => $tax->get_id(),
									'wc_rate_id'  => 4712,
									'code'        => 'US-CA-VAT-1',
									'rate'        => 8.25,
									'is_compound' => 'no',
								),
								'price_data' => $this->price( self::TAX_PRODUCT_ID, 190, true ),
							),
						),
						'trial_end'         => $trial_end,
						'add_invoice_items' => array(
							array( 'price_data' => $this->price( self::FEE_PRODUCT_ID, 750, false ) ),
						),
						'discounts'         => array(
							array(
								'amount_off' => 500,
								'currency'   => 'USD',
								'duration'   => 'once',
								'name'       => 'Coupon - rec63-save5',
							),
						),
						'metadata'          => array( 'subscription_source' => 'woo_subscriptions' ),
					),
				),
			),
			$this->get_requests()
		);

		$this->assertSame( array( $subscription->get_user_id() ), $this->customer_user_ids, "The Stripe customer is the subscription user's, as the client's get_customer_id_for_order() (customer service :431-446)." );
		$saved = wc_get_order( $subscription->get_id() );
		$this->assertSame( self::MAIN_SUBSCRIPTION_ID, $saved->get_meta( '_wcpay_subscription_id', true ) );
		$this->assertSame( self::MAIN_ITEM_ID, $saved->get_item( $line_item->get_id() )->get_meta( '_wcpay_subscription_item_id', true ) );
		$this->assertSame( array( 'di_1UM1RecT63Kept' ), $saved->get_meta( '_wcpay_subscription_discount_ids', true ) );
		$this->assertSame( self::MAIN_INVOICE_ID, $saved->get_meta( '_wcpay_billing_invoice_id', true ), 'The parent invoice is recorded so paying the parent order marks it paid.' );
	}

	/**
	 * @testdox An amount below the platform minimum stops checkout with the client's message (client `class-wc-payments-subscription-service.php:461-470`).
	 */
	public function test_an_amount_below_the_minimum_stops_checkout(): void {
		$this->queue_entry( $this->get_entry( 'error_amount_too_small' ) );
		$subscription = $this->create_subscription( array(), 1, '0.75' );
		$subscription->set_total( '0.75' );
		$subscription->save();

		$message = $this->get_checkout_error( $subscription );

		$this->assertSame(
			sprintf( 'There was a problem creating your subscription. %s doesn\'t meet the <strong>minimum recurring amount</strong> this payment method can process.', wc_price( '0.75' ) ),
			$message
		);
		$this->assertSame( 1, $this->http_client->request_count );
		$this->assertSame( '', wc_get_order( $subscription->get_id() )->get_meta( '_wcpay_subscription_id', true ) );
	}

	/**
	 * @testdox A customer with subscriptions in another currency stops checkout with the client's message (client `class-wc-payments-subscription-service.php:471-480`).
	 */
	public function test_a_currency_mix_stops_checkout(): void {
		$this->queue_entry( $this->get_entry( 'error_combine_currencies' ) );
		$subscription = $this->create_subscription( array(), 1, '23.90', 'EUR' );

		$message = $this->get_checkout_error( $subscription );

		$this->assertSame(
			'The subscription couldn\'t be created because it uses a different currency (EUR) from your existing subscriptions (USD). Please ensure all subscriptions use the same currency.',
			$message
		);
		$this->assertSame( '', wc_get_order( $subscription->get_id() )->get_meta( '_wcpay_subscription_id', true ) );
	}

	/**
	 * @testdox A subscription whose product was deleted stops checkout with the general message before any platform call (client `class-wc-payments-subscription-service.php:432`, `:482`).
	 */
	public function test_a_deleted_product_stops_checkout_before_the_platform(): void {
		$subscription = $this->create_subscription();
		wc_get_product( $this->get_line_item( $subscription )->get_product_id() )->delete( true );

		$message = $this->get_checkout_error( wc_get_order( $subscription->get_id() ) );

		$this->assertSame( 'There was a problem creating your subscription. Please try again or contact us for assistance.', $message );
		$this->assertSame( 0, $this->http_client->request_count );
	}

	/**
	 * @testdox A billing cycle longer than one year stops checkout before any platform call (client `class-wc-payments-subscription-service.php:1171-1177`).
	 * @testWith ["month", "13"]
	 *           ["year", "2"]
	 *           ["week", "53"]
	 *           ["day", "366"]
	 *
	 * @param string $period   Billing period.
	 * @param string $interval Billing interval.
	 */
	public function test_a_billing_cycle_over_one_year_stops_checkout( string $period, string $interval ): void {
		$subscription = $this->create_subscription(
			array(
				'_billing_period'   => $period,
				'_billing_interval' => $interval,
			)
		);

		$message = $this->get_checkout_error( $subscription );

		$this->assertSame( 'There was a problem creating your subscription. Please try again or contact us for assistance.', $message );
		$this->assertSame( 0, $this->http_client->request_count );
	}

	/**
	 * @testdox A subscription without a customer account stops checkout without asking for a Stripe customer (client `class-wc-payments-subscription-service.php:433-438`).
	 */
	public function test_a_subscription_without_a_user_stops_checkout(): void {
		$subscription = $this->create_subscription();
		$subscription->set_customer_id( 0 );
		$subscription->save();
		$this->customer_service->expects( $this->never() )->method( 'get_or_create_customer_id_for_user' );

		$message = $this->get_checkout_error( $subscription );

		$this->assertSame( 'There was a problem creating your subscription. Please try again or contact us for assistance.', $message );
		$this->assertSame( 0, $this->http_client->request_count );
	}

	/**
	 * @testdox A subscription paid with another gateway gets no Stripe subscription (client `class-wc-payments-subscription-service.php:428-430`).
	 */
	public function test_a_subscription_paid_with_another_gateway_is_left_alone(): void {
		$subscription = $this->create_subscription( array(), 2, '49.80', 'USD', 'stripe' );
		$this->customer_service->expects( $this->never() )->method( 'get_or_create_customer_id_for_user' );

		$this->sut->create_subscription( $subscription );

		$this->assertSame( 0, $this->http_client->request_count );
	}

	/**
	 * @testdox The `wcpay_subscriptions_prepare_subscription_data` filter changes the data sent; the subscription source is added after it (client `class-wc-payments-subscription-service.php:444`, `:853-860`).
	 */
	public function test_the_prepare_filter_changes_the_data_sent(): void {
		$this->queue_entry( $this->get_entry( 'create_subscription' ) );
		$subscription = $this->create_subscription();
		$filtered     = null;
		add_filter(
			'wcpay_subscriptions_prepare_subscription_data',
			function ( $data ) use ( &$filtered ) {
				$filtered            = $data;
				$data['description'] = 'Rec T63 coffee club';
				return $data;
			}
		);

		$this->sut->create_subscription( $subscription );

		$this->assertSame( self::RECORDED_CUSTOMER_ID, $filtered['customer'] );
		$this->assertCount( 1, $filtered['items'] );
		$this->assertArrayNotHasKey( 'metadata', $filtered, 'The subscription source is not the filter\'s to change.' );
		$body = $this->get_requests()[0][2];
		$this->assertSame( 'Rec T63 coffee club', $body['description'] );
		$this->assertSame( array( 'subscription_source' => 'woo_subscriptions' ), $body['metadata'] );
	}

	/**
	 * @testdox A manual subscription renewed by hand gets a Stripe subscription only when it has payment tokens and none yet (client creation-logic tests, `class-wc-payments-subscription-service.php:800-817`).
	 * @testWith [true, true, "", 1]
	 *           [true, false, "", 0]
	 *           [false, true, "", 0]
	 *           [true, true, "sub_1UM1VrBzWlxcwgpP6A3GwGLe", 0]
	 *
	 * @param bool   $is_manual             Whether the subscription renews manually.
	 * @param bool   $has_token             Whether the subscription has a payment token.
	 * @param string $wcpay_subscription_id Existing Stripe subscription ID.
	 * @param int    $expected_requests     Platform requests sent.
	 */
	public function test_a_manual_renewal_creates_a_stripe_subscription_only_with_tokens( bool $is_manual, bool $has_token, string $wcpay_subscription_id, int $expected_requests ): void {
		$this->queue_entry( $this->get_entry( 'create_subscription' ) );
		$subscription = $this->create_subscription(
			array(
				'_requires_manual_renewal' => $is_manual ? 'true' : 'false',
				'_wcpay_subscription_id'   => $wcpay_subscription_id,
			)
		);
		if ( $has_token ) {
			$subscription->add_payment_token( $this->create_token() );
		}
		$renewal_order = \WC_Helper_Order::create_order();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::RENEWAL_SUBSCRIPTIONS ][ $renewal_order->get_id() ] = array( $subscription->get_id() );

		$this->sut->create_subscription_for_manual_renewal( $renewal_order->get_id() );

		$this->assertSame( $expected_requests, $this->http_client->request_count );
		$this->assertSame( $expected_requests ? self::MAIN_SUBSCRIPTION_ID : $wcpay_subscription_id, wc_get_order( $subscription->get_id() )->get_meta( '_wcpay_subscription_id', true ) );
	}

	/**
	 * @testdox Switching a subscription to WooPayments creates its Stripe subscription backdated to its start and anchored on its next payment (client `class-wc-payments-subscription-service.php:495-509`, `:848-851`).
	 */
	public function test_switching_to_woopayments_creates_a_backdated_stripe_subscription(): void {
		$this->queue_entry( $this->get_entry( 'create_subscription' ) );
		$last_paid    = time() - DAY_IN_SECONDS;
		$next_payment = time() + 29 * DAY_IN_SECONDS;
		$subscription = $this->create_subscription(
			array(
				'_schedule_last_order_date_created' => gmdate( 'Y-m-d H:i:s', $last_paid - HOUR_IN_SECONDS ),
				'_schedule_last_order_date_paid'    => gmdate( 'Y-m-d H:i:s', $last_paid ),
				'_schedule_next_payment'            => gmdate( 'Y-m-d H:i:s', $next_payment ),
			)
		);
		$subscription->set_date_created( time() - 60 * DAY_IN_SECONDS );
		$subscription->save();

		$this->sut->maybe_create_subscription_from_update_payment_method( wc_get_order( $subscription->get_id() ), 'woocommerce_payments' );

		$this->assertSame(
			array(
				'test_mode'            => true,
				'customer'             => self::RECORDED_CUSTOMER_ID,
				'items'                => array(
					array(
						'metadata'   => array( 'wc_item_id' => $this->get_line_item( $subscription )->get_id() ),
						'quantity'   => 2,
						'price_data' => $this->price( self::RECORDED_PRODUCT_ID, 2490, true ),
					),
				),
				'backdate_start_date'  => $last_paid,
				'billing_cycle_anchor' => $next_payment,
				'metadata'             => array( 'subscription_source' => 'woo_subscriptions' ),
			),
			$this->get_requests()[0][2]
		);
		$this->assertSame( self::MAIN_SUBSCRIPTION_ID, wc_get_order( $subscription->get_id() )->get_meta( '_wcpay_subscription_id', true ) );
	}

	/**
	 * @testdox Switching to another gateway, or a subscription that already has a Stripe subscription, creates nothing (client `class-wc-payments-subscription-service.php:496-504`).
	 * @testWith ["stripe", ""]
	 *           ["woocommerce_payments", "sub_1UM1VrBzWlxcwgpP6A3GwGLe"]
	 *
	 * @param string $new_payment_method    New payment method.
	 * @param string $wcpay_subscription_id Existing Stripe subscription ID.
	 */
	public function test_switching_creates_nothing_for_other_gateways_or_linked_subscriptions( string $new_payment_method, string $wcpay_subscription_id ): void {
		$subscription = $this->create_subscription( array( '_wcpay_subscription_id' => $wcpay_subscription_id ) );

		$this->sut->maybe_create_subscription_from_update_payment_method( $subscription, $new_payment_method );

		$this->assertSame( 0, $this->http_client->request_count );
	}

	/**
	 * @testdox Each status change reaches the Stripe subscription (client test_cancel_subscription, test_suspend_subscription, test_set_pending_cancel_for_subscription, test_reactivate_subscription).
	 * @dataProvider status_changes
	 *
	 * @param string     $method   Service method the status hook calls.
	 * @param string     $pair     Recorded answer.
	 * @param string     $verb     HTTP method sent.
	 * @param string     $path     Path sent.
	 * @param array|null $body     Body sent.
	 */
	public function test_status_changes_reach_the_stripe_subscription( string $method, string $pair, string $verb, string $path, ?array $body ): void {
		$this->queue_entry( $this->get_entry( $pair ) );
		$subscription = $this->create_subscription( array( '_wcpay_subscription_id' => self::MAIN_SUBSCRIPTION_ID ) );

		$this->sut->$method( $subscription );

		$this->assertSame( array( array( $verb, $path, $body ) ), $this->get_requests() );
	}

	/**
	 * Status changes and the request each sends: client `class-wc-payments-subscription-service.php:518-530`, `:539-587`, `:614-616`, `:597-605`; endpoints `class-wc-payments-api-client.php:1721-1733`, `:1746-1758`.
	 *
	 * @return array<string,array{string,string,string,string,array<string,mixed>|null}>
	 */
	public static function status_changes(): array {
		$path = '/sites/4/wcpay/subscriptions/' . self::MAIN_SUBSCRIPTION_ID;

		return array(
			'cancelled or expired' => array( 'cancel_subscription', 'cancel_subscription', 'DELETE', $path . '?test_mode=1', null ),
			'on hold'              => array(
				'handle_subscription_status_on_hold',
				'update_subscription',
				'POST',
				$path,
				array(
					'test_mode'        => true,
					'pause_collection' => array( 'behavior' => 'void' ),
				),
			),
			'pending cancel'       => array(
				'set_pending_cancel_for_subscription',
				'update_subscription',
				'POST',
				$path,
				array(
					'test_mode'            => true,
					'cancel_at_period_end' => 'true',
				),
			),
			'active again'         => array(
				'reactivate_subscription',
				'update_subscription',
				'POST',
				$path,
				array(
					'test_mode'            => true,
					'cancel_at_period_end' => 'false',
					'pause_collection'     => '',
				),
			),
		);
	}

	/**
	 * @testdox Putting a Stripe-billed subscription on hold leaves a note on it (client `class-wc-payments-subscription-service.php:549-550`).
	 */
	public function test_putting_on_hold_notes_the_suspension(): void {
		$this->queue_entry( $this->get_entry( 'update_subscription' ) );
		$subscription = $this->create_subscription( array( '_wcpay_subscription_id' => self::MAIN_SUBSCRIPTION_ID ) );

		$this->sut->handle_subscription_status_on_hold( $subscription );

		$this->assertContains( 'Suspended WooPayments Subscription because subscription status changed to on-hold.', $this->get_order_note_texts( $subscription ) );
	}

	/**
	 * @testdox Status changes made while running without Stripe sync stay local, and reach Stripe again afterwards, even when the callback fails.
	 *
	 * Client 11.1.0 removes the on-hold and reactivation hooks around a renewal recorded from an invoice event (`class-wc-payments-subscriptions-event-handler.php:165-175`).
	 */
	public function test_status_changes_reach_stripe_again_after_running_without_sync(): void {
		$subscription = $this->create_subscription( array( '_wcpay_subscription_id' => self::MAIN_SUBSCRIPTION_ID ) );

		try {
			$this->sut->run_without_stripe_sync(
				function () use ( $subscription ): void {
					$this->sut->handle_subscription_status_on_hold( $subscription );
					$this->sut->reactivate_subscription( $subscription );
					throw new \RuntimeException( 'Recording the renewal failed.' );
				}
			);
			$this->fail( 'The callback failure must be passed on.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'Recording the renewal failed.', $exception->getMessage() );
		}

		$this->assertSame( 0, $this->http_client->request_count );
		$this->assertNotContains( 'Suspended WooPayments Subscription because subscription status changed to on-hold.', $this->get_order_note_texts( $subscription ) );

		$this->queue_entry( $this->get_entry( 'update_subscription' ) );
		$this->sut->handle_subscription_status_on_hold( $subscription );

		$this->assertSame( 1, $this->http_client->request_count, 'Putting it on hold later pauses it at Stripe again.' );
	}

	/**
	 * @testdox Status changes send nothing for a subscription without a Stripe subscription, and on-hold sends nothing for one moved to another gateway (client `class-wc-payments-subscription-service.php:521-523`, `:543-545`, `:1010-1012`).
	 * @testWith ["cancel_subscription", "woocommerce_payments", ""]
	 *           ["handle_subscription_status_on_hold", "woocommerce_payments", ""]
	 *           ["set_pending_cancel_for_subscription", "woocommerce_payments", ""]
	 *           ["reactivate_subscription", "woocommerce_payments", ""]
	 *           ["handle_subscription_status_on_hold", "stripe", "sub_1UM1VrBzWlxcwgpP6A3GwGLe"]
	 *
	 * @param string $method                Service method the status hook calls.
	 * @param string $payment_method        Subscription payment method.
	 * @param string $wcpay_subscription_id Stripe subscription ID.
	 */
	public function test_status_changes_send_nothing_for_other_subscriptions( string $method, string $payment_method, string $wcpay_subscription_id ): void {
		$subscription = $this->create_subscription( array( '_wcpay_subscription_id' => $wcpay_subscription_id ), 2, '49.80', 'USD', $payment_method );

		$this->sut->$method( $subscription );

		$this->assertSame( 0, $this->http_client->request_count );
		$this->assertNotContains( 'Suspended WooPayments Subscription because subscription status changed to on-hold.', $this->get_order_note_texts( $subscription ) );
	}

	/**
	 * @testdox A platform failure on cancel or update is logged and does not stop the status change (client `class-wc-payments-subscription-service.php:525-529`, `:1014-1018`).
	 * @testWith ["cancel_subscription"]
	 *           ["set_pending_cancel_for_subscription"]
	 *
	 * @param string $method Service method the status hook calls.
	 */
	public function test_platform_failures_on_status_changes_do_not_propagate( string $method ): void {
		$this->http_client->responses[] = $this->make_response(
			500,
			array(
				'code'    => 'wcpay_server_error',
				'message' => 'Rec T63 platform failure',
				'data'    => array( 'status' => 500 ),
			)
		);
		$subscription                   = $this->create_subscription( array( '_wcpay_subscription_id' => self::MAIN_SUBSCRIPTION_ID ) );

		$this->sut->$method( $subscription );

		$this->assertSame( 1, $this->http_client->request_count );
	}

	/**
	 * @testdox A platform error from $method is logged with its status and code, never its message.
	 * @testWith ["create_subscription", "There was a problem creating the WooPayments subscription."]
	 *           ["cancel_subscription", "There was a problem canceling the subscription on WooPayments server."]
	 *           ["set_pending_cancel_for_subscription", "There was a problem updating the WooPayments subscription on server."]
	 *
	 * Client 11.1.0 appends the platform's message (`class-wc-payments-subscription-service.php:459`, `:528`, `:1017`).
	 *
	 * @param string $method   Service method that calls the platform.
	 * @param string $expected Expected line.
	 */
	public function test_platform_error_log_leaves_out_platform_text( string $method, string $expected ): void {
		// The error envelope client 11.1.0 reads (error.code, error.message, error.type; class-wc-payments-api-client.php:2852-2871).
		$this->http_client->responses[] = $this->make_response(
			404,
			array(
				'error' => array(
					'code'    => 'resource_missing',
					'message' => "No such customer: 'cus_123'; ask shopper@example.com, see https://pay.example.test/r?key=sk_test_leak123",
					'type'    => 'invalid_request_error',
				),
			)
		);

		$subscription          = $this->create_subscription( 'create_subscription' === $method ? array() : array( '_wcpay_subscription_id' => self::MAIN_SUBSCRIPTION_ID ) );
		$this->logging_enabled = true;
		$logger                = RecordingWcLogger::install();

		if ( 'create_subscription' === $method ) {
			$this->get_checkout_error( $subscription );
		} else {
			$this->sut->$method( $subscription );
		}

		$context = $this->get_logged_context( $logger, $expected );
		$this->assertSame( array( 404, 'resource_missing', $subscription->get_id() ), array( $context['http_status'], $context['error_code'], $context['subscription_id'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * @testdox A Stripe-billed subscription supports Stripe's scheduled payments and not amount or date changes; others the reverse (client test_prevent_wcpay_subscription_changes, `class-wc-payments-subscription-service.php:705-723`).
	 * @testWith [false, "gateway_scheduled_payments", true, false]
	 *           [false, "subscription_amount_changes", false, true]
	 *           [false, "subscription_date_changes", false, true]
	 *           [false, "subscription_suspension", false, false]
	 *           [false, "subscription_suspension", true, true]
	 *           [true, "gateway_scheduled_payments", false, true]
	 *           [true, "subscription_amount_changes", true, false]
	 *           [true, "subscription_date_changes", true, false]
	 *           [true, "subscriptions", false, true]
	 *           [true, "subscription_cancellation", false, true]
	 *           [true, "subscription_payment_method_change_customer", false, true]
	 *           [true, "random_feature", true, false]
	 *
	 * @param bool   $is_stripe_billed Whether the subscription is Stripe-billed.
	 * @param string $feature          Feature asked about.
	 * @param bool   $supported        What the gateway answered.
	 * @param bool   $expected         What the subscription supports.
	 */
	public function test_supports_follow_stripe_billing( bool $is_stripe_billed, string $feature, bool $supported, bool $expected ): void {
		$subscription = $this->create_subscription( array( '_wcpay_subscription_id' => $is_stripe_billed ? self::MAIN_SUBSCRIPTION_ID : '' ) );

		$this->assertSame( $expected, $this->sut->prevent_wcpay_subscription_changes( $supported, $feature, $subscription ) );
	}

	/**
	 * @testdox A Stripe-billed subscription's edit screen loses the actions that create pending orders or process a renewal (client `class-wc-payments-subscription-service.php:731-746`).
	 * @testWith [true, ["send_order_details"]]
	 *           [false, ["wcs_create_pending_parent", "wcs_create_pending_renewal", "wcs_process_renewal", "send_order_details"]]
	 *
	 * @param bool              $is_stripe_billed Whether the subscription is Stripe-billed.
	 * @param array<int,string> $expected         Actions left.
	 */
	public function test_manual_renewal_actions_are_removed_for_stripe_billed_subscriptions( bool $is_stripe_billed, array $expected ): void {
		$GLOBALS['theorder'] = $this->create_subscription( array( '_wcpay_subscription_id' => $is_stripe_billed ? self::MAIN_SUBSCRIPTION_ID : '' ) );
		$actions             = array(
			'wcs_create_pending_parent'  => 'Create pending parent order',
			'wcs_create_pending_renewal' => 'Create pending renewal order',
			'wcs_process_renewal'        => 'Process renewal',
			'send_order_details'         => 'Email invoice / order details to customer',
		);

		$this->assertSame( $expected, array_keys( $this->sut->prevent_wcpay_manual_renewal( $actions ) ) );
	}

	/**
	 * @testdox A token added to a Stripe-billed subscription becomes its Stripe subscription's default payment method (client test_update_wcpay_subscription_payment_method, `class-wc-payments-subscription-service.php:628-648`).
	 * @testWith ["woocommerce_payments", "sub_1UM1VrBzWlxcwgpP6A3GwGLe", 1]
	 *           ["woocommerce_payments", "", 0]
	 *           ["stripe", "sub_1UM1VrBzWlxcwgpP6A3GwGLe", 0]
	 *
	 * @param string $payment_method        Subscription payment method.
	 * @param string $wcpay_subscription_id Stripe subscription ID.
	 * @param int    $expected_requests     Platform requests sent.
	 */
	public function test_a_new_token_becomes_the_stripe_default_payment_method( string $payment_method, string $wcpay_subscription_id, int $expected_requests ): void {
		$this->queue_entry( $this->get_entry( 'update_subscription' ) );
		$subscription = $this->create_subscription( array( '_wcpay_subscription_id' => $wcpay_subscription_id ), 2, '49.80', 'USD', $payment_method );
		$token        = $this->create_token();

		$this->sut->update_wcpay_subscription_payment_method( $subscription->get_id(), $token->get_id(), $token );

		$this->assertSame( $expected_requests, $this->http_client->request_count );
		if ( $expected_requests ) {
			$this->assertSame(
				array(
					array(
						'POST',
						'/sites/4/wcpay/subscriptions/' . self::MAIN_SUBSCRIPTION_ID,
						array(
							'test_mode'              => true,
							'default_payment_method' => self::MAIN_PAYMENT_METHOD,
						),
					),
				),
				$this->get_requests()
			);
		}
	}

	/**
	 * @testdox Moving a subscription off WooPayments cancels its Stripe subscription and keeps the ID as cancelled (client `class-wc-payments-subscription-service.php:915-926`).
	 * @testWith ["stripe", true]
	 *           ["woocommerce_payments", false]
	 *
	 * @param string $new_payment_method New payment method.
	 * @param bool   $expect_cancel      Whether the Stripe subscription is cancelled.
	 */
	public function test_moving_off_woopayments_cancels_the_stripe_subscription( string $new_payment_method, bool $expect_cancel ): void {
		$this->queue_entry( $this->get_entry( 'cancel_subscription' ) );
		$subscription = $this->create_subscription( array( '_wcpay_subscription_id' => self::MAIN_SUBSCRIPTION_ID ) );

		$this->sut->maybe_cancel_subscription( $subscription, $new_payment_method );

		$saved = wc_get_order( $subscription->get_id() );
		if ( ! $expect_cancel ) {
			$this->assertSame( 0, $this->http_client->request_count );
			$this->assertSame( self::MAIN_SUBSCRIPTION_ID, $saved->get_meta( '_wcpay_subscription_id', true ) );
			return;
		}

		$this->assertSame( array( array( 'DELETE', '/sites/4/wcpay/subscriptions/' . self::MAIN_SUBSCRIPTION_ID . '?test_mode=1', null ) ), $this->get_requests() );
		$this->assertFalse( $saved->meta_exists( '_wcpay_subscription_id' ) );
		$this->assertSame( self::MAIN_SUBSCRIPTION_ID, $saved->get_meta( '_cancelled_wcpay_subscription_id', true ) );
	}

	/**
	 * @testdox After a payment method change, the pending invoice is charged and, once paid, its failed renewal order is completed with the new token (client test_maybe_attempt_payment_for_subscription, `class-wc-payments-subscription-service.php:658-694`).
	 *
	 * Request from client `:670` with `charge_invoice()`'s default empty data (`class-wc-payments-api-client.php:1554-1567`).
	 *
	 * @testWith ["paid", true]
	 *           ["open", false]
	 *
	 * @param string $invoice_status Invoice status the platform answers.
	 * @param bool   $expect_paid    Whether the renewal order is completed.
	 */
	public function test_a_payment_method_change_charges_the_pending_invoice( string $invoice_status, bool $expect_paid ): void {
		WooCommerceSubscriptionsDoubles::load_change_payment_gateway();
		\WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment = true;

		$entry                               = $this->get_entry( 'charge_invoice' );
		$entry['response']['body']['status'] = $invoice_status;
		$this->queue_entry( $entry );
		$subscription  = $this->create_subscription(
			array(
				'_wcpay_subscription_id'    => self::MAIN_SUBSCRIPTION_ID,
				'_wcpay_pending_invoice_id' => self::MAIN_INVOICE_ID,
			)
		);
		$renewal_order = \WC_Helper_Order::create_order();
		$renewal_order->update_meta_data( '_wcpay_billing_invoice_id', self::MAIN_INVOICE_ID );
		$renewal_order->set_status( 'failed' );
		$renewal_order->save();
		$token = $this->create_token();

		$flag_while_paying = null;
		add_action(
			'woocommerce_payment_complete',
			function () use ( &$flag_while_paying ) {
				$flag_while_paying = \WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment;
			}
		);

		$this->sut->maybe_attempt_payment_for_subscription( $subscription, $token );

		$this->assertSame( array( array( 'POST', '/sites/4/wcpay/invoices/' . self::MAIN_INVOICE_ID . '/pay', array( 'test_mode' => true ) ) ), $this->get_requests() );
		$saved_order = wc_get_order( $renewal_order->get_id() );
		$pending     = wc_get_order( $subscription->get_id() )->get_meta( '_wcpay_pending_invoice_id', true );
		if ( ! $expect_paid ) {
			$this->assertSame( self::MAIN_INVOICE_ID, $pending );
			$this->assertTrue( $saved_order->needs_payment() );
			return;
		}

		$this->assertSame( '', $pending );
		$this->assertTrue( $saved_order->is_paid() );
		$this->assertSame( array( $token->get_id() ), array_map( 'absint', $saved_order->get_payment_tokens() ) );
		$this->assertFalse( $flag_while_paying, 'Paying must not count as a payment method change, or the subscription is not activated.' );
		$this->assertTrue( \WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment, 'The change request flag is restored.' );
		$this->assertSame( 1, wc_notice_count( 'success' ) );
		$lock_token = wc_get_container()->get( OrderPaymentStore::class )->claim_order_payment_lock_for_operation( $saved_order, new WooPaymentsPersistenceProfile(), null, 'test' );
		$this->assertNotNull( $lock_token, 'The order payment lock is released after the completion.' );
	}

	/**
	 * @testdox When the invoice.paid webhook holds the renewal order's payment lock, the payment method change leaves the order to it: nothing is completed here, the success notice stays and the change request flag is untouched.
	 *
	 * Client 11.1.0 completes the order inline with no lock (`class-wc-payments-subscription-service.php:677-690`); native records
	 * every payment under the order payment lock (O15), and the webhook records this one.
	 */
	public function test_a_payment_method_change_leaves_a_renewal_locked_by_the_webhook_to_it(): void {
		WooCommerceSubscriptionsDoubles::load_change_payment_gateway();
		\WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment = true;
		$this->queue_entry( $this->get_entry( 'charge_invoice' ) );
		$subscription  = $this->create_subscription(
			array(
				'_wcpay_subscription_id'    => self::MAIN_SUBSCRIPTION_ID,
				'_wcpay_pending_invoice_id' => self::MAIN_INVOICE_ID,
			)
		);
		$renewal_order = \WC_Helper_Order::create_order();
		$renewal_order->update_meta_data( '_wcpay_billing_invoice_id', self::MAIN_INVOICE_ID );
		$renewal_order->set_status( 'failed' );
		$renewal_order->save();
		wc_get_container()->get( OrderPaymentStore::class )->claim_order_payment_lock( $renewal_order, new WooPaymentsPersistenceProfile(), 'pi_webhookRecordingTheRenewal' );
		$payments_completed = did_action( 'woocommerce_payment_complete' );

		$this->sut->maybe_attempt_payment_for_subscription( $subscription, $this->create_token() );

		$this->assertTrue( wc_get_order( $renewal_order->get_id() )->needs_payment(), 'The webhook completes the order, not this request.' );
		$this->assertSame( 0, did_action( 'woocommerce_payment_complete' ) - $payments_completed );
		$this->assertSame( 1, wc_notice_count( 'success' ), 'The invoice was paid with the new payment method.' );
		$this->assertTrue( \WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment, 'The change request flag is untouched.' );
	}

	/**
	 * @testdox No invoice is charged for a subscription without a pending invoice, or one that is not Stripe-billed (client `class-wc-payments-subscription-service.php:664-668`).
	 * @testWith ["", "sub_1UM1VrBzWlxcwgpP6A3GwGLe"]
	 *           ["in_1UM1VrBzWlxcwgpPgrIwNSlu", ""]
	 *
	 * @param string $pending_invoice_id    Pending invoice ID.
	 * @param string $wcpay_subscription_id Stripe subscription ID.
	 */
	public function test_nothing_is_charged_without_a_pending_invoice_of_a_stripe_billed_subscription( string $pending_invoice_id, string $wcpay_subscription_id ): void {
		$subscription = $this->create_subscription(
			array(
				'_wcpay_subscription_id'    => $wcpay_subscription_id,
				'_wcpay_pending_invoice_id' => $pending_invoice_id,
			)
		);

		$this->sut->maybe_attempt_payment_for_subscription( $subscription, $this->create_token() );

		$this->assertSame( 0, $this->http_client->request_count );
	}

	/**
	 * @testdox The next payment date moves to the end of the Stripe period, with date changes allowed only while it moves (client test_update_dates_to_match_wcpay_subscription, `class-wc-payments-subscription-service.php:778-793`).
	 */
	public function test_aligns_the_next_payment_date_with_the_stripe_period(): void {
		$wcpay_subscription = $this->get_entry( 'get_subscription' )['response']['body'];
		$subscription       = $this->create_subscription( array( '_wcpay_subscription_id' => self::MAIN_SUBSCRIPTION_ID ) );
		$allowed_while_set  = null;
		add_action(
			'woocommerce_subscription_date_updated',
			function ( $updated ) use ( &$allowed_while_set ) {
				$allowed_while_set = $this->sut->prevent_wcpay_subscription_changes( false, 'subscription_date_changes', $updated );
			}
		);

		$this->sut->update_dates_to_match_wcpay_subscription( $wcpay_subscription, $subscription );

		$this->assertSame( $wcpay_subscription['current_period_end'], wc_get_order( $subscription->get_id() )->get_time( 'next_payment' ) );
		$this->assertTrue( $allowed_while_set );
		$this->assertFalse( $this->sut->prevent_wcpay_subscription_changes( true, 'subscription_date_changes', $subscription ) );
	}

	/**
	 * @testdox A subscription is Stripe-billed when paid with WooPayments and linked to a Stripe subscription, never on a staging copy; its orders follow it (client test_is_wcpay_subscription, `class-wc-payments-subscription-service.php:292-294`, `:312-324`).
	 * @testWith ["woocommerce_payments", "sub_1UM1VrBzWlxcwgpP6A3GwGLe", false, true]
	 *           ["woocommerce_payments", "", false, false]
	 *           ["stripe", "sub_1UM1VrBzWlxcwgpP6A3GwGLe", false, false]
	 *           ["woocommerce_payments", "sub_1UM1VrBzWlxcwgpP6A3GwGLe", true, false]
	 *
	 * @param string $payment_method        Subscription payment method.
	 * @param string $wcpay_subscription_id Stripe subscription ID.
	 * @param bool   $is_staging            Whether the site is a staging copy.
	 * @param bool   $expected              Whether the subscription and its order are Stripe-billed.
	 */
	public function test_tells_stripe_billed_subscriptions_and_orders( string $payment_method, string $wcpay_subscription_id, bool $is_staging, bool $expected ): void {
		$GLOBALS[ WooCommerceSubscriptionsDoubles::DUPLICATE_SITE ] = $is_staging;
		$subscription = $this->create_subscription( array( '_wcpay_subscription_id' => $wcpay_subscription_id ), 2, '49.80', 'USD', $payment_method );
		$order        = $this->relate_order( array( 'renewal' => $subscription ) );

		$this->assertSame( $expected, $this->sut->is_wcpay_subscription( $subscription ) );
		$this->assertSame( $expected, $this->sut->is_wcpay_subscription_order( $order ) );
	}

	/**
	 * @testdox An order is Stripe-billed when any related subscription of any relation is, and not without subscriptions (client test_is_wcpay_subscription_order_with_mixed_subscriptions, `_without_subscriptions`).
	 */
	public function test_an_order_is_stripe_billed_when_any_related_subscription_is(): void {
		$tokenised     = $this->create_subscription();
		$stripe_billed = $this->create_subscription( array( '_wcpay_subscription_id' => self::MAIN_SUBSCRIPTION_ID ) );

		$this->assertTrue(
			$this->sut->is_wcpay_subscription_order(
				$this->relate_order(
					array(
						'parent'      => $tokenised,
						'resubscribe' => $stripe_billed,
					)
				)
			)
		);
		$this->assertFalse( $this->sut->is_wcpay_subscription_order( $this->relate_order( array( 'parent' => $tokenised ) ) ) );
		$this->assertFalse( $this->sut->is_wcpay_subscription_order( \WC_Helper_Order::create_order() ) );
	}

	/**
	 * @testdox A renewal order of a Stripe-billed subscription counts as Stripe-billed even without its own _wcpay_subscription_id, so native never charges it (invariant 1; the client's skip at trait :1243-1247 also requires the meta).
	 */
	public function test_a_renewal_order_without_its_own_subscription_id_is_still_stripe_billed(): void {
		$stripe_billed = $this->create_subscription( array( '_wcpay_subscription_id' => self::MAIN_SUBSCRIPTION_ID ) );
		$renewal       = $this->relate_order( array( 'renewal' => $stripe_billed ) );

		$this->assertSame( '', $renewal->get_meta( '_wcpay_subscription_id', true ) );
		$this->assertTrue( $this->sut->is_wcpay_subscription_order( $renewal ) );
	}

	/**
	 * @testdox A subscription without a parent order bills no one-time shipping where the client fatals reading the parent's shipping (client `class-wc-payments-subscription-service.php:985`).
	 */
	public function test_one_time_shipping_without_a_parent_order_bills_nothing(): void {
		$subscription = $this->create_subscription();
		$line_item    = $this->get_line_item( $subscription );
		$product      = wc_get_product( $line_item->get_product_id() );
		$product->update_meta_data( '_subscription_one_time_shipping', 'yes' );
		$product->save();
		$this->assertSame( 0, $subscription->get_parent_id() );

		$this->queue_entry( $this->get_entry( 'create_subscription' ) );
		$this->sut->create_subscription( wc_get_order( $subscription->get_id() ) );

		$body = $this->get_requests()[0][2];
		$this->assertSame( array( array( 'price_data' => $this->price( self::SHIPPING_PRODUCT_ID, 0, false ) ) ), $body['add_invoice_items'] );
	}

	/**
	 * @testdox A prepare filter that returns something other than an array sends the unfiltered data and logs the broken callback.
	 */
	public function test_a_broken_prepare_filter_sends_the_unfiltered_data_and_logs(): void {
		$subscription = $this->create_subscription();
		$broken       = static fn() => 'not an array';
		add_filter( 'wcpay_subscriptions_prepare_subscription_data', $broken );
		$logged       = array();
		$logger       = function ( $message ) use ( &$logged ) {
			$logged[] = $message;
			return $message;
		};
		add_filter( 'woocommerce_logger_log_message', $logger );
		$this->logging_enabled = true;

		try {
			$this->queue_entry( $this->get_entry( 'create_subscription' ) );
			$this->sut->create_subscription( wc_get_order( $subscription->get_id() ) );
		} finally {
			remove_filter( 'wcpay_subscriptions_prepare_subscription_data', $broken );
			remove_filter( 'woocommerce_logger_log_message', $logger );
		}

		$this->assertSame( self::RECORDED_CUSTOMER_ID, $this->get_requests()[0][2]['customer'] );
		$this->assertNotEmpty( array_filter( $logged, static fn( $line ): bool => false !== strpos( (string) $line, 'wcpay_subscriptions_prepare_subscription_data' ) ) );
	}

	/**
	 * @testdox A Stripe-billed subscription's edit screen shows its WooPayments Subscription ID; others show nothing (client `class-wc-payments-subscription-service.php:753-768`).
	 * @testWith ["woocommerce_payments", "<p><strong>WooPayments Subscription ID:</strong> sub_1UM1VrBzWlxcwgpP6A3GwGLe</p>"]
	 *           ["stripe", ""]
	 *
	 * @param string $payment_method Subscription payment method.
	 * @param string $expected       Markup shown.
	 */
	public function test_shows_the_stripe_subscription_id_on_the_edit_screen( string $payment_method, string $expected ): void {
		$subscription = $this->create_subscription( array( '_wcpay_subscription_id' => self::MAIN_SUBSCRIPTION_ID ), 2, '49.80', 'USD', $payment_method );

		ob_start();
		$this->sut->show_wcpay_subscription_id( $subscription );

		$this->assertSame( $expected, ob_get_clean() );
	}

	/**
	 * Create a monthly subscription of user 1 with one line item whose product already has its Stripe product.
	 *
	 * @param array<string,mixed> $meta           Subscription meta overriding the defaults.
	 * @param int                 $quantity       Item quantity.
	 * @param string              $subtotal       Item subtotal.
	 * @param string              $currency       Currency.
	 * @param string              $payment_method Payment method.
	 * @return SubscriptionDouble
	 */
	private function create_subscription( array $meta = array(), int $quantity = 2, string $subtotal = '49.80', string $currency = 'USD', string $payment_method = 'woocommerce_payments' ): SubscriptionDouble {
		$product = \WC_Helper_Product::create_simple_product();
		$product->update_meta_data( '_wcpay_product_id_test', self::RECORDED_PRODUCT_ID );
		$product->update_meta_data( '_wcpay_product_id_test_linked_to', self::RECORDED_ACCOUNT_ID );
		$product->save();

		$subscription = new SubscriptionDouble();
		$subscription->set_customer_id( 1 );
		$subscription->set_payment_method( $payment_method );
		$subscription->set_currency( $currency );
		$meta += array(
			'_billing_period'          => 'month',
			'_billing_interval'        => '1',
			'_requires_manual_renewal' => 'false',
		);
		foreach ( $meta as $key => $value ) {
			$subscription->update_meta_data( $key, $value );
		}

		$item = new \WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( $quantity );
		$item->set_subtotal( $subtotal );
		$item->set_total( $subtotal );
		$subscription->add_item( $item );
		$subscription->save();

		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ][] = $subscription->get_id();

		return wc_get_order( $subscription->get_id() );
	}

	/**
	 * Get a subscription's line item.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return \WC_Order_Item_Product
	 */
	private function get_line_item( WC_Order $subscription ): \WC_Order_Item_Product {
		$items = $subscription->get_items();

		return reset( $items );
	}

	/**
	 * Add a flat rate shipping line to a subscription.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @param string   $total        Shipping total.
	 * @return \WC_Order_Item_Shipping
	 */
	private function add_shipping( WC_Order $subscription, string $total ): \WC_Order_Item_Shipping {
		$item = new \WC_Order_Item_Shipping();
		$item->set_method_title( 'Flat rate' );
		$item->set_method_id( 'flat_rate' );
		$item->set_total( $total );
		$subscription->add_item( $item );
		$subscription->save();

		return $item;
	}

	/**
	 * Add a tax line to a subscription.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @param string   $tax_total    Tax on items.
	 * @param string   $shipping_tax Tax on shipping.
	 * @return \WC_Order_Item_Tax
	 */
	private function add_tax( WC_Order $subscription, string $tax_total, string $shipping_tax ): \WC_Order_Item_Tax {
		$item = new \WC_Order_Item_Tax();
		$item->set_rate_id( 4712 );
		$item->set_rate_code( 'US-CA-VAT-1' );
		$item->set_label( 'VAT' );
		$item->set_rate_percent( 8.25 );
		$item->set_compound( false );
		$item->set_tax_total( $tax_total );
		$item->set_shipping_tax_total( $shipping_tax );
		$subscription->add_item( $item );
		$subscription->save();

		return $item;
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
	 * Give a product a sign-up fee, stored as WooCommerce Subscriptions stores it.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $fee        Sign-up fee.
	 */
	private function set_sign_up_fee( int $product_id, string $fee ): void {
		$product = wc_get_product( $product_id );
		$product->update_meta_data( '_subscription_sign_up_fee', $fee );
		$product->save();
	}

	/**
	 * Create an order related to subscriptions.
	 *
	 * @param array<string,WC_Order> $relations Subscriptions by relation.
	 * @return WC_Order
	 */
	private function relate_order( array $relations ): WC_Order {
		$order = \WC_Helper_Order::create_order();
		foreach ( $relations as $relation => $subscription ) {
			$GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ][ $order->get_id() ][ $relation ][] = $subscription->get_id();
		}

		return $order;
	}

	/**
	 * Create a saved card token for the recorded card.
	 *
	 * @return \WC_Payment_Token_CC
	 */
	private function create_token(): \WC_Payment_Token_CC {
		$token = new \WC_Payment_Token_CC();
		$token->set_token( self::MAIN_PAYMENT_METHOD );
		$token->set_gateway_id( 'woocommerce_payments' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2034' );
		$token->set_user_id( 1 );
		$token->save();

		return $token;
	}

	/**
	 * Build a Stripe price in USD, monthly when recurring.
	 *
	 * @param string $product     Stripe product ID.
	 * @param int    $unit_amount Unit amount in cents, as the JSON body carries it.
	 * @param bool   $recurring   Whether the price recurs monthly.
	 * @return array<string,mixed>
	 */
	private function price( string $product, int $unit_amount, bool $recurring ): array {
		$price = array(
			'currency'            => 'USD',
			'product'             => $product,
			'unit_amount_decimal' => $unit_amount,
		);

		if ( $recurring ) {
			$price['recurring'] = array(
				'interval'       => 'month',
				'interval_count' => 1,
			);
		}

		return $price;
	}

	/**
	 * Create the Stripe subscription and return the checkout error it stops checkout with.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return string
	 */
	private function get_checkout_error( WC_Order $subscription ): string {
		try {
			$this->sut->create_subscription( $subscription );
		} catch ( \Exception $exception ) {
			return $exception->getMessage();
		}

		$this->fail( 'Checkout must be stopped.' );
	}

	/**
	 * Get a billing fixture entry, from the entries or the supporting entries.
	 *
	 * @param string $pair Entry name.
	 * @return array<string,mixed>
	 */
	private function get_entry( string $pair ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local test fixture.
		$recording = json_decode( (string) file_get_contents( self::BILLING_FIXTURE ), true );
		$entries   = array_merge( $recording['entries'], $recording['supporting_entries'] ?? array() );
		$matches   = array_values( array_filter( $entries, static fn( array $entry ) => $pair === $entry['pair'] ) );
		$this->assertNotEmpty( $matches, "Fixture entry $pair is missing." );

		return $matches[0];
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
