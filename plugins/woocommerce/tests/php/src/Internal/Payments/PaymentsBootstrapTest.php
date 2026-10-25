<?php
/**
 * PaymentsBootstrap tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\DependencyManagement\RuntimeContainer;
use Automattic\WooCommerce\Internal\Features\FeaturesController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFrontendPricesController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRestController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyUsageDetector;
use Automattic\WooCommerce\Internal\Payments\PaymentsBootstrap;
use Automattic\WooCommerce\Internal\Payments\ProviderGatewaysController;
use Automattic\WooCommerce\Internal\Payments\RefundRowCapture;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsStatusChangeRetryListener;
use Automattic\WooCommerce\Internal\Payments\PaymentGatewayProviderInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsCutoverPluginLifecycleListener;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsCutoverReconciliationJob;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsCutoverDataMigration;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Shadow\NativePaymentsShadowMode;
use WC_Unit_Test_Case;

/**
 * Tests for PaymentsBootstrap.
 */
class PaymentsBootstrapTest extends WC_Unit_Test_Case {

	private const WCPAY = 'Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\\';

	/**
	 * @testdox WooCommerce classifies a REST request once and starts Multi-Currency, the provider's Multi-Currency classes first, before the payment provider.
	 */
	public function test_woocommerce_starts_multi_currency_before_payments_on_one_request_type(): void {
		$container   = $this->make_container( WooPaymentsSetupTier::CONNECTED, WooPaymentsRuntimeArbiter::OWNER_BUILTIN, MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true );
		$rest_checks = 0;
		$count       = static function ( $is_rest ) use ( &$rest_checks ) {
			++$rest_checks;
			return $is_rest;
		};
		add_filter( 'woocommerce_is_rest_api_request', $count );
		$previous_uri           = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Saved as is to restore it after the case.
		$_SERVER['REQUEST_URI'] = '/wp-json/wc/v3/products';

		try {
			$this->load_as_woocommerce_does( $container );
		} finally {
			remove_filter( 'woocommerce_is_rest_api_request', $count );
			$this->restore_request_uri( $previous_uri );
		}

		$this->assertSame( 1, $rest_checks, 'The request must be classified once for both bootstraps.' );
		$multi_currency_provider = array_search( WooPaymentsProvider::get_multi_currency_classes()[0], $container->resolved, true );
		$multi_currency_rest     = array_search( MultiCurrencyRestController::class, $container->resolved, true );
		$payments_rest_class     = WooPaymentsProvider::get_classes_by_setup_tier()[ WooPaymentsSetupTier::CONNECTED ]['rest'][1];
		$payments_rest           = array_search( $payments_rest_class, $container->resolved, true );
		$this->assertNotFalse( $multi_currency_provider );
		$this->assertNotFalse( $multi_currency_rest, 'Multi-Currency must register its REST classes on a REST request.' );
		$this->assertNotFalse( $payments_rest, 'The payment provider must register its REST classes on a REST request.' );
		$this->assertLessThan( $multi_currency_rest, $multi_currency_provider );
		$this->assertLessThan( $payments_rest, $multi_currency_rest );
	}

	/**
	 * @testdox WooCommerce's own load ran the composition: the Multi-Currency bootstrap put the extension-flag mirror on the Features change action.
	 */
	public function test_woocommerce_load_runs_the_composition(): void {
		// The test site runs without the WooPayments extension, so the bootstrap adds the mirror whatever the features say.
		$this->assertSame( 10, has_action( FeaturesController::FEATURE_ENABLED_CHANGED_ACTION, array( MultiCurrencyFeatureController::class, 'mirror_extension_flag' ) ) );
	}

