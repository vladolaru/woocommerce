<?php
/**
 * Tests for the PayPal wallet authorized payments processor (ported from the extension's AuthorizedPaymentsProcessorTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\OrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PaymentsEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Authorization;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\AuthorizationStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Capture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\CaptureStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\FraudProcessorResponse;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Money;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Payments;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PurchaseUnit;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\AmountFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Notice\AuthorizeOrderActionNotice;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor\AuthorizedPaymentsProcessor;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\SubscriptionHelper;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Automattic\WooCommerce\Vendor\Psr\Log\NullLogger;
use Mockery;
use Mockery\MockInterface;
use WC_Order;

/**
 * Capturing, reauthorizing and voiding the authorizations of a PayPal order for a WooCommerce order.
 *
 * @group paypal-wallet
 */
class AuthorizedPaymentsProcessorTest extends WalletTestCase {

	/**
	 * The WooCommerce order mock.
	 *
	 * @var WC_Order&MockInterface
	 */
	private $wc_order;

	/**
	 * The PayPal order ID stored on the WooCommerce order.
	 *
	 * @var string
	 */
	private $paypal_order_id = 'abc';

	/**
	 * The ID of the default authorization.
	 *
	 * @var string
	 */
	private $authorization_id = 'qwe';

	/**
	 * The order total.
	 *
	 * @var float
	 */
	private $amount = 42.0;

	/**
	 * The order currency.
	 *
	 * @var string
	 */
	private $currency = 'EUR';

	/**
	 * The PayPal order the order endpoint returns; tests reassign it to change what the endpoint serves.
	 *
	 * @var Order
	 */
	private $paypal_order;

	/**
	 * The default (capturable) authorization.
	 *
	 * @var Authorization
	 */
	private $authorization;

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
	 * The ID of the capture the payments endpoint returns.
	 *
	 * @var string
	 */
	private $capture_id = '123qwe';

	/**
	 * The processor under test.
	 *
	 * @var AuthorizedPaymentsProcessor
	 */
	private $sut;

	/**
	 * Build the processor with mocked collaborators and a PayPal order that holds one capturable authorization.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->wc_order = $this->create_wc_order( $this->paypal_order_id );

		$this->authorization = $this->create_authorization( $this->authorization_id, AuthorizationStatus::CREATED );
		$this->paypal_order  = $this->create_paypal_order( array( $this->authorization ) );

		$this->order_endpoint = $this->mock( OrderEndpoint::class );
		$this->order_endpoint
			->shouldReceive( 'order' )
			->with( $this->paypal_order_id )
			->andReturnUsing(
				function () {
					return $this->paypal_order;
				}
			)
			->byDefault();

		$this->payments_endpoint = $this->mock( PaymentsEndpoint::class );

		$notice = $this->mock( AuthorizeOrderActionNotice::class );
		$notice->shouldReceive( 'display_message' );

		$this->sut = new AuthorizedPaymentsProcessor(
			$this->order_endpoint,
			$this->payments_endpoint,
			new NullLogger(),
			$notice,
			$this->mock( ContainerInterface::class ),
			$this->mock( SubscriptionHelper::class ),
			$this->mock( AmountFactory::class )
		);
	}

	/**
	 * @testdox Should capture the authorization and report success.
	 */
	public function test_success(): void {
		$this->payments_endpoint
			->expects( 'capture' )
			->with( $this->authorization_id, $this->expected_money() )
			->andReturn( $this->create_capture( $this->capture_id, CaptureStatus::COMPLETED ) );

		$this->assertSame( AuthorizedPaymentsProcessor::SUCCESSFUL, $this->sut->process( $this->wc_order ) );
	}

	/**
	 * @testdox Should capture the last authorization that can still be captured.
	 */
	public function test_captures_last_captureable(): void {
		$authorizations     = $this->authorizations_in_every_status();
		$this->paypal_order = $this->create_paypal_order( $authorizations );

		$this->payments_endpoint
			->expects( 'capture' )
			->with( $authorizations[2]->id(), $this->expected_money() )
			->andReturn( $this->create_capture( $this->capture_id, CaptureStatus::COMPLETED ) );

		$this->assertSame( AuthorizedPaymentsProcessor::SUCCESSFUL, $this->sut->process( $this->wc_order ) );
	}

