<?php
/**
 * MultiCurrencyAnalyticsController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Admin\PageController;
use Automattic\WooCommerce\Blocks\Assets\AssetDataRegistry;
use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\Internal\Admin\WCAdminAssets;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\AnalyticsService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\AnalyticsSqlFilters;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\RequestContext;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\ServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\UsageDetector;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Adds Multi-Currency to WooCommerce Analytics: the customer-currency filter and the SQL clauses that filter and convert
 * reports by currency. The bootstrap's order listeners also call it to record customer currencies and convert order stats.
 *
 * @since 11.0.0
 * @internal
 */
class MultiCurrencyAnalyticsController implements RegisterHooksInterface {

	private const DISABLE_SELECT_FILTER       = 'wcpay_multi_currency_disable_filter_select_clauses';
	private const EXTEND_SELECT_FILTER        = 'wcpay_multi_currency_filter_select_clauses';
	private const DISABLE_JOIN_FILTER         = 'wcpay_multi_currency_disable_filter_join_clauses';
	private const EXTEND_JOIN_FILTER          = 'wcpay_multi_currency_filter_join_clauses';
	private const DISABLE_WHERE_FILTER        = 'wcpay_multi_currency_disable_filter_where_clauses';
	private const EXTEND_WHERE_FILTER         = 'wcpay_multi_currency_filter_where_clauses';
	private const DISABLE_ORDER_SELECT_FILTER = 'wcpay_multi_currency_disable_filter_select_orders_clauses';
	private const EXTEND_ORDER_SELECT_FILTER  = 'wcpay_multi_currency_filter_select_orders_clauses';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var MultiCurrencyRuntimeArbiter
	 */
	private MultiCurrencyRuntimeArbiter $arbiter;

	/**
	 * Multi-Currency service factory.
	 *
	 * @var ServiceFactory
	 */
	private ServiceFactory $service_factory;

	/**
	 * Request context.
	 *
	 * @var RequestContext
	 */
	private RequestContext $request_context;

	/**
	 * Persisted Multi-Currency usage detector.
	 *
	 * @var UsageDetector
	 */
	private UsageDetector $usage_detector;

	/**
	 * Analytics service, built on first use.
	 *
	 * @var AnalyticsService|null
	 */
	private ?AnalyticsService $analytics_service = null;

	/**
	 * Analytics SQL filters.
	 *
	 * @var AnalyticsSqlFilters
	 */
	private AnalyticsSqlFilters $analytics_sql_filters;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter $arbiter                Runtime owner arbiter.
	 * @param ServiceFactory              $service_factory        Multi-Currency service factory.
	 * @param UsageDetector               $usage_detector         Persisted usage detector.
	 * @param RequestContext              $request_context        Request context.
	 * @param AnalyticsSqlFilters         $analytics_sql_filters Analytics SQL filters.
	 */
	final public function init(
		MultiCurrencyRuntimeArbiter $arbiter,
		ServiceFactory $service_factory,
		UsageDetector $usage_detector,
		RequestContext $request_context,
		AnalyticsSqlFilters $analytics_sql_filters
	): void {
		$this->arbiter               = $arbiter;
		$this->service_factory       = $service_factory;
		$this->usage_detector        = $usage_detector;
		$this->request_context       = $request_context;
		$this->analytics_sql_filters = $analytics_sql_filters;
	}

