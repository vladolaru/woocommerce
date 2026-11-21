<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Tests\Internal\Payments\StorefrontMultiCurrencyTestTrait;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\TransportLog;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingApi;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingInvoiceService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingProductService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingSubscriptionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeHttpClient;
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
 * in `Fixtures/platform-stripe-billing-api.json`. Request bodies are written from the client's array literals, with `test_mode`
 * first as the client's `request()` adds it (`class-wc-payments-api-client.php:2635-2640`).
 */
class StripeBillingSubscriptionServiceTest extends WC_Unit_Test_Case {

	use StorefrontMultiCurrencyTestTrait;

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

	/**
	 * Whether the store is in test mode, for the requests' flag and the service alike.
	 *
	 * @var bool
	 */
	private bool $test_mode = true;

	private const BILLING_FIXTURE = __DIR__ . '/../Fixtures/platform-stripe-billing-api.json';

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
	 * Line written when Stripe has no subscription with the ID, which counts as a confirmed cancel.
	 */
	private const NOT_FOUND_LINE = 'Stripe has no such subscription on this account and mode, so it is counted as cancelled.';

	/**
	 * Start of the note a subscription off WooPayments gets once Stripe confirms its Stripe subscription cancelled.
	 */
	private const CANCELLED_NOTE_START = 'This subscription\'s payment method was changed';

	/**
	 * Address of WooPayments support, the WooPayments row's Get support link in Payments settings.
	 */
	private const SUPPORT_URL = 'https://woocommerce.com/my-account/contact-support/?select=woopayments';

	/**
	 * The System Under Test.
	 *
	 * @var StripeBillingSubscriptionService
	 */
	private $sut;

	/**
	 * Fake platform transport.
	 *
	 * @var FakeHttpClient
	 */
	private FakeHttpClient $http_client;

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
		$this->customer_user_ids = array();
		$this->logging_enabled   = false;
		$this->test_mode         = true;

