<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFrontendCurrenciesController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFrontendPricesController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySelectedCurrencyController;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\CartTotalsCurrencyService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\ServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilderFactory;
use WC_Cart;
use WC_Helper_Product;
use WC_Session;
use WC_Unit_Test_Case;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound,Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName

/**
 * In-memory session that lists its data, as WooCommerce's own session handler does.
 */
class CartTotalsCurrencyTestSession extends WC_Session {

	/**
	 * Get the session data.
	 *
	 * @return array<string,mixed>
	 */
	public function get_session_data(): array {
		return $this->_data;
	}
}

/**
 * Tests for the CartTotalsCurrencyService class, with the module's storefront hooks registered on a USD store that enables
 * GBP at 0.708099, rounded up to 0.50 and charmed by -0.1, as the WooPayments extension's tests set it up
 * (client 11.3.0 `tests/unit/multi-currency/test-class-multi-currency.php:58-66`, `:135-141`).
 */
class CartTotalsCurrencyServiceTest extends WC_Unit_Test_Case {

	private const CART_PRODUCT_PRICE        = 100;
	private const STORE_CURRENCY_CART_TOTAL = '100.00';
	private const CACHED_SHIPPING_RATES_KEY = 'shipping_for_package_0';
	private const CACHED_SHIPPING_RATES     = array( 'package_hash' => 'wc_ship_cached' );
	private const REBUILT_SHIPPING_RATES    = array( 'package_hash' => 'wc_ship_rebuilt' );
	private const STORE_API_CART_ROUTE      = '/wc/store/v1/cart';
	private const STOREFRONT_REQUEST_URI    = '/shop/';
	private const LATER_CALLBACK_PRIORITY   = 5000;
	private const OTHER_CART_SAVE_PRIORITY  = 1500;
	private const CURRENCY_SESSION_KEY      = 'wcpay_cart_totals_currency';
	private const HASH_SESSION_KEY          = 'wcpay_cart_totals_hash';
	private const SELECTED_CURRENCY_KEY     = 'wcpay_currency';

	/**
	 * The System Under Test.
	 *
	 * @var CartTotalsCurrencyService
	 */
	private $sut;

	/**
	 * Original WooCommerce session.
	 *
	 * @var mixed
	 */
	private $original_session;

	/**
	 * Original request URI, or null when unset.
	 *
	 * @var string|null
	 */
	private ?string $original_request_uri;

