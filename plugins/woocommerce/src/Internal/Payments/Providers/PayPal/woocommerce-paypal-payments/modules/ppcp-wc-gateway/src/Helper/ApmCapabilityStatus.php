<?php
/**
 * Status of the alternative payment methods seller capability.
 *
 * @package WooCommerce\PayPalCommerce\WcGateway\Helper
 */

declare( strict_types = 1 );

namespace WooCommerce\PayPalCommerce\WcGateway\Helper;

use WooCommerce\PayPalCommerce\ApiClient\Entity\SellerStatusCapability;
use WooCommerce\PayPalCommerce\ApiClient\Helper\ProductStatus;
use WooCommerce\PayPalCommerce\ApiClient\Entity\SellerStatus;

/**
 * Reads the APM seller capability for features that use it as a proxy (Pay Later
 * messaging), without depending on the local APM module.
 */
class ApmCapabilityStatus extends ProductStatus {
	/**
	 * Shares the result-cache key of the local APM product status on purpose: both
	 * classes read the same capability, so they reuse one cached answer, make no
	 * extra seller-status request, and cannot disagree within a request.
	 */
	public const KEY             = 'products_local_apms_enabled';
	public const CAPABILITY_NAME = 'PAYPAL_CHECKOUT_ALTERNATIVE_PAYMENT_METHODS';

	protected function check_api_response( SellerStatus $seller_status ): bool {
		foreach ( $seller_status->capabilities() as $capability ) {
			if ( $capability->name() === self::CAPABILITY_NAME && $capability->status() === SellerStatusCapability::STATUS_ACTIVE ) {
				return true;
			}
		}

		return false;
	}
}
