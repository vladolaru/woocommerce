<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Connection\Rest_Authentication;
use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendStylesService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendTrackingController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWooPaySessionController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWooPaySessionService;
use ReflectionClass;
use WC_REST_Unit_Test_Case;
use WPAjaxDieContinueException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Tests for the WooPaymentsWooPaySessionController class.
 */
class WooPaymentsWooPaySessionControllerTest extends WC_REST_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsWooPaySessionController
	 */
	private $sut;

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
		if ( $this->sut instanceof WooPaymentsWooPaySessionController ) {
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
	 * @testdox Should register WooPay route and AJAX hooks when native owns runtime and WooPay is enabled.
	 */
	public function test_registers_woopay_route_and_ajax_hooks_when_native_owns_runtime_and_woopay_is_enabled(): void {
		$service   = new RecordingWooPaySessionService();
		$this->sut = $this->create_controller( true, true, $service );

		$this->sut->register();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		do_action( 'rest_api_init' );

		$this->assertArrayHasKey( '/payments/woopay/session', $this->server->get_routes() );
		$this->assertRouteHasMethod( $this->server->get_routes()['/payments/woopay/session'], WP_REST_Server::READABLE );
		$route_args = $this->server->get_routes()['/payments/woopay/session'][0]['args'];
		$this->assertTrue( $route_args['email']['required'] );
		$this->assertSame( 'email', $route_args['email']['format'] );
		$this->assertSame( 20, has_filter( 'determine_current_user', array( $service, 'determine_current_user_for_woopay' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_order_payment_status_changed', array( $service, 'woopay_order_payment_status_changed' ) ) );
		$this->assertSame( 1, has_action( 'woocommerce_store_api_checkout_order_processed', array( $service, 'catch_woopay_checkout_errors' ) ) );

		foreach ( $this->get_expected_ajax_hooks() as $hook => $method ) {
			$this->assertNotFalse( has_action( $hook, array( $this->sut, $method ) ), "{$hook} should be registered." );
		}
		$this->assertFalse( has_action( 'woopay_restore_order_customer_id', array( $service, 'restore_order_customer_id_from_requests_with_verified_email' ) ), 'The always-on restore service must own the recovery hook.' );
	}

	/**
	 * @testdox Should register WooPay frontend hooks when native owns runtime and WooPay is enabled.
	 */
	public function test_registers_woopay_frontend_hooks_when_native_owns_runtime_and_woopay_is_enabled(): void {
		$this->sut = $this->create_controller( true, true );

		$this->sut->register();

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
		$this->sut = $this->create_controller( true, false, $service );
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
	 * @testdox Should keep controller registration enabled when the button filter hides WooPay buttons.
	 */
	public function test_button_filter_does_not_disable_controller_registration(): void {
		$service   = $this->create_real_enabled_session_service();
		$this->sut = $this->create_controller( true, true, $service );
		add_filter( 'wcpay_woopay_enabled', '__return_false' );

		$this->sut->register();

		$this->assertTrue( $service->is_woopay_enabled() );
		$this->assertNotFalse( has_action( 'rest_api_init', array( $this->sut, 'register_routes' ) ) );
	}

	/**
	 * @testdox Should register no hooks when native does not own runtime.
	 */
	public function test_registers_no_hooks_when_native_does_not_own_runtime(): void {
		$service   = new RecordingWooPaySessionService();
		$this->sut = $this->create_controller( false, true, $service );

		$this->sut->register();

		$this->assertFalse( has_filter( 'determine_current_user', array( $service, 'determine_current_user_for_woopay' ) ) );
		$this->assertFalse( has_action( 'woocommerce_order_payment_status_changed', array( $service, 'woopay_order_payment_status_changed' ) ) );
		$this->assertFalse( has_action( 'woopay_restore_order_customer_id', array( $service, 'restore_order_customer_id_from_requests_with_verified_email' ) ) );
		$this->assertFalse( has_action( 'woocommerce_store_api_checkout_order_processed', array( $service, 'catch_woopay_checkout_errors' ) ) );
		$this->assertFalse( has_action( 'rest_api_init', array( $this->sut, 'register_routes' ) ) );
		foreach ( $this->get_expected_ajax_hooks() as $hook => $method ) {
			$this->assertFalse( has_action( $hook, array( $this->sut, $method ) ) );
		}
		foreach ( $this->get_expected_frontend_hooks() as $hook => $method ) {
			$this->assertFalse( has_action( $hook, array( $this->sut, $method ) ) );
		}
		$this->assertFalse( has_filter( 'wcpay_metadata_from_order', array( $this->sut, 'maybe_add_woopay_user_metadata' ) ) );
	}

	/**
	 * @testdox Should keep the inbound identity hooks and the session route registered when WooPay is disabled.
	 */
	public function test_keeps_identity_hooks_and_session_route_when_platform_checkout_is_disabled(): void {
		$service   = new RecordingWooPaySessionService();
		$this->sut = $this->create_controller( true, false, $service );

		$this->sut->register();

		$this->assertSame( 20, has_filter( 'determine_current_user', array( $service, 'determine_current_user_for_woopay' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_order_payment_status_changed', array( $service, 'woopay_order_payment_status_changed' ) ) );
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
		$this->assertFalse( has_action( 'woopay_restore_order_customer_id', array( $service, 'restore_order_customer_id_from_requests_with_verified_email' ) ), 'The controller must not reclaim the always-on recovery hook when WooPay is disabled.' );
	}

	/**
	 * @testdox Should enqueue classic WooPay save-user assets on checkout even when the express button is hidden.
	 */
	public function test_enqueue_frontend_assets_preserves_classic_save_user_when_checkout_button_is_hidden(): void {
		$service                            = new RecordingWooPaySessionService();
		$service->woopay_enabled            = true;
		$service->should_show_woopay_button = false;
		$this->sut                          = $this->create_controller( true, true, $service );

		$this->set_checkout_shortcode_page();

		$this->sut->enqueue_frontend_assets();

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
		$this->sut                                    = $this->create_controller( true, true, $service );
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
	 * @testdox Should enqueue direct checkout assets on a Cart Block without rendering an express button.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_enqueue_frontend_assets_for_direct_checkout_on_cart_block(): void {
		$service                                      = new RecordingWooPaySessionService();
		$service->should_show_woopay_button           = false;
		$service->should_load_woopay_save_user_assets = false;
		$service->direct_checkout_enabled             = true;
		$this->sut                                    = $this->create_controller( true, true, $service );
		$page_id                                      = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:woocommerce/cart --><div class="wp-block-woocommerce-cart"></div><!-- /wp:woocommerce/cart -->',
			)
		);

		global $post;
		$post = get_post( $page_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );
		add_filter( 'woocommerce_is_cart', '__return_true' );

		$this->sut->enqueue_frontend_assets();
		$express_button = $this->sut->get_express_checkout_button_html();

		$this->assertTrue( wp_script_is( 'wc-woopayments-woopay', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'wc-woopayments-woopay', 'enqueued' ) );
		$this->assertSame( '', $express_button );
	}

	/**
	 * @testdox A page carrying the Cart Block keeps the full WooPay config even when it is not the store's cart page.
	 *
	 * Client 11.1.0 counts any page with the Cart Block as a cart page (class-wc-payments-woopay-direct-checkout.php:141-143)
	 * and gives only other pages the light common config.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_cart_block_page_keeps_the_full_config(): void {
		$service                                      = new RecordingWooPaySessionService();
		$service->should_show_woopay_button           = false;
		$service->should_load_woopay_save_user_assets = false;
		$service->direct_checkout_enabled             = true;
		$this->sut                                    = $this->create_controller( true, true, $service );
		$page_id                                      = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:woocommerce/cart --><div class="wp-block-woocommerce-cart"></div><!-- /wp:woocommerce/cart -->',
			)
		);

		global $post;
		$post = get_post( $page_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		$this->sut->enqueue_frontend_assets();

		$this->assertTrue( wp_script_is( 'wc-woopayments-woopay', 'enqueued' ) );
		$this->assertSame( 1, $service->frontend_config_calls );
		$this->assertSame( 0, $service->direct_checkout_config_calls );
	}

	/**
	 * @testdox Should enqueue direct checkout assets for a block mini-cart on an ordinary page at footer time.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_enqueue_frontend_assets_for_direct_checkout_block_mini_cart(): void {
		$service                                      = new RecordingWooPaySessionService();
		$service->should_show_woopay_button           = false;
		$service->should_load_woopay_save_user_assets = false;
		$service->direct_checkout_enabled             = true;
		$this->sut                                    = $this->create_controller( true, true, $service );
		$this->sut->register();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		do_action( 'woocommerce_blocks_cart_enqueue_data' );
		$this->sut->enqueue_frontend_assets();

		$this->assert_light_direct_checkout_assets( $service );
	}

	/**
	 * @testdox Should enqueue direct checkout assets for a legacy mini-cart on an ordinary page at footer time.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_enqueue_frontend_assets_for_direct_checkout_legacy_mini_cart(): void {
		$service                                      = new RecordingWooPaySessionService();
		$service->should_show_woopay_button           = false;
		$service->should_load_woopay_save_user_assets = false;
		$service->direct_checkout_enabled             = true;
		$this->sut                                    = $this->create_controller( true, true, $service );
		$this->sut->register();
		if ( ! wp_script_is( 'wc-cart-fragments', 'registered' ) ) {
			wp_register_script( 'wc-cart-fragments', 'https://example.com/cart-fragments.js', array(), '1.0', true );
		}
		wp_enqueue_script( 'wc-cart-fragments' );

		$this->sut->enqueue_frontend_assets();

		$this->assert_light_direct_checkout_assets( $service );
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
		$this->sut = $this->create_controller( true, true, $this->create_real_enabled_session_service( false ) );
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
		$this->sut                                    = $this->create_controller( true, true, $service );
		$this->sut->register();
		if ( ! wp_script_is( 'wc-cart-fragments', 'registered' ) ) {
			wp_register_script( 'wc-cart-fragments', 'https://example.com/cart-fragments.js', array(), '1.0', true );
		}
		wp_enqueue_script( 'wc-cart-fragments' );

		$this->sut->enqueue_frontend_assets();

		$this->assertFalse( wp_script_is( 'wc-woopayments-woopay', 'enqueued' ) );
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
		$this->sut                                    = $this->create_controller( true, true, $service );
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
		$this->sut                                    = $this->create_controller( true, true, $service );
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
	 * @testdox Should run direct checkout on a classic checkout page where a Mini-Cart block renders, as client 11.1.0.
	 *
	 * Client 11.1.0 should_enqueue_scripts() loads direct checkout wherever woocommerce_blocks_cart_enqueue_data fired, the
	 * checkout page included (class-wc-payments-woopay-direct-checkout.php:130-134).
	 */
	public function test_enqueue_frontend_assets_runs_direct_checkout_for_a_mini_cart_block_on_classic_checkout(): void {
		$service                                      = new RecordingWooPaySessionService();
		$service->should_show_woopay_button           = false;
		$service->should_load_woopay_save_user_assets = false;
		$service->direct_checkout_enabled             = true;
		$this->sut                                    = $this->create_controller( true, true, $service );
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
	 * @testdox Should run direct checkout on a Checkout block page where a Mini-Cart block renders, with the light config.
	 *
	 * Runs in its own process: WOOCOMMERCE_CART, once an earlier test defines it (WC_Form_Handler::update_cart_action() does),
	 * makes is_cart() true for the rest of the run, and this page must not be a cart page.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_enqueue_frontend_assets_runs_direct_checkout_for_a_mini_cart_block_on_checkout_block_page(): void {
		$service                                      = new RecordingWooPaySessionService();
		$service->should_show_woopay_button           = false;
		$service->should_load_woopay_save_user_assets = false;
		$service->direct_checkout_enabled             = true;
		$this->sut                                    = $this->create_controller( true, true, $service );
		$this->sut->register();
		$this->reset_frontend_surface_state();
		update_option( 'woocommerce_checkout_page_id', $this->set_current_page_with_content( '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->' ) );
		$this->reset_cart_checkout_page_cache();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		do_action( 'woocommerce_blocks_cart_enqueue_data' );

		$this->sut->enqueue_frontend_assets();

		$this->assert_light_direct_checkout_assets( $service );
	}

	/**
	 * @testdox Should not enqueue direct checkout assets for the classic cart widget on checkout pages, as client 11.1.0.
	 *
	 * Runs in its own process: WOOCOMMERCE_CART, once an earlier test defines it (WC_Form_Handler::update_cart_action() does),
	 * makes is_cart() true for the rest of the run, and this page must not be a cart page.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_enqueue_frontend_assets_skips_the_classic_cart_widget_on_checkout_pages(): void {
		$service                                      = new RecordingWooPaySessionService();
		$service->should_show_woopay_button           = false;
		$service->should_load_woopay_save_user_assets = false;
		$service->direct_checkout_enabled             = true;
		$this->sut                                    = $this->create_controller( true, true, $service );
		$this->sut->register();
		$this->set_checkout_shortcode_page();
		if ( ! wp_script_is( 'wc-cart-fragments', 'registered' ) ) {
			wp_register_script( 'wc-cart-fragments', 'https://example.com/cart-fragments.js', array(), '1.0', true );
		}
		wp_enqueue_script( 'wc-cart-fragments' );

		$this->sut->enqueue_frontend_assets();

		$this->assertFalse( wp_script_is( 'wc-woopayments-woopay', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'wc-woopayments-woopay', 'enqueued' ) );
	}

	/**
	 * @testdox Should not enqueue classic WooPay assets on checkout block pages.
	 *
	 * Runs in its own process: WOOCOMMERCE_CART, once an earlier test defines it (WC_Form_Handler::update_cart_action() does),
	 * makes is_cart() true for the rest of the run, and this page must not be a cart page.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_enqueue_frontend_assets_skips_checkout_block_pages(): void {
		$service                                      = new RecordingWooPaySessionService();
		$service->should_show_woopay_button           = false;
		$service->should_load_woopay_save_user_assets = false;
		$service->direct_checkout_enabled             = true;
		$this->sut                                    = $this->create_controller( true, true, $service );
		$page_id                                      = self::factory()->post->create(
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

		$this->sut->enqueue_frontend_assets();

		$this->assertFalse( wp_script_is( 'wc-woopayments-woopay', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'wc-woopayments-woopay', 'enqueued' ) );
	}

	/**
	 * @testdox Should not enqueue direct checkout assets on unrelated pages.
	 *
	 * A prior checkout test must not make this unrelated page appear to be checkout.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_enqueue_frontend_assets_skips_unrelated_pages_for_direct_checkout(): void {
		$service                                      = new RecordingWooPaySessionService();
		$service->should_show_woopay_button           = false;
		$service->should_load_woopay_save_user_assets = false;
		$service->direct_checkout_enabled             = true;
		$this->sut                                    = $this->create_controller( true, true, $service );

		$this->sut->enqueue_frontend_assets();

		$this->assertFalse( wp_script_is( 'wc-woopayments-woopay', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'wc-woopayments-woopay', 'enqueued' ) );
	}

	/**
	 * @testdox Should move the classic billing email into a contact section only while WooPay is enabled.
	 */
	public function test_register_relocates_classic_email_field_only_when_woopay_is_enabled(): void {
		$this->sut = $this->create_controller( true, false );
		$this->sut->register();

		$this->assertFalse( has_action( 'woocommerce_checkout_billing', array( $this->sut, 'woopay_fields_before_billing_details' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_form_field_email', array( $this->sut, 'filter_woocommerce_form_field_woopay_email' ) ) );

		$this->sut = $this->create_controller( true, true );
		$this->sut->register();

		$this->assertSame( -50, has_action( 'woocommerce_checkout_billing', array( $this->sut, 'woopay_fields_before_billing_details' ) ) );
		$this->assertSame( 20, has_filter( 'woocommerce_form_field_email', array( $this->sut, 'filter_woocommerce_form_field_woopay_email' ) ) );
	}

	/**
	 * @testdox Should render the WooPay contact section with the billing email field.
	 */
	public function test_woopay_fields_before_billing_details_renders_contact_section(): void {
		$this->sut = $this->create_controller( true, true );
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
		$this->sut = $this->create_controller( true, true );
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
		$this->sut = $this->create_controller( true, true );
		$field     = '<p class="form-row"><input type="email" name="billing_email" /></p>';

		$this->assertSame( $field, $this->sut->filter_woocommerce_form_field_woopay_email( $field, 'billing_email', array( 'class' => array( 'form-row-wide' ) ), '' ) );
	}

	/**
	 * @testdox Should require a phone number when the shopper asks to save their details in WooPay.
	 */
	public function test_maybe_show_woopay_phone_number_error_requires_phone_when_saving_user(): void {
		$this->sut = $this->create_controller( true, true );
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
		$this->sut = $this->create_controller( true, true );
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
		$this->sut = $this->create_controller( true, false );
		$this->set_checkout_shortcode_page();

		$this->assertSame( '', $this->sut->get_express_checkout_button_html() );
	}

	/**
	 * @testdox Should give a product-context WooPay placeholder on product pages.
	 */
	public function test_express_checkout_button_html_on_product_page(): void {
		$this->sut = $this->create_controller( true, true );
		$this->set_current_product();

		$this->assertStringContainsString( 'data-product_page="1"', $this->sut->get_express_checkout_button_html() );
	}

	/**
	 * @testdox Should render product-context WooPay on product_page shortcode pages.
	 */
	public function test_express_checkout_button_html_supports_product_page_shortcode(): void {
		$this->sut = $this->create_controller( true, true );
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
		$this->sut = $this->create_controller( true, true, $service );
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
		$this->sut = $this->create_controller( true, true );
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
		$this->sut = $this->create_controller( true, true );
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
		$this->sut = $this->create_controller( true, true );
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
		$this->sut = $this->create_controller( true, true );
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
	 * @testdox Order-pay offers WooPay only when the order is in the active currency (store $store_currency, active $active_currency, order $order_currency).
	 *
	 * The WooPay session preloads the Store API order, whose totals carry the active currency (CurrencyFormatter::format()),
	 * while the payment charges the order's currency. Native multi-currency sets the active currency through the
	 * woocommerce_currency filter (MultiCurrencyFrontendCurrenciesController::get_woocommerce_currency()); the filter here
	 * stands in for a shopper whose selected currency is $active_currency.
	 *
	 * @testWith ["USD", "", "EUR", false]
	 *           ["USD", "EUR", "USD", false]
	 *           ["USD", "EUR", "EUR", true]
	 *           ["EUR", "", "EUR", true]
	 *
	 * @param string $store_currency  Store currency option.
	 * @param string $active_currency Currency the woocommerce_currency filter returns, or empty for none.
	 * @param string $order_currency  Order currency.
	 * @param bool   $offered         Whether WooPay is offered for the order.
	 */
	public function test_order_pay_offers_woopay_only_in_the_order_currency( string $store_currency, string $active_currency, string $order_currency, bool $offered ): void {
		update_option( 'woocommerce_currency', $store_currency );
		$owner_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order    = \WC_Helper_Order::create_order( $owner_id );
		$order->set_currency( $order_currency );
		$order->save();
		wp_set_current_user( $owner_id );
		if ( '' !== $active_currency ) {
			add_filter( 'woocommerce_currency', static fn() => $active_currency, 900 );
		}
		$this->sut = $this->create_controller( true, true );
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
	 * @testdox Order-pay offers WooPay for a $currency order in the active $currency only when that currency uses two price decimals ($decimals).
	 *
	 * The Store API order the WooPay session preloads writes its amounts with two decimals (OrderSchema::get_totals() calls
	 * AbstractSchema::prepare_money_response(), whose decimals default to 2) and labels them with the active decimals
	 * (CurrencyFormatter::format(), currency_minor_unit from wc_get_price_decimals()), so a ¥1,000 order at 0 decimals would
	 * read as ¥100,000. Native multi-currency sets the active decimals through the wc_get_price_decimals filter at priority 900
	 * (MultiCurrencyFrontendCurrenciesController); the filters here stand in for a shopper who selected $currency.
	 *
	 * @testWith ["JPY", 0, false]
	 *           ["BHD", 3, false]
	 *           ["JPY", 2, true]
	 *
	 * @param string $currency Order currency, also the active currency.
	 * @param int    $decimals Active price decimals.
	 * @param bool   $offered  Whether WooPay is offered for the order.
	 */
	public function test_order_pay_offers_woopay_only_with_two_price_decimals( string $currency, int $decimals, bool $offered ): void {
		update_option( 'woocommerce_currency', 'USD' );
		$owner_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order    = \WC_Helper_Order::create_order( $owner_id );
		$order->set_currency( $currency );
		$order->save();
		wp_set_current_user( $owner_id );
		add_filter( 'woocommerce_currency', static fn() => $currency, 900 );
		add_filter( 'wc_get_price_decimals', static fn() => $decimals, 900 );
		$this->sut = $this->create_controller( true, true );
		$this->set_order_pay_page( $order->get_id(), $order->get_order_key() );

		$this->sut->enqueue_frontend_assets();
		$config = $this->get_localized_woopay_config();
		$html   = $this->sut->get_express_checkout_button_html();

		if ( $offered ) {
			$this->assertSame( '1', $config['shouldShowWooPayButton'] );
			$this->assertSame( (string) $order->get_id(), $config['order_id'] );
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
		$this->sut = $this->create_controller( true, true );
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
		$this->sut = $this->create_controller( true, true );
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
		$this->sut = $this->create_controller( true, true );
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
	 * @testdox Offers no WooPay on the Subscriptions change-payment page, as client 11.1.0 (class-wc-payments-woopay-button-handler.php:124-126).
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
		$this->sut = $this->create_controller( true, true );
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
		$this->sut = $this->create_controller( true, true );
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
	 * @testdox The signed-request filter is strengthen-only and cannot grant access to an unsigned request.
	 */
	public function test_check_permission_filter_cannot_grant_access_to_unsigned_request(): void {
		$this->sut = $this->create_controller( true, true );

		// Real blog-token check is false in tests; a filter returning true must not grant access.
		add_filter( 'wcpay_woopay_is_signed_with_blog_token', '__return_true' );

		$request = new WP_REST_Request( 'GET', '/payments/woopay/session' );
		$request->set_header( 'User-Agent', 'WooPay' );
		$request->set_param( 'email', 'shopper@example.com' );

		$result = $this->sut->check_permission( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_cannot_view', $result->get_error_code() );
	}

	/**
	 * @testdox A signed request is allowed when no filter restricts it.
	 */
	public function test_check_permission_allows_signed_request_without_filter(): void {
		$this->sut = $this->create_controller( true, true );
		$this->force_real_blog_token_signed();

		$request = new WP_REST_Request( 'GET', '/payments/woopay/session' );
		$request->set_header( 'User-Agent', 'WooPay' );
		$request->set_param( 'email', 'shopper@example.com' );

		$this->assertTrue( $this->sut->check_permission( $request ) );
	}

	/**
	 * @testdox The signed-request filter can still restrict access to a signed request.
	 */
	public function test_check_permission_filter_can_restrict_signed_request(): void {
		$this->sut = $this->create_controller( true, true );
		$this->force_real_blog_token_signed();

		// The real check is true, but a filter returning false must still deny access.
		add_filter( 'wcpay_woopay_is_signed_with_blog_token', '__return_false' );

		$request = new WP_REST_Request( 'GET', '/payments/woopay/session' );
		$request->set_header( 'User-Agent', 'WooPay' );
		$request->set_param( 'email', 'shopper@example.com' );

		$result = $this->sut->check_permission( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_rest_cannot_view', $result->get_error_code() );
	}

	/**
	 * @testdox Should preserve the one-argument shape of the signed-request filter.
	 */
	public function test_check_permission_preserves_signed_request_filter_arg_shape(): void {
		$this->sut = $this->create_controller( true, true );
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

		$this->assertTrue( $this->sut->check_permission( $request ) );
		$this->assertIsArray( $captured_args );
		$this->assertCount( 1, $captured_args, 'The preserved WooPay signed-token filter should receive only the boolean signed state.' );
		$this->assertTrue( $captured_args[0] );
	}

	/**
	 * @testdox Should log the exception's class, code and trace, never its message, and return an error when WooPay session assembly throws.
	 */
	public function test_get_session_logs_and_returns_error_when_assembly_throws(): void {
		$service                 = new ThrowingWooPaySessionService();
		$service->woopay_enabled = true;
		$this->sut               = $this->create_controller( true, true, $service );

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

		$matching = array_values(
			array_filter(
				$logged,
				static function ( $entry ) {
					return 'error' === $entry['level']
						&& isset( $entry['context']['source'] )
						&& 'woopayments-woopay-session' === $entry['context']['source'];
				}
			)
		);

		$this->assertNotEmpty( $matching, 'Expected a logged error for the swallowed WooPay session exception.' );
		$this->assertSame( 'Unable to assemble WooPay session data.', $matching[0]['message'] );
		$this->assertSame( \RuntimeException::class, $matching[0]['context']['exception'] );
		$this->assertSame( 7, $matching[0]['context']['code'] );
		$this->assertArrayHasKey( 'trace', $matching[0]['context'] );
		foreach ( $logged as $entry ) {
			$written = $entry['message'] . wp_json_encode( $entry['context'] );
			$this->assertStringNotContainsString( 'shopper@example.com', $written );
			$this->assertStringNotContainsString( 'tok_secret_123', $written );
		}
	}

	/**
	 * @testdox Should return session data for signed WooPay requests.
	 */
	public function test_rest_session_route_returns_session_data_for_signed_woopay_request(): void {
		$service   = new RecordingWooPaySessionService();
		$this->sut = $this->create_controller( true, true, $service );
		$this->sut->register_routes();
		$this->force_real_blog_token_signed();

		$request = new WP_REST_Request( 'GET', '/payments/woopay/session' );
		$request->set_header( 'User-Agent', 'WooPay' );
		$request->set_param( 'email', 'shopper@example.com' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'session' => 'native' ), $response->get_data() );
		$this->assertSame( 'shopper@example.com', $service->last_session_email );
	}

	/**
	 * @testdox Should build WooPay AJAX-compatible response payloads.
	 */
	public function test_builds_ajax_response_payloads(): void {
		$service   = new RecordingWooPaySessionService();
		$this->sut = $this->create_controller( true, true, $service );

		$this->assertSame( array( 'result' => 'success' ), $this->sut->get_init_woopay_response( array( 'email' => 'shopper@example.com' ) ) );
		$this->assertSame( array( 'encrypted' => 'session' ), $this->sut->get_encrypted_session_response( array( 'email' => 'shopper@example.com' ) ) );
		$this->assertSame( array( 'result' => 'success' ), $this->sut->get_phone_session_response( array( 'phone_number' => '+15555550123' ) ) );
		$this->assertSame( array( 'signature' => 'signed' ), $this->sut->get_signature_response( array() ) );
		$this->assertSame( array( 'encrypted' => 'minimum' ), $this->sut->get_minimum_session_response( array() ) );
		$this->assertSame( '+15555550123', $service->last_phone_request['phone_number'] );
	}

	/**
	 * @testdox The admin and shopper appearance writes store the posted appearance.
	 */
	public function test_appearance_writes_store_the_posted_appearance(): void {
		$service   = new RecordingWooPaySessionService();
		$this->sut = $this->create_controller( true, true, $service );
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
		$this->sut = $this->create_controller( true, true, $service );
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
			'minimum session'             => array( 'minimum session', 'wc_ajax_wcpay_get_woopay_minimum_session_data', 'customer', null, array(), $failure, null ),
			'admin appearance, no nonce'  => array( 'admin appearance, no nonce', 'wp_ajax_wcpay_admin_set_woopay_appearance', 'administrator', null, $appearance, $error, null ),
			'admin appearance, a shopper' => array( 'admin appearance, a shopper', 'wp_ajax_wcpay_admin_set_woopay_appearance', 'customer', 'wcpay_admin_woopay_appearance_nonce', $appearance, $error, null ),
			'shopper appearance'          => array( 'shopper appearance', 'wc_ajax_wcpay_shopper_set_woopay_appearance', 'customer', null, $appearance, $error, null ),
			'product add-to-cart'         => array( 'product add-to-cart', 'wc_ajax_wcpay_add_to_cart', 'customer', null, $product_id, '-1', 403 ),
			'error notice'                => array( 'error notice', 'wp_ajax_woopay_express_checkout_button_show_error_notice', 'customer', null, $notice, $not_allowed, null ),
			'error notice, logged out'    => array( 'error notice, logged out', 'wp_ajax_nopriv_woopay_express_checkout_button_show_error_notice', 'customer', null, $notice, $not_allowed, null ),
		);
	}

	/**
	 * @testdox Should return the preserved WooPay signature AJAX success envelope.
	 */
	public function test_signature_ajax_handler_returns_success_envelope(): void {
		$service   = new RecordingWooPaySessionService();
		$this->sut = $this->create_controller( true, true, $service );
		$_POST     = array( // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'_ajax_nonce' => wp_create_nonce( 'woopay_signature_nonce' ),
		);
		$_REQUEST  = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$response = $this->dispatch_signature_ajax();

		$this->assertTrue( $response['success'] );
		$this->assertSame( array( 'signature' => 'signed' ), $response['data'] );
	}

	/**
	 * @testdox Should require the WooPay button nonce before rendering frontend error notices.
	 */
	public function test_show_error_notice_ajax_handler_requires_woopay_button_nonce(): void {
		$this->sut = $this->create_controller( true, true );
		$_POST     = array( // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'message' => 'WooPay is unavailable.',
		);
		$_REQUEST  = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$unauthorized = $this->dispatch_show_error_notice_ajax();

		$this->assertFalse( $unauthorized['success'] );
		$this->assertIsString( $unauthorized['data'] );
		$this->assertNotSame( '', $unauthorized['data'] );

		$_POST    = array( // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'_ajax_nonce' => wp_create_nonce( 'woopay_button_nonce' ),
			'message'     => 'WooPay is unavailable.',
		);
		$_REQUEST = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$authorized = $this->dispatch_show_error_notice_ajax();

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
		$this->sut = $this->create_controller( true, true, $service );
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
		$this->sut                  = $this->create_controller( true, true, $service );
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

		$this->sut = $this->create_controller( true, true );
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

		$response = $this->dispatch_add_to_cart_ajax();
		$cart     = WC()->cart->get_cart();
		$item     = reset( $cart );

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
			$this->sut = $this->create_controller( true, true );
			$_POST     = array( // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'security'   => wp_create_nonce( 'wcpay-add-to-cart' ),
				'product_id' => (string) $product->get_id(),
				'quantity'   => $request_quantity,
			);
			$_REQUEST  = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing

			$response   = $this->dispatch_add_to_cart_ajax();
			$cart_items = WC()->cart->get_cart();
			$item       = reset( $cart_items );

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
	 * @param bool                                 $native_register Whether native should register hooks.
	 * @param bool                                 $woopay_enabled  Whether WooPay should be enabled.
	 * @param WooPaymentsWooPaySessionService|null $service         Optional service double.
	 * @return WooPaymentsWooPaySessionController
	 */
	private function create_controller( bool $native_register, bool $woopay_enabled, ?WooPaymentsWooPaySessionService $service = null ): WooPaymentsWooPaySessionController {
		$arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( $native_register );

		$service = $service ?? new RecordingWooPaySessionService();
		if ( $service instanceof RecordingWooPaySessionService ) {
			$service->woopay_enabled = $woopay_enabled;
		}

		$controller = new WooPaymentsWooPaySessionController();
		$controller->init( $arbiter, $service );

		return $controller;
	}

	/**
	 * Create a real enabled session service for controller integration tests.
	 *
	 * @param bool $test_mode Whether the account reports test mode.
	 * @return WooPaymentsWooPaySessionService
	 */
	private function create_real_enabled_session_service( bool $test_mode = true ): WooPaymentsWooPaySessionService {
		$settings = array(
			'enabled'                           => 'yes',
			'platform_checkout'                 => 'yes',
			'express_checkout_product_methods'  => array( 'woopay' ),
			'express_checkout_cart_methods'     => array( 'woopay' ),
			'express_checkout_checkout_methods' => array( 'woopay' ),
		);

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_account_id', 'get_publishable_key', 'get_cached_account_data', 'is_test_mode_enabled', 'get_gateway_setting' ) )
			->getMock();
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
			->onlyMethods( array( 'is_shopper_tracking_enabled' ) )
			->getMock();
		$tracking_controller->method( 'is_shopper_tracking_enabled' )->willReturn( true );

		$service = new WooPaymentsWooPaySessionService();
		$service->init( $account_service, new WooPaymentsFrontendStylesService(), $tracking_controller );

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
	 * Dispatch the WooPay signature AJAX handler and decode the JSON response.
	 *
	 * @return array{success:bool,data:mixed}
	 */
	private function dispatch_signature_ajax(): array {
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			static function () {
				return static function (): void {
					throw new WPAjaxDieContinueException();
				};
			}
		);

		ob_start();
		try {
			$this->sut->handle_get_woopay_signature();
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}
		$body = (string) ob_get_clean();

		$decoded = json_decode( $body, true );
		$this->assertIsArray( $decoded, 'WooPay signature AJAX should emit a JSON object.' );

		return $decoded;
	}

	/**
	 * Dispatch the WooPay error notice AJAX handler and decode the JSON response.
	 *
	 * @return array{success:bool,data:mixed}
	 */
	private function dispatch_show_error_notice_ajax(): array {
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			static function () {
				return static function (): void {
					throw new WPAjaxDieContinueException();
				};
			}
		);

		ob_start();
		try {
			$this->sut->handle_show_error_notice();
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}
		$body = (string) ob_get_clean();

		$decoded = json_decode( $body, true );
		$this->assertIsArray( $decoded, 'WooPay notice AJAX should emit a JSON object.' );

		return $decoded;
	}

	/**
	 * Dispatch the WooPay product add-to-cart AJAX handler and decode the JSON response.
	 *
	 * @return array<string,mixed>
	 */
	private function dispatch_add_to_cart_ajax(): array {
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			static function () {
				return static function (): void {
					throw new WPAjaxDieContinueException();
				};
			}
		);

		ob_start();
		try {
			$this->sut->handle_add_to_cart();
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}
		$body = (string) ob_get_clean();

		$decoded = json_decode( $body, true );
		$this->assertIsArray( $decoded, 'WooPay add-to-cart AJAX should emit a JSON object.' );

		return $decoded;
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
			'wc_ajax_wcpay_get_woopay_minimum_session_data' => 'handle_get_woopay_minimum_session_data',
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
