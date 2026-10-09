<?php
/**
 * OrderAppContext class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\OrderPin;
use InvalidArgumentException;
use WC_Order;

/**
 * The platform app the current request's PayPal calls go through, and the one place that decides it for a call.
 *
 * Code that works on a known order enters its pinned app; everything else lets the transport pick for the store payee,
 * on each call. The context lives for one request: the container builds one instance per request.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class OrderAppContext {

	/**
	 * The order meta that pins the app an order's PayPal calls go through.
	 *
	 * @since 11.3.0
	 */
	public const ORDER_APP_META_KEY = '_wc_paypal_wallet_order_app';

	/**
	 * The entered app, or null when none is entered.
	 *
	 * @var string|null
	 */
	private ?string $app = null;

	/**
	 * Enter an app for the rest of the request, until reset.
	 *
	 * @since 11.3.0
	 *
	 * @param string $app One of the PlatformTransport::APP_ constants.
	 *
	 * @throws InvalidArgumentException When the app is unknown.
	 */
	public function enter( string $app ): void {
		if ( ! self::is_app( $app ) ) {
			throw new InvalidArgumentException( 'Unknown PayPal wallet platform app.' );
		}
		$this->app = $app;
	}

	/**
	 * Enter the app pinned on an order; an order with no pin, or an unknown one, enters the platform app.
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Order $order The order.
	 */
	public function enter_for_order( WC_Order $order ): void {
		$this->enter( OrderPin::app( $order ) );
	}

	/**
	 * The entered app, or the platform app when none is entered.
	 *
	 * @since 11.3.0
	 *
	 * @return string
	 */
	public function current(): string {
		return $this->app ?? PlatformTransport::APP_PLATFORM;
	}

	/**
	 * Whether an app is entered.
	 *
	 * @since 11.3.0
	 *
	 * @return bool
	 */
	public function is_entered(): bool {
		return null !== $this->app;
	}

	/**
	 * Leave the entered app.
	 *
	 * @since 11.3.0
	 */
	public function reset(): void {
		$this->app = null;
	}

	/**
	 * The app a call goes through: the entered one, or else the one the transport picks for the payee.
	 *
	 * The pick runs on every call and enters nothing, so a later call still sees an order context entered meanwhile.
	 *
	 * @since 11.3.0
	 *
	 * @param PlatformTransport $transport   The transport.
	 * @param string            $payee_email The store payee.
	 * @return string
	 *
	 * @throws RuntimeException When the transport fails or picks an app it does not know.
	 */
	public function for_call( PlatformTransport $transport, string $payee_email ): string {
		if ( $this->is_entered() ) {
			return $this->current();
		}

		$app = $transport->pick_order_app( $payee_email );
		if ( ! self::is_app( $app ) ) {
			throw new RuntimeException( 'The PayPal wallet platform transport picked an unknown app.' );
		}

		return $app;
	}

	/**
	 * Whether a value names one of the transport's apps.
	 *
	 * @since 11.3.0
	 *
	 * @param string $app The value.
	 * @return bool
	 */
	public static function is_app( string $app ): bool {
		return in_array( $app, array( PlatformTransport::APP_PLATFORM, PlatformTransport::APP_MERCHANT_APP ), true );
	}
}
