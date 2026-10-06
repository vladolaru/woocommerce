<?php
/**
 * MultiCurrencyBootstrap class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Container;
use Automattic\WooCommerce\Internal\DependencyManagement\RuntimeContainer;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyUsageDetector;
use Automattic\WooCommerce\Internal\MultiCurrency\Shadow\MultiCurrencyShadowMode;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Selects the independent Multi-Currency registrations for the current request.
 *
 * @since 11.2.0
 * @internal
 */
final class MultiCurrencyBootstrap {

	/**
	 * Runtime container retained for an admin-originated internal REST dispatch.
	 *
	 * @var Container|RuntimeContainer|null
	 */
	private $admin_rest_container = null;

	/**
	 * Container for the cron roots this request registers when Action Scheduler first runs an action.
	 *
	 * @var Container|RuntimeContainer|null
	 */
	private $on_demand_container = null;

	/**
	 * Container the order listeners resolve their controllers from.
	 *
	 * @var Container|RuntimeContainer|null
	 */
	private $order_container = null;

	/**
	 * Roots this request registered when WooCommerce loaded.
	 *
	 * @var array<int,class-string>
	 */
	private array $registered_roots = array();

	/**
	 * Persisted data tier, or null when this request class does not check order history at load.
	 *
	 * @var string|null
	 */
	private ?string $data_tier = null;

	/**
	 * Provider roots, resolved once per request.
	 *
	 * @var array<int,class-string>|null
	 */
	private ?array $provider_roots = null;

	/** Base lifecycle root. @var array<int,class-string> */
	private const BASE = array(
		MultiCurrencyStoreCurrencyLifecycleController::class,
	);

	/** Currency and price conversion roots. @var array<int,class-string> */
	private const PRICE = array(
		MultiCurrencyFrontendCurrenciesController::class,
		MultiCurrencyFrontendPricesController::class,
		MultiCurrencySelectedCurrencyController::class,
	);

	/** Extension compatibility roots. @var array<int,class-string> */
	private const COMPAT = array(
		MultiCurrencyCompatibilityController::class,
		MultiCurrencyBookingsCompatibilityController::class,
		MultiCurrencyDepositsCompatibilityController::class,
		MultiCurrencyPreOrdersCompatibilityController::class,
		MultiCurrencyUpsCompatibilityController::class,
		MultiCurrencyFedExCompatibilityController::class,
		MultiCurrencyPointsRewardsCompatibilityController::class,
		MultiCurrencyNameYourPriceCompatibilityController::class,
		MultiCurrencyProductAddOnsCompatibilityController::class,
		MultiCurrencySubscriptionsCompatibilityController::class,
		MultiCurrencyExplicitPriceController::class,
	);

	/** Storefront presentation roots. @var array<int,class-string> */
	private const STOREFRONT = array(
		MultiCurrencySwitcherWidgetController::class,
		MultiCurrencySwitcherBlockController::class,
		MultiCurrencyStorefrontIntegrationController::class,
		MultiCurrencyAsyncPriceRendererController::class,
	);

	/** Historical analytics roots. @var array<int,class-string> */
	private const HISTORY = array(
		MultiCurrencyAnalyticsController::class,
		MultiCurrencyTrackingController::class,
	);

	/** Settings management roots. @var array<int,class-string> */
	private const MANAGEMENT = array(
		MultiCurrencySettingsController::class,
		MultiCurrencyRestController::class,
	);

	/** Admin-only roots. @var array<int,class-string> */
	private const ADMIN = array(
		MultiCurrencyAdminNoticesController::class,
		MultiCurrencyAdminNoteController::class,
	);

	/**
	 * Provider-owned Multi-Currency roots resolver.
	 *
	 * @var callable
	 * @phpstan-var callable(): array<int,class-string>
	 */
	private $provider_roots_resolver;

	/**
	 * Initialize provider-neutral Multi-Currency composition.
	 *
	 * @since 11.2.0
	 *
	 * @param callable $provider_roots_resolver Provider-owned roots resolver.
	 * @phpstan-param callable(): array<int,class-string> $provider_roots_resolver
	 */
	public function __construct( callable $provider_roots_resolver ) {
		$this->provider_roots_resolver = $provider_roots_resolver;
	}

