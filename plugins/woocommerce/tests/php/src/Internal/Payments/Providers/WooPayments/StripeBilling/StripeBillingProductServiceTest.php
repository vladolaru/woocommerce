<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsTransportLog;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingApi;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingProductService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeWooPaymentsHttpClient;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\ProviderTextLogAssertions;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\RecordingWcLogger;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\SubscriptionVariationProductDouble;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\VariableSubscriptionProductDouble;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use WC_Product;
use WC_Unit_Test_Case;

/**
 * Stripe products for subscription products and one-time items.
 *
 * Expectations follow client 11.1.0 (`tests/unit/subscriptions/test-class-wc-payments-product-service.php` and
 * `includes/subscriptions/class-wc-payments-product-service.php`); platform answers are the local platform recordings
 * in `Fixtures/rec-t63-billing-api.json`. Request bodies are written from the client's array literals, with `test_mode`
 * first as the client's `request()` adds it (`class-wc-payments-api-client.php:2635-2640`).
 */
class StripeBillingProductServiceTest extends WC_Unit_Test_Case {

	use ProviderTextLogAssertions;

	private const FIXTURE = __DIR__ . '/../Fixtures/rec-t63-billing-api.json';

	/**
	 * Blog and account the recordings were made on.
	 */
	private const RECORDED_BLOG_ID    = 4;
	private const RECORDED_ACCOUNT_ID = 'acct_1TrY2nBzWlxcwgpP';

	/**
	 * Stripe product the recorded `create_product`, `update_product` and `get_product_by_id` answer with.
	 */
	private const RECORDED_PRODUCT_ID = 'prod_VMl10VL0VK371N';

	/**
	 * Another recorded Stripe product, and the recorded legacy price.
	 */
	private const OTHER_PRODUCT_ID = 'prod_VMl1fRBycUk6wP';
	private const TEST_PRICE_ID    = 'price_1UM1VFBzWlxcwgpPfqFOGkZ2';
	private const LIVE_PRICE_ID    = 'price_1UM1WdBzWlxcwgpPTdu1MSjd';

	/**
	 * An account the store was connected to before.
	 */
	private const PREVIOUS_ACCOUNT_ID = 'acct_1PrevAcctS7k2Qm';

	/**
	 * The System Under Test.
	 *
	 * @var StripeBillingProductService
	 */
	private $sut;

	/**
	 * Fake platform transport.
	 *
	 * @var FakeWooPaymentsHttpClient
	 */
	private FakeWooPaymentsHttpClient $http_client;

	/**
	 * Whether the store is in test mode.
	 *
	 * @var bool
	 */
	private bool $test_mode = true;

	/**
	 * Whether the gateway's debug logging setting is on, as the WooPayments logger reads it.
	 *
	 * @var bool
	 */
	private bool $logging = false;

