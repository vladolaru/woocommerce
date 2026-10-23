<?php
/**
 * Tests for the signature check of the PayPal wallet incoming webhook endpoint.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\WebhookEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Webhook;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\WebhookEventFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\WebhookFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ApiHostResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\RequestHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\IncomingWebhookEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Status\WebhookSimulation;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\WebhookEventStorage;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use GetAllHeadersDouble;
use Mockery\MockInterface;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The permission check of the incoming webhook route sends the request's signature headers to PayPal's
 * verify-webhook-signature endpoint, and lets only a verified request reach a handler. The check runs through the real
 * REST server, the real webhook endpoint and the real event factory; PayPal's answer comes from stubbed HTTP.
 *
 * The webhook endpoint reads the signature headers through getallheaders(), which the command line does not have, so
 * the tests answer it from a double.
 *
 * @group paypal-wallet
 */
class IncomingWebhookEndpointVerifyRequestTest extends WalletTestCase {

	private const API_HOST = 'https://api.paypal.test';

	/**
	 * The REST server the test replaced, put back on tearDown.
	 *
	 * @var WP_REST_Server|null
	 */
	private $original_rest_server;

	/**
	 * The logger of the incoming webhook endpoint.
	 *
	 * @var LoggerInterface&MockInterface
	 */
	private $logger;

	/**
	 * The handler that is responsible for the event.
	 *
	 * @var RequestHandler&MockInterface
	 */
	private $handler;

	/**
	 * The System Under Test.
	 *
	 * @var IncomingWebhookEndpoint
	 */
	private $sut;

	/**
	 * Load the getallheaders() double, give the request its signature headers, and build the endpoint over a webhook
	 * with the ID "WH-1".
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_rest_server = $GLOBALS['wp_rest_server'] ?? null;

		require_once dirname( __DIR__, 2 ) . '/Doubles/GetAllHeadersDouble.php';
		if ( ! GetAllHeadersDouble::in_effect() ) {
			$this->markTestSkipped( 'PHP has getallheaders(), which reads the headers of the real request.' );
		}

		GetAllHeadersDouble::set_headers( $this->signature_headers() );

		$host_resolver = $this->mock( ApiHostResolver::class );
		$host_resolver->shouldReceive( 'host' )->andReturn( self::API_HOST );

		$webhook_endpoint = new WebhookEndpoint(
			$host_resolver,
			$this->make_bearer( false ),
			new WebhookFactory(),
			new WebhookEventFactory(),
			$this->mock( LoggerInterface::class )->shouldIgnoreMissing()
		);

		$simulation = $this->mock( WebhookSimulation::class );
		$simulation->shouldReceive( 'is_simulation_event' )->andReturn( false );

		$storage = $this->mock( WebhookEventStorage::class );
		$storage->shouldReceive( 'save' );

		$this->handler = $this->mock( RequestHandler::class );
		$this->handler->shouldReceive( 'responsible_for_request' )->andReturn( true );
		$this->handler->shouldReceive( 'event_types' )->andReturn( array( 'CHECKOUT.ORDER.APPROVED' ) );

		$this->logger = $this->mock( LoggerInterface::class )->shouldIgnoreMissing();

		$this->sut = new IncomingWebhookEndpoint(
			$webhook_endpoint,
			new Webhook( 'https://shop.example/wp-json/paypal/v1/incoming', array(), 'WH-1' ),
			$this->logger,
			true,
			new WebhookEventFactory(),
			$simulation,
			$storage,
			$this->handler
		);
	}

	/**
	 * Forget the headers, log out and put back the REST server.
	 */
	public function tearDown(): void {
		try {
			GetAllHeadersDouble::reset();
			wp_set_current_user( 0 );
			$GLOBALS['wp_rest_server'] = $this->original_rest_server; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring test state.
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * The headers PayPal signs a webhook with.
	 *
	 * @return array<string, string>
	 */
	private function signature_headers(): array {
		return array(
			'paypal-auth-algo'         => 'SHA256withRSA',
			'PAYPAL-CERT-URL'          => 'https://api.paypal.test/v1/notifications/certs/CERT-1',
			'PayPal-Transmission-Id'   => 'TRANSMISSION-1',
			'PAYPAL-TRANSMISSION-SIG'  => 'SIGNATURE-1',
			'PAYPAL-TRANSMISSION-TIME' => '2026-10-05T10:00:00Z',
			'Content-Type'             => 'application/json',
		);
	}

	/**
	 * A webhook request for an approved order, as PayPal sends it.
	 *
	 * @param string $event_id The ID of the event.
	 * @return WP_REST_Request
	 */
	private function webhook_request( string $event_id = 'EVENT-1' ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/paypal/v1/incoming' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body_params(
			array(
				'id'         => $event_id,
				'event_type' => 'CHECKOUT.ORDER.APPROVED',
			)
		);

		return $request;
	}

	/**
	 * Answer the verification call with the given status, or with a transport error when none is given.
	 *
	 * @param string|null $verification_status SUCCESS, FAILURE or null for a WP_Error.
	 */
	private function stub_verification( ?string $verification_status ): void {
		$this->stub_http(
			null === $verification_status
				? new WP_Error( 'http_request_failed', 'Connection refused' )
				: $this->http_response( 200, (string) wp_json_encode( array( 'verification_status' => $verification_status ) ) )
		);
	}

	/**
	 * Send the request through a REST server that only has the incoming webhook route, and return the response.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	private function dispatch( WP_REST_Request $request ): WP_REST_Response {
		$server                    = new WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the REST server.
		remove_all_actions( 'rest_api_init' );
		add_action(
			'rest_api_init',
			function () {
				$this->assertTrue( $this->sut->register() );
			}
		);
		do_action( 'rest_api_init', $server );

		return $server->dispatch( $request );
	}

	/**
	 * @testdox Should send the signature headers and the webhook ID to PayPal and let a verified request reach its handler with a 200 answer.
	 */
	public function test_verified_request_reaches_the_handler_with_a_200_answer(): void {
		$this->stub_verification( 'SUCCESS' );
		$this->handler->shouldReceive( 'handle_request' )->once()->andReturn( new WP_REST_Response( array( 'success' => true ), 200 ) );

		$response = $this->dispatch( $this->webhook_request() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'success' => true ), $response->get_data() );

		$this->assertCount( 1, $this->http_requests, 'One verification call should be sent' );
		$this->assertSame( self::API_HOST . '/v1/notifications/verify-webhook-signature', $this->http_requests[0]['url'] );
		$sent = json_decode( $this->http_requests[0]['request']['body'], true );
		$this->assertSame( 'WH-1', $sent['webhook_id'] );
		$this->assertSame( 'SHA256withRSA', $sent['auth_algo'] );
		$this->assertSame( 'https://api.paypal.test/v1/notifications/certs/CERT-1', $sent['cert_url'] );
		$this->assertSame( 'TRANSMISSION-1', $sent['transmission_id'] );
		$this->assertSame( 'SIGNATURE-1', $sent['transmission_sig'] );
		$this->assertSame( '2026-10-05T10:00:00Z', $sent['transmission_time'] );
	}

