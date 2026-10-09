<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Order;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\OrderPin;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\OrderAppContext;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use InvalidArgumentException;

/**
 * Tests for the order pin: the app a WooCommerce order's PayPal calls go through.
 *
 * @group paypal-wallet
 */
class OrderPinTest extends WalletTestCase {

	/**
	 * @testdox Should set the app as pending meta that the caller's save persists, and read it back.
	 * @testWith ["platform"]
	 *           ["merchant_app"]
	 *
	 * @param string $app The app.
	 */
	public function test_record_writes_the_meta( string $app ): void {
		$order = wc_create_order();

		OrderPin::record( $order, $app );

		$this->assertSame( $app, $order->get_meta( OrderAppContext::ORDER_APP_META_KEY, true ) );
		$this->assertFalse( OrderPin::is_pinned( wc_get_order( $order->get_id() ) ), 'record() leaves saving to the caller' );
		$order->save();
		$reloaded = wc_get_order( $order->get_id() );
		$this->assertSame( $app, $reloaded->get_meta( OrderAppContext::ORDER_APP_META_KEY, true ), 'The pin is saved with the order' );
		$this->assertSame( $app, OrderPin::app( $reloaded ) );
		$this->assertTrue( OrderPin::is_pinned( $reloaded ) );
	}

	/**
	 * @testdox Should read the platform app, and no pin, for an order without one or with an unknown value.
	 * @testWith [null]
	 *           [""]
	 *           ["someone_else"]
	 *
	 * @param string|null $stored The stored meta value, or null for none.
	 */
	public function test_app_defaults_to_the_platform( ?string $stored ): void {
		$order = wc_create_order();
		if ( null !== $stored ) {
			$order->update_meta_data( OrderAppContext::ORDER_APP_META_KEY, $stored );
			$order->save();
		}

		$this->assertSame( PlatformTransport::APP_PLATFORM, OrderPin::app( $order ) );
		$this->assertFalse( OrderPin::is_pinned( $order ) );
	}

	/**
	 * @testdox Should refuse to record an unknown app and leave the order unpinned.
	 */
	public function test_record_refuses_an_unknown_app(): void {
		$order = wc_create_order();

		try {
			OrderPin::record( $order, 'someone_else' );
			$this->fail( 'An unknown app must be refused' );
		} catch ( InvalidArgumentException $exception ) {
			$this->assertFalse( OrderPin::is_pinned( wc_get_order( $order->get_id() ) ) );
		}
	}
}
