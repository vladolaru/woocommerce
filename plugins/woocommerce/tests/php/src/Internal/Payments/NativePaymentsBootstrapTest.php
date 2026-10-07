<?php
/**
 * NativePaymentsBootstrap tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsMerchantRestController;
use Automattic\WooCommerce\Internal\DependencyManagement\RuntimeContainer;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRestController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyUsageDetector;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsBootstrap;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsGatewayRegistry;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsState;
use Automattic\WooCommerce\Internal\Payments\PaymentGatewayProviderContract;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminRestRouteRegistrar;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverPluginLifecycleListener;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverReconciliationJob;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverNormalizationRunner;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWooPayPreflightGuard;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWebhookReliabilityService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Shadow\NativePaymentsShadowMode;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
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
	 * @testdox Production composition passes the provider's root lists through closures, so building the bootstrap autoloads no provider class.
	 */
	public function test_production_composition_resolves_provider_roots_lazily(): void {
		$method       = new ReflectionMethod( \WooCommerce::class, 'init_hooks' );
		$source_lines = file( $method->getFileName() );
		$method_body  = implode( '', array_slice( $source_lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );

		foreach ( array( 'get_bootstrap_root_matrix', 'get_multi_currency_provider_roots' ) as $resolver ) {
			$this->assertMatchesRegularExpression(
				'/\b(?:fn|function)\s*\([^)]*\)[^;]*?WooPaymentsProvider::' . $resolver . '\(\)/',
				$method_body,
				"WooPaymentsProvider::{$resolver}() must be called from inside a closure."
			);
		}
	}

	/** @testdox Native Payments passes provider roots and REST classification into Multi-Currency registration. */
	public function test_native_bootstrap_passes_provider_roots_and_rest_classifier_to_multi_currency_registration(): void {
		$container      = $this->make_container( NativePaymentsState::DISABLED, NativePaymentsRuntimeArbiter::OWNER_NATIVE, MultiCurrencyRuntimeArbiter::OWNER_CORE, true );
		$provider_calls = 0;
		$rest_calls     = 0;
		$sut            = new NativePaymentsBootstrap(
			static fn(): array => array(),
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
		$container    = $this->make_container( NativePaymentsState::ACTIVE, NativePaymentsRuntimeArbiter::OWNER_NATIVE );
		$rest_calls   = 0;
		$sut          = new NativePaymentsBootstrap(
			static function () use ( &$matrix_calls ): array {
				++$matrix_calls;
				return WooPaymentsProvider::get_bootstrap_root_matrix();
			},
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
		$this->assertSame( 0, $matrix_calls, 'The disabled bootstrap should not ask the provider for its root matrix.' );
	}

	/**
	 * @testdox WP-CLI gets the cron roots in every set-up tier, so Action Scheduler runs under WP-CLI reach their handlers.
	 */
	public function test_wp_cli_gets_the_cron_roots_in_every_set_up_tier(): void {
		$sut       = $this->make_bootstrap();
		$roots_for = new ReflectionMethod( NativePaymentsBootstrap::class, 'roots_for' );
		$roots_for->setAccessible( true );

		foreach ( array( NativePaymentsState::AVAILABLE, NativePaymentsState::CONNECTED, NativePaymentsState::ACTIVE ) as $state ) {
			$cron_roots = $roots_for->invoke( $sut, $state, 'cron' );
			$this->assertNotEmpty( $cron_roots, "The $state cron cell must not be empty." );
			$this->assertSame( $cron_roots, $roots_for->invoke( $sut, $state, 'cli' ), "The $state WP-CLI request must register the cron roots." );
		}
		$this->assertSame( array(), $roots_for->invoke( $sut, NativePaymentsState::DISABLED, 'cli' ), 'A disabled tier registers nothing under WP-CLI.' );
	}

	/**
	 * @testdox The plugin lifecycle listener loads on every request class where WordPress can activate or deactivate a plugin.
	 */
	public function test_plugin_lifecycle_listener_loads_wherever_plugins_change(): void {
		$sut       = $this->make_bootstrap();
		$roots_for = new ReflectionMethod( NativePaymentsBootstrap::class, 'roots_for' );
		$roots_for->setAccessible( true );

		// activate_plugin() and deactivate_plugins() fire the lifecycle hooks in any request that calls them: wp-admin,
		// admin-ajax, the wp/v2/plugins and wc-admin REST routes, Action Scheduler (cron) and WP-CLI.
		foreach ( array( NativePaymentsState::AVAILABLE, NativePaymentsState::CONNECTED, NativePaymentsState::ACTIVE ) as $state ) {
			foreach ( array( 'admin', 'ajax', 'rest', 'cron', 'cli' ) as $request ) {
				$this->assertContains( WooPaymentsCutoverPluginLifecycleListener::class, $roots_for->invoke( $sut, $state, $request ), "The $state $request request must load the plugin lifecycle listener." );
			}
			// The controller owns the admin notices and the click, so it stays on admin requests.
			foreach ( array( 'ajax', 'rest', 'cron', 'cli' ) as $request ) {
				$this->assertNotContains( WooPaymentsCutoverController::class, $roots_for->invoke( $sut, $state, $request ), "The $state $request request must not resolve the cutover controller." );
			}
		}
	}

	/**
	 * @testdox The gateway provider is resolved only when WooCommerce builds its gateway list.
	 */
	public function test_gateway_provider_is_resolved_only_by_the_gateway_list(): void {
		$container = $this->make_container( NativePaymentsState::CONNECTED, NativePaymentsRuntimeArbiter::OWNER_NATIVE );
		$sut       = $this->make_bootstrap();

		$sut->register( $container, '__return_false' );

		$this->assertNotContains( WooPaymentsProvider::class, $container->resolved, 'Registering the gateway must not build the provider.' );
		$resolvers = $container->get( NativePaymentsGatewayRegistry::class )->provider_resolvers;
		$this->assertCount( 1, $resolvers );
		$provider = $resolvers[0]();
		$this->assertSame( WooPaymentsProvider::class, $provider->get_recorded_class_name(), 'The resolver must build the provider root that follows the registry.' );
	}

	/**
	 * @testdox Keeps cutover normalization out of shopper roots while preserving maintenance ordering.
	 */
	public function test_active_provider_matrix_bounds_cutover_normalization_to_maintenance_roots(): void {
		$active_roots = WooPaymentsProvider::get_bootstrap_root_matrix()[ NativePaymentsState::ACTIVE ];

		$this->assertNotContains( WooPaymentsCutoverNormalizationRunner::class, $active_roots['front'], 'A shopper request must not run cutover normalization.' );
		$this->assertNotContains( WooPaymentsCutoverNormalizationRunner::class, $active_roots['ajax'], 'An AJAX shopper request must not run cutover normalization.' );
		$this->assertNotContains( WooPaymentsCutoverNormalizationRunner::class, $active_roots['rest'], 'A REST shopper request must not run cutover normalization.' );
		$this->assertSame( WooPaymentsCutoverNormalizationRunner::class, $active_roots['admin'][0], 'Admin cutover normalization must run before bounded native consumers.' );
		$this->assertSame( WooPaymentsCutoverNormalizationRunner::class, $active_roots['cron'][0], 'Cron cutover normalization must run before bounded native consumers.' );
	}

	/** @testdox Cutover reconciliation is registered only on admin and cron roots in every available native tier. */
	public function test_provider_matrix_bounds_cutover_reconciliation_to_admin_and_cron_roots(): void {
		$matrix = WooPaymentsProvider::get_bootstrap_root_matrix();

		foreach ( array( NativePaymentsState::AVAILABLE, NativePaymentsState::CONNECTED, NativePaymentsState::ACTIVE ) as $state ) {
			$this->assertContains( WooPaymentsCutoverReconciliationJob::class, $matrix[ $state ]['admin'], $state . ' admin requests must register cutover reconciliation.' );
			$this->assertContains( WooPaymentsCutoverReconciliationJob::class, $matrix[ $state ]['cron'], $state . ' cron requests must register cutover reconciliation.' );

			foreach ( array( 'front', 'ajax', 'rest' ) as $request_type ) {
				$this->assertNotContains( WooPaymentsCutoverReconciliationJob::class, $matrix[ $state ][ $request_type ] ?? array(), $state . ' ' . $request_type . ' requests must not pay cutover reconciliation cost.' );
			}
		}
	}

	/** @testdox Should resolve and register each explicit connected REST root once in order. */
	public function test_register_resolves_connected_rest_roots_once_in_order(): void {
		$container = $this->make_container( NativePaymentsState::CONNECTED, NativePaymentsRuntimeArbiter::OWNER_NATIVE );
		$sut       = $this->make_bootstrap();

		$sut->register( $container, '__return_true' );

		$this->assertSame( $this->expected_events( WooPaymentsProvider::get_bootstrap_root_matrix()[ NativePaymentsState::CONNECTED ]['rest'] ), $container->events );
		$this->assertSame( array_values( array_unique( $container->resolved ) ), $container->resolved, 'Each explicit root should be resolved once.' );
	}

	/** @testdox Should make only the independent ownership decisions for a disabled tier. */
	public function test_register_resolves_no_native_roots_for_disabled_tier(): void {
		$container = $this->make_container( NativePaymentsState::DISABLED, NativePaymentsRuntimeArbiter::OWNER_NATIVE );
		$sut       = $this->make_bootstrap();

		$sut->register( $container, '__return_false' );

		$this->assertSame( $this->expected_events( array() ), $container->events );
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
		$request_roots = WooPaymentsProvider::get_bootstrap_root_matrix()[ $state ][ $request ] ?? array();
		$container     = $this->make_container( $state, NativePaymentsRuntimeArbiter::OWNER_NATIVE );
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
			'available front' => array( NativePaymentsState::AVAILABLE, 'front', array( WooPaymentsCutoverReconciliationJob::class, WooPaymentsCutoverPluginLifecycleListener::class ) ),
			'active front'    => array(
				NativePaymentsState::ACTIVE,
				'front',
				array(
					self::WCPAY . 'WooPaymentsCutoverNormalizationRunner',
					WooPaymentsCutoverReconciliationJob::class,
					self::WCPAY . 'WooPaymentsCanceledAuthorizationFeeRemediationService',
					self::WCPAY . 'WooPaymentsLoanApprovedNote',
					WooPaymentsCutoverPluginLifecycleListener::class,
					self::WCPAY . 'WooPaymentsGatewaySettingsSynchronizer',
					self::WCPAY . 'WooPaymentsSellingLocationsFraudSync',
				),
			),
			'active admin'    => array(
				NativePaymentsState::ACTIVE,
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
			'disabled front'  => array( NativePaymentsState::DISABLED, '__return_false', false ),
			'available admin' => array( NativePaymentsState::AVAILABLE, '__return_false', true ),
			'active cron'     => array( NativePaymentsState::ACTIVE, '__return_false', false ),
		);
		foreach ( $cases as $label => list( $state, $is_rest, $admin ) ) {
			if ( 'active cron' === $label ) {
				add_filter( 'wp_doing_cron', '__return_true' );
			}
			if ( $admin ) {
				set_current_screen( 'woocommerce_page_wc-status' );
			}
			$sut = $this->make_bootstrap();

			$sut->register( $this->make_container( $state, NativePaymentsRuntimeArbiter::OWNER_NATIVE ), $is_rest );
			remove_filter( 'wp_doing_cron', '__return_true' );
			set_current_screen( 'front' );

			$this->assertFalse( has_action( 'action_scheduler_before_execute', array( $sut, 'handle_action_scheduler_before_execute' ) ), $label );
		}
	}

	/** @testdox A gateway registry listed last, with no provider root after it, registers without a provider. */
	public function test_registry_listed_last_registers_without_a_provider(): void {
		$container = $this->make_container( NativePaymentsState::AVAILABLE, NativePaymentsRuntimeArbiter::OWNER_NATIVE );
		$sut       = new NativePaymentsBootstrap(
			static fn(): array => array( NativePaymentsState::AVAILABLE => array( 'cron' => array( WooPaymentsCutoverReconciliationJob::class, NativePaymentsGatewayRegistry::class ) ) ),
			static fn(): array => array()
		);

		$sut->register( $container, '__return_false' );
		do_action( 'action_scheduler_before_execute', 1, 'WP Cron' );

		$this->assertSame(
			array_merge(
				$this->expected_events( array( WooPaymentsCutoverReconciliationJob::class ) ),
				array( 'get:' . NativePaymentsGatewayRegistry::class, 'register:' . NativePaymentsGatewayRegistry::class )
			),
			$container->events
		);
	}

	/**
	 * @testdox Every provider root resolves from the container and registers hooks, appears once per list, and every gateway registry is followed by a gateway provider.
	 */
	public function test_provider_matrix_roots_are_registrable_and_listed_once(): void {
		$matrix = WooPaymentsProvider::get_bootstrap_root_matrix();

		$this->assertEmpty( $matrix[ NativePaymentsState::DISABLED ] ?? array(), 'A disabled store registers no provider root.' );
		foreach ( $matrix as $state => $request_groups ) {
			foreach ( $request_groups as $request => $roots ) {
				$cell = "$state $request";
				$this->assertSame( array_values( array_unique( $roots ) ), $roots, "$cell lists a root twice." );
				foreach ( $roots as $index => $root ) {
					$service = wc_get_container()->get( $root );
					if ( 0 < $index && NativePaymentsGatewayRegistry::class === $roots[ $index - 1 ] ) {
						$this->assertInstanceOf( PaymentGatewayProviderContract::class, $service, "$cell: the root after the gateway registry must be its gateway provider." );
						continue;
					}
					$this->assertInstanceOf( RegisterHooksInterface::class, $service, "$cell: $root must register hooks." );
				}
				$this->assertNotSame( NativePaymentsGatewayRegistry::class, end( $roots ), "$cell: the gateway registry needs a provider root after it." );
			}
		}
	}

	/** @testdox Mutation requests retain their required observers. */
	public function test_provider_matrix_keeps_required_mutation_observers(): void {
		$matrix         = WooPaymentsProvider::get_bootstrap_root_matrix();
		$connected_rest = $matrix[ NativePaymentsState::CONNECTED ]['rest'];
		$active_front   = $matrix[ NativePaymentsState::ACTIVE ]['front'];

		foreach (
			array(
				self::WCPAY . 'WooPaymentsOrderAdminActionsController',
				self::WCPAY . 'WooPay\WooPaymentsWooPayOrderStatusSync',
				self::WCPAY . 'WooPaymentsApplePayDomainService',
				self::WCPAY . 'WooPaymentsOrderTrackingService',
				self::WCPAY . 'WooPaymentsOperationalQueueService',
			) as $observer
		) {
			$this->assertContains( $observer, $connected_rest, $observer . ' must observe connected REST mutations.' );
		}

		$this->assertContains( self::WCPAY . 'WooPaymentsOrderTrackingService', $active_front, 'Active shopper order creation must retain tracking hooks.' );
	}

	/** @testdox Registers the express checkout Store API cart extension on active page requests, where the Cart and Checkout blocks preload the cart. */
	public function test_provider_matrix_registers_the_cart_extension_on_active_pages(): void {
		$matrix = WooPaymentsProvider::get_bootstrap_root_matrix();

		$this->assertContains( self::WCPAY . 'WooPaymentsExpressCheckoutStoreApiExtension', $matrix[ NativePaymentsState::ACTIVE ]['front'] );
		$this->assertContains( self::WCPAY . 'WooPaymentsExpressCheckoutStoreApiExtension', $matrix[ NativePaymentsState::ACTIVE ]['rest'] );
	}

	/**
	 * The native shopper scripts post their Tracks events to the REST route this controller registers, in a request
	 * of its own; without the controller among the REST roots the route does not exist there.
	 *
	 * @testdox Registers the shopper Tracks controller on active REST requests, where its route is served.
	 */
	public function test_provider_matrix_serves_the_shopper_tracks_route_on_active_rest(): void {
		$matrix = WooPaymentsProvider::get_bootstrap_root_matrix();

		$this->assertContains( self::WCPAY . 'WooPaymentsFrontendTrackingController', $matrix[ NativePaymentsState::ACTIVE ]['rest'] );
	}

	/** @testdox Registers the WooPay preflight guard only for active REST requests. */
	public function test_provider_matrix_bounds_woopay_preflight_guard_to_active_rest(): void {
		$matrix = WooPaymentsProvider::get_bootstrap_root_matrix();

		foreach ( $matrix as $state => $request_groups ) {
			foreach ( $request_groups as $request_type => $roots ) {
				if ( NativePaymentsState::ACTIVE === $state && 'rest' === $request_type ) {
					$this->assertContains( WooPaymentsWooPayPreflightGuard::class, $roots );
					continue;
				}

				$this->assertNotContains( WooPaymentsWooPayPreflightGuard::class, $roots, $state . ' ' . $request_type . ' must not register the WooPay preflight guard.' );
			}
		}
	}

	/** @testdox Should defer merchant routes on connected and active admin requests until internal REST initialization. */
	public function test_provider_matrix_tiers_merchant_rest_routes(): void {
		$matrix = WooPaymentsProvider::get_bootstrap_root_matrix();

		$this->assertNotContains( WooPaymentsMerchantRestController::class, $matrix[ NativePaymentsState::AVAILABLE ]['admin'] );
		$this->assertNotContains( WooPaymentsAdminRestRouteRegistrar::class, $matrix[ NativePaymentsState::AVAILABLE ]['admin'] );
		$this->assertNotContains( WooPaymentsMerchantRestController::class, $matrix[ NativePaymentsState::AVAILABLE ]['rest'] ?? array() );
		$this->assertContains( WooPaymentsMerchantRestController::class, $matrix[ NativePaymentsState::CONNECTED ]['rest'] );
		$this->assertNotContains( WooPaymentsMerchantRestController::class, $matrix[ NativePaymentsState::CONNECTED ]['admin'] );
		$this->assertContains( WooPaymentsAdminRestRouteRegistrar::class, $matrix[ NativePaymentsState::CONNECTED ]['admin'] );
		$this->assertNotContains( WooPaymentsAdminRestRouteRegistrar::class, $matrix[ NativePaymentsState::CONNECTED ]['rest'] );
		$this->assertContains( WooPaymentsMerchantRestController::class, $matrix[ NativePaymentsState::ACTIVE ]['rest'] );
		$this->assertNotContains( WooPaymentsMerchantRestController::class, $matrix[ NativePaymentsState::ACTIVE ]['admin'] );
		$this->assertContains( WooPaymentsAdminRestRouteRegistrar::class, $matrix[ NativePaymentsState::ACTIVE ]['admin'] );
		$this->assertNotContains( WooPaymentsAdminRestRouteRegistrar::class, $matrix[ NativePaymentsState::ACTIVE ]['rest'] );
	}

	/** @testdox Account refresh roots register the webhook recovery listener before refresh handlers can run. */
	public function test_account_refresh_roots_are_followed_by_webhook_reliability_listener(): void {
		foreach ( WooPaymentsProvider::get_bootstrap_root_matrix() as $state => $request_groups ) {
			foreach ( $request_groups as $request => $roots ) {
				$account_index = array_search( WooPaymentsAccountService::class, $roots, true );
				if ( false === $account_index ) {
					continue;
				}

				$this->assertSame(
					WooPaymentsWebhookReliabilityService::class,
					$roots[ $account_index + 1 ] ?? null,
					$state . ' ' . $request . ' must register webhook recovery immediately after the account refresh producer.'
				);
			}
		}
	}

	/**
	 * The recording container clamps the stored tier with its own copy of the rule; the real clamp is checked by
	 * NativePaymentsStateTest::test_get_state_clamps_connected_tiers_while_plugin_is_active.
	 *
	 * @testdox A plugin-owned site with an available effective tier registers nothing, not even shadow mode, by default.
	 */
	public function test_plugin_owner_registers_no_shadow_mode_by_default(): void {
		$container = $this->make_container( NativePaymentsState::ACTIVE, NativePaymentsRuntimeArbiter::OWNER_PLUGIN );
		$sut       = $this->make_bootstrap( array( NativePaymentsShadowMode::class, 'register_when_enabled' ) );

		$sut->register( $container, '__return_false' );

		$this->assertSame( $this->expected_events( array() ), $container->events );
		$this->assertNotContains( NativePaymentsShadowMode::class, $container->resolved );
	}

	/** @testdox Should register only the native shadow exception on plugin-owned shopper requests when explicitly enabled. */
	public function test_plugin_owner_registers_opt_in_shadow_only(): void {
		add_filter( NativePaymentsShadowMode::FILTER_SHADOW_ENABLED, '__return_true' );
		$container = $this->make_container( NativePaymentsState::ACTIVE, NativePaymentsRuntimeArbiter::OWNER_PLUGIN );
		$sut       = $this->make_bootstrap( array( NativePaymentsShadowMode::class, 'register_when_enabled' ) );

		$sut->register( $container, '__return_false' );

		$this->assertSame( $this->expected_events( array( NativePaymentsShadowMode::class ) ), $container->events );
	}

	/**
	 * The recording container returns disabled for owner-less sites with its own copy of the rule; the real rule is checked by
	 * NativePaymentsStateTest::test_get_state_returns_disabled_without_overwriting_the_stored_state_when_no_runtime_owns_the_site.
	 *
	 * @testdox An owner-less site with a disabled effective tier registers nothing, even with shadow mode enabled.
	 */
	public function test_owner_none_registers_nothing_even_with_shadow_mode_enabled(): void {
		add_filter( NativePaymentsShadowMode::FILTER_SHADOW_ENABLED, '__return_true' );
		$container = $this->make_container( NativePaymentsState::ACTIVE, NativePaymentsRuntimeArbiter::OWNER_NONE );
		$sut       = $this->make_bootstrap( array( NativePaymentsShadowMode::class, 'register_when_enabled' ) );

		$sut->register( $container, '__return_false' );

		$this->assertSame( $this->expected_events( array() ), $container->events );
	}

	/** @testdox The provider's plugin-owner hooks are added only on sites the plugin owns, with nothing resolved from the container. */
	public function test_plugin_owner_registrar_runs_only_for_the_plugin_owner(): void {
		$owners = array(
			NativePaymentsRuntimeArbiter::OWNER_PLUGIN => 1,
			NativePaymentsRuntimeArbiter::OWNER_NATIVE => 0,
			NativePaymentsRuntimeArbiter::OWNER_NONE   => 0,
		);

		foreach ( $owners as $owner => $expected_calls ) {
			$container = $this->make_container( NativePaymentsState::DISABLED, $owner );
			$calls     = 0;
			$sut       = new NativePaymentsBootstrap(
				array( WooPaymentsProvider::class, 'get_bootstrap_root_matrix' ),
				array( WooPaymentsProvider::class, 'get_multi_currency_provider_roots' ),
				static function ( $received ) use ( &$calls, $container ): void {
					self::assertSame( $container, $received );
					++$calls;
				}
			);

			$sut->register( $container, '__return_false' );

			$this->assertSame( $expected_calls, $calls, $owner );
			$this->assertSame( $this->expected_events( array() ), $container->events, $owner );
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
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		update_option( 'active_plugins', array_values( array_diff( (array) get_option( 'active_plugins', array() ), array( NativePaymentsRuntimeArbiter::PLUGIN_FILE ) ) ) );
		update_option( NativePaymentsState::OPTION_NAME, $state );
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
			static fn(): array => WooPaymentsProvider::get_bootstrap_root_matrix(),
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
				foreach ( array( NativePaymentsState::AVAILABLE, NativePaymentsState::CONNECTED, NativePaymentsState::ACTIVE ) as $state ) {
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
	 * Build the neutral facade with the WooPayments-owned root matrix.
	 *
	 * @param callable|null $plugin_owner_registrar Provider-owned hooks for plugin-owned sites.
	 * @return NativePaymentsBootstrap
	 */
	private function make_bootstrap( ?callable $plugin_owner_registrar = null ): NativePaymentsBootstrap {
		return new NativePaymentsBootstrap(
			array( WooPaymentsProvider::class, 'get_bootstrap_root_matrix' ),
			array( WooPaymentsProvider::class, 'get_multi_currency_provider_roots' ),
			$plugin_owner_registrar
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
			'get:' . NativePaymentsState::class,
			'get:' . NativePaymentsRuntimeArbiter::class,
		);

		$roots = array_merge( $roots, $on_demand_roots );
		$count = count( $roots );
		for ( $index = 0; $index < $count; ++$index ) {
			$root     = $roots[ $index ];
			$events[] = 'get:' . $root;
			if ( NativePaymentsGatewayRegistry::class === $root ) {
				++$index;
				$events[] = 'provider-resolver:' . $root;
				$events[] = 'register:' . $root;
				continue;
			}
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
			 * Resolve a recording service for every explicit root.
			 *
			 * @param string $class_name Class name.
			 * @return object Recording service.
			 */
			public function get( string $class_name ) {
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

						/** Return the configured native payments owner. */
						public function get_payments_owner(): string {
							return $this->owner;
						}

						/** Return the configured effective native state. */
						public function get_state(): string {
							if ( NativePaymentsRuntimeArbiter::OWNER_NONE === $this->owner ) {
								return NativePaymentsState::DISABLED;
							}

							if (
								NativePaymentsRuntimeArbiter::OWNER_PLUGIN === $this->owner &&
								in_array( $this->state, array( NativePaymentsState::CONNECTED, NativePaymentsState::ACTIVE ), true )
							) {
								return NativePaymentsState::AVAILABLE;
							}

							return $this->state;
						}

						/** @var array<int,callable> Provider resolvers handed to the registry. */
						public array $provider_resolvers = array();

						/**
						 * Record a lazy provider without resolving it.
						 *
						 * @param callable $resolver Provider resolver.
						 */
						public function register_provider_resolver( callable $resolver ): void {
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