	/**
	 * @testdox A woocommerce_is_rest_api_request callback that returns null does not stop WooCommerce's load; the request is not REST.
	 */
	public function test_null_rest_api_request_answer_does_not_stop_the_load(): void {
		$container              = $this->make_container( WooPaymentsSetupTier::CONNECTED, WooPaymentsRuntimeArbiter::OWNER_BUILTIN, MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true );
		$returns_null           = static function () {
			return null;
		};
		$previous_uri           = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Saved as is to restore it after the case.
		$_SERVER['REQUEST_URI'] = '/wp-json/wc/v3/products';
		add_filter( 'woocommerce_is_rest_api_request', $returns_null );

		try {
			$this->load_as_woocommerce_does( $container );
		} finally {
			remove_filter( 'woocommerce_is_rest_api_request', $returns_null );
			$this->restore_request_uri( $previous_uri );
		}

		$this->assertNotContains( MultiCurrencyRestController::class, $container->resolved, 'A null answer is not a REST request.' );
		$this->assertContains( MultiCurrencyFrontendPricesController::class, $container->resolved, 'Multi-Currency must still start.' );
	}

	/** @testdox With woocommerce_load_payment_providers false, WooCommerce starts neither bootstrap and does not classify the request. */
	public function test_load_payment_providers_false_starts_neither_bootstrap(): void {
		add_filter( 'woocommerce_load_payment_providers', '__return_false' );
		$container   = $this->make_container( WooPaymentsSetupTier::ACTIVE, WooPaymentsRuntimeArbiter::OWNER_BUILTIN, MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true );
		$rest_checks = 0;
		$count       = static function ( $is_rest ) use ( &$rest_checks ) {
			++$rest_checks;
			return $is_rest;
		};
		add_filter( 'woocommerce_is_rest_api_request', $count );
		$previous_uri           = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Saved as is to restore it after the case.
		$_SERVER['REQUEST_URI'] = '/wp-json/wc/v3/products';

		try {
			$this->load_as_woocommerce_does( $container );
		} finally {
			remove_filter( 'woocommerce_is_rest_api_request', $count );
			remove_filter( 'woocommerce_load_payment_providers', '__return_false' );
			$this->restore_request_uri( $previous_uri );
		}

		$this->assertSame( array(), $container->events, 'Neither bootstrap may resolve a service.' );
		$this->assertSame( 0, $rest_checks, 'The request must not be classified.' );
	}

	/**
	 * @testdox The gateway provider is resolved only when WooCommerce builds its gateway list.
	 */
	public function test_gateway_provider_is_resolved_only_by_the_gateway_list(): void {
		$container = $this->make_container( WooPaymentsSetupTier::CONNECTED, WooPaymentsRuntimeArbiter::OWNER_BUILTIN );
		$sut       = $this->make_bootstrap();

		$sut->register( $container, 'front' );

		$this->assertNotContains( WooPaymentsProvider::class, $container->resolved, 'Registering the gateway must not build the provider.' );
		$resolvers = $container->get( ProviderGatewaysController::class )->provider_resolvers;
		$this->assertCount( 1, $resolvers );
		$provider = $resolvers[0]();
		$this->assertSame( WooPaymentsProvider::class, $provider->get_recorded_class_name(), 'The resolver must build the listed provider class.' );
	}

	/** @testdox Should resolve and register each listed connected REST class once in order. */
	public function test_register_resolves_connected_rest_classes_once_in_order(): void {
		$container = $this->make_container( WooPaymentsSetupTier::CONNECTED, WooPaymentsRuntimeArbiter::OWNER_BUILTIN );
		$sut       = $this->make_bootstrap();

		$sut->register( $container, 'rest' );

		$this->assertSame( $this->expected_events( WooPaymentsProvider::get_classes_by_setup_tier()[ WooPaymentsSetupTier::CONNECTED ]['rest'] ), $container->events );
		$this->assertSame( array_values( array_unique( $container->resolved ) ), $container->resolved, 'Each listed class should be resolved once.' );
	}

