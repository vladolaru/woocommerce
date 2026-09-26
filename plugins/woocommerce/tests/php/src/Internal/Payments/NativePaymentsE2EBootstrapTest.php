<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use WC_Data_Store;
use WC_Payment_Token_CC;
use WC_Rate_Limiter;
use WC_Unit_Test_Case;
use WP_REST_Request;

/**
 * Tests for the native WooPayments E2E mu-plugin bootstrap.
 */
class NativePaymentsE2EBootstrapTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Missing E2E constant leaves native payments disabled.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_missing_constant_leaves_native_disabled(): void {
		$this->load_bootstrap();

		// EnvironmentIsolation clears this constant for the main process, but a
		// @runInSeparateProcess test runs in a child that does not inherit those
		// overrides, so it has to restate the precondition. wp-env defines the
		// constant for every environment including this container, which would
		// otherwise make the absent case unreachable on a machine configured to
		// run the WooPayments native E2E suite while still passing on CI.
		Constants::set_constant( 'E2E_WOOPAYMENTS_NATIVE', null );

		$this->assertFalse(
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising the bootstrap filter in a test.
			(bool) apply_filters( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, false ),
			'The test bootstrap must not change runtime ownership without the exact E2E constant.'
		);
		$this->assertTrue(
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising the bootstrap filter in a test.
			(bool) apply_filters( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, true ),
			'The test bootstrap must preserve an existing rollout signal without the exact E2E constant.'
		);
	}

	/**
	 * @testdox Exact true E2E constant enables native payments before owner resolution.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_true_constant_enables_native_before_owner_resolution(): void {
		$this->define_native_e2e_constant();
		$this->load_bootstrap();

		$this->assertTrue(
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising the bootstrap filter in a test.
			(bool) apply_filters( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, false ),
			'The mu-plugin filter must enable native payments before the arbiter resolves ownership.'
		);
	}

	/**
	 * @testdox Active standalone WooPayments plugin wins over the native E2E bootstrap.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_active_standalone_plugin_still_wins(): void {
		$this->define_native_e2e_constant();
		$this->load_bootstrap();
		update_option( 'active_plugins', array( NativePaymentsRuntimeArbiter::PLUGIN_FILE ) );

		$arbiter = wc_get_container()->get( NativePaymentsRuntimeArbiter::class );

		$this->assertSame(
			NativePaymentsRuntimeArbiter::OWNER_PLUGIN,
			$arbiter->get_runtime_owner(),
			'Plugin-wins ownership must remain authoritative when the standalone plugin is active.'
		);
	}

	/**
	 * @testdox Native kill switch disables the E2E native owner.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_kill_switch_disables_native_owner(): void {
		$this->define_native_e2e_constant();
		$this->load_bootstrap();
		update_option( 'active_plugins', array() );
		update_option( NativePaymentsRuntimeArbiter::NATIVE_RUNTIME_KILL_SWITCH_OPTION, true );

		$arbiter = wc_get_container()->get( NativePaymentsRuntimeArbiter::class );

		$this->assertSame(
			NativePaymentsRuntimeArbiter::OWNER_NONE,
			$arbiter->get_runtime_owner(),
			'The host-controlled kill switch must disable E2E native ownership.'
		);
	}

	/**
	 * @testdox Runtime status route rejects an unauthenticated request.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_status_route_rejects_unauthenticated_request(): void {
		$this->load_bootstrap();
		wp_set_current_user( 0 );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Initializing the REST server in a test.
		do_action( 'rest_api_init' );

		$response = rest_do_request(
			new WP_REST_Request( 'GET', '/wc-native-payments-e2e/v1/status' )
		);

		$this->assertSame( 401, $response->get_status(), 'Unauthenticated diagnostics must be rejected.' );
	}

	/**
	 * @testdox Saved-card evidence route rejects an unauthenticated request.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_saved_card_evidence_route_rejects_unauthenticated_request(): void {
		$this->load_bootstrap();
		wp_set_current_user( 0 );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Initializing the REST server in a test.
		do_action( 'rest_api_init' );

		$response = rest_do_request(
			new WP_REST_Request( 'GET', '/wc-native-payments-e2e/v1/saved-card-evidence' )
		);

		$this->assertSame( 401, $response->get_status(), 'Unauthenticated saved-card evidence must be rejected.' );
	}

	/**
	 * @testdox Saved-card evidence returns exact local token mappings and rate-limit readiness.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_saved_card_evidence_returns_exact_local_tokens_and_creation_readiness(): void {
		$this->load_bootstrap();
		$user_id = self::factory()->user->create(
			array(
				'user_login' => 'saved-card-customer',
				'role'       => 'customer',
			)
		);
		$token   = $this->create_card_token( $user_id, 'pm_exact', true );
		$this->create_card_token( $user_id, 'pm_unscoped', false, '' );
		WC_Rate_Limiter::set_rate_limit( 'add_payment_method_' . $user_id, 20 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Initializing the REST server in a test.
		do_action( 'rest_api_init' );
		$request = new WP_REST_Request( 'GET', '/wc-native-payments-e2e/v1/saved-card-evidence' );
		$request->set_param( 'customer_username', 'saved-card-customer' );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'Authenticated local saved-card evidence should be readable.' );
		$this->assertFalse( $data['creation_ready'], 'An active Core add-payment-method limiter must report not ready.' );
		$this->assertSame(
			array(
				array(
					'token_id'          => $token->get_id(),
					'payment_method_id' => 'pm_exact',
					'is_default'        => true,
				),
			),
			$data['tokens'],
			'Local evidence must preserve the exact WooCommerce token and provider payment-method mapping.'
		);
	}

	/**
	 * @testdox Named saved-card evidence proves the exact local mapping and provider customer.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_named_saved_card_evidence_proves_local_mapping_and_provider_customer(): void {
		$this->define_native_e2e_constant();
		$this->load_bootstrap();
		$user_id          = self::factory()->user->create(
			array(
				'user_login' => 'native-saved-card-customer',
				'role'       => 'customer',
			)
		);
		$token            = $this->create_card_token( $user_id, 'pm_exact', true );
		$customer_service = new class() extends WooPaymentsCustomerService {
			/**
			 * Return deterministic provider customer evidence.
			 *
			 * @param int|null $user_id WordPress user ID.
			 * @return string
			 */
			public function get_persisted_customer_id_by_user_id( ?int $user_id ): ?string {
				unset( $user_id );
				return 'cus_exact';
			}
		};
		wc_get_container()->replace( WooPaymentsCustomerService::class, $customer_service );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Initializing the REST server in a test.
		do_action( 'rest_api_init' );
		$request = new WP_REST_Request( 'GET', '/wc-native-payments-e2e/v1/saved-card-evidence' );
		$request->set_param( 'customer_username', 'native-saved-card-customer' );
		$request->set_param( 'token_id', $token->get_id() );
		$request->set_param( 'payment_method_id', 'pm_exact' );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'Exact native saved-card evidence should be readable.' );
		$this->assertSame( 'cus_exact', $data['provider_customer_id'], 'Evidence must name the exact provider customer.' );
		$this->assertArrayNotHasKey(
			'provider_default_payment_method_id',
			$data,
			'Evidence must not depend on an unsupported individual-customer provider read.'
		);
	}

	/**
	 * @testdox Named saved-card evidence rejects a mismatched provider payment-method ID.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_named_saved_card_evidence_rejects_mismatched_local_mapping(): void {
		$this->define_native_e2e_constant();
		$this->load_bootstrap();
		$user_id = self::factory()->user->create(
			array(
				'user_login' => 'mismatched-saved-card-customer',
				'role'       => 'customer',
			)
		);
		$token   = $this->create_card_token( $user_id, 'pm_actual', true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Initializing the REST server in a test.
		do_action( 'rest_api_init' );
		$request = new WP_REST_Request( 'GET', '/wc-native-payments-e2e/v1/saved-card-evidence' );
		$request->set_param( 'customer_username', 'mismatched-saved-card-customer' );
		$request->set_param( 'token_id', $token->get_id() );
		$request->set_param( 'payment_method_id', 'pm_expected' );

		$response = rest_do_request( $request );

		$this->assertSame( 409, $response->get_status(), 'Mismatched local token evidence must fail closed.' );
		$this->assertSame( 'saved_card_mapping_mismatch', $response->get_data()['code'], 'The mismatch must be explicit.' );
	}

	/**
	 * @testdox The E2E runtime does not register a surrogate WooPayments cutover action.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_runtime_does_not_register_surrogate_cutover_action(): void {
		$this->define_native_e2e_constant();
		$this->define_transition_e2e_constant();
		$this->load_bootstrap();

		$this->assertFalse(
			has_action( 'admin_post_wc_native_payments_e2e_cutover' ),
			'The pilot must drive the product-owned WooPaymentsCutoverController instead of an MU-plugin surrogate.'
		);
	}

	/**
	 * @testdox Runtime status never trusts locally writable callback proof.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_status_rejects_locally_writable_callback_proof(): void {
		$this->define_native_e2e_constant();
		$this->define_transition_e2e_constant();
		$this->load_bootstrap();
		\Jetpack_Options::update_option( 'id', 321 );
		update_option(
			'woocommerce_native_payments_e2e_callback_probe',
			array(
				'registered'    => true,
				'reachable'     => true,
				'wpcom_blog_id' => 321,
				'proved_at'     => time(),
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Initializing the REST server in a test.
		do_action( 'rest_api_init' );

		$response = rest_do_request(
			new WP_REST_Request( 'GET', '/wc-native-payments-e2e/v1/status' )
		);

		$this->assertSame(
			array(
				'registered'    => false,
				'reachable'     => false,
				'wpcom_blog_id' => 0,
			),
			$response->get_data()['callback_probe'],
			'The local runtime must not be able to invent callback readiness.'
		);
	}

	/**
	 * @testdox Runtime status rejects stale or wrong-blog callback proof.
	 *
	 * @dataProvider invalid_callback_proof_provider
	 *
	 * @param array<string,mixed> $proof Invalid callback proof.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_status_rejects_invalid_callback_proof( array $proof ): void {
		$this->define_native_e2e_constant();
		$this->define_transition_e2e_constant();
		$this->load_bootstrap();
		\Jetpack_Options::update_option( 'id', 321 );
		update_option( 'woocommerce_native_payments_e2e_callback_probe', $proof );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Initializing the REST server in a test.
		do_action( 'rest_api_init' );

		$response = rest_do_request(
			new WP_REST_Request( 'GET', '/wc-native-payments-e2e/v1/status' )
		);

		$this->assertSame(
			array(
				'registered'    => false,
				'reachable'     => false,
				'wpcom_blog_id' => 0,
			),
			$response->get_data()['callback_probe'],
			'Untrusted callback proof must fail closed.'
		);
	}

	/**
	 * Invalid callback proofs.
	 *
	 * @return array<string,array{array<string,mixed>}>
	 */
	public static function invalid_callback_proof_provider(): array {
		return array(
			'stale'      => array(
				array(
					'registered'    => true,
					'reachable'     => true,
					'wpcom_blog_id' => 321,
					'proved_at'     => time() - 600,
				),
			),
			'wrong blog' => array(
				array(
					'registered'    => true,
					'reachable'     => true,
					'wpcom_blog_id' => 999,
					'proved_at'     => time(),
				),
			),
		);
	}

	/**
	 * Define the exact opt-in constant.
	 */
	private function define_native_e2e_constant(): void {
		if ( ! defined( 'E2E_WOOPAYMENTS_NATIVE' ) ) {
			define( 'E2E_WOOPAYMENTS_NATIVE', true );
		}

		// A real define() is the weakest of the three layers Constants reads: an
		// override set earlier wins over it, and EnvironmentIsolation sets one to
		// clear whatever wp-env defined for this container. Set the override so
		// this test states its precondition on the layer that actually decides.
		Constants::set_constant( 'E2E_WOOPAYMENTS_NATIVE', true );
	}

	/**
	 * Define the exact transition opt-in constant.
	 */
	private function define_transition_e2e_constant(): void {
		if ( ! defined( 'E2E_WOOPAYMENTS_TRANSITION' ) ) {
			define( 'E2E_WOOPAYMENTS_TRANSITION', true );
		}
	}

	/**
	 * Create a WooPayments card token for a customer.
	 *
	 * @param int    $user_id           WordPress user ID.
	 * @param string $payment_method_id Provider payment-method ID.
	 * @param bool   $is_default        Whether the token is the local default.
	 * @param string $gateway_id        Payment gateway ID.
	 * @return WC_Payment_Token_CC
	 */
	private function create_card_token(
		int $user_id,
		string $payment_method_id,
		bool $is_default,
		string $gateway_id = 'woocommerce_payments'
	): WC_Payment_Token_CC {
		$token = new WC_Payment_Token_CC();
		$token->set_gateway_id( $gateway_id );
		$token->set_token( $payment_method_id );
		$token->set_user_id( $user_id );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '02' );
		$token->set_expiry_year( '2045' );
		$token->set_default( $is_default );
		$token->save();
		WC_Data_Store::load( 'payment-token' )->set_default_status( $token->get_id(), $is_default );

		return $token;
	}

	/**
	 * Load and activate the same file that wp-env maps into the mu-plugins directory.
	 *
	 * The file deliberately skips its own registration under PHPUnit, so that
	 * merely being loaded as an mu-plugin cannot force native payments ownership
	 * onto unrelated tests. This class is the one that wants it active, so it
	 * registers explicitly. A fresh instance per call is correct because the
	 * WordPress test case restores the hook registry between tests, which drops
	 * whatever a previous test registered.
	 */
	private function load_bootstrap(): void {
		if ( ! class_exists( 'WooCommerce_WooPayments_Native_E2E_Runtime', false ) ) {
			require_once dirname( __DIR__, 4 ) . '/e2e/test-plugins/woopayments-native-runtime/woopayments-native-runtime.php';
		}

		( new \WooCommerce_WooPayments_Native_E2E_Runtime() )->register();
	}
}
