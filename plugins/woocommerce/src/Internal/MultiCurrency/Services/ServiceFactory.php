<?php
/**
 * ServiceFactory class file.
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
class ServiceFactory {

	/**
	 * State builder factory.
	 *
	 * @var StateBuilderFactory
	 */
	private StateBuilderFactory $state_builder_factory;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param StateBuilderFactory $state_builder_factory State builder factory.
	 */
	final public function init( StateBuilderFactory $state_builder_factory ): void {
		$this->state_builder_factory = $state_builder_factory;
	}

	/**
	 * Create a price converter.
	 *
	 * @return PriceConverter
	 */
	public function create_price_converter(): PriceConverter {
		return new PriceConverter(
			$this->state_builder_factory->get_state_builder(),
			new PriceCalculator( new LocalizationService() )
		);
	}

	/**
	 * Create a display service.
	 *
	 * @return DisplayService
	 */
	public function create_display_service(): DisplayService {
		$localization_service = new LocalizationService();

		return new DisplayService(
			$this->state_builder_factory->get_state_builder(),
			$localization_service,
			new GeolocationService( $localization_service )
		);
	}

	/**
	 * Create a geolocation service.
	 *
	 * @return GeolocationService
	 */
	public function create_geolocation_service(): GeolocationService {
		return new GeolocationService( new LocalizationService() );
	}

	/**
	 * Create a selected-currency persistence service.
	 *
	 * @return SelectedCurrencyPersistenceService
	 */
	public function create_selected_currency_persistence_service(): SelectedCurrencyPersistenceService {
		return new SelectedCurrencyPersistenceService( $this->state_builder_factory->get_state_builder() );
	}

	/**
	 * Create a switcher renderer.
	 *
	 * @return SwitcherRenderer
	 */
	public function create_switcher_renderer(): SwitcherRenderer {
		return new SwitcherRenderer( $this->state_builder_factory->get_state_builder() );
	}

	/**
	 * Create an Analytics service.
	 *
	 * @return AnalyticsService
	 */
	public function create_analytics_service(): AnalyticsService {
		return new AnalyticsService( $this->state_builder_factory->get_state_builder() );
	}

	/**
	 * Create a tracker data builder.
	 *
	 * @return TrackerDataBuilder
	 */
	public function create_tracker_data_builder(): TrackerDataBuilder {
		return new TrackerDataBuilder( $this->state_builder_factory->get_state_builder() );
	}

	/**
	 * Create the settings currency catalog, over the rate service that holds the automatic-rate provider.
	 *
	 * @return SettingsCurrencyCatalog
	 */
	public function create_settings_currency_catalog(): SettingsCurrencyCatalog {
		return new SettingsCurrencyCatalog(
			new LocalizationService(),
			$this->state_builder_factory->get_state_builder(),
			wc_get_container()->get( RateService::class )
		);
	}

	/**
	 * Create a store-currency lifecycle service.
	 *
	 * @return StoreCurrencyLifecycleService
	 */
	public function create_store_currency_lifecycle_service(): StoreCurrencyLifecycleService {
		return new StoreCurrencyLifecycleService(
			new DatabaseCache(),
			$this->state_builder_factory->get_state_builder()
		);
	}
}
