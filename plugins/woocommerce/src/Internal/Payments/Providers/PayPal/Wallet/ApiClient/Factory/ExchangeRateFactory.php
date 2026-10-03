<?php
/**
 * The ExchangeRateFactory Factory.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory;

use stdClass;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\ExchangeRate;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;

/**
 * Class ExchangeRateFactory
 */
class ExchangeRateFactory {
	/**
	 * Returns an ExchangeRate object based off a PayPal Response.
	 *
	 * @param stdClass $data The JSON object.
	 *
	 * @return ExchangeRate|null
	 * @throws RuntimeException When JSON object is malformed.
	 */
	public function from_paypal_response( stdClass $data ): ?ExchangeRate {
		// Looks like all fields in this object are optional, according to the docs,
		// and sometimes we get an empty object.
		$source_currency = $data->source_currency ?? '';
		$target_currency = $data->target_currency ?? '';
		$value           = $data->value ?? '';
		if ( ! $source_currency && ! $target_currency && ! $value ) {
			// Do not return empty object.
			return null;
		}

		return new ExchangeRate( $source_currency, $target_currency, $value );
	}
}
