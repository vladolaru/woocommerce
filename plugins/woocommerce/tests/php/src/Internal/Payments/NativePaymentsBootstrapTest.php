<?php
/**
 * NativePaymentsBootstrap tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsMerchantRestController;
use Automattic\WooCommerce\Internal\DependencyManagement\RuntimeContainer;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsBootstrap;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsGatewayRegistry;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsState;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverNormalizationRunner;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWebhookReliabilityService;
use Automattic\WooCommerce\Internal\Payments\Shadow\NativePaymentsShadowMode;
use ReflectionMethod;
use WC_Unit_Test_Case;

/**
 * Tests for NativePaymentsBootstrap.
 */
class NativePaymentsBootstrapTest extends WC_Unit_Test_Case {

	private const ADMIN_NAVIGATION = 'Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsAdminNavigationController';

	private const WCPAY = 'Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\\';

	/** Available admin roots. */
	private const AVAILABLE_ADMIN = array(
		self::WCPAY . 'WooPaymentsCutoverController',
	);

	/** Connected admin roots. */
	private const CONNECTED_ADMIN = array(
		self::WCPAY . 'WooPaymentsCutoverController',
		self::ADMIN_NAVIGATION,
		self::WCPAY . 'WooPaymentsAccountService',
		self::WCPAY . 'WooPaymentsWebhookReliabilityService',
		self::WCPAY . 'WooPaymentsCustomerService',
		self::WCPAY . 'WooPaymentsOrderFraudMetaBox',
		self::WCPAY . 'WooPaymentsOrderAdminActionsController',
		self::WCPAY . 'WooPaymentsOrderStatusChangeController',
		self::WCPAY . 'WooPay\WooPaymentsWooPayOrderStatusSync',
		self::WCPAY . 'WooPay\WooPaymentsWooPayExtensionSync',
		self::WCPAY . 'WooPaymentsApplePayDomainService',
		self::WCPAY . 'WooPaymentsCurrencyComplianceNotice',
		self::WCPAY . 'WooPaymentsOrderTrackingService',
		self::WCPAY . 'WooPaymentsOperationalQueueService',
	);

	/** Connected AJAX roots. */
	private const CONNECTED_AJAX = array(
		self::WCPAY . 'WooPaymentsAccountService',
		self::WCPAY . 'WooPaymentsWebhookReliabilityService',
		self::WCPAY . 'WooPaymentsCustomerService',
		self::WCPAY . 'WooPaymentsOrderAdminActionsController',
		self::WCPAY . 'WooPaymentsOrderStatusChangeController',
		self::WCPAY . 'WooPay\WooPaymentsWooPayOrderStatusSync',
		self::WCPAY . 'WooPay\WooPaymentsWooPayExtensionSync',
		self::WCPAY . 'WooPaymentsApplePayDomainService',
		self::WCPAY . 'WooPaymentsOrderTrackingService',
		self::WCPAY . 'WooPaymentsOperationalQueueService',
	);

	/** Connected REST roots. */
	private const CONNECTED_REST = array(
		self::WCPAY . 'WooPaymentsAccountService',
		self::WCPAY . 'WooPaymentsWebhookReliabilityService',
		WooPaymentsMerchantRestController::class,
		self::WCPAY . 'WooPaymentsCustomerService',
		self::WCPAY . 'WooPaymentsOrderAdminActionsController',
		self::WCPAY . 'WooPay\WooPaymentsWooPayOrderStatusSync',
		self::WCPAY . 'WooPaymentsApplePayDomainService',
		self::WCPAY . 'WooPaymentsWebhookRestController',
		self::WCPAY . 'WooPaymentsMobileRestController',
		self::WCPAY . 'WooPaymentsAccountSessionRestController',
		self::WCPAY . 'WooPaymentsCustomersRestController',
		self::WCPAY . 'WooPaymentsDepositsRestController',
		self::WCPAY . 'WooPaymentsPaymentDetailsRestController',
		self::WCPAY . 'WooPaymentsAuthorizationsRestController',
		self::WCPAY . 'WooPaymentsTransactionsRestController',
		self::WCPAY . 'WooPaymentsDisputesRestController',
		self::WCPAY . 'WooPaymentsDisputeReadinessRestController',
		self::WCPAY . 'WooPaymentsCapitalRestController',
		self::WCPAY . 'WooPaymentsDocumentsRestController',
		self::WCPAY . 'WooPaymentsReportsRestController',
		self::WCPAY . 'WooPaymentsTosRestController',
		self::WCPAY . 'WooPaymentsOrderTrackingService',
		self::WCPAY . 'WooPaymentsOperationalQueueService',
	);

