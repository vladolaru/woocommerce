<?php
/**
 * Tests for the FraudNet client metadata header of the order creation request.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\OrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\ExperienceContext;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PurchaseUnit;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\OrderFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PatchCollectionFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\FraudNet\FraudNet;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\SubscriptionHelper;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Mockery;

/**
 * Every order creation request carries PayPal's client metadata ID, the FraudNet session ID, when FraudNet is enabled; a
 * wallet store enables it always (`wcgateway.is-fraudnet-enabled` is true). This is part of what core sends PayPal, so the
 * cases pin that the header is sent with FraudNet enabled and left out with it disabled.
 *
 * @group paypal-wallet
 */
class OrderEndpointFraudNetHeaderTest extends WalletTestCase {

	/**
	 * Create an order through an endpoint built with the given FraudNet setting and return the request that went out.
	 *
	 * @param bool $fraudnet_enabled Whether the endpoint has FraudNet enabled.
	 * @return array The request arguments of the one request.
	 */
	private function create_order_and_get_request( bool $fraudnet_enabled ): array {
		$fraudnet = $this->mock( FraudNet::class );
		$fraudnet->shouldReceive( 'session_id' )->andReturn( 'fraudnet-session-1' );

		$order_factory = $this->mock( OrderFactory::class );
		$order_factory->shouldReceive( 'from_paypal_response' )->andReturn( $this->mock( Order::class ) );

		$purchase_unit = Mockery::mock( PurchaseUnit::class );
		$purchase_unit->shouldReceive( 'to_array' )->andReturn( array( 'reference_id' => 'default' ) );

		$this->stub_http( $this->http_response( 201, '{"success":true}' ) );

		$sut = new OrderEndpoint(
			'https://example.com/',
			$this->make_bearer(),
			$order_factory,
			$this->mock( PatchCollectionFactory::class ),
			'CAPTURE',
			$this->mock( LoggerInterface::class )->shouldIgnoreMissing(),
			$this->mock( SubscriptionHelper::class ),
			$fraudnet_enabled,
			$fraudnet
		);

		$sut->create( array( $purchase_unit ), ExperienceContext::SHIPPING_PREFERENCE_NO_SHIPPING );

		$this->assertCount( 1, $this->http_requests, 'Exactly one request should be sent' );

		return $this->http_requests[0]['request'];
	}

	/**
	 * @testdox Should send the FraudNet session ID as the PayPal client metadata ID when creating an order with FraudNet enabled (wallet).
	 */
	public function test_create_sends_the_client_metadata_id(): void {
		$request = $this->create_order_and_get_request( true );

		$this->assertSame( 'fraudnet-session-1', $request['headers']['PayPal-Client-Metadata-Id'] );
	}

	/**
	 * @testdox Should send no client metadata ID when creating an order with FraudNet disabled (wallet).
	 */
	public function test_create_sends_no_client_metadata_id_when_disabled(): void {
		$request = $this->create_order_and_get_request( false );

		$this->assertArrayNotHasKey( 'PayPal-Client-Metadata-Id', $request['headers'] );
	}
}
