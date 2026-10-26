<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\CurrencyRateProviderInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\RateService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateInvalidator;

/**
 * Builds a rate service outside the container, holding the given provider, for tests that build their own state.
 */
final class RateServices {

	/**
	 * Build a rate service.
	 *
	 * @param CurrencyRateProviderInterface|null $provider    Automatic-rate provider, or null for none.
	 * @param StateInvalidator|null              $invalidator Invalidation coordinator the service bumps when a provider is set.
	 * @return RateService
	 */
	public static function with( ?CurrencyRateProviderInterface $provider = null, ?StateInvalidator $invalidator = null ): RateService {
		$service = new RateService();
		$service->init( $invalidator ?? new StateInvalidator() );
		if ( null !== $provider ) {
			$service->set_provider( $provider );
		}

		return $service;
	}
}
