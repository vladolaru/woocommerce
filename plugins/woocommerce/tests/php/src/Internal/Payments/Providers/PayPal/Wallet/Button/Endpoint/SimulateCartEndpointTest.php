<?php
/**
 * Tests for the button cart simulation endpoint.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Button\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Button\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Assets\DisabledSmartButton;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Assets\SmartButton;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Endpoint\SimulateCartEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\IsolatedCartSimulator;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\RequestData;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Helper\CartProductsHelper;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Mockery\MockInterface;

/**
 * The simulated total, shipping fee and button state the endpoint answers with, and the session write it removes from
 * the shutdown hook. The endpoint ends the request with a real wp_send_json_*().
 *
 * @group paypal-wallet
 */
class SimulateCartEndpointTest extends WalletTestCase {

	/**
	 * The smart button mock.
	 *
	 * @var SmartButton&MockInterface
	 */
	private $smart_button;

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

		$this->smart_button   = $this->mock( SmartButton::class );
		$this->request_data   = $this->mock( RequestData::class );
		$this->cart_products  = $this->mock( CartProductsHelper::class );
		$this->cart_simulator = $this->mock( IsolatedCartSimulator::class );

		$this->sut = new SimulateCartEndpoint(
			$this->smart_button,
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
	 * Make the smart button answer the three questions of the product location.
	 *
	 * @param array $context_data  The context data the endpoint hands over.
	 * @param bool  $pay_later     Whether Pay Later buttons are enabled.
	 * @param bool  $messaging     Whether Pay Later messaging is enabled.
	 * @param bool  $button_hidden Whether the button is disabled.
	 */
	private function stub_smart_button_flags( array $context_data, bool $pay_later = true, bool $messaging = true, bool $button_hidden = false ): void {
		$this->smart_button->shouldReceive( 'is_pay_later_button_enabled_for_location' )->with( 'product', $context_data )->andReturn( $pay_later );
		$this->smart_button->shouldReceive( 'is_pay_later_messaging_enabled_for_location' )->with( 'product', $context_data )->andReturn( $messaging );
		$this->smart_button->shouldReceive( 'is_button_disabled' )->with( 'product', $context_data )->andReturn( $button_hidden );
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
	 * @testdox Should respond with the simulated total, the shipping fee and the Pay Later and button state when the merchant has cart simulation enabled.
	 */
	public function test_responds_with_simulated_totals_and_button_state(): void {
		$this->set_wallet_option( 'woocommerce_currency', 'EUR' );
		$this->set_wallet_option( 'woocommerce_default_country', 'DE' );
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
				'shipping_fee' => 4.5,
			)
		);
		$this->stub_smart_button_flags(
			array(
				'product'     => 'a-product',
				'order_total' => 19.99,
			)
		);

		$response = $this->run_handler();

		$this->assertTrue( $response['success'] );
		$this->assertSame(
			array(
				'total'         => 19.99,
				'shipping_fee'  => 4.5,
				'currency_code' => 'EUR',
				'country_code'  => 'DE',
				'funding'       => array( 'paylater' => array( 'enabled' => true ) ),
				'button'        => array( 'is_disabled' => false ),
				'messages'      => array( 'is_hidden' => false ),
			),
			$response['data']
		);
	}

	/**
	 * @testdox Should report the Pay Later buttons as disabled, the messages as hidden and the button as disabled when the smart button says so.
	 */
	public function test_reports_the_disabled_button_state(): void {
		$this->stub_posted_products(
			array(
				array(
					'product'  => 'a-product',
					'quantity' => 1,
				),
			)
		);
		$this->cart_simulator->shouldReceive( 'simulate' )->once()->andReturn(
			array(
				'total'        => 10.0,
				'shipping_fee' => 0.0,
			)
		);
		$this->stub_smart_button_flags(
			array(
				'product'     => 'a-product',
				'order_total' => 10.0,
			),
			false,
			false,
			true
		);

		$response = $this->run_handler();

		$this->assertTrue( $response['success'] );
		$this->assertSame( array( 'enabled' => false ), $response['data']['funding']['paylater'] );
		$this->assertSame( array( 'is_disabled' => true ), $response['data']['button'] );
		$this->assertSame( array( 'is_hidden' => true ), $response['data']['messages'] );
	}

	/**
	 * @testdox Should remove the session write from the shutdown hook before simulating, so this read-only request cannot wipe a concurrent shopper's cart.
	 */
	public function test_removes_session_persistence_before_simulating(): void {
		$callback = array( WC()->session, 'save_data' );
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
				return array(
					'total'        => 19.99,
					'shipping_fee' => 0.0,
				);
			}
		);
		$this->stub_smart_button_flags(
			array(
				'product'     => 'a-product',
				'order_total' => 19.99,
			)
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

	/**
	 * @testdox Should send an error without reading the request when the smart button is not the real one, as on a page the v6 SDK owns.
	 */
	public function test_sends_an_error_when_the_smart_button_is_disabled(): void {
		$sut = new SimulateCartEndpoint(
			$this->mock( DisabledSmartButton::class ),
			$this->request_data,
			$this->cart_products,
			$this->cart_simulator,
			$this->mock( LoggerInterface::class )->shouldIgnoreMissing()
		);
		$this->request_data->shouldNotReceive( 'read_request' );
		$this->cart_simulator->shouldNotReceive( 'simulate' );

		$response = $this->run_ajax_handler( array( $sut, 'handle_request' ) );

		$this->assertSame( array( 'success' => false ), $response, 'The answer is an error without a message or a detail' );
	}
}
