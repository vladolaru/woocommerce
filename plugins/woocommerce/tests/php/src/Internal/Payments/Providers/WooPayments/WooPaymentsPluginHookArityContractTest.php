<?php
/**
 * WooPaymentsPluginHookArityContractTest file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyAnalyticsController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyAsyncPriceRendererController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCompatibilityController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySelectedCurrencyController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyStorefrontIntegrationController;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyFrontendProjectionService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyGeolocationService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyLocalizationService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencySelectedCurrencyPersistenceService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsState;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsHttpClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayOrderStatusSync;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAuthorizationsRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCheckoutBridge;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDepositsRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDisputesRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsEventIngestor;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendTrackingController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentRequestBuilder;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsMobileRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderSuccessPage;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTransactionsRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWooPaySessionController;
use ReflectionClass;
use ReflectionMethod;
use WC_Coupon;
use WC_Order;
use WC_Payment_Token_CC;
use WC_Product_Simple;
use WP_REST_Request;
use WC_Unit_Test_Case;

/**
 * Pins the plugin 11.1.0 hook argument shapes (arity and declared types) for the 70 hooks of the
 * retired `hook-shape-parity.php` manifest (`data/bc-inventory-sources/hook-shape-inventory.json`,
 * 38 probe groups), by driving native's own product-path entry point for each group and observing
 * the hook fire, instead of firing it directly.
 *
 * See `.agents/scratchpad/sessions/2026-09-03-native-payments-program-orientation/`
 * `plan-task-t4-bc-inventories.md`, "2. Hook argument shapes (arity and declared types)", and
 * the controller review's "Arity stays runtime, with a time box."
 *
 * Every one of the 38 groups below reaches its hook(s) through a real native call, ported from the
 * retired harness's own native branch for that group (`hook-shape-parity.php`, read at
 * `data/bc-inventory-sources/hook-shape-parity.php`). None of them fires the hook itself, so
 * `STATIC_ONLY` is empty: every hook in the manifest is proven at runtime here, and the name test
 * (`WooPaymentsPluginHookNamesContractTest`) reads `probed_hooks()` to skip re-proving them
 * statically.
 *
 * @since 11.2.0
 */
class WooPaymentsPluginHookArityContractTest extends WC_Unit_Test_Case {

	/**
	 * Hooks the retired manifest requires that no native product path could reach within the
	 * plan's time box, with the reason, and the fixture arity they still need to satisfy statically
	 * (via the name test's native fire-site scanner instead of a runtime probe).
	 *
	 * Empty: every one of the 70 manifest hooks has a working product-path probe below.
	 *
	 * @var array<string,string>
	 */
	private const STATIC_ONLY = array();

	/**
	 * Hooks whose native product path unavoidably triggers a real `_deprecated_hook()`/
	 * `wc_deprecated_hook()` notice on the way to firing (a deprecated sibling hook the plugin
	 * itself deprecated, or a deprecated alias native still supports), which WordPress's test case
	 * otherwise fails as "unexpected."
	 *
	 * @var array<string,true>
	 */
	private const TRIGGERS_DEPRECATION_NOTICE = array(
		'wc_payments_thank_you_page_bnpl_payment_method_logo_url' => true,
		'wcpay_multi_currency_should_hide_widgets' => true,
	);

	/**
	 * The `api`/`method` argument values `probe_api_transport()` drives, via
	 * `WooPaymentsApiClient::get_disputes( array() )` (`self::DISPUTES_API = 'disputes'`, method
	 * `'GET'`). `wcpay_api_request_params` fires as `apply_filters( 'wcpay_api_request_params',
	 * $params, $api, $method )`, so a same-type swap of `$api` and `$method` (both strings) passes a
	 * type check but fails this exact-value one.
	 *
	 * @var array{api:string,method:string}
	 */
	private const API_TRANSPORT_EXPECTED_ARGS = array(
		'api'    => 'disputes',
		'method' => 'GET',
	);

	/**
	 * Loaded `plugin-11.1.0-hooks.json` fixture, keyed by hook name.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static array $fixture_by_name;

	/**
	 * Load the plugin-11.1.0-hooks.json fixture once for the class.
	 */
	public static function wpSetUpBeforeClass(): void {
		$path = __DIR__ . '/Fixtures/plugin-11.1.0-hooks.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a committed test fixture, not user input.
		$fixture               = json_decode( (string) file_get_contents( $path ), true );
		self::$fixture_by_name = array();
		foreach ( $fixture['entries'] as $entry ) {
			self::$fixture_by_name[ $entry['name'] ] = $entry;
		}
	}

