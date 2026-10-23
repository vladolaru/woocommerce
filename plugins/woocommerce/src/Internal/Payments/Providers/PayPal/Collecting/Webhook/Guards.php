<?php
/**
 * Guards class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Webhook;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\HeldCapture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\HeldSettlement;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\CustomIds;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use WC_Order;

/**
 * Tells this store's webhook events from the other stores' events that the platform apps' subscriptions also receive.
 *
 * A subscription receives every event of its app, for every store the app serves. A capture event belongs to this store
 * only when it names the PayPal order, or the capture, of one of its wallet orders; an onboarding event only when it
 * carries the store's tracking ID. The checks read the IDs, never the subscription the event came through.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class Guards {

	/**
	 * The capture events, whose resource is a capture (or, for REFUNDED and REVERSED, a refund of one).
	 *
	 * @since 11.3.0
	 */
	public const CAPTURE_EVENTS = array(
		'PAYMENT.CAPTURE.COMPLETED',
		'PAYMENT.CAPTURE.PENDING',
		'PAYMENT.CAPTURE.REVERSED',
		'PAYMENT.CAPTURE.REFUNDED',
		'PAYMENT.CAPTURE.DENIED',
	);

	/**
	 * The checkout events, whose resource is the PayPal order.
	 *
	 * @since 11.3.0
	 */
	public const CHECKOUT_EVENTS = array(
		'CHECKOUT.ORDER.APPROVED',
		'CHECKOUT.ORDER.COMPLETED',
		'CHECKOUT.PAYMENT-APPROVAL.REVERSED',
	);

	/**
	 * The onboarding and consent events, which the platform app's subscription receives.
	 *
	 * @since 11.3.0
	 */
	public const ONBOARDING_EVENTS = array(
		'MERCHANT.ONBOARDING.COMPLETED',
		'MERCHANT.PARTNER-CONSENT.REVOKED',
	);

	/**
	 * Whether a capture event's resource names an order: its PayPal order when the event carries one, else its capture.
	 *
	 * A refund resource names no PayPal order; its capture is the one its "up" link points to.
	 *
	 * @since 11.3.0
	 *
	 * @param array    $event_resource The event resource.
	 * @param WC_Order $order    The order.
	 * @return bool
	 */
	public function capture_event_matches_order( array $event_resource, WC_Order $order ): bool {
		$paypal_order_id = self::text( $event_resource['supplementary_data']['related_ids']['order_id'] ?? null );
		if ( '' !== $paypal_order_id ) {
			$stored = self::text( $order->get_meta( PayPalGateway::ORDER_ID_META_KEY, true ) );

			return '' !== $stored && $stored === $paypal_order_id;
		}

		$stored_captures = array_filter(
			array(
				self::text( $order->get_meta( HeldCapture::CAPTURE_ID_META_KEY, true ) ),
				self::text( $order->get_meta( HeldSettlement::RETURNED_META_KEY, true ) ),
				$order->get_transaction_id(),
			)
		);

		return array() !== array_intersect( self::capture_ids( $event_resource ), $stored_captures );
	}

	/**
	 * Whether a checkout event's resource, a PayPal order, is the order's PayPal order.
	 *
	 * @since 11.3.0
	 *
	 * @param array    $event_resource The event resource.
	 * @param WC_Order $order    The order.
	 * @return bool
	 */
	public function checkout_event_matches_order( array $event_resource, WC_Order $order ): bool {
		$paypal_order_id = self::text( $event_resource['id'] ?? null );
		$stored          = self::text( $order->get_meta( PayPalGateway::ORDER_ID_META_KEY, true ) );

		return '' !== $paypal_order_id && $stored === $paypal_order_id;
	}

	/**
	 * Whether an order is still pending and holds no PayPal order ID: the wallet may be mid-capture, with the ID in memory
	 * and not saved yet, so an event naming the order cannot be matched yet.
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Order $order The order.
	 * @return bool
	 */
	public function awaits_paypal_order_id( WC_Order $order ): bool {
		return '' === self::text( $order->get_meta( PayPalGateway::ORDER_ID_META_KEY, true ) ) && $order->has_status( OrderStatus::PENDING );
	}

	/**
	 * Whether an onboarding event carries the store's tracking ID.
	 *
	 * @since 11.3.0
	 *
	 * @param array  $event_resource    The event resource.
	 * @param string $tracking_id The store's tracking ID.
	 * @return bool
	 */
	public function onboarding_event_is_ours( array $event_resource, string $tracking_id ): bool {
		$event_tracking_id = self::text( $event_resource['tracking_id'] ?? null );

		return '' !== $tracking_id && $event_tracking_id === $tracking_id;
	}

	/**
	 * The wallet order an event's resource names, whether or not the event is this store's.
	 *
	 * Tried in order: the custom ID when it is an order ID, as the wallet sets it before capturing; the PayPal order ID;
	 * the capture ID. Only an order paid through the wallet gateway is returned.
	 *
	 * @since 11.3.0
	 *
	 * @param array $event_resource The event resource.
	 * @return WC_Order|null
	 */
	public function order_for_event( array $event_resource ): ?WC_Order {
		$custom_ids = array( self::text( $event_resource['custom_id'] ?? null ) );
		foreach ( (array) ( $event_resource['purchase_units'] ?? array() ) as $unit ) {
			$custom_ids[] = is_array( $unit ) ? self::text( $unit['custom_id'] ?? null ) : '';
		}
		foreach ( $custom_ids as $custom_id ) {
			if ( '' === $custom_id || 0 === strpos( $custom_id, CustomIds::CUSTOMER_ID_PREFIX ) || ! ctype_digit( $custom_id ) ) {
				continue;
			}
			$order = wc_get_order( (int) $custom_id );

			return $order instanceof WC_Order && PayPalGateway::ID === $order->get_payment_method() ? $order : null;
		}

		$paypal_order_ids = array_filter( array( self::text( $event_resource['supplementary_data']['related_ids']['order_id'] ?? null ), self::text( $event_resource['id'] ?? null ) ) );
		foreach ( array_unique( $paypal_order_ids ) as $paypal_order_id ) {
			$order = $this->first_wallet_order(
				array(
					// A webhook names an order by its PayPal order ID only when its custom ID is a cart session's; webhooks are rare.
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- See above.
						array(
							'key'   => PayPalGateway::ORDER_ID_META_KEY,
							'value' => $paypal_order_id,
						),
					),
				)
			);
			if ( null !== $order ) {
				return $order;
			}
		}

		foreach ( self::capture_ids( $event_resource ) as $capture_id ) {
			$order = $this->first_wallet_order( array( 'transaction_id' => $capture_id ) );
			if ( null !== $order ) {
				return $order;
			}
		}

		return null;
	}

	/**
	 * The capture IDs a resource can name: its related capture, the capture its "up" link points to, and its own ID.
	 *
	 * @param array $event_resource The event resource.
	 * @return string[]
	 */
	private static function capture_ids( array $event_resource ): array {
		$ids = array( self::text( $event_resource['supplementary_data']['related_ids']['capture_id'] ?? null ) );
		foreach ( (array) ( $event_resource['links'] ?? array() ) as $link ) {
			if ( is_array( $link ) && 'up' === ( $link['rel'] ?? '' ) && is_string( $link['href'] ?? null ) && preg_match( '#/v2/payments/captures/([^/?]+)#', $link['href'], $matches ) ) {
				$ids[] = $matches[1];
			}
		}
		$ids[] = self::text( $event_resource['id'] ?? null );

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * The first wallet order matching query arguments, or null.
	 *
	 * @param array $args The query arguments.
	 * @return WC_Order|null
	 */
	private function first_wallet_order( array $args ): ?WC_Order {
		$orders = wc_get_orders(
			array_merge(
				$args,
				array(
					'payment_method' => PayPalGateway::ID,
					'limit'          => 1,
				)
			)
		);

		return is_array( $orders ) && isset( $orders[0] ) && $orders[0] instanceof WC_Order ? $orders[0] : null;
	}

	/**
	 * A scalar as a string; anything else as an empty string.
	 *
	 * @param mixed $value The value.
	 * @return string
	 */
	private static function text( $value ): string {
		return is_string( $value ) || is_int( $value ) ? (string) $value : '';
	}
}
