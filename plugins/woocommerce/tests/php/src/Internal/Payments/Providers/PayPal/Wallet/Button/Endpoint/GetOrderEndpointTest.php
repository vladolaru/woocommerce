<?php
/**
 * Tests for the get-order endpoint.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Button\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Button\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\OrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\PayPalApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Endpoint\GetOrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Exception\NonceValidationException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Session\CartData;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Session\CartDataTransientStorage;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\RequestData;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Mockery\MockInterface;
use stdClass;
use WC_Session_Handler;

/**
 * Only the session that owns the stored cart data may read the PayPal order: a logged-in user by user ID, a guest by
 * the customer ID of the WooCommerce session. Anyone else gets an error that does not say why.
 *
 * The cart data is stored through the real transient storage. The endpoint ends the request with a real
 * wp_send_json_*().
 *
 * @group paypal-wallet
 */
class GetOrderEndpointTest extends WalletTestCase {

	private const DENIED_MESSAGE = 'Invalid or expired order access';

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
	private $api_endpoint;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockInterface
	 */
	private $logger;

	/**
	 * The real transient storage of the cart data.
	 *
	 * @var CartDataTransientStorage
	 */
	private $cart_data_storage;

	/**
	 * The System Under Test.
	 *
	 * @var GetOrderEndpoint
	 */
	private $sut;

	/**
	 * Build the endpoint over mocked collaborators and the real cart data storage.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->request_data      = $this->mock( RequestData::class );
		$this->api_endpoint      = $this->mock( OrderEndpoint::class );
		$this->logger            = $this->mock( LoggerInterface::class );
		$this->cart_data_storage = new CartDataTransientStorage();

		$this->sut = new GetOrderEndpoint( $this->request_data, $this->api_endpoint, $this->logger, $this->cart_data_storage );
	}

	/**
	 * Store cart data for a PayPal order.
	 *
	 * @param string      $order_id            The PayPal order ID.
	 * @param int         $user_id             The user the cart belongs to, 0 for a guest.
	 * @param string|null $session_customer_id The customer ID of the guest's session.
	 * @return CartData The stored cart data, with its key.
	 */
	private function store_cart_data( string $order_id, int $user_id, ?string $session_customer_id = null ): CartData {
		$cart_data = new CartData( array(), array(), false, $user_id, 'hash' );
		$cart_data->set_paypal_order_id( $order_id );
		$cart_data->set_session_customer_id( $session_customer_id );
		$cart_data->generate_key();

		// Placeholders that claim the two transient names, so the base class deletes them; the storage then overwrites them.
		$this->set_wallet_transient( (string) $cart_data->key(), array() );
		$this->set_wallet_transient( 'ppcp_cart_by_order_' . $order_id, '' );
		$this->cart_data_storage->save( $cart_data );

		return $cart_data;
	}

	/**
	 * Make the request ask for the given order.
	 *
	 * @param string $order_id The PayPal order ID.
	 */
	private function request_order( string $order_id ): void {
		$this->request_data->shouldReceive( 'read_request' )->once()->with( GetOrderEndpoint::nonce() )->andReturn( array( 'order_id' => $order_id ) );
	}

