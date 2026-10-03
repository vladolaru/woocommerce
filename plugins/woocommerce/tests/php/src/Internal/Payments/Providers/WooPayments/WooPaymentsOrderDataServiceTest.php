<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\ClientRenderedCapturedEvents;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsOrderDataService class.
 */
class WooPaymentsOrderDataServiceTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsOrderDataService
	 */
	private WooPaymentsOrderDataService $sut;

	/**
	 * Original store currency.
	 *
	 * @var string
	 */
	private string $original_currency;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->original_currency = (string) get_option( 'woocommerce_currency', 'USD' );
		$this->sut               = wc_get_container()->get( WooPaymentsOrderDataService::class );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		update_option( 'woocommerce_currency', $this->original_currency );
		parent::tearDown();
	}

	/**
	 * @testdox Billing details preserve the WooPayments order-to-Stripe address shape.
	 */
	public function test_get_billing_data_from_order_preserves_woopayments_shape(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );

		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_last_name( 'Lovelace' );
		$order->set_billing_email( 'ada@example.com' );
		$order->set_billing_phone( '+15555550123' );
		$order->set_billing_address_1( '1 Main St' );
		$order->set_billing_address_2( 'Suite 2' );
		$order->set_billing_city( 'Austin' );
		$order->set_billing_state( 'TX' );
		$order->set_billing_postcode( '78701' );
		$order->set_billing_country( 'US' );

		$this->assertSame(
			array(
				'address' => array(
					'country'     => 'US',
					'line1'       => '1 Main St',
					'line2'       => 'Suite 2',
					'city'        => 'Austin',
					'state'       => 'TX',
					'postal_code' => '78701',
				),
				'email'   => 'ada@example.com',
				'phone'   => '+15555550123',
				'name'    => 'Ada Lovelace',
			),
			$this->sut->get_billing_data_from_order( $order )
		);
	}

	/**
	 * Fee-breakdown notes from recorded captured timeline events read exactly as client 11.1.0 renders them.
	 *
	 * @dataProvider recorded_captured_event_names
	 *
	 * @param string $name Case name in `Fixtures/rec-n296-captured-event-notes.json`.
	 */
	public function test_get_fee_breakdown_note_from_timeline_event_renders_the_client_note( string $name ): void {
		$case = ClientRenderedCapturedEvents::get( $name );

		$this->assertSame( '<strong>Fee details:</strong>' . $case['client_html'], $this->sut->get_fee_breakdown_note_from_timeline_event( $case['event'] ) );
	}

	/**
	 * Recorded REC-5a captured events: one without conversion, one converted from EUR.
	 *
	 * @return array<string,array{string}>
	 */
	public function recorded_captured_event_names(): array {
		return array(
			'USD, no conversion' => array( 'recorded-rec-5a:usd_full_refund_free_text_reason' ),
			'converted from EUR' => array( 'recorded-rec-5a:eur_full_refund' ),
		);
	}

	/**
	 * @testdox Settlement exchange-rate meta should preserve Stripe conversion rates for converted-currency orders.
	 * @dataProvider settlement_exchange_rate_provider
	 *
	 * @param string $store_currency Store default currency.
	 * @param string $order_currency Order presentment currency.
	 * @param string $account_currency WooPayments account default currency.
	 * @param float  $provider_exchange_rate Stripe exchange rate.
	 * @param string $expected_exchange_rate Expected order meta exchange rate.
	 */
	public function test_get_settlement_exchange_rate_order_meta_preserves_provider_rate_for_converted_order( string $store_currency, string $order_currency, string $account_currency, float $provider_exchange_rate, string $expected_exchange_rate ): void {
		update_option( 'woocommerce_currency', $store_currency );
		$order = $this->create_order_with_currency( $order_currency );

		$meta = $this->sut->get_settlement_exchange_rate_order_meta(
			$order,
			array(
				'balance_transaction' => array(
					'exchange_rate' => $provider_exchange_rate,
				),
			),
			$account_currency
		);

		$this->assertSame(
			array(
				'_wcpay_multi_currency_stripe_exchange_rate' => $expected_exchange_rate,
			),
			$meta
		);
	}

	/**
	 * Data provider for settlement exchange-rate meta tests.
	 *
	 * @return array<string,array{string,string,string,float,string}>
	 */
	public function settlement_exchange_rate_provider(): array {
		return array(
			'two-decimal presentment and account currencies' => array( 'USD', 'GBP', 'usd', 1.33127, '1.33127' ),
			'zero-decimal presentment currency' => array( 'USD', 'JPY', 'usd', 0.63, '0.0063' ),
			'integer interpreted rate'          => array( 'USD', 'JPY', 'usd', 1000.0, '10' ),
			'zero-decimal account currency'     => array( 'JPY', 'USD', 'jpy', 0.0063, '0.63' ),
		);
	}

	/**
	 * @testdox Settlement exchange-rate meta should be omitted when store and account default currencies differ.
	 */
	public function test_get_settlement_exchange_rate_order_meta_skips_provider_rate_when_store_and_account_defaults_differ(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		$order = $this->create_order_with_currency( 'GBP' );

		$meta = $this->sut->get_settlement_exchange_rate_order_meta(
			$order,
			array(
				'balance_transaction' => array(
					'exchange_rate' => 1.33127,
				),
			),
			'usd'
		);

		$this->assertSame( array(), $meta );
	}

	/**
	 * @testdox Settlement exchange-rate meta should be omitted when the order and account currencies are equal.
	 *
	 * Mirrors client 11.1.0 `attach_exchange_info_to_order` (`gw:2776-2804`, specifically `gw:2792`):
	 * the client only requests/attaches a Stripe exchange rate when `$currency_order !== $currency_account`,
	 * because a same-currency order was never converted and has no meaningful rate to record. This is the
	 * one branch neither existing data-provider row (all converted-currency pairs) nor the
	 * store/account-mismatch case above exercises.
	 */
	public function test_get_settlement_exchange_rate_order_meta_skips_provider_rate_when_order_and_account_currencies_are_equal(): void {
		update_option( 'woocommerce_currency', 'USD' );
		$order = $this->create_order_with_currency( 'USD' );

		$meta = $this->sut->get_settlement_exchange_rate_order_meta(
			$order,
			array(
				'balance_transaction' => array(
					'exchange_rate' => 1.0,
				),
			),
			'usd'
		);

		$this->assertSame( array(), $meta );
	}

	/**
	 * Create an order in the given currency.
	 *
	 * @param string $currency Currency code.
	 * @return WC_Order
	 */
	private function create_order_with_currency( string $currency ): WC_Order {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_currency( $currency );
		$order->save();

		return $order;
	}
}
