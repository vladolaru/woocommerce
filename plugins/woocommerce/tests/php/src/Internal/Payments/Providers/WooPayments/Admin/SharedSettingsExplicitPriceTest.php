<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Admin;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\NavigationController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\SharedSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\OnboardingRedirect;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminMenuBadgeService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsApplePayDomainService;
use WC_Unit_Test_Case;

/**
 * Tests for the `shouldUseExplicitPrice` flag the WooPayments admin shared settings preload.
 *
 * Source: plugin 11.1.0 `class-wc-payments-admin.php:1040` and
 * `class-wc-payments-explicit-price-formatter.php:55-74,167-190`.
 */
class SharedSettingsExplicitPriceTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var SharedSettings
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

		$arbiter = $this->getMockBuilder( WooPaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_builtin_owner' ) )
			->getMock();
		$arbiter->method( 'is_builtin_owner' )->willReturn( true );

		$account_service = $this->createMock( WooPaymentsAccountService::class );
		$badge_service   = $this->createMock( WooPaymentsAdminMenuBadgeService::class );
		$navigation      = new NavigationController();
		$navigation->init( $arbiter, $account_service, $badge_service, $this->createMock( OnboardingRedirect::class ) );
		$this->sut = new SharedSettings();
		$this->sut->init( $account_service, $badge_service, $this->createMock( WooPaymentsApplePayDomainService::class ), $navigation );
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
	 * @testdox Should preload shouldUseExplicitPrice as true only when Multi-Currency is on with an additional enabled currency.
	 * @dataProvider provide_explicit_price_flag_cases
	 *
	 * @param bool                               $multi_currency_enabled Whether Multi-Currency is on.
	 * @param array<string,string|string[]|null> $options               Currency options to set; null deletes the option.
	 * @param bool                               $expected               The preloaded flag.
	 * @param string                             $message                Failure message.
	 */
	public function test_preloads_explicit_price_flag( bool $multi_currency_enabled, array $options, bool $expected, string $message ): void {
		$this->set_multi_currency_enabled( $multi_currency_enabled );
		foreach ( $options as $option => $value ) {
			if ( null === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, $value );
			}
		}

		$settings = $this->sut->preload_shared_settings( array() );

		$this->assertArrayHasKey( 'shouldUseExplicitPrice', $settings['woopaymentsSettings'] );
		$this->assertSame( $expected, $settings['woopaymentsSettings']['shouldUseExplicitPrice'], $message );
	}

	/**
	 * Multi-Currency states and the explicit price flag they preload.
	 *
	 * @return array<string,array{0:bool,1:array<string,string|string[]|null>,2:bool,3:string}>
	 */
	public function provide_explicit_price_flag_cases(): array {
		$eur_at_manual_rate = array(
			'wcpay_multi_currency_enabled_currencies' => array( 'USD', 'EUR' ),
			'wcpay_multi_currency_exchange_rate_eur'  => 'manual',
			'wcpay_multi_currency_manual_rate_eur'    => '0.9',
		);

		return array(
			'on with an additional currency'          => array( true, $eur_at_manual_rate, true, 'A second enabled currency should turn on explicit currency codes.' ),
			'on with only the store currency'         => array(
				true,
				array( 'wcpay_multi_currency_enabled_currencies' => array( 'USD' ) ),
				false,
				'The store currency alone should not add currency codes.',
			),
			'on with an additional currency, no rate' => array(
				true,
				array(
					'wcpay_multi_currency_enabled_currencies' => array( 'USD', 'EUR' ),
					'wcpay_multi_currency_exchange_rate_eur' => 'manual',
					'wcpay_multi_currency_manual_rate_eur' => null,
				),
				false,
				'A currency without a rate is not an enabled currency.',
			),
			'off with an additional currency'         => array( false, $eur_at_manual_rate, false, 'Disabled Multi-Currency should never add currency codes by default.' ),
		);
	}

	/**
	 * @testdox Should pass the WooPayments extension's default to wcpay_multi_currency_should_output_explicit_price and use its result.
	 *
	 * @testWith [true, true, false]
	 *           [false, false, true]
	 *
	 * @param bool $multi_currency_enabled Whether Multi-Currency is on.
	 * @param bool $expected_default       The default the filter should receive.
	 * @param bool $filtered               The value the filter returns.
	 */
	public function test_applies_the_woopayments_extension_explicit_price_filter( bool $multi_currency_enabled, bool $expected_default, bool $filtered ): void {
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
			->onlyMethods( array( 'is_builtin_owner' ) )
			->getMock();
		$arbiter->method( 'is_builtin_owner' )->willReturn( $enabled );
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
