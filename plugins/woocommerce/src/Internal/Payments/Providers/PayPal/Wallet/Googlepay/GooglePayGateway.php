<?php
/**
 * GooglePayGateway contract stub.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Googlepay
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Googlepay;

/**
 * Carries only the gateway ID, which kept wallet code still compares and keys settings by.
 *
 * The gateway itself belongs to a feature the wallet does not ship. Remove this stub together with the last
 * reference to it when the feature's branches are cut (plan B).
 *
 * @since 11.3.0
 * @internal
 */
final class GooglePayGateway {

	/**
	 * The gateway ID, as the extension registers it.
	 */
	public const ID = 'ppcp-googlepay';
}