	/**
	 * Set up a store whose storefront runs Multi-Currency, with a guest session and an empty cart.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'USD', 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_gbp', '0.708099' );
		update_option( 'wcpay_multi_currency_price_rounding_gbp', '0.50' );
		update_option( 'wcpay_multi_currency_price_charm_gbp', '-0.1' );
		wp_set_current_user( 0 );
		$this->original_session     = WC()->session;
		$this->original_request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$_SERVER['REQUEST_URI']     = self::STOREFRONT_REQUEST_URI;
		WC()->session               = new CartTotalsCurrencyTestSession();
		WC()->cart->empty_cart();

		$container = wc_get_container();
		$container->reset_all_resolved();
		$container->replace( MultiCurrencyRuntimeArbiter::class, $this->create_builtin_arbiter() );
		foreach ( array( MultiCurrencyFrontendCurrenciesController::class, MultiCurrencyFrontendPricesController::class, MultiCurrencySelectedCurrencyController::class ) as $class_name ) {
			$container->get( $class_name )->register();
		}
		$this->sut = $container->get( CartTotalsCurrencyService::class );
	}

	/**
	 * Restore the session, the request and the container.
	 */
	public function tearDown(): void {
		try {
			WC()->cart->empty_cart();
			WC()->session = $this->original_session;
			unset( $_REQUEST['rest_route'], $_GET['currency'] );
			if ( null === $this->original_request_uri ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $this->original_request_uri;
			}
			wc_get_container()->reset_all_replacements();
			wc_get_container()->reset_all_resolved();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Should recalculate totals left in the store currency by a switch made elsewhere, on the next REST load: $_dataName.
	 *
	 * Client 11.3.0 `tests/unit/multi-currency/test-class-multi-currency.php:915-952`: 100 USD at 0.708099, rounded up to 0.50
	 * and charmed by -0.1, is GBP 70.90.
	 *
	 * @dataProvider provide_rest_request_kinds
	 *
	 * @param string $kind How the request shows it is a REST request.
	 */
	public function test_recalculates_stale_totals_in_the_selected_currency_on_the_next_rest_load( string $kind ): void {
		$this->add_product_to_cart();
		$this->switch_on_an_earlier_request( 'GBP' );

		$this->as_request( $kind, fn() => $this->reload_cart_from_session() );

		$this->assertSame(
			array( 'GBP', 70.90, 70.90, 'GBP' ),
			array(
				get_woocommerce_currency(),
				round( (float) WC()->cart->get_totals()['total'], 2 ),
				round( (float) WC()->session->get( 'cart_totals' )['total'], 2 ),
				WC()->session->get( self::CURRENCY_SESSION_KEY ),
			)
		);
	}

	/**
	 * How a request shows it is a REST request.
	 *
	 * @return array<string,array<int,string>>
	 */
	public function provide_rest_request_kinds(): array {
		return array(
			'REST API URL'         => array( 'rest_filter' ),
			'rest_route parameter' => array( 'rest_route' ),
		);
	}

	/**
	 * @testdox A page view recalculates stale totals only when it selected the currency before the cart loaded: $_dataName.
	 *
	 * Storefront pages show the saved totals and cart and checkout recalculate them, so a page view after a switch made
	 * elsewhere leaves them alone: a third-party calculation that throws would otherwise fail every page view. The page view
	 * that switched recalculates (client 11.3.0 `tests/unit/multi-currency/test-class-multi-currency.php:961-1023`).
	 *
	 * @dataProvider provide_page_views
	 *
	 * @param bool             $switched_here Whether this page view selected GBP before the cart loaded.
	 * @param array<int,mixed> $expected      Recalculations, the cart total and the stamp after the load.
	 */
	public function test_page_view_recalculates_stale_totals_only_when_it_selected_the_currency( bool $switched_here, array $expected ): void {
		$this->add_product_to_cart();
		if ( $switched_here ) {
			$this->with_cart_not_loaded( fn() => wc_get_container()->get( ServiceFactory::class )->create_selected_currency_persistence_service()->update_selected_currency( 'GBP' ) );
		} else {
			$this->switch_on_an_earlier_request( 'GBP' );
		}

		$recalculations = $this->as_request( 'page', fn() => $this->count_recalculations_during( fn() => $this->reload_cart_from_session() ) );

		$this->assertSame( $expected, array( $recalculations, round( (float) WC()->cart->get_totals()['total'], 2 ), WC()->session->get( self::CURRENCY_SESSION_KEY ) ) );
	}

	/**
	 * Page views after a switch to GBP.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function provide_page_views(): array {
		return array(
			'switched on an earlier request' => array( false, array( 0, 100.0, 'USD' ) ),
			'switched on this page view'     => array( true, array( 1, 70.90, 'GBP' ) ),
		);
	}

	/**
	 * @testdox Should stamp only the totals the main cart saved in its calculation: $_dataName.
	 *
	 * A stamp says which currency the session's totals were calculated in, so it is written only for totals the main cart
	 * saved; otherwise it is removed, and the next load recalculates (client 11.3.0
	 * `tests/unit/multi-currency/test-class-multi-currency.php:1105-1236`).
	 *
	 * @dataProvider provide_saves
	 *
	 * @param string      $save  What happens to the session's totals in the calculation after a switch to GBP.
	 * @param string|null $stamp The stamp after it.
	 */
	public function test_stamps_only_the_totals_the_main_cart_saved( string $save, ?string $stamp ): void {
		$this->add_product_to_cart();
		$cart_session = $this->get_cart_session();
		if ( in_array( $save, array( 'not saved', 'another cart saved, the main save skipped' ), true ) ) {
			remove_action( 'woocommerce_after_calculate_totals', array( $cart_session, 'set_session' ), 1000 );
		}
		if ( in_array( $save, array( 'another cart saved after the main save', 'another cart saved, the main save skipped' ), true ) ) {
			add_action( 'woocommerce_after_calculate_totals', array( $this, 'save_another_cart_with_store_currency_totals' ), self::OTHER_CART_SAVE_PRIORITY );
		}
		if ( 'changed in memory after the save' === $save ) {
			add_action( 'woocommerce_after_calculate_totals', array( $this, 'lower_the_total_by_one' ), self::LATER_CALLBACK_PRIORITY );
		}
		$this->switch_on_an_earlier_request( 'GBP' );

		WC()->cart->calculate_totals();

		$this->assertSame( $stamp, WC()->session->get( self::CURRENCY_SESSION_KEY ) );
	}

	/**
	 * What happens to the session's totals in a calculation.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function provide_saves(): array {
		return array(
			'saved by the main cart'                    => array( 'saved by the main cart', 'GBP' ),
			'changed in memory after the save'          => array( 'changed in memory after the save', 'GBP' ),
			'not saved'                                 => array( 'not saved', null ),
			'another cart saved after the main save'    => array( 'another cart saved after the main save', null ),
			'another cart saved, the main save skipped' => array( 'another cart saved, the main save skipped', null ),
		);
	}

	/**
	 * @testdox Should stamp saved totals with the hash and currency the WooPayments extension writes for them.
	 *
	 * Client 11.3.0 `includes/multi-currency/MultiCurrency.php:1338-1339`, `:1397-1399`: the session keys
	 * `wcpay_cart_totals_currency` and `wcpay_cart_totals_hash`, the hash being `md5( (string) wp_json_encode( $totals ) )`.
	 * The expected hash is the md5 of this array's JSON encoding, worked out outside this module:
	 * `{"subtotal":"90.00","subtotal_tax":0,"shipping_total":"10.00","shipping_tax":1.5,"shipping_taxes":{"1":1.5},...}`.
	 */
	public function test_stamps_saved_totals_with_the_extension_hash_and_currency(): void {
		$totals = array(
			'subtotal'            => '90.00',
			'subtotal_tax'        => 0,
			'shipping_total'      => '10.00',
			'shipping_tax'        => 1.5,
			'shipping_taxes'      => array( 1 => 1.5 ),
			'discount_total'      => 0,
			'discount_tax'        => 0,
			'cart_contents_total' => '90.00',
			'cart_contents_tax'   => 0,
			'cart_contents_taxes' => array(),
			'fee_total'           => '0',
			'fee_tax'             => 0,
			'fee_taxes'           => array(),
			'total'               => '101.50',
			'total_tax'           => 1.5,
		);
		$this->switch_on_an_earlier_request( 'GBP' );
		WC()->cart->set_totals( $totals );
		WC()->session->set( 'cart_totals', $totals );

		$this->sut->record_cart_totals_currency( WC()->cart );

		$this->assertSame(
			array(
				self::CURRENCY_SESSION_KEY => 'GBP',
				self::HASH_SESSION_KEY     => 'e9d19597aee439b058207946a17291db',
			),
			array(
				self::CURRENCY_SESSION_KEY => WC()->session->get( self::CURRENCY_SESSION_KEY ),
				self::HASH_SESSION_KEY     => WC()->session->get( self::HASH_SESSION_KEY ),
			)
		);
	}

	/**
	 * @testdox Should leave the stamp alone for another cart's calculation, and remove it for a save outside the main cart's calculation.
	 *
	 * Client 11.3.0 `tests/unit/multi-currency/test-class-multi-currency.php:1168-1187`, `:1253-1265`.
	 */
	public function test_ignores_other_carts_and_saves_outside_the_calculation(): void {
		$this->add_product_to_cart();
		WC()->session->set( self::CURRENCY_SESSION_KEY, 'EUR' );
		$other_cart = $this->createMock( WC_Cart::class );
		$other_cart->method( 'get_totals' )->willReturn( WC()->session->get( 'cart_totals' ) );

		$this->sut->record_cart_totals_currency( $other_cart );
		$after_other_cart = WC()->session->get( self::CURRENCY_SESSION_KEY );
		$this->sut->start_cart_totals_calculation( WC()->cart );
		do_action( 'woocommerce_cart_updated' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		WC()->session->set( 'cart_totals', array( 'total' => self::STORE_CURRENCY_CART_TOTAL ) );
		$this->sut->record_cart_totals_currency( WC()->cart );

		$this->assertSame( array( 'EUR', null ), array( $after_other_cart, WC()->session->get( self::CURRENCY_SESSION_KEY ) ) );
	}

	/**
	 * @testdox On a REST load, should recalculate only totals the stamp does not describe in the current currency: $_dataName.
	 *
	 * Client 11.3.0 `tests/unit/multi-currency/test-class-multi-currency.php:1323-1348`, `:1375-1401`, `:1540-1600`: totals
	 * saved where Multi-Currency did not run keep an older stamp and only the hash shows it; totals saved before the stamp
	 * existed, or by a runtime that does not stamp, carry none.
	 *
	 * @dataProvider provide_saved_totals
	 *
	 * @param string $saved          The saved totals, after a cart calculated in GBP.
	 * @param int    $recalculations Recalculations the load makes.
	 */
	public function test_recalculates_on_a_rest_load_only_totals_the_stamp_does_not_describe( string $saved, int $recalculations ): void {
		$this->switch_on_an_earlier_request( 'GBP' );
		$this->add_product_to_cart();
		if ( 'saved where Multi-Currency did not run' === $saved ) {
			$totals = WC()->session->get( 'cart_totals' );
			foreach ( array( 'subtotal', 'cart_contents_total', 'total' ) as $key ) {
				$totals[ $key ] = self::STORE_CURRENCY_CART_TOTAL;
			}
			WC()->session->set( 'cart_totals', $totals );
		} elseif ( 'unstamped' === $saved ) {
			WC()->session->__unset( self::CURRENCY_SESSION_KEY );
		} elseif ( 'none saved' === $saved ) {
			WC()->session->__unset( self::CURRENCY_SESSION_KEY );
			WC()->session->set( 'cart_totals', null );
		}

		$counted = $this->as_request( 'rest_route', fn() => $this->count_recalculations_during( array( $this->sut, 'maybe_recalculate_cart_for_currency' ) ) );

		$this->assertSame( $recalculations, $counted );
	}

	/**
	 * Saved totals after a cart calculated in GBP.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function provide_saved_totals(): array {
		return array(
			'stamped as calculated'                  => array( 'stamped as calculated', 0 ),
			'saved where Multi-Currency did not run' => array( 'saved where Multi-Currency did not run', 1 ),
			'unstamped'                              => array( 'unstamped', 1 ),
			'none saved'                             => array( 'none saved', 0 ),
		);
	}

	/**
	 * @testdox Should drop the shipping rates cached beside stale totals once per stale state, and keep them beside current totals.
	 *
	 * A page view does not recalculate, so the rates are dropped without calculating; rates rebuilt for the same stale totals
	 * stay cached, so live-rate carriers are not asked again on every load (client 11.3.0
	 * `tests/unit/multi-currency/test-class-multi-currency.php:1403-1476`, `:1515-1538`).
	 */
	public function test_drops_cached_shipping_rates_once_per_stale_state(): void {
		$this->switch_on_an_earlier_request( 'GBP' );
		$this->add_product_to_cart();
		WC()->session->set( self::CACHED_SHIPPING_RATES_KEY, self::CACHED_SHIPPING_RATES );
		$this->as_request( 'page', array( $this->sut, 'maybe_recalculate_cart_for_currency' ) );
		$beside_current_totals = WC()->session->get( self::CACHED_SHIPPING_RATES_KEY );
		$totals                = WC()->session->get( 'cart_totals' );
		$totals['total']       = self::STORE_CURRENCY_CART_TOTAL;
		WC()->session->set( 'cart_totals', $totals );

		$recalculations = $this->as_request( 'page', fn() => $this->count_recalculations_during( array( $this->sut, 'maybe_recalculate_cart_for_currency' ) ) );
		$after_first    = WC()->session->get( self::CACHED_SHIPPING_RATES_KEY );
		WC()->session->set( self::CACHED_SHIPPING_RATES_KEY, self::REBUILT_SHIPPING_RATES );
		$this->as_request( 'page', array( $this->sut, 'maybe_recalculate_cart_for_currency' ) );

		$this->assertSame(
			array( self::CACHED_SHIPPING_RATES, 0, null, self::REBUILT_SHIPPING_RATES ),
			array( $beside_current_totals, $recalculations, $after_first, WC()->session->get( self::CACHED_SHIPPING_RATES_KEY ) )
		);
	}

	/**
	 * @testdox Once the totals are stamped again, a later stale state drops the cached shipping rates again.
	 *
	 * Client 11.3.0 `tests/unit/multi-currency/test-class-multi-currency.php:1478-1513`.
	 */
	public function test_a_new_stamp_lets_a_later_stale_state_drop_the_rates_again(): void {
		$this->add_product_to_cart();
		WC()->session->set( self::CURRENCY_SESSION_KEY, 'EUR' );
		WC()->session->set( self::CACHED_SHIPPING_RATES_KEY, self::CACHED_SHIPPING_RATES );
		$this->as_request( 'page', array( $this->sut, 'maybe_recalculate_cart_for_currency' ) );
		WC()->cart->calculate_totals();
		$restamped = WC()->session->get( self::CURRENCY_SESSION_KEY );
		WC()->session->set( self::CURRENCY_SESSION_KEY, 'EUR' );
		WC()->session->set( self::CACHED_SHIPPING_RATES_KEY, self::REBUILT_SHIPPING_RATES );

		$this->as_request( 'page', array( $this->sut, 'maybe_recalculate_cart_for_currency' ) );

		$this->assertSame( array( 'USD', null ), array( $restamped, WC()->session->get( self::CACHED_SHIPPING_RATES_KEY ) ) );
	}

	/**
	 * @testdox Should remove the stamp and its hash when the cart is emptied or loads empty: $_dataName.
	 *
	 * Removing the last item does not fire `woocommerce_cart_emptied`, so the next load drops the stamp (client 11.3.0
	 * `tests/unit/multi-currency/test-class-multi-currency.php:1302-1321`, `:1350-1359`).
	 *
	 * @testWith ["emptied"]
	 *           ["last item removed, then loaded"]
	 *
	 * @param string $how How the cart lost its items.
	 */
	public function test_removes_the_stamp_of_a_cart_without_items( string $how ): void {
		$this->add_product_to_cart();
		$stamped = WC()->session->get( self::CURRENCY_SESSION_KEY );

		if ( 'emptied' === $how ) {
			WC()->cart->empty_cart();
		} else {
			WC()->cart->remove_cart_item( (string) array_key_first( WC()->cart->get_cart() ) );
			$this->sut->maybe_recalculate_cart_for_currency();
		}

		$this->assertSame( array( 'USD', null, null ), array( $stamped, WC()->session->get( self::CURRENCY_SESSION_KEY ), WC()->session->get( self::HASH_SESSION_KEY ) ) );
	}

	/**
	 * Save totals in the store currency to the session, as another cart's WC_Cart_Session::set_session() would.
	 *
	 * @internal
	 */
	public function save_another_cart_with_store_currency_totals(): void {
		$totals          = WC()->session->get( 'cart_totals' );
		$totals['total'] = self::STORE_CURRENCY_CART_TOTAL;
		WC()->session->set( 'cart_totals', $totals );
		do_action( 'woocommerce_cart_updated' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
	}

	/**
	 * Lower the cart's total in memory after WooCommerce saved it, as a store credit plugin might.
	 *
	 * @internal
	 *
	 * @param mixed $cart The cart.
	 */
	public function lower_the_total_by_one( $cart ): void {
		$cart->set_total( (float) $cart->get_total( 'edit' ) - 1 );
	}

	/**
	 * Add a product priced 100 in the store currency to the empty cart and calculate it.
	 */
	private function add_product_to_cart(): void {
		$product = WC_Helper_Product::create_simple_product();
		$product->set_regular_price( (string) self::CART_PRODUCT_PRICE );
		$product->set_price( (string) self::CART_PRODUCT_PRICE );
		$product->save();
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		WC()->cart->calculate_totals();
	}

	/**
	 * Store the guest's selection as a switch on an earlier request leaves it, and read it from now on.
	 *
	 * @param string $currency_code Currency code.
	 */
	private function switch_on_an_earlier_request( string $currency_code ): void {
		WC()->session->set( self::SELECTED_CURRENCY_KEY, $currency_code );
		wc_get_container()->get( StateBuilderFactory::class )->get_state_builder()->reset();
	}

	/**
	 * Get the session handler the cart loads and saves through.
	 *
	 * @return \WC_Cart_Session
	 */
	private function get_cart_session(): \WC_Cart_Session {
		$property = new \ReflectionProperty( WC_Cart::class, 'session' );
		$property->setAccessible( true );

		return $property->getValue( WC()->cart );
	}

	/**
	 * Load the cart from the session, as a new request does.
	 */
	private function reload_cart_from_session(): void {
		$this->get_cart_session()->get_cart_from_session();
	}

	/**
	 * Run the callback as if the cart had not loaded yet in this request.
	 *
	 * @param callable $callback Callback.
	 * @return mixed The callback's return value.
	 */
	private function with_cart_not_loaded( callable $callback ) {
		global $wp_actions;
		$actions  = array( 'wp_loaded', 'woocommerce_cart_loaded_from_session' );
		$original = array_intersect_key( $wp_actions, array_flip( $actions ) );
		foreach ( $actions as $action ) {
			unset( $wp_actions[ $action ] );
		}

		try {
			return $callback();
		} finally {
			foreach ( $original as $action => $count ) {
				$wp_actions[ $action ] = $count; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the counts read above.
			}
		}
	}

	/**
	 * Run the callback as a storefront page view, or as a REST request shown by a `rest_route` parameter or by the URL.
	 *
	 * @param string   $kind     'page', 'rest_route' or 'rest_filter'.
	 * @param callable $callback Callback.
	 * @return mixed The callback's return value.
	 */
	private function as_request( string $kind, callable $callback ) {
		if ( 'rest_route' === $kind ) {
			$_REQUEST['rest_route'] = self::STORE_API_CART_ROUTE;
		} elseif ( 'rest_filter' === $kind ) {
			add_filter( 'woocommerce_is_rest_api_request', '__return_true' );
		}

		try {
			return $callback();
		} finally {
			unset( $_REQUEST['rest_route'] );
			remove_filter( 'woocommerce_is_rest_api_request', '__return_true' );
		}
	}

	/**
	 * Count the cart calculations the callback makes: each fires `woocommerce_after_calculate_totals` once.
	 *
	 * @param callable $callback Callback.
	 * @return int
	 */
	private function count_recalculations_during( callable $callback ): int {
		$recalculations = 0;
		$spy            = static function () use ( &$recalculations ): void {
			++$recalculations;
		};
		add_action( 'woocommerce_after_calculate_totals', $spy );

		try {
			$callback();
		} finally {
			remove_action( 'woocommerce_after_calculate_totals', $spy );
		}

		return $recalculations;
	}

	/**
	 * Create a module arbiter that gives Multi-Currency to WooCommerce.
	 *
	 * @return MultiCurrencyRuntimeArbiter
	 */
	private function create_builtin_arbiter(): MultiCurrencyRuntimeArbiter {
		return new class() extends MultiCurrencyRuntimeArbiter {
			/**
			 * Skip the parent constructor.
			 */
			public function __construct() {}

			/**
			 * Get the Multi-Currency runtime owner.
			 *
			 * @return string
			 */
			public function get_runtime_owner(): string {
				return MultiCurrencyRuntimeArbiter::OWNER_BUILTIN;
			}

			/**
			 * Tell whether WooCommerce runs Multi-Currency.
			 *
			 * @return bool
			 */
			public function is_builtin_owner(): bool {
				return true;
			}

			/**
			 * Tell whether the WooPayments extension runs Multi-Currency.
			 *
			 * @return bool
			 */
			public function is_extension_owner(): bool {
				return false;
			}

			/**
			 * Tell whether the Multi-Currency feature is on.
			 *
			 * @return bool
			 */
			public function feature_is_enabled(): bool {
				return true;
			}

			/**
			 * Tell whether the WooPayments extension owns payments.
			 *
			 * @return bool
			 */
			public function is_payments_extension_owner(): bool {
				return false;
			}

			/**
			 * Tell whether the built-in WooPayments owns payments.
			 *
			 * @return bool
			 */
			public function is_payments_builtin_owner(): bool {
				return true;
			}
		};
	}
}