	/**
	 * @testdox A $request request on a $state store registers the cron classes it lacks once, when Action Scheduler first runs an action in it.
	 * @dataProvider page_request_cron_class_gaps
	 *
	 * @param string            $state           Setup tier.
	 * @param string            $request         Request type.
	 * @param array<int,string> $missing_classes Cron classes the request lacks, in cron order.
	 */
	public function test_page_request_registers_its_missing_cron_classes_once_when_an_action_runs( string $state, string $request, array $missing_classes ): void {
		$request_classes = WooPaymentsProvider::get_classes_by_setup_tier()[ $state ][ $request ] ?? array();
		$container       = $this->make_container( $state, WooPaymentsRuntimeArbiter::OWNER_BUILTIN );
		$sut             = $this->make_bootstrap();
		if ( 'admin' === $request ) {
			set_current_screen( 'woocommerce_page_wc-status' );
		}

		$sut->register( $container, $request );
		set_current_screen( 'front' );

		$this->assertSame( $this->expected_events( $request_classes ), $container->events, 'Loading must register only the request classes.' );
		do_action( 'action_scheduler_before_execute', 1, 'WP Cron' );
		do_action( 'action_scheduler_before_execute', 2, 'WP Cron' );
		$this->assertSame( $this->expected_events( $request_classes, $missing_classes ), $container->events, 'The first action must register the missing cron classes, once.' );
	}

