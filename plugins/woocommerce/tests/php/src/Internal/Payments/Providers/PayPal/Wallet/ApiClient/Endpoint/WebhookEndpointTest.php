<?php
/**
 * Tests for the webhook endpoint.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\WebhookEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Webhook;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\PayPalApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\WebhookEventFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\WebhookFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ApiHostResolver;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Mockery\MockInterface;
use stdClass;
use WP_Error;

/**
 * The webhook endpoint: create, list, delete, simulate and verify webhooks over a stubbed HTTP layer. Which event types
 * the wallet subscribes to is pinned by WebhookHandlersServicesTest; here create() is only held to send what it is given.
 *
 * @group paypal-wallet
 */
class WebhookEndpointTest extends WalletTestCase {

	private const HOST = 'https://example.com/';

	/**
	 * The host resolver mock.
	 *
	 * @var ApiHostResolver|MockInterface
	 */
	private $host_resolver;

	/**
	 * Set up the collaborators.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->host_resolver = $this->mock( ApiHostResolver::class );
		$this->host_resolver->shouldReceive( 'host' )->andReturn( self::HOST )->byDefault();
	}

	/**
	 * Build the endpoint under test.
	 *
	 * @return WebhookEndpoint
	 */
	private function create_endpoint(): WebhookEndpoint {
		return new WebhookEndpoint(
			$this->host_resolver,
			$this->make_bearer( false ),
			new WebhookFactory(),
			new WebhookEventFactory(),
			$this->mock( LoggerInterface::class )->shouldIgnoreMissing()
		);
	}

	/**
	 * Answer the one request the test expects with the given response and anything else with an error, so a request to
	 * another URL or with another method shows up as a failed call.
	 *
	 * @param string         $method   The expected HTTP method.
	 * @param string         $path     The expected path below the host.
	 * @param array|WP_Error $response The answer.
	 */
	private function stub_notifications_request( string $method, string $path, $response ): void {
		$this->stub_http(
			static function ( $request, $url ) use ( $method, $path, $response ) {
				if ( self::HOST . $path !== $url || $method !== $request['method'] ) {
					return new WP_Error( 'unexpected_request', $request['method'] . ' ' . $url );
				}

				return $response;
			}
		);
	}

	/**
	 * Assert the one request the endpoint sent carried the bearer, and return its arguments.
	 *
	 * @return array
	 */
	private function assert_single_authorized_request(): array {
		$this->assertCount( 1, $this->http_requests, 'Exactly one request should be sent' );
		$this->assertSame( 'Bearer bearer', $this->http_requests[0]['request']['headers']['Authorization'] );

		return $this->http_requests[0]['request'];
	}

	/**
	 * Given a webhook without an ID, create() sends exactly the webhook it is given (its URL and event types) and
	 * returns the webhook PayPal answers with, ID included.
	 *
	 * @testdox Should send the webhook to the configured host and return the created webhook.
	 */
	public function test_create_sends_request_to_configured_host_and_returns_created_webhook(): void {
		$event_types = array(
			(object) array( 'name' => 'CHECKOUT.ORDER.APPROVED' ),
			(object) array( 'name' => 'PAYMENT.CAPTURE.COMPLETED' ),
		);
		$hook        = new Webhook( 'https://mysite.com/incoming', $event_types );
		$this->stub_notifications_request(
			'POST',
			'v1/notifications/webhooks',
			$this->http_response(
				201,
				(string) wp_json_encode(
					array(
						'id'          => 'NEW-ID',
						'url'         => 'https://mysite.com/incoming',
						'event_types' => $event_types,
					)
				)
			)
		);

		$result = $this->create_endpoint()->create( $hook );

		$this->assertSame( 'NEW-ID', $result->id() );
		$this->assertSame( 'https://mysite.com/incoming', $result->url() );
		$this->assertEquals( $event_types, $result->event_types() );
		$request = $this->assert_single_authorized_request();
		$this->assertSame( 'application/json', $request['headers']['Content-Type'] );
		$this->assertSame( wp_json_encode( $hook->to_array() ), $request['body'], 'The webhook is sent as it was given' );
	}

