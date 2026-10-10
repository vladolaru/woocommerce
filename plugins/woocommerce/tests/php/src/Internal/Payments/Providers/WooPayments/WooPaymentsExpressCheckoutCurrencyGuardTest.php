<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutCurrencyGuard;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use WC_Helper_Order;
use WC_Unit_Test_Case;
use WP_REST_Request;

/**
 * Tests for the WooPaymentsExpressCheckoutCurrencyGuard class.
 */
class WooPaymentsExpressCheckoutCurrencyGuardTest extends WC_Unit_Test_Case {

	/**
	 * System under test.
	 *
	 * @var WooPaymentsExpressCheckoutCurrencyGuard
	 */
	private WooPaymentsExpressCheckoutCurrencyGuard $sut;

	/**
	 * WooCommerce log lines written during the test, once per log handler.
	 *
	 * @var array<int,array{message:string,level:string,context:array<string,mixed>}>
	 */
	private array $log_lines = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = wc_get_container()->get( WooPaymentsExpressCheckoutCurrencyGuard::class );
		add_filter( 'woocommerce_logger_log_message', array( $this, 'record_log_line' ), 10, 3 );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_filter( 'woocommerce_logger_log_message', array( $this, 'record_log_line' ), 10 );
		parent::tearDown();
	}

	/**
	 * Record a WooCommerce log line.
	 *
	 * @param string              $message Message.
	 * @param string              $level   Level.
	 * @param array<string,mixed> $context Context.
	 * @return string
	 */
	public function record_log_line( $message, $level, $context ) {
		$this->log_lines[] = array(
			'message' => (string) $message,
			'level'   => (string) $level,
			'context' => is_array( $context ) ? $context : array(),
		);

		return $message;
	}

	/**
	 * Count the error lines written under the payment-info source. The filter runs once per log handler, so each line
	 * is counted once.
	 *
	 * @return int
	 */
	private function count_payment_info_errors(): int {
		$errors = array_filter(
			$this->log_lines,
			static fn( array $line ): bool => 'error' === $line['level'] && 'payment-info' === ( $line['context']['source'] ?? null )
		);

		return count( array_unique( array_column( $errors, 'message' ) ) );
	}

	/**
	 * Build a Store API checkout request shaped like an ECE order placement.
	 *
	 * @param array<string,string> $headers Headers to set on the request.
	 * @return WP_REST_Request
	 */
	private function create_request( array $headers = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );

		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return $request;
	}

	/**
	 * Build the header set of a well-formed ECE request.
	 *
	 * @param string $currency Element boot currency to carry, empty to omit the header.
	 * @return array<string,string>
	 */
	private function ece_headers( string $currency = '' ): array {
		$headers = array(
			'X-WooPayments-Tokenized-Cart'       => 'true',
			'X-WooPayments-Tokenized-Cart-Nonce' => wp_create_nonce( 'woopayments_tokenized_cart_nonce' ),
		);

		if ( '' !== $currency ) {
			$headers['X-WooPayments-Payment-Currency'] = $currency;
		}

		return $headers;
	}

	/**
	 * @testdox Should reject order placement when the order currency drifted away from the element boot currency.
	 */
	public function test_throws_route_exception_on_currency_mismatch(): void {
		$order = WC_Helper_Order::create_order();
		$order->set_currency( 'EUR' );

		$request = $this->create_request( $this->ece_headers( 'usd' ) );

		try {
			$this->sut->assert_currency_matches_element( $order, $request );
			$this->fail( 'Expected a RouteException for the currency mismatch.' );
		} catch ( RouteException $exception ) {
			$this->assertSame( 'wcpay_express_checkout_currency_mismatch', $exception->getErrorCode() );
			$this->assertSame( 400, $exception->getCode() );
		}
		$this->assertSame( 1, $this->count_payment_info_errors() );
		$this->assertStringContainsString( 'element currency: usd, order currency: eur', $this->log_lines[0]['message'] );
	}

	/**
	 * @testdox Should still reject a mismatch, but keep a currency header that is not a currency code out of the log line.
	 *
	 * Any caller can send the header; only a three-letter code is copied into the store's log.
	 */
	public function test_keeps_a_header_that_is_not_a_currency_code_out_of_the_log(): void {
		$order = WC_Helper_Order::create_order();
		$order->set_currency( 'EUR' );

		$request = $this->create_request( $this->ece_headers( "usd\nforged log line" ) );

		try {
			$this->sut->assert_currency_matches_element( $order, $request );
			$this->fail( 'Expected a RouteException for the currency mismatch.' );
		} catch ( RouteException $exception ) {
			$this->assertSame( 'wcpay_express_checkout_currency_mismatch', $exception->getErrorCode() );
		}
		$this->assertSame( 1, $this->count_payment_info_errors() );
		foreach ( $this->log_lines as $line ) {
			$this->assertStringNotContainsString( 'forged log line', $line['message'] );
		}
	}

	/**
	 * @testdox Should allow order placement when the element and order currencies agree regardless of case.
	 */
	public function test_allows_matching_currency(): void {
		$order = WC_Helper_Order::create_order();
		$order->set_currency( 'USD' );

		$request = $this->create_request( $this->ece_headers( 'usd' ) );

		$this->sut->assert_currency_matches_element( $order, $request );
		$this->assertSame( 0, $this->count_payment_info_errors() );
	}

	/**
	 * @testdox Should fail open when no payment-currency header was sent.
	 */
	public function test_fails_open_without_currency_header(): void {
		$order = WC_Helper_Order::create_order();
		$order->set_currency( 'EUR' );

		$request = $this->create_request( $this->ece_headers() );

		$this->sut->assert_currency_matches_element( $order, $request );
		$this->assertSame( 0, $this->count_payment_info_errors() );
	}

	/**
	 * @testdox Should ignore Store API requests that did not originate from express checkout.
	 */
	public function test_ignores_non_express_checkout_requests(): void {
		$order = WC_Helper_Order::create_order();
		$order->set_currency( 'EUR' );

		$request = $this->create_request(
			array( 'X-WooPayments-Payment-Currency' => 'usd' )
		);

		$this->sut->assert_currency_matches_element( $order, $request );
		$this->assertSame( 0, $this->count_payment_info_errors() );
	}

	/**
	 * @testdox Should ignore express-checkout-shaped requests whose tokenized-cart nonce does not verify.
	 */
	public function test_ignores_requests_with_invalid_nonce(): void {
		$order = WC_Helper_Order::create_order();
		$order->set_currency( 'EUR' );

		$request = $this->create_request(
			array(
				'X-WooPayments-Tokenized-Cart'       => 'true',
				'X-WooPayments-Tokenized-Cart-Nonce' => 'not-a-valid-nonce',
				'X-WooPayments-Payment-Currency'     => 'usd',
			)
		);

		$this->sut->assert_currency_matches_element( $order, $request );
		$this->assertSame( 0, $this->count_payment_info_errors() );
	}
}