	/**
	 * Register Multi-Currency for its current runtime owner.
	 *
	 * @since 11.2.0
	 *
	 * @param Container|RuntimeContainer $container           Runtime dependency container.
	 * @param callable                   $is_rest_api_request Whether the current request is a REST request.
	 */
	public function register( $container, callable $is_rest_api_request ): void {
		$arbiter = $container->get( MultiCurrencyRuntimeArbiter::class );
		$owner   = $arbiter->get_runtime_owner();
		if ( MultiCurrencyRuntimeArbiter::OWNER_NONE === $owner ) {
			return;
		}

		if ( MultiCurrencyRuntimeArbiter::OWNER_PLUGIN === $owner ) {
			/**
			 * Filters whether read-only native Multi-Currency shadow mode is enabled.
			 *
			 * @since 11.0.0
			 *
			 * @param bool $enabled Whether shadow mode is enabled. Default false.
			 */
			if ( ! apply_filters( MultiCurrencyShadowMode::FILTER_SHADOW_ENABLED, false ) ) {
				return;
			}

			$this->register_roots( $container, array_merge( $this->get_provider_roots(), array( MultiCurrencyShadowMode::class ) ) );
			return;
		}

		$this->order_container = $container;
		// The client copies the order's exchange rates to refunds, records the customer currency and converts Analytics order stats
		// on every request (client 11.1.0 `includes/multi-currency/MultiCurrency.php:371`, `:384`, `Analytics.php:79-80`), so an
		// order created or imported on any request is handled, including a synchronous Analytics import on ?wc-ajax=checkout.
		if ( false === has_action( 'woocommerce_order_refunded', array( $this, 'handle_woocommerce_order_refunded' ) ) ) {
			add_action( 'woocommerce_order_refunded', array( $this, 'handle_woocommerce_order_refunded' ), 50, 2 );
			add_action( 'woocommerce_order_status_changed', array( $this, 'handle_woocommerce_order_status_changed' ) );
			add_filter( 'woocommerce_analytics_update_order_stats_data', array( $this, 'handle_woocommerce_analytics_update_order_stats_data' ), 99999, 2 );
		}

		$request = self::classify_request( $is_rest_api_request );
		$roots   = $this->get_core_roots( $container, $request );
		if ( ! empty( $roots ) ) {
			if ( 'admin' === $request ) {
				$this->admin_rest_container = $container;
				if ( false === has_action( 'rest_api_init', array( $this, 'register_admin_rest_controller' ) ) ) {
					add_action( 'rest_api_init', array( $this, 'register_admin_rest_controller' ), 0 );
				}
			}

			if ( array( MultiCurrencyExplicitPriceController::class ) !== $roots ) {
				$roots = array_merge( $this->get_provider_roots(), $roots );
			}

			$this->register_roots( $container, $roots );
		}

		$this->register_cron_roots_on_demand( $container, $request, $roots );
	}

	/**
	 * Register the cron roots this request lacks once Action Scheduler runs an action in it.
	 *
	 * ALTERNATE_WP_CRON and WP-CLI run actions, such as the Analytics import and the tracker, inside a front or CLI request.
	 * Admin and REST requests already register every cron root, so the Tools > Scheduled Actions "Run" link never needs this listener.
	 * The client registers Analytics and Tracking on every request (client 11.1.0 `includes/multi-currency/MultiCurrency.php:340,344`).
	 *
	 * @param Container|RuntimeContainer $container  Runtime dependency container.
	 * @param string                     $request    Request class.
	 * @param array<int,class-string>    $registered Roots registered for this request.
	 */
	private function register_cron_roots_on_demand( $container, string $request, array $registered ): void {
		if ( 'cron' === $request ) {
			return;
		}

		// A request that did not check order history at load leaves that check to the listener.
		if ( null !== $this->data_tier && empty( array_diff( $this->get_roots_for_tier( $this->data_tier, 'cron' ), $registered ) ) ) {
			return;
		}

		$this->on_demand_container = $container;
		$this->registered_roots    = $registered;
		// Action Scheduler fires this before it checks the action has callbacks and runs it (`ActionScheduler_Abstract_QueueRunner::process_action()`).
		add_action( 'action_scheduler_before_execute', array( $this, 'handle_action_scheduler_before_execute' ), 0 );
	}

	/**
	 * Register the missing cron roots before Action Scheduler runs the first action of this request.
	 *
	 * @internal
	 */
	public function handle_action_scheduler_before_execute(): void {
		remove_action( 'action_scheduler_before_execute', array( $this, 'handle_action_scheduler_before_execute' ), 0 );
		$container                 = $this->on_demand_container;
		$this->on_demand_container = null;
		if ( null === $container ) {
			return;
		}

		$tier = $this->data_tier ?? $this->get_history_tier( $container->get( MultiCurrencyUsageDetector::class ) );
		$cron = $this->get_roots_for_tier( $tier, 'cron' );
		if ( empty( $cron ) ) {
			return;
		}

		$this->register_roots( $container, array_values( array_diff( array_merge( $this->get_provider_roots(), $cron ), $this->registered_roots ) ) );
	}

