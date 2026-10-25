<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyAsyncPriceRendererController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyDisplayService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRequestContext;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyAsyncPriceRendererController class.
 */
class MultiCurrencyAsyncPriceRendererControllerTest extends WC_Unit_Test_Case {

	private const SCRIPT_HANDLE        = 'wc-multi-currency-async-renderer';
	private const LEGACY_SCRIPT_HANDLE = 'wcpay-multi-currency-async-renderer';

	/**
	 * Store price-format options a test changed, with their values before the change.
	 *
	 * @var array<string,mixed>
	 */
	private array $store_format_options = array();

	/**
	 * The WooCommerce session before the test replaced it.
	 *
	 * @var object|null
	 */
	private $original_session;

	/**
	 * Keep the WooCommerce session the test replaces.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->original_session = WC()->session;
	}

	/**
	 * Hooks touched by the controller.
	 *
	 * @var string[]
	 */
	private array $hooks = array(
		'wc_price',
		'woocommerce_format_sale_price',
		'woocommerce_format_price_range',
		'wp_enqueue_scripts',
		'wcpay_multi_currency_async_price_type',
	);

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		foreach ( $this->hooks as $hook ) {
			remove_all_filters( $hook );
		}

		unset( $_GET['currency'] );
		WC()->session = $this->original_session;
		Constants::clear_single_constant( 'WC_VERSION' );
		delete_option( 'wcpay_multi_currency_enable_auto_currency' );
		foreach ( $this->store_format_options as $option => $value ) {
			update_option( $option, $value );
		}
		$this->store_format_options = array();

		wp_dequeue_script( self::SCRIPT_HANDLE );
		wp_deregister_script( self::SCRIPT_HANDLE );
		wp_dequeue_script( self::LEGACY_SCRIPT_HANDLE );
		wp_deregister_script( self::LEGACY_SCRIPT_HANDLE );
		wp_dequeue_style( self::SCRIPT_HANDLE );
		wp_deregister_style( self::SCRIPT_HANDLE );
		wp_dequeue_style( self::LEGACY_SCRIPT_HANDLE );
		wp_deregister_style( self::LEGACY_SCRIPT_HANDLE );