	/**
	 * A failed verification is an unauthorized request for the REST server: it answers 401 to a visitor who is not
	 * logged in, which is how PayPal calls, and 403 to a logged-in user.
	 *
	 * @testdox Should refuse a request whose signature PayPal rejects, with $expected_status for $who, and never reach the handler.
	 *
	 * @dataProvider data_rejected_request_status
	 *
	 * @param string $who             Who sends the request.
	 * @param bool   $logged_in       Whether the sender is logged in.
	 * @param int    $expected_status The status of the answer.
	 */
	public function test_request_whose_signature_fails_is_refused_and_never_reaches_the_handler( string $who, bool $logged_in, int $expected_status ): void {
		$this->stub_verification( 'FAILURE' );
		$this->handler->shouldNotReceive( 'handle_request' );
		$this->logger->shouldReceive( 'error' )->once()->with( 'Webhook verification failed.' );
		if ( $logged_in ) {
			wp_set_current_user( self::factory()->user->create() );
		}

		$response = $this->dispatch( $this->webhook_request() );

		$this->assertSame( $expected_status, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->get_data()['code'] );
		$this->assertCount( 1, $this->http_requests, 'One verification call should be sent' );
	}

	/**
	 * Who sends the rejected request, and the status the answer carries.
	 *
	 * @return array<string, array{string, bool, int}>
	 */
	public function data_rejected_request_status(): array {
		return array(
			'a visitor who is not logged in' => array( 'a visitor who is not logged in', false, 401 ),
			'a logged-in user'               => array( 'a logged-in user', true, 403 ),
		);
	}

	/**
	 * @testdox Should ask PayPal once for an event, however often the permission check runs.
	 */
	public function test_verification_result_is_cached_for_the_event(): void {
		$this->stub_verification( 'SUCCESS' );

		$this->assertTrue( $this->sut->verify_request( $this->webhook_request( 'EVENT-1' ) ) );
		$this->assertTrue( $this->sut->verify_request( $this->webhook_request( 'EVENT-1' ) ) );
		$this->assertCount( 1, $this->http_requests, 'The second check of the same event should not call PayPal' );

		$this->assertTrue( $this->sut->verify_request( $this->webhook_request( 'EVENT-2' ) ) );
		$this->assertCount( 2, $this->http_requests, 'Another event should be verified on its own' );
	}

	/**
	 * @testdox Should refuse the request and remember the refusal when PayPal cannot be reached.
	 */
	public function test_verification_that_cannot_reach_paypal_refuses_the_request(): void {
		$this->stub_verification( null );
		$this->logger->shouldReceive( 'error' )->once()->with( 'Webhook verification failed: Not able to verify webhook event.' );

		$this->assertFalse( $this->sut->verify_request( $this->webhook_request() ) );
		$this->assertFalse( $this->sut->verify_request( $this->webhook_request() ) );

		$this->assertCount( 1, $this->http_requests, 'The refusal should be remembered, not asked again' );
	}

	/**
	 * @testdox Should refuse the request without calling PayPal when a signature header is missing.
	 */
	public function test_request_without_a_signature_header_is_refused_without_calling_paypal(): void {
		$headers = $this->signature_headers();
		unset( $headers['PAYPAL-TRANSMISSION-SIG'] );
		GetAllHeadersDouble::set_headers( $headers );
		$this->logger->shouldReceive( 'error' )->once()->with( 'Webhook verification failed: Not a valid webhook event. Header PAYPAL-TRANSMISSION-SIG is missing' );

		$this->assertFalse( $this->sut->verify_request( $this->webhook_request() ) );

		$this->assertCount( 0, $this->http_requests, 'No call should be sent without the full signature' );
	}

	/**
	 * @testdox Should refuse a request that is not JSON without calling PayPal.
	 */
	public function test_request_that_is_not_json_is_refused_without_calling_paypal(): void {
		$request = $this->webhook_request();
		$request->set_header( 'content-type', 'text/plain' );
		$this->logger->shouldReceive( 'error' )->once()->with( 'Webhook request rejected: expected application/json content type.' );

		$this->assertFalse( $this->sut->verify_request( $request ) );

		$this->assertCount( 0, $this->http_requests, 'No call should be sent for a request that is not JSON' );
	}
}
