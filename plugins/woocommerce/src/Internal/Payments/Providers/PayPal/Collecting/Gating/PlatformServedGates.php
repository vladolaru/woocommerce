<?php
/**
 * PlatformServedGates class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition\FeaturesDefinition;

/**
 * The wallet filters that keep authorize-only and saved PayPal and Venmo off while the platform serves the store.
 *
 * The platform captures every payment for the merchant and cannot keep a buyer's vault token for them, so a collecting
 * or platform-connected store always captures and never offers to save PayPal or Venmo. A store with first-party
 * credentials is left alone.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class PlatformServedGates {

	/**
	 * The connection state.
	 *
	 * @var ConnectionState
	 */
	private ConnectionState $connection_state;

	/**
	 * Constructor.
	 *
	 * @param ConnectionState $connection_state The connection state.
	 */
	public function __construct( ConnectionState $connection_state ) {
		$this->connection_state = $connection_state;
	}

	/**
	 * Force the capture intent while the platform serves the store.
	 *
	 * The filter carries `CAPTURE` or `AUTHORIZE` from OrderEndpoint::create() and `capture` or `authorize` from
	 * SmartButton::intent(), so a lower-case value gets `capture` and anything else gets `CAPTURE`.
	 *
	 * @internal
	 *
	 * @param mixed $intent The order intent.
	 *
	 * @return mixed The capture intent, or the value unchanged when the platform does not serve the store.
	 */
	public function handle_woocommerce_paypal_payments_order_intent( $intent ) {
		if ( ! $this->connection_state->is_served_by_platform() ) {
			return $intent;
		}

		return is_string( $intent ) && '' !== $intent && strtolower( $intent ) === $intent ? 'capture' : 'CAPTURE';
	}

	/**
	 * Mark saved PayPal and Venmo unavailable while the platform serves the store, so the settings app hides the toggle.
	 *
	 * @internal
	 *
	 * @param mixed $features The merchant features, keyed by feature ID.
	 *
	 * @return mixed The features with saved PayPal and Venmo off, or the value unchanged when it is not an array or the
	 *               platform does not serve the store.
	 */
	public function handle_woocommerce_paypal_payments_rest_common_merchant_features( $features ) {
		if ( ! is_array( $features ) || ! $this->connection_state->is_served_by_platform() ) {
			return $features;
		}
		$features[ FeaturesDefinition::FEATURE_SAVE_PAYPAL_AND_VENMO ] = array( 'enabled' => false );

		return $features;
	}
}
