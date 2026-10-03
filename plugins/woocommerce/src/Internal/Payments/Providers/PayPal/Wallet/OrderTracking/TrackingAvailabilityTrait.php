<?php
/**
 * The order tracking module.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderTracking
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderTracking;

use WC_Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\Bearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor\AuthorizedPaymentsProcessor;

trait TrackingAvailabilityTrait {

	/**
	 * Checks if tracking should be enabled for current post.
	 *
	 * @param Bearer $bearer The Bearer.
	 * @return bool
	 */
	protected function is_tracking_enabled( Bearer $bearer ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification
		$post_id = (int) wc_clean( wp_unslash( $_GET['id'] ?? $_GET['post'] ?? '' ) );
		if ( ! $post_id ) {
			return false;
		}

		$order = wc_get_order( $post_id );
		if ( ! ( $order instanceof WC_Order ) ) {
			return false;
		}

		$captured                  = $order->get_meta( AuthorizedPaymentsProcessor::CAPTURED_META_KEY );
		$is_captured               = empty( $captured ) || wc_string_to_bool( $captured );
		$is_paypal_order_edit_page = $order->get_meta( PayPalGateway::ORDER_ID_META_KEY ) && ! empty( $order->get_transaction_id() );

		try {
			$token = $bearer->bearer();
			return $is_paypal_order_edit_page
				&& $is_captured
				&& $token->is_tracking_available()
				&& apply_filters( 'woocommerce_paypal_payments_shipment_tracking_enabled', true );
		} catch ( RuntimeException $exception ) {
			return false;
		}
	}
}
