<?php
/**
 * Tests for the cached PayPal wallet order endpoint (ported from the extension's OrderEndpointCachedTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\OrderEndpointCached;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\OrderFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PatchCollectionFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\FraudNet\FraudNet;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\SubscriptionHelper;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use WC_Order;

/**
 * The cached order endpoint fetches a PayPal order once and serves repeat lookups from memory.
 *
 * @group paypal-wallet
 */
class OrderEndpointCachedTest extends WalletTestCase {

	/**
	 * Build the endpoint under test over the given order factory.
	 *
	 * @param OrderFactory $order_factory The order factory mock.
	 * @return OrderEndpointCached
	 */
	private function make_endpoint( OrderFactory $order_factory ): OrderEndpointCached {
		return new OrderEndpointCached(
			'https://example.com/',
			$this->make_bearer( false ),
			$order_factory,
			$this->mock( PatchCollectionFactory::class ),
			'CAPTURE',
			$this->mock( LoggerInterface::class )->shouldIgnoreMissing(),
			$this->mock( SubscriptionHelper::class ),
			false,
			$this->mock( FraudNet::class )
		);
	}

	/**
	 * An order factory mock that builds the given orders, one per call, in order.
	 *
	 * @param Order[] $orders The orders to return.
	 * @return OrderFactory
	 */
	private function make_order_factory( array $orders ): OrderFactory {
		$factory = $this->mock( OrderFactory::class );
		$factory
			->expects( 'from_paypal_response' )
			->times( count( $orders ) )
			->andReturnValues( $orders );

		return $factory;
	}

	/**
	 * A WC order carrying the given PayPal order ID in its meta.
	 *
	 * @param string $paypal_id The PayPal order ID.
	 * @return WC_Order
	 */
	private function make_wc_order( string $paypal_id ): WC_Order {
		$wc_order = new WC_Order();
		$wc_order->update_meta_data( PayPalGateway::ORDER_ID_META_KEY, $paypal_id );

		return $wc_order;
	}

	/**
	 * @testdox Should fetch the order once and return the cached instance on the second call.
	 */
	public function test_order_returns_cached_result_on_second_call(): void {
		$order = $this->mock( Order::class );
		$sut   = $this->make_endpoint( $this->make_order_factory( array( $order ) ) );
		$this->stub_http( $this->http_response( 200, '{}' ) );

		$result_1 = $sut->order( 'abc123' );
		$result_2 = $sut->order( 'abc123' );

		$this->assertSame( $order, $result_1 );
		$this->assertSame( $result_1, $result_2 );
		$this->assertCount( 1, $this->http_requests, 'Only the first call should reach the API' );
	}

	/**
	 * @testdox Should cache by the PayPal ID held in a WC order's meta.
	 */
	public function test_order_with_wc_order_returns_cached_result(): void {
		$order    = $this->mock( Order::class );
		$sut      = $this->make_endpoint( $this->make_order_factory( array( $order ) ) );
		$wc_order = $this->make_wc_order( 'abc123' );
		$this->stub_http( $this->http_response( 200, '{}' ) );

		$result_1 = $sut->order( $wc_order );
		$result_2 = $sut->order( $wc_order );

		$this->assertSame( $order, $result_1 );
		$this->assertSame( $result_1, $result_2 );
		$this->assertCount( 1, $this->http_requests, 'Only the first call should reach the API' );
	}

	/**
	 * @testdox Should share one cache entry between a string ID and a WC order that holds the same PayPal ID.
	 */
	public function test_order_with_string_and_wc_order_returns_same_cached_result(): void {
		$order = $this->mock( Order::class );
		$sut   = $this->make_endpoint( $this->make_order_factory( array( $order ) ) );
		$this->stub_http( $this->http_response( 200, '{}' ) );

		$result_1 = $sut->order( 'abc123' );
		$result_2 = $sut->order( $this->make_wc_order( 'abc123' ) );

		$this->assertSame( $order, $result_1 );
		$this->assertSame( $result_1, $result_2 );
		$this->assertCount( 1, $this->http_requests, 'Only the first call should reach the API' );
	}

	/**
	 * @testdox Should fetch again for a different PayPal ID.
	 */
	public function test_order_with_different_ids_does_not_return_cached_result(): void {
		$order_1 = $this->mock( Order::class );
		$order_2 = $this->mock( Order::class );
		$sut     = $this->make_endpoint( $this->make_order_factory( array( $order_1, $order_2 ) ) );
		$this->stub_http( $this->http_response( 200, '{}' ) );

		$result_1 = $sut->order( 'abc123' );
		$result_2 = $sut->order( 'def456' );

		$this->assertSame( $order_1, $result_1 );
		$this->assertSame( $order_2, $result_2 );
		$this->assertNotSame( $result_1, $result_2 );
		$this->assertSame( 'https://example.com/v2/checkout/orders/abc123', $this->http_requests[0]['url'] );
		$this->assertSame( 'https://example.com/v2/checkout/orders/def456', $this->http_requests[1]['url'] );
	}

	/**
	 * @testdox Should keep every fetched order cached under its own ID.
	 */
	public function test_order_caches_multiple_orders(): void {
		$order_1 = $this->mock( Order::class );
		$order_2 = $this->mock( Order::class );
		$sut     = $this->make_endpoint( $this->make_order_factory( array( $order_1, $order_2 ) ) );
		$this->stub_http( $this->http_response( 200, '{}' ) );

		$result_1 = $sut->order( 'abc123' );
		$result_2 = $sut->order( 'def456' );

		$this->assertSame( $order_1, $sut->order( 'abc123' ) );
		$this->assertSame( $order_2, $sut->order( 'def456' ) );
		$this->assertSame( $order_1, $result_1 );
		$this->assertSame( $order_2, $result_2 );
		$this->assertCount( 2, $this->http_requests, 'Only the two first lookups should reach the API' );
	}
}
