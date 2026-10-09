<?php
/**
 * GatewaySwitch class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use ReflectionClass;
use Throwable;

/**
 * Turns the wallet gateway on through WooCommerce's settings API, so a collecting store can take PayPal payments.
 *
 * The CLI and the profiler enter the collecting state on a store whose wallet has not booted, so the gateway WooCommerce
 * holds is the shell's placeholder, which never saves. The wallet's own gateway class is used for its settings only:
 * built without its constructor, which needs the booted wallet, it reads its stored row, falls back to its own form
 * defaults for a missing one, and saves through `WC_Settings_API::update_option()`, filters included.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class GatewaySwitch {

	/**
	 * Turn the wallet gateway on. Nothing else of its settings changes.
	 *
	 * @since 11.3.0
	 *
	 * @throws RuntimeException When the gateway's settings cannot be saved, for example after a change to the gateway
	 *                          class this switch does not expect; the failure is logged.
	 */
	public function turn_on(): void {
		try {
			$this->save_enabled();
		} catch ( Throwable $failure ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					sprintf( 'Could not turn the PayPal gateway on: %1$s: %2$s', get_class( $failure ), $failure->getMessage() ),
					array( 'source' => 'woocommerce-paypal-wallet' )
				);
			}

			throw new RuntimeException( 'Could not turn the PayPal gateway on, so the store did not start collecting. See the WooCommerce log (source woocommerce-paypal-wallet).', 0, $failure ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A fixed message and the cause, for the CLI and the log; never output as HTML.
		}
	}

	/**
	 * Save `enabled = yes` through the gateway's settings API.
	 */
	protected function save_enabled(): void {
		$gateway     = ( new ReflectionClass( PayPalGateway::class ) )->newInstanceWithoutConstructor();
		$gateway->id = PayPalGateway::ID;
		$gateway->init_form_fields();
		$gateway->init_settings();
		$gateway->update_option( 'enabled', 'yes' );
	}
}
