<?php
/**
 * MultiCurrencyBootstrap tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\DependencyManagement\RuntimeContainer;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyBootstrap;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyUsageDetector;
use Automattic\WooCommerce\Internal\MultiCurrency\Shadow\MultiCurrencyShadowMode;
use WC_Unit_Test_Case;

/** Tests for MultiCurrencyBootstrap. */
class MultiCurrencyBootstrapTest extends WC_Unit_Test_Case {

	/** All roots formerly registered by the core-owned bootstrap. */
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

	/** @testdox Should retain core roots when no provider roots are configured. */
	public function test_empty_provider_resolver_keeps_core_registration_providerless(): void {
		$container = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_CORE, true, false );
		$sut       = new MultiCurrencyBootstrap( static fn(): array => array() );

		$sut->register( $container, '__return_false' );

		$this->assertSame( self::core_root_matrix()['configured front'][3], $container->registered );
	}

	/** @testdox Should resolve only the ownership arbiter when the runtime has no owner. */
	public function test_owner_none_resolves_only_the_arbiter(): void {
		$container      = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_NONE, false, false );
		$resolver_calls = 0;
		$sut            = new MultiCurrencyBootstrap(
			static function () use ( &$resolver_calls ): array {
				++$resolver_calls;
				return array( 'ProviderRoot' );
			}
		);

		$sut->register( $container, '__return_false' );

		$this->assertSame( array( MultiCurrencyRuntimeArbiter::class ), $container->resolved );
		$this->assertSame( 0, $resolver_calls );
	}

	/** @testdox Should order and de-duplicate provider roots before core roots. */
	public function test_core_registration_orders_and_deduplicates_provider_roots_before_core_roots(): void {
		$provider_roots = array( 'ProviderRoot', self::CORE_ROOTS[0], 'ProviderRoot' );
		$container      = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_CORE, true, false );
		$resolver_calls = 0;
		$sut            = new MultiCurrencyBootstrap(
			static function () use ( &$provider_roots, &$resolver_calls ): array {
				++$resolver_calls;
				return $provider_roots;
			}
		);

		$sut->register( $container, '__return_false' );

		$this->assertSame( array_merge( array( 'ProviderRoot' ), self::core_root_matrix()['configured front'][3] ), $container->registered );
		$this->assertSame( array_values( array_unique( $container->registered ) ), $container->registered );
		$this->assertSame( 1, $resolver_calls );
	}

	/** @testdox Should order provider roots before an enabled plugin shadow root. */
	public function test_plugin_shadow_registers_provider_roots_before_shadow(): void {
		add_filter( MultiCurrencyShadowMode::FILTER_SHADOW_ENABLED, '__return_true' );
		$container      = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_PLUGIN, false, false );
		$resolver_calls = 0;
		$sut            = new MultiCurrencyBootstrap(
			static function () use ( &$resolver_calls ): array {
				++$resolver_calls;
				return array( 'ProviderRoot' );
			}
		);

		$sut->register( $container, '__return_false' );

		$this->assertSame( array( 'ProviderRoot', MultiCurrencyShadowMode::class ), $container->registered );
		$this->assertSame( 1, $resolver_calls );
	}

	/** @testdox Should not resolve provider roots when plugin shadow mode is disabled. */
	public function test_plugin_owner_with_disabled_shadow_does_not_resolve_provider_roots(): void {
		$container      = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_PLUGIN, false, false );
		$resolver_calls = 0;
		$sut            = new MultiCurrencyBootstrap(
			static function () use ( &$resolver_calls ): array {
				++$resolver_calls;
				return array( 'ProviderRoot' );
			}
		);

		$sut->register( $container, '__return_false' );

		$this->assertSame( 0, $resolver_calls );
		$this->assertSame( array(), $container->registered );
	}

	/** @testdox Should not resolve provider roots for an empty front request. */
	public function test_empty_front_request_does_not_resolve_provider_roots(): void {
		$container      = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_CORE, false, false );
		$resolver_calls = 0;
		$sut            = new MultiCurrencyBootstrap(
			static function () use ( &$resolver_calls ): array {
				++$resolver_calls;
				return array( 'ProviderRoot' );
			}
		);

		$sut->register( $container, '__return_false' );

		$this->assertSame( 0, $resolver_calls );
		$this->assertSame( array(), $container->registered );
	}

	/** @testdox Should not resolve provider roots for an empty AJAX request. */
	public function test_empty_ajax_request_does_not_resolve_provider_roots(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$container      = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_CORE, false, false );
		$resolver_calls = 0;
		$sut            = new MultiCurrencyBootstrap(
			static function () use ( &$resolver_calls ): array {
				++$resolver_calls;
				return array( 'ProviderRoot' );
			}
		);

		try {
			$sut->register( $container, '__return_true' );
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		$this->assertSame( 0, $resolver_calls );
		$this->assertSame( array(), $container->registered );
	}

	/**
	 * @testdox Should treat an empty Action Scheduler request as cron before AJAX, REST, and admin.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_empty_action_scheduler_request_precedes_ajax_rest_and_admin_without_resolving_provider_roots(): void {
		$_REQUEST['action'] = 'as_async_request_queue_runner';
		add_filter( 'wp_doing_ajax', '__return_true' );
		$container      = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_CORE, false, false );
		$resolver_calls = 0;
		$sut            = new MultiCurrencyBootstrap(
			static function () use ( &$resolver_calls ): array {
				++$resolver_calls;
				return array( 'ProviderRoot' );
			}
		);

		try {
			$sut->register( $container, '__return_true' );
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		$this->assertSame( 0, $resolver_calls );
		$this->assertSame( array(), $container->registered );
	}

	/**
	 * @testdox Should not resolve provider roots for an empty CLI request.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_empty_cli_request_does_not_resolve_provider_roots(): void {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		$container      = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_CORE, false, false );
		$resolver_calls = 0;
		$sut            = new MultiCurrencyBootstrap(
			static function () use ( &$resolver_calls ): array {
				++$resolver_calls;
				return array( 'ProviderRoot' );
			}
		);

		$sut->register( $container, '__return_true' );

		$this->assertSame( 0, $resolver_calls );
		$this->assertSame( array(), $container->registered );
	}

	/**
	 * @testdox Should select the exact core root matrix for each persisted-data tier and request class.
	 *
	 * @dataProvider core_root_matrix
	 *
	 * @param bool              $configured Whether an additional currency is configured.
	 * @param bool              $historical Whether historical Multi-Currency data exists.
	 * @param string            $request    Request class.
	 * @param array<int,string> $expected   Expected roots.
	 */
	public function test_selects_exact_core_root_matrix( bool $configured, bool $historical, string $request, array $expected ): void {
		$container = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_CORE, $configured, $historical );
		$sut       = new MultiCurrencyBootstrap( static fn(): array => array() );
		$this->assertTrue( method_exists( MultiCurrencyBootstrap::class, 'get_core_roots' ) );
		if ( ! method_exists( MultiCurrencyBootstrap::class, 'get_core_roots' ) ) {
			return;
		}
		$method = new \ReflectionMethod( MultiCurrencyBootstrap::class, 'get_core_roots' );
		$method->setAccessible( true );

		$this->assertSame( $expected, $method->invoke( $sut, $container, $request ) );
	}

	/** @return array<string,array{bool,bool,string,array<int,string>}> */
	public static function core_root_matrix(): array {
		$base       = array( self::CORE_ROOTS[0] );
		$price      = array( self::CORE_ROOTS[1], self::CORE_ROOTS[2], self::CORE_ROOTS[3] );
		$compat     = array_slice( self::CORE_ROOTS, 5, 11 );
		$storefront = array( self::CORE_ROOTS[16], self::CORE_ROOTS[17], self::CORE_ROOTS[19], self::CORE_ROOTS[20] );
		$history    = array( self::CORE_ROOTS[4], self::CORE_ROOTS[23] );
		$settings   = self::CORE_ROOTS[18];
		$rest       = self::CORE_ROOTS[21];
		$override   = self::CORE_ROOTS[22];
		$admin      = array( self::CORE_ROOTS[24], self::CORE_ROOTS[25] );

		return array(
			'configured front' => array( true, false, 'front', array_merge( $base, $price, $compat, $storefront ) ),
			'configured ajax'  => array( true, false, 'ajax', array_merge( $base, $price, $compat, array( self::CORE_ROOTS[19], self::CORE_ROOTS[20] ), $history ) ),
			'configured rest'  => array( true, false, 'rest', array_merge( $base, $price, $compat, array( self::CORE_ROOTS[17], self::CORE_ROOTS[19], self::CORE_ROOTS[20] ), $history, array( $rest, $override ) ) ),
			'configured admin' => array( true, false, 'admin', array_merge( $base, $price, $compat, array( self::CORE_ROOTS[4], self::CORE_ROOTS[17], $settings, self::CORE_ROOTS[23] ), $admin ) ),
			'configured cron'  => array( true, false, 'cron', array_merge( $base, array( self::CORE_ROOTS[2] ), $compat, $history ) ),
			'configured cli'   => array( true, false, 'cli', array_merge( $base, array( self::CORE_ROOTS[2] ), $compat, $history ) ),
			'historical admin' => array( false, true, 'admin', array_merge( $base, array( self::CORE_ROOTS[4], $settings, self::CORE_ROOTS[23] ), $admin ) ),
			'historical rest'  => array( false, true, 'rest', array_merge( $base, array( self::CORE_ROOTS[4], $rest, $override, self::CORE_ROOTS[23] ) ) ),
			'historical cron'  => array( false, true, 'cron', array_merge( $base, $history ) ),
			'historical front' => array( false, true, 'front', array() ),
			'historical ajax'  => array( false, true, 'ajax', array() ),
			'historical cli'   => array( false, true, 'cli', array() ),
			'empty admin'      => array( false, false, 'admin', array( $settings ) ),
			'empty rest'       => array( false, false, 'rest', array( $rest ) ),
			'empty front'      => array( false, false, 'front', array() ),
			'empty ajax'       => array( false, false, 'ajax', array() ),
			'empty cron'       => array( false, false, 'cron', array() ),
			'empty cli'        => array( false, false, 'cli', array() ),
		);
	}

	/** @testdox Should avoid historical storage checks for no-config front, AJAX, and CLI requests. */
	public function test_no_config_front_ajax_and_cli_skip_historical_order_detection(): void {
		foreach ( array( 'front', 'ajax', 'cli' ) as $request ) {
			$container = $this->make_container( MultiCurrencyRuntimeArbiter::OWNER_CORE, false, true );
			$sut       = new MultiCurrencyBootstrap( static fn(): array => array() );
			$this->assertTrue( method_exists( MultiCurrencyBootstrap::class, 'get_core_roots' ) );
			if ( ! method_exists( MultiCurrencyBootstrap::class, 'get_core_roots' ) ) {
				return;
			}
			$method = new \ReflectionMethod( MultiCurrencyBootstrap::class, 'get_core_roots' );
			$method->setAccessible( true );

			$this->assertSame( array(), $method->invoke( $sut, $container, $request ) );
			$this->assertSame( 0, $container->foreign_currency_order_checks, $request . ' must return before querying order storage.' );
		}
	}

	/** @testdox Should retain every former core root in the configured request-matrix union. */
	public function test_configured_matrix_inventory_is_the_former_core_root_list_without_duplicates(): void {
		$roots = array();
		foreach ( self::core_root_matrix() as $case ) {
			if ( $case[0] ) {
				$roots = array_merge( $roots, $case[3] );
			}
		}

		$actual   = array_values( array_unique( $roots ) );
		$expected = self::CORE_ROOTS;
		sort( $actual );
		sort( $expected );

		$this->assertSame( $expected, $actual );
	}

	/** @testdox Should classify all request signals with the required precedence. */
	public function test_classifies_request_signals_with_required_precedence(): void {
		$this->assertTrue( method_exists( MultiCurrencyBootstrap::class, 'classify_signals' ) );
		if ( ! method_exists( MultiCurrencyBootstrap::class, 'classify_signals' ) ) {
			return;
		}
		$method = new \ReflectionMethod( MultiCurrencyBootstrap::class, 'classify_signals' );
		$method->setAccessible( true );

		$this->assertSame( 'cli', $method->invoke( null, true, true, true, true, true ) );
		$this->assertSame( 'cron', $method->invoke( null, false, true, true, true, true ) );
		$this->assertSame( 'ajax', $method->invoke( null, false, false, true, true, true ) );
		$this->assertSame( 'rest', $method->invoke( null, false, false, false, true, true ) );
		$this->assertSame( 'admin', $method->invoke( null, false, false, false, false, true ) );
		$this->assertSame( 'front', $method->invoke( null, false, false, false, false, false ) );
	}

	/**
	 * @param string $owner Multi-Currency owner.
	 * @param bool   $configured Whether extra configured currencies exist.
	 * @param bool   $historical Whether historical orders exist.
	 * @return RuntimeContainer&object{resolved:array<int,string>,registered:array<int,string>,foreign_currency_order_checks:int}
	 */
	private function make_container( string $owner, bool $configured, bool $historical ): RuntimeContainer {
		$arbiter = new class( $owner ) extends MultiCurrencyRuntimeArbiter {
			/** @var string */
			private $owner;

			/**
			 * Initialize the configured owner.
			 *
			 * @param string $owner Configured owner.
			 */
			public function __construct( string $owner ) {
				$this->owner = $owner;
			}

			/** Return the configured owner. @return string Configured owner. */
			public function get_runtime_owner(): string {
				return $this->owner;
			}
		};

		return new class( $arbiter, $configured, $historical ) extends RuntimeContainer {
			/** @var array<int,string> */
			public array $resolved = array();

			/** @var array<int,string> */
			public array $registered = array();

			/** @var int */
			public int $foreign_currency_order_checks = 0;

			/** @var MultiCurrencyRuntimeArbiter */
			private $arbiter;

			/** @var bool */
			private $configured;

			/** @var bool */
			private $historical;

			/**
			 * Initialize the recording container.
			 *
			 * @param MultiCurrencyRuntimeArbiter $arbiter Runtime owner arbiter.
			 * @param bool                        $configured Whether extra configured currencies exist.
			 * @param bool                        $historical Whether historical orders exist.
			 */
			public function __construct( MultiCurrencyRuntimeArbiter $arbiter, bool $configured, bool $historical ) {
				parent::__construct( array() );
				$this->arbiter    = $arbiter;
				$this->configured = $configured;
				$this->historical = $historical;
			}

			/**
			 * Resolve a recorder for the requested class.
			 *
			 * @param string $class_name Requested class.
			 * @return object Recorder.
			 */
			public function get( string $class_name ) {
				$this->resolved[] = $class_name;
				if ( MultiCurrencyRuntimeArbiter::class === $class_name ) {
					return $this->arbiter;
				}

				if ( MultiCurrencyUsageDetector::class === $class_name ) {
					$configured = $this->configured;
					$historical = $this->historical;
					$container  = $this;

					return new class( $configured, $historical, $container ) {
						/** @var bool */
						private $configured;

						/** @var bool */
						private $historical;

						/** @var object */
						private $container;

						/**
						 * Initialize persisted-data signals.
						 *
						 * @param bool   $configured Whether extra configured currencies exist.
						 * @param bool   $historical Whether historical orders exist.
						 * @param object $container Recording container.
						 */
						public function __construct( bool $configured, bool $historical, object $container ) {
							$this->configured = $configured;
							$this->historical = $historical;
							$this->container  = $container;
						}

						/** Return the configured-currency signal. @return bool Whether configured currencies exist. */
						public function has_additional_enabled_currencies(): bool {
							return $this->configured;
						}

						/** Count and return the historical-order signal. @return bool Whether historical orders exist. */
						public function has_foreign_currency_orders(): bool {
							++$this->container->foreign_currency_order_checks;

							return $this->historical;
						}
					};
				}

				$registered = &$this->registered;
				return new class( $class_name, $registered ) {
					/** @var string */
					private $class_name;

					/** @var array<int,string> */
					private $registered;

					/**
					 * Initialize the recording registrar.
					 *
					 * @param string            $class_name Class name.
					 * @param array<int,string> $registered Registered roots.
					 */
					public function __construct( string $class_name, array &$registered ) {
						$this->class_name = $class_name;
						$this->registered = &$registered;
					}

					/** Record registration. */
					public function register(): void {
						$this->registered[] = $this->class_name;
					}
				};
			}
		};
	}
}
