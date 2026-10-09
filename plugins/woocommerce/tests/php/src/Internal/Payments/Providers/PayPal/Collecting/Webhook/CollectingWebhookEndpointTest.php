<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Webhook;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Webhook\CollectingWebhookEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\IncomingWebhookEndpoint;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Tests for the webhook endpoint of a store the platform serves: each delivery is verified with the app whose
 * subscription it came through.
 *
 * @group paypal-wallet
 */
class CollectingWebhookEndpointTest extends WalletTestCase {
	use BootsCollectingContainer;
	use WebhookFixtures;

	/**
	 * Both apps' subscriptions.
	 */
	private const SUBSCRIPTIONS = array(
		PlatformTransport::APP_PLATFORM     => 'WH-PLATFORM',
		PlatformTransport::APP_MERCHANT_APP => 'WH-MERCHANT',
	);

	/**
	 * Boot a collecting store over a fake transport and return its webhook endpoint.
	 *
	 * @param FakePlatformTransport $transport The transport.
	 * @return IncomingWebhookEndpoint
	 */
	private function endpoint( FakePlatformTransport $transport ): IncomingWebhookEndpoint {
		return $this->boot_container( array( new TransportBindingModule( $transport ) ) )->get( 'webhook.endpoint.controller' );
	}

	/**
	 * The apps a transport was asked to verify with, in order.
	 *
	 * @param FakePlatformTransport $transport The transport.
	 * @return string[]
	 */
	private function verified_with( FakePlatformTransport $transport ): array {
		return array_map(
			static function ( array $call ): string {
				return $call[0];
			},
			$transport->calls_to( 'verify_webhook' )
		);
	}

	/**
	 * @testdox Should keep the wallet's own endpoint on a store the platform does not serve.
	 */
	public function test_unserved_store_keeps_the_wallets_endpoint(): void {
		$endpoint = $this->endpoint( new FakePlatformTransport() );

		$this->assertSame( IncomingWebhookEndpoint::class, get_class( $endpoint ) );
	}

	/**
	 * @testdox Should verify a capture event of a merchant-app order with the merchant app only, passing the PayPal headers.
	 */
	public function test_capture_event_is_verified_with_the_pinned_app(): void {
		$this->set_collecting();
		$transport = new FakePlatformTransport( array( 'webhooks' => self::SUBSCRIPTIONS ) );
		$endpoint  = $this->endpoint( $transport );
		$order     = $this->wallet_order( '1', PlatformTransport::APP_MERCHANT_APP );
		$request   = $this->event_request( 'PAYMENT.CAPTURE.PENDING', $this->capture_resource( $order, 'PP-ORDER-1', 'PENDING' ) );

		$this->assertInstanceOf( CollectingWebhookEndpoint::class, $endpoint );
		$this->assertTrue( $endpoint->verify_request( $request ) );

		$this->assertSame( array( PlatformTransport::APP_MERCHANT_APP ), $this->verified_with( $transport ) );
		$call = $transport->calls_to( 'verify_webhook' )[0];
		$this->assertSame( 'TX-1', $call[1]['paypal-transmission-id'] ?? null, 'The headers reach the transport by their PayPal names, as strings' );
		$this->assertSame( 'SHA256withRSA', $call[1]['paypal-auth-algo'] ?? null );
		$this->assertSame( $request->get_body(), $call[2] );
	}

	/**
	 * @testdox Should reject a pinned order's event its app does not verify, without asking the other app.
	 */
	public function test_pinned_event_never_falls_back_to_the_other_app(): void {
		$this->set_collecting();
		$transport = new FakePlatformTransport(
			array(
				'webhooks' => self::SUBSCRIPTIONS,
				'verify'   => array( PlatformTransport::APP_PLATFORM => true ),
			)
		);
		$endpoint  = $this->endpoint( $transport );
		$order     = $this->wallet_order( '1', PlatformTransport::APP_MERCHANT_APP );

		$this->assertFalse( $endpoint->verify_request( $this->event_request( 'PAYMENT.CAPTURE.COMPLETED', $this->capture_resource( $order, 'PP-ORDER-1' ) ) ) );
		$this->assertSame( array( PlatformTransport::APP_MERCHANT_APP ), $this->verified_with( $transport ) );
	}

	/**
	 * @testdox Should verify an onboarding event with the platform app first.
	 */
	public function test_onboarding_event_is_verified_with_the_platform(): void {
		$this->set_collecting();
		$transport = new FakePlatformTransport( array( 'webhooks' => self::SUBSCRIPTIONS ) );
		$endpoint  = $this->endpoint( $transport );

		$this->assertTrue( $endpoint->verify_request( $this->event_request( 'MERCHANT.ONBOARDING.COMPLETED', array( 'tracking_id' => 'TRACK-OURS' ) ) ) );
		$this->assertSame( array( PlatformTransport::APP_PLATFORM ), $this->verified_with( $transport ) );
	}

