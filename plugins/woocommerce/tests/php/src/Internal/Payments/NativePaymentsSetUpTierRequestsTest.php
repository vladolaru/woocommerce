<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use ActionScheduler;
use ActionScheduler_Store;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsBootstrap;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsState;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGatewaySettingsSynchronizer;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderTrackingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProviderGatewayAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests what a connected or active native store registers on real requests: the gateway on a connected store, and
 * the scheduled-action handlers under WP-CLI.
 *
 * The client registers its gateway whether or not it is enabled (client 11.1.0 `includes/class-wc-payments.php:730`)
 * and attaches its scheduled-action handlers whenever it loads, WP-CLI included (`includes/class-wc-payments.php:603,657`).
 * Each case runs in its own process because it boots the native payments bootstrap for one request.
 */
class NativePaymentsSetUpTierRequestsTest extends WC_Unit_Test_Case {

	/**
	 * Outbound HTTP requests attempted during the test.
	 *
	 * @var array<int,string>
	 */
	private array $outbound_requests = array();

	/**
	 * Block and record outbound HTTP.
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter(
			'pre_http_request',
			function ( $response, $args, $url ) {
				unset( $response, $args );
				$this->outbound_requests[] = (string) $url;
				return new \WP_Error( 'blocked', 'Outbound HTTP is blocked in this test.' );
			},
			10,
			3
		);
	}

	/**
	 * @testdox A $label store registers the WooPayments gateway and offers it at checkout only when the tier is active.
	 * @dataProvider gateway_availability_cases
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string $label     Case label.
	 * @param string $state     Stored native tier.
	 * @param string $enabled   The card gateway's enabled setting.
	 * @param bool   $available Whether checkout must offer the gateway.
	 */
	public function test_gateway_is_registered_and_offered_only_in_the_active_tier( string $label, string $state, string $enabled, bool $available ): void {
		unset( $label );
		$this->arrange_native_owner( $state );
		// Only the account readiness is stubbed, so an enabled gateway would be available; the gateway itself stays real.
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )->onlyMethods( array( 'can_process_payments' ) )->getMock();
		$provider->method( 'can_process_payments' )->willReturn( true );
		$provider->init(
			wc_get_container()->get( WooPaymentsProviderGatewayAdapter::class ),
			wc_get_container()->get( WooPaymentsApiClient::class ),
			wc_get_container()->get( WooPaymentsAccountService::class )
		);
		wc_get_container()->replace( WooPaymentsProvider::class, $provider );
		add_filter(
			'pre_option_woocommerce_woocommerce_payments_settings',
			static fn() => array(
				'enabled'   => $enabled,
				'test_mode' => 'yes',
			)
		);

		$this->run_bootstrap( '__return_false' );
		WC()->payment_gateways()->payment_gateways = array();
		WC()->payment_gateways()->init();