	/**
	 * Put native into the `active` state for the duration of one test (D9), and replace the WPCOM
	 * transport with a connected, canned-response double so API-client probe groups reach their hook
	 * instead of failing pre-flight on "site is not connected to WordPress.com" or making a real
	 * network call. Every probed group that goes through `WooPaymentsApiClient` (directly or via a
	 * REST controller) resolves the client through the DI container, so replacing its
	 * `WooPaymentsHttpClient` collaborator here covers all of them uniformly.
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		update_option( 'active_plugins', array() );
		update_option( NativePaymentsState::OPTION_NAME, NativePaymentsState::ACTIVE );
		wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->invalidate();
		wc_get_container()->get( NativePaymentsState::class )->invalidate();

		wc_get_container()->replace( WooPaymentsHttpClient::class, $this->fake_http_client() );
		wc_get_container()->reset_all_resolved();
	}

	/**
	 * A `WooPaymentsHttpClient` double reporting a connected site and returning a canned successful
	 * transport response for any request.
	 */
	private function fake_http_client(): WooPaymentsHttpClient {
		return new class() extends WooPaymentsHttpClient {
			/**
			 * @return bool
			 */
			public function is_connected(): bool {
				return true;
			}

			/**
			 * @return int|null
			 */
			public function get_blog_id(): ?int {
				return 123456789;
			}

			/**
			 * @param string      $method         HTTP method.
			 * @param string      $path           WPCOM API path.
			 * @param string[]    $headers        Request headers.
			 * @param string|null $body           Encoded request body.
			 * @param int         $timeout        Request timeout.
			 * @param bool        $use_user_token Whether to sign with the connection-owner user token.
			 * @param bool        $blocking       Whether the request should block for the response.
			 * @return array<string,mixed>
			 */
			public function request( string $method, string $path, array $headers = array(), ?string $body = null, int $timeout = 70, bool $use_user_token = false, bool $blocking = true ) {
				unset( $method, $path, $headers, $body, $timeout, $use_user_token, $blocking );

				return array(
					'body'     => '{"data":[]}',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'headers'  => array(),
					'cookies'  => array(),
				);
			}
		};
	}