	/**
	 * @testdox Should report the PayPal order as inaccessible when fetching it fails.
	 */
	public function test_inaccessible(): void {
		$this->order_endpoint
			->expects( 'order' )
			->with( $this->paypal_order_id )
			->andThrow( RuntimeException::class );

		$this->assertSame( AuthorizedPaymentsProcessor::INACCESSIBLE, $this->sut->process( $this->wc_order ) );
	}

	/**
	 * @testdox Should report the PayPal order as not found when fetching it fails with a 404.
	 */
	public function test_not_found(): void {
		$this->order_endpoint
			->expects( 'order' )
			->with( $this->paypal_order_id )
			->andThrow( new RuntimeException( 'text', 404 ) );

		$this->assertSame( AuthorizedPaymentsProcessor::NOT_FOUND, $this->sut->process( $this->wc_order ) );
	}

	/**
	 * @testdox Should report a failure when the capture request fails.
	 */
	public function test_capture_fails(): void {
		$this->payments_endpoint
			->expects( 'capture' )
			->with( $this->authorization_id, $this->expected_money() )
			->andThrow( RuntimeException::class );

		$this->assertSame( AuthorizedPaymentsProcessor::FAILED, $this->sut->process( $this->wc_order ) );
	}

	/**
	 * @testdox Should report already captured when the only authorization is captured.
	 */
	public function test_already_captured(): void {
		$this->paypal_order = $this->create_paypal_order( array( $this->create_authorization( $this->authorization_id, AuthorizationStatus::CAPTURED ) ) );

		$this->assertSame( AuthorizedPaymentsProcessor::ALREADY_CAPTURED, $this->sut->process( $this->wc_order ) );
	}

	/**
	 * @testdox Should report a bad authorization when the only authorization is denied.
	 */
	public function test_bad_authorization(): void {
		$this->paypal_order = $this->create_paypal_order( array( $this->create_authorization( $this->authorization_id, AuthorizationStatus::DENIED ) ) );

		$this->assertSame( AuthorizedPaymentsProcessor::BAD_AUTHORIZATION, $this->sut->process( $this->wc_order ) );
	}

	/**
	 * @testdox Should capture, store the capture ID as the transaction ID, mark the order captured and paid, and note the capture.
	 */
	public function test_capture_authorized_payment(): void {
		$order = $this->create_real_order( 'on-hold' );
		$this->order_endpoint->shouldReceive( 'order' )->andReturn( $this->paypal_order );

		$this->payments_endpoint
			->expects( 'capture' )
			->with( $this->authorization_id, $this->expected_money() )
			->andReturn( $this->create_capture( $this->capture_id, CaptureStatus::COMPLETED ) );

		$this->assertTrue( $this->sut->capture_authorized_payment( $order ) );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertSame( $this->capture_id, $reloaded->get_transaction_id() );
		$this->assertSame( 'true', $reloaded->get_meta( AuthorizedPaymentsProcessor::CAPTURED_META_KEY ) );
		$this->assertTrue( $reloaded->is_paid(), 'payment_complete() should have run' );
		$this->assertNotSame( 'on-hold', $reloaded->get_status() );
		$this->assert_order_has_note_containing( $order->get_id(), 'Payment successfully captured.' );
	}

	/**
	 * @testdox Should complete the order without a second capture, and mark it captured with a note, when the authorization is already captured.
	 */
	public function test_capture_authorized_payment_already_captured(): void {
		$order        = $this->create_real_order( 'on-hold' );
		$paypal_order = $this->create_paypal_order( array( $this->create_authorization( $this->authorization_id, AuthorizationStatus::CAPTURED ) ) );
		$this->order_endpoint->shouldReceive( 'order' )->andReturn( $paypal_order );
		$this->payments_endpoint->shouldNotReceive( 'capture' );

		$this->assertTrue( $this->sut->capture_authorized_payment( $order ) );

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertSame( 'true', $reloaded->get_meta( AuthorizedPaymentsProcessor::CAPTURED_META_KEY ) );
		$this->assertTrue( $reloaded->is_paid(), 'payment_complete() should have run' );
		$this->assertNotSame( 'on-hold', $reloaded->get_status() );
		$this->assert_order_has_note_containing( $order->get_id(), 'Payment successfully captured.' );
	}

