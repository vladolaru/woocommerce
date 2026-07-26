<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use WC_Data_Store;
use WC_Payment_Token_CC;
use WC_Rate_Limiter;
use WC_Unit_Test_Case;
use WPDieException;
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
	 * @testdox Named saved-card evidence proves the exact local and provider default.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_named_saved_card_evidence_proves_local_and_provider_default(): void {
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
			public function get_customer_id_by_user_id( ?int $user_id ): ?string {
				unset( $user_id );
				return 'cus_exact';
			}
		};
		$api_client       = new class() extends WooPaymentsApiClient {
			/**
			 * Return deterministic provider default evidence.
			 *
			 * @param string $customer_id Provider customer ID.
			 * @return array<string,mixed>
			 */
			public function get_customer( string $customer_id ): array {
				return array(
					'id'               => $customer_id,
					'invoice_settings' => array(
						'default_payment_method' => 'pm_exact',
					),
				);
			}
		};
		wc_get_container()->replace( WooPaymentsCustomerService::class, $customer_service );
		wc_get_container()->replace( WooPaymentsApiClient::class, $api_client );
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
		$this->assertSame( 'pm_exact', $data['provider_default_payment_method_id'], 'Evidence must return the exact provider default.' );
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
	 * @testdox Transition cutover action is hidden without the exact transition constant.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_transition_cutover_action_is_hidden_outside_transition_mode(): void {
		$this->define_native_e2e_constant();
		$this->load_bootstrap();
		update_option( 'active_plugins', array( NativePaymentsRuntimeArbiter::PLUGIN_FILE ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		( new \WooCommerce_WooPayments_Native_E2E_Runtime() )->render_transition_cutover();
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Switch to native WooPayments', $output, 'The irreversible action must remain transition-only.' );
	}

	/**
	 * @testdox Transition cutover renders a nonce-protected semantic button for authorized merchants.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_transition_cutover_renders_semantic_nonce_protected_button(): void {
		$this->define_native_e2e_constant();
		$this->define_transition_e2e_constant();
		$this->load_bootstrap();
		update_option( 'active_plugins', array( NativePaymentsRuntimeArbiter::PLUGIN_FILE ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		( new \WooCommerce_WooPayments_Native_E2E_Runtime() )->render_transition_cutover();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<button', $output, 'The transition action must use native button semantics.' );
		$this->assertStringContainsString( 'Switch to native WooPayments', $output, 'The button must describe the exact transition.' );
		$this->assertStringContainsString( '_wpnonce', $output, 'The transition form must carry a WordPress nonce.' );
	}

	/**
	 * @testdox Transition cutover deactivates only the standalone WooPayments plugin.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_transition_cutover_deactivates_only_standalone_woopayments(): void {
		$this->define_native_e2e_constant();
		$this->define_transition_e2e_constant();
		$this->load_bootstrap();
		$other_plugin = 'other-plugin/other-plugin.php';
		update_option( 'active_plugins', array( NativePaymentsRuntimeArbiter::PLUGIN_FILE, $other_plugin ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$nonce                = wp_create_nonce( 'wc-native-payments-e2e-cutover' );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;
		add_filter(
			'wp_redirect',
			static function () {
				throw new \RuntimeException( 'redirected' );
			}
		);

		try {
			( new \WooCommerce_WooPayments_Native_E2E_Runtime() )->handle_transition_cutover();
			$this->fail( 'A successful transition must redirect.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'redirected', $exception->getMessage(), 'The handler should finish with the expected redirect.' );
		}

		$this->assertSame( array( $other_plugin ), array_values( get_option( 'active_plugins' ) ), 'No unrelated plugin may be deactivated.' );
	}

	/**
	 * @testdox Transition cutover refuses callers without WooCommerce management capability.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_transition_cutover_rejects_unauthorized_user(): void {
		$this->define_native_e2e_constant();
		$this->define_transition_e2e_constant();
		$this->load_bootstrap();
		update_option( 'active_plugins', array( NativePaymentsRuntimeArbiter::PLUGIN_FILE ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$nonce                = wp_create_nonce( 'wc-native-payments-e2e-cutover' );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		$this->expectException( WPDieException::class );

		( new \WooCommerce_WooPayments_Native_E2E_Runtime() )->handle_transition_cutover();
	}

	/**
	 * @testdox Runtime status accepts only fresh callback proof for the exact current blog ID.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_status_accepts_only_fresh_exact_callback_proof(): void {
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
				'registered'    => true,
				'reachable'     => true,
				'wpcom_blog_id' => 321,
			),
			$response->get_data()['callback_probe'],
			'Fresh proof must remain bound to the exact current Jetpack blog.'
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
	 * Load the same file that wp-env maps into the mu-plugins directory when needed.
	 */
	private function load_bootstrap(): void {
		if ( class_exists( 'WooCommerce_WooPayments_Native_E2E_Runtime', false ) ) {
			return;
		}

		require_once dirname( __DIR__, 4 ) . '/e2e/test-plugins/woopayments-native-runtime/woopayments-native-runtime.php';
	}
}