	/**
	 * Copy the order's exchange-rate meta to a new refund.
	 *
	 * @internal
	 *
	 * @param mixed $order_id  Order ID.
	 * @param mixed $refund_id Refund ID.
	 */
	public function handle_woocommerce_order_refunded( $order_id, $refund_id ): void {
		if ( null === $this->order_container ) {
			return;
		}

		/**
		 * Native Multi-Currency price controller.
		 *
		 * @var MultiCurrencyFrontendPricesController $controller
		 */
		$controller = $this->order_container->get( MultiCurrencyFrontendPricesController::class );
		$controller->add_refund_meta( $order_id, $refund_id );
	}

	/**
	 * Add the order's currency to the currencies customers have used.
	 *
	 * @internal
	 *
	 * @param mixed $order_id Order ID.
	 */
	public function handle_woocommerce_order_status_changed( $order_id ): void {
		if ( null === $this->order_container ) {
			return;
		}

		/**
		 * Native Multi-Currency Analytics controller.
		 *
		 * @var MultiCurrencyAnalyticsController $controller
		 */
		$controller = $this->order_container->get( MultiCurrencyAnalyticsController::class );
		$controller->record_customer_currency( $order_id );
	}

	/**
	 * Convert an Analytics order-stats row to the store currency.
	 *
	 * @internal
	 *
	 * @param mixed $args  Order stats row.
	 * @param mixed $order Order or refund.
	 * @return mixed
	 */
	public function handle_woocommerce_analytics_update_order_stats_data( $args, $order ) {
		if ( null === $this->order_container || ! is_array( $args ) ) {
			return $args;
		}

		/**
		 * Native Multi-Currency Analytics controller.
		 *
		 * @var MultiCurrencyAnalyticsController $controller
		 */
		$controller = $this->order_container->get( MultiCurrencyAnalyticsController::class );

		return $controller->convert_order_stats_data( $args, $order );
	}

	/**
	 * Get the provider roots, resolving them once per request.
	 *
	 * @return array<int,class-string> Provider root class names.
	 */
	private function get_provider_roots(): array {
		if ( null === $this->provider_roots ) {
			$this->provider_roots = ( $this->provider_roots_resolver )();
		}

		return $this->provider_roots;
	}

	/**
	 * Register the native Multi-Currency REST controller during an internal dispatch from an admin page.
	 *
	 * @since 11.2.0
	 */
	public function register_admin_rest_controller(): void {
		if ( null === $this->admin_rest_container ) {
			return;
		}

		/**
		 * Native Multi-Currency REST controller.
		 *
		 * @var MultiCurrencyRestController $controller
		 */
		$controller = $this->admin_rest_container->get( MultiCurrencyRestController::class );
		$controller->register();
	}

	/**
	 * Get core-owned roots for the persisted data and current request.
	 *
	 * @param Container|RuntimeContainer $container Runtime dependency container.
	 * @param string                     $request   Request class.
	 * @return array<int,class-string> Root class names in registration order.
	 */
	private function get_core_roots( $container, string $request ): array {
		/** Resolve persisted Multi-Currency usage. @var MultiCurrencyUsageDetector $usage_detector */
		$usage_detector = $container->get( MultiCurrencyUsageDetector::class );
		if ( $usage_detector->has_additional_enabled_currencies() ) {
			$this->data_tier = 'configured';
			return $this->get_roots_for_tier( 'configured', $request );
		}

		// The explicit-price filter can be added after WooCommerce loads, so its controller registers wherever prices may render and
		// the filter is read at render, as the client does (client 11.1.0 `includes/class-wc-payments-explicit-price-formatter.php:55-73`).
		if ( in_array( $request, array( 'front', 'ajax', 'cli' ), true ) ) {
			return array( MultiCurrencyExplicitPriceController::class );
		}

		$this->data_tier = $this->get_history_tier( $usage_detector );

		return array_merge( $this->get_roots_for_tier( $this->data_tier, $request ), array( MultiCurrencyExplicitPriceController::class ) );
	}

	/**
	 * Get the data tier of a store without additional currencies from its order history.
	 *
	 * @param MultiCurrencyUsageDetector $usage_detector Persisted usage detector.
	 * @return string Persisted data tier: historical or empty.
	 */
	private function get_history_tier( $usage_detector ): string {
		try {
			return $usage_detector->has_foreign_currency_orders() ? 'historical' : 'empty';
		} catch ( \RuntimeException $error ) {
			// Preserve recovery services when order history cannot be determined.
			return 'historical';
		}
	}

