<?php
/**
 * Tests for the PayPal wallet order endpoint (ported from the extension's OrderEndpointTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\Bearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\OrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Address;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Capture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\CaptureStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\ExperienceContext;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\FraudProcessorResponse;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PatchCollection;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Payer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Payments;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PurchaseUnit;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Shipping;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\OrderFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PatchCollectionFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ErrorResponse;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\FraudNet\FraudNet;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\SubscriptionHelper;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;
use WC_Order;
use WP_Error;

/**
 * Order endpoint: create, fetch, capture and patch a PayPal order over a stubbed HTTP layer.
 *
 * @group paypal-wallet
 */
class OrderEndpointTest extends WalletTestCase {

	private const HOST = 'https://example.com/';

	/**
	 * The shipping entity returned by purchase unit mocks.
	 *
	 * @var Shipping
	 */
	private $shipping;

	/**
	 * The PSR logger mock shared by the endpoints built in a test.
	 *
	 * @var MockInterface
	 */
	private $logger;

	/**
	 * Set up the shared collaborators.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->shipping = new Shipping( 'shipping', new Address( 'US', 'street', '', 'CA', '', '12345' ) );
		$this->logger   = $this->mock( LoggerInterface::class )->shouldIgnoreMissing();
	}

	/**
	 * Build the endpoint under test, or a partial mock of it.
	 *
	 * @param Bearer|null                 $bearer        The bearer; a mock that must not be used when omitted.
	 * @param OrderFactory|null           $order_factory The order factory mock.
	 * @param PatchCollectionFactory|null $patch_factory The patch collection factory mock.
	 * @param bool                        $partial       Whether to return a partial mock, to stub order().
	 * @return OrderEndpoint|MockInterface
	 */
	private function make_endpoint( ?Bearer $bearer = null, ?OrderFactory $order_factory = null, ?PatchCollectionFactory $patch_factory = null, bool $partial = false ) {
		$arguments = array(
			self::HOST,
			$bearer ?? $this->mock( Bearer::class ),
			$order_factory ?? $this->mock( OrderFactory::class ),
			$patch_factory ?? $this->mock( PatchCollectionFactory::class ),
			'CAPTURE',
			$this->logger,
			$this->mock( SubscriptionHelper::class ),
			false,
			$this->mock( FraudNet::class ),
		);

		if ( $partial ) {
			return Mockery::mock( OrderEndpoint::class, $arguments )->makePartial();
		}

		return new OrderEndpoint( ...$arguments );
	}

	/**
	 * Assert the one request the endpoint sent: its URL, method and the headers every PayPal call carries.
	 *
	 * @param string      $url    Expected URL.
	 * @param string      $method Expected HTTP method.
	 * @param string|null $prefer Expected Prefer header, or null when the request sends none.
	 */
	private function assert_single_request( string $url, string $method, ?string $prefer ): void {
		$this->assertCount( 1, $this->http_requests, 'Exactly one request should be sent' );

		$request = $this->http_requests[0];
		$this->assertSame( $url, $request['url'] );
		$this->assertSame( $method, $request['request']['method'] );
		$this->assertSame( 'Bearer bearer', $request['request']['headers']['Authorization'] );
		$this->assertSame( 'application/json', $request['request']['headers']['Content-Type'] );
		if ( null === $prefer ) {
			$this->assertArrayNotHasKey( 'Prefer', $request['request']['headers'] );
		} else {
			$this->assertSame( $prefer, $request['request']['headers']['Prefer'] );
		}
	}

	/**
	 * A WC order carrying the PayPal order ID in its meta, or none.
	 *
	 * @param string|null $paypal_id The PayPal order ID to store, or null to store nothing.
	 * @return WC_Order
	 */
	private function make_wc_order( ?string $paypal_id ): WC_Order {
		$wc_order = new WC_Order();
		if ( null !== $paypal_id ) {
			$wc_order->update_meta_data( PayPalGateway::ORDER_ID_META_KEY, $paypal_id );
		}
		return $wc_order;
	}

	/**
	 * Data provider: a PayPal order ID as a string, and the same ID held in a WC order's meta.
	 *
	 * @return array<string, array{bool}>
	 */
	public static function order_valid_inputs(): array {
		return array(
			'string id'   => array( false ),
			'WC order id' => array( true ),
		);
	}

