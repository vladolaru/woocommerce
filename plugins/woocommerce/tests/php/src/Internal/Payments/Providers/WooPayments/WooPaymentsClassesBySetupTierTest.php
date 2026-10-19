<?php
/**
 * WooPaymentsProvider class-list tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\WooPaymentsAdminNavigationController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\WooPaymentsAdminSharedSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\WooPaymentsMerchantRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\WooPaymentsHomeTasks;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\WooPaymentsUserPreferenceFields;
use Automattic\WooCommerce\Internal\Payments\ProviderGatewaysController;
use Automattic\WooCommerce\Internal\Payments\PaymentGatewayProviderInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsConnectionFeatures;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminRestRouteRegistrar;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsCutoverNormalizationRunner;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverPluginLifecycleListener;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverReconciliationJob;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsWebhookReliabilityService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWooCommerceUpdateListener;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayPreflightGuard;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use WC_Unit_Test_Case;

/**
 * Tests for the classes WooPaymentsProvider::get_classes_by_setup_tier() lists for each setup tier and request type.
 */
class WooPaymentsClassesBySetupTierTest extends WC_Unit_Test_Case {

	private const WCPAY = 'Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\\';

	/**
	 * @testdox The Fee details job's handler, the store setup report and the account milestones load on the same requests.
	 */
	public function test_scheduled_job_handlers_load_on_the_same_requests(): void {
		$handlers = array( 'WooPaymentsFeeDetailsNoteController', 'WooPaymentsStoreSetupReportController', 'WooPaymentsAccountMilestonesController' );
		foreach ( WooPaymentsProvider::get_classes_by_setup_tier() as $state => $requests ) {
			foreach ( $requests as $request => $roots ) {
				$present = array_values( array_filter( $handlers, static fn( string $handler ): bool => in_array( self::WCPAY . $handler, $roots, true ) ) );
				$this->assertContains( count( $present ), array( 0, count( $handlers ) ), "The $state $request request must register all of these scheduled-job handlers or none of them." );
			}
		}
	}

	/**
	 * @testdox WP-CLI gets the cron roots in every set-up tier, so Action Scheduler runs under WP-CLI reach their handlers.
	 */
	public function test_wp_cli_gets_the_cron_roots_in_every_set_up_tier(): void {
		$matrix = WooPaymentsProvider::get_classes_by_setup_tier();

		foreach ( array( WooPaymentsSetupTier::AVAILABLE, WooPaymentsSetupTier::CONNECTED, WooPaymentsSetupTier::ACTIVE ) as $state ) {
			$cron_roots = ( $matrix[ $state ]['cron'] ?? array() );
			$this->assertNotEmpty( $cron_roots, "The $state cron cell must not be empty." );
			$this->assertSame( $cron_roots, ( $matrix[ $state ]['cli'] ?? array() ), "The $state WP-CLI request must register the cron roots." );
		}
		$this->assertSame( array(), ( $matrix[ WooPaymentsSetupTier::DISABLED ]['cli'] ?? array() ), 'A disabled tier registers nothing under WP-CLI.' );
	}

	/**
	 * @testdox The plugin lifecycle listener loads on every request class where WordPress can activate or deactivate a plugin.
	 */
	public function test_plugin_lifecycle_listener_loads_wherever_plugins_change(): void {
		$matrix = WooPaymentsProvider::get_classes_by_setup_tier();

		// activate_plugin() and deactivate_plugins() fire the lifecycle hooks in any request that calls them: wp-admin,
		// admin-ajax, the wp/v2/plugins and wc-admin REST routes, Action Scheduler (cron), WP-CLI, and XML-RPC (front),
		// where Jetpack's remote plugin management runs.
		foreach ( array( WooPaymentsSetupTier::AVAILABLE, WooPaymentsSetupTier::CONNECTED, WooPaymentsSetupTier::ACTIVE ) as $state ) {
			foreach ( array( 'front', 'admin', 'ajax', 'rest', 'cron', 'cli' ) as $request ) {
				$this->assertContains( WooPaymentsCutoverPluginLifecycleListener::class, ( $matrix[ $state ][ $request ] ?? array() ), "The $state $request request must load the plugin lifecycle listener." );
			}
			// The controller owns the admin notices and the click, so it stays on admin requests.
			foreach ( array( 'ajax', 'rest', 'cron', 'cli' ) as $request ) {
				$this->assertNotContains( WooPaymentsCutoverController::class, ( $matrix[ $state ][ $request ] ?? array() ), "The $state $request request must not resolve the cutover controller." );
			}
		}
	}

