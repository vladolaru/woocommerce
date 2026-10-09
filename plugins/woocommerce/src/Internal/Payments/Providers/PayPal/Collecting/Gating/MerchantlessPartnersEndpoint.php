<?php
/**
 * MerchantlessPartnersEndpoint class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PartnersEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\SellerStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;

/**
 * The wallet's partners endpoint for a store the platform serves before it has a merchant ID: a collecting store.
 *
 * The wallet asks for the seller status to learn the merchant's products and capabilities (card fields, APMs,
 * reference transactions, seller type). A collecting store has no merchant yet, so the request would go to the
 * merchant-integrations endpoint with an empty merchant ID and fail. The status is refused here instead, without a
 * request, with the exception every reader already handles as a failed lookup.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class MerchantlessPartnersEndpoint extends PartnersEndpoint {

	/**
	 * Refuse the seller status: there is no merchant to look up.
	 *
	 * @throws RuntimeException Always.
	 */
	public function seller_status(): SellerStatus {
		throw new RuntimeException( 'The store has no PayPal merchant ID yet; there is no seller status to fetch.' );
	}
}
