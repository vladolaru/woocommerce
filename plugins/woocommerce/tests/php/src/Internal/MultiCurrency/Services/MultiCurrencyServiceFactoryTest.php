<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyAnalyticsService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyDisplayService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyGeolocationService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyPriceConverter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencySelectedCurrencyPersistenceService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencySettingsCurrencyCatalog;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStoreCurrencyLifecycleService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencySwitcherRenderer;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyTrackerDataBuilder;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyServiceFactory class.
 */
class MultiCurrencyServiceFactoryTest extends WC_Unit_Test_Case {

	/**
	 * Each creator and the class it builds.
	 *
	 * @return array<string,array{string,class-string}>
	 */
	public static function creators(): array {
		return array(
			'price projection'              => array( 'create_price_converter', MultiCurrencyPriceConverter::class ),
			'frontend projection'           => array( 'create_display_service', MultiCurrencyDisplayService::class ),
			'geolocation'                   => array( 'create_geolocation_service', MultiCurrencyGeolocationService::class ),
			'selected-currency persistence' => array( 'create_selected_currency_persistence_service', MultiCurrencySelectedCurrencyPersistenceService::class ),
			'switcher projection'           => array( 'create_switcher_renderer', MultiCurrencySwitcherRenderer::class ),
			'analytics projection'          => array( 'create_analytics_service', MultiCurrencyAnalyticsService::class ),
			'tracking projection'           => array( 'create_tracker_data_builder', MultiCurrencyTrackerDataBuilder::class ),
			'store-currency lifecycle'      => array( 'create_store_currency_lifecycle_service', MultiCurrencyStoreCurrencyLifecycleService::class ),
			'settings currency catalog'     => array( 'create_settings_currency_catalog', MultiCurrencySettingsCurrencyCatalog::class ),
		);
	}

	/**
	 * @testdox Should create each service: $creator.
	 *
	 * @dataProvider creators
	 *
	 * @param string       $creator    Creator method.
	 * @param class-string $class_name Class the creator builds.
	 */
	public function test_creates_each_service( string $creator, string $class_name ): void {
		$sut = wc_get_container()->get( MultiCurrencyServiceFactory::class );

		$this->assertInstanceOf( $class_name, $sut->$creator() );
	}
}
