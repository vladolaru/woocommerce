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
	 * Count the error lines written under the WooPayments log source. The filter runs once per log handler, so each line
	 * is counted once.
	 *
	 * @return int
	 */
	private function count_woopayments_errors(): int {
		$errors = array_filter(
			$this->log_lines,
			static fn( array $line ): bool => 'error' === $line['level'] && 'woopayments' === ( $line['context']['source'] ?? null )
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
		$this->assertSame( 1, $this->count_woopayments_errors() );
		$this->assertStringContainsString( 'element currency: usd, order currency: eur', $this->log_lines[0]['message'] );
	}

	/**
	 * @dataProvider provider_non_currency_code_headers
	 * @testdox Should still reject a mismatch, but keep a currency header that is not a currency code out of the log line ($_dataName).
	 *
	 * Any caller can send the header; only a three-letter code is copied into the store's log. In PCRE, `$` also matches
	 * before a final line break, so the code check must end at the very end of the string.
	 *
	 * @param string $header Payment-currency header value.
	 */
	public function test_keeps_a_header_that_is_not_a_currency_code_out_of_the_log( string $header ): void {
		$order = WC_Helper_Order::create_order();
		$order->set_currency( 'EUR' );

		$request = $this->create_request( $this->ece_headers( $header ) );

		try {
			$this->sut->assert_currency_matches_element( $order, $request );
			$this->fail( 'Expected a RouteException for the currency mismatch.' );
		} catch ( RouteException $exception ) {
			$this->assertSame( 'wcpay_express_checkout_currency_mismatch', $exception->getErrorCode() );
		}
		$this->assertSame( 1, $this->count_woopayments_errors() );
		$this->assertStringContainsString( 'element currency: (not a currency code), order currency: eur', $this->log_lines[0]['message'] );
		$this->assertStringNotContainsString( "\n", $this->log_lines[0]['message'] );
		foreach ( $this->log_lines as $line ) {
			$this->assertStringNotContainsString( 'forged log line', $line['message'] );
		}
	}

	/**
	 * Header values that carry a currency code but are not one.
	 *
	 * @return array<string,array{string}>
	 */
	public function provider_non_currency_code_headers(): array {
		return array(
			'forged log line'     => array( "usd\nforged log line" ),
			'trailing line break' => array( "usd\n" ),
		);
	}

	/**
	 * @dataProvider provider_requests_the_guard_lets_through
	 * @testdox Should let order placement through without logging when the guard has nothing to reject ($_dataName).
	 *
	 * @param string               $order_currency  Order currency.
	 * @param array<string,string> $headers         Request headers.
	 * @param bool                 $add_valid_nonce Whether to add a valid tokenized-cart nonce header.
	 */
	public function test_lets_order_placement_through( string $order_currency, array $headers, bool $add_valid_nonce ): void {
		$order = WC_Helper_Order::create_order();
		$order->set_currency( $order_currency );
		if ( $add_valid_nonce ) {
			$headers['X-WooPayments-Tokenized-Cart-Nonce'] = wp_create_nonce( 'woopayments_tokenized_cart_nonce' );
		}

		$request = $this->create_request( $headers );

		$this->sut->assert_currency_matches_element( $order, $request );
		$this->assertSame( 0, $this->count_woopayments_errors() );
	}

	/**
	 * Requests the guard lets through: matching currencies, no currency header, not express checkout, an invalid nonce.
	 *
	 * @return array<string,array{string,array<string,string>,bool}>
	 */
	public function provider_requests_the_guard_lets_through(): array {
		return array(
			'currencies agree regardless of case'  => array(
				'USD',
				array(
					'X-WooPayments-Tokenized-Cart'   => 'true',
					'X-WooPayments-Payment-Currency' => 'usd',
				),
				true,
			),
			'no payment-currency header'           => array( 'EUR', array( 'X-WooPayments-Tokenized-Cart' => 'true' ), true ),
			'not an express checkout request'      => array( 'EUR', array( 'X-WooPayments-Payment-Currency' => 'usd' ), false ),
			'tokenized-cart nonce does not verify' => array(
				'EUR',
				array(
					'X-WooPayments-Tokenized-Cart'       => 'true',
					'X-WooPayments-Tokenized-Cart-Nonce' => 'not-a-valid-nonce',
					'X-WooPayments-Payment-Currency'     => 'usd',
				),
				false,
			),
		);
	}
}
