<?php
/**
 * WooPaymentsExpressCheckoutCurrencyGuard class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;

/**
 * Defends against currency mismatches between the Stripe Express Checkout
 * Element's boot currency and the cart's resolved currency at order
 * placement, which can happen when a multi-currency plugin flips the cart
 * based on the shipping address chosen inside the wallet sheet.
 *
 * Mirrors the WooPayments client plugin's
 * WC_Payments_Express_Checkout_Currency_Guard.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsExpressCheckoutCurrencyGuard implements RegisterHooksInterface {

	private const MISMATCH_ERROR_CODE = 'wcpay_express_checkout_currency_mismatch';

	private const TOKENIZED_CART_NONCE_ACTION = 'woopayments_tokenized_cart_nonce';

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
	 * Register hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		if ( false === has_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'assert_currency_matches_element' ) ) ) {
			add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'assert_currency_matches_element' ), 10, 2 );
		}
	}

	/**
	 * Compare the boot currency carried on the request to the order's
	 * resolved currency. Fail-open when no header was sent (non-ECE caller).
	 *
	 * @param \WC_Order        $order   The order being created.
	 * @param \WP_REST_Request $request The Store API request.
	 *
	 * @phpstan-param \WP_REST_Request<array<string,mixed>> $request
	 *
	 * @throws RouteException When the currencies disagree.
	 */
	public function assert_currency_matches_element( \WC_Order $order, \WP_REST_Request $request ): void {
		if ( ! $this->is_express_checkout_request( $request ) ) {
			return;
		}

		$expected = strtolower( (string) $request->get_header( 'X-WooPayments-Payment-Currency' ) );
		if ( '' === $expected ) {
			return;
		}

		$actual = strtolower( $order->get_currency() );
		if ( $expected === $actual ) {
			return;
		}

		wc_get_logger()->error(
			sprintf(
				'Express checkout currency mismatch at order placement. Order: %d, element currency: %s, order currency: %s.',
				$order->get_id(),
				$expected,
				$actual
			),
			array( 'source' => 'payment-info' )
		);

		throw new RouteException(
			self::MISMATCH_ERROR_CODE, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Machine-readable error code constant; the message arguments are escaped below.
			sprintf(
				/* translators: 1: expected currency code, 2: actual currency code */
				esc_html__(
					'The shipping address you selected requires a different currency (%2$s) than the one this payment was started with (%1$s). You have not been charged — please reload the page and try again.',
					'woocommerce'
				),
				esc_html( strtoupper( $expected ) ),
				esc_html( strtoupper( $actual ) )
			),
			400
		);
	}

	/**
	 * Scope the assertion to ECE-originated Store API requests: the tokenized
	 * cart header must be set and its nonce must verify.
	 *
	 * @param \WP_REST_Request $request The Store API request.
	 * @return bool
	 *
	 * @phpstan-param \WP_REST_Request<array<string,mixed>> $request
	 */
	private function is_express_checkout_request( \WP_REST_Request $request ): bool {
		if ( 'true' !== $request->get_header( 'X-WooPayments-Tokenized-Cart' ) ) {
			return false;
		}

		$nonce = (string) $request->get_header( 'X-WooPayments-Tokenized-Cart-Nonce' );

		return (bool) wp_verify_nonce( $nonce, self::TOKENIZED_CART_NONCE_ACTION );
	}
}
