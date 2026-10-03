<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendTrackingController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsExpressCheckoutController class.
 */
class WooPaymentsExpressCheckoutControllerTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsExpressCheckoutController|null
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
		if ( $this->sut instanceof WooPaymentsExpressCheckoutController ) {
			foreach ( $this->get_expected_frontend_hooks() as $hook => $method ) {
				remove_action( $hook, array( $this->sut, $method ) );
			}
			remove_filter( 'wcpay_tracks_event_properties', array( $this->sut, 'add_tracking_event_properties' ) );
		}

		$this->reset_frontend_surface_state();
		delete_option( 'woocommerce_checkout_page_id' );
		delete_option( 'woocommerce_cart_page_id' );
		remove_all_filters( 'woocommerce_is_checkout' );
		remove_all_filters( 'woocommerce_is_cart' );
		remove_all_filters( 'woocommerce_is_product' );
		$this->set_order_pay_query_var( 0 );
		wp_dequeue_script( 'wc-woopayments-express-checkout' );
		wp_dequeue_style( 'wc-woopayments-express-checkout' );
		wp_dequeue_script( 'wp-hooks' );
		wp_deregister_script( 'wc-woopayments-express-checkout' );
		wp_deregister_style( 'wc-woopayments-express-checkout' );
		wp_deregister_script( 'stripe' );
		wp_reset_postdata();
		parent::tearDown();
	}

	/**
	 * @testdox Should register express checkout frontend hooks when native owns runtime and payment request is available.
	 */
	public function test_registers_frontend_hooks_when_native_owns_runtime_and_payment_request_is_available(): void {
		$this->sut = $this->create_controller( true, true );

		$this->sut->register();

		foreach ( $this->get_expected_frontend_hooks() as $hook => $method ) {
			$this->assertNotFalse( has_action( $hook, array( $this->sut, $method ) ), "{$hook} should be registered." );
		}
		$this->assertNotFalse( has_filter( 'wcpay_tracks_event_properties', array( $this->sut, 'add_tracking_event_properties' ) ) );
	}

	/**
	 * @testdox Should register no hooks when native does not own the runtime.
	 */
	public function test_registers_no_hooks_when_native_does_not_own_runtime(): void {
		$this->sut = $this->create_controller( false, true );

		$this->sut->register();

		foreach ( $this->get_expected_frontend_hooks() as $hook => $method ) {
			$this->assertFalse( has_action( $hook, array( $this->sut, $method ) ) );
		}
		$this->assertFalse( has_filter( 'wcpay_tracks_event_properties', array( $this->sut, 'add_tracking_event_properties' ) ) );
	}

	/**
	 * @testdox Should register frontend hooks without eagerly depending on payment request settings.
	 */
	public function test_registers_frontend_hooks_without_eager_payment_request_gate(): void {
		$this->sut = $this->create_controller( true, false );

		$this->sut->register();

		foreach ( $this->get_expected_frontend_hooks() as $hook => $method ) {
			$this->assertNotFalse( has_action( $hook, array( $this->sut, $method ) ), "{$hook} should be registered." );
		}
		$this->assertNotFalse( has_filter( 'wcpay_tracks_event_properties', array( $this->sut, 'add_tracking_event_properties' ) ) );
	}

	/**
	 * @testdox Should enqueue separate express checkout assets and localize ECE params on checkout.
	 */
	public function test_enqueue_frontend_assets_loads_separate_express_checkout_bundle(): void {
		$this->sut = $this->create_controller( true, true );
		$this->set_checkout_shortcode_page();

		$this->sut->enqueue_frontend_assets();

		$this->assertTrue( wp_script_is( 'wc-woopayments-express-checkout', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'wc-woopayments-express-checkout', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'stripe', 'registered' ) );
		$this->assertSame( 'https://js.stripe.com/v3/', wp_scripts()->registered['stripe']->src );
		$localized_data = wp_scripts()->get_data( 'wc-woopayments-express-checkout', 'data' );
		$this->assertIsString( $localized_data );
		$this->assertStringContainsString( 'var wcpayExpressCheckoutParams', $localized_data );
		$this->assertStringContainsString( '"enabled_methods":["payment_request"]', $localized_data );
		$this->assertNotContains( 'wp-hooks', wp_scripts()->registered['wc-woopayments-express-checkout']->deps );
	}

	/**
	 * @testdox Should enqueue express checkout assets on custom checkout shortcode pages.
	 */
	public function test_enqueue_frontend_assets_loads_on_checkout_shortcode_page(): void {
		$this->sut = $this->create_controller( true, true );
		$page_id   = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[woocommerce_checkout]',
			)
		);

		global $post;
		$post = get_post( $page_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		$this->sut->enqueue_frontend_assets();

		$this->assertTrue( wp_script_is( 'wc-woopayments-express-checkout', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'wc-woopayments-express-checkout', 'enqueued' ) );
	}

	/**
	 * @testdox Should enqueue express checkout assets with pay-for-order context on order-pay pages.
	 */
	public function test_enqueue_frontend_assets_loads_on_order_pay_page(): void {
		$service   = new RecordingExpressCheckoutService();
		$this->sut = $this->create_controller( true, true, $service );
		$this->set_checkout_shortcode_page();
		$this->set_order_pay_query_var( 123 );

		$this->sut->enqueue_frontend_assets();

		$this->assertTrue( wp_script_is( 'wc-woopayments-express-checkout', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'wc-woopayments-express-checkout', 'enqueued' ) );
		$this->assertSame( array( 'pay_for_order' ), $service->contexts );
		$localized_data = wp_scripts()->get_data( 'wc-woopayments-express-checkout', 'data' );
		$this->assertIsString( $localized_data );
		$this->assertStringContainsString( '"button_context":"pay_for_order"', $localized_data );
	}

	/**
	 * @testdox Should enqueue express checkout assets with product context on product pages.
	 */
	public function test_enqueue_frontend_assets_loads_on_product_page(): void {
		$service   = new RecordingExpressCheckoutService();
		$this->sut = $this->create_controller( true, true, $service );
		$this->set_current_product();

		$this->sut->enqueue_frontend_assets();

		$this->assertTrue( wp_script_is( 'wc-woopayments-express-checkout', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'wc-woopayments-express-checkout', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'wp-hooks', 'enqueued' ) );
		$this->assertSame( array( 'product' ), $service->contexts );
		$localized_data = wp_scripts()->get_data( 'wc-woopayments-express-checkout', 'data' );
		$this->assertIsString( $localized_data );
		$this->assertStringContainsString( '"button_context":"product"', $localized_data );
		$this->assertStringContainsString( '"label":"Merchant (via WooCommerce)"', $localized_data );
	}

	/**
	 * @testdox Should not enqueue classic ECE assets on checkout block pages.
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

		$this->assertFalse( wp_script_is( 'wc-woopayments-express-checkout', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'wc-woopayments-express-checkout', 'enqueued' ) );
	}

	/**
	 * @testdox Should render the reference ECE container on supported shopper surfaces.
	 */
	public function test_display_express_checkout_buttons_renders_ece_container(): void {
		$this->sut = $this->create_controller( true, true );
		$this->set_checkout_shortcode_page();

		ob_start();
		$this->sut->display_express_checkout_buttons();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'wcpay-express-checkout-wrapper', $output );
		$this->assertStringContainsString( 'id="wcpay-express-checkout-element"', $output );
		$this->assertStringContainsString( 'wcpay-express-checkout-button-separator', $output );
	}

	/**
	 * @testdox Should render the ECE container with pay-for-order context on order-pay pages.
	 */
	public function test_display_express_checkout_buttons_renders_on_order_pay_page(): void {
		$service   = new RecordingExpressCheckoutService();
		$this->sut = $this->create_controller( true, true, $service );
		$this->set_checkout_shortcode_page();
		$this->set_order_pay_query_var( 123 );

		ob_start();
		$this->sut->display_express_checkout_buttons();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'wcpay-express-checkout-wrapper', $output );
		$this->assertStringNotContainsString( 'wcpay-express-checkout-button-separator', $output );
		$this->assertSame( array( 'pay_for_order' ), $service->contexts );
	}

	/**
	 * @testdox Should render the ECE container with product context on product pages.
	 */
	public function test_display_express_checkout_buttons_renders_on_product_page(): void {
		$service   = new RecordingExpressCheckoutService();
		$this->sut = $this->create_controller( true, true, $service );
		$this->set_current_product();

		ob_start();
		$this->sut->display_express_checkout_buttons();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'wcpay-express-checkout-wrapper', $output );
		$this->assertStringNotContainsString( 'wcpay-express-checkout-button-separator', $output );
		$this->assertSame( array( 'product' ), $service->contexts );
	}

	/**
	 * @testdox Should use the live SKU shortcode product for localized data and the rendered Express Checkout container.
	 */
	public function test_product_page_shortcode_with_sku_uses_live_product_for_express_checkout(): void {
		$escaped_product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'name'          => 'Escaped Widget',
				'price'         => '9.87',
				'regular_price' => '9.87',
				'virtual'       => true,
			)
		);
		$live_product    = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'name'          => 'Live SKU Widget',
				'price'         => '12.34',
				'regular_price' => '12.34',
				'virtual'       => true,
			)
		);
		$live_product->set_sku( 'live-shortcode-sku' );
		$live_product->save();

		$content = '[[product_page id="' . $escaped_product->get_id() . '"]] [product_page sku="live-shortcode-sku"]';
		$this->set_current_page_with_content( $content );
		unset( $GLOBALS['product'] );

		$this->sut = $this->create_controller( true, true, $this->create_real_service() );
		$this->sut->enqueue_frontend_assets();

		$localized_data = wp_scripts()->get_data( 'wc-woopayments-express-checkout', 'data' );
		$this->assertIsString( $localized_data );
		$this->assertStringContainsString( '"label":"Live SKU Widget"', $localized_data );
		$this->assertStringContainsString( '"amount":1234', $localized_data );
		$this->assertStringNotContainsString( 'Escaped Widget', $localized_data );

		$this->sut->register();
		$this->setExpectedDeprecated( 'Theme without comments.php' );
		$output = do_shortcode( $content );

		$this->assertStringContainsString( 'id="wcpay-express-checkout-element"', $output );
	}

	/**
	 * @testdox Should render no ECE container or assets when payment request is disabled.
	 */
	public function test_display_express_checkout_buttons_renders_nothing_when_payment_request_is_disabled(): void {
		$this->sut = $this->create_controller( true, false );
		$this->set_checkout_shortcode_page();

		ob_start();
		$this->sut->display_express_checkout_buttons();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
		$this->assertFalse( wp_script_is( 'wc-woopayments-express-checkout', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'wc-woopayments-express-checkout', 'enqueued' ) );
	}

	/**
	 * @testdox Should mark Apple Pay and Google Pay load/click events as all-store Tracks events.
	 */
	public function test_add_tracking_event_properties_marks_platform_wallet_events_as_all_store_events(): void {
		$this->sut = $this->create_controller( true, true );

		$this->assertSame(
			array( 'record_event_data' => array( 'track_on_all_stores' => true ) ),
			$this->sut->add_tracking_event_properties( array(), 'wcpay_applepay_button_load' )
		);
		$this->assertSame(
			array( 'record_event_data' => array( 'track_on_all_stores' => true ) ),
			$this->sut->add_tracking_event_properties( array(), 'wcpay_gpay_button_click' )
		);
		$this->assertSame( array(), $this->sut->add_tracking_event_properties( array(), 'wcpay_woopay_button_click' ) );
	}

	/**
	 * @testdox Should stash the login redirect URL in a cookie and redirect to my-account on a valid nonce.
	 */
	public function test_handle_express_checkout_redirect_redirects_to_my_account(): void {
		$this->sut = $this->create_controller( true, true );

		$my_account_page_id = $this->factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'woocommerce_myaccount_page_id', $my_account_page_id );

		$checkout_url                                = home_url( '/checkout/' );
		$_GET['wcpay_express_checkout_redirect_url'] = rawurlencode( $checkout_url );
		$_GET['_wpnonce']                            = wp_create_nonce( 'wcpay-set-redirect-url' );

		$captured_redirect = null;
		$capture_redirect  = static function ( $location ) use ( &$captured_redirect ) {
			$captured_redirect = $location;
			return false;
		};
		add_filter( 'wp_redirect', $capture_redirect );
		add_filter( 'woocommerce_set_cookie_enabled', '__return_false' );

		try {
			$this->sut->handle_express_checkout_redirect();

			$this->assertSame( get_permalink( $my_account_page_id ), $captured_redirect );
		} finally {
			remove_filter( 'wp_redirect', $capture_redirect );
			remove_filter( 'woocommerce_set_cookie_enabled', '__return_false' );
			unset( $_GET['wcpay_express_checkout_redirect_url'], $_GET['_wpnonce'] );
			delete_option( 'woocommerce_myaccount_page_id' );
		}
	}

	/**
	 * @dataProvider provide_valid_ingress_redirect_targets
	 * @testdox Should stash each valid express checkout redirect target exactly once and only redirect to My Account.
	 *
	 * @param string $target       Expected stashed target.
	 * @param bool   $allow_host  Whether the target host is explicitly allowed.
	 */
	public function test_handle_express_checkout_redirect_stashes_valid_targets_once( string $target, bool $allow_host ): void {
		$this->sut = $this->create_controller( true, true );

		$my_account_page_id = $this->factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'woocommerce_myaccount_page_id', $my_account_page_id );

		$_GET['wcpay_express_checkout_redirect_url'] = rawurlencode( $target );
		$_GET['_wpnonce']                            = wp_create_nonce( 'wcpay-set-redirect-url' );

		$cookie_values  = array();
		$capture_cookie = static function ( bool $enabled, string $name, string $value ) use ( &$cookie_values ): bool {
			unset( $enabled );
			if ( 'wcpay_express_checkout_redirect_url' === $name ) {
				$cookie_values[] = $value;
			}
			return false;
		};
		add_filter( 'woocommerce_set_cookie_enabled', $capture_cookie, 10, 3 );

		$redirect_locations = array();
		$capture_redirect   = static function ( $location ) use ( &$redirect_locations ) {
			$redirect_locations[] = $location;
			return false;
		};
		add_filter( 'wp_redirect', $capture_redirect );

		$allowed_hosts = null;
		if ( $allow_host ) {
			$allowed_hosts = static function ( array $hosts ): array {
				$hosts[] = 'allowed.example';
				return $hosts;
			};
			add_filter( 'allowed_redirect_hosts', $allowed_hosts );
		}

		try {
			$this->sut->handle_express_checkout_redirect();

			$nonempty_cookie_values = array_values(
				array_filter(
					$cookie_values,
					static function ( string $value ): bool {
						return '' !== $value;
					}
				)
			);
			$this->assertSame( array( $target ), $nonempty_cookie_values );
			$this->assertSame( array( get_permalink( $my_account_page_id ) ), $redirect_locations );
		} finally {
			if ( $allow_host && null !== $allowed_hosts ) {
				remove_filter( 'allowed_redirect_hosts', $allowed_hosts );
			}
			remove_filter( 'woocommerce_set_cookie_enabled', $capture_cookie );
			remove_filter( 'wp_redirect', $capture_redirect );
			unset( $_GET['wcpay_express_checkout_redirect_url'], $_GET['_wpnonce'] );
			delete_option( 'woocommerce_myaccount_page_id' );
		}
	}

	/**
	 * Provide valid ingress redirect targets.
	 *
	 * @return array<string,array{string,bool}>
	 */
	public function provide_valid_ingress_redirect_targets(): array {
		return array(
			'local absolute target'   => array( home_url( '/checkout/' ), false ),
			'relative target'         => array( '/checkout/', false ),
			'allowed external target' => array( 'https://allowed.example/checkout/', true ),
			'retained percent escape' => array( home_url( '/checkout/?next=%2Faccount' ), false ),
		);
	}

	/**
	 * @testdox Should not persist an external express checkout redirect target.
	 */
	public function test_handle_express_checkout_redirect_does_not_persist_external_target(): void {
		$this->sut = $this->create_controller( true, true );

		$my_account_page_id = $this->factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'woocommerce_myaccount_page_id', $my_account_page_id );

		$_GET['wcpay_express_checkout_redirect_url'] = rawurlencode( 'https://evil.example/phish' );
		$_GET['_wpnonce']                            = wp_create_nonce( 'wcpay-set-redirect-url' );

		$cookie_values  = array();
		$capture_cookie = static function ( bool $enabled, string $name, string $value ) use ( &$cookie_values ): bool {
			unset( $enabled );
			if ( 'wcpay_express_checkout_redirect_url' === $name ) {
				$cookie_values[] = $value;
			}
			return false;
		};
		add_filter( 'woocommerce_set_cookie_enabled', $capture_cookie, 10, 3 );

		$redirect_locations = array();
		$capture_redirect   = static function ( $location ) use ( &$redirect_locations ) {
			$redirect_locations[] = $location;
			return false;
		};
		add_filter( 'wp_redirect', $capture_redirect );

		try {
			$this->sut->handle_express_checkout_redirect();

			$this->assertSame( array( get_permalink( $my_account_page_id ) ), $redirect_locations );
			$this->assertNotContains( 'https://evil.example/phish', $cookie_values );
			$this->assertSame( array(), array_values( array_filter( $cookie_values ) ) );
		} finally {
			remove_filter( 'woocommerce_set_cookie_enabled', $capture_cookie );
			remove_filter( 'wp_redirect', $capture_redirect );
			unset( $_GET['wcpay_express_checkout_redirect_url'], $_GET['_wpnonce'] );
			delete_option( 'woocommerce_myaccount_page_id' );
		}
	}

	/**
	 * @testdox Should not persist a scheme-relative external express checkout redirect target.
	 */
	public function test_handle_express_checkout_redirect_does_not_persist_scheme_relative_external_target(): void {
		$this->sut = $this->create_controller( true, true );

		$my_account_page_id = $this->factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'woocommerce_myaccount_page_id', $my_account_page_id );

		$_GET['wcpay_express_checkout_redirect_url'] = rawurlencode( '//evil.example/phish' );
		$_GET['_wpnonce']                            = wp_create_nonce( 'wcpay-set-redirect-url' );

		$cookie_values  = array();
		$capture_cookie = static function ( bool $enabled, string $name, string $value ) use ( &$cookie_values ): bool {
			unset( $enabled );
			if ( 'wcpay_express_checkout_redirect_url' === $name ) {
				$cookie_values[] = $value;
			}
			return false;
		};
		add_filter( 'woocommerce_set_cookie_enabled', $capture_cookie, 10, 3 );

		$redirect_locations = array();
		$capture_redirect   = static function ( $location ) use ( &$redirect_locations ) {
			$redirect_locations[] = $location;
			return false;
		};
		add_filter( 'wp_redirect', $capture_redirect );

		try {
			$this->sut->handle_express_checkout_redirect();

			$this->assertSame( array( get_permalink( $my_account_page_id ) ), $redirect_locations );
			$this->assertNotContains( '//evil.example/phish', $cookie_values );
			$this->assertSame( array(), array_values( array_filter( $cookie_values ) ) );
		} finally {
			remove_filter( 'woocommerce_set_cookie_enabled', $capture_cookie );
			remove_filter( 'wp_redirect', $capture_redirect );
			unset( $_GET['wcpay_express_checkout_redirect_url'], $_GET['_wpnonce'] );
			delete_option( 'woocommerce_myaccount_page_id' );
		}
	}

	/**
	 * @testdox Should ignore a non-string express checkout redirect query value.
	 */
	public function test_handle_express_checkout_redirect_ignores_non_string_query_value(): void {
		$this->sut = $this->create_controller( true, true );

		$my_account_page_id = $this->factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'woocommerce_myaccount_page_id', $my_account_page_id );

		$_GET['wcpay_express_checkout_redirect_url'] = array( 'https://evil.example/phish' );
		$_GET['_wpnonce']                            = wp_create_nonce( 'wcpay-set-redirect-url' );

		$cookie_values  = array();
		$capture_cookie = static function ( bool $enabled, string $name, string $value ) use ( &$cookie_values ): bool {
			unset( $enabled );
			if ( 'wcpay_express_checkout_redirect_url' === $name ) {
				$cookie_values[] = $value;
			}
			return false;
		};
		add_filter( 'woocommerce_set_cookie_enabled', $capture_cookie, 10, 3 );

		$redirect_locations = array();
		$capture_redirect   = static function ( $location ) use ( &$redirect_locations ) {
			$redirect_locations[] = $location;
			return false;
		};
		add_filter( 'wp_redirect', $capture_redirect );

		try {
			$this->sut->handle_express_checkout_redirect();

			$this->assertSame( array( get_permalink( $my_account_page_id ) ), $redirect_locations );
			$this->assertSame( array(), array_values( array_filter( $cookie_values ) ) );
		} finally {
			remove_filter( 'woocommerce_set_cookie_enabled', $capture_cookie );
			remove_filter( 'wp_redirect', $capture_redirect );
			unset( $_GET['wcpay_express_checkout_redirect_url'], $_GET['_wpnonce'] );
			delete_option( 'woocommerce_myaccount_page_id' );
		}
	}

	/**
	 * @testdox Should not redirect on an invalid nonce.
	 */
	public function test_handle_express_checkout_redirect_ignores_invalid_nonce(): void {
		$this->sut = $this->create_controller( true, true );

		$_GET['wcpay_express_checkout_redirect_url'] = rawurlencode( home_url( '/checkout/' ) );
		$_GET['_wpnonce']                            = 'invalid';

		$captured_redirect = null;
		$capture_redirect  = static function ( $location ) use ( &$captured_redirect ) {
			$captured_redirect = $location;
			return false;
		};
		add_filter( 'wp_redirect', $capture_redirect );

		$this->sut->handle_express_checkout_redirect();

		$this->assertNull( $captured_redirect );

		remove_filter( 'wp_redirect', $capture_redirect );
		unset( $_GET['wcpay_express_checkout_redirect_url'], $_GET['_wpnonce'] );
	}

	/**
	 * @testdox Should return the stashed express checkout URL as the login redirect.
	 */
	public function test_get_login_redirect_url_uses_stashed_cookie(): void {
		$this->sut = $this->create_controller( true, true );

		$checkout_url                                   = home_url( '/checkout/' );
		$_COOKIE['wcpay_express_checkout_redirect_url'] = $checkout_url;
		add_filter( 'woocommerce_set_cookie_enabled', '__return_false' );

		try {
			$this->assertSame(
				$checkout_url,
				$this->sut->get_login_redirect_url( home_url( '/my-account/' ) )
			);
		} finally {
			remove_filter( 'woocommerce_set_cookie_enabled', '__return_false' );
			unset( $_COOKIE['wcpay_express_checkout_redirect_url'] );
		}
	}

	/**
	 * @testdox Should keep the default login redirect without a stashed URL.
	 */
	public function test_get_login_redirect_url_keeps_default_without_cookie(): void {
		$this->sut = $this->create_controller( true, true );

		unset( $_COOKIE['wcpay_express_checkout_redirect_url'] );

		$this->assertSame(
			home_url( '/my-account/' ),
			$this->sut->get_login_redirect_url( home_url( '/my-account/' ) )
		);
	}

	/**
	 * @dataProvider provide_valid_cookie_redirect_targets
	 * @testdox Should preserve valid cookie target $target byte-for-byte and clear it exactly once.
	 *
	 * @param string $target      Valid cookie redirect target.
	 * @param bool   $allow_host Whether the target host is explicitly allowed.
	 */
	public function test_get_login_redirect_url_preserves_valid_cookie_and_clears_it_once( string $target, bool $allow_host ): void {
		$this->sut = $this->create_controller( true, true );

		$_COOKIE['wcpay_express_checkout_redirect_url'] = $target;
		$cookie_values                                  = array();
		$capture_cookie                                 = static function ( bool $enabled, string $name, string $value ) use ( &$cookie_values ): bool {
			unset( $enabled );
			if ( 'wcpay_express_checkout_redirect_url' === $name ) {
				$cookie_values[] = $value;
			}
			return false;
		};
		add_filter( 'woocommerce_set_cookie_enabled', $capture_cookie, 10, 3 );

		$allowed_hosts = null;
		if ( $allow_host ) {
			$allowed_hosts = static function ( array $hosts ): array {
				$hosts[] = 'allowed.example';
				return $hosts;
			};
			add_filter( 'allowed_redirect_hosts', $allowed_hosts );
		}

		try {
			$this->assertSame( $target, $this->sut->get_login_redirect_url( home_url( '/my-account/' ) ) );
			$this->assertSame( array( '' ), $cookie_values );
		} finally {
			if ( $allow_host && null !== $allowed_hosts ) {
				remove_filter( 'allowed_redirect_hosts', $allowed_hosts );
			}
			remove_filter( 'woocommerce_set_cookie_enabled', $capture_cookie );
			unset( $_COOKIE['wcpay_express_checkout_redirect_url'] );
		}
	}

	/**
	 * Provide valid cookie redirect targets.
	 *
	 * @return array<string,array{string,bool}>
	 */
	public function provide_valid_cookie_redirect_targets(): array {
		return array(
			'local absolute target retains percent escape' => array( home_url( '/checkout/?next=%2Faccount' ), false ),
			'relative target'                              => array( '/checkout/', false ),
			'allowed external target'                      => array( 'https://allowed.example/checkout/', true ),
		);
	}

	/**
	 * @dataProvider provide_non_string_login_redirect_fallbacks
	 * @testdox Should preserve a non-string caller fallback when the cookie is absent or invalid.
	 *
	 * @param bool              $has_cookie            Whether the redirect cookie is present.
	 * @param mixed             $cookie_value          Redirect cookie value.
	 * @param mixed             $fallback              Caller-provided fallback.
	 * @param array<int,string> $expected_cookie_values Expected target-cookie writes.
	 */
	public function test_get_login_redirect_url_preserves_non_string_fallback( bool $has_cookie, $cookie_value, $fallback, array $expected_cookie_values ): void {
		$this->sut = $this->create_controller( true, true );

		if ( $has_cookie ) {
			$_COOKIE['wcpay_express_checkout_redirect_url'] = $cookie_value;
		} else {
			unset( $_COOKIE['wcpay_express_checkout_redirect_url'] );
		}

		$cookie_values  = array();
		$capture_cookie = static function ( bool $enabled, string $name, string $value ) use ( &$cookie_values ): bool {
			unset( $enabled );
			if ( 'wcpay_express_checkout_redirect_url' === $name ) {
				$cookie_values[] = $value;
			}
			return false;
		};
		add_filter( 'woocommerce_set_cookie_enabled', $capture_cookie, 10, 3 );

		try {
			$result = $this->sut->get_login_redirect_url( $fallback );

			$this->assertSame( $expected_cookie_values, $cookie_values );
			$this->assertSame( $fallback, $result );
		} finally {
			remove_filter( 'woocommerce_set_cookie_enabled', $capture_cookie );
			unset( $_COOKIE['wcpay_express_checkout_redirect_url'] );
		}
	}

	/**
	 * Provide non-string login redirect fallbacks.
	 *
	 * @return array<string,array{bool,mixed,mixed,array<int,string>}>
	 */
	public function provide_non_string_login_redirect_fallbacks(): array {
		return array(
			'absent cookie with array fallback'   => array( false, null, array( 'fallback' ), array() ),
			'invalid cookie with object fallback' => array( true, 'https://evil.example/phish', new \stdClass(), array( '' ) ),
		);
	}

	/**
	 * @testdox Should reject an external stashed express checkout URL and clear its cookie.
	 */
	public function test_get_login_redirect_url_rejects_external_stashed_url(): void {
		$this->sut = $this->create_controller( true, true );

		$_COOKIE['wcpay_express_checkout_redirect_url'] = 'https://evil.example/phish';
		$cookie_values                                  = array();
		$capture_cookie                                 = static function ( bool $enabled, string $name, string $value ) use ( &$cookie_values ): bool {
			unset( $enabled );
			if ( 'wcpay_express_checkout_redirect_url' === $name ) {
				$cookie_values[] = $value;
			}
			return false;
		};
		add_filter( 'woocommerce_set_cookie_enabled', $capture_cookie, 10, 3 );

		try {
			$this->assertSame(
				home_url( '/my-account/' ),
				$this->sut->get_login_redirect_url( home_url( '/my-account/' ) )
			);
			$this->assertSame( array( '' ), $cookie_values );
		} finally {
			remove_filter( 'woocommerce_set_cookie_enabled', $capture_cookie );
			unset( $_COOKIE['wcpay_express_checkout_redirect_url'] );
		}
	}

	/**
	 * @testdox Should reject a non-string stashed express checkout URL and clear its cookie.
	 */
	public function test_get_login_redirect_url_rejects_non_string_cookie_value(): void {
		$this->sut = $this->create_controller( true, true );

		$_COOKIE['wcpay_express_checkout_redirect_url'] = array( 'https://evil.example/phish' );
		$cookie_values                                  = array();
		$capture_cookie                                 = static function ( bool $enabled, string $name, string $value ) use ( &$cookie_values ): bool {
			unset( $enabled );
			if ( 'wcpay_express_checkout_redirect_url' === $name ) {
				$cookie_values[] = $value;
			}
			return false;
		};
		add_filter( 'woocommerce_set_cookie_enabled', $capture_cookie, 10, 3 );

		try {
			$this->assertSame(
				home_url( '/my-account/' ),
				$this->sut->get_login_redirect_url( home_url( '/my-account/' ) )
			);
			$this->assertSame( array( '' ), $cookie_values );
		} finally {
			remove_filter( 'woocommerce_set_cookie_enabled', $capture_cookie );
			unset( $_COOKIE['wcpay_express_checkout_redirect_url'] );
		}
	}

	/**
	 * @testdox Should reject a scheme-relative external stashed express checkout URL and clear its cookie.
	 */
	public function test_get_login_redirect_url_rejects_scheme_relative_stashed_url(): void {
		$this->sut = $this->create_controller( true, true );

		$_COOKIE['wcpay_express_checkout_redirect_url'] = '//evil.example/phish';
		$cookie_values                                  = array();
		$capture_cookie                                 = static function ( bool $enabled, string $name, string $value ) use ( &$cookie_values ): bool {
			unset( $enabled );
			if ( 'wcpay_express_checkout_redirect_url' === $name ) {
				$cookie_values[] = $value;
			}
			return false;
		};
		add_filter( 'woocommerce_set_cookie_enabled', $capture_cookie, 10, 3 );

		try {
			$this->assertSame(
				home_url( '/my-account/' ),
				$this->sut->get_login_redirect_url( home_url( '/my-account/' ) )
			);
			$this->assertSame( array( '' ), $cookie_values );
		} finally {
			remove_filter( 'woocommerce_set_cookie_enabled', $capture_cookie );
			unset( $_COOKIE['wcpay_express_checkout_redirect_url'] );
		}
	}

	/**
	 * @testdox Should preserve an external stashed URL when its host is explicitly allowed.
	 */
	public function test_get_login_redirect_url_preserves_external_stashed_url_for_allowed_host(): void {
		$this->sut = $this->create_controller( true, true );

		$_COOKIE['wcpay_express_checkout_redirect_url'] = 'https://evil.example/phish';
		$allowed_hosts                                  = static function ( array $hosts ): array {
			$hosts[] = 'evil.example';
			return $hosts;
		};
		add_filter( 'allowed_redirect_hosts', $allowed_hosts );
		add_filter( 'woocommerce_set_cookie_enabled', '__return_false' );

		try {
			$this->assertSame(
				'https://evil.example/phish',
				$this->sut->get_login_redirect_url( home_url( '/my-account/' ) )
			);
		} finally {
			remove_filter( 'allowed_redirect_hosts', $allowed_hosts );
			remove_filter( 'woocommerce_set_cookie_enabled', '__return_false' );
			unset( $_COOKIE['wcpay_express_checkout_redirect_url'] );
		}
	}

	/**
	 * @testdox Should preserve a relative stashed express checkout URL.
	 */
	public function test_get_login_redirect_url_preserves_relative_stashed_url(): void {
		$this->sut = $this->create_controller( true, true );

		$_COOKIE['wcpay_express_checkout_redirect_url'] = '/checkout/';
		add_filter( 'woocommerce_set_cookie_enabled', '__return_false' );

		try {
			$this->assertSame(
				'/checkout/',
				$this->sut->get_login_redirect_url( home_url( '/my-account/' ) )
			);
		} finally {
			remove_filter( 'woocommerce_set_cookie_enabled', '__return_false' );
			unset( $_COOKIE['wcpay_express_checkout_redirect_url'] );
		}
	}

	/**
	 * Create the System Under Test.
	 *
	 * @param bool                                   $native_register     Whether native should register hooks.
	 * @param bool                                   $payment_request_on  Whether payment request should be available.
	 * @param WooPaymentsExpressCheckoutService|null $service            Optional service double.
	 * @return WooPaymentsExpressCheckoutController
	 */
	private function create_controller( bool $native_register, bool $payment_request_on, ?WooPaymentsExpressCheckoutService $service = null ): WooPaymentsExpressCheckoutController {
		$arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( $native_register );

		$service = $service ?? new RecordingExpressCheckoutService();
		if ( $service instanceof RecordingExpressCheckoutService ) {
			$service->should_show_payment_request_button = $payment_request_on;
		}

		$controller = new WooPaymentsExpressCheckoutController();
		$controller->init( $arbiter, $service, $this->createStub( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudPreventionService::class ) );

		return $controller;
	}

	/**
	 * Create the real Express Checkout service for controller integration tests.
	 *
	 * @return WooPaymentsExpressCheckoutService
	 */
	private function create_real_service(): WooPaymentsExpressCheckoutService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_account_id', 'get_publishable_key', 'get_cached_account_data', 'is_test_mode_enabled', 'get_gateway_setting', 'is_payment_request_enabled' ) )
			->getMock();
		$account_service->method( 'get_account_id' )->willReturn( 'acct_123' );
		$account_service->method( 'get_publishable_key' )->willReturn( 'pk_test_123' );
		$account_service->method( 'get_cached_account_data' )->willReturn(
			array(
				'country'          => 'US',
				'payments_enabled' => true,
				'capabilities'     => array(),
			)
		);
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );
		$account_service->method( 'is_payment_request_enabled' )->willReturn( true );
		$account_service->method( 'get_gateway_setting' )->willReturnCallback(
			static function ( string $key, $fallback = null ) {
				$settings = array(
					'manual_capture'                   => 'no',
					'payment_request'                  => 'yes',
					'payment_request_button_type'      => 'default',
					'payment_request_button_theme'     => 'dark',
					'payment_request_button_size'      => 'medium',
					'express_checkout_product_methods' => array( 'payment_request' ),
				);

				return array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback;
			}
		);

		$provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );

		$tracking_controller = $this->getMockBuilder( WooPaymentsFrontendTrackingController::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_shopper_tracking_enabled' ) )
			->getMock();
		$tracking_controller->method( 'is_shopper_tracking_enabled' )->willReturn( true );

		$service = new WooPaymentsExpressCheckoutService();
		$service->init( $account_service, $provider, $tracking_controller );

		return $service;
	}

	/**
	 * Get expected frontend hooks and callbacks.
	 *
	 * @return array<string,string>
	 */
	private function get_expected_frontend_hooks(): array {
		return array(
			'wp_enqueue_scripts'                           => 'enqueue_frontend_assets',
			'woocommerce_after_add_to_cart_form'           => 'display_express_checkout_buttons',
			'woocommerce_checkout_before_customer_details' => 'display_express_checkout_buttons',
			'woocommerce_proceed_to_checkout'              => 'display_express_checkout_buttons',
			'woocommerce_pay_order_before_payment'         => 'display_express_checkout_buttons',
		);
	}

	/**
	 * Set the current order-pay query var.
	 *
	 * @param int $order_id Order ID.
	 */
	private function set_order_pay_query_var( int $order_id ): void {
		global $wp;

		if ( ! is_object( $wp ) ) {
			$wp = new \WP(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		if ( $order_id > 0 ) {
			$wp->query_vars['order-pay'] = $order_id;
			return;
		}

		unset( $wp->query_vars['order-pay'] );
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
	 * Set the current request to a product page.
	 */
	private function set_current_product(): void {
		$this->reset_frontend_surface_state();
		delete_option( 'woocommerce_cart_page_id' );

		$product = \WC_Helper_Product::create_simple_product( true );
		global $post;
		$post               = get_post( $product->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['product'] = $product;
		$this->go_to( get_permalink( $product->get_id() ) );
		setup_postdata( $post );
		add_filter( 'woocommerce_is_product', '__return_true' );
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
		$this->set_order_pay_query_var( 0 );
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
}
