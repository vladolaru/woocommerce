<?php
/**
 * PayWithCryptoGuard class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\GatewayIds;

/**
 * Keeps the Pay with Crypto gateway off a collecting store.
 *
 * Pay with Crypto needs a PayPal business account that holds the Crypto capability, and a collecting store has no
 * account yet. The wallet's own capability check says no on such a store, but its answer lives in a cache the extension
 * shares and never expires, so a "yes" written earlier would register the gateway. This filter reads the state instead.
 * A platform-connected or first-party store is left to the capability check.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class PayWithCryptoGuard {

	/**
	 * The connection state reader.
	 *
	 * @var ConnectionState
	 */
	private ConnectionState $connection_state;

	/**
	 * Constructor.
	 *
	 * @param ConnectionState $connection_state The connection state reader.
	 */
	public function __construct( ConnectionState $connection_state ) {
		$this->connection_state = $connection_state;
	}

	/**
	 * Remove the Pay with Crypto gateway from WooCommerce's gateway list while the store collects.
	 *
	 * Hooked to `woocommerce_payment_gateways` after the wallet's own filter added the gateway.
	 *
	 * @internal
	 *
	 * @param mixed $gateways The registered gateways: class names and gateway objects.
	 *
	 * @return mixed The list without the gateway, or the value unchanged when it is not an array or the store does not
	 *               collect.
	 */
	public function handle_woocommerce_payment_gateways( $gateways ) {
		if ( ! is_array( $gateways ) || ConnectionState::COLLECTING !== $this->connection_state->resolve() ) {
			return $gateways;
		}

		return array_values(
			array_filter(
				$gateways,
				static function ( $gateway ): bool {
					return ! ( is_object( $gateway ) && GatewayIds::PWC === ( $gateway->id ?? '' ) );
				}
			)
		);
	}
}
