<?php
/**
 * WooPaymentsPluginEndpointsContractTest file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRestController;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsState;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminRestRouteRegistrar;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsReportsRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWooPaySessionController;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsRestController;
use WC_REST_Unit_Test_Case;
use WP_REST_Request;

/**
 * Pins the plugin 11.1.0 REST route and AJAX action surface (`plugin-11.1.0-endpoints.json`)
 * against what native serves, so a route or action neither served nor recorded as an allowed
 * difference is a build-time failure instead of a silent regression.
 *
 * See `.agents/scratchpad/sessions/2026-09-03-native-payments-program-orientation/`
 * `plan-task-t4-bc-inventories.md`, "3. REST route and AJAX endpoint presence".
 *
 * @since 11.2.0
 */
class WooPaymentsPluginEndpointsContractTest extends WC_REST_Unit_Test_Case {

	use NativeSourceScanTrait;

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
		'POST wc/v3 /payments/settings/schedule-stripe-billing-migration' => array(
			'authority'   => 'rest-route-exceptions.txt (Task 6.1 / D13) + plan.md Decision 1: DROPPED, Stripe Billing engine excluded.',
			'disposition' => 'DROPPED',
			'successors'  => array(),
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
	 * A shop-manager user, created lazily the first time `native_serves()` needs to probe a
	 * permission callback, and reused for the rest of the test.
	 *
	 * @var int|null
	 */
	private ?int $shop_manager_id = null;

	/**
	 * Load the plugin-11.1.0-endpoints.json fixture once for the class.
	 */
	public static function wpSetUpBeforeClass(): void {
		$path = __DIR__ . '/Fixtures/plugin-11.1.0-endpoints.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a committed test fixture, not user input.
		self::$fixture = json_decode( (string) file_get_contents( $path ), true );
	}

	/**
	 * Put native into the `active` state for the duration of one test and register the REST
	 * routes needed to check the fixture, without needing a connected account (D9).
	 *
	 * The registrar roots are the production admin REST wiring
	 * (`WooPaymentsAdminRestRouteRegistrar::get_connected_controller_roots()`), called through
	 * their public `register_routes()` directly rather than through `register()`: several (for
	 * example `WooPaymentsDocumentsRestController`) gate `register()` behind live account
	 * capability flags (`is_documents_enabled()`) that a disconnected test account cannot satisfy,
	 * even though the routes themselves are real and unconditional once reached. The WooPay
	 * session, Multi-Currency and NOX onboarding controllers are not part of that production
	 * wiring; they are registered here only so their routes exist to check, and their own
	 * production registration is proven by their own tests.
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		update_option( 'active_plugins', array() );
		update_option( NativePaymentsState::OPTION_NAME, NativePaymentsState::ACTIVE );
		wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->invalidate();
		wc_get_container()->get( NativePaymentsState::class )->invalidate();
		update_option( 'wcpay_multi_currency_rendering_mode', 'cache' );

		foreach ( WooPaymentsAdminRestRouteRegistrar::get_connected_controller_roots() as $root ) {
			wc_get_container()->get( $root )->register_routes();
		}
		wc_get_container()->get( WooPaymentsWooPaySessionController::class )->register_routes();
		wc_get_container()->get( MultiCurrencyRestController::class )->handle_rest_api_init();
		wc_get_container()->get( WooPaymentsRestController::class )->register_routes();
	}

	/**
	 * Invalidate the singleton native state so no later test observes the `active` state this
	 * test put it in (gate: `NativePaymentsBootstrapTest` and friends stay clean). The enabling
	 * filter and the stored option are undone first, the way `NativePaymentsStateTest` does,
	 * so nothing left in `parent::tearDown()` can re-cache `active` for the next test.
	 */
	public function tearDown(): void {
		try {
			remove_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
			delete_option( NativePaymentsState::OPTION_NAME );
			wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->invalidate();
			wc_get_container()->get( NativePaymentsState::class )->invalidate();
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
	 * @testdox Native serves every plugin 11.1.0 REST route and method, or the difference is recorded.
	 */
	public function test_native_serves_every_plugin_rest_route_and_method(): void {
		$native_routes = $this->server->get_routes();
		$failures      = array();

		foreach ( self::$fixture['rest_routes'] as $route ) {
			foreach ( $route['methods'] as $method ) {
				$key = $method . ' ' . $route['namespace'] . ' ' . $route['route'];
				if ( isset( self::ALLOWED_DIFFERENCES[ $key ] ) ) {
					continue;
				}

				$fixture_path = '/' . $route['namespace'] . '/' . ltrim( $route['route'], '/' );
				if ( ! $this->native_serves( $native_routes, $fixture_path, $route['sample_path'], $method ) ) {
					$failures[] = $key . ' (plugin_site: ' . $route['plugin_site'] . ')';
				}
			}
		}

		$this->assertSame( array(), $failures, "Native must serve every plugin 11.1.0 route/method or list it in ALLOWED_DIFFERENCES:\n" . implode( "\n", $failures ) );
	}

	/**
	 * @testdox Every superseded route's native NOX successor is registered.
	 */
	public function test_superseded_routes_have_their_native_successor(): void {
		$native_routes = $this->server->get_routes();
		$missing       = array();

		foreach ( self::ALLOWED_DIFFERENCES as $key => $difference ) {
			foreach ( $difference['successors'] as $successor ) {
				list( $namespace, $route ) = explode( ' ', $successor, 2 );
				$full_path                 = '/' . trim( $namespace, '/' ) . '/' . ltrim( $route, '/' );
				if ( ! array_key_exists( $full_path, $native_routes ) ) {
					$missing[] = $key . ' -> ' . $full_path;
				}
			}
		}

		$this->assertSame( array(), $missing, "Every SUPERSEDED route's native successor must be registered:\n" . implode( "\n", $missing ) );
	}

	/**
	 * @testdox Native has a source site naming every plugin 11.1.0 AJAX action.
	 *
	 * AJAX action registration is scattered across several runtime gates (WooPay enablement,
	 * Subscriptions and Bookings availability) that a disconnected unit test cannot all satisfy
	 * at once. A literal-name scan proves native names the same action, the way the plugin
	 * extractor itself proves the plugin does; that native actually calls `add_action()` with the
	 * exact callback is owned by each site's own class: `WooPaymentsWooPaySessionControllerTest`
	 * (10 WooPay actions), `WooPaymentsFrontendTrackingControllerTest` (4),
	 * `WooPaymentsCheckoutAjaxControllerTest` (3), `WooPaymentsPaymentMethodMessagingTest` (2),
	 * `WooPaymentsSubscriptionAdminPaymentMethodHandlerTest` (1) and
	 * `MultiCurrencyBookingsCompatibilityControllerTest` (2).
	 */
	public function test_native_names_every_plugin_ajax_action(): void {
		$plugin_path = WC()->plugin_path();
		$roots       = array_map(
			static function ( string $root ) use ( $plugin_path ): string {
				return $plugin_path . '/' . $root;
			},
			self::AJAX_SCAN_ROOTS
		);
		$literals    = array_flip( $this->native_all_php_string_literals( $this->native_collect_files( $roots, array( 'php' ) ) ) );
		$missing     = array();

		foreach ( self::$fixture['ajax_actions'] as $action ) {
			if ( ! isset( $literals[ $action['action'] ] ) ) {
				$missing[] = $action['action'] . ' (plugin_site: ' . $action['plugin_site'] . ')';
			}
		}

		$this->assertSame( array(), $missing, "Native must name every plugin 11.1.0 AJAX action:\n" . implode( "\n", $missing ) );
	}

	/**
	 * @testdox No ALLOWED_DIFFERENCES route is stale: native must not already serve it.
	 */
	public function test_allowed_differences_are_not_stale(): void {
		$native_routes = $this->server->get_routes();
		$stale         = array();

		foreach ( self::$fixture['rest_routes'] as $route ) {
			foreach ( $route['methods'] as $method ) {
				$key = $method . ' ' . $route['namespace'] . ' ' . $route['route'];
				if ( ! isset( self::ALLOWED_DIFFERENCES[ $key ] ) || 'SUPERSEDED' !== self::ALLOWED_DIFFERENCES[ $key ]['disposition'] ) {
					continue;
				}

				$fixture_path = '/' . $route['namespace'] . '/' . ltrim( $route['route'], '/' );
				if ( $this->native_serves( $native_routes, $fixture_path, $route['sample_path'], $method ) ) {
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
	 * @param array<string,mixed> $native_routes Route table from `WP_REST_Server::get_routes()`.
	 * @param string              $fixture_path  The fixture route with its namespace, groups intact.
	 * @param string              $sample_path   A concrete path matching the fixture route's regex.
	 * @param string              $method        HTTP method.
	 */
	private function native_serves( array $native_routes, string $fixture_path, string $sample_path, string $method ): bool {
		$fixture_skeleton = $this->route_skeleton( $fixture_path );

		foreach ( $native_routes as $native_route => $handlers ) {
			if ( $this->route_skeleton( $native_route ) !== $fixture_skeleton ) {
				continue;
			}

			if ( 1 !== preg_match( '@^' . $native_route . '$@i', $sample_path ) ) {
				continue;
			}

			foreach ( $handlers as $handler ) {
				if ( isset( $handler['methods'][ $method ] ) && $handler['methods'][ $method ] ) {
					if ( ! $this->native_grants_shop_manager( $handler, $sample_path, $method ) ) {
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
	private function native_grants_shop_manager( array $handler, string $sample_path, string $method ): bool {
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
