<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Transport;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\OrderPin;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\OrderAppContext;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface\HoldsWalletState;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use InvalidArgumentException;

/**
 * Tests for the request-scoped order app context and the app a call uses.
 *
 * @group paypal-wallet
 */
class OrderAppContextTest extends WalletTestCase {

	use HoldsWalletState;

	/**
	 * The System Under Test.
	 *
	 * @var OrderAppContext
	 */
	private OrderAppContext $sut;

	/**
	 * Set up a fresh context.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new OrderAppContext();
	}

	/**
	 * @testdox Should start on the platform app, not entered.
	 */
	public function test_defaults_to_the_platform_app(): void {
		$this->assertSame( PlatformTransport::APP_PLATFORM, $this->sut->current() );
		$this->assertFalse( $this->sut->is_entered() );
	}

	/**
	 * @testdox Should report the app it entered.
	 */
	public function test_enter_sets_the_current_app(): void {
		$this->sut->enter( PlatformTransport::APP_MERCHANT_APP );

		$this->assertSame( PlatformTransport::APP_MERCHANT_APP, $this->sut->current() );
		$this->assertTrue( $this->sut->is_entered() );
	}

	/**
	 * @testdox Should refuse an app the transport does not know.
	 */
	public function test_enter_refuses_an_unknown_app(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->sut->enter( 'first_party' );
	}

	/**
	 * @testdox Should enter the app pinned on the order.
	 */
	public function test_enter_for_order_reads_the_pinned_app(): void {
		$order = wc_create_order();
		$order->update_meta_data( OrderAppContext::ORDER_APP_META_KEY, PlatformTransport::APP_MERCHANT_APP );
		$order->save();

		$this->sut->enter_for_order( wc_get_order( $order->get_id() ) );

		$this->assertSame( '_wc_paypal_wallet_order_app', OrderAppContext::ORDER_APP_META_KEY );
		$this->assertSame( PlatformTransport::APP_MERCHANT_APP, $this->sut->current() );
		$this->assertTrue( $this->sut->is_entered() );
	}

	/**
	 * @testdox Should enter the platform app for an order with no pin or an unknown one.
	 * @testWith [null]
	 *           ["first_party"]
	 *
	 * @param string|null $pinned The stored pin, or null for none.
	 */
	public function test_enter_for_order_falls_back_to_the_platform_app( ?string $pinned ): void {
		$order = wc_create_order();
		if ( null !== $pinned ) {
			$order->update_meta_data( OrderAppContext::ORDER_APP_META_KEY, $pinned );
			$order->save();
		}
		$this->sut->enter( PlatformTransport::APP_MERCHANT_APP );

		$this->sut->enter_for_order( $order );

		$this->assertSame( PlatformTransport::APP_PLATFORM, $this->sut->current() );
		$this->assertTrue( $this->sut->is_entered(), 'An order context counts as entered even when it falls back' );
	}

	/**
	 * @testdox Should go back to the platform app, not entered, after a reset.
	 */
	public function test_reset_leaves_the_context(): void {
		$this->sut->enter( PlatformTransport::APP_MERCHANT_APP );

		$this->sut->reset();

		$this->assertSame( PlatformTransport::APP_PLATFORM, $this->sut->current() );
		$this->assertFalse( $this->sut->is_entered() );
	}

	/**
	 * @testdox Should ask the transport to pick the app for the payee when no order context is entered.
	 * @testWith ["merchant_app"]
	 *           ["platform"]
	 *
	 * @param string $pick The app the transport picks.
	 */
	public function test_for_call_picks_when_not_entered( string $pick ): void {
		$transport = new FakePlatformTransport( array( 'pick' => $pick ) );

		$this->assertSame( $pick, $this->sut->for_call( $transport, 'payee@example.com' ) );
		$this->assertSame( array( array( 'payee@example.com' ) ), $transport->calls_to( 'pick_order_app' ) );
		$this->assertFalse( $this->sut->is_entered(), 'A pick does not enter the context' );
	}

	/**
	 * @testdox Should use the entered app without asking the transport to pick.
	 */
	public function test_for_call_uses_the_entered_app(): void {
		$transport = new FakePlatformTransport( array( 'pick' => PlatformTransport::APP_MERCHANT_APP ) );
		$this->sut->enter( PlatformTransport::APP_PLATFORM );

		$this->assertSame( PlatformTransport::APP_PLATFORM, $this->sut->for_call( $transport, 'payee@example.com' ) );
		$this->assertSame( array(), $transport->calls_to( 'pick_order_app' ) );
	}

	/**
	 * @testdox Should throw the wallet's exception when the transport picks an app it does not know.
	 */
	public function test_for_call_refuses_an_unknown_pick(): void {
		$transport = new FakePlatformTransport( array( 'pick' => 'first_party' ) );
		$this->expectException( RuntimeException::class );

		$this->sut->for_call( $transport, 'payee@example.com' );
	}

	/**
	 * @testdox Should keep an order on the app that created it after the store becomes platform connected, while a new call picks the platform app.
	 */
	public function test_pin_survives_the_platform_connection(): void {
		$transport = new FakePlatformTransport();
		$this->set_collecting();
		$picked = $this->sut->for_call( $transport, 'payee@example.com' );
		$this->assertSame( PlatformTransport::APP_MERCHANT_APP, $picked, 'A collecting store picks the merchant app' );
		$order = wc_create_order();
		OrderPin::record( $order, $picked );
		$order->save();
		$this->sut->reset();

		$this->set_platform_connected();

		$this->assertSame( PlatformTransport::APP_PLATFORM, $this->sut->for_call( $transport, 'payee@example.com' ), 'A new call picks the platform app' );
		$this->sut->reset();
		$this->sut->enter_for_order( wc_get_order( $order->get_id() ) );
		$this->assertSame( PlatformTransport::APP_MERCHANT_APP, $this->sut->current(), 'The pinned order keeps its app' );
	}
}