	/**
	 * @testdox Should fetch the order by its PayPal ID, given as a string or through a WC order's meta.
	 * @dataProvider order_valid_inputs
	 *
	 * @param bool $use_wc_order Whether to pass a WC order instead of the string ID.
	 */
	public function test_order_fetches_by_paypal_id( bool $use_wc_order ): void {
		$order         = $this->mock( Order::class );
		$order_factory = $this->mock( OrderFactory::class );
		$order_factory
			->expects( 'from_paypal_response' )
			->andReturnUsing(
				function ( \stdClass $json ) use ( $order ): ?Order {
					return $json->is_correct ? $order : null;
				}
			);
		$this->logger->shouldNotReceive( 'warning' );
		$this->stub_http( $this->http_response( 200, '{"is_correct":true}' ) );

		$sut = $this->make_endpoint( $this->make_bearer(), $order_factory );

		$result = $sut->order( $use_wc_order ? $this->make_wc_order( 'abc123' ) : 'abc123' );

		$this->assertSame( $order, $result );
		$this->assert_single_request( self::HOST . 'v2/checkout/orders/abc123', 'GET', null );
	}

	/**
	 * Data provider: inputs that cannot resolve to a PayPal order ID.
	 *
	 * @return array<string, array{string}>
	 */
	public static function order_invalid_inputs(): array {
		return array(
			'integer'                       => array( 'integer' ),
			'WC order without the meta key' => array( 'wc_order_without_meta' ),
		);
	}

	/**
	 * @testdox Should reject an order argument that carries no PayPal order ID, without sending a request.
	 * @dataProvider order_invalid_inputs
	 *
	 * @param string $kind Which invalid input to pass.
	 */
	public function test_order_rejects_invalid_input( string $kind ): void {
		$this->stub_http( $this->http_response( 200, '{}' ) );
		$sut = $this->make_endpoint();

		$this->expectException( InvalidArgumentException::class );

		try {
			$sut->order( 'integer' === $kind ? 123 : $this->make_wc_order( null ) );
		} finally {
			$this->assertCount( 0, $this->http_requests, 'No request should be sent' );
		}
	}

	/**
	 * @testdox Should throw a runtime exception when fetching the order fails with a WP error.
	 */
	public function test_order_throws_on_wp_error(): void {
		$this->stub_http( new WP_Error( 'http_request_failed', 'Connection refused' ) );
		$sut = $this->make_endpoint( $this->make_bearer() );

		$this->expectException( RuntimeException::class );

		$sut->order( 'id' );
	}

	/**
	 * @testdox Should throw a runtime exception when fetching the order returns a status other than 200.
	 */
	public function test_order_throws_when_status_is_not_200(): void {
		$this->stub_http( $this->http_response( 500, '{"some_error":true}' ) );
		$sut = $this->make_endpoint( $this->make_bearer() );

		$this->expectException( RuntimeException::class );

		$sut->order( 'id' );
	}

	/**
	 * @testdox Should POST to the capture URL and return the order built from the response.
	 */
	public function test_capture_posts_to_capture_url_and_returns_order(): void {
		$status = $this->mock( OrderStatus::class );
		$status->expects( 'is' )->with( 'COMPLETED' )->andReturn( false );
		$order_to_capture = $this->mock( Order::class );
		$order_to_capture->expects( 'status' )->andReturn( $status );
		$order_to_capture->expects( 'id' )->andReturn( 'id' );

		$expected_order = $this->mock( Order::class );
		$order_factory  = $this->mock( OrderFactory::class );
		$order_factory
			->expects( 'from_paypal_response' )
			->andReturnUsing(
				function ( $json ) use ( $expected_order ) {
					return $json->is_correct ? $expected_order : Mockery::mock( Order::class );
				}
			);

		$purchase_unit = $this->mock( PurchaseUnit::class );
		$payments      = $this->mock( Payments::class );
		$capture       = $this->mock( Capture::class );
		$expected_order->shouldReceive( 'purchase_units' )->once()->andReturn( array( '0' => $purchase_unit ) );
		$purchase_unit->shouldReceive( 'payments' )->once()->andReturn( $payments );
		$payments->shouldReceive( 'captures' )->once()->andReturn( array( '0' => $capture ) );
		$capture->shouldReceive( 'status' )->once()->andReturn( new CaptureStatus( CaptureStatus::COMPLETED ) );

		$before_capture_calls = array();
		add_action(
			'woocommerce_paypal_payments_before_capture_order',
			function ( $order ) use ( &$before_capture_calls ) {
				$before_capture_calls[] = $order;
			}
		);
		$this->logger->shouldNotReceive( 'warning' );
		$this->stub_http( $this->http_response( 201, '{"is_correct":true}' ) );

		$sut = $this->make_endpoint( $this->make_bearer(), $order_factory );

		$result = $sut->capture( $order_to_capture );

		$this->assertSame( $expected_order, $result );
		$this->assert_single_request( self::HOST . 'v2/checkout/orders/id/capture', 'POST', 'return=representation' );
		$this->assertSame( array( $order_to_capture ), $before_capture_calls, 'The before-capture action should fire once with the order' );
	}

