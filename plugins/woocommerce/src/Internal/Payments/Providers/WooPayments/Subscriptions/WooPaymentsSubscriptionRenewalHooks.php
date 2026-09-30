<?php
/**
 * WooPaymentsSubscriptionRenewalHooks class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Attaches the WooCommerce Subscriptions renewal hooks for native WooPayments on every request of a native-owned store.
 *
 * The client attaches them whenever WooPayments loads, whether or not its gateway is enabled (client 11.1.0
 * `includes/class-wc-payments.php:630-649`, `includes/compat/subscriptions/trait-wc-payment-gateway-wcpay-subscriptions.php:274-298`),
 * so a connected store with the gateway disabled still renews. The callbacks resolve the card gateway only when they run.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsSubscriptionRenewalHooks implements RegisterHooksInterface {

	/**
	 * Whether the renewal hooks were attached in this request.
	 *
	 * @var bool
	 */
	private static bool $attached = false;

	/**
	 * Runtime ownership arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * Card gateway, resolved when a renewal hook runs.
	 *
	 * @var NativeWooPaymentsGateway|null
	 */
	private ?NativeWooPaymentsGateway $gateway = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter $arbiter Runtime ownership arbiter.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter ): void {
		$this->arbiter = $arbiter;
	}

	/**
	 * Attach the renewal hooks on `plugins_loaded` priority 11 when native owns payments.
	 *
	 * That is when the client detects Subscriptions and attaches (client 11.1.0 `woocommerce-payments.php:214`), after
	 * Subscriptions has loaded and before WooCommerce builds its emails.
	 */
	public function register(): void {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		if ( did_action( 'plugins_loaded' ) ) {
			$this->handle_plugins_loaded();
			return;
		}

		add_action( 'plugins_loaded', array( $this, 'handle_plugins_loaded' ), 11 );
	}

	/**
	 * Attach the renewal hooks when Subscriptions is available.
	 *
	 * @internal
	 */
	public function handle_plugins_loaded(): void {
		if ( ! WooPaymentsSubscriptionMethodPolicy::is_subscriptions_available() ) {
			return;
		}

		self::attach( $this );
	}

	/**
	 * Attach the renewal hooks once per request, pointing them at the given handler.
	 *
	 * Called with this class on native-owned requests, or with the card gateway when it is built first (for example in tests).
	 *
	 * @param NativeWooPaymentsGateway|self $handler Object that handles renewals, failing-method updates and forced manual renewal.
	 * @return bool Whether this call attached the hooks.
	 */
	public static function attach( object $handler ): bool {
		if ( self::$attached ) {
			return false;
		}
		self::$attached = true;

		if ( false === has_filter( 'woocommerce_email_classes', array( NativeWooPaymentsGateway::class, 'add_subscription_emails' ) ) ) {
			add_filter( 'woocommerce_email_classes', array( NativeWooPaymentsGateway::class, 'add_subscription_emails' ), 20 );
		}

		add_action( 'woocommerce_checkout_subscription_created', array( $handler, 'maybe_force_subscription_to_manual' ), 10, 1 );

		foreach ( WooPaymentsSubscriptionMethodPolicy::get_reusable_gateway_ids() as $gateway_id ) {
			add_action( 'woocommerce_scheduled_subscription_payment_' . $gateway_id, array( $handler, 'scheduled_subscription_payment' ), 10, 2 );
			add_action( 'woocommerce_subscription_failing_payment_method_updated_' . $gateway_id, array( $handler, 'update_failing_payment_method' ), 10, 2 );
		}

		WooPaymentsSubscriptionAdminPaymentMethodHandler::instance()->register_hooks();

		return true;
	}

	/**
	 * Process a scheduled renewal through the card gateway.
	 *
	 * @internal
	 *
	 * @param mixed $amount        Renewal amount.
	 * @param mixed $renewal_order Renewal order.
	 */
	public function scheduled_subscription_payment( $amount, $renewal_order ): void {
		$this->get_gateway()->scheduled_subscription_payment( $amount, $renewal_order );
	}

	/**
	 * Copy an updated failing payment method through the card gateway.
	 *
	 * @internal
	 *
	 * @param mixed $subscription  Subscription.
	 * @param mixed $renewal_order Renewal order.
	 */
	public function update_failing_payment_method( $subscription, $renewal_order ): void {
		$this->get_gateway()->update_failing_payment_method( $subscription, $renewal_order );
	}

	/**
	 * Apply the card gateway's manual-renewal policy to a new subscription.
	 *
	 * @internal
	 *
	 * @param mixed $subscription Subscription.
	 */
	public function maybe_force_subscription_to_manual( $subscription ): void {
		$this->get_gateway()->maybe_force_subscription_to_manual( $subscription );
	}

	/**
	 * Get the card gateway, resolving it on first use.
	 *
	 * @return NativeWooPaymentsGateway
	 */
	private function get_gateway(): NativeWooPaymentsGateway {
		if ( null === $this->gateway ) {
			$this->gateway = wc_get_container()->get( NativeWooPaymentsGateway::class );
		}

		return $this->gateway;
	}
}
