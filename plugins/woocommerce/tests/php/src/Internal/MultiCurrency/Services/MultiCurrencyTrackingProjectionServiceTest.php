<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyLocalizationInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyTrackingProjectionService;
use Automattic\WooCommerce\RestApi\UnitTests\HPOSToggleTrait;
use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyTrackingProjectionService class.
 */
class MultiCurrencyTrackingProjectionServiceTest extends WC_Unit_Test_Case {

	use HPOSToggleTrait;

	/**
	 * Original store currency.
	 *
	 * @var string
	 */
	private string $original_currency;

	/**
	 * Whether HPOS was authoritative before the test.
	 *
	 * @var bool
	 */
	private bool $original_hpos_enabled;

	/**
	 * Ensure the permanent HPOS tables exist before per-test transactions start.
	 */
	public static function wpSetUpBeforeClass(): void {
		self::setup_cot_tables();
	}

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->original_currency     = get_option( 'woocommerce_currency', 'USD' );
		$this->original_hpos_enabled = OrderUtil::custom_orders_table_usage_is_enabled();
		update_option( 'woocommerce_currency', 'USD' );
		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		$this->toggle_cot_authoritative( $this->original_hpos_enabled );
		remove_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		update_option( 'woocommerce_currency', $this->original_currency );
		delete_option( 'wcpay_multi_currency_exchange_rate_gbp' );
		delete_option( 'wcpay_multi_currency_exchange_rate_jpy' );