	/**
	 * Regression guard: the before_capture_order action must not fire for an already-completed order (e.g. vaulted
	 * ACDC payments that PayPal auto-captures). Firing it would trigger CardCaptureValidator and throw
	 * "Could not capture the PayPal order".
	 *
	 * @testdox Should return an already completed order as it is, without firing the before-capture action or sending a request.
	 */
	public function test_capture_returns_completed_order_untouched(): void {
		$status = $this->mock( OrderStatus::class );
		$status->expects( 'is' )->with( 'COMPLETED' )->andReturn( true );
		$order_to_capture = $this->mock( Order::class );
		$order_to_capture->expects( 'status' )->andReturn( $status );

		$before_capture_calls = 0;
		add_action(
			'woocommerce_paypal_payments_before_capture_order',
			function () use ( &$before_capture_calls ) {
				++$before_capture_calls;
			}
		);
		$this->logger->shouldNotReceive( 'warning' );
		$this->stub_http( $this->http_response( 201, '{}' ) );

		$result = $this->make_endpoint()->capture( $order_to_capture );

		$this->assertSame( $order_to_capture, $result );
		$this->assertSame( 0, $before_capture_calls, 'The before-capture action must not fire for a completed order' );
		$this->assertCount( 0, $this->http_requests, 'No request should be sent for a completed order' );
	}

	/**
	 * A not-yet-completed order mock that reports the given PayPal ID.
	 *
	 * @param string $id The PayPal order ID.
	 * @return MockInterface
	 */
	private function make_capturable_order( string $id ): MockInterface {
		$status = $this->mock( OrderStatus::class );
		$status->expects( 'is' )->with( 'COMPLETED' )->andReturn( false );
		$order = $this->mock( Order::class );
		$order->expects( 'status' )->andReturn( $status );
		$order->shouldReceive( 'id' )->andReturn( $id );

		return $order;
	}

	/**
	 * @testdox Should throw a runtime exception when capturing fails with a WP error.
	 */
	public function test_capture_throws_on_wp_error(): void {
		$this->stub_http( new WP_Error( 'http_request_failed', 'Connection refused' ) );
		$sut = $this->make_endpoint( $this->make_bearer() );

		$this->expectException( RuntimeException::class );

		$sut->capture( $this->make_capturable_order( 'id' ) );
	}

	/**
	 * @testdox Should throw a runtime exception when capturing returns a status other than 201.
	 */
	public function test_capture_throws_when_status_is_not_201(): void {
		$this->stub_http( $this->http_response( 500, '{"some_error":true}' ) );
		$sut = $this->make_endpoint( $this->make_bearer() );

		$this->expectException( RuntimeException::class );

		$sut->capture( $this->make_capturable_order( 'id' ) );
	}

	/**
	 * @testdox Should fetch and return the order again when PayPal answers that it was already captured.
	 */
	public function test_capture_returns_refetched_order_when_already_captured(): void {
		$order_to_expect = $this->mock( Order::class );
		$sut             = $this->make_endpoint( $this->make_bearer(), null, null, true );
		$sut->expects( 'order' )->with( 'id' )->andReturn( $order_to_expect );
		$this->stub_http( $this->http_response( 500, '{"some_error": "' . ErrorResponse::ORDER_ALREADY_CAPTURED . '"}' ) );

		$result = $sut->capture( $this->make_capturable_order( 'id' ) );

		$this->assertSame( $order_to_expect, $result );
	}

