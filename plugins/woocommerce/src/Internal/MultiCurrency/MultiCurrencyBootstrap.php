<?php
/**
 * MultiCurrencyBootstrap class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Container;
use Automattic\WooCommerce\Internal\DependencyManagement\RuntimeContainer;
use Automattic\WooCommerce\Internal\Features\FeaturesController;
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
	 * Container for the cron classes this request registers when Action Scheduler first runs an action.
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
	 * Classes this request registered when WooCommerce loaded.
	 *
	 * @var array<int,class-string>
	 */
	private array $registered_classes = array();

	/**
	 * How far the store uses Multi-Currency (configured, historical or empty), or null when this request type does not
	 * check order history at load.
	 *
	 * @var string|null
	 */
	private ?string $usage = null;

	/**
	 * Provider classes, resolved once per request.
	 *
	 * @var array<int,class-string>|null
	 */
	private ?array $provider_classes = null;

	/** Base lifecycle class. @var array<int,class-string> */
	private const BASE = array(
		MultiCurrencyStoreCurrencyLifecycleController::class,
	);

	/** Currency and price conversion classes. @var array<int,class-string> */
	private const PRICE = array(
		MultiCurrencyFrontendCurrenciesController::class,
		MultiCurrencyFrontendPricesController::class,
		MultiCurrencySelectedCurrencyController::class,
	);

	/** Extension compatibility classes. @var array<int,class-string> */
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

	/** Storefront presentation classes. @var array<int,class-string> */
	private const STOREFRONT = array(
		MultiCurrencySwitcherWidgetController::class,
		MultiCurrencySwitcherBlockController::class,
		MultiCurrencyStorefrontIntegrationController::class,
		MultiCurrencyAsyncPriceRendererController::class,
	);

	/** Historical analytics classes. @var array<int,class-string> */
	private const HISTORY = array(
		MultiCurrencyAnalyticsController::class,
		MultiCurrencyTrackingController::class,
	);

	/** Settings management classes. @var array<int,class-string> */
	private const MANAGEMENT = array(
		MultiCurrencySettingsController::class,
		MultiCurrencyRestController::class,
	);

	/** Admin-only classes. @var array<int,class-string> */
	private const ADMIN = array(
		MultiCurrencyAdminNoticesController::class,
		MultiCurrencyAdminNoteController::class,
	);

	/**
	 * Lists the provider's Multi-Currency classes.
	 *
	 * @var callable
	 * @phpstan-var callable(): array<int,class-string>
	 */
	private $provider_classes_resolver;

	/**
	 * Initialize provider-neutral Multi-Currency composition.
	 *
	 * @since 11.2.0
	 *
	 * @param callable $provider_classes_resolver Lists the provider's Multi-Currency classes.
	 * @phpstan-param callable(): array<int,class-string> $provider_classes_resolver
	 */
	public function __construct( callable $provider_classes_resolver ) {
		$this->provider_classes_resolver = $provider_classes_resolver;
	}

	/**
	 * Register Multi-Currency for its current runtime owner.
	 *
	 * @since 11.2.0
	 *
	 * @param Container|RuntimeContainer $container    Runtime dependency container.
	 * @param string                     $request_type The request type, one of the RequestTypeUtil constants.
	 */
	public function register( $container, string $request_type ): void {
		$arbiter = $container->get( MultiCurrencyRuntimeArbiter::class );
		// Before the arbiter reads the feature option, so the first request after the extension stops owning payments runs on the handed-over value.
		MultiCurrencyFeatureController::track_payments_owner( $arbiter->is_payments_extension_owner(), $arbiter->is_payments_builtin_owner() );
		// Whenever the Features page decides Multi-Currency, also while it is off, so an enable reaches the extension's flag too.
		if ( ! $arbiter->is_payments_extension_owner() && false === has_action( FeaturesController::FEATURE_ENABLED_CHANGED_ACTION, array( MultiCurrencyFeatureController::class, 'mirror_extension_flag' ) ) ) {
			add_action( FeaturesController::FEATURE_ENABLED_CHANGED_ACTION, array( MultiCurrencyFeatureController::class, 'mirror_extension_flag' ), 10, 2 );
		}
		$owner = $arbiter->get_runtime_owner();
		if ( MultiCurrencyRuntimeArbiter::OWNER_NONE === $owner ) {
			return;
		}

		if ( MultiCurrencyRuntimeArbiter::OWNER_EXTENSION === $owner ) {
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

			$this->register_classes( $container, array_merge( $this->get_provider_classes(), array( MultiCurrencyShadowMode::class ) ) );
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

		$classes = $this->get_module_classes_for_request( $container, $request_type );
		if ( ! empty( $classes ) ) {
			if ( 'admin' === $request_type ) {
				$this->admin_rest_container = $container;
				if ( false === has_action( 'rest_api_init', array( $this, 'register_admin_rest_controller' ) ) ) {
					add_action( 'rest_api_init', array( $this, 'register_admin_rest_controller' ), 0 );
				}
			}

			if ( array( MultiCurrencyExplicitPriceController::class ) !== $classes ) {
				$classes = array_merge( $this->get_provider_classes(), $classes );
			}

			$this->register_classes( $container, $classes );
		}

		$this->register_cron_classes_on_demand( $container, $request_type, $classes );
	}

	/**
	 * Register the cron classes this request lacks once Action Scheduler runs an action in it.
	 *
	 * ALTERNATE_WP_CRON and WP-CLI run actions, such as the Analytics import and the tracker, inside a front or CLI request.
	 * Admin and REST requests already register every cron class, so the Tools > Scheduled Actions "Run" link never needs this listener.
	 * The client registers Analytics and Tracking on every request (client 11.1.0 `includes/multi-currency/MultiCurrency.php:340,344`).
	 *
	 * @param Container|RuntimeContainer $container  Runtime dependency container.
	 * @param string                     $request_type Request type.
	 * @param array<int,class-string>    $registered   Classes registered for this request.
	 */
	private function register_cron_classes_on_demand( $container, string $request_type, array $registered ): void {
		if ( 'cron' === $request_type ) {
			return;
		}

		// A request that did not check order history at load leaves that check to the listener.
		if ( null !== $this->usage && empty( array_diff( $this->get_classes_for_usage( $this->usage, 'cron' ), $registered ) ) ) {
			return;
		}

		$this->on_demand_container = $container;
		$this->registered_classes  = $registered;
		// Action Scheduler fires this before it checks the action has callbacks and runs it (`ActionScheduler_Abstract_QueueRunner::process_action()`).
		add_action( 'action_scheduler_before_execute', array( $this, 'handle_action_scheduler_before_execute' ), 0 );
	}

	/**
	 * Register the missing cron classes before Action Scheduler runs the first action of this request.
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

		$usage = $this->usage ?? $this->get_usage_from_order_history( $container->get( MultiCurrencyUsageDetector::class ) );
		$cron  = $this->get_classes_for_usage( $usage, 'cron' );
		if ( empty( $cron ) ) {
			return;
		}

		$this->register_classes( $container, array_values( array_diff( array_merge( $this->get_provider_classes(), $cron ), $this->registered_classes ) ) );
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
	 * Get the provider's Multi-Currency classes, resolving them once per request.
	 *
	 * @return array<int,class-string> Provider class names.
	 */
	private function get_provider_classes(): array {
		if ( null === $this->provider_classes ) {
			$this->provider_classes = ( $this->provider_classes_resolver )();
		}

		return $this->provider_classes;
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
	 * Get the module's classes for the current request type, and record how far the store uses Multi-Currency, which the
	 * on-demand cron registration reads.
	 *
	 * @param Container|RuntimeContainer $container    Runtime dependency container.
	 * @param string                     $request_type Request type.
	 * @return array<int,class-string> Class names in registration order.
	 */
	private function get_module_classes_for_request( $container, string $request_type ): array {
		/** Resolve persisted Multi-Currency usage. @var MultiCurrencyUsageDetector $usage_detector */
		$usage_detector = $container->get( MultiCurrencyUsageDetector::class );
		if ( $usage_detector->has_additional_enabled_currencies() ) {
			$this->usage = 'configured';
			return $this->get_classes_for_usage( 'configured', $request_type );
		}

		// The explicit-price filter can be added after WooCommerce loads, so its controller registers wherever prices may render and
		// the filter is read at render, as the client does (client 11.1.0 `includes/class-wc-payments-explicit-price-formatter.php:55-73`).
		if ( in_array( $request_type, array( 'front', 'ajax', 'cli' ), true ) ) {
			return array( MultiCurrencyExplicitPriceController::class );
		}

		$this->usage = $this->get_usage_from_order_history( $usage_detector );

		return array_merge( $this->get_classes_for_usage( $this->usage, $request_type ), array( MultiCurrencyExplicitPriceController::class ) );
	}

	/**
	 * Get how far a store without additional currencies uses Multi-Currency, from its order history.
	 *
	 * @param MultiCurrencyUsageDetector $usage_detector Persisted usage detector.
	 * @return string Usage: historical or empty.
	 */
	private function get_usage_from_order_history( $usage_detector ): string {
		try {
			return $usage_detector->has_foreign_currency_orders() ? 'historical' : 'empty';
		} catch ( \RuntimeException $error ) {
			// Preserve recovery services when order history cannot be determined.
			return 'historical';
		}
	}

	/**
	 * Get the module's classes for one usage and request type.
	 *
	 * @param string $usage        How far the store uses Multi-Currency: configured, historical or empty.
	 * @param string $request_type Request type.
	 * @return array<int,class-string> Class names in registration order.
	 */
	private function get_classes_for_usage( string $usage, string $request_type ): array {
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

		return $matrix[ $usage ][ $request_type ] ?? array();
	}

	/**
	 * Resolve and register each listed class once, keeping the provider's classes ahead of the module's.
	 *
	 * @param Container|RuntimeContainer $container Runtime dependency container.
	 * @param array<int,class-string>    $classes   Class names in registration order.
	 */
	private function register_classes( $container, array $classes ): void {
		foreach ( array_unique( $classes ) as $class_name ) {
			/**
			 * Registrar.
			 *
			 * @var RegisterHooksInterface $registrar
			 */
			$registrar = $container->get( $class_name );
			$registrar->register();
		}
	}
}