		parent::tear_down();
	}

	/**
	 * @testdox Should not register async renderer hooks when plugin owns runtime.
	 */
	public function test_does_not_register_hooks_when_plugin_owns_runtime(): void {
		update_option( 'wcpay_multi_currency_enable_auto_currency', 'yes' );
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_EXTENSION );

		$sut->maybe_register_async_hooks();

		$this->assert_hooks_not_registered( $sut );
	}

	/**
	 * @testdox Should register async renderer hooks when core owns active cache mode.
	 */
	public function test_registers_hooks_when_core_owns_active_cache_mode(): void {
		update_option( 'wcpay_multi_currency_enable_auto_currency', 'yes' );
		$sut = $this->create_controller();

		$sut->maybe_register_async_hooks();
		$sut->maybe_register_async_hooks();

		$this->assertSame( 999, has_filter( 'wc_price', array( $sut, 'handle_wc_price' ) ) );
		$this->assertSame( 999, has_filter( 'woocommerce_format_sale_price', array( $sut, 'handle_woocommerce_format_sale_price' ) ) );
		$this->assertSame( 999, has_filter( 'woocommerce_format_price_range', array( $sut, 'handle_woocommerce_format_price_range' ) ) );
		$this->assertSame( 10, has_action( 'wp_enqueue_scripts', array( $sut, 'handle_wp_enqueue_scripts' ) ) );
	}

	/**
	 * @testdox Should not register async renderer hooks when activation is blocked.
	 *
	 * @dataProvider registration_blocker_data
	 *
	 * @param bool        $cache_optimized_mode Whether cache-optimized mode is active.
	 * @param bool        $frontend_request     Whether this is a frontend request.
	 * @param bool        $store_api_request    Whether this is a Store API request.
	 * @param bool        $admin_api_request    Whether this is an admin API request.
	 * @param bool        $active_session       Whether a WooCommerce session is active.
	 * @param bool        $auto_enabled         Whether automatic currency switching is enabled.
	 * @param string|null $query_currency       The request's currency query argument, null when absent.
	 */
	public function test_does_not_register_hooks_when_activation_is_blocked(
		bool $cache_optimized_mode,
		bool $frontend_request,
		bool $store_api_request,
		bool $admin_api_request,
		bool $active_session,
		bool $auto_enabled,
		?string $query_currency
	): void {
		update_option( 'wcpay_multi_currency_enable_auto_currency', $auto_enabled ? 'yes' : 'no' );

		if ( null !== $query_currency ) {
			$_GET['currency'] = $query_currency;
		}

		$sut = $this->create_controller(
			MultiCurrencyRuntimeArbiter::OWNER_BUILTIN,
			$cache_optimized_mode,
			$frontend_request,
			$store_api_request,
			$admin_api_request,
			$active_session
		);

		$sut->maybe_register_async_hooks();

		$this->assert_hooks_not_registered( $sut );
	}

	/**
	 * @testdox Should not register async renderer hooks in a $label request.
	 * @testWith ["wp-admin"]
	 *           ["cron"]
	 *
	 * @param string $label The request kind.
	 */
	public function test_does_not_register_hooks_in_admin_or_cron_requests( string $label ): void {
		update_option( 'wcpay_multi_currency_enable_auto_currency', 'yes' );
		if ( 'wp-admin' === $label ) {
			set_current_screen( 'dashboard' );
		} else {
			add_filter( 'wp_doing_cron', '__return_true' );
		}
		$sut = $this->create_controller();

		try {
			$sut->maybe_register_async_hooks();

			$this->assert_hooks_not_registered( $sut );
		} finally {
			unset( $GLOBALS['current_screen'] );
			remove_filter( 'wp_doing_cron', '__return_true' );
		}
	}

	/**
	 * @testdox Should defer async renderer registration to woocommerce_init instead of deciding during bootstrap.
	 */
	public function test_defers_registration_to_woocommerce_init(): void {
		update_option( 'wcpay_multi_currency_enable_auto_currency', 'yes' );
		$sut = $this->create_controller();

		$sut->register();
		$sut->register();

		$this->assertSame(
			10,
			has_action( 'woocommerce_init', array( $sut, 'maybe_register_async_hooks' ) ),
			'register() must hook the activation decision to woocommerce_init.'
		);
		$this->assert_hooks_not_registered( $sut );
	}

	/**
	 * @testdox Should not probe the WooCommerce session while registering, because that reenters the constructor.
	 */
	public function test_does_not_probe_the_session_during_registration(): void {
		update_option( 'wcpay_multi_currency_enable_auto_currency', 'yes' );
		$sut      = $this->create_controller();
		$probed   = false;
		$recorder = static function () use ( &$probed ): bool {
			$probed = true;

			return false;
		};
		$this->use_session( $recorder );

		$sut->register();

		// register() runs from WooCommerce::init_hooks(), inside the constructor,
		// where the singleton is unassigned. A session probe there resolves WC()
		// into a second construction and recurses until memory is exhausted, so
		// the probe must not happen until the deferred callback runs.
		$this->assertFalse(
			$probed,
			'register() must not probe the WooCommerce session; doing so recurses through WC() during bootstrap.'
		);

		$sut->maybe_register_async_hooks();

		$this->assertTrue(
			$probed,
			'The deferred callback must still make the session-dependent activation decision.'
		);
	}

	/**
	 * @testdox Should wrap prices with async skeleton markup.
	 */
	public function test_wraps_prices_with_async_skeleton_markup(): void {
		$sut = $this->create_controller();

		add_filter(
			'wcpay_multi_currency_async_price_type',
			static function ( string $price_type, $price, array $args ): string {
				return 'yes' === ( $args['shipping'] ?? 'no' ) && '25' === (string) $price ? 'shipping' : $price_type;
			},
			10,
			3
		);

		$result = $sut->handle_wc_price(
			'<span class="woocommerce-Price-amount amount"><bdi>$25.00</bdi></span>',
			'25',
			array( 'shipping' => 'yes' ),
			'25.00',
			'25'
		);

		$this->assertStringContainsString( 'class="woocommerce-Price-amount amount wcpay-async-price"', $result );
		$this->assertStringContainsString( 'data-wcpay-price="25.00"', $result );
		$this->assertStringContainsString( 'data-wcpay-price-type="shipping"', $result );
		$this->assertStringContainsString( '<bdi class="wcpay-price-skeleton"></bdi>', $result );
		$this->assertStringContainsString( '<span class="screen-reader-text wcpay-price-placeholder">', $result );
		$this->assertStringContainsString( '$25.00', $result );
	}

	/**
	 * @testdox Should escape the price type and strip scripts from the placeholder markup.
	 */
	public function test_escapes_the_price_type_and_strips_scripts_from_the_placeholder(): void {
		$sut = $this->create_controller();
		add_filter(
			'wcpay_multi_currency_async_price_type',
			static fn(): string => 'shipping" data-bad="1'
		);

		$result = $sut->handle_wc_price(
			'<span class="woocommerce-Price-amount amount"><bdi><script>alert(1)</script>$25.99</bdi></span>',
			'25.99',
			array(),
			'25.99',
			'25.99'
		);

		$this->assertStringContainsString( 'data-wcpay-price-type="shipping&quot; data-bad=&quot;1"', $result );
		$this->assertStringContainsString( '$25.99', $result );
		$this->assertStringNotContainsString( '<script>', $result );
	}

	/**
	 * @testdox Should write float prices with a dot decimal separator in the placeholder and the sale and range annotations.
	 */
	public function test_writes_float_prices_with_a_dot(): void {
		$sut = $this->create_controller();

		$placeholder = $sut->handle_wc_price( '<span>$12.50</span>', 12.5, array(), 12.5, 12.5 );
		$sale        = $sut->handle_woocommerce_format_sale_price(
			'<span class="screen-reader-text">Original price was: $12.50.</span> <span class="screen-reader-text">Current price is: $9.50.</span>',
			12.5,
			9.5
		);
		$range       = $sut->handle_woocommerce_format_price_range( '<span class="screen-reader-text">Price range: $10.50 through $30.25</span>', 10.5, 30.25 );

		$this->assertStringContainsString( 'data-wcpay-price="12.5"', $placeholder );
		$this->assertStringContainsString( 'data-wcpay-sr-price="12.5"', $sale );
		$this->assertStringContainsString( 'data-wcpay-sr-price="9.5"', $sale );
		$this->assertStringContainsString( 'data-wcpay-sr-price-from="10.5"', $range );
		$this->assertStringContainsString( 'data-wcpay-sr-price-to="30.25"', $range );
	}

	/**
	 * @testdox Should leave sale and range markup unchanged for a non-numeric price, and never touch the placeholder span.
	 */
	public function test_leaves_non_numeric_prices_and_the_placeholder_alone(): void {
		$sut        = $this->create_controller();
		$sale_html  = '<span class="screen-reader-text wcpay-price-placeholder">$50.00</span>'
			. ' <span class="screen-reader-text">Original price was: $50.00.</span>'
			. ' <span class="screen-reader-text">Current price is: $35.00.</span>';
		$range_html = '<span class="screen-reader-text">Price range: $10.00 through $30.00</span>';

		$this->assertStringContainsString( '<span class="screen-reader-text wcpay-price-placeholder">', $sut->handle_woocommerce_format_sale_price( $sale_html, '50', '35' ) );
		$this->assertSame( $sale_html, $sut->handle_woocommerce_format_sale_price( $sale_html, 'Free', '35' ) );
		$this->assertSame( $range_html, $sut->handle_woocommerce_format_price_range( $range_html, 'Free', '30' ) );
	}

	/**
	 * @testdox Should annotate sale and range screen reader text.
	 */
	public function test_annotates_sale_and_range_screen_reader_text(): void {
		$sut        = $this->create_controller();
		$sale_html  = '<span class="screen-reader-text">Original price was: $50.00.</span>'
			. '<span class="screen-reader-text">Current price is: $35.00.</span>';
		$range_html = '<span class="screen-reader-text">Price range: $10.00 through $30.00</span>';

		$sale_result  = $sut->handle_woocommerce_format_sale_price( $sale_html, '50', '35' );
		$range_result = $sut->handle_woocommerce_format_price_range( $range_html, '10', '30' );

		$this->assertStringContainsString( 'data-wcpay-sr-type="sale_original"', $sale_result );
		$this->assertStringContainsString( 'data-wcpay-sr-price="50"', $sale_result );
		$this->assertStringContainsString( 'data-wcpay-sr-type="sale_current"', $sale_result );
		$this->assertStringContainsString( 'data-wcpay-sr-price="35"', $sale_result );
		$this->assertStringContainsString( 'data-wcpay-sr-type="range"', $range_result );
		$this->assertStringContainsString( 'data-wcpay-sr-price-from="10"', $range_result );
		$this->assertStringContainsString( 'data-wcpay-sr-price-to="30"', $range_result );
	}

	/**
	 * @testdox Should enqueue async renderer assets and localized config.
	 */
	public function test_enqueues_async_renderer_assets_and_localized_config(): void {
		$format = array(
			'woocommerce_currency'           => 'EUR',
			'woocommerce_price_num_decimals' => '3',
			'woocommerce_price_decimal_sep'  => ',',
			'woocommerce_price_thousand_sep' => '.',
			'woocommerce_currency_pos'       => 'right_space',
		);
		foreach ( $format as $option => $value ) {
			$this->store_format_options[ $option ] = get_option( $option );
			update_option( $option, $value );
		}
		$sut = $this->create_controller();
		add_filter(
			'plugins_url',
			static function ( string $url, string $path ): string {
				unset( $url );

				return 'https://example.test/wp-content/plugins/woocommerce/' . $path;
			},
			10,
			2
		);
		Constants::set_constant( 'WC_VERSION', '1.2.3' );

		$sut->handle_wp_enqueue_scripts();

		$script        = wp_scripts()->registered[ self::SCRIPT_HANDLE ] ?? null;
		$legacy_script = wp_scripts()->registered[ self::LEGACY_SCRIPT_HANDLE ] ?? null;
		$style         = wp_styles()->registered[ self::SCRIPT_HANDLE ] ?? null;
		$legacy_style  = wp_styles()->registered[ self::LEGACY_SCRIPT_HANDLE ] ?? null;
		$data          = wp_scripts()->get_data( self::SCRIPT_HANDLE, 'data' );

		$this->assertNotNull( $script );
		$this->assertSame( 'https://example.test/wp-content/plugins/woocommerce/assets/js/frontend/multi-currency-async-renderer.min.js', $script->src );
		$this->assertTrue( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertIsString( $data );
		$this->assertStringStartsWith( 'var wcpayAsyncPriceConfig = ', $data );
		$this->assertSame(
			array(
				'apiUrl'          => rest_url( 'wc/v3/payments/multi-currency/public/config' ),
				'defaultCurrency' => array(
					'symbol'       => '€',
					'decimals'     => 3,
					'decimal_sep'  => ',',
					'thousand_sep' => '.',
					'symbol_pos'   => 'right_space',
				),
				'srText'          => array(
					'sale_original' => 'Original price was: %s.',
					'sale_current'  => 'Current price is: %s.',
					'range'         => 'Price range: %1$s through %2$s',
				),
			),
			json_decode( substr( $data, strlen( 'var wcpayAsyncPriceConfig = ' ), -1 ), true )
		);
		$this->assertInstanceOf( \_WP_Dependency::class, $legacy_script );
		$this->assertFalse( $legacy_script->src );
		$this->assertSame( array( self::SCRIPT_HANDLE ), $legacy_script->deps );
		$this->assertFalse( wp_script_is( self::LEGACY_SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertNotNull( $style );
		$this->assertSame( 'https://example.test/wp-content/plugins/woocommerce/assets/css/multi-currency-async-renderer.css', $style->src );
		$this->assertSame( '1.2.3', $style->ver );
		$this->assertTrue( wp_style_is( self::SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertInstanceOf( \_WP_Dependency::class, $legacy_style );
		$this->assertFalse( $legacy_style->src );
		$this->assertSame( array( self::SCRIPT_HANDLE ), $legacy_style->deps );
		$this->assertFalse( wp_style_is( self::LEGACY_SCRIPT_HANDLE, 'enqueued' ) );
	}

	/**
	 * Data provider for registration blockers.
	 *
	 * Client 11.1.0 counts any `?currency=` argument, empty included, as a pending switch
	 * (`includes/multi-currency/MultiCurrency.php:357-360`).
	 *
	 * @return array<string,array{bool,bool,bool,bool,bool,bool,string|null}>
	 */
	public function registration_blocker_data(): array {
		return array(
			'inactive cache mode'           => array( false, true, false, false, false, true, null ),
			'non-frontend request'          => array( true, false, false, false, false, true, null ),
			'Store API request'             => array( true, true, true, false, false, true, null ),
			'admin API request'             => array( true, true, false, true, false, true, null ),
			'active session'                => array( true, true, false, false, true, true, null ),
			'auto switching disabled'       => array( true, true, false, false, false, false, null ),
			'pending currency switch'       => array( true, true, false, false, false, true, 'EUR' ),
			'empty currency query argument' => array( true, true, false, false, false, true, '' ),
		);
	}

	/**
	 * Assert controller hooks are not registered.
	 *
	 * @param MultiCurrencyAsyncPriceRendererController $sut System under test.
	 */
	private function assert_hooks_not_registered( MultiCurrencyAsyncPriceRendererController $sut ): void {
		$this->assertFalse( has_filter( 'wc_price', array( $sut, 'handle_wc_price' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_format_sale_price', array( $sut, 'handle_woocommerce_format_sale_price' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_format_price_range', array( $sut, 'handle_woocommerce_format_price_range' ) ) );
		$this->assertFalse( has_action( 'wp_enqueue_scripts', array( $sut, 'handle_wp_enqueue_scripts' ) ) );
	}

	/**
	 * Create an async price renderer controller.
	 *
	 * @param string $owner                Runtime owner.
	 * @param bool   $cache_optimized_mode Whether cache-optimized mode is active.
	 * @param bool   $frontend_request     Whether this is a frontend request.
	 * @param bool   $store_api_request    Whether this is a Store API request.
	 * @param bool   $admin_api_request    Whether this is an admin API request.
	 * @param bool   $active_session       Whether a WooCommerce session is active.
	 * @return MultiCurrencyAsyncPriceRendererController
	 */
	private function create_controller(
		string $owner = MultiCurrencyRuntimeArbiter::OWNER_BUILTIN,
		bool $cache_optimized_mode = true,
		bool $frontend_request = true,
		bool $store_api_request = false,
		bool $admin_api_request = false,
		bool $active_session = false
	): MultiCurrencyAsyncPriceRendererController {
		$controller = new MultiCurrencyAsyncPriceRendererController();
		$controller->init(
			$this->create_arbiter( $owner ),
			ServiceFactories::with( $this, array( 'create_display_service' => $this->create_display_service( $cache_optimized_mode ) ) ),
			$this->create_request_context( $frontend_request, $store_api_request, $admin_api_request )
		);
		$this->use_session(
			static function () use ( $active_session ): bool {
				return $active_session;
			}
		);

		return $controller;
	}

	/**
	 * Replace the WooCommerce session with one that answers has_session() through a callback.
	 *
	 * @param callable $has_session Callback telling whether a session cookie exists.
	 */
	private function use_session( callable $has_session ): void {
		WC()->session = new class( $has_session ) extends \WC_Mock_Session_Handler {
			/**
			 * Callback telling whether a session cookie exists.
			 *
			 * @var callable
			 */
			private $has_session;

			/**
			 * Constructor.
			 *
			 * @param callable $has_session Callback telling whether a session cookie exists.
			 */
			public function __construct( callable $has_session ) {
				$this->has_session = $has_session;
			}

			/**
			 * Tell whether a session cookie exists.
			 *
			 * @return bool
			 */
			public function has_session(): bool {
				return (bool) call_user_func( $this->has_session );
			}
		};
	}

	/**
	 * Create a static runtime arbiter.
	 *
	 * @param string $owner Runtime owner.
	 * @return MultiCurrencyRuntimeArbiter
	 */
	private function create_arbiter( string $owner ): MultiCurrencyRuntimeArbiter {
		return new class( $owner ) extends MultiCurrencyRuntimeArbiter {
			/**
			 * Runtime owner.
			 *
			 * @var string
			 */
			private string $owner;

			/**
			 * Constructor.
			 *
			 * @param string $owner Runtime owner.
			 */
			public function __construct( string $owner ) {
				$this->owner = $owner;
			}

			/**
			 * Tell whether core should register.
			 *
			 * @return bool
			 */
			public function is_builtin_owner(): bool {
				return MultiCurrencyRuntimeArbiter::OWNER_BUILTIN === $this->owner;
			}
		};
	}

	/**
	 * Create a frontend projection service test double.
	 *
	 * @param bool $cache_optimized_mode Whether cache-optimized mode is active.
	 * @return MultiCurrencyDisplayService
	 */
	private function create_display_service( bool $cache_optimized_mode ): MultiCurrencyDisplayService {
		return new class( $cache_optimized_mode ) extends MultiCurrencyDisplayService {
			/**
			 * Whether cache-optimized mode is active.
			 *
			 * @var bool
			 */
			private bool $cache_optimized_mode;

			/**
			 * Constructor.
			 *
			 * @param bool $cache_optimized_mode Whether cache-optimized mode is active.
			 */
			public function __construct( bool $cache_optimized_mode ) {
				$this->cache_optimized_mode = $cache_optimized_mode;
			}

			/**
			 * Tell whether cache-optimized rendering is active.
			 *
			 * @return bool
			 */
			public function is_cache_optimized_mode(): bool {
				return $this->cache_optimized_mode;
			}
		};
	}

	/**
	 * Create a request context test double.
	 *
	 * @param bool $frontend_request  Whether this is a frontend request.
	 * @param bool $store_api_request Whether this is a Store API request.
	 * @param bool $admin_api_request Whether this is an admin API request.
	 * @return MultiCurrencyRequestContext
	 */
	private function create_request_context( bool $frontend_request, bool $store_api_request, bool $admin_api_request ): MultiCurrencyRequestContext {
		return new class( $frontend_request, $store_api_request, $admin_api_request ) extends MultiCurrencyRequestContext {
			/**
			 * Whether this is a frontend request.
			 *
			 * @var bool
			 */
			private bool $frontend_request;

			/**
			 * Whether this is a Store API request.
			 *
			 * @var bool
			 */
			private bool $store_api_request;

			/**
			 * Whether this is an admin API request.
			 *
			 * @var bool
			 */
			private bool $admin_api_request;

			/**
			 * Constructor.
			 *
			 * @param bool $frontend_request  Whether this is a frontend request.
			 * @param bool $store_api_request Whether this is a Store API request.
			 * @param bool $admin_api_request Whether this is an admin API request.
			 */
			public function __construct( bool $frontend_request, bool $store_api_request, bool $admin_api_request ) {
				$this->frontend_request  = $frontend_request;
				$this->store_api_request = $store_api_request;
				$this->admin_api_request = $admin_api_request;
			}

			/**
			 * Tell whether frontend hooks should register.
			 *
			 * @return bool
			 */
			public function should_register_frontend_hooks(): bool {
				return $this->frontend_request;
			}

			/**
			 * Tell whether this is a Store API request.
			 *
			 * @return bool
			 */
			public function is_store_api_request(): bool {
				return $this->store_api_request;
			}

			/**
			 * Tell whether this is an admin API request.
			 *
			 * @return bool
			 */
			public function is_admin_api_request(): bool {
				return $this->admin_api_request;
			}
		};
	}
}
