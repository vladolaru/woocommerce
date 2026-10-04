<?php
/**
 * Manage the Seller status.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PartnersEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\SellerStatusProduct;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\DccApplies;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\FailureRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ProductStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\SellerStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ProductStatusResultCache;

class DCCProductStatus extends ProductStatus {
	public const KEY = 'products_dcc_enabled';

	protected DccApplies $dcc_applies;

	public function __construct(
		bool $is_connected,
		PartnersEndpoint $partners_endpoint,
		FailureRegistry $api_failure_registry,
		ProductStatusResultCache $result_cache,
		DccApplies $dcc_applies
	) {
		parent::__construct( $is_connected, $partners_endpoint, $api_failure_registry, $result_cache );

		$this->dcc_applies = $dcc_applies;
	}

	protected function check_api_response( SellerStatus $seller_status ): bool {
		foreach ( $seller_status->products() as $product ) {
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

			if ( in_array( 'CUSTOM_CARD_PROCESSING', $product->capabilities(), true ) ) {
				return true;
			}
		}

		return false;
	}

	protected function get_cache_lifespan( bool $is_eligible ): int {
		if ( ! $is_eligible && $this->dcc_applies->for_country_currency() ) {
			return 3 * HOUR_IN_SECONDS;
		}

		return MONTH_IN_SECONDS;
	}
}
