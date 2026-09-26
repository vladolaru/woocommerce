<?php
/**
 * WooPaymentsTokenClassMapController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsAmazonPayToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsLinkToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsSepaToken;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Maps persisted WooPayments token types to native token classes.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsTokenClassMapController implements RegisterHooksInterface {

	private const TYPE_CLASS_MAP = array(
		WooPaymentsSepaToken::TYPE      => WooPaymentsSepaToken::class,
		WooPaymentsLinkToken::TYPE      => WooPaymentsLinkToken::class,
		WooPaymentsAmazonPayToken::TYPE => WooPaymentsAmazonPayToken::class,
	);

	private const DERIVED_CLASS_MAP = array(
		'WC_Payment_Token_wcpay_sepa'       => WooPaymentsSepaToken::class,
		'WC_Payment_Token_wcpay_link'       => WooPaymentsLinkToken::class,
		'WC_Payment_Token_wcpay_amazon_pay' => WooPaymentsAmazonPayToken::class,
	);

	private const LEGACY_CLASS_MAP = array(
		'WC_Payment_Token_WCPay_SEPA'       => WooPaymentsSepaToken::class,
		'WC_Payment_Token_WCPay_Link'       => WooPaymentsLinkToken::class,
		'WC_Payment_Token_WCPay_Amazon_Pay' => WooPaymentsAmazonPayToken::class,
	);

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter $arbiter Runtime owner arbiter.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter ): void {
		$this->arbiter = $arbiter;
	}

	/**
	 * Register token-class mapping hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		if ( false === has_filter( 'woocommerce_payment_token_class', array( $this, 'handle_woocommerce_payment_token_class' ) ) ) {
			add_filter( 'woocommerce_payment_token_class', array( $this, 'handle_woocommerce_payment_token_class' ), 10, 2 );
		}
	}

	/**
	 * Handle the woocommerce_payment_token_class filter.
	 *
	 * @internal
	 *
	 * @param string $class_name Token class name WooCommerce would load.
	 * @param string $type       Persisted token type.
	 * @return string
	 */
	public function handle_woocommerce_payment_token_class( string $class_name, string $type ): string {
		if ( isset( self::TYPE_CLASS_MAP[ $type ] ) ) {
			return self::TYPE_CLASS_MAP[ $type ];
		}

		$class_map = array_merge( self::DERIVED_CLASS_MAP, self::LEGACY_CLASS_MAP );

		return $class_map[ $class_name ] ?? $class_name;
	}
}
