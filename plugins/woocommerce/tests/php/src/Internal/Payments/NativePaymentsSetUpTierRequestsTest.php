<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use ActionScheduler;
use ActionScheduler_Store;
use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsBootstrap;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsState;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCanceledAuthorizationFeeRemediationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGatewaySettingsSynchronizer;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderTrackingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionRenewalHooks;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsSepaToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProviderGatewayAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use WC_Order;
use WC_Payment_Tokens;
use WC_Unit_Test_Case;

/**
 * Tests what a connected or active native store registers on real requests: the gateway on a connected store, and
 * the scheduled-action handlers under WP-CLI.
 *
 * The client registers its gateway whether or not it is enabled (client 11.1.0 `includes/class-wc-payments.php:730`)
 * and attaches its scheduled-action handlers whenever it loads, WP-CLI included (`includes/class-wc-payments.php:603,657`).
 * Each case boots the native payments bootstrap for one request; tearDown undoes what that boot leaves for the rest of
 * the process.
 */
class NativePaymentsSetUpTierRequestsTest extends WC_Unit_Test_Case {

	/**
	 * Outbound HTTP requests attempted during the test.
	 *
	 * @var array<int,string>
	 */
	private array $outbound_requests = array();

	/**
	 * Payment gateways before the case booted the native bootstrap.
	 *
	 * @var array<string,mixed>
	 */
	private array $original_payment_gateways = array();

	/**
	 * Block and record outbound HTTP.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->original_payment_gateways = WC()->payment_gateways()->payment_gateways;

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
	 * Undo what one booted request leaves for the rest of the process: the gateway list rebuilt with native gateways, the
	 * container's replacements and resolved roots, the REST routes and the two static one-time hook flags.
	 */
	public function tearDown(): void {
		WC()->payment_gateways()->payment_gateways = $this->original_payment_gateways;
		wc_get_container()->reset_all_replacements();
		wc_get_container()->reset_all_resolved();
		$GLOBALS['wp_rest_server'] = null;
		Constants::clear_single_constant( 'WP_CLI' );
		set_current_screen( 'front' );

		$renewal_hooks = new \ReflectionProperty( WooPaymentsSubscriptionRenewalHooks::class, 'attached' );
		$renewal_hooks->setAccessible( true );
		$renewal_hooks->setValue( null, false );
		$fallback_hooks = new \ReflectionProperty( NativeWooPaymentsGateway::class, 'classic_checkout_fallback_hooks_added' );
		$fallback_hooks->setAccessible( true );
		$fallback_hooks->setValue( null, false );
		parent::tearDown();
	}

	/**
	 * @testdox A $label store registers the WooPayments gateway and offers it at checkout only when the tier is active.
	 * @dataProvider gateway_availability_cases
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
	 * @testdox An active store whose card gateway onboarding enabled offers it at checkout only once the account can take payments ($label).
	 * @dataProvider account_readiness_cases
	 *
	 * @param string $label             Case label.
	 * @param bool   $details_submitted Whether the account details were submitted.
	 * @param bool   $payments_enabled  Whether the account can accept payments.
	 * @param bool   $available         Whether checkout must offer the gateway.
	 */
	public function test_active_store_offers_the_gateway_only_when_the_account_can_take_payments( string $label, bool $details_submitted, bool $payments_enabled, bool $available ): void {
		unset( $label );
		$this->arrange_native_owner( NativePaymentsState::ACTIVE );
		add_filter(
			'pre_option_woocommerce_woocommerce_payments_settings',
			static fn() => array(
				'enabled'   => 'yes',
				'test_mode' => 'yes',
			)
		);
		// Only the WPCOM connection is stubbed; the account readiness comes from the real account service and cache.
		$api_client = $this->createMock( WooPaymentsApiClient::class );
		$api_client->method( 'is_available' )->willReturn( true );
		wc_get_container()->replace( WooPaymentsApiClient::class, $api_client );
		$account_service = wc_get_container()->get( WooPaymentsAccountService::class );
		$account_service->cache_account_data(
			array(
				'account_id'           => 'acct_test123',
				'status'               => $payments_enabled ? 'complete' : 'restricted',
				'is_live'              => true,
				'details_submitted'    => $details_submitted,
				'payments_enabled'     => $payments_enabled,
				'live_publishable_key' => 'pk_live_test123',
				'test_publishable_key' => 'pk_test_test123',
			)
		);
		$provider = new WooPaymentsProvider();
		$provider->init( wc_get_container()->get( WooPaymentsProviderGatewayAdapter::class ), $api_client, $account_service );
		wc_get_container()->replace( WooPaymentsProvider::class, $provider );

		$this->run_bootstrap( '__return_false' );
		$this->reload_payment_gateways();

		$gateway = WC()->payment_gateways()->payment_gateways()[ OrderPaymentStore::GATEWAY_ID ] ?? null;
		$this->assertInstanceOf( NativeWooPaymentsGateway::class, $gateway, 'An active store registers the gateway.' );
		$this->assertSame( 'yes', $gateway->enabled, 'The card gateway is enabled, as onboarding leaves it.' );
		$this->assertSame( $available, $gateway->is_available() );
		$this->assertSame( $available, array_key_exists( OrderPaymentStore::GATEWAY_ID, WC()->payment_gateways()->get_available_payment_gateways() ) );
	}

