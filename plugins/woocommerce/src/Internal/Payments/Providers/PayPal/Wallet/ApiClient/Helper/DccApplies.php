<?php
/**
 * The DCC Applies helper checks if the current installation can use DCC or not.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper;

/**
 * Class DccApplies
 */
class DccApplies {

	/**
	 * The matrix which countries and currency combinations can be used for DCC.
	 *
	 * @var array
	 */
	private $allowed_country_currency_matrix;

	/**
	 * The getter of the 3-letter currency code of the shop.
	 *
	 * @var CurrencyGetter
	 */
	private CurrencyGetter $currency;

	/**
	 * 2-letter country code of the shop.
	 *
	 * @var string
	 */
	private $country;

	/**
	 * DccApplies constructor.
	 *
	 * @param array          $allowed_country_currency_matrix The matrix which countries and currency combinations can be used for DCC.
	 * @param CurrencyGetter $currency The getter of the 3-letter currency code of the shop.
	 * @param string         $country 2-letter country code of the shop.
	 */
	public function __construct(
		array $allowed_country_currency_matrix,
		CurrencyGetter $currency,
		string $country
	) {
		$this->allowed_country_currency_matrix = $allowed_country_currency_matrix;
		$this->currency                        = $currency;
		$this->country                         = $country;
	}

	/**
	 * Returns whether DCC can be used in the current country and the current currency used.
	 *
	 * @return bool
	 */
	public function for_country_currency(): bool {
		if ( ! in_array( $this->country, array_keys( $this->allowed_country_currency_matrix ), true ) ) {
			return false;
		}
		$applies = in_array( $this->currency->get(), $this->allowed_country_currency_matrix[ $this->country ], true );
		return $applies;
	}
}
