<?php
/**
 * Tests for the PayPal wallet approve-order endpoint (ported from the extension's ApproveOrderEndpointTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\OrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PurchaseUnit;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\DccApplies;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\OrderHelper;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\Context;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\ThreeDSecure;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\ApproveOrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\RequestData;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Helper\WooCommerceOrderCreator;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\SessionHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsModel;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Error;
use Mockery\MockInterface;
use WC_Session_Handler;

/**
 * Approving a PayPal order: the order must belong to the current shopper's session before any state changes.
 *
 * @group paypal-wallet
 */
class ApproveOrderEndpointTest extends WalletTestCase {

	/**
	 * The request data mock.
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
	 * The PayPal session handler mock.
	 *
	 * @var SessionHandler&MockInterface
	 */
	private $session_handler;

	/**
	 * The gateway mock.
	 *
	 * @var PayPalGateway&MockInterface
	 */
	private $gateway;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockInterface
	 */
	private $logger;

	/**
	 * The context helper mock.
	 *
	 * @var Context&MockInterface
	 */
	private $context;

	/**
	 * The endpoint under test.
	 *
	 * @var ApproveOrderEndpoint
	 */
	private $sut;

	/**
	 * The WooCommerce session the test replaced, restored on tearDown.
	 *
	 * @var mixed
	 */
	private $original_session;

