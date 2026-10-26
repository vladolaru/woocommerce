<?php
/**
 * WooPaymentsRestRoutesContractTest file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDocumentsRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminRestRouteRegistrar;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsReportsRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPaySessionController;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsRestController;
use Closure;
use ReflectionFunction;
use WC_REST_Unit_Test_Case;
use WP_REST_Request;
use WP_REST_Server;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPaySessionControllerTest;

/**
 * Pins the plugin 11.1.0 REST route and AJAX action surface (`woopayments-endpoints.json`)
 * against what native serves, so a route or action neither served nor recorded as an allowed
 * difference is a build-time failure instead of a silent regression.
 *
 * @since 11.2.0
 */
class WooPaymentsRestRoutesContractTest extends WC_REST_Unit_Test_Case {

	use SourceScanTrait;

	/**
	 * Native scan roots for the AJAX action literal harvest.
	 *
	 * @var array<int,string>
	 */
	private const AJAX_SCAN_ROOTS = array(
		'src/Internal/Payments',
		'src/Internal/MultiCurrency',
		'src/Internal/Admin/Settings/PaymentsProviders/WooPayments',
	);

	/**
	 * Fixture routes superseded by a native NOX successor, and the ones intentionally dropped.
	 *
	 * Each key is `"METHOD NAMESPACE ROUTE"` exactly as the fixture prints it. Successor routes
	 * are the exact `rest-route-exceptions.txt` values (Task 6.1 / D8 of the 2026-07-06 plan).
	 * A `DROPPED` disposition carries no successor and is not stale-checked: a generic native
	 * route (for example the single-option settings setter) can structurally overlap the dropped
	 * plugin path's shape without performing its behavior, which would otherwise read as a false
	 * "now served" positive.
	 *
	 * @var array<string,array{authority:string,disposition:string,successors:array<int,string>}>
	 */
	private const ALLOWED_DIFFERENCES = array(
		'POST wc/v3 /payments/onboarding/kyc/session'   => array(
			'authority'   => 'rest-route-exceptions.txt (Task 6.1 / D8): SUPERSEDED by native NOX onboarding.',
			'disposition' => 'SUPERSEDED',
			'successors'  => array( 'wc-admin /settings/payments/woopayments/onboarding/step/business_verification/kyc_session' ),
		),
		'POST wc/v3 /payments/onboarding/kyc/finalize'  => array(
			'authority'   => 'rest-route-exceptions.txt (Task 6.1 / D8): SUPERSEDED by native NOX onboarding.',
			'disposition' => 'SUPERSEDED',
			'successors'  => array( 'wc-admin /settings/payments/woopayments/onboarding/step/business_verification/kyc_session/finish' ),
		),
		'POST wc/v3 /payments/onboarding/reset'         => array(
			'authority'   => 'rest-route-exceptions.txt (Task 6.1 / D8): SUPERSEDED by native NOX onboarding.',
			'disposition' => 'SUPERSEDED',
			'successors'  => array( 'wc-admin /settings/payments/woopayments/onboarding/reset' ),
		),
		'GET wc/v3 /payments/onboarding/fields'         => array(
			'authority'   => 'rest-route-exceptions.txt (Task 6.1 / D8): SUPERSEDED, field context moves into onboarding details/preload.',
			'disposition' => 'SUPERSEDED',
			'successors'  => array( 'wc-admin /settings/payments/woopayments/onboarding', 'wc-admin /settings/payments/woopayments/onboarding/preload' ),
		),
		'GET wc/v3 /payments/onboarding/business_types' => array(
			'authority'   => 'rest-route-exceptions.txt (Task 6.1 / D8): SUPERSEDED, business-type context moves into onboarding details/preload.',
			'disposition' => 'SUPERSEDED',
			'successors'  => array( 'wc-admin /settings/payments/woopayments/onboarding', 'wc-admin /settings/payments/woopayments/onboarding/preload' ),
		),
		'POST wc/v3 /payments/onboarding/test_drive_account/init' => array(
			'authority'   => 'rest-route-exceptions.txt (Task 6.1 / D8): SUPERSEDED by the native test-account onboarding step.',
			'disposition' => 'SUPERSEDED',
			'successors'  => array( 'wc-admin /settings/payments/woopayments/onboarding/step/test_account/init' ),
		),
		'POST wc/v3 /payments/onboarding/test_drive_account/disable' => array(
			'authority'   => 'rest-route-exceptions.txt (Task 6.1 / D8): SUPERSEDED by native context-aware test-account disable.',
			'disposition' => 'SUPERSEDED',
			'successors'  => array( 'wc-admin /settings/payments/woopayments/onboarding/test_account/disable', 'wc-admin /settings/payments/woopayments/onboarding/step/business_verification/test_account/disable' ),
		),
		'POST wc/v3 /payments/survey/reports-feedback'  => array(
			'authority'   => 'data/client-delta-10.8.0-11.1.0.tsv row 4 (10.9.0 inline Reports feedback survey, n/a plugin-only).',
			'disposition' => 'DROPPED',
			'successors'  => array(),
		),
	);

