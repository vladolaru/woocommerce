<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Admin\API\Reports\Cache as ReportsCache;
use Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore as OrdersStatsDataStore;
use Automattic\WooCommerce\Admin\API\Reports\Taxes\DataStore as TaxesDataStore;
use Automattic\WooCommerce\Blocks\Assets\AssetDataRegistry;
use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Admin\WCAdminAssets;
use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyAnalyticsController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\AnalyticsService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\DatabaseCache;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\LocalizationService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\ServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\RequestContext;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\AnalyticsSqlFilters;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\UsageDetector;
use WC_Unit_Test_Case;
use Automattic\WooCommerce\Tests\Internal\MultiCurrency\RateServices;

/**
 * Tests for the MultiCurrencyAnalyticsController class.
 */
class MultiCurrencyAnalyticsControllerTest extends WC_Unit_Test_Case {

	/**
	 * Hooks touched by the analytics controller.
	 *
	 * @var string[]
	 */
	private array $hooks = array(
		'woocommerce_analytics_report_should_use_cache',
		'woocommerce_analytics_update_order_stats_data',
		'woocommerce_analytics_orders_query_args',
		'woocommerce_analytics_orders_stats_query_args',
		'woocommerce_analytics_clauses_select',
		'woocommerce_analytics_clauses_join',
		'woocommerce_analytics_clauses_where_orders_subquery',
		'woocommerce_analytics_clauses_where_orders_stats_total',
		'woocommerce_analytics_clauses_where_orders_stats_interval',
		'woocommerce_analytics_clauses_select_orders_subquery',
		'woocommerce_analytics_clauses_select_orders_stats_total',
		'woocommerce_new_order',
		'wcpay_multi_currency_disable_filter_select_clauses',
		'wcpay_multi_currency_filter_select_clauses',
		'admin_enqueue_scripts',
	);

	/**
	 * Shared asset data registry.
	 *
	 * @var AssetDataRegistry
	 */
	private $registry;

	/**
	 * Registry values before the test.
	 *
	 * @var array<string,mixed>
	 */
	private array $registry_state = array();

	/**
	 * Snapshot the shared asset data registry.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'USD' );
		$this->registry = Package::container()->get( AssetDataRegistry::class );
		foreach ( array( 'data', 'lazy_data' ) as $property ) {
			$reflection = new \ReflectionProperty( AssetDataRegistry::class, $property );
			$reflection->setAccessible( true );
			$this->registry_state[ $property ] = $reflection->getValue( $this->registry );
		}
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		foreach ( $this->hooks as $hook ) {
			remove_all_filters( $hook );
		}

		delete_transient( 'wc_mc_has_orders' );
		foreach ( $this->registry_state as $property => $value ) {
			$reflection = new \ReflectionProperty( AssetDataRegistry::class, $property );
			$reflection->setAccessible( true );
			$reflection->setValue( $this->registry, $value );
		}
		unset( $_GET['page'], $_GET['currency'], $_GET['currency_is'] );
		set_current_screen( 'front' );
		wp_dequeue_script( 'wc-admin-multi-currency-analytics' );
		wp_deregister_script( 'wc-admin-multi-currency-analytics' );
		wp_deregister_script( 'WCPAY_MULTI_CURRENCY_ANALYTICS' );

		parent::tear_down();
	}

	/**
	 * @testdox Should not register analytics hooks when plugin owns runtime.
	 */
	public function test_does_not_register_analytics_hooks_when_extension_owns_runtime(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_EXTENSION, true );

		$sut->register();