	/**
	 * Given a webhook that already has an ID, create() sends no request and returns the same instance.
	 *
	 * @testdox Should return the existing webhook without a request when it was already created.
	 */
	public function test_create_returns_existing_webhook_without_request_when_already_created(): void {
		$hook = new Webhook( 'https://mysite.com/incoming', array(), 'EXISTING-ID' );

		$result = $this->create_endpoint()->create( $hook );

		$this->assertSame( $hook, $result );
		$this->assertCount( 0, $this->http_requests );
	}

	/**
	 * @testdox Should throw a PayPal API exception when PayPal does not answer a create with 201.
	 */
	public function test_create_throws_paypal_api_exception_on_unexpected_status(): void {
		$this->stub_http( $this->http_response( 400, '{"name":"INVALID_REQUEST"}' ) );

		$this->expectException( PayPalApiException::class );

		$this->create_endpoint()->create( new Webhook( 'https://mysite.com/incoming', array() ) );
	}

	/**
	 * Given the webhooks PayPal reports for the current auth token, list() returns a webhook for each.
	 *
	 * @testdox Should return the webhooks PayPal lists.
	 */
	public function test_list_returns_webhooks_from_factory(): void {
		$event_types = array( (object) array( 'name' => 'CHECKOUT.ORDER.APPROVED' ) );
		$this->stub_notifications_request(
			'GET',
			'v1/notifications/webhooks',
			$this->http_response(
				200,
				(string) wp_json_encode(
					array(
						'webhooks' => array(
							array(
								'id'          => 'ID-1',
								'url'         => 'https://mysite.com/incoming',
								'event_types' => $event_types,
							),
						),
					)
				)
			)
		);

		$result = $this->create_endpoint()->list();

		$this->assertCount( 1, $result );
		$this->assertSame( 'ID-1', $result[0]->id() );
		$this->assertSame( 'https://mysite.com/incoming', $result[0]->url() );
		$this->assert_single_authorized_request();
	}

	/**
	 * @testdox Should throw a runtime exception when the webhook list cannot be loaded.
	 */
	public function test_list_throws_when_request_fails(): void {
		$this->stub_http( new WP_Error( 'http_request_failed', 'Connection timeout' ) );

		$this->expectException( RuntimeException::class );

		$this->create_endpoint()->list();
	}

	/**
	 * Given a persisted webhook, delete() sends the delete request for its ID on the configured host.
	 *
	 * @testdox Should send the delete request for the webhook ID.
	 */
	public function test_delete_sends_request_for_webhook_id(): void {
		$hook = new Webhook( 'https://mysite.com/incoming', array(), 'ID-TO-DELETE' );
		$this->stub_notifications_request( 'DELETE', 'v1/notifications/webhooks/ID-TO-DELETE', $this->http_response( 204, '' ) );

		$this->create_endpoint()->delete( $hook );

		$this->assert_single_authorized_request();
	}

	/**
	 * @testdox Should send no request when the webhook was never persisted.
	 */
	public function test_delete_does_nothing_when_webhook_has_no_id(): void {
		$hook = new Webhook( 'https://mysite.com/incoming', array() );

		$this->create_endpoint()->delete( $hook );

		$this->assertCount( 0, $this->http_requests );
	}

	/**
	 * @testdox Should throw a PayPal API exception when PayPal rejects the delete with a status other than 204.
	 */
	public function test_delete_throws_paypal_api_exception_on_unexpected_status(): void {
		$hook = new Webhook( 'https://mysite.com/incoming', array(), 'ID-TO-DELETE' );
		$this->stub_http( $this->http_response( 404, '{"message":"not found"}' ) );

		$this->expectException( PayPalApiException::class );

		$this->create_endpoint()->delete( $hook );
	}

	/**
	 * Given a webhook subscription and an event type, simulate() asks PayPal to send that event and returns the event
	 * PayPal answers with.
	 *
	 * @testdox Should request the simulated event and return the event PayPal reports.
	 */
	public function test_simulate_returns_event_from_factory(): void {
		$hook = new Webhook( 'https://mysite.com/incoming', array(), 'ID-1' );
		$this->stub_notifications_request(
			'POST',
			'v1/notifications/simulate-event',
			$this->http_response( 202, '{"id":"EVENT-1","event_type":"CHECKOUT.ORDER.APPROVED"}' )
		);

		$result = $this->create_endpoint()->simulate( $hook, 'CHECKOUT.ORDER.APPROVED', null );

		$this->assertSame( 'EVENT-1', $result->id() );
		$this->assertSame( 'CHECKOUT.ORDER.APPROVED', $result->event_type() );
		$request = $this->assert_single_authorized_request();
		$this->assertSame(
			array(
				'webhook_id' => 'ID-1',
				'event_type' => 'CHECKOUT.ORDER.APPROVED',
			),
			json_decode( $request['body'], true ),
			'Without a resource version none is sent'
		);
	}

