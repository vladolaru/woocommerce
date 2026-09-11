<?php
/**
 * MultiCurrencyBootstrap class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Container;
use Automattic\WooCommerce\Internal\DependencyManagement\RuntimeContainer;
use Automattic\WooCommerce\Internal\MultiCurrency\Shadow\MultiCurrencyShadowMode;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsMultiCurrencyProviderBootstrap;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Selects the independent Multi-Currency registrations for the current request.
 *
 * @since 11.2.0
 * @internal
 */
final class MultiCurrencyBootstrap {

	/** Core-owned Multi-Currency roots in registration order. */
	private const CORE_ROOTS = array(
		MultiCurrencyStoreCurrencyLifecycleController::class,
		MultiCurrencyFrontendCurrenciesController::class,
		MultiCurrencyFrontendPricesController::class,
		MultiCurrencySelectedCurrencyController::class,
		MultiCurrencyAnalyticsController::class,
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
		MultiCurrencySwitcherWidgetController::class,
		MultiCurrencySwitcherBlockController::class,
		MultiCurrencySettingsController::class,
		MultiCurrencyStorefrontIntegrationController::class,
		MultiCurrencyAsyncPriceRendererController::class,
		MultiCurrencyRestController::class,
		MultiCurrencyRestRequestOverrideController::class,
		MultiCurrencyTrackingController::class,
		MultiCurrencyAdminNoticesController::class,
		MultiCurrencyAdminNoteController::class,
	);

	/**
	 * Register Multi-Currency for its current runtime owner.
	 *
	 * @since 11.2.0
	 *
	 * @param Container|RuntimeContainer $container Runtime dependency container.
	 */
	public function register( $container ): void {
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

			$this->register_root( $container, WooPaymentsMultiCurrencyProviderBootstrap::class );
			$this->register_root( $container, MultiCurrencyShadowMode::class );
			return;
		}

		$this->register_root( $container, WooPaymentsMultiCurrencyProviderBootstrap::class );
		foreach ( self::CORE_ROOTS as $root ) {
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
		 * Explicit registrar.
		 *
		 * @var RegisterHooksInterface $registrar
		 */
		$registrar = $container->get( $root );
		$registrar->register();
	}
}
