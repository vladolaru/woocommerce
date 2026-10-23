<?php

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Entity;

/**
 * CartTotals object for the Store API.
 */
class CartTotals {
	/**
	 * The total items.
	 *
	 * @var Money
	 */
	private Money $total_items;

	/**
	 * The total items tax.
	 *
	 * @var Money
	 */
	private Money $total_items_tax;

	/**
	 * The total fees.
	 *
	 * @var Money
	 */
	private Money $total_fees;

	/**
	 * The total fees tax.
	 *
	 * @var Money
	 */
	private Money $total_fees_tax;

	/**
	 * The total discount.
	 *
	 * @var Money
	 */
	private Money $total_discount;

	/**
	 * The total discount tax.
	 *
	 * @var Money
	 */
	private Money $total_discount_tax;

	/**
	 * The total shipping.
	 *
	 * @var Money
	 */
	private Money $total_shipping;

	/**
	 * The total shipping tax.
	 *
	 * @var Money
	 */
	private Money $total_shipping_tax;

	/**
	 * The total price.
	 *
	 * @var Money
	 */
	private Money $total_price;

	/**
	 * The total tax.
	 *
	 * @var Money
	 */
	private Money $total_tax;

	/**
	 * CartTotals constructor.
	 *
	 * @param Money $total_items        The total items.
	 * @param Money $total_items_tax    The total items tax.
	 * @param Money $total_fees         The total fees.
	 * @param Money $total_fees_tax     The total fees tax.
	 * @param Money $total_discount     The total discount.
	 * @param Money $total_discount_tax The total discount tax.
	 * @param Money $total_shipping     The total shipping.
	 * @param Money $total_shipping_tax The total shipping tax.
	 * @param Money $total_price        The total price.
	 * @param Money $total_tax          The total tax.
	 */
	public function __construct(
		Money $total_items,
		Money $total_items_tax,
		Money $total_fees,
		Money $total_fees_tax,
		Money $total_discount,
		Money $total_discount_tax,
		Money $total_shipping,
		Money $total_shipping_tax,
		Money $total_price,
		Money $total_tax
	) {
		$this->total_items        = $total_items;
		$this->total_items_tax    = $total_items_tax;
		$this->total_fees         = $total_fees;
		$this->total_fees_tax     = $total_fees_tax;
		$this->total_discount     = $total_discount;
		$this->total_discount_tax = $total_discount_tax;
		$this->total_shipping     = $total_shipping;
		$this->total_shipping_tax = $total_shipping_tax;
		$this->total_price        = $total_price;
		$this->total_tax          = $total_tax;
	}

	/**
	 * Returns the total items.
	 */
	public function total_items(): Money {
		return $this->total_items;
	}

	/**
	 * Returns the total items tax.
	 */
	public function total_items_tax(): Money {
		return $this->total_items_tax;
	}

	/**
	 * Returns the total fees.
	 */
	public function total_fees(): Money {
		return $this->total_fees;
	}

	/**
	 * Returns the total fees tax.
	 */
	public function total_fees_tax(): Money {
		return $this->total_fees_tax;
	}

	/**
	 * Returns the total discount.
	 */
	public function total_discount(): Money {
		return $this->total_discount;
	}

	/**
	 * Returns the total discount tax.
	 */
	public function total_discount_tax(): Money {
		return $this->total_discount_tax;
	}

	/**
	 * Returns the total shipping.
	 */
	public function total_shipping(): Money {
		return $this->total_shipping;
	}

	/**
	 * Returns the total shipping tax.
	 */
	public function total_shipping_tax(): Money {
		return $this->total_shipping_tax;
	}

	/**
	 * Returns the total price.
	 */
	public function total_price(): Money {
		return $this->total_price;
	}

	/**
	 * Returns the total tax.
	 */
	public function total_tax(): Money {
		return $this->total_tax;
	}
}