	/**
	 * @testdox Should send the resource version of a simulated event when one is given.
	 */
	public function test_simulate_sends_the_resource_version(): void {
		$hook = new Webhook( 'https://mysite.com/incoming', array(), 'ID-1' );
		$this->stub_http( $this->http_response( 202, '{"id":"EVENT-1","event_type":"CHECKOUT.ORDER.APPROVED"}' ) );

		$this->create_endpoint()->simulate( $hook, 'CHECKOUT.ORDER.APPROVED', '2.0' );

		$request = $this->assert_single_authorized_request();
		$this->assertSame( '2.0', json_decode( $request['body'], true )['resource_version'] );
	}

	/**
	 * Given a PayPal API that reports the webhook signature as verified, verify_event() returns true; given one that
	 * reports it as failed, false.
	 *
	 * @testdox Should reflect the verification status PayPal reports.
	 *
	 * @dataProvider data_verification_status
	 *
	 * @param string $verification_status The status PayPal reports.
	 * @param bool   $expected            Whether the event is verified.
	 */
	public function test_verify_event_reflects_paypal_verification_status( string $verification_status, bool $expected ): void {
		$this->stub_notifications_request(
			'POST',
			'v1/notifications/verify-webhook-signature',
			$this->http_response( 200, (string) wp_json_encode( array( 'verification_status' => $verification_status ) ) )
		);

		$result = $this->create_endpoint()->verify_event(
			'SHA256withRSA',
			'https://api.paypal.com/cert',
			'transmission-id',
			'transmission-sig',
			'transmission-time',
			'WEBHOOK-ID',
			new stdClass()
		);

		$this->assertSame( $expected, $result );
		$request = $this->assert_single_authorized_request();
		$this->assertSame( 'WEBHOOK-ID', json_decode( $request['body'], true )['webhook_id'] );
		$this->assertSame( 'transmission-sig', json_decode( $request['body'], true )['transmission_sig'] );
	}

	/**
	 * The statuses PayPal reports and whether the event counts as verified.
	 *
	 * @return array<string, array>
	 */
	public function data_verification_status(): array {
		return array(
			'success status verifies the event' => array( 'SUCCESS', true ),
			'failure status rejects the event'  => array( 'FAILURE', false ),
		);
	}

	/**
	 * @testdox Should throw a runtime exception when the webhook event cannot be verified because the request failed.
	 */
	public function test_verify_event_throws_when_request_fails(): void {
		$this->stub_http( new WP_Error( 'http_request_failed', 'Connection timeout' ) );

		$this->expectException( RuntimeException::class );

		$this->create_endpoint()->verify_event(
			'SHA256withRSA',
			'https://api.paypal.com/cert',
			'transmission-id',
			'transmission-sig',
			'transmission-time',
			'WEBHOOK-ID',
			new stdClass()
		);
	}

	/**
	 * The host follows the connection state, so each call resolves it again.
	 *
	 * @testdox Should resolve the host again on every call instead of keeping the one from construction.
	 */
	public function test_host_is_resolved_fresh_on_every_call_not_cached_at_construction(): void {
		$this->host_resolver = $this->mock( ApiHostResolver::class );
		$this->host_resolver->shouldReceive( 'host' )->andReturn( 'https://before-connect.example.com', 'https://after-connect.example.com' );
		$this->stub_http( $this->http_response( 200, '{"webhooks":[]}' ) );

		$testee = $this->create_endpoint();
		$testee->list();
		$testee->list();

		$this->assertSame(
			array(
				'https://before-connect.example.com/v1/notifications/webhooks',
				'https://after-connect.example.com/v1/notifications/webhooks',
			),
			array_column( $this->http_requests, 'url' )
		);
	}
}
