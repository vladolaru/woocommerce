<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyAsyncPriceRendererController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFrontendCurrenciesController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFrontendPricesController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRestRequestOverrideController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySelectedCurrencyController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyStoreApiRouteController;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\RequestContext;
use WC_Session;
use WC_Unit_Test_Case;
use WP_REST_Request;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound,Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName

/**
 * In-memory guest session holding a selected currency, without a cookie.
 */
class MultiCurrencyStoreApiRouteTestSession extends WC_Session {

	/**
	 * Hold the given selected currency.
	 *
	 * @param string|null $currency_code Selected currency code, or null for none.
	 */
	public function __construct( ?string $currency_code ) {
		if ( null !== $currency_code ) {
			$this->set( 'wcpay_currency', $currency_code );
		}
	}
}

/**
 * Tests for the MultiCurrencyStoreApiRouteController class, on a USD store that enables GBP at 0.80 and EUR at 0.90, for a guest
 * whose session holds GBP. Each case registers the module's request hooks as WooCommerce would for its request URL and
 * referer, then lets WordPress match routes.
 */
class MultiCurrencyStoreApiRouteControllerTest extends WC_Unit_Test_Case {

	private const SHOP_REFERER  = 'shop';
	private const ADMIN_REFERER = 'admin';

	/**
	 * Original WooCommerce session.
	 *
	 * @var mixed
	 */
	private $original_session;

	/**
	 * Original server data.
	 *
	 * @var array<string,mixed>
	 */
	private array $original_server;

	/**
	 * Original posted data.
	 *
	 * @var array<string,mixed>
	 */
	private array $original_post;