	/** Connected cron and Action Scheduler roots. */
	private const CONNECTED_CRON = array(
		self::WCPAY . 'WooPaymentsAccountService',
		self::WCPAY . 'WooPaymentsWebhookReliabilityService',
		self::WCPAY . 'WooPaymentsOperationalQueueService',
		self::WCPAY . 'WooPaymentsOrderTrackingService',
		self::WCPAY . 'WooPay\WooPaymentsWooPayOrderStatusSync',
		self::WCPAY . 'WooPay\WooPaymentsWooPayExtensionSync',
		self::WCPAY . 'WooPaymentsApplePayDomainService',
		self::WCPAY . 'WooPaymentsCanceledAuthorizationFeeRemediationService',
		self::WCPAY . 'WooPaymentsOrderAdminActionsController',
	);

	/** Active shopper roots. */
	private const ACTIVE_FRONT = array(
		NativePaymentsGatewayRegistry::class,
		WooPaymentsProvider::class,
		self::WCPAY . 'WooPaymentsAccountService',
		self::WCPAY . 'WooPaymentsWebhookReliabilityService',
		self::WCPAY . 'WooPaymentsFrontendStylesService',
		self::WCPAY . 'WooPaymentsCheckoutBridge',
		self::WCPAY . 'WooPaymentsAddressProvider',
		self::WCPAY . 'WooPaymentsCustomerService',
		self::WCPAY . 'WooPaymentsDuplicatePaymentPreventionService',
		self::WCPAY . 'WooPaymentsRedirectReturnController',
		self::WCPAY . 'WooPaymentsOrderAdminActionsController',
		self::WCPAY . 'WooPaymentsOrderStatusChangeController',
		self::WCPAY . 'WooPaymentsTokenizedCartSessionController',
		self::WCPAY . 'WooPaymentsWooPaySessionController',
		self::WCPAY . 'WooPay\WooPaymentsWooPayOrderStatusSync',
		self::WCPAY . 'WooPay\WooPaymentsWooPayExtensionSync',
		self::WCPAY . 'WooPaymentsExpressCheckoutController',
		self::WCPAY . 'WooPaymentsOrderSuccessPage',
		self::WCPAY . 'WooPaymentsPaymentMethodMessaging',
		self::WCPAY . 'WooPaymentsTokenClassMapController',
		self::WCPAY . 'WooPaymentsApplePayDomainService',
		self::WCPAY . 'WooPaymentsFrontendTrackingController',
		self::WCPAY . 'WooPaymentsOrderTrackingService',
		self::WCPAY . 'WooPaymentsOperationalQueueService',
	);

	/** Active admin roots. */
	private const ACTIVE_ADMIN = array(
		self::WCPAY . 'WooPaymentsCutoverNormalizationRunner',
		NativePaymentsGatewayRegistry::class,
		WooPaymentsProvider::class,
		self::WCPAY . 'WooPaymentsCutoverController',
		self::ADMIN_NAVIGATION,
		self::WCPAY . 'WooPaymentsAccountService',
		self::WCPAY . 'WooPaymentsWebhookReliabilityService',
		self::WCPAY . 'WooPaymentsCustomerService',
		self::WCPAY . 'WooPaymentsOrderFraudMetaBox',
		self::WCPAY . 'WooPaymentsOrderAdminActionsController',
		self::WCPAY . 'WooPaymentsOrderStatusChangeController',
		self::WCPAY . 'WooPay\WooPaymentsWooPayOrderStatusSync',
		self::WCPAY . 'WooPay\WooPaymentsWooPayExtensionSync',
		self::WCPAY . 'WooPaymentsApplePayDomainService',
		self::WCPAY . 'WooPaymentsCurrencyComplianceNotice',
		self::WCPAY . 'WooPaymentsOrderTrackingService',
		self::WCPAY . 'WooPaymentsOperationalQueueService',
	);