	/**
	 * A PayPal order that serialises to the given array.
	 *
	 * @param array $data The array.
	 * @return Order&MockInterface
	 */
	private function paypal_order( array $data ) {
		$order = $this->mock( Order::class );
		$order->shouldReceive( 'to_array' )->andReturn( $data );

		return $order;
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
	 * Assert the response is the denial that reveals nothing about the owner or the session.
	 *
	 * @param array $response The response.
	 */
	private function assert_denied_without_disclosure( array $response ): void {
		$this->assertFalse( $response['success'] );
		$this->assertSame( array( 'message' => self::DENIED_MESSAGE ), $response['data'] );
		foreach ( array( 'session', 'owner', 'belong', 'user' ) as $revealing_word ) {
			$this->assertStringNotContainsString( $revealing_word, strtolower( $response['data']['message'] ) );
		}
	}

	/**
	 * @testdox Should return the order data when the session identifier matches the user the cart data belongs to.
	 */
	public function test_handle_returns_order_data_when_session_identifier_matches(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$this->store_cart_data( 'ORDER-MATCH-123', $user_id );
		$this->request_order( 'ORDER-MATCH-123' );
		$this->api_endpoint->shouldReceive( 'order' )->once()->with( 'ORDER-MATCH-123' )->andReturn( $this->paypal_order( array( 'id' => 'ORDER-MATCH-123' ) ) );

		$response = $this->run_handler();

		$this->assertTrue( $response['success'] );
		$this->assertSame( array( 'id' => 'ORDER-MATCH-123' ), $response['data'] );
	}

	/**
	 * @testdox Should block disclosure and answer with an error that names no owner or session when the session does not match.
	 */
	public function test_handle_blocks_disclosure_and_returns_error_when_session_does_not_match(): void {
		$owner_id    = self::factory()->user->create();
		$attacker_id = self::factory()->user->create();
		wp_set_current_user( $attacker_id );
		$this->store_cart_data( 'ORDER-MISMATCH-456', $owner_id );
		$this->request_order( 'ORDER-MISMATCH-456' );
		$this->api_endpoint->shouldNotReceive( 'order' );
		$this->logger->shouldReceive( 'warning' )->once()->with( 'Unauthorized GetOrder attempt for PayPal order ORDER-MISMATCH-456. Session mismatch.' );

		$response = $this->run_handler();

		$this->assert_denied_without_disclosure( $response );
	}

	/**
	 * @testdox Should not extend the transient expiry when the order is read, so the session binding does not stretch the authorization window.
	 */
	public function test_handle_does_not_extend_transient_expiry_on_access(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$cart_data = $this->store_cart_data( 'ORDER-TTL-789', $user_id );
		$timeout   = '_transient_timeout_' . $cart_data->key();
		// Pull the expiry back, so an access that wrote the transient again would push it out to two hours.
		$expiry = time() + 600;
		update_option( $timeout, $expiry );
		$this->request_order( 'ORDER-TTL-789' );
		$this->api_endpoint->shouldReceive( 'order' )->once()->andReturn( $this->paypal_order( array( 'id' => 'ORDER-TTL-789' ) ) );

		$response = $this->run_handler();

		$this->assertTrue( $response['success'] );
		$this->assertSame( $expiry, (int) get_option( $timeout ), 'Reading the order leaves the expiry where it was' );
	}

	/**
	 * @testdox Should return the order data to a guest when the session customer ID matches the stored one.
	 */
	public function test_handle_returns_order_data_for_guest_when_session_customer_id_matches(): void {
		wp_set_current_user( 0 );
		$this->use_session_of_customer( 'sess-guest-abc' );
		$this->store_cart_data( 'ORDER-GUEST-MATCH-001', 0, 'sess-guest-abc' );
		$this->request_order( 'ORDER-GUEST-MATCH-001' );
		$this->api_endpoint->shouldReceive( 'order' )->once()->with( 'ORDER-GUEST-MATCH-001' )->andReturn( $this->paypal_order( array( 'id' => 'ORDER-GUEST-MATCH-001' ) ) );

		$response = $this->run_handler();

		$this->assertTrue( $response['success'] );
		$this->assertSame( array( 'id' => 'ORDER-GUEST-MATCH-001' ), $response['data'] );
	}

	/**
	 * @testdox Should block disclosure to a guest when the session customer ID does not match the stored one.
	 */
	public function test_handle_blocks_disclosure_for_guest_when_session_customer_id_does_not_match(): void {
		wp_set_current_user( 0 );
		$this->use_session_of_customer( 'sess-DIFFERENT' );
		$this->store_cart_data( 'ORDER-GUEST-MISMATCH-002', 0, 'sess-guest-abc' );
		$this->request_order( 'ORDER-GUEST-MISMATCH-002' );
		$this->api_endpoint->shouldNotReceive( 'order' );
		$this->logger->shouldReceive( 'warning' )->once()->with( 'Unauthorized GetOrder attempt for PayPal order ORDER-GUEST-MISMATCH-002. Session mismatch.' );

		$response = $this->run_handler();

		$this->assert_denied_without_disclosure( $response );
	}

	/**
	 * @testdox Should block disclosure to a logged-in user when the cart data belongs to a guest session.
	 */
	public function test_handle_blocks_disclosure_to_a_user_when_the_cart_data_belongs_to_a_guest(): void {
		wp_set_current_user( self::factory()->user->create() );
		$this->use_session_of_customer( 'sess-guest-abc' );
		$this->store_cart_data( 'ORDER-GUEST-OWNED-003', 0, 'sess-guest-abc' );
		$this->request_order( 'ORDER-GUEST-OWNED-003' );
		$this->api_endpoint->shouldNotReceive( 'order' );
		$this->logger->shouldReceive( 'warning' )->once();

		$response = $this->run_handler();

		$this->assert_denied_without_disclosure( $response );
	}

	/**
	 * @testdox Should answer with an error, and log it, when no cart data was stored for the order.
	 */
	public function test_handle_blocks_disclosure_when_no_cart_data_exists(): void {
		$this->request_order( 'ORDER-UNKNOWN-004' );
		$this->api_endpoint->shouldNotReceive( 'order' );
		$this->logger->shouldReceive( 'warning' )->once()->with( 'Unauthorized GetOrder attempt for PayPal order ORDER-UNKNOWN-004. No CartData found.' );

		$response = $this->run_handler();

		$this->assert_denied_without_disclosure( $response );
	}

	/**
	 * @testdox Should answer with an error when the request names no order.
	 */
	public function test_handle_requires_an_order_id(): void {
		$this->request_data->shouldReceive( 'read_request' )->once()->andReturn( array() );
		$this->api_endpoint->shouldNotReceive( 'order' );

		$response = $this->run_handler();

		$this->assertFalse( $response['success'] );
		$this->assertSame( array( 'message' => 'Order ID is required' ), $response['data'] );
	}

	/**
	 * @testdox Should answer with an error message when the nonce is invalid.
	 */
	public function test_handle_answers_a_nonce_failure(): void {
		$this->request_data->shouldReceive( 'read_request' )->once()->andThrow( new NonceValidationException( 'Could not validate nonce.' ) );
		$this->api_endpoint->shouldNotReceive( 'order' );

		$response = $this->run_handler();

		$this->assertFalse( $response['success'] );
		$this->assertSame( array( 'message' => 'Could not validate nonce.' ), $response['data'] );
	}

	/**
	 * @testdox Should answer with the name and the details of a PayPal API error and log it when the order cannot be read.
	 */
	public function test_handle_answers_a_paypal_api_error(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$this->store_cart_data( 'ORDER-API-ERROR-005', $user_id );
		$this->request_order( 'ORDER-API-ERROR-005' );
		$api_response          = new stdClass();
		$api_response->name    = 'RESOURCE_NOT_FOUND';
		$api_response->message = 'The specified resource does not exist.';
		$this->api_endpoint->shouldReceive( 'order' )->once()->andThrow( new PayPalApiException( $api_response, 404 ) );
		$this->logger->shouldReceive( 'error' )->once();

		$response = $this->run_handler();

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'RESOURCE_NOT_FOUND', $response['data']['name'] );
		$this->assertSame( 404, $response['data']['code'] );
	}

	/**
	 * Give WooCommerce a session whose customer ID is the given one.
	 *
	 * @param string $customer_id The customer ID of the session.
	 */
	private function use_session_of_customer( string $customer_id ): void {
		$session = $this->mock( WC_Session_Handler::class );
		$session->shouldReceive( 'get_customer_id' )->andReturn( $customer_id );

		$this->use_own_wc_session( $session );
	}
}