	/**
	 * @testdox A connected store keeps the saved-card hooks on requests that never build the gateway list.
	 */
	public function test_connected_store_keeps_the_saved_card_hooks(): void {
		$this->arrange_native_owner( NativePaymentsState::CONNECTED );

		$this->run_bootstrap( '__return_false' );

		// Look at the attached callbacks rather than resolve the service here, which would attach them itself.
		$this->assertTrue( $this->has_token_service_callback( 'woocommerce_payment_token_deleted' ), 'Deleting a saved card must detach it at the provider.' );
		$this->assertTrue( $this->has_token_service_callback( 'woocommerce_get_customer_payment_tokens' ), 'Saved-card lists must be reconciled with the provider.' );
	}

	/**
	 * @testdox $label: a saved SEPA token loads as its WooPayments token class.
	 * @dataProvider saved_token_requests
	 *
	 * @param string $label   Case label.
	 * @param string $state   Stored native tier.
	 * @param string $request Request class: 'cron', 'cli', 'admin' or 'front'.
	 */
	public function test_saved_sepa_token_loads_on_every_request_of_a_set_up_store( string $label, string $state, string $request ): void {
		unset( $label );
		$token = new WooPaymentsSepaToken();
		$token->set_token( 'pm_test_sepa' );
		$token->set_gateway_id( OrderPaymentStore::GATEWAY_ID );
		$token->set_user_id( 1 );
		$token->set_last4( '3000' );
		$token->save();
		$this->arrange_native_owner( $state );
		if ( 'cron' === $request ) {
			add_filter( 'wp_doing_cron', '__return_true' );
		} elseif ( 'cli' === $request ) {
			Constants::set_constant( 'WP_CLI', true );
		} elseif ( 'admin' === $request ) {
			set_current_screen( 'edit-shop_subscription' );
		}

		$this->run_bootstrap( '__return_false' );

		// The client loads its SEPA token class on every request (client 11.1.0 `includes/class-wc-payments.php:468`).
		$this->assertInstanceOf( WooPaymentsSepaToken::class, WC_Payment_Tokens::get( $token->get_id() ), 'A renewal or subscription view must find the saved SEPA credential.' );
	}