	/**
	 * Register analytics hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		// The client adds the Analytics filter on admin requests (client 11.1.0 `includes/multi-currency/Analytics.php:69-72`).
		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'handle_admin_enqueue_scripts' ) );
		}

		add_filter( 'woocommerce_analytics_orders_query_args', array( $this, 'handle_woocommerce_analytics_orders_query_args' ) );
		add_filter( 'woocommerce_analytics_orders_stats_query_args', array( $this, 'handle_woocommerce_analytics_orders_query_args' ) );

		if ( ! $this->request_context->is_wc_rest_api_request() ) {
			return;
		}

		try {
			if ( ! $this->usage_detector->has_foreign_currency_orders() ) {
				return;
			}
		} catch ( \RuntimeException $e ) {
			return;
		}

		add_filter( 'woocommerce_analytics_clauses_select', array( $this, 'handle_woocommerce_analytics_clauses_select' ), 20, 2 );
		add_filter( 'woocommerce_analytics_clauses_join', array( $this, 'handle_woocommerce_analytics_clauses_join' ), 20, 2 );
		add_filter( 'woocommerce_analytics_clauses_where_orders_subquery', array( $this, 'handle_woocommerce_analytics_clauses_where' ) );
		add_filter( 'woocommerce_analytics_clauses_where_orders_stats_total', array( $this, 'handle_woocommerce_analytics_clauses_where' ) );
		add_filter( 'woocommerce_analytics_clauses_where_orders_stats_interval', array( $this, 'handle_woocommerce_analytics_clauses_where' ) );

		add_filter( 'woocommerce_analytics_clauses_select_orders_subquery', array( $this, 'handle_woocommerce_analytics_clauses_select_orders' ) );
		add_filter( 'woocommerce_analytics_clauses_select_orders_stats_total', array( $this, 'handle_woocommerce_analytics_clauses_select_orders' ) );
	}

	/**
	 * Load the customer currency filter, column and report currency on wc-admin pages for store managers.
	 *
	 * The client loads them on every admin page (client 11.1.0 `includes/multi-currency/Analytics.php:69-72`, `:116-166`);
	 * Analytics only runs inside wc-admin, so other admin pages are left alone.
	 *
	 * @internal
	 */
	public function handle_admin_enqueue_scripts(): void {
		if ( ! PageController::is_admin_page() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Concrete values, added only when the key is absent, so a key another plugin already registered, even lazily, is kept.
		$registry = Package::container()->get( AssetDataRegistry::class );
		if ( ! $registry->exists( 'customerCurrencies' ) ) {
			$registry->add( 'customerCurrencies', $this->get_analytics_service()->get_customer_currency_options() );
		}
		// Symbols are checked on their own: another plugin may supply the currency list without them.
		if ( ! $registry->exists( 'customerCurrencySymbols' ) ) {
			$registry->add( 'customerCurrencySymbols', $this->get_analytics_service()->get_currency_symbols() );
		}

		try {
			WCAdminAssets::register_script( 'wp-admin-scripts', 'multi-currency-analytics', true );
		} catch ( \Exception $e ) {
			// Core's asset loader finds no build manifest: a checkout whose admin client was not built.
			wc_get_logger()->warning( 'Multi-Currency Analytics script not loaded: ' . $e->getMessage(), array( 'source' => 'multi-currency' ) );
			return;
		}

		// The `WCPAY_MULTI_CURRENCY_ANALYTICS` handle, kept as an alias for scripts that list it as a dependency (client 11.1.0 `Analytics.php:27`).
		wp_register_script( 'WCPAY_MULTI_CURRENCY_ANALYTICS', false, array( 'wc-admin-multi-currency-analytics' ), WC_VERSION, true );
	}

	/**
	 * Add an order's currency to the currencies customers have used (client 11.1.0 `includes/multi-currency/MultiCurrency.php:700-717`).
	 *
	 * @internal
	 *
	 * @param mixed $order_id Order ID.
	 */
	public function record_customer_currency( $order_id ): void {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof \WC_Abstract_Order ) {
			return;
		}

		$this->get_analytics_service()->record_customer_currency( strtoupper( $order->get_currency() ) );
	}

	/**
	 * Convert order stats data to store default currency for multi-currency orders.
	 *
	 * @internal
	 *
	 * @param array<string,mixed> $args  Order stats args.
	 * @param mixed               $order Order.
	 * @return array<string,mixed>
	 */
	public function convert_order_stats_data( array $args, $order ): array {
		if ( ! $order instanceof \WC_Abstract_Order ) {
			return $args;
		}

		return $this->get_analytics_service()->convert_order_stats_data( $args, $order );
	}

	/**
	 * Apply customer-currency request args to analytics query args.
	 *
	 * @internal
	 *
	 * @param array<string,mixed> $args Analytics args.
	 * @return array<string,mixed>
	 */
	public function handle_woocommerce_analytics_orders_query_args( array $args ): array {
		return $this->get_analytics_service()->apply_customer_currency_args( $args, $this->get_request_args() );
	}

	/**
	 * Extend the Analytics SELECT clauses with the order currency columns and the store-currency conversion.
	 *
	 * @internal
	 *
	 * @param string[] $clauses SELECT clauses.
	 * @param mixed    $context Analytics context.
	 * @return string[]
	 */
	public function handle_woocommerce_analytics_clauses_select( array $clauses, $context = null ): array {
		// The clauses stay as they are without a context, before the disable filter, and in a context that is neither
		// stats nor a subquery, after it (client 11.1.0 `includes/multi-currency/Analytics.php:233-260`).
		if ( ! is_string( $context ) || $this->is_clause_filter_disabled( self::DISABLE_SELECT_FILTER ) ) {
			return $clauses;
		}

		$context_type = explode( '_', $context )[1] ?? null;
		if ( ! in_array( $context_type, array( 'stats', 'subquery' ), true ) ) {
			return $clauses;
		}

		$clauses = $this->analytics_sql_filters->extend_select_clauses( $clauses, $context, OrderUtil::custom_orders_table_usage_is_enabled() );

		return $this->apply_clause_filter( self::EXTEND_SELECT_FILTER, $clauses );
	}