		$this->assertFalse( has_filter( 'woocommerce_analytics_orders_query_args', array( $sut, 'handle_woocommerce_analytics_orders_query_args' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_analytics_clauses_select', array( $sut, 'handle_woocommerce_analytics_clauses_select' ) ) );
	}

	/**
	 * @testdox Should register baseline analytics hooks when core owns runtime.
	 */
	public function test_registers_baseline_analytics_hooks_when_core_owns_runtime(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false );

		$sut->register();
		$sut->register();

		$this->assertSame( 10, has_filter( 'woocommerce_analytics_orders_query_args', array( $sut, 'handle_woocommerce_analytics_orders_query_args' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_analytics_orders_stats_query_args', array( $sut, 'handle_woocommerce_analytics_orders_query_args' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_analytics_clauses_select', array( $sut, 'handle_woocommerce_analytics_clauses_select' ) ) );
	}

	/**
	 * @testdox Should add the currency SQL to Analytics requests sent with a rest_route parameter, as on plain-permalink stores.
	 */
	public function test_registers_sql_hooks_for_rest_route_requests(): void {
		$previous_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Preserve the test request URI for restoration.
		set_transient( 'wc_mc_has_orders', '1', HOUR_IN_SECONDS );
		// The client checks with core's WooCommerce::is_rest_api_request(), which accepts rest_route (client 11.1.0 `Analytics.php:89`).
		$_SERVER['REQUEST_URI'] = '/index.php?rest_route=/wc-analytics/reports/orders';
		$_GET['rest_route']     = '/wc-analytics/reports/orders';

		try {
			$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, null );
			$sut->register();
		} finally {
			unset( $_GET['rest_route'] );
			if ( null === $previous_uri ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $previous_uri;
			}
		}

		$this->assertSame( 20, has_filter( 'woocommerce_analytics_clauses_select', array( $sut, 'handle_woocommerce_analytics_clauses_select' ) ) );
	}

	/**
	 * @testdox Should not resolve the WooCommerce singleton while registering analytics hooks.
	 */
	public function test_register_does_not_resolve_woocommerce_singleton_for_rest_detection(): void {
		$reflection = new \ReflectionClass( \WooCommerce::class );
		$instance   = $reflection->getProperty( '_instance' );
		$instance->setAccessible( true );
		$previous_instance = $instance->getValue();
		$previous_uri      = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Preserve the test request URI for restoration.
		$sentinel          = new class() extends \WooCommerce {
			/**
			 * Whether is_rest_api_request was called.
			 *
			 * @var bool
			 */
			public bool $is_rest_api_request_called = false;

			/**
			 * Constructor intentionally avoids booting WooCommerce.
			 */
			public function __construct() {}

			/**
			 * Record accidental WooCommerce singleton access.
			 *
			 * @return bool
			 */
			public function is_rest_api_request() {
				$this->is_rest_api_request_called = true;

				return true;
			}
		};

		$instance->setValue( $sentinel );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Simulate a REST request URI.
		$_SERVER['REQUEST_URI'] = '/wp-json/wc-analytics/reports/orders';

		try {
			$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, null );
			$sut->register();
		} finally {
			$instance->setValue( $previous_instance );

			if ( null === $previous_uri ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $previous_uri;
			}
		}

		$this->assertFalse( $sentinel->is_rest_api_request_called, 'Analytics registration must not call WC() while WooCommerce is still constructing.' );
	}

	/**
	 * @testdox Should register SQL hooks only for REST requests with multi-currency orders.
	 */
	public function test_registers_sql_hooks_only_for_rest_requests_with_multi_currency_orders(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true );
		set_transient( UsageDetector::HAS_MC_ORDERS_TRANSIENT, '1', HOUR_IN_SECONDS );

		$sut->register();

