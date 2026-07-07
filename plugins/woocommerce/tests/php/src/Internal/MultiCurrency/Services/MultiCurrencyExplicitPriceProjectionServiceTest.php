<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyExplicitPriceProjectionService;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyExplicitPriceProjectionService class.
 */
class MultiCurrencyExplicitPriceProjectionServiceTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should project explicit price hook manifest.
	 */
	public function test_projects_explicit_price_hook_manifest(): void {
		$manifest = MultiCurrencyExplicitPriceProjectionService::get_hook_manifest();

		$this->assertSame(
			array(
				array(
					'hook'          => 'woocommerce_cart_total',
					'callback'      => 'get_explicit_price',
					'priority'      => 100,
					'accepted_args' => 1,
				),
				array(
					'hook'          => 'woocommerce_get_formatted_order_total',
					'callback'      => 'get_explicit_price',
					'priority'      => 100,
					'accepted_args' => 2,
				),
			),
			$manifest['filters']
		);
		$this->assertSame(
			array(
				array(
					'hook'          => 'woocommerce_admin_order_totals_after_tax',
					'callback'      => 'register_formatted_woocommerce_price_filter',
					'priority'      => 10,
					'accepted_args' => 1,
				),
				array(
					'hook'          => 'woocommerce_admin_order_totals_after_total',
					'callback'      => 'unregister_formatted_woocommerce_price_filter',
					'priority'      => 10,
					'accepted_args' => 1,
				),
			),
			$manifest['actions']
		);
	}

	/**
	 * @testdox Should append currency code when additional currencies are enabled.
	 */
	public function test_appends_currency_code_when_additional_currencies_are_enabled(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_currency' )->willReturn( 'BRL' );

		$this->assertSame(
			'R$ 5,90 BRL',
			MultiCurrencyExplicitPriceProjectionService::get_explicit_price( 'R$ 5,90', $order, true, 'USD' )
		);
		$this->assertSame(
			'$10.30 USD',
			MultiCurrencyExplicitPriceProjectionService::get_explicit_price( '$10.30', null, true, 'USD' )
		);
	}

	/**
	 * @testdox Should keep price unchanged when explicit prices are inactive.
	 */
	public function test_keeps_price_unchanged_when_explicit_prices_are_inactive(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_currency' )->willReturn( 'BRL' );

		$this->assertSame(
			'R$ 5,90',
			MultiCurrencyExplicitPriceProjectionService::get_explicit_price( 'R$ 5,90', $order, false, 'USD' )
		);
		$this->assertSame(
			'$10.30',
			MultiCurrencyExplicitPriceProjectionService::get_explicit_price( '$10.30', null, false, 'USD' )
		);
	}

	/**
	 * @testdox Should skip prices that already contain the currency code.
	 */
	public function test_skips_prices_that_already_contain_currency_code(): void {
		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_currency' )->willReturn( 'CHF' );

		$this->assertSame(
			'CHF 10.30',
			MultiCurrencyExplicitPriceProjectionService::get_explicit_price( 'CHF 10.30', $order, true, 'USD' )
		);
		$this->assertSame(
			'<span>$10.30&nbsp;USD</span>',
			MultiCurrencyExplicitPriceProjectionService::get_explicit_price( '<span>$10.30&nbsp;USD</span>', null, true, 'USD' )
		);
	}

	/**
	 * @testdox Should project explicit wc price args.
	 */
	public function test_projects_explicit_wc_price_args(): void {
		$args = array(
			'price_format' => '%1$s%2$s',
			'currency'     => 'USD',
		);

		$this->assertSame(
			array(
				'price_format' => '%1$s%2$s&nbsp;USD',
				'currency'     => 'USD',
			),
			MultiCurrencyExplicitPriceProjectionService::get_explicit_price_args( $args, true )
		);
		$this->assertSame(
			array(
				'price_format' => '%1$s%2$s&nbsp;USD',
				'currency'     => 'USD',
			),
			MultiCurrencyExplicitPriceProjectionService::get_explicit_price_args(
				array(
					'price_format' => '%1$s%2$s&nbsp;USD',
					'currency'     => 'USD',
				),
				true
			)
		);
		$this->assertSame( $args, MultiCurrencyExplicitPriceProjectionService::get_explicit_price_args( $args, false ) );
	}
}
