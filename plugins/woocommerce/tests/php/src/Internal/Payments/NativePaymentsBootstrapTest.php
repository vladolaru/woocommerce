<?php
/**
 * NativePaymentsBootstrap tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\DependencyManagement\RuntimeContainer;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRestController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyUsageDetector;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsBootstrap;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsGatewayRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\Payments\PaymentGatewayProviderContract;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverPluginLifecycleListener;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverReconciliationJob;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverNormalizationRunner;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTierSyncController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Shadow\NativePaymentsShadowMode;
use ReflectionMethod;
use WC_Unit_Test_Case;

/**
 * Tests for NativePaymentsBootstrap.
 */
class NativePaymentsBootstrapTest extends WC_Unit_Test_Case {

	private const WCPAY = 'Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\\';

	/**
	 * A source scan, kept because the laziness shows only in which classes autoload while WooCommerce boots, and this
	 * test process has loaded them all already. An array callable or a first-class callable would autoload the provider.
	 *
	 * @testdox Production composition passes the provider's class lists through closures, so building the bootstrap autoloads no provider class.
	 */
	public function test_production_composition_resolves_provider_roots_lazily(): void {
		$method       = new ReflectionMethod( \WooCommerce::class, 'init_hooks' );
		$source_lines = file( $method->getFileName() );
		$method_body  = implode( '', array_slice( $source_lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );

		$this->assertMatchesRegularExpression(
			'/\b(?:fn|function)\s*\([^)]*\)[^;]*?WooPaymentsSetupTier::class\s*\)->get_classes_for_request\(/',
			$method_body,
			'WooPaymentsSetupTier::get_classes_for_request() must be called from inside a closure.'
		);
		$this->assertMatchesRegularExpression(
			'/\b(?:fn|function)\s*\([^)]*\)[^;]*?WooPaymentsProvider::get_multi_currency_provider_roots\(\)/',
			$method_body,
			'WooPaymentsProvider::get_multi_currency_provider_roots() must be called from inside a closure.'
		);
	}

	/** @testdox Native Payments passes provider roots and REST classification into Multi-Currency registration. */
	public function test_native_bootstrap_passes_provider_roots_and_rest_classifier_to_multi_currency_registration(): void {
		$container      = $this->make_container( WooPaymentsSetupTier::DISABLED, WooPaymentsRuntimeArbiter::OWNER_BUILTIN, MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true );
		$provider_calls = 0;
		$rest_calls     = 0;
		$sut            = new NativePaymentsBootstrap(
			static fn(): array => array(),
			static fn(): bool => true,
			static function () use ( &$provider_calls ): array {
				++$provider_calls;
				return array( 'MultiCurrencyProviderRoot' );
			}
		);

		$sut->register(
			$container,
			static function () use ( &$rest_calls ): bool {
				++$rest_calls;
				return true;
			}
		);

		$this->assertSame( 1, $provider_calls );
		$this->assertSame( 2, $rest_calls );
		$this->assertContains( 'MultiCurrencyProviderRoot', $container->resolved );
		$this->assertContains( MultiCurrencyRestController::class, $container->resolved );
		$this->assertContains( 'register:MultiCurrencyProviderRoot', $container->events );

		$provider_root_position   = array_search( 'MultiCurrencyProviderRoot', $container->resolved, true );
		$rest_controller_position = array_search( MultiCurrencyRestController::class, $container->resolved, true );

		$this->assertNotFalse( $provider_root_position );
		$this->assertNotFalse( $rest_controller_position );
		$this->assertLessThan( $rest_controller_position, $provider_root_position );
	}

	/** @testdox Should return before request or container work when the bootstrap filter is false. */
	public function test_bootstrap_filter_false_short_circuits_all_work(): void {
		add_filter( NativePaymentsBootstrap::FILTER_BOOTSTRAP_ENABLED, '__return_false' );
		$matrix_calls = 0;
		$container    = $this->make_container( WooPaymentsSetupTier::ACTIVE, WooPaymentsRuntimeArbiter::OWNER_BUILTIN );
		$rest_calls   = 0;
		$sut          = new NativePaymentsBootstrap(
			static function () use ( &$matrix_calls ): array {
				++$matrix_calls;
				return array();
			},
			static fn(): bool => true,
			static fn(): array => array()
		);

		$sut->register(
			$container,
			static function () use ( &$rest_calls ): bool {
				++$rest_calls;
				return true;
			}
		);

		$this->assertSame( array(), $container->events, 'The disabled bootstrap should not resolve any service.' );
		$this->assertSame( 0, $rest_calls, 'The disabled bootstrap should not classify the request.' );
		$this->assertSame( 0, $matrix_calls, 'The disabled bootstrap should not ask the provider for its class lists.' );
	}

	/**
	 * @testdox The gateway provider is resolved only when WooCommerce builds its gateway list.
	 */
	public function test_gateway_provider_is_resolved_only_by_the_gateway_list(): void {
		$container = $this->make_container( WooPaymentsSetupTier::CONNECTED, WooPaymentsRuntimeArbiter::OWNER_BUILTIN );
		$sut       = $this->make_bootstrap();

		$sut->register( $container, '__return_false' );

		$this->assertNotContains( WooPaymentsProvider::class, $container->resolved, 'Registering the gateway must not build the provider.' );
		$resolvers = $container->get( NativePaymentsGatewayRegistry::class )->provider_resolvers;
		$this->assertCount( 1, $resolvers );
		$provider = $resolvers[0]();
		$this->assertSame( WooPaymentsProvider::class, $provider->get_recorded_class_name(), 'The resolver must build the provider root that follows the registry.' );
	}

	/** @testdox Should resolve and register each explicit connected REST root once in order. */
	public function test_register_resolves_connected_rest_roots_once_in_order(): void {
		$container = $this->make_container( WooPaymentsSetupTier::CONNECTED, WooPaymentsRuntimeArbiter::OWNER_BUILTIN );
		$sut       = $this->make_bootstrap();

		$sut->register( $container, '__return_true' );

		$this->assertSame( $this->expected_events( WooPaymentsProvider::get_classes_by_setup_tier()[ WooPaymentsSetupTier::CONNECTED ]['rest'] ), $container->events );
		$this->assertSame( array_values( array_unique( $container->resolved ) ), $container->resolved, 'Each explicit root should be resolved once.' );
	}

	/**
	 * @testdox A $request request on a $state store registers the cron roots it lacks once, when Action Scheduler first runs an action in it.
	 * @dataProvider page_request_cron_root_gaps
	 *
	 * @param string            $state         Native state.
	 * @param string            $request       Request type.
	 * @param array<int,string> $missing_roots Cron roots the request lacks, in cron order.
	 */
	public function test_page_request_registers_its_missing_cron_roots_once_when_an_action_runs( string $state, string $request, array $missing_roots ): void {
		$request_roots = WooPaymentsProvider::get_classes_by_setup_tier()[ $state ][ $request ] ?? array();
		$container     = $this->make_container( $state, WooPaymentsRuntimeArbiter::OWNER_BUILTIN );
		$sut           = $this->make_bootstrap();
		if ( 'admin' === $request ) {
			set_current_screen( 'woocommerce_page_wc-status' );
		}

		$sut->register( $container, '__return_false' );
		set_current_screen( 'front' );

		$this->assertSame( $this->expected_events( $request_roots ), $container->events, 'Loading must register only the request roots.' );
		do_action( 'action_scheduler_before_execute', 1, 'WP Cron' );
		do_action( 'action_scheduler_before_execute', 2, 'WP Cron' );
		$this->assertSame( $this->expected_events( $request_roots, $missing_roots ), $container->events, 'The first action must register the missing cron roots, once.' );
	}

	/** @return array<string,array{string,string,array<int,string>}> */
	public static function page_request_cron_root_gaps(): array {
		return array(
			'available front' => array( WooPaymentsSetupTier::AVAILABLE, 'front', array( WooPaymentsCutoverReconciliationJob::class ) ),
			'active front'    => array(
				WooPaymentsSetupTier::ACTIVE,
				'front',
				array(
					self::WCPAY . 'WooPaymentsCutoverNormalizationRunner',
					WooPaymentsCutoverReconciliationJob::class,
					self::WCPAY . 'WooPaymentsCanceledAuthorizationFeeRemediationService',
					self::WCPAY . 'WooPaymentsLoanApprovedNote',
					self::WCPAY . 'WooPaymentsGatewaySettingsSynchronizer',
					self::WCPAY . 'WooPaymentsSellingLocationsFraudSync',
				),
			),
			'active admin'    => array(
				WooPaymentsSetupTier::ACTIVE,
				'admin',
				array(
					self::WCPAY . 'WooPaymentsCanceledAuthorizationFeeRemediationService',
					self::WCPAY . 'WooPaymentsDuplicatePaymentPreventionService',
				),
			),
		);
	}

	/** @testdox Adds no Action Scheduler listener on a disabled store, on cron and WP-CLI requests, or when the request already has every cron root. */
	public function test_adds_no_action_scheduler_listener_when_no_cron_root_is_missing(): void {
		$cases = array(
			'disabled front'  => array( WooPaymentsSetupTier::DISABLED, '__return_false', false ),
			'available admin' => array( WooPaymentsSetupTier::AVAILABLE, '__return_false', true ),
			'active cron'     => array( WooPaymentsSetupTier::ACTIVE, '__return_false', false ),
		);
		foreach ( $cases as $label => list( $state, $is_rest, $admin ) ) {
			if ( 'active cron' === $label ) {
				add_filter( 'wp_doing_cron', '__return_true' );
			}
			if ( $admin ) {
				set_current_screen( 'woocommerce_page_wc-status' );
			}
			$sut = $this->make_bootstrap();

			$sut->register( $this->make_container( $state, WooPaymentsRuntimeArbiter::OWNER_BUILTIN ), $is_rest );
			remove_filter( 'wp_doing_cron', '__return_true' );
			set_current_screen( 'front' );

			$this->assertFalse( has_action( 'action_scheduler_before_execute', array( $sut, 'handle_action_scheduler_before_execute' ) ), $label );
		}
	}

	/**
	 * The recording container clamps the stored tier with its own copy of the rule; the real clamp is checked by
	 * WooPaymentsSetupTierTest::test_get_state_clamps_connected_tiers_while_plugin_is_active.
	 *
	 * @testdox A plugin-owned site with an available effective tier registers only the setup tier sync controller and the plugin lifecycle listener, not shadow mode.
	 */
	public function test_plugin_owner_registers_no_shadow_mode_by_default(): void {
		$container = $this->make_container( WooPaymentsSetupTier::ACTIVE, WooPaymentsRuntimeArbiter::OWNER_EXTENSION );
		$sut       = $this->make_bootstrap();

		$sut->register( $container, '__return_false' );

		// A plugin deactivated over XML-RPC (Jetpack remote management, a front request) must still start the switch.
		$this->assertSame( $this->expected_events( array( WooPaymentsSetupTierSyncController::class, WooPaymentsCutoverPluginLifecycleListener::class ) ), $container->events );
		$this->assertNotContains( NativePaymentsShadowMode::class, $container->resolved );
	}

	/**
	 * The recording container returns disabled for owner-less sites with its own copy of the rule; the real rule is checked by
	 * WooPaymentsSetupTierTest::test_get_state_returns_disabled_without_overwriting_the_stored_state_when_no_runtime_owns_the_site.
	 *
	 * @testdox An owner-less site with a disabled effective tier registers nothing, even with shadow mode enabled.
	 */
	public function test_owner_none_registers_nothing_even_with_shadow_mode_enabled(): void {
		add_filter( NativePaymentsShadowMode::FILTER_SHADOW_ENABLED, '__return_true' );
		$container = $this->make_container( WooPaymentsSetupTier::ACTIVE, WooPaymentsRuntimeArbiter::OWNER_NONE );
		$sut       = $this->make_bootstrap();

		$sut->register( $container, '__return_false' );

		$this->assertSame( $this->expected_events( array() ), $container->events );
	}

	/** @testdox The setup tier sync controller is registered, on a disabled tier too, only on sites the plugin owns. */
	public function test_setup_tier_sync_controller_registers_only_for_the_plugin_owner(): void {
		$owners = array(
			WooPaymentsRuntimeArbiter::OWNER_EXTENSION => array( WooPaymentsSetupTierSyncController::class ),
			WooPaymentsRuntimeArbiter::OWNER_BUILTIN   => array(),
			WooPaymentsRuntimeArbiter::OWNER_NONE      => array(),
		);

		foreach ( $owners as $owner => $expected_classes ) {
			$container = $this->make_container( WooPaymentsSetupTier::DISABLED, $owner );
			$sut       = $this->make_bootstrap();

			$sut->register( $container, '__return_false' );

			$this->assertSame( $this->expected_events( $expected_classes ), $container->events, $owner );
		}
	}

	/**
	 * @testdox With the real bootstrap, registry and arbiter, the WooPayments gateways leave the gateway list once the built-in WooPayments stops owning payments.
	 */
	public function test_gateway_list_follows_the_arbiter_after_the_built_in_runtime_loses_ownership(): void {
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		update_option( 'active_plugins', array_values( array_diff( (array) get_option( 'active_plugins', array() ), array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) ) ) );
		wc_get_container()->reset_all_resolved();
		$gateway  = new class() extends \WC_Payment_Gateway {
			/** Give the gateway the WooPayments ID. */
			public function __construct() {
				$this->id = 'woocommerce_payments';
			}
		};
		$provider = new class( true, array( $gateway ), 'woocommerce_payments' ) extends StaticProvider {
			/** @var int Number of gateway list reads. */
			public int $gateway_reads = 0;

			/**
			 * Count each gateway list read.
			 *
			 * @return array<int,\WC_Payment_Gateway>
			 */
			public function get_payment_gateways(): array {
				++$this->gateway_reads;
				return parent::get_payment_gateways();
			}
		};
		wc_get_container()->replace( WooPaymentsProvider::class, $provider );
		$sut = new NativePaymentsBootstrap(
			static fn(): array => array( WooPaymentsProvider::class ),
			static fn( $container ): bool => $container->get( WooPaymentsRuntimeArbiter::class )->is_builtin_owner(),
			static fn(): array => array()
		);

		try {
			$sut->register( wc_get_container(), '__return_false' );
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Building the gateway list as WooCommerce does.
			$this->assertSame( array( $gateway ), apply_filters( 'woocommerce_payment_gateways', array() ), 'The gateway must be listed while the built-in WooPayments owns payments.' );
			$this->assertSame( 1, $provider->gateway_reads );

			remove_all_filters( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER );
			add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_false' );
			wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();

			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Building the gateway list as WooCommerce does.
			$this->assertSame( array(), apply_filters( 'woocommerce_payment_gateways', array() ), 'The gateway must leave the list once the built-in WooPayments stops owning payments.' );
			$this->assertSame( 1, $provider->gateway_reads, 'A provider whose check fails must not be asked for its gateways.' );
		} finally {
			wc_get_container()->reset_all_replacements();
			wc_get_container()->reset_all_resolved();
		}
	}

