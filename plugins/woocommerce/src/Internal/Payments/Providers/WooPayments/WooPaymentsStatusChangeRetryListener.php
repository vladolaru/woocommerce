<?php
/**
 * WooPaymentsStatusChangeRetryListener class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\RenewalGatewayHandover;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use WC_Order;

/**
 * Answers a queued status-change capture or cancel retry that falls due where the built-in WooPayments cannot run it:
 * the WooPayments extension was activated, the WooPayments feature was turned off, or the account is no longer connected.
 *
 * WooPaymentsOrderAdminActionsController queues the retry and runs it where its retry callback is hooked; there this
 * listener does nothing. Otherwise, while the order is still completed (capture) or cancelled (cancel) and authorized,
 * and for a capture paid with WooPayments, it writes the failure note client 11.1.0 writes when such a capture or
 * cancel fails (class-wc-payments-order-service.php:1827-1831, :1880-1884), so the merchant knows the authorization is
 * still open. It changes no status and calls no platform.
 *
 * As the low tiers' cron root, it also registers the setup tier re-check (WooPaymentsSetupTierRecheck) and, under the
 * same guard, the filter that hands a due WooPayments renewal to the gateway (RenewalGatewayHandover).
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsStatusChangeRetryListener implements RegisterHooksInterface {

	/** WooCommerce Subscriptions' action for a due renewal. */
	public const RENEWAL_ACTION = 'woocommerce_scheduled_subscription_payment';

	/** WooCommerce Subscriptions' action for a retry of a failed renewal. */
	public const RENEWAL_RETRY_ACTION = 'woocommerce_scheduled_subscription_payment_retry';

	/**
	 * The WooPayments gateways whose renewals are handed over: the card and Amazon Pay gateways, the reusable ones
	 * (SubscriptionMethodPolicy::get_reusable_gateway_ids()). Written out, so the check loads no class.
	 */
	public const RENEWAL_GATEWAY_IDS = array( 'woocommerce_payments', 'woocommerce_payments_amazon_pay' );

	/**
	 * Every value Stripe Billing records on a subscription, live, cancelled or migrated. A subscription carrying any of them
	 * is never handed over, since Stripe may still bill it. Written out, so the check loads no class.
	 */
	public const STRIPE_BILLING_META_KEYS = array(
		'_wcpay_subscription_id',
		'_wcpay_subscription_discount_ids',
		'_wcpay_pending_invoice_id',
		'_wcpay_billing_invoice_id',
		'_cancelled_wcpay_subscription_id',
		'_migrated_wcpay_subscription_id',
		'_migrated_wcpay_subscription_discount_ids',
		'_migrated_wcpay_pending_invoice_id',
		'_migrated_wcpay_billing_invoice_id',
		'_wcpay_subscription_migrated_during',
	);

	/**
	 * Setup tier, which decides whether the re-check and the renewal hand-over register.
	 *
	 * @var WooPaymentsSetupTier
	 */
	private WooPaymentsSetupTier $setup_tier;

	/**
	 * Initialize the listener.
	 *
	 * @internal
	 *
	 * @param WooPaymentsSetupTier $setup_tier Setup tier.
	 */
	final public function init( WooPaymentsSetupTier $setup_tier ): void {
		$this->setup_tier = $setup_tier;
	}

	/**
	 * Register the retry hook and, on a stored `available` tier the built-in owns, the setup tier re-check and the renewal
	 * hand-over filter.
	 *
	 * This class is the cron root of the low tiers, so both register here: two hooks, with no class loaded, no option read
	 * and no query.
	 */
	public function register() {
		add_action( WooPaymentsOrderAdminActionsController::RETRY_STATUS_CHANGE_OPERATION_ACTION, array( $this, 'handle_retry_status_change_operation' ), 10, 4 );
		if ( $this->setup_tier->should_register_recheck() ) {
			WooPaymentsSetupTier::register_recheck_hook();
			$callback = array( self::class, 'handle_subscription_is_manual' );
			if ( false === has_filter( 'woocommerce_subscription_is_manual', $callback ) ) {
				add_filter( 'woocommerce_subscription_is_manual', $callback, 0, 2 );
			}
		}
	}

	/**
	 * Hand a due renewal of a WooPayments subscription to the gateway while the setup tier is the lowest, as the extension's
	 * always-registered gateway takes it.
	 *
	 * Only inside WooCommerce Subscriptions' due-renewal and retry actions, and only for a card or Amazon Pay subscription.
	 * One carrying any Stripe Billing value stays manual whatever it came in as, since Stripe may still bill it. Otherwise a
	 * manual answer goes to RenewalGatewayHandover, the first class this filter loads.
	 *
	 * @internal
	 *
	 * @param mixed $is_manual    Whether the subscription renews manually.
	 * @param mixed $subscription The subscription.
	 * @return mixed
	 */
	public static function handle_subscription_is_manual( $is_manual, $subscription = null ) {
		if (
			( ! doing_action( self::RENEWAL_ACTION ) && ! doing_action( self::RENEWAL_RETRY_ACTION ) )
			|| ! $subscription instanceof WC_Order
			|| ! in_array( $subscription->get_payment_method(), self::RENEWAL_GATEWAY_IDS, true )
		) {
			return $is_manual;
		}

		// The subscription's meta was loaded with it, so this reads no store.
		foreach ( $subscription->get_meta_data() as $meta ) {
			if ( in_array( $meta->key, self::STRIPE_BILLING_META_KEYS, true ) && ! empty( $meta->value ) ) {
				return true;
			}
		}

		if ( true !== $is_manual ) {
			return $is_manual;
		}

		return wc_get_container()->get( RenewalGatewayHandover::class )->handle( $subscription );
	}

	/**
	 * Note that a retried status-change capture or cancel did not run, unless the order actions controller runs it.
	 *
	 * An operation on the stored authorization is noted while the order stores requires_capture; an operation on an
	 * intent the status change read, while the order still stores that intent and no settled status. A capture is noted
	 * only on an order paid with WooPayments, which is the only order the built-in would have captured.
	 *
	 * @internal
	 *
	 * @param mixed $order_id  Order ID.
	 * @param mixed $operation Capture or cancel.
	 * @param mixed $retry     Number of this retry, from 1.
	 * @param mixed $intent_id Intent the status change read, or empty when it acted on the stored authorization.
	 */
	public function handle_retry_status_change_operation( $order_id, $operation = '', $retry = 0, $intent_id = '' ): void {
		unset( $retry );
		if ( self::is_retry_run_by_controller() ) {
			return;
		}

		$statuses = array(
			PaymentProcessingService::OPERATION_CAPTURE => OrderStatus::COMPLETED,
			PaymentProcessingService::OPERATION_CANCEL  => OrderStatus::CANCELLED,
		);
		if ( ! is_string( $operation ) || ! isset( $statuses[ $operation ] ) ) {
			return;
		}

		$order = wc_get_order( absint( $order_id ) );
		if (
			! $order instanceof WC_Order
			|| ! $order->has_status( $statuses[ $operation ] )
			|| ( PaymentProcessingService::OPERATION_CAPTURE === $operation && ! WooPaymentsPersistenceVocabulary::is_woopayments_gateway_id( (string) $order->get_payment_method() ) )
		) {
			return;
		}

		$stored_status  = (string) $order->get_meta( '_intention_status', true );
		$read_intent_id = is_string( $intent_id ) ? $intent_id : '';
		$is_unperformed = '' === $read_intent_id
			? 'requires_capture' === $stored_status
			: $read_intent_id === (string) $order->get_meta( '_intent_id', true ) && ! in_array( $stored_status, WooPaymentsPaymentOperationService::SETTLED_INTENT_STATUSES, true );
		if ( ! $is_unperformed ) {
			return;
		}

		WooPaymentsOrderAdminActionsController::add_operation_failure_note( $order, $operation );
	}

	/**
	 * Tell whether the order actions controller's retry callback is hooked in this process, so the controller runs the retry.
	 *
	 * The process registered its classes once, at boot. A setup tier raised later in the same process, for example by a
	 * re-check step in the same queue run, does not register the controller, so the tier cannot answer this.
	 *
	 * @return bool
	 */
	private static function is_retry_run_by_controller(): bool {
		global $wp_filter;

		$hook = $wp_filter[ WooPaymentsOrderAdminActionsController::RETRY_STATUS_CHANGE_OPERATION_ACTION ] ?? null;
		if ( ! $hook instanceof \WP_Hook ) {
			return false;
		}

		foreach ( $hook->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;
				if (
					is_array( $function )
					&& ( $function[0] ?? null ) instanceof WooPaymentsOrderAdminActionsController
					&& 'handle_retry_status_change_operation' === ( $function[1] ?? null )
				) {
					return true;
				}
			}
		}

		return false;
	}
}