	/**
	 * @testdox Should void every authorization that is still voidable (created or pending).
	 */
	public function test_void(): void {
		$authorizations     = $this->authorizations_in_every_status();
		$this->paypal_order = $this->create_paypal_order( $authorizations );

		$this->payments_endpoint->expects( 'void' )->with( $authorizations[0] );
		$this->payments_endpoint->expects( 'void' )->with( $authorizations[2] );

		$this->sut->void_authorizations( $this->paypal_order );
	}

	/**
	 * @testdox Should let the error through when voiding an authorization fails.
	 */
	public function test_void_when_the_void_request_fails(): void {
		$exception = new RuntimeException( 'void error' );
		$this->payments_endpoint
			->expects( 'void' )
			->with( $this->authorization )
			->andThrow( $exception );

		$this->expectExceptionObject( $exception );

		$this->sut->void_authorizations( $this->paypal_order );
	}

	/**
	 * @testdox Should throw when no authorization is voidable.
	 */
	public function test_void_when_no_authorization_is_voidable(): void {
		$this->paypal_order = $this->create_paypal_order(
			array(
				$this->create_authorization( 'id1', AuthorizationStatus::VOIDED ),
				$this->create_authorization( 'id2', AuthorizationStatus::EXPIRED ),
			)
		);

		$this->expectException( RuntimeException::class );

		$this->sut->void_authorizations( $this->paypal_order );
	}

	/**
	 * @testdox Should fail the order with the processor response code in the note when the capture is declined for fraud.
	 */
	public function test_declined_capture_with_fraud_response_code_passes_code_to_status_note(): void {
		$this->order_endpoint->shouldReceive( 'order' )->andReturn( $this->paypal_order );

		$capture = $this->mock( Capture::class );
		$capture->shouldReceive( 'id' )->andReturn( $this->capture_id );
		$capture->shouldReceive( 'status' )->andReturn( new CaptureStatus( CaptureStatus::DECLINED ) );
		$capture->shouldReceive( 'fraud_processor_response' )->andReturn( new FraudProcessorResponse( null, null, '9500' ) );

		$this->payments_endpoint
			->expects( 'capture' )
			->with( $this->authorization_id, $this->expected_money() )
			->andReturn( $capture );

		$this->wc_order
			->shouldReceive( 'update_status' )
			->once()
			->with(
				'failed',
				Mockery::on(
					function ( string $note ): bool {
						return false !== strpos( $note, '9500: Suspected Fraud' );
					}
				)
			);
		$this->wc_order->shouldNotReceive( 'add_order_note' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/your bank was unable to approve this transaction/' );
		$this->sut->capture_authorized_payment( $this->wc_order );
	}

	/**
	 * @testdox Should fail the order with the generic note and throw the generic message when the capture is declined without a fraud code.
	 */
	public function test_declined_capture_without_fraud_code_throws_generic_message(): void {
		$this->order_endpoint->shouldReceive( 'order' )->andReturn( $this->paypal_order );

		$capture = $this->mock( Capture::class );
		$capture->shouldReceive( 'id' )->andReturn( $this->capture_id );
		$capture->shouldReceive( 'status' )->andReturn( new CaptureStatus( CaptureStatus::DECLINED ) );
		$capture->shouldReceive( 'fraud_processor_response' )->andReturn( null );

		$this->payments_endpoint
			->expects( 'capture' )
			->with( $this->authorization_id, $this->expected_money() )
			->andReturn( $capture );

		$this->wc_order->shouldReceive( 'update_status' )->once()->with( 'failed', 'Could not capture the payment.' );
		$this->wc_order->shouldNotReceive( 'add_order_note' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Payment provider declined the payment, please use a different payment method.' );
		$this->sut->capture_authorized_payment( $this->wc_order );
	}

	/**
	 * A saved order carrying the PayPal order ID, the total and the currency the processor reads.
	 *
	 * @param string $status The order status.
	 * @return WC_Order
	 */
	private function create_real_order( string $status ): WC_Order {
		$order = wc_create_order();
		$order->set_currency( $this->currency );
		$order->set_total( (string) $this->amount );
		$order->update_meta_data( PayPalGateway::ORDER_ID_META_KEY, $this->paypal_order_id );
		$order->set_status( $status );
		$order->save();

		return $order;
	}

	/**
	 * Assert that one of the order's notes contains the text.
	 *
	 * @param int    $order_id The order ID.
	 * @param string $text     The expected text.
	 */
	private function assert_order_has_note_containing( int $order_id, string $text ): void {
		$matching = array_filter(
			wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order_id ) ), 'content' ),
			static function ( string $note ) use ( $text ): bool {
				return false !== strpos( $note, $text );
			}
		);

