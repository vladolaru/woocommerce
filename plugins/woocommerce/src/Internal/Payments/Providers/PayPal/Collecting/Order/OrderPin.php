<?php
/**
 * OrderPin class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\OrderAppContext;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use InvalidArgumentException;
use WC_Order;

/**
 * The app a WooCommerce order's PayPal order belongs to, stored on the order.
 *
 * A PayPal order answers only the app that created it, so every later call on it has to go through that app.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class OrderPin {

	/**
	 * Pin an order to an app. The pin is pending meta until the caller saves the order, as the PayPal meta write does.
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Order $order The order.
	 * @param string   $app   One of the PlatformTransport::APP_ constants.
	 *
	 * @throws InvalidArgumentException When the app is unknown.
	 */
	public static function record( WC_Order $order, string $app ): void {
		if ( ! OrderAppContext::is_app( $app ) ) {
			throw new InvalidArgumentException( 'Unknown PayPal wallet platform app.' );
		}

		$order->update_meta_data( OrderAppContext::ORDER_APP_META_KEY, $app );
	}

	/**
	 * The app an order is pinned to, or the platform app when it has no valid pin.
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Order $order The order.
	 * @return string One of the PlatformTransport::APP_ constants.
	 */
	public static function app( WC_Order $order ): string {
		$pinned = $order->get_meta( OrderAppContext::ORDER_APP_META_KEY, true );

		return is_string( $pinned ) && OrderAppContext::is_app( $pinned ) ? $pinned : PlatformTransport::APP_PLATFORM;
	}

	/**
	 * Whether an order carries a valid pin.
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Order $order The order.
	 * @return bool
	 */
	public static function is_pinned( WC_Order $order ): bool {
		$pinned = $order->get_meta( OrderAppContext::ORDER_APP_META_KEY, true );

		return is_string( $pinned ) && OrderAppContext::is_app( $pinned );
	}

	/**
	 * Whether an order was paid with the PayPal wallet: through its gateway, and pinned to an app or carrying a PayPal order ID.
	 *
	 * A pin or a PayPal order ID alone is not enough: an order paid by another gateway is not a wallet order. Some wallet
	 * paths leave an order unpinned, such as a capture already completed before processing, or a pin that failed.
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Order $order The order.
	 * @return bool
	 */
	public static function is_wallet_order( WC_Order $order ): bool {
		if ( PayPalGateway::ID !== $order->get_payment_method() ) {
			return false;
		}

		return self::is_pinned( $order ) || ! empty( $order->get_meta( PayPalGateway::ORDER_ID_META_KEY, true ) );
	}
}