	/**
	 * Set up the service over a fake transport, connected to the recorded account.
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
		$account_service->method( 'is_test_mode_enabled' )->willReturnCallback( fn() => $this->test_mode );
		$account_service->method( 'is_test_mode_onboarding_enabled' )->willReturnCallback( fn() => $this->test_mode );
		$account_service->method( 'get_account_id' )->willReturn( self::RECORDED_ACCOUNT_ID );
		$account_service->method( 'get_gateway_setting' )->willReturnCallback( fn( string $key ) => 'enable_logging' === $key && $this->logging ? 'yes' : null );

		$api_client = new WooPaymentsApiClient();
		$api_client->init( $this->http_client, $account_service, wc_get_container()->get( WooPaymentsTransportLog::class ) );
		$api = new StripeBillingApi();
		$api->init( $api_client );

		$logger = new WooPaymentsLogger();
		$logger->init( $account_service );

		$this->sut = new StripeBillingProductService();
		$this->sut->init( $api, $account_service, $logger );
	}

	/**
	 * Clear the subscription product registry the doubles read.
	 */
	public function tearDown(): void {
		unset( $GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_PRODUCT_IDS ] );
		parent::tearDown();
	}

	/**
	 * @testdox A platform error $scenario is logged with its status and code, never its message.
	 * @testWith ["fetching an item's unlinked Stripe product", "Error occurred when fetching product : wcpay_product_id=prod_VMl10VL0VK371N, account_id=acct_1TrY2nBzWlxcwgpP"]
	 *           ["checking a product's unlinked Stripe product", "Error validating WooPayments product: product_id=%d, wcpay_product_id=prod_VMl10VL0VK371N, account_id=acct_1TrY2nBzWlxcwgpP"]
	 *           ["creating a Stripe product", "There was a problem creating the product #%d in WooPayments."]
	 *           ["updating a Stripe product", "There was a problem updating the product #%d in WooPayments."]
	 *           ["archiving a Stripe product", "There was a problem archiving the live product in WooPayments."]
	 *           ["unarchiving a Stripe product", "There was a problem unarchiving the live product in WooPayments."]
	 *           ["archiving a legacy price", "There was a problem archiving the live product price ID in WooPayments."]
	 *
	 * Client 11.1.0 appends the platform's message to each line (`class-wc-payments-product-service.php`).
	 *
	 * @param string $scenario Platform call that fails.
	 * @param string $expected Expected line, with the product ID for %d.
	 */
	public function test_platform_error_log_leaves_out_platform_text( string $scenario, string $expected ): void {
		$this->logging = true;
		$product       = null;
		switch ( $scenario ) {
			case "fetching an item's unlinked Stripe product":
				update_option( '_wcpay_product_id_test_shipping', self::RECORDED_PRODUCT_ID );
				$this->queue_platform_error();
				$this->queue_platform_error();
				break;
			case "checking a product's unlinked Stripe product":
				$product = $this->create_subscription_product( 'REC-T63 Coffee Box', 'REC-T63 monthly coffee box', array( '_wcpay_product_id_test' => self::RECORDED_PRODUCT_ID ) );
				$this->queue_platform_error();
				$this->queue_platform_error();
				break;
			case 'creating a Stripe product':
				$product = $this->create_subscription_product( 'REC-T63 Coffee Box', 'REC-T63 monthly coffee box' );
				$this->queue_platform_error();
				break;
			case 'updating a Stripe product':
				$product = $this->create_subscription_product(
					'REC-T63 Coffee Box Deluxe',
					'REC-T63 monthly coffee box, renamed',
					array(
						'_wcpay_product_id_test'           => self::RECORDED_PRODUCT_ID,
						'_wcpay_product_id_test_linked_to' => self::RECORDED_ACCOUNT_ID,
						'_wcpay_product_hash'              => '157edb40778acb45ff5dce71451e7ff1',
					)
				);
				$this->queue_platform_error();
				break;
			case 'archiving a Stripe product':
			case 'unarchiving a Stripe product':
				$this->test_mode = false;
				$product         = $this->create_subscription_product(
					'REC-T63 Coffee Box',
					'REC-T63 monthly coffee box',
					array(
						'_wcpay_product_id_live'           => self::RECORDED_PRODUCT_ID,
						'_wcpay_product_id_live_linked_to' => self::RECORDED_ACCOUNT_ID,
					)
				);
				$this->queue_platform_error();
				break;
			default:
				$this->test_mode = false;
				$product         = $this->create_subscription_product(
					'REC-T63 Coffee Box',
					'REC-T63 monthly coffee box',
					array(
						'_wcpay_product_id_live'           => self::RECORDED_PRODUCT_ID,
						'_wcpay_product_id_live_linked_to' => self::RECORDED_ACCOUNT_ID,
						'_wcpay_product_price_id_live'     => self::LIVE_PRICE_ID,
					)
				);
				$this->queue_platform_error();
				$this->queue_platform_error();
		}
		$logger = RecordingWcLogger::install();

		switch ( $scenario ) {
			case "fetching an item's unlinked Stripe product":
				try {
					$this->sut->get_wcpay_product_id_for_item( 'shipping' );
				} catch ( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException $exception ) {
					// The Stripe product the fetch falls back to creating fails too, and that failure is the caller's.
					unset( $exception );
				}
				break;
			case "checking a product's unlinked Stripe product":
			case 'creating a Stripe product':
				$this->sut->get_or_create_wcpay_product_id( $product );
				break;
			case 'updating a Stripe product':
				$this->sut->maybe_schedule_product_create_or_update( $product->get_id() );
				$this->sut->create_or_update_products();
				break;
			case 'unarchiving a Stripe product':
				$this->sut->maybe_unarchive_product( $product->get_id() );
				break;
			default:
				$this->sut->maybe_archive_product( $product->get_id() );
		}

		$context = $this->get_logged_context( $logger, sprintf( $expected, null === $product ? 0 : $product->get_id() ) );
		$this->assertSame( array( 404, 'resource_missing' ), array( $context['http_status'], $context['error_code'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * @testdox A product without a Stripe product gets one with its name and description, linked to the current account (client test_create_product, test_get_or_create_wcpay_product_id_will_create_product_if_not_exist, `class-wc-payments-product-service.php:387-397`, `:703-708`).
	 */
	public function test_creates_the_stripe_product_when_the_product_has_none(): void {
		$this->queue_recorded_responses( 'create_product' );
		$product = $this->create_subscription_product( 'REC-T63 Coffee Box', 'REC-T63 monthly coffee box, Stripe Billing recording' );

		$result = $this->sut->get_or_create_wcpay_product_id( $product );

		$this->assertSame( self::RECORDED_PRODUCT_ID, $result );
		$requests = $this->get_requests();
		$this->assertCount( 1, $requests );
		$this->assertSame(
			array(
				'POST',
				'/sites/4/wcpay/products',
				array(
					'test_mode'   => true,
					'description' => 'REC-T63 monthly coffee box, Stripe Billing recording',
					'name'        => 'REC-T63 Coffee Box',
				),
			),
			$requests[0]
		);
		$saved = wc_get_product( $product->get_id() );
		$this->assertSame( self::RECORDED_PRODUCT_ID, $saved->get_meta( '_wcpay_product_id_test' ) );
		$this->assertSame( self::RECORDED_ACCOUNT_ID, $saved->get_meta( '_wcpay_product_id_test_linked_to' ) );
		$this->assertSame( '157edb40778acb45ff5dce71451e7ff1', $saved->get_meta( '_wcpay_product_hash' ), 'The hash is md5 of description then name, as the client stores it.' );
	}

	/**
	 * @testdox A Stripe product linked to the current account is returned in the store's mode without a platform call (client test_get_or_create_wcpay_product_id_for_test and _for_live, `class-wc-payments-product-service.php:275-277`).
	 * @testWith [true, "_wcpay_product_id_test"]
	 *           [false, "_wcpay_product_id_live"]
	 *
	 * @param bool   $test_mode Mode of the store, and the mode asked for.
	 * @param string $meta_key  Meta key of that mode.
	 */
	public function test_returns_the_linked_stripe_product_without_a_request( bool $test_mode, string $meta_key ): void {
		// In the store's mode a link the service does not accept makes it create a Stripe product, so the link check shows.
		$this->test_mode = $test_mode;
		$product         = $this->create_subscription_product(
			'REC-T63 Coffee Box',
			'REC-T63 monthly coffee box, Stripe Billing recording',
			array(
				$meta_key                => self::RECORDED_PRODUCT_ID,
				$meta_key . '_linked_to' => self::RECORDED_ACCOUNT_ID,
			)
		);

		$result = $this->sut->get_or_create_wcpay_product_id( $product, $test_mode );

		$this->assertSame( self::RECORDED_PRODUCT_ID, $result );
		$this->assertSame( 0, $this->http_client->request_count );
	}

	/**
	 * @testdox A Stripe product linked to another account is replaced by a new one for the current account (client test_create_new_product_for_different_account).
	 */
	public function test_replaces_a_stripe_product_of_another_account(): void {
		$this->queue_recorded_responses( 'create_product' );
		$product = $this->create_subscription_product(
			'REC-T63 Coffee Box',
			'REC-T63 monthly coffee box, Stripe Billing recording',
			array(
				'_wcpay_product_id_test'           => self::OTHER_PRODUCT_ID,
				'_wcpay_product_id_test_linked_to' => self::PREVIOUS_ACCOUNT_ID,
			)
		);

		$result = $this->sut->get_or_create_wcpay_product_id( $product );

		$this->assertSame( self::RECORDED_PRODUCT_ID, $result );
		$this->assertSame( 1, $this->http_client->request_count );
		$saved = wc_get_product( $product->get_id() );
		$this->assertSame( self::RECORDED_PRODUCT_ID, $saved->get_meta( '_wcpay_product_id_test' ) );
		$this->assertSame( self::RECORDED_ACCOUNT_ID, $saved->get_meta( '_wcpay_product_id_test_linked_to' ) );
	}

	/**
	 * @testdox A Stripe product saved before account linking is kept and linked when the current account has it (client has_wcpay_product_id legacy case).
	 */
	public function test_links_a_stripe_product_saved_before_account_linking(): void {
		$this->queue_recorded_responses( 'get_product_by_id' );
		$product = $this->create_subscription_product(
			'REC-T63 Coffee Box',
			'REC-T63 monthly coffee box, Stripe Billing recording',
			array( '_wcpay_product_id_test' => self::RECORDED_PRODUCT_ID )
		);

		$result = $this->sut->get_or_create_wcpay_product_id( $product );

		$this->assertSame( self::RECORDED_PRODUCT_ID, $result );
		$requests = $this->get_requests();
		$this->assertCount( 1, $requests, 'Only the existence check is sent; no new Stripe product is created.' );
		$this->assertSame( array( 'GET', '/sites/4/wcpay/products/' . self::RECORDED_PRODUCT_ID . '?test_mode=1', null ), $requests[0] );
		$this->assertSame( self::RECORDED_ACCOUNT_ID, wc_get_product( $product->get_id() )->get_meta( '_wcpay_product_id_test_linked_to' ) );
	}

	/**
	 * @testdox A renamed subscription product is sent to the platform at the end of the request and its hash updated (client test_update_products_live_only, `class-wc-payments-product-service.php:451-465`).
	 */
	public function test_sends_a_renamed_product_at_the_end_of_the_request(): void {
		$this->queue_recorded_responses( 'update_product' );
		$product = $this->create_subscription_product(
			'REC-T63 Coffee Box Deluxe',
			'REC-T63 monthly coffee box, renamed',
			array(
				'_wcpay_product_id_test'           => self::RECORDED_PRODUCT_ID,
				'_wcpay_product_id_test_linked_to' => self::RECORDED_ACCOUNT_ID,
				'_wcpay_product_hash'              => '157edb40778acb45ff5dce71451e7ff1',
			)
		);

		$this->sut->maybe_schedule_product_create_or_update( $product->get_id() );
		$this->assertSame( 0, $this->http_client->request_count, 'Nothing is sent before the end of the request.' );
		$this->sut->create_or_update_products();

		$this->assertSame(
			array(
				array(
					'POST',
					'/sites/4/wcpay/products/' . self::RECORDED_PRODUCT_ID,
					array(
						'test_mode'   => true,
						'description' => 'REC-T63 monthly coffee box, renamed',
						'name'        => 'REC-T63 Coffee Box Deluxe',
					),
				),
			),
			$this->get_requests()
		);
		$this->assertSame( '3a7fe09e2b840163667f5f451ef9c1a8', wc_get_product( $product->get_id() )->get_meta( '_wcpay_product_hash' ) );
	}

	/**
	 * @testdox A renamed product is sent to both its live and its test Stripe products, each with its mode (client test_update_products_live_and_test, `class-wc-payments-product-service.php:460-463`).
	 */
	public function test_sends_a_renamed_product_to_both_modes(): void {
		$this->test_mode = false;
		$this->queue_recorded_responses( 'update_product', 'update_product' );
		$product = $this->create_subscription_product(
			'REC-T63 Coffee Box Deluxe',
			'REC-T63 monthly coffee box, renamed',
			array(
				'_wcpay_product_id_live'           => self::OTHER_PRODUCT_ID,
				'_wcpay_product_id_live_linked_to' => self::RECORDED_ACCOUNT_ID,
				'_wcpay_product_id_test'           => self::RECORDED_PRODUCT_ID,
				'_wcpay_product_id_test_linked_to' => self::RECORDED_ACCOUNT_ID,
			)
		);

		$this->sut->maybe_schedule_product_create_or_update( $product->get_id() );
		$this->sut->create_or_update_products();

		$product_data = array(
			'description' => 'REC-T63 monthly coffee box, renamed',
			'name'        => 'REC-T63 Coffee Box Deluxe',
		);
		$this->assertSame(
			array(
				array( 'POST', '/sites/4/wcpay/products/' . self::OTHER_PRODUCT_ID, array( 'test_mode' => false ) + $product_data ),
				array( 'POST', '/sites/4/wcpay/products/' . self::RECORDED_PRODUCT_ID, array( 'test_mode' => true ) + $product_data ),
			),
			$this->get_requests()
		);
	}

	/**
	 * @testdox A variation already queued in the request is not checked again when its product is saved again (client `class-wc-payments-product-service.php:345-348`).
	 */
	public function test_checks_a_queued_variation_once_per_request(): void {
		// The legacy Stripe product check fails, so the variation stays queued and a second check would ask the platform again.
		$this->http_client->responses[] = $this->make_error_response( 404, 'resource_missing', 'No such product: \'' . self::OTHER_PRODUCT_ID . '\'' );

		list( $variable_product ) = $this->create_variable_subscription_product(
			array(
				'24.90' => array( '_wcpay_product_id_test' => self::OTHER_PRODUCT_ID ),
			)
		);

		$this->sut->maybe_schedule_product_create_or_update( $variable_product->get_id() );
		$this->sut->maybe_schedule_product_create_or_update( $variable_product->get_id() );

		$this->assertSame( 1, $this->http_client->request_count );
	}

	/**
	 * @testdox Saving a product that is not a subscription sends nothing, even when it carries a legacy Stripe product (client `class-wc-payments-product-service.php:339-342`).
	 */
	public function test_saving_a_product_that_is_not_a_subscription_sends_nothing(): void {
		$product = \WC_Helper_Product::create_simple_product();
		$product->update_meta_data( '_wcpay_product_id_test', self::OTHER_PRODUCT_ID );
		$product->save();

		$this->sut->maybe_schedule_product_create_or_update( $product->get_id() );
		$this->sut->create_or_update_products();

		$this->assertSame( 0, $this->http_client->request_count );
	}

	/**
	 * @testdox A queued product that is no longer a subscription at the end of the request is not sent (client `class-wc-payments-product-service.php:431-433`).
	 */
	public function test_a_queued_product_that_stopped_being_a_subscription_is_not_sent(): void {
		$product = $this->create_subscription_product( 'REC-T63 Coffee Box', 'REC-T63 monthly coffee box, Stripe Billing recording' );
		$this->sut->maybe_schedule_product_create_or_update( $product->get_id() );

		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_PRODUCT_IDS ] = array();
		$this->sut->create_or_update_products();

		$this->assertSame( 0, $this->http_client->request_count );
	}

	/**
	 * @testdox A subscription product whose name and description match the saved hash sends nothing (client `class-wc-payments-product-service.php:355`, `:447-449`).
	 */
	public function test_an_unchanged_product_sends_nothing(): void {
		$product = $this->create_subscription_product(
			'REC-T63 Coffee Box',
			'REC-T63 monthly coffee box, Stripe Billing recording',
			array(
				'_wcpay_product_id_test'           => self::RECORDED_PRODUCT_ID,
				'_wcpay_product_id_test_linked_to' => self::RECORDED_ACCOUNT_ID,
				'_wcpay_product_hash'              => '157edb40778acb45ff5dce71451e7ff1',
			)
		);

		$this->sut->maybe_schedule_product_create_or_update( $product->get_id() );
		$this->sut->create_or_update_products();

		$this->assertSame( 0, $this->http_client->request_count );
	}

	/**
	 * @testdox A variable subscription syncs each priced variation with its own data and skips variations without a price (client `class-wc-payments-product-service.php:344-353`, `:719-721`).
	 */
	public function test_syncs_priced_variations_and_skips_unpriced_ones(): void {
		$this->queue_recorded_responses( 'create_product' );
		// A store can show variations without a price; the service must still skip them.
		add_filter( 'woocommerce_hide_invisible_variations', '__return_false' );
		list( $variable_product, $priced, $unpriced ) = $this->create_variable_subscription_product(
			array(
				'24.90' => array(),
				''      => array(),
			)
		);

		$this->sut->maybe_schedule_product_create_or_update( $variable_product->get_id() );
		$this->sut->create_or_update_products();

		$this->assertSame(
			array(
				array(
					'POST',
					'/sites/4/wcpay/products',
					array(
						'test_mode'   => true,
						'description' => 'REC-T63 variation priced at 24.90',
						'name'        => 'REC-T63 Coffee Plan',
					),
				),
			),
			$this->get_requests()
		);
		$this->assertSame( self::RECORDED_PRODUCT_ID, wc_get_product( $priced->get_id() )->get_meta( '_wcpay_product_id_test' ) );
		$this->assertFalse( wc_get_product( $unpriced->get_id() )->meta_exists( '_wcpay_product_id_test' ) );
		$this->assertFalse( wc_get_product( $variable_product->get_id() )->meta_exists( '_wcpay_product_id_test' ), 'The variable product itself has no Stripe product.' );
	}

	/**
	 * @testdox A product without a name gets no Stripe product (client `class-wc-payments-product-service.php:391`, `:907-911`).
	 */
	public function test_refuses_a_product_without_a_name(): void {
		$product = $this->create_subscription_product( 'REC-T63 Coffee Box', 'REC-T63 monthly coffee box, Stripe Billing recording' );
		$product->set_name( '' );

		$result = $this->sut->get_or_create_wcpay_product_id( $product );

		$this->assertSame( '', $result );
		$this->assertSame( 0, $this->http_client->request_count );
		$this->assertFalse( wc_get_product( $product->get_id() )->meta_exists( '_wcpay_product_id_test' ) );
	}

	/**
	 * @testdox Trashing a subscription product archives its legacy prices in both modes, forgets them, and archives its Stripe product (client test_archive_product, test_archive_price, `class-wc-payments-product-service.php:531-537`, `:577-584`, `:880-898`).
	 */
	public function test_trashing_archives_the_prices_and_the_stripe_product(): void {
		$this->test_mode = false;
		$this->queue_recorded_responses( 'update_price', 'update_price', 'update_product' );
		$product = $this->create_subscription_product(
			'REC-T63 Coffee Box',
			'REC-T63 monthly coffee box, Stripe Billing recording',
			array(
				'_wcpay_product_id_live'           => self::RECORDED_PRODUCT_ID,
				'_wcpay_product_id_live_linked_to' => self::RECORDED_ACCOUNT_ID,
				'_wcpay_product_price_id_live'     => self::LIVE_PRICE_ID,
				'_wcpay_product_price_id_test'     => self::TEST_PRICE_ID,
				'_wcpay_product_price_hash'        => 'c1f0e9b4d2a7c3e8f5b6a9d0e1f2a3b4',
			)
		);

		$this->sut->maybe_archive_product( $product->get_id() );

		$this->assertSame(
			array(
				array(
					'POST',
					'/sites/4/wcpay/products/prices/' . self::TEST_PRICE_ID,
					array(
						'test_mode' => true,
						'active'    => 'false',
					),
				),
				array(
					'POST',
					'/sites/4/wcpay/products/prices/' . self::LIVE_PRICE_ID,
					array(
						'test_mode' => false,
						'active'    => 'false',
					),
				),
				array(
					'POST',
					'/sites/4/wcpay/products/' . self::RECORDED_PRODUCT_ID,
					array(
						'test_mode' => false,
						'active'    => 'false',
					),
				),
			),
			$this->get_requests()
		);
		$saved = wc_get_product( $product->get_id() );
		$this->assertFalse( $saved->meta_exists( '_wcpay_product_price_id_live' ) );
		$this->assertFalse( $saved->meta_exists( '_wcpay_product_price_id_test' ) );
		$this->assertFalse( $saved->meta_exists( '_wcpay_product_price_hash' ) );
		$this->assertSame( self::RECORDED_PRODUCT_ID, $saved->get_meta( '_wcpay_product_id_live' ), 'The Stripe product ID is kept, so restoring the product reactivates it.' );
	}

	/**
	 * @testdox Restoring a trashed subscription product reactivates its live and test Stripe products (client unarchive_product, `class-wc-payments-product-service.php:558-564`).
	 */
	public function test_restoring_reactivates_the_stripe_products(): void {
		$this->queue_recorded_responses( 'update_product', 'update_product' );
		$product = $this->create_subscription_product(
			'REC-T63 Coffee Box',
			'REC-T63 monthly coffee box, Stripe Billing recording',
			array(
				'_wcpay_product_id_live'           => self::OTHER_PRODUCT_ID,
				'_wcpay_product_id_live_linked_to' => self::RECORDED_ACCOUNT_ID,
				'_wcpay_product_id_test'           => self::RECORDED_PRODUCT_ID,
				'_wcpay_product_id_test_linked_to' => self::RECORDED_ACCOUNT_ID,
			)
		);

		$this->sut->maybe_unarchive_product( $product->get_id() );

		$this->assertSame(
			array(
				array(
					'POST',
					'/sites/4/wcpay/products/' . self::OTHER_PRODUCT_ID,
					array(
						'test_mode' => false,
						'active'    => 'true',
					),
				),
				array(
					'POST',
					'/sites/4/wcpay/products/' . self::RECORDED_PRODUCT_ID,
					array(
						'test_mode' => true,
						'active'    => 'true',
					),
				),
			),
			$this->get_requests()
		);
	}

	/**
	 * @testdox A one-time item gets a Stripe product saved under its sanitized type, linked to the current account (client test_get_wcpay_product_id_for_item, `class-wc-payments-product-service.php:413-420`).
	 */
	public function test_creates_the_stripe_product_for_an_item_type(): void {
		$this->test_mode = false;
		$this->queue_recorded_responses( 'create_product' );

		$result = $this->sut->get_wcpay_product_id_for_item( 'Test Tax *&^ name' );

		$this->assertSame( self::RECORDED_PRODUCT_ID, $result );
		$this->assertFalse( get_option( '_wcpay_product_id_live_Test Tax *&^ name' ) );
		$this->assertSame( self::RECORDED_PRODUCT_ID, get_option( '_wcpay_product_id_live_test_tax__name' ) );
		$this->assertSame( self::RECORDED_ACCOUNT_ID, get_option( '_wcpay_product_id_live_test_tax__name_linked_to' ) );
		$this->assertSame(
			array(
				array(
					'POST',
					'/sites/4/wcpay/products',
					array(
						'test_mode'   => false,
						'description' => 'N/A',
						'name'        => 'Test_tax__name',
					),
				),
			),
			$this->get_requests()
		);
	}

	/**
	 * @testdox An item type's Stripe product saved before account linking is kept and linked when the current account has it (client get_wcpay_product_id_for_item).
	 */
	public function test_links_an_item_stripe_product_saved_before_account_linking(): void {
		$this->queue_recorded_responses( 'get_product_by_id' );
		update_option( '_wcpay_product_id_test_shipping', self::RECORDED_PRODUCT_ID );

		$result = $this->sut->get_wcpay_product_id_for_item( 'shipping' );

		$this->assertSame( self::RECORDED_PRODUCT_ID, $result );
		$this->assertSame( array( array( 'GET', '/sites/4/wcpay/products/' . self::RECORDED_PRODUCT_ID . '?test_mode=1', null ) ), $this->get_requests() );
		$this->assertSame( self::RECORDED_ACCOUNT_ID, get_option( '_wcpay_product_id_test_shipping_linked_to' ) );
	}

	/**
	 * @testdox An item type's Stripe product linked to the current account is reused without a platform call (client `class-wc-payments-product-service.php:212-218`).
	 */
	public function test_reuses_an_item_stripe_product_of_the_current_account(): void {
		update_option( '_wcpay_product_id_test_sign_up_fee', self::OTHER_PRODUCT_ID );
		update_option( '_wcpay_product_id_test_sign_up_fee_linked_to', self::RECORDED_ACCOUNT_ID );

		$result = $this->sut->get_wcpay_product_id_for_item( 'sign_up_fee' );

		$this->assertSame( self::OTHER_PRODUCT_ID, $result );
		$this->assertSame( 0, $this->http_client->request_count );
	}

	/**
	 * @testdox An item type's Stripe product linked to another account is replaced by a new one linked to the current account (client `class-wc-payments-product-service.php:212-215`, `:412-420`).
	 */
	public function test_replaces_an_item_stripe_product_of_another_account(): void {
		$this->queue_recorded_responses( 'create_product' );
		update_option( '_wcpay_product_id_test_sign_up_fee', self::OTHER_PRODUCT_ID );
		update_option( '_wcpay_product_id_test_sign_up_fee_linked_to', self::PREVIOUS_ACCOUNT_ID );

		$result = $this->sut->get_wcpay_product_id_for_item( 'sign_up_fee' );

		$this->assertSame( self::RECORDED_PRODUCT_ID, $result );
		$this->assertSame(
			array(
				array(
					'POST',
					'/sites/4/wcpay/products',
					array(
						'test_mode'   => true,
						'description' => 'N/A',
						'name'        => 'Sign_up_fee',
					),
				),
			),
			$this->get_requests()
		);
		$this->assertSame( self::RECORDED_PRODUCT_ID, get_option( '_wcpay_product_id_test_sign_up_fee' ) );
		$this->assertSame( self::RECORDED_ACCOUNT_ID, get_option( '_wcpay_product_id_test_sign_up_fee_linked_to' ) );
	}

	/**
	 * @testdox A billing cycle is valid only when it is no longer than one year (client is_valid_billing_cycle).
	 * @testWith ["year", 1, true]
	 *           ["year", 2, false]
	 *           ["month", 12, true]
	 *           ["month", 13, false]
	 *           ["week", 52, true]
	 *           ["week", 53, false]
	 *           ["day", 365, true]
	 *           ["day", 366, false]
	 *           ["day", 0, false]
	 *           ["fortnight", 1, false]
	 *
	 * @param string $period   Billing period.
	 * @param int    $interval Billing interval.
	 * @param bool   $expected Whether the cycle is valid.
	 */
	public function test_billing_cycles_are_limited_to_one_year( string $period, int $interval, bool $expected ): void {
		$this->assertSame( $expected, $this->sut->is_valid_billing_cycle( $period, $interval ) );
	}

	/**
	 * Create a product that WooCommerce Subscriptions reports as a subscription.
	 *
	 * @param string               $name        Product name.
	 * @param string               $description Product description.
	 * @param array<string,string> $meta        Product meta.
	 * @return WC_Product
	 */
	private function create_subscription_product( string $name, string $description, array $meta = array() ): WC_Product {
		$product = new \WC_Product_Simple();
		$product->set_name( $name );
		$product->set_description( $description );
		$product->set_regular_price( '24.90' );
		foreach ( $meta as $key => $value ) {
			$product->update_meta_data( $key, $value );
		}
		$product->save();

		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_PRODUCT_IDS ][] = $product->get_id();

		return $product;
	}

	/**
	 * Create a variable subscription with one variation per price, each registered as a subscription product.
	 *
	 * Wires the doubles the way WooCommerce Subscriptions stores these types: the variable and variation data stores.
	 *
	 * @param array<string,array<string,string>> $variations Variation meta keyed by regular price; an empty price leaves the variation unpriced.
	 * @return array<int,WC_Product> The variable product, then the variations in the given order.
	 */
	private function create_variable_subscription_product( array $variations ): array {
		add_filter(
			'woocommerce_data_stores',
			static fn( $stores ) => array_merge(
				$stores,
				array(
					'product-variable-subscription'  => 'WC_Product_Variable_Data_Store_CPT',
					'product-subscription_variation' => 'WC_Product_Variation_Data_Store_CPT',
				)
			)
		);
		add_filter(
			'woocommerce_product_class',
			static function ( $classname, $product_type ) {
				if ( 'variable-subscription' === $product_type ) {
					return VariableSubscriptionProductDouble::class;
				}

				return 'variation' === $product_type ? SubscriptionVariationProductDouble::class : $classname;
			},
			10,
			2
		);

		$variable_product = new VariableSubscriptionProductDouble();
		$variable_product->set_name( 'REC-T63 Coffee Plan' );
		$variable_product->set_description( 'REC-T63 coffee plan, two bag sizes' );
		$variable_product->save();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_PRODUCT_IDS ][] = $variable_product->get_id();

		$products = array( $variable_product );
		foreach ( $variations as $price => $meta ) {
			$variation = new SubscriptionVariationProductDouble();
			$variation->set_parent_id( $variable_product->get_id() );
			$variation->set_description( '' === (string) $price ? 'REC-T63 variation without a price' : 'REC-T63 variation priced at ' . $price );
			$variation->set_regular_price( (string) $price );
			foreach ( $meta as $key => $value ) {
				$variation->update_meta_data( $key, $value );
			}
			$variation->save();
			$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_PRODUCT_IDS ][] = $variation->get_id();
			$products[] = $variation;
		}

		return $products;
	}

	/**
	 * Build a platform error answer.
	 *
	 * @param int    $http_status HTTP status.
	 * @param string $code        Error code.
	 * @param string $message     Error message.
	 * @return array<string,mixed>
	 */
	private function make_error_response( int $http_status, string $code, string $message ): array {
		return array(
			'response' => array( 'code' => $http_status ),
			'headers'  => array( 'content-type' => 'application/json; charset=UTF-8' ),
			'body'     => wp_json_encode(
				array(
					'code'    => $code,
					'message' => $message,
					'data'    => array( 'status' => $http_status ),
				)
			),
		);
	}

	/**
	 * Queue the recorded platform answers of fixture entries, in order.
	 *
	 * @param string ...$pairs Fixture entry names.
	 * @return array<int,array<string,mixed>> The entries.
	 */
	private function queue_recorded_responses( string ...$pairs ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local test fixture.
		$fixture = json_decode( (string) file_get_contents( self::FIXTURE ), true );
		$entries = array();
		foreach ( $pairs as $pair ) {
			$matches = array_values( array_filter( $fixture['entries'], static fn( array $entry ) => $pair === $entry['pair'] ) );
			$this->assertNotEmpty( $matches, "Fixture entry $pair is missing." );
			$entries[]                      = $matches[0];
			$this->http_client->responses[] = array(
				'response' => array( 'code' => (int) $matches[0]['response']['http_status'] ),
				'headers'  => array( 'content-type' => $matches[0]['response']['content_type'] ),
				'body'     => wp_json_encode( $matches[0]['response']['body'] ),
			);
		}

		return $entries;
	}

	/**
	 * Queue the platform's answer to a failed request: HTTP 404 with the error envelope client 11.1.0 reads (error.code,
	 * error.message, error.type, as the platform forwards Stripe's; class-wc-payments-api-client.php:2852-2871), its message
	 * holding an email and a URL.
	 */
	private function queue_platform_error(): void {
		$this->http_client->responses[] = array(
			'response' => array( 'code' => 404 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'error' => array(
						'code'    => 'resource_missing',
						'message' => "No such customer: 'cus_123'; ask shopper@example.com, see https://pay.example.test/r?key=sk_test_leak123",
						'type'    => 'invalid_request_error',
					),
				)
			),
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
}