	/**
	 * Run a capture whose response order carries one capture with the given status and fraud response, and return
	 * what capture() throws.
	 *
	 * @param FraudProcessorResponse|null $fraud The fraud processor response of the declined capture.
	 */
	private function capture_declined_with( ?FraudProcessorResponse $fraud ): void {
		$expected_order = $this->mock( Order::class );
		$order_factory  = $this->mock( OrderFactory::class );
		$order_factory->expects( 'from_paypal_response' )->andReturn( $expected_order );

		$purchase_unit = $this->mock( PurchaseUnit::class );
		$payments      = $this->mock( Payments::class );
		$capture       = $this->mock( Capture::class );
		$expected_order->shouldReceive( 'purchase_units' )->once()->andReturn( array( $purchase_unit ) );
		$purchase_unit->shouldReceive( 'payments' )->once()->andReturn( $payments );
		$payments->shouldReceive( 'captures' )->once()->andReturn( array( $capture ) );
		$capture->shouldReceive( 'status' )->once()->andReturn( new CaptureStatus( CaptureStatus::DECLINED ) );
		$capture->shouldReceive( 'fraud_processor_response' )->once()->andReturn( $fraud );

		$this->logger->shouldNotReceive( 'warning' );
		$this->stub_http( $this->http_response( 201, '{"id":"order123"}' ) );

		$this->make_endpoint( $this->make_bearer(), $order_factory )->capture( $this->make_capturable_order( 'id' ) );
	}

	/**
	 * @testdox Should throw the customer message mapped to the fraud response code when the capture is declined.
	 */
	public function test_capture_declined_with_fraud_response_code_throws_detailed_message(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/your bank was unable to approve this transaction/' );

		$this->capture_declined_with( new FraudProcessorResponse( null, null, '9500' ) );
	}

	/**
	 * @testdox Should throw the generic decline message when the declined capture has no fraud response.
	 */
	public function test_capture_declined_with_null_fraud_throws_generic_message(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Payment provider declined the payment, please use a different payment method.' );

		$this->capture_declined_with( null );
	}

	/**
	 * @testdox Should throw the generic decline message when the fraud response has no code.
	 */
	public function test_capture_declined_with_empty_response_code_throws_generic_message(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Payment provider declined the payment, please use a different payment method.' );

		$this->capture_declined_with( new FraudProcessorResponse( null, null, null ) );
	}

	/**
	 * A patch collection mock that holds two patches.
	 *
	 * @param Order $order_to_update  The order being patched.
	 * @param Order $order_to_compare The target order.
	 * @return PatchCollectionFactory
	 */
	private function make_patch_factory( Order $order_to_update, Order $order_to_compare ): PatchCollectionFactory {
		$patches    = array( 'patch-1', 'patch-2' );
		$collection = $this->mock( PatchCollection::class );
		$collection->expects( 'patches' )->andReturn( $patches );
		$collection->expects( 'to_array' )->andReturn( $patches );
		$factory = $this->mock( PatchCollectionFactory::class );
		$factory->expects( 'from_orders' )->with( $order_to_update, $order_to_compare )->andReturn( $collection );

		return $factory;
	}

	/**
	 * A pair of order mocks for the patch tests; the first one reports the PayPal ID "id".
	 *
	 * @return Order[]
	 */
	private function make_orders_to_patch(): array {
		$order_to_update = $this->mock( Order::class );
		$order_to_update->shouldReceive( 'id' )->andReturn( 'id' );
		$order_to_update->shouldReceive( 'purchase_units' )->andReturn( array() );

		return array( $order_to_update, $this->mock( Order::class ) );
	}

	/**
	 * @testdox Should PATCH the order with the patches and return the order fetched afterwards.
	 */
	public function test_patch_order_with_patches_then_refetches_order(): void {
		list( $order_to_update, $order_to_compare ) = $this->make_orders_to_patch();

		$expected_order = $this->mock( Order::class );
		$patch_factory  = $this->make_patch_factory( $order_to_update, $order_to_compare );
		$sut            = $this->make_endpoint( $this->make_bearer(), null, $patch_factory, true );
		$sut->expects( 'order' )->with( 'id' )->andReturn( $expected_order );
		$this->logger->shouldNotReceive( 'warning' );
		$this->stub_http( $this->http_response( 204, '' ) );

		$result = $sut->patch_order_with( $order_to_update, $order_to_compare );

		$this->assertSame( $expected_order, $result );
		$this->assert_single_request( self::HOST . 'v2/checkout/orders/id', 'PATCH', 'return=representation' );
		$this->assertSame( array( 'patch-1', 'patch-2' ), json_decode( $this->http_requests[0]['request']['body'] ) );
	}