	/**
	 * @testdox A store without an account, an extension-owned store included, loads only the cutover classes on every request.
	 */
	public function test_available_tier_loads_only_the_cutover_classes(): void {
		$matrix  = WooPaymentsProvider::get_classes_by_setup_tier();
		$allowed = array( WooPaymentsCutoverController::class, WooPaymentsCutoverReconciliationJob::class, WooPaymentsCutoverPluginLifecycleListener::class );

		foreach ( $matrix[ WooPaymentsSetupTier::AVAILABLE ] as $request => $classes ) {
			$this->assertSame( array(), array_values( array_diff( $classes, $allowed ) ), "A $request request of a store without an account must load no payment class." );
		}
	}

	/**
	 * @testdox $_dataName loads on every request of a connected or active store and on none of a store without an account.
	 *
	 * @dataProvider connected_request_roots_provider
	 *
	 * @param string $root The root class.
	 */
	public function test_root_loads_on_every_connected_request( string $root ): void {
		$matrix = WooPaymentsProvider::get_classes_by_setup_tier();

		foreach ( array( WooPaymentsSetupTier::CONNECTED, WooPaymentsSetupTier::ACTIVE ) as $state ) {
			foreach ( array( 'front', 'admin', 'ajax', 'rest', 'cron', 'cli' ) as $request ) {
				$this->assertContains( $root, ( $matrix[ $state ][ $request ] ?? array() ), "The $state $request request must load $root." );
			}
		}
		foreach ( array( 'front', 'admin', 'ajax', 'rest', 'cron', 'cli' ) as $request ) {
			$this->assertNotContains( $root, ( $matrix[ WooPaymentsSetupTier::AVAILABLE ][ $request ] ?? array() ), "A store without an account must not load $root on a $request request." );
		}
	}

	/**
	 * Roots that act on any request of a store with an account.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function connected_request_roots_provider(): array {
		return array(
			// An update finishes on the init of any request.
			'The WooCommerce update listener'       => array( WooPaymentsWooCommerceUpdateListener::class ),
			// Every WordPress.com request of the store carries the connection's Safe Mode arguments.
			'The WordPress.com connection features' => array( WooPaymentsConnectionFeatures::class ),
		);
	}

	/**
	 * @testdox $_dataName loads only on connected and active admin and REST requests, never on dormant tiers.
	 *
	 * @dataProvider connected_admin_and_rest_roots_provider
	 *
	 * @param string $root The root class.
	 */
	public function test_root_loads_only_on_connected_and_active_admin_and_rest_requests( string $root ): void {
		foreach ( WooPaymentsProvider::get_classes_by_setup_tier() as $state => $request_groups ) {
			foreach ( $request_groups as $request_type => $roots ) {
				$expected = in_array( $state, array( WooPaymentsSetupTier::CONNECTED, WooPaymentsSetupTier::ACTIVE ), true )
					&& in_array( $request_type, array( 'admin', 'rest' ), true );

				$this->assertSame( $expected, in_array( $root, $roots, true ), $state . ' ' . $request_type );
			}
		}
		$this->assertArrayHasKey( 'rest', WooPaymentsProvider::get_classes_by_setup_tier()[ WooPaymentsSetupTier::CONNECTED ] );
	}

	/**
	 * Roots that serve the merchant's admin pages and their REST reads.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function connected_admin_and_rest_roots_provider(): array {
		return array(
			'The hidden column preferences' => array( WooPaymentsUserPreferenceFields::class ),
			'The Home tasks'                => array( WooPaymentsHomeTasks::class ),
		);
	}

	/**
	 * @testdox Keeps cutover normalization out of shopper roots while preserving maintenance ordering.
	 */
	public function test_active_provider_matrix_bounds_cutover_normalization_to_maintenance_roots(): void {
		$active_roots = WooPaymentsProvider::get_classes_by_setup_tier()[ WooPaymentsSetupTier::ACTIVE ];

		$this->assertNotContains( WooPaymentsCutoverNormalizationRunner::class, $active_roots['front'], 'A shopper request must not run cutover normalization.' );
		$this->assertNotContains( WooPaymentsCutoverNormalizationRunner::class, $active_roots['ajax'], 'An AJAX shopper request must not run cutover normalization.' );
		$this->assertNotContains( WooPaymentsCutoverNormalizationRunner::class, $active_roots['rest'], 'A REST shopper request must not run cutover normalization.' );
		$this->assertSame( WooPaymentsCutoverNormalizationRunner::class, $active_roots['admin'][0], 'Admin cutover normalization must run before bounded native consumers.' );
		$this->assertSame( WooPaymentsCutoverNormalizationRunner::class, $active_roots['cron'][0], 'Cron cutover normalization must run before bounded native consumers.' );
	}

