<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\Features\FeaturesController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Proxies\LegacyProxy;
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
	 * @var WooPaymentsRuntimeArbiter
	 */
	private $payments_arbiter;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( 'woocommerce_feature_multi_currency_enabled' );
		$this->payments_arbiter = wc_get_container()->get( WooPaymentsRuntimeArbiter::class );
		$this->payments_arbiter->invalidate();
		$this->sut = wc_get_container()->get( MultiCurrencyRuntimeArbiter::class );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_feature_multi_currency_enabled' );
		$this->payments_arbiter->invalidate();
		$this->reset_legacy_proxy_mocks();
		parent::tearDown();
	}

	/**
	 * @testdox Should leave core multi-currency disabled by default on new installs.
	 */
	public function test_core_multi_currency_is_disabled_by_default(): void {
		$this->assertFalse( $this->sut->feature_is_enabled() );
	}

	/**
	 * @testdox Should read the feature option without building the feature definitions, since ownership is decided before init.
	 */
	public function test_feature_check_does_not_build_feature_definitions(): void {
		$features_controller = $this->createMock( FeaturesController::class );
		$features_controller->expects( $this->never() )->method( 'feature_is_enabled' );
		wc_get_container()->replace( FeaturesController::class, $features_controller );

		try {
			$sut = new MultiCurrencyRuntimeArbiter();
			$sut->init(
				$this->payments_arbiter,
				wc_get_container()->get( LegacyProxy::class )
			);

			$this->assertFalse( $sut->feature_is_enabled() );
			$this->enable_core_multi_currency();
			$this->assertTrue( $sut->feature_is_enabled() );
		} finally {
			wc_get_container()->reset_replacement( FeaturesController::class );
		}
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
		$entry = WooPaymentsRuntimeArbiter::PLUGIN_FILE;
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
	private function enable_woopayments_feature(): void {
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
	}

	/**
	 * @testdox Should resolve the multi-currency runtime owner and registrations from the payments state.
	 * @dataProvider runtime_owner_provider
	 *
	 * @param bool   $extension_active                 Whether the WooPayments plugin is active.
	 * @param bool   $extension_multi_currency_enabled Whether the plugin's customer multi-currency feature is enabled.
	 * @param bool   $woopayments_feature              Whether the WooPayments feature is enabled.
	 * @param bool   $core_multi_currency              Whether the core multi-currency feature is enabled.
	 * @param string $owner                            Expected runtime owner.
	 * @param bool   $core_registers                   Whether core multi-currency should register.
	 * @param bool   $extension_registers              Whether plugin multi-currency should register.
	 * @param bool   $builtin_owner                    Whether the built-in WooPayments owns payments.
	 */
	public function test_runtime_owner_by_state( bool $extension_active, bool $extension_multi_currency_enabled, bool $woopayments_feature, bool $core_multi_currency, string $owner, bool $core_registers, bool $extension_registers, bool $builtin_owner ): void {
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, $woopayments_feature ? 'yes' : 'no' );
		$this->fake_plugin( $extension_active, false, false, $extension_multi_currency_enabled );
		if ( $core_multi_currency ) {
			$this->enable_core_multi_currency();
		}

		$this->assertSame( $owner, $this->sut->get_runtime_owner(), 'Runtime owner.' );
		$this->assertSame( $core_registers, $this->sut->is_builtin_owner(), 'Core registration.' );
		$this->assertSame( $extension_registers, $this->sut->is_extension_owner(), 'Plugin registration.' );
		$this->assertSame( $builtin_owner, $this->sut->is_payments_builtin_owner(), 'Built-in payments owner.' );
	}

	/**
	 * Provide runtime owner rows: plugin active, plugin multi-currency flag, WooPayments feature, core multi-currency
	 * feature, expected owner, core registers, plugin registers, built-in payments owner.
	 *
	 * @return array<string,array{0:bool,1:bool,2:bool,3:bool,4:string,5:bool,6:bool,7:bool}>
	 */
	public function runtime_owner_provider(): array {
		return array(
			'the extension owns payments'       => array( true, true, false, false, MultiCurrencyRuntimeArbiter::OWNER_EXTENSION, false, true, false ),
			'the extension disables customer multi-currency' => array( true, false, false, false, MultiCurrencyRuntimeArbiter::OWNER_NONE, false, false, false ),
			'the built-in owns payments and core is enabled' => array( false, true, true, true, MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true, false, true ),
			'the extension flag is disabled under the built-in' => array( false, false, true, true, MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true, false, true ),
			'no payments runtime owns the site' => array( false, true, false, false, MultiCurrencyRuntimeArbiter::OWNER_NONE, false, false, false ),
			'the extension stays owner while the WooPayments feature is on' => array( true, true, true, false, MultiCurrencyRuntimeArbiter::OWNER_EXTENSION, false, true, false ),
			'the built-in owns payments and core is disabled' => array( false, true, true, false, MultiCurrencyRuntimeArbiter::OWNER_NONE, false, false, true ),
			'core runs on its feature alone with no payments owner' => array( false, false, false, true, MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true, false, false ),
		);
	}

	/**
	 * @testdox Should report which WooPayments runtime owns payments, as the payments arbiter resolves it.
	 */
	public function test_reports_the_payments_owner(): void {
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'no' );
		$this->payments_arbiter->invalidate();
		$this->fake_plugin( true );
		$this->assertTrue( $this->sut->is_payments_extension_owner() );
		$this->assertFalse( $this->sut->is_payments_builtin_owner() );

		$this->reset_legacy_proxy_mocks();
		$this->payments_arbiter->invalidate();
		$this->fake_plugin();
		$this->assertFalse( $this->sut->is_payments_extension_owner() );
		$this->assertFalse( $this->sut->is_payments_builtin_owner() );

		$this->payments_arbiter->invalidate();
		$this->enable_woopayments_feature();
		$this->assertFalse( $this->sut->is_payments_extension_owner() );
		$this->assertTrue( $this->sut->is_payments_builtin_owner() );
	}

	/**
	 * Enable the core multi-currency feature.
	 */
	private function enable_core_multi_currency(): void {
		update_option( 'woocommerce_feature_multi_currency_enabled', 'yes' );
	}
}
