<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyAsyncPriceProjectionService;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyAsyncPriceProjectionService class.
 */
class MultiCurrencyAsyncPriceProjectionServiceTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should project async renderer activation blockers.
	 */
	public function test_projects_activation_blockers(): void {
		$this->assertFalse( MultiCurrencyAsyncPriceProjectionService::should_activate( false, false, false, false, false ) );
		$this->assertSame(
			array(
				'cache_optimized_mode_inactive',
				'admin_context',
				'cron_context',
				'admin_api_request',
				'active_session',
			),
			MultiCurrencyAsyncPriceProjectionService::get_activation_blockers( false, true, true, true, true )
		);
	}

	/**
	 * @testdox Should wrap price HTML with WooPayments-compatible skeleton markup.
	 */
	public function test_wraps_price_html_with_woopayments_compatible_skeleton_markup(): void {
		$result = MultiCurrencyAsyncPriceProjectionService::wrap_price_with_skeleton(
			'<span class="woocommerce-Price-amount amount"><bdi><script>alert(1)</script>$25.99</bdi></span>',
			'25.99',
			'shipping" data-bad="1'
		);

		$this->assertStringContainsString( 'class="woocommerce-Price-amount amount wcpay-async-price"', $result );
		$this->assertStringContainsString( 'data-wcpay-price="25.99"', $result );
		$this->assertStringContainsString( 'data-wcpay-price-type="shipping&quot; data-bad=&quot;1"', $result );
		$this->assertStringContainsString( '<bdi class="wcpay-price-skeleton"></bdi>', $result );
		$this->assertStringContainsString( 'class="screen-reader-text wcpay-price-placeholder"', $result );
		$this->assertStringContainsString( '$25.99', $result );
		$this->assertStringNotContainsString( '<script>', $result );
	}

	/**
	 * @testdox Should write a float placeholder price with a dot decimal separator.
	 */
	public function test_writes_float_placeholder_price_with_a_dot(): void {
		$result = MultiCurrencyAsyncPriceProjectionService::wrap_price_with_skeleton( '<span>$12.50</span>', 12.5 );

		$this->assertStringContainsString( 'data-wcpay-price="12.5"', $result );
	}

	/**
	 * @testdox Should annotate sale price screen reader text without touching placeholders.
	 */
	public function test_annotates_sale_price_screen_reader_text_without_touching_placeholders(): void {
		$html = '<span class="screen-reader-text wcpay-price-placeholder">$50.00</span>'
			. ' <span class="screen-reader-text">Original price was: $50.00.</span>'
			. ' <span class="screen-reader-text">Current price is: $35.00.</span>';

		$result = MultiCurrencyAsyncPriceProjectionService::annotate_sale_price_screen_reader_text( $html, '50', '35' );

		$this->assertStringContainsString( '<span class="screen-reader-text wcpay-price-placeholder">', $result );
		$this->assertStringContainsString( 'data-wcpay-sr-type="sale_original"', $result );
		$this->assertStringContainsString( 'data-wcpay-sr-price="50"', $result );
		$this->assertStringContainsString( 'data-wcpay-sr-type="sale_current"', $result );
		$this->assertStringContainsString( 'data-wcpay-sr-price="35"', $result );
		$this->assertSame(
			$html,
			MultiCurrencyAsyncPriceProjectionService::annotate_sale_price_screen_reader_text( $html, 'Free', '35' )
		);
	}

	/**
	 * @testdox Should write float sale prices with a dot decimal separator.
	 */
	public function test_writes_float_sale_prices_with_a_dot(): void {
		$html = '<span class="screen-reader-text">Original price was: $12.50.</span>'
			. ' <span class="screen-reader-text">Current price is: $9.50.</span>';

		$result = MultiCurrencyAsyncPriceProjectionService::annotate_sale_price_screen_reader_text( $html, 12.5, 9.5 );

		$this->assertStringContainsString( 'data-wcpay-sr-price="12.5"', $result );
		$this->assertStringContainsString( 'data-wcpay-sr-price="9.5"', $result );
	}

	/**
	 * @testdox Should write float range prices with a dot decimal separator.
	 */
	public function test_writes_float_range_prices_with_a_dot(): void {
		$html = '<span class="screen-reader-text">Price range: $10.50 through $30.25</span>';

		$result = MultiCurrencyAsyncPriceProjectionService::annotate_price_range_screen_reader_text( $html, 10.5, 30.25 );

		$this->assertStringContainsString( 'data-wcpay-sr-price-from="10.5"', $result );
		$this->assertStringContainsString( 'data-wcpay-sr-price-to="30.25"', $result );
	}

	/**
	 * @testdox Should annotate price range screen reader text and skip non-numeric prices.
	 */
	public function test_annotates_price_range_screen_reader_text_and_skips_non_numeric_prices(): void {
		$html = '<span class="woocommerce-Price-amount amount"><bdi>$10.00</bdi></span>'
			. ' &ndash; '
			. '<span class="woocommerce-Price-amount amount"><bdi>$30.00</bdi></span>'
			. ' <span class="screen-reader-text">Price range: $10.00 through $30.00</span>';

		$result = MultiCurrencyAsyncPriceProjectionService::annotate_price_range_screen_reader_text( $html, '10', '30' );

		$this->assertStringContainsString( 'data-wcpay-sr-type="range"', $result );
		$this->assertStringContainsString( 'data-wcpay-sr-price-from="10"', $result );
		$this->assertStringContainsString( 'data-wcpay-sr-price-to="30"', $result );
		$this->assertSame(
			$html,
			MultiCurrencyAsyncPriceProjectionService::annotate_price_range_screen_reader_text( $html, 'Free', '30' )
		);
	}

	/**
	 * @testdox Should project async renderer asset manifest.
	 */
	public function test_projects_async_renderer_asset_manifest(): void {
		$default_currency = MultiCurrencyAsyncPriceProjectionService::get_default_currency_config(
			'&euro;',
			2,
			',',
			'.',
			'right_space'
		);
		$manifest         = MultiCurrencyAsyncPriceProjectionService::get_asset_manifest(
			'https://example.test/wp-json/wc/v3/payments/multi-currency/public/config',
			$default_currency,
			'https://example.test/wp-content/plugins/woocommerce/assets/css/multi-currency-async-renderer.css',
			'1.2.3'
		);

		$this->assertSame(
			array(
				'symbol'       => html_entity_decode( '&euro;', ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'decimals'     => 2,
				'decimal_sep'  => ',',
				'thousand_sep' => '.',
				'symbol_pos'   => 'right_space',
			),
			$default_currency
		);
		$this->assertSame(
			array(
				'script' => array(
					'localized_object' => 'wcpayAsyncPriceConfig',
					'config'           => array(
						'apiUrl'          => 'https://example.test/wp-json/wc/v3/payments/multi-currency/public/config',
						'defaultCurrency' => $default_currency,
						'srText'          => array(
							'sale_original' => 'Original price was: %s.',
							'sale_current'  => 'Current price is: %s.',
							'range'         => 'Price range: %1$s through %2$s',
						),
					),
				),
				'style'  => array(
					'handle'        => 'wc-multi-currency-async-renderer',
					'legacy_handle' => 'wcpay-multi-currency-async-renderer',
					'url'           => 'https://example.test/wp-content/plugins/woocommerce/assets/css/multi-currency-async-renderer.css',
					'version'       => '1.2.3',
				),
			),
			$manifest
		);
	}
}
