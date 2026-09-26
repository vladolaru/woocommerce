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
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = wc_get_container()->get( WooPaymentsExpressCheckoutCurrencyGuard::class );
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
	}

	/**
	 * @testdox Should allow order placement when the element and order currencies agree regardless of case.
	 */
	public function test_allows_matching_currency(): void {
		$order = WC_Helper_Order::create_order();
		$order->set_currency( 'USD' );

		$request = $this->create_request( $this->ece_headers( 'usd' ) );

		$this->sut->assert_currency_matches_element( $order, $request );
		$this->assertTrue( true );
	}

	/**
	 * @testdox Should fail open when no payment-currency header was sent.
	 */
	public function test_fails_open_without_currency_header(): void {
		$order = WC_Helper_Order::create_order();
		$order->set_currency( 'EUR' );

		$request = $this->create_request( $this->ece_headers() );

		$this->sut->assert_currency_matches_element( $order, $request );
		$this->assertTrue( true );
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
		$this->assertTrue( true );
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
		$this->assertTrue( true );
	}
}
