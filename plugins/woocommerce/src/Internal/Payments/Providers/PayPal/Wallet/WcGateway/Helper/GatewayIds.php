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

	/**
	 * The extension's Bancontact gateway.
	 */
	public const BANCONTACT = 'ppcp-bancontact';

	/**
	 * The extension's BLIK gateway.
	 */
	public const BLIK = 'ppcp-blik';

	/**
	 * The extension's EPS gateway.
	 */
	public const EPS = 'ppcp-eps';

	/**
	 * The extension's iDEAL gateway.
	 */
	public const IDEAL = 'ppcp-ideal';

	/**
	 * The extension's MyBank gateway.
	 */
	public const MYBANK = 'ppcp-mybank';

	/**
	 * The extension's Przelewy24 gateway.
	 */
	public const P24 = 'ppcp-p24';

	/**
	 * The extension's Trustly gateway.
	 */
	public const TRUSTLY = 'ppcp-trustly';

	/**
	 * The extension's Multibanco gateway.
	 */
	public const MULTIBANCO = 'ppcp-multibanco';

	/**
	 * The extension's Pay upon Invoice gateway.
	 */
	public const PAY_UPON_INVOICE = 'ppcp-pay-upon-invoice-gateway';

	/**
	 * The extension's OXXO gateway.
	 */
	public const OXXO = 'ppcp-oxxo-gateway';

	/**
	 * The extension's Pay with Crypto gateway.
	 */
	public const PWC = 'ppcp-pwc';
}