		$registered = WC()->payment_gateways()->payment_gateways();
		$this->assertArrayHasKey( OrderPaymentStore::GATEWAY_ID, $registered, 'A connected or active store must register the gateway, as the client does.' );
		$this->assertInstanceOf( NativeWooPaymentsGateway::class, $registered[ OrderPaymentStore::GATEWAY_ID ] );
		$offered = array_keys( WC()->payment_gateways()->get_available_payment_gateways() );
		$this->assertSame( $available, in_array( OrderPaymentStore::GATEWAY_ID, $offered, true ) );
		$this->assertSame( array(), $this->outbound_requests );
	}

	/**
	 * @testdox A connected store keeps the saved-card hooks on requests that never build the gateway list.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_connected_store_keeps_the_saved_card_hooks(): void {
		$this->arrange_native_owner( NativePaymentsState::CONNECTED );

		$this->run_bootstrap( '__return_false' );

		// Look at the attached callbacks rather than resolve the service here, which would attach them itself.
		$this->assertTrue( $this->has_token_service_callback( 'woocommerce_payment_token_deleted' ), 'Deleting a saved card must detach it at the provider.' );
		$this->assertTrue( $this->has_token_service_callback( 'woocommerce_get_customer_payment_tokens' ), 'Saved-card lists must be reconciled with the provider.' );
	}

	/**
	 * @testdox An Action Scheduler run under WP-CLI on a $state store reaches the native order-tracking handler.
	 * @dataProvider set_up_states
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string $state Stored native tier.
	 */
	public function test_action_scheduler_run_under_wp_cli_reaches_the_native_handler( string $state ): void {
		define( 'WP_CLI', true );
		$order = new WC_Order();
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$order->update_meta_data( '_payment_method_id', 'pm_test_cli' );
		$order->save();
		$this->arrange_native_owner( $state );
		$api_client = $this->createMock( WooPaymentsApiClient::class );
		$api_client->expects( $this->once() )->method( 'track_order' )->willReturn( array( 'result' => 'success' ) );
		wc_get_container()->replace( WooPaymentsApiClient::class, $api_client );

		$this->run_bootstrap( '__return_false' );
		$action_id = as_enqueue_async_action( WooPaymentsOrderTrackingService::TRACK_NEW_ORDER_ACTION, array( $order->get_id() ) );
		ActionScheduler::runner()->process_action( $action_id, 'WP CLI' );

		$this->assertSame( ActionScheduler_Store::STATUS_COMPLETE, ActionScheduler::store()->get_status( $action_id ) );
		$this->assertSame( 'yes', wc_get_order( $order->get_id() )->get_meta( WooPaymentsOrderTrackingService::NEW_ORDER_TRACKING_COMPLETE_META_KEY ), 'The native handler must have tracked the order.' );
	}

	/**
	 * @testdox The Settings > Payments toggle moves a connected store to active when it creates the settings, and back to connected when it updates them.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_classic_toggle_keeps_the_tier_in_step_with_the_gateway(): void {
		$this->arrange_native_owner( NativePaymentsState::CONNECTED );
		// No settings yet: the first toggle creates the option (add_option), the second updates it.
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->run_bootstrap( '__return_false' );
		$this->reload_payment_gateways();

		$this->toggle_gateway();
		$this->assertSame( 'yes', get_option( 'woocommerce_woocommerce_payments_settings' )['enabled'] ?? null );
		$this->assertSame( NativePaymentsState::ACTIVE, $this->stored_state(), 'Enabling the gateway must make the store active.' );

		$this->toggle_gateway();
		$this->assertSame( NativePaymentsState::CONNECTED, $this->stored_state(), 'Disabling the gateway must make the store connected again.' );
	}

	/**
	 * @testdox The payment gateways REST route moves a connected store to active, and back to connected.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rest_update_keeps_the_tier_in_step_with_the_gateway(): void {
		$this->arrange_native_owner( NativePaymentsState::CONNECTED );
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enabled'   => 'no',
				'test_mode' => 'yes',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->run_bootstrap( '__return_true' );
		$this->reload_payment_gateways();

		foreach ( array(
			true  => NativePaymentsState::ACTIVE,
			false => NativePaymentsState::CONNECTED,
		) as $enabled => $expected ) {
			$request = new \WP_REST_Request( 'PUT', '/wc/v3/payment_gateways/' . OrderPaymentStore::GATEWAY_ID );
			$request->set_body_params( array( 'enabled' => (bool) $enabled ) );
			$response = rest_do_request( $request );

			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( $expected, $this->stored_state() );
		}
	}

	/**
	 * @testdox Writing the shared settings option on a plugin-owned store leaves native's stored tier untouched.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_plugin_owned_settings_write_leaves_the_native_tier_alone(): void {
		update_option( 'active_plugins', array_merge( (array) get_option( 'active_plugins', array() ), array( NativePaymentsRuntimeArbiter::PLUGIN_FILE ) ) );
		update_option( NativePaymentsState::OPTION_NAME, NativePaymentsState::CONNECTED, true );
		wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->invalidate();
		wc_get_container()->get( NativePaymentsState::class )->invalidate();
		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->run_bootstrap( '__return_false' );

		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enabled' => 'yes' ) );
		wc_get_container()->get( WooPaymentsGatewaySettingsSynchronizer::class )->handle_settings_updated( array(), array( 'enabled' => 'yes' ) );

		$this->assertSame( NativePaymentsState::CONNECTED, $this->stored_state(), 'The plugin owns this option too; native must not rewrite its tier.' );
	}

	/** @return array<string,array{string,string,string,bool}> */
	public static function gateway_availability_cases(): array {
		return array(
			'connected, gateway disabled'               => array( 'connected', NativePaymentsState::CONNECTED, 'no', false ),
			'connected, gateway enabled but tier stale' => array( 'stale connected', NativePaymentsState::CONNECTED, 'yes', false ),
			'active, gateway enabled (fixture control)' => array( 'active', NativePaymentsState::ACTIVE, 'yes', true ),
		);
	}

	/** @return array<string,array{string}> */
	public static function set_up_states(): array {
		return array(
			'connected' => array( NativePaymentsState::CONNECTED ),
			'active'    => array( NativePaymentsState::ACTIVE ),
		);
	}

	/**
	 * Tell whether a token service callback is attached to a hook.
	 *
	 * @param string $hook Hook name.
	 * @return bool
	 */
	private function has_token_service_callback( string $hook ): bool {
		global $wp_filter;

		foreach ( isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : array() as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof WooPaymentsTokenService ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Read the stored tier straight from the database.
	 *
	 * @return string
	 */
	private function stored_state(): string {
		wp_cache_delete( NativePaymentsState::OPTION_NAME, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		return (string) get_option( NativePaymentsState::OPTION_NAME );
	}

	/**
	 * Build WooCommerce's gateway list again, as a new request would after the bootstrap ran.
	 */
	private function reload_payment_gateways(): void {
		WC()->payment_gateways()->payment_gateways = array();
		WC()->payment_gateways()->init();
	}

	/**
	 * Press the Settings > Payments enable toggle for the WooPayments gateway through WooCommerce's AJAX handler.
	 */
	private function toggle_gateway(): void {
		$_POST['gateway_id']  = OrderPaymentStore::GATEWAY_ID;
		$_REQUEST['security'] = wp_create_nonce( 'woocommerce-toggle-payment-gateway-enabled' );
		$die_handler          = static function () {
			return static function () {
				throw new \RuntimeException( 'ajax-die' );
			};
		};
		add_filter( 'wp_die_ajax_handler', $die_handler );
		ob_start();
		try {
			\WC_AJAX::toggle_gateway_enabled();
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'ajax-die', $exception->getMessage() );
		} finally {
			$json = (string) ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', $die_handler );
			unset( $_POST['gateway_id'], $_REQUEST['security'] );
		}
		$this->assertTrue( json_decode( $json, true )['success'] ?? false, 'The toggle must succeed: ' . $json );
	}

	/**
	 * Make native the payments owner with the given stored tier.
	 *
	 * @param string $state Stored native tier.
	 */
	private function arrange_native_owner( string $state ): void {
		update_option( 'active_plugins', array_values( array_diff( (array) get_option( 'active_plugins', array() ), array( NativePaymentsRuntimeArbiter::PLUGIN_FILE ) ) ) );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		update_option( NativePaymentsState::OPTION_NAME, $state, true );
		wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->invalidate();
		wc_get_container()->get( NativePaymentsState::class )->invalidate();
	}

	/**
	 * Run the native payments bootstrap with the WooPayments root matrix, as WooCommerce does when it loads.
	 *
	 * @param callable $is_rest_api_request Whether the request is a REST request.
	 */
	private function run_bootstrap( callable $is_rest_api_request ): void {
		( new NativePaymentsBootstrap(
			array( WooPaymentsProvider::class, 'get_bootstrap_root_matrix' ),
			static fn(): array => array()
		) )->register( wc_get_container(), $is_rest_api_request );
	}
}