	/**
	 * Classify the current request without resolving another service. The native payments bootstrap uses it too.
	 *
	 * @since 11.2.0
	 * @internal
	 *
	 * @param callable $is_rest_api_request Whether the current request is a REST request.
	 * @return string Request class: cli, cron, ajax, rest, admin or front.
	 */
	public static function classify_request( callable $is_rest_api_request ): string {
		return self::classify_signals(
			Constants::is_true( 'WP_CLI' ),
			wp_doing_cron() || wc_is_running_from_async_action_scheduler(),
			wp_doing_ajax(),
			(bool) $is_rest_api_request(),
			is_admin()
		);
	}

	/**
	 * Select a request class from early-safe signals in precedence order.
	 *
	 * @param bool $is_cli   Whether WP-CLI is running.
	 * @param bool $is_cron  Whether cron or Action Scheduler is running.
	 * @param bool $is_ajax  Whether WordPress AJAX is running.
	 * @param bool $is_rest  Whether this is a REST request.
	 * @param bool $is_admin Whether this is an admin request.
	 * @return string Request class.
	 */
	private static function classify_signals( bool $is_cli, bool $is_cron, bool $is_ajax, bool $is_rest, bool $is_admin ): string {
		if ( $is_cli ) {
			return 'cli';
		}
		if ( $is_cron ) {
			return 'cron';
		}
		if ( $is_ajax ) {
			return 'ajax';
		}
		if ( $is_rest ) {
			return 'rest';
		}

		return $is_admin ? 'admin' : 'front';
	}

	/**
	 * Get roots for one data tier and request class.
	 *
	 * @param string $tier    Persisted data tier.
	 * @param string $request Request class.
	 * @return array<int,class-string> Root class names in registration order.
	 */
	private function get_roots_for_tier( string $tier, string $request ): array {
		$matrix = array(
			'configured' => array(
				'front' => array_merge( self::BASE, self::PRICE, self::COMPAT, self::STOREFRONT ),
				'ajax'  => array_merge( self::BASE, self::PRICE, self::COMPAT, array( MultiCurrencyStorefrontIntegrationController::class, MultiCurrencyAsyncPriceRendererController::class ), self::HISTORY ),
				'rest'  => array_merge( self::BASE, self::PRICE, self::COMPAT, array( MultiCurrencySwitcherBlockController::class, MultiCurrencyStorefrontIntegrationController::class, MultiCurrencyAsyncPriceRendererController::class ), self::HISTORY, array( self::MANAGEMENT[1], MultiCurrencyRestRequestOverrideController::class ) ),
				'admin' => array_merge( self::BASE, self::PRICE, self::COMPAT, array( MultiCurrencyAnalyticsController::class, MultiCurrencySwitcherBlockController::class, self::MANAGEMENT[0], MultiCurrencyTrackingController::class ), self::ADMIN ),
				'cron'  => array_merge( self::BASE, array( MultiCurrencyFrontendPricesController::class ), self::COMPAT, self::HISTORY ),
				'cli'   => array_merge( self::BASE, array( MultiCurrencyFrontendPricesController::class ), self::COMPAT, self::HISTORY ),
			),
			'historical' => array(
				'admin' => array_merge( self::BASE, array( MultiCurrencyAnalyticsController::class, self::MANAGEMENT[0], MultiCurrencyTrackingController::class ), self::ADMIN ),
				'rest'  => array_merge( self::BASE, array( MultiCurrencyAnalyticsController::class, self::MANAGEMENT[1], MultiCurrencyRestRequestOverrideController::class, MultiCurrencyTrackingController::class ) ),
				'cron'  => array_merge( self::BASE, self::HISTORY ),
			),
			'empty'      => array(
				// The availability note targets stores without currencies yet (client 11.1.0 `MultiCurrency.php:380,1135-1145`).
				'admin' => array( self::MANAGEMENT[0], MultiCurrencyAdminNoteController::class ),
				'rest'  => array( self::MANAGEMENT[1] ),
			),
		);

		return $matrix[ $tier ][ $request ] ?? array();
	}

	/**
	 * Resolve and register explicit roots once, preserving provider-before-core order.
	 *
	 * @param Container|RuntimeContainer $container Runtime dependency container.
	 * @param array<int,class-string>    $roots     Root class names.
	 */
	private function register_roots( $container, array $roots ): void {
		foreach ( array_unique( $roots ) as $root ) {
			$this->register_root( $container, $root );
		}
	}

	/**
	 * Resolve and register one explicit Multi-Currency root.
	 *
	 * @param Container|RuntimeContainer $container Runtime dependency container.
	 * @param class-string               $root      Root class name.
	 */
	private function register_root( $container, string $root ): void {
		/**
		 * Resolve the explicit registrar.
		 *
		 * @var RegisterHooksInterface $registrar
		 */
		$registrar = $container->get( $root );
		$registrar->register();
	}
}