	/**
	 * @testdox Should throw a runtime exception when patching returns a status other than 204.
	 */
	public function test_patch_order_with_throws_when_status_is_not_204(): void {
		list( $order_to_update, $order_to_compare ) = $this->make_orders_to_patch();

		$patch_factory = $this->make_patch_factory( $order_to_update, $order_to_compare );
		$sut           = $this->make_endpoint( $this->make_bearer(), null, $patch_factory );
		$this->stub_http( $this->http_response( 500, '{"has_error":true}' ) );

		$this->expectException( RuntimeException::class );

		$sut->patch_order_with( $order_to_update, $order_to_compare );
	}

	/**
	 * @testdox Should throw a runtime exception when patching fails with a WP error.
	 */
	public function test_patch_order_with_throws_on_wp_error(): void {
		list( $order_to_update, $order_to_compare ) = $this->make_orders_to_patch();

		$patch_factory = $this->make_patch_factory( $order_to_update, $order_to_compare );
		$sut           = $this->make_endpoint( $this->make_bearer(), null, $patch_factory, true );
		$this->stub_http( new WP_Error( 'http_request_failed', 'Connection refused' ) );

		$this->expectException( RuntimeException::class );

		$sut->patch_order_with( $order_to_update, $order_to_compare );
	}

	/**
	 * @testdox Should return the order as it is, without a request, when there is nothing to patch.
	 */
	public function test_patch_order_with_returns_order_when_no_patches(): void {
		$order_to_update  = $this->mock( Order::class );
		$order_to_compare = $this->mock( Order::class );
		$collection       = $this->mock( PatchCollection::class );
		$collection->expects( 'patches' )->andReturn( array() );
		$patch_factory = $this->mock( PatchCollectionFactory::class );
		$patch_factory->expects( 'from_orders' )->with( $order_to_update, $order_to_compare )->andReturn( $collection );
		$this->logger->shouldNotReceive( 'warning' );
		$this->stub_http( $this->http_response( 204, '' ) );

		$result = $this->make_endpoint( null, null, $patch_factory )->patch_order_with( $order_to_update, $order_to_compare );

		$this->assertSame( $order_to_update, $result );
		$this->assertCount( 0, $this->http_requests, 'No request should be sent' );
	}

	/**
	 * A purchase unit mock whose to_array() returns the given data.
	 *
	 * @param bool  $contains_physical_goods Whether the unit reports physical goods.
	 * @param array $to_array                The array form of the unit.
	 * @return MockInterface
	 */
	private function make_purchase_unit( bool $contains_physical_goods, array $to_array ): MockInterface {
		$purchase_unit = Mockery::mock( PurchaseUnit::class, array( 'contains_physical_goods' => $contains_physical_goods ) );
		$purchase_unit->expects( 'to_array' )->andReturn( $to_array );
		$purchase_unit->shouldReceive( 'shipping' )->andReturn( $this->shipping );

		return $purchase_unit;
	}

	/**
	 * A payer mock.
	 *
	 * @param string $email The payer's email address; the payer array is only sent when it is not empty.
	 * @return MockInterface
	 */
	private function make_payer( string $email ): MockInterface {
		$payer = $this->mock( Payer::class );
		$payer->expects( 'email_address' )->andReturn( $email );
		if ( '' !== $email ) {
			$payer->expects( 'to_array' )->andReturn( array( 'payer' ) );
		}

		return $payer;
	}

	/**
	 * @testdox Should POST the purchase units to the orders URL and return the created order, leaving out an empty payer.
	 */
	public function test_create_posts_purchase_units_and_returns_order(): void {
		$expected_order = $this->mock( Order::class );
		$order_factory  = $this->mock( OrderFactory::class );
		$order_factory
			->expects( 'from_paypal_response' )
			->andReturnUsing(
				function ( $json ) use ( $expected_order ) {
					return $json->success ? $expected_order : Mockery::mock( Order::class );
				}
			);
		$this->logger->shouldNotReceive( 'warning' );
		$this->stub_http( $this->http_response( 201, '{"success":true}' ) );
		$sut = $this->make_endpoint( $this->make_bearer(), $order_factory );

		$result = $sut->create(
			array( $this->make_purchase_unit( false, array( 'singlePurchaseUnit' ) ) ),
			ExperienceContext::SHIPPING_PREFERENCE_NO_SHIPPING,
			$this->make_payer( '' )
		);

		$this->assertSame( $expected_order, $result );
		$this->assert_single_request( self::HOST . 'v2/checkout/orders', 'POST', 'return=representation' );
		$body = json_decode( $this->http_requests[0]['request']['body'], true );
		$this->assertSame( 'singlePurchaseUnit', $body['purchase_units'][0][0] );
		$this->assertArrayNotHasKey( 'payer', $body, 'A payer without an email address should not be sent' );
	}

