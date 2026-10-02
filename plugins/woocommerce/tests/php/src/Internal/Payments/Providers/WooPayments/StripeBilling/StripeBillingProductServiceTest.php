<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingApi;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingProductService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeWooPaymentsHttpClient;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use WC_Product;
use WC_Unit_Test_Case;

/**
 * Stripe products for subscription products and one-time items.
 *
 * Expectations follow client 11.1.0 (`tests/unit/subscriptions/test-class-wc-payments-product-service.php` and
 * `includes/subscriptions/class-wc-payments-product-service.php`); platform answers are the local platform recordings
 * in `Fixtures/rec-t63-billing-api.json`.
 */
class StripeBillingProductServiceTest extends WC_Unit_Test_Case {

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

		$api_client = new WooPaymentsApiClient();
		$api_client->init( $this->http_client, $account_service );
		$api = new StripeBillingApi();
		$api->init( $api_client );

		$logger = new StripeBillingLogger();
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
	 * @testdox A product without a Stripe product gets one with its name and description, linked to the current account (client test_create_product, test_get_or_create_wcpay_product_id_will_create_product_if_not_exist).
	 */
	public function test_creates_the_stripe_product_when_the_product_has_none(): void {
		$entry   = $this->queue_recorded_responses( 'create_product' )[0];
		$product = $this->create_subscription_product( 'REC-T63 Coffee Box', 'REC-T63 monthly coffee box, Stripe Billing recording' );

		$result = $this->sut->get_or_create_wcpay_product_id( $product );

		$this->assertSame( self::RECORDED_PRODUCT_ID, $result );
		$requests = $this->get_requests();
		$this->assertCount( 1, $requests );
		$this->assertSame( array( 'POST', '/sites/4/wcpay/products', $entry['request']['body'] ), $requests[0] );
		$saved = wc_get_product( $product->get_id() );
		$this->assertSame( self::RECORDED_PRODUCT_ID, $saved->get_meta( '_wcpay_product_id_test' ) );
		$this->assertSame( self::RECORDED_ACCOUNT_ID, $saved->get_meta( '_wcpay_product_id_test_linked_to' ) );
		$this->assertSame( '157edb40778acb45ff5dce71451e7ff1', $saved->get_meta( '_wcpay_product_hash' ), 'The hash is md5 of description then name, as the client stores it.' );
	}

	/**
	 * @testdox A Stripe product linked to the current account is returned without a platform call (client test_get_or_create_wcpay_product_id_for_test and _for_live).
	 * @testWith [true, "_wcpay_product_id_test"]
	 *           [false, "_wcpay_product_id_live"]
	 *
	 * @param bool   $test_mode Mode asked for.
	 * @param string $meta_key  Meta key of that mode.
	 */
	public function test_returns_the_linked_stripe_product_without_a_request( bool $test_mode, string $meta_key ): void {
		$this->test_mode = false;
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
	 * @testdox A renamed subscription product is sent to the platform at the end of the request and its hash updated (client test_update_products_live_only).
	 */
	public function test_sends_a_renamed_product_at_the_end_of_the_request(): void {
		$entry   = $this->queue_recorded_responses( 'update_product' )[0];
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

		$this->assertSame( array( array( 'POST', '/sites/4/wcpay/products/' . self::RECORDED_PRODUCT_ID, $entry['request']['body'] ) ), $this->get_requests() );
		$this->assertSame( '3a7fe09e2b840163667f5f451ef9c1a8', wc_get_product( $product->get_id() )->get_meta( '_wcpay_product_hash' ) );
	}

	/**
	 * @testdox A renamed product is sent to both its live and its test Stripe products, each with its mode (client test_update_products_live_and_test).
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
	 * @testdox A subscription product saved several times is sent once, and other products are not sent (client maybe_schedule_product_create_or_update).
	 */
	public function test_sends_each_saved_subscription_product_once(): void {
		$this->queue_recorded_responses( 'create_product' );
		$product       = $this->create_subscription_product( 'REC-T63 Coffee Box', 'REC-T63 monthly coffee box, Stripe Billing recording' );
		$other_product = \WC_Helper_Product::create_simple_product();

		$this->sut->maybe_schedule_product_create_or_update( $product->get_id() );
		$this->sut->maybe_schedule_product_create_or_update( $product->get_id() );
		$this->sut->maybe_schedule_product_create_or_update( $other_product->get_id() );
		$this->sut->create_or_update_products();

		$requests = $this->get_requests();
		$this->assertCount( 1, $requests );
		$this->assertSame( '/sites/4/wcpay/products', $requests[0][1] );
		$this->assertSame( 'REC-T63 Coffee Box', $requests[0][2]['name'] );
	}

	/**
	 * @testdox Trashing a subscription product archives its legacy prices in both modes, forgets them, and archives its Stripe product (client test_archive_product, test_archive_price).
	 */
	public function test_trashing_archives_the_prices_and_the_stripe_product(): void {
		$this->test_mode = false;
		$price_entry     = $this->queue_recorded_responses( 'update_price', 'update_price', 'update_product' )[0];
		$product         = $this->create_subscription_product(
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
				array( 'POST', '/sites/4/wcpay/products/prices/' . self::TEST_PRICE_ID, $price_entry['request']['body'] ),
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
	 * @testdox Restoring a trashed subscription product reactivates its live and test Stripe products (client unarchive_product).
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
	 * @testdox A one-time item gets a Stripe product saved under its sanitized type, linked to the current account (client test_get_wcpay_product_id_for_item).
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
	 * @testdox An item type's Stripe product is reused for the current account and replaced for another one (client get_wcpay_product_id_for_item).
	 * @testWith ["acct_1TrY2nBzWlxcwgpP", "prod_VMl1fRBycUk6wP", 0]
	 *           ["acct_1PrevAcctS7k2Qm", "prod_VMl10VL0VK371N", 1]
	 *
	 * @param string $linked_account_id Account the saved Stripe product belongs to.
	 * @param string $expected          Stripe product ID used.
	 * @param int    $request_count     Platform requests sent.
	 */
	public function test_reuses_an_item_stripe_product_only_for_its_account( string $linked_account_id, string $expected, int $request_count ): void {
		$this->queue_recorded_responses( 'create_product' );
		update_option( '_wcpay_product_id_test_sign_up_fee', self::OTHER_PRODUCT_ID );
		update_option( '_wcpay_product_id_test_sign_up_fee_linked_to', $linked_account_id );

		$result = $this->sut->get_wcpay_product_id_for_item( 'sign_up_fee' );

		$this->assertSame( $expected, $result );
		$this->assertSame( $request_count, $this->http_client->request_count );
		$this->assertSame( $expected, get_option( '_wcpay_product_id_test_sign_up_fee' ) );
		$this->assertSame( self::RECORDED_ACCOUNT_ID, get_option( '_wcpay_product_id_test_sign_up_fee_linked_to' ) );
	}

	/**
	 * @testdox A duplicated product does not carry the Stripe product IDs, prices or hashes (client exclude_meta_wcpay_product).
	 */
	public function test_duplicates_leave_out_the_stripe_ids_and_hashes(): void {
		$result = $this->sut->exclude_meta_wcpay_product( array( '_existing_excluded_key' ) );

		$this->assertSame(
			array(
				'_existing_excluded_key',
				'_wcpay_product_hash',
				'_wcpay_product_id_live',
				'_wcpay_product_id_test',
				'_wcpay_product_price_hash',
				'_wcpay_product_price_id_live',
				'_wcpay_product_price_id_test',
			),
			$result
		);
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
