<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAmountMismatchHold;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeeDetailsNoteController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDuplicatePaymentPreventionService;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for WooPaymentsAmountMismatchHold.
 */
class WooPaymentsAmountMismatchHoldTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsAmountMismatchHold
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = wc_get_container()->get( WooPaymentsAmountMismatchHold::class );
	}

	/**
	 * @testdox A payment is compared to the saved total even when the request rounds the order on read.
	 * @testWith ["10.40", 1040, "eur", false]
	 *           ["10.40", 900, "eur", true]
	 *           ["10.40", 1000, "eur", true]
	 *           ["10.60", 1060, "eur", false]
	 *           ["10.40", 1040, "gbp", true]
	 *
	 * @param string $total    Saved EUR total.
	 * @param int    $amount   Amount charged in minor units.
	 * @param string $currency Currency charged.
	 * @param bool   $held     Whether the payment differs from the saved total.
	 */
	public function test_compares_the_saved_total_in_a_lower_precision_request( string $total, int $amount, string $currency, bool $held ): void {
		$order = $this->create_order( $total, 'EUR' );
		update_option( 'woocommerce_price_num_decimals', '0' );
		$order   = wc_get_container()->get( OrderPaymentLifecycleService::class )->get_fresh_order_from_data_store( $order );
		$rounded = (string) $order->get_total();
		$this->assertSame( number_format( (float) $total, 0 ), $rounded );

		$hold = $this->sut->decide_hold( $order, $this->succeeded_intent( $amount, $currency ) );

		$this->assertSame( $held, null !== $hold );
		if ( null !== $hold ) {
			$this->assertStringContainsString( 'order total is ' . (float) $total . ' EUR.', $hold['log_line'] );
		}
		$this->assertSame( 0, wc_get_price_decimals() );
		$this->assertSame( $rounded, (string) $order->get_total(), 'The capture action still sees the rounded total.' );
	}

	/**
	 * @testdox The separate duplicate-payment check still compares to the total loaded by the current request.
	 */
	public function test_duplicate_payment_check_keeps_the_loaded_amount(): void {
		$order = $this->create_order( '10.40', 'EUR' );
		update_option( 'woocommerce_price_num_decimals', '0' );
		$order   = wc_get_container()->get( OrderPaymentLifecycleService::class )->get_fresh_order_from_data_store( $order );
		$service = wc_get_container()->get( WooPaymentsDuplicatePaymentPreventionService::class );
		$this->assertNull( $service->get_amount_mismatch_error( $this->succeeded_intent( 1000, 'eur' ), $order ) );
	}

	/**
	 * @testdox An ordinary USD store can decide a payment without another order read or a decimals override.
	 */
	public function test_ordinary_store_uses_the_loaded_total(): void {
		$order = $this->create_order( '10.40', 'USD' );
		$reads = did_action( 'clean_post_cache' );
		$seen  = array();
		$watch = static function ( $decimals ) use ( &$seen ) {
			$seen[] = isset( $GLOBALS['wp_filter']['wc_get_price_decimals']->callbacks[ PHP_INT_MAX ] );
			return $decimals;
		};
		add_filter( 'wc_get_price_decimals', $watch );

		try {
			$this->assertNull( $this->sut->decide_hold( $order, $this->succeeded_intent( 1040, 'usd' ) ) );
			$this->assertNotNull( $this->sut->decide_hold( $order, $this->succeeded_intent( 1000, 'usd' ) ) );
		} finally {
			remove_filter( 'wc_get_price_decimals', $watch );
		}
		$this->assertSame( $reads, did_action( 'clean_post_cache' ) );
		$this->assertNotContains( true, $seen );
	}

	/**
	 * @testdox A succeeded PaymentIntent whose amount or currency differs from the order total holds the order with the client's note: $_dataName.
	 *
	 * Client 11.3.0 class-wc-payments-order-service.php:2081-2084 (the comparison) and :2101-2116 (the note), pinned by its
	 * test_order_is_held_when_intent_amount_does_not_match_order_total() and
	 * test_order_is_held_when_intent_currency_does_not_match_order_currency().
	 *
	 * @dataProvider provider_payments_that_differ_from_the_order_total
	 *
	 * @param string $order_currency  Order currency.
	 * @param int    $amount          Intent amount, in minor units.
	 * @param string $intent_currency Intent currency.
	 * @param float  $charged         Charged amount the note names.
	 * @param string $charged_code    Charged currency code the note names.
	 */
	public function test_payment_that_differs_from_the_order_total_holds_the_order( string $order_currency, int $amount, string $intent_currency, float $charged, string $charged_code ): void {
		$order = $this->create_order( '25.19', $order_currency );

		$hold = $this->sut->decide_hold( $order, $this->succeeded_intent( $amount, $intent_currency ) );

		$transaction_url = wc_get_container()->get( WooPaymentsOrderNoteService::class )->transaction_url( 'pi_mismatch', 'ch_mismatch' );
		$this->assertIsArray( $hold );
		$this->assertSame(
			'A payment of ' . wc_price( $charged, array( 'currency' => $charged_code ) ) . ' ' . $charged_code . ' was charged using WooPayments (<a href="' . $transaction_url . '" target="_blank" rel="noopener noreferrer">pi_mismatch</a>), but the order total is ' . wc_price( 25.19, array( 'currency' => $order_currency ) ) . ' ' . $order_currency . '. The order was put <strong>on hold</strong>; review it before fulfilling.',
			$hold['notes'][0]
		);
	}

	/**
	 * Payments that differ from a 25.19 order total.
	 *
	 * @return array<string,array{string,int,string,float,string}>
	 */
	public function provider_payments_that_differ_from_the_order_total(): array {
		return array(
			'amount differs'   => array( 'USD', 2267, 'usd', 22.67, 'USD' ),
			'currency differs' => array( 'CAD', 2519, 'usd', 25.19, 'USD' ),
		);
	}

	/**
	 * @testdox A payment completes its order, with no hold, when $_dataName.
	 *
	 * Client 11.3.0 class-wc-payments-order-service.php:483-491: the capture branch comes first, only a PaymentIntent is
	 * compared, and the currency compares case-insensitively; class-wc-payments-webhook-processing-service.php:711-717
	 * leaves an invoice's intent to the invoice, and the partial-capture case is its
	 * test_partial_capture_completes_order_despite_amount_below_order_total(). The authorization is the one the order
	 * recorded: its stored intent and transaction ID name the payment.
	 *
	 * @dataProvider provider_payments_that_complete
	 *
	 * @param array<string,mixed> $intent                Intent overrides.
	 * @param string              $stored_intent_status Stored intent status of the order.
	 */
	public function test_payment_that_completes_its_order_holds_nothing( array $intent, string $stored_intent_status ): void {
		$order = $this->create_order( '25.19', 'USD' );
		if ( '' !== $stored_intent_status ) {
			$order->set_transaction_id( 'pi_mismatch' );
			$order->update_meta_data( '_intent_id', 'pi_mismatch' );
			$order->update_meta_data( '_intention_status', $stored_intent_status );
			$order->save();
		}

		$this->assertNull( $this->sut->decide_hold( $order, array_merge( $this->succeeded_intent( 2519, 'usd' ), $intent ) ) );
	}

	/**
	 * Payments that complete a 25.19 USD order.
	 *
	 * @return array<string,array{array<string,mixed>,string}>
	 */
	public function provider_payments_that_complete(): array {
		return array(
			'the amount and currency match'                => array( array(), '' ),
			'the currency matches in another case'         => array( array( 'currency' => 'USD' ), '' ),
			'an authorization is captured for less'        => array( array( 'amount' => 2000 ), 'requires_capture' ),
			'a SetupIntent succeeded'                      => array(
				array(
					'id'     => 'seti_mismatch',
					'object' => 'setup_intent',
					'amount' => 2000,
				),
				'',
			),
			'a Stripe Billing invoice paid another amount' => array(
				array(
					'amount'  => 2000,
					'invoice' => 'in_mismatch',
				),
				'',
			),
			'the intent has not succeeded'                 => array(
				array(
					'amount' => 2000,
					'status' => 'requires_capture',
				),
				'',
			),
		);
	}

	/**
	 * @testdox A payment for less than the total is held on an order storing requires_capture unless it is the capture of the authorization the order recorded: $_dataName.
	 *
	 * The capture is exempt only when the order stores requires_capture, the payment is the intent the order stored before
	 * the caller wrote any (the caller's value, or the stored intent when it passes none), and the transaction ID names it.
	 * Client 11.3.0 exempts any order storing requires_capture (class-wc-payments-order-service.php:484-485).
	 *
	 * @testWith ["the recorded authorization's capture", "pi_mismatch", "pi_mismatch", null, false]
	 *           ["the recorded authorization's capture, by the intent stored before the caller's write", "pi_another", "pi_mismatch", "pi_mismatch", false]
	 *           ["no intent and no transaction ID stored", "", "", null, true]
	 *           ["no transaction ID", "pi_mismatch", "", null, true]
	 *           ["a transaction ID naming another attempt", "pi_mismatch", "pi_earlier", null, true]
	 *           ["another intent stored before the caller's write", "pi_mismatch", "pi_mismatch", "pi_earlier", true]
	 *           ["no intent stored before the caller's write", "pi_mismatch", "pi_mismatch", "", true]
	 *
	 * @param string      $case_name        Case name.
	 * @param string      $stored_intent    The intent the order stores now, or '' for none.
	 * @param string      $transaction_id   The order's transaction ID, or '' for none.
	 * @param string|null $stored_intent_id The intent the caller read before its write, or null for none passed.
	 * @param bool        $expected_held    Whether the order is held.
	 */
	public function test_stored_requires_capture_exempts_only_the_recorded_authorizations_capture( string $case_name, string $stored_intent, string $transaction_id, ?string $stored_intent_id, bool $expected_held ): void {
		unset( $case_name );
		$order = $this->create_order( '25.19', 'USD' );
		$order->set_transaction_id( $transaction_id );
		if ( '' !== $stored_intent ) {
			$order->update_meta_data( '_intent_id', $stored_intent );
		}
		$order->update_meta_data( '_intention_status', 'requires_capture' );
		$order->save();

		$hold = $this->sut->decide_hold( $order, $this->succeeded_intent( 2000, 'usd' ), $stored_intent_id );

		$this->assertSame( $expected_held, null !== $hold );
	}

	/**
	 * @testdox Deciding a hold writes no log line; the hold carries the client's line for whoever applies it.
	 *
	 * Client 11.3.0 class-wc-payments-order-service.php:2117-2131 logs only when it adds the hold note.
	 */
	public function test_deciding_a_hold_logs_nothing_and_carries_the_client_line(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$logger = RecordingWcLogger::install();
		$order  = $this->create_order( '25.19', 'USD' );

		$hold = $this->sut->decide_hold( $order, $this->succeeded_intent( 2267, 'usd' ) );

		$this->assertSame( array(), $this->get_hold_log_lines( $logger ) );
		$this->assertSame( 'Order ' . $order->get_id() . ' put on hold: intent pi_mismatch succeeded for 2267 usd, but the order total is 25.19 USD.', $hold['log_line'] ?? null );
	}

	/**
	 * @testdox A held event is logged only when the lifecycle adds its note, not when the order already has it: $_dataName.
	 *
	 * Client 11.3.0 class-wc-payments-order-service.php:2117-2131 logs only when it holds the order.
	 *
	 * @testWith ["the lifecycle adds the note", false, 1]
	 *           ["the order already has the note", true, 0]
	 *
	 * @param string $case_name      Case name.
	 * @param bool   $already_noted  Whether the order already has the hold note.
	 * @param int    $expected_lines Hold log lines expected.
	 */
	public function test_held_event_is_logged_only_when_the_lifecycle_adds_its_note( string $case_name, bool $already_noted, int $expected_lines ): void {
		unset( $case_name );
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$logger = RecordingWcLogger::install();
		$order  = $this->create_order( '25.19', 'USD' );
		$order->set_payment_method( 'woocommerce_payments' );
		$order->save();

		$hold = $this->sut->decide_hold( $order, $this->succeeded_intent( 2267, 'usd' ) );
		if ( $already_noted ) {
			$order->add_order_note( $hold['notes'][0] );
		}
		wc_get_container()->get( WooPaymentsFeeDetailsNoteController::class )->apply_and_schedule_fee_details(
			$order,
			new PaymentLifecycleEvent( PaymentLifecycleEvent::STATUS_COMPLETED, 'pi_mismatch', array(), array(), $hold['notes'][0], PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_HELD, $hold['notes'], false, true ),
			$hold
		);

		$this->assertSame( 1, $this->sut->has_hold_note( $order, $hold ) ? 1 : 0 );
		$this->assertCount( $expected_lines, $this->get_hold_log_lines( $logger ) );
	}

	/**
	 * Get the hold log lines a recording logger holds.
	 *
	 * @param RecordingWcLogger $logger Recording logger.
	 * @return string[]
	 */
	private function get_hold_log_lines( RecordingWcLogger $logger ): array {
		return array_values( array_filter( array_map( 'strval', array_column( $logger->lines, 1 ) ), static fn( string $line ): bool => false !== strpos( $line, 'put on hold: intent' ) ) );
	}

	/**
	 * Build a succeeded PaymentIntent with one charge.
	 *
	 * @param int    $amount   Amount, in minor units.
	 * @param string $currency Currency.
	 * @return array<string,mixed>
	 */
	private function succeeded_intent( int $amount, string $currency ): array {
		return array(
			'id'       => 'pi_mismatch',
			'object'   => 'payment_intent',
			'status'   => 'succeeded',
			'amount'   => $amount,
			'currency' => $currency,
			'charges'  => array( 'data' => array( array( 'id' => 'ch_mismatch' ) ) ),
		);
	}

	/**
	 * Create an order.
	 *
	 * @param string $total    Order total.
	 * @param string $currency Order currency.
	 * @return WC_Order
	 */
	private function create_order( string $total, string $currency ): WC_Order {
		$order = wc_create_order();
		$order->set_currency( $currency );
		$order->set_total( $total );
		$order->save();

		return $order;
	}
}