		$this->assertNotEmpty( $matching, "No order note contains '$text'" );
	}

	/**
	 * A Mockery argument matcher for the Money the processor captures: the order total in the order currency.
	 *
	 * @return \Mockery\Matcher\Closure
	 */
	private function expected_money() {
		return Mockery::on(
			function ( $money ): bool {
				return $money instanceof Money && $this->amount === $money->value() && $this->currency === $money->currency_code();
			}
		);
	}

	/**
	 * Seven authorizations, one per status; only the first and the third (created, pending) can be captured or voided.
	 *
	 * @return Authorization[]
	 */
	private function authorizations_in_every_status(): array {
		return array(
			$this->create_authorization( 'id1', AuthorizationStatus::CREATED ),
			$this->create_authorization( 'id2', AuthorizationStatus::VOIDED ),
			$this->create_authorization( 'id3', AuthorizationStatus::PENDING ),
			$this->create_authorization( 'id4', AuthorizationStatus::CAPTURED ),
			$this->create_authorization( 'id5', AuthorizationStatus::DENIED ),
			$this->create_authorization( 'id6', AuthorizationStatus::EXPIRED ),
			$this->create_authorization( 'id7', AuthorizationStatus::COMPLETED ),
		);
	}

	/**
	 * A WooCommerce order mock that carries the PayPal order ID, the total and the currency.
	 *
	 * @param string $paypal_order_id The PayPal order ID.
	 * @return WC_Order&MockInterface
	 */
	private function create_wc_order( string $paypal_order_id ) {
		$wc_order = $this->mock( WC_Order::class );
		$wc_order->shouldReceive( 'get_meta' )->with( PayPalGateway::ORDER_ID_META_KEY )->andReturn( $paypal_order_id );
		$wc_order->shouldReceive( 'get_total' )->andReturn( $this->amount );
		$wc_order->shouldReceive( 'get_currency' )->andReturn( $this->currency );

		return $wc_order;
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

	/**
	 * A capture mock with the given ID and status.
	 *
	 * @param string $id     The capture ID.
	 * @param string $status The capture status.
	 * @return Capture
	 */
	private function create_capture( string $id, string $status ): Capture {
		$capture = $this->mock( Capture::class );
		$capture->shouldReceive( 'id' )->andReturn( $id );
		$capture->shouldReceive( 'status' )->andReturn( new CaptureStatus( $status ) );

		return $capture;
	}

	/**
	 * A PayPal order mock whose one purchase unit holds the given authorizations.
	 *
	 * @param Authorization[] $authorizations The authorizations.
	 * @return Order
	 */
	private function create_paypal_order( array $authorizations ): Order {
		$payments = $this->mock( Payments::class );
		$payments->shouldReceive( 'authorizations' )->andReturn( $authorizations );

		$purchase_unit = $this->mock( PurchaseUnit::class );
		$purchase_unit->shouldReceive( 'payments' )->andReturn( $payments );

		$order = $this->mock( Order::class );
		$order->shouldReceive( 'purchase_units' )->andReturn( array( $purchase_unit ) );

		return $order;
	}
}
