<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutStoreApiExtension;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendTrackingController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenizedCartSessionController;
use Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema;
use Automattic\WooCommerce\StoreApi\StoreApi;
use Automattic\WooCommerce\StoreApi\Utilities\OrderController;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticNativeRuntimeArbiter;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsExpressCheckoutStoreApiExtension class.
 */
class WooPaymentsExpressCheckoutStoreApiExtensionTest extends WC_Unit_Test_Case {

	/**
	 * Countries WooPayments 11.1.0 makes the state optional for (`Express_Checkout_Element_States::COUNTRIES_WITHOUT_STATES`).
	 */
	private const CLIENT_COUNTRIES_WITHOUT_STATES = array( 'DZ', 'AO', 'BD', 'BJ', 'BO', 'BG', 'HR', 'DO', 'GH', 'GT', 'HU', 'KE', 'LA', 'LR', 'LT', 'MD', 'NA', 'NP', 'PK', 'PY', 'RO', 'SA', 'ZA', 'TZ', 'UG', 'ZM' );

	/**
	 * System under test.
	 *
	 * @var WooPaymentsExpressCheckoutStoreApiExtension|null
	 */
	private ?WooPaymentsExpressCheckoutStoreApiExtension $sut = null;

	/**
	 * Original request URI.
	 *
	 * @var mixed
	 */
	private $original_request_uri;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->original_request_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Raw snapshot restored in tearDown.
		$this->unregister_refresh_ui_callback();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		if ( $this->sut instanceof WooPaymentsExpressCheckoutStoreApiExtension ) {
			remove_action( 'woocommerce_blocks_loaded', array( $this->sut, 'register_store_api_extension' ) );
			remove_filter( 'woocommerce_get_country_locale', array( $this->sut, 'modify_country_locale_for_express_checkout' ), 20 );
			remove_action( 'init', array( $this->sut, 'register_refresh_ui_update_callback' ), 15 );
		}