	/**
	 * Loaded fixture.
	 *
	 * @var array<string,mixed>
	 */
	private static array $fixture;

	/**
	 * A shop-manager user, created lazily the first time `builtin_serves()` needs to probe a
	 * permission callback, and reused for the rest of the test.
	 *
	 * @var int|null
	 */
	private ?int $shop_manager_id = null;

	/**
	 * Load the woopayments-endpoints.json fixture once for the class.
	 */
	public static function wpSetUpBeforeClass(): void {
		$path = __DIR__ . '/Fixtures/woopayments-endpoints.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a committed test fixture, not user input.
		self::$fixture = json_decode( (string) file_get_contents( $path ), true );
	}

	/**
	 * Put native into the `active` state for the duration of one test and register the REST
	 * routes needed to check the fixture, without needing a connected account (D9).
	 *
	 * The registrar roots are the production admin REST wiring
	 * (`WooPaymentsAdminRestRouteRegistrar::get_connected_controller_roots()`), called through
	 * their public `register_routes()` directly rather than through `register()`.
	 * `WooPaymentsDocumentsRestController` checks a live account flag (`is_documents_enabled()`) that
	 * a disconnected test account cannot satisfy, so its instance here reads Documents as enabled. The WooPay
	 * session, Multi-Currency and NOX onboarding controllers are not part of that production
	 * wiring; they are registered here only so their routes exist to check, and their own
	 * production registration is proven by their own tests.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( 'active_plugins', array() );
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::ACTIVE );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
		update_option( 'wcpay_multi_currency_rendering_mode', 'cache' );

		// A disconnected test account cannot enable Documents, whose controller registers its routes only when it is.
		$documents_account = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_documents_enabled' ) )
			->getMock();
		$documents_account->method( 'is_documents_enabled' )->willReturn( true );
		$documents = new WooPaymentsDocumentsRestController();
		$documents->init( new Client(), $documents_account );
		wc_get_container()->replace( WooPaymentsDocumentsRestController::class, $documents );

		foreach ( WooPaymentsAdminRestRouteRegistrar::get_connected_controller_roots() as $root ) {
			wc_get_container()->get( $root )->register_routes();
		}
		wc_get_container()->get( WooPaymentsWooPaySessionController::class )->register_routes();
		wc_get_container()->get( MultiCurrencyRestController::class )->handle_rest_api_init();
		wc_get_container()->get( WooPaymentsRestController::class )->register_routes();
		wc_get_container()->get( StripeBillingModule::class )->register_routes();
	}

	/**
	 * Invalidate the singleton native state so no later test observes the `active` state this
	 * test put it in (gate: `PaymentsBootstrapTest` and friends stay clean). The enabling
	 * filter and the stored option are undone first, the way `WooPaymentsSetupTierTest` does,
	 * so nothing left in `parent::tearDown()` can re-cache `active` for the next test.
	 */
	public function tearDown(): void {
		try {
			delete_option( WooPaymentsSetupTier::OPTION_NAME );
			wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
			wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
			wc_get_container()->reset_all_replacements();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox The fixture is complete: its declared count matches its entries, and every route and action is unique.
	 */
	public function test_fixture_is_complete(): void {
		$fixture = self::$fixture;

		$this->assertSame( $fixture['rest_route_count'], count( $fixture['rest_routes'] ), 'rest_route_count must match the number of rest_routes entries.' );
		$this->assertSame( $fixture['ajax_action_count'], count( $fixture['ajax_actions'] ), 'ajax_action_count must match the number of ajax_actions entries.' );

		$route_keys = array_map(
			static function ( array $route ): string {
				return $route['namespace'] . ' ' . $route['route'];
			},
			$fixture['rest_routes']
		);
		$this->assertSame( array_unique( $route_keys ), $route_keys, 'Every namespace/route pair must be unique.' );

		$actions = array_column( $fixture['ajax_actions'], 'action' );
		$this->assertSame( array_unique( $actions ), $actions, 'Every AJAX action must be unique.' );
	}

	/**
	 * @testdox The built-in WooPayments serves every REST route and method of the WooPayments extension, or the difference is recorded.
	 */
	public function test_builtin_serves_every_woopayments_extension_rest_route_and_method(): void {
		$builtin_routes = $this->server->get_routes();
		$failures       = array();

		foreach ( self::$fixture['rest_routes'] as $route ) {
			foreach ( $route['methods'] as $method ) {
				$key = $method . ' ' . $route['namespace'] . ' ' . $route['route'];
				if ( isset( self::ALLOWED_DIFFERENCES[ $key ] ) ) {
					continue;
				}

				$fixture_path = '/' . $route['namespace'] . '/' . ltrim( $route['route'], '/' );
				if ( ! $this->builtin_serves( $builtin_routes, $fixture_path, $route['sample_path'], $method ) ) {
					$failures[] = $key . ' (plugin_site: ' . $route['plugin_site'] . ')';
				}
			}
		}

		$this->assertSame( array(), $failures, "Native must serve every plugin 11.1.0 route/method or list it in ALLOWED_DIFFERENCES:\n" . implode( "\n", $failures ) );
	}

	/**
	 * @testdox Every superseded route's NOX successor is registered.
	 */
	public function test_superseded_routes_have_their_successor(): void {
		$builtin_routes = $this->server->get_routes();
		$missing        = array();

		foreach ( self::ALLOWED_DIFFERENCES as $key => $difference ) {
			foreach ( $difference['successors'] as $successor ) {
				list( $namespace, $route ) = explode( ' ', $successor, 2 );
				$full_path                 = '/' . trim( $namespace, '/' ) . '/' . ltrim( $route, '/' );
				if ( ! array_key_exists( $full_path, $builtin_routes ) ) {
					$missing[] = $key . ' -> ' . $full_path;
				}
			}
		}

		$this->assertSame( array(), $missing, "Every SUPERSEDED route's native successor must be registered:\n" . implode( "\n", $missing ) );
	}

	/**
	 * @testdox The built-in WooPayments has a source site naming every AJAX action of the WooPayments extension.
	 *
	 * AJAX action registration is scattered across several runtime gates (WooPay enablement,
	 * Subscriptions and Bookings availability) that a disconnected unit test cannot all satisfy
	 * at once. A literal-name scan proves native names the same action, the way the plugin
	 * extractor itself proves the plugin does; that native actually calls `add_action()` with the
	 * exact callback is owned by each site's own class: `WooPaymentsWooPaySessionControllerTest`
	 * (10 WooPay actions), `WooPaymentsFrontendTrackingControllerTest` (4),
	 * `WooPaymentsCheckoutAjaxControllerTest` (3), `WooPaymentsPaymentMethodMessagingTest` (2),
	 * `WooPaymentsSubscriptionAdminPaymentMethodHandlerTest` (1) and
	 * `WooCommerceBookingsTest` (2).
	 */
	public function test_builtin_names_every_woopayments_extension_ajax_action(): void {
		$plugin_path = WC()->plugin_path();
		$roots       = array_map(
			static function ( string $root ) use ( $plugin_path ): string {
				return $plugin_path . '/' . $root;
			},
			self::AJAX_SCAN_ROOTS
		);
		$literals    = array_flip( $this->source_all_php_string_literals( $this->source_collect_files( $roots, array( 'php' ) ) ) );
		$missing     = array();

		foreach ( self::$fixture['ajax_actions'] as $action ) {
			if ( ! isset( $literals[ $action['action'] ] ) ) {
				$missing[] = $action['action'] . ' (plugin_site: ' . $action['plugin_site'] . ')';
			}
		}

		$this->assertSame( array(), $missing, "Native must name every plugin 11.1.0 AJAX action:\n" . implode( "\n", $missing ) );
	}

	/**
	 * @testdox Each WooPayments route path is answered by the same route and controller when WooCommerce registers all of its REST routes as when the provider's routes stand alone.
	 *
	 * The controllers register through their own register(), as on a REST request, alongside every other WooCommerce
	 * route; the first route that matches a path and method is the one WordPress dispatches to.
	 */
	public function test_each_provider_route_keeps_its_handler_when_registered_with_all_woocommerce_routes(): void {
		global $wp_rest_server;

		$roots       = array_merge( WooPaymentsAdminRestRouteRegistrar::get_connected_controller_roots(), array( WooPaymentsWooPaySessionController::class ) );
		$controllers = array_map( static fn( string $root ) => wc_get_container()->get( $root ), $roots );
		$previous    = $wp_rest_server;

		try {
			// The provider's routes alone, in registration order.
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh REST server for this comparison; restored below.
			$wp_rest_server = new WP_REST_Server();
			foreach ( $controllers as $controller ) {
				$controller->register_routes();
			}
			$alone = $wp_rest_server->get_routes();

			// Every WooCommerce route, through the controllers' own registration.
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh REST server for this comparison; restored below.
			$wp_rest_server = new WP_REST_Server();
			foreach ( $controllers as $controller ) {
				$controller->register();
			}
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Initializing the REST server in a test.
			do_action( 'rest_api_init', $wp_rest_server );
			$all = $wp_rest_server->get_routes();
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the test's REST server.
			$wp_rest_server = $previous;
		}

		$checked    = 0;
		$mismatches = array();
		foreach ( self::$fixture['rest_routes'] as $route ) {
			foreach ( $route['methods'] as $method ) {
				$expected = $this->first_match( $alone, $route['sample_path'], $method );
				if ( null === $expected ) {
					continue;
				}

				++$checked;
				$actual = $this->first_match( $all, $route['sample_path'], $method );
				if ( $expected !== $actual ) {
					$mismatches[] = $method . ' ' . $route['sample_path'] . ': ' . implode( ' ', $expected ) . ' became ' . ( null === $actual ? 'unmatched' : implode( ' ', $actual ) );
				}
			}
		}

		$this->assertGreaterThan( 80, $checked, 'The fixture paths the provider serves must be checked.' );
		$this->assertSame( array(), $mismatches, "Each provider route path must keep its route and controller:\n" . implode( "\n", $mismatches ) );
	}

	/**
	 * Get the first route that matches a path and method, with the class of the controller that handles it.
	 *
	 * @param array<string,mixed> $routes Route table from `WP_REST_Server::get_routes()`.
	 * @param string              $path   Request path.
	 * @param string              $method HTTP method.
	 * @return array{0:string,1:string}|null Route and controller class, or null when nothing matches.
	 */
	private function first_match( array $routes, string $path, string $method ): ?array {
		foreach ( $routes as $route => $handlers ) {
			if ( 1 !== preg_match( '@^' . $route . '$@i', $path ) ) {
				continue;
			}

			foreach ( $handlers as $handler ) {
				if ( empty( $handler['methods'][ $method ] ) ) {
					continue;
				}

				$callback = $handler['callback'];
				if ( is_array( $callback ) ) {
					$owner = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
				} elseif ( $callback instanceof Closure ) {
					$bound = ( new ReflectionFunction( $callback ) )->getClosureThis();
					$owner = null === $bound ? 'closure' : get_class( $bound );
				} else {
					$owner = is_string( $callback ) ? $callback : 'callable';
				}

				return array( $route, $owner );
			}
		}

		return null;
	}

	/**
	 * @testdox A plugin that empties WooCommerce's REST namespaces removes the provider's admin routes but not the webhook or WooPay session route.
	 *
	 * Client 11.1.0 registers both routes on its own rest_api_init callback (class-wc-payments.php:860, :2360-2361, :2451-2452),
	 * so such a plugin does not remove them there either.
	 */
	public function test_emptied_rest_namespaces_keep_only_the_webhook_and_woopay_session_routes(): void {
		global $wp_rest_server;

		$roots       = array_merge( WooPaymentsAdminRestRouteRegistrar::get_connected_controller_roots(), array( WooPaymentsWooPaySessionController::class ) );
		$controllers = array_map( static fn( string $root ) => wc_get_container()->get( $root ), $roots );
		$previous    = $wp_rest_server;

		try {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh REST server for this comparison; restored below.
			$wp_rest_server = new WP_REST_Server();
			$server_routes  = array_keys( $wp_rest_server->get_routes() );
			foreach ( $controllers as $controller ) {
				$controller->register_routes();
			}
			$index_routes    = array_map( static fn( string $route_namespace ) => '/' . $route_namespace, $wp_rest_server->get_namespaces() );
			$provider_routes = array_diff( array_keys( $wp_rest_server->get_routes() ), $server_routes, $index_routes );

			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh REST server for this comparison; restored below.
			$wp_rest_server = new WP_REST_Server();
			foreach ( $controllers as $controller ) {
				$controller->register();
			}
			// Added after the provider's callbacks at the same priority, as by a plugin that loads after WooCommerce.
			add_filter( 'woocommerce_rest_api_get_rest_namespaces', '__return_empty_array' );
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Initializing the REST server in a test.
			do_action( 'rest_api_init', $wp_rest_server );
			$kept = array_values( array_intersect( $provider_routes, array_keys( $wp_rest_server->get_routes() ) ) );
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the test's REST server.
			$wp_rest_server = $previous;
		}

		sort( $kept );
		$this->assertGreaterThan( 50, count( $provider_routes ), 'The admin controllers must register their routes when nothing empties the list.' );
		$this->assertSame( array( '/payments/woopay/session', '/wc/v3/payments/webhook' ), $kept, 'Only the webhook and WooPay session routes may survive an emptied list of WooCommerce REST namespaces.' );
	}

	/**
	 * @testdox No ALLOWED_DIFFERENCES route is stale: the built-in WooPayments must not already serve it.
	 */
	public function test_allowed_differences_are_not_stale(): void {
		$builtin_routes = $this->server->get_routes();
		$stale          = array();

		foreach ( self::$fixture['rest_routes'] as $route ) {
			foreach ( $route['methods'] as $method ) {
				$key = $method . ' ' . $route['namespace'] . ' ' . $route['route'];
				if ( ! isset( self::ALLOWED_DIFFERENCES[ $key ] ) || 'SUPERSEDED' !== self::ALLOWED_DIFFERENCES[ $key ]['disposition'] ) {
					continue;
				}

				$fixture_path = '/' . $route['namespace'] . '/' . ltrim( $route['route'], '/' );
				if ( $this->builtin_serves( $builtin_routes, $fixture_path, $route['sample_path'], $method ) ) {
					$stale[] = $key;
				}
			}
		}

		$this->assertSame( array(), $stale, "These ALLOWED_DIFFERENCES routes are now served natively; remove the allowance:\n" . implode( "\n", $stale ) );
	}

	/**
	 * Whether a registered native route table serves the given sample path and method under a
	 * route with the same literal skeleton as the fixture route.
	 *
	 * Matching `sample_path` alone accepts a sibling route that also happens to have a parameter
	 * where the fixture route is a plain literal segment (for example a plugin
	 * `/payments/transactions/summary` route is structurally swallowed by a native
	 * `/payments/transactions/(?P<transaction_id>\w+)` route, since "summary" matches `\w+`).
	 * Requiring the same skeleton — each `(?P<name>…)` group collapsed to `{}` — rules that out
	 * while still accepting an equivalent or widened group regex (bc-surface-diff §2), since the
	 * skeleton comparison never looks at what is inside the group.
	 *
	 * @param array<string,mixed> $builtin_routes Route table from `WP_REST_Server::get_routes()`.
	 * @param string              $fixture_path  The fixture route with its namespace, groups intact.
	 * @param string              $sample_path   A concrete path matching the fixture route's regex.
	 * @param string              $method        HTTP method.
	 */
	private function builtin_serves( array $builtin_routes, string $fixture_path, string $sample_path, string $method ): bool {
		$fixture_skeleton = $this->route_skeleton( $fixture_path );

		foreach ( $builtin_routes as $builtin_route => $handlers ) {
			if ( $this->route_skeleton( $builtin_route ) !== $fixture_skeleton ) {
				continue;
			}

			if ( 1 !== preg_match( '@^' . $builtin_route . '$@i', $sample_path ) ) {
				continue;
			}

			foreach ( $handlers as $handler ) {
				if ( isset( $handler['methods'][ $method ] ) && $handler['methods'][ $method ] ) {
					if ( ! $this->builtin_grants_shop_manager( $handler, $sample_path, $method ) ) {
						continue;
					}

					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether the matched handler's `permission_callback` grants a plain WordPress Shop Manager
	 * (the plugin's own `WC_Payments_REST_Controller::check_permission()` requirement,
	 * `class-wc-payments-rest-controller.php:63-64`: `current_user_can( 'manage_woocommerce' )`, which
	 * none of the plugin's REST controller subclasses override).
	 *
	 * Two native controllers matched by this fixture do not grant plain `manage_woocommerce`, and
	 * neither does the plugin route it corresponds to, so both keep their own restriction rather than
	 * being forced to `true`:
	 * - `WooPaymentsReportsRestController` restricts further (`is_reports_enabled()` and
	 *   `has_account()`, `WooPaymentsReportsRestController.php:129`) — a business-state gate the
	 *   plugin enforces instead at route-registration time
	 *   (`WC_Payments_Features::is_reports_area_enabled()`,
	 *   `class-wc-rest-payments-reports-balance-controller.php:27-29` and its siblings). A
	 *   disconnected unit test account cannot satisfy that gate, and forcing it here would test the
	 *   gate instead of the capability.
	 * - `WooPaymentsWooPaySessionController` is not a `manage_woocommerce` route at all, in the
	 *   plugin or natively: both require the WooPay-signed request instead
	 *   (`class-wc-rest-woopay-session-controller.php:80-81`:
	 *   `$this->is_request_from_woopay() && $this->has_valid_request_signature()`; the plugin
	 *   controller extends `WP_REST_Controller` directly, not `WC_Payments_REST_Controller`).
	 *
	 * @param array<string,mixed> $handler     Registered native route handler.
	 * @param string              $sample_path Concrete sample path for the matched route.
	 * @param string              $method      HTTP method.
	 */
	private function builtin_grants_shop_manager( array $handler, string $sample_path, string $method ): bool {
		$callback = $handler['permission_callback'] ?? null;
		if ( ! is_callable( $callback ) ) {
			return true;
		}

		if ( is_array( $callback ) && isset( $callback[0] ) && ( $callback[0] instanceof WooPaymentsReportsRestController || $callback[0] instanceof WooPaymentsWooPaySessionController ) ) {
			return true;
		}

		if ( null === $this->shop_manager_id ) {
			$this->shop_manager_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		}

		$previous_user = get_current_user_id();
		wp_set_current_user( $this->shop_manager_id );

		try {
			$result = call_user_func( $callback, new WP_REST_Request( $method, $sample_path ) );
		} finally {
			wp_set_current_user( $previous_user );
		}

		return true === $result;
	}

	/**
	 * Collapse every `(?P<name>…)` named group in a route to `{name}`, so two routes that differ
	 * only in their group's regex (an equivalent or widened pattern) compare equal, while a route
	 * that renames the parameter itself (a REST client's `set_param( 'name', … )` calls stop
	 * matching) does not.
	 *
	 * @param string $route A REST route string, with or without its namespace.
	 */
	private function route_skeleton( string $route ): string {
		return preg_replace( '/\(\?P<([a-zA-Z_][a-zA-Z0-9_]*)>[^()]*\)/', '{$1}', $route );
	}
}
