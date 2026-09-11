<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Enums\FeaturePluginCompatibility;
use Automattic\WooCommerce\Internal\Features\FeaturesController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyUsageDetector;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyFeatureController class.
 */
class MultiCurrencyFeatureControllerTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var MultiCurrencyFeatureController
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION );
		delete_option( 'wcpay_multi_currency_enabled_currencies' );
		delete_transient( MultiCurrencyUsageDetector::HAS_MC_ORDERS_TRANSIENT );
		$this->sut = new MultiCurrencyFeatureController();
		$this->sut->init( new MultiCurrencyUsageDetector() );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		delete_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION );
		delete_option( 'wcpay_multi_currency_enabled_currencies' );
		delete_transient( MultiCurrencyUsageDetector::HAS_MC_ORDERS_TRANSIENT );
		parent::tear_down();
	}

	/**
	 * @testdox Should register the stable Multi-Currency feature with its dynamic radio setting.
	 */
	public function test_add_feature_definition_registers_dynamic_radio_setting(): void {
		$features_controller = $this->createMock( FeaturesController::class );
		$features_controller
			->expects( $this->once() )
			->method( 'add_feature_definition' )
			->with(
				MultiCurrencyFeatureController::FEATURE_ID,
				'Multi-currency',
				$this->callback(
					function ( array $definition ): bool {
						return MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION === $definition['option_key']
							&& false === $definition['enabled_by_default']
							&& false === $definition['disable_ui']
							&& false === $definition['is_experimental']
							&& FeaturePluginCompatibility::COMPATIBLE === $definition['default_plugin_compatibility']
							&& 'radio' === $definition['setting']['type']
							&& is_callable( $definition['setting']['value'] )
							&& is_callable( $definition['setting']['disabled'] )
							&& is_callable( $definition['setting']['desc'] );
					}
				)
			);

		$this->sut->add_feature_definition( $features_controller );
	}

	/**
	 * @testdox Should leave normal yes and no choices available when the feature has no persisted usage.
	 */
	public function test_get_feature_setting_allows_unprotected_values(): void {
		$setting = $this->sut->get_feature_setting();

		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$this->assertSame( 'yes', $setting['value']() );
		$this->assertSame( array(), $setting['disabled']() );

		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'no' );
		$this->assertSame( 'no', $setting['value']() );
		$this->assertSame( array(), $setting['disabled']() );
	}

	/**
	 * @testdox Should protect disabling when a non-default configured currency exists.
	 */
	public function test_get_feature_setting_disables_no_for_configured_currency(): void {
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );

		$setting = $this->sut->get_feature_setting();

		$this->assertSame( array( 'no' ), $setting['disabled']() );
		$this->assertStringContainsString(
			'Disabling Multi-Currency stops currency switching but keeps your currency settings and order data.',
			$setting['desc']()
		);
		$this->assertStringContainsString( '>Disable Multi-Currency<', $setting['desc']() );
	}

	/**
	 * @testdox Should protect disabling when historical Multi-Currency orders exist.
	 */
	public function test_get_feature_setting_disables_no_for_historical_orders(): void {
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		set_transient( MultiCurrencyUsageDetector::HAS_MC_ORDERS_TRANSIENT, '1', HOUR_IN_SECONDS );

		$this->assertSame( array( 'no' ), $this->sut->get_feature_setting()['disabled']() );
	}

	/**
	 * @testdox Should build a core change-feature URL with a valid nonce.
	 */
	public function test_get_feature_setting_builds_disable_anyway_url(): void {
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );

		$description = $this->sut->get_feature_setting()['desc']();
		preg_match( '/href="([^"]+)"/', $description, $matches );
		$url = html_entity_decode( $matches[1] ?? '' );

		$this->assertSame( '0', wp_parse_url( $url, PHP_URL_QUERY ) ? wp_parse_args( wp_parse_url( $url, PHP_URL_QUERY ) )['multi_currency'] : null );
		$this->assertTrue( false !== wp_verify_nonce( wp_parse_args( wp_parse_url( $url, PHP_URL_QUERY ) )['_feature_nonce'], 'change_feature_enable' ) );
	}

	/**
	 * @testdox Should leave preserved Multi-Currency settings and order data intact during programmatic disable.
	 */
	public function test_programmatic_disable_preserves_multi_currency_data(): void {
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );
		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->update_meta_data( '_wcpay_multi_currency_order_exchange_rate', '0.9' );
		$order->save();

		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'no' );

		$this->assertSame( 'no', get_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION ) );
		$this->assertSame( array( 'EUR' ), get_option( 'wcpay_multi_currency_enabled_currencies' ) );
		$this->assertSame( '0.9', $order->get_meta( '_wcpay_multi_currency_order_exchange_rate', true ) );
	}
}
