<?php
/**
 * MultiCurrencyBootstrap tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\DependencyManagement\RuntimeContainer;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyBootstrap;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\Shadow\MultiCurrencyShadowMode;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsMultiCurrencyProviderBootstrap;
use WC_Unit_Test_Case;

/**
 * Tests for MultiCurrencyBootstrap.
 */
class MultiCurrencyBootstrapTest extends WC_Unit_Test_Case {

	/**
	 * Ordered core-owned roots copied from the approved composition inventory.
	 *
	 * @var array<int,string>
	 */
	private const CORE_ROOTS = array(
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyStoreCurrencyLifecycleController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyFrontendCurrenciesController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyFrontendPricesController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencySelectedCurrencyController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyAnalyticsController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyCompatibilityController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyBookingsCompatibilityController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyDepositsCompatibilityController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyPreOrdersCompatibilityController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyUpsCompatibilityController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyFedExCompatibilityController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyPointsRewardsCompatibilityController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyNameYourPriceCompatibilityController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyProductAddOnsCompatibilityController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencySubscriptionsCompatibilityController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyExplicitPriceController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencySwitcherWidgetController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencySwitcherBlockController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencySettingsController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyStorefrontIntegrationController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyAsyncPriceRendererController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyRestController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyRestRequestOverrideController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyTrackingController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyAdminNoticesController',
		'Automattic\\WooCommerce\\Internal\\MultiCurrency\\MultiCurrencyAdminNoteController',
	);

	/**
	 * @testdox Should resolve no module roots when no multi-currency runtime owns the site.
	 */
	public function test_owner_none_resolves_no_module_roots(): void {
		$container = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_NONE );
		$sut       = new MultiCurrencyBootstrap();

		$sut->register( $container );

		$this->assertSame( array( MultiCurrencyRuntimeArbiter::class ), $container->resolved, 'Only the ownership decision should be resolved.' );
	}

	/**
	 * @testdox Should configure the provider before registering every core-owned root once.
	 */
	public function test_core_owner_registers_provider_before_all_core_roots_once(): void {
		$container = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_CORE );
		$sut       = new MultiCurrencyBootstrap();

		$sut->register( $container );

		$expected = array( 'get:' . MultiCurrencyRuntimeArbiter::class );
		foreach ( array_merge( array( WooPaymentsMultiCurrencyProviderBootstrap::class ), self::CORE_ROOTS ) as $root ) {
			$expected[] = 'get:' . $root;
			$expected[] = 'register:' . $root;
		}

		$this->assertSame( $expected, $container->events, 'Provider setup should precede every core-owned registrar in inventory order.' );
		$this->assertSame( array_values( array_unique( $container->resolved ) ), $container->resolved, 'Each explicit root should be resolved once.' );
	}

	/**
	 * @testdox Should resolve no plugin-owned support roots when Multi-Currency shadow mode is disabled.
	 */
	public function test_plugin_owner_with_default_shadow_resolves_no_support_roots(): void {
		$container = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_PLUGIN );
		$sut       = new MultiCurrencyBootstrap();

		$sut->register( $container );

		$this->assertSame( array( MultiCurrencyRuntimeArbiter::class ), $container->resolved, 'Plugin-owned Multi-Currency should add no default Core cost.' );
	}

	/**
	 * @testdox Should configure the plugin provider before the explicitly enabled Multi-Currency shadow root.
	 */
	public function test_plugin_owner_registers_only_explicitly_enabled_shadow_after_provider(): void {
		add_filter( MultiCurrencyShadowMode::FILTER_SHADOW_ENABLED, '__return_true' );
		$container = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_PLUGIN );
		$sut       = new MultiCurrencyBootstrap();

		$sut->register( $container );

		$this->assertSame(
			array(
				'get:' . MultiCurrencyRuntimeArbiter::class,
				'get:' . WooPaymentsMultiCurrencyProviderBootstrap::class,
				'register:' . WooPaymentsMultiCurrencyProviderBootstrap::class,
				'get:' . MultiCurrencyShadowMode::class,
				'register:' . MultiCurrencyShadowMode::class,
			),
			$container->events,
			'Plugin-owned shadow mode should configure its provider boundary before registering the shadow root.'
		);
	}

	/**
	 * Build a container that records explicit resolution and registration order.
	 *
	 * @param string $owner Multi-Currency owner.
	 * @return RuntimeContainer&object{resolved:array<int,string>,events:array<int,string>}
	 */
	private function make_container( string $owner ): RuntimeContainer {
		$arbiter = new class( $owner ) extends MultiCurrencyRuntimeArbiter {
			/** @var string */
			private $owner;

			/**
			 * Set the owner returned by the fake.
			 *
			 * @param string $owner Multi-Currency owner.
			 */
			public function __construct( string $owner ) {
				$this->owner = $owner;
			}

			/** Return the configured owner. */
			public function get_runtime_owner(): string {
				return $this->owner;
			}
		};

		return new class( $arbiter ) extends RuntimeContainer {
			/** @var array<int,string> */
			public array $resolved = array();

			/** @var array<int,string> */
			public array $events = array();

			/** @var MultiCurrencyRuntimeArbiter */
			private $arbiter;

			/** @var array<string,object> */
			private array $registrars = array();

			/**
			 * Initialize the recording container.
			 *
			 * @param MultiCurrencyRuntimeArbiter $arbiter Runtime owner arbiter.
			 */
			public function __construct( MultiCurrencyRuntimeArbiter $arbiter ) {
				parent::__construct( array() );
				$this->arbiter = $arbiter;
			}

			/**
			 * Resolve a recording registrar for every explicit root.
			 *
			 * @param string $class_name Class name.
			 * @return object Recording registrar.
			 */
			public function get( string $class_name ) {
				$this->resolved[] = $class_name;
				$this->events[]   = 'get:' . $class_name;
				if ( MultiCurrencyRuntimeArbiter::class === $class_name ) {
					return $this->arbiter;
				}

				if ( ! isset( $this->registrars[ $class_name ] ) ) {
					$events                          = &$this->events;
					$this->registrars[ $class_name ] = new class( $class_name, $events ) {
						/** @var string */
						private $class_name;

						/** @var array<int,string> */
						private $events;

						/**
						 * Initialize the registrar recorder.
						 *
						 * @param string            $class_name Class name.
						 * @param array<int,string> $events     Recorded events.
						 */
						public function __construct( string $class_name, array &$events ) {
							$this->class_name = $class_name;
							$this->events     = &$events;
						}

						/** Record registration. */
						public function register(): void {
							$this->events[] = 'register:' . $this->class_name;
						}
					};
				}

				return $this->registrars[ $class_name ];
			}
		};
	}
}
