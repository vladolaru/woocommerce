<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendStylesService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentMethodMessaging;
use WC_Product;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsPaymentMethodMessaging class.
 */
class WooPaymentsPaymentMethodMessagingTest extends WC_Unit_Test_Case {

	private const SCRIPT_HANDLE            = 'wc-woopayments-payment-method-messaging';
	private const STYLE_HANDLE             = 'wc-woopayments-payment-method-messaging';
	private const CART_BLOCK_SCRIPT_HANDLE = 'wc-woopayments-cart-block-payment-method-messaging';
	private const CART_BLOCK_STYLE_HANDLE  = 'wc-woopayments-cart-block-payment-method-messaging';

	/**
	 * Registered messaging controllers to clean up.
	 *
	 * @var WooPaymentsPaymentMethodMessaging[]
	 */
	private array $registered_controllers = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->reset_frontend_surface_state();
		update_option( 'woocommerce_calc_taxes', 'no' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_tax_display_shop', 'excl' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->registered_controllers as $controller ) {
			foreach ( $this->get_expected_hooks() as $hook => $method ) {
				remove_action( $hook, array( $controller, $method ) );
			}
		}

		wp_dequeue_script( self::SCRIPT_HANDLE );
		wp_deregister_script( self::SCRIPT_HANDLE );
		wp_dequeue_style( self::STYLE_HANDLE );
		wp_deregister_style( self::STYLE_HANDLE );
		wp_dequeue_script( self::CART_BLOCK_SCRIPT_HANDLE );
		wp_deregister_script( self::CART_BLOCK_SCRIPT_HANDLE );
		wp_dequeue_style( self::CART_BLOCK_STYLE_HANDLE );
		wp_deregister_style( self::CART_BLOCK_STYLE_HANDLE );
		wp_deregister_script( 'wc-woopayments-appearance' );
		wp_deregister_script( 'stripe' );
		delete_option( 'woocommerce_default_country' );
		delete_option( 'woocommerce_currency' );
		delete_option( 'woocommerce_calc_taxes' );
		delete_option( 'woocommerce_prices_include_tax' );
		delete_option( 'woocommerce_tax_display_shop' );
		$this->reset_frontend_surface_state();