	/** @return array<string,array{string,string,array<int,string>}> */
	public static function page_request_cron_class_gaps(): array {
		return array(
			'available front' => array( WooPaymentsSetupTier::AVAILABLE, 'front', array( WooPaymentsCutoverReconciliationJob::class, WooPaymentsStatusChangeRetryListener::class ) ),
			'available admin' => array( WooPaymentsSetupTier::AVAILABLE, 'admin', array( WooPaymentsStatusChangeRetryListener::class ) ),
			'active front'    => array(
				WooPaymentsSetupTier::ACTIVE,
				'front',
				array(
					self::WCPAY . 'Cutover\\WooPaymentsCutoverDataMigration',
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

	/** @testdox Adds no Action Scheduler listener on a disabled store, on cron and WP-CLI requests, or when the request already has every cron class. */
	public function test_adds_no_action_scheduler_listener_when_no_cron_class_is_missing(): void {
		$cases = array(
			'disabled front' => array( WooPaymentsSetupTier::DISABLED, 'front' ),
			'active cron'    => array( WooPaymentsSetupTier::ACTIVE, 'cron' ),
		);
		foreach ( $cases as $label => list( $state, $request ) ) {
			$sut = $this->make_bootstrap();

			$sut->register( $this->make_container( $state, WooPaymentsRuntimeArbiter::OWNER_BUILTIN ), $request );

			$this->assertFalse( has_action( 'action_scheduler_before_execute', array( $sut, 'handle_action_scheduler_before_execute' ) ), $label );
		}
	}

	/**
	 * The recording container uses the real setup tier with a stubbed owner; the owner rule itself is checked by
	 * WooPaymentsSetupTierTest::test_extension_owner_reads_available_whatever_is_stored.
	 *
	 * @testdox An extension-owned site with an available effective tier registers only the plugin lifecycle listener, not shadow mode.
	 */
	public function test_extension_owner_registers_no_shadow_mode_by_default(): void {
		$container = $this->make_container( WooPaymentsSetupTier::ACTIVE, WooPaymentsRuntimeArbiter::OWNER_EXTENSION );
		$sut       = $this->make_bootstrap();

		$sut->register( $container, 'front' );

		// A plugin deactivated over XML-RPC (Jetpack remote management, a front request) must still start the switch.
		$this->assertSame( $this->expected_events( array( WooPaymentsCutoverPluginLifecycleListener::class ) ), $container->events );
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

		$sut->register( $container, 'front' );

		$this->assertSame( $this->expected_events( array() ), $container->events );
	}

	/** @testdox A stored disabled tier registers the available classes on a site the WooPayments extension owns, and nothing on the others. */
	public function test_a_stored_disabled_tier_follows_the_owner(): void {
		$owners = array(
			WooPaymentsRuntimeArbiter::OWNER_EXTENSION => array( WooPaymentsCutoverPluginLifecycleListener::class ),
			WooPaymentsRuntimeArbiter::OWNER_BUILTIN   => array(),
			WooPaymentsRuntimeArbiter::OWNER_NONE      => array(),
		);

		foreach ( $owners as $owner => $expected_classes ) {
			$container = $this->make_container( WooPaymentsSetupTier::DISABLED, $owner );
			$sut       = $this->make_bootstrap();

			$sut->register( $container, 'front' );

			$this->assertSame( $this->expected_events( $expected_classes ), $container->events, $owner );
		}
	}

	/**
	 * @testdox With the real bootstrap, registry and arbiter, the WooPayments gateways leave the gateway list once the built-in WooPayments stops owning payments.
	 */
	public function test_gateway_list_follows_the_arbiter_after_the_built_in_runtime_loses_ownership(): void {
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
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
		$sut = new PaymentsBootstrap(
			static fn(): array => array( WooPaymentsProvider::class ),
			static fn( $container ): bool => $container->get( WooPaymentsRuntimeArbiter::class )->is_builtin_owner()
		);

		try {
			$sut->register( wc_get_container(), 'front' );
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Building the gateway list as WooCommerce does.
			$this->assertSame( array( $gateway ), apply_filters( 'woocommerce_payment_gateways', array() ), 'The gateway must be listed while the built-in WooPayments owns payments.' );
			$this->assertSame( 1, $provider->gateway_reads );

			update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'no' );
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
	 * WooCommerce runs both bootstraps inside its constructor, before WC() has an instance to return, so any WC() call on
	 * this path constructs WooCommerce again and recurses until PHP gives up. The test reproduces that by clearing the
	 * instance; a WC() call then re-enters WooCommerce's composition, which the woocommerce_load_payment_providers filter
	 * records.
	 *
	 * @testdox The payments and Multi-Currency bootstrap makes no WC() call in a $request request with the $theme theme in the $state tier.
	 * @dataProvider bootstrap_request_themes
	 *
	 * @param string $request Request type: front, ajax, rest, admin, cron or cli.
	 * @param string $theme   Active theme stylesheet and template.
	 * @param string $state   Setup tier.
	 */
	public function test_bootstrap_makes_no_wc_call( string $request, string $theme, string $state ): void {
		add_filter( 'pre_http_request', static fn() => new \WP_Error( 'blocked', 'Outbound HTTP is blocked in this test.' ) );
		add_filter( 'pre_option_stylesheet', static fn() => $theme );
		add_filter( 'pre_option_template', static fn() => $theme );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
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
		add_filter( 'woocommerce_load_payment_providers', $recorder );

		$instance = new \ReflectionProperty( \WooCommerce::class, '_instance' );
		$instance->setAccessible( true );
		$woocommerce = $instance->getValue();
		$instance->setValue( null, null );
		try {
			$this->load_as( $woocommerce, $request );
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
	 * Run WooCommerce's payments and Multi-Currency composition in one request type, with the shared container.
	 *
	 * @param \WooCommerce $woocommerce The WooCommerce instance whose composition runs.
	 * @param string       $request     Request type: front, ajax, rest, admin, cron or cli.
	 */
	private function load_as( \WooCommerce $woocommerce, string $request ): void {
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
		$previous_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Saved as is to restore it after the case.
		if ( 'rest' === $request ) {
			$_SERVER['REQUEST_URI'] = '/wp-json/wc/v3/products';
		}

		try {
			$this->invoke_composition( $woocommerce, wc_get_container() );
		} finally {
			if ( null !== $filter ) {
				remove_filter( $filter, '__return_true' );
			}
			Constants::clear_single_constant( 'WP_CLI' );
			set_current_screen( 'front' );
			$this->restore_request_uri( $previous_uri );
		}
	}

	/**
	 * Put back the request URI a case replaced.
	 *
	 * @param string|null $previous_uri The request URI before the case, or null when it had none.
	 */
	private function restore_request_uri( ?string $previous_uri ): void {
		if ( null === $previous_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
			return;
		}
		$_SERVER['REQUEST_URI'] = $previous_uri;
	}

	/**
	 * Run WooCommerce's payments and Multi-Currency composition with a recording container.
	 *
	 * @param RuntimeContainer $container Recording container.
	 */
	private function load_as_woocommerce_does( RuntimeContainer $container ): void {
		$this->invoke_composition( WC(), $container );
	}

	/**
	 * Call the private method WooCommerce::init_hooks() starts both bootstraps through.
	 *
	 * @param \WooCommerce                                       $woocommerce WooCommerce instance.
	 * @param \Automattic\WooCommerce\Container|RuntimeContainer $container   Dependency container.
	 */
	private function invoke_composition( \WooCommerce $woocommerce, $container ): void {
		$method = new \ReflectionMethod( \WooCommerce::class, 'load_payments_and_multi_currency' );
		$method->setAccessible( true );
		$method->invoke( $woocommerce, $container );
	}

	/**
	 * Build the bootstrap with the WooPayments setup tier's class lists, as WooCommerce does.
	 *
	 * @return PaymentsBootstrap
	 */
	private function make_bootstrap(): PaymentsBootstrap {
		return new PaymentsBootstrap(
			static fn( $container, string $request_type ): array => $container->get( WooPaymentsSetupTier::class )->get_classes_for_request( $request_type ),
			static fn( $container ): bool => $container->get( WooPaymentsRuntimeArbiter::class )->is_builtin_owner()
		);
	}

	/**
	 * Build expected facade events from a literal class list.
	 *
	 * @param array<int,string> $classes           Listed classes.
	 * @param array<int,string> $on_demand_classes Classes registered later, when Action Scheduler runs an action.
	 * @return array<int,string>
	 */
	private function expected_events( array $classes, array $on_demand_classes = array() ): array {
		$events = array();

		foreach ( array_merge( $classes, $on_demand_classes ) as $class_name ) {
			if ( is_a( $class_name, PaymentGatewayProviderInterface::class, true ) ) {
				$events[] = 'get:' . ProviderGatewaysController::class;
				$events[] = 'provider-resolver:' . ProviderGatewaysController::class;
				$events[] = 'register:' . ProviderGatewaysController::class;
				$events[] = 'get:' . RefundRowCapture::class;
				$events[] = 'register:' . RefundRowCapture::class;
				continue;
			}
			$events[] = 'get:' . $class_name;
			$events[] = 'register:' . $class_name;
		}

		return $events;
	}

	/**
	 * Build a container that records explicit resolution and registration order.
	 *
	 * @param string $state                Setup tier.
	 * @param string $owner                WooPayments runtime owner.
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
			 * @param string $state                Setup tier.
			 * @param string $owner                WooPayments runtime owner.
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
						 * @param string $owner WooPayments runtime owner.
						 */
						public function __construct( string $owner ) {
							$this->owner = $owner;
						}

						/** Return the case's owner. */
						public function get_runtime_owner(): string {
							return $this->owner;
						}

						/** A site with an owner has the WooPayments feature on, as these cases assume. */
						public function is_builtin_enabled(): bool {
							return WooPaymentsRuntimeArbiter::OWNER_NONE !== $this->owner;
						}
					};
					$tier    = new WooPaymentsSetupTier();
					$tier->init( $arbiter );
					$this->services[ WooPaymentsSetupTier::class ] = $tier;
				}

				return $this->services[ WooPaymentsSetupTier::class ];
			}

			/**
			 * Resolve a recording service for every listed class.
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
						 * @param string            $state                Setup tier.
						 * @param string            $owner                WooPayments runtime owner.
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

						/** Return the configured Multi-Currency or WooPayments owner. */
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
						public function set_provider( callable $resolver, callable $should_register_gateways ): void {
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
