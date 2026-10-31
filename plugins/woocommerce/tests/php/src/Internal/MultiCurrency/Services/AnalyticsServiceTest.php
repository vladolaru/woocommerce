<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\LocalizationInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFrontendCurrenciesController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\AnalyticsService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\OrderContextService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\PriceConverter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\RequestContext;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\ServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilderFactory;
use WC_Unit_Test_Case;

/**
 * Tests for the AnalyticsService class.
 */
class AnalyticsServiceTest extends WC_Unit_Test_Case {

	/**
	 * Original store currency.
	 *
	 * @var string
	 */
	private string $original_currency;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->original_currency = get_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_currency', 'USD' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		update_option( 'woocommerce_currency', $this->original_currency );
		wc_get_container()->get( StateBuilderFactory::class )->get_state_builder()->reset();

		parent::tear_down();
	}

	/**
	 * @testdox Should convert order stats to default currency.
	 */
	public function test_converts_order_stats_to_default_currency(): void {
		$order = $this->create_order( 'GBP', '0.5', 'USD' );
		$sut   = $this->create_service( $this->create_state() );

		$this->assertSame(
			array(
				'net_total'      => 20.0,
				'shipping_total' => 6.0,
				'tax_total'      => 4.0,
				'total_sales'    => 30.0,
			),
			$sut->convert_order_stats_data( $this->create_order_stats_args(), $order )
		);
	}

	/**
	 * @testdox Should prefer Stripe exchange rate for order stats.
	 */
	public function test_prefers_stripe_exchange_rate_for_order_stats(): void {
		$order = $this->create_order( 'GBP', '0.5', 'USD', '1.25' );
		$sut   = $this->create_service( $this->create_state() );

		$this->assertSame(
			array(
				'net_total'      => 12.5,
				'shipping_total' => 3.75,
				'tax_total'      => 2.5,
				'total_sales'    => 18.75,
			),
			$sut->convert_order_stats_data( $this->create_order_stats_args(), $order )
		);
	}

	/**
	 * @testdox Should leave default currency order stats unchanged.
	 */
	public function test_leaves_default_currency_order_stats_unchanged(): void {
		$order = $this->create_order( 'USD', '0.5', 'USD' );
		$args  = $this->create_order_stats_args();
		$sut   = $this->create_service( $this->create_state() );

		$this->assertSame( $args, $sut->convert_order_stats_data( $args, $order ) );
	}

	/**
	 * @testdox Should leave order stats unchanged when required meta is missing.
	 */
	public function test_leaves_order_stats_unchanged_when_required_meta_is_missing(): void {
		$order = wc_create_order();
		$order->set_currency( 'GBP' );
		$order->save();
		$args = $this->create_order_stats_args();
		$sut  = $this->create_service( $this->create_state() );

		$this->assertSame( $args, $sut->convert_order_stats_data( $args, $order ) );
	}

	/**
	 * @testdox Should fall back to the order rate when the processor rate cannot convert: $_dataName.
	 *
	 * Client 11.2.0 `tests/unit/multi-currency/test-class-analytics.php:226-255`: a GBP order at an order rate of 0.75 and a net
	 * total of 36.00 converts to 48.00.
	 *
	 * @testWith [""]
	 *           ["invalid"]
	 *           ["0"]
	 *           [-1]
	 *           ["1e999"]
	 *
	 * @param mixed $processor_rate Saved processor rate.
	 */
	public function test_falls_back_to_the_order_rate_when_the_processor_rate_cannot_convert( $processor_rate ): void {
		$result = $this->create_service( $this->create_state() )->convert_order_stats_data( $this->create_net_total_args( 36.0 ), $this->create_foreign_order( 0.75, $processor_rate ) );

		$this->assertSame( 48.0, $result['net_total'] );
		$this->assertSame( 48.0, $result['total_sales'] );
	}

	/**
	 * @testdox Should leave the stats row as recorded and log the order when no saved rate can convert: $_dataName.
	 *
	 * Client 11.2.0 `tests/unit/multi-currency/test-class-analytics.php:257-283` and `includes/multi-currency/Analytics.php:217-222`.
	 *
	 * @testWith ["invalid", ""]
	 *           [-1, ""]
	 *           ["1e999", ""]
	 *           ["1e-320", ""]
	 *           ["invalid", "0"]
	 *
	 * @param mixed $order_rate     Saved order rate.
	 * @param mixed $processor_rate Saved processor rate.
	 */
	public function test_leaves_the_stats_row_when_no_saved_rate_can_convert( $order_rate, $processor_rate ): void {
		$logger = $this->capture_log();
		$order  = $this->create_foreign_order( $order_rate, $processor_rate );
		$args   = $this->create_net_total_args( 36.0 );

		$this->assertSame( $args, $this->create_service( $this->create_state() )->convert_order_stats_data( $args, $order ) );
		$this->assertSame(
			array( array( 'error', sprintf( 'Multi-Currency Analytics: order %d has no valid saved exchange rate, its order stats were not converted.', $order->get_id() ), 'multi-currency' ) ),
			$logger->entries
		);
	}

	/**
	 * @testdox Should use a valid processor rate as it is, without the order rate, and log nothing.
	 *
	 * Client 11.2.0 `tests/unit/multi-currency/test-class-analytics.php:285-294`.
	 */
	public function test_uses_a_valid_processor_rate_without_the_order_rate(): void {
		$logger = $this->capture_log();

		$result = $this->create_service( $this->create_state() )->convert_order_stats_data( $this->create_net_total_args( 36.0 ), $this->create_foreign_order( 'invalid', 41.90 / 36 ) );

		$this->assertSame( 41.9, $result['total_sales'] );
		$this->assertSame( array(), $logger->entries );
	}

	/**
	 * @testdox Should round the Analytics import with the store's decimals while the shopper's currency has none: $expected.
	 *
	 * A PLN store with JPY selected on a storefront request, as an import run by ALTERNATE_WP_CRON is; an EUR order of 10.00
	 * at an order rate of 0.23 is 43.478... PLN. Another plugin's decimals filter still applies.
	 *
	 * @testWith [null, 43.48]
	 *           [3, 43.478]
	 *
	 * @param int|null $filtered_decimals Decimals another plugin's filter gives, or null for none.
	 * @param float    $expected          Converted net total.
	 */
	public function test_rounds_the_import_with_the_store_decimals( ?int $filtered_decimals, float $expected ): void {
		update_option( 'woocommerce_currency', 'PLN' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'PLN', 'JPY' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_jpy', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_jpy', '35' );
		add_filter( 'wcpay_multi_currency_override_selected_currency', static fn() => 'JPY' );
		if ( null !== $filtered_decimals ) {
			add_filter( 'wc_get_price_decimals', static fn() => $filtered_decimals );
		}
		$this->register_frontend_currencies();
		$this->assertSame( 0, wc_get_price_decimals(), 'Precondition: the storefront shows JPY, which has no decimals.' );
		$state_builder = wc_get_container()->get( StateBuilderFactory::class )->get_state_builder();
		$sut           = new AnalyticsService( $state_builder );
		add_filter( 'woocommerce_analytics_update_order_stats_data', array( $sut, 'convert_order_stats_data' ), 10, 2 );
		$order = $this->create_order( 'EUR', '0.23', 'PLN' );

		/** This filter is documented in src/Admin/API/Reports/Orders/Stats/DataStore.php */
		$result = apply_filters( 'woocommerce_analytics_update_order_stats_data', $this->create_net_total_args( 10.0 ), $order );

		$this->assertSame( $expected, $result['net_total'] );
		$this->assertSame( 0, wc_get_price_decimals(), 'The storefront still shows JPY after the import.' );
	}

	/**
	 * @testdox Should apply customer currency request args.
	 */
	public function test_applies_customer_currency_request_args(): void {
		$sut = $this->create_service( $this->create_state() );

		$this->assertSame(
			array(
				'period'          => 'month',
				'currency_is'     => array( 'gbp', 'jpy' ),
				'currency_is_not' => array( 'usd' ),
				'currency'        => 'eur',
			),
			$sut->apply_customer_currency_args(
				array( 'period' => 'month' ),
				array(
					'currency_is'     => array( 'gbp', '<b>jpy</b>' ),
					'currency_is_not' => array( 'usd' ),
					'currency'        => ' eur ',
				)
			)
		);
	}

	/**
	 * @testdox Should list customer currency options from the recorded currencies.
	 * @dataProvider customer_currency_options_provider
	 *
	 * @param string[] $customer_currencies Recorded customer currencies.
	 * @param string[] $expected_codes      Expected option values, in order.
	 */
	public function test_lists_customer_currency_options( array $customer_currencies, array $expected_codes ): void {
		$sut = $this->create_service( $this->create_state( 'USD', $customer_currencies ) );

		$this->assertSame(
			array_map(
				static fn( string $code ): array => array(
					'label' => html_entity_decode( get_woocommerce_currencies()[ $code ] ),
					'value' => $code,
				),
				$expected_codes
			),
			$sut->get_customer_currency_options()
		);
	}

	/**
	 * Recorded customer currencies and the options they give. AUD is known to WooCommerce but not offered by the state.
	 *
	 * @return array<string,array{0:string[],1:string[]}>
	 */
	public function customer_currency_options_provider(): array {
		return array(
			'default appended, repeat dropped, known but unoffered currency kept' => array( array( 'GBP', 'AUD', 'GBP' ), array( 'GBP', 'AUD', 'USD' ) ),
			'code WooCommerce does not know skipped' => array( array( 'ZZZ' ), array( 'USD' ) ),
		);
	}

	/**
	 * @testdox Should keep a customer currency option after the merchant stops offering the currency.
	 */
	public function test_keeps_customer_currency_option_after_the_currency_is_removed(): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.9' );
		$state_builder = wc_get_container()->get( StateBuilderFactory::class )->get_state_builder();
		$state_builder->reset();
		$sut = new AnalyticsService( $state_builder );
		$sut->record_customer_currency( 'EUR' );

		update_option( 'wcpay_multi_currency_enabled_currencies', array() );
		$state_builder->reset();

		$this->assertArrayNotHasKey( 'EUR', $state_builder->build()->get_available_currencies(), 'Without a rate provider, a removed manual currency is not available.' );
		$this->assertSame(
			array(
				array(
					'label' => html_entity_decode( get_woocommerce_currencies()['EUR'] ),
					'value' => 'EUR',
				),
				array(
					'label' => html_entity_decode( get_woocommerce_currencies()['USD'] ),
					'value' => 'USD',
				),
			),
			$sut->get_customer_currency_options()
		);
	}

	/**
	 * Create order stats args.
	 *
	 * @return array<string,float>
	 */
	private function create_order_stats_args(): array {
		return array(
			'net_total'      => 10.0,
			'shipping_total' => 3.0,
			'tax_total'      => 2.0,
			'total_sales'    => 15.0,
		);
	}

	/**
	 * Create order stats args with only a net total.
	 *
	 * @param float $net_total Net total in the order currency.
	 * @return array<string,float>
	 */
	private function create_net_total_args( float $net_total ): array {
		return array(
			'net_total'      => $net_total,
			'shipping_total' => 0.0,
			'tax_total'      => 0.0,
			'total_sales'    => $net_total,
		);
	}

	/**
	 * Create a GBP order stamped for a USD store with the given saved rates, as order meta holds them.
	 *
	 * @param mixed $order_rate     Saved order rate.
	 * @param mixed $processor_rate Saved processor rate.
	 * @return \WC_Order
	 */
	private function create_foreign_order( $order_rate, $processor_rate ): \WC_Order {
		$order = wc_create_order();
		$order->set_currency( 'GBP' );
		$order->update_meta_data( PriceConverter::META_KEY_ORDER_DEFAULT_CURRENCY, 'USD' );
		$order->update_meta_data( PriceConverter::META_KEY_ORDER_EXCHANGE_RATE, $order_rate );
		$order->update_meta_data( PriceConverter::META_KEY_STRIPE_EXCHANGE_RATE, $processor_rate );

		return $order;
	}

	/**
	 * Capture what WooCommerce logs for the rest of the test.
	 *
	 * @return \WC_Logger Logger whose `entries` hold each entry's level, message and source.
	 */
	private function capture_log(): \WC_Logger {
		$logger = new class() extends \WC_Logger {
			/**
			 * Logged entries.
			 *
			 * @var array<int,array{string,string,string}>
			 */
			public array $entries = array();

			/**
			 * Record an entry.
			 *
			 * @param string              $level   Level.
			 * @param string              $message Message.
			 * @param array<string,mixed> $context Context.
			 */
			public function log( $level, $message, $context = array() ) {
				$this->entries[] = array( $level, $message, (string) ( $context['source'] ?? '' ) );
			}
		};
		add_filter( 'woocommerce_logging_class', static fn() => $logger );

		return $logger;
	}

	/**
	 * Register the storefront currency filters as a storefront request on a store where WooCommerce runs Multi-Currency does.
	 */
	private function register_frontend_currencies(): void {
		$arbiter    = new class() extends MultiCurrencyRuntimeArbiter {
			/**
			 * Constructor.
			 */
			public function __construct() {}

			/**
			 * Tell whether WooCommerce runs Multi-Currency.
			 *
			 * @return bool
			 */
			public function is_builtin_owner(): bool {
				return true;
			}
		};
		$controller = new MultiCurrencyFrontendCurrenciesController();
		$controller->init( $arbiter, wc_get_container()->get( ServiceFactory::class ), wc_get_container()->get( RequestContext::class ), wc_get_container()->get( OrderContextService::class ) );
		$controller->register();
	}

	/**
	 * Create an order with multi-currency meta.
	 *
	 * @param string      $currency             Order currency.
	 * @param string|null $order_exchange_rate  Order exchange rate.
	 * @param string|null $default_currency     Default currency.
	 * @param string|null $stripe_exchange_rate Stripe exchange rate.
	 * @return \WC_Order
	 */
	private function create_order(
		string $currency,
		?string $order_exchange_rate,
		?string $default_currency,
		?string $stripe_exchange_rate = null
	): \WC_Order {
		$order = wc_create_order();
		$order->set_currency( $currency );

		if ( null !== $order_exchange_rate ) {
			$order->update_meta_data( PriceConverter::META_KEY_ORDER_EXCHANGE_RATE, $order_exchange_rate );
		}

		if ( null !== $default_currency ) {
			$order->update_meta_data( PriceConverter::META_KEY_ORDER_DEFAULT_CURRENCY, $default_currency );
		}

		if ( null !== $stripe_exchange_rate ) {
			$order->update_meta_data( PriceConverter::META_KEY_STRIPE_EXCHANGE_RATE, $stripe_exchange_rate );
		}

		$order->save();

		return $order;
	}

	/**
	 * Create the analytics projection service.
	 *
	 * @param MultiCurrencyState $state Multi-currency state.
	 * @return AnalyticsService
	 */
	private function create_service( MultiCurrencyState $state ): AnalyticsService {
		return new AnalyticsService( $this->create_state_builder( $state ) );
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
	 * @param string   $selected_code       Selected currency code.
	 * @param string[] $customer_currencies Customer-used currencies.
	 * @return MultiCurrencyState
	 */
	private function create_state( string $selected_code = 'USD', array $customer_currencies = array() ): MultiCurrencyState {
		$usd = $this->create_currency( 'USD', 1.0, true );
		$gbp = $this->create_currency( 'GBP', 0.5 );
		$jpy = $this->create_currency( 'JPY', 151.0 );

		$available = array(
			'USD' => $usd,
			'GBP' => $gbp,
			'JPY' => $jpy,
		);

		return new MultiCurrencyState( $available, $available, $usd, $available[ $selected_code ], $customer_currencies );
	}

	/**
	 * Create a currency.
	 *
	 * @param string $code       Currency code.
	 * @param float  $rate       Currency rate.
	 * @param bool   $is_default Whether this is the default currency.
	 * @return MultiCurrencyCurrency
	 */
	private function create_currency( string $code, float $rate, bool $is_default = false ): MultiCurrencyCurrency {
		return new MultiCurrencyCurrency( $this->create_localization(), $code, $rate, $is_default );
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
}