	/**
	 * @testdox An Action Scheduler run under WP-CLI on a $state store reaches the native order-tracking handler.
	 * @dataProvider set_up_states
	 *
	 * @param string $state Stored native tier.
	 */
	public function test_action_scheduler_run_under_wp_cli_reaches_the_native_handler( string $state ): void {
		Constants::set_constant( 'WP_CLI', true );
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
	 * @testdox $label: a scheduled action that runs inside a page request reaches its native handler.
	 * @dataProvider page_request_action_runs
	 *
	 * @param string $label   Case label.
	 * @param string $state   Stored native tier.
	 * @param string $request How the action runs: 'alternate_wp_cron' on a front page, or 'admin_run' from Tools > Scheduled Actions.
	 */
	public function test_scheduled_action_run_inside_a_page_request_reaches_the_native_handler( string $label, string $state, string $request ): void {
		unset( $label );
		$hook = WooPaymentsCanceledAuthorizationFeeRemediationService::CHECK_AFFECTED_ORDERS_HOOK;
		delete_option( WooPaymentsCanceledAuthorizationFeeRemediationService::CHECK_STATE_OPTION_KEY );
		$this->arrange_native_owner( $state );
		if ( 'admin_run' === $request ) {
			set_current_screen( 'woocommerce_page_wc-status' );
		}

		$this->run_bootstrap( '__return_false' );
		if ( 'alternate_wp_cron' === $request ) {
			// ALTERNATE_WP_CRON includes wp-cron.php on wp_loaded, so DOING_CRON appears only after WooCommerce loaded.
			add_filter( 'wp_doing_cron', '__return_true' );
		}
		$handler_attached = null;
		add_action(
			'action_scheduler_begin_execute',
			static function () use ( &$handler_attached, $hook ): void {
				$handler_attached = false !== has_action( $hook );
			}
		);
		$action_id = as_enqueue_async_action( $hook, array(), WooPaymentsCanceledAuthorizationFeeRemediationService::ACTION_SCHEDULER_GROUP_ID );
		ActionScheduler::runner()->process_action( $action_id, 'alternate_wp_cron' === $request ? 'WP Cron' : 'Admin List Table' );

		$this->assertTrue( $handler_attached, 'The native handler must be attached before Action Scheduler runs the action.' );
		$this->assertSame( ActionScheduler_Store::STATUS_COMPLETE, ActionScheduler::store()->get_status( $action_id ) );
		$this->assertSame( 'no_affected_orders', get_option( WooPaymentsCanceledAuthorizationFeeRemediationService::CHECK_STATE_OPTION_KEY ), 'The native handler must have run.' );
	}

	/**
	 * @testdox The Settings > Payments toggle moves a connected store to active when it creates the settings, and back to connected when it updates them.
	 */
	public function test_classic_toggle_keeps_the_tier_in_step_with_the_gateway(): void {
		$this->arrange_native_owner( NativePaymentsState::CONNECTED );
		// A connected store has an account that can take payments; without one the gateway needs setup and WooCommerce refuses the toggle, as for the client.
		$api_client = $this->createMock( WooPaymentsApiClient::class );
		$api_client->method( 'is_available' )->willReturn( true );
		wc_get_container()->replace( WooPaymentsApiClient::class, $api_client );
		wc_get_container()->get( WooPaymentsAccountService::class )->cache_account_data(
			array(
				'account_id'        => 'acct_test123',
				'status'            => 'complete',
				'is_live'           => true,
				'details_submitted' => true,
				'payments_enabled'  => true,
			)
		);
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

	/** @return array<string,array{string,bool,bool,bool}> */
	public static function account_readiness_cases(): array {
		return array(
			'details not submitted, payments disabled' => array( 'not ready', false, false, false ),
			'details submitted, payments enabled (control)' => array( 'ready', true, true, true ),
		);
	}

	/** @return array<string,array{string,string,string}> */
	public static function page_request_action_runs(): array {
		return array(
			'connected, ALTERNATE_WP_CRON on a front page' => array( 'connected, ALTERNATE_WP_CRON', NativePaymentsState::CONNECTED, 'alternate_wp_cron' ),
			'active, ALTERNATE_WP_CRON on a front page'    => array( 'active, ALTERNATE_WP_CRON', NativePaymentsState::ACTIVE, 'alternate_wp_cron' ),
			'connected, Scheduled Actions Run link (admin)' => array( 'connected, Scheduled Actions Run', NativePaymentsState::CONNECTED, 'admin_run' ),
			'active, Scheduled Actions Run link (admin)'   => array( 'active, Scheduled Actions Run', NativePaymentsState::ACTIVE, 'admin_run' ),
		);
	}

	/**
	 * @testdox An active store's on-hold email sent from a $request request carries the Multibanco instructions.
	 * @testWith ["rest"]
	 *           ["cron"]
	 *           ["admin"]
	 *
	 * @param string $request Request class: 'rest' (Store API checkout), 'cron' (deferred email) or 'admin' (resend).
	 */
	public function test_on_hold_email_carries_multibanco_instructions_on_every_active_request( string $request ): void {
		$order = new WC_Order();
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID_PREFIX . 'multibanco' );
		$order->set_status( 'on-hold' );
		$order->update_meta_data( '_wcpay_multibanco_reference', '123 456 789' );
		$order->update_meta_data( '_wcpay_multibanco_entity', '12345' );
		$order->update_meta_data( '_wcpay_multibanco_url', 'https://pay.stripe.com/multibanco/voucher' );
		$order->update_meta_data( '_wcpay_multibanco_expiry', (string) ( time() + DAY_IN_SECONDS ) );
		$order->save();
		$this->arrange_native_owner( NativePaymentsState::ACTIVE );
		if ( 'cron' === $request ) {
			add_filter( 'wp_doing_cron', '__return_true' );
		} elseif ( 'admin' === $request ) {
			set_current_screen( 'woocommerce_page_wc-orders' );
		}

		$this->run_bootstrap( 'rest' === $request ? '__return_true' : '__return_false' );
		$email         = WC()->mailer()->get_emails()['WC_Email_Customer_On_Hold_Order'];
		$email->object = $order;

		// Client 11.1.0 attaches the email callback on every request (includes/class-wc-payments-order-success-page.php:40).
		$this->assertStringContainsString( '123 456 789', $email->get_content_html(), 'The on-hold email must carry the Multibanco reference.' );
	}

	/** @return array<string,array{string,string,string}> */
	public static function saved_token_requests(): array {
		return array(
			'active cron'     => array( 'active cron', NativePaymentsState::ACTIVE, 'cron' ),
			'active WP-CLI'   => array( 'active WP-CLI', NativePaymentsState::ACTIVE, 'cli' ),
			'active admin'    => array( 'active admin', NativePaymentsState::ACTIVE, 'admin' ),
			'connected cron'  => array( 'connected cron', NativePaymentsState::CONNECTED, 'cron' ),
			'connected admin' => array( 'connected admin', NativePaymentsState::CONNECTED, 'admin' ),
			'connected front' => array( 'connected front', NativePaymentsState::CONNECTED, 'front' ),
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
