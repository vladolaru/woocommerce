<?php
/**
 * Characterization tests for the PayPal wallet refund processor (written in core; the extension has no equivalent).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\OrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PaymentsEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Amount;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Authorization;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\AuthorizationStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Capture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\CaptureStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Money;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Payments;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PurchaseUnit;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\RefundCapture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\RefundFeesUpdater;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor\RefundProcessor;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Mockery;
use Mockery\MockInterface;
use WC_Order;
use WC_Payment_Gateway;

/**
 * What the PayPal path of the refund processor does today: the refund request it sends, the meta it writes back, the void
 * path for an authorization that was never captured, and what it returns when the refund cannot be made.
 *
 * @group paypal-wallet
 */
class RefundProcessorTest extends WalletTestCase {

	private const PAYPAL_ORDER_ID = 'PP-ORDER-1';

	private const PREFIX = 'WCTEST-';

	/**
	 * The order endpoint mock.
	 *
	 * @var OrderEndpoint&MockInterface
	 */
	private $order_endpoint;

	/**
	 * The payments endpoint mock.
	 *
	 * @var PaymentsEndpoint&MockInterface
	 */
	private $payments_endpoint;

	/**
	 * The refund fees updater mock.
	 *
	 * @var RefundFeesUpdater&MockInterface
	 */
	private $refund_fees_updater;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockInterface
	 */
	private $logger;

	/**
	 * The test's own callback on the gateway list, the only one tear down removes.
	 *
	 * @var callable
	 */
	private $gateway_filter;

	/**
	 * The processor under test.
	 *
	 * @var RefundProcessor
	 */
	private $sut;