		$this->assertSame( 20, has_filter( 'woocommerce_analytics_clauses_select', array( $sut, 'handle_woocommerce_analytics_clauses_select' ) ) );
		$this->assertSame( 20, has_filter( 'woocommerce_analytics_clauses_join', array( $sut, 'handle_woocommerce_analytics_clauses_join' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_analytics_clauses_where_orders_subquery', array( $sut, 'handle_woocommerce_analytics_clauses_where' ) ) );
	}

	/**
	 * @testdox Should omit optional SQL hooks when historical order detection fails.
	 */
	public function test_omits_optional_sql_hooks_when_historical_order_query_fails(): void {
		global $wpdb;

		add_filter( 'pre_option_' . CustomOrdersTableController::CUSTOM_ORDERS_TABLE_USAGE_ENABLED_OPTION, static fn(): string => 'no' );
		$detector = new UsageDetector();
		$sut      = new MultiCurrencyAnalyticsController();
		$sut->init(
			$this->create_arbiter( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN ),
			wc_get_container()->get( ServiceFactory::class ),
			$detector,
			$this->create_request_context( true ),
			wc_get_container()->get( AnalyticsSqlFilters::class )
		);
		$original_postmeta = $wpdb->postmeta;
		$suppress_errors   = $wpdb->suppress_errors( true );
		$wpdb->postmeta    = "{$wpdb->prefix}missing_multi_currency_order_meta";

		try {
			$sut->register();
		} finally {
			$wpdb->postmeta = $original_postmeta;
			$wpdb->suppress_errors( $suppress_errors );
		}

		$this->assertSame( 10, has_filter( 'woocommerce_analytics_orders_query_args', array( $sut, 'handle_woocommerce_analytics_orders_query_args' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_analytics_clauses_select', array( $sut, 'handle_woocommerce_analytics_clauses_select' ) ) );
	}

	/**
	 * @testdox Should register selected-currency SQL hooks for REST requests with multi-currency orders.
	 */
	public function test_registers_selected_currency_sql_hooks_for_rest_requests_with_multi_currency_orders(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true );
		set_transient( UsageDetector::HAS_MC_ORDERS_TRANSIENT, '1', HOUR_IN_SECONDS );
		$_GET['currency'] = 'EUR';

		$sut->register();

		$this->assertSame( 10, has_filter( 'woocommerce_analytics_clauses_select_orders_subquery', array( $sut, 'handle_woocommerce_analytics_clauses_select_orders' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_analytics_clauses_select_orders_stats_total', array( $sut, 'handle_woocommerce_analytics_clauses_select_orders' ) ) );
	}

	/**
	 * @testdox Should not resolve the default currency while registering selected-currency SQL hooks.
	 */
	public function test_register_does_not_resolve_default_currency_for_selected_currency_sql_hooks(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true );
		set_transient( UsageDetector::HAS_MC_ORDERS_TRANSIENT, '1', HOUR_IN_SECONDS );
		$_GET['currency'] = 'EUR';
		add_filter(
			'woocommerce_currency',
			static function (): string {
				throw new \RuntimeException( 'Default currency should not be resolved during analytics registration.' );
			}
		);

		$sut->register();

		$this->assertSame( 10, has_filter( 'woocommerce_analytics_clauses_select_orders_subquery', array( $sut, 'handle_woocommerce_analytics_clauses_select_orders' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_analytics_clauses_select_orders_stats_total', array( $sut, 'handle_woocommerce_analytics_clauses_select_orders' ) ) );
	}

	/**
	 * @testdox Should leave selected-currency order select clauses unchanged for the default currency.
	 */
	public function test_selected_currency_order_select_clauses_are_unchanged_for_default_currency(): void {
		global $wpdb;

		$sut              = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );
		$_GET['currency'] = 'USD';
		$clauses          = array( "{$wpdb->prefix}wc_order_stats.net_total" );

		$result = $sut->handle_woocommerce_analytics_clauses_select_orders( $clauses );

		$this->assertSame( $clauses, $result, 'Default-currency analytics requests should not project selected-currency totals.' );
	}

	/**
	 * @testdox Should keep the Analytics report cache when the WooCommerce Stripe Gateway's dev mode constant is on.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_keeps_analytics_report_cache_with_the_stripe_gateway_dev_mode_constant(): void {
		define( 'WC_STRIPE_DEV_MODE', true );
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );

		$sut->register();

		$this->assertTrue( apply_filters( 'woocommerce_analytics_report_should_use_cache', true, 'woocommerce_orders_stats_query' ) );
	}

	/**
	 * @testdox Should apply customer currency request args.
	 */
	public function test_applies_customer_currency_request_args(): void {
		$sut                 = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );
		$_GET['currency_is'] = array( ' EUR ', 'GBP' );
		$_GET['currency']    = ' CAD ';

		$result = $sut->handle_woocommerce_analytics_orders_query_args( array( 'status' => 'completed' ) );

		$this->assertSame( array( 'EUR', 'GBP' ), $result['currency_is'] );
		$this->assertSame( array(), $result['currency_is_not'] );
		$this->assertSame( 'CAD', $result['currency'] );
		$this->assertSame( 'completed', $result['status'] );
	}

	/**
	 * @testdox Should update order stats data through projection.
	 */
	public function test_converts_order_stats_data_with_the_order_exchange_rate(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->set_currency( 'EUR' );
		$order->update_meta_data( '_wcpay_multi_currency_order_exchange_rate', 2 );
		$order->update_meta_data( '_wcpay_multi_currency_order_default_currency', 'USD' );
		$order->save();
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false, new AnalyticsService( $this->create_state_builder() ) );

		$result = $sut->convert_order_stats_data(
			array(
				'net_total'      => 10.0,
				'shipping_total' => 4.0,
				'tax_total'      => 2.0,
			),
			$order
		);

		$this->assertSame( 5.0, $result['net_total'] );
		$this->assertSame( 2.0, $result['shipping_total'] );
		$this->assertSame( 1.0, $result['tax_total'] );
		$this->assertSame( 8.0, $result['total_sales'] );
	}

	/**
	 * @testdox Should convert a refund's order stats with the exchange rate copied from its order.
	 */
	public function test_converts_refund_order_stats_data(): void {
		// WooCommerce Analytics passes refunds to this filter too (`Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore::update()`).
		$refund = new \WC_Order_Refund();
		$refund->set_currency( 'EUR' );
		$refund->update_meta_data( '_wcpay_multi_currency_order_exchange_rate', 2 );
		$refund->update_meta_data( '_wcpay_multi_currency_order_default_currency', 'USD' );
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false, new AnalyticsService( $this->create_state_builder() ) );

		$result = $sut->convert_order_stats_data(
			array(
				'net_total'      => -10.0,
				'shipping_total' => -4.0,
				'tax_total'      => -2.0,
			),
			$refund
		);

		$this->assertSame( -5.0, $result['net_total'] );
		$this->assertSame( -2.0, $result['shipping_total'] );
		$this->assertSame( -1.0, $result['tax_total'] );
		$this->assertSame( -8.0, $result['total_sales'] );
	}

	/**
	 * @testdox Should report the Taxes report's taxable amounts in the store currency, as it does the tax.
	 */
	public function test_converts_taxes_report_taxable_amounts_to_the_store_currency(): void {
		\WC_Helper_Reports::reset_stats_dbs();
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_tax_based_on', 'billing' );
		update_option( 'woocommerce_shipping_tax_class', '' );
		$rate_id = (int) \WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'DE',
				'tax_rate'          => '19',
				'tax_rate_name'     => 'VAT',
				'tax_rate_priority' => 1,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 0,
				'tax_rate_class'    => '',
			)
		);
		set_transient( UsageDetector::HAS_MC_ORDERS_TRANSIENT, '1', HOUR_IN_SECONDS );
		$this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true )->register();

		// One order in the store currency and one in EUR at 2 EUR to the dollar, each 2 x 100 product + 10 fee on the order side
		// and 5 shipping. The expected amounts use the conversion the tax sums get (client 11.1.0 `includes/multi-currency/Analytics.php:667-671`).
		foreach ( array( 'USD', 'EUR' ) as $currency ) {
			$order = wc_create_order();
			$order->set_currency( $currency );
			$order->set_billing_country( 'DE' );
			$order->set_shipping_country( 'DE' );
			$order->add_product( \WC_Helper_Product::create_simple_product( true, array( 'regular_price' => '100' ) ), 2 );
			$fee = new \WC_Order_Item_Fee();
			$fee->set_name( 'Handling' );
			$fee->set_total( '10' );
			$fee->set_tax_status( 'taxable' );
			$order->add_item( $fee );
			$shipping = new \WC_Order_Item_Shipping();
			$shipping->set_method_id( 'flat_rate' );
			$shipping->set_total( '5' );
			$order->add_item( $shipping );
			$order->calculate_totals();
			if ( 'EUR' === $currency ) {
				$order->update_meta_data( '_wcpay_multi_currency_order_exchange_rate', 2 );
				$order->update_meta_data( '_wcpay_multi_currency_order_default_currency', 'USD' );
			}
			$order->set_status( OrderStatus::COMPLETED );
			$order->set_date_paid( time() );
			$order->save();
			OrdersStatsDataStore::sync_order( $order->get_id() );
			TaxesDataStore::sync_order_taxes( $order->get_id() );
		}
		ReportsCache::invalidate();

		$row = ( new TaxesDataStore() )->get_data(
			array(
				'after'    => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
				'before'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'taxes'    => array( $rate_id ),
				'per_page' => 100,
				'page'     => 1,
			)
		)->data[0];

		// 215 USD plus 215 EUR at 2 EUR to the dollar (107.50 USD), split 210 + 105 order side and 5 + 2.50 shipping.
		$this->assertSame( 322.5, $row['taxable_amount'] );
		$this->assertSame( 315.0, $row['order_taxable_amount'] );
		$this->assertSame( 7.5, $row['shipping_taxable_amount'] );
	}

	/**
	 * @testdox Should respect SQL clause disable and extension filters.
	 */
	public function test_respects_sql_clause_disable_and_extension_filters(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );
		add_filter( 'pre_option_' . CustomOrdersTableController::CUSTOM_ORDERS_TABLE_USAGE_ENABLED_OPTION, static fn(): string => 'no' );
		$clauses = array( 'discount_amount' );
		add_filter( 'wcpay_multi_currency_disable_filter_select_clauses', '__return_true' );

		$this->assertSame( $clauses, $sut->handle_woocommerce_analytics_clauses_select( $clauses, 'orders_stats' ) );

		remove_filter( 'wcpay_multi_currency_disable_filter_select_clauses', '__return_true' );
		add_filter(
			'wcpay_multi_currency_filter_select_clauses',
			static fn(): array => array( 'filtered' )
		);

		$this->assertSame( array( 'filtered' ), $sut->handle_woocommerce_analytics_clauses_select( $clauses, 'orders_stats' ) );
	}

	/**
	 * @testdox Should return SELECT clauses before the extension filter when the context is $label.
	 *
	 * Client 11.1.0 returns before its disable filter without a context, and before its extension filter when the
	 * context is not a stats or subquery one (`includes/multi-currency/Analytics.php:233-299`).
	 *
	 * @dataProvider unsupported_select_context_data
	 *
	 * @param string      $label                  Case label.
	 * @param string|null $context                Analytics context.
	 * @param int         $expected_disable_calls Times the disable filter is applied.
	 */
	public function test_returns_select_clauses_before_the_extension_filter_for_an_unsupported_context( string $label, ?string $context, int $expected_disable_calls ): void {
		unset( $label );
		$sut           = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );
		$clauses       = array( ', product_id' );
		$disable_calls = 0;
		$extend_calls  = 0;
		add_filter(
			'wcpay_multi_currency_disable_filter_select_clauses',
			static function ( $disable ) use ( &$disable_calls ) {
				++$disable_calls;
				return $disable;
			}
		);
		add_filter(
			'wcpay_multi_currency_filter_select_clauses',
			static function () use ( &$extend_calls ): array {
				++$extend_calls;
				return array( 'filtered' );
			}
		);

		$this->assertSame( $clauses, $sut->handle_woocommerce_analytics_clauses_select( $clauses, $context ) );
		$this->assertSame( $expected_disable_calls, $disable_calls );
		$this->assertSame( 0, $extend_calls );
	}

