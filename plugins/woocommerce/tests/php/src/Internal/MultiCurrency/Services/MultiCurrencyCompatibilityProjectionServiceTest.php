<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyCompatibilityProjectionService;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyCompatibilityProjectionService class.
 */
class MultiCurrencyCompatibilityProjectionServiceTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should project compatibility integrations when multiple currencies are enabled.
	 */
	public function test_projects_compatibility_integrations_when_multiple_currencies_are_enabled(): void {
		$this->assertSame(
			array(
				'WooCommerceBookings',
				'WooCommerceFedEx',
				'WooCommerceNameYourPrice',
				'WooCommercePreOrders',
				'WooCommerceProductAddOns',
				'WooCommerceSubscriptions',
				'WooCommerceUPS',
				'WooCommerceDeposits',
				'WooCommercePointsAndRewards',
			),
			MultiCurrencyCompatibilityProjectionService::get_compatibility_integrations( true )
		);
		$this->assertSame( array(), MultiCurrencyCompatibilityProjectionService::get_compatibility_integrations( false ) );
	}

	/**
	 * @testdox Should project switching disable reasons from explicit inputs.
	 */
	public function test_projects_switching_disable_reasons_from_explicit_inputs(): void {
		$this->assertSame(
			array( 'pay_for_order' ),
			MultiCurrencyCompatibilityProjectionService::get_switching_disable_reasons( array( 'pay_for_order' => 'true' ) )
		);
		$this->assertSame(
			array( 'subscription_context' ),
			MultiCurrencyCompatibilityProjectionService::get_switching_disable_reasons( array(), true )
		);
		$this->assertSame(
			array( 'external_filter' ),
			MultiCurrencyCompatibilityProjectionService::get_switching_disable_reasons( array(), false, true )
		);
		$this->assertSame(
			array( 'pay_for_order', 'subscription_context', 'external_filter' ),
			MultiCurrencyCompatibilityProjectionService::get_switching_disable_reasons( array( 'pay_for_order' => '1' ), true, true )
		);
	}

	/**
	 * @testdox Should project switching disabled boolean from reason presence.
	 */
	public function test_projects_switching_disabled_boolean_from_reason_presence(): void {
		$this->assertFalse( MultiCurrencyCompatibilityProjectionService::should_disable_currency_switching() );
		$this->assertTrue( MultiCurrencyCompatibilityProjectionService::should_disable_currency_switching( array( 'pay_for_order' => '1' ) ) );
		$this->assertTrue( MultiCurrencyCompatibilityProjectionService::should_disable_currency_switching( array(), true ) );
		$this->assertTrue( MultiCurrencyCompatibilityProjectionService::should_disable_currency_switching( array(), false, true ) );
	}
}
