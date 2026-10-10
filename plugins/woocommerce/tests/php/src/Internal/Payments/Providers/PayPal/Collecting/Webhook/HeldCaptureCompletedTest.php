<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Webhook;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Webhook\HeldCaptureCompleted;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * Tests for the handler that completes a held order when PayPal completes its capture.
 *
 * @group paypal-wallet
 */
class HeldCaptureCompletedTest extends WalletTestCase {
	use BootsCollectingContainer;
	use WebhookFixtures;

	/**
	 * The note the wallet's completion handler adds.
	 */
	private const CAPTURED_NOTE = 'Payment successfully captured.';

	/**
	 * The booted container.
	 *
	 * @var ContainerInterface
	 */
	private ContainerInterface $container;

	/**
	 * The System Under Test.
	 *
	 * @var HeldCaptureCompleted
	 */
	private HeldCaptureCompleted $sut;

	/**
	 * Boot a collecting store over a fake transport.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_collecting();
		$this->container = $this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) );
		$this->sut       = $this->container->get( 'collecting.webhook.held-completed' );
	}

	/**
	 * @testdox Should release a held order and complete it through the wallet's own completion, with one captured note.
	 */
	public function test_completed_capture_completes_the_held_order(): void {
		$order   = $this->wallet_order( '1', PlatformTransport::APP_MERCHANT_APP );
		$request = $this->event_request( 'PAYMENT.CAPTURE.COMPLETED', $this->capture_resource( $order, 'PP-ORDER-1' ) );

		$this->assertTrue( $this->sut->responsible_for_request( $request ) );
		$response = $this->sut->handle_request( $request );

		$this->assertTrue( $response->get_data()['success'] );
		$fresh = wc_get_order( $order->get_id() );
		$this->assertSame( 'processing', $fresh->get_status() );
		$this->assertFalse( $this->has_held_meta( $order ) );
		$this->assertSame( 1, $this->count_notes( $order, self::CAPTURED_NOTE ) );
		$this->assertNotNull( $fresh->get_date_paid(), 'The fork\'s completion handler marks the order paid' );
	}

	/**
	 * @testdox Should read the PayPal order for the transaction ID through the order's pinned app.
	 */
	public function test_completion_calls_paypal_through_the_pinned_app(): void {
		$order = $this->wallet_order( '1', PlatformTransport::APP_MERCHANT_APP );

		$this->sut->handle_request( $this->event_request( 'PAYMENT.CAPTURE.COMPLETED', $this->capture_resource( $order, 'PP-ORDER-1' ) ) );

		$reads = array_values(
			array_filter(
				$this->http_requests,
				static function ( array $entry ): bool {
					return false !== strpos( $entry['url'], 'v2/checkout/orders/PP-ORDER-1' );
				}
			)
		);
		$this->assertNotEmpty( $reads );
		$this->assertSame( 'Bearer token-merchant_app', $reads[0]['request']['headers']['Authorization'] );
	}

	/**
	 * @testdox Should complete the order once when PayPal delivers the event again, through the endpoint.
	 */
	public function test_redelivery_is_a_no_op(): void {
		$order    = $this->wallet_order( '1', PlatformTransport::APP_MERCHANT_APP );
		$endpoint = $this->container->get( 'webhook.endpoint.controller' );

		$first  = $endpoint->handle_request( $this->event_request( 'PAYMENT.CAPTURE.COMPLETED', $this->capture_resource( $order, 'PP-ORDER-1' ) ) );
		$second = $endpoint->handle_request( $this->event_request( 'PAYMENT.CAPTURE.COMPLETED', $this->capture_resource( $order, 'PP-ORDER-1' ) ) );

		$this->assertTrue( $first->get_data()['success'] );
		$this->assertTrue( $second->get_data()['success'] );
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( 1, $this->count_notes( $order, self::CAPTURED_NOTE ) );
	}

	/**
	 * @testdox Should answer an event whose PayPal order is not the held order's with success and change nothing.
	 */
	public function test_mismatched_event_changes_nothing(): void {
		$order = $this->wallet_order( '1', PlatformTransport::APP_MERCHANT_APP );

		$response = $this->sut->handle_request( $this->event_request( 'PAYMENT.CAPTURE.COMPLETED', $this->capture_resource( $order, 'PP-FOREIGN' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );
		$this->assertTrue( $this->has_held_meta( $order ) );
		$this->assertSame( 0, $this->count_notes( $order, self::CAPTURED_NOTE ) );
	}

	/**
	 * @testdox Should leave an order that is not held to the wallet's own handler.
	 */
	public function test_not_responsible_for_an_order_that_is_not_held(): void {
		$order = $this->wallet_order( '1', PlatformTransport::APP_MERCHANT_APP, false );

		$this->assertFalse( $this->sut->responsible_for_request( $this->event_request( 'PAYMENT.CAPTURE.COMPLETED', $this->capture_resource( $order, 'PP-ORDER-1' ) ) ) );
		$this->assertFalse( $this->sut->responsible_for_request( $this->event_request( 'PAYMENT.CAPTURE.PENDING', $this->capture_resource( $order, 'PP-ORDER-1' ) ) ), 'Another event type' );
	}
}