	/**
	 * Register a PayPal gateway that supports refunds and build the processor over mocked endpoints.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->gateway_filter = static function ( $gateways ) {
			$gateways[] = new class() extends WC_Payment_Gateway {
				/**
				 * A PayPal gateway that supports refunds.
				 */
				public function __construct() {
					$this->id       = PayPalGateway::ID;
					$this->supports = array( 'products', 'refunds' );
				}
			};

			return $gateways;
		};
		add_filter( 'woocommerce_payment_gateways', $this->gateway_filter );
		WC()->payment_gateways()->init();

		$this->order_endpoint      = $this->mock( OrderEndpoint::class );
		$this->payments_endpoint   = $this->mock( PaymentsEndpoint::class );
		$this->refund_fees_updater = $this->mock( RefundFeesUpdater::class );
		$this->logger              = $this->mock( LoggerInterface::class )->shouldIgnoreMissing();

		$this->sut = new RefundProcessor(
			$this->order_endpoint,
			$this->payments_endpoint,
			$this->refund_fees_updater,
			self::PREFIX,
			$this->logger
		);
	}

	/**
	 * Take the test gateway out of the gateway list again, so later tests see the registered gateways only.
	 */
	public function tearDown(): void {
		try {
			remove_filter( 'woocommerce_payment_gateways', $this->gateway_filter );
			WC()->payment_gateways()->init();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Should send PayPal a refund for the first capture, with the refunded amount in the order currency, the reason and the prefixed order number as invoice ID.
	 */
	public function test_refund_request_for_a_capture(): void {
		$wc_order = $this->create_wc_order();
		$capture  = $this->create_capture( 'CAP-1' );
		$this->order_endpoint->expects( 'order' )->with( self::PAYPAL_ORDER_ID )->andReturn( $this->create_paypal_order( array(), array( $capture, $this->create_capture( 'CAP-2' ) ) ) );
		$this->refund_fees_updater->shouldReceive( 'update' );

		$sent = null;
		$this->payments_endpoint
			->expects( 'refund' )
			->once()
			->andReturnUsing(
				function ( RefundCapture $refund ) use ( &$sent ): string {
					$sent = $refund;
					return 'REFUND-1';
				}
			);

		$this->assertTrue( $this->sut->process( $wc_order, 12.5, 'Damaged in transit' ) );

		$this->assertInstanceOf( RefundCapture::class, $sent );
		$this->assertSame( $capture, $sent->for_capture(), 'The first capture should be the one refunded' );
		$this->assertSame( 12.5, $sent->amount()->value() );
		$this->assertSame( 'EUR', $sent->amount()->currency_code() );
		$this->assertSame( 'Damaged in transit', $sent->note_to_payer() );
		$this->assertSame( self::PREFIX . $wc_order->get_order_number(), $sent->invoice_id() );
		$this->assertSame(
			array(
				'invoice_id'    => self::PREFIX . $wc_order->get_order_number(),
				'note_to_payer' => 'Damaged in transit',
				'amount'        => array(
					'currency_code' => 'EUR',
					'value'         => '12.50',
				),
			),
			$sent->to_array(),
			'The refund body PayPal receives'
		);
	}

	/**
	 * @testdox Should use the invoice ID of the capture, not the prefixed order number, when the capture has one.
	 */
	public function test_refund_uses_the_capture_invoice_id_when_present(): void {
		$wc_order = $this->create_wc_order();
		$this->order_endpoint->expects( 'order' )->andReturn( $this->create_paypal_order( array(), array( $this->create_capture( 'CAP-1', 'INV-FROM-PAYPAL' ) ) ) );
		$this->refund_fees_updater->shouldReceive( 'update' );

		$sent = null;
		$this->payments_endpoint
			->expects( 'refund' )
			->andReturnUsing(
				function ( RefundCapture $refund ) use ( &$sent ): string {
					$sent = $refund;
					return 'REFUND-1';
				}
			);

		$this->assertTrue( $this->sut->process( $wc_order, 3.0 ) );

		$this->assertSame( 'INV-FROM-PAYPAL', $sent->invoice_id() );
	}

	/**
	 * @testdox Should store the refund ID in the order's refunds meta, after the IDs already there, and update the refund fees for the order.
	 */
	public function test_refund_stores_the_refund_id_and_updates_the_fees(): void {
		$wc_order = $this->create_wc_order();
		$wc_order->update_meta_data( PayPalGateway::REFUNDS_META_KEY, array( 'REFUND-0' ) );
		$wc_order->save();
		$this->order_endpoint->expects( 'order' )->andReturn( $this->create_paypal_order( array(), array( $this->create_capture( 'CAP-1' ) ) ) );
		$this->payments_endpoint->expects( 'refund' )->andReturn( 'REFUND-1' );
		$this->refund_fees_updater->expects( 'update' )->once()->with( Mockery::on( fn ( $order ) => $order instanceof WC_Order && $order->get_id() === $wc_order->get_id() ) );

		$this->assertTrue( $this->sut->process( $wc_order, 5.0 ) );

		$this->assertSame( array( 'REFUND-0', 'REFUND-1' ), wc_get_order( $wc_order->get_id() )->get_meta( PayPalGateway::REFUNDS_META_KEY ) );
	}

	/**
	 * @testdox Should leave the order status alone after a refund.
	 */
	public function test_refund_does_not_change_the_order_status(): void {
		$wc_order = $this->create_wc_order();
		$this->order_endpoint->expects( 'order' )->andReturn( $this->create_paypal_order( array(), array( $this->create_capture( 'CAP-1' ) ) ) );
		$this->payments_endpoint->expects( 'refund' )->andReturn( 'REFUND-1' );
		$this->refund_fees_updater->shouldReceive( 'update' );

		$this->sut->process( $wc_order, 5.0 );

		$this->assertSame( 'processing', wc_get_order( $wc_order->get_id() )->get_status() );
	}

	/**
	 * @testdox Should void the authorization, mark the order refunded and send no refund when the order was only authorized.
	 */
	public function test_void_for_an_authorized_order(): void {
		$wc_order      = $this->create_wc_order();
		$authorization = $this->create_authorization( 'AUTH-1', AuthorizationStatus::CREATED );
		$this->order_endpoint->expects( 'order' )->andReturn( $this->create_paypal_order( array( $authorization ), array() ) );
		$this->payments_endpoint->expects( 'void' )->once()->with( $authorization );
		$this->payments_endpoint->shouldNotReceive( 'refund' );
		$this->refund_fees_updater->shouldNotReceive( 'update' );

		$this->assertTrue( $this->sut->process( $wc_order, 5.0 ) );

		$reloaded = wc_get_order( $wc_order->get_id() );
		$this->assertSame( 'refunded', $reloaded->get_status() );
		$this->assertSame( '', $reloaded->get_meta( PayPalGateway::REFUNDS_META_KEY ), 'A void stores no refund ID' );
	}

	/**
	 * @testdox Should void every voidable authorization (created or pending) and skip the others.
	 */
	public function test_void_skips_authorizations_that_cannot_be_voided(): void {
		$wc_order       = $this->create_wc_order();
		$created        = $this->create_authorization( 'AUTH-1', AuthorizationStatus::CREATED );
		$pending        = $this->create_authorization( 'AUTH-2', AuthorizationStatus::PENDING );
		$authorizations = array(
			$created,
			$this->create_authorization( 'AUTH-3', AuthorizationStatus::VOIDED ),
			$pending,
			$this->create_authorization( 'AUTH-4', AuthorizationStatus::CAPTURED ),
		);
		$this->order_endpoint->expects( 'order' )->andReturn( $this->create_paypal_order( $authorizations, array() ) );
		$this->payments_endpoint->expects( 'void' )->once()->with( $created );
		$this->payments_endpoint->expects( 'void' )->once()->with( $pending );

		$this->assertTrue( $this->sut->process( $wc_order, 5.0 ) );
	}

	/**
	 * @testdox Should void instead of refunding when the order has a voidable authorization and a capture.
	 */
	public function test_void_wins_over_refund(): void {
		$wc_order      = $this->create_wc_order();
		$authorization = $this->create_authorization( 'AUTH-1', AuthorizationStatus::PENDING );
		$this->order_endpoint->expects( 'order' )->andReturn( $this->create_paypal_order( array( $authorization ), array( $this->create_capture( 'CAP-1' ) ) ) );
		$this->payments_endpoint->expects( 'void' )->once()->with( $authorization );
		$this->payments_endpoint->shouldNotReceive( 'refund' );

		$this->assertTrue( $this->sut->process( $wc_order, 5.0 ) );

		$this->assertSame( 'refunded', wc_get_order( $wc_order->get_id() )->get_status() );
	}

	/**
	 * @testdox Should refund the capture when the only authorization can no longer be voided.
	 */
	public function test_refunds_when_the_authorization_is_not_voidable(): void {
		$wc_order = $this->create_wc_order();
		$this->order_endpoint->expects( 'order' )->andReturn(
			$this->create_paypal_order(
				array( $this->create_authorization( 'AUTH-1', AuthorizationStatus::CAPTURED ) ),
				array( $this->create_capture( 'CAP-1' ) )
			)
		);
		$this->payments_endpoint->shouldNotReceive( 'void' );
		$this->payments_endpoint->expects( 'refund' )->once()->andReturn( 'REFUND-1' );
		$this->refund_fees_updater->shouldReceive( 'update' );

		$this->assertTrue( $this->sut->process( $wc_order, 5.0 ) );
	}

	/**
	 * @testdox Should return false and log the reason, without storing a refund, when PayPal rejects the refund.
	 */
	public function test_returns_false_when_the_refund_request_fails(): void {
		$wc_order = $this->create_wc_order();
		$this->order_endpoint->expects( 'order' )->andReturn( $this->create_paypal_order( array(), array( $this->create_capture( 'CAP-1' ) ) ) );
		$this->payments_endpoint->expects( 'refund' )->andThrow( new RuntimeException( 'Could not refund payment.' ) );
		$this->refund_fees_updater->shouldNotReceive( 'update' );
		$this->logger->expects( 'error' )->once()->with( 'Refund failed: Could not refund payment.' );

		$this->assertFalse( $this->sut->process( $wc_order, 5.0 ) );

		$this->assertSame( '', wc_get_order( $wc_order->get_id() )->get_meta( PayPalGateway::REFUNDS_META_KEY ) );
	}

	/**
	 * @testdox Should return false and leave the order status alone when PayPal rejects the void.
	 */
	public function test_returns_false_when_the_void_request_fails(): void {
		$wc_order = $this->create_wc_order();
		$this->order_endpoint->expects( 'order' )->andReturn( $this->create_paypal_order( array( $this->create_authorization( 'AUTH-1', AuthorizationStatus::CREATED ) ), array() ) );
		$this->payments_endpoint->expects( 'void' )->andThrow( new RuntimeException( 'Could not void transaction.' ) );
		$this->logger->expects( 'error' )->once()->with( 'Refund failed: Could not void transaction.' );

		$this->assertFalse( $this->sut->process( $wc_order, 5.0 ) );

		$this->assertSame( 'processing', wc_get_order( $wc_order->get_id() )->get_status() );
	}

	/**
	 * @testdox Should return false with "Nothing to refund/void." when the PayPal order has no capture and no voidable authorization.
	 */
	public function test_returns_false_when_there_is_nothing_to_refund(): void {
		$wc_order = $this->create_wc_order();
		$this->order_endpoint->expects( 'order' )->andReturn( $this->create_paypal_order( array( $this->create_authorization( 'AUTH-1', AuthorizationStatus::VOIDED ) ), array() ) );
		$this->payments_endpoint->shouldNotReceive( 'refund' );
		$this->payments_endpoint->shouldNotReceive( 'void' );
		$this->logger->expects( 'error' )->once()->with( 'Refund failed: Nothing to refund/void.' );

		$this->assertFalse( $this->sut->process( $wc_order, 5.0 ) );
	}

	/**
	 * @testdox Should return false with "No purchase units." when the PayPal order has none.
	 */
	public function test_returns_false_when_the_paypal_order_has_no_purchase_units(): void {
		$wc_order = $this->create_wc_order();
		$this->order_endpoint->expects( 'order' )->andReturn( new Order( self::PAYPAL_ORDER_ID, array(), new OrderStatus( OrderStatus::COMPLETED ) ) );
		$this->logger->expects( 'error' )->once()->with( 'Refund failed: No purchase units.' );

		$this->assertFalse( $this->sut->process( $wc_order, 5.0 ) );
	}

	/**
	 * @testdox Should return false without calling PayPal when the order carries no PayPal order ID.
	 */
	public function test_returns_false_without_a_paypal_order_id(): void {
		$wc_order = $this->create_wc_order();
		$wc_order->delete_meta_data( PayPalGateway::ORDER_ID_META_KEY );
		$wc_order->save();
		$this->order_endpoint->shouldNotReceive( 'order' );
		$this->logger->expects( 'error' )->once()->with( 'Refund failed: PayPal order ID not found in meta.' );

		$this->assertFalse( $this->sut->process( $wc_order, 5.0 ) );
	}

	/**
	 * @testdox Should return true without calling PayPal when the order was paid with a method that does not support refunds.
	 */
	public function test_returns_true_for_a_method_without_refund_support(): void {
		$wc_order = $this->create_wc_order();
		$wc_order->set_payment_method( 'cod' );
		$wc_order->save();
		$this->order_endpoint->shouldNotReceive( 'order' );
		$this->payments_endpoint->shouldNotReceive( 'refund' );

		$this->assertTrue( $this->sut->process( $wc_order, 5.0 ) );
	}

	/**
	 * A saved processing order paid with the PayPal gateway, in euros, that carries the PayPal order ID.
	 *
	 * @return WC_Order
	 */
	private function create_wc_order(): WC_Order {
		$wc_order = wc_create_order();
		$wc_order->set_payment_method( PayPalGateway::ID );
		$wc_order->set_currency( 'EUR' );
		$wc_order->set_total( '20' );
		$wc_order->update_meta_data( PayPalGateway::ORDER_ID_META_KEY, self::PAYPAL_ORDER_ID );
		$wc_order->set_status( 'processing' );
		$wc_order->save();

		return $wc_order;
	}

	/**
	 * A PayPal order whose one purchase unit holds the given payments.
	 *
	 * @param Authorization[] $authorizations The authorizations.
	 * @param Capture[]       $captures       The captures.
	 * @return Order
	 */
	private function create_paypal_order( array $authorizations, array $captures ): Order {
		$purchase_unit = new PurchaseUnit(
			new Amount( new Money( 20.0, 'EUR' ) ),
			array(),
			null,
			'default',
			'',
			'',
			'',
			'',
			new Payments( $authorizations, $captures )
		);

		return new Order( self::PAYPAL_ORDER_ID, array( $purchase_unit ), new OrderStatus( OrderStatus::COMPLETED ) );
	}

	/**
	 * A completed capture.
	 *
	 * @param string $id         The capture ID.
	 * @param string $invoice_id The invoice ID PayPal reports for the capture.
	 * @return Capture
	 */
	private function create_capture( string $id, string $invoice_id = '' ): Capture {
		return new Capture(
			$id,
			new CaptureStatus( CaptureStatus::COMPLETED ),
			new Amount( new Money( 20.0, 'EUR' ) ),
			true,
			'',
			$invoice_id,
			'',
			null,
			null
		);
	}

	/**
	 * An authorization with the given ID and status.
	 *
	 * @param string $id     The authorization ID.
	 * @param string $status The authorization status.
	 * @return Authorization
	 */
	private function create_authorization( string $id, string $status ): Authorization {
		return new Authorization( $id, new AuthorizationStatus( $status ), null );
	}
}
