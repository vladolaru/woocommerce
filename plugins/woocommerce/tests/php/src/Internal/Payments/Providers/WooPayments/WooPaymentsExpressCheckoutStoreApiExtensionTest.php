<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\ProductTaxStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutStoreApiExtension;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendTrackingController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenizedCartSessionController;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema;
use Automattic\WooCommerce\StoreApi\StoreApi;
use Automattic\WooCommerce\StoreApi\Utilities\OrderController;
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
		unset( $GLOBALS[ WooCommerceSubscriptionsDoubles::CART_CONTAINS_SUBSCRIPTION ] );
		WC()->countries->locale = array();
		$this->unregister_refresh_ui_callback();
		delete_option( 'woocommerce_woocommerce_payments_apple_pay_settings' );
		delete_option( 'woocommerce_woocommerce_payments_google_pay_settings' );
		delete_option( 'woocommerce_currency' );
		// Dispatching here may build the REST server, and the hooks rest_api_init added go when this test's hooks are
		// restored. Drop the server so a later test builds a fresh one with its hooks.
		$GLOBALS['wp_rest_server'] = null;
		parent::tearDown();
	}

	/**
	 * @testdox Should register Store API extension hooks.
	 */
	public function test_registers_hooks(): void {
		$this->sut = $this->create_extension();
		$this->sut->register();

		$this->assertSame( 10, has_action( 'woocommerce_blocks_loaded', array( $this->sut, 'register_store_api_extension' ) ) );
		$this->assertSame( 20, has_filter( 'woocommerce_get_country_locale', array( $this->sut, 'modify_country_locale_for_express_checkout' ) ) );
		$this->assertSame( 15, has_action( 'init', array( $this->sut, 'register_refresh_ui_update_callback' ) ) );
	}

	/**
	 * @testdox Should expose the WooPayments extension's Apple Pay/Google Pay and Amazon Pay methods for the current currency.
	 *
	 * @dataProvider provider_cart_extension_methods
	 *
	 * @param bool              $payment_request Whether Apple Pay/Google Pay is enabled.
	 * @param bool              $amazon_pay      Whether Amazon Pay is usable.
	 * @param array<int,string> $expected        Expected methods.
	 */
	public function test_get_cart_extension_data_returns_woopayments_extension_methods( bool $payment_request, bool $amazon_pay, array $expected ): void {
		update_option( 'woocommerce_currency', 'EUR' );

		$this->sut = $this->create_extension( $payment_request, $amazon_pay, true, 'EUR' );

		$this->assertSame(
			array(
				'express_checkout_methods' => $expected,
				'setup_future_usage'       => null,
				'cart_supported'           => true,
				'tax_risk'                 => false,
			),
			$this->sut->get_cart_extension_data()
		);
	}

	/**
	 * @testdox Should keep the currency methods for product previews while the Blocks cart follows additions and removals.
	 */
	public function test_cart_support_follows_sku_exclusions(): void {
		wc_empty_cart();
		$this->sut = $this->create_extension( true, true );
		$eligible  = \WC_Helper_Product::create_simple_product();
		$excluded  = \WC_Helper_Product::create_simple_product();
		add_filter( 'wcpay_payment_request_is_cart_supported', static fn( $allowed, $product ) => $allowed && $product->get_id() !== $excluded->get_id(), 10, 2 );
		WC()->cart->add_to_cart( $eligible->get_id() );
		$this->assert_cart_wallet_data( true, false );
		$key = WC()->cart->add_to_cart( $excluded->get_id() );
		$this->assert_cart_wallet_data( false, false );
		$this->assertSame( array( 'payment_request', 'amazon_pay' ), $this->sut->get_cart_extension_data()['express_checkout_methods'] );
		WC()->cart->remove_cart_item( $key );
		$this->assert_cart_wallet_data( true, false );
	}

	/**
	 * @testdox Should mark billing-address tax only while a taxable line has no shipping address update.
	 */
	public function test_cart_tax_follows_taxable_items_and_shipping(): void {
		wc_empty_cart();
		$this->sut = $this->create_extension();
		( new \WC_Shipping_Zone( 0 ) )->add_shipping_method( 'flat_rate' );
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_tax_based_on', 'billing' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		\WC_Tax::_insert_tax_rate(
			array(
				'tax_rate'          => '10.0000',
				'tax_rate_name'     => 'Test tax',
				'tax_rate_priority' => 1,
			)
		);
		$virtual = \WC_Helper_Product::create_simple_product( true, array( 'regular_price' => '100' ) );
		$virtual->set_virtual( true );
		$virtual->set_tax_status( ProductTaxStatus::NONE );
		$virtual->save();
		$this->assert_cart_wallet_data( true, false );
		$key = WC()->cart->add_to_cart( $virtual->get_id() );
		$this->assert_cart_wallet_data( true, false );
		WC()->cart->remove_cart_item( $key );
		$virtual->set_tax_status( ProductTaxStatus::TAXABLE );
		$virtual->save();
		WC()->cart->add_to_cart( $virtual->get_id() );
		$this->assert_cart_wallet_data( true, true );
		$physical = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $physical->get_id() );
		$this->assert_cart_wallet_data( true, false );
	}

	/**
	 * @testdox Should use the merchant's answer for the Store API request on the mounted cart.
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $allow_api Whether the filter accepts the Store API request.
	 */
	public function test_cart_support_uses_current_request( bool $allow_api ): void {
		wc_empty_cart();
		$this->sut = $this->create_extension();
		$product   = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $product->get_id() );
		add_filter( 'wcpay_payment_request_is_cart_supported', static fn() => WC()->is_store_api_request() === $allow_api );
		foreach ( array( '/cart/', '/checkout/', '/wp-json/wc/store/v1/cart/add-item' ) as $uri ) {
			$_SERVER['REQUEST_URI'] = $uri;
			$this->assert_cart_wallet_data( '/wp-json/wc/store/v1/cart/add-item' === $uri ? $allow_api : ! $allow_api, false );
		}
	}

	/**
	 * Check the two independent Blocks fields on the current cart.
	 *
	 * @param bool $supported Whether the cart supports wallets.
	 * @param bool $tax_risk  Whether a taxable line can add unseen tax.
	 */
	private function assert_cart_wallet_data( bool $supported, bool $tax_risk ): void {
		$data = $this->sut->get_cart_extension_data();
		$this->assertSame( $supported, $data['cart_supported'] );
		$this->assertSame( $tax_risk, $data['tax_risk'] );
		$this->assertContains( 'payment_request', $data['express_checkout_methods'] );
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
		foreach ( array( 'apple_pay', 'google_pay' ) as $wallet_method_id ) {
			update_option( 'woocommerce_woocommerce_payments_' . $wallet_method_id . '_settings', array( 'enabled' => 'yes' ) );
		}
		$service   = $this->create_real_service(
			array(
				'express_checkout_product_methods'  => array( 'woopay' ),
				'express_checkout_cart_methods'     => array( 'woopay' ),
				'express_checkout_checkout_methods' => array( 'woopay' ),
			)
		);
		$this->sut = new WooPaymentsExpressCheckoutStoreApiExtension();
		$this->sut->init( $service );

		$this->assertSame(
			array(
				'express_checkout_methods' => array( 'payment_request' ),
				'setup_future_usage'       => null,
				'cart_supported'           => true,
				'tax_risk'                 => false,
			),
			$this->sut->get_cart_extension_data()
		);
	}

	/**
	 * On the storefront the client's Amazon Pay gateway is unavailable while it needs setup (client 11.1.0
	 * class-wc-payment-gateway-wcpay.php:946, needs_setup() at :851-858): without an account, without the account's `status` or
	 * `payments_enabled` (class-wc-payments-account.php:360-365), or with payments disabled. Its cart extension then lists no Amazon
	 * Pay (class-wc-payments-express-checkout-store-api-extension.php:81-93).
	 *
	 * @testdox Should list Amazon Pay in the Store API cart data only for an account that needs no setup: $scenario.
	 * @testWith ["a complete account", [], true]
	 *           ["no status", ["status"], false]
	 *           ["no payments_enabled", ["payments_enabled"], false]
	 *
	 * @param string   $scenario       Scenario description.
	 * @param string[] $missing_fields Account fields left out.
	 * @param bool     $listed         Whether Amazon Pay should be listed.
	 */
	public function test_get_cart_extension_data_lists_amazon_pay_only_for_an_account_that_needs_no_setup( string $scenario, array $missing_fields, bool $listed ): void {
		unset( $scenario );
		// Account fields as recorded in Fixtures/platform-test-drive-account.json `account`.
		$account_data = array(
			'account_id'                       => 'acct_store_api_test',
			'status'                           => 'complete',
			'payments_enabled'                 => true,
			'country'                          => 'US',
			'capabilities'                     => array( 'amazon_pay_payments' => 'active' ),
			'ece_confirmation_tokens_disabled' => false,
		);
		$service      = $this->create_real_service(
			array(
				'enabled'                           => 'yes',
				'upe_enabled_payment_method_ids'    => array( 'card', 'amazon_pay' ),
				'express_checkout_product_methods'  => array( 'amazon_pay' ),
				'express_checkout_cart_methods'     => array( 'amazon_pay' ),
				'express_checkout_checkout_methods' => array( 'amazon_pay' ),
			),
			array_diff_key( $account_data, array_flip( $missing_fields ) )
		);
		$this->sut    = new WooPaymentsExpressCheckoutStoreApiExtension();
		$this->sut->init( $service );

		$this->assertSame(
			array(
				'express_checkout_methods' => $listed ? array( 'amazon_pay' ) : array(),
				'setup_future_usage'       => null,
				'cart_supported'           => true,
				'tax_risk'                 => false,
			),
			$this->sut->get_cart_extension_data()
		);
	}

	/**
	 * @testdox Should list Amazon Pay in the Store API cart data while no express checkout location lists it (client 11.1.0 `extend_cart_data()`, class-wc-payments-express-checkout-store-api-extension.php:88, asks the location-blind `can_use_amazon_pay()`, button helper :362-388).
	 */
	public function test_get_cart_extension_data_lists_amazon_pay_whatever_its_locations(): void {
		$service = $this->create_real_service(
			array(
				'enabled'                           => 'yes',
				'upe_enabled_payment_method_ids'    => array( 'card', 'amazon_pay' ),
				'express_checkout_product_methods'  => array( 'payment_request' ),
				'express_checkout_cart_methods'     => array( 'payment_request' ),
				'express_checkout_checkout_methods' => array( 'payment_request' ),
			),
			// Account fields as recorded in Fixtures/platform-test-drive-account.json `account`.
			array(
				'account_id'                       => 'acct_store_api_test',
				'status'                           => 'complete',
				'payments_enabled'                 => true,
				'country'                          => 'US',
				'capabilities'                     => array( 'amazon_pay_payments' => 'active' ),
				'ece_confirmation_tokens_disabled' => false,
			)
		);
		$this->sut = new WooPaymentsExpressCheckoutStoreApiExtension();
		$this->sut->init( $service );

		$this->assertSame(
			array(
				'express_checkout_methods' => array( 'amazon_pay' ),
				'setup_future_usage'       => null,
				'cart_supported'           => true,
				'tax_risk'                 => false,
			),
			$this->sut->get_cart_extension_data()
		);
	}

	/**
	 * @testdox Should expose the reference-compatible Store API extension schema.
	 */
	public function test_get_cart_extension_schema_returns_express_checkout_methods_schema(): void {
		$this->sut = $this->create_extension();

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
				// Client 11.2.0 class-wc-payments-express-checkout-store-api-extension.php:118-124.
				'setup_future_usage'       => array(
					'description' => __( 'Whether the payment method should be saved for future off-session payments.', 'woocommerce' ),
					'type'        => array( 'string', 'null' ),
					'enum'        => array( 'off_session', null ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'cart_supported'           => array(
					'description' => __( 'Whether the current cart supports express checkout.', 'woocommerce' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'tax_risk'                 => array(
					'description' => __( 'Whether billing-address tax can change the wallet total for a taxable cart with no shipping.', 'woocommerce' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			),
			$this->sut->get_cart_extension_schema()
		);
	}

	/**
	 * @testdox Should send the setup_future_usage for the cart in the Store API cart data: $scenario (client 11.2.0 class-wc-payments-express-checkout-store-api-extension.php:98, button helper :347-366).
	 * @testWith ["a WooCommerce Subscriptions cart", true, "off_session"]
	 *           ["an ordinary cart", false, null]
	 *
	 * @param string      $scenario     Scenario description.
	 * @param bool        $subscription Whether the cart contains a subscription.
	 * @param string|null $expected     Expected setup_future_usage.
	 */
	public function test_get_cart_extension_data_sends_the_cart_setup_future_usage( string $scenario, bool $subscription, ?string $expected ): void {
		unset( $scenario );
		WooCommerceSubscriptionsDoubles::load_cart();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::CART_CONTAINS_SUBSCRIPTION ] = $subscription;
		$this->sut = $this->create_extension();

		$data = $this->sut->get_cart_extension_data();

		$this->assertArrayHasKey( 'setup_future_usage', $data );
		$this->assertSame( $expected, $data['setup_future_usage'] );
	}

	/**
	 * @testdox Should make the state optional for the WooPayments extension's 26 countries on express checkout Store API requests, also through the rest_route query argument: $request_uri.
	 *
	 * @testWith ["/wp-json/wc/store/v1/checkout", ""]
	 *           ["/?rest_route=/wc/store/v1/checkout", "/wc/store/v1/checkout"]
	 *
	 * @param string $request_uri Request URI.
	 * @param string $rest_route  The rest_route request argument, or '' for none.
	 */
	public function test_express_checkout_request_makes_state_optional_for_woopayments_extension_countries( string $request_uri, string $rest_route ): void {
		$this->sut = $this->create_extension();
		$this->set_express_checkout_request( $request_uri );
		if ( '' !== $rest_route ) {
			$_REQUEST['rest_route'] = $rest_route;
		}

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
	 * @testdox Should leave the country locales unchanged when one of the WooPayments extension's express checkout conditions does not hold.
	 *
	 * @dataProvider provider_non_express_checkout_requests
	 *
	 * @param callable $arrange Arranges the request after a valid express checkout request is set.
	 * @param bool     $available Whether express checkout is available.
	 */
	public function test_non_express_checkout_request_leaves_locales_unchanged( callable $arrange, bool $available = true ): void {
		$this->sut = $this->create_extension( true, false, $available );
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
		$this->sut = $this->create_extension();
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
	 * @testdox Should answer refresh-ui cart extension requests with the cart only after the callback registers.
	 */
	public function test_refresh_ui_cart_extensions_request(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/cart/extensions';
		$this->sut              = $this->create_extension();
		$request                = new \WP_REST_Request( 'POST', '/wc/store/v1/cart/extensions' );
		$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
		$request->set_body_params( array( 'namespace' => 'woopayments/express-checkout/refresh-ui' ) );

		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );

		$this->sut->register_refresh_ui_update_callback();
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 2 );
		try {
			$response = rest_get_server()->dispatch( $request );

			$this->assertSame( 200, $response->get_status() );
			$this->assertArrayHasKey( 'totals', $response->get_data() );
			$this->assertSame( 2, $response->get_data()['items_count'] ?? null, 'Refreshing the express checkout UI leaves the cart as it was.' );
		} finally {
			WC()->cart->empty_cart();
		}
	}

	/**
	 * @testdox Should not register the refresh-ui callback when one of the WooPayments extension's registration guards fails.
	 *
	 * @dataProvider provider_refresh_ui_registration_guards
	 *
	 * @param callable $arrange   Arranges the request.
	 * @param bool     $available Whether express checkout is available.
	 */
	public function test_does_not_register_refresh_ui_update_callback_when_guard_fails( callable $arrange, bool $available ): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/cart/extensions';
		$this->sut              = $this->create_extension( true, false, $available );
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
	 * @param bool   $payment_request Whether Apple Pay/Google Pay is enabled.
	 * @param bool   $amazon_pay      Whether Amazon Pay is usable.
	 * @param bool   $available       Whether express checkout is available.
	 * @param string $currency        Expected cart currency.
	 * @return WooPaymentsExpressCheckoutStoreApiExtension
	 */
	private function create_extension( bool $payment_request = true, bool $amazon_pay = false, bool $available = true, string $currency = 'USD' ): WooPaymentsExpressCheckoutStoreApiExtension {
		$express_checkout_service = $this->getMockBuilder( WooPaymentsExpressCheckoutService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_payment_request_enabled', 'is_amazon_pay_usable', 'is_express_checkout_available' ) )
			->getMock();
		$express_checkout_service->method( 'is_payment_request_enabled' )->willReturn( $payment_request );
		$express_checkout_service->method( 'is_express_checkout_available' )->willReturn( $available );
		$express_checkout_service
			->method( 'is_amazon_pay_usable' )
			->willReturnCallback(
				function ( string $context = 'checkout', string $requested_currency = '' ) use ( $amazon_pay, $currency ): bool {
					$this->assertSame( 'checkout', $context );
					$this->assertSame( $currency, $requested_currency );

					return $amazon_pay;
				}
			);

		$extension = new WooPaymentsExpressCheckoutStoreApiExtension();
		$extension->init( $express_checkout_service );

		return $extension;
	}

	/**
	 * Create a real express checkout service over mocked account settings.
	 *
	 * @param array<string,mixed> $settings     Gateway settings.
	 * @param array<string,mixed> $account_data Cached account data.
	 * @return WooPaymentsExpressCheckoutService
	 */
	private function create_real_service( array $settings, array $account_data = array( 'country' => 'US' ) ): WooPaymentsExpressCheckoutService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'get_gateway_setting', 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->init( wc_get_container()->get( LegacyProxy::class ), wc_get_container()->get( WooPaymentsDatabaseCache::class ) );
		$account_service->method( 'get_cached_account_data' )->willReturn( $account_data );
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );
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
