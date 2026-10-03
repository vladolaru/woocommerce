<?php
/**
 * Manage the Seller status.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\PayUponInvoice
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\PayUponInvoice;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\SellerStatusProduct;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ProductStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\SellerStatus;

class PayUponInvoiceProductStatus extends ProductStatus {
	public const KEY = 'products_pui_enabled';

	protected function check_api_response( SellerStatus $seller_status ): bool {
		foreach ( $seller_status->products() as $product ) {
			if ( $product->name() !== 'PAYMENT_METHODS' ) {
				continue;
			}

			if ( ! in_array(
				$product->vetting_status(),
				array(
					SellerStatusProduct::VETTING_STATUS_APPROVED,
					SellerStatusProduct::VETTING_STATUS_SUBSCRIBED,
				),
				true
			)
			) {
				continue;
			}

			if ( in_array( 'PAY_UPON_INVOICE', $product->capabilities(), true ) ) {
				return true;
			}
		}

		return false;
	}

	protected function get_cache_lifespan( bool $is_eligible ): int {
		return MONTH_IN_SECONDS;
	}
}
