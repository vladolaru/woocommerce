<?php
/**
 * Tests for the v6 cart simulation endpoint.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\IsolatedCartSimulator;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\RequestData;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Helper\CartProductsHelper;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Endpoint\SimulateCartEndpoint;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Mockery\MockInterface;
use WC_Helper_Product;
use WC_Session_Handler;

/**
 * The simulated total and currency the endpoint answers with, and what it leaves alone: the shopper's own cart and the
 * session write on shutdown. The endpoint ends the request with a real wp_send_json_*().
 *
 * @group paypal-wallet
 */
class SimulateCartEndpointTest extends WalletTestCase {

	/**
	 * The request reader mock.
	 *
	 * @var RequestData&MockInterface
	 */
	private $request_data;

	/**
	 * The cart products helper mock.
	 *
	 * @var CartProductsHelper&MockInterface
	 */
	private $cart_products;

	/**
	 * The isolated cart simulator mock.
	 *
	 * @var IsolatedCartSimulator&MockInterface
	 */
	private $cart_simulator;

	/**
	 * The System Under Test.
	 *
	 * @var SimulateCartEndpoint
	 */
	private $sut;

	/**
	 * Build the endpoint over mocked collaborators and a session of its own.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->request_data   = $this->mock( RequestData::class );
		$this->cart_products  = $this->mock( CartProductsHelper::class );
		$this->cart_simulator = $this->mock( IsolatedCartSimulator::class );

		$this->sut = new SimulateCartEndpoint(
			$this->request_data,
			$this->cart_products,
			$this->cart_simulator,
			$this->mock( LoggerInterface::class )->shouldIgnoreMissing()
		);

		$this->use_own_wc_session();
	}

	/**
	 * Make the request carry the given products.
	 *
	 * @param array $products The products the request holds and the helper returns.
	 */
	private function stub_posted_products( array $products ): void {
		$this->request_data->shouldReceive( 'read_request' )->with( SimulateCartEndpoint::nonce() )->andReturn( array( 'products' => $products ) );
		$this->cart_products->shouldReceive( 'products_from_data' )->andReturn( $products );
	}

	/**
	 * Run the handler and return the JSON it sent.
	 *
	 * @return array
	 */
	private function run_handler(): array {
		return $this->run_ajax_handler( array( $this->sut, 'handle_request' ) );
	}

	/**
	 * @testdox Should respond with the simulated total and the shop's currency code only, so the endpoint stays free of the button-state flags of the v5 endpoint.
	 */
	public function test_responds_with_total_and_currency_code_only(): void {
		$this->set_wallet_option( 'woocommerce_currency', 'EUR' );
		$posted_products = array(
			array(
				'product'  => 'a-product',
				'quantity' => 2,
			),
		);
		$this->stub_posted_products( $posted_products );
		$this->cart_simulator->shouldReceive( 'simulate' )->once()->with( $posted_products )->andReturn(
			array(
				'total'        => 19.99,
				'shipping_fee' => 0.0,
			)
		);

		$response = $this->run_handler();

		$this->assertTrue( $response['success'] );
		$this->assertSame(
			array(
				'total'         => '19.99',
				'currency_code' => 'EUR',
			),
			$response['data']
		);
	}

	/**
	 * @testdox Should write the total as a string at the store's price precision, so a payment sheet shows 20.000 where the store uses 3 decimals.
	 */
	public function test_total_follows_the_price_decimals_of_the_store(): void {
		$this->set_wallet_option( 'woocommerce_price_num_decimals', 3 );
		$this->stub_posted_products(
			array(
				array(
					'product'  => 'a-product',
					'quantity' => 1,
				),
			)
		);
		$this->cart_simulator->shouldReceive( 'simulate' )->once()->andReturn( array( 'total' => 20.0 ) );

		$response = $this->run_handler();

		$this->assertSame( '20.000', $response['data']['total'] );
	}

	/**
	 * @testdox Should price the products through the isolated simulator and leave the shopper's real cart untouched.
	 */
	public function test_does_not_touch_the_real_cart(): void {
		$product = WC_Helper_Product::create_simple_product();
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $product->get_id(), 3 );
		$cart_before = WC()->cart->get_cart_contents();

		$this->stub_posted_products(
			array(
				array(
					'product'  => 'a-product',
					'quantity' => 1,
				),
			)
		);
		$this->cart_simulator->shouldReceive( 'simulate' )->once()->andReturn( array( 'total' => 42.5 ) );

		$response = $this->run_handler();

		$this->assertSame( '42.50', $response['data']['total'], 'The total comes from the simulator, not from the real cart' );
		$this->assertSame( $cart_before, WC()->cart->get_cart_contents(), 'The real cart keeps its items and quantities' );
		$this->assertSame( 3, array_values( WC()->cart->get_cart_contents() )[0]['quantity'] );
	}

	/**
	 * @testdox Should remove the session write from the shutdown hook before simulating, so this read-only request cannot wipe a concurrent shopper's cart.
	 */
	public function test_removes_session_persistence_before_simulating(): void {
		$session  = WC()->session;
		$callback = array( $session, 'save_data' );
		add_action( 'shutdown', $callback, 20 );
		$this->assertNotFalse( has_action( 'shutdown', $callback ), 'The session write is registered before the request' );

		$this->stub_posted_products(
			array(
				array(
					'product'  => 'a-product',
					'quantity' => 1,
				),
			)
		);
		$registered_during_simulation = null;
		$this->cart_simulator->shouldReceive( 'simulate' )->once()->andReturnUsing(
			static function () use ( $callback, &$registered_during_simulation ) {
				$registered_during_simulation = has_action( 'shutdown', $callback );
				return array( 'total' => 19.99 );
			}
		);

		$this->run_handler();

		$this->assertFalse( $registered_during_simulation, 'The session write is gone when the simulation runs' );
		$this->assertFalse( has_action( 'shutdown', $callback ), 'The session write stays removed' );
	}

	/**
	 * @testdox Should skip the simulation and send an error when the merchant switched cart simulation off with the filter.
	 */
	public function test_skips_simulation_when_disabled_by_filter(): void {
		$calls = $this->spy_filter( 'woocommerce_paypal_payments_simulate_cart_enabled', false );
		$this->request_data->shouldNotReceive( 'read_request' );
		$this->cart_simulator->shouldNotReceive( 'simulate' );

		$response = $this->run_handler();

		$this->assertCount( 1, $calls );
		$this->assertTrue( $calls[0][0], 'The filter receives true as its default' );
		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Cart simulation is disabled.', $response['data']['message'] );
	}

	/**
	 * @testdox Should skip the simulation and send an error when no usable products were posted.
	 */
	public function test_skips_simulation_when_no_products_are_posted(): void {
		$this->request_data->shouldReceive( 'read_request' )->once()->with( SimulateCartEndpoint::nonce() )->andReturn( array() );
		$this->cart_products->shouldReceive( 'products_from_data' )->once()->andReturn( null );
		$this->cart_simulator->shouldNotReceive( 'simulate' );

		$response = $this->run_handler();

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Necessary fields not defined. Action aborted.', $response['data']['message'] );
	}
}