		$this->http_client          = new FakeHttpClient();
		$this->http_client->blog_id = self::RECORDED_BLOG_ID;

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'is_test_mode_onboarding_enabled', 'get_account_id', 'is_dev_mode_enabled', 'get_gateway_setting' ) )
			->getMock();
		$account_service->method( 'is_test_mode_enabled' )->willReturnCallback( fn(): bool => $this->test_mode );
		$account_service->method( 'is_test_mode_onboarding_enabled' )->willReturn( true );
		$account_service->method( 'get_account_id' )->willReturn( self::RECORDED_ACCOUNT_ID );
		$account_service->method( 'get_gateway_setting' )->willReturnCallback( fn( string $key ) => 'enable_logging' === $key && $this->logging_enabled ? 'yes' : '' );

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
		$this->sut->init( $api, $this->customer_service, $product_service, $invoice_service, $logger, wc_get_container()->get( WooPaymentsActionSchedulerService::class ), $account_service );
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
			$this->reset_container_replacements();
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
	 * @testdox A $currency subscription item of $subtotal goes to Stripe as a unit amount of $expected.
	 *
	 * Expected values come from client 11.1.0 WC_Payments_Subscription_Service::format_item_price_data()
	 * (includes/subscriptions/class-wc-payments-subscription-service.php:337-358): the amount rounded to WooCommerce's
	 * rounding precision (6 here), multiplied by 100 unless the currency is in WC_Payments_Utils::zero_decimal_currencies()
	 * (includes/class-wc-payments-utils.php:222-240). Three-decimal currencies and UGX are not in that list, so they are
	 * multiplied by 100 too.
	 *
	 * @dataProvider provide_unit_amounts_per_currency
	 *
	 * @param string $currency Currency.
	 * @param string $subtotal Item subtotal for a quantity of one.
	 * @param float  $expected Stripe unit_amount_decimal.
	 */
	public function test_item_unit_amount_follows_the_currency_minor_unit( string $currency, string $subtotal, float $expected ): void {
		$items = $this->sut->get_recurring_item_data_for_subscription( $this->create_subscription( array(), 1, $subtotal, $currency ) );

		$this->assertSame( $expected, $items[0]['price_data']['unit_amount_decimal'] );
	}

	/**
	 * @testdox A subscription item with no units stays in the Stripe data at a unit amount of 0.
	 *
	 * Client 11.3.0 test "zero quantity item is kept with a zero unit amount"
	 * (includes/subscriptions/class-wc-payments-subscription-service.php:880-887): an invoice repair then prices the
	 * Stripe item at 0 instead of leaving the old amount billed.
	 */
	public function test_zero_quantity_item_is_kept_with_a_zero_unit_amount(): void {
		$items = $this->sut->get_recurring_item_data_for_subscription( $this->create_subscription( array(), 0 ) );

		$this->assertSame( 0, $items[0]['quantity'] );
		$this->assertSame( 0.0, $items[0]['price_data']['unit_amount_decimal'] );
	}

	/**
	 * Item subtotals and the unit amounts client 11.1.0 sends for them.
	 *
	 * @return array<string,array{string,string,float}>
	 */
	public function provide_unit_amounts_per_currency(): array {
		$cases = array();
		foreach ( array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' ) as $currency ) {
			$cases[ $currency . ', zero-decimal' ] = array( $currency, '1500', 1500.0 );
		}

		return $cases + array(
			'JPY, a fraction kept'              => array( 'JPY', '1500.5', 1500.5 ),
			'USD, recorded amount'              => array( 'USD', '24.90', 2490.0 ),
			'EUR, rounded to six decimals'      => array( 'EUR', '12.3456789', 1234.5679 ),
			'GBP, a fraction of a cent kept'    => array( 'GBP', '0.333333333', 33.3333 ),
			'KWD, three-decimal'                => array( 'KWD', '1.234', 123.4 ),
			'BHD, three-decimal'                => array( 'BHD', '10.5', 1050.0 ),
			'UGX, not in the zero-decimal list' => array( 'UGX', '1500', 150000.0 ),
		);
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
	 * @testdox A subscription with $label stops checkout with the general message before any platform call; a Stripe customer is asked for: $asks_customer.
	 *
	 * Client `class-wc-payments-subscription-service.php`: deleted product `:432`, `:482`; no customer account `:433-438`;
	 * billing cycle longer than one year `:1171-1177`.
	 *
	 * @dataProvider provider_checkout_refusals_before_the_platform
	 *
	 * @param string               $label  Case label.
	 * @param array<string,string> $meta   Subscription meta.
	 * @param callable|null        $change Change made to the subscription after it is created, given the test and the subscription.
	 * @param bool                 $asks_customer Whether checkout asks the customer service for a Stripe customer first.
	 */
	public function test_checkout_is_refused_before_the_platform( string $label, array $meta, ?callable $change, bool $asks_customer ): void {
		unset( $label );
		$subscription = $this->create_subscription( $meta );
		if ( null !== $change ) {
			$change( $this, $subscription );
		}
		$this->customer_service->expects( $asks_customer ? $this->atLeastOnce() : $this->never() )->method( 'get_or_create_customer_id_for_user' );

		$message = $this->get_checkout_error( wc_get_order( $subscription->get_id() ) );

		$this->assertSame( 'There was a problem creating your subscription. Please try again or contact us for assistance.', $message );
		$this->assertSame( 0, $this->http_client->request_count );
	}

	/**
	 * Subscriptions checkout refuses before any platform call.
	 *
	 * @return array<string,array{0:string,1:array<string,string>,2:?callable,3:bool}>
	 */
	public function provider_checkout_refusals_before_the_platform(): array {
		return array(
			'deleted product'     => array(
				'a deleted product',
				array(),
				static function ( self $test, WC_Order $subscription ): void {
					wc_get_product( $test->get_line_item( $subscription )->get_product_id() )->delete( true );
				},
				true,
			),
			'13 months'           => array(
				'a billing cycle of 13 months',
				array(
					'_billing_period'   => 'month',
					'_billing_interval' => '13',
				),
				null,
				true,
			),
			'2 years'             => array(
				'a billing cycle of 2 years',
				array(
					'_billing_period'   => 'year',
					'_billing_interval' => '2',
				),
				null,
				true,
			),
			'53 weeks'            => array(
				'a billing cycle of 53 weeks',
				array(
					'_billing_period'   => 'week',
					'_billing_interval' => '53',
				),
				null,
				true,
			),
			'366 days'            => array(
				'a billing cycle of 366 days',
				array(
					'_billing_period'   => 'day',
					'_billing_interval' => '366',
				),
				null,
				true,
			),
			'no customer account' => array(
				'no customer account',
				array(),
				static function ( self $test, WC_Order $subscription ): void {
					unset( $test );
					$subscription->set_customer_id( 0 );
					$subscription->save();
				},
				false,
			),
		);
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
	 * @testdox A manual subscription renewed by hand gets a Stripe subscription, not backdated, only when it has payment tokens and none yet (client creation-logic tests, `class-wc-payments-subscription-service.php:800-817`).
	 *
	 * The client creates it through the same create_subscription(), with the switch flag false in a request of its own (`:108`, `:848`).
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
		if ( $expected_requests ) {
			$this->assertArrayNotHasKey( 'backdate_start_date', $this->get_requests()[0][2], 'Only a switch to WooPayments keeps the billing dates.' );
			$this->assertArrayNotHasKey( 'billing_cycle_anchor', $this->get_requests()[0][2] );
		}
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
	 * @testdox A new Stripe subscription clears the pending invoice an earlier one left, so a later payment method change charges nothing.
	 *
	 * The mark is set only for the Stripe subscription the subscription holds, and creation runs only without one, so a mark
	 * found at creation is an earlier Stripe subscription's open invoice. Its renewal was paid another way before the
	 * subscription left WooPayments and came back with a saved card.
	 */
	public function test_a_new_stripe_subscription_clears_an_earlier_pending_invoice(): void {
		$this->queue_entry( $this->get_entry( 'create_subscription' ) );
		$subscription = $this->create_subscription(
			array(
				'_wcpay_pending_invoice_id'        => 'in_earlier_open_invoice',
				'_cancelled_wcpay_subscription_id' => 'sub_earlier',
			)
		);

		$this->sut->maybe_create_subscription_from_update_payment_method( $subscription, 'woocommerce_payments' );
		$saved = wc_get_order( $subscription->get_id() );
		$this->sut->maybe_attempt_payment_for_subscription( $saved, $this->create_token() );

		$this->assertSame( self::MAIN_SUBSCRIPTION_ID, $saved->get_meta( '_wcpay_subscription_id', true ) );
		$this->assertSame( '', $saved->get_meta( '_wcpay_pending_invoice_id', true ) );
		$this->assertSame( array( 'POST' ), array_column( $this->get_requests(), 0 ), 'Only the creation; the earlier invoice is not charged.' );
	}

	/**
	 * @testdox A checkout subscription created after a switch to WooPayments in the same process keeps its own billing dates.
	 *
	 * Client 11.1.0 sets the switch's backdating flag on its subscription service singleton and never clears it
	 * (`class-wc-payments-subscription-service.php:108`, `:506`, `:848`).
	 */
	public function test_a_checkout_subscription_after_a_switch_is_not_backdated(): void {
		$this->queue_entry( $this->get_entry( 'create_subscription' ) );
		$this->queue_entry( $this->get_entry( 'create_subscription' ) );
		$switched = $this->create_subscription( array( '_schedule_next_payment' => gmdate( 'Y-m-d H:i:s', time() + 29 * DAY_IN_SECONDS ) ) );
		$checkout = $this->create_subscription();
		add_action( 'woocommerce_subscription_payment_method_updated', array( $this->sut, 'maybe_create_subscription_from_update_payment_method' ), 10, 2 );
		add_action( 'woocommerce_checkout_subscription_created', array( $this->sut, 'create_subscription' ) );

		do_action( 'woocommerce_subscription_payment_method_updated', wc_get_order( $switched->get_id() ), 'woocommerce_payments', 'stripe' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		do_action( 'woocommerce_checkout_subscription_created', wc_get_order( $checkout->get_id() ) ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment

		$creates = array_values( array_filter( $this->get_requests(), static fn( array $request ): bool => 'POST' === $request[0] && 1 === preg_match( '#/wcpay/subscriptions$#', $request[1] ) ) );
		$this->assertCount( 2, $creates );
		$this->assertArrayHasKey( 'billing_cycle_anchor', $creates[0][2], 'The switched subscription keeps its billing dates.' );
		$this->assertArrayNotHasKey( 'backdate_start_date', $creates[1][2] );
		$this->assertArrayNotHasKey( 'billing_cycle_anchor', $creates[1][2] );
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
	 * @testdox Each status change reaches the Stripe subscription, and only putting it on hold notes the suspension (client test_cancel_subscription, test_suspend_subscription, test_set_pending_cancel_for_subscription, test_reactivate_subscription).
	 * @dataProvider status_changes
	 *
	 * @param string     $method   Service method the status hook calls.
	 * @param string     $pair     Recorded answer.
	 * @param string     $verb     HTTP method sent.
	 * @param string     $path     Path sent.
	 * @param array|null $body     Body sent.
	 * @param bool       $suspended Whether the subscription gets the suspension note (client `:549-550`).
	 */
	public function test_status_changes_reach_the_stripe_subscription( string $method, string $pair, string $verb, string $path, ?array $body, bool $suspended ): void {
		$this->queue_entry( $this->get_entry( $pair ) );
		$subscription = $this->create_subscription( array( '_wcpay_subscription_id' => self::MAIN_SUBSCRIPTION_ID ) );

		$this->sut->$method( $subscription );

		$this->assertSame( array( array( $verb, $path, $body ) ), $this->get_requests() );
		$this->assertSame( $suspended, in_array( 'Suspended WooPayments Subscription because subscription status changed to on-hold.', $this->get_order_note_texts( $subscription ), true ) );
	}

	/**
	 * Status changes and the request each sends: client `class-wc-payments-subscription-service.php:518-530`, `:539-587`, `:614-616`, `:597-605`; endpoints `class-wc-payments-api-client.php:1721-1733`, `:1746-1758`.
	 *
	 * @return array<string,array{string,string,string,string,array<string,mixed>|null,bool}>
	 */
	public static function status_changes(): array {
		$path = '/sites/4/wcpay/subscriptions/' . self::MAIN_SUBSCRIPTION_ID;

		return array(
			'cancelled or expired' => array( 'cancel_subscription', 'cancel_subscription', 'DELETE', $path . '?test_mode=1', null, false ),
			'on hold'              => array(
				'handle_subscription_status_on_hold',
				'update_subscription',
				'POST',
				$path,
				array(
					'test_mode'        => true,
					'pause_collection' => array( 'behavior' => 'void' ),
				),
				true,
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
				false,
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
				false,
			),
		);
	}

	/**
	 * @testdox The on-hold suspension line names the code that changed the status, through the module's status hook, without call arguments.
	 *
	 * Client 11.1.0 appends `getTraceAsString()` to the message (`class-wc-payments-subscription-service.php:552-565`).
	 * The status change reaches the service through the module's hook callback, as in production. WooCommerce Subscriptions
	 * fires the status hook two frames deeper than the subscription double, from `save()` and `status_transition()`
	 * (`class-wc-subscription.php:606`, `:640-653`; core `class-wc-order.php:286`), so the 20 frames the line writes keep a margin.
	 */
	public function test_suspension_log_traces_the_status_change_without_call_arguments(): void {
		$this->queue_entry( $this->get_entry( 'update_subscription' ) );
		$subscription = $this->create_subscription( array( '_wcpay_subscription_id' => self::MAIN_SUBSCRIPTION_ID ) );
		$subscription->set_status( 'active' );
		$subscription->save();
		$this->register_stripe_billing_module();
		$this->logging_enabled = true;
		$logger                = RecordingWcLogger::install();

		$this->put_on_hold_from_custom_code( $subscription, 'secret-call-argument' );

		$prefix = 'Suspended WooPayments Subscription because subscription status changed to on-hold.';
		$lines  = array_keys( array_filter( $logger->lines, static fn( array $line ): bool => 0 === strpos( $line[1], $prefix ) ) );
		$this->assertCount( 1, $lines );
		$this->assertSame(
			sprintf( '%s WC ID: %d; WooPayments ID: %s.', $prefix, $subscription->get_id(), self::MAIN_SUBSCRIPTION_ID ),
			$logger->lines[ $lines[0] ][1]
		);
		$trace = (string) ( $logger->contexts[ $lines[0] ]['trace'] ?? '' );
		$this->assertStringContainsString( __CLASS__ . '->put_on_hold_from_custom_code()', $trace, 'The trace reaches past the status hooks to the code that changed the status.' );
		$this->assertStringNotContainsString( 'secret-call', $trace . wp_json_encode( $logger->contexts[ $lines[0] ] ), 'The trace leaves out call arguments.' );
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
	 * @testdox A platform failure on $method does not stop the status change, writes an error line whatever the logging setting, and leaves a note on the subscription, without the suspension note.
	 * @testWith ["handle_subscription_status_on_hold", "There was a problem updating the WooPayments subscription on server.", "WooPayments could not pause this subscription at Stripe"]
	 *           ["set_pending_cancel_for_subscription", "There was a problem updating the WooPayments subscription on server.", "WooPayments could not set this subscription to cancel at the end of its period at Stripe"]
	 *           ["reactivate_subscription", "There was a problem updating the WooPayments subscription on server.", "WooPayments could not resume this subscription at Stripe"]
	 *
	 * Client 11.1.0 only writes a gated log line (`class-wc-payments-subscription-service.php:525-529`, `:549-550`, `:1014-1018`),
	 * and notes the suspension whether or not Stripe paused it; a failure here leaves Stripe billing out of step.
	 *
	 * @param string $method        Service method the status hook calls.
	 * @param string $expected_line Error line written.
	 * @param string $expected_note Note left on the subscription.
	 */
	public function test_platform_failures_on_status_changes_are_logged_and_noted( string $method, string $expected_line, string $expected_note ): void {
		$this->http_client->responses[] = $this->make_response(
			500,
			array(
				'code'    => 'wcpay_server_error',
				'message' => 'Rec T63 platform failure',
				'data'    => array( 'status' => 500 ),
			)
		);
		$subscription                   = $this->create_subscription( array( '_wcpay_subscription_id' => self::MAIN_SUBSCRIPTION_ID ) );
		$logger                         = RecordingWcLogger::install();

		$this->sut->$method( $subscription );

		$this->assertSame( 1, $this->http_client->request_count );
		$this->assertContains( $expected_line, array_column( $logger->get_errors(), 1 ), 'Logged with logging off.' );
		$notes = $this->get_order_note_texts( $subscription );
		$this->assertCount( 1, array_filter( $notes, static fn( string $note ): bool => 0 === strpos( $note, $expected_note ) ) );
		$this->assertNotContains( 'Suspended WooPayments Subscription because subscription status changed to on-hold.', $notes );
	}

	/**
	 * @testdox A platform error from $method is logged with its status and code, never its message.
	 * @testWith ["create_subscription", "info"]
	 *           ["cancel_subscription", "error"]
	 *           ["set_pending_cancel_for_subscription", "error"]
	 *
	 * Client 11.1.0 appends the platform's message (`class-wc-payments-subscription-service.php:459`, `:528`, `:1017`).
	 *
	 * @param string $method Service method that calls the platform.
	 * @param string $level  Level of the line.
	 */
	public function test_platform_error_log_leaves_out_platform_text( string $method, string $level ): void {
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

		$context = $this->get_logged_context_at_level( $logger, $level );
		$this->assertSame( array( 404, 'resource_missing', $subscription->get_id() ), array( $context['http_status'], $context['error_code'], $context['subscription_id'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * @testdox A Stripe-billed subscription supports Stripe's scheduled payments and not amount or date changes; other WooPayments ones the reverse; another gateway's keep its answer (client test_prevent_wcpay_subscription_changes, `class-wc-payments-subscription-service.php:705-723`).
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
	 *           [false, "gateway_scheduled_payments", true, false, "woocommerce_payments_sepa_debit"]
	 *           [false, "gateway_scheduled_payments", true, true, "asaas-credit-card"]
	 *           [false, "subscription_amount_changes", false, false, "asaas-credit-card"]
	 *           [false, "subscription_date_changes", false, false, "asaas-credit-card"]
	 *
	 * The asaas-credit-card rows: Asaas Gateway for WooCommerce lists `gateway_scheduled_payments` in its gateway's
	 * supports (`woo-asaas/includes/gateway/class-gateway.php:110-121`) and bills on its own schedule. Client 11.1.0
	 * answers false for it too.
	 *
	 * @param bool   $is_stripe_billed Whether the subscription is Stripe-billed.
	 * @param string $feature          Feature asked about.
	 * @param bool   $supported        What the gateway answered.
	 * @param bool   $expected         What the subscription supports.
	 * @param string $payment_method   The subscription's payment method.
	 */
	public function test_supports_follow_stripe_billing( bool $is_stripe_billed, string $feature, bool $supported, bool $expected, string $payment_method = 'woocommerce_payments' ): void {
		$subscription = $this->create_subscription( array( '_wcpay_subscription_id' => $is_stripe_billed ? self::MAIN_SUBSCRIPTION_ID : '' ), 2, '49.80', 'USD', $payment_method );

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
		$this->assertNull( $this->get_retry_cancel_delay( $subscription, 1 ), 'A confirmed cancel schedules no retry.' );
	}

	/**
	 * @testdox A cancel that Stripe refuses after a $trigger keeps the Stripe ID and is retried at +1, +10 and +60 minutes: $_dataName.
	 *
	 * Each failed attempt writes the always-written line and nothing else; after the fourth the subscription gets the give-up
	 * note once. A confirmed retry moves the ID as a first-time cancel does: off WooPayments it moves to the cancelled key,
	 * on a cancelled subscription it stays, as today.
	 *
	 * @dataProvider provider_failed_cancels
	 *
	 * @param string $trigger          `payment method change` or `cancellation`.
	 * @param int    $failures         Attempts Stripe refuses before it confirms, 4 for all of them.
	 * @param string $expected_id_key  Meta key that holds the Stripe ID at the end.
	 * @param int    $expected_give_up Give-up notes at the end.
	 */
	public function test_a_failed_cancel_keeps_the_stripe_id_and_is_retried( string $trigger, int $failures, string $expected_id_key, int $expected_give_up ): void {
		$subscription = $this->create_subscription( array( '_wcpay_subscription_id' => self::MAIN_SUBSCRIPTION_ID ) );
		$subscription->set_status( 'active' );
		$subscription->save();
		$attempts = min( $failures + 1, 4 );
		for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
			if ( $attempt > 1 ) {
				$this->queue_entry( $this->get_entry( 'get_subscription' ) );
			}
			if ( $attempt <= $failures ) {
				$this->queue_platform_failure();
			} else {
				$this->queue_entry( $this->get_entry( 'cancel_subscription' ) );
			}
		}
		$this->register_stripe_billing_module();
		$logger = RecordingWcLogger::install();

		if ( 'cancellation' === $trigger ) {
			$subscription->update_status( 'cancelled' );
		} else {
			// WooCommerce Subscriptions saves the new payment method before it fires the hook (class-wc-subscriptions-change-payment-gateway.php:588-607).
			$subscription->set_payment_method( 'stripe' );
			$subscription->save();
			do_action( 'woocommerce_subscription_payment_method_updated_from_woocommerce_payments', $subscription, 'stripe' );
		}

		$saved = wc_get_order( $subscription->get_id() );
		$this->assertSame( self::MAIN_SUBSCRIPTION_ID, $saved->get_meta( '_wcpay_subscription_id', true ), 'The ID stays until Stripe confirms.' );
		$this->assertFalse( $saved->meta_exists( '_cancelled_wcpay_subscription_id' ) );
		$this->assertSame( array(), $this->get_notes_starting_with( $saved, 'WooPayments could not' ), 'The first failure leaves no note.' );

		$delays = array(
			1 => MINUTE_IN_SECONDS,
			2 => 10 * MINUTE_IN_SECONDS,
			3 => HOUR_IN_SECONDS,
		);
		for ( $retry = 1; $retry < $attempts; $retry++ ) {
			$this->assertEqualsWithDelta( $delays[ $retry ], $this->get_retry_cancel_delay( $subscription, $retry ), 5, "Retry $retry runs on the webhook retry cadence." );
			do_action( StripeBillingSubscriptionService::RETRY_CANCEL_ACTION, $subscription->get_id(), $retry );
		}

		$saved = wc_get_order( $subscription->get_id() );
		$this->assertSame( self::MAIN_SUBSCRIPTION_ID, $saved->get_meta( $expected_id_key, true ) );
		$moved = '_cancelled_wcpay_subscription_id' === $expected_id_key;
		$this->assertSame( ! $moved, $saved->meta_exists( '_wcpay_subscription_id' ), 'Only a confirmed cancel off WooPayments moves the ID.' );
		$this->assertSame( $moved, $saved->meta_exists( '_cancelled_wcpay_subscription_id' ) );
		$this->assertNull( $this->get_retry_cancel_delay( $subscription, $attempts ), 'No retry follows the last attempt or a confirmed one.' );
		$this->assertCount( $failures, array_keys( array_column( $logger->get_errors(), 1 ), 'There was a problem canceling the subscription on WooPayments server.', true ), 'One always-written line per failed attempt.' );
		$this->assertSame(
			$expected_give_up ? array( 'WooPayments could not cancel this subscription&#039;s Stripe Billing subscription (' . self::MAIN_SUBSCRIPTION_ID . ') after 4 attempts, so the customer may still be charged each period. <a href="' . self::SUPPORT_URL . '" target="_blank" rel="noopener noreferrer">Contact WooPayments support</a> with this ID to have it cancelled. Each charge it takes appears as a renewal order of this subscription, where you can refund it.' ) : array(),
			$this->get_notes_starting_with( $saved, 'WooPayments could not' )
		);
		$this->assertSame( 1 + 2 * ( $attempts - 1 ), $this->http_client->request_count, 'Each retry reads the Stripe subscription, then cancels it.' );
	}

	/**
	 * Failed cancels: what started them, how many attempts Stripe refuses, where the ID ends and the give-up notes.
	 *
	 * @return array<string,array{string,int,string,int}>
	 */
	public function provider_failed_cancels(): array {
		return array(
			'payment method change, refused once'       => array( 'payment method change', 1, '_cancelled_wcpay_subscription_id', 0 ),
			'payment method change, refused four times' => array( 'payment method change', 4, '_wcpay_subscription_id', 1 ),
			'cancellation, refused once'                => array( 'cancellation', 1, '_wcpay_subscription_id', 0 ),
			'cancellation, refused four times'          => array( 'cancellation', 4, '_wcpay_subscription_id', 1 ),
		);
	}

	/**
	 * @testdox The give-up note escapes its text and the Stripe ID, and keeps only the link to WooPayments support as HTML.
	 *
	 * The ID is read from the subscription's meta. One that is not a Stripe ID fails the cancel before any request, so the
	 * last attempt gives up on it.
	 */
	public function test_the_give_up_note_escapes_its_text_and_links_support(): void {
		$subscription = $this->create_subscription( array( '_wcpay_subscription_id' => 'sub_<b>"&\'</b>' ), 2, '49.80', 'USD', 'stripe' );

		$this->sut->handle_retry_cancel_subscription( $subscription->get_id(), 3 );

		$this->assertSame( 0, $this->http_client->request_count );
		$this->assertSame(
			array( 'WooPayments could not cancel this subscription&#039;s Stripe Billing subscription (sub_&lt;b&gt;&quot;&amp;&#039;&lt;/b&gt;) after 4 attempts, so the customer may still be charged each period. <a href="' . self::SUPPORT_URL . '" target="_blank" rel="noopener noreferrer">Contact WooPayments support</a> with this ID to have it cancelled. Each charge it takes appears as a renewal order of this subscription, where you can refund it.' ),
			$this->get_notes_starting_with( wc_get_order( $subscription->get_id() ), 'WooPayments could not' )
		);
	}

	/**
	 * @testdox A cancel answered with $_dataName $expectation.
	 *
	 * The platform passes Stripe's answer for an ID Stripe does not have through unchanged: 404 with `resource_missing`. No
	 * outage takes that shape. In live mode a not-found counts as a confirmed cancel with one always-written line and no
	 * retry. In test mode the same answer is likely a live subscription that still bills, so it is retried, as is any other
	 * answer, including the same code at another status or a 404 with another code.
	 *
	 * @dataProvider provider_cancel_answers
	 *
	 * @param int                 $http_status        HTTP status the platform answers.
	 * @param array<string,mixed> $body               Body the platform answers.
	 * @param bool                $test_mode          Whether the store, and so the request, is in test mode.
	 * @param bool                $expected_confirmed Whether the cancel counts as confirmed.
	 * @param string              $expectation        What happens, for the test name.
	 */
	public function test_a_cancel_counts_only_a_live_stripe_not_found_as_confirmed( int $http_status, array $body, bool $test_mode, bool $expected_confirmed, string $expectation ): void {
		unset( $expectation );
		$this->test_mode = $test_mode;
		// WooCommerce Subscriptions saves the new payment method before it fires the hook (class-wc-subscriptions-change-payment-gateway.php:601-607).
		$subscription                   = $this->create_subscription( array( '_wcpay_subscription_id' => self::MAIN_SUBSCRIPTION_ID ), 2, '49.80', 'USD', 'stripe' );
		$this->http_client->responses[] = $this->make_response( $http_status, $body );
		$logger                         = RecordingWcLogger::install();

		$this->sut->maybe_cancel_subscription( $subscription, 'stripe' );

		$this->assertSame( array( 'DELETE', '/sites/4/wcpay/subscriptions/' . self::MAIN_SUBSCRIPTION_ID . '?test_mode=' . ( $test_mode ? '1' : '0' ) ), array_slice( $this->get_requests()[0], 0, 2 ), 'The request carries the mode the service read.' );
		$saved = wc_get_order( $subscription->get_id() );
		$this->assertSame( self::MAIN_SUBSCRIPTION_ID, $saved->get_meta( $expected_confirmed ? '_cancelled_wcpay_subscription_id' : '_wcpay_subscription_id', true ) );
		$this->assertSame( ! $expected_confirmed, null !== $this->get_retry_cancel_delay( $subscription, 1 ), 'Only an unconfirmed cancel is retried.' );
		$messages = array_column( $logger->lines, 1 );
		$this->assertSame( $expected_confirmed ? 1 : 0, count( array_keys( $messages, self::NOT_FOUND_LINE, true ) ) );
		$this->assertSame( $expected_confirmed ? 0 : 1, count( array_keys( $messages, 'There was a problem canceling the subscription on WooPayments server.', true ) ) );
		if ( $expected_confirmed ) {
			// A failure keeps the ID, so only the not-found row can carry a note.
			$this->assertSame( array(), $this->get_notes_starting_with( $saved, self::CANCELLED_NOTE_START ), 'Stripe answers the same for another account, so no cancel note.' );
			$context = $this->get_logged_context( $logger, self::NOT_FOUND_LINE );
			$this->assertSame( array( $subscription->get_id(), self::MAIN_SUBSCRIPTION_ID, 1, 404, 'resource_missing' ), array( $context['subscription_id'], $context['wcpay_subscription_id'], $context['attempt'], $context['http_status'], $context['error_code'] ) );
			$this->assert_log_holds_no_provider_text( $logger );
		}
	}

	/**
	 * Platform answers to a cancel, in live mode unless named: Stripe's not-found, the same in test mode, a platform failure,
	 * Stripe's `resource_missing` for another object (a 400), and a 404 from the platform's own routing.
	 *
	 * @return array<string,array{int,array<string,mixed>,bool,bool,string}>
	 */
	public function provider_cancel_answers(): array {
		$stripe_error = static fn( string $param ): array => array(
			'error' => array(
				'code'    => 'resource_missing',
				'message' => "No such customer: 'cus_123'; ask shopper@example.com, see https://pay.example.test/r?key=sk_test_leak123",
				'param'   => $param,
				'type'    => 'invalid_request_error',
			),
		);

		return array(
			'404 resource_missing (Stripe has no such subscription)' => array( 404, $stripe_error( 'id' ), false, true, 'moves the ID, writes one line and schedules no retry' ),
			'404 resource_missing in test mode (likely a live subscription)' => array( 404, $stripe_error( 'id' ), true, false, 'keeps the ID and is retried' ),
			'502 wcpay_request_failure (platform failure)' => array(
				502,
				array(
					'code'    => 'wcpay_request_failure',
					'message' => 'Platform failure',
					'data'    => array( 'status' => 502 ),
				),
				false,
				false,
				'keeps the ID and is retried',
			),
			'400 resource_missing (another object)'        => array( 400, $stripe_error( 'customer' ), false, false, 'keeps the ID and is retried' ),
			'404 rest_no_route (platform routing)'         => array(
				404,
				array(
					'code'    => 'rest_no_route',
					'message' => 'No route was found matching the URL and request method.',
					'data'    => array( 'status' => 404 ),
				),
				false,
				false,
				'keeps the ID and is retried',
			),
		);
	}

	/**
	 * @testdox A live-mode last cancel retry that finds Stripe has no such subscription counts it as cancelled, with the same line and no give-up note.
	 *
	 * Retry 3 is the last attempt, where a failure would add the give-up note.
	 */
	public function test_a_cancel_retry_counts_a_live_stripe_not_found_as_confirmed(): void {
		$this->test_mode                = false;
		$subscription                   = $this->create_subscription( array( '_wcpay_subscription_id' => self::MAIN_SUBSCRIPTION_ID ), 2, '49.80', 'USD', 'stripe' );
		$not_found                      = array(
			'error' => array(
				'code'    => 'resource_missing',
				'message' => "No such subscription: '" . self::MAIN_SUBSCRIPTION_ID . "'",
				'param'   => 'id',
				'type'    => 'invalid_request_error',
			),
		);
		$this->http_client->responses[] = $this->make_response( 404, $not_found );
		$this->http_client->responses[] = $this->make_response( 404, $not_found );
		$logger                         = RecordingWcLogger::install();

		$this->sut->handle_retry_cancel_subscription( $subscription->get_id(), 3 );

		$this->assertSame( self::MAIN_SUBSCRIPTION_ID, wc_get_order( $subscription->get_id() )->get_meta( '_cancelled_wcpay_subscription_id', true ) );
		$context = $this->get_logged_context( $logger, self::NOT_FOUND_LINE );
		$this->assertSame( array( $subscription->get_id(), self::MAIN_SUBSCRIPTION_ID, 4 ), array( $context['subscription_id'], $context['wcpay_subscription_id'], $context['attempt'] ) );
		$this->assertSame( array(), $this->get_notes_starting_with( wc_get_order( $subscription->get_id() ), 'WooPayments could not' ), 'No give-up note.' );
		$this->assertSame( array(), $this->get_notes_starting_with( wc_get_order( $subscription->get_id() ), self::CANCELLED_NOTE_START ), 'Stripe answers the same for another account, so no cancel note.' );
	}

	/**
	 * @testdox A cancel job for a subscription back on WooPayments $_dataName.
	 *
	 * Its Stripe subscription is wanted again, so the job sends no cancel. After a failed attempt, which Stripe may have
	 * applied with its answer lost, the job reads the Stripe subscription once: a canceled one releases the ID, and
	 * WooCommerce Subscriptions then renews by token. A first attempt has no earlier one, so it reads nothing.
	 *
	 * @dataProvider provider_cancel_jobs_back_on_woopayments
	 *
	 * @param int         $retry           Attempt of the job.
	 * @param string      $status          Subscription status when the job runs.
	 * @param string|null $stripe_status   Status the Stripe subscription reads, or null when nothing is read.
	 * @param string      $expected_id_key Meta key that holds the Stripe ID afterwards.
	 */
	public function test_a_cancel_job_for_a_subscription_back_on_woopayments( int $retry, string $status, ?string $stripe_status, string $expected_id_key ): void {
		$subscription = $this->create_subscription( array( '_wcpay_subscription_id' => self::MAIN_SUBSCRIPTION_ID ) );
		$subscription->set_status( $status );
		$subscription->save();
		if ( null !== $stripe_status ) {
			$entry                               = $this->get_entry( 'get_subscription' );
			$entry['response']['body']['status'] = $stripe_status;
			$this->queue_entry( $entry );
		}

		$this->sut->handle_retry_cancel_subscription( $subscription->get_id(), $retry );

		$this->assertSame( null === $stripe_status ? array() : array( array( 'GET', '/sites/4/wcpay/subscriptions/' . self::MAIN_SUBSCRIPTION_ID . '?test_mode=1', null ) ), $this->get_requests(), 'At most one read, and never a cancel.' );
		$saved = wc_get_order( $subscription->get_id() );
		$this->assertSame( self::MAIN_SUBSCRIPTION_ID, $saved->get_meta( $expected_id_key, true ) );
		$this->assertSame( '_wcpay_subscription_id' === $expected_id_key, $saved->meta_exists( '_wcpay_subscription_id' ) );
		$this->assertSame( 'woocommerce_payments', $saved->get_payment_method() );
		$this->assertSame( $status, $saved->get_status() );
		$this->assertNull( $this->get_retry_cancel_delay( $subscription, $retry + 1 ) );
		$this->assertSame( array(), $this->get_notes_starting_with( $saved, self::CANCELLED_NOTE_START ), 'The return read is silent.' );
	}

	/**
	 * Cancel jobs for a subscription back on WooPayments: the attempt, the subscription status, what Stripe reads and where the ID ends.
	 *
	 * @return array<string,array{int,string,string|null,string}>
	 */
	public function provider_cancel_jobs_back_on_woopayments(): array {
		return array(
			'reads a live Stripe subscription after a failed attempt and keeps the ID' => array( 1, 'active', 'active', '_wcpay_subscription_id' ),
			'reads the live Stripe subscription of one on hold and keeps the ID' => array( 1, 'on-hold', 'active', '_wcpay_subscription_id' ),
			'reads a canceled Stripe subscription after a failed attempt and releases the ID' => array( 2, 'active', 'canceled', '_cancelled_wcpay_subscription_id' ),
			'reads nothing on a first attempt' => array( 0, 'active', null, '_wcpay_subscription_id' ),
		);
	}

	/**
	 * @testdox A subscription moved to $_dataName gets one note naming its cancelled Stripe subscription, and no second one when the cancel runs again.
	 *
	 * A background job can end a customer's automatic billing long after the move, so the subscription says why. The note
	 * is written where the ID moves; once it has moved, every cancel path finds no ID and stops. The same note fits a move
	 * to another gateway, to Manual Renewal and to another WooPayments payment method.
	 *
	 * @dataProvider provider_confirmed_cancels_off_woopayments
	 *
	 * @param string $route          How the cancel ran: `job`, `job reads canceled` or `customer change`.
	 * @param string $payment_method Payment method the subscription moved to, empty for Manual Renewal.
	 */
	public function test_a_confirmed_cancel_off_woopayments_notes_the_subscription_once( string $route, string $payment_method ): void {
		$meta = array( '_wcpay_subscription_id' => self::MAIN_SUBSCRIPTION_ID );
		if ( '' === $payment_method ) {
			$meta['_requires_manual_renewal'] = 'true';
		}
		$subscription = $this->create_subscription( $meta, 2, '49.80', 'USD', $payment_method );
		if ( 'customer change' !== $route ) {
			$entry = $this->get_entry( 'get_subscription' );
			if ( 'job reads canceled' === $route ) {
				$entry['response']['body']['status'] = 'canceled';
			}
			$this->queue_entry( $entry );
		}
		if ( 'job reads canceled' !== $route ) {
			$this->queue_entry( $this->get_entry( 'cancel_subscription' ) );
		}

		if ( 'customer change' === $route ) {
			$this->sut->maybe_cancel_subscription( $subscription, $payment_method );
		} else {
			$this->sut->handle_retry_cancel_subscription( $subscription->get_id(), 'job' === $route ? 0 : 2 );
		}
		$requests = $this->http_client->request_count;
		$this->sut->handle_retry_cancel_subscription( $subscription->get_id(), 0 );
		$this->sut->maybe_cancel_subscription( wc_get_order( $subscription->get_id() ), $payment_method );
		$this->sut->request_stripe_subscription_cancel( wc_get_order( $subscription->get_id() ) );

		$saved = wc_get_order( $subscription->get_id() );
		$this->assertSame( self::MAIN_SUBSCRIPTION_ID, $saved->get_meta( '_cancelled_wcpay_subscription_id', true ) );
		$this->assertSame(
			array( 'This subscription\'s payment method was changed, so its Stripe Billing subscription (' . self::MAIN_SUBSCRIPTION_ID . ') was cancelled and Stripe Billing no longer charges the customer for it. Future renewals are paid with the payment method now set on this subscription, or by the customer if it is set to Manual Renewal.' ),
			$this->get_notes_starting_with( $saved, self::CANCELLED_NOTE_START )
		);
		$this->assertSame( $requests, $this->http_client->request_count, 'Nothing more is sent once the ID moved.' );
		$this->assertNull( $this->get_retry_cancel_delay( $subscription, 0 ) );
	}

	/**
	 * Cancels Stripe confirms for a subscription moved off WooPayments, by where it moved and how the cancel ran. A customer's
	 * change fires WooCommerce Subscriptions' payment-method-updated-from hook (maybe_cancel_subscription()); an admin's
	 * change, the only way to Manual Renewal, only saves, and the save asks the cancel job (handle_retry_cancel_subscription()).
	 *
	 * @return array<string,array{string,string}>
	 */
	public function provider_confirmed_cancels_off_woopayments(): array {
		return array(
			'another gateway, cancelled on the customer\'s change' => array( 'customer change', 'stripe' ),
			'another gateway, cancelled by the job' => array( 'job', 'stripe' ),
			'Manual Renewal, cancelled by the job'  => array( 'job', '' ),
			'Manual Renewal, read canceled on a later attempt' => array( 'job reads canceled', '' ),
			'another WooPayments payment method, cancelled on the customer\'s change' => array( 'customer change', 'woocommerce_payments_sepa_debit' ),
			'another WooPayments payment method, cancelled by the job' => array( 'job', 'woocommerce_payments_sepa_debit' ),
		);
	}

	/**
	 * @testdox A cancel retry that finds the Stripe subscription already canceled counts it as cancelled, without another cancel: $_dataName.
	 * @testWith ["moved off WooPayments", "stripe", "active", "_cancelled_wcpay_subscription_id"]
	 *           ["cancelled on WooPayments", "woocommerce_payments", "cancelled", "_wcpay_subscription_id"]
	 *
	 * @param string $name            Case name.
	 * @param string $payment_method  Subscription payment method.
	 * @param string $status          Subscription status.
	 * @param string $expected_id_key Meta key that holds the Stripe ID afterwards.
	 */
	public function test_a_cancel_retry_counts_an_already_canceled_stripe_subscription( string $name, string $payment_method, string $status, string $expected_id_key ): void {
		unset( $name );
		$subscription = $this->create_subscription( array( '_wcpay_subscription_id' => self::MAIN_SUBSCRIPTION_ID ), 2, '49.80', 'USD', $payment_method );
		$subscription->set_status( $status );
		$subscription->save();
		$entry                               = $this->get_entry( 'get_subscription' );
		$entry['response']['body']['status'] = 'canceled';
		$this->queue_entry( $entry );

		$this->sut->handle_retry_cancel_subscription( $subscription->get_id(), 2 );

		$this->assertSame( array( array( 'GET', '/sites/4/wcpay/subscriptions/' . self::MAIN_SUBSCRIPTION_ID . '?test_mode=1', null ) ), $this->get_requests() );
		$this->assertSame( self::MAIN_SUBSCRIPTION_ID, wc_get_order( $subscription->get_id() )->get_meta( $expected_id_key, true ) );
		$this->assertNull( $this->get_retry_cancel_delay( $subscription, 3 ) );
	}

	/**
	 * @testdox A cancel retry for an expired incomplete Stripe subscription ends after the status read.
	 */
	public function test_expired_first_invoice_cancel(): void {
		$subscription = $this->create_subscription( array( '_wcpay_subscription_id' => self::MAIN_SUBSCRIPTION_ID ) );
		$subscription->update_status( 'cancelled' );
		$notes                               = wc_get_order_notes( array( 'order_id' => $subscription->get_id() ) );
		$entry                               = $this->get_entry( 'get_subscription' );
		$entry['response']['body']['status'] = 'incomplete_expired';
		$this->queue_entry( $entry );

		$this->sut->handle_retry_cancel_subscription( $subscription->get_id(), 1 );

		$this->assertSame( array( 'GET' ), array_column( $this->get_requests(), 0 ) );
		$this->assertCount( count( $notes ), wc_get_order_notes( array( 'order_id' => $subscription->get_id() ) ) );
		$this->assertNull( $this->get_retry_cancel_delay( $subscription, 2 ) );
	}

	/**
	 * @testdox Saving a Stripe-billed subscription with the payment method $_dataName asks the cancel job for its first attempt, due now, and sends nothing to the platform.
	 *
	 * The edit screen, the REST writers and code all end in set_payment_method() and save(); none fires the updated-from hook.
	 * Manual Renewal is an empty method (WooCommerce Subscriptions 9.4.1 class-wc-subscription.php:2531-2535).
	 *
	 * @testWith ["", "Manual Renewal"]
	 *           ["manual", "the REST writers' manual"]
	 *           ["stripe", "another gateway"]
	 *           ["woocommerce_payments_klarna", "another WooPayments method"]
	 *
	 * @param string $payment_method Payment method saved.
	 * @param string $name           Case name.
	 */
	public function test_a_save_off_woopayments_asks_for_the_stripe_cancel( string $payment_method, string $name ): void {
		unset( $name );
		$subscription = $this->create_subscription( array( '_wcpay_subscription_id' => self::MAIN_SUBSCRIPTION_ID ) );
		$this->register_stripe_billing_module();

		$subscription->set_payment_method( $payment_method );
		$subscription->save();

		$this->assertSame( 0, $this->http_client->request_count, 'No platform request inside the save.' );
		$jobs = $this->get_pending_cancel_jobs( $subscription );
		$this->assertCount( 1, $jobs, 'One job.' );
		$this->assertSame( 0, $jobs[0]['args']['retry'], 'For the first attempt.' );
		$this->assertEqualsWithDelta( time(), $jobs[0]['due'], 5, 'Due now.' );
		$this->assertSame( self::MAIN_SUBSCRIPTION_ID, wc_get_order( $subscription->get_id() )->get_meta( '_wcpay_subscription_id', true ), 'The ID stays until Stripe confirms.' );
	}

	/**
	 * @testdox Saving a subscription $_dataName asks for nothing, and the subscription service is never resolved.
	 *
	 * The service is replaced by an object of another class, so resolving it fails the test: the save reads only the
	 * payment method and meta in memory.
	 *
	 * @testWith ["on WooPayments with a Stripe ID", "woocommerce_payments", "sub_1"]
	 *           ["on WooPayments without a Stripe ID", "woocommerce_payments", ""]
	 *           ["on another gateway without a Stripe ID", "stripe", ""]
	 *
	 * @param string $name                  Case name.
	 * @param string $payment_method        Payment method saved.
	 * @param string $wcpay_subscription_id Stripe subscription ID it holds.
	 */
	public function test_a_save_that_is_not_off_stripe_billing_asks_nothing( string $name, string $payment_method, string $wcpay_subscription_id ): void {
		unset( $name );
		$subscription = $this->create_subscription( '' !== $wcpay_subscription_id ? array( '_wcpay_subscription_id' => $wcpay_subscription_id ) : array() );
		$this->register_stripe_billing_module();
		wc_get_container()->replace( StripeBillingSubscriptionService::class, new \stdClass() );

		$subscription->set_payment_method( $payment_method );
		$subscription->update_meta_data( '_billing_interval', '2' );
		$subscription->save();

		$this->assertSame( array(), $this->get_pending_cancel_jobs( $subscription ) );
		$this->assertSame( 0, $this->http_client->request_count );
	}

	/**
	 * @testdox Saving a Stripe-billed subscription still on WooPayments asks nothing when it is $_dataName: only the payment method counts on a save.
	 *
	 * A status cancel never moves the ID, so an ended status would ask on every later save. Subscriptions counts
	 * pending-cancel as ended, which would end a Stripe subscription set to stop at period end. The manual flag with the
	 * method still WooPayments is what a manual renewal paid with a saved card leaves, and Stripe bills that subscription.
	 *
	 * @testWith ["cancelled", "cancelled", false]
	 *           ["expired", "expired", false]
	 *           ["pending cancellation", "pending-cancel", false]
	 *           ["active with the manual flag", "active", true]
	 *
	 * @param string $name      Case name.
	 * @param string $status    Status saved.
	 * @param bool   $is_manual Whether the manual flag is set.
	 */
	public function test_a_save_on_woopayments_asks_nothing_whatever_the_status_or_flag( string $name, string $status, bool $is_manual ): void {
		unset( $name );
		$subscription = $this->create_subscription( array( '_wcpay_subscription_id' => self::MAIN_SUBSCRIPTION_ID ) );
		$this->register_stripe_billing_module();

		$subscription->set_status( $status );
		$subscription->set_requires_manual_renewal( $is_manual );
		$subscription->save();

		$this->assertSame( array(), $this->get_pending_cancel_jobs( $subscription ) );
		$this->assertSame( 0, $this->http_client->request_count );
	}

	/**
	 * @testdox Saving a subscription off WooPayments asks no second job while a cancel job at attempt $retry is $state.
	 *
	 * A pending job stays the same action, due when it was: the save neither adds a job nor replaces it with one due now.
	 *
	 * @testWith [0, "pending"]
	 *           [1, "pending"]
	 *           [2, "pending"]
	 *           [3, "pending"]
	 *           [1, "running"]
	 *
	 * @param int    $retry Attempt of the existing job.
	 * @param string $state `pending` or `running`.
	 */
	public function test_a_save_asks_nothing_while_a_cancel_job_is_pending_or_running( int $retry, string $state ): void {
		$subscription = $this->create_subscription( array( '_wcpay_subscription_id' => self::MAIN_SUBSCRIPTION_ID ) );
		$this->register_stripe_billing_module();
		$due       = time() + HOUR_IN_SECONDS;
		$args      = array(
			'subscription_id' => $subscription->get_id(),
			'retry'           => $retry,
		);
		$action_id = as_schedule_single_action( $due, StripeBillingSubscriptionService::RETRY_CANCEL_ACTION, $args, 'woocommerce_payments' );
		if ( 'running' === $state ) {
			\ActionScheduler::store()->log_execution( $action_id );
		}

		$subscription->set_payment_method( 'stripe' );
		$subscription->save();

		$expected = 'pending' === $state ? array(
			array(
				'id'   => $action_id,
				'args' => $args,
				'due'  => $due,
			),
		) : array();
		$this->assertSame( $expected, $this->get_pending_cancel_jobs( $subscription ), 'Only the existing job, as it was.' );
	}

	/**
	 * @testdox Saving a subscription off WooPayments after its attempts gave up starts a new run at the first attempt.
	 */
	public function test_a_save_after_a_give_up_starts_a_new_run(): void {
		$subscription = $this->create_subscription( array( '_wcpay_subscription_id' => self::MAIN_SUBSCRIPTION_ID ), 2, '49.80', 'USD', 'stripe' );
		$this->register_stripe_billing_module();
		$last_attempt = as_schedule_single_action(
			time() - HOUR_IN_SECONDS,
			StripeBillingSubscriptionService::RETRY_CANCEL_ACTION,
			array(
				'subscription_id' => $subscription->get_id(),
				'retry'           => 3,
			),
			'woocommerce_payments'
		);
		\ActionScheduler::store()->mark_complete( $last_attempt );

		$subscription->set_billing_email( 'renewed@example.com' );
		$subscription->save();

		$jobs = $this->get_pending_cancel_jobs( $subscription );
		$this->assertCount( 1, $jobs );
		$this->assertSame( 0, $jobs[0]['args']['retry'] );
	}

	/**
	 * @testdox A save that throws $_dataName asks for nothing: the ask runs only after a save that completes.
	 *
	 * Core catches the exception, so save() returns. Before the write, nothing is stored. After it, the new method is stored
	 * but the save did not complete; the next save or invoice asks instead.
	 *
	 * @testWith ["before the write", "woocommerce_before_subscription_object_save", "woocommerce_payments"]
	 *           ["after the write", "woocommerce_update_order", "stripe"]
	 *
	 * @param string $name            Case name.
	 * @param string $hook            Hook that throws: the last listener before the data store write, or the data store's own.
	 * @param string $expected_stored Payment method stored afterwards.
	 */
	public function test_a_save_that_throws_asks_for_nothing( string $name, string $hook, string $expected_stored ): void {
		unset( $name );
		$subscription = $this->create_subscription( array( '_wcpay_subscription_id' => self::MAIN_SUBSCRIPTION_ID ) );
		$this->register_stripe_billing_module();
		$fail = static function (): void {
			throw new \Exception( 'Save failed.' );
		};
		add_action( $hook, $fail, PHP_INT_MAX );

		try {
			$subscription->set_payment_method( 'stripe' );
			$subscription->save();
		} finally {
			remove_action( $hook, $fail, PHP_INT_MAX );
		}

		$this->assertNotNull( $subscription->get_last_save_error(), 'The save failed.' );
		$this->assertSame( $expected_stored, wc_get_order( $subscription->get_id() )->get_payment_method() );
		$this->assertSame( array(), $this->get_pending_cancel_jobs( $subscription ) );
	}

	/**
	 * @testdox The first attempt of the cancel job $_dataName.
	 *
	 * It reads the Stripe subscription, then cancels it. A failure keeps the ID and asks for the second attempt on the
	 * webhook retry cadence, so a run has four attempts.
	 *
	 * @testWith ["moves the ID once Stripe confirms", true]
	 *           ["keeps the ID and asks for attempt 2 in a minute when Stripe refuses", false]
	 *
	 * @param string $name      Case name.
	 * @param bool   $confirmed Whether Stripe confirms the cancel.
	 */
	public function test_the_first_cancel_attempt_of_the_job( string $name, bool $confirmed ): void {
		unset( $name );
		$subscription = $this->create_subscription( array( '_wcpay_subscription_id' => self::MAIN_SUBSCRIPTION_ID ), 2, '49.80', 'USD', 'stripe' );
		$this->queue_entry( $this->get_entry( 'get_subscription' ) );
		if ( $confirmed ) {
			$this->queue_entry( $this->get_entry( 'cancel_subscription' ) );
		} else {
			$this->queue_platform_failure();
		}

		$this->sut->handle_retry_cancel_subscription( $subscription->get_id(), 0 );

		$path = '/sites/4/wcpay/subscriptions/' . self::MAIN_SUBSCRIPTION_ID . '?test_mode=1';
		$this->assertSame( array( array( 'GET', $path ), array( 'DELETE', $path ) ), array_map( static fn( array $request ): array => array_slice( $request, 0, 2 ), $this->get_requests() ) );
		$saved = wc_get_order( $subscription->get_id() );
		$this->assertSame( self::MAIN_SUBSCRIPTION_ID, $saved->get_meta( $confirmed ? '_cancelled_wcpay_subscription_id' : '_wcpay_subscription_id', true ) );
		if ( $confirmed ) {
			$this->assertNull( $this->get_retry_cancel_delay( $subscription, 1 ) );
		} else {
			$this->assertEqualsWithDelta( MINUTE_IN_SECONDS, $this->get_retry_cancel_delay( $subscription, 1 ), 5 );
		}
	}

	/**
	 * @testdox A failed customer payment method change and the save's own first attempt leave one second attempt pending.
	 *
	 * Subscriptions saves the new method (class-wc-subscriptions-change-payment-gateway.php:601), which asks the job for
	 * its first attempt, then fires the updated-from hook (:607), whose inline cancel fails and asks for the second. The
	 * job's own failed first attempt asks for the same second attempt, and the scheduler keeps the earlier one.
	 */
	public function test_a_failed_customer_change_and_its_first_job_leave_one_second_attempt(): void {
		$subscription = $this->create_subscription( array( '_wcpay_subscription_id' => self::MAIN_SUBSCRIPTION_ID ) );
		$this->register_stripe_billing_module();
		$this->queue_platform_failure();
		$this->queue_entry( $this->get_entry( 'get_subscription' ) );
		$this->queue_platform_failure();

		$subscription->set_payment_method( 'stripe' );
		$subscription->save();
		do_action( 'woocommerce_subscription_payment_method_updated_from_woocommerce_payments', $subscription, 'stripe' );
		do_action( StripeBillingSubscriptionService::RETRY_CANCEL_ACTION, $subscription->get_id(), 0 );

		$second = as_get_scheduled_actions(
			array(
				'hook'     => StripeBillingSubscriptionService::RETRY_CANCEL_ACTION,
				'args'     => array(
					'subscription_id' => $subscription->get_id(),
					'retry'           => 1,
				),
				'group'    => 'woocommerce_payments',
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			),
			'ids'
		);
		$this->assertCount( 1, $second );
		$this->assertSame( 3, $this->http_client->request_count, 'The inline cancel, then the job\'s read and cancel.' );
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

		$entry                               = $this->get_entry( 'charge_invoice_charging' );
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
		// The recorded charge collected 10.00 USD.
		$renewal_order->set_total( '10.00' );
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
		$lock_token = wc_get_container()->get( OrderPaymentLock::class )->claim( $saved_order, new WooPaymentsPersistenceVocabulary(), null, 'test' );
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
		$this->queue_entry( $this->get_entry( 'charge_invoice_charging' ) );
		$subscription  = $this->create_subscription(
			array(
				'_wcpay_subscription_id'    => self::MAIN_SUBSCRIPTION_ID,
				'_wcpay_pending_invoice_id' => self::MAIN_INVOICE_ID,
			)
		);
		$renewal_order = \WC_Helper_Order::create_order();
		$renewal_order->update_meta_data( '_wcpay_billing_invoice_id', self::MAIN_INVOICE_ID );
		$renewal_order->set_status( 'failed' );
		// The recorded charge collected 10.00 USD.
		$renewal_order->set_total( '10.00' );
		$renewal_order->save();
		wc_get_container()->get( OrderPaymentLock::class )->claim( $renewal_order, new WooPaymentsPersistenceVocabulary(), 'pi_webhookRecordingTheRenewal', 'payment operation' );
		$payments_completed = did_action( 'woocommerce_payment_complete' );
		$token              = $this->create_token();

		$this->sut->maybe_attempt_payment_for_subscription( $subscription, $token );

		$this->assertTrue( wc_get_order( $renewal_order->get_id() )->needs_payment(), 'The webhook completes the order, not this request.' );
		$this->assertSame( 0, did_action( 'woocommerce_payment_complete' ) - $payments_completed );
		$this->assertSame( array( $token->get_id() ), array_map( 'absint', wc_get_order( $renewal_order->get_id() )->get_payment_tokens() ), 'The new token is on the renewal before the lock is claimed.' );
		$this->assertSame( 1, wc_notice_count( 'success' ), 'The invoice was paid with the new payment method.' );
		$this->assertTrue( \WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment, 'The change request flag is untouched.' );
	}

	/**
	 * @testdox A paid charge whose invoice does not match the renewal completes nothing and shows no notice, and the new token is on the renewal.
	 *
	 * Client 11.3.0 class-wc-payments-subscription-service.php:684-686 adds the token to the renewal once it exists, and
	 * :688-699 completes it, with the notice, only when the charge response's amount_paid and currency match; pinned by its
	 * test_maybe_attempt_payment_for_subscription_does_not_complete_order_without_matching_invoice().
	 */
	public function test_a_paid_charge_that_does_not_match_the_renewal_completes_nothing(): void {
		WooCommerceSubscriptionsDoubles::load_change_payment_gateway();
		$this->queue_entry( $this->get_entry( 'charge_invoice_charging' ) );
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
		$token              = $this->create_token();
		$payments_completed = did_action( 'woocommerce_payment_complete' );

		$this->sut->maybe_attempt_payment_for_subscription( $subscription, $token );

		$saved_order = wc_get_order( $renewal_order->get_id() );
		$this->assertSame( 'failed', $saved_order->get_status() );
		$this->assertSame( 0, did_action( 'woocommerce_payment_complete' ) - $payments_completed );
		$this->assertSame( array( $token->get_id() ), array_map( 'absint', $saved_order->get_payment_tokens() ) );
		$this->assertSame( 0, wc_notice_count( 'success' ) );
	}

	/**
	 * @testdox Paying a failed renewal decides on the renewal read again under the order payment lock: $_dataName.
	 *
	 * The charge is compared with the renewal read under the order payment lock. Client 11.3.0 completes it, with the notice,
	 * only when the charge matches the renewal it completes (class-wc-payments-subscription-service.php:688-697).
	 *
	 * @testWith ["10.00", "12.00", false]
	 *           ["12.00", "10.00", true]
	 *
	 * @param string $first_total   Renewal total at the first read.
	 * @param string $stored_total  Renewal total stored when the order payment lock is claimed.
	 * @param bool   $expected_paid Whether the renewal is completed, with the notice.
	 */
	public function test_a_paid_charge_is_matched_against_the_renewal_read_under_the_lock( string $first_total, string $stored_total, bool $expected_paid ): void {
		WooCommerceSubscriptionsDoubles::load_change_payment_gateway();
		$this->queue_entry( $this->get_entry( 'charge_invoice_charging' ) );
		$subscription  = $this->create_subscription(
			array(
				'_wcpay_subscription_id'    => self::MAIN_SUBSCRIPTION_ID,
				'_wcpay_pending_invoice_id' => self::MAIN_INVOICE_ID,
			)
		);
		$renewal_order = \WC_Helper_Order::create_order();
		$renewal_order->update_meta_data( '_wcpay_billing_invoice_id', self::MAIN_INVOICE_ID );
		$renewal_order->set_status( 'failed' );
		$renewal_order->set_total( $first_total );
		$renewal_order->save();
		$renewal_id = $renewal_order->get_id();
		add_action(
			'woocommerce_payment_token_added_to_order',
			static function ( $order_id ) use ( $renewal_id, $stored_total ): void {
				if ( $renewal_id === (int) $order_id ) {
					$other = wc_get_order( $renewal_id );
					$other->set_total( $stored_total );
					$other->save();
				}
			}
		);

		$this->sut->maybe_attempt_payment_for_subscription( $subscription, $this->create_token() );

		$this->assertSame( $expected_paid, wc_get_order( $renewal_id )->is_paid() );
		$this->assertSame( $expected_paid ? 1 : 0, wc_notice_count( 'success' ) );
	}

	/**
	 * @testdox Paying a failed USD 10.49 renewal on a JPY store matches the charge against the stored total.
	 *
	 * The renewal is read again under the order payment lock before the charge is matched against it, here with the store's
	 * zero decimals in force. The match allows one cent per line, so a total read as 10 would not match 1049 cents. The
	 * recorded invoice charge, its amounts changed to 1049.
	 */
	public function test_a_paid_charge_is_matched_against_the_stored_total_on_a_store_with_fewer_decimals(): void {
		WooCommerceSubscriptionsDoubles::load_change_payment_gateway();
		$entry                                    = $this->get_entry( 'charge_invoice_charging' );
		$entry['response']['body']['amount_due']  = 1049;
		$entry['response']['body']['amount_paid'] = 1049;
		$entry['response']['body']['total']       = 1049;
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
		$renewal_order->set_total( '10.49' );
		$renewal_order->save();
		$this->register_storefront_multi_currency( 'JPY', array( 'USD' => '0.0067' ) );
		$this->assertSame( 0, wc_get_price_decimals(), 'Precondition: the store currency, JPY, is in force.' );

		$this->sut->maybe_attempt_payment_for_subscription( $subscription, $this->create_token() );

		$this->assertTrue( wc_get_order( $renewal_order->get_id() )->is_paid() );
		$this->assertSame( 1, wc_notice_count( 'success' ) );
	}

	/**
	 * @testdox Paying a failed renewal its invoice settled before the claim shows the notice only when the charge matches it: $_dataName.
	 *
	 * The paid invoice's webhook takes the order payment lock first: it pays a renewal the charge matches and holds one it
	 * does not. Client 11.3.0 shows the notice only when the charge matches (class-wc-payments-subscription-service.php:688-697).
	 *
	 * @testWith ["held by its invoice", "on-hold", "12.00", 0]
	 *           ["paid by its invoice", "processing", "10.00", 1]
	 *
	 * @param string $case_name        Case name.
	 * @param string $settled_status   Renewal status its invoice left before the claim.
	 * @param string $settled_total    Renewal total then.
	 * @param int    $expected_notices Success notices expected.
	 */
	public function test_a_renewal_its_invoice_settled_before_the_claim_shows_the_notice_only_on_a_match( string $case_name, string $settled_status, string $settled_total, int $expected_notices ): void {
		unset( $case_name );
		WooCommerceSubscriptionsDoubles::load_change_payment_gateway();
		$this->queue_entry( $this->get_entry( 'charge_invoice_charging' ) );
		$subscription  = $this->create_subscription(
			array(
				'_wcpay_subscription_id'    => self::MAIN_SUBSCRIPTION_ID,
				'_wcpay_pending_invoice_id' => self::MAIN_INVOICE_ID,
			)
		);
		$renewal_order = \WC_Helper_Order::create_order();
		$renewal_order->update_meta_data( '_wcpay_billing_invoice_id', self::MAIN_INVOICE_ID );
		$renewal_order->set_status( 'failed' );
		$renewal_order->set_total( '10.00' );
		$renewal_order->save();
		$renewal_id = $renewal_order->get_id();
		add_action(
			'woocommerce_payment_token_added_to_order',
			static function ( $order_id ) use ( $renewal_id, $settled_status, $settled_total ): void {
				if ( $renewal_id === (int) $order_id ) {
					$other = wc_get_order( $renewal_id );
					$other->set_total( $settled_total );
					$other->set_status( $settled_status );
					$other->save();
				}
			}
		);
		$token = $this->create_token();

		$this->sut->maybe_attempt_payment_for_subscription( $subscription, $token );

		$renewal = wc_get_order( $renewal_id );
		$this->assertSame( $settled_status, $renewal->get_status() );
		$this->assertSame( $expected_notices, wc_notice_count( 'success' ) );
		$this->assertContains( $token->get_id(), $renewal->get_payment_tokens() );
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
	 * Load the Stripe Billing module with WooCommerce Subscriptions active, its hook callbacks
	 * resolving this test's subscription service.
	 */
	private function register_stripe_billing_module(): void {
		$this->register_legacy_proxy_function_mocks(
			array(
				'class_exists' => static fn( $class_name, ...$args ) => 'WC_Subscriptions' === $class_name || class_exists( $class_name, ...$args ),
			)
		);
		wc_get_container()->replace( StripeBillingSubscriptionService::class, $this->sut );

		$module = new StripeBillingModule();
		$module->register();
	}

	/**
	 * Put a subscription on hold the way a store's own code would, one call above the status change.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @param string   $reason       Note added with the change.
	 */
	private function put_on_hold_from_custom_code( WC_Order $subscription, string $reason ): void {
		$subscription->update_status( 'on-hold', $reason );
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
	 * Queue a platform failure.
	 */
	private function queue_platform_failure(): void {
		$this->http_client->responses[] = $this->make_response(
			500,
			array(
				'code'    => 'wcpay_server_error',
				'message' => 'Rec T63 platform failure',
				'data'    => array( 'status' => 500 ),
			)
		);
	}

	/**
	 * Get how long after now a subscription's pending cancel retry runs, or null when none is pending.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @param int      $retry        Retry number.
	 * @return int|null
	 */
	private function get_retry_cancel_delay( WC_Order $subscription, int $retry ): ?int {
		$actions = as_get_scheduled_actions(
			array(
				'hook'   => StripeBillingSubscriptionService::RETRY_CANCEL_ACTION,
				'args'   => array(
					'subscription_id' => $subscription->get_id(),
					'retry'           => $retry,
				),
				'group'  => 'woocommerce_payments',
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			)
		);
		$action  = reset( $actions );

		return $action ? $action->get_schedule()->get_date()->getTimestamp() - time() : null;
	}

	/**
	 * Get a subscription's pending cancel jobs, one entry per action in action ID order, so duplicates show.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return array<int,array{id:int,args:array<string,mixed>,due:int}>
	 */
	private function get_pending_cancel_jobs( WC_Order $subscription ): array {
		$jobs = array();
		foreach ( as_get_scheduled_actions(
			array(
				'hook'     => StripeBillingSubscriptionService::RETRY_CANCEL_ACTION,
				'group'    => 'woocommerce_payments',
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			)
		) as $action_id => $action ) {
			$args = $action->get_args();
			if ( $subscription->get_id() === ( $args['subscription_id'] ?? null ) ) {
				$jobs[ (int) $action_id ] = array(
					'id'   => (int) $action_id,
					'args' => $args,
					'due'  => $action->get_schedule()->get_date()->getTimestamp(),
				);
			}
		}
		ksort( $jobs );

		return array_values( $jobs );
	}

	/**
	 * Get the notes of an order that start with a text.
	 *
	 * @param WC_Order $order Order or subscription.
	 * @param string   $text  Start of the notes.
	 * @return array<int,string>
	 */
	private function get_notes_starting_with( WC_Order $order, string $text ): array {
		return array_values( array_filter( $this->get_order_note_texts( $order ), static fn( string $note ): bool => 0 === strpos( $note, $text ) ) );
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
