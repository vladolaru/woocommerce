<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Webhook;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Webhook\Guards;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Tests for the guards that tell this store's webhook events from the other stores' events an app's subscription receives.
 *
 * @group paypal-wallet
 */
class GuardsTest extends WalletTestCase {
	use WebhookFixtures;

	/**
	 * The System Under Test.
	 *
	 * @var Guards
	 */
	private Guards $sut;

	/**
	 * Build the guards.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new Guards();
	}

	/**
	 * @testdox Should match a capture event to an order only when the related PayPal order ID is the order's.
	 */
	public function test_capture_event_matches_on_the_paypal_order_id(): void {
		$order = $this->wallet_order( '1' );

		$this->assertTrue( $this->sut->capture_event_matches_order( $this->capture_resource( $order, 'PP-ORDER-1' ), $order ) );
		$this->assertFalse( $this->sut->capture_event_matches_order( $this->capture_resource( $order, 'PP-ORDER-OTHER' ), $order ), 'Another store\'s PayPal order' );
	}

	/**
	 * @testdox Should not match a capture event naming a PayPal order to an order that has no PayPal order ID.
	 */
	public function test_capture_event_does_not_match_an_order_without_paypal_order_id(): void {
		$order = $this->wallet_order( '1' );
		$order->delete_meta_data( PayPalGateway::ORDER_ID_META_KEY );
		$order->save();

		$this->assertFalse( $this->sut->capture_event_matches_order( $this->capture_resource( $order, 'PP-ORDER-1' ), $order ) );
	}

	/**
	 * @testdox Should match a refund event, which names no PayPal order, on the refunded capture's ID.
	 */
	public function test_refund_event_matches_on_the_capture_id(): void {
		$order = $this->wallet_order( '1' );

		$this->assertTrue( $this->sut->capture_event_matches_order( $this->refund_resource( $order, 'CAPTURE-1' ), $order ) );
		$this->assertFalse( $this->sut->capture_event_matches_order( $this->refund_resource( $order, 'CAPTURE-OTHER' ), $order ) );
	}

	/**
	 * @testdox Should match an onboarding event only for the store's own non-empty tracking ID.
	 */
	public function test_onboarding_event_is_ours_on_the_tracking_id(): void {
		$this->assertTrue( $this->sut->onboarding_event_is_ours( array( 'tracking_id' => 'TRACK-OURS' ), 'TRACK-OURS' ) );
		$this->assertFalse( $this->sut->onboarding_event_is_ours( array( 'tracking_id' => 'TRACK-THEIRS' ), 'TRACK-OURS' ) );
		$this->assertFalse( $this->sut->onboarding_event_is_ours( array(), 'TRACK-OURS' ), 'No tracking ID in the event' );
		$this->assertFalse( $this->sut->onboarding_event_is_ours( array( 'tracking_id' => '' ), '' ), 'A store with no tracking ID' );
	}

	/**
	 * @testdox Should find the wallet order an event names by its custom ID.
	 */
	public function test_order_for_event_by_custom_id(): void {
		$order = $this->wallet_order( '1' );

		$found = $this->sut->order_for_event( $this->capture_resource( $order, 'PP-ORDER-1' ) );

		$this->assertNotNull( $found );
		$this->assertSame( $order->get_id(), $found->get_id() );
	}

	/**
	 * @testdox Should find the wallet order by its PayPal order ID when the custom ID is a cart session's.
	 */
	public function test_order_for_event_by_paypal_order_id(): void {
		$order                 = $this->wallet_order( '7' );
		$resource              = $this->capture_resource( $order, 'PP-ORDER-7' );
		$resource['custom_id'] = 'pcp_customer_abc';

		$found = $this->sut->order_for_event( $resource );

		$this->assertNotNull( $found );
		$this->assertSame( $order->get_id(), $found->get_id() );
	}

	/**
	 * @testdox Should find no order for an event naming an order another gateway was paid with.
	 */
	public function test_order_for_event_ignores_other_gateways(): void {
		$order = $this->wallet_order( '1' );
		$order->set_payment_method( 'bacs' );
		$order->save();

		$this->assertNull( $this->sut->order_for_event( $this->capture_resource( $order, 'PP-ORDER-1' ) ) );
	}

	/**
	 * @testdox Should find no order for an event naming no order of this store.
	 */
	public function test_order_for_event_finds_nothing_for_a_foreign_event(): void {
		$this->assertNull(
			$this->sut->order_for_event(
				array(
					'id'                 => 'CAPTURE-FOREIGN',
					'custom_id'          => '987654321',
					'supplementary_data' => array( 'related_ids' => array( 'order_id' => 'PP-FOREIGN' ) ),
				)
			)
		);
	}
}
