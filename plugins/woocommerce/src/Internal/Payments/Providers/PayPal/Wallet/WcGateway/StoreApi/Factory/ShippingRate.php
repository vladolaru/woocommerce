<?php

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Factory;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\ShippingOption;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Entity\Money;

/**
 * ShippingRate object for the Store API.
 */
class ShippingRate {
	/**
	 * The rate ID.
	 *
	 * @var string
	 */
	private string $rate_id;

	/**
	 * The name.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Whether the rate is selected.
	 *
	 * @var bool
	 */
	private bool $selected;

	/**
	 * The price.
	 *
	 * @var Money
	 */
	private Money $price;

	/**
	 * The taxes.
	 *
	 * @var Money
	 */
	private Money $taxes;

	/**
	 * ShippingRate constructor.
	 *
	 * @param string $rate_id  The rate ID.
	 * @param string $name     The name.
	 * @param bool   $selected Whether the rate is selected.
	 * @param Money  $price    The price.
	 * @param Money  $taxes    The taxes.
	 */
	public function __construct(
		string $rate_id,
		string $name,
		bool $selected,
		Money $price,
		Money $taxes
	) {
		$this->rate_id  = $rate_id;
		$this->name     = $name;
		$this->selected = $selected;
		$this->price    = $price;
		$this->taxes    = $taxes;
	}

	/**
	 * Returns the rate ID.
	 */
	public function rate_id(): string {
		return $this->rate_id;
	}

	/**
	 * Returns the name.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns whether the rate is selected.
	 */
	public function selected(): bool {
		return $this->selected;
	}

	/**
	 * Returns the price.
	 */
	public function price(): Money {
		return $this->price;
	}

	/**
	 * Returns the taxes.
	 */
	public function taxes(): Money {
		return $this->taxes;
	}

	/**
	 * Returns the ShippingOption object for the PayPal API.
	 */
	public function to_paypal(): ShippingOption {
		return new ShippingOption(
			$this->rate_id,
			$this->name,
			$this->selected,
			$this->price->to_paypal(),
			ShippingOption::TYPE_SHIPPING
		);
	}
}
