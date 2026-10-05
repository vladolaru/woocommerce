<?php

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Factory;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Entity\CartTotals;

/**
 * Factory for the Store API cart totals.
 */
class CartTotalsFactory {
	/**
	 * The money factory.
	 *
	 * @var MoneyFactory
	 */
	private MoneyFactory $money_factory;

	/**
	 * CartTotalsFactory constructor.
	 *
	 * @param MoneyFactory $money_factory The money factory.
	 */
	public function __construct( MoneyFactory $money_factory ) {
		$this->money_factory = $money_factory;
	}

	/**
	 * Parses the 'totals' object from the cart response.
	 *
	 * @param array $obj The obj.
	 */
	public function from_response_obj( array $obj ): CartTotals {
		return new CartTotals(
			$this->money_factory->from_response_values( $obj, 'total_items' ),
			$this->money_factory->from_response_values( $obj, 'total_items_tax' ),
			$this->money_factory->from_response_values( $obj, 'total_fees' ),
			$this->money_factory->from_response_values( $obj, 'total_fees_tax' ),
			$this->money_factory->from_response_values( $obj, 'total_discount' ),
			$this->money_factory->from_response_values( $obj, 'total_discount_tax' ),
			$this->money_factory->from_response_values( $obj, 'total_shipping' ),
			$this->money_factory->from_response_values( $obj, 'total_shipping_tax' ),
			$this->money_factory->from_response_values( $obj, 'total_price' ),
			$this->money_factory->from_response_values( $obj, 'total_tax' )
		);
	}
}