		if ( null === $this->original_request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->original_request_uri;
		}
		unset( $_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART'], $_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART_NONCE'], $_GET['change_payment_method'], $_GET['rest_route'], $_REQUEST['rest_route'] );
		remove_all_filters( 'wp_doing_cron' );
		WC()->countries->locale = array();
		$this->unregister_refresh_ui_callback();
		delete_option( 'woocommerce_currency' );
		parent::tearDown();
	}

	/**
	 * @testdox Should register Store API extension hooks only when native WooPayments owns the runtime.
	 */
	public function test_registers_hooks_only_when_native_owns_runtime(): void {
		$this->sut = $this->create_extension( false );
		$this->sut->register();

		$this->assertFalse( has_action( 'woocommerce_blocks_loaded', array( $this->sut, 'register_store_api_extension' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_get_country_locale', array( $this->sut, 'modify_country_locale_for_express_checkout' ) ) );
		$this->assertFalse( has_action( 'init', array( $this->sut, 'register_refresh_ui_update_callback' ) ) );

		$this->sut = $this->create_extension( true );
		$this->sut->register();

		$this->assertSame( 10, has_action( 'woocommerce_blocks_loaded', array( $this->sut, 'register_store_api_extension' ) ) );
		$this->assertSame( 20, has_filter( 'woocommerce_get_country_locale', array( $this->sut, 'modify_country_locale_for_express_checkout' ) ) );
		$this->assertSame( 15, has_action( 'init', array( $this->sut, 'register_refresh_ui_update_callback' ) ) );
	}

	/**
	 * @testdox Should expose the client's Apple Pay/Google Pay and Amazon Pay methods for the current currency.
	 *
	 * @dataProvider provider_cart_extension_methods
	 *
	 * @param bool              $payment_request Whether Apple Pay/Google Pay is enabled.
	 * @param bool              $amazon_pay      Whether Amazon Pay is usable.
	 * @param array<int,string> $expected        Expected methods.
	 */
	public function test_get_cart_extension_data_returns_client_methods( bool $payment_request, bool $amazon_pay, array $expected ): void {
		update_option( 'woocommerce_currency', 'EUR' );

		$this->sut = $this->create_extension( true, $payment_request, $amazon_pay, true, 'EUR' );

		$this->assertSame(
			array( 'express_checkout_methods' => $expected ),
			$this->sut->get_cart_extension_data()
		);
	}

	/**
	 * Data provider for cart extension method sets.
	 *
	 * @return array<string,array{0:bool,1:bool,2:array<int,string>}>
	 */
	public function provider_cart_extension_methods(): array {
		return array(
			'both methods'         => array( true, true, array( 'payment_request', 'amazon_pay' ) ),
			'payment request only' => array( true, false, array( 'payment_request' ) ),
			'amazon pay only'      => array( false, true, array( 'amazon_pay' ) ),
			'no methods'           => array( false, false, array() ),
		);
	}

	/**
	 * @testdox Should never expose WooPay or cart-location gating in the Store API express checkout methods.
	 */
	public function test_get_cart_extension_data_excludes_woopay_and_ignores_locations(): void {
		$service   = $this->create_real_service(
			array(
				'payment_request'                   => 'yes',
				'express_checkout_product_methods'  => array( 'woopay' ),
				'express_checkout_cart_methods'     => array( 'woopay' ),
				'express_checkout_checkout_methods' => array( 'woopay' ),
			)
		);
		$this->sut = new WooPaymentsExpressCheckoutStoreApiExtension();
		$this->sut->init( new StaticNativeRuntimeArbiter( true ), $service );

		$this->assertSame(
			array( 'express_checkout_methods' => array( 'payment_request' ) ),
			$this->sut->get_cart_extension_data()
		);
	}

	/**
	 * @testdox Should expose the reference-compatible Store API extension schema.
	 */
	public function test_get_cart_extension_schema_returns_express_checkout_methods_schema(): void {
		$this->sut = $this->create_extension( true );

		$this->assertSame(
			array(
				'express_checkout_methods' => array(
					'description' => __( 'Express Checkout methods available for the cart\'s current currency.', 'woocommerce' ),
					'type'        => 'array',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
					'items'       => array(
						'type' => 'string',
					),
				),
			),
			$this->sut->get_cart_extension_schema()
		);
	}

	/**
	 * @testdox Should make the state optional for the client's 26 countries on express checkout Store API requests.
	 */
	public function test_express_checkout_request_makes_state_optional_for_client_countries(): void {
		$this->sut = $this->create_extension( true );
		$this->set_express_checkout_request();

		$locales = $this->sut->modify_country_locale_for_express_checkout(
			array(
				'RO' => array(
					'state' => array(
						'label'    => 'County',
						'required' => true,
					),
				),
				'US' => array(
					'state' => array(
						'required' => true,
					),
				),
			)
		);

		foreach ( self::CLIENT_COUNTRIES_WITHOUT_STATES as $country_code ) {
			$this->assertFalse( $locales[ $country_code ]['state']['required'], "State should be optional for {$country_code}." );
		}
		$this->assertSame( 'County', $locales['RO']['state']['label'] );
		$this->assertTrue( $locales['US']['state']['required'] );
		$this->assertCount( 27, $locales );
	}

	/**
	 * @testdox Should detect express checkout Store API requests made through the rest_route query argument.
	 */
	public function test_express_checkout_request_through_rest_route_query_argument(): void {
		$this->sut = $this->create_extension( true );
		$this->set_express_checkout_request( '/?rest_route=/wc/store/v1/checkout' );
		$_REQUEST['rest_route'] = '/wc/store/v1/checkout';

		$locales = $this->sut->modify_country_locale_for_express_checkout( array() );

		$this->assertFalse( $locales['RO']['state']['required'] );
	}

	/**
	 * @testdox Should leave the country locales unchanged when a client express checkout condition does not hold.
	 *
	 * @dataProvider provider_non_express_checkout_requests
	 *
	 * @param callable $arrange Arranges the request after a valid express checkout request is set.
	 * @param bool     $available Whether express checkout is available.
	 */
	public function test_non_express_checkout_request_leaves_locales_unchanged( callable $arrange, bool $available = true ): void {
		$this->sut = $this->create_extension( true, true, false, $available );
		$this->set_express_checkout_request();
		$arrange();

		$locales = array(
			'RO' => array(
				'state' => array(
					'required' => true,
				),
			),
		);

		$this->assertSame( $locales, $this->sut->modify_country_locale_for_express_checkout( $locales ) );
	}

	/**
	 * Data provider for requests the client does not treat as express checkout.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function provider_non_express_checkout_requests(): array {
		return array(
			'missing tokenized cart header'   => array(
				static function (): void {
					unset( $_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART'] );
				},
			),
			'tokenized cart header not true'  => array(
				static function (): void {
					$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART'] = '1';
				},
			),
			'missing nonce'                   => array(
				static function (): void {
					unset( $_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART_NONCE'] );
				},
			),
			'invalid nonce'                   => array(
				static function (): void {
					$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART_NONCE'] = wp_create_nonce( 'another_action' );
				},
			),
			'store api route not allowlisted' => array(
				static function (): void {
					$_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/batch';
				},
			),
			'non store api route'             => array(
				static function (): void {
					$_SERVER['REQUEST_URI'] = '/wp-json/wc/v3/orders';
				},
			),
			'cron request'                    => array(
				static function (): void {
					add_filter( 'wp_doing_cron', '__return_true' );
				},
			),
			'change payment method request'   => array(
				static function (): void {
					$_GET['change_payment_method'] = '123';
				},
			),
			'express checkout unavailable'    => array(
				static function (): void {},
				false,
			),
		);
	}

	/**
	 * @testdox Should skip Store API state validation for listed countries only on express checkout requests.
	 */
	public function test_store_api_address_validation_skips_state_only_for_express_checkout(): void {
		$this->sut = $this->create_extension( true );
		$this->sut->register();
		$order = wc_create_order();
		$order->set_billing_first_name( 'Ana' );
		$order->set_billing_last_name( 'Pop' );
		$order->set_billing_address_1( 'Strada 1' );
		$order->set_billing_city( 'Cluj' );
		$order->set_billing_postcode( '400001' );
		$order->set_billing_country( 'RO' );
		$order->set_billing_phone( '0712345678' );
		$order->set_billing_state( '' );

		WC()->countries->locale = array();
		$non_express_errors     = $this->validate_billing_address( $order );

		$this->set_express_checkout_request();
		WC()->countries->locale = array();
		$express_errors         = $this->validate_billing_address( $order );

		$this->assertSame( array( 'state' ), $non_express_errors->get_all_error_data( 'billing' ) );
		$this->assertSame( array(), $express_errors->get_all_error_data( 'billing' ) );
	}

	/**
	 * @testdox Should register the no-op refresh-ui Store API cart update callback when express checkout is available.
	 */
	public function test_registers_refresh_ui_update_callback(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/cart/extensions';
		$this->sut              = $this->create_extension( true );
		$this->sut->register_refresh_ui_update_callback();

		$callback = StoreApi::container()->get( ExtendSchema::class )->get_update_callback( 'woopayments/express-checkout/refresh-ui' );

		$this->assertSame( '__return_null', $callback );
		$this->assertSame( 'woopayments/express-checkout/refresh-ui', WooPaymentsExpressCheckoutStoreApiExtension::REFRESH_UI_NAMESPACE );
	}

	/**
	 * @testdox Should answer refresh-ui cart extension requests with the cart only after the callback registers.
	 */
	public function test_refresh_ui_cart_extensions_request(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/cart/extensions';
		$this->sut              = $this->create_extension( true );
		$request                = new \WP_REST_Request( 'POST', '/wc/store/v1/cart/extensions' );
		$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
		$request->set_body_params( array( 'namespace' => 'woopayments/express-checkout/refresh-ui' ) );

		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );

		$this->sut->register_refresh_ui_update_callback();
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'totals', $response->get_data() );
	}

	/**
	 * @testdox Should not register the refresh-ui callback when a client registration guard fails.
	 *
	 * @dataProvider provider_refresh_ui_registration_guards
	 *
	 * @param callable $arrange   Arranges the request.
	 * @param bool     $available Whether express checkout is available.
	 */
	public function test_does_not_register_refresh_ui_update_callback_when_guard_fails( callable $arrange, bool $available ): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/cart/extensions';
		$this->sut              = $this->create_extension( true, true, false, $available );
		$arrange();

		$this->sut->register_refresh_ui_update_callback();

		$this->expectException( \Exception::class );
		StoreApi::container()->get( ExtendSchema::class )->get_update_callback( 'woopayments/express-checkout/refresh-ui' );
	}

	/**
	 * Data provider for refresh-ui registration guards.
	 *
	 * @return array<string,array{0:callable,1:bool}>
	 */
	public function provider_refresh_ui_registration_guards(): array {
		return array(
			'express checkout unavailable'  => array( static function (): void {}, false ),
			// The Cart and Checkout blocks preload the cart inside the page request; only Store API requests run the callback.
			'shop page request'             => array(
				static function (): void {
					$_SERVER['REQUEST_URI'] = '/checkout/';
				},
				true,
			),
			'cron request'                  => array(
				static function (): void {
					add_filter( 'wp_doing_cron', '__return_true' );
				},
				true,
			),
			'change payment method request' => array(
				static function (): void {
					$_GET['change_payment_method'] = '123';
				},
				true,
			),
		);
	}

	/**
	 * Run Store API billing address validation for an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return \WP_Error
	 */
	private function validate_billing_address( \WC_Order $order ): \WP_Error {
		$errors = new \WP_Error();
		$method = new \ReflectionMethod( OrderController::class, 'validate_address_fields' );
		$method->setAccessible( true );
		$method->invoke( new OrderController(), $order, 'billing', $errors );

		return $errors;
	}

	/**
	 * Simulate an express checkout (tokenized cart) Store API request.
	 *
	 * @param string $request_uri Request URI.
	 */
	private function set_express_checkout_request( string $request_uri = '/wp-json/wc/store/v1/checkout' ): void {
		$_SERVER['REQUEST_URI']                             = $request_uri;
		$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART']       = 'true';
		$_SERVER['HTTP_X_WOOPAYMENTS_TOKENIZED_CART_NONCE'] = wp_create_nonce( WooPaymentsTokenizedCartSessionController::TOKENIZED_CART_NONCE_ACTION );
	}

	/**
	 * Remove the refresh-ui callback from the shared Store API extend schema.
	 */
	private function unregister_refresh_ui_callback(): void {
		$extend   = StoreApi::container()->get( ExtendSchema::class );
		$property = new \ReflectionProperty( ExtendSchema::class, 'callback_methods' );
		$property->setAccessible( true );
		$callbacks = $property->getValue( $extend );
		unset( $callbacks['woopayments/express-checkout/refresh-ui'] );
		$property->setValue( $extend, $callbacks );
	}

	/**
	 * Create the System Under Test.
	 *
	 * @param bool   $native_register Whether native should register.
	 * @param bool   $payment_request Whether Apple Pay/Google Pay is enabled.
	 * @param bool   $amazon_pay      Whether Amazon Pay is usable.
	 * @param bool   $available       Whether express checkout is available.
	 * @param string $currency        Expected cart currency.
	 * @return WooPaymentsExpressCheckoutStoreApiExtension
	 */
	private function create_extension( bool $native_register, bool $payment_request = true, bool $amazon_pay = false, bool $available = true, string $currency = 'USD' ): WooPaymentsExpressCheckoutStoreApiExtension {
		$express_checkout_service = $this->getMockBuilder( WooPaymentsExpressCheckoutService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_payment_request_enabled', 'can_use_amazon_pay', 'is_express_checkout_available' ) )
			->getMock();
		$express_checkout_service->method( 'is_payment_request_enabled' )->willReturn( $payment_request );
		$express_checkout_service->method( 'is_express_checkout_available' )->willReturn( $available );
		$express_checkout_service
			->method( 'can_use_amazon_pay' )
			->willReturnCallback(
				function ( string $requested_currency = '' ) use ( $amazon_pay, $currency ): bool {
					$this->assertSame( $currency, $requested_currency );

					return $amazon_pay;
				}
			);

		$extension = new WooPaymentsExpressCheckoutStoreApiExtension();
		$extension->init( new StaticNativeRuntimeArbiter( $native_register ), $express_checkout_service );

		return $extension;
	}

	/**
	 * Create a real express checkout service over mocked account settings.
	 *
	 * @param array<string,mixed> $settings Gateway settings.
	 * @return WooPaymentsExpressCheckoutService
	 */
	private function create_real_service( array $settings ): WooPaymentsExpressCheckoutService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'get_gateway_setting', 'is_payment_request_enabled' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturn( array( 'country' => 'US' ) );
		$account_service->method( 'is_payment_request_enabled' )->willReturn( 'yes' === ( $settings['payment_request'] ?? 'no' ) );
		$account_service->method( 'get_gateway_setting' )->willReturnCallback(
			static fn( string $key, $fallback = null ) => array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback
		);

		$service = new WooPaymentsExpressCheckoutService();
		$service->init(
			$account_service,
			$this->createMock( WooPaymentsProvider::class ),
			$this->createMock( WooPaymentsFrontendTrackingController::class )
		);

		return $service;
	}
}
