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

class InstallmentsProductStatus extends ProductStatus {
	public const KEY = 'products_installments_enabled';

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

	protected function get_cache_lifespan( bool $is_eligible ): int {
		return MONTH_IN_SECONDS;
	}
}
