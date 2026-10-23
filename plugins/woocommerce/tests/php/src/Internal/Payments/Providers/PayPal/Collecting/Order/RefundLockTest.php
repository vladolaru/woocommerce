<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Order;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\LockingRefundProcessor;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\OrderPin;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\RefundLock;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface\OwnerIndependent;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor\RefundProcessor;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use ReflectionClass;
use ReflectionMethod;
use WC_Order;
use WP_Error;

/**
 * Tests for the server-side refund lock while PayPal Wallet setup is incomplete.
 *
 * @group paypal-wallet
 */
class RefundLockTest extends WalletTestCase {
	use BootsCollectingContainer;

	private const MESSAGE = 'Refunds are available once PayPal Wallet setup is complete';

	/**
	 * The gateway list before the test.
	 *
	 * @var array
	 */
	private array $saved_gateways = array();

	/**
	 * Keep the gateway list, which booting the wallet can rebuild with the wallet's own gateway.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->saved_gateways = WC()->payment_gateways()->payment_gateways;
	}

	/**
	 * Put the gateway list back. It is restored as it was, not rebuilt: a rebuild would run the booted wallet's own
	 * gateway filter, which the parent removes only afterwards, and keep its gateway for later tests.
	 */
	public function tearDown(): void {
		try {
			WC()->payment_gateways()->payment_gateways = $this->saved_gateways;
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Put the store in the collecting state.
	 */
	private function set_collecting(): void {
		$this->set_wallet_option(
			Options::COLLECTING,
			array(
				'payee_email' => 'payee@example.com',
				'tracking_id' => 'abc',
				'environment' => 'sandbox',
				'payee_bound' => false,
			)
		);
	}

	/**
	 * Put the store in the platform-connected state.
	 */
	private function set_platform_connected(): void {
		$this->set_wallet_option(
			Options::PLATFORM,
			array(
				'merchant_id' => 'M2',
				'tracking_id' => 'abc',
				'payee_email' => 'connected@example.com',
				'environment' => 'sandbox',
			)
		);
	}

	/**
	 * A wallet order, optionally pinned and held.
	 *
	 * @param bool $pinned Whether the order carries the pin.
	 * @param bool $held   Whether the order carries the held-capture meta.
	 * @return WC_Order
	 */
	private function wallet_order( bool $pinned, bool $held ): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( PayPalGateway::ID );
		$order->update_meta_data( PayPalGateway::ORDER_ID_META_KEY, 'PP-LOCK-1' );
		if ( $held ) {
			$order->update_meta_data( RefundLock::HELD_CAPTURE_META_KEY, 'PAYEE_SETUP_PENDING' );
		}
		$order->save();
		if ( $pinned ) {
			OrderPin::record( $order, PlatformTransport::APP_MERCHANT_APP );
			$order->save();
		}

		return $order;
	}

	/**
	 * Set the store state by name.
	 *
	 * @param string $state `collecting`, `platform_connected` or `dormant`.
	 */
	private function set_state( string $state ): void {
		if ( ConnectionState::COLLECTING === $state ) {
			$this->set_collecting();
		} elseif ( ConnectionState::PLATFORM_CONNECTED === $state ) {
			$this->set_platform_connected();
		}
	}

	/**
	 * @testdox Should lock a held order, and any wallet order while the store still collects, and nothing else.
	 * @testWith ["collecting", true, true, true]
	 *           ["collecting", false, true, true]
	 *           ["collecting", true, false, true]
	 *           ["collecting", false, false, true]
	 *           ["platform_connected", true, true, true]
	 *           ["platform_connected", false, true, true]
	 *           ["platform_connected", true, false, false]
	 *           ["platform_connected", false, false, false]
	 *           ["dormant", true, false, false]
	 *           ["dormant", false, true, true]
	 *
	 * @param string $state    The store state.
	 * @param bool   $pinned   Whether the order carries the pin.
	 * @param bool   $held     Whether the order carries the held-capture meta.
	 * @param bool   $expected Whether refunds are locked.
	 */
	public function test_is_locked( string $state, bool $pinned, bool $held, bool $expected ): void {
		$this->set_state( $state );
		$order = $this->wallet_order( $pinned, $held );

		$this->assertSame( $expected, ( new RefundLock( new ConnectionState() ) )->is_locked( $order ) );
	}

	/**
	 * @testdox Should not lock, while collecting, an order another gateway paid or one with no PayPal order ID.
	 * @testWith ["bacs", "PP-LOCK-1"]
	 *           ["ppcp-gateway", ""]
	 *
	 * @param string $payment_method The order's payment method.
	 * @param string $paypal_id      The PayPal order ID meta, empty for none.
	 */
	public function test_collecting_store_does_not_lock_other_orders( string $payment_method, string $paypal_id ): void {
		$this->set_collecting();
		$order = wc_create_order();
		$order->set_payment_method( $payment_method );
		if ( '' !== $paypal_id ) {
			$order->update_meta_data( PayPalGateway::ORDER_ID_META_KEY, $paypal_id );
		}
		$order->save();

		$this->assertFalse( ( new RefundLock( new ConnectionState() ) )->is_locked( $order ) );
	}

	/**
	 * @testdox Should not lock a pinned order another gateway paid, even while the store collects.
	 */
	public function test_collecting_store_does_not_lock_a_pinned_order_of_another_gateway(): void {
		$this->set_collecting();
		$order = $this->wallet_order( true, false );
		$order->set_payment_method( 'bacs' );
		$order->save();

		$this->assertFalse( ( new RefundLock( new ConnectionState() ) )->is_locked( $order ) );
	}

	/**
	 * @testdox Should let the refund-locked filter override the decision both ways, and pass it the order.
	 * @testWith [true, false]
	 *           [false, true]
	 *
	 * @param bool $held     Whether the order is held, which locks it.
	 * @param bool $override The filter's answer.
	 */
	public function test_filter_overrides_the_decision( bool $held, bool $override ): void {
		$this->set_platform_connected();
		$order = $this->wallet_order( true, $held );
		$calls = $this->spy_filter( 'woocommerce_paypal_wallet_refund_locked', $override );

		$this->assertSame( $override, ( new RefundLock( new ConnectionState() ) )->is_locked( $order ) );
		$this->assertCount( 1, $calls );
		$this->assertSame( $held, $calls[0][0], 'The filter gets the computed decision' );
		$this->assertSame( $order->get_id(), $calls[0][1]->get_id(), 'The filter gets the order' );
	}

	/**
	 * @testdox Should keep the computed decision when a filter callback answers something other than a bool.
	 * @testWith ["yes"]
	 *           [0]
	 *           [null]
	 *
	 * @param mixed $returned The filter's answer.
	 */
	public function test_filter_answer_that_is_not_a_bool_is_ignored( $returned ): void {
		$this->set_collecting();
		$order = $this->wallet_order( true, false );
		add_filter(
			'woocommerce_paypal_wallet_refund_locked',
			static function () use ( $returned ) {
				return $returned;
			}
		);

		$this->assertTrue( ( new RefundLock( new ConnectionState() ) )->is_locked( $order ) );
	}

	/**
	 * @testdox Should refuse a locked refund with the setup message and leave the wrapped processor alone.
	 */
	public function test_processor_refuses_a_locked_order(): void {
		$this->set_collecting();
		$order = $this->wallet_order( true, false );
		$inner = $this->mock( RefundProcessor::class );
		$inner->shouldNotReceive( 'process' );
		$sut = new LockingRefundProcessor( $inner, new RefundLock( new ConnectionState() ) );

		try {
			$sut->process( $order, 5.0, 'reason' );
			$this->fail( 'A locked refund must be refused' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( self::MESSAGE, $exception->getMessage() );
		}
	}

	/**
	 * @testdox Should hand an unlocked refund, and the void and mode calls, to the wrapped processor.
	 */
	public function test_processor_delegates_an_unlocked_order(): void {
		$this->set_platform_connected();
		$order        = $this->wallet_order( true, false );
		$paypal_order = new Order( 'PP-LOCK-1', array(), new OrderStatus( OrderStatus::COMPLETED ) );
		$inner        = $this->mock( RefundProcessor::class );
		$inner->shouldReceive( 'process' )->once()->with( $order, 5.0, 'reason' )->andReturn( false );
		$inner->shouldReceive( 'determine_refund_mode' )->once()->with( $paypal_order )->andReturn( RefundProcessor::REFUND_MODE_VOID );
		$inner->shouldReceive( 'void' )->once()->with( $paypal_order );
		$inner->shouldReceive( 'refund' )->once()->with( $paypal_order, $order, 5.0, 'reason' )->andReturn( 'REFUND-1' );
		$sut = new LockingRefundProcessor( $inner, new RefundLock( new ConnectionState() ) );

		$this->assertFalse( $sut->process( $order, 5.0, 'reason' ) );
		$this->assertSame( RefundProcessor::REFUND_MODE_VOID, $sut->determine_refund_mode( $paypal_order ) );
		$sut->void( $paypal_order );
		$this->assertSame( 'REFUND-1', $sut->refund( $paypal_order, $order, 5.0, 'reason' ) );
	}

	/**
	 * @testdox Should declare every public method of the wallet's refund processor, so none bypasses the lock or the wrapped processor.
	 */
	public function test_processor_declares_every_public_method_of_the_wallet_processor(): void {
		$own = new ReflectionClass( LockingRefundProcessor::class );

		foreach ( ( new ReflectionClass( RefundProcessor::class ) )->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			$this->assertSame( LockingRefundProcessor::class, $own->getMethod( $method->getName() )->getDeclaringClass()->getName(), $method->getName() . '() is declared by the decorator' );
		}
	}

	/**
	 * @testdox Should make WooCommerce refuse a locked gateway refund with the setup message, without calling PayPal.
	 */
	public function test_gateway_refund_of_a_locked_order_is_a_wp_error(): void {
		$this->set_collecting();
		$container = $this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) );
		$this->assertInstanceOf( LockingRefundProcessor::class, $container->get( 'wcgateway.processor.refunds' ) );
		WC()->payment_gateways()->payment_gateways = array( $container->get( 'wcgateway.paypal-gateway' ) );

		$order = $this->wallet_order( true, false );
		$order->set_total( '20.00' );
		$order->save();

		$result = wc_refund_payment( $order, '5.00', 'reason' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( self::MESSAGE, $result->get_error_message() );
		$this->assertSame( array(), $this->http_requests, 'No PayPal call is made' );
	}

	/**
	 * @testdox Should decide base_locked() as is_locked() does, without asking the filter.
	 * @testWith ["collecting", true, true]
	 *           ["collecting", false, true]
	 *           ["platform_connected", true, true]
	 *           ["platform_connected", false, false]
	 *
	 * @param string $state    The store state.
	 * @param bool   $held     Whether the order carries the held-capture meta.
	 * @param bool   $expected Whether the order is locked before the filter.
	 */
	public function test_base_locked_ignores_the_filter( string $state, bool $held, bool $expected ): void {
		$this->set_state( $state );
		$order = $this->wallet_order( false, $held );
		$calls = $this->spy_filter( 'woocommerce_paypal_wallet_refund_locked', ! $expected );

		$sut = new RefundLock( new ConnectionState() );

		$this->assertSame( $expected, $sut->base_locked( $order ) );
		$this->assertCount( 0, $calls, 'The base predicate never calls the filter' );
	}

	/**
	 * @testdox Should lock through the shell's callback on the filter the order view applies, and keep is_locked() unchanged with one filter call and no recursion.
	 */
	public function test_shell_callback_locks_a_held_order_for_the_view(): void {
		$this->set_platform_connected();
		$this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) );
		( new OwnerIndependent() )->register();
		$held   = $this->wallet_order( true, true );
		$normal = $this->wallet_order( true, false );
		$calls  = array();
		add_filter(
			'woocommerce_paypal_wallet_refund_locked',
			static function ( $locked, $order ) use ( &$calls ) {
				$calls[] = array( $locked, $order->get_id() );
				return $locked;
			},
			10,
			2
		);

