<?php
/**
 * MultiCurrencyStateBuilderFactory class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyCacheInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyLocalizationInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistryFactory;

/**
 * Creates native multi-currency state builders with the live rate-provider registry.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencyStateBuilderFactory {

	/**
	 * Rate provider registry factory.
	 *
	 * @var CurrencyRateProviderRegistryFactory
	 */
	private CurrencyRateProviderRegistryFactory $provider_registry_factory;

	/**
	 * Request-local state invalidation coordinator.
	 *
	 * @var MultiCurrencyStateInvalidator
	 */
	private MultiCurrencyStateInvalidator $state_invalidator;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param CurrencyRateProviderRegistryFactory $provider_registry_factory Rate provider registry factory.
	 * @param MultiCurrencyStateInvalidator       $state_invalidator         Request-local state invalidation coordinator.
	 */
	final public function init( CurrencyRateProviderRegistryFactory $provider_registry_factory, MultiCurrencyStateInvalidator $state_invalidator ): void {
		$this->provider_registry_factory = $provider_registry_factory;
		$this->state_invalidator         = $state_invalidator;
	}

	/**
	 * Create a state builder.
	 *
	 * @param MultiCurrencyLocalizationInterface|null $localization_service Optional localization boundary.
	 * @param MultiCurrencyCacheInterface|null        $cache                Optional cache boundary.
	 * @return MultiCurrencyStateBuilder
	 */
	public function create(
		?MultiCurrencyLocalizationInterface $localization_service = null,
		?MultiCurrencyCacheInterface $cache = null
	): MultiCurrencyStateBuilder {
		$localization_service = $localization_service ?? new MultiCurrencyLocalizationService();
		$cache                = $cache ?? new MultiCurrencyDatabaseCache();

		return new MultiCurrencyStateBuilder(
			$localization_service,
			new MultiCurrencyRateService( $this->provider_registry_factory->create() ),
			$cache,
			$this->state_invalidator
		);
	}
}
