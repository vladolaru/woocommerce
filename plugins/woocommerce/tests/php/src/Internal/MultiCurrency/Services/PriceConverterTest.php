<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\LocalizationInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\PriceCalculator;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\PriceConverter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilder;
use WC_Unit_Test_Case;

/**
 * Tests for the PriceConverter class.
 */
class PriceConverterTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should project selected-currency product prices.
	 */
	public function test_converts_product_prices_to_the_selected_currency(): void {
		$state = $this->create_state( 'GBP' );
		$sut   = $this->create_service( $state );

		$this->assertSame( 8.4, $sut->get_price( '10.00', 'product' ) );
	}

	/**
	 * @testdox Should add the charm to shipping only when the charm-only-to-products filter is turned off.
	 */
	public function test_applies_the_charm_to_shipping_when_the_products_only_filter_is_off(): void {
		$sut = $this->create_service( $this->create_state( 'GBP' ) );

		// 10.00 at 0.82 is 8.20, rounded up to 8.50; the -0.10 charm follows the filter (client 11.1.0 `includes/multi-currency/MultiCurrency.php:922-930`, `:962-967`).
		$this->assertSame( 8.5, $sut->get_price( '10.00', 'shipping' ) );

		add_filter( 'wcpay_multi_currency_apply_charm_only_to_products', '__return_false' );
		$this->assertSame( 8.4, $sut->get_price( '10.00', 'shipping' ) );
	}

	/**
	 * @testdox Should ask the charm-only-to-products filter only when a product or shipping price is converted.
	 */
	public function test_asks_the_charm_filter_only_for_converted_product_and_shipping_prices(): void {
		$calls = 0;
		$count = static function ( $value ) use ( &$calls ) {
			++$calls;
			return $value;
		};
		add_filter( 'wcpay_multi_currency_apply_charm_only_to_products', $count );

		$this->create_service( $this->create_state( 'GBP' ) )->get_price( '10.00', 'coupon' );
		$this->create_service( $this->create_state( 'USD' ) )->get_price( '10.00', 'shipping' );
		$this->assertSame( 0, $calls );

		$this->create_service( $this->create_state( 'GBP' ) )->get_price( '10.00', 'product' );
		$this->assertSame( 1, $calls );
	}

	/**
	 * @testdox Should project raw conversions between enabled currencies.
	 */
	public function test_converts_raw_amounts_between_enabled_currencies(): void {
		$state = $this->create_state( 'GBP' );
		$sut   = $this->create_service( $state );

		$this->assertSame( 10.0 * ( 0.82 / 1.25 ), $sut->get_raw_conversion( 10.0, 'GBP', 'CAD' ) );
	}

	/**
	 * @testdox Should project selected-currency price-filter bounds to default currency.
	 */
	public function test_converts_price_filter_bounds_to_the_default_currency(): void {
		$sut = $this->create_service( $this->create_state( 'GBP' ) );

		$this->assertSame( '10', $sut->get_price_filter_query_value( 8.2, '>=' ) );
		$this->assertSame( '11', $sut->get_price_filter_query_value( 8.21, '<=' ) );
	}

	/**
	 * @testdox Should convert from a selected currency that is not enabled, keeping the enabled list as it is.
	 *
	 * A subscription cart keeps its subscription's currency after the merchant disabled it, so the selected currency can be
	 * one the enabled list no longer holds.
	 */
	public function test_converts_from_a_selected_currency_that_is_not_enabled(): void {
		$usd   = $this->create_currency( 'USD', 1.0, true );
		$gbp   = $this->create_currency( 'GBP', 0.82, false );
		$state = new MultiCurrencyState(
			array(
				'USD' => $usd,
				'GBP' => $gbp,
			),
			array( 'USD' => $usd ),
			$usd,
			$gbp
		);
		$sut   = $this->create_service( $state );

		$this->assertSame( 8.2 * ( 1.0 / 0.82 ), $sut->get_raw_conversion( 8.2, 'USD', 'GBP' ) );
		$this->assertSame( 10.0 * ( 0.82 / 1.0 ), $sut->get_raw_conversion( 10.0, 'GBP', 'USD' ), 'Into the selected currency too, as a Name Your Price amount entered in USD is.' );
		$this->assertSame( '10', $sut->get_price_filter_query_value( 8.2, '>=' ) );
		$this->assertSame( array( 'USD' ), array_keys( $state->get_enabled_currencies() ) );
	}

	/**
	 * @testdox Should report whether selected and default currencies differ.
	 */
	public function test_reports_whether_selected_currency_differs_from_default(): void {
		$this->assertTrue( $this->create_service( $this->create_state( 'GBP' ) )->should_convert_between_selected_and_default_currency() );
		$this->assertFalse( $this->create_service( $this->create_state( 'USD' ) )->should_convert_between_selected_and_default_currency() );
	}

	/**
	 * @testdox Should project order exchange-rate meta for non-default orders.
	 */
	public function test_returns_exchange_rate_meta_for_non_default_currency_orders(): void {
		$sut = $this->create_service( $this->create_state( 'GBP' ) );

		$this->assertSame(
			array(
				'_wcpay_multi_currency_order_exchange_rate'    => '0.82',
				'_wcpay_multi_currency_order_default_currency' => 'USD',
			),
			$sut->get_order_meta_candidates( 'GBP' )
		);
	}

	/**
	 * @testdox Should not project order meta for default-currency orders.
	 */
	public function test_returns_no_order_meta_for_default_currency_orders(): void {
		$sut = $this->create_service( $this->create_state( 'USD' ) );

		$this->assertSame( array(), $sut->get_order_meta_candidates( 'USD' ) );
	}

	/**
	 * @testdox Should project refund meta copied from non-default orders.
	 */
	public function test_copies_refund_meta_from_non_default_currency_orders(): void {
		$order = wc_create_order();
		$order->set_currency( 'GBP' );
		$order->update_meta_data( '_wcpay_multi_currency_order_exchange_rate', 0.82 );
		$order->update_meta_data( '_wcpay_multi_currency_order_default_currency', 'USD' );
		$order->update_meta_data( '_wcpay_multi_currency_stripe_exchange_rate', 1.25 );
		$order->save();
		$sut = $this->create_service( $this->create_state( 'GBP' ) );

		$this->assertSame(
			array(
				'_wcpay_multi_currency_order_exchange_rate'    => 0.82,
				'_wcpay_multi_currency_order_default_currency' => 'USD',
				'_wcpay_multi_currency_stripe_exchange_rate'   => 1.25,
			),
			$sut->get_refund_meta_candidates( $order )
		);
	}

	/**
	 * @testdox Should not project refund meta for default-currency orders.
	 */
	public function test_returns_no_refund_meta_for_default_currency_orders(): void {
		$order = wc_create_order();
		$order->set_currency( 'USD' );
		$order->save();
		$sut = $this->create_service( $this->create_state( 'USD' ) );

		$this->assertSame( array(), $sut->get_refund_meta_candidates( $order ) );
	}

	/**
	 * Create the projection service.
	 *
	 * @param MultiCurrencyState $state Multi-currency state.
	 * @return PriceConverter
	 */
	private function create_service( MultiCurrencyState $state ): PriceConverter {
		return new PriceConverter(
			$this->create_state_builder( $state ),
			new PriceCalculator( $this->create_localization() )
		);
	}

	/**
	 * Create a state builder test double.
	 *
	 * @param MultiCurrencyState $state Multi-currency state.
	 * @return StateBuilder
	 */
	private function create_state_builder( MultiCurrencyState $state ): StateBuilder {
		return new class( $state ) extends StateBuilder {
			/**
			 * Multi-currency state.
			 *
			 * @var MultiCurrencyState
			 */
			private MultiCurrencyState $state;

			/**
			 * Constructor.
			 *
			 * @param MultiCurrencyState $state Multi-currency state.
			 */
			public function __construct( MultiCurrencyState $state ) {
				$this->state = $state;
			}

			/**
			 * Build a multi-currency state snapshot.
			 *
			 * @return MultiCurrencyState
			 */
			public function build(): MultiCurrencyState {
				return $this->state;
			}
		};
	}

	/**
	 * Create multi-currency state.
	 *
	 * @param string $selected_code Selected currency code.
	 * @return MultiCurrencyState
	 */
	private function create_state( string $selected_code ): MultiCurrencyState {
		$usd = $this->create_currency( 'USD', 1.0, true );
		$gbp = $this->create_currency( 'GBP', 0.82, false, '0.50', -0.10 );
		$cad = $this->create_currency( 'CAD', 1.25, false );

		$enabled = array(
			'USD' => $usd,
			'GBP' => $gbp,
			'CAD' => $cad,
		);

		return new MultiCurrencyState( $enabled, $enabled, $usd, $enabled[ $selected_code ] );
	}

	/**
	 * Create a currency.
	 *
	 * @param string $code       Currency code.
	 * @param float  $rate       Currency rate.
	 * @param bool   $is_default Whether this is the default currency.
	 * @param string $rounding   Rounding amount.
	 * @param float  $charm      Charm amount.
	 * @return MultiCurrencyCurrency
	 */
	private function create_currency( string $code, float $rate, bool $is_default, string $rounding = '0', float $charm = 0.0 ): MultiCurrencyCurrency {
		$currency = new MultiCurrencyCurrency( $this->create_localization(), $code, $rate, $is_default );
		$currency->set_rounding( $rounding );
		$currency->set_charm( $charm );

		return $currency;
	}

	/**
	 * Create a localization test double.
	 *
	 * @return LocalizationInterface
	 */
	private function create_localization(): LocalizationInterface {
		return new class() implements LocalizationInterface {
			/**
			 * Get a currency format.
			 *
			 * @param string $currency_code Currency code.
			 * @return array<string,mixed>
			 */
			public function get_currency_format( $currency_code ): array {
				return array(
					'currency_pos' => 'left',
					'thousand_sep' => ',',
					'decimal_sep'  => '.',
					'num_decimals' => 2,
				);
			}

			/**
			 * Get locale data for a country.
			 *
			 * @param string $country Country code.
			 * @return array<string,mixed>
			 */
			public function get_country_locale_data( $country ): array {
				return array();
			}
		};
	}
}
