<?php
/**
 * MultiCurrencyBootstrap class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

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

			$this->register_roots( $container, array_merge( ( $this->provider_roots_resolver )(), array( MultiCurrencyShadowMode::class ) ) );
			return;
		}

		$roots = $this->get_core_roots( $container, $this->classify_request( $is_rest_api_request ) );
		if ( empty( $roots ) ) {
			return;
		}

		$this->register_roots( $container, array_merge( ( $this->provider_roots_resolver )(), $roots ) );
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
			return $this->get_roots_for_tier( 'configured', $request );
		}

		if ( in_array( $request, array( 'front', 'ajax', 'cli' ), true ) ) {
			return array();
		}

		return $this->get_roots_for_tier( $usage_detector->has_foreign_currency_orders() ? 'historical' : 'empty', $request );
	}

	/**
	 * Classify the current request without resolving another service.
	 *
	 * @param callable $is_rest_api_request Whether the current request is a REST request.
	 * @return string Request class.
	 */
	private function classify_request( callable $is_rest_api_request ): string {
		return self::classify_signals(
			defined( 'WP_CLI' ) && WP_CLI,
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
				'rest'  => array_merge( self::BASE, self::PRICE, self::COMPAT, array( MultiCurrencyStorefrontIntegrationController::class, MultiCurrencyAsyncPriceRendererController::class ), self::HISTORY, array( self::MANAGEMENT[1], MultiCurrencyRestRequestOverrideController::class ) ),
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
				'admin' => array( self::MANAGEMENT[0] ),
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