	/**
	 * @testdox Should fall back to the other subscribed app for an event with no pinned order, and accept when it verifies.
	 * @testWith ["unknown"]
	 *           ["unpinned"]
	 *
	 * @param string $kind Whether the event names no order of the store or an order with no pin.
	 */
	public function test_event_without_a_pinned_order_falls_back( string $kind ): void {
		$this->set_collecting();
		$transport = new FakePlatformTransport(
			array(
				'webhooks' => self::SUBSCRIPTIONS,
				'verify'   => array( PlatformTransport::APP_MERCHANT_APP => true ),
			)
		);
		$endpoint  = $this->endpoint( $transport );
		if ( 'unknown' === $kind ) {
			$resource = array(
				'id'                 => 'CAPTURE-FOREIGN',
				'custom_id'          => '987654321',
				'supplementary_data' => array( 'related_ids' => array( 'order_id' => 'PP-FOREIGN' ) ),
			);
		} else {
			$resource = $this->capture_resource( $this->wallet_order( '1', null ), 'PP-ORDER-1' );
		}

		$this->assertTrue( $endpoint->verify_request( $this->event_request( 'PAYMENT.CAPTURE.COMPLETED', $resource ) ) );
		$this->assertSame( array( PlatformTransport::APP_PLATFORM, PlatformTransport::APP_MERCHANT_APP ), $this->verified_with( $transport ) );
	}

	/**
	 * @testdox Should reject an event no subscribed app verifies.
	 */
	public function test_unverified_event_is_rejected(): void {
		$this->set_collecting();
		$transport = new FakePlatformTransport(
			array(
				'webhooks' => self::SUBSCRIPTIONS,
				'verify'   => false,
			)
		);

		$this->assertFalse( $this->endpoint( $transport )->verify_request( $this->event_request( 'MERCHANT.ONBOARDING.COMPLETED', array( 'tracking_id' => 'TRACK-OURS' ) ) ) );
		$this->assertSame( array( PlatformTransport::APP_PLATFORM, PlatformTransport::APP_MERCHANT_APP ), $this->verified_with( $transport ) );
	}

	/**
	 * @testdox Should try only the apps that hold a subscription.
	 */
	public function test_only_subscribed_apps_are_tried(): void {
		$this->set_collecting();
		$transport = new FakePlatformTransport( array( 'webhooks' => array( PlatformTransport::APP_MERCHANT_APP => 'WH-MERCHANT' ) ) );

		$this->assertTrue( $this->endpoint( $transport )->verify_request( $this->event_request( 'MERCHANT.ONBOARDING.COMPLETED', array( 'tracking_id' => 'TRACK-OURS' ) ) ) );
		$this->assertSame( array( PlatformTransport::APP_MERCHANT_APP ), $this->verified_with( $transport ) );
	}

	/**
	 * @testdox Should verify each event once per request, as the permission callback may run more than once.
	 */
	public function test_verification_is_cached_per_event(): void {
		$this->set_collecting();
		$transport = new FakePlatformTransport( array( 'webhooks' => self::SUBSCRIPTIONS ) );
		$endpoint  = $this->endpoint( $transport );
		$request   = $this->event_request( 'MERCHANT.ONBOARDING.COMPLETED', array( 'tracking_id' => 'TRACK-OURS' ) );

		$this->assertTrue( $endpoint->verify_request( $request ) );
		$this->assertTrue( $endpoint->verify_request( $request ) );

		$this->assertCount( 1, $transport->calls_to( 'verify_webhook' ) );
	}

	/**
	 * @testdox Should reject a delivery that is not JSON without asking PayPal.
	 */
	public function test_non_json_delivery_is_rejected(): void {
		$this->set_collecting();
		$transport = new FakePlatformTransport( array( 'webhooks' => self::SUBSCRIPTIONS ) );
		$request   = $this->event_request( 'MERCHANT.ONBOARDING.COMPLETED', array( 'tracking_id' => 'TRACK-OURS' ) );
		$request->set_header( 'Content-Type', 'text/plain' );

		$this->assertFalse( $this->endpoint( $transport )->verify_request( $request ) );
		$this->assertSame( array(), $transport->calls_to( 'verify_webhook' ) );
	}

	/**
	 * @testdox Should answer an event for another store's order with success and change nothing.
	 */
	public function test_foreign_capture_event_is_answered_and_ignored(): void {
		$this->set_collecting();
		$endpoint = $this->endpoint( new FakePlatformTransport( array( 'webhooks' => self::SUBSCRIPTIONS ) ) );
		// A local order whose ID another store's event happens to carry as its custom ID.
		$order = $this->wallet_order( '1', PlatformTransport::APP_MERCHANT_APP, false );

		foreach ( array( 'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.PENDING', 'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED', 'PAYMENT.CAPTURE.DENIED' ) as $type ) {
			$response = $endpoint->handle_request( $this->event_request( $type, $this->capture_resource( $order, 'PP-FOREIGN' ), 'WH-' . $type ) );

			$this->assertSame( 200, $response->get_status() );
			$this->assertTrue( $response->get_data()['success'], "$type is answered with success" );
		}

		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( 'on-hold', $fresh->get_status() );
		$this->assertSame( array(), $fresh->get_refunds() );
	}
}
