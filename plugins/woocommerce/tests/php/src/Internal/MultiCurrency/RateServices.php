<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\CurrencyRateProvider;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRateService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateInvalidator;

/**
 * Builds a rate service outside the container, holding the given provider, for tests that build their own state.
 */
final class RateServices {

	/**
	 * Build a rate service.
	 *
	 * @param CurrencyRateProvider|null          $provider    Automatic-rate provider, or null for none.
	 * @param MultiCurrencyStateInvalidator|null $invalidator Invalidation coordinator the service bumps when a provider is set.
	 * @return MultiCurrencyRateService
	 */
	public static function with( ?CurrencyRateProvider $provider = null, ?MultiCurrencyStateInvalidator $invalidator = null ): MultiCurrencyRateService {
		$service = new MultiCurrencyRateService();
		$service->init( $invalidator ?? new MultiCurrencyStateInvalidator() );
		if ( null !== $provider ) {
			$service->set_provider( $provider );
		}

		return $service;
	}
}
