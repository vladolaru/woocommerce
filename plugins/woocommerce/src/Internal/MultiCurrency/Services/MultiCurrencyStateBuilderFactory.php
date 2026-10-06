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
	 * Builder shared by every consumer that does not bring its own boundaries.
	 *
	 * @var MultiCurrencyStateBuilder|null
	 */
	private ?MultiCurrencyStateBuilder $shared_builder = null;

	/**
	 * Provider registrars the shared builder's rate registry was built from.
	 *
	 * @var array<int,mixed>
	 */
	private array $shared_builder_registrars = array();

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
	 * Get the request's shared state builder, or a separate one when boundaries are supplied.
	 *
	 * Every consumer shares one builder, so the state is built once per request as the client keeps one Multi-Currency
	 * instance (client 11.1.0 `includes/multi-currency/MultiCurrency.php:750-756`). It rebuilds after reset() and when its
	 * stored inputs change: a Multi-Currency option, the store currency or the current user.
	 *
	 * @param MultiCurrencyLocalizationInterface|null $localization_service Optional localization boundary.
	 * @param MultiCurrencyCacheInterface|null        $cache                Optional cache boundary.
	 * @return MultiCurrencyStateBuilder
	 */
	public function create(
		?MultiCurrencyLocalizationInterface $localization_service = null,
		?MultiCurrencyCacheInterface $cache = null
	): MultiCurrencyStateBuilder {
		if ( null === $localization_service && null === $cache ) {
			// The rate registry is taken from the registrars once, so a builder made before a provider registered is replaced.
			$registrars = $this->provider_registry_factory->get_provider_registrars();
			if ( null === $this->shared_builder || $registrars !== $this->shared_builder_registrars ) {
				$this->shared_builder            = $this->build_state_builder( new MultiCurrencyLocalizationService(), new MultiCurrencyDatabaseCache() );
				$this->shared_builder_registrars = $registrars;
			}

			if ( false === has_action( 'updated_option', array( $this, 'handle_option_change' ) ) ) {
				add_action( 'added_option', array( $this, 'handle_option_change' ) );
				add_action( 'updated_option', array( $this, 'handle_option_change' ) );
				add_action( 'deleted_option', array( $this, 'handle_option_change' ) );
				add_action( 'set_current_user', array( $this->state_invalidator, 'invalidate' ) );
			}

			return $this->shared_builder;
		}

		return $this->build_state_builder( $localization_service ?? new MultiCurrencyLocalizationService(), $cache ?? new MultiCurrencyDatabaseCache() );
	}

	/**
	 * Drop the shared state when an option it reads changes.
	 *
	 * @internal
	 *
	 * @param mixed $option Option name.
	 */
	public function handle_option_change( $option ): void {
		if ( ! is_string( $option ) || MultiCurrencyCacheInterface::CURRENCIES_KEY === $option ) {
			return;
		}

		if ( 'woocommerce_currency' === $option || 0 === strpos( $option, 'wcpay_multi_currency_' ) ) {
			$this->state_invalidator->invalidate();
		}
	}

	/**
	 * Build a state builder over the given boundaries.
	 *
	 * @param MultiCurrencyLocalizationInterface $localization_service Localization boundary.
	 * @param MultiCurrencyCacheInterface        $cache                Cache boundary.
	 * @return MultiCurrencyStateBuilder
	 */
	private function build_state_builder( MultiCurrencyLocalizationInterface $localization_service, MultiCurrencyCacheInterface $cache ): MultiCurrencyStateBuilder {
		return new MultiCurrencyStateBuilder(
			$localization_service,
			new MultiCurrencyRateService( $this->provider_registry_factory->create() ),
			$cache,
			$this->state_invalidator
		);
	}
}
