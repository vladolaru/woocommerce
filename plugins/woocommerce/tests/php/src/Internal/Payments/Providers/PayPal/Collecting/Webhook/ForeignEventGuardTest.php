<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Webhook;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Webhook\ForeignEventGuard;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Webhook\Guards;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\RecordingLogger;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use WC_Order;

/**
 * Tests for the handler that answers another store's capture and checkout events, and asks PayPal to retry an event
 * that arrives before the store saved the order's PayPal order ID.
 *
 * @group paypal-wallet
 */
class ForeignEventGuardTest extends WalletTestCase {
	use WebhookFixtures;

	/**
	 * The System Under Test.
	 *
	 * @var ForeignEventGuard
	 */
	private ForeignEventGuard $sut;

	/**
	 * Build the guard.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new ForeignEventGuard( new Guards(), new RecordingLogger() );
	}

	/**
	 * A checkout resource, a PayPal order, for a custom ID.
	 *
	 * @param string $paypal_order_id The PayPal order ID.
	 * @param string $custom_id       The custom ID.
	 * @return array
	 */
	private function checkout_resource( string $paypal_order_id, string $custom_id ): array {
		return array(
			'id'             => $paypal_order_id,
			'status'         => 'APPROVED',
			'purchase_units' => array( array( 'custom_id' => $custom_id ) ),
		);
	}

	/**
	 * A pending wallet order mid-capture: the wallet holds its PayPal order ID in memory, not saved yet.
	 *
	 * @return WC_Order
	 */
	private function pending_order_without_paypal_order_id(): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( PayPalGateway::ID );
		$order->set_status( OrderStatus::PENDING );
		$order->save();

		return $order;
	}

	/**
	 * @testdox Should leave a checkout event that names only a cart session to the wallet.
	 */
	public function test_cart_session_checkout_event_passes_through(): void {
		$this->assertFalse( $this->sut->responsible_for_request( $this->event_request( 'CHECKOUT.ORDER.APPROVED', $this->checkout_resource( 'PP-NEW', 'pcp_customer_abc' ) ) ) );
	}

	/**
	 * @testdox Should drop with success a checkout event whose PayPal order is not the named order's.
	 */
	public function test_mismatched_checkout_event_is_dropped(): void {
		$order   = $this->wallet_order( '1', PlatformTransport::APP_MERCHANT_APP, false );
		$request = $this->event_request( 'CHECKOUT.ORDER.APPROVED', $this->checkout_resource( 'PP-FOREIGN', (string) $order->get_id() ) );

		$this->assertTrue( $this->sut->responsible_for_request( $request ) );
		$response = $this->sut->handle_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
		$this->assertFalse( $this->sut->responsible_for_request( $this->event_request( 'CHECKOUT.ORDER.APPROVED', $this->checkout_resource( 'PP-ORDER-1', (string) $order->get_id() ) ) ), 'The order\'s own checkout event goes to the wallet' );
	}

	/**
	 * @testdox Should answer 503 for an event naming a pending order that has no PayPal order ID saved yet, so PayPal retries.
	 * @testWith ["PAYMENT.CAPTURE.COMPLETED"]
	 *           ["CHECKOUT.ORDER.APPROVED"]
	 *
	 * @param string $event_type The event type.
	 */
	public function test_event_before_the_paypal_order_id_is_saved_is_retried( string $event_type ): void {
		$order    = $this->pending_order_without_paypal_order_id();
		$resource = 'CHECKOUT.ORDER.APPROVED' === $event_type ? $this->checkout_resource( 'PP-ORDER-1', (string) $order->get_id() ) : $this->capture_resource( $order, 'PP-ORDER-1' );
		$request  = $this->event_request( $event_type, $resource );

		$this->assertTrue( $this->sut->responsible_for_request( $request ) );
		$response = $this->sut->handle_request( $request );

		$this->assertSame( 503, $response->get_status() );
		$this->assertFalse( $response->get_data()['success'] );
		$this->assertSame( 'pending', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * @testdox Should drop with success a capture event for an order whose saved PayPal order ID differs.
	 */
	public function test_event_for_a_differing_paypal_order_id_is_dropped(): void {
		$order = $this->pending_order_without_paypal_order_id();
		$order->update_meta_data( PayPalGateway::ORDER_ID_META_KEY, 'PP-OURS' );
		$order->save();

		$response = $this->sut->handle_request( $this->event_request( 'PAYMENT.CAPTURE.COMPLETED', $this->capture_resource( $order, 'PP-FOREIGN' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
	}
}