	/**
	 * Contexts the SELECT clause handler leaves alone.
	 *
	 * @return array<string,array{string,string|null,int}>
	 */
	public function unsupported_select_context_data(): array {
		return array(
			'missing'                    => array( 'missing', null, 0 ),
			'neither stats nor subquery' => array( 'neither stats nor subquery', 'products', 1 ),
		);
	}

	/**
	 * @testdox Should not apply the order SELECT disable filter when the report shows the store currency.
	 *
	 * Client 11.1.0 adds the order SELECT handlers only when a non-default currency is selected
	 * (`includes/multi-currency/Analytics.php:105-108`), so its disable filter is not applied otherwise (`:425-436`).
	 */
	public function test_does_not_apply_the_order_select_disable_filter_for_the_store_currency(): void {
		$sut              = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );
		$_GET['currency'] = 'USD';
		$disable_calls    = 0;
		add_filter(
			'wcpay_multi_currency_disable_filter_select_orders_clauses',
			static function ( $disable ) use ( &$disable_calls ) {
				++$disable_calls;
				return $disable;
			}
		);

		$sut->handle_woocommerce_analytics_clauses_select_orders( array( 'net_total' ) );

		$this->assertSame( 0, $disable_calls );
	}

	/**
	 * @testdox Should register SQL hooks from the cached multi-currency orders transient without querying.
	 */
	public function test_registers_sql_hooks_from_cached_multi_currency_orders_transient(): void {
		set_transient( 'wc_mc_has_orders', '1', HOUR_IN_SECONDS );
		$sut = $this->create_controller_without_orders_resolver( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );

		$sut->register();

		$this->assertSame(
			20,
			has_filter( 'woocommerce_analytics_clauses_select', array( $sut, 'handle_woocommerce_analytics_clauses_select' ) ),
			'A cached positive transient should drive SQL hook registration without scanning order meta.'
		);
	}

	/**
	 * @testdox Should not remember "no foreign-currency orders", so an order imported with an exchange rate counts on the next request.
	 */
	public function test_counts_an_imported_foreign_currency_order_on_the_next_request(): void {
		// Earlier versions cached "no" as '0'; it must not hide the import either.
		set_transient( 'wc_mc_has_orders', '0', HOUR_IN_SECONDS );
		$this->create_controller_without_orders_resolver( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN )->register();
		$this->assertSame( '0', get_transient( 'wc_mc_has_orders' ), 'A negative answer is not written.' );

		// An import writes the rate through the order CRUD API, without the price controller (meta key: client 11.1.0 `includes/multi-currency/FrontendPrices.php:373`).
		$order = \WC_Helper_Order::create_order();
		$order->set_currency( 'EUR' );
		$order->update_meta_data( '_wcpay_multi_currency_order_exchange_rate', '0.5' );
		$order->save();
		$sut = $this->create_controller_without_orders_resolver( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );
		$sut->register();

		// The client asks on every Analytics request (client `includes/multi-currency/Analytics.php:565-590`).
		$this->assertSame( 20, has_filter( 'woocommerce_analytics_clauses_select', array( $sut, 'handle_woocommerce_analytics_clauses_select' ) ) );
		$this->assertSame( '1', get_transient( 'wc_mc_has_orders' ), 'A positive answer is cached; order meta only accumulates.' );
	}

	/**
	 * @testdox Should load the customer currency filter on wc-admin pages for store managers, with currency names and symbols.
	 */
	public function test_loads_the_customer_currency_filter_on_wc_admin_pages_for_store_managers(): void {
		// The plugin stores the customer currency list as currency codes and the enabled list and manual rates as these options
		// (client 11.1.0 `includes/multi-currency/MultiCurrency.php:700-717`, `:767-783`, `:1757-1770`).
		update_option( 'wcpay_multi_currency_stored_customer_currencies', array( 'EUR' ) );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.9' );
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );
		set_current_screen( 'woocommerce_page_wc-admin' );
		$sut->register();
		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( $sut, 'handle_admin_enqueue_scripts' ) ) );

		$_GET['page'] = 'wc-admin';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$sut->handle_admin_enqueue_scripts();
		$this->assertFalse( $this->registry->exists( 'customerCurrencies' ), 'Users who cannot manage WooCommerce get no filter.' );
		$this->assertFalse( wp_script_is( 'wc-admin-multi-currency-analytics', 'enqueued' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$sut->handle_admin_enqueue_scripts();

		// Labels as the client's AssetDataRegistry entry (client `includes/multi-currency/Analytics.php:125-154`).
		$this->assertSame(
			array(
				array(
					'label' => 'Euro',
					'value' => 'EUR',
				),
				array(
					'label' => 'United States (US) dollar',
					'value' => 'USD',
				),
			),
			$this->get_registry_data()['customerCurrencies']
		);
		// Every currency's symbol, as the client's currencyData (client `includes/admin/class-wc-payments-admin.php:943-954`).
		$symbols = $this->get_registry_data()['customerCurrencySymbols'];
		$this->assertSame( '€', $symbols['EUR'] );
		$this->assertSame( '$', $symbols['USD'] );
		$this->assertSame( '¥', $symbols['JPY'] );
	}

	/**
	 * @testdox Should enqueue the Analytics script, with the plugin's handle as an alias, on wc-admin pages for store managers.
	 */
	public function test_enqueues_the_analytics_script_for_store_managers(): void {
		try {
			WCAdminAssets::get_script_asset_filename( 'wp-admin-scripts', 'multi-currency-analytics' );
		} catch ( \Exception $e ) {
			// CI's PHP job runs without the admin build; local runs and the release package have it.
			$this->markTestSkipped( 'The admin client is not built: ' . $e->getMessage() );
		}
		$sut          = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );
		$_GET['page'] = 'wc-admin';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );

		$sut->handle_admin_enqueue_scripts();

		$this->assertTrue( wp_script_is( 'wc-admin-multi-currency-analytics', 'enqueued' ) );
		// The plugin's handle (client 11.1.0 `includes/multi-currency/Analytics.php:27`).
		$this->assertSame( array( 'wc-admin-multi-currency-analytics' ), wp_scripts()->registered['WCPAY_MULTI_CURRENCY_ANALYTICS']->deps );
	}

	/**
	 * @testdox Should keep customer currencies another plugin registered ($label) and still provide every currency's symbol.
	 * @testWith ["concretely", false]
	 *           ["lazily", true]
	 *
	 * @param string $label Case label.
	 * @param bool   $lazy  Whether the other plugin registered a lazy value.
	 */
	public function test_keeps_customer_currencies_another_plugin_registered( string $label, bool $lazy ): void {
		unset( $label );
		$sut          = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );
		$_GET['page'] = 'wc-admin';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$theirs = array(
			array(
				'label' => 'Euro',
				'value' => 'EUR',
			),
		);
		$this->registry->add( 'customerCurrencies', $lazy ? static fn(): array => $theirs : $theirs );

		$sut->handle_admin_enqueue_scripts();
		$lazy_data = new \ReflectionMethod( AssetDataRegistry::class, 'execute_lazy_data' );
		$lazy_data->setAccessible( true );
		$lazy_data->invoke( $this->registry );

		$this->assertSame( $theirs, $this->get_registry_data()['customerCurrencies'] );
		$this->assertSame( '€', $this->get_registry_data()['customerCurrencySymbols']['EUR'] );
	}

	/**
	 * Read the asset data registry's registered values.
	 *
	 * @return array<string,mixed>
	 */
	private function get_registry_data(): array {
		$data = new \ReflectionProperty( AssetDataRegistry::class, 'data' );
		$data->setAccessible( true );

		return $data->getValue( $this->registry );
	}

	/**
	 * Create an analytics controller.
	 *
	 * @param string                $owner                     Runtime owner.
	 * @param bool|null             $rest_request Whether the request is a WooCommerce REST request; null reads the real request.
	 * @param AnalyticsService|null $analytics_service Analytics projection service; the factory's by default.
	 * @return MultiCurrencyAnalyticsController
	 */
	private function create_controller( string $owner, ?bool $rest_request = false, ?AnalyticsService $analytics_service = null ): MultiCurrencyAnalyticsController {
		$controller = new MultiCurrencyAnalyticsController();
		$controller->init(
			$this->create_arbiter( $owner ),
			ServiceFactories::with( $this, array( 'create_analytics_service' => $analytics_service ) ),
			new UsageDetector(),
			null === $rest_request ? wc_get_container()->get( RequestContext::class ) : $this->create_request_context( $rest_request ),
			wc_get_container()->get( AnalyticsSqlFilters::class )
		);

		return $controller;
	}

	/**
	 * Create a request context that answers whether the request is a WooCommerce REST request.
	 *
	 * @param bool $rest_request Whether the request is a WooCommerce REST request.
	 * @return RequestContext
	 */
	private function create_request_context( bool $rest_request ): RequestContext {
		return new class( $rest_request ) extends RequestContext {
			/**
			 * Whether the request is a WooCommerce REST request.
			 *
			 * @var bool
			 */
			private bool $rest_request;

			/**
			 * Constructor.
			 *
			 * @param bool $rest_request Whether the request is a WooCommerce REST request.
			 */
			public function __construct( bool $rest_request ) {
				$this->rest_request = $rest_request;
			}

			/**
			 * Tell whether the request is a WooCommerce REST request.
			 *
			 * @return bool
			 */
			public function is_wc_rest_api_request(): bool {
				return $this->rest_request;
			}
		};
	}

	/**
	 * Create an analytics controller that resolves multi-currency orders through the cached query.
	 *
	 * The multi-currency orders resolver seam is intentionally left unset so the transient-backed
	 * existence check is exercised.
	 *
	 * @param string $owner Runtime owner.
	 * @return MultiCurrencyAnalyticsController
	 */
	private function create_controller_without_orders_resolver( string $owner ): MultiCurrencyAnalyticsController {
		$controller = new MultiCurrencyAnalyticsController();
		$controller->init(
			$this->create_arbiter( $owner ),
			wc_get_container()->get( ServiceFactory::class ),
			new UsageDetector(),
			$this->create_request_context( true ),
			wc_get_container()->get( AnalyticsSqlFilters::class )
		);

		return $controller;
	}

	/**
	 * Create a state builder.
	 *
	 * @return StateBuilder
	 */
	private function create_state_builder(): StateBuilder {
		$localization_service = new LocalizationService();

		return new StateBuilder(
			$localization_service,
			RateServices::with(),
			new DatabaseCache()
		);
	}

	/**
	 * Create a static multi-currency runtime arbiter.
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
			 * Get the multi-currency runtime owner for the current site.
			 *
			 * @return string
			 */
			public function get_runtime_owner(): string {
				return $this->owner;
			}

			/**
			 * Tell whether core multi-currency may register hooks.
			 *
			 * @return bool
			 */
			public function is_builtin_owner(): bool {
				return MultiCurrencyRuntimeArbiter::OWNER_BUILTIN === $this->owner;
			}
		};
	}
}
