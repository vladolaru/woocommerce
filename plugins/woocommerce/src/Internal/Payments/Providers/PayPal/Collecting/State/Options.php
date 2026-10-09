<?php
/**
 * Options class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State;

/**
 * The names of the options the collecting state keeps, and typed readers for the array options.
 *
 * The readers never write: a missing or malformed option reads as an empty array.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class Options {

	/**
	 * The collecting state: the store sells with PayPal before the merchant has a PayPal account.
	 * Keys: payee_email, tracking_id, environment, payee_bound.
	 *
	 * @since 11.3.0
	 */
	public const COLLECTING = 'woocommerce_paypal_wallet_collecting';

	/**
	 * The platform connection: the merchant finished onboarding and PayPal confirmed their merchant ID.
	 * Keys: merchant_id, tracking_id, payee_email, connected_at, environment.
	 *
	 * @since 11.3.0
	 */
	public const PLATFORM = 'woocommerce_paypal_wallet_platform';

	/**
	 * The ID of the first order that was routed to the collecting payee. Written once, with add_option().
	 *
	 * @since 11.3.0
	 */
	public const FIRST_ORDER = 'woocommerce_paypal_wallet_first_order';

	/**
	 * The transport's own webhook subscriptions, as webhook IDs by platform app. Never the wallet's `ppcp-webhook`.
	 *
	 * @since 11.3.0
	 */
	public const WEBHOOKS = 'woocommerce_paypal_wallet_webhooks';

	/**
	 * The collecting option.
	 *
	 * @since 11.3.0
	 *
	 * @return array
	 */
	public function collecting(): array {
		return $this->read( self::COLLECTING );
	}

	/**
	 * The platform option.
	 *
	 * @since 11.3.0
	 *
	 * @return array
	 */
	public function platform(): array {
		return $this->read( self::PLATFORM );
	}

	/**
	 * The transport's webhook subscriptions option.
	 *
	 * @since 11.3.0
	 *
	 * @return array
	 */
	public function webhooks(): array {
		return $this->read( self::WEBHOOKS );
	}

	/**
	 * Read an option as an array; anything that is not an array reads as an empty one.
	 *
	 * @param string $name The option name.
	 *
	 * @return array
	 */
	private function read( string $name ): array {
		$value = get_option( $name, array() );

		return is_array( $value ) ? $value : array();
	}
}