	/** Active AJAX roots. */
	private const ACTIVE_AJAX = array(
		NativePaymentsGatewayRegistry::class,
		WooPaymentsProvider::class,
		self::WCPAY . 'WooPaymentsAccountService',
		self::WCPAY . 'WooPaymentsWebhookReliabilityService',
		self::WCPAY . 'WooPaymentsCustomerService',
		self::WCPAY . 'WooPaymentsOrderAdminActionsController',
		self::WCPAY . 'WooPaymentsOrderStatusChangeController',
		self::WCPAY . 'WooPay\WooPaymentsWooPayOrderStatusSync',
		self::WCPAY . 'WooPay\WooPaymentsWooPayExtensionSync',
		self::WCPAY . 'WooPaymentsApplePayDomainService',
		self::WCPAY . 'WooPaymentsOrderTrackingService',
		self::WCPAY . 'WooPaymentsOperationalQueueService',
		self::WCPAY . 'WooPaymentsCheckoutBridge',
		self::WCPAY . 'WooPaymentsAddressProvider',
		self::WCPAY . 'WooPaymentsDuplicatePaymentPreventionService',
		self::WCPAY . 'WooPaymentsCheckoutAjaxController',
		self::WCPAY . 'WooPaymentsTokenizedCartSessionController',
		self::WCPAY . 'WooPaymentsWooPaySessionController',
		self::WCPAY . 'WooPaymentsExpressCheckoutController',
		self::WCPAY . 'WooPaymentsPaymentMethodMessaging',
		self::WCPAY . 'WooPaymentsTokenClassMapController',
		self::WCPAY . 'WooPaymentsFrontendTrackingController',
	);

	/** Active REST and Store API roots. */
	private const ACTIVE_REST = array(
		NativePaymentsGatewayRegistry::class,
		WooPaymentsProvider::class,
		self::WCPAY . 'WooPaymentsAccountService',
		self::WCPAY . 'WooPaymentsWebhookReliabilityService',
		WooPaymentsMerchantRestController::class,
		self::WCPAY . 'WooPaymentsCustomerService',
		self::WCPAY . 'WooPaymentsOrderAdminActionsController',
		self::WCPAY . 'WooPay\WooPaymentsWooPayOrderStatusSync',
		self::WCPAY . 'WooPaymentsApplePayDomainService',
		self::WCPAY . 'WooPaymentsWebhookRestController',
		self::WCPAY . 'WooPaymentsMobileRestController',
		self::WCPAY . 'WooPaymentsAccountSessionRestController',
		self::WCPAY . 'WooPaymentsCustomersRestController',
		self::WCPAY . 'WooPaymentsDepositsRestController',
		self::WCPAY . 'WooPaymentsPaymentDetailsRestController',
		self::WCPAY . 'WooPaymentsAuthorizationsRestController',
		self::WCPAY . 'WooPaymentsTransactionsRestController',
		self::WCPAY . 'WooPaymentsDisputesRestController',
		self::WCPAY . 'WooPaymentsDisputeReadinessRestController',
		self::WCPAY . 'WooPaymentsCapitalRestController',
		self::WCPAY . 'WooPaymentsDocumentsRestController',
		self::WCPAY . 'WooPaymentsReportsRestController',
		self::WCPAY . 'WooPaymentsTosRestController',
		self::WCPAY . 'WooPaymentsOrderTrackingService',
		self::WCPAY . 'WooPaymentsOperationalQueueService',
		self::WCPAY . 'WooPaymentsCheckoutBridge',
		self::WCPAY . 'WooPaymentsAddressProvider',
		self::WCPAY . 'WooPaymentsDuplicatePaymentPreventionService',
		self::WCPAY . 'WooPaymentsTokenizedCartSessionController',
		self::WCPAY . 'WooPaymentsWooPaySessionController',
		self::WCPAY . 'WooPaymentsExpressCheckoutController',
		self::WCPAY . 'WooPaymentsExpressCheckoutStoreApiExtension',
		self::WCPAY . 'WooPaymentsExpressCheckoutCurrencyGuard',
		self::WCPAY . 'WooPaymentsTokenClassMapController',
	);

