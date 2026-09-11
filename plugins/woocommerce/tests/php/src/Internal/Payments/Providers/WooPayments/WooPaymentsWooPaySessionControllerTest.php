<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Connection\Rest_Authentication;
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
		if ( function_exists( 'WC' ) && WC() && WC()->cart ) {
			WC()->cart->empty_cart();
		}
		wp_dequeue_script( 'wc-woopayments-woopay' );
		wp_dequeue_style( 'wc-woopayments-woopay' );
		wp_deregister_script( 'wc-woopayments-woopay' );
		wp_deregister_style( 'wc-woopayments-woopay' );
		wp_reset_postdata();
		delete_option( 'woocommerce_enable_guest_checkout' );
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
		$this->assertNotFalse( has_action( 'woopay_restore_order_customer_id', array( $service, 'restore_order_customer_id_from_requests_with_verified_email' ) ) );
		$this->assertSame( 1, has_action( 'woocommerce_store_api_checkout_order_processed', array( $service, 'catch_woopay_checkout_errors' ) ) );

		foreach ( $this->get_expected_ajax_hooks() as $hook => $method ) {
			$this->assertNotFalse( has_action( $hook, array( $this->sut, $method ) ), "{$hook} should be registered." );
		}
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
		$this->assertNotFalse( has_filter( 'wcpay_metadata_from_order', array( $this->sut, 'maybe_add_woopay_user_metadata' ) ) );
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
		$this->assertNotFalse( has_action( 'woopay_restore_order_customer_id', array( $service, 'restore_order_customer_id_from_requests_with_verified_email' ) ) );
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
	}

	/**
	 * @testdox Should not enqueue classic WooPay assets on checkout block pages.
	 */
	public function test_enqueue_frontend_assets_skips_checkout_block_pages(): void {
		$this->sut = $this->create_controller( true, true );
		$page_id   = self::factory()->post->create(
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
	 */
	public function test_filter_woocommerce_form_field_woopay_email_keeps_core_field_off_checkout(): void {
		if ( defined( 'WOOCOMMERCE_CHECKOUT' ) ) {
			$this->markTestSkipped( 'Another test in this process defined WOOCOMMERCE_CHECKOUT; is_checkout() cannot be false here.' );
		}

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
	 * @testdox Should render the WooPay separator on checkout.
	 */
	public function test_display_express_checkout_buttons_renders_separator_on_checkout(): void {
		$this->sut = $this->create_controller( true, true );
		$this->set_checkout_shortcode_page();

		ob_start();
		$this->sut->display_express_checkout_buttons();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="wcpay-woopay-button"', $output );
		$this->assertStringContainsString( 'wcpay-express-checkout-button-separator', $output );
	}

	/**
	 * @testdox Should not render the WooPay separator on product pages.
	 */
	public function test_display_express_checkout_buttons_omits_separator_on_product_page(): void {
		$this->sut = $this->create_controller( true, true );
		$this->set_current_product();

		ob_start();
		$this->sut->display_express_checkout_buttons();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="wcpay-woopay-button"', $output );
		$this->assertStringNotContainsString( 'wcpay-express-checkout-button-separator', $output );
	}

	/**
	 * @testdox Should render product-context WooPay on product_page shortcode pages.
	 */
	public function test_display_express_checkout_buttons_supports_product_page_shortcode(): void {
		$this->sut = $this->create_controller( true, true );
		$product   = \WC_Helper_Product::create_simple_product( true );
		$product->set_sku( 'woopay-controller-shortcode' );
		$product->save();
		$this->set_current_page_with_content( "[product_page columns='3' class='featured' sku='woopay-controller-shortcode']" );

		ob_start();
		$this->sut->display_express_checkout_buttons();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="wcpay-woopay-button"', $output );
		$this->assertStringContainsString( 'data-product_page="1"', $output );
		$this->assertStringNotContainsString( 'wcpay-express-checkout-button-separator', $output );
	}

	/**
	 * @testdox Should evaluate the button filter once while rendering with the real session service.
	 */
	public function test_display_express_checkout_buttons_applies_button_filter_once(): void {
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

		ob_start();
		$this->sut->display_express_checkout_buttons();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="wcpay-woopay-button"', $output );
		$this->assertSame( 1, $enabled_filter_calls );
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
	 * @testdox Should log and return an error when WooPay session assembly throws.
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

		$matching = array_filter(
			$logged,
			static function ( $entry ) {
				return 'error' === $entry['level']
					&& isset( $entry['context']['source'] )
					&& 'woopayments-woopay-session' === $entry['context']['source']
					&& false !== strpos( (string) $entry['message'], 'kaboom' );
			}
		);

		$this->assertNotEmpty( $matching, 'Expected a logged error for the swallowed WooPay session exception.' );
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
		$this->assertSame( array( 'result' => 'success' ), $this->sut->get_admin_appearance_response( array( 'appearance' => $this->get_valid_appearance() ) ) );
		$this->assertSame( array( 'stored' => true ), $this->sut->get_shopper_appearance_response( array( 'appearance' => $this->get_valid_appearance() ) ) );
		$this->assertSame( '+15555550123', $service->last_phone_request['phone_number'] );
		$this->assertSame( $this->get_valid_appearance(), $service->last_appearance );
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
		$this->assertSame( 'You aren’t authorized to do that.', $unauthorized['data'] );

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
	 * @testdox Should report false when shopper appearance was already stored.
	 */
	public function test_shopper_appearance_response_reports_when_appearance_slot_is_filled(): void {
		$service                    = new RecordingWooPaySessionService();
		$service->appearance_stored = false;
		$this->sut                  = $this->create_controller( true, true, $service );

		$response = $this->sut->get_shopper_appearance_response( array( 'appearance' => $this->get_valid_appearance() ) );

		$this->assertSame( array( 'stored' => false ), $response );
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
	 * @return WooPaymentsWooPaySessionService
	 */
	private function create_real_enabled_session_service(): WooPaymentsWooPaySessionService {
		$settings = array(
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
				'account_id'                 => 'acct_123',
				'details_submitted'          => true,
				'capabilities'               => array( 'card_payments' => 'active' ),
				'country'                    => 'US',
				'platform_checkout_eligible' => true,
			)
		);
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );
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
			'wp_enqueue_scripts'                           => 'enqueue_frontend_assets',
			'woocommerce_checkout_before_customer_details' => 'display_express_checkout_buttons',
			'woocommerce_proceed_to_checkout'              => 'display_express_checkout_buttons',
			'woocommerce_after_add_to_cart_form'           => 'display_express_checkout_buttons',
			'woocommerce_pay_order_before_payment'         => 'display_express_checkout_buttons',
			'woocommerce_payment_complete'                 => 'handle_woocommerce_payment_complete',
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
