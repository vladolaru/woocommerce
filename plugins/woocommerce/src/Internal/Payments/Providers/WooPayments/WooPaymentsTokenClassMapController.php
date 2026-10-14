<?php
/**
 * WooPaymentsTokenClassMapController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsAmazonPayToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsLinkToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsSepaToken;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Loads saved WooPayments SEPA, Link and Amazon Pay tokens with the token classes here, whether a token row names its
 * type or one of the WooPayments extension's token class names.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsTokenClassMapController implements RegisterHooksInterface {

	private const TYPE_CLASS_MAP = array(
		WooPaymentsSepaToken::TYPE      => WooPaymentsSepaToken::class,
		WooPaymentsLinkToken::TYPE      => WooPaymentsLinkToken::class,
		WooPaymentsAmazonPayToken::TYPE => WooPaymentsAmazonPayToken::class,
	);

	private const COMPAT_TOKEN_CLASS_MAP = array(
		'WC_Payment_Token_WCPay_SEPA'       => WooPaymentsSepaToken::class,
		'WC_Payment_Token_WCPay_Link'       => WooPaymentsLinkToken::class,
		'WC_Payment_Token_WCPay_Amazon_Pay' => WooPaymentsAmazonPayToken::class,
	);

	/**
	 * Register token-class mapping hooks.
	 */
	public function register() {
		add_filter( 'woocommerce_payment_token_class', array( $this, 'handle_woocommerce_payment_token_class' ), 10, 2 );
	}

	/**
	 * Handle the woocommerce_payment_token_class filter.
	 *
	 * @internal
	 *
	 * @param mixed $class_name Token class name WooCommerce would load.
	 * @param mixed $type       Persisted token type.
	 * @return mixed
	 */
	public function handle_woocommerce_payment_token_class( $class_name, $type ) {
		if ( is_string( $type ) && isset( self::TYPE_CLASS_MAP[ $type ] ) ) {
			return self::TYPE_CLASS_MAP[ $type ];
		}

		if ( ! is_string( $class_name ) ) {
			return $class_name;
		}

		return self::COMPAT_TOKEN_CLASS_MAP[ $class_name ] ?? $class_name;
	}
}
