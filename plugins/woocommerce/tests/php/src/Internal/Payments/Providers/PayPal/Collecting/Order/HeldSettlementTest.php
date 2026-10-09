<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Order;

use ArrayObject;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\HeldCapture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\HeldSettlement;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\HeldOrders;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\OrderAppContext;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Amount;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Capture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\CaptureStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Money;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\RequestHandler;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FixedHeldOrders;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\RecordingLogger;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Webhook\WebhookFixtures;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Tests for the settlement of held orders: one settlement per order under concurrency, the app context, and an order
 * the wallet's completion could not complete.
 *
 * @group paypal-wallet
 */
class HeldSettlementTest extends WalletTestCase {
	use BootsCollectingContainer;
	use WebhookFixtures;

	/**
	 * The booted container.
	 *
	 * @var ContainerInterface
	 */
	private ContainerInterface $container;

	/**
	 * The System Under Test.
	 *
	 * @var HeldSettlement
	 */
	private HeldSettlement $sut;

	/**
	 * The orders the returned-payment action fired for.
	 *
	 * @var ArrayObject
	 */
	private ArrayObject $returned;

	/**
	 * Boot a collecting store over a fake transport and spy on the returned-payment action.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_collecting();
		$this->container = $this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) );
		$this->sut       = $this->container->get( 'collecting.held-settlement' );

		$this->returned = new ArrayObject();
		$spy            = $this->returned;
		add_action(
			'woocommerce_paypal_wallet_held_payment_returned',
			static function ( $order ) use ( $spy ) {
				$spy->append( $order );
			}
		);
	}

	/**
	 * A capture of a status.
	 *
	 * @param string $status The status.
	 * @return Capture
	 */
	private function capture( string $status ): Capture {
		return new Capture( 'CAPTURE-1', new CaptureStatus( $status ), new Amount( new Money( 20.0, 'USD' ) ), true, '', '', '', null, null );
	}

	/**
	 * @testdox Should leave alone an order a webhook returned meanwhile, when settled from a stale copy.
	 */
	public function test_stale_copy_does_not_return_twice(): void {
		$order = $this->wallet_order( '1' );
		$stale = wc_get_order( $order->get_id() );
		$this->assertTrue( $this->container->get( 'collecting.held-orders' )->is_held( $stale ), 'The copy has read its held meta' );
		$this->stub_captures( array( 'CAPTURE-1' => $this->capture_json( 'CAPTURE-1', 'REFUNDED' ) ) );
		$this->container->get( 'webhook.endpoint.controller' )->handle_request( $this->event_request( 'PAYMENT.CAPTURE.REFUNDED', $this->refund_resource( $order, 'CAPTURE-1' ) ) );
		$this->assertSame( 'cancelled', wc_get_order( $order->get_id() )->get_status(), 'The webhook returned the order' );

		$outcome = $this->sut->apply( $stale, $this->capture( 'REFUNDED' ), 0 );

		$this->assertSame( HeldSettlement::OUTCOME_UNCHANGED, $outcome );
		$this->assertSame( 10, $this->stock() );
		$this->assertSame( 1, $this->count_notes( $order, 'PayPal returned the held payment to the customer because setup was not completed' ) );
		$this->assertCount( 1, $this->returned );
	}

	/**
	 * @testdox Should leave alone an order a webhook completed meanwhile, when settled from a stale copy.
	 */
	public function test_stale_copy_does_not_complete_twice(): void {
		$order = $this->wallet_order( '1' );
		$stale = wc_get_order( $order->get_id() );
		$this->assertTrue( $this->container->get( 'collecting.held-orders' )->is_held( $stale ), 'The copy has read its held meta' );
		$this->container->get( 'webhook.endpoint.controller' )->handle_request( $this->event_request( 'PAYMENT.CAPTURE.COMPLETED', $this->capture_resource( $order, 'PP-ORDER-1' ) ) );

		$this->assertSame( HeldSettlement::OUTCOME_UNCHANGED, $this->sut->apply( $stale, $this->capture( 'COMPLETED' ), 0 ) );
		$this->assertSame( HeldSettlement::OUTCOME_UNCHANGED, $this->sut->apply( $stale, $this->capture( 'REFUNDED' ), 0 ), 'A completed order is not returned either' );

		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( 1, $this->count_notes( $order, 'Payment successfully captured.' ) );
		$this->assertSame( 8, $this->stock() );
	}

