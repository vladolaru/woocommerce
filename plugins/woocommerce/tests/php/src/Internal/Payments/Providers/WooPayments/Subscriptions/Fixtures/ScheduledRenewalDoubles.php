<?php
/**
 * WooCommerce Subscriptions' scheduled renewal and retry actions, for the renewal hand-over tests.
 *
 * @package WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Subscriptions\Fixtures
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Subscriptions\Fixtures;

use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use Exception;
use WC_Order;

/**
 * Stands in for the WooCommerce Subscriptions 9.4.1 callbacks of its due-renewal and retry actions, with the same checks in
 * the same order, so a test fires the real action names through Action Scheduler.
 *
 * - `woocommerce_scheduled_subscription_payment` priority 1: `WC_Subscriptions_Manager::process_renewal()`
 *   (includes/core/class-wc-subscriptions-manager.php:139-170), which creates the renewal order through the data copier's
 *   filters (includes/core/class-wc-subscriptions-data-copier.php:153, :173; includes/core/wcs-order-functions.php:245-246).
 * - priority 10: `WC_Subscriptions_Payment_Gateways::gateway_scheduled_subscription_payment()`
 *   (includes/gateways/class-wc-subscriptions-payment-gateways.php:68-122).
 * - `woocommerce_scheduled_subscription_payment_retry`: the payment step of `WCS_Retry_Manager::maybe_retry_payment()`
 *   (includes/payment-retry/class-wcs-retry-manager.php:340-377).
 * - `wcs_order_contains_early_renewal()`, the Customer Renewal Invoice and the order screen's "Retry Renewal Payment".
 *
 * A renewal order is created for subscriptions with a total; the zero-total branch is left out. The test's hook snapshot
 * removes the callbacks when the test ends.
 */
final class ScheduledRenewalDoubles {

	/** WooCommerce Subscriptions' note on a manual renewal order. */
	public const MANUAL_NOTE = 'Manual renewal order awaiting customer payment.';

	/** WooCommerce Subscriptions' note when a retry finds a manual subscription. */
	public const RETRY_SKIPPED_NOTE = 'Renewal payment retry skipped - related subscription has changed to manual renewal.';

	/** WooCommerce Subscriptions' note when the merchant retries a renewal from the order screen. */
	public const ADMIN_RETRY_NOTE = 'Retry renewal payment action requested by admin.';

	/** The key WooCommerce Subscriptions lists its Customer Renewal Invoice under in the mailer. */
	public const RENEWAL_INVOICE_EMAIL = 'WCS_Email_Customer_Renewal_Invoice';

	/**
	 * Meta the stand-in subscription keeps that WooCommerce Subscriptions stores as subscription properties, so it is not
	 * copied to a renewal order.
	 */
	private const SUBSCRIPTION_ONLY_META = array( '_requires_manual_renewal', '_billing_period', '_billing_interval', '_subscription_suspension_count' );

	/**
	 * Load subscriptions as RenewalSubscriptionDouble and attach the two actions' callbacks.
	 */
	public static function load(): void {
		WooCommerceSubscriptionsDoubles::load();
		WooCommerceSubscriptionsDoubles::load_renewal_order_listener();
		add_filter( 'woocommerce_order_class', array( self::class, 'get_subscription_order_class' ), 20, 3 );
		add_action( 'woocommerce_scheduled_subscription_payment', array( self::class, 'process_renewal' ), 1, 1 );
		add_action( 'woocommerce_scheduled_subscription_payment', array( self::class, 'gateway_scheduled_subscription_payment' ), 10, 1 );
		add_action( 'woocommerce_scheduled_subscription_payment_retry', array( self::class, 'retry_payment' ), 10, 1 );
		// A paid renewal reactivates its subscription, and a failed one puts it on hold, as in WooCommerce Subscriptions.
		WooCommerceSubscriptionsDoubles::activate_subscriptions_on_renewal_payment();
		add_filter( 'woocommerce_order_status_changed', 'WC_Subscriptions_Renewal_Order::maybe_record_subscription_payment', 10, 3 );

		if ( ! function_exists( 'wcs_order_contains_early_renewal' ) ) {
			// Stands in for 9.4.1 `includes/core/wcs-order-functions.php:1059-1075`: an order the early-renewal modal marked.
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need its early-renewal check.
			eval( 'namespace { function wcs_order_contains_early_renewal( $order ) { $order = is_object( $order ) ? $order : wc_get_order( $order ); $is_early_renewal = $order instanceof WC_Order && absint( $order->get_meta( "_subscription_renewal_early", true ) ) > 0; return apply_filters( "woocommerce_subscriptions_is_early_renewal_order", $is_early_renewal, $order ); } }' );
		}
	}