		parent::tearDown();
	}

	/**
	 * @testdox The cart-total AJAX handler recalculates the cart before answering.
	 *
	 * Runs in its own process: the handler defines the cart/checkout context
	 * constants, which would otherwise pin is_checkout() for every later test.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_cart_total_ajax_recalculates_stale_cart_totals(): void {
		$controller = $this->create_controller( true, true, array( 'card', 'affirm' ), array( 'affirm_payments' => 'active' ) );
		$product    = \WC_Helper_Product::create_simple_product( true, array( 'regular_price' => '10' ) );

		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $product->get_id(), 2 );
		WC()->cart->calculate_totals();
		// A stale in-memory figure, as after a change that has not been recalculated on this request.
		WC()->cart->set_total( 0 );

		$_REQUEST['security'] = wp_create_nonce( 'wcpay-get-cart-total' );
		$die_handler          = static function () {
			return static function () {
				throw new \Exception( 'ajax-die' );
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $die_handler );

		ob_start();
		try {
			$controller->handle_get_cart_total();
		} catch ( \Exception $exception ) {
			$this->assertSame( 'ajax-die', $exception->getMessage() );
		} finally {
			$json = (string) ob_get_clean();
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', $die_handler );
			unset( $_REQUEST['security'] );
			WC()->cart->empty_cart();
		}

		$this->assertSame( 2000, json_decode( $json, true )['total'] ?? null, 'The AJAX answer must come from a freshly calculated cart, as in the plugin.' );
	}

	/**
	 * @testdox Should register all BNPL messaging hooks when native owns runtime without reading eligibility.
	 */
	public function test_registers_bnpl_messaging_hooks_when_native_owns_runtime_without_reading_eligibility(): void {
		$plugin_owned = $this->create_controller( false, true, array( 'affirm' ), array( 'affirm_payments' => 'active' ) );
		$plugin_owned->register();
		$this->assert_hooks_not_registered( $plugin_owned );

		$account_service = $this->create_account_service( true, array( 'affirm' ), array( 'affirm_payments' => 'active' ), 0 );
		$active_bnpl     = $this->create_controller( true, true, array( 'affirm' ), array( 'affirm_payments' => 'active' ), $account_service );
		$active_bnpl->register();
		$this->registered_controllers[] = $active_bnpl;

		$this->assertSame( 10, has_action( 'woocommerce_single_product_summary', array( $active_bnpl, 'render_site_messaging' ) ) );
		$this->assertSame( 5, has_action( 'woocommerce_proceed_to_checkout', array( $active_bnpl, 'render_site_messaging' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_blocks_enqueue_cart_block_scripts_after', array( $active_bnpl, 'render_site_messaging' ) ) );
		$this->assertSame( 10, has_action( 'wc_ajax_wcpay_get_cart_total', array( $active_bnpl, 'handle_get_cart_total' ) ) );
		$this->assertSame( 10, has_action( 'wc_ajax_wcpay_check_bnpl_availability', array( $active_bnpl, 'handle_check_bnpl_availability' ) ) );
	}

	/**
	 * @testdox Should not read eligibility on an unsupported shopper surface.
	 *
	 * Runs in its own process because prior cart tests can pin the WOOCOMMERCE_CART constant, making this unsupported surface look supported.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_does_not_read_eligibility_on_unsupported_shopper_surface(): void {
		$account_service = $this->create_account_service( true, array( 'affirm' ), array( 'affirm_payments' => 'active' ), 0 );
		$controller      = $this->create_controller( true, true, array( 'affirm' ), array( 'affirm_payments' => 'active' ), $account_service );

		ob_start();
		$controller->render_site_messaging();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * @testdox Should not render or enqueue messaging on an ineligible product surface.
	 */
	public function test_does_not_render_or_enqueue_messaging_on_an_ineligible_product_surface(): void {
		$product = \WC_Helper_Product::create_simple_product( true, array( 'regular_price' => '50.00' ) );
		$this->set_current_product( $product );

		$controller = $this->create_controller( true, false, array( 'affirm' ), array( 'affirm_payments' => 'active' ) );

		ob_start();
		$controller->render_site_messaging();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
		$this->assertFalse( wp_script_is( self::SCRIPT_HANDLE, 'registered' ) );
		$this->assertFalse( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( self::STYLE_HANDLE, 'registered' ) );
		$this->assertFalse( wp_style_is( self::STYLE_HANDLE, 'enqueued' ) );
	}

	/**
	 * @testdox Should not process the cart-total AJAX callback for ineligible messaging.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_does_not_process_cart_total_ajax_for_ineligible_messaging(): void {
		$controller  = $this->create_controller( true, false, array( 'affirm' ), array( 'affirm_payments' => 'active' ) );
		$die_handler = static function () {
			return static function () {
				throw new \Exception( 'ajax-die' );
			};
		};

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $die_handler );

		ob_start();
		try {
			$controller->handle_get_cart_total();
		} catch ( \Exception $exception ) {
			$this->fail( $exception->getMessage() );
		} finally {
			$output = (string) ob_get_clean();
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', $die_handler );
		}

		$this->assertSame( '', $output );
	}

	/**
	 * @testdox Should not process the BNPL availability AJAX callback for ineligible messaging.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_does_not_process_bnpl_availability_ajax_for_ineligible_messaging(): void {
		$controller  = $this->create_controller( true, false, array( 'affirm' ), array( 'affirm_payments' => 'active' ) );
		$die_handler = static function () {
			return static function () {
				throw new \Exception( 'ajax-die' );
			};
		};

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $die_handler );

		ob_start();
		try {
			$controller->handle_check_bnpl_availability();
		} catch ( \Exception $exception ) {
			$this->fail( $exception->getMessage() );
		} finally {
			$output = (string) ob_get_clean();
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', $die_handler );
		}

		$this->assertSame( '', $output );
	}

	/**
	 * @testdox Should reuse eligibility and active methods during repeated eligible rendering.
	 */
	public function test_reuses_eligibility_and_active_methods_during_repeated_eligible_rendering(): void {
		$product = \WC_Helper_Product::create_simple_product( true, array( 'regular_price' => '50.00' ) );
		$this->set_current_product( $product );

		$account_service = $this->create_account_service( true, array( 'affirm' ), array( 'affirm_payments' => 'active' ), 1 );
		$controller      = $this->create_controller( true, true, array( 'affirm' ), array( 'affirm_payments' => 'active' ), $account_service );

		ob_start();
		$controller->render_site_messaging();
		$payment_methods = $this->get_localized_script_data()['paymentMethods'];
		$controller->render_site_messaging();
		$output = (string) ob_get_clean();

		$this->assertSame( '<div id="payment-method-message"></div><div id="payment-method-message"></div>', $output );
		$this->assertSame( array( 'affirm' ), $payment_methods );
	}

	/**
	 * @testdox Should localize active BNPL messaging config and render the product-page container.
	 */
	public function test_localizes_active_bnpl_messaging_config_and_renders_product_container(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_currency', 'USD' );
		$product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'regular_price' => '50.00',
				'price'         => '50.00',
			)
		);
		$this->set_current_product( $product );

		$controller = $this->create_controller(
			true,
			true,
			array( 'card', 'affirm', 'afterpay_clearpay', 'klarna', 'ideal' ),
			array(
				'affirm_payments'            => 'active',
				'afterpay_clearpay_payments' => 'inactive',
				'klarna_payments'            => 'active',
				'ideal_payments'             => 'active',
			)
		);

		ob_start();
		$controller->render_site_messaging();
		$output = (string) ob_get_clean();

		$this->assertSame( '<div id="payment-method-message"></div>', $output );
		$this->assertTrue( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertStringContainsString(
			'/assets/js/frontend/woopayments-payment-method-messaging',
			wp_scripts()->registered[ self::SCRIPT_HANDLE ]->src
		);
		$this->assertContains( 'wc-woopayments-appearance', wp_scripts()->registered[ self::SCRIPT_HANDLE ]->deps );
		$this->assertTrue( wp_style_is( self::STYLE_HANDLE, 'enqueued' ) );
		$this->assertStringContainsString(
			'/assets/css/woopayments-payment-method-messaging.css',
			wp_styles()->registered[ self::STYLE_HANDLE ]->src
		);
		$script_data = $this->get_localized_script_data();

		$this->assertSame( 'base_product', $script_data['productId'] );
		$this->assertSame( array( 'affirm', 'klarna' ), $script_data['paymentMethods'] );
		$this->assertSame(
			array(
				'base_product' => array(
					'amount'   => 5000,
					'currency' => 'USD',
				),
			),
			$script_data['productVariations']
		);
		$this->assertSame( 'US', $script_data['country'] );
		$this->assertSame( 'en', $script_data['locale'] );
		$this->assertSame( 'acct_test', $script_data['accountId'] );
		$this->assertSame( 'pk_test_123', $script_data['publishableKey'] );
		$this->assertSame( 'USD', $script_data['currencyCode'] );
		$this->assertFalse( (bool) $script_data['isCart'] );
		$this->assertFalse( (bool) $script_data['isCartBlock'] );
		$this->assertSame( 0, (int) $script_data['cartTotal'] );
		$this->assertNotEmpty( $script_data['nonce']['get_cart_total'] );
		$this->assertNotEmpty( $script_data['nonce']['is_bnpl_available'] );
		$this->assertStringContainsString( '%%endpoint%%', $script_data['wcAjaxUrl'] );
		$this->assertSame( 'shared-styles-token', $script_data['stylesCacheVersion'] );
		$this->assertTrue( (bool) $script_data['shouldInitializePMME'] );
		$this->assertTrue( (bool) $script_data['shouldShowPMME'] );
	}

	/**
	 * @testdox Should enqueue the Blocks cart BNPL plugin on the cart-block surface.
	 */
	public function test_enqueues_blocks_cart_bnpl_plugin_on_cart_block_surface(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_currency', 'USD' );

		$controller = $this->create_controller( true, true, array( 'affirm' ), array( 'affirm_payments' => 'active' ) );
		$controller->register();
		$this->registered_controllers[] = $controller;

		ob_start();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test trigger for the existing cart-block enqueue hook.
		do_action( 'woocommerce_blocks_enqueue_cart_block_scripts_after' );
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output, 'Cart block PMME should render through the Blocks slotfill rather than classic placeholder markup.' );
		$this->assertFalse( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ), 'Classic PMME script should not be enqueued for the cart block.' );
		$this->assertTrue( wp_script_is( self::CART_BLOCK_SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertStringContainsString(
			'/assets/client/blocks/wc-woopayments-cart-block-payment-method-messaging.js',
			wp_scripts()->registered[ self::CART_BLOCK_SCRIPT_HANDLE ]->src
		);
		$this->assertContains( 'stripe', wp_scripts()->registered[ self::CART_BLOCK_SCRIPT_HANDLE ]->deps );
		$this->assertContains( 'wc-blocks-checkout', wp_scripts()->registered[ self::CART_BLOCK_SCRIPT_HANDLE ]->deps );
		$this->assertTrue( wp_style_is( self::CART_BLOCK_STYLE_HANDLE, 'enqueued' ) );
		$this->assertStringContainsString(
			'/assets/client/blocks/wc-woopayments-cart-block-payment-method-messaging.css',
			wp_styles()->registered[ self::CART_BLOCK_STYLE_HANDLE ]->src
		);

		$script_data = $this->get_localized_script_data( self::CART_BLOCK_SCRIPT_HANDLE );

		$this->assertSame( array( 'affirm' ), $script_data['paymentMethods'] );
		$this->assertTrue( (bool) $script_data['isCartBlock'] );
		$this->assertTrue( (bool) $script_data['shouldInitializePMME'] );
	}

	/**
	 * @testdox Should report BNPL availability from native amount limits.
	 */
	public function test_reports_bnpl_availability_from_native_amount_limits(): void {
		$controller = $this->create_controller( true, true, array( 'affirm' ), array( 'affirm_payments' => 'active' ) );

		$this->assertSame(
			array(
				'success' => true,
				'data'    => array( 'is_available' => true ),
			),
			$controller->get_bnpl_availability_response(
				array(
					'price'    => 5000,
					'currency' => 'USD',
					'country'  => 'US',
				)
			)
		);

		$this->assertSame(
			array(
				'success' => true,
				'data'    => array( 'is_available' => false ),
			),
			$controller->get_bnpl_availability_response(
				array(
					'price'    => 50,
					'currency' => 'USD',
					'country'  => 'US',
				)
			)
		);
	}

	/**
	 * Create a BNPL messaging controller.
	 *
	 * @param bool                           $native_register        Whether native should own runtime.
	 * @param bool                           $gateway_enabled        Whether the gateway setting is enabled.
	 * @param array<int,string>              $enabled_payment_methods Enabled payment method IDs.
	 * @param array<string,string>           $capabilities           Capability statuses keyed by Stripe capability ID.
	 * @param WooPaymentsAccountService|null $account_service Optional account service double.
	 * @return WooPaymentsPaymentMethodMessaging
	 */
	private function create_controller(
		bool $native_register,
		bool $gateway_enabled,
		array $enabled_payment_methods,
		array $capabilities,
		?WooPaymentsAccountService $account_service = null
	): WooPaymentsPaymentMethodMessaging {
		$arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( $native_register );

		$account_service = $account_service ?? $this->create_account_service( $gateway_enabled, $enabled_payment_methods, $capabilities );

		$frontend_styles_service = $this->getMockBuilder( WooPaymentsFrontendStylesService::class )
			->onlyMethods( array( 'get_styles_cache_version' ) )
			->getMock();
		$frontend_styles_service->method( 'get_styles_cache_version' )->willReturn( 'shared-styles-token' );

		$controller = new WooPaymentsPaymentMethodMessaging();
		$controller->init( $arbiter, $account_service, new WooPaymentsPaymentMethodRegistry(), new WooPaymentsOrderDataService(), $frontend_styles_service );

		return $controller;
	}

	/**
	 * Create an account service double for messaging tests.
	 *
	 * @param bool                 $gateway_enabled        Whether the gateway setting is enabled.
	 * @param array<int,string>    $enabled_payment_methods Enabled payment method IDs.
	 * @param array<string,string> $capabilities           Capability statuses keyed by Stripe capability ID.
	 * @param int|null             $eligibility_call_count Expected calls to each eligibility read, if constrained.
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service( bool $gateway_enabled, array $enabled_payment_methods, array $capabilities, ?int $eligibility_call_count = null ): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments', 'is_gateway_enabled', 'get_account_id', 'get_publishable_key', 'get_cached_account_data', 'get_gateway_setting' ) )
			->getMock();

		if ( null === $eligibility_call_count ) {
			$account_service->method( 'can_process_payments' )->willReturn( $gateway_enabled );
			$account_service->method( 'is_gateway_enabled' )->willReturn( $gateway_enabled );
			$account_service->method( 'get_cached_account_data' )->willReturn(
				array(
					'country'      => 'US',
					'capabilities' => $capabilities,
				)
			);
			$account_service->method( 'get_gateway_setting' )->willReturnCallback(
				static function ( string $key, $fallback = null ) use ( $enabled_payment_methods ) {
					return 'upe_enabled_payment_method_ids' === $key ? $enabled_payment_methods : $fallback;
				}
			);
		} else {
			$account_service->expects( $this->exactly( $eligibility_call_count ) )->method( 'can_process_payments' )->willReturn( $gateway_enabled );
			$account_service->expects( $this->exactly( $eligibility_call_count ) )->method( 'is_gateway_enabled' )->willReturn( $gateway_enabled );
			$account_service->expects( $this->exactly( $eligibility_call_count ) )->method( 'get_cached_account_data' )->willReturn(
				array(
					'country'      => 'US',
					'capabilities' => $capabilities,
				)
			);
			$account_service->expects( $this->exactly( $eligibility_call_count ) )->method( 'get_gateway_setting' )->willReturnCallback(
				static function ( string $key, $fallback = null ) use ( $enabled_payment_methods ) {
					return 'upe_enabled_payment_method_ids' === $key ? $enabled_payment_methods : $fallback;
				}
			);
		}

		$account_service->method( 'get_account_id' )->willReturn( 'acct_test' );
		$account_service->method( 'get_publishable_key' )->willReturn( 'pk_test_123' );

		return $account_service;
	}

	/**
	 * Assert that none of the expected hooks were registered.
	 *
	 * @param WooPaymentsPaymentMethodMessaging $controller Messaging controller.
	 */
	private function assert_hooks_not_registered( WooPaymentsPaymentMethodMessaging $controller ): void {
		foreach ( $this->get_expected_hooks() as $hook => $method ) {
			$this->assertFalse( has_action( $hook, array( $controller, $method ) ), "{$hook} should not be registered." );
		}
	}

	/**
	 * Get expected hooks and callback methods.
	 *
	 * @return array<string,string>
	 */
	private function get_expected_hooks(): array {
		return array(
			'woocommerce_single_product_summary'    => 'render_site_messaging',
			'woocommerce_proceed_to_checkout'       => 'render_site_messaging',
			'woocommerce_blocks_enqueue_cart_block_scripts_after' => 'render_site_messaging',
			'wc_ajax_wcpay_get_cart_total'          => 'handle_get_cart_total',
			'wc_ajax_wcpay_check_bnpl_availability' => 'handle_check_bnpl_availability',
		);
	}

	/**
	 * Set the current queried product.
	 *
	 * @param WC_Product $product Product object.
	 */
	private function set_current_product( WC_Product $product ): void {
		$this->reset_frontend_surface_state();
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
	 * Get localized BNPL messaging script data.
	 *
	 * @param string $script_handle Script handle.
	 * @return array<string,mixed>
	 */
	private function get_localized_script_data( string $script_handle = self::SCRIPT_HANDLE ): array {
		$script_data_string = wp_scripts()->get_data( $script_handle, 'data' );
		$this->assertIsString( $script_data_string );

		$start_pos = strpos( $script_data_string, '{' );
		$end_pos   = strrpos( $script_data_string, '}' );
		$this->assertIsInt( $start_pos );
		$this->assertIsInt( $end_pos );

		$script_data = json_decode( substr( $script_data_string, $start_pos, ( $end_pos - $start_pos ) + 1 ), true );
		$this->assertIsArray( $script_data );

		return $script_data;
	}
}
