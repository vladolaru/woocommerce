<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Enums\FeaturePluginCompatibility;
use Automattic\WooCommerce\Internal\Features\FeaturesController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyCacheInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
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

	/** @var int */
	private $previous_user_id;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION );
		delete_option( 'wcpay_multi_currency_enabled_currencies' );
		delete_transient( MultiCurrencyUsageDetector::HAS_MC_ORDERS_TRANSIENT );
		$this->previous_user_id = get_current_user_id();
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
		wp_set_current_user( $this->previous_user_id );
		$_GET = array();
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
	 * @testdox Should protect disabling for real posts-storage Multi-Currency metadata.
	 */
	public function test_get_feature_setting_disables_no_for_real_posts_storage_order(): void {
		global $wpdb;

		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$order_id = $this->factory->post->create( array( 'post_type' => 'shop_order' ) );
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $order_id,
				'meta_key'   => '_wcpay_multi_currency_order_exchange_rate',
				'meta_value' => '0.9',
			)
		);
		$detector = new MultiCurrencyUsageDetector();
		$detector->set_hpos_enabled_resolver( static fn(): bool => false );
		$this->sut = new MultiCurrencyFeatureController();
		$this->sut->init( $detector );
		delete_transient( MultiCurrencyUsageDetector::HAS_MC_ORDERS_TRANSIENT );

		$this->assertSame( array( 'no' ), $this->sut->get_feature_setting()['disabled']() );
	}

	/**
	 * @testdox Should protect disabling for real HPOS Multi-Currency metadata.
	 */
	public function test_get_feature_setting_disables_no_for_real_hpos_storage_order(): void {
		global $wpdb;

		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$order = wc_create_order();
		$wpdb->insert(
			"{$wpdb->prefix}wc_orders_meta",
			array(
				'order_id'   => $order->get_id(),
				'meta_key'   => '_wcpay_multi_currency_order_exchange_rate',
				'meta_value' => '0.9',
			)
		);
		$detector = new MultiCurrencyUsageDetector();
		$detector->set_hpos_enabled_resolver( static fn(): bool => true );
		$this->sut = new MultiCurrencyFeatureController();
		$this->sut->init( $detector );
		delete_transient( MultiCurrencyUsageDetector::HAS_MC_ORDERS_TRANSIENT );

		$this->assertSame( array( 'no' ), $this->sut->get_feature_setting()['disabled']() );
	}

	/**
	 * @testdox Should fail closed when historical order detection cannot query storage.
	 */
	public function test_get_feature_setting_disables_no_when_historical_order_query_fails(): void {
		global $wpdb;

		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$detector = new MultiCurrencyUsageDetector();
		$detector->set_hpos_enabled_resolver( static fn(): bool => false );
		$this->sut = new MultiCurrencyFeatureController();
		$this->sut->init( $detector );
		$original_postmeta = $wpdb->postmeta;
		$suppress_errors   = $wpdb->suppress_errors( true );
		$wpdb->postmeta    = "{$wpdb->prefix}missing_multi_currency_order_meta";

		try {
			$this->assertSame( array( 'no' ), $this->sut->get_feature_setting()['disabled']() );
		} finally {
			$wpdb->postmeta = $original_postmeta;
			$wpdb->suppress_errors( $suppress_errors );
		}
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

	/**
	 * @testdox Should confirm disabling through the core handler without removing Multi-Currency data.
	 */
	public function test_core_confirmation_disables_only_feature_option_and_preserves_multi_currency_data(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );
		update_option( 'wcpay_multi_currency_stored_customer_currencies', array( 12 => 'EUR' ) );
		$cached_currencies = array(
			'data'    => array(
				'currencies' => array( 'EUR' => '0.9' ),
				'updated'    => 1234567890,
			),
			'fetched' => 1234567890,
			'errored' => false,
		);
		update_option( MultiCurrencyCacheInterface::CURRENCIES_KEY, $cached_currencies );
		update_user_meta( $user_id, MultiCurrencyStateBuilder::CURRENCY_STORAGE_KEY, 'EUR' );
		update_option( 'wcpay_multi_currency_store_currency', 'USD' );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'automatic' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.9' );
		update_option( 'wcpay_multi_currency_price_rounding_eur', '0.01' );
		update_option( 'wcpay_multi_currency_price_charm_eur', '0.99' );
		update_option( 'wcpay_multi_currency_show_store_currency_changed_notice', 'yes' );
		update_option( 'wcpay_multi_currency_enable_auto_currency', 'yes' );
		update_option( 'wcpay_multi_currency_enable_storefront_switcher', 'no' );
		update_option( 'wcpay_multi_currency_rendering_mode', 'preview' );
		update_option( 'wcpay_multi_currency_setup_completed', 'yes' );
		$order = wc_create_order();
		$order->update_meta_data( '_wcpay_multi_currency_order_exchange_rate', '0.9' );
		$order->save();
		$features = wc_get_container()->get( FeaturesController::class );

		$this->assertNotNull( $features->get_feature_definition( MultiCurrencyFeatureController::FEATURE_ID ), 'The existing FeaturesController extension point must reach the feature controller.' );
		$_GET = array(
			'multi_currency' => '0',
			'_feature_nonce' => wp_create_nonce( 'change_feature_enable' ),
		);
		$features->change_feature_enable_from_query_params();

		$this->assertSame( 'no', get_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION ) );
		$this->assertSame( array( 'EUR' ), get_option( 'wcpay_multi_currency_enabled_currencies' ) );
		$this->assertSame( array( 12 => 'EUR' ), get_option( 'wcpay_multi_currency_stored_customer_currencies' ) );
		$this->assertSame( $cached_currencies, get_option( MultiCurrencyCacheInterface::CURRENCIES_KEY ) );
		$this->assertSame( 'EUR', get_user_meta( $user_id, MultiCurrencyStateBuilder::CURRENCY_STORAGE_KEY, true ) );
		$this->assertSame( 'USD', get_option( 'wcpay_multi_currency_store_currency' ) );
		$this->assertSame( 'automatic', get_option( 'wcpay_multi_currency_exchange_rate_eur' ) );
		$this->assertSame( '0.9', get_option( 'wcpay_multi_currency_manual_rate_eur' ) );
		$this->assertSame( '0.01', get_option( 'wcpay_multi_currency_price_rounding_eur' ) );
		$this->assertSame( '0.99', get_option( 'wcpay_multi_currency_price_charm_eur' ) );
		$this->assertSame( 'yes', get_option( 'wcpay_multi_currency_show_store_currency_changed_notice' ) );
		$this->assertSame( 'yes', get_option( 'wcpay_multi_currency_enable_auto_currency' ) );
		$this->assertSame( 'no', get_option( 'wcpay_multi_currency_enable_storefront_switcher' ) );
		$this->assertSame( 'preview', get_option( 'wcpay_multi_currency_rendering_mode' ) );
		$this->assertSame( 'yes', get_option( 'wcpay_multi_currency_setup_completed' ) );
		$this->assertSame( '0.9', $order->get_meta( '_wcpay_multi_currency_order_exchange_rate', true ) );
	}

	/**
	 * @testdox Should reject an invalid nonce through the core confirmation handler.
	 */
	public function test_core_confirmation_rejects_invalid_nonce(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$_GET = array(
			'multi_currency' => '0',
			'_feature_nonce' => 'invalid',
		);

		$this->expectException( \WPDieException::class );
		wc_get_container()->get( FeaturesController::class )->change_feature_enable_from_query_params();
	}

	/**
	 * @testdox Should leave the feature enabled when the core confirmation user lacks permission.
	 */
	public function test_core_confirmation_requires_manage_woocommerce_capability(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'customer' ) ) );
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$_GET = array(
			'multi_currency' => '0',
			'_feature_nonce' => wp_create_nonce( 'change_feature_enable' ),
		);

		wc_get_container()->get( FeaturesController::class )->change_feature_enable_from_query_params();

		$this->assertSame( 'yes', get_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION ) );
	}
}