	/**
	 * List the Customer Renewal Invoice stand-in in the mailer under Subscriptions' key, as
	 * `WC_Subscriptions_Email::add_emails()` does at priority 10 (includes/core/class-wc-subscriptions-email.php:37,69-74).
	 * The mailer reads it when it is next built.
	 */
	public static function load_renewal_invoice_email(): void {
		add_filter( 'woocommerce_email_classes', array( self::class, 'add_renewal_invoice_email' ), 10, 1 );
	}

	/**
	 * Add the Customer Renewal Invoice stand-in to the mailer's emails.
	 *
	 * @internal
	 *
	 * @param mixed $email_classes Email classes.
	 * @return mixed
	 */
	public static function add_renewal_invoice_email( $email_classes ) {
		if ( is_array( $email_classes ) ) {
			$email_classes[ self::RENEWAL_INVOICE_EMAIL ] = new RenewalInvoiceEmailDouble();
		}

		return $email_classes;
	}

	/**
	 * Tell whether the order screen offers "Retry Renewal Payment", as `WCS_Admin_Meta_Boxes::can_renewal_order_be_retried()`
	 * decides (includes/core/admin/class-wcs-admin-meta-boxes.php:401-417): a renewal that needs payment and names a gateway
	 * with a scheduled-payment listener, whose subscription supports date changes and renews automatically.
	 *
	 * @param WC_Order $order Renewal order.
	 * @return bool
	 */
	public static function can_renewal_order_be_retried( WC_Order $order ): bool {
		$subscriptions = wcs_get_subscriptions_for_renewal_order( $order->get_id() );
		if ( empty( $subscriptions ) || ! $order->needs_payment() || '' === $order->get_payment_method() ) {
			return false;
		}

		$gateway          = wc_get_payment_gateway_by_order( $order );
		$gateway_supports = isset( $gateway->id ) && false !== has_action( 'woocommerce_scheduled_subscription_payment_' . $gateway->id );
		$subscription     = reset( $subscriptions );

		return $gateway_supports && $subscription->payment_method_supports( 'subscription_date_changes' ) && ! $subscription->is_manual();
	}

	/**
	 * Apply "Retry Renewal Payment" from the order screen, as `WCS_Admin_Meta_Boxes::process_retry_renewal_payment_action_request()`
	 * does (includes/core/admin/class-wcs-admin-meta-boxes.php:377-385).
	 *
	 * @param WC_Order $order Renewal order.
	 */
	public static function process_retry_renewal_payment_action_request( WC_Order $order ): void {
		if ( self::can_renewal_order_be_retried( $order ) ) {
			WC()->payment_gateways();
			$order->add_order_note( self::ADMIN_RETRY_NOTE, 0, true );
			do_action( 'woocommerce_scheduled_subscription_payment_' . $order->get_payment_method(), $order->get_total(), $order );
		}
	}