	/** @testdox Cutover reconciliation is registered only on admin and cron roots in every available setup tier. */
	public function test_provider_matrix_bounds_cutover_reconciliation_to_admin_and_cron_roots(): void {
		$matrix = WooPaymentsProvider::get_classes_by_setup_tier();

		foreach ( array( WooPaymentsSetupTier::AVAILABLE, WooPaymentsSetupTier::CONNECTED, WooPaymentsSetupTier::ACTIVE ) as $state ) {
			$this->assertContains( WooPaymentsCutoverReconciliationJob::class, $matrix[ $state ]['admin'], $state . ' admin requests must register cutover reconciliation.' );
			$this->assertContains( WooPaymentsCutoverReconciliationJob::class, $matrix[ $state ]['cron'], $state . ' cron requests must register cutover reconciliation.' );

			foreach ( array( 'front', 'ajax', 'rest' ) as $request_type ) {
				$this->assertNotContains( WooPaymentsCutoverReconciliationJob::class, $matrix[ $state ][ $request_type ] ?? array(), $state . ' ' . $request_type . ' requests must not pay cutover reconciliation cost.' );
			}
		}
	}

	/**
	 * @testdox Every provider root resolves from the container and is a gateway provider or registers hooks, appears once per list, and no list names the gateway registry.
	 */
	public function test_provider_matrix_roots_are_registrable_and_listed_once(): void {
		$matrix = WooPaymentsProvider::get_classes_by_setup_tier();

		$this->assertEmpty( $matrix[ WooPaymentsSetupTier::DISABLED ] ?? array(), 'A disabled store registers no provider root.' );
		foreach ( $matrix as $state => $request_groups ) {
			foreach ( $request_groups as $request => $roots ) {
				$cell = "$state $request";
				$this->assertSame( array_values( array_unique( $roots ) ), $roots, "$cell lists a root twice." );
				foreach ( $roots as $root ) {
					$service = wc_get_container()->get( $root );
					if ( $service instanceof PaymentGatewayProviderInterface ) {
						continue;
					}
					$this->assertInstanceOf( RegisterHooksInterface::class, $service, "$cell: $root must register hooks." );
				}
				$this->assertNotContains( ProviderGatewaysController::class, $roots, "$cell: the bootstrap adds the gateway registry for a listed gateway provider." );
			}
		}
	}

	/** @testdox Mutation requests retain their required observers. */
	public function test_provider_matrix_keeps_required_mutation_observers(): void {
		$matrix         = WooPaymentsProvider::get_classes_by_setup_tier();
		$connected_rest = $matrix[ WooPaymentsSetupTier::CONNECTED ]['rest'];
		$active_front   = $matrix[ WooPaymentsSetupTier::ACTIVE ]['front'];

		foreach (
			array(
				self::WCPAY . 'WooPaymentsOrderAdminActionsController',
				self::WCPAY . 'WooPay\WooPaymentsWooPayOrderStatusSync',
				self::WCPAY . 'WooPaymentsApplePayDomainService',
				self::WCPAY . 'WooPaymentsFraudOrderTrackingService',
				self::WCPAY . 'WooPaymentsStoreSetupReportController',
				self::WCPAY . 'WooPaymentsAccountMilestonesController',
			) as $observer
		) {
			$this->assertContains( $observer, $connected_rest, $observer . ' must observe connected REST mutations.' );
		}

		$this->assertContains( self::WCPAY . 'WooPaymentsFraudOrderTrackingService', $active_front, 'Active shopper order creation must retain tracking hooks.' );
	}

	/** @testdox Registers the express checkout Store API cart extension on active page requests, where the Cart and Checkout blocks preload the cart. */
	public function test_provider_matrix_registers_the_cart_extension_on_active_pages(): void {
		$matrix = WooPaymentsProvider::get_classes_by_setup_tier();

		$this->assertContains( self::WCPAY . 'WooPaymentsExpressCheckoutStoreApiExtension', $matrix[ WooPaymentsSetupTier::ACTIVE ]['front'] );
		$this->assertContains( self::WCPAY . 'WooPaymentsExpressCheckoutStoreApiExtension', $matrix[ WooPaymentsSetupTier::ACTIVE ]['rest'] );
	}

