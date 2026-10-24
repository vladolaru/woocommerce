<?php
/**
 * MultiCurrencyServiceFactory class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

/**
 * Builds the Multi-Currency services the controllers use, on the request's shared state builder.
 *
 * Services are built on first use rather than injected, because the localization service reads two transients and a PHP
 * file when it is constructed.
 *
 * @since 11.0.0
 * @internal
 */
class MultiCurrencyServiceFactory {

	/**
	 * State builder factory.
	 *
	 * @var MultiCurrencyStateBuilderFactory
	 */
	private MultiCurrencyStateBuilderFactory $state_builder_factory;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyStateBuilderFactory $state_builder_factory State builder factory.
	 */
	final public function init( MultiCurrencyStateBuilderFactory $state_builder_factory ): void {
		$this->state_builder_factory = $state_builder_factory;
	}

	/**
	 * Create a price projection service.
	 *
	 * @return MultiCurrencyPriceProjectionService
	 */
	public function create_price_projection_service(): MultiCurrencyPriceProjectionService {
		return new MultiCurrencyPriceProjectionService(
			$this->state_builder_factory->create(),
			new MultiCurrencyPriceCalculator( new MultiCurrencyLocalizationService() )
		);
	}

	/**
	 * Create a frontend projection service.
	 *
	 * @return MultiCurrencyFrontendProjectionService
	 */
	public function create_frontend_projection_service(): MultiCurrencyFrontendProjectionService {
		$localization_service = new MultiCurrencyLocalizationService();

		return new MultiCurrencyFrontendProjectionService(
			$this->state_builder_factory->create(),
			$localization_service,
			new MultiCurrencyGeolocationService( $localization_service )
		);
	}

	/**
	 * Create a geolocation service.
	 *
	 * @return MultiCurrencyGeolocationService
	 */
	public function create_geolocation_service(): MultiCurrencyGeolocationService {
		return new MultiCurrencyGeolocationService( new MultiCurrencyLocalizationService() );
	}

	/**
	 * Create a selected-currency persistence service.
	 *
	 * @return MultiCurrencySelectedCurrencyPersistenceService
	 */
	public function create_selected_currency_persistence_service(): MultiCurrencySelectedCurrencyPersistenceService {
		return new MultiCurrencySelectedCurrencyPersistenceService( $this->state_builder_factory->create() );
	}

	/**
	 * Create a switcher projection service.
	 *
	 * @return MultiCurrencySwitcherProjectionService
	 */
	public function create_switcher_projection_service(): MultiCurrencySwitcherProjectionService {
		return new MultiCurrencySwitcherProjectionService( $this->state_builder_factory->create() );
	}

	/**
	 * Create an analytics projection service.
	 *
	 * @return MultiCurrencyAnalyticsProjectionService
	 */
	public function create_analytics_projection_service(): MultiCurrencyAnalyticsProjectionService {
		return new MultiCurrencyAnalyticsProjectionService( $this->state_builder_factory->create() );
	}

	/**
	 * Create a tracking projection service.
	 *
	 * @return MultiCurrencyTrackingProjectionService
	 */
	public function create_tracking_projection_service(): MultiCurrencyTrackingProjectionService {
		return new MultiCurrencyTrackingProjectionService( $this->state_builder_factory->create() );
	}

	/**
	 * Create a store-currency lifecycle service.
	 *
	 * @return MultiCurrencyStoreCurrencyLifecycleService
	 */
	public function create_store_currency_lifecycle_service(): MultiCurrencyStoreCurrencyLifecycleService {
		return new MultiCurrencyStoreCurrencyLifecycleService(
			new MultiCurrencyDatabaseCache(),
			$this->state_builder_factory->create()
		);
	}
}
