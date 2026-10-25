<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyLocalizationInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyTrackerDataBuilder;
use Automattic\WooCommerce\RestApi\UnitTests\HPOSToggleTrait;
use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyTrackerDataBuilder class.
 */
class MultiCurrencyTrackerDataBuilderTest extends WC_Unit_Test_Case {

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

		$result  = $sut->build( array( 'existing' => 'value' ), OrderUtil::custom_orders_table_usage_is_enabled() );
		$payload = $result[ MultiCurrencyTrackerDataBuilder::TRACKER_KEY ];

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

		$result  = $sut->build( array(), OrderUtil::custom_orders_table_usage_is_enabled() );
		$payload = $result[ MultiCurrencyTrackerDataBuilder::TRACKER_KEY ];

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

		$result  = $sut->build( array(), OrderUtil::custom_orders_table_usage_is_enabled() );
		$payload = $result[ MultiCurrencyTrackerDataBuilder::TRACKER_KEY ];

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

		$result  = $sut->build( array(), $hpos_enabled );
		$payload = $result[ MultiCurrencyTrackerDataBuilder::TRACKER_KEY ];

		$this->assertSame(
			array(
				'counts'     => 0,
				'currencies' => array(),
			),
			$payload['order_counts']
		);
	}

	/**
	 * @testdox Should count and total completed, processing and refunded Multi-Currency orders by currency and gateway, as the client does (HPOS: $hpos_enabled).
	 *
	 * Client 11.1.0 `includes/multi-currency/Tracking.php:177-197`: the currency counts and totals add the query's values to 0
	 * (so a whole-number posts total stays an int), only a NULL gateway becomes 'unknown', and each gateway bucket keeps the
	 * query's strings. The strings are how MySQL returns COUNT() and SUM() for each store: the HPOS total is DECIMAL(26,8),
	 * the posts total a meta string. An order saved with an empty payment method keeps '' in the HPOS column but stores no
	 * `_payment_method` meta in the posts store, where the query then reads NULL.
	 *
	 * @testWith [true, "20.50000000", "5.00000000", "", "8.00000000", "300.00000000"]
	 *           [false, "20.5", "5", "unknown", "8", "300"]
	 *
	 * @param bool   $hpos_enabled Whether HPOS is authoritative.
	 * @param string $gbp_card     The GBP woocommerce_payments bucket total, as the query returns it.
	 * @param string $gbp_cod      The GBP cod bucket total, as the query returns it.
	 * @param string $eur_gateway  The EUR bucket of the order saved with an empty payment method.
	 * @param string $eur_none     The EUR bucket total, as the query returns it.
	 * @param string $jpy_null     The JPY NULL-gateway bucket total, as the query returns it.
	 */
	public function test_counts_multi_currency_orders_by_currency_and_gateway( bool $hpos_enabled, string $gbp_card, string $gbp_cod, string $eur_gateway, string $eur_none, string $jpy_null ): void {
		$this->toggle_cot_authoritative( $hpos_enabled );
		$this->create_order( 'GBP', 'woocommerce_payments', '10.50', 'completed' );
		$this->create_order( 'GBP', 'woocommerce_payments', '10.00', 'processing' );
		$this->create_order( 'GBP', 'cod', '5.00', 'completed' );
		$this->create_order( 'EUR', '', '8.00', 'refunded' );
		$this->clear_payment_method( $this->create_order( 'JPY', 'cod', '300', 'completed' ), $hpos_enabled );
		$this->create_order( 'GBP', 'woocommerce_payments', '99.00', 'pending' );
		$this->create_order( 'GBP', 'woocommerce_payments', '77.00', 'completed', false );
		$sut = $this->create_service( $this->create_state() );

		$result       = $sut->build( array(), $hpos_enabled );
		$order_counts = $result[ MultiCurrencyTrackerDataBuilder::TRACKER_KEY ]['order_counts'];
		ksort( $order_counts['currencies'] );
		ksort( $order_counts['currencies']['GBP']['gateways'] );

		$this->assertSame(
			array(
				'counts'     => 5,
				'currencies' => array(
					'EUR' => array(
						'counts'   => 1,
						'totals'   => 0 + $eur_none,
						'gateways' => array(
							$eur_gateway => array(
								'counts' => '1',
								'totals' => $eur_none,
							),
						),
					),
					'GBP' => array(
						'counts'   => 3,
						'totals'   => 25.5,
						'gateways' => array(
							'cod'                  => array(
								'counts' => '1',
								'totals' => $gbp_cod,
							),
							'woocommerce_payments' => array(
								'counts' => '2',
								'totals' => $gbp_card,
							),
						),
					),
					'JPY' => array(
						'counts'   => 1,
						'totals'   => 0 + $jpy_null,
						'gateways' => array(
							'unknown' => array(
								'counts' => '1',
								'totals' => $jpy_null,
							),
						),
					),
				),
			),
			$order_counts
		);
	}

	/**
	 * Leave an order with no stored payment method, as an order from before WooCommerce stored one has: no
	 * `_payment_method` meta in the posts store, a NULL `payment_method` column in the HPOS store.
	 *
	 * @param int  $order_id     Order id.
	 * @param bool $hpos_enabled Whether HPOS is authoritative.
	 */
	private function clear_payment_method( int $order_id, bool $hpos_enabled ): void {
		global $wpdb;

		if ( $hpos_enabled ) {
			$wpdb->update( OrdersTableDataStore::get_orders_table_name(), array( 'payment_method' => null ), array( 'id' => $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture.
		} else {
			delete_post_meta( $order_id, '_payment_method' );
		}
	}

	/**
	 * Create and save an order, with the Multi-Currency exchange-rate meta unless told otherwise.
	 *
	 * @param string $currency       Order currency.
	 * @param string $payment_method Payment method id, empty for none.
	 * @param string $total          Order total.
	 * @param string $status         Order status.
	 * @param bool   $with_rate      Whether to record an exchange rate.
	 * @return int The order id.
	 */
	private function create_order( string $currency, string $payment_method, string $total, string $status, bool $with_rate = true ): int {
		$order = new \WC_Order();
		$order->set_currency( $currency );
		$order->set_payment_method( $payment_method );
		$order->set_total( $total );
		$order->set_status( $status );
		if ( $with_rate ) {
			$order->update_meta_data( '_wcpay_multi_currency_order_exchange_rate', '0.8' );
		}

		return $order->save();
	}

	/**
	 * Create the tracking projection service.
	 *
	 * @param MultiCurrencyState $state Multi-currency state.
	 * @return MultiCurrencyTrackerDataBuilder
	 */
	private function create_service( MultiCurrencyState $state ): MultiCurrencyTrackerDataBuilder {
		return new MultiCurrencyTrackerDataBuilder( $this->create_state_builder( $state ) );
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