	/**
	 * Load registered subscriptions as RenewalSubscriptionDouble.
	 *
	 * @internal
	 *
	 * @param mixed $class_name Order class name.
	 * @param mixed $order_type Order type.
	 * @param mixed $order_id   Order ID.
	 * @return mixed
	 */
	public static function get_subscription_order_class( $class_name, $order_type, $order_id ) {
		unset( $order_type );

		return in_array( absint( $order_id ), $GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ] ?? array(), true ) ? RenewalSubscriptionDouble::class : $class_name;
	}

	/**
	 * Process a due renewal as `WC_Subscriptions_Manager::process_renewal()` does.
	 *
	 * @internal
	 *
	 * @param mixed $subscription_id Subscription ID.
	 * @throws Exception When the renewal order cannot be created twice.
	 */
	public static function process_renewal( $subscription_id ): void {
		$subscription = wcs_get_subscription( $subscription_id );

		if ( $subscription instanceof RenewalSubscriptionDouble && $subscription->has_status( 'active' ) && ( 0 === absint( $subscription->get_total() ) || $subscription->is_manual() || '' === $subscription->get_payment_method() || ! $subscription->payment_method_supports( 'gateway_scheduled_payments' ) ) ) {
			$subscription->update_status( 'on-hold', 'Subscription renewal payment due:' );

			$renewal_order = self::create_renewal_order( $subscription );
			if ( is_wp_error( $renewal_order ) ) {
				$renewal_order = self::create_renewal_order( $subscription );
				if ( is_wp_error( $renewal_order ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test double message, not HTML output.
					throw new Exception( 'Error: Unable to create renewal order. Message: ' . $renewal_order->get_error_message() );
				}
			}

			if ( $subscription->is_manual() ) {
				do_action( 'woocommerce_generated_manual_renewal_order', $renewal_order->get_id(), $subscription );
				$renewal_order->add_order_note( self::MANUAL_NOTE );
			} else {
				$renewal_order->set_payment_method( wc_get_payment_gateway_by_order( $subscription ) );
				$renewal_order->save();
			}
		}
	}

	/**
	 * Fire the gateway's renewal hook for an automatic subscription, as
	 * `WC_Subscriptions_Payment_Gateways::gateway_scheduled_subscription_payment()` does.
	 *
	 * @internal
	 *
	 * @param mixed $subscription_id Subscription ID.
	 */
	public static function gateway_scheduled_subscription_payment( $subscription_id ): void {
		$subscription = wcs_get_subscription( $subscription_id );
		if ( ! $subscription instanceof RenewalSubscriptionDouble || $subscription->payment_method_supports( 'gateway_scheduled_payments' ) ) {
			return;
		}

		if ( ! $subscription->is_manual() && ! $subscription->has_status( wcs_get_subscription_ended_statuses() ) ) {
			$latest_renewal_order = $subscription->get_last_order( 'all', 'renewal' );
			if ( $latest_renewal_order instanceof WC_Order && $latest_renewal_order->needs_payment() ) {
				self::trigger_gateway_renewal_payment_hook( $latest_renewal_order );
			}
		}
	}

	/**
	 * Fire the renewal order's gateway hook, as `WC_Subscriptions_Payment_Gateways::trigger_gateway_renewal_payment_hook()` does.
	 *
	 * @param WC_Order $renewal_order Renewal order.
	 */
	public static function trigger_gateway_renewal_payment_hook( WC_Order $renewal_order ): void {
		if ( $renewal_order->get_total() > 0 && $renewal_order->get_payment_method() ) {
			WC()->payment_gateways();
			do_action( 'woocommerce_scheduled_subscription_payment_' . $renewal_order->get_payment_method(), $renewal_order->get_total(), $renewal_order );
		}
	}

	/**
	 * Retry a failed renewal's payment as `WCS_Retry_Manager::maybe_retry_payment()` does once the retry is due: the order goes
	 * back to pending and its subscriptions on hold; a manual subscription clears the order's payment method and skips the
	 * retry, otherwise the subscription's payment method is copied to the order and the gateway hook fires.
	 *
	 * @internal
	 *
	 * @param mixed $order_id Renewal order ID.
	 */
	public static function retry_payment( $order_id ): void {
		$last_order = wc_get_order( absint( $order_id ) );
		if ( ! $last_order instanceof WC_Order ) {
			return;
		}

		$subscriptions = wcs_get_subscriptions_for_renewal_order( $last_order->get_id() );
		$last_order->update_status( 'pending', 'Subscription renewal payment retry:', true );

		$is_manual    = false;
		$subscription = null;
		foreach ( $subscriptions as $subscription ) {
			$subscription->update_status( 'on-hold', 'Subscription renewal payment retry:' );
			// `wcs_order_contains_manual_subscription()` (includes/core/wcs-order-functions.php) asks each subscription.
			$is_manual = $is_manual || $subscription->is_manual();
		}

		if ( $is_manual ) {
			$last_order->set_payment_method( '' );
			$last_order->add_order_note( self::RETRY_SKIPPED_NOTE );
			$last_order->save();
			return;
		}

		if ( $subscription instanceof WC_Order ) {
			$last_order->set_payment_method( $subscription->get_payment_method() );
			$last_order->save();
			self::trigger_gateway_renewal_payment_hook( $last_order );
		}
	}

	/**
	 * Create a renewal order as `wcs_create_renewal_order()` does: the subscription's meta through the data copier's two
	 * filters, its line items through the two item filters, its customer, currency, address and total. An exception from a
	 * filter undoes the order and gives a `WP_Error`, as the creation's transaction does (includes/core/wcs-order-functions.php:308-312).
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return WC_Order|\WP_Error
	 */
	public static function create_renewal_order( WC_Order $subscription ) {
		$order = new WC_Order();
		try {
			return self::fill_renewal_order( $order, $subscription );
		} catch ( Exception $e ) {
			$order->delete( true );

			return new \WP_Error( 'new-order-error', $e->getMessage() );
		}
	}

	/**
	 * Fill a new renewal order from its subscription.
	 *
	 * @param WC_Order $order        New order.
	 * @param WC_Order $subscription Subscription.
	 * @return WC_Order
	 */
	private static function fill_renewal_order( WC_Order $order, WC_Order $subscription ): WC_Order {
		$order->set_customer_id( $subscription->get_customer_id() );
		$order->set_currency( $subscription->get_currency() );
		$order->set_address( $subscription->get_address( 'billing' ), 'billing' );
		$order->set_payment_method( $subscription->get_payment_method() );
		$order->save();

		$data = array();
		foreach ( $subscription->get_meta_data() as $meta ) {
			if ( ! in_array( $meta->key, self::SUBSCRIPTION_ONLY_META, true ) && 0 !== strpos( $meta->key, '_schedule_' ) ) {
				$data[ $meta->key ] = $meta->value;
			}
		}
		// The copier reads the stored meta rows, the saved token IDs among them.
		$data['_payment_tokens'] = $subscription->get_payment_tokens();
		$data                    = apply_filters( 'wc_subscriptions_renewal_order_data', $data, $order, $subscription );
		$data                    = apply_filters( 'wc_subscriptions_object_data', $data, $order, $subscription, 'renewal_order' );
		foreach ( $data as $key => $value ) {
			if ( '_payment_tokens' === $key ) {
				$order->get_data_store()->update_payment_token_ids( $order, (array) $value );
				continue;
			}
			$order->update_meta_data( $key, $value );
		}

		$items = apply_filters( 'wcs_new_order_items', $subscription->get_items(), $order, $subscription );
		$items = apply_filters( 'wcs_renewal_order_items', $items, $order, $subscription );
		foreach ( $items as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				$copy = new \WC_Order_Item_Product();
				$copy->set_name( $item->get_name() );
				$copy->set_quantity( $item->get_quantity() );
				$copy->set_subtotal( $item->get_subtotal() );
				$copy->set_total( $item->get_total() );
				$order->add_item( $copy );
			}
		}
		$order->set_total( $subscription->get_total() );
		$order->update_meta_data( '_subscription_renewal', $subscription->get_id() );
		$order->save();

		$GLOBALS[ WooCommerceSubscriptionsDoubles::RENEWAL_SUBSCRIPTIONS ][ $order->get_id() ][]          = $subscription->get_id();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ][ $order->get_id() ]['renewal'][] = $subscription->get_id();

		return wc_get_order( $order->get_id() );
	}
}