	/**
	 * The native shopper scripts post their Tracks events to the REST route this controller registers, in a request
	 * of its own; without the controller among the REST roots the route does not exist there.
	 *
	 * @testdox Registers the shopper Tracks controller on active REST requests, where its route is served.
	 */
	public function test_provider_matrix_serves_the_shopper_tracks_route_on_active_rest(): void {
		$matrix = WooPaymentsProvider::get_classes_by_setup_tier();

		$this->assertContains( self::WCPAY . 'WooPaymentsFrontendTrackingController', $matrix[ WooPaymentsSetupTier::ACTIVE ]['rest'] );
	}

	/** @testdox Registers the WooPay preflight guard only for active REST requests. */
	public function test_provider_matrix_bounds_woopay_preflight_guard_to_active_rest(): void {
		$matrix = WooPaymentsProvider::get_classes_by_setup_tier();

		foreach ( $matrix as $state => $request_groups ) {
			foreach ( $request_groups as $request_type => $roots ) {
				if ( WooPaymentsSetupTier::ACTIVE === $state && 'rest' === $request_type ) {
					$this->assertContains( WooPaymentsWooPayPreflightGuard::class, $roots );
					continue;
				}

				$this->assertNotContains( WooPaymentsWooPayPreflightGuard::class, $roots, $state . ' ' . $request_type . ' must not register the WooPay preflight guard.' );
			}
		}
	}

	/** @testdox Should defer merchant routes on connected and active admin requests until internal REST initialization. */
	public function test_provider_matrix_tiers_merchant_rest_routes(): void {
		$matrix = WooPaymentsProvider::get_classes_by_setup_tier();

		$this->assertNotContains( WooPaymentsMerchantRestController::class, $matrix[ WooPaymentsSetupTier::AVAILABLE ]['admin'] );
		$this->assertNotContains( WooPaymentsAdminRestRouteRegistrar::class, $matrix[ WooPaymentsSetupTier::AVAILABLE ]['admin'] );
		$this->assertNotContains( WooPaymentsMerchantRestController::class, $matrix[ WooPaymentsSetupTier::AVAILABLE ]['rest'] ?? array() );
		$this->assertContains( WooPaymentsMerchantRestController::class, $matrix[ WooPaymentsSetupTier::CONNECTED ]['rest'] );
		$this->assertNotContains( WooPaymentsMerchantRestController::class, $matrix[ WooPaymentsSetupTier::CONNECTED ]['admin'] );
		$this->assertContains( WooPaymentsAdminRestRouteRegistrar::class, $matrix[ WooPaymentsSetupTier::CONNECTED ]['admin'] );
		$this->assertNotContains( WooPaymentsAdminRestRouteRegistrar::class, $matrix[ WooPaymentsSetupTier::CONNECTED ]['rest'] );
		$this->assertContains( WooPaymentsMerchantRestController::class, $matrix[ WooPaymentsSetupTier::ACTIVE ]['rest'] );
		$this->assertNotContains( WooPaymentsMerchantRestController::class, $matrix[ WooPaymentsSetupTier::ACTIVE ]['admin'] );
		$this->assertContains( WooPaymentsAdminRestRouteRegistrar::class, $matrix[ WooPaymentsSetupTier::ACTIVE ]['admin'] );
		$this->assertNotContains( WooPaymentsAdminRestRouteRegistrar::class, $matrix[ WooPaymentsSetupTier::ACTIVE ]['rest'] );
	}

	/** @testdox The WooPayments admin pages' navigation and shared settings register together on connected and active admin requests only. */
	public function test_provider_matrix_registers_the_admin_pages_on_connected_and_active_admin_requests(): void {
		foreach ( WooPaymentsProvider::get_classes_by_setup_tier() as $state => $request_groups ) {
			foreach ( $request_groups as $request_type => $roots ) {
				$expected   = 'admin' === $request_type && in_array( $state, array( WooPaymentsSetupTier::CONNECTED, WooPaymentsSetupTier::ACTIVE ), true );
				$navigation = array_search( WooPaymentsAdminNavigationController::class, $roots, true );
				$preload    = array_search( WooPaymentsAdminSharedSettings::class, $roots, true );

				$this->assertSame( $expected, false !== $navigation, "$state $request_type navigation" );
				$this->assertSame( $expected, false !== $preload, "$state $request_type shared settings" );
				if ( $expected ) {
					// Right after navigation, so the preload keeps its place among the priority-10 shared settings filters.
					$this->assertSame( $navigation + 1, $preload, "$state $request_type order" );
				}
			}
		}
	}

	/** @testdox Account refresh roots register the webhook recovery listener before refresh handlers can run. */
	public function test_account_refresh_roots_are_followed_by_webhook_reliability_listener(): void {
		foreach ( WooPaymentsProvider::get_classes_by_setup_tier() as $state => $request_groups ) {
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
}