	/**
	 * Extend the Analytics JOIN clauses with the order currency and exchange-rate meta.
	 *
	 * @internal
	 *
	 * @param string[] $clauses JOIN clauses.
	 * @param mixed    $context Analytics context.
	 * @return string[]
	 */
	public function handle_woocommerce_analytics_clauses_join( array $clauses, $context = '' ): array {
		if ( $this->is_clause_filter_disabled( self::DISABLE_JOIN_FILTER ) || ! is_string( $context ) ) {
			return $clauses;
		}

		$clauses = $this->analytics_sql_filters->extend_join_clauses( $clauses, $context, OrderUtil::custom_orders_table_usage_is_enabled() );

		return $this->apply_clause_filter( self::EXTEND_JOIN_FILTER, $clauses );
	}

	/**
	 * Extend the Analytics WHERE clauses with the customer-currency filter of the request.
	 *
	 * @internal
	 *
	 * @param string[] $clauses WHERE clauses.
	 * @return string[]
	 */
	public function handle_woocommerce_analytics_clauses_where( array $clauses ): array {
		if ( $this->is_clause_filter_disabled( self::DISABLE_WHERE_FILTER ) ) {
			return $clauses;
		}

		$clauses = $this->analytics_sql_filters->extend_where_clauses( $clauses, $this->get_request_args(), OrderUtil::custom_orders_table_usage_is_enabled() );

		return $this->apply_clause_filter( self::EXTEND_WHERE_FILTER, $clauses );
	}

	/**
	 * Extend the order SELECT clauses so net totals show in each order's currency when the request selects a currency.
	 *
	 * @internal
	 *
	 * @param string[] $clauses SELECT clauses.
	 * @return string[]
	 */
	public function handle_woocommerce_analytics_clauses_select_orders( array $clauses ): array {
		// The client adds this handler only when a non-default currency is selected (client 11.1.0 `includes/multi-currency/Analytics.php:105-108`).
		if ( ! $this->has_selected_non_default_currency() || $this->is_clause_filter_disabled( self::DISABLE_ORDER_SELECT_FILTER ) ) {
			return $clauses;
		}

		$clauses = $this->analytics_sql_filters->extend_order_select_clauses( $clauses );

		return $this->apply_clause_filter( self::EXTEND_ORDER_SELECT_FILTER, $clauses );
	}

	/**
	 * Get the Analytics service.
	 *
	 * @return AnalyticsService
	 */
	private function get_analytics_service(): AnalyticsService {
		if ( null === $this->analytics_service ) {
			$this->analytics_service = $this->service_factory->create_analytics_service();
		}

		return $this->analytics_service;
	}

	/**
	 * Get request args.
	 *
	 * @return array<string,mixed>
	 */
	private function get_request_args(): array {
		return $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Tell whether the Analytics request selects a currency other than the store currency.
	 *
	 * @return bool
	 */
	private function has_selected_non_default_currency(): bool {
		$currency = $this->get_request_args()['currency'] ?? null;
		if ( ! is_scalar( $currency ) ) {
			return false;
		}

		$currency = strtoupper( trim( (string) $currency ) );

		return '' !== $currency && strtoupper( get_woocommerce_currency() ) !== $currency;
	}

	/**
	 * Tell whether an extension disabled a clause filter.
	 *
	 * @param string $hook Hook name.
	 * @return bool
	 */
	private function is_clause_filter_disabled( string $hook ): bool {
		/**
		 * Filters whether Multi-Currency leaves this kind of Analytics SQL clause unchanged.
		 *
		 * @param bool $disabled Whether the SQL clause filter should be disabled.
		 *
		 * @since 11.0.0
		 */
		return (bool) apply_filters( $hook, false );
	}

	/**
	 * Apply a WooPayments-compatible clause extension filter.
	 *
	 * @param string   $hook    Hook name.
	 * @param string[] $clauses Clauses.
	 * @return string[]
	 */
	private function apply_clause_filter( string $hook, array $clauses ): array {
		/**
		 * Filters the Analytics SQL clauses after Multi-Currency extended them.
		 *
		 * @param string[] $clauses SQL clauses.
		 *
		 * @since 11.0.0
		 */
		$filtered = apply_filters( $hook, $clauses );

		return is_array( $filtered ) ? $filtered : $clauses;
	}
}
