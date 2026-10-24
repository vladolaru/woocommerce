<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyAnalyticsProjectionService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyFrontendProjectionService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyGeolocationService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyPriceProjectionService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencySelectedCurrencyPersistenceService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStoreCurrencyLifecycleService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencySwitcherProjectionService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyTrackingProjectionService;
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
			'price projection'              => array( 'create_price_projection_service', MultiCurrencyPriceProjectionService::class ),
			'frontend projection'           => array( 'create_frontend_projection_service', MultiCurrencyFrontendProjectionService::class ),
			'geolocation'                   => array( 'create_geolocation_service', MultiCurrencyGeolocationService::class ),
			'selected-currency persistence' => array( 'create_selected_currency_persistence_service', MultiCurrencySelectedCurrencyPersistenceService::class ),
			'switcher projection'           => array( 'create_switcher_projection_service', MultiCurrencySwitcherProjectionService::class ),
			'analytics projection'          => array( 'create_analytics_projection_service', MultiCurrencyAnalyticsProjectionService::class ),
			'tracking projection'           => array( 'create_tracking_projection_service', MultiCurrencyTrackingProjectionService::class ),
			'store-currency lifecycle'      => array( 'create_store_currency_lifecycle_service', MultiCurrencyStoreCurrencyLifecycleService::class ),
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