	/**
	 * Invalidate the singleton native state and undo any container replacement a probe made, so no
	 * later test observes state this test put in place (gate: `NativePaymentsBootstrapTest` and
	 * friends stay clean). The enabling filter and the stored option are undone first, the way
	 * `NativePaymentsStateTest` does, so nothing left in `parent::tearDown()` can re-cache `active`
	 * for the next test.
	 */
	public function tearDown(): void {
		try {
			remove_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
			delete_option( NativePaymentsState::OPTION_NAME );
			wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->invalidate();
			wc_get_container()->get( NativePaymentsState::class )->invalidate();
			$this->reset_container_replacements();
			wc_get_container()->reset_all_resolved();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * The 70 manifest hooks this class proves at runtime, mapped to the private probe method that
	 * reaches each one through native's own product path. Read by the name test
	 * (`WooPaymentsPluginHookNamesContractTest`) so it does not re-prove them statically.
	 *
	 * @return array<string,string>
	 */
	public static function probed_hooks(): array {
		return array(
			'wcpay_api_request_headers'                    => 'probe_api_transport',
			'wcpay_api_request_params'                     => 'probe_api_transport',
			'wcpay_api_request_response'                   => 'probe_api_transport',
			'wcpay_list_fraud_outcome_transactions_request' => 'probe_fraud_reports',
			'wcpay_list_fraud_outcome_transactions_summary_request' => 'probe_fraud_reports',
			'wcpay_get_fraud_outcome_transactions_search_autocomplete_request' => 'probe_fraud_reports',
			'wcpay_get_fraud_outcome_transactions_export_request' => 'probe_fraud_reports',
			'wcpay_get_pm_promotions_request'              => 'probe_api_promotions',
			'wcpay_activate_pm_promotion_request'          => 'probe_api_promotions',
			'wcpay_get_authorization_request'              => 'probe_api_authorizations',
			'wc_pay_get_authorizations_summary'            => 'probe_api_authorizations',
			'wcpay_list_documents_request'                 => 'probe_api_documents',
			'wcpay_validate_vat_request'                   => 'probe_api_vat',
			'wcpay_get_active_loan_summary_request'        => 'probe_api_capital',
			'wcpay_get_loans_request'                      => 'probe_api_capital',
			'wcpay_get_account_capital_link'               => 'probe_api_capital',
			'wcpay_get_dispute_status_counts'              => 'probe_api_disputes',
			'wcpay_list_transactions_request'              => 'probe_list_transactions',
			'wcpay_list_disputes_request'                  => 'probe_list_disputes',
			'wcpay_list_deposits_request'                  => 'probe_list_deposits',
			'wcpay_list_authorizations_request'            => 'probe_list_authorizations',
			'wcpay_metadata_from_order'                    => 'probe_order_metadata',
			'wcpay_upe_available_payment_methods'          => 'probe_payment_method_availability',
			'wcpay_payment_fields_js_config'               => 'probe_checkout_config',
			'wc_payments_account_id_for_intent_confirmation' => 'probe_checkout_config',
			'wc_payments_thank_you_page_bnpl_payment_method_logo_url' => 'probe_order_success_logos',
			'wc_payments_thank_you_page_lpm_payment_method_logo_url' => 'probe_order_success_logos',
			'wcpay_is_woopay_store_api_request'            => 'probe_store_api_request',
			'wcpay_payment_request_is_product_supported'   => 'probe_express_product',
			'wcpay_payment_request_product_data'           => 'probe_express_product',
			'wcpay_payment_request_supported_types'        => 'probe_express_product',
			'wcpay_payment_request_total_label'            => 'probe_express_product',
			'wcpay_test_mode'                              => 'probe_account_mode',
			'wcpay_dev_mode'                               => 'probe_account_mode',
			'wcpay_test_mode_onboarding'                   => 'probe_account_mode',
			'wcpay_database_cache_ttl'                     => 'probe_database_cache',
			'wcpay_get_add_payment_method_redirect_url'    => 'probe_add_payment_method',
			'wcpay_terminal_payment_completed_order_status' => 'probe_terminal_payment',
			'wcpay_create_customer_disallowed_order_statuses' => 'probe_customer_creation',
			'wcpay_shopper_tracking_enabled'               => 'probe_tracking',
			'wcpay_tracks_event_properties'                => 'probe_tracking',
			'wcpay_woopay_is_signed_with_blog_token'       => 'probe_woopay_signature',
			'wcpay_webhook_platform_checkout_order_status_changed' => 'probe_woopay_order_status',
			'wc_payments_get_onboarding_data_args'         => 'probe_onboarding',
			'woocommerce_payments_account_refreshed'       => 'probe_account_refresh',
			'woocommerce_payments_before_webhook_delivery' => 'probe_webhook_delivery',
			'woocommerce_payments_after_webhook_delivery'  => 'probe_webhook_delivery',
			'woocommerce_woocommerce_payments_payment_requires_action' => 'probe_payment_requires_action',
			'wcpay_multi_currency_storefront_widget_css'   => 'probe_mc_storefront',
			'wcpay_multi_currency_storefront_widget_instance' => 'probe_mc_storefront',
			'wcpay_multi_currency_storefront_widget_args'  => 'probe_mc_storefront',
			'wcpay_multi_currency_override_notice_country' => 'probe_mc_notice',
			'wcpay_multi_currency_override_notice_currency_name' => 'probe_mc_notice',
			'wcpay_multi_currency_apply_charm_only_to_products' => 'probe_mc_frontend',
			'wcpay_multi_currency_override_selected_currency' => 'probe_mc_state',
			'wcpay_multi_currency_should_return_store_currency' => 'probe_mc_compatibility',
			'wcpay_multi_currency_should_convert_product_price' => 'probe_mc_compatibility',
			'wcpay_multi_currency_should_convert_coupon_amount' => 'probe_mc_compatibility',
			'wcpay_multi_currency_should_disable_currency_switching' => 'probe_mc_compatibility',
			'wcpay_multi_currency_should_hide_widgets'     => 'probe_mc_compatibility',
			'wcpay_multi_currency_async_price_type'        => 'probe_mc_async',
			'wcpay_multi_currency_disable_filter_select_clauses' => 'probe_mc_analytics',
			'wcpay_multi_currency_filter_select_clauses'   => 'probe_mc_analytics',
			'wcpay_multi_currency_disable_filter_join_clauses' => 'probe_mc_analytics',
			'wcpay_multi_currency_filter_join_clauses'     => 'probe_mc_analytics',
			'wcpay_multi_currency_disable_filter_where_clauses' => 'probe_mc_analytics',
			'wcpay_multi_currency_filter_where_clauses'    => 'probe_mc_analytics',
			'wcpay_multi_currency_disable_filter_select_orders_clauses' => 'probe_mc_analytics',
			'wcpay_multi_currency_filter_select_orders_clauses' => 'probe_mc_analytics',
			'wcpay_usd_format'                             => 'probe_mc_localization',
		);
	}

	/**
	 * `[hook, probe method]` rows, one per probed hook.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function hook_probe_provider(): array {
		$rows = array();
		foreach ( self::probed_hooks() as $hook => $probe_method ) {
			$rows[ $hook ] = array( $hook, $probe_method );
		}

		return $rows;
	}

	/**
	 * @testdox The probed set is exactly the preserved manifest, and every probed name is in the fixture.
	 */
	public function test_probe_set_is_the_preserved_manifest(): void {
		$probed = array_keys( self::probed_hooks() );

		$this->assertCount( 70, $probed, 'The preserved hook-shape manifest has 70 hooks.' );
		$this->assertSame( array_unique( $probed ), $probed, 'Every probed hook name must be unique.' );

		$missing_from_fixture = array_diff( $probed, array_keys( self::$fixture_by_name ) );
		$this->assertSame( array(), $missing_from_fixture, 'Every probed hook must be a fixture entry: ' . implode( ', ', $missing_from_fixture ) );
	}

	/**
	 * @dataProvider hook_probe_provider
	 * @testdox Native fires $hook on its product path with the plugin 11.1.0 arity and declared types.
	 *
	 * @param string $hook         Hook name.
	 * @param string $probe_method Private probe method name.
	 */
	public function test_native_fires_plugin_hook_with_plugin_arity( string $hook, string $probe_method ): void {
		if ( isset( self::TRIGGERS_DEPRECATION_NOTICE[ $hook ] ) ) {
			$this->setExpectedDeprecated( $hook );
		}

		$entry    = self::$fixture_by_name[ $hook ];
		$captured = null;

		$recorder = static function ( ...$args ) use ( &$captured ) {
			if ( null === $captured ) {
				$captured = $args;
			}

			return $args[0] ?? null;
		};
		$stopper  = static function () use ( $hook ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal control-flow exception message, never rendered as output.
			throw new WooPaymentsHookArityProbeReached( 'probe reached ' . $hook );
		};

		add_filter( $hook, $recorder, PHP_INT_MIN, 99 );
		add_filter( $hook, $stopper, PHP_INT_MIN + 1, 99 );

		try {
			$this->$probe_method();
		} catch ( WooPaymentsHookArityProbeReached $reached ) {
			unset( $reached );
		} finally {
			remove_filter( $hook, $recorder, PHP_INT_MIN );
			remove_filter( $hook, $stopper, PHP_INT_MIN + 1 );
		}

		$this->assertNotNull( $captured, "Native must fire {$hook} on its product path (plugin_site: {$entry['plugin_site']})." );

		// The fixture's top-level `arity`/`params` are the plugin's own MAXIMUM-arity call site
		// (`data/t4-bc-tests-4-5-review.md` R3: for `wcpay_metadata_from_order`, fired at both a
		// 2- and a 3-argument plugin site, this is 3), so a callback registered against either
		// plugin population still receives every argument it declared.
		$this->assertCount( $entry['arity'], $captured, "Native fired {$hook} with a different argument count than the plugin." );

		if ( null !== $entry['params'] ) {
			foreach ( $entry['params'] as $index => $type_spec ) {
				$this->assert_argument_type( $type_spec, $captured[ $index ], $hook, $index );
			}
		}

		// `$api` and `$method` are both strings, so the type check above cannot see them swapped by
		// position. `probe_api_transport()` drives a known request, so its exact argument values are
		// asserted here instead.
		if ( 'wcpay_api_request_params' === $hook ) {
			$this->assertSame( self::API_TRANSPORT_EXPECTED_ARGS['api'], $captured[1], "{$hook} argument 1 (api) must be the request path the probe drove." );
			$this->assertSame( self::API_TRANSPORT_EXPECTED_ARGS['method'], $captured[2], "{$hook} argument 2 (method) must be the HTTP method the probe drove." );
		}
	}

	/**
	 * Assert a captured argument satisfies a fixture-declared `@param` type (possibly a `|` union).
	 *
	 * @param string $type_spec Declared type, e.g. `array`, `WC_Order`, `array|bool`.
	 * @param mixed  $value     Captured runtime value.
	 * @param string $hook      Hook name (for the failure message).
	 * @param int    $index     Argument index (for the failure message).
	 */
	private function assert_argument_type( string $type_spec, $value, string $hook, int $index ): void {
		$scalar_map = array(
			'bool'   => 'boolean',
			'int'    => 'integer',
			'float'  => 'double',
			'string' => 'string',
			'array'  => 'array',
			'null'   => 'NULL',
		);

		foreach ( explode( '|', $type_spec ) as $candidate ) {
			$candidate = ltrim( $candidate, '?' );

			if ( 'mixed' === $candidate || ( 'object' === $candidate && is_object( $value ) ) ) {
				return;
			}

			// `X[]` (an array of X): the declared element type is documentation for humans, not a
			// second runtime check this test repeats; matching plain `array` is what the plugin's
			// own docblock promises at the argument boundary.
			if ( '[]' === substr( $candidate, -2 ) ) {
				$candidate = 'array';
			}

			if ( isset( $scalar_map[ $candidate ] ) && gettype( $value ) === $scalar_map[ $candidate ] ) {
				return;
			}

			if ( class_exists( $candidate ) && $value instanceof $candidate ) {
				return;
			}
		}

		$this->fail( "{$hook} argument {$index} must be a `{$type_spec}`, got " . ( is_object( $value ) ? get_class( $value ) : gettype( $value ) ) . '.' );
	}

	// -----------------------------------------------------------------------------------------
	// Reflection helpers (ported from the retired hook-shape-parity.php harness).
	// -----------------------------------------------------------------------------------------

	/**
	 * Invoke a private/protected instance method.
	 *
	 * @param object           $target Target object.
	 * @param string           $method Method name.
	 * @param array<int,mixed> $args   Positional arguments.
	 * @return mixed
	 */
	private function native_invoke( object $target, string $method, array $args = array() ) {
		$reflection = new ReflectionMethod( $target, $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( $target, $args );
	}

	// -----------------------------------------------------------------------------------------
	// Probe groups, one per `hook-shape-inventory.json` manifest group (38 total).
	// -----------------------------------------------------------------------------------------

	/**
	 * Group `api_transport` (3 hooks): the API client's request-building and response-handling
	 * surrounding path, via a disputes list call over the class-level `WooPaymentsHttpClient` double.
	 */
	private function probe_api_transport(): void {
		$api_client = wc_get_container()->get( WooPaymentsApiClient::class );
		$this->run_ignoring_non_probe_errors( static fn() => $api_client->get_disputes( array() ) );
	}

	/**
	 * Group `fraud_reports` (4 hooks): the fraud-outcome transactions REST endpoints.
	 */
	private function probe_fraud_reports(): void {
		$controller = wc_get_container()->get( WooPaymentsTransactionsRestController::class );
		$request    = new WP_REST_Request( 'GET', '/wc/v3/payments/transactions/hook-shape' );
		$request->set_param( 'status', 'block' );
		$request->set_param( 'page', 1 );
		$request->set_param( 'pagesize', 1 );

		foreach ( array( 'get_fraud_outcome_transactions', 'get_fraud_outcome_transactions_summary', 'get_fraud_outcome_transactions_search_autocomplete', 'get_fraud_outcome_transactions_export' ) as $method ) {
			$this->run_ignoring_non_probe_errors( static fn() => $controller->$method( $request ) );
		}
	}

	/**
	 * Group `api_promotions` (2 hooks): PM promotions API client calls.
	 */
	private function probe_api_promotions(): void {
		$api_client = wc_get_container()->get( WooPaymentsApiClient::class );

		$this->run_ignoring_non_probe_errors(
			static fn() => $api_client->get_pm_promotions(
				array(
					'locale'     => 'en_US',
					'dismissals' => array(),
				)
			)
		);
		$this->run_ignoring_non_probe_errors( static fn() => $api_client->activate_pm_promotion( 'hook-shape-promotion' ) );
	}

	/**
	 * Group `api_authorizations` (2 hooks): single-authorization and authorizations-summary API
	 * client calls.
	 */
	private function probe_api_authorizations(): void {
		$api_client = wc_get_container()->get( WooPaymentsApiClient::class );

		$this->run_ignoring_non_probe_errors( static fn() => $api_client->get_authorization( 'pi_hook_shape' ) );
		$this->run_ignoring_non_probe_errors( static fn() => $api_client->get_authorizations_summary() );
	}

	/**
	 * Group `api_documents` (1 hook): documents list API client call.
	 */
	private function probe_api_documents(): void {
		$api_client = wc_get_container()->get( WooPaymentsApiClient::class );
		$this->run_ignoring_non_probe_errors( static fn() => $api_client->get_documents( array( 'pagesize' => 1 ) ) );
	}

	/**
	 * Group `api_vat` (1 hook): VAT validation API client call.
	 */
	private function probe_api_vat(): void {
		$api_client = wc_get_container()->get( WooPaymentsApiClient::class );
		$this->run_ignoring_non_probe_errors( static fn() => $api_client->validate_vat( 'EU123456789' ) );
	}

	/**
	 * Group `api_capital` (3 hooks): capital summary, loans and capital-link API client calls.
	 */
	private function probe_api_capital(): void {
		$api_client = wc_get_container()->get( WooPaymentsApiClient::class );

		$this->run_ignoring_non_probe_errors( static fn() => $api_client->get_capital_active_loan_summary() );
		$this->run_ignoring_non_probe_errors( static fn() => $api_client->get_capital_loans() );
		$this->run_ignoring_non_probe_errors( static fn() => $api_client->create_capital_link( 'http://localhost/hook-shape-return', 'http://localhost/hook-shape-refresh' ) );
	}

	/**
	 * Group `api_disputes` (1 hook): dispute status counts API client call.
	 */
	private function probe_api_disputes(): void {
		$api_client = wc_get_container()->get( WooPaymentsApiClient::class );
		$this->run_ignoring_non_probe_errors( static fn() => $api_client->get_dispute_status_counts() );
	}

	/**
	 * Run a probe callable, letting a `WooPaymentsHookArityProbeReached` propagate (that is the
	 * success path) while swallowing any other exception the canned `WooPaymentsHttpClient` double's
	 * response provokes downstream of the probed hook (the probe only needs execution to reach the
	 * hook, not to complete cleanly afterward).
	 *
	 * @param callable $probe Probe callable.
	 */
	private function run_ignoring_non_probe_errors( callable $probe ): void {
		try {
			$probe();
		} catch ( WooPaymentsHookArityProbeReached $reached ) {
			throw $reached;
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
		}
	}

	/**
	 * Groups `list_transactions`, `list_disputes`, `list_deposits`, `list_authorizations` (1 hook
	 * each): a paginated list REST endpoint, identical in shape across the four controllers.
	 *
	 * @param string $controller_class Controller class name.
	 * @param string $method           List method name.
	 * @param string $route            REST route (for the request object only; routing is not
	 *                                 exercised here).
	 */
	private function probe_list_endpoint( string $controller_class, string $method, string $route ): void {
		$controller = wc_get_container()->get( $controller_class );
		$request    = new WP_REST_Request( 'GET', $route );
		$request->set_param( 'page', 1 );
		$request->set_param( 'pagesize', 1 );
		$this->run_ignoring_non_probe_errors( static fn() => $controller->$method( $request ) );
	}

	/**
	 * Group `list_transactions` (1 hook): the transactions list REST endpoint.
	 */
	private function probe_list_transactions(): void {
		$this->probe_list_endpoint( WooPaymentsTransactionsRestController::class, 'get_transactions', '/wc/v3/payments/transactions' );
	}

	/**
	 * Group `list_disputes` (1 hook): the disputes list REST endpoint.
	 */
	private function probe_list_disputes(): void {
		$this->probe_list_endpoint( WooPaymentsDisputesRestController::class, 'get_disputes', '/wc/v3/payments/disputes' );
	}

	/**
	 * Group `list_deposits` (1 hook): the deposits list REST endpoint.
	 */
	private function probe_list_deposits(): void {
		$this->probe_list_endpoint( WooPaymentsDepositsRestController::class, 'get_deposits', '/wc/v3/payments/deposits' );
	}

	/**
	 * Group `list_authorizations` (1 hook): the authorizations list REST endpoint.
	 */
	private function probe_list_authorizations(): void {
		$this->probe_list_endpoint( WooPaymentsAuthorizationsRestController::class, 'get_authorizations', '/wc/v3/payments/authorizations' );
	}

	/**
	 * Group `order_metadata` (1 hook): the intent-metadata builder over a real order.
	 */
	private function probe_order_metadata(): void {
		$order = wc_create_order();
		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_last_name( 'Lovelace' );
		$order->set_billing_email( 'ada@example.test' );
		$order->save();

		WooPaymentsIntentRequestBuilder::metadata_from_order( $order, 'single', 'no' );
	}

	/**
	 * Group `payment_method_availability` (1 hook): the available-payment-methods registry read.
	 */
	private function probe_payment_method_availability(): void {
		$registry = wc_get_container()->get( WooPaymentsPaymentMethodRegistry::class );
		$registry->get_all();
	}

	/**
	 * Group `checkout_config` (2 hooks): the checkout payment-fields JS config builder.
	 */
	private function probe_checkout_config(): void {
		$bridge = wc_get_container()->get( WooPaymentsCheckoutBridge::class );
		$bridge->get_payment_fields_js_config();
	}

	/**
	 * Group `order_success_logos` (2 hooks): the thank-you-page payment method logo renderer.
	 */
	private function probe_order_success_logos(): void {
		$order       = new WC_Order();
		$page        = wc_get_container()->get( WooPaymentsOrderSuccessPage::class );
		$registry    = wc_get_container()->get( WooPaymentsPaymentMethodRegistry::class );
		$definitions = $registry->get_all();
		$definition  = $definitions['ideal'] ?? reset( $definitions );

		// A registry with no definitions must fail this row, not silently skip it: an empty
		// registry is exactly the regression this probe exists to catch.
		$this->assertNotFalse( $definition, 'The native payment method registry must have at least one definition to probe order_success_logos.' );

		$this->native_invoke( $page, 'render_definition_title', array( $definition, $order, false ) );
	}

	/**
	 * Group `store_api_request` (1 hook): the fraud-prevention error message builder, which reads
	 * whether the current request is a WooPay Store API request.
	 */
	private function probe_store_api_request(): void {
		$gateway = new NativeWooPaymentsGateway();
		$this->native_invoke( $gateway, 'get_fraud_prevention_error_message', array( true ) );
	}

	/**
	 * Group `express_product` (4 hooks): the express-checkout product-support and product-data
	 * readers, over a global `$product`.
	 */
	private function probe_express_product(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Hook shape product' );
		$product->set_regular_price( '12.34' );
		$product->set_price( '12.34' );

		$had_product        = array_key_exists( 'product', $GLOBALS );
		$previous_product   = $GLOBALS['product'] ?? null;
		$GLOBALS['product'] = $product;

		// `get_product_for_product_page()` only trusts the global `$product` while WooCommerce is
		// mid-way through rendering the single-product add-to-cart form; reproduce that context so
		// native reaches the product (rather than falling back to the queried post, which a unit
		// test has none of).
		$probe = function () {
			$service = wc_get_container()->get( WooPaymentsExpressCheckoutService::class );
			$this->native_invoke( $service, 'is_product_supported' );
			$this->native_invoke( $service, 'get_product_data' );
		};

		try {
			add_action( 'woocommerce_after_add_to_cart_form', $probe );
			do_action( 'woocommerce_after_add_to_cart_form' );
		} finally {
			remove_action( 'woocommerce_after_add_to_cart_form', $probe );
			if ( $had_product ) {
				$GLOBALS['product'] = $previous_product;
			} else {
				unset( $GLOBALS['product'] );
			}
		}
	}

	/**
	 * Group `account_mode` (3 hooks): the account test/dev/test-onboarding mode readers.
	 */
	private function probe_account_mode(): void {
		$service = wc_get_container()->get( WooPaymentsAccountService::class );
		$service->is_test_mode_enabled();
		$service->is_dev_mode_enabled();
		$service->is_test_mode_onboarding_enabled();
	}

	/**
	 * Group `database_cache` (1 hook): the account cache TTL reader.
	 */
	private function probe_database_cache(): void {
		$service        = wc_get_container()->get( WooPaymentsAccountService::class );
		$cache_contents = array(
			'data'               => array(),
			'errored'            => false,
			'consecutive_errors' => 0,
		);
		$this->native_invoke( $service, 'get_account_cache_ttl', array( $cache_contents ) );
	}

	/**
	 * Group `add_payment_method` (1 hook): the My Account "add payment method" gateway action, with
	 * test doubles for the API/customer/token services swapped in via the DI container (the
	 * container-replacement pattern `NativeWooPaymentsGatewayTest` uses), since
	 * `NativeWooPaymentsGateway` resolves them lazily through `wc_get_container()`.
	 */
	private function probe_add_payment_method(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Saving/restoring $_POST around an in-process probe request, not handling a real HTTP submission.
		$previous_post    = $_POST;
		$previous_user_id = get_current_user_id();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Building a probe request in-process, not handling a real HTTP submission.
		$_POST['wcpay-setup-intent'] = 'seti_hook_shape';
		if ( 0 >= $previous_user_id ) {
			wp_set_current_user( 1 );
		}

		$token = new WC_Payment_Token_CC();
		$token->set_token( 'pm_hook_shape' );

		$api_client = $this->createStub( WooPaymentsApiClient::class );
		$api_client->method( 'get_setup_intention' )->willReturn(
			array(
				'id'             => 'seti_hook_shape',
				'status'         => 'succeeded',
				'customer'       => 'cus_hook_shape',
				'payment_method' => 'pm_hook_shape',
			)
		);
		// `is_available()` (called by fraud-prevention gating ahead of the probed filter) is not
		// stubbed, so it still needs its real collaborators initialized.
		$api_client->init( $this->fake_http_client(), wc_get_container()->get( WooPaymentsAccountService::class ) );

		$customer_service = $this->createStub( WooPaymentsCustomerService::class );
		$customer_service->method( 'get_customer_id_by_user_id' )->willReturn( 'cus_hook_shape' );

		$token_service = $this->createStub( WooPaymentsTokenService::class );
		$token_service->method( 'get_or_create_token_for_user' )->willReturn( $token );

		wc_get_container()->replace( WooPaymentsApiClient::class, $api_client );
		wc_get_container()->replace( WooPaymentsCustomerService::class, $customer_service );
		wc_get_container()->replace( WooPaymentsTokenService::class, $token_service );
		wc_get_container()->reset_all_resolved();

		try {
			$gateway = new NativeWooPaymentsGateway();
			$gateway->add_payment_method();
		} finally {
			$_POST = $previous_post;
			wp_set_current_user( $previous_user_id );
		}
	}

	/**
	 * Group `terminal_payment` (1 hook): the mobile terminal-payment-completed order status
	 * resolver.
	 */
	private function probe_terminal_payment(): void {
		$order      = new WC_Order();
		$controller = wc_get_container()->get( WooPaymentsMobileRestController::class );

		$this->native_invoke(
			$controller,
			'mark_terminal_payment_completed',
			array(
				$order,
				array(
					'id'       => 'pi_hook_shape',
					'status'   => 'succeeded',
					'currency' => 'usd',
				),
				'pi_hook_shape',
			)
		);
	}

	/**
	 * Group `customer_creation` (1 hook): the mobile "create Stripe customer for order" REST
	 * action.
	 */
	private function probe_customer_creation(): void {
		$order = wc_create_order();

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/create_customer' );
		$request->set_param( 'order_id', $order->get_id() );

		$controller = wc_get_container()->get( WooPaymentsMobileRestController::class );
		$this->run_ignoring_non_probe_errors( static fn() => $controller->create_customer( $request ) );
	}

	/**
	 * Group `tracking` (2 hooks): shopper-tracking-enabled and per-event property readers.
	 */
	private function probe_tracking(): void {
		$controller = wc_get_container()->get( WooPaymentsFrontendTrackingController::class );
		$controller->is_shopper_tracking_enabled();
		$controller->record_user_event( 'hook_shape', array( 'source' => 'hook_shape' ) );
	}

	/**
	 * Group `woopay_signature` (1 hook): the WooPay session controller's request-signature check.
	 */
	private function probe_woopay_signature(): void {
		$controller = wc_get_container()->get( WooPaymentsWooPaySessionController::class );

		$auth_class = 'Automattic\\Jetpack\\Connection\\Rest_Authentication';
		$restore    = null;

		if ( class_exists( $auth_class ) && method_exists( $auth_class, 'init' ) ) {
			$auth_instance   = $auth_class::init();
			$auth_reflection = new ReflectionClass( $auth_class );
			if ( $auth_reflection->hasProperty( 'rest_authentication_status' ) && $auth_reflection->hasProperty( 'rest_authentication_type' ) ) {
				$status_property = $auth_reflection->getProperty( 'rest_authentication_status' );
				$type_property   = $auth_reflection->getProperty( 'rest_authentication_type' );
				$status_property->setAccessible( true );
				$type_property->setAccessible( true );
				$status_before = $status_property->getValue( $auth_instance );
				$type_before   = $type_property->getValue( $auth_instance );
				$status_property->setValue( $auth_instance, true );
				$type_property->setValue( $auth_instance, 'blog' );
				$restore = static function () use ( $status_property, $type_property, $auth_instance, $status_before, $type_before ): void {
					$status_property->setValue( $auth_instance, $status_before );
					$type_property->setValue( $auth_instance, $type_before );
				};
			}
		}

		try {
			$request = new WP_REST_Request( 'GET', '/payments/woopay/session' );
			$request->set_header( 'User-Agent', 'WooPay' );
			$controller->check_permission( $request );
		} finally {
			if ( null !== $restore ) {
				$restore();
			}
		}
	}

	/**
	 * Group `woopay_order_status` (1 hook): the WooPay order-status webhook sync.
	 */
	private function probe_woopay_order_status(): void {
		$order = wc_create_order();
		$order->update_meta_data( 'is_woopay', true );
		$order->save();

		$sync = wc_get_container()->get( WooPaymentsWooPayOrderStatusSync::class );
		$sync->send_webhook( $order->get_id(), 'pending', 'processing' );
	}

	/**
	 * Group `onboarding` (1 hook): the account-onboarding-initialization API client call.
	 */
	private function probe_onboarding(): void {
		$api_client = wc_get_container()->get( WooPaymentsApiClient::class );
		$this->run_ignoring_non_probe_errors( static fn() => $api_client->initialize_onboarding( false, 'http://localhost/hook-shape-return' ) );
	}

	/**
	 * Group `account_refresh` (1 hook): the manifest's own entry point,
	 * `WooPaymentsAccountService::refresh_account_data()` (the plugin's main refresh path, not the
	 * webhook-only strict variant), with the API client stubbed so an empty (but valid) account
	 * payload is returned without a real network call.
	 */
	private function probe_account_refresh(): void {
		$api_client = $this->createStub( WooPaymentsApiClient::class );
		$api_client->method( 'is_available' )->willReturn( true );
		$api_client->method( 'get_account' )->willReturn( array() );

		wc_get_container()->replace( WooPaymentsApiClient::class, $api_client );
		wc_get_container()->reset_all_resolved();

		$service = wc_get_container()->get( WooPaymentsAccountService::class );
		$this->run_ignoring_non_probe_errors( static fn() => $service->refresh_account_data() );
	}

	/**
	 * Group `webhook_delivery` (2 hooks): the webhook event ingestor's before/after processing
	 * hooks.
	 */
	private function probe_webhook_delivery(): void {
		$event_body = array(
			'id'   => '',
			'type' => 'hook_shape.probe',
			'data' => array(
				'object' => array(
					'id' => '',
				),
			),
		);

		$ingestor = wc_get_container()->get( WooPaymentsEventIngestor::class );
		$this->run_ignoring_non_probe_errors( static fn() => $ingestor->process( $event_body ) );
	}

	/**
	 * Group `payment_requires_action` (1 hook): the subscription-renewal "customer action
	 * required" outcome handler.
	 */
	private function probe_payment_requires_action(): void {
		$order = new WC_Order();
		$order->set_status( 'failed' );
		$order->set_currency( 'USD' );

		$gateway = new NativeWooPaymentsGateway();
		$outcome = new PaymentOutcome( PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION );

		$this->native_invoke( $gateway, 'maybe_handle_subscription_customer_action_required', array( $order, $outcome ) );
	}

	/**
	 * Build a minimal native multi-currency fixture (a default and a selected currency) for the
	 * multi-currency probe groups, the way the retired harness's `$build_native_mc_fixture` did.
	 *
	 * @return array{builder:MultiCurrencyStateBuilder,geolocation:MultiCurrencyGeolocationService,localization:MultiCurrencyLocalizationService,state:MultiCurrencyState}
	 */
	private function build_native_mc_fixture(): array {
		$localization  = wc_get_container()->get( MultiCurrencyLocalizationService::class );
		$default_code  = strtoupper( (string) get_woocommerce_currency() );
		$selected_code = 'EUR' === $default_code ? 'USD' : 'EUR';
		$country_code  = 'EUR' === $selected_code ? 'DE' : 'US';
		$default       = new MultiCurrencyCurrency( $localization, $default_code, 1.0, true );
		$selected      = new MultiCurrencyCurrency( $localization, $selected_code, 1.0, false );
		$currencies    = array(
			$default_code  => $default,
			$selected_code => $selected,
		);
		$state         = new MultiCurrencyState( $currencies, $currencies, $default, $selected );

		$builder = $this->createStub( MultiCurrencyStateBuilder::class );
		$builder->method( 'build' )->willReturn( $state );

		$geolocation = $this->createStub( MultiCurrencyGeolocationService::class );
		$geolocation->method( 'get_currency_by_customer_location' )->willReturn( $selected_code );
		$geolocation->method( 'get_country_by_customer_location' )->willReturn( $country_code );

		return array(
			'builder'      => $builder,
			'geolocation'  => $geolocation,
			'localization' => $localization,
			'state'        => $state,
		);
	}

	/**
	 * Group `mc_storefront` (3 hooks): the Storefront widget CSS and breadcrumb-defaults hooks.
	 */
	private function probe_mc_storefront(): void {
		$controller = wc_get_container()->get( MultiCurrencyStorefrontIntegrationController::class );
		$controller->handle_wp_enqueue_scripts();
		$controller->handle_woocommerce_breadcrumb_defaults( array( 'wrap_before' => '<nav class="woocommerce-breadcrumb">' ) );
	}

	/**
	 * Group `mc_notice` (2 hooks): the geolocation currency-update notice's country/currency-name
	 * override hooks.
	 */
	private function probe_mc_notice(): void {
		$fixture     = $this->build_native_mc_fixture();
		$controller  = new MultiCurrencySelectedCurrencyController();
		$persistence = new MultiCurrencySelectedCurrencyPersistenceService( $fixture['builder'] );
		$controller->set_persistence_service( $persistence );
		$controller->set_geolocation_service( $fixture['geolocation'] );
		$controller->handle_wp_footer();
	}

	/**
	 * Group `mc_frontend` (1 hook): the storefront public-config charm-pricing projection.
	 */
	private function probe_mc_frontend(): void {
		$fixture = $this->build_native_mc_fixture();
		$service = new MultiCurrencyFrontendProjectionService( $fixture['builder'], $fixture['localization'], $fixture['geolocation'] );
		$service->get_public_config();
	}

	/**
	 * Group `mc_state` (1 hook): the selected-currency override compatibility hook.
	 */
	private function probe_mc_state(): void {
		$controller = wc_get_container()->get( MultiCurrencyCompatibilityController::class );
		$controller->override_selected_currency();
	}

	/**
	 * Group `mc_compatibility` (5 hooks): the extension-compatibility predicate hooks.
	 */
	private function probe_mc_compatibility(): void {
		$controller = wc_get_container()->get( MultiCurrencyCompatibilityController::class );
		$product    = new WC_Product_Simple();
		$coupon     = new WC_Coupon();

		$controller->should_return_store_currency();
		$controller->should_convert_product_price( $product );
		$controller->should_convert_coupon_amount( $coupon );
		$controller->should_disable_currency_switching();
		$controller->should_hide_widgets();
	}

	/**
	 * Group `mc_async` (1 hook): the async price-skeleton renderer's price-type hook.
	 */
	private function probe_mc_async(): void {
		$controller = wc_get_container()->get( MultiCurrencyAsyncPriceRendererController::class );
		$controller->handle_wc_price( '$12.34', 12.34, array( 'currency' => 'USD' ), 12.34, 12.34 );
	}

	/**
	 * Group `mc_analytics` (8 hooks): the Analytics SQL-clause filter hooks.
	 */
	private function probe_mc_analytics(): void {
		$controller = wc_get_container()->get( MultiCurrencyAnalyticsController::class );
		$controller->set_hpos_resolver( static fn(): bool => false );
		$controller->set_request_args_resolver( static fn(): array => array( 'currency' => 'EUR' ) );
		$controller->set_default_currency_resolver( static fn(): string => 'USD' );

		$controller->handle_woocommerce_analytics_clauses_select( array( 'hook_shape_clause' ), 'orders_stats' );
		$controller->handle_woocommerce_analytics_clauses_join( array( 'hook_shape_clause' ), 'orders_stats' );
		$controller->handle_woocommerce_analytics_clauses_where( array( 'hook_shape_clause' ) );
		$controller->handle_woocommerce_analytics_clauses_select_orders( array( 'hook_shape_clause' ) );
	}

	/**
	 * Group `mc_localization` (1 hook, dynamic `wcpay_{currency}_format`): the per-currency format
	 * reader, probed for USD (the fixture's concrete `wcpay_usd_format` sample).
	 */
	private function probe_mc_localization(): void {
		$service = wc_get_container()->get( MultiCurrencyLocalizationService::class );
		$service->get_currency_format( 'USD' );
	}
}