	/**
	 * @testdox Should leave an order alone while another request holds its settlement lock, and take over a stale lock.
	 */
	public function test_lock_held_by_another_request(): void {
		$order = $this->wallet_order( '1' );
		$key   = HeldSettlement::LOCK_OPTION_PREFIX . $order->get_id();
		add_option( $key, (string) time(), '', false );

		$this->assertSame( HeldSettlement::OUTCOME_UNCHANGED, $this->sut->apply( $order, $this->capture( 'REFUNDED' ), 0 ) );
		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );
		$this->assertTrue( $this->has_held_meta( $order ) );

		update_option( $key, (string) ( time() - HeldSettlement::LOCK_TTL - 1 ), false );
		$this->assertSame( HeldSettlement::OUTCOME_RETURNED, $this->sut->apply( $order, $this->capture( 'REFUNDED' ), 0 ) );
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$this->assertFalse( get_option( $key ), 'The lock is released' );
	}

	/**
	 * @testdox Should restore the order app context after completing an order.
	 * @testWith [null]
	 *           ["platform"]
	 *
	 * @param string|null $entered The app entered before, or null for none.
	 */
	public function test_complete_restores_the_app_context( ?string $entered ): void {
		$context = $this->container->get( 'collecting.order-app-context' );
		if ( null !== $entered ) {
			$context->enter( $entered );
		}
		$order = $this->wallet_order( '1', PlatformTransport::APP_MERCHANT_APP );

		$this->assertSame( HeldSettlement::OUTCOME_COMPLETED, $this->sut->complete( $order ) );

		$this->assertSame( null !== $entered, $context->is_entered() );
		if ( null !== $entered ) {
			$this->assertSame( $entered, $context->current() );
		}
		$reads = array_values(
			array_filter(
				$this->http_requests,
				static function ( array $entry ): bool {
					return false !== strpos( $entry['url'], 'v2/checkout/orders/PP-ORDER-1' );
				}
			)
		);
		$this->assertSame( 'Bearer token-merchant_app', $reads[0]['request']['headers']['Authorization'], 'The wallet\'s read went through the pinned app' );
	}

	/**
	 * @testdox Should keep the held meta when the wallet's completion reports a failure, so the reconcile sees the order.
	 */
	public function test_failed_completion_keeps_the_order_held(): void {
		$failing = new class() implements RequestHandler {
			/**
			 * The event types.
			 *
			 * @return string[]
			 */
			public function event_types(): array {
				return array( 'PAYMENT.CAPTURE.COMPLETED' );
			}

			/**
			 * Always responsible.
			 *
			 * @param WP_REST_Request $request The request.
			 * @return bool
			 */
			public function responsible_for_request( WP_REST_Request $request ): bool {
				unset( $request );
				return true;
			}

			/**
			 * Report a failure.
			 *
			 * @param WP_REST_Request $request The request.
			 * @return WP_REST_Response
			 */
			public function handle_request( WP_REST_Request $request ): WP_REST_Response {
				unset( $request );
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => 'No order for webhook event x was found.',
					)
				);
			}
		};
		$sut     = new HeldSettlement( new HeldOrders(), new HeldCapture( new CollectingState( new Options(), new FixedHeldOrders( 1 ) ) ), $failing, new OrderAppContext(), new RecordingLogger() );
		$order   = $this->wallet_order( '1' );

		try {
			$sut->complete( $order );
			$this->fail( 'A failed completion throws' );
		} catch ( RuntimeException $exception ) {
			$this->assertStringContainsString( 'No order for webhook event', $exception->getMessage() );
		}

		$this->assertTrue( $this->has_held_meta( $order ) );
		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );
	}
}
