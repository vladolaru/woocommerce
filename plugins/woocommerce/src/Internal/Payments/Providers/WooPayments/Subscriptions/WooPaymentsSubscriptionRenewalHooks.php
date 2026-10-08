<?php
/**
 * WooPaymentsSubscriptionRenewalHooks class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderMode;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProviderGatewayAdapter;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Proxies\LegacyProxy;

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
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $arbiter;

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
	 * @param WooPaymentsRuntimeArbiter $arbiter Runtime ownership arbiter.
	 */
	final public function init( WooPaymentsRuntimeArbiter $arbiter ): void {
		$this->arbiter = $arbiter;
	}

	/**
	 * Attach the renewal hooks on `plugins_loaded` priority 11 when native owns payments.
	 *
	 * That is when the client detects Subscriptions and attaches (client 11.1.0 `woocommerce-payments.php:214`), after
	 * Subscriptions has loaded and before WooCommerce builds its emails.
	 */
	public function register(): void {
		if ( ! $this->arbiter->is_builtin_owner() ) {
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

		if ( ! WooPaymentsSubscriptionMethodPolicy::is_duplicate_site() ) {
			add_filter( 'wcs_renewal_order_items', array( self::class, 'check_renewal_mode' ), 10, 3 );
		}

		// Subscriptions before its data copier filtered only the meta query; the client switches the same way for its own
		// renewal meta (client 11.1.0 `includes/compat/subscriptions/trait-wc-payment-gateway-wcpay-subscriptions.php:315-320`).
		if ( wc_get_container()->get( LegacyProxy::class )->call_function( 'class_exists', 'WC_Subscriptions_Data_Copier' ) ) {
			add_filter( 'wc_subscriptions_object_data', array( self::class, 'exclude_charge_idempotency_key' ), 10, 1 );
		} else {
			foreach ( array( 'subscription', 'parent', 'renewal_order', 'resubscribe_order' ) as $copy_type ) {
				add_filter( "wcs_{$copy_type}_meta_query", array( self::class, 'exclude_charge_idempotency_key_from_meta_query' ), 10, 1 );
			}
		}

		return true;
	}

	/**
	 * Leave the charge idempotency key and its ambiguity record out of the data Subscriptions copies between orders and subscriptions.
	 *
	 * The key belongs to one order's charge. Subscriptions copies a parent order's meta to its subscription, and the
	 * subscription's meta to every renewal, so a key kept after an ambiguous parent charge would be sent again by a
	 * renewal with another body, which the provider refuses within 24 hours (review 37 F1). The record of that ambiguous
	 * failure belongs to the same charge, so it stays behind with the key.
	 *
	 * @internal
	 *
	 * @param mixed $data Data to copy, keyed by meta key.
	 * @return mixed
	 */
	public static function exclude_charge_idempotency_key( $data ) {
		if ( is_array( $data ) ) {
			unset( $data[ WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META ], $data[ WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META ] );
		}

		return $data;
	}

	/**
	 * Leave the charge idempotency key and its ambiguity record out of the meta query older Subscriptions versions copy with.
	 *
	 * @internal
	 *
	 * @param mixed $meta_query SQL query selecting the meta to copy.
	 * @return mixed
	 */
	public static function exclude_charge_idempotency_key_from_meta_query( $meta_query ) {
		if ( ! is_string( $meta_query ) ) {
			return $meta_query;
		}

		return $meta_query . sprintf( " AND `meta_key` NOT IN ('%s', '%s')", WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META, WooPaymentsProviderGatewayAdapter::CHARGE_AMBIGUITY_META );
	}

	/**
	 * Refuse to create a renewal order when the subscription was paid in a different WooPayments mode than the current one.
	 *
	 * A test-mode payment method cannot be charged in live mode, and the reverse, so the renewal would only fail.
	 * Client 11.1.0 `WC_Payments_Subscription_Service::check_wcpay_mode_for_subscription()`; WooCommerce Subscriptions
	 * rolls the renewal order back when this throws.
	 *
	 * @internal
	 *
	 * @param mixed $items         Items for the renewal order.
	 * @param mixed $renewal_order Renewal order.
	 * @param mixed $subscription  Subscription being renewed.
	 * @return mixed The items, unchanged.
	 * @throws \RuntimeException When the subscription's mode differs from the current mode.
	 */
	public static function check_renewal_mode( $items, $renewal_order, $subscription ) {
		unset( $renewal_order );
		if ( ! $subscription instanceof \WC_Order || ! is_callable( array( $subscription, 'get_parent' ) ) ) {
			return $items;
		}

		$parent_order = $subscription->get_parent();
		if ( ! $parent_order instanceof \WC_Order ) {
			return $items;
		}

		$subscription_mode = $parent_order->get_meta( '_wcpay_mode' );
		$current_mode      = wc_get_container()->get( WooPaymentsAccountService::class )->get_order_mode();
		if ( ! is_string( $subscription_mode ) || '' === $subscription_mode || $subscription_mode === $current_mode ) {
			return $items;
		}

		if ( WooPaymentsOrderMode::TEST === $subscription_mode ) {
			throw new \RuntimeException( esc_html__( 'Subscription was made when WooPayments was in the test mode and cannot be renewed in the live mode.', 'woocommerce' ) );
		}

		throw new \RuntimeException( esc_html__( 'Subscription was made when WooPayments was in the live mode and cannot be renewed in the test mode.', 'woocommerce' ) );
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
