<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Utilities;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Tests\Internal\Compatibility\WooPaymentsCompatibilityNoticeTestTrait;
use Automattic\WooCommerce\Utilities\MultiCurrencyUtil;
use WC_Unit_Test_Case;

/**
 * Tests the public currency utility across runtime owners.
 */
class MultiCurrencyUtilTest extends WC_Unit_Test_Case {
	use WooPaymentsCompatibilityNoticeTestTrait;

	/**
	 * Reset the per-request warnings and cached services between owners.
	 */
	public function tearDown(): void {
		$this->reset_compatibility_notices( MultiCurrencyUtil::class );
		$this->reset_container_resolutions();
		parent::tearDown();
	}

	/**
	 * @testdox With no owner, amounts and the store currency survive a third-party display currency filter.
	 */
	public function test_no_owner_preserves_amounts_and_store_currency(): void {
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'no' );
		update_option( 'woocommerce_currency', 'CAD' );
		add_filter( 'woocommerce_currency', static fn() => 'EUR' );

		$this->assertFalse( MultiCurrencyUtil::is_active() );
		$this->assertSame( 'CAD', MultiCurrencyUtil::get_store_currency() );
		$this->assertSame( array( 'CAD' => 1.0 ), MultiCurrencyUtil::get_enabled_currencies() );
		$this->assertSame( 12.34, MultiCurrencyUtil::convert( 12.34, 'EUR' ) );
		$this->assertSame( 12.34, MultiCurrencyUtil::convert_price( '12.34', 'product', 'EUR' ) );
	}

	/**
	 * @testdox A yen price rounds to whole yen while a raw amount keeps its fraction.
	 */
	public function test_zero_decimal_currency(): void {
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'JPY' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_jpy', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_jpy', '150' );

		$this->assertSame( 151.5, MultiCurrencyUtil::convert( 1.01, 'JPY' ) );
		$this->assertSame( 152.0, MultiCurrencyUtil::convert_price( 1.01, 'tax', 'JPY' ) );
	}

	/**
	 * @testdox A currency with no rate leaves the amount unchanged and emits one notice per method.
	 */
	public function test_currency_without_a_rate(): void {
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array() );
		$notices = array();
		add_action(
			'doing_it_wrong_run',
			static function ( $method ) use ( &$notices ) {
				$notices[] = $method;
			}
		);
		$this->setExpectedIncorrectUsage( MultiCurrencyUtil::class . '::convert()' );
		$this->setExpectedIncorrectUsage( MultiCurrencyUtil::class . '::convert_price()' );

		$this->assertSame( 7.4, MultiCurrencyUtil::convert( 7.4, 'EUR' ) );
		$this->assertSame( 7.4, MultiCurrencyUtil::convert( 7.4, 'USD', 'EUR' ) );
		$this->assertSame( 7.4, MultiCurrencyUtil::convert_price( 7.4, 'shipping', 'EUR' ) );
		$this->assertSame( 9.5, MultiCurrencyUtil::convert_price( 9.5, 'shipping', 'EUR' ) );
		$this->assertSame( array( MultiCurrencyUtil::class . '::convert()', MultiCurrencyUtil::class . '::convert_price()' ), $notices );
	}

	/**
	 * @testdox An extension owner without a usable accessor or methods gives identity answers and developer notices.
	 *
	 * @testWith [false]
	 *           [true]
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @param bool $accessor_exists Whether an incomplete extension has declared the accessor.
	 */
	public function test_incomplete_extension( bool $accessor_exists ): void {
		$this->enable_extension();
		if ( $accessor_exists ) {
			require __DIR__ . '/../Internal/MultiCurrency/Compat/Fixtures/extension-currency-accessor.fixture';
			$GLOBALS['wc_test_multi_currency_extension'] = new \stdClass();
		}

		$this->setExpectedIncorrectUsage( MultiCurrencyUtil::class . '::get_enabled_currencies()' );
		$this->setExpectedIncorrectUsage( MultiCurrencyUtil::class . '::convert()' );
		$this->setExpectedIncorrectUsage( MultiCurrencyUtil::class . '::convert_price()' );

		$this->assertTrue( MultiCurrencyUtil::is_active() );
		$this->assertSame( array( 'USD' => 1.0 ), MultiCurrencyUtil::get_enabled_currencies() );
		$this->assertSame( 12.34, MultiCurrencyUtil::convert( 12.34, 'EUR' ) );
		$this->assertSame( 12.34, MultiCurrencyUtil::convert_price( 12.34 ) );
	}

	/**
	 * @testdox The extension converts prices with its selected object and decides which raw conversions it accepts.
	 *
	 * @testWith [true]
	 *           [false]
	 * @param bool $euro_enabled Whether the selected currency is still enabled.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_extension_owner_delegates( bool $euro_enabled ): void {
		$this->enable_extension();
		require __DIR__ . '/../Internal/MultiCurrency/Compat/Fixtures/extension-currency-accessor.fixture';
		$euro = $this->getMockBuilder( \stdClass::class )->addMethods( array( 'get_rate', 'get_code' ) )->getMock();
		$euro->method( 'get_rate' )->willReturn( 0.75 );
		$euro->method( 'get_code' )->willReturn( 'EUR' );
		$dollar = $this->getMockBuilder( \stdClass::class )->addMethods( array( 'get_rate' ) )->getMock();
		$dollar->method( 'get_rate' )->willReturn( 1.0 );

		$extension  = $this->getMockBuilder( \stdClass::class )->addMethods( array( 'get_enabled_currencies', 'get_selected_currency', 'get_raw_conversion', 'get_price' ) )->getMock();
		$currencies = array( 'USD' => $dollar );
		$rates      = array( 'USD' => 1.0 );
		if ( $euro_enabled ) {
			$currencies['EUR'] = $euro;
			$rates['EUR']      = 0.75;
		}
		$extension->method( 'get_enabled_currencies' )->willReturn( $currencies );
		$extension->method( 'get_selected_currency' )->willReturn( $euro );
		$extension->expects( $this->exactly( 3 ) )->method( 'get_raw_conversion' )->willReturnCallback(
			static function ( $amount, $to, $from ) use ( $euro_enabled ) {
				if ( 'CHF' === $to ) {
					throw new \Error( 'Missing rate' );
				}
				if ( $euro_enabled && 'EUR' === $to && '' === $from ) {
					return $amount * 0.75;
				}
				if ( $euro_enabled && 'USD' === $to && 'EUR' === $from ) {
					return $amount / 0.75;
				}
				throw new \RuntimeException( 'Currency is unavailable' );
			}
		);
		$extension->expects( $this->exactly( 2 ) )->method( 'get_price' )->willReturnMap(
			array(
				array( 10.0, 'product', $euro, 7.99 ),
				array( 10.0, 'shipping', null, 8.0 ),
			)
		);
		$GLOBALS['wc_test_multi_currency_extension'] = $extension;

		$this->assertSame( $rates, MultiCurrencyUtil::get_enabled_currencies() );
		if ( ! $euro_enabled ) {
			$this->setExpectedIncorrectUsage( MultiCurrencyUtil::class . '::convert()' );
		}
		$this->assertSame( $euro_enabled ? 7.5 : 10.0, MultiCurrencyUtil::convert( 10 ) );
		$this->assertSame( $euro_enabled ? 10.0 : 7.5, MultiCurrencyUtil::convert( 7.5, 'USD', 'EUR' ) );
		$this->assertSame( 8.0, MultiCurrencyUtil::convert_price( 10, 'shipping' ) );
		$this->assertSame( 7.99, MultiCurrencyUtil::convert_price( '10', 'product', 'eur' ) );

		$this->setExpectedIncorrectUsage( MultiCurrencyUtil::class . '::convert()' );
		$this->setExpectedIncorrectUsage( MultiCurrencyUtil::class . '::convert_price()' );
		$this->assertSame( 10.0, MultiCurrencyUtil::convert( 10, 'CHF' ) );
		$this->assertSame( 10.0, MultiCurrencyUtil::convert_price( 10, 'product', 'CHF' ) );

		$inert = $this->getMockBuilder( \stdClass::class )->addMethods( array( 'get_enabled_currencies' ) )->getMock();
		$inert->method( 'get_enabled_currencies' )->willReturn( array() );
		$GLOBALS['wc_test_multi_currency_extension'] = $inert;
		$this->assertSame( array( 'USD' => 1.0 ), MultiCurrencyUtil::get_enabled_currencies() );

		$GLOBALS['wc_test_multi_currency_extension'] = $extension;
		update_option( MultiCurrencyFeatureController::WOOPAYMENTS_MULTI_CURRENCY_FLAG_OPTION, '0' );
		$this->assertFalse( MultiCurrencyUtil::is_active() );
		$this->assertSame( 10.0, MultiCurrencyUtil::convert( 10, 'EUR' ) );
		$this->assertSame( 10.0, MultiCurrencyUtil::convert_price( 10, 'product', 'EUR' ) );
		$this->assertSame( array( 'USD' => 1.0 ), MultiCurrencyUtil::get_enabled_currencies() );
	}

	/**
	 * Set both feature flags so the extension wins solely through the arbiter.
	 */
	private function enable_extension(): void {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'active_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) );
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( MultiCurrencyFeatureController::WOOPAYMENTS_MULTI_CURRENCY_FLAG_OPTION, '1' );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
	}
}
