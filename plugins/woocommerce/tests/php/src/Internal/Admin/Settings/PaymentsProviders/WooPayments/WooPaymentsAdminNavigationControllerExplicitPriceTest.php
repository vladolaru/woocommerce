<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Settings\PaymentsProviders\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsAdminNavigationController;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsOnboardingRedirect;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminMenuBadgeService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsApplePayDomainService;
use WC_Unit_Test_Case;

/**
 * Tests for the `shouldUseExplicitPrice` flag the WooPayments admin navigation controller preloads.
 *
 * Source: plugin 11.1.0 `class-wc-payments-admin.php:1040` and
 * `class-wc-payments-explicit-price-formatter.php:55-74,167-190`.
 */
class WooPaymentsAdminNavigationControllerExplicitPriceTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsAdminNavigationController
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		update_option( 'woocommerce_currency', 'USD' );

		$arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( true );

		$this->sut = new WooPaymentsAdminNavigationController();
		$this->sut->init(
			$arbiter,
			$this->createMock( WooPaymentsAccountService::class ),
			$this->createMock( WooPaymentsAdminMenuBadgeService::class ),
			$this->createMock( WooPaymentsApplePayDomainService::class ),
			$this->createMock( WooPaymentsOnboardingRedirect::class )
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		try {
			unset( $_GET['page'], $_GET['tab'] );
			$this->reset_container_replacements();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Should preload shouldUseExplicitPrice as true when Multi-Currency is on with an additional enabled currency.
	 */
	public function test_preloads_explicit_price_flag_on_with_an_additional_currency(): void {
		$this->set_multi_currency_enabled( true );
		$this->enable_manual_rate_currency( 'EUR', '0.9' );

		$settings = $this->sut->preload_shared_settings( array() );

		$this->assertTrue( $settings['woopaymentsSettings']['shouldUseExplicitPrice'], 'A second enabled currency should turn on explicit currency codes.' );
	}

	/**
	 * @testdox Should preload shouldUseExplicitPrice as false when Multi-Currency is on with only the store currency.
	 */
	public function test_preloads_explicit_price_flag_off_with_only_the_store_currency(): void {
		$this->set_multi_currency_enabled( true );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'USD' ) );

		$settings = $this->sut->preload_shared_settings( array() );

		$this->assertArrayHasKey( 'shouldUseExplicitPrice', $settings['woopaymentsSettings'] );
		$this->assertFalse( $settings['woopaymentsSettings']['shouldUseExplicitPrice'], 'The store currency alone should not add currency codes.' );
	}

	/**
	 * @testdox Should preload shouldUseExplicitPrice as false when a configured currency has no rate, like the plugin's enabled currencies.
	 */
	public function test_preloads_explicit_price_flag_off_when_the_additional_currency_is_unavailable(): void {
		$this->set_multi_currency_enabled( true );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'USD', 'EUR' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		delete_option( 'wcpay_multi_currency_manual_rate_eur' );

		$settings = $this->sut->preload_shared_settings( array() );

		$this->assertFalse( $settings['woopaymentsSettings']['shouldUseExplicitPrice'], 'A currency without a rate is not an enabled currency.' );
	}

	/**
	 * @testdox Should preload shouldUseExplicitPrice as false when Multi-Currency is disabled, even with a configured currency.
	 */
	public function test_preloads_explicit_price_flag_off_when_multi_currency_is_disabled(): void {
		$this->set_multi_currency_enabled( false );
		$this->enable_manual_rate_currency( 'EUR', '0.9' );

		$settings = $this->sut->preload_shared_settings( array() );

		$this->assertFalse( $settings['woopaymentsSettings']['shouldUseExplicitPrice'], 'Disabled Multi-Currency should never add currency codes by default.' );
	}

	/**
	 * @testdox Should pass the plugin's default to wcpay_multi_currency_should_output_explicit_price and use its result.
	 *
	 * @testWith [true, true, false]
	 *           [false, false, true]
	 *
	 * @param bool $multi_currency_enabled Whether Multi-Currency is on.
	 * @param bool $expected_default       The default the filter should receive.
	 * @param bool $filtered               The value the filter returns.
	 */
	public function test_applies_the_legacy_explicit_price_filter( bool $multi_currency_enabled, bool $expected_default, bool $filtered ): void {
		$this->set_multi_currency_enabled( $multi_currency_enabled );
		$this->enable_manual_rate_currency( 'EUR', '0.9' );
		$received = array();
		add_filter(
			'wcpay_multi_currency_should_output_explicit_price',
			static function ( $should_output ) use ( &$received, $filtered ) {
				$received[] = $should_output;
				return $filtered;
			}
		);

		$settings = $this->sut->preload_shared_settings( array() );

		$this->assertSame( array( $expected_default ), $received, 'The filter should run once with the plugin rule as its default.' );
		$this->assertSame( $filtered, $settings['woopaymentsSettings']['shouldUseExplicitPrice'] );
	}

	/**
	 * Set whether core Multi-Currency owns the currency pipeline.
	 *
	 * @param bool $enabled Whether Multi-Currency is on.
	 */
	private function set_multi_currency_enabled( bool $enabled ): void {
		$arbiter = $this->getMockBuilder( MultiCurrencyRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_core_register' ) )
			->getMock();
		$arbiter->method( 'should_core_register' )->willReturn( $enabled );
		wc_get_container()->replace( MultiCurrencyRuntimeArbiter::class, $arbiter );
	}

	/**
	 * Enable an additional currency with a manual exchange rate, so no rate provider is needed.
	 *
	 * @param string $currency_code Currency code.
	 * @param string $rate          Manual exchange rate.
	 */
	private function enable_manual_rate_currency( string $currency_code, string $rate ): void {
		$option_suffix = strtolower( $currency_code );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'USD', $currency_code ) );
		update_option( 'wcpay_multi_currency_exchange_rate_' . $option_suffix, 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_' . $option_suffix, $rate );
	}
}
