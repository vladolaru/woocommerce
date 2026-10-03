<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Subscriptions;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsBootstrap;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsState;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionRenewalHooks;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the native WooPayments Subscriptions renewal hooks.
 *
 * The client attaches its renewal handlers and the failed-renewal email whenever WooPayments loads, with or without its
 * gateway enabled: `wcpay_init()` runs on `plugins_loaded` (client 11.1.0 `woocommerce-payments.php:214`), builds the
 * card gateway and calls its `init_hooks()` (`includes/class-wc-payments.php:630-649`), which attaches the email filter
 * and the per-gateway renewal actions (`includes/compat/subscriptions/trait-wc-payment-gateway-wcpay-subscriptions.php:274-298`).
 * Each case boots the native payments bootstrap for one request; tearDown undoes what that boot leaves for the rest of
 * the process. The WP-CLI renewals, the legacy facade case and the staging case run in their own process, as they define
 * WP_CLI, declare WC_Payments or alias WCS_Staging.
 */
class WooPaymentsSubscriptionRenewalHooksTest extends WC_Unit_Test_Case {

	/**
	 * Payment gateways before the case booted the native bootstrap.
	 *
	 * @var array<string,mixed>
	 */
	private array $original_payment_gateways = array();

	/**
	 * Remember the gateway list the case may rebuild.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->original_payment_gateways = WC()->payment_gateways()->payment_gateways;
	}

	/**
	 * Undo what one booted request leaves for the rest of the process: the gateway list rebuilt with native gateways, the
	 * container's replacements and resolved roots, the payments ownership memos, the REST routes and the two static
	 * one-time hook flags.
	 */
	public function tearDown(): void {
		WC()->payment_gateways()->payment_gateways = $this->original_payment_gateways;
		wc_get_container()->reset_all_replacements();
		wc_get_container()->reset_all_resolved();
		wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->invalidate();
		wc_get_container()->get( NativePaymentsState::class )->invalidate();
		$GLOBALS['wp_rest_server'] = null;

		$renewal_hooks = new \ReflectionProperty( WooPaymentsSubscriptionRenewalHooks::class, 'attached' );
		$renewal_hooks->setAccessible( true );
		$renewal_hooks->setValue( null, false );
		$fallback_hooks = new \ReflectionProperty( NativeWooPaymentsGateway::class, 'classic_checkout_fallback_hooks_added' );
		$fallback_hooks->setAccessible( true );
		$fallback_hooks->setValue( null, false );
		parent::tearDown();
	}

