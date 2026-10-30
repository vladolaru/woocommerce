<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\Jetpack\Connection\Rest_Authentication;
use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCheckoutAssets;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendStylesService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendTrackingController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\SessionController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\SessionService;
use ReflectionClass;
use WC_REST_Unit_Test_Case;
use WPAjaxDieContinueException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\Eligibility;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\AppearanceStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\AdaptedExtensions;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\StoreApiRequestHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\BlocksDataExtractor;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;

/**
 * Tests for the SessionController class.
 */
class SessionControllerTest extends WC_REST_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var SessionController
	 */
	private $sut;

	/**
	 * WooPay eligibility rules the controller under test reads.
	 *
	 * @var Eligibility|null
	 */
	private ?Eligibility $woopay_eligibility = null;

	/**
	 * WooPay checkout appearance store the controller under test writes.
	 *
	 * @var AppearanceStore|null
	 */
	private ?AppearanceStore $appearance_store = null;

	/**
	 * Handler of WooPay's Store API requests the controller under test binds its hooks to.
	 *
	 * @var StoreApiRequestHandler|null
	 */
	private ?StoreApiRequestHandler $store_api_request_handler = null;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->reset_frontend_surface_state();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		if ( $this->sut instanceof SessionController ) {
			remove_action( 'rest_api_init', array( $this->sut, 'register_routes' ) );
			foreach ( $this->get_expected_ajax_hooks() as $hook => $method ) {
				remove_action( $hook, array( $this->sut, $method ) );
			}
			foreach ( $this->get_expected_frontend_hooks() as $hook => $method ) {
				remove_action( $hook, array( $this->sut, $method ) );
			}
			remove_filter( 'wcpay_metadata_from_order', array( $this->sut, 'maybe_add_woopay_user_metadata' ) );
		}

		$this->reset_real_blog_token_signed();
		$this->reset_frontend_surface_state();
		delete_option( 'woocommerce_checkout_page_id' );
		delete_option( 'woocommerce_cart_page_id' );
		wc_clear_notices();
		remove_all_filters( 'wcpay_woopay_is_signed_with_blog_token' );
		remove_all_filters( 'woocommerce_is_checkout' );
		remove_all_filters( 'woocommerce_is_cart' );
		remove_all_filters( 'woocommerce_is_product' );
		remove_all_filters( 'woocommerce_available_payment_gateways' );
		remove_all_filters( 'wcpay_woopay_enabled' );
		remove_all_filters( 'wp_die_ajax_handler' );
		remove_all_filters( 'wp_doing_ajax' );
		remove_all_filters( 'woocommerce_currency' );
		remove_all_filters( 'wc_get_price_decimals' );
		delete_option( 'woocommerce_currency' );
		if ( function_exists( 'WC' ) && WC() && WC()->cart ) {
			WC()->cart->empty_cart();
		}
		wp_dequeue_script( 'wc-woopayments-woopay' );
		wp_dequeue_style( 'wc-woopayments-woopay' );
		wp_deregister_script( 'wc-woopayments-woopay' );
		wp_deregister_style( 'wc-woopayments-woopay' );
		wp_reset_postdata();
		delete_option( 'woocommerce_enable_guest_checkout' );
		unset( $GLOBALS['wp']->query_vars['order-pay'] );
		wp_set_current_user( 0 );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		parent::tearDown();
	}

	/**
	 * @testdox Should register WooPay route, AJAX and frontend hooks when the built-in WooPayments owns the runtime and WooPay is enabled.
	 */
	public function test_registers_woopay_hooks_when_builtin_owns_runtime_and_woopay_is_enabled(): void {
		$service   = new RecordingWooPaySessionService();
		$this->sut = $this->create_controller( true, $service );

		$this->sut->register();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		do_action( 'rest_api_init' );

		$this->assertArrayHasKey( '/payments/woopay/session', $this->server->get_routes() );
		$this->assertRouteHasMethod( $this->server->get_routes()['/payments/woopay/session'], WP_REST_Server::READABLE );
		$this->assertRouteHasMethod( $this->server->get_routes()['/payments/woopay/session'], WP_REST_Server::CREATABLE );
		$route_args = $this->server->get_routes()['/payments/woopay/session'][0]['args'];
		// Optional since client 11.2.0: an attested request names the email inside its envelope (class-wc-rest-woopay-session-controller.php:51-57).
		$this->assertFalse( $route_args['email']['required'] );
		$this->assertSame( 'email', $route_args['email']['format'] );
		$this->assertSame( 20, has_filter( 'determine_current_user', array( $this->store_api_request_handler, 'determine_current_user_for_woopay' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_order_payment_status_changed', array( $this->store_api_request_handler, 'woopay_order_payment_status_changed' ) ) );
		$this->assertSame( 1, has_action( 'woocommerce_store_api_checkout_order_processed', array( $this->store_api_request_handler, 'catch_woopay_checkout_errors' ) ) );

		foreach ( $this->get_expected_ajax_hooks() as $hook => $method ) {
			$this->assertNotFalse( has_action( $hook, array( $this->sut, $method ) ), "{$hook} should be registered." );
		}
		foreach ( $this->get_expected_frontend_hooks() as $hook => $method ) {
			$this->assertNotFalse( has_action( $hook, array( $this->sut, $method ) ), "{$hook} should be registered." );
		}
		$this->assertSame( 10, has_action( 'wp_footer', array( $this->sut, 'enqueue_frontend_assets' ) ) );
		$this->assertSame( 20, has_action( 'wp_footer', 'wp_print_footer_scripts' ) );
		$this->assertNotFalse( has_filter( 'wcpay_metadata_from_order', array( $this->sut, 'maybe_add_woopay_user_metadata' ) ) );
	}

	/**
	 * @testdox Should register the WooPay frontend and AJAX hooks on init when registered while plugins load.
	 */
	public function test_registers_woopay_hooks_on_init_when_registered_while_plugins_load(): void {
		global $wp_actions;
		$service   = new RecordingWooPaySessionService();
		$this->sut = $this->create_controller( false, $service );
		remove_all_actions( 'init' );
		unset( $wp_actions['init'] );

		$this->sut->register();
		// While plugins load, Jetpack cannot confirm the connection owner yet, so WooPay reads as disabled.
		$service->woopay_enabled = true;
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		do_action( 'init' );

		foreach ( array_merge( $this->get_expected_ajax_hooks(), $this->get_expected_frontend_hooks() ) as $hook => $method ) {
			$this->assertNotFalse( has_action( $hook, array( $this->sut, $method ) ), "{$hook} should be registered once init runs." );
		}
	}

	/**
	 * @testdox Should keep the inbound identity hooks and the session route registered when WooPay is disabled.
	 */
	public function test_keeps_identity_hooks_and_session_route_when_platform_checkout_is_disabled(): void {
		$service   = new RecordingWooPaySessionService();
		$this->sut = $this->create_controller( false, $service );

		$this->sut->register();

		$this->assertSame( 20, has_filter( 'determine_current_user', array( $this->store_api_request_handler, 'determine_current_user_for_woopay' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_order_payment_status_changed', array( $this->store_api_request_handler, 'woopay_order_payment_status_changed' ) ) );
		// The session route stays registered so an ineligible or disabled state answers with the
		// permission callback's 401 instead of a 404, mirroring the plugin's unconditional route.
		$this->assertNotFalse( has_action( 'rest_api_init', array( $this->sut, 'register_routes' ) ) );
		foreach ( $this->get_expected_ajax_hooks() as $hook => $method ) {
			$this->assertFalse( has_action( $hook, array( $this->sut, $method ) ) );
		}
		foreach ( $this->get_expected_frontend_hooks() as $hook => $method ) {
			$this->assertFalse( has_action( $hook, array( $this->sut, $method ) ) );
		}
		$this->assertFalse( has_filter( 'wcpay_metadata_from_order', array( $this->sut, 'maybe_add_woopay_user_metadata' ) ) );
	}

	/**
	 * @testdox Should not load WooPay on an archive whose term ID equals the post ID of a page with a product_page shortcode.
	 *
	 * A prior test can permanently define WOOCOMMERCE_CHECKOUT in the parent process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_enqueue_frontend_assets_skips_an_archive_whose_term_id_matches_a_product_page(): void {
		$service                            = new RecordingWooPaySessionService();
		$service->woopay_enabled            = true;
		$service->should_show_woopay_button = true;
		$this->sut                          = $this->create_controller( true, $service );
		$term_id                            = self::factory()->category->create( array( 'name' => 'Empty archive' ) );
		// A product shortcode page whose post ID is the category's term ID (import_id asks wp_insert_post() for that ID).
		$page_id = wp_insert_post(
			array(
				'import_id'    => $term_id,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Product page with the term ID',
				'post_content' => '[product_page id="1"]',
			)
		);
		$this->assertSame( $term_id, $page_id, 'The fixture needs a page whose ID equals the term ID.' );
		$this->go_to( get_category_link( $term_id ) );

		$this->sut->enqueue_frontend_assets();

		$this->assertFalse( wp_script_is( 'wc-woopayments-woopay', 'enqueued' ) );
	}

	/**
	 * @testdox Should enqueue classic WooPay save-user assets on checkout even when the express button is hidden.
	 */
	public function test_enqueue_frontend_assets_preserves_classic_save_user_when_checkout_button_is_hidden(): void {
		$service                            = new RecordingWooPaySessionService();
		$service->woopay_enabled            = true;
		$service->should_show_woopay_button = false;
		$this->sut                          = $this->create_controller( true, $service );

		$this->set_checkout_shortcode_page();
		// The source URL is the page permalink core's Blocks settings use, not wc_get_checkout_url() and its filter.
		$filter_checkout_url = static function (): string {
			return 'https://example.test/filtered-checkout/';
		};
		add_filter( 'woocommerce_get_checkout_url', $filter_checkout_url );

		$this->sut->enqueue_frontend_assets();
		remove_filter( 'woocommerce_get_checkout_url', $filter_checkout_url );

		$this->assertTrue( wp_script_is( 'wc-woopayments-woopay', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'wc-woopayments-woopay', 'enqueued' ) );
		$localized_data = wp_scripts()->get_data( 'wc-woopayments-woopay', 'data' );
		$this->assertIsString( $localized_data );
		$this->assertStringContainsString( '"shouldShowWooPayButton":""', $localized_data );
		$this->assertStringContainsString( '"PRE_CHECK_SAVE_MY_INFO":"1"', $localized_data );
		$this->assertSame( (string) get_permalink( wc_get_page_id( 'checkout' ) ), $this->get_localized_woopay_config()['woopaySourceUrl'] );
		$this->assertStringContainsString( '/assets/client/blocks/wc-woopayments-phone-validation.js', $this->get_localized_woopay_config()['woopayPhoneValidationScriptUrl'] );
	}

	/**
	 * @testdox The classic WooPay config carries the checkout assets' shared keys right after the WooPay flags.
	 */
	public function test_classic_woopay_config_carries_the_shared_script_config_after_the_flags(): void {
		$shared = array(
			'ajaxUrl'                  => 'shared-ajax-url',
			'platformTrackerNonce'     => 'shared-platform-tracker-nonce',
			'tracksUrl'                => 'shared-tracks-url',
			'tracksRestNonce'          => 'shared-tracks-rest-nonce',
			'isShopperTrackingEnabled' => 'shared-tracking',
			'woopayHost'               => 'shared-woopay-host',
			'testMode'                 => 'shared-test-mode',
			'wcpayVersionNumber'       => 'shared-version',
		);
		$assets = $this->createMock( WooPaymentsCheckoutAssets::class );
		$assets->method( 'get_shared_script_config' )->willReturn( $shared );
		wc_get_container()->replace( WooPaymentsCheckoutAssets::class, $assets );
		$service                 = new RecordingWooPaySessionService();
		$service->woopay_enabled = true;
		$this->sut               = $this->create_controller( true, $service );
		$this->set_checkout_shortcode_page();

		$this->sut->enqueue_frontend_assets();

		$config    = $this->get_localized_woopay_config();
		$flags_end = array_search( 'forceNetworkSavedCards', array_keys( $config ), true ) + 1;
		$this->assertSame( $shared, array_slice( $config, $flags_end, count( $shared ), true ) );
	}

	/**
	 * @testdox Should enqueue direct checkout assets on a classic cart without rendering an express button.
	 *
	 * A prior test can permanently define WOOCOMMERCE_CHECKOUT in the parent process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_enqueue_frontend_assets_for_direct_checkout_on_classic_cart(): void {
		$service                                      = new RecordingWooPaySessionService();
		$service->should_show_woopay_button           = false;
		$service->should_load_woopay_save_user_assets = false;
		$service->direct_checkout_enabled             = true;
		$this->sut                                    = $this->create_controller( true, $service );
		add_filter( 'woocommerce_is_cart', '__return_true' );

		$this->sut->enqueue_frontend_assets();
		$express_button = $this->sut->get_express_checkout_button_html();

		$this->assertTrue( wp_script_is( 'wc-woopayments-woopay', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'wc-woopayments-woopay', 'enqueued' ) );
		$localized_data = wp_scripts()->get_data( 'wc-woopayments-woopay', 'data' );
		$this->assertIsString( $localized_data );
		$this->assertStringContainsString( '"isWooPayDirectCheckoutEnabled":"1"', $localized_data );
		$this->assertStringContainsString( '"woopaySessionNonce":"woopay-session-nonce"', $localized_data );
		$this->assertStringContainsString( '"wcAjaxUrl":', $localized_data );
		$this->assertSame( '', $express_button );
	}

	/**
	 * Pages holding the Cart Block that are not the store's cart page.
	 *
	 * @return array<string,array{0:string|null}>
	 */
	public function provide_cart_block_pages(): array {
		return array(
			'another page'      => array( null ),
			'the checkout page' => array( 'woocommerce_checkout_page_id' ),
		);
	}

	/**
	 * @testdox A page carrying the Cart Block keeps the full WooPay config with the cart's context even when it is not the store's cart page.
	 *
	 * Client 11.1.0 counts any page with the Cart Block as a cart page (class-wc-payments-woopay-direct-checkout.php:141-143)
	 * and gives only other pages the light common config.
	 *
	 * @dataProvider provide_cart_block_pages
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string|null $page_option WooCommerce page option pointing at the page, or null for none.
	 */
	public function test_cart_block_page_keeps_the_full_config( ?string $page_option ): void {
		$service                                      = new RecordingWooPaySessionService();
		$service->should_show_woopay_button           = false;
		$service->should_load_woopay_save_user_assets = false;
		$service->direct_checkout_enabled             = true;
		$this->sut                                    = $this->create_controller( true, $service );
		$page_id                                      = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:woocommerce/cart --><div class="wp-block-woocommerce-cart"></div><!-- /wp:woocommerce/cart -->',
			)
		);

		if ( null !== $page_option ) {
			update_option( $page_option, $page_id );
		}
		$this->go_to( get_permalink( $page_id ) );

		global $post;
		$post = get_post( $page_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		$this->sut->enqueue_frontend_assets();

		$this->assertSame( null !== $page_option, is_checkout() );
		$this->assertTrue( wp_script_is( 'wc-woopayments-woopay', 'enqueued' ) );
		$this->assertSame( 1, $service->frontend_config_calls );
		$this->assertSame( 0, $service->direct_checkout_config_calls );
		// The cart's button context, as client 11.1.0 get_button_context() gives a Cart block page, the checkout page
		// too: it checks the cart before the checkout (class-wc-payments-express-checkout-button-helper.php:275-277, :450-468).
		$this->assertStringContainsString( '"context":"cart"', (string) wp_scripts()->get_data( 'wc-woopayments-woopay', 'data' ) );
	}

	/**
	 * @testdox Should enqueue the light direct checkout assets for a mini-cart at footer time: $_dataName.
	 *
	 * Runs in its own process: WOOCOMMERCE_CART, once an earlier test defines it (WC_Form_Handler::update_cart_action() does),
	 * makes is_cart() true for the rest of the run, and the Checkout block page must not be a cart page.
	 *
	 * @dataProvider light_direct_checkout_surfaces
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string $surface Page and cart surface, as arrange_direct_checkout_surface() builds it.
	 */
	public function test_enqueue_frontend_assets_loads_light_direct_checkout_for_a_mini_cart( string $surface ): void {
		$service                                      = new RecordingWooPaySessionService();
		$service->should_show_woopay_button           = false;
		$service->should_load_woopay_save_user_assets = false;
		$service->direct_checkout_enabled             = true;
		$this->sut                                    = $this->create_controller( true, $service );
		$this->arrange_direct_checkout_surface( $surface );
		// The light config emits only connection keys, so it computes no tracking keys: no tracking policy read.
		$tracking_reads = 0;
		$count_read     = static function ( $value ) use ( &$tracking_reads ) {
			++$tracking_reads;
			return $value;
		};
		add_filter( 'wcpay_shopper_tracking_enabled', $count_read );
		add_filter( 'pre_option_woocommerce_allow_tracking', $count_read );

		$this->sut->enqueue_frontend_assets();

		$this->assert_light_direct_checkout_assets( $service );
		$this->assertSame( array( 'ajaxUrl', 'platformTrackerNonce', 'woopayHost', 'testMode' ), array_keys( (array) $service->last_connection_script_config ), 'The controller hands over the connection keys.' );
		$this->assertSame( 0, $tracking_reads, 'No tracking option read and no tracking filter.' );
	}

	/**
	 * Mini-cart surfaces that get the light direct checkout config.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function light_direct_checkout_surfaces(): array {
		return array(
			'block mini-cart on an ordinary page'      => array( 'block mini-cart' ),
			'classic mini-cart on an ordinary page'    => array( 'classic mini-cart' ),
			'mini-cart block on a Checkout block page' => array( 'block mini-cart on checkout block page' ),
		);
	}

	/**
	 * @testdox A mini-cart page in live mode loads direct checkout without geolocating the shopper.
	 *
	 * Client 11.1.0 geolocates only where its button handler runs (product, cart, checkout); a mini-cart page gets the
	 * common config (class-wc-payments.php:1797-1835).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_mini_cart_page_does_not_geolocate_the_shopper(): void {
		$geolocations = 0;
		add_filter(
			'woocommerce_geolocate_ip',
			static function () use ( &$geolocations ) {
				++$geolocations;
				return 'US';
			}
		);
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$this->make_base_gateway_available();
		$this->sut = $this->create_controller( true, $this->create_real_enabled_session_service( false ) );
		$this->sut->register();
		if ( ! wp_script_is( 'wc-cart-fragments', 'registered' ) ) {
			wp_register_script( 'wc-cart-fragments', 'https://example.com/cart-fragments.js', array(), '1.0', true );
		}
		wp_enqueue_script( 'wc-cart-fragments' );

		$this->sut->enqueue_frontend_assets();

		$this->assertTrue( wp_script_is( 'wc-woopayments-woopay', 'enqueued' ) );
		$localized_data = wp_scripts()->get_data( 'wc-woopayments-woopay', 'data' );
		$this->assertIsString( $localized_data );
		$this->assertStringContainsString( '"isWooPayDirectCheckoutEnabled":"1"', $localized_data );
		$this->assertSame( 0, $geolocations );
	}

	/**
	 * @testdox A mini-cart page loads no direct checkout for a guest whose cart needs an account.
	 *
	 * Client 11.1.0 class-wc-payments-woopay-direct-checkout.php:92-94 enqueues nothing when the guest rule fails.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_mini_cart_page_loads_no_direct_checkout_when_the_guest_rule_fails(): void {
		$service                                      = new RecordingWooPaySessionService();
		$service->should_show_woopay_button           = false;
		$service->should_load_woopay_save_user_assets = false;
		$service->direct_checkout_enabled             = true;
		$service->guest_rule_passes                   = false;
		$this->sut                                    = $this->create_controller( true, $service );
		$this->sut->register();
		if ( ! wp_script_is( 'wc-cart-fragments', 'registered' ) ) {
			wp_register_script( 'wc-cart-fragments', 'https://example.com/cart-fragments.js', array(), '1.0', true );
		}
		wp_enqueue_script( 'wc-cart-fragments' );

		$this->sut->enqueue_frontend_assets();

		$this->assertFalse( wp_script_is( 'wc-woopayments-woopay', 'enqueued' ) );
	}

	/**
	 * @testdox A classic cart loads WooPay direct checkout only while WooPayments is an available gateway: $label.
	 *
	 * Client 11.1.0 loads its direct-checkout script whenever the store-level check passes (class-wc-payments-features.php:237-243,
	 * class-wc-payments-woopay-direct-checkout.php:87-121), so its cart also redirects to WooPay while WooPayments is not
	 * offered. Here nothing loads then, and "Proceed to checkout" stays on the store.
	 *
	 * @testWith ["gateway available", true]
	 *           ["gateway unavailable", false]
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string $label             Case label.
	 * @param bool   $gateway_available Whether the base gateway is among the available gateways.
	 */
	public function test_cart_loads_direct_checkout_only_while_the_gateway_is_available( string $label, bool $gateway_available ): void {
		unset( $label );
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		if ( $gateway_available ) {
			$this->make_base_gateway_available();
		}
		$this->sut = $this->create_controller( true, $this->create_real_enabled_session_service() );
		add_filter( 'woocommerce_is_cart', '__return_true' );

		$this->sut->enqueue_frontend_assets();

		$this->assertSame( $gateway_available, wp_script_is( 'wc-woopayments-woopay', 'enqueued' ) );
		if ( $gateway_available ) {
			$localized_data = wp_scripts()->get_data( 'wc-woopayments-woopay', 'data' );
			$this->assertIsString( $localized_data );
			$this->assertStringContainsString( '"isWooPayDirectCheckoutEnabled":"1"', $localized_data );
		}
	}

	/**
	 * @testdox A product page with a mini-cart keeps the WooPay button but not direct checkout for a guest the guest rule keeps out.
	 *
	 * On a product page the client's button checks the product, not the cart (client 11.1.0
	 * class-wc-payments-woopay-button-handler.php:300-316), while its direct-checkout script stays unloaded
	 * (class-wc-payments-woopay-direct-checkout.php:92-94).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_product_page_turns_direct_checkout_off_when_the_guest_rule_fails(): void {
		$service                                      = new RecordingWooPaySessionService();
		$service->should_show_woopay_button           = true;
		$service->should_load_woopay_save_user_assets = false;
		$service->direct_checkout_enabled             = true;
		$service->guest_rule_passes                   = false;
		$this->sut                                    = $this->create_controller( true, $service );
		$this->set_current_product();
		if ( ! wp_script_is( 'wc-cart-fragments', 'registered' ) ) {
			wp_register_script( 'wc-cart-fragments', 'https://example.com/cart-fragments.js', array(), '1.0', true );
		}
		wp_enqueue_script( 'wc-cart-fragments' );

		$this->sut->enqueue_frontend_assets();

		$this->assertTrue( wp_script_is( 'wc-woopayments-woopay', 'enqueued' ), 'The product button still loads the script.' );
		$localized_data = wp_scripts()->get_data( 'wc-woopayments-woopay', 'data' );
		$this->assertIsString( $localized_data );
		$this->assertStringContainsString( '"isWooPayDirectCheckoutEnabled":""', $localized_data );
	}

	/**
	 * @testdox Should not build the WooPay config for a mini-cart page when direct checkout is off.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_mini_cart_page_skips_woopay_config_when_direct_checkout_is_off(): void {
		$service                                      = new RecordingWooPaySessionService();
		$service->should_show_woopay_button           = false;
		$service->should_load_woopay_save_user_assets = false;
		$service->direct_checkout_enabled             = false;
		$this->sut                                    = $this->create_controller( true, $service );
		$this->sut->register();
		if ( ! wp_script_is( 'wc-cart-fragments', 'registered' ) ) {
			wp_register_script( 'wc-cart-fragments', 'https://example.com/cart-fragments.js', array(), '1.0', true );
		}
		wp_enqueue_script( 'wc-cart-fragments' );

		$this->sut->enqueue_frontend_assets();

		$this->assertSame( 0, $service->frontend_config_calls, 'Client 11.1.0 only runs direct checkout when it is enabled.' );
		$this->assertFalse( wp_script_is( 'wc-woopayments-woopay', 'enqueued' ) );
	}

	/**
	 * @testdox Should run direct checkout on a classic checkout page where a Mini-Cart block renders.
	 *
	 * Client 11.1.0 should_enqueue_scripts() loads direct checkout wherever woocommerce_blocks_cart_enqueue_data fired, the
	 * checkout page included (class-wc-payments-woopay-direct-checkout.php:130-134).
	 */
	public function test_enqueue_frontend_assets_runs_direct_checkout_for_a_mini_cart_block_on_classic_checkout(): void {
		$service                                      = new RecordingWooPaySessionService();
		$service->should_show_woopay_button           = false;
		$service->should_load_woopay_save_user_assets = false;
		$service->direct_checkout_enabled             = true;
		$this->sut                                    = $this->create_controller( true, $service );
		$this->sut->register();
		$this->set_checkout_shortcode_page();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		do_action( 'woocommerce_blocks_cart_enqueue_data' );

		$this->sut->enqueue_frontend_assets();

		$this->assertTrue( wp_script_is( 'wc-woopayments-woopay', 'enqueued' ) );
		// wp_localize_script() sends scalars as strings.
		$this->assertSame( '1', $this->get_localized_woopay_config()['isWooPayDirectCheckoutEnabled'] );
	}

	/**
	 * @testdox Should not enqueue direct checkout assets: $_dataName.
	 *
	 * Runs in its own process: WOOCOMMERCE_CART, once an earlier test defines it (WC_Form_Handler::update_cart_action() does),
	 * makes is_cart() true for the rest of the run, and these pages must not be cart pages. A prior checkout test must not
	 * make the unrelated page appear to be checkout.
	 *
	 * @dataProvider no_direct_checkout_surfaces
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string $surface Page and cart surface, as arrange_direct_checkout_surface() builds it.
	 */
	public function test_enqueue_frontend_assets_skips_direct_checkout( string $surface ): void {
		$service                                      = new RecordingWooPaySessionService();
		$service->should_show_woopay_button           = false;
		$service->should_load_woopay_save_user_assets = false;
		$service->direct_checkout_enabled             = true;
		$this->sut                                    = $this->create_controller( true, $service );
		$this->arrange_direct_checkout_surface( $surface );

		$this->sut->enqueue_frontend_assets();

		$this->assertFalse( wp_script_is( 'wc-woopayments-woopay', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'wc-woopayments-woopay', 'enqueued' ) );
	}

	/**
	 * Surfaces that load no direct checkout assets.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function no_direct_checkout_surfaces(): array {
		return array(
			'classic cart widget on the checkout page' => array( 'classic cart widget on checkout' ),
			'Checkout block page'                      => array( 'checkout block page' ),
			'unrelated page'                           => array( 'unrelated page' ),
		);
	}

	/**
	 * Pages whose block or shortcode the password form, or a `<!--nextpage-->` break, keeps off the rendered content, and
	 * whether the classic WooPay script loads there, with which button context. The page content is read raw, as
	 * has_block() reads it.
	 *
	 * Rows with direct checkout off show the button; rows with it on hide the button, so only direct checkout loads the script.
	 * The cart page rows run without direct checkout, so only the Cart block exclusion keeps the script off.
	 *
	 * @return array<string,array{0:string,1:bool,2:string|null,3:bool,4:bool,5:string|null}>
	 */
	public function provide_pages_whose_content_the_rendered_page_hides(): array {
		$cart       = '<!-- wp:woocommerce/cart --><div class="wp-block-woocommerce-cart"></div><!-- /wp:woocommerce/cart -->';
		$checkout   = '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->';
		$page_break = '<!-- wp:paragraph --><p>First page</p><!-- /wp:paragraph --><!--nextpage-->';

		return array(
			'password page with a product shortcode'    => array( '[product_page id="1"]', true, null, false, false, 'product' ),
			'product shortcode after a page break'      => array( $page_break . '[product_page id="1"]', false, null, false, false, 'product' ),
			'password checkout page holding the Checkout block' => array( $checkout, true, 'woocommerce_checkout_page_id', false, false, null ),
			'checkout page with the Checkout block after a page break' => array( $page_break . $checkout, false, 'woocommerce_checkout_page_id', false, false, null ),
			'password page holding the Cart block, direct checkout' => array( $cart, true, null, true, false, 'cart' ),
			'Cart block after a page break, direct checkout' => array( $page_break . $cart, false, null, true, false, 'cart' ),
			'password cart page holding the Cart block' => array( $cart, true, 'woocommerce_cart_page_id', false, false, null ),
			'cart page with the Cart block after a page break' => array( $page_break . $cart, false, 'woocommerce_cart_page_id', false, false, null ),
			'password Checkout block page with the cart widget' => array( $checkout, true, null, true, true, null ),
			'Checkout block after a page break, with the cart widget' => array( $page_break . $checkout, false, null, true, true, null ),
		);
	}

	/**
	 * @testdox Should read the raw page content for the Blocks exclusion, the cart and product surfaces and direct checkout: $_dataName.
	 * @dataProvider provide_pages_whose_content_the_rendered_page_hides
	 *
	 * A separate process: WOOCOMMERCE_CART or WOOCOMMERCE_CHECKOUT, once an earlier test defines it, holds for the rest of the run.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string      $content          Page content.
	 * @param bool        $password         Whether the page is password protected.
	 * @param string|null $page_option      WooCommerce page option pointing at the page, or null for none.
	 * @param bool        $direct_checkout  Whether direct checkout runs; the button shows only without it.
	 * @param bool        $cart_widget      Whether the classic cart widget's fragments script is on the page.
	 * @param string|null $expected_context Button context the classic script loads with, or null when it does not load.
	 */
	public function test_enqueue_frontend_assets_reads_the_raw_page_content( string $content, bool $password, ?string $page_option, bool $direct_checkout, bool $cart_widget, ?string $expected_context ): void {
		$service                                      = new RecordingWooPaySessionService();
		$service->should_show_woopay_button           = ! $direct_checkout;
		$service->should_load_woopay_save_user_assets = false;
		$service->direct_checkout_enabled             = $direct_checkout;
		$this->sut                                    = $this->create_controller( true, $service );
		$page_id                                      = self::factory()->post->create(
			array(
				'post_type'     => 'page',
				'post_status'   => 'publish',
				'post_content'  => $content,
				'post_password' => $password ? 'secret' : '',
			)
		);
		if ( null !== $page_option ) {
			update_option( $page_option, $page_id );
		}
		$this->reset_cart_checkout_page_cache();
		$this->go_to( get_permalink( $page_id ) );
		if ( $cart_widget ) {
			wp_register_script( 'wc-cart-fragments', 'https://example.com/cart-fragments.js', array(), '1.0', true );
			wp_enqueue_script( 'wc-cart-fragments' );
		}
		$this->assertSame( $password, post_password_required( $page_id ), 'Precondition: the password form renders instead of the content.' );
		$this->assertSame( 'woocommerce_checkout_page_id' === $page_option, is_checkout(), 'Precondition: only the store checkout page is a checkout.' );
		$this->assertSame( 'woocommerce_cart_page_id' === $page_option, is_cart(), 'Precondition: only the store cart page is a cart.' );

		$this->sut->enqueue_frontend_assets();

		$this->assertSame( null !== $expected_context, wp_script_is( 'wc-woopayments-woopay', 'enqueued' ) );
		if ( null !== $expected_context ) {
			$this->assertStringContainsString( '"context":"' . $expected_context . '"', (string) wp_scripts()->get_data( 'wc-woopayments-woopay', 'data' ) );
		}
	}

	/**
	 * Pages whose product shortcode lives outside the visited page itself: in the post an archive's loop holds, or escaped
	 * as `[[product_page]]`, which WordPress prints as text. Client 11.2.0 reads only the main query's page and skips escaped
	 * tags (class-wc-payments-express-checkout-button-helper.php:255-259, :975-1031), for the page and the button context.
	 *
	 * @return array<string,array{0:string|null,1:string|null,2:string|null,3:string|null}>
	 */
	public function provide_shortcodes_outside_the_visited_page(): array {
		return array(
			"product shortcode in an archive's loop post" => array( null, null, '[product_page id="1"]', null ),
			'cart page whose loop post holds a product shortcode' => array( '[woocommerce_cart]', 'woocommerce_cart_page_id', '[product_page id="1"]', 'cart' ),
			'escaped product shortcode on a page'         => array( '[[product_page id="1"]]', null, null, null ),
			'cart page holding an escaped product shortcode' => array( '[woocommerce_cart] [[product_page id="1"]]', 'woocommerce_cart_page_id', null, 'cart' ),
			'product shortcode after an escaped one on a page' => array( '[[product_page id="1"]] [product_page id="1"]', null, null, 'product' ),
		);
	}

	/**
	 * @testdox Should read the product shortcode from the visited page only, skipping escaped tags: $_dataName.
	 * @dataProvider provide_shortcodes_outside_the_visited_page
	 *
	 * A separate process: WOOCOMMERCE_CART or WOOCOMMERCE_CHECKOUT, once an earlier test defines it, holds for the rest of the run.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string|null $page_content     Content of the visited page, or null for a search page.
	 * @param string|null $page_option      WooCommerce page option pointing at the visited page, or null for none.
	 * @param string|null $loop_content     Content of a post the global loop holds, or null for none.
	 * @param string|null $expected_context Button context the classic script loads with, or null when it does not load.
	 */
	public function test_enqueue_frontend_assets_reads_only_the_visited_page( ?string $page_content, ?string $page_option, ?string $loop_content, ?string $expected_context ): void {
		$service                                      = new RecordingWooPaySessionService();
		$service->should_show_woopay_button           = true;
		$service->should_load_woopay_save_user_assets = false;
		$service->direct_checkout_enabled             = false;
		$this->sut                                    = $this->create_controller( true, $service );
		if ( null === $page_content ) {
			$this->go_to( home_url( '/?s=nothing-matches' ) );
			$this->assertFalse( is_singular(), 'Precondition: not a singular view.' );
		} else {
			$page_id = self::factory()->post->create(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_content' => $page_content,
				)
			);
			if ( null !== $page_option ) {
				update_option( $page_option, $page_id );
			}
			$this->reset_cart_checkout_page_cache();
			$this->go_to( get_permalink( $page_id ) );
		}
		if ( null !== $loop_content ) {
			$GLOBALS['post'] = self::factory()->post->create_and_get( array( 'post_content' => $loop_content ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The post of the page's loop.
		}
		$this->assertSame( null !== $page_option, is_cart(), 'Precondition: only the store cart page is a cart.' );

		$this->sut->enqueue_frontend_assets();

		$this->assertSame( null !== $expected_context, wp_script_is( 'wc-woopayments-woopay', 'enqueued' ) );
		if ( null !== $expected_context ) {
			$this->assertStringContainsString( '"context":"' . $expected_context . '"', (string) wp_scripts()->get_data( 'wc-woopayments-woopay', 'data' ) );
		}
	}

	/**
	 * @testdox Should move the classic billing email into a contact section only while WooPay is enabled.
	 */
	public function test_register_relocates_classic_email_field_only_when_woopay_is_enabled(): void {
		$this->sut = $this->create_controller( false );
		$this->sut->register();

		$this->assertFalse( has_action( 'woocommerce_checkout_billing', array( $this->sut, 'woopay_fields_before_billing_details' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_form_field_email', array( $this->sut, 'filter_woocommerce_form_field_woopay_email' ) ) );

		$this->sut = $this->create_controller( true );
		$this->sut->register();

		$this->assertSame( -50, has_action( 'woocommerce_checkout_billing', array( $this->sut, 'woopay_fields_before_billing_details' ) ) );
		$this->assertSame( 20, has_filter( 'woocommerce_form_field_email', array( $this->sut, 'filter_woocommerce_form_field_woopay_email' ) ) );
	}

	/**
	 * @testdox Should render the WooPay contact section with the billing email field.
	 */
	public function test_woopay_fields_before_billing_details_renders_contact_section(): void {
		$this->sut = $this->create_controller( true );
		$this->set_checkout_shortcode_page();

		ob_start();
		$this->sut->woopay_fields_before_billing_details();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<div class="woocommerce-billing-fields" id="contact_details">', $output );
		$this->assertStringContainsString( '<h3>Contact information</h3>', $output );
		$this->assertStringContainsString( 'name="billing_email"', $output );
		$this->assertStringContainsString( 'woopay-billing-email', $output );
		$this->assertStringContainsString( 'woopay-billing-email-input', $output );
		$this->assertStringContainsString( 'type="email"', $output );
		$this->assertTrue( wp_style_is( 'wc-blocks-style', 'enqueued' ) );
	}

	/**
	 * @testdox Should hide the core billing email on checkout while leaving the WooPay one and other pages alone.
	 */
	public function test_filter_woocommerce_form_field_woopay_email_hides_core_field_on_checkout(): void {
		$this->sut = $this->create_controller( true );
		$field     = '<p class="form-row"><input type="email" name="billing_email" /></p>';

		$this->set_checkout_shortcode_page();

		$this->assertSame( '', $this->sut->filter_woocommerce_form_field_woopay_email( $field, 'billing_email', array( 'class' => array( 'form-row-wide' ) ), '' ) );
		$this->assertSame( $field, $this->sut->filter_woocommerce_form_field_woopay_email( $field, 'billing_email', array( 'class' => array( 'form-row-wide woopay-billing-email' ) ), '' ) );

		// The pay-for-order page is a checkout page the relocation must leave alone.
		$GLOBALS['wp']->query_vars['order-pay'] = 123;
		$this->assertSame( $field, $this->sut->filter_woocommerce_form_field_woopay_email( $field, 'billing_email', array( 'class' => array( 'form-row-wide' ) ), '' ) );
		unset( $GLOBALS['wp']->query_vars['order-pay'] );
	}

	/**
	 * @testdox Should leave the core billing email alone away from the checkout page.
	 *
	 * A prior test can permanently define WOOCOMMERCE_CHECKOUT in the parent process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_filter_woocommerce_form_field_woopay_email_keeps_core_field_off_checkout(): void {
		$this->sut = $this->create_controller( true );
		$field     = '<p class="form-row"><input type="email" name="billing_email" /></p>';

		$this->assertSame( $field, $this->sut->filter_woocommerce_form_field_woopay_email( $field, 'billing_email', array( 'class' => array( 'form-row-wide' ) ), '' ) );
	}

	/**
	 * @testdox Should require a phone number when the shopper asks to save their details in WooPay.
	 */
	public function test_maybe_show_woopay_phone_number_error_requires_phone_when_saving_user(): void {
		$this->sut = $this->create_controller( true );
		$this->sut->register();

		$this->assertSame( 10, has_action( 'woocommerce_checkout_process', array( $this->sut, 'maybe_show_woopay_phone_number_error' ) ) );

		$cases = array(
			'not saving, no phone'       => array( array(), 0 ),
			'not saving, checkbox false' => array( array( 'save_user_in_woopay' => 'false' ), 0 ),
			'saving without phone field' => array( array( 'save_user_in_woopay' => 'true' ), 1 ),
			'saving with empty phone'    => array(
				array(
					'save_user_in_woopay'     => 'true',
					'woopay_user_phone_field' => array( 'full' => '' ),
				),
				1,
			),
			'saving with phone'          => array(
				array(
					'save_user_in_woopay'     => 'true',
					'woopay_user_phone_field' => array( 'full' => '+15555550123' ),
				),
				0,
			),
		);

		foreach ( $cases as $label => list( $post, $expected_errors ) ) {
			wc_clear_notices();
			$_POST = $post;

			$this->sut->maybe_show_woopay_phone_number_error();

			$this->assertCount( $expected_errors, wc_get_notices( 'error' ), $label );
		}

		$notice = wc_get_notices( 'error' );
		$_POST  = array();
		wc_clear_notices();
		$this->assertSame( array(), $notice );

		$_POST = array( 'save_user_in_woopay' => 'true' );
		$this->sut->maybe_show_woopay_phone_number_error();
		$this->assertStringContainsString( '<strong>Mobile Number</strong> is required to create an WooPay account.', wc_get_notices( 'error' )[0]['notice'] );
		$_POST = array();
		wc_clear_notices();
	}

	/**
	 * @testdox Should give only the WooPay placeholder for the shared wrapper on checkout.
	 */
	public function test_express_checkout_button_html_is_the_placeholder_on_checkout(): void {
		$this->sut = $this->create_controller( true );
		$this->set_checkout_shortcode_page();

		$output = $this->sut->get_express_checkout_button_html();

		$this->assertStringStartsWith( '<div id="wcpay-woopay-button" data-product_page="0">', $output );
		$this->assertStringNotContainsString( 'wcpay-express-checkout-wrapper', $output );
		$this->assertStringNotContainsString( 'wcpay-express-checkout-button-separator', $output );
	}

	/**
	 * @testdox Should give no WooPay placeholder when WooPay is disabled.
	 */
	public function test_express_checkout_button_html_is_empty_when_woopay_is_disabled(): void {
		$this->sut = $this->create_controller( false );
		$this->set_checkout_shortcode_page();

		$this->assertSame( '', $this->sut->get_express_checkout_button_html() );
	}

	/**
	 * @testdox Should give a product-context WooPay placeholder on product pages.
	 */
	public function test_express_checkout_button_html_on_product_page(): void {
		$this->sut = $this->create_controller( true );
		$this->set_current_product();

		$this->assertStringContainsString( 'data-product_page="1"', $this->sut->get_express_checkout_button_html() );
	}

	/**
	 * @testdox Should render product-context WooPay on product_page shortcode pages.
	 */
	public function test_express_checkout_button_html_supports_product_page_shortcode(): void {
		$this->sut = $this->create_controller( true );
		$product   = \WC_Helper_Product::create_simple_product( true );
		$product->set_sku( 'woopay-controller-shortcode' );
		$product->save();
		$this->set_current_page_with_content( "[product_page columns='3' class='featured' sku='woopay-controller-shortcode']" );

		$output = $this->sut->get_express_checkout_button_html();

		$this->assertStringContainsString( 'id="wcpay-woopay-button"', $output );
		$this->assertStringContainsString( 'data-product_page="1"', $output );
	}

	/**
	 * @testdox Should evaluate the button filter once while rendering with the real session service.
	 */
	public function test_express_checkout_button_html_applies_button_filter_once(): void {
		$this->make_base_gateway_available();
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$service   = $this->create_real_enabled_session_service();
		$this->sut = $this->create_controller( true, $service );
		$this->set_checkout_shortcode_page();
		$enabled_filter_calls = 0;
		add_filter(
			'wcpay_woopay_enabled',
			static function ( bool $enabled ) use ( &$enabled_filter_calls ): bool {
				++$enabled_filter_calls;

				return $enabled;
			}
		);

		$output = $this->sut->get_express_checkout_button_html();

		$this->assertStringContainsString( 'id="wcpay-woopay-button"', $output );
		$this->assertSame( 1, $enabled_filter_calls );
	}

	/**
	 * @testdox On an order's pay page the WooPay config carries the order, its key and billing email, so WooPay pays that order.
	 *
	 * Client 11.1.0 add_pay_for_order_params_to_js_config() (class-wc-payments-express-checkout-button-display-handler.php:184-222).
	 */
	public function test_order_pay_config_carries_the_order_for_its_owner(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order    = \WC_Helper_Order::create_order( $owner_id );
		wp_set_current_user( $owner_id );
		$this->sut = $this->create_controller( true );
		$this->set_order_pay_page( $order->get_id(), $order->get_order_key() );

		$this->sut->enqueue_frontend_assets();
		$config = $this->get_localized_woopay_config();

		$this->assertSame( (string) $order->get_id(), $config['order_id'] );
		$this->assertSame( 'true', $config['pay_for_order'] );
		$this->assertSame( $order->get_order_key(), $config['key'] );
		$this->assertSame( $order->get_billing_email(), $config['billing_email'] );
		$this->assertSame( 'pay_for_order', $config['woopayButton']['context'] );
		$this->assertSame( '1', $config['shouldShowWooPayButton'] );
		$this->assertStringContainsString( 'id="wcpay-woopay-button"', $this->sut->get_express_checkout_button_html() );
	}

	/**
	 * @testdox A guest paying a guest order gets the order and key but only the email they gave, never the order's.
	 */
	public function test_order_pay_config_gives_a_guest_only_their_own_email(): void {
		$order     = \WC_Helper_Order::create_order( 0 );
		$this->sut = $this->create_controller( true );
		$this->set_order_pay_page( $order->get_id(), $order->get_order_key() );

		$this->sut->enqueue_frontend_assets();
		$config = $this->get_localized_woopay_config();

		$this->assertSame( (string) $order->get_id(), $config['order_id'] );
		$this->assertSame( $order->get_order_key(), $config['key'] );
		$this->assertSame( '', $config['billing_email'] );
	}

	/**
	 * @testdox A guest's session email goes into the order-pay WooPay config only when the page is protected from page caching (DONOTCACHEPAGE $do_not_cache).
	 *
	 * Core defines DONOTCACHEPAGE on the configured checkout page, its order-pay endpoint included
	 * (WC_Cache_Helper::prevent_caching()); another page carrying the checkout shortcode reads as checkout without it. The
	 * constant is overridden both ways, because an earlier test in the process can define it.
	 *
	 * @testWith [true, "guest@example.com"]
	 *           [false, ""]
	 *
	 * @param bool   $do_not_cache Whether the page defined DONOTCACHEPAGE.
	 * @param string $expected     Billing email in the config.
	 */
	public function test_order_pay_config_gives_a_guest_their_email_only_on_an_uncached_page( bool $do_not_cache, string $expected ): void {
		$order     = \WC_Helper_Order::create_order( 0 );
		$this->sut = $this->create_controller( true );
		$this->set_order_pay_page( $order->get_id(), $order->get_order_key() );
		$session_customer = WC()->session->get( 'customer' );
		WC()->session->set( 'customer', array( 'email' => 'guest@example.com' ) );
		Constants::set_constant( 'DONOTCACHEPAGE', $do_not_cache );

		try {
			$this->sut->enqueue_frontend_assets();
		} finally {
			Constants::clear_single_constant( 'DONOTCACHEPAGE' );
			WC()->session->set( 'customer', $session_customer );
		}
		$config = $this->get_localized_woopay_config();

		$this->assertSame( (string) $order->get_id(), $config['order_id'] );
		$this->assertSame( $expected, $config['billing_email'] );
	}

	/**
	 * @testdox An order's pay page offers no WooPay, and no order data, when its pay link does not let the visitor pay the order.
	 *
	 * Without the order WooPay would start a cart session from the order's pay page.
	 *
	 * @testWith ["wrong key"]
	 *           ["no key"]
	 *           ["no pay_for_order flag"]
	 *           ["another customer's order"]
	 *
	 * @param string $scenario Case to exercise.
	 */
	public function test_order_pay_without_a_payable_order_shows_no_woopay( string $scenario ): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order    = \WC_Helper_Order::create_order( $owner_id );
		wp_set_current_user( "another customer's order" === $scenario ? self::factory()->user->create( array( 'role' => 'customer' ) ) : $owner_id );
		$this->sut = $this->create_controller( true );
		$this->set_order_pay_page( $order->get_id(), 'wrong key' === $scenario ? 'wc_order_not_the_key' : $order->get_order_key() );
		if ( 'no key' === $scenario ) {
			unset( $_GET['key'] );
		}
		if ( 'no pay_for_order flag' === $scenario ) {
			unset( $_GET['pay_for_order'] );
		}

		$this->assertSame( '', $this->sut->get_express_checkout_button_html() );
		$this->sut->enqueue_frontend_assets();
		$config = $this->get_localized_woopay_config();
		$this->assertSame( '', $config['shouldShowWooPayButton'] );
		$this->assertArrayNotHasKey( 'order_id', $config );
		$this->assertArrayNotHasKey( 'key', $config );
		$this->assertArrayNotHasKey( 'billing_email', $config );
	}

	/**
	 * @testdox Order-pay offers WooPay only for an order in the active currency with two active price decimals: $_dataName.
	 *
	 * The WooPay session preloads the Store API order, whose totals carry the active currency (CurrencyFormatter::format()),
	 * while the payment charges the order's currency. Native multi-currency sets the active currency through the
	 * woocommerce_currency filter (MultiCurrencyFrontendCurrenciesController::get_woocommerce_currency()); the filter here
	 * stands in for a shopper whose selected currency is $active_currency.
	 *
	 * The Store API order also writes its amounts with two decimals (OrderSchema::get_totals() calls
	 * AbstractSchema::prepare_money_response(), whose decimals default to 2) and labels them with the active decimals
	 * (CurrencyFormatter::format(), currency_minor_unit from wc_get_price_decimals()), so a ¥1,000 order at 0 decimals would
	 * read as ¥100,000. Native multi-currency sets the active decimals through the wc_get_price_decimals filter at priority 900
	 * (MultiCurrencyFrontendCurrenciesController); the filter here stands in for it.
	 *
	 * @dataProvider order_pay_currency_cases
	 *
	 * @param string      $store_currency  Store currency option.
	 * @param string|null $active_currency Currency the woocommerce_currency filter returns, or null for no filter.
	 * @param string      $order_currency  Order currency.
	 * @param int|null    $active_decimals Price decimals the wc_get_price_decimals filter returns, or null for no filter.
	 * @param bool        $offered         Whether WooPay is offered for the order.
	 */
	public function test_order_pay_offers_woopay_only_in_the_order_currency_with_two_decimals( string $store_currency, ?string $active_currency, string $order_currency, ?int $active_decimals, bool $offered ): void {
		update_option( 'woocommerce_currency', $store_currency );
		$owner_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order    = \WC_Helper_Order::create_order( $owner_id );
		$order->set_currency( $order_currency );
		$order->save();
		wp_set_current_user( $owner_id );
		if ( null !== $active_currency ) {
			add_filter( 'woocommerce_currency', static fn() => $active_currency, 900 );
		}
		if ( null !== $active_decimals ) {
			add_filter( 'wc_get_price_decimals', static fn() => $active_decimals, 900 );
		}
		$this->sut = $this->create_controller( true );
		$this->set_order_pay_page( $order->get_id(), $order->get_order_key() );

		$this->sut->enqueue_frontend_assets();
		$config = $this->get_localized_woopay_config();
		$html   = $this->sut->get_express_checkout_button_html();

		if ( $offered ) {
			$this->assertSame( '1', $config['shouldShowWooPayButton'] );
			$this->assertSame( (string) $order->get_id(), $config['order_id'] );
			$this->assertSame( $order->get_order_key(), $config['key'] );
			$this->assertStringContainsString( 'id="wcpay-woopay-button"', $html );

			return;
		}

		$this->assertSame( '', $config['shouldShowWooPayButton'] );
		$this->assertArrayNotHasKey( 'order_id', $config );
		$this->assertArrayNotHasKey( 'key', $config );
		$this->assertArrayNotHasKey( 'billing_email', $config );
		$this->assertSame( '', $html );
	}

	/**
	 * Store, active and order currencies, active price decimals, and whether order-pay offers WooPay.
	 *
	 * @return array<string,array{0:string,1:string|null,2:string,3:int|null,4:bool}>
	 */
	public function order_pay_currency_cases(): array {
		return array(
			'USD store, no active currency, EUR order' => array( 'USD', null, 'EUR', null, false ),
			'USD store, EUR active, USD order'         => array( 'USD', 'EUR', 'USD', null, false ),
			'USD store, EUR active, EUR order'         => array( 'USD', 'EUR', 'EUR', null, true ),
			'EUR store, no active currency, EUR order' => array( 'EUR', null, 'EUR', null, true ),
			'JPY order and active, 0 decimals'         => array( 'USD', 'JPY', 'JPY', 0, false ),
			'BHD order and active, 3 decimals'         => array( 'USD', 'BHD', 'BHD', 3, false ),
			'JPY order and active, 2 decimals'         => array( 'USD', 'JPY', 'JPY', 2, true ),
		);
	}

	/**
	 * @testdox The WooPay button in the pay form follows the config even after multi-currency switches to the order's currency there.
	 *
	 * Native multi-currency returns the order's currency only once the pay form runs before_woocommerce_pay
	 * (MultiCurrencyFrontendCurrenciesController::init_order_currency_from_query_vars()), after the config is localized at
	 * wp_enqueue_scripts; the session request later formats the order in the shopper's currency.
	 */
	public function test_order_pay_button_follows_the_config_when_the_pay_form_switches_currency(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order    = \WC_Helper_Order::create_order( $owner_id );
		$order->set_currency( 'USD' );
		$order->save();
		wp_set_current_user( $owner_id );
		$active_currency = 'EUR';
		add_filter(
			'woocommerce_currency',
			static function () use ( &$active_currency ) {
				return $active_currency;
			},
			900
		);
		$this->sut = $this->create_controller( true );
		$this->set_order_pay_page( $order->get_id(), $order->get_order_key() );

		$this->sut->enqueue_frontend_assets();
		$this->assertSame( '', $this->get_localized_woopay_config()['shouldShowWooPayButton'] );
		$active_currency = 'USD';

		$this->assertSame( '', $this->sut->get_express_checkout_button_html() );
	}

	/**
	 * @testdox On an order's pay page the WooPay button pays the order even when the checkout page also carries a product shortcode.
	 *
	 * The classic script adds the product to the cart before WooPay when the button reads data-product_page="1" or its context
	 * is product (woopayments-woopay.js isProductPageWooPayButton(), prepareProductCartForWooPay()); the order context and
	 * the order's params keep it on the order.
	 */
	public function test_order_pay_with_a_product_shortcode_on_the_checkout_page_pays_the_order(): void {
		$product  = \WC_Helper_Product::create_simple_product( true );
		$owner_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order    = \WC_Helper_Order::create_order( $owner_id );
		wp_set_current_user( $owner_id );
		$this->sut = $this->create_controller( true );
		$this->set_order_pay_page( $order->get_id(), $order->get_order_key(), '[woocommerce_checkout] [product_page id="' . $product->get_id() . '"]' );

		$html = $this->sut->get_express_checkout_button_html();
		$this->sut->enqueue_frontend_assets();
		$config = $this->get_localized_woopay_config();

		$this->assertStringContainsString( '<div id="wcpay-woopay-button" data-product_page="0">', $html );
		$this->assertSame( 'pay_for_order', $config['woopayButton']['context'] );
		$this->assertSame( (string) $order->get_id(), $config['order_id'] );
		$this->assertSame( $order->get_order_key(), $config['key'] );
	}

	/**
	 * @testdox Offers WooPay for the order on order-pay when the checkout page holds the Checkout block.
	 *
	 * Core renders the classic checkout form there (src/Blocks/BlockTypes/Checkout.php render() on the order-pay endpoint);
	 * client 11.1.0 shows WooPay on that form whatever the checkout page holds (class-wc-payments-woopay-button-handler.php:245-322).
	 */
	public function test_order_pay_under_a_checkout_block_page_offers_woopay_for_the_order(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order    = \WC_Helper_Order::create_order( $owner_id );
		wp_set_current_user( $owner_id );
		$this->sut = $this->create_controller( true );
		$this->set_order_pay_page( $order->get_id(), $order->get_order_key(), '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->' );

		$this->assertStringContainsString( 'id="wcpay-woopay-button"', $this->sut->get_express_checkout_button_html() );
		$this->sut->enqueue_frontend_assets();
		$this->assertTrue( wp_script_is( 'wc-woopayments-woopay', 'enqueued' ) );
		$config = $this->get_localized_woopay_config();
		$this->assertSame( (string) $order->get_id(), $config['order_id'] );
		$this->assertSame( $order->get_order_key(), $config['key'] );

		unset( $GLOBALS['wp']->query_vars['order-pay'] );
		$this->assertSame( '', $this->sut->get_express_checkout_button_html(), 'The Checkout block page itself stays with the Blocks button.' );
	}

	/**
	 * @testdox Offers no WooPay on the Subscriptions change-payment page (the WooPayments extension class-wc-payments-woopay-button-handler.php:124-126).
	 *
	 * @testWith ["[woocommerce_checkout]"]
	 *           ["<!-- wp:woocommerce/checkout --><div class=\"wp-block-woocommerce-checkout\"></div><!-- /wp:woocommerce/checkout -->"]
	 *
	 * @param string $page_content Checkout page content.
	 */
	public function test_change_payment_page_offers_no_woopay( string $page_content ): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order    = \WC_Helper_Order::create_order( $owner_id );
		wp_set_current_user( $owner_id );
		$this->sut = $this->create_controller( true );
		$this->set_order_pay_page( $order->get_id(), $order->get_order_key(), $page_content );
		$_GET['change_payment_method'] = (string) $order->get_id();

		$this->assertSame( '', $this->sut->get_express_checkout_button_html() );
		$this->sut->enqueue_frontend_assets();
		$this->assertFalse( wp_script_is( 'wc-woopayments-woopay', 'enqueued' ) );
	}

	/**
	 * @testdox Should require WooPay user agent and a signed request.
	 */
	public function test_woopay_rest_route_requires_woopay_user_agent_and_signed_request(): void {
		$this->sut = $this->create_controller( true );
		$this->sut->register_routes();

		$missing_agent = new WP_REST_Request( 'GET', '/payments/woopay/session' );
		$missing_agent->set_param( 'email', 'shopper@example.com' );
		$this->assertSame( rest_authorization_required_code(), $this->server->dispatch( $missing_agent )->get_status() );

		$unsigned = new WP_REST_Request( 'GET', '/payments/woopay/session' );
		$unsigned->set_header( 'User-Agent', 'WooPay' );
		$unsigned->set_param( 'email', 'shopper@example.com' );
		$this->assertSame( rest_authorization_required_code(), $this->server->dispatch( $unsigned )->get_status() );

		$this->force_real_blog_token_signed();
		$signed = new WP_REST_Request( 'GET', '/payments/woopay/session' );
		$signed->set_header( 'User-Agent', 'WooPay' );
		$signed->set_param( 'email', 'shopper@example.com' );

		$this->assertSame( 200, $this->server->dispatch( $signed )->get_status() );
	}

	/**
	 * @testdox A signature admits the session route only on the signed path, and the signed-request filter can only restrict it, never grant it: $_dataName.
	 *
	 * The real blog-token check is false in tests unless the test signs the request. Client 11.2.0 accepts a signature only
	 * while the stored account keeps the store on the signed path (class-woopay-session.php:1007-1010;
	 * tests/unit/admin/test-class-wc-rest-woopay-session-controller.php:76-86).
	 *
	 * @dataProvider signed_request_filter_cases
	 *
	 * @param bool      $signed         Whether the real blog-token check passes.
	 * @param bool|null $filter         What the wcpay_woopay_is_signed_with_blog_token filter returns, or null for no filter.
	 * @param bool      $on_signed_path Whether the platform keeps the store on WooPay's signed path.
	 * @param bool      $allowed        Whether the request is allowed.
	 */
	public function test_has_permission_follows_the_signed_request_filter_only_to_restrict( bool $signed, ?bool $filter, bool $on_signed_path, bool $allowed ): void {
		$this->sut = $this->create_controller( true );
		$this->woopay_eligibility->flags->force_signed_requests = $on_signed_path;
		if ( $signed ) {
			$this->force_real_blog_token_signed();
		}
		if ( null !== $filter ) {
			add_filter( 'wcpay_woopay_is_signed_with_blog_token', $filter ? '__return_true' : '__return_false' );
		}

		$request = new WP_REST_Request( 'GET', '/payments/woopay/session' );
		$request->set_header( 'User-Agent', 'WooPay' );
		$request->set_param( 'email', 'shopper@example.com' );

		$result = $this->sut->has_permission( $request );

		if ( $allowed ) {
			$this->assertTrue( $result );

			return;
		}

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_cannot_view', $result->get_error_code() );
	}

	/**
	 * Real signature check, filter answer, signed path and whether the request is allowed.
	 *
	 * @return array<string,array{0:bool,1:bool|null,2:bool,3:bool}>
	 */
	public function signed_request_filter_cases(): array {
		return array(
			'unsigned, filter says signed' => array( false, true, true, false ),
			'signed, no filter'            => array( true, null, true, true ),
			'signed, filter says unsigned' => array( true, false, true, false ),
			'signed, off the signed path'  => array( true, null, false, false ),
			'signed, filter says signed, off the signed path' => array( true, true, false, false ),
		);
	}

	/**
	 * @testdox Should preserve the one-argument shape of the signed-request filter.
	 */
	public function test_has_permission_preserves_signed_request_filter_arg_shape(): void {
		$this->sut = $this->create_controller( true );
		$this->force_real_blog_token_signed();
		$captured_args = null;

		add_filter(
			'wcpay_woopay_is_signed_with_blog_token',
			static function ( ...$args ) use ( &$captured_args ) {
				$captured_args = $args;
				return $args[0];
			},
			10,
			99
		);

		$request = new WP_REST_Request( 'GET', '/payments/woopay/session' );
		$request->set_header( 'User-Agent', 'WooPay' );
		$request->set_param( 'email', 'shopper@example.com' );

		$this->assertTrue( $this->sut->has_permission( $request ) );
		$this->assertIsArray( $captured_args );
		$this->assertCount( 1, $captured_args, 'The preserved WooPay signed-token filter should receive only the boolean signed state.' );
		$this->assertTrue( $captured_args[0] );
	}

	/**
	 * A failure assembling the session answers the extension's 400 wcpay_server_error with the exception's message and
	 * logs it at info when debug logging is on (class-wc-rest-woopay-session-controller.php:67-71).
	 *
	 * @testdox Should return the exception's message and log it only with logging $logging_enabled when WooPay session assembly throws.
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $logging_enabled Whether WooPayments debug logging is on.
	 */
	public function test_get_session_logs_and_returns_error_when_assembly_throws( bool $logging_enabled ): void {
		$service                 = new ThrowingWooPaySessionService();
		$service->woopay_enabled = true;
		$this->sut               = $this->create_controller( true, $service );
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => $logging_enabled ? 'yes' : 'no' ) );

		$logged = array();
		add_filter(
			'woocommerce_logger_log_message',
			static function ( $message, $level, $context ) use ( &$logged ) {
				$logged[] = array(
					'message' => $message,
					'level'   => $level,
					'context' => $context,
				);

				return $message;
			},
			10,
			3
		);

		$request = new WP_REST_Request( 'GET', '/payments/woopay/session' );
		$request->set_param( 'email', 'shopper@example.com' );

		$result = $this->sut->get_session( $request );

		remove_all_filters( 'woocommerce_logger_log_message' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wcpay_server_error', $result->get_error_code() );
		$this->assertSame( 'Unable to read the cart.', $result->get_error_message() );
		$this->assertSame( 400, $result->get_error_data()['status'] );

		$messages = wp_list_pluck( $logged, 'message' );
		if ( $logging_enabled ) {
			$this->assertContains( 'Error validating cart token from WooPay request: Unable to read the cart.', $messages );
		} else {
			$this->assertSame( array(), preg_grep( '/WooPay request/', $messages ) );
		}
	}

	/**
	 * Client 11.1.0 catches only an Exception there (class-wc-rest-woopay-session-controller.php:67), so a PHP Error never
	 * hands WooPay its text, which can name a server path.
	 *
	 * @testdox Should answer a fixed message, not the error's text, when WooPay session assembly raises a PHP error.
	 */
	public function test_get_session_hides_a_php_error_text(): void {
		$service            = new ThrowingWooPaySessionService();
		$service->throwable = new \TypeError( 'Argument #1 must be of type array, null given, called in /var/www/html/wp-content/plugins/an-extension/cart.php on line 12' );
		$this->sut          = $this->create_controller( true, $service );

		$result = $this->sut->get_session( new WP_REST_Request( 'GET', '/payments/woopay/session' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wcpay_server_error', $result->get_error_code() );
		$this->assertSame( 'Unexpected server error', $result->get_error_message() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * @testdox Should return session data for signed WooPay requests.
	 */
	public function test_rest_session_route_returns_session_data_for_signed_woopay_request(): void {
		$service   = new RecordingWooPaySessionService();
		$this->sut = $this->create_controller( true, $service );
		$this->sut->register_routes();
		$this->force_real_blog_token_signed();

		$request = new WP_REST_Request( 'GET', '/payments/woopay/session' );
		$request->set_header( 'User-Agent', 'WooPay' );
		$request->set_param( 'email', 'shopper@example.com' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'session' => 'native' ), $response->get_data() );
		$this->assertSame( 'shopper@example.com', $service->last_session_email );
		$this->assertSame( $request, $service->last_session_request, 'The session data is built for the WooPay request, whose Cart-Token it reads.' );
	}

	/**
	 * @testdox The session route answers $_dataName.
	 *
	 * Client 11.2.0 class-wc-rest-woopay-session-controller.php:35-61 (GET and POST, `email` optional) and :100-129 (WooPay's
	 * user agent, then a signature on the signed path or a fresh attestation; a Cart-Token is refused with its own log line);
	 * class-woopay-session.php:1069-1156 (the envelope opens only under the attestation key and is at most 300 seconds
	 * old, read from a JSON or form body, never the query string); tests/unit/admin/test-class-wc-rest-woopay-session-controller.php:76-175.
	 *
	 * @dataProvider session_route_credentials_provider
	 *
	 * @param string      $method         HTTP method.
	 * @param string      $credential     'attestation' (JSON body), 'attestation in a form body', 'attestation in the query string',
	 *                                    'stale attestation', 'blog-token envelope', 'cart token' or 'signature'.
	 * @param bool        $on_signed_path Whether the platform keeps the store on WooPay's signed path.
	 * @param int         $status         Expected response status.
	 * @param string|null $log_line       Expected denial log line, or null when none is expected.
	 */
	public function test_session_route_admits_a_signature_on_the_signed_path_or_a_fresh_attestation( string $method, string $credential, bool $on_signed_path, int $status, ?string $log_line ): void {
		WooPayEnvelopes::connect_store();
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		$logged    = $this->record_log_messages();
		$this->sut = $this->create_controller( true );
		$this->woopay_eligibility->flags->force_signed_requests = $on_signed_path;
		$this->sut->register_routes();
		$request = new WP_REST_Request( $method, '/payments/woopay/session' );
		$request->set_header( 'User-Agent', 'WooPay' );
		if ( 'signature' === $credential ) {
			$this->force_real_blog_token_signed();
		} elseif ( 'cart token' === $credential ) {
			$_SERVER['HTTP_CART_TOKEN'] = \Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils::get_cart_token( 't_guesthash' );
		} elseif ( 'attestation in a form body' === $credential ) {
			$request->set_body_params( array( 'woopay_attestation' => WooPayEnvelopes::seal( array( 'timestamp' => time() ), 'woopay-attestation-v1' ) ) );
		} elseif ( 'attestation in the query string' === $credential ) {
			$request->set_query_params( array( 'woopay_attestation' => WooPayEnvelopes::seal( array( 'timestamp' => time() ), 'woopay-attestation-v1' ) ) );
		} else {
			$envelope = array(
				'attestation'         => WooPayEnvelopes::seal( array( 'timestamp' => time() ), 'woopay-attestation-v1' ),
				'stale attestation'   => WooPayEnvelopes::seal( array( 'timestamp' => time() - 301 ), 'woopay-attestation-v1' ),
				'blog-token envelope' => WooPayEnvelopes::seal( array( 'timestamp' => time() ), null ),
			)[ $credential ];
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( array( 'woopay_attestation' => $envelope ) ) );
		}

		try {
			$response = $this->server->dispatch( $request );
		} finally {
			unset( $_SERVER['HTTP_CART_TOKEN'] );
		}

		$this->assertSame( $status, $response->get_status() );
		if ( 200 === $status ) {
			$this->assertSame( array( 'session' => 'native' ), $response->get_data() );
		}
		if ( null !== $log_line ) {
			$this->assertContains( $log_line, $logged->getArrayCopy() );
		}
	}

	/**
	 * Session-route requests: method, credential, signed path, status and denial log line.
	 *
	 * @return array<string,array{0:string,1:string,2:bool,3:int,4:string|null}>
	 */
	public function session_route_credentials_provider(): array {
		$denied = 'WooPay session route denied: no signature and no usable attestation.';

		return array(
			'a POST with a fresh attestation and no email' => array( 'POST', 'attestation', false, 200, null ),
			'a POST with a fresh attestation in a form body' => array( 'POST', 'attestation in a form body', false, 200, null ),
			'a POST with a fresh attestation only in the query string' => array( 'POST', 'attestation in the query string', false, 401, $denied ),
			'a POST with an attestation 301 seconds old'   => array( 'POST', 'stale attestation', false, 401, $denied ),
			'a POST with an envelope sealed under the blog token itself' => array( 'POST', 'blog-token envelope', false, 401, $denied ),
			'a POST carrying only a Cart-Token'            => array( 'POST', 'cart token', false, 401, 'WooPay session route denied: a Cart-Token does not authorize this route, which needs a signature or an attestation.' ),
			'a signed GET with no email on the signed path' => array( 'GET', 'signature', true, 200, null ),
			'a signed GET off the signed path'             => array( 'GET', 'signature', false, 401, $denied ),
		);
	}

	/**
	 * @testdox The session route refuses an attestation it has already accepted once: $_dataName.
	 *
	 * Client 11.2.0 class-woopay-session.php:1432-1448 spends the envelope on its first use, through wp_cache_add() on an
	 * external object cache and set_transient() otherwise, keyed on the HMAC's decoded bytes (:1110-1122), so another
	 * spelling of the same envelope is the same claim; tests/unit/admin/test-class-wc-rest-woopay-session-controller.php:178-220
	 * and tests/unit/woopay/test-class-woopay-session.php:808-833. The replay is a new request, so it gets a new server and
	 * handler, as a new PHP request would.
	 *
	 * @dataProvider replayed_attestation_provider
	 *
	 * @param string $respelling      How the replay spells the envelope's `hash` field: 'as sent', 'padding dropped' or 'whitespace inserted'.
	 * @param bool   $ext_object_cache Whether the store runs an external object cache.
	 */
	public function test_session_route_refuses_a_replayed_attestation( string $respelling, bool $ext_object_cache ): void {
		WooPayEnvelopes::connect_store();
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		$logged   = $this->record_log_messages();
		$envelope = WooPayEnvelopes::seal(
			array(
				'timestamp'  => time(),
				'user_email' => 'shopper@example.com',
			),
			'woopay-attestation-v1'
		);
		$replay   = $envelope;
		if ( 'padding dropped' === $respelling ) {
			$replay['hash'] = rtrim( $envelope['hash'], '=' );
		} elseif ( 'whitespace inserted' === $respelling ) {
			$replay['hash'] = substr( $envelope['hash'], 0, 8 ) . ' ' . substr( $envelope['hash'], 8 );
		}
		$this->assertSame( 'as sent' === $respelling, $replay['hash'] === $envelope['hash'], 'The spelling under test is what the row says.' );
		$was_using_ext_object_cache = wp_using_ext_object_cache( $ext_object_cache );
		$statuses                   = array();

		try {
			foreach ( array( $envelope, $replay ) as $sent ) {
				$this->server              = new \WC_Lazy_REST_Server();
				$GLOBALS['wp_rest_server'] = $this->server;
				$this->sut                 = $this->create_controller( true );
				$this->sut->register_routes();
				$request = new WP_REST_Request( 'POST', '/payments/woopay/session' );
				$request->set_header( 'User-Agent', 'WooPay' );
				$request->set_header( 'Content-Type', 'application/json' );
				$request->set_body( (string) wp_json_encode( array( 'woopay_attestation' => $sent ) ) );
				$statuses[] = $this->server->dispatch( $request )->get_status();
			}
		} finally {
			wp_using_ext_object_cache( $was_using_ext_object_cache );
		}

		$this->assertSame( array( 200, 401 ), $statuses );
		$this->assertContains( 'WooPay attestation rejected: envelope has already been used, or its claim could not be recorded.', $logged->getArrayCopy() );
	}

	/**
	 * Replays: the spelling of the replayed envelope and the store's claim backend.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function replayed_attestation_provider(): array {
		return array(
			'the same envelope, database transients'      => array( 'as sent', false ),
			'the same envelope, an external object cache' => array( 'as sent', true ),
			'its base64 padding dropped, database transients' => array( 'padding dropped', false ),
			'a space inserted in its hash, database transients' => array( 'whitespace inserted', false ),
		);
	}

	/**
	 * @testdox The session route serves adapted-extension balances, and the nonce that unlocks them, only for an email WooPay named: $_dataName.
	 *
	 * Client 11.2.0 class-woopay-session.php:523-530 (the attested email outranks request parameters) and :715-746 (the
	 * balances and email_verified_session_nonce follow the attested email, or on a signed request the email it sends);
	 * tests/unit/woopay/test-class-woopay-session.php:871-878, :953-1100.
	 *
	 * @dataProvider session_route_balance_email_provider
	 *
	 * @param string|null $attested_email Email the attestation names, or null for a signed request.
	 * @param bool        $guest          Whether the attestation names no account at all.
	 * @param string|null $expected_email Email the balances and the nonce follow, or null for none.
	 */
	public function test_session_route_serves_balances_only_for_an_email_woopay_named( ?string $attested_email, bool $guest, ?string $expected_email ): void {
		WooPayEnvelopes::connect_store();
		$named_id   = $this->factory->user->create( array( 'user_email' => 'named@example.com' ) );
		$param_id   = $this->factory->user->create( array( 'user_email' => 'parameter@example.com' ) );
		$extensions = $this->createMock( AdaptedExtensions::class );
		$extensions->method( 'get_extension_data' )->willReturn( array() );
		$extensions->method( 'get_adapted_extensions_data' )->willReturnCallback(
			static fn( string $email ): array => array( 'points-and-rewards' => array( 'points_for' => $email ) )
		);
		$this->sut = $this->create_controller( true, $this->create_real_enabled_session_service( true, $extensions ) );
		$this->sut->register_routes();
		$request = new WP_REST_Request( null === $attested_email && ! $guest ? 'GET' : 'POST', '/payments/woopay/session' );
		$request->set_header( 'User-Agent', 'WooPay' );
		$request->set_param( 'email', 'parameter@example.com' );
		if ( null === $attested_email && ! $guest ) {
			$this->force_real_blog_token_signed();
		} else {
			$payload = array( 'timestamp' => time() );
			if ( ! $guest ) {
				$payload['user_email'] = $attested_email;
			}
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body(
				(string) wp_json_encode(
					array(
						'woopay_attestation' => WooPayEnvelopes::seal( $payload, 'woopay-attestation-v1' ),
						'email'              => 'parameter@example.com',
					)
				)
			);
		}

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $data );
		$this->assertSame( $expected_email ?? 'parameter@example.com', $data['email'], 'The attested email outranks the parameter.' );
		if ( null === $expected_email ) {
			$this->assertArrayNotHasKey( 'adapted_extensions', $data );
			$this->assertArrayNotHasKey( 'email_verified_session_nonce', $data );

			return;
		}

		$expected_user = 'named@example.com' === $expected_email ? $named_id : $param_id;
		$this->assertSame( array( 'points-and-rewards' => array( 'points_for' => $expected_email ) ), $data['adapted_extensions'] );
		$this->assertSame( substr( wp_hash( wp_nonce_tick( 'wc_store_api' ) . '|wc_store_api|' . $expected_user . '|', 'nonce' ), -12, 10 ), $data['email_verified_session_nonce'] );
	}

	/**
	 * Session-route requests naming an email: the attested one, guest, and the email the balances follow.
	 *
	 * @return array<string,array{0:string|null,1:bool,2:string|null}>
	 */
	public function session_route_balance_email_provider(): array {
		return array(
			'an attested account and another email in the parameter' => array( 'named@example.com', false, 'named@example.com' ),
			'a guest attestation and an email in the parameter'      => array( null, true, null ),
			'a signed request on the signed path naming the email in its parameter' => array( null, false, 'parameter@example.com' ),
		);
	}

	/**
	 * Record the message of every WooPayments log line written until the test ends.
	 *
	 * @return \ArrayObject<int,string>
	 */
	private function record_log_messages(): \ArrayObject {
		$messages = new \ArrayObject();
		add_filter(
			'woocommerce_logger_log_message',
			static function ( $message, $level, $context ) use ( $messages ) {
				unset( $level );
				if ( 'woopayments' === ( $context['source'] ?? '' ) ) {
					$messages[] = (string) $message;
				}

				return $message;
			},
			10,
			3
		);

		return $messages;
	}

	/**
	 * @testdox Should build WooPay AJAX-compatible response payloads.
	 */
	public function test_builds_ajax_response_payloads(): void {
		$service   = new RecordingWooPaySessionService();
		$this->sut = $this->create_controller( true, $service );
		$this->sut->register();

		$this->assertSame( array( 'result' => 'success' ), $this->dispatch_ajax_hook_with_nonce( 'wc_ajax_wcpay_init_woopay', 'wcpay_init_woopay_nonce', array( 'email' => 'shopper@example.com' ) ) );
		$this->assertSame( array( 'encrypted' => 'session' ), $this->dispatch_ajax_hook_with_nonce( 'wc_ajax_wcpay_get_woopay_session', 'woopay_session_nonce', array( 'email' => 'shopper@example.com' ) ) );
		$this->assertSame( array( 'result' => 'success' ), $this->dispatch_ajax_hook_with_nonce( 'wc_ajax_wcpay_set_woopay_phone_number', 'woopay_session_nonce', array( 'phone_number' => '+15555550123' ) ) );
		$this->assertSame(
			array(
				'success' => true,
				'data'    => array( 'signature' => 'signed' ),
			),
			$this->dispatch_ajax_hook_with_nonce( 'wc_ajax_wcpay_get_woopay_signature', 'woopay_signature_nonce', array() )
		);
		$this->assertSame( '+15555550123', $service->last_phone_request['phone_number'] );
	}

	/**
	 * Client 11.2.0 `WC_Payments_WooPay_Session::ajax_get_woopay_session()` (class-woopay-session.php:868-885) answers
	 * `wp_send_json_error( 'Could not determine the blog ID.', 503 )` before it builds a session; native checks the blog
	 * token too, which signs the session. The status is not readable here (see dispatch_ajax_hook()).
	 *
	 * @testdox The WooPay session request is refused without building a session when the store's Jetpack $missing is empty.
	 * @testWith ["blog ID", "", "token-key.blog-token"]
	 *           ["blog token", "12345", ""]
	 *
	 * @param string $missing    The Jetpack option left empty.
	 * @param string $blog_id    Store blog ID.
	 * @param string $blog_token Store blog token.
	 */
	public function test_session_request_is_refused_without_the_blog_id_or_token( string $missing, string $blog_id, string $blog_token ): void {
		unset( $missing );
		\Jetpack_Options::update_option( 'id', $blog_id );
		\Jetpack_Options::update_option( 'blog_token', $blog_token );
		$this->sut = $this->create_controller( true, $this->create_real_enabled_session_service() );
		$this->sut->register();

		$this->assertSame(
			array(
				'success' => false,
				'data'    => 'Could not determine the blog ID.',
			),
			$this->dispatch_ajax_hook_with_nonce( 'wc_ajax_wcpay_get_woopay_session', 'woopay_session_nonce', array( 'email' => 'shopper@example.com' ) )
		);
	}

	/**
	 * WordPress slashes the posted fields; the WooPay scripts post the shopper's email, the WooPay user session and the
	 * pay-for-order fields (client 11.1.0 checkout/woopay/init-woopay.js:54-63, checkout/woopay/email-input-iframe.js:191-199).
	 *
	 * @testdox The $hook handler hands the session service the posted fields, unslashed.
	 * @testWith ["wc_ajax_wcpay_init_woopay", "wcpay_init_woopay_nonce", "last_init_request"]
	 *           ["wc_ajax_wcpay_get_woopay_session", "woopay_session_nonce", "last_encrypted_session_request"]
	 *
	 * @param string $hook         AJAX hook.
	 * @param string $nonce_action Nonce the handler checks.
	 * @param string $recorded     Property of the session service double that records the request.
	 */
	public function test_session_handlers_pass_the_unslashed_request( string $hook, string $nonce_action, string $recorded ): void {
		$service   = new RecordingWooPaySessionService();
		$this->sut = $this->create_controller( true, $service );
		$this->sut->register();
		$fields = array(
			'email'         => "o'brien@example.com",
			'user_session'  => 'session "token"',
			'order_id'      => '123',
			'key'           => 'wc_order_abc',
			'billing_email' => "o'brien@example.com",
		);

		$this->dispatch_ajax_hook_with_nonce( $hook, $nonce_action, wp_slash( $fields ) );

		$this->assertSame( $fields, array_intersect_key( $service->$recorded, $fields ) );
	}

	/**
	 * Unticking "Save my information" posts empty=1 (client 11.1.0 components/woopay/save-user/checkout-page-save-user.js:115-160).
	 *
	 * @testdox A signed-in shopper's empty phone-number request clears the WooPay data stored in the session.
	 */
	public function test_empty_phone_number_request_clears_the_stored_woopay_data(): void {
		$service   = new RecordingWooPaySessionService();
		$this->sut = $this->create_controller( true, $service );
		$this->sut->register();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		// The session key the WooPayments extension stores the shopper's WooPay data under (class-woopay-session.php:36).
		WC()->session->set( 'woopay-user-data', array( 'save_user_in_woopay' => true ) );

		$response = $this->dispatch_ajax_hook_with_nonce( 'wc_ajax_wcpay_set_woopay_phone_number', 'woopay_session_nonce', array( 'empty' => '1' ) );

		$this->assertSame( array( 'result' => 'success' ), $response );
		$this->assertNull( $service->get_woopay_session_data() );
		$this->assertSame( array(), $service->last_phone_request );
	}

	/**
	 * @testdox The admin and shopper appearance writes store the posted appearance.
	 */
	public function test_appearance_writes_store_the_posted_appearance(): void {
		$service   = new RecordingWooPaySessionService();
		$this->sut = $this->create_controller( true, $service );
		$this->sut->register();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->post_appearance_request( 'wcpay_admin_woopay_appearance_nonce' );
		$admin = $this->dispatch_ajax_hook( 'wp_ajax_wcpay_admin_set_woopay_appearance' );
		$this->post_appearance_request( 'woopay_session_nonce' );
		$shopper = $this->dispatch_ajax_hook( 'wc_ajax_wcpay_shopper_set_woopay_appearance' );

		$this->assertSame( array( 'success' => true ), $admin['body'] );
		$this->assertSame(
			array(
				'success' => true,
				'data'    => array( 'stored' => true ),
			),
			$shopper['body']
		);
		$this->assertSame( 2, $service->appearance_writes );
		$this->assertSame( $this->get_valid_appearance(), $service->last_appearance );
	}

	/**
	 * @testdox The $label handler refuses a request without its nonce or capability and changes nothing.
	 * @dataProvider guarded_ajax_requests
	 *
	 * Client 11.1.0 checks the same nonces (class-woopay-session.php ajax_* handlers, class-wc-payments-woopay-button-handler.php
	 * show_error_notice) and manage_woocommerce for the admin appearance write (class-woopay-session.php:1211-1217).
	 *
	 * @param string              $label           Case label.
	 * @param string              $hook            AJAX hook the controller registers.
	 * @param string              $role            Role of the requesting user.
	 * @param string|null         $nonce_action    Nonce posted, or null for a wrong one.
	 * @param array<string,mixed> $post            Other posted fields.
	 * @param mixed               $expected_body   Decoded answer.
	 * @param int|null            $expected_status Status of a wp_die() answer.
	 */
	public function test_ajax_handler_guard_refuses_the_request( string $label, string $hook, string $role, ?string $nonce_action, array $post, $expected_body, ?int $expected_status ): void {
		unset( $label );
		$service   = new RecordingWooPaySessionService();
		$this->sut = $this->create_controller( true, $service );
		$this->sut->register();
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		$nonce    = null === $nonce_action ? 'not-a-valid-nonce' : wp_create_nonce( $nonce_action );
		$_POST    = array_merge( // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$post,
			array(
				'_ajax_nonce' => $nonce,
				'security'    => $nonce,
			)
		);
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$response = $this->dispatch_ajax_hook( $hook );

		$this->assertSame( $expected_body, $response['body'] );
		if ( null !== $expected_status ) {
			$this->assertSame( $expected_status, $response['status'] );
		}
		$this->assertSame( array(), $service->last_phone_request );
		$this->assertSame( 0, $service->appearance_writes );
		$this->assertSame( 0, wc_notice_count() );
		$this->assertTrue( WC()->cart->is_empty() );
	}

	/** @return array<string,array{string,string,string,string|null,array<string,mixed>,mixed,int|null}> */
	public function guarded_ajax_requests(): array {
		$failure     = array( 'result' => 'failure' );
		$error       = array(
			'success' => false,
			'data'    => $failure,
		);
		$appearance  = array(
			'appearance' => array(
				'theme'     => 'stripe',
				'variables' => array( 'colorText' => '#111111' ),
			),
		);
		$product_id  = array(
			'product_id' => '1',
			'quantity'   => '1',
		);
		$notice      = array( 'message' => 'WooPay is unavailable.' );
		$not_allowed = array(
			'success' => false,
			'data'    => 'You aren’t authorized to do that.',
		);

		return array(
			'init WooPay'                 => array( 'init WooPay', 'wc_ajax_wcpay_init_woopay', 'customer', null, array( 'email' => 'shopper@example.com' ), $failure, null ),
			'WooPay session'              => array( 'WooPay session', 'wc_ajax_wcpay_get_woopay_session', 'customer', null, array( 'email' => 'shopper@example.com' ), $failure, null ),
			'WooPay phone'                => array( 'WooPay phone', 'wc_ajax_wcpay_set_woopay_phone_number', 'customer', null, array( 'phone_number' => '+15555550123' ), $failure, null ),
			'WooPay signature'            => array( 'WooPay signature', 'wc_ajax_wcpay_get_woopay_signature', 'customer', null, array(), $error, null ),
			'admin appearance, no nonce'  => array( 'admin appearance, no nonce', 'wp_ajax_wcpay_admin_set_woopay_appearance', 'administrator', null, $appearance, $error, null ),
			'admin appearance, a shopper' => array( 'admin appearance, a shopper', 'wp_ajax_wcpay_admin_set_woopay_appearance', 'customer', 'wcpay_admin_woopay_appearance_nonce', $appearance, $error, null ),
			'shopper appearance'          => array( 'shopper appearance', 'wc_ajax_wcpay_shopper_set_woopay_appearance', 'customer', null, $appearance, $error, null ),
			'product add-to-cart'         => array( 'product add-to-cart', 'wc_ajax_wcpay_add_to_cart', 'customer', null, $product_id, '-1', 403 ),
			'error notice'                => array( 'error notice', 'wp_ajax_woopay_express_checkout_button_show_error_notice', 'customer', null, $notice, $not_allowed, null ),
			'error notice, logged out'    => array( 'error notice, logged out', 'wp_ajax_nopriv_woopay_express_checkout_button_show_error_notice', 'customer', null, $notice, $not_allowed, null ),
		);
	}

	/**
	 * @testdox Should render the posted message as a frontend error notice for a request with the WooPay button nonce.
	 */
	public function test_show_error_notice_ajax_renders_the_posted_message_with_the_button_nonce(): void {
		$this->sut = $this->create_controller( true );
		$this->sut->register();

		$authorized = $this->dispatch_ajax_hook_with_nonce( 'wp_ajax_woopay_express_checkout_button_show_error_notice', 'woopay_button_nonce', array( 'message' => 'WooPay is unavailable.' ) );

		$this->assertIsArray( $authorized );
		$this->assertTrue( $authorized['success'] );
		$this->assertStringContainsString( 'WooPay is unavailable.', $authorized['data']['notice'] );
	}

	/**
	 * @testdox The $label appearance write is refused and writes nothing while WooPay global theme support is off.
	 * @dataProvider appearance_write_hooks
	 *
	 * Client 11.1.0 class-woopay-session.php:1219-1224 (admin) and :1273-1278 (shopper).
	 *
	 * @param string $label        Case label.
	 * @param string $hook         AJAX hook.
	 * @param string $nonce_action Nonce action the handler checks.
	 */
	public function test_appearance_write_needs_global_theme_support( string $label, string $hook, string $nonce_action ): void {
		unset( $label );
		$service   = new RecordingWooPaySessionService();
		$this->sut = $this->create_controller( true, $service );
		$this->sut->register();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$service->global_theme_support_enabled = false;
		$this->post_appearance_request( $nonce_action );
		$off = $this->dispatch_ajax_hook( $hook );

		$this->assertSame(
			array(
				'success' => false,
				'data'    => 'This action is not available.',
			),
			$off['body']
		);
		$this->assertSame( 0, $service->appearance_writes );

		$service->global_theme_support_enabled = true;
		$this->post_appearance_request( $nonce_action );
		$on = $this->dispatch_ajax_hook( $hook );

		$this->assertTrue( $on['body']['success'] ?? false );
		$this->assertSame( 1, $service->appearance_writes );
	}

	/** @return array<string,array{string,string,string}> */
	public function appearance_write_hooks(): array {
		return array(
			'admin'   => array( 'admin', 'wp_ajax_wcpay_admin_set_woopay_appearance', 'wcpay_admin_woopay_appearance_nonce' ),
			'shopper' => array( 'shopper', 'wc_ajax_wcpay_shopper_set_woopay_appearance', 'woopay_session_nonce' ),
		);
	}

	/**
	 * @testdox Should report false when shopper appearance was already stored.
	 */
	public function test_shopper_appearance_response_reports_when_appearance_slot_is_filled(): void {
		$service                    = new RecordingWooPaySessionService();
		$service->appearance_stored = false;
		$this->sut                  = $this->create_controller( true, $service );
		$this->sut->register();
		$this->post_appearance_request( 'woopay_session_nonce' );

		$response = $this->dispatch_ajax_hook( 'wc_ajax_wcpay_shopper_set_woopay_appearance' );

		$this->assertSame( array( 'stored' => false ), $response['body']['data'] ?? null );
	}

	/**
	 * @testdox Product-page add-to-cart should preserve selected variable product attributes.
	 */
	public function test_add_to_cart_preserves_top_level_variation_attributes(): void {
		if ( ! function_exists( 'wc_load_cart' ) ) {
			$this->markTestSkipped( 'Cart bootstrap is unavailable.' );
		}
		wc_load_cart();

		$product      = \WC_Helper_Product::create_variation_product();
		$variation_id = 0;
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( $variation && 'huge' === $variation->get_attribute( 'pa_size' ) && 'blue' === $variation->get_attribute( 'pa_colour' ) && '' === $variation->get_attribute( 'pa_number' ) ) {
				$variation_id = $child_id;
				break;
			}
		}
		$this->assertGreaterThan( 0, $variation_id );

		$this->sut = $this->create_controller( true );
		$_POST     = array( // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'security'            => wp_create_nonce( 'wcpay-add-to-cart' ),
			'product_id'          => (string) $product->get_id(),
			'variation_id'        => (string) $variation_id,
			'quantity'            => '1',
			'attribute_pa_size'   => 'huge',
			'attribute_pa_colour' => 'blue',
			'attribute_pa_number' => '2',
		);
		$_REQUEST  = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$this->sut->register();

		$response = $this->dispatch_ajax_hook( 'wc_ajax_wcpay_add_to_cart' )['body'];
		$cart     = WC()->cart->get_cart();
		$item     = reset( $cart );

		$this->assertIsArray( $response );
		$this->assertSame( 'success', $response['result'] );
		$this->assertSame( $variation_id, $item['variation_id'] );
		$this->assertSame(
			array(
				'attribute_pa_size'   => 'huge',
				'attribute_pa_colour' => 'blue',
				'attribute_pa_number' => '2',
			),
			$item['variation']
		);
	}

	/**
	 * Provides WooPay product quantities and their expected cart values.
	 *
	 * @return array<string,array{0:mixed,1:int|float,2:bool,3:?string}>
	 */
	public function add_to_cart_quantities_provider(): array {
		return array(
			'decimal quantity'           => array( '0.25', 0.25, true, null ),
			'localized decimal quantity' => array( '0,25', 0.25, true, ',' ),
			'default integer quantity'   => array( '3', 3, false, null ),
			'mixed text quantity'        => array( 'abc3', 1, true, null ),
			'numeric prefix quantity'    => array( '0.25junk', 1, true, null ),
			'non-scalar quantity'        => array( array( '0.25' ), 1, true, null ),
		);
	}

	/**
	 * @testdox Product-page add-to-cart should normalize $request_quantity to $expected_quantity.
	 * @dataProvider add_to_cart_quantities_provider
	 *
	 * @param mixed       $request_quantity  Quantity received from the product form.
	 * @param int|float   $expected_quantity Expected cart quantity.
	 * @param bool        $fractional_store  Whether the store accepts fractional stock amounts.
	 * @param string|null $decimal_separator Optional store decimal separator.
	 */
	public function test_add_to_cart_normalizes_quantity( $request_quantity, $expected_quantity, bool $fractional_store, ?string $decimal_separator ): void {
		if ( ! function_exists( 'wc_load_cart' ) ) {
			$this->markTestSkipped( 'Cart bootstrap is unavailable.' );
		}
		wc_load_cart();

		if ( $fractional_store ) {
			remove_filter( 'woocommerce_stock_amount', 'intval' );
			add_filter( 'woocommerce_stock_amount', 'floatval' );
		}

		$decimal_separator_filter = static function () use ( $decimal_separator ): string {
			return (string) $decimal_separator;
		};
		if ( null !== $decimal_separator ) {
			add_filter( 'wc_get_price_decimal_separator', $decimal_separator_filter );
		}

		try {
			$product   = \WC_Helper_Product::create_simple_product();
			$this->sut = $this->create_controller( true );
			$_POST     = array( // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'security'   => wp_create_nonce( 'wcpay-add-to-cart' ),
				'product_id' => (string) $product->get_id(),
				'quantity'   => $request_quantity,
			);
			$_REQUEST  = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$this->sut->register();

			$response   = $this->dispatch_ajax_hook( 'wc_ajax_wcpay_add_to_cart' )['body'];
			$cart_items = WC()->cart->get_cart();
			$item       = reset( $cart_items );

			$this->assertIsArray( $response );
			$this->assertSame( 'success', $response['result'] );
			$this->assertSame( $expected_quantity, $item['quantity'] );
		} finally {
			if ( null !== $decimal_separator ) {
				remove_filter( 'wc_get_price_decimal_separator', $decimal_separator_filter );
			}
			if ( $fractional_store ) {
				remove_filter( 'woocommerce_stock_amount', 'floatval' );
				add_filter( 'woocommerce_stock_amount', 'intval' );
			}
		}
	}

	/**
	 * Create the System Under Test.
	 *
	 * @param bool                $woopay_enabled Whether WooPay should be enabled.
	 * @param SessionService|null $service        Optional service double.
	 * @return SessionController
	 */
	private function create_controller( bool $woopay_enabled, ?SessionService $service = null ): SessionController {
		$service = $service ?? new RecordingWooPaySessionService();
		if ( $service instanceof RecordingWooPaySessionService ) {
			$service->woopay_enabled  = $woopay_enabled;
			$this->woopay_eligibility = new RecordingWooPayEligibility( $service );
			$this->appearance_store   = new RecordingWooPayAppearanceStore( $service );
		}
		$this->store_api_request_handler = new StoreApiRequestHandler();
		$this->store_api_request_handler->init( $this->woopay_eligibility, new AdaptedExtensions(), wc_get_container()->get( WooPaymentsLogger::class ), $service );

		$controller = new SessionController();
		$controller->init( $service, $this->woopay_eligibility, $this->appearance_store, $this->store_api_request_handler );

		return $controller;
	}

	/**
	 * Create a real enabled session service for controller integration tests.
	 *
	 * @param bool                   $test_mode          Whether the account reports test mode.
	 * @param AdaptedExtensions|null $adapted_extensions Adapted extensions registry, or null for a real empty one.
	 * @return SessionService
	 */
	private function create_real_enabled_session_service( bool $test_mode = true, ?AdaptedExtensions $adapted_extensions = null ): SessionService {
		$settings = array(
			'enabled'                           => 'yes',
			'platform_checkout'                 => 'yes',
			'express_checkout_product_methods'  => array( 'woopay' ),
			'express_checkout_cart_methods'     => array( 'woopay' ),
			'express_checkout_checkout_methods' => array( 'woopay' ),
		);

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_account_id', 'get_publishable_key', 'get_cached_account_data', 'get_stored_account_data', 'is_test_mode_enabled', 'get_gateway_setting' ) )
			->getMock();
		// The cached account's signed-path flag, as client 11.2.0 reads it (includes/class-wc-payments-features.php:226-233).
		$account_service->method( 'get_stored_account_data' )->willReturn( array( 'platform_woopay_force_signed_requests' => true ) );
		$account_service->method( 'get_account_id' )->willReturn( 'acct_123' );
		$account_service->method( 'get_publishable_key' )->willReturn( 'pk_test_123' );
		$account_service->method( 'get_cached_account_data' )->willReturn(
			array(
				'account_id'                        => 'acct_123',
				'details_submitted'                 => true,
				'capabilities'                      => array( 'card_payments' => 'active' ),
				'country'                           => 'US',
				'platform_checkout_eligible'        => true,
				'platform_direct_checkout_eligible' => true,
			)
		);
		$account_service->method( 'is_test_mode_enabled' )->willReturn( $test_mode );
		$account_service->method( 'get_gateway_setting' )->willReturnCallback(
			static fn( string $key, $fallback = null ) => array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback
		);
		$tracking_controller = $this->getMockBuilder( WooPaymentsFrontendTrackingController::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_shopper_event_tracking_enabled' ) )
			->getMock();
		$tracking_controller->method( 'is_shopper_event_tracking_enabled' )->willReturn( true );

		$this->woopay_eligibility = new Eligibility();
		$this->woopay_eligibility->init( $account_service );

		$this->appearance_store = new AppearanceStore();
		$this->appearance_store->init( new WooPaymentsFrontendStylesService() );

		$service = new SessionService();
		$service->init( $account_service, $this->woopay_eligibility, $this->appearance_store, $tracking_controller, wc_get_container()->get( BlocksDataExtractor::class ), $adapted_extensions ?? new AdaptedExtensions(), $this->createMock( WooPaymentsCustomerService::class ), wc_get_container()->get( WooPaymentsLogger::class ) );

		return $service;
	}

	/**
	 * Make a base WooPayments gateway available to the session service.
	 */
	private function make_base_gateway_available(): void {
		add_filter(
			'woocommerce_available_payment_gateways',
			static function ( array $gateways ): array {
				$gateway = new class() extends \WC_Payment_Gateway {
					/**
					 * Build the available base-gateway fixture.
					 */
					public function __construct() {
						$this->id      = 'woocommerce_payments';
						$this->enabled = 'yes';
					}

					/**
					 * Process a fixture payment.
					 *
					 * @param int $order_id Order ID.
					 * @return array<string,mixed>
					 */
					public function process_payment( $order_id ) {
						unset( $order_id );

						return array();
					}
				};

				$gateways['woocommerce_payments'] = $gateway;

				return $gateways;
			}
		);
	}

	/**
	 * Force the Jetpack blog-token signed check to report true.
	 *
	 * The native controller's authoritative check calls
	 * Rest_Authentication::is_signed_with_blog_token(), which reads a private
	 * singleton state that is false in tests. This drives that state to a signed
	 * blog-token result so the strengthen-only filter logic can be exercised.
	 */
	private function force_real_blog_token_signed(): void {
		if ( ! class_exists( Rest_Authentication::class ) ) {
			$this->markTestSkipped( 'Jetpack Rest_Authentication is unavailable.' );
		}

		$instance   = Rest_Authentication::init();
		$reflection = new ReflectionClass( Rest_Authentication::class );

		$status = $reflection->getProperty( 'rest_authentication_status' );
		$status->setAccessible( true );
		$status->setValue( $instance, true );

		$type = $reflection->getProperty( 'rest_authentication_type' );
		$type->setAccessible( true );
		$type->setValue( $instance, 'blog' );

		$this->assertTrue( Rest_Authentication::is_signed_with_blog_token() );
	}

	/**
	 * Reset the Jetpack blog-token signed check state forced during a test.
	 */
	private function reset_real_blog_token_signed(): void {
		if ( ! class_exists( Rest_Authentication::class ) ) {
			return;
		}

		$instance   = Rest_Authentication::init();
		$reflection = new ReflectionClass( Rest_Authentication::class );

		$status = $reflection->getProperty( 'rest_authentication_status' );
		$status->setAccessible( true );
		$status->setValue( $instance, null );

		$type = $reflection->getProperty( 'rest_authentication_type' );
		$type->setAccessible( true );
		$type->setValue( $instance, null );
	}

	/**
	 * Arrange the page and the cart surfaces it renders for a direct checkout row.
	 *
	 * @param string $surface Surface named by light_direct_checkout_surfaces() or no_direct_checkout_surfaces().
	 */
	private function arrange_direct_checkout_surface( string $surface ): void {
		switch ( $surface ) {
			case 'block mini-cart':
				$this->sut->register();
				// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
				do_action( 'woocommerce_blocks_cart_enqueue_data' );
				break;
			case 'classic mini-cart':
				$this->sut->register();
				if ( ! wp_script_is( 'wc-cart-fragments', 'registered' ) ) {
					wp_register_script( 'wc-cart-fragments', 'https://example.com/cart-fragments.js', array(), '1.0', true );
				}
				wp_enqueue_script( 'wc-cart-fragments' );
				break;
			case 'block mini-cart on checkout block page':
				$this->sut->register();
				$this->reset_frontend_surface_state();
				update_option( 'woocommerce_checkout_page_id', $this->set_current_page_with_content( '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->' ) );
				$this->reset_cart_checkout_page_cache();
				// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
				do_action( 'woocommerce_blocks_cart_enqueue_data' );
				break;
			case 'classic cart widget on checkout':
				$this->sut->register();
				$this->set_checkout_shortcode_page();
				if ( ! wp_script_is( 'wc-cart-fragments', 'registered' ) ) {
					wp_register_script( 'wc-cart-fragments', 'https://example.com/cart-fragments.js', array(), '1.0', true );
				}
				wp_enqueue_script( 'wc-cart-fragments' );
				break;
			case 'checkout block page':
				$page_id = self::factory()->post->create(
					array(
						'post_type'    => 'page',
						'post_status'  => 'publish',
						'post_content' => '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->',
					)
				);

				global $post;
				$post = get_post( $page_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				setup_postdata( $post );
				add_filter( 'woocommerce_is_checkout', '__return_true' );
				break;
			case 'unrelated page':
				break;
			default:
				$this->fail( 'Unknown surface ' . $surface );
		}
	}

	/**
	 * Assert the classic script loaded with the light direct-checkout config only.
	 *
	 * @param RecordingWooPaySessionService $service The session service double.
	 */
	private function assert_light_direct_checkout_assets( RecordingWooPaySessionService $service ): void {
		$this->assertTrue( wp_script_is( 'wc-woopayments-woopay', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'wc-woopayments-woopay', 'enqueued' ), 'Client 11.1.0 loads no WooPay stylesheet for direct checkout.' );
		$this->assertSame( 0, $service->frontend_config_calls, 'The full WooPay config is not built on a mini-cart page.' );
		$localized_data = wp_scripts()->get_data( 'wc-woopayments-woopay', 'data' );
		$this->assertIsString( $localized_data );
		$this->assertStringContainsString( '"isWooPayDirectCheckoutEnabled":"1"', $localized_data );
		$this->assertStringContainsString( '"woopayMinimumSessionData":{"encrypted":"minimum"}', $localized_data );
		$this->assertStringContainsString( '"wcAjaxUrl":', $localized_data );
		$this->assertStringNotContainsString( 'shouldShowWooPayButton', $localized_data );
	}

	/**
	 * Post a valid appearance write with the given nonce.
	 *
	 * @param string $nonce_action Nonce action.
	 */
	private function post_appearance_request( string $nonce_action ): void {
		$_POST    = array( // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'_ajax_nonce' => wp_create_nonce( $nonce_action ),
			'appearance'  => $this->get_valid_appearance(),
		);
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Fire an AJAX hook the controller registered and capture the decoded body, and the status of a wp_die() answer.
	 *
	 * wp_send_json() sets the status only while no header was sent, which PHPUnit's own output already did, so a JSON
	 * answer's status cannot be read here; its body tells the outcome.
	 *
	 * @param string $hook AJAX hook name.
	 * @return array{status:int|null,body:mixed}
	 */
	private function dispatch_ajax_hook( string $hook ): array {
		$status      = null;
		$message     = '';
		$die_handler = static function () use ( &$status ) {
			return static function ( $die_message, $title = '', $args = array() ) use ( &$status ): void {
				unset( $title );
				if ( is_array( $args ) && ! empty( $args['response'] ) ) {
					$status = (int) $args['response'];
				}
				throw new WPAjaxDieContinueException( is_scalar( $die_message ) ? (string) $die_message : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only carrier of the wp_die() message.
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $die_handler );

		ob_start();
		try {
			do_action( $hook ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Fires the AJAX hook WordPress or WooCommerce fires for the request.
		} catch ( WPAjaxDieContinueException $e ) {
			$message = $e->getMessage();
		} finally {
			$body = (string) ob_get_clean();
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', $die_handler );
		}

		$decoded = json_decode( $body, true );

		return array(
			'status' => $status,
			'body'   => null !== $decoded ? $decoded : ( '' !== $body ? $body : $message ),
		);
	}

	/**
	 * Post the fields with a valid nonce for the action, fire the AJAX hook and return the decoded body.
	 *
	 * @param string              $hook         AJAX hook name.
	 * @param string              $nonce_action Nonce action the handler checks.
	 * @param array<string,mixed> $post         Other posted fields.
	 * @return mixed
	 */
	private function dispatch_ajax_hook_with_nonce( string $hook, string $nonce_action, array $post ) {
		$_POST    = array_merge( $post, array( '_ajax_nonce' => wp_create_nonce( $nonce_action ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		return $this->dispatch_ajax_hook( $hook )['body'];
	}

	/**
	 * Get a valid WooPay appearance payload.
	 *
	 * @return array<string,mixed>
	 */
	private function get_valid_appearance(): array {
		return array(
			'theme'     => 'stripe',
			'variables' => array(
				'colorText' => '#111111',
			),
		);
	}

	/**
	 * Get expected AJAX hooks and callbacks.
	 *
	 * @return array<string,string>
	 */
	private function get_expected_ajax_hooks(): array {
		return array(
			'wc_ajax_wcpay_init_woopay'                   => 'handle_init_woopay',
			'wc_ajax_wcpay_get_woopay_session'            => 'handle_get_woopay_session',
			'wc_ajax_wcpay_set_woopay_phone_number'       => 'handle_set_woopay_phone_number',
			'wc_ajax_wcpay_get_woopay_signature'          => 'handle_get_woopay_signature',
			'wp_ajax_wcpay_admin_set_woopay_appearance'   => 'handle_set_admin_woopay_appearance',
			'wc_ajax_wcpay_shopper_set_woopay_appearance' => 'handle_set_shopper_woopay_appearance',
			'wc_ajax_wcpay_add_to_cart'                   => 'handle_add_to_cart',
			'wp_ajax_woopay_express_checkout_button_show_error_notice' => 'handle_show_error_notice',
			'wp_ajax_nopriv_woopay_express_checkout_button_show_error_notice' => 'handle_show_error_notice',
		);
	}

	/**
	 * Get expected frontend hooks and callbacks.
	 *
	 * @return array<string,string>
	 */
	private function get_expected_frontend_hooks(): array {
		return array(
			'wp_enqueue_scripts'           => 'enqueue_frontend_assets',
			'wp_footer'                    => 'enqueue_frontend_assets',
			'woocommerce_payment_complete' => 'handle_woocommerce_payment_complete',
		);
	}

	/**
	 * Set the current request to a product page.
	 */
	private function set_current_product(): void {
		$this->reset_frontend_surface_state();
		delete_option( 'woocommerce_cart_page_id' );

		$product = \WC_Helper_Product::create_simple_product( true );
		$this->go_to( get_permalink( $product->get_id() ) );
		$GLOBALS['product'] = $product;
		add_filter( 'woocommerce_is_product', '__return_true' );
	}

	/**
	 * Set the current request to a classic checkout shortcode page.
	 */
	private function set_checkout_shortcode_page(): void {
		$this->reset_frontend_surface_state();
		delete_option( 'woocommerce_cart_page_id' );

		update_option( 'woocommerce_checkout_page_id', $this->set_current_page_with_content( '[woocommerce_checkout]' ) );
	}

	/**
	 * Set the current request to the order-pay endpoint of the checkout page.
	 *
	 * @param int    $order_id     Order ID.
	 * @param string $key          Order key in the pay link.
	 * @param string $page_content Checkout page content: the classic shortcode by default.
	 */
	private function set_order_pay_page( int $order_id, string $key, string $page_content = '[woocommerce_checkout]' ): void {
		$this->reset_frontend_surface_state();
		update_option( 'woocommerce_checkout_page_id', $this->set_current_page_with_content( $page_content ) );
		$this->reset_cart_checkout_page_cache();
		$GLOBALS['wp']->query_vars['order-pay'] = (string) $order_id;
		$_GET['pay_for_order']                  = 'true';
		$_GET['key']                            = $key;
	}

	/**
	 * Read the localized classic WooPay config.
	 *
	 * @return array<string,mixed>
	 */
	private function get_localized_woopay_config(): array {
		$localized_data = wp_scripts()->get_data( 'wc-woopayments-woopay', 'data' );
		$this->assertIsString( $localized_data );
		$this->assertSame( 1, preg_match( '/var wcpay_core_woopay_config = (\{.*\});/s', $localized_data, $matches ) );

		return json_decode( $matches[1], true );
	}

	/**
	 * Set the current request to a page containing the given content.
	 *
	 * @param string $content Page content.
	 * @return int
	 */
	private function set_current_page_with_content( string $content ): int {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);

		global $post;
		$this->go_to( get_permalink( $page_id ) );
		$post = get_post( $page_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		return $page_id;
	}

	/**
	 * Reset shopper-surface globals that earlier broad-suite tests may leave behind.
	 */
	private function reset_frontend_surface_state(): void {
		remove_all_filters( 'woocommerce_is_checkout' );
		remove_all_filters( 'woocommerce_is_cart' );
		remove_all_filters( 'woocommerce_is_product' );
		delete_option( 'woocommerce_checkout_page_id' );
		delete_option( 'woocommerce_cart_page_id' );
		$this->reset_cart_checkout_page_cache();
		unset( $GLOBALS['post'], $GLOBALS['product'] );
		unset( $GLOBALS['wp_actions']['woocommerce_blocks_cart_enqueue_data'] );
		wp_dequeue_script( 'wc-cart-fragments' );
		wp_reset_postdata();
		$this->go_to( home_url( '/' ) );
	}

	/**
	 * Reset cached cart/checkout page checks between simulated requests.
	 */
	private function reset_cart_checkout_page_cache(): void {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::class ) ) {
			return;
		}

		foreach ( array( 'is_cart_page', 'is_checkout_page' ) as $property_name ) {
			$property = new \ReflectionProperty( \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::class, $property_name );
			$property->setAccessible( true );
			$property->setValue( null, null );
		}
	}

	/**
	 * Assert a route handler accepts a method.
	 *
	 * @param array<int,array<string,mixed>> $route_handlers Route handlers.
	 * @param string                         $method         HTTP method.
	 */
	private function assertRouteHasMethod( array $route_handlers, string $method ): void {
		foreach ( $route_handlers as $handler ) {
			if ( isset( $handler['methods'][ $method ] ) ) {
				$this->assertArrayHasKey( $method, $handler['methods'], "Route handler should register the {$method} method." );
				return;
			}
		}

		$this->fail( "Route did not register {$method}." );
	}
}
