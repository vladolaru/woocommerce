<?php
/**
 * GatewayIds class file.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper;

/**
 * IDs of the extension's gateways that the wallet does not register but must still recognise in shared state (order meta
 * written by the extension, legacy settings a migration maps). Shared contract: never rename.
 *
 * @since 11.3.0
 * @internal
 */
final class GatewayIds {
	/**
	 * The extension's Debit & Credit Cards (ACDC) gateway.
	 */
	public const CREDIT_CARD = 'ppcp-credit-card-gateway';

	/**
	 * The extension's standalone card button (BCDC) gateway.
	 */
	public const CARD_BUTTON = 'ppcp-card-button-gateway';

	/**
	 * The extension's Apple Pay gateway.
	 */
	public const APPLE_PAY = 'ppcp-applepay';

	/**
	 * The extension's Google Pay gateway.
	 */
	public const GOOGLE_PAY = 'ppcp-googlepay';
}
