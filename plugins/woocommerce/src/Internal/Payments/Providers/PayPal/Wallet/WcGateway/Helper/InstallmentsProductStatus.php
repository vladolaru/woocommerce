<?php
/**
 * Manage the Seller status for Installments.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ProductStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\SellerStatus;

/**
 * Class InstallmentsProductStatus.
 */
class InstallmentsProductStatus extends ProductStatus {
	public const KEY = 'products_installments_enabled';

	/**
	 * Checks the PayPal API response for the product status.
	 *
	 * @param SellerStatus $seller_status The seller status.
	 */
	protected function check_api_response( SellerStatus $seller_status ): bool {
		foreach ( $seller_status->capabilities() as $capability ) {
			if ( $capability->name() !== 'INSTALLMENTS' ) {
				continue;
			}

			if ( $capability->status() === 'ACTIVE' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the cache lifespan of the status, in seconds.
	 *
	 * @param bool $is_eligible Whether is eligible.
	 */
	protected function get_cache_lifespan( bool $is_eligible ): int {
		return MONTH_IN_SECONDS;
	}
}
