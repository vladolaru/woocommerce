<?php
/**
 * The Product factory.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory;

use stdClass;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Product;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;

/**
 * Class ProductFactory
 */
class ProductFactory {

	/**
	 * Creates a Product based off a PayPal response.
	 *
	 * @param stdClass $data The JSON object.
	 *
	 * @return Product
	 * @throws RuntimeException When JSON object is malformed.
	 */
	public function from_paypal_response( stdClass $data ): Product {
		if ( ! isset( $data->id ) ) {
			throw new RuntimeException( 'No id for product given' );
		}
		if ( ! isset( $data->name ) ) {
			throw new RuntimeException( 'No name for product given' );
		}

		return new Product(
			$data->id,
			$data->name,
			$data->description ?? ''
		);
	}
}
