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
