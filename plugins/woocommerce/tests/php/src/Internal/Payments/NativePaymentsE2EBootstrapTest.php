<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
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
	 * Define the exact opt-in constant.
	 */
	private function define_native_e2e_constant(): void {
		if ( ! defined( 'E2E_WOOPAYMENTS_NATIVE' ) ) {
			define( 'E2E_WOOPAYMENTS_NATIVE', true );
		}
	}

	/**
	 * Load the same file that wp-env maps into the mu-plugins directory.
	 */
	private function load_bootstrap(): void {
		require_once dirname( __DIR__, 4 ) . '/e2e/test-plugins/woopayments-native-runtime/woopayments-native-runtime.php';
	}
}