	/**
	 * WooCommerce runs this bootstrap inside its constructor, before WC() has an instance to return, so any WC() call on this
	 * path constructs WooCommerce again and recurses until PHP gives up. The test reproduces that by clearing the instance;
	 * a WC() call then re-enters the bootstrap, which the bootstrap filter records.
	 *
	 * @testdox The payments and Multi-Currency bootstrap makes no WC() call in a $request request with the $theme theme in the $state tier.
	 * @dataProvider bootstrap_request_themes
	 *
	 * @param string $request Request class: front, ajax, rest, admin, cron or cli.
	 * @param string $theme   Active theme stylesheet and template.
	 * @param string $state   Native payments tier.
	 */
	public function test_bootstrap_makes_no_wc_call( string $request, string $theme, string $state ): void {
		add_filter( 'pre_http_request', static fn() => new \WP_Error( 'blocked', 'Outbound HTTP is blocked in this test.' ) );
		add_filter( 'pre_option_stylesheet', static fn() => $theme );
		add_filter( 'pre_option_template', static fn() => $theme );
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		update_option( 'active_plugins', array_values( array_diff( (array) get_option( 'active_plugins', array() ), array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) ) ) );
		update_option( WooPaymentsSetupTier::OPTION_NAME, $state );
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'USD', 'EUR' ) );
		update_option( 'wcpay_multi_currency_enable_storefront_switcher', 'yes' );
		wc_get_container()->reset_all_resolved();

		$wc_call  = null;
		$entries  = 0;
		$recorder = static function ( $enabled ) use ( &$entries, &$wc_call ) {
			if ( 1 < ++$entries && null === $wc_call ) {
				$wc_call = 'WC() was called';
				foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
					if ( 'WC' === $frame['function'] && ! isset( $frame['class'] ) ) {
						$wc_call .= ' from ' . ( $frame['file'] ?? '?' ) . ':' . ( $frame['line'] ?? '?' );
						break;
					}
				}
				throw new \LogicException( 'WC() was called while the bootstrap ran.' );
			}

			return $enabled;
		};
		add_filter( NativePaymentsBootstrap::FILTER_BOOTSTRAP_ENABLED, $recorder );

		$sut      = new NativePaymentsBootstrap(
			static fn( $container, string $request_type ): array => $container->get( WooPaymentsSetupTier::class )->get_classes_for_request( $request_type ),
			static fn( $container ): bool => $container->get( WooPaymentsRuntimeArbiter::class )->is_builtin_owner(),
			static fn(): array => WooPaymentsProvider::get_multi_currency_provider_roots()
		);
		$instance = new \ReflectionProperty( \WooCommerce::class, '_instance' );
		$instance->setAccessible( true );
		$woocommerce = $instance->getValue();
		$instance->setValue( null, null );
		try {
			$this->register_as( $sut, $request );
		} catch ( \LogicException $e ) {
			unset( $e );
		} finally {
			$instance->setValue( null, $woocommerce );
			wc_get_container()->reset_all_resolved();
		}

		$this->assertNull( $wc_call, (string) $wc_call );
		$this->assertSame( 1, $entries, 'The bootstrap must run once.' );
	}

	/** @return array<string,array{string,string,string}> */
	public static function bootstrap_request_themes(): array {
		$cases = array();
		foreach ( array( 'front', 'ajax', 'rest', 'admin', 'cron', 'cli' ) as $request ) {
			foreach ( array( 'storefront', 'twentytwentyfive' ) as $theme ) {
				foreach ( array( WooPaymentsSetupTier::AVAILABLE, WooPaymentsSetupTier::CONNECTED, WooPaymentsSetupTier::ACTIVE ) as $state ) {
					$cases[ "$request, $theme, $state" ] = array( $request, $theme, $state );
				}
			}
		}

		return $cases;
	}

	/**
	 * Run the bootstrap as WooCommerce loads it in one request class, with the shared container.
	 *
	 * @param NativePaymentsBootstrap $sut     Bootstrap under test.
	 * @param string                  $request Request class: front, ajax, rest, admin, cron or cli.
	 */
	private function register_as( NativePaymentsBootstrap $sut, string $request ): void {
		$filter = array(
			'ajax' => 'wp_doing_ajax',
			'cron' => 'wp_doing_cron',
		)[ $request ] ?? null;
		if ( null !== $filter ) {
			add_filter( $filter, '__return_true' );
		}
		if ( 'cli' === $request ) {
			Constants::set_constant( 'WP_CLI', true );
		}
		if ( 'admin' === $request ) {
			set_current_screen( 'edit-page' );
		}

		try {
			$sut->register( wc_get_container(), 'rest' === $request ? '__return_true' : '__return_false' );
		} finally {
			if ( null !== $filter ) {
				remove_filter( $filter, '__return_true' );
			}
			Constants::clear_single_constant( 'WP_CLI' );
			set_current_screen( 'front' );
		}
	}

	/**
	 * Build the bootstrap with the WooPayments setup tier's class lists, as WooCommerce does.
	 *
	 * @return NativePaymentsBootstrap
	 */
	private function make_bootstrap(): NativePaymentsBootstrap {
		return new NativePaymentsBootstrap(
			static fn( $container, string $request_type ): array => $container->get( WooPaymentsSetupTier::class )->get_classes_for_request( $request_type ),
			static fn( $container ): bool => $container->get( WooPaymentsRuntimeArbiter::class )->is_builtin_owner(),
			array( WooPaymentsProvider::class, 'get_multi_currency_provider_roots' )
		);
	}

	/**
	 * Build expected facade events from a literal root list.
	 *
	 * @param array<int,string> $roots           Explicit roots.
	 * @param array<int,string> $on_demand_roots Roots registered later, when Action Scheduler runs an action.
	 * @return array<int,string>
	 */
	private function expected_events( array $roots, array $on_demand_roots = array() ): array {
		$events = array(
			'get:' . MultiCurrencyRuntimeArbiter::class,
		);

		foreach ( array_merge( $roots, $on_demand_roots ) as $root ) {
			if ( is_a( $root, PaymentGatewayProviderContract::class, true ) ) {
				$events[] = 'get:' . NativePaymentsGatewayRegistry::class;
				$events[] = 'provider-resolver:' . NativePaymentsGatewayRegistry::class;
				$events[] = 'register:' . NativePaymentsGatewayRegistry::class;
				continue;
			}
			$events[] = 'get:' . $root;
			$events[] = 'register:' . $root;
		}

		return $events;
	}

	/**
	 * Build a container that records explicit resolution and registration order.
	 *
	 * @param string $state                Native state.
	 * @param string $owner                Native runtime owner.
	 * @param string $multi_currency_owner Multi-Currency runtime owner.
	 * @param bool   $configured           Whether additional currencies are configured.
	 * @param bool   $historical           Whether historical Multi-Currency orders exist.
	 * @return RuntimeContainer&object{resolved:array<int,string>,events:array<int,string>}
	 */
	private function make_container( string $state, string $owner, string $multi_currency_owner = MultiCurrencyRuntimeArbiter::OWNER_NONE, bool $configured = false, bool $historical = false ): RuntimeContainer {
		return new class( $state, $owner, $multi_currency_owner, $configured, $historical ) extends RuntimeContainer {
			/** @var array<int,string> */
			public array $resolved = array();

			/** @var array<int,string> */
			public array $events = array();

			/** @var string */
			private $state;

			/** @var string */
			private $owner;

			/** @var string */
			private $multi_currency_owner;

			/** @var bool */
			private $configured;

			/** @var bool */
			private $historical;

			/** @var array<string,object> */
			private array $services = array();

			/**
			 * Initialize the recording container.
			 *
			 * @param string $state                Native state.
			 * @param string $owner                Native runtime owner.
			 * @param string $multi_currency_owner Multi-Currency runtime owner.
			 * @param bool   $configured           Whether additional currencies are configured.
			 * @param bool   $historical           Whether historical Multi-Currency orders exist.
			 */
			public function __construct( string $state, string $owner, string $multi_currency_owner, bool $configured, bool $historical ) {
				parent::__construct( array() );
				$this->state                = $state;
				$this->owner                = $owner;
				$this->multi_currency_owner = $multi_currency_owner;
				$this->configured           = $configured;
				$this->historical           = $historical;
			}

			/**
			 * The real setup tier, reading the case's stored tier and owner, so its class lists are the ones under test.
			 *
			 * @return WooPaymentsSetupTier
			 */
			private function get_setup_tier(): WooPaymentsSetupTier {
				if ( ! isset( $this->services[ WooPaymentsSetupTier::class ] ) ) {
					update_option( WooPaymentsSetupTier::OPTION_NAME, $this->state );
					$arbiter = new class( $this->owner ) extends WooPaymentsRuntimeArbiter {
						/** @var string */
						private $owner;

						/**
						 * Initialize the owner stub.
						 *
						 * @param string $owner Native runtime owner.
						 */
						public function __construct( string $owner ) {
							$this->owner = $owner;
						}

						/** Return the case's owner. */
						public function get_runtime_owner(): string {
							return $this->owner;
						}
					};
					$tier    = new WooPaymentsSetupTier();
					$tier->init( $arbiter );
					$this->services[ WooPaymentsSetupTier::class ] = $tier;
				}

				return $this->services[ WooPaymentsSetupTier::class ];
			}

			/**
			 * Resolve a recording service for every explicit root.
			 *
			 * @param string $class_name Class name.
			 * @return object Recording service.
			 */
			public function get( string $class_name ) {
				if ( WooPaymentsSetupTier::class === $class_name ) {
					return $this->get_setup_tier();
				}

				$this->resolved[] = $class_name;
				$this->events[]   = 'get:' . $class_name;
				if ( MultiCurrencyUsageDetector::class === $class_name ) {
					return new class( $this->configured, $this->historical ) {
						/** @var bool */
						private $configured;

						/** @var bool */
						private $historical;

						/**
						 * Initialize persisted-data signals.
						 *
						 * @param bool $configured Whether additional currencies are configured.
						 * @param bool $historical Whether historical orders exist.
						 */
						public function __construct( bool $configured, bool $historical ) {
							$this->configured = $configured;
							$this->historical = $historical;
						}

						/** Return the configured-currency signal. */
						public function has_additional_enabled_currencies(): bool {
							return $this->configured;
						}

						/** Return the historical-order signal. */
						public function has_foreign_currency_orders(): bool {
							return $this->historical;
						}
					};
				}

				if ( ! isset( $this->services[ $class_name ] ) ) {
					$events                        = &$this->events;
					$state                         = $this->state;
					$owner                         = $this->owner;
					$multi_currency_owner          = $this->multi_currency_owner;
					$this->services[ $class_name ] = new class( $class_name, $events, $state, $owner, $multi_currency_owner ) {
						/** @var string */
						private $class_name;

						/** @var array<int,string> */
						private $events;

						/** @var string */
						private $state;

						/** @var string */
						private $owner;

						/** @var string */
						private $multi_currency_owner;

						/**
						 * Initialize the service recorder.
						 *
						 * @param string            $class_name Class name.
						 * @param array<int,string> $events     Recorded events.
						 * @param string            $state                Native state.
						 * @param string            $owner                Native runtime owner.
						 * @param string            $multi_currency_owner Multi-Currency runtime owner.
						 */
						public function __construct( string $class_name, array &$events, string $state, string $owner, string $multi_currency_owner ) {
							$this->class_name           = $class_name;
							$this->events               = &$events;
							$this->state                = $state;
							$this->owner                = $owner;
							$this->multi_currency_owner = $multi_currency_owner;
						}

						/** Return the recorded class name. */
						public function get_recorded_class_name(): string {
							return $this->class_name;
						}

						/** Return the configured Multi-Currency or native owner. */
						public function get_runtime_owner(): string {
							return MultiCurrencyRuntimeArbiter::class === $this->class_name ? $this->multi_currency_owner : $this->owner;
						}

						/** Tell whether the configured payments owner is the WooPayments extension. */
						public function is_payments_extension_owner(): bool {
							return WooPaymentsRuntimeArbiter::OWNER_EXTENSION === $this->owner;
						}

						/** Tell whether the configured payments owner is the built-in WooPayments. */
						public function is_payments_builtin_owner(): bool {
							return WooPaymentsRuntimeArbiter::OWNER_BUILTIN === $this->owner;
						}

						/** @var array<int,callable> Provider resolvers handed to the registry. */
						public array $provider_resolvers = array();

						/**
						 * Record a lazy provider without resolving it.
						 *
						 * @param callable $resolver                 Provider resolver.
						 * @param callable $should_register_gateways Provider gateway check.
						 */
						public function add_provider( callable $resolver, callable $should_register_gateways ): void {
							unset( $should_register_gateways );
							$this->provider_resolvers[] = $resolver;
							$this->events[]             = 'provider-resolver:' . $this->class_name;
						}

						/** Record registration. */
						public function register(): void {
							$this->events[] = 'register:' . $this->class_name;
						}
					};
				}

				return $this->services[ $class_name ];
			}
		};
	}
}