	/**
	 * Set up the store, the guest's session and fresh module services.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_session = WC()->session;
		$this->original_server  = $_SERVER;
		$this->original_post    = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Restored in tear-down.
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP', 'EUR' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_gbp', '0.80' );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.90' );
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		wp_set_current_user( 0 );
		WC()->session = new MultiCurrencyStoreApiRouteTestSession( 'GBP' );
		wc_get_container()->reset_all_resolved();
	}

	/**
	 * Restore the request, the session, the REST server and the module services.
	 */
	public function tearDown(): void {
		global $wp_rest_server;

		try {
			$_SERVER        = $this->original_server;
			$_POST          = $this->original_post;
			WC()->session   = $this->original_session;
			$wp_rest_server = null;
			wc_get_container()->reset_all_resolved();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Should run a Store API route its URL did not name in the shopper's currency once matched: $_dataName.
	 * @dataProvider provide_store_api_routes_reached_through_another_url
	 *
	 * Client 11.3.0 `tests/unit/multi-currency/test-class-multi-currency.php:1864-1911`: a merchant route leaves the
	 * request in the store currency; a Store API route turns on the price hooks at 99 and the created-customer copy at 10.
	 * The cart-load check of saved totals is armed too (`MultiCurrency.php:459`), and the async price markup is removed.
	 *
	 * @param string $request_uri    Request URL path.
	 * @param string $route          Route WordPress matched.
	 * @param string $referer        Referring page: 'admin' or 'shop'.
	 * @param string $rendering_mode Multi-Currency rendering mode.
	 */
	public function test_runs_a_matched_store_api_route_in_the_selected_currency( string $request_uri, string $route, string $referer, string $rendering_mode ): void {
		update_option( 'wcpay_multi_currency_rendering_mode', $rendering_mode );
		update_option( 'wcpay_multi_currency_enable_auto_currency', 'yes' );
		$this->start_request( $request_uri, $referer );

		$this->match_rest_route( '/wc/v3/products' );
		$this->assertSame( 'USD', get_woocommerce_currency(), 'A merchant route stays in the store currency.' );

		$this->assertNull( $this->match_rest_route( $route ) );

		$container = wc_get_container();
		$selected  = $container->get( MultiCurrencySelectedCurrencyController::class );
		$this->assertSame( 'GBP', get_woocommerce_currency() );
		/**
		 * Ask whether product prices convert, as the module's price filter does.
		 *
		 * @since 11.0.0
		 */
		$this->assertTrue( apply_filters( MultiCurrencyFrontendPricesController::FILTER_SHOULD_CONVERT_PRODUCT_PRICE, true, new \stdClass() ) );
		$this->assertSame( 99, has_filter( 'woocommerce_product_get_price', array( $container->get( MultiCurrencyFrontendPricesController::class ), 'get_product_price_string' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_created_customer', array( $selected, 'handle_woocommerce_created_customer' ) ) );
		$this->assertSame( PHP_INT_MAX, has_action( 'woocommerce_cart_loaded_from_session', array( $selected, 'handle_woocommerce_cart_loaded_from_session' ) ) );
		$this->assertFalse( has_filter( 'wc_price', array( $container->get( MultiCurrencyAsyncPriceRendererController::class ), 'handle_wc_price' ) ) );
	}

	/**
	 * Requests whose URL does not name the Store API route WordPress matches, with a shop or an admin referer.
	 *
	 * @return array<string,array{string,string,string,string}>
	 */
	public function provide_store_api_routes_reached_through_another_url(): array {
		return array(
			'a mixed-case route'                 => array( '/wp-json/wc/Store/v1/cart/update-item', '/wc/Store/v1/cart/update-item', self::SHOP_REFERER, 'speed' ),
			'a route the URL does not name'      => array( '/wp-json/', '/wc/store/v1/cart/update-item', self::SHOP_REFERER, 'speed' ),
			'batched in batch/v1'                => array( '/wp-json/batch/v1', '/wc/store/v1/cart/update-item', self::SHOP_REFERER, 'speed' ),
			'batched in batch/v1, admin referer' => array( '/wp-json/batch/v1', '/wc/store/v1/cart/update-item', self::ADMIN_REFERER, 'speed' ),
			'batched in batch/v1, cache mode'    => array( '/wp-json/batch/v1', '/wc/store/v1/cart/update-item', self::SHOP_REFERER, 'cache' ),
		);
	}

	/**
	 * @testdox Should run a dispatched Store API route in the shopper's currency, and a merchant route before it in the store currency: $_dataName.
	 * @dataProvider provide_store_api_route_dispatches
	 *
	 * Client 11.3.0 `tests/unit/multi-currency/test-class-multi-currency.php:1916-1951`. The routes take POST, as a cart
	 * update does: WordPress's `batch/v1` accepts only POST, PUT, PATCH and DELETE sub-requests
	 * (`wp-includes/rest-api/class-wp-rest-server.php:131-134`), so a cart read cannot be batched.
	 *
	 * @param string $request_uri Request URL path.
	 * @param string $referer     Referring page: 'admin' or 'shop'.
	 * @param string $dispatch    'direct' for one mixed-case request, 'batch' for a merchant then a Store API sub-request.
	 */
	public function test_runs_a_dispatched_store_api_route_in_the_selected_currency( string $request_uri, string $referer, string $dispatch ): void {
		$this->start_request( $request_uri, $referer );
		$seen     = array();
		$recorder = static function ( string $key ) use ( &$seen ): callable {
			return static function () use ( $key, &$seen ) {
				$seen[ $key ] = get_woocommerce_currency();

				return rest_ensure_response( array( 'ok' => true ) );
			};
		};
		$this->register_routes(
			static function () use ( $recorder ): void {
				foreach ( array(
					'wc/v3'       => 'merchant',
					'wc/store/v1' => 'store',
				) as $namespace => $key ) {
					register_rest_route(
						$namespace,
						'/mc-update-stub',
						array(
							'methods'             => 'POST',
							'callback'            => $recorder( $key ),
							'permission_callback' => '__return_true',
							'allow_batch'         => array( 'v1' => true ),
						)
					);
				}
			}
		);

		if ( 'batch' === $dispatch ) {
			$request = new WP_REST_Request( 'POST', '/batch/v1' );
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body(
				(string) wp_json_encode(
					array(
						'requests' => array(
							array(
								'method' => 'POST',
								'path'   => '/wc/v3/mc-update-stub',
							),
							array(
								'method' => 'POST',
								'path'   => '/wc/store/v1/mc-update-stub',
							),
						),
					)
				)
			);
			$expected = array(
				'merchant' => 'USD',
				'store'    => 'GBP',
			);
		} else {
			$request  = new WP_REST_Request( 'POST', '/wc/Store/v1/mc-update-stub' );
			$expected = array( 'store' => 'GBP' );
		}
		rest_do_request( $request );

		$this->assertSame( $expected, $seen );
	}

	/**
	 * Outer URLs and how the Store API route is dispatched, with a shop or an admin referer.
	 *
	 * @return array<string,array{string,string,string}>
	 */
	public function provide_store_api_route_dispatches(): array {
		return array(
			'a mixed-case route'                 => array( '/wp-json/wc/Store/v1/mc-update-stub', self::SHOP_REFERER, 'direct' ),
			'a mixed-case route, admin referer'  => array( '/wp-json/wc/Store/v1/mc-update-stub', self::ADMIN_REFERER, 'direct' ),
			'batched in batch/v1'                => array( '/wp-json/batch/v1', self::SHOP_REFERER, 'batch' ),
			'batched in batch/v1, admin referer' => array( '/wp-json/batch/v1', self::ADMIN_REFERER, 'batch' ),
		);
	}

	/**
	 * @testdox Should select the geolocated currency at the match for a guest with none stored: $_dataName.
	 * @dataProvider provide_requests_selecting_by_geolocation_at_the_match
	 *
	 * Client 11.3.0 `tests/unit/multi-currency/test-class-multi-currency.php:1953-1983` (admin referer) and `:2023-2056`
	 * (cache mode): nothing selected a currency at registration, and the batched Store API route runs in the geolocated one.
	 *
	 * @param string $referer        Referring page: 'admin' or 'shop'.
	 * @param string $rendering_mode Multi-Currency rendering mode.
	 */
	public function test_selects_the_geolocated_currency_at_the_match( string $referer, string $rendering_mode ): void {
		update_option( 'wcpay_multi_currency_enable_auto_currency', 'yes' );
		update_option( 'wcpay_multi_currency_rendering_mode', $rendering_mode );
		add_filter( 'woocommerce_geolocate_ip', static fn() => 'GB' );
		WC()->session = new MultiCurrencyStoreApiRouteTestSession( null );
		$this->start_request( '/wp-json/batch/v1', $referer );
		$seen = array();
		$this->register_routes(
			static function () use ( &$seen ): void {
				register_rest_route(
					'wc/store/v1',
					'/mc-update-stub',
					array(
						'methods'             => 'POST',
						'callback'            => static function () use ( &$seen ) {
							$seen[] = get_woocommerce_currency();

							return rest_ensure_response( array( 'ok' => true ) );
						},
						'permission_callback' => '__return_true',
						'allow_batch'         => array( 'v1' => true ),
					)
				);
			}
		);
		$request = new WP_REST_Request( 'POST', '/batch/v1' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'requests' => array(
						array(
							'method' => 'POST',
							'path'   => '/wc/store/v1/mc-update-stub',
						),
					),
				)
			)
		);

		rest_do_request( $request );

		$this->assertSame( array( 'GBP' ), $seen );
		$this->assertSame( 'GBP', WC()->session->get( 'wcpay_currency' ) );
	}

	/**
	 * A batch request with an admin referer, and one from the shop in cache-optimized mode.
	 *
	 * @return array<string,array{string,string}>
	 */
	public function provide_requests_selecting_by_geolocation_at_the_match(): array {
		return array(
			'admin referer'    => array( self::ADMIN_REFERER, 'speed' ),
			'shop, cache mode' => array( self::SHOP_REFERER, 'cache' ),
		);
	}

	/**
	 * @testdox Should name the currency of the session a matched route loads with the cart: $_dataName.
	 * @dataProvider provide_routes_loading_a_cart_session
	 *
	 * Client 11.3.0 `tests/unit/multi-currency/test-class-multi-currency.php:2281-2347`: GBP before the cart loads, then the
	 * loaded session's own currency. A Cart-Token cart route loads a session object of its own, so the case swaps the session
	 * in, as `AbstractCartRoute::load_cart_session()` and `wc_load_cart()` do.
	 *
	 * @param string $route Route requested: the Store API one, or a merchant route that dispatches it.
	 */
	public function test_names_the_currency_of_the_session_loaded_with_the_cart( string $route ): void {
		$this->start_request( '/wp-json/', self::SHOP_REFERER );
		$seen = array();
		$this->register_routes(
			static function () use ( &$seen ): void {
				register_rest_route(
					'wc/store/v1',
					'/mc-session-stub',
					array(
						'methods'             => 'GET',
						'callback'            => static function () use ( &$seen ) {
							$seen[]       = get_woocommerce_currency();
							WC()->session = new MultiCurrencyStoreApiRouteTestSession( 'EUR' );
							// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Firing an existing WooCommerce action.
							do_action( 'woocommerce_cart_loaded_from_session', WC()->cart );
							$seen[] = get_woocommerce_currency();

							return rest_ensure_response( array( 'ok' => true ) );
						},
						'permission_callback' => '__return_true',
					)
				);
				register_rest_route(
					'mc-test/v1',
					'/session',
					array(
						'methods'             => 'GET',
						'callback'            => static fn() => rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/mc-session-stub' ) ),
						'permission_callback' => '__return_true',
					)
				);
			}
		);

		rest_do_request( new WP_REST_Request( 'GET', $route ) );

		$this->assertSame( array( 'GBP', 'EUR' ), $seen );
	}

	/**
	 * Routes that load a cart session: the Store API route itself, and a merchant route dispatching it.
	 *
	 * @return array<string,array{string}>
	 */
	public function provide_routes_loading_a_cart_session(): array {
		return array(
			'matched directly'               => array( '/wc/store/v1/mc-session-stub' ),
			'nested under a non-Store route' => array( '/mc-test/v1/session' ),
		);
	}

	/**
	 * @testdox Should treat a Store API route named in the posted rest_route field of a page URL as Store API, and an internal dispatch on a page as not: $_dataName.
	 * @dataProvider provide_store_api_routes_dispatched_on_a_page_url
	 *
	 * Client 11.3.0 `tests/unit/multi-currency/test-class-multi-currency.php:2429-2499`: WordPress serves a `rest_route` posted to
	 * any URL, which core does not treat as a REST request.
	 *
	 * @param string $route_namespace REST namespace.
	 * @param string $path            Resource path in that namespace.
	 * @param bool   $posted          Whether the route is posted to the page.
	 */
	public function test_classifies_a_store_api_route_dispatched_on_a_page_url( string $route_namespace, string $path, bool $posted ): void {
		$route = '/' . $route_namespace . $path;
		if ( $posted ) {
			$_POST['rest_route'] = $route;
		}
		$this->start_request( '/', self::SHOP_REFERER );
		$request_context = wc_get_container()->get( RequestContext::class );
		$seen            = array();
		$this->register_routes(
			static function () use ( &$seen, $request_context, $route_namespace, $path ): void {
				register_rest_route(
					$route_namespace,
					$path,
					array(
						'methods'             => 'POST',
						'callback'            => static function () use ( &$seen, $request_context ) {
							$seen[]       = $request_context->is_store_api_request();
							WC()->session = new MultiCurrencyStoreApiRouteTestSession( 'EUR' );
							// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Firing an existing WooCommerce action.
							do_action( 'woocommerce_cart_loaded_from_session', WC()->cart );
							$seen[] = get_woocommerce_currency();

							return rest_ensure_response( array( 'ok' => true ) );
						},
						'permission_callback' => '__return_true',
					)
				);
			}
		);

		rest_do_request( new WP_REST_Request( 'POST', $route ) );

		$this->assertSame( $posted, $seen[0], 'The route runs as a Store API request only when the request posted it.' );
		// The internal dispatch is a control for the classification only: its page also follows the loaded session, so a
		// currency assertion there could not fail.
		if ( $posted ) {
			$this->assertSame( 'EUR', $seen[1], "The page's currency follows the session the route loaded." );
		}
		$this->assertFalse( $request_context->is_store_api_request(), 'The request is not left a Store API request.' );
	}

	/**
	 * A Store API route posted as `rest_route` on a page URL, and one dispatched internally on a page.
	 *
	 * @return array<string,array{string,string,bool}>
	 */
	public function provide_store_api_routes_dispatched_on_a_page_url(): array {
		return array(
			'rest_route in the request'          => array( 'wc/store/v1', '/mc-page-stub', true ),
			'posted agentic checkout route'      => array( 'wc/ucp/v1', '/carts', true ),
			'internal dispatch on a normal page' => array( 'wc/store/v1', '/mc-page-stub', false ),
		);
	}

	/**
	 * @testdox Should select the geolocated currency on the session loaded with the cart, only while the matched route runs.
	 *
	 * Client 11.3.0 `tests/unit/multi-currency/test-class-multi-currency.php:2189-2273`: the selection armed at the match runs
	 * when the cart loads, and is detached once the route's callbacks have run.
	 */
	public function test_selects_the_geolocated_currency_when_the_cart_loads_during_a_matched_route(): void {
		update_option( 'wcpay_multi_currency_enable_auto_currency', 'yes' );
		add_filter( 'woocommerce_geolocate_ip', static fn() => 'GB' );
		WC()->session = new MultiCurrencyStoreApiRouteTestSession( null );
		$this->start_request( '/wp-json/', self::SHOP_REFERER );
		$selected = wc_get_container()->get( MultiCurrencySelectedCurrencyController::class );
		$seen     = array();
		$this->register_routes(
			static function () use ( &$seen ): void {
				register_rest_route(
					'wc/store/v1',
					'/mc-geolocation-stub',
					array(
						'methods'             => 'GET',
						'callback'            => static function () use ( &$seen ) {
							// The match already selected GBP on the session it found; the cart's session has none.
							WC()->session = new MultiCurrencyStoreApiRouteTestSession( null );
							// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Firing an existing WooCommerce action.
							do_action( 'woocommerce_cart_loaded_from_session', WC()->cart );
							$seen[] = WC()->session->get( 'wcpay_currency' );

							return rest_ensure_response( array( 'ok' => true ) );
						},
						'permission_callback' => '__return_true',
					)
				);
			}
		);

		rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/mc-geolocation-stub' ) );

		$this->assertSame( array( 'GBP' ), $seen );
		$this->assertFalse( has_action( 'woocommerce_cart_loaded_from_session', array( $selected, 'handle_geolocation_init' ) ) );
	}

	/**
	 * @testdox Should clear the match when the matched request's callbacks have run, and not when a request nested in it ends.
	 *
	 * Client 11.3.0 `tests/unit/multi-currency/test-class-multi-currency.php:2061-2187`.
	 */
	public function test_the_match_ends_with_the_request_that_made_it(): void {
		$this->start_request( '/wp-json/', self::SHOP_REFERER );
		$request_context = wc_get_container()->get( RequestContext::class );
		$seen            = array();
		$this->register_routes(
			static function () use ( &$seen, $request_context ): void {
				register_rest_route(
					'wc/store/v1',
					'/mc-nested-inner',
					array(
						'methods'             => 'GET',
						'callback'            => static fn() => rest_ensure_response( array( 'ok' => true ) ),
						'permission_callback' => '__return_true',
					)
				);
				register_rest_route(
					'wc/store/v1',
					'/mc-nested-outer',
					array(
						'methods'             => 'POST',
						'callback'            => static function () use ( &$seen, $request_context ) {
							$seen['before the nested request'] = $request_context->is_store_api_request();
							rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/mc-nested-inner' ) );
							$seen['after the nested request'] = $request_context->is_store_api_request();

							return rest_ensure_response( array( 'ok' => true ) );
						},
						'permission_callback' => '__return_true',
					)
				);
			}
		);

		rest_do_request( new WP_REST_Request( 'POST', '/wc/store/v1/mc-nested-outer' ) );
		$seen['after the matched request'] = $request_context->is_store_api_request();

		$this->assertSame(
			array(
				'before the nested request' => true,
				'after the nested request'  => true,
				'after the matched request' => false,
			),
			$seen
		);
	}

	/**
	 * Register the module's hooks for a request with this URL and referer, as WooCommerce does when it loads.
	 *
	 * @param string $request_uri Request URL path.
	 * @param string $referer     Referring page: 'admin' for the admin area, 'shop' for the shop page.
	 */
	private function start_request( string $request_uri, string $referer ): void {
		$_SERVER['REQUEST_URI']  = $request_uri;
		$_SERVER['HTTP_REFERER'] = self::ADMIN_REFERER === $referer ? admin_url() : home_url( '/shop/' );
		$container               = wc_get_container();
		foreach ( array(
			MultiCurrencyFrontendCurrenciesController::class,
			MultiCurrencyFrontendPricesController::class,
			MultiCurrencySelectedCurrencyController::class,
			MultiCurrencyRestRequestOverrideController::class,
			MultiCurrencyStoreApiRouteController::class,
		) as $class_name ) {
			$container->get( $class_name )->register();
		}
		$container->get( MultiCurrencyAsyncPriceRendererController::class )->maybe_register_async_hooks();
	}

	/**
	 * Start a fresh REST server that registers the given routes.
	 *
	 * @param callable $register_routes Callback registering the routes.
	 */
	private function register_routes( callable $register_routes ): void {
		global $wp_rest_server;

		add_action( 'rest_api_init', $register_routes );
		$wp_rest_server = null;
		rest_get_server();
	}

	/**
	 * Let the module see WordPress match a route, as WordPress does before the route's callbacks run.
	 *
	 * @param string $route Matched route.
	 * @return mixed The result the filter gives.
	 */
	private function match_rest_route( string $route ) {
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Firing an existing WordPress filter.
		return apply_filters( 'rest_request_before_callbacks', null, array(), new WP_REST_Request( 'POST', $route ) );
	}
}
