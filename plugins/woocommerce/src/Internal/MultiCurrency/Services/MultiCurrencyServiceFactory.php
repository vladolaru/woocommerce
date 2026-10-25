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
	 * @return MultiCurrencyPriceConverter
	 */
	public function create_price_converter(): MultiCurrencyPriceConverter {
		return new MultiCurrencyPriceConverter(
			$this->state_builder_factory->create(),
			new MultiCurrencyPriceCalculator( new MultiCurrencyLocalizationService() )
		);
	}

	/**
	 * Create a frontend projection service.
	 *
	 * @return MultiCurrencyDisplayService
	 */
	public function create_display_service(): MultiCurrencyDisplayService {
		$localization_service = new MultiCurrencyLocalizationService();

		return new MultiCurrencyDisplayService(
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
	 * @return MultiCurrencySwitcherRenderer
	 */
	public function create_switcher_renderer(): MultiCurrencySwitcherRenderer {
		return new MultiCurrencySwitcherRenderer( $this->state_builder_factory->create() );
	}

	/**
	 * Create an analytics projection service.
	 *
	 * @return MultiCurrencyAnalyticsService
	 */
	public function create_analytics_service(): MultiCurrencyAnalyticsService {
		return new MultiCurrencyAnalyticsService( $this->state_builder_factory->create() );
	}

	/**
	 * Create a tracking projection service.
	 *
	 * @return MultiCurrencyTrackerDataBuilder
	 */
	public function create_tracker_data_builder(): MultiCurrencyTrackerDataBuilder {
		return new MultiCurrencyTrackerDataBuilder( $this->state_builder_factory->create() );
	}

	/**
	 * Create the settings currency catalog, over the rate service that holds the automatic-rate provider.
	 *
	 * @return MultiCurrencySettingsCurrencyCatalog
	 */
	public function create_settings_currency_catalog(): MultiCurrencySettingsCurrencyCatalog {
		return new MultiCurrencySettingsCurrencyCatalog(
			new MultiCurrencyLocalizationService(),
			$this->state_builder_factory->create(),
			wc_get_container()->get( MultiCurrencyRateService::class )
		);
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
