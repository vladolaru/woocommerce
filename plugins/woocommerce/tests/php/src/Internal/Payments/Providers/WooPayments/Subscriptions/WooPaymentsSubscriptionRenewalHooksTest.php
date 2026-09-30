<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Subscriptions;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsBootstrap;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsState;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionRenewalHooks;
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
 * Each case runs in its own process because it loads a Subscriptions stand-in class.
 */
class WooPaymentsSubscriptionRenewalHooksTest extends WC_Unit_Test_Case {

	/**
	 * @testdox A native-owned $state store has the renewal handlers and the failed-renewal email after init.
	 * @dataProvider native_owned_states
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
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
	}

	/**
	 * @testdox The renewal handler resolves the card gateway only when a renewal runs.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
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
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
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
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
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
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
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
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
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
	 * Load a WooCommerce Subscriptions core stand-in, which is how the gateway and the renewal root detect Subscriptions.
	 */
	private function load_subscriptions(): void {
		if ( ! class_exists( 'WC_Subscriptions_Core_Plugin', false ) ) {
			class_alias( self::class, 'WC_Subscriptions_Core_Plugin' );
		}
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
