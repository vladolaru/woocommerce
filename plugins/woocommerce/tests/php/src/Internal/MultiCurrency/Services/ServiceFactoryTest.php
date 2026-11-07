<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\AnalyticsService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\DisplayService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\GeolocationService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\PriceConverter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\SelectedCurrencyPersistenceService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\ServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\SettingsCurrencyCatalog;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StoreCurrencyLifecycleService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\SwitcherRenderer;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\TrackerDataBuilder;
use WC_Unit_Test_Case;

/**
 * Tests for the ServiceFactory class.
 */
class ServiceFactoryTest extends WC_Unit_Test_Case {

	/**
	 * Each creator and the class it builds.
	 *
	 * @return array<string,array{string,class-string}>
	 */
	public static function creators(): array {
		return array(
			'price converter'               => array( 'create_price_converter', PriceConverter::class ),
			'display service'               => array( 'create_display_service', DisplayService::class ),
			'geolocation'                   => array( 'create_geolocation_service', GeolocationService::class ),
			'selected-currency persistence' => array( 'create_selected_currency_persistence_service', SelectedCurrencyPersistenceService::class ),
			'switcher renderer'             => array( 'create_switcher_renderer', SwitcherRenderer::class ),
			'analytics service'             => array( 'create_analytics_service', AnalyticsService::class ),
			'tracker data builder'          => array( 'create_tracker_data_builder', TrackerDataBuilder::class ),
			'store-currency lifecycle'      => array( 'create_store_currency_lifecycle_service', StoreCurrencyLifecycleService::class ),
			'settings currency catalog'     => array( 'create_settings_currency_catalog', SettingsCurrencyCatalog::class ),
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
		$sut = wc_get_container()->get( ServiceFactory::class );

		$this->assertInstanceOf( $class_name, $sut->$creator() );
	}
}
