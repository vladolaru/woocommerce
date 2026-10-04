<?php
/**
 * Tests for the update-shipping endpoint.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\OrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PatchCollection;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PurchaseUnit;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PurchaseUnitFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Exception\NonceValidationException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\RequestData;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\UpdateShippingEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\SessionHandler;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Mockery;
use Mockery\MockInterface;

/**
 * Patching the PayPal order with the shipping of the cart is only allowed for the order the session holds. The
 * endpoint ends the request with a real wp_send_json_*().
 *
 * @group paypal-wallet
 */
class UpdateShippingEndpointTest extends WalletTestCase {

	/**
	 * The request reader mock.
	 *
	 * @var RequestData&MockInterface
	 */
	private $request_data;

	/**
	 * The order endpoint mock.
	 *
	 * @var OrderEndpoint&MockInterface
	 */
	private $order_endpoint;

	/**
	 * The purchase unit factory mock.
	 *
	 * @var PurchaseUnitFactory&MockInterface
	 */
	private $purchase_unit_factory;

	/**
	 * The PayPal session handler mock.
	 *
	 * @var SessionHandler&MockInterface
	 */
	private $session_handler;

	/**
	 * The System Under Test.
	 *
	 * @var UpdateShippingEndpoint
	 */
	private $sut;

	/**
	 * Build the endpoint over mocked collaborators.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->request_data          = $this->mock( RequestData::class );
		$this->order_endpoint        = $this->mock( OrderEndpoint::class );
		$this->purchase_unit_factory = $this->mock( PurchaseUnitFactory::class );
		$this->session_handler       = $this->mock( SessionHandler::class );

		$this->sut = new UpdateShippingEndpoint(
			$this->request_data,
			$this->order_endpoint,
			$this->purchase_unit_factory,
			$this->session_handler,
			$this->mock( LoggerInterface::class )->shouldIgnoreMissing()
		);
	}

	/**
	 * Make the session hold the given PayPal order, or none.
	 *
	 * @param string|null $order_id The ID of the order in the session.
	 */
	private function session_holds_order( ?string $order_id ): void {
		$session_order = null;
		if ( null !== $order_id ) {
			$session_order = $this->mock( Order::class );
			$session_order->shouldReceive( 'id' )->andReturn( $order_id );
		}

		$this->session_handler->shouldReceive( 'order' )->andReturn( $session_order );
	}

	/**
	 * @testdox Should patch the order with the purchase unit of the cart and answer with success when the order ID matches the session's PayPal order.
	 */
	public function test_patch_proceeds_when_order_id_matches_session(): void {
		$order_id = 'ORDER-MATCH-123';
		$this->request_data->shouldReceive( 'read_request' )->once()->with( UpdateShippingEndpoint::nonce() )->andReturn( array( 'order_id' => $order_id ) );
		$this->session_holds_order( $order_id );

		$purchase_unit = $this->mock( PurchaseUnit::class );
		$purchase_unit->shouldReceive( 'to_array' )->andReturn( array( 'amount' => array( 'value' => '10.00' ) ) );
		$purchase_unit->shouldReceive( 'reference_id' )->andReturn( 'default' );
		$this->purchase_unit_factory->shouldReceive( 'from_wc_cart' )->once()->with( null, true )->andReturn( $purchase_unit );

		$patched = null;
		$this->order_endpoint->shouldReceive( 'patch' )->once()->with(
			$order_id,
			Mockery::on(
				static function ( PatchCollection $patches ) use ( &$patched ): bool {
					$patched = $patches->to_array();
					return true;
				}
			)
		);

		$response = $this->run_ajax_handler( array( $this->sut, 'handle_request' ) );

		$this->assertTrue( $response['success'] );
		$this->assertSame(
			array(
				array(
					'op'    => 'replace',
					'path'  => "/purchase_units/@reference_id=='default'",
					'value' => array( 'amount' => array( 'value' => '10.00' ) ),
				),
			),
			$patched
		);
	}

	/**
	 * @testdox Should issue no patch and answer with an error when the order ID does not match the session's PayPal order.
	 */
	public function test_patch_is_rejected_when_order_id_does_not_match_session(): void {
		$this->request_data->shouldReceive( 'read_request' )->once()->with( UpdateShippingEndpoint::nonce() )->andReturn( array( 'order_id' => 'ORDER-REQUEST' ) );
		$this->session_holds_order( 'ORDER-SESSION' );
		$this->purchase_unit_factory->shouldNotReceive( 'from_wc_cart' );
		$this->order_endpoint->shouldNotReceive( 'patch' );

		$response = $this->run_ajax_handler( array( $this->sut, 'handle_request' ) );

		$this->assertFalse( $response['success'] );
		$this->assertSame( array( 'message' => 'Order validation failed.' ), $response['data'] );
	}

	/**
	 * @testdox Should issue no patch and answer with an error when the session holds no PayPal order.
	 */
	public function test_patch_is_rejected_when_no_session_order_exists(): void {
		$this->request_data->shouldReceive( 'read_request' )->once()->with( UpdateShippingEndpoint::nonce() )->andReturn( array( 'order_id' => 'ORDER-123' ) );
		$this->session_holds_order( null );
		$this->purchase_unit_factory->shouldNotReceive( 'from_wc_cart' );
		$this->order_endpoint->shouldNotReceive( 'patch' );

		$response = $this->run_ajax_handler( array( $this->sut, 'handle_request' ) );

		$this->assertFalse( $response['success'] );
		$this->assertSame( array( 'message' => 'Order validation failed.' ), $response['data'] );
	}

	/**
	 * @testdox Should issue no patch and answer with an error message when the nonce is invalid.
	 */
	public function test_patch_is_rejected_when_the_nonce_is_invalid(): void {
		$this->request_data->shouldReceive( 'read_request' )->once()->andThrow( new NonceValidationException( 'Could not validate nonce.' ) );
		$this->session_handler->shouldNotReceive( 'order' );
		$this->order_endpoint->shouldNotReceive( 'patch' );

		$response = $this->run_ajax_handler( array( $this->sut, 'handle_request' ) );

		$this->assertFalse( $response['success'] );
		$this->assertSame( array( 'message' => 'Could not validate nonce.' ), $response['data'] );
	}
}
