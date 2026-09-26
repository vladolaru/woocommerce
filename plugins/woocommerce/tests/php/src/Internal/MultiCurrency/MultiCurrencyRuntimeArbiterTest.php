<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Enums\FeaturePluginCompatibility;
use Automattic\WooCommerce\Internal\Features\FeaturesController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyRuntimeArbiter class.
 */
class MultiCurrencyRuntimeArbiterTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var MultiCurrencyRuntimeArbiter
	 */
	private $sut;

	/**
	 * Payments arbiter used by the system under test.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private $payments_arbiter;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'woocommerce_feature_multi_currency_enabled' );
		delete_option( 'woocommerce_native_payments_enabled' );
		$this->payments_arbiter = wc_get_container()->get( NativePaymentsRuntimeArbiter::class );
		$this->payments_arbiter->invalidate();
		$this->sut = wc_get_container()->get( MultiCurrencyRuntimeArbiter::class );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED );
		delete_option( 'woocommerce_feature_multi_currency_enabled' );
		delete_option( 'woocommerce_native_payments_enabled' );
		$this->payments_arbiter->invalidate();
		$this->reset_legacy_proxy_mocks();
		parent::tearDown();
	}

	/**
	 * @testdox Should register the visible, stable multi-currency feature as disabled by default.
	 */
	public function test_registers_multi_currency_feature_definition(): void {
		$features_controller = $this->createMock( FeaturesController::class );
		$features_controller
			->expects( $this->once() )
			->method( 'add_feature_definition' )
			->with(
				'multi_currency',
				'Multi-currency',
				$this->callback(
					function ( array $definition ): bool {
						return 'woocommerce_feature_multi_currency_enabled' === $definition['option_key']
							&& 'Let customers shop and pay in their own currency.' === $definition['description']
							&& false === $definition['enabled_by_default']
							&& false === $definition['disable_ui']
							&& false === $definition['is_experimental']
							&& FeaturePluginCompatibility::COMPATIBLE === $definition['default_plugin_compatibility']
							&& isset( $definition['setting'] );
					}
				)
			);

		$this->sut->add_feature_definition( $features_controller );
	}

	/**
	 * @testdox Should leave core multi-currency disabled by default on new installs.
	 */
	public function test_core_multi_currency_is_disabled_by_default(): void {
		$this->assertFalse( $this->sut->feature_is_enabled() );
	}

	/**
	 * Control every WooPayments-plugin detection signal in a single mock registration.
	 *
	 * @param bool $in_list      Whether the plugin is in the per-site active-plugins list.
	 * @param bool $network      Whether the plugin is in the network active-sitewide-plugins list.
	 * @param bool $class_loaded Whether the WC_Payments bootstrap class is loaded.
	 * @param bool $multi_currency_enabled Whether the WooPayments customer multi-currency feature is enabled.
	 */
	private function fake_plugin( bool $in_list = false, bool $network = false, bool $class_loaded = false, bool $multi_currency_enabled = true ): void {
		$entry = NativePaymentsRuntimeArbiter::PLUGIN_FILE;
		$this->register_legacy_proxy_function_mocks(
			array(
				'get_option'      => function ( $name, $default_value = false ) use ( $in_list, $entry, $multi_currency_enabled ) {
					if ( 'active_plugins' === $name ) {
						return $in_list ? array( $entry ) : array();
					}
					if ( '_wcpay_feature_customer_multi_currency' === $name ) {
						return $multi_currency_enabled ? '1' : '0';
					}
					return get_option( $name, $default_value );
				},
				'get_site_option' => function ( $name, $default_value = false ) use ( $network, $entry ) {
					if ( 'active_sitewide_plugins' === $name ) {
						return $network ? array( $entry => 1234567890 ) : array();
					}
					return get_site_option( $name, $default_value );
				},
				'class_exists'    => function ( $class_name, $autoload = true ) use ( $class_loaded ) {
					if ( 'WC_Payments' === ltrim( (string) $class_name, '\\' ) ) {
						return $class_loaded;
					}
					return class_exists( $class_name, $autoload );
				},
			)
		);
		$this->payments_arbiter->invalidate();
	}

	/**
	 * Enable the native runtime feature flag.
	 */
	private function enable_native_runtime(): void {
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
	}

	/**
	 * @testdox Should keep plugin multi-currency ownership while the WooPayments plugin owns payments.
	 */
	public function test_plugin_payments_owner_keeps_plugin_multi_currency_owner(): void {
		$this->fake_plugin( true );

		$this->assertSame( MultiCurrencyRuntimeArbiter::OWNER_PLUGIN, $this->sut->get_runtime_owner(), 'Plugin payments ownership should also own multi-currency.' );
		$this->assertTrue( $this->sut->should_plugin_register(), 'Plugin multi-currency should remain responsible for price filters in plugin mode.' );
		$this->assertFalse( $this->sut->should_core_register(), 'Core multi-currency must not register price filters in plugin mode.' );
	}

	/**
	 * @testdox Should leave multi-currency unowned when the plugin disables customer multi-currency.
	 */
	public function test_plugin_payments_owner_with_disabled_customer_multi_currency_leaves_multi_currency_unowned(): void {
		$this->fake_plugin( true, false, false, false );

		$this->assertSame( MultiCurrencyRuntimeArbiter::OWNER_NONE, $this->sut->get_runtime_owner(), 'Plugin payments ownership alone should not imply plugin multi-currency ownership.' );
		$this->assertFalse( $this->sut->should_plugin_register(), 'Plugin multi-currency should not register when the WooPayments feature is disabled.' );
		$this->assertFalse( $this->sut->should_core_register(), 'Core multi-currency must not register while the plugin still owns payments.' );
	}

	/**
	 * @testdox Should keep plugin multi-currency ownership when native payments is enabled but the plugin remains active.
	 */
	public function test_plugin_wins_multi_currency_when_native_payments_enabled(): void {
		$this->fake_plugin( true );
		$this->enable_native_runtime();

		$this->assertSame( MultiCurrencyRuntimeArbiter::OWNER_PLUGIN, $this->sut->get_runtime_owner(), 'Plugin-wins must avoid split-brain multi-currency ownership.' );
		$this->assertFalse( $this->sut->should_core_register(), 'Core multi-currency must not register while the plugin remains active.' );
	}

	/**
	 * @testdox Should use core multi-currency when native payments owns the site.
	 */
	public function test_native_payments_owner_uses_core_multi_currency_owner(): void {
		$this->fake_plugin();
		$this->enable_native_runtime();
		$this->enable_core_multi_currency();

		$this->assertSame( MultiCurrencyRuntimeArbiter::OWNER_CORE, $this->sut->get_runtime_owner(), 'Native payments ownership should flip multi-currency to core.' );
		$this->assertTrue( $this->sut->should_core_register(), 'Core multi-currency may register only in native payments mode.' );
		$this->assertFalse( $this->sut->should_plugin_register(), 'Plugin multi-currency should not own the price pipeline in native mode.' );
	}

	/**
	 * @testdox Should use the core feature flag when native payments owns the site, independently of the plugin flag.
	 */
	public function test_native_payments_owner_ignores_disabled_plugin_multi_currency_flag(): void {
		$this->fake_plugin( false, false, false, false );
		$this->enable_native_runtime();
		$this->enable_core_multi_currency();

		$this->assertSame( MultiCurrencyRuntimeArbiter::OWNER_CORE, $this->sut->get_runtime_owner(), 'The plugin flag must not override the independent core feature setting.' );
		$this->assertTrue( $this->sut->should_core_register(), 'Core multi-currency should register when its core feature is enabled.' );
		$this->assertFalse( $this->sut->should_plugin_register(), 'Plugin multi-currency must not register in native mode either.' );
	}

	/**
	 * @testdox Should leave multi-currency unowned when its core feature is disabled.
	 */
	public function test_native_payments_owner_with_disabled_core_feature_leaves_multi_currency_unowned(): void {
		$this->fake_plugin();
		$this->enable_native_runtime();

		$this->assertSame( MultiCurrencyRuntimeArbiter::OWNER_NONE, $this->sut->get_runtime_owner(), 'Native payments ownership must not imply multi-currency enablement.' );
		$this->assertFalse( $this->sut->should_core_register(), 'Core multi-currency must remain dormant until its own feature is enabled.' );
	}

	/**
	 * @testdox Should leave multi-currency unowned when no payments runtime owns the site.
	 */
	public function test_no_payments_owner_leaves_multi_currency_unowned(): void {
		$this->fake_plugin();

		$this->assertSame( MultiCurrencyRuntimeArbiter::OWNER_NONE, $this->sut->get_runtime_owner(), 'Without a payments owner, core multi-currency should stay dormant.' );
		$this->assertFalse( $this->sut->should_core_register(), 'Core multi-currency must not register without native payments ownership.' );
		$this->assertFalse( $this->sut->should_plugin_register(), 'Plugin multi-currency is absent when the plugin is absent.' );
	}

	/**
	 * Enable the core multi-currency feature.
	 */
	private function enable_core_multi_currency(): void {
		update_option( 'woocommerce_feature_multi_currency_enabled', 'yes' );
	}
}
