<?php
/**
 * Tests for the PayPal wallet incoming webhook endpoint (ported from the extension's IncomingWebhookEndpointTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Webhooks
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Webhooks;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\WebhookEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\WebhookEvent;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\WebhookEventFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Exception\PayPalOrderMissingException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\RequestHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\IncomingWebhookEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Status\WebhookSimulation;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\WebhookEventStorage;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The incoming webhook endpoint hands the request to the responsible handler and never lets a handler's exception
 * escape: PayPal would retry the webhook indefinitely if it never got a response.
 *
 * @group paypal-wallet
 */
class IncomingWebhookEndpointTest extends WalletTestCase {

	/**
	 * The webhook event factory mock.
	 *
	 * @var WebhookEventFactory|\Mockery\MockInterface
	 */
	private $webhook_event_factory;

	/**
	 * The webhook simulation mock.
	 *
	 * @var WebhookSimulation|\Mockery\MockInterface
	 */
	private $simulation;

	/**
	 * The last webhook event storage mock.
	 *
	 * @var WebhookEventStorage|\Mockery\MockInterface
	 */
	private $last_webhook_event_storage;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface|\Mockery\MockInterface
	 */
	private $logger;

	/**
	 * The REST server the test replaced, restored on tearDown.
	 *
	 * @var WP_REST_Server|null
	 */
	private $original_rest_server;

	/**
	 * Build the collaborators every test shares.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_rest_server = $GLOBALS['wp_rest_server'] ?? null;

		$this->webhook_event_factory      = $this->mock( WebhookEventFactory::class );
		$this->simulation                 = $this->mock( WebhookSimulation::class );
		$this->last_webhook_event_storage = $this->mock( WebhookEventStorage::class );
		$this->logger                     = $this->mock( LoggerInterface::class );

		$this->logger->shouldReceive( 'debug' );
		$this->logger->shouldReceive( 'info' );
		$this->last_webhook_event_storage->shouldReceive( 'save' );
		$this->simulation->shouldReceive( 'is_simulation_event' )->andReturn( false );
	}

	/**
	 * Restore the REST server.
	 */
	public function tearDown(): void {
		try {
			$GLOBALS['wp_rest_server'] = $this->original_rest_server; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring test state.
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * A webhook request carrying a CHECKOUT.ORDER.APPROVED event.
	 *
	 * @return WP_REST_Request
	 */
	private function create_request(): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/paypal/v1/incoming' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body_params( array( 'event_type' => 'CHECKOUT.ORDER.APPROVED' ) );

		return $request;
	}

	/**
	 * The endpoint under test, with the given handlers.
	 *
	 * @param RequestHandler ...$handlers The request handlers.
	 * @return IncomingWebhookEndpoint
	 */
	private function create_endpoint( RequestHandler ...$handlers ): IncomingWebhookEndpoint {
		return new IncomingWebhookEndpoint(
			$this->mock( WebhookEndpoint::class ),
			null,
			$this->logger,
			false,
			$this->webhook_event_factory,
			$this->simulation,
			$this->last_webhook_event_storage,
			...$handlers
		);
	}

	/**
	 * A handler that is responsible for the approved-order event.
	 *
	 * @return RequestHandler|\Mockery\MockInterface
	 */
	private function create_responsible_handler() {
		$event = new WebhookEvent( 'evt-1', null, 'checkout-order', '1.0', 'CHECKOUT.ORDER.APPROVED', '', '', (object) array() );
		$this->webhook_event_factory->shouldReceive( 'from_array' )->andReturn( $event );

		$handler = $this->mock( RequestHandler::class );
		$handler->shouldReceive( 'responsible_for_request' )->andReturn( true );
		$handler->shouldReceive( 'event_types' )->andReturn( array( 'CHECKOUT.ORDER.APPROVED' ) );

		return $handler;
	}

	/**
	 * When a handler's handle_request() throws an exception that is not a RuntimeException (for example
	 * PayPalOrderMissingException, which extends the plain Exception class), it must not propagate uncaught.
	 * Previously this caused an uncaught fatal and an HTTP 500, which made PayPal retry the webhook indefinitely.
	 *
	 * @testdox Should answer with a failure response, not an uncaught exception, when a handler throws an unexpected exception.
	 */
	public function test_unexpected_exception_from_handler_does_not_propagate_uncaught(): void {
		$handler = $this->create_responsible_handler();
		$handler->shouldReceive( 'handle_request' )->andThrow( new PayPalOrderMissingException( 'There was an error processing your order.' ) );

		$this->logger->shouldReceive( 'error' )->once();

		$response = $this->create_endpoint( $handler )->handle_request( $this->create_request() );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['success'] );
	}

	/**
	 * The happy path must keep working unchanged after wrapping handle_request() in a try/catch.
	 *
	 * @testdox Should return the handler's response unchanged and log no error when the handler succeeds.
	 */
	public function test_handler_response_is_returned_unchanged_on_success(): void {
		$expected_response = new WP_REST_Response( array( 'success' => true ) );

		$handler = $this->create_responsible_handler();
		$handler->shouldReceive( 'handle_request' )->andReturn( $expected_response );

		$this->logger->shouldNotReceive( 'error' );

		$response = $this->create_endpoint( $handler )->handle_request( $this->create_request() );

		$this->assertSame( $expected_response, $response );
	}

	/**
	 * New case: the extension's test does not cover the route registration.
	 *
	 * @testdox Should register a POST route at paypal/v1/incoming that calls handle_request and checks the request with verify_request.
	 */
	public function test_register_adds_the_incoming_route(): void {
		$endpoint = $this->create_endpoint();

		// Register on a clean server, with nothing but this endpoint hooked, so no other plugin's routes are involved.
		$server                    = new WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the REST server.
		remove_all_actions( 'rest_api_init' );
		add_action(
			'rest_api_init',
			function () use ( $endpoint ) {
				$this->assertTrue( $endpoint->register() );
			}
		);

		do_action( 'rest_api_init', $server );

		$routes = $server->get_routes( 'paypal/v1' );
		$this->assertArrayHasKey( '/paypal/v1/incoming', $routes );

		$route_endpoints = $routes['/paypal/v1/incoming'];
		$this->assertCount( 1, $route_endpoints );
		$this->assertSame( array( 'POST' => true ), $route_endpoints[0]['methods'] );
		$this->assertSame( array( $endpoint, 'handle_request' ), $route_endpoints[0]['callback'] );
		$this->assertSame( array( $endpoint, 'verify_request' ), $route_endpoints[0]['permission_callback'] );
	}
}
