<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\LocalizationInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\SwitcherRenderer;
use WC_Unit_Test_Case;

/**
 * Tests for the SwitcherRenderer class.
 */
class SwitcherRendererTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should project widget markup with preserved query parameters and currency labels.
	 */
	public function test_renders_widget_markup_keeping_query_parameters_with_currency_labels(): void {
		$sut = $this->create_service( $this->create_state( 'GBP' ) );

		$markup = $sut->get_widget_markup(
			array(
				'title'  => 'Shop currency',
				'symbol' => true,
				'flag'   => true,
			),
			array(
				'before_widget' => '<section class="widget">',
				'after_widget'  => '</section>',
				'before_title'  => '<h2>',
				'after_title'   => '</h2>',
			),
			array(
				's'        => 'shirts',
				'currency' => 'EUR',
				'paged'    => '2',
			)
		);

		$this->assertStringStartsWith( '<section class="widget">', $markup );
		$this->assertStringContainsString( '<h2>Shop currency</h2>', $markup );
		$this->assertStringContainsString( '<form>', $markup );
		$this->assertStringContainsString( 'class="js-woopayments-currency-switcher"', $markup );
		$this->assertStringContainsString( 'aria-label="Shop currency"', $markup );
		$this->assertStringContainsString( 'name="s" value="shirts"', $markup );
		$this->assertStringContainsString( 'name="paged" value="2"', $markup );
		$this->assertStringNotContainsString( 'type="hidden" name="currency"', $markup );
		$this->assertStringContainsString( 'value="GBP" selected', $markup );
		// Decoded text: WordPress 7.2 prints the symbol as a literal £ where 7.1 prints &pound;.
		$this->assertStringContainsString(
			html_entity_decode( $this->get_flag( 'GB' ) . ' £ GBP', ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			html_entity_decode( $markup, ENT_QUOTES | ENT_HTML5, 'UTF-8' )
		);
		$this->assertStringEndsWith( '</section>', $markup );
	}

	/**
	 * @testdox Should project block markup with its styles, label, symbol and border: $_dataName.
	 *
	 * Client 11.1.0 CurrencySwitcherBlock.php:165-178 prefixes the option text with the currency symbol when `with_symbol`
	 * is true and the symbol differs from the code (USD "$ USD"), and :211 renders a "1px solid" select border when the
	 * block attribute `border` is true, a "0px solid" one when it is false.
	 * @dataProvider provide_block_markup_attributes
	 *
	 * @param string              $selected   Selected currency.
	 * @param array<string,mixed> $attributes Block attributes.
	 * @param array<string,mixed> $query_args Query arguments the form carries.
	 * @param string[]            $expected   Markup the block must contain.
	 */
	public function test_renders_block_markup_with_its_styles_label_symbol_and_border( string $selected, array $attributes, array $query_args, array $expected ): void {
		$sut = $this->create_service( $this->create_state( $selected ) );

		$markup = $sut->get_block_markup( $attributes, $query_args );

		foreach ( $expected as $text ) {
			$this->assertStringContainsString( $text, $markup );
		}
	}

	/**
	 * Block attributes: symbol off with a borderless styled select and a carried query argument, and symbol and border on.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:array<string,mixed>,3:string[]}>
	 */
	public function provide_block_markup_attributes(): array {
		return array(
			'styled, without symbol or border' => array(
				'JPY',
				array(
					'symbol'          => false,
					'flag'            => true,
					'fontSize'        => 18,
					'fontLineHeight'  => 1.4,
					'fontColor'       => '#123456',
					'border'          => false,
					'borderRadius'    => 6,
					'borderColor'     => '#654321',
					'backgroundColor' => 'transparent',
				),
				array(
					'orderby' => 'price',
				),
				array(
					'<form>',
					'name="orderby" value="price"',
					'class="currency-switcher-holder"',
					'line-height: 1.4;',
					'class="js-woopayments-currency-switcher"',
					'aria-label="Select your currency"',
					'border: 0px solid;',
					'border-radius: 6px;',
					'border-color: #654321;',
					'font-size: 18px;',
					'color: #123456;',
					'background-color: transparent;',
					$this->get_flag( 'JP' ) . ' JPY',
					'value="JPY" selected',
				),
			),
			'symbol and border on'             => array(
				'USD',
				array(
					'symbol' => true,
					'flag'   => false,
					'border' => true,
				),
				array(),
				array(
					'border: 1px solid;',
					'<option value="USD" selected>&#036; USD</option>',
				),
			),
		);
	}

	/**
	 * @testdox Should entity-encode markup in a currency option label.
	 *
	 * Client 11.1.0 CurrencySwitcherBlock.php:178 prints the option text through `esc_html()`,
	 * so a filtered symbol carrying tags reaches the shopper as text, not markup.
	 */
	public function test_entity_encodes_markup_in_currency_option_label(): void {
		$symbol_filter = static function ( $symbol, $currency ) {
			return 'GBP' === $currency ? '<b>£</b>' : $symbol;
		};
		add_filter( 'woocommerce_currency_symbol', $symbol_filter, 10, 2 );
		$sut = $this->create_service( $this->create_state( 'USD' ) );

		$markup = $sut->get_block_markup(
			array(
				'symbol' => true,
				'flag'   => false,
			)
		);
		remove_filter( 'woocommerce_currency_symbol', $symbol_filter, 10 );

		$this->assertStringContainsString( '<option value="GBP">&lt;b&gt;£&lt;/b&gt; GBP</option>', $markup );
		$this->assertStringNotContainsString( '<b>', $markup );
	}

	/**
	 * @testdox Should keep markup in a widget currency option label.
	 *
	 * Client 11.1.0 CurrencySwitcherWidget.php:146 prints the option text unescaped, unlike the block.
	 */
	public function test_keeps_markup_in_widget_currency_option_label(): void {
		$symbol_filter = static function ( $symbol, $currency ) {
			return 'GBP' === $currency ? '<b>£</b>' : $symbol;
		};
		add_filter( 'woocommerce_currency_symbol', $symbol_filter, 10, 2 );
		$sut = $this->create_service( $this->create_state( 'USD' ) );

		$markup = $sut->get_widget_markup(
			array(
				'title'  => '',
				'symbol' => true,
				'flag'   => false,
			)
		);
		remove_filter( 'woocommerce_currency_symbol', $symbol_filter, 10 );

		$this->assertStringContainsString( '<option value="GBP"><b>£</b> GBP</option>', $markup );
	}

	/**
	 * @testdox Should offer enabled USD and EUR while excluding merely available CAD.
	 * @see WooPayments 11.1.0 tests/e2e/specs/wcpay/merchant/merchant-multi-currency-widget.spec.ts:52
	 */
	public function test_offers_enabled_usd_and_eur_while_excluding_available_cad(): void {
		$usd       = $this->create_currency( 'USD', true );
		$eur       = $this->create_currency( 'EUR', false );
		$cad       = $this->create_currency( 'CAD', false );
		$available = array(
			'USD' => $usd,
			'EUR' => $eur,
			'CAD' => $cad,
		);
		$enabled   = array(
			'USD' => $usd,
			'EUR' => $eur,
		);
		$sut       = $this->create_service( new MultiCurrencyState( $available, $enabled, $usd, $eur ) );

		$markup = $sut->get_block_markup();
		preg_match_all( '/<option value="([^"]+)"(?: selected)?>/', $markup, $matches );

		$this->assertSame( array( 'USD', 'EUR' ), $matches[1] );
		$this->assertStringNotContainsString( 'value="CAD"', $markup );
		$this->assertStringContainsString( '<option value="USD">', $markup );
		$this->assertSame( 1, substr_count( $markup, ' selected>' ) );
		$this->assertStringContainsString( '<option value="EUR" selected>', $markup );
	}

	/**
	 * @testdox Should render no switcher markup: $_dataName.
	 * @dataProvider provide_states_without_a_switcher
	 *
	 * @param MultiCurrencyState $state              Multi-currency state.
	 * @param bool               $switching_disabled Whether currency switching is disabled.
	 */
	public function test_renders_no_switcher_when_switching_is_disabled_or_one_currency_is_enabled( MultiCurrencyState $state, bool $switching_disabled ): void {
		$sut = $this->create_service( $state );

		$this->assertSame( '', $sut->get_widget_markup( array(), array(), array(), $switching_disabled ) );
		$this->assertSame( '', $sut->get_block_markup( array(), array(), $switching_disabled ) );
	}

	/**
	 * States without a switcher: switching disabled, and only one currency enabled.
	 *
	 * @return array<string,array{0:MultiCurrencyState,1:bool}>
	 */
	public function provide_states_without_a_switcher(): array {
		return array(
			'switching disabled'        => array( $this->create_state( 'GBP' ), true ),
			'only one currency enabled' => array( $this->create_single_currency_state(), false ),
		);
	}

	/**
	 * Create the projection service.
	 *
	 * @param MultiCurrencyState $state Multi-currency state.
	 * @return SwitcherRenderer
	 */
	private function create_service( MultiCurrencyState $state ): SwitcherRenderer {
		return new SwitcherRenderer( $this->create_state_builder( $state ) );
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
		$usd = $this->create_currency( 'USD', true );
		$gbp = $this->create_currency( 'GBP', false );
		$jpy = $this->create_currency( 'JPY', false );

		$enabled = array(
			'USD' => $usd,
			'GBP' => $gbp,
			'JPY' => $jpy,
		);

		return new MultiCurrencyState( $enabled, $enabled, $usd, $enabled[ $selected_code ] );
	}

	/**
	 * Create a single-currency state.
	 *
	 * @return MultiCurrencyState
	 */
	private function create_single_currency_state(): MultiCurrencyState {
		$usd = $this->create_currency( 'USD', true );

		return new MultiCurrencyState( array( 'USD' => $usd ), array( 'USD' => $usd ), $usd, $usd );
	}

	/**
	 * Create a currency.
	 *
	 * @param string $code       Currency code.
	 * @param bool   $is_default Whether this is the default currency.
	 * @return MultiCurrencyCurrency
	 */
	private function create_currency( string $code, bool $is_default ): MultiCurrencyCurrency {
		return new MultiCurrencyCurrency( $this->create_localization(), $code, 1.0, $is_default );
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
				unset( $currency_code );

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
				unset( $country );

				return array();
			}
		};
	}

	/**
	 * Get an ISO country flag.
	 *
	 * @param string $country_code Country code.
	 * @return string
	 */
	private function get_flag( string $country_code ): string {
		$first  = 0x1F1E6 + ord( $country_code[0] ) - ord( 'A' );
		$second = 0x1F1E6 + ord( $country_code[1] ) - ord( 'A' );

		return html_entity_decode(
			'&#x' . dechex( $first ) . ';&#x' . dechex( $second ) . ';',
			ENT_NOQUOTES,
			'UTF-8'
		);
	}
}