	/**
	 * Build the endpoint over mocked collaborators.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_session = WC()->session;

		$this->request_data    = $this->mock( RequestData::class );
		$this->api_endpoint    = $this->mock( OrderEndpoint::class );
		$this->session_handler = $this->mock( SessionHandler::class );
		$this->gateway         = $this->mock( PayPalGateway::class );
		$this->logger          = $this->mock( LoggerInterface::class );
		$this->context         = $this->mock( Context::class );

		$this->sut = new ApproveOrderEndpoint(
			$this->request_data,
			$this->api_endpoint,
			$this->session_handler,
			$this->mock( ThreeDSecure::class ),
			$this->mock( SettingsProvider::class ),
			$this->mock( SettingsModel::class ),
			$this->mock( DccApplies::class ),
			$this->mock( OrderHelper::class ),
			false,
			$this->gateway,
			$this->mock( WooCommerceOrderCreator::class ),
			$this->logger,
			$this->context
		);
	}

	/**
	 * Restore the WooCommerce session.
	 */
	public function tearDown(): void {
		try {
			WC()->session = $this->original_session;
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Run handle_request() and return the JSON response it sent.
	 *
	 * wp_send_json_*() ends in wp_die(), so the die handler throws to stop execution there. It throws an Error rather
	 * than an Exception: the endpoint catches Exception around its wp_send_json_*() calls, and a real wp_die() exits.
	 *
	 * @return array The decoded response ("success" and "data").
	 */
	private function run_handler(): array {
		$die_handler = function () {
			return function ( $message ) {
				throw new Error( 'wp_die:' . esc_html( (string) $message ) );
			};
		};
		add_filter( 'wp_die_ajax_handler', $die_handler );
		add_filter( 'wp_doing_ajax', '__return_true' );

		$finished = true;
		ob_start();
		try {
			$this->sut->handle_request();
			$finished = false;
		} catch ( Error $e ) {
			if ( 0 !== strpos( $e->getMessage(), 'wp_die:' ) ) {
				throw $e;
			}
		} finally {
			$body = (string) ob_get_clean();
		}

		$this->assertTrue( $finished, 'The handler should end the request with a JSON response' );
		$response = json_decode( $body, true );
		$this->assertIsArray( $response, 'The handler should send JSON, got: ' . $body );

		return $response;
	}

	/**
	 * Make WC()->session a session whose unique customer ID is the given one.
	 *
	 * @param string $customer_id The current shopper's session ID.
	 * @return WC_Session_Handler&MockInterface
	 */
	private function use_wc_session( string $customer_id ) {
		$wc_session = $this->mock( WC_Session_Handler::class );
		$wc_session->shouldReceive( 'get_customer_unique_id' )->andReturn( $customer_id );

		WC()->session = $wc_session;

		return $wc_session;
	}

	/**
	 * A PayPal order whose purchase unit carries the given custom ID.
	 *
	 * @param string $custom_id The custom ID.
	 * @param bool   $ready     Whether the order status counts as approved or created.
	 * @return Order&MockInterface
	 */
	private function paypal_order_with_custom_id( string $custom_id, bool $ready = true ) {
		$purchase_unit = $this->mock( PurchaseUnit::class );
		$purchase_unit->shouldReceive( 'custom_id' )->andReturn( $custom_id );

		$order = $this->mock( Order::class );
		$order->shouldReceive( 'purchase_units' )->andReturn( array( $purchase_unit ) );
		$order->shouldReceive( 'payment_source' )->andReturn( null );

		if ( $ready ) {
			$order_status = $this->mock( OrderStatus::class );
			$order_status->shouldReceive( 'is' )->andReturn( true );
			$order->shouldReceive( 'status' )->andReturn( $order_status );
		}

		return $order;
	}

	/**
	 * Have the request carry the given PayPal order ID and funding source.
	 *
	 * @param string      $order_id       The PayPal order ID.
	 * @param string|null $funding_source The funding source.
	 */
	private function request_for( string $order_id, ?string $funding_source = null ): void {
		$this->request_data->shouldReceive( 'read_request' )
			->with( ApproveOrderEndpoint::nonce() )
			->andReturn(
				array(
					'order_id'               => $order_id,
					'funding_source'         => $funding_source,
					'should_create_wc_order' => false,
				)
			);
	}

	/**
	 * When handle_request() fetches a PayPal order, it extracts custom_id from purchase_units[0] and parses the
	 * pcp_customer_<session_id> value.
	 *
	 * @testdox Should read the session ID from the purchase unit's custom ID and approve the order when it matches.
	 */
	public function test_extracts_session_id_from_purchase_unit_custom_id(): void {
		$session_id = 'abc123';
		$order      = $this->paypal_order_with_custom_id( 'pcp_customer_' . $session_id );

		$this->request_for( 'ORDER-123' );
		$this->api_endpoint->shouldReceive( 'order' )->with( 'ORDER-123' )->andReturn( $order );
		$this->session_handler->shouldReceive( 'replace_funding_source' )->once();
		$this->session_handler->shouldReceive( 'replace_order' )->once()->with( $order );
		$this->context->shouldReceive( 'is_checkout' )->andReturn( false );

		$wc_session = $this->use_wc_session( $session_id );
		$wc_session->shouldReceive( 'set' )->once()->with( 'chosen_payment_method', PayPalGateway::ID );

		$response = $this->run_handler();

		$this->assertTrue( $response['success'] );
	}

	/**
	 * If the extracted session ID does not match the current WC session ID, handle_request() raises an exception and
	 * does not call replace_order() or process_payment().
	 *
	 * @testdox Should reject an order from another session and leave the session and the payment untouched.
	 */
	public function test_mismatched_session_id_raises_exception_and_blocks_state_changes(): void {
		$order = $this->paypal_order_with_custom_id( 'pcp_customer_victim-session', false );

		$this->request_for( 'ORDER' );
		$this->api_endpoint->shouldReceive( 'order' )->with( 'ORDER' )->andReturn( $order );
		$this->session_handler->shouldReceive( 'replace_order' )->never();
		$this->gateway->shouldReceive( 'process_payment' )->never();
		$this->logger->shouldReceive( 'error' )->once();

		$this->use_wc_session( 'attacker-session' );

		$response = $this->run_handler();

		$this->assertFalse( $response['success'] );
	}

	/**
	 * An attacker-supplied order_id belonging to a different session is rejected with a non-descriptive error response,
	 * so nothing leaks about who owns the order.
	 *
	 * @testdox Should answer a mismatched session with a message that reveals nothing about order ownership.
	 */
	public function test_mismatched_session_response_reveals_no_order_ownership(): void {
		$order = $this->paypal_order_with_custom_id( 'pcp_customer_victim-session', false );

		$this->request_for( 'ORDER' );
		$this->api_endpoint->shouldReceive( 'order' )->with( 'ORDER' )->andReturn( $order );
		$this->logger->shouldReceive( 'error' )->once();

		$this->use_wc_session( 'attacker-session' );

		$response = $this->run_handler();

		$this->assertFalse( $response['success'] );
		$message = strtolower( $response['data']['message'] ?? '' );
		$this->assertNotSame( '', $message, 'The error response should carry a message' );
		foreach ( array( 'session', 'owner', 'belong', 'other user' ) as $revealing_phrase ) {
			$this->assertStringNotContainsString( $revealing_phrase, $message );
		}
	}

	/**
	 * @testdox Should approve an order created by the same session: replace the session order and funding source and answer with success.
	 */
	public function test_valid_order_session_proceeds_to_replace_order(): void {
		$session_id = 'session-99';
		$order      = $this->paypal_order_with_custom_id( 'pcp_customer_' . $session_id );

		$this->request_for( 'VALID-ORDER', 'paypal' );
		$this->api_endpoint->shouldReceive( 'order' )->with( 'VALID-ORDER' )->andReturn( $order );
		$this->session_handler->shouldReceive( 'replace_funding_source' )->once()->with( 'paypal' );
		$this->session_handler->shouldReceive( 'replace_order' )->once()->with( $order );
		$this->context->shouldReceive( 'is_checkout' )->andReturn( false );

		$wc_session = $this->use_wc_session( $session_id );
		$wc_session->shouldReceive( 'set' )->once()->with( 'chosen_payment_method', PayPalGateway::ID );

		$response = $this->run_handler();

		$this->assertTrue( $response['success'] );
	}
}