		parent::tear_down();
	}

	/**
	 * @testdox Should project tracker data with enabled non-default currencies.
	 */
	public function test_projects_tracker_data_with_enabled_non_default_currencies(): void {
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'automatic' );
		update_option( 'wcpay_multi_currency_exchange_rate_jpy', 'manual' );

		$sut = $this->create_service( $this->create_state() );

		$result  = $sut->project_tracker_data( array( 'existing' => 'value' ), OrderUtil::custom_orders_table_usage_is_enabled() );
		$payload = $result[ MultiCurrencyTrackingProjectionService::TRACKER_KEY ];

		$this->assertSame( 'value', $result['existing'] );
		$this->assertSame(
			array(
				'code' => 'USD',
				'name' => html_entity_decode( get_woocommerce_currencies()['USD'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ),
			),
			$payload['default_currency']
		);
		$this->assertSame( array( 'GBP', 'JPY' ), array_keys( $payload['enabled_currencies'] ) );
		$this->assertSame( 'automatic (default)', $payload['enabled_currencies']['GBP']['rate_type'] );
		$this->assertSame( 'manual', $payload['enabled_currencies']['JPY']['rate_type'] );
		$this->assertFalse( $payload['enabled_currencies']['GBP']['is_zero_decimal'] );
		$this->assertTrue( $payload['enabled_currencies']['JPY']['is_zero_decimal'] );
	}

	/**
	 * @testdox Should mark default rate rounding and charm values.
	 */
	public function test_marks_default_rate_rounding_and_charm_values(): void {
		$sut = $this->create_service( $this->create_state() );

		$result  = $sut->project_tracker_data( array(), OrderUtil::custom_orders_table_usage_is_enabled() );
		$payload = $result[ MultiCurrencyTrackingProjectionService::TRACKER_KEY ];

		$this->assertSame( '1.00 (default)', $payload['enabled_currencies']['GBP']['price_rounding'] );
		$this->assertSame( '100 (default)', $payload['enabled_currencies']['JPY']['price_rounding'] );
		$this->assertSame( '0.00 (default)', $payload['enabled_currencies']['GBP']['price_charm'] );
	}

	/**
	 * @testdox Should preserve custom rounding and charm values.
	 */
	public function test_preserves_custom_rounding_and_charm_values(): void {
		$state = $this->create_state(
			array(
				'GBP' => array(
					'rounding' => '0.50',
					'charm'    => -0.01,
				),
			)
		);
		$sut   = $this->create_service( $state );

		$result  = $sut->project_tracker_data( array(), OrderUtil::custom_orders_table_usage_is_enabled() );
		$payload = $result[ MultiCurrencyTrackingProjectionService::TRACKER_KEY ];

		$this->assertSame( '0.50', $payload['enabled_currencies']['GBP']['price_rounding'] );
		$this->assertSame( -0.01, $payload['enabled_currencies']['GBP']['price_charm'] );
	}

	/**
	 * @testdox Should report zero order counts without Multi-Currency orders (HPOS: $hpos_enabled).
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $hpos_enabled Whether HPOS is authoritative.
	 */
	public function test_reports_zero_order_counts_without_multi_currency_orders( bool $hpos_enabled ): void {
		$this->toggle_cot_authoritative( $hpos_enabled );
		$this->create_order( 'GBP', 'woocommerce_payments', '10.00', 'completed', false );
		$sut = $this->create_service( $this->create_state() );

		$result  = $sut->project_tracker_data( array(), $hpos_enabled );
		$payload = $result[ MultiCurrencyTrackingProjectionService::TRACKER_KEY ];

		$this->assertSame(
			array(
				'counts'     => 0,
				'currencies' => array(),
			),
			$payload['order_counts']
		);
	}

	/**
	 * @testdox Should count and total completed, processing and refunded Multi-Currency orders by currency and gateway (HPOS: $hpos_enabled).
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $hpos_enabled Whether HPOS is authoritative.
	 */
	public function test_counts_multi_currency_orders_by_currency_and_gateway( bool $hpos_enabled ): void {
		$this->toggle_cot_authoritative( $hpos_enabled );
		$this->create_order( 'GBP', 'woocommerce_payments', '10.50', 'completed' );
		$this->create_order( 'GBP', 'woocommerce_payments', '10.00', 'processing' );
		$this->create_order( 'GBP', 'cod', '5.00', 'completed' );
		$this->create_order( 'EUR', '', '8.00', 'refunded' );
		$this->create_order( 'GBP', 'woocommerce_payments', '99.00', 'pending' );
		$this->create_order( 'GBP', 'woocommerce_payments', '77.00', 'completed', false );
		$sut = $this->create_service( $this->create_state() );

		$result       = $sut->project_tracker_data( array(), $hpos_enabled );
		$order_counts = $result[ MultiCurrencyTrackingProjectionService::TRACKER_KEY ]['order_counts'];
		ksort( $order_counts['currencies'] );
		ksort( $order_counts['currencies']['GBP']['gateways'] );

		$this->assertSame(
			array(
				'counts'     => 4,
				'currencies' => array(
					'EUR' => array(
						'counts'   => 1,
						'totals'   => 8.0,
						'gateways' => array(
							'unknown' => array(
								'counts' => 1,
								'totals' => 8.0,
							),
						),
					),
					'GBP' => array(
						'counts'   => 3,
						'totals'   => 25.5,
						'gateways' => array(
							'cod'                  => array(
								'counts' => 1,
								'totals' => 5.0,
							),
							'woocommerce_payments' => array(
								'counts' => 2,
								'totals' => 20.5,
							),
						),
					),
				),
			),
			$order_counts
		);
	}

	/**
	 * Create and save an order, with the Multi-Currency exchange-rate meta unless told otherwise.
	 *
	 * @param string $currency       Order currency.
	 * @param string $payment_method Payment method id, empty for none.
	 * @param string $total          Order total.
	 * @param string $status         Order status.
	 * @param bool   $with_rate      Whether to record an exchange rate.
	 */
	private function create_order( string $currency, string $payment_method, string $total, string $status, bool $with_rate = true ): void {
		$order = new \WC_Order();
		$order->set_currency( $currency );
		$order->set_payment_method( $payment_method );
		$order->set_total( $total );
		$order->set_status( $status );
		if ( $with_rate ) {
			$order->update_meta_data( '_wcpay_multi_currency_order_exchange_rate', '0.8' );
		}
		$order->save();
	}

	/**
	 * Create the tracking projection service.
	 *
	 * @param MultiCurrencyState $state Multi-currency state.
	 * @return MultiCurrencyTrackingProjectionService
	 */
	private function create_service( MultiCurrencyState $state ): MultiCurrencyTrackingProjectionService {
		return new MultiCurrencyTrackingProjectionService( $this->create_state_builder( $state ) );
	}

	/**
	 * Create a state builder test double.
	 *
	 * @param MultiCurrencyState $state Multi-currency state.
	 * @return MultiCurrencyStateBuilder
	 */
	private function create_state_builder( MultiCurrencyState $state ): MultiCurrencyStateBuilder {
		return new class( $state ) extends MultiCurrencyStateBuilder {
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
	 * @param array<string,array<string,mixed>> $overrides Currency setting overrides.
	 * @return MultiCurrencyState
	 */
	private function create_state( array $overrides = array() ): MultiCurrencyState {
		$usd = $this->create_currency( 'USD', 1.0, true, $overrides['USD'] ?? array() );
		$gbp = $this->create_currency( 'GBP', 0.5, false, $overrides['GBP'] ?? array() );
		$jpy = $this->create_currency( 'JPY', 151.0, false, $overrides['JPY'] ?? array() );

		$enabled = array(
			'USD' => $usd,
			'GBP' => $gbp,
			'JPY' => $jpy,
		);

		return new MultiCurrencyState( $enabled, $enabled, $usd, $usd );
	}

	/**
	 * Create a currency.
	 *
	 * @param string              $code        Currency code.
	 * @param float               $rate        Currency rate.
	 * @param bool                $is_default  Whether this is the default currency.
	 * @param array<string,mixed> $overrides   Currency setting overrides.
	 * @return MultiCurrencyCurrency
	 */
	private function create_currency( string $code, float $rate, bool $is_default, array $overrides ): MultiCurrencyCurrency {
		$currency = new MultiCurrencyCurrency( $this->create_localization(), $code, $rate, $is_default );
		$currency->set_rounding( (string) ( $overrides['rounding'] ?? ( $currency->get_is_zero_decimal() ? '100' : '1.00' ) ) );
		$currency->set_charm( $overrides['charm'] ?? 0.0 );

		return $currency;
	}

	/**
	 * Create a localization test double.
	 *
	 * @return MultiCurrencyLocalizationInterface
	 */
	private function create_localization(): MultiCurrencyLocalizationInterface {
		return new class() implements MultiCurrencyLocalizationInterface {
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
					'num_decimals' => 'JPY' === $currency_code ? 0 : 2,
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