	/**
	 * @testdox A native-owned $state store has the renewal handlers and the failed-renewal email after init, and refuses a renewal across test and live mode.
	 * @dataProvider native_owned_states
	 *
	 * @param string $state Stored native tier.
	 */
	public function test_native_owned_store_has_renewal_handlers_after_init( string $state ): void {
		$this->load_subscriptions();
		$this->arrange_ownership( false, true, $state );

		$this->register_native_payments_and_run_init();

		foreach ( array( OrderPaymentStore::GATEWAY_ID, OrderPaymentStore::GATEWAY_ID_PREFIX . 'amazon_pay' ) as $gateway_id ) {
			$this->assertTrue( has_action( 'woocommerce_scheduled_subscription_payment_' . $gateway_id ), "The $gateway_id renewal handler must be attached." );
			$this->assertTrue( has_action( 'woocommerce_subscription_failing_payment_method_updated_' . $gateway_id ), "The $gateway_id failing-method handler must be attached." );
		}
		$this->assertSame( 20, has_filter( 'woocommerce_email_classes', array( NativeWooPaymentsGateway::class, 'add_subscription_emails' ) ), 'The failed-renewal email must be registered.' );

		$this->use_order_mode( 'prod' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Subscription was made when WooPayments was in the test mode and cannot be renewed in the live mode.' );
		apply_filters( 'wcs_renewal_order_items', array( 'line_item_a' ), new WC_Order(), $this->create_subscription_paid_in_mode( 'test' ) );
	}

	/**
	 * @testdox The renewal handler resolves the card gateway only when a renewal runs.
	 */
	public function test_connected_store_attaches_the_renewal_root_without_building_the_gateway(): void {
		$this->load_subscriptions();
		$this->arrange_ownership( false, true, NativePaymentsState::CONNECTED );

		$this->register_native_payments_and_run_init();

		$root = wc_get_container()->get( WooPaymentsSubscriptionRenewalHooks::class );
		$this->assertSame( 10, has_action( 'woocommerce_scheduled_subscription_payment_' . OrderPaymentStore::GATEWAY_ID, array( $root, 'scheduled_subscription_payment' ) ) );
		$gateway = new \ReflectionProperty( WooPaymentsSubscriptionRenewalHooks::class, 'gateway' );
		$gateway->setAccessible( true );
		$this->assertNull( $gateway->getValue( $root ), 'Registering the renewal hooks must not build the card gateway.' );
	}

	/**
	 * @testdox A $label store has no native renewal handler and no native failed-renewal email after init.
	 * @dataProvider stores_without_native_renewals
	 *
	 * @param string $label        Case label.
	 * @param bool   $plugin_owned Whether the WooPayments plugin is active.
	 * @param bool   $native       Whether native payments is enabled.
	 * @param string $state        Stored native tier.
	 */
	public function test_store_without_native_ownership_has_no_native_renewal_handlers( string $label, bool $plugin_owned, bool $native, string $state ): void {
		unset( $label );
		$this->load_subscriptions();
		$this->arrange_ownership( $plugin_owned, $native, $state );

		$this->register_native_payments_and_run_init();
		if ( $plugin_owned ) {
			// Other code can still build the native card gateway, for example the legacy facade; it must not attach either.
			new NativeWooPaymentsGateway();
		}

		foreach ( array( OrderPaymentStore::GATEWAY_ID, OrderPaymentStore::GATEWAY_ID_PREFIX . 'amazon_pay' ) as $gateway_id ) {
			$this->assertFalse( has_action( 'woocommerce_scheduled_subscription_payment_' . $gateway_id ), "No native $gateway_id renewal handler may be attached." );
		}
		$this->assertFalse( has_filter( 'woocommerce_email_classes', array( NativeWooPaymentsGateway::class, 'add_subscription_emails' ) ), 'The native failed-renewal email must not be registered.' );
	}

	/**
	 * @testdox A scheduled renewal on a $label store finds the native gateway and reaches the native handler.
	 * @dataProvider renewal_contexts
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param string $label  Case label.
	 * @param string $state  Stored native tier.
	 * @param bool   $wp_cli Whether the renewal runs under WP-CLI.
	 */
	public function test_scheduled_renewal_reaches_the_native_handler( string $label, string $state, bool $wp_cli ): void {
		unset( $label );
		if ( $wp_cli ) {
			define( 'WP_CLI', true );
		}
		$this->load_subscriptions();
		$this->arrange_ownership( false, true, $state );
		$this->register_native_payments_and_run_init();
		$gateway = $this->install_spy_gateway();
		$this->reload_payment_gateways();
		list( $subscription, $renewal_order ) = $this->create_subscription_with_renewal_order();

		$this->assertSame( $gateway, wc_get_payment_gateway_by_order( $subscription ), 'Subscriptions must find the native gateway, or it treats the subscription as manual.' );
		$this->assertTrue( $this->run_scheduled_subscription_payment( $subscription, $renewal_order ), 'The renewal must be automatic, not a manual renewal order.' );
		$this->assertSame( array( array( 'scheduled_subscription_payment', 12.5, $renewal_order->get_id() ) ), $gateway->calls, 'The renewal must reach the native gateway once.' );
	}

	/**
	 * @testdox The renewal root forwards each Subscriptions callback to the card gateway.
	 */
	public function test_renewal_root_forwards_to_the_card_gateway(): void {
		$gateway = $this->install_spy_gateway();
		$root    = wc_get_container()->get( WooPaymentsSubscriptionRenewalHooks::class );
		$order   = new WC_Order();
		$order->save();

		$root->scheduled_subscription_payment( 7.25, $order );
		$root->update_failing_payment_method( $order, $order );
		$root->maybe_force_subscription_to_manual( $order );

		$this->assertSame(
			array(
				array( 'scheduled_subscription_payment', 7.25, $order->get_id() ),
				array( 'update_failing_payment_method', $order->get_id(), $order->get_id() ),
				array( 'maybe_force_subscription_to_manual', $order->get_id() ),
			),
			$gateway->calls
		);
	}

	/**
	 * @testdox A gateway built after the renewal root attached adds no second renewal handler.
	 */
	public function test_gateway_built_after_the_root_adds_no_second_handler(): void {
		global $wp_filter;

		$this->load_subscriptions();
		$this->arrange_ownership( false, true, NativePaymentsState::ACTIVE );
		$this->register_native_payments_and_run_init();
		$gateway = $this->install_spy_gateway();
		$this->reload_payment_gateways();
		list( , $renewal_order ) = $this->create_subscription_with_renewal_order();

		$hook = 'woocommerce_scheduled_subscription_payment_' . OrderPaymentStore::GATEWAY_ID;
		$this->assertSame( 1, array_sum( array_map( 'count', $wp_filter[ $hook ]->callbacks ) ), 'Exactly one renewal callback may be attached.' );
		do_action( $hook, $renewal_order->get_total(), $renewal_order );
		$this->assertCount( 1, $gateway->calls, 'A renewal must reach the gateway once.' );
	}

	/**
	 * @testdox The renewal root attaches on plugins_loaded priority 11 when WooCommerce loads earlier, as the client does.
	 */
	public function test_renewal_root_attaches_at_the_client_timing(): void {
		global $wp_actions;

		$this->load_subscriptions();
		$this->arrange_ownership( false, true, NativePaymentsState::CONNECTED );
		$root = wc_get_container()->get( WooPaymentsSubscriptionRenewalHooks::class );
		unset( $wp_actions['plugins_loaded'] );

		$root->register();

		$this->assertSame( 11, has_action( 'plugins_loaded', array( $root, 'handle_plugins_loaded' ) ) );
		$this->assertFalse( has_action( 'woocommerce_scheduled_subscription_payment_' . OrderPaymentStore::GATEWAY_ID ), 'Nothing may attach before Subscriptions has loaded.' );
		$root->handle_plugins_loaded();
		$this->assertSame( 10, has_action( 'woocommerce_scheduled_subscription_payment_' . OrderPaymentStore::GATEWAY_ID, array( $root, 'scheduled_subscription_payment' ) ) );
		$this->assertSame( 20, has_filter( 'woocommerce_email_classes', array( NativeWooPaymentsGateway::class, 'add_subscription_emails' ) ) );
	}

	/**
	 * @testdox A store where nobody owns payments attaches no renewal handler when the legacy facade builds the gateway.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_owner_none_gateway_from_the_legacy_facade_attaches_no_handler(): void {
		$this->load_subscriptions();
		$this->arrange_ownership( false, false, NativePaymentsState::CONNECTED );
		if ( ! class_exists( 'WC_Payments', false ) ) {
			require_once WC_ABSPATH . 'src/Internal/Payments/Providers/WooPayments/Compat/legacy/class-wc-payments.php';
		}
		$this->setExpectedDeprecated( 'WC_Payments::get_gateway' );

		$gateway = \WC_Payments::get_gateway();

		$this->assertInstanceOf( NativeWooPaymentsGateway::class, $gateway );
		$this->assertFalse( has_action( 'woocommerce_scheduled_subscription_payment_' . OrderPaymentStore::GATEWAY_ID ), 'No native renewal handler may be attached.' );
		$this->assertFalse( has_filter( 'woocommerce_email_classes', array( NativeWooPaymentsGateway::class, 'add_subscription_emails' ) ), 'The native failed-renewal email must not be registered.' );
	}

	/**
	 * @testdox A renewal of a subscription paid in $subscription_mode mode, created in $current_mode mode, is refused only when the modes differ.
	 * @dataProvider renewal_modes
	 *
	 * Client 11.1.0 `tests/unit/subscriptions/test-class-wc-payments-subscription-service.php:858-884`. The staging case below
	 * shows the guard is attached to `wcs_renewal_order_items`.
	 *
	 * @param string      $subscription_mode `_wcpay_mode` on the subscription's parent order.
	 * @param string      $current_mode      Current order mode of the store.
	 * @param string|null $expected_error    Expected refusal message, or null when the renewal goes ahead.
	 */
	public function test_renewal_is_refused_when_the_mode_changed( string $subscription_mode, string $current_mode, ?string $expected_error ): void {
		$this->use_order_mode( $current_mode );
		$items = array( 'line_item_a', 'line_item_b' );

		try {
			if ( null !== $expected_error ) {
				$this->expectException( \RuntimeException::class );
				$this->expectExceptionMessage( $expected_error );
			}

			$result = WooPaymentsSubscriptionRenewalHooks::check_renewal_mode( $items, new WC_Order(), $this->create_subscription_paid_in_mode( $subscription_mode ) );

			$this->assertSame( $items, $result );
		} finally {
			$this->reset_container_replacements();
		}
	}

	/**
	 * @testdox A staging copy does not refuse renewals over the mode, as in the client.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_staging_copy_does_not_refuse_renewals_over_the_mode(): void {
		$this->load_subscriptions();
		require_once __DIR__ . '/../Fixtures/DuplicateSiteSubscriptionsStaging.php';
		class_alias( \Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\DuplicateSiteSubscriptionsStaging::class, 'WCS_Staging' );
		$this->arrange_ownership( false, true, NativePaymentsState::ACTIVE );
		$this->register_native_payments_and_run_init();
		$this->use_order_mode( 'prod' );
		$items = array( 'line_item_a' );

		$result = apply_filters( 'wcs_renewal_order_items', $items, new WC_Order(), $this->create_subscription_paid_in_mode( 'test' ) );

		$this->assertSame( $items, $result );
	}

	/** @return array<string,array{string,string,?string}> */
	public static function renewal_modes(): array {
		return array(
			'test subscription, live store' => array( 'test', 'prod', 'Subscription was made when WooPayments was in the test mode and cannot be renewed in the live mode.' ),
			'live subscription, test store' => array( 'prod', 'test', 'Subscription was made when WooPayments was in the live mode and cannot be renewed in the test mode.' ),
			'same mode'                     => array( 'test', 'test', null ),
			'no recorded mode'              => array( '', 'prod', null ),
		);
	}

	/**
	 * Make the store report the given order mode.
	 *
	 * @param string $order_mode `test` or `prod`.
	 */
	private function use_order_mode( string $order_mode ): void {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_order_mode' ) )
			->getMock();
		$account_service->method( 'get_order_mode' )->willReturn( $order_mode );
		wc_get_container()->replace( WooPaymentsAccountService::class, $account_service );
	}

	/**
	 * Create a subscription stand-in whose parent order was paid in the given mode.
	 *
	 * @param string $mode `_wcpay_mode` on the parent order; empty for none.
	 * @return WC_Order
	 */
	private function create_subscription_paid_in_mode( string $mode ): WC_Order {
		$parent_order = new WC_Order();
		$parent_order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		if ( '' !== $mode ) {
			$parent_order->update_meta_data( '_wcpay_mode', $mode );
		}
		$parent_order->save();

		$subscription               = new class() extends WC_Order {
			/** @var WC_Order|null */
			public ?WC_Order $parent_order = null;

			/**
			 * Return the parent order, as WC_Subscription::get_parent() does.
			 *
			 * @return WC_Order|false
			 */
			public function get_parent() {
				return $this->parent_order ?? false;
			}
		};
		$subscription->parent_order = $parent_order;

		return $subscription;
	}

	/** @return array<string,array{string,string,bool}> */
	public static function renewal_contexts(): array {
		return array(
			'connected, gateway disabled' => array( 'connected', NativePaymentsState::CONNECTED, false ),
			'connected, WP-CLI runner'    => array( 'connected WP-CLI', NativePaymentsState::CONNECTED, true ),
			'active, WP-CLI runner'       => array( 'active WP-CLI', NativePaymentsState::ACTIVE, true ),
		);
	}

	/** @return array<string,array{string}> */
	public static function native_owned_states(): array {
		return array(
			'connected, gateway disabled' => array( NativePaymentsState::CONNECTED ),
			'active'                      => array( NativePaymentsState::ACTIVE ),
		);
	}

	/** @return array<string,array{string,bool,bool,string}> */
	public static function stores_without_native_renewals(): array {
		return array(
			'plugin-owned, native tier clamped' => array( 'plugin-owned', true, true, NativePaymentsState::ACTIVE ),
			'plugin-owned, native not enabled'  => array( 'plugin-owned', true, false, NativePaymentsState::DISABLED ),
			'never set up'                      => array( 'never set up', false, false, NativePaymentsState::DISABLED ),
		);
	}

	/**
	 * Put a card gateway that records its renewal calls in the container, where the provider and the renewal root find it.
	 *
	 * @return NativeWooPaymentsGateway&object{calls:array<int,array<int,mixed>>}
	 */
	private function install_spy_gateway(): NativeWooPaymentsGateway {
		$gateway = new class() extends NativeWooPaymentsGateway {
			/** @var array<int,array<int,mixed>> */
			public array $calls = array();

			/**
			 * Record a renewal instead of charging.
			 *
			 * @param mixed $amount        Renewal amount.
			 * @param mixed $renewal_order Renewal order.
			 */
			public function scheduled_subscription_payment( $amount, $renewal_order ): void {
				$this->calls[] = array( __FUNCTION__, (float) $amount, $renewal_order->get_id() );
			}

			/**
			 * Record a failing-method update.
			 *
			 * @param mixed $subscription  Subscription.
			 * @param mixed $renewal_order Renewal order.
			 */
			public function update_failing_payment_method( $subscription, $renewal_order ): void {
				$this->calls[] = array( __FUNCTION__, $subscription->get_id(), $renewal_order->get_id() );
			}

			/**
			 * Record the manual-renewal policy check.
			 *
			 * @param mixed $subscription Subscription.
			 */
			public function maybe_force_subscription_to_manual( $subscription ): void {
				$this->calls[] = array( __FUNCTION__, $subscription->get_id() );
			}
		};
		wc_get_container()->replace( NativeWooPaymentsGateway::class, $gateway );

		return $gateway;
	}

	/**
	 * Build WooCommerce's gateway list again, as a new request would after the bootstrap ran.
	 */
	private function reload_payment_gateways(): void {
		WC()->payment_gateways()->payment_gateways = array();
		WC()->payment_gateways()->init();
	}

	/**
	 * Create a subscription stand-in paid with WooPayments and its pending renewal order.
	 *
	 * @return array{WC_Order,WC_Order}
	 */
	private function create_subscription_with_renewal_order(): array {
		$subscription = new WC_Order();
		$subscription->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$subscription->save();

		$renewal_order = new WC_Order();
		$renewal_order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$renewal_order->set_total( '12.50' );
		$renewal_order->set_status( 'pending' );
		$renewal_order->save();

		return array( $subscription, $renewal_order );
	}

	/**
	 * Run a scheduled renewal the way WooCommerce Subscriptions does.
	 *
	 * Mirrors Subscriptions 8.x: `WC_Subscription::is_manual()` asks `has_available_payment_method()`, which is
	 * `wc_get_payment_gateway_by_order( $subscription )` (`includes/core/class-wc-subscription.php:700-710`,
	 * `includes/gateways/class-wc-subscriptions-payment-gateways.php:131-133`). An automatic renewal then fires the
	 * gateway's hook through `trigger_gateway_renewal_payment_hook()` (same file, `:68-122`).
	 *
	 * @param WC_Order $subscription  Subscription stand-in.
	 * @param WC_Order $renewal_order Renewal order.
	 * @return bool Whether the renewal was automatic.
	 */
	private function run_scheduled_subscription_payment( WC_Order $subscription, WC_Order $renewal_order ): bool {
		if ( ! wc_get_payment_gateway_by_order( $subscription ) ) {
			return false;
		}

		if ( $renewal_order->get_total() > 0 && $renewal_order->get_payment_method() ) {
			WC()->payment_gateways();
			do_action( 'woocommerce_scheduled_subscription_payment_' . $renewal_order->get_payment_method(), $renewal_order->get_total(), $renewal_order );
		}

		return true;
	}

	/**
	 * Report the WooCommerce Subscriptions core library as loaded, which is how the gateway and the renewal root detect
	 * Subscriptions. They ask LegacyProxy; the mock is reset after every test.
	 */
	private function load_subscriptions(): void {
		$this->register_legacy_proxy_function_mocks(
			array(
				'class_exists' => static fn( $class_name, ...$args ) => 'WC_Subscriptions_Core_Plugin' === $class_name || class_exists( $class_name, ...$args ),
			)
		);
	}

	/**
	 * Arrange payments ownership and the stored tier for a fresh request.
	 *
	 * @param bool   $plugin_owned Whether the WooPayments plugin is active.
	 * @param bool   $native       Whether native payments is enabled.
	 * @param string $state        Stored native tier.
	 */
	private function arrange_ownership( bool $plugin_owned, bool $native, string $state ): void {
		$active_plugins = array_values( array_diff( (array) get_option( 'active_plugins', array() ), array( NativePaymentsRuntimeArbiter::PLUGIN_FILE ) ) );
		if ( $plugin_owned ) {
			$active_plugins[] = NativePaymentsRuntimeArbiter::PLUGIN_FILE;
		}
		update_option( 'active_plugins', $active_plugins );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, $native ? '__return_true' : '__return_false' );
		update_option( NativePaymentsState::OPTION_NAME, $state, true );
		wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->invalidate();
		wc_get_container()->get( NativePaymentsState::class )->invalidate();
	}

	/**
	 * Run the native payments bootstrap for a shopper request, as WooCommerce does when it loads, then run the `init`
	 * callbacks it added. The rest of `init` already ran when the test suite loaded WordPress.
	 */
	private function register_native_payments_and_run_init(): void {
		global $wp_filter;

		$before = isset( $wp_filter['init'] ) ? $wp_filter['init']->callbacks : array();
		( new NativePaymentsBootstrap(
			array( WooPaymentsProvider::class, 'get_bootstrap_root_matrix' ),
			static fn(): array => array()
		) )->register( wc_get_container(), '__return_false' );

		$after = isset( $wp_filter['init'] ) ? $wp_filter['init']->callbacks : array();
		ksort( $after );
		foreach ( $after as $priority => $callbacks ) {
			foreach ( $callbacks as $id => $callback ) {
				if ( ! isset( $before[ $priority ][ $id ] ) ) {
					call_user_func( $callback['function'] );
				}
			}
		}
	}
}