	/**
	 * @testdox Should send the payer along with the purchase units when the payer has an email address.
	 */
	public function test_create_sends_payer_with_email(): void {
		$expected_order = $this->mock( Order::class );
		$order_factory  = $this->mock( OrderFactory::class );
		$order_factory->expects( 'from_paypal_response' )->andReturn( $expected_order );
		$this->logger->shouldNotReceive( 'warning' );
		$this->stub_http( $this->http_response( 201, '{"success":true}' ) );
		$sut = $this->make_endpoint( $this->make_bearer(), $order_factory );

		$result = $sut->create(
			array( $this->make_purchase_unit( true, array( 'singlePurchaseUnit' ) ) ),
			ExperienceContext::SHIPPING_PREFERENCE_GET_FROM_FILE,
			$this->make_payer( 'email@email.com' )
		);

		$this->assertSame( $expected_order, $result );
		$this->assertCount( 1, $this->http_requests );
		$this->assertSame( 'POST', $this->http_requests[0]['request']['method'] );
		$body = json_decode( $this->http_requests[0]['request']['body'], true );
		$this->assertSame( 'payer', $body['payer'][0] );
	}

	/**
	 * @testdox Should throw a runtime exception when creating the order fails with a WP error.
	 */
	public function test_create_throws_on_wp_error(): void {
		$this->stub_http( new WP_Error( 'http_request_failed', 'Connection refused' ) );
		$sut = $this->make_endpoint( $this->make_bearer() );

		$this->expectException( RuntimeException::class );

		$sut->create(
			array( $this->make_purchase_unit( false, array( 'singlePurchaseUnit' ) ) ),
			ExperienceContext::SHIPPING_PREFERENCE_NO_SHIPPING,
			$this->make_payer( 'email@email.com' )
		);
	}

	/**
	 * @testdox Should throw a runtime exception when creating the order returns a status other than 200 or 201.
	 */
	public function test_create_throws_when_status_is_not_201(): void {
		$this->stub_http( $this->http_response( 500, '{"has_error":true}' ) );
		$sut = $this->make_endpoint( $this->make_bearer() );

		$this->expectException( RuntimeException::class );

		$sut->create(
			array( $this->make_purchase_unit( true, array( 'singlePurchaseUnit' ) ) ),
			ExperienceContext::SHIPPING_PREFERENCE_GET_FROM_FILE,
			$this->make_payer( 'email@email.com' )
		);
	}

	/**
	 * GIVEN a purchase unit whose shipping node only carries options
	 * WHEN an order is created with a non-GET_FROM_FILE shipping preference
	 * THEN the shipping node left empty by stripping the options is not sent.
	 *
	 * @testdox Should not send the shipping node that is left empty after the options are stripped.
	 */
	public function test_create_unsets_empty_shipping_node_left_after_removing_options(): void {
		$order_factory = $this->mock( OrderFactory::class );
		$order_factory->expects( 'from_paypal_response' )->andReturn( $this->mock( Order::class ) );
		$this->logger->shouldNotReceive( 'warning' );
		$this->stub_http( $this->http_response( 201, '{"success":true}' ) );
		$sut = $this->make_endpoint( $this->make_bearer(), $order_factory );

		$sut->create(
			array( $this->make_purchase_unit( true, array( 'shipping' => array( 'options' => array( array( 'id' => 'flat_rate' ) ) ) ) ) ),
			ExperienceContext::SHIPPING_PREFERENCE_SET_PROVIDED_ADDRESS,
			$this->make_payer( '' )
		);

		$this->assertCount( 1, $this->http_requests );
		$body = json_decode( $this->http_requests[0]['request']['body'], true );
		$this->assertArrayNotHasKey( 'shipping', $body['purchase_units'][0] );
	}
}