		$this->assertTrue( apply_filters( 'woocommerce_paypal_wallet_refund_locked', false, $held ), 'The view applies the filter to false and gets the lock' );
		$this->assertFalse( apply_filters( 'woocommerce_paypal_wallet_refund_locked', false, $normal ), 'An order that is not locked stays unlocked' );

		$calls = array();
		$sut   = new RefundLock( new ConnectionState() );
		$this->assertTrue( $sut->is_locked( $held ) );
		$this->assertFalse( $sut->is_locked( $normal ) );
		$this->assertSame( array( array( true, $held->get_id() ), array( false, $normal->get_id() ) ), $calls, 'One filter call per decision' );
	}

	/**
	 * @testdox Should let a callback after the module's unlock a refund through is_locked(), as it could before the module hooked the filter.
	 */
	public function test_a_later_callback_can_still_unlock(): void {
		$this->set_collecting();
		$this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) );
		$order = $this->wallet_order( true, true );
		add_filter( 'woocommerce_paypal_wallet_refund_locked', '__return_false', 10 );

		$this->assertFalse( ( new RefundLock( new ConnectionState() ) )->is_locked( $order ) );
	}

	/**
	 * @testdox Should keep a lock a callback set, compute the base for a false value, and pass a non-order or non-bool through.
	 */
	public function test_callback_handles_its_inputs(): void {
		$this->set_collecting();
		$sut    = new RefundLock( new ConnectionState() );
		$wallet = $this->wallet_order( true, false );

		$this->assertTrue( $sut->handle_woocommerce_paypal_wallet_refund_locked( true, $wallet ) );
		$this->assertTrue( $sut->handle_woocommerce_paypal_wallet_refund_locked( false, $wallet ), 'False becomes the base answer: a wallet order while collecting' );
		$this->assertFalse( $sut->handle_woocommerce_paypal_wallet_refund_locked( false, null ), 'Not an order: unchanged' );
		$this->assertSame( 'x', $sut->handle_woocommerce_paypal_wallet_refund_locked( 'x', $wallet ), 'Not a bool: unchanged' );
		$this->assertNull( $sut->handle_woocommerce_paypal_wallet_refund_locked( null, $wallet ) );
	}
}