	/** Active cron and Action Scheduler roots. */
	private const ACTIVE_CRON = array(
		self::WCPAY . 'WooPaymentsCutoverNormalizationRunner',
		NativePaymentsGatewayRegistry::class,
		WooPaymentsProvider::class,
		self::WCPAY . 'WooPaymentsAccountService',
		self::WCPAY . 'WooPaymentsWebhookReliabilityService',
		self::WCPAY . 'WooPaymentsOperationalQueueService',
		self::WCPAY . 'WooPaymentsOrderTrackingService',
		self::WCPAY . 'WooPay\WooPaymentsWooPayOrderStatusSync',
		self::WCPAY . 'WooPay\WooPaymentsWooPayExtensionSync',
		self::WCPAY . 'WooPaymentsApplePayDomainService',
		self::WCPAY . 'WooPaymentsCanceledAuthorizationFeeRemediationService',
		self::WCPAY . 'WooPaymentsOrderAdminActionsController',
		self::WCPAY . 'WooPaymentsOrderStatusChangeController',
		self::WCPAY . 'WooPaymentsDuplicatePaymentPreventionService',
	);

	/** @testdox Production composition supplies the provider root matrix through a lazy closure. */
	public function test_production_composition_uses_lazy_provider_matrix_resolver(): void {
		$method       = new ReflectionMethod( \WooCommerce::class, 'init_hooks' );
		$source_lines = file( $method->getFileName() );
		$method_body  = implode( '', array_slice( $source_lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );

		$this->assertStringContainsString(
			'static fn(): array => Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsProvider::get_bootstrap_root_matrix()',
			$method_body,
			'The provider matrix resolver must remain lazy so Tier 0 does not autoload provider roots.'
		);
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
			}
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
	 * @testdox Should select the exact explicit roots for each unique tier and request composition.
	 * @dataProvider unique_matrix_compositions
	 *
	 * @param string            $state          Native state.
	 * @param string            $request_type   Request type.
	 * @param array<int,string> $expected_roots Expected explicit roots.
	 */
	public function test_resolves_exact_matrix_roots_once_in_order( string $state, string $request_type, array $expected_roots ): void {
		$sut       = $this->make_bootstrap();
		$roots_for = new ReflectionMethod( NativePaymentsBootstrap::class, 'roots_for' );
		$roots_for->setAccessible( true );

		$this->assertSame( $expected_roots, $roots_for->invoke( $sut, $state, $request_type ), 'The matrix cell should select only its explicit roots in order.' );
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

	/** @testdox Should resolve and register each explicit connected REST root once in order. */
	public function test_register_resolves_connected_rest_roots_once_in_order(): void {
		$container = $this->make_container( NativePaymentsState::CONNECTED, NativePaymentsRuntimeArbiter::OWNER_NATIVE );
		$sut       = $this->make_bootstrap();

		$sut->register( $container, '__return_true' );

		$this->assertSame( $this->expected_events( self::CONNECTED_REST ), $container->events );
		$this->assertSame( array_values( array_unique( $container->resolved ) ), $container->resolved, 'Each explicit root should be resolved once.' );
	}

	/** @testdox Should make only the independent ownership decisions for a disabled tier. */
	public function test_register_resolves_no_native_roots_for_disabled_tier(): void {
		$container = $this->make_container( NativePaymentsState::DISABLED, NativePaymentsRuntimeArbiter::OWNER_NATIVE );
		$sut       = $this->make_bootstrap();

		$sut->register( $container, '__return_false' );

		$this->assertSame( $this->expected_events( array() ), $container->events );
	}

	/** @return array<string,array{string,string,array<int,string>}> */
	public static function unique_matrix_compositions(): array {
		return array(
			'disabled'        => array( NativePaymentsState::DISABLED, 'admin', array() ),
			'available no-op' => array( NativePaymentsState::AVAILABLE, 'front', array() ),
			'available admin' => array( NativePaymentsState::AVAILABLE, 'admin', self::AVAILABLE_ADMIN ),
			'connected no-op' => array( NativePaymentsState::CONNECTED, 'front', array() ),
			'connected admin' => array( NativePaymentsState::CONNECTED, 'admin', self::CONNECTED_ADMIN ),
			'connected AJAX'  => array( NativePaymentsState::CONNECTED, 'ajax', self::CONNECTED_AJAX ),
			'connected REST'  => array( NativePaymentsState::CONNECTED, 'rest', self::CONNECTED_REST ),
			'connected cron'  => array( NativePaymentsState::CONNECTED, 'cron', self::CONNECTED_CRON ),
			'connected CLI'   => array( NativePaymentsState::CONNECTED, 'cli', array() ),
			'active front'    => array( NativePaymentsState::ACTIVE, 'front', self::ACTIVE_FRONT ),
			'active admin'    => array( NativePaymentsState::ACTIVE, 'admin', self::ACTIVE_ADMIN ),
			'active AJAX'     => array( NativePaymentsState::ACTIVE, 'ajax', self::ACTIVE_AJAX ),
			'active REST'     => array( NativePaymentsState::ACTIVE, 'rest', self::ACTIVE_REST ),
			'active cron'     => array( NativePaymentsState::ACTIVE, 'cron', self::ACTIVE_CRON ),
			'active CLI'      => array( NativePaymentsState::ACTIVE, 'cli', array() ),
		);
	}

	/** @testdox Provider roots are real classes and mutation requests retain their required observers. */
	public function test_provider_matrix_keeps_required_mutation_observers(): void {
		$matrix         = WooPaymentsProvider::get_bootstrap_root_matrix();
		$connected_rest = $matrix[ NativePaymentsState::CONNECTED ]['rest'];
		$active_front   = $matrix[ NativePaymentsState::ACTIVE ]['front'];

		foreach ( $matrix as $request_groups ) {
			foreach ( $request_groups as $roots ) {
				foreach ( $roots as $root ) {
					$this->assertTrue( class_exists( $root ), $root . ' must be a real class reference.' );
				}
			}
		}

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

	/** @testdox Should expose merchant routes only for connected and active REST requests. */
	public function test_provider_matrix_tiers_merchant_rest_routes(): void {
		$matrix = WooPaymentsProvider::get_bootstrap_root_matrix();

		$this->assertNotContains( WooPaymentsMerchantRestController::class, $matrix[ NativePaymentsState::AVAILABLE ]['admin'] );
		$this->assertArrayNotHasKey( 'rest', $matrix[ NativePaymentsState::AVAILABLE ] );
		$this->assertContains( WooPaymentsMerchantRestController::class, $matrix[ NativePaymentsState::CONNECTED ]['rest'] );
		$this->assertNotContains( WooPaymentsMerchantRestController::class, $matrix[ NativePaymentsState::CONNECTED ]['admin'] );
		$this->assertContains( WooPaymentsMerchantRestController::class, $matrix[ NativePaymentsState::ACTIVE ]['rest'] );
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

	/** @testdox Should classify every request type and preserve CLI, cron, AJAX, REST, and admin precedence. */
	public function test_classifies_all_request_types_with_collision_precedence(): void {
		$this->assertTrue( method_exists( NativePaymentsBootstrap::class, 'classify_signals' ), 'The facade should own the in-place classifier.' );
		if ( ! method_exists( NativePaymentsBootstrap::class, 'classify_signals' ) ) {
			return;
		}

		$classifier = new ReflectionMethod( NativePaymentsBootstrap::class, 'classify_signals' );
		$classifier->setAccessible( true );
		$cases = array(
			'CLI'            => array( array( true, false, false, false, false ), 'cli' ),
			'cron'           => array( array( false, true, false, false, false ), 'cron' ),
			'AJAX'           => array( array( false, false, true, false, false ), 'ajax' ),
			'REST'           => array( array( false, false, false, true, false ), 'rest' ),
			'admin'          => array( array( false, false, false, false, true ), 'admin' ),
			'front'          => array( array( false, false, false, false, false ), 'front' ),
			'CLI collision'  => array( array( true, true, true, true, true ), 'cli' ),
			'cron collision' => array( array( false, true, true, true, true ), 'cron' ),
			'AJAX collision' => array( array( false, false, true, true, true ), 'ajax' ),
			'REST collision' => array( array( false, false, false, true, true ), 'rest' ),
		);

		foreach ( $cases as $label => list( $signals, $expected ) ) {
			$this->assertSame( $expected, $classifier->invokeArgs( null, $signals ), $label );
		}
	}

	/** @testdox Plugin-owned sites retain the available effective state without default shadow cost. */
	public function test_plugin_owner_uses_the_available_effective_state_without_default_shadow(): void {
		$container = $this->make_container( NativePaymentsState::ACTIVE, NativePaymentsRuntimeArbiter::OWNER_PLUGIN );
		$sut       = $this->make_bootstrap();

		$sut->register( $container, '__return_false' );

		$this->assertSame( $this->expected_events( array() ), $container->events );
		$this->assertNotContains( NativePaymentsShadowMode::class, $container->resolved );
	}

	/** @testdox Should register only the native shadow exception on plugin-owned shopper requests when explicitly enabled. */
	public function test_plugin_owner_registers_opt_in_shadow_only(): void {
		add_filter( NativePaymentsShadowMode::FILTER_SHADOW_ENABLED, '__return_true' );
		$container = $this->make_container( NativePaymentsState::ACTIVE, NativePaymentsRuntimeArbiter::OWNER_PLUGIN );
		$sut       = $this->make_bootstrap();

		$sut->register( $container, '__return_false' );

		$this->assertSame( $this->expected_events( array( NativePaymentsShadowMode::class ) ), $container->events );
	}

	/** @testdox Owner-less sites receive a disabled effective state. */
	public function test_owner_none_uses_a_disabled_effective_state(): void {
		add_filter( NativePaymentsShadowMode::FILTER_SHADOW_ENABLED, '__return_true' );
		$container = $this->make_container( NativePaymentsState::ACTIVE, NativePaymentsRuntimeArbiter::OWNER_NONE );
		$sut       = $this->make_bootstrap();

		$sut->register( $container, '__return_false' );

		$this->assertSame( $this->expected_events( array() ), $container->events );
	}

	/**
	 * Build the neutral facade with the WooPayments-owned root matrix.
	 *
	 * @return NativePaymentsBootstrap
	 */
	private function make_bootstrap(): NativePaymentsBootstrap {
		return new NativePaymentsBootstrap( array( WooPaymentsProvider::class, 'get_bootstrap_root_matrix' ) );
	}

	/**
	 * Build expected facade events from a literal root list.
	 *
	 * @param array<int,string> $roots Explicit roots.
	 * @return array<int,string>
	 */
	private function expected_events( array $roots ): array {
		$events = array(
			'get:' . MultiCurrencyRuntimeArbiter::class,
			'get:' . NativePaymentsState::class,
			'get:' . NativePaymentsRuntimeArbiter::class,
		);

		$count = count( $roots );
		for ( $index = 0; $index < $count; ++$index ) {
			$root     = $roots[ $index ];
			$events[] = 'get:' . $root;
			if ( NativePaymentsGatewayRegistry::class === $root ) {
				$provider = $roots[ ++$index ];
				$events[] = 'get:' . $provider;
				$events[] = 'provider:' . $provider;
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
	 * @param string $state Native state.
	 * @param string $owner Native runtime owner.
	 * @return RuntimeContainer&object{resolved:array<int,string>,events:array<int,string>}
	 */
	private function make_container( string $state, string $owner ): RuntimeContainer {
		return new class( $state, $owner ) extends RuntimeContainer {
			/** @var array<int,string> */
			public array $resolved = array();

			/** @var array<int,string> */
			public array $events = array();

			/** @var string */
			private $state;

			/** @var string */
			private $owner;

			/** @var array<string,object> */
			private array $services = array();

			/**
			 * Initialize the recording container.
			 *
			 * @param string $state Native state.
			 * @param string $owner Native runtime owner.
			 */
			public function __construct( string $state, string $owner ) {
				parent::__construct( array() );
				$this->state = $state;
				$this->owner = $owner;
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

				if ( ! isset( $this->services[ $class_name ] ) ) {
					$events                        = &$this->events;
					$state                         = $this->state;
					$owner                         = $this->owner;
					$this->services[ $class_name ] = new class( $class_name, $events, $state, $owner ) {
						/** @var string */
						private $class_name;

						/** @var array<int,string> */
						private $events;

						/** @var string */
						private $state;

						/** @var string */
						private $owner;

						/**
						 * Initialize the service recorder.
						 *
						 * @param string            $class_name Class name.
						 * @param array<int,string> $events     Recorded events.
						 * @param string            $state      Native state.
						 * @param string            $owner      Native runtime owner.
						 */
						public function __construct( string $class_name, array &$events, string $state, string $owner ) {
							$this->class_name = $class_name;
							$this->events     = &$events;
							$this->state      = $state;
							$this->owner      = $owner;
						}

						/** Return the recorded class name. */
						public function get_recorded_class_name(): string {
							return $this->class_name;
						}

						/** Return the configured Multi-Currency or native owner. */
						public function get_runtime_owner(): string {
							return MultiCurrencyRuntimeArbiter::class === $this->class_name ? MultiCurrencyRuntimeArbiter::OWNER_NONE : $this->owner;
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

						/**
						 * Record provider insertion.
						 *
						 * @param object $provider Recording provider.
						 */
						public function register_provider( object $provider ): void {
							$this->events[] = 'provider:' . $provider->get_recorded_class_name();
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
