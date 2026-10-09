<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Transport;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\ContextHostResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\OrderAppContext;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ApiHostResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\Environment;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FixedHeldOrders;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Tests for the host resolver that answers the host of the app the call uses.
 *
 * @group paypal-wallet
 */
class ContextHostResolverTest extends WalletTestCase {

	/**
	 * The order app context.
	 *
	 * @var OrderAppContext
	 */
	private OrderAppContext $context;

	/**
	 * Build the context.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->context = new OrderAppContext();
	}

	/**
	 * Build the resolver over a transport, with a wallet connection state that is not connected, in sandbox.
	 *
	 * @param FakePlatformTransport $transport The transport.
	 * @return ContextHostResolver
	 */
	private function build_sut( FakePlatformTransport $transport ): ContextHostResolver {
		return new ContextHostResolver(
			new ConnectionState( false, new Environment( true ) ),
			$this->context,
			$transport,
			new CollectingState( new Options(), new FixedHeldOrders( 0 ) )
		);
	}

	/**
	 * @testdox Should be an ApiHostResolver, so the wallet's consumers accept it.
	 */
	public function test_is_an_api_host_resolver(): void {
		$this->assertInstanceOf( ApiHostResolver::class, $this->build_sut( new FakePlatformTransport() ) );
	}

	/**
	 * @testdox Should answer the host of the entered app, or of the picked app when none is entered.
	 */
	public function test_answers_the_host_of_the_app_for_the_call(): void {
		$transport = new FakePlatformTransport( array( 'pick' => PlatformTransport::APP_MERCHANT_APP ) );
		$sut       = $this->build_sut( $transport );

		$picked = $sut->host();
		$this->context->enter( PlatformTransport::APP_PLATFORM );
		$entered = $sut->host();

		$this->assertSame( 'https://api.merchant-app.fake.test', $picked );
		$this->assertSame( 'https://api.platform.fake.test', $entered );
	}

	/**
	 * @testdox Should answer the wallet's own host while the transport is not ready, without asking it for one.
	 */
	public function test_answers_the_wallet_host_while_the_transport_is_not_ready(): void {
		$transport = new FakePlatformTransport( array( 'ready' => false ) );

		$this->assertSame( CONNECT_WOO_SANDBOX_URL, $this->build_sut( $transport )->host() );
		$this->assertSame( array(), $transport->calls_to( 'host' ) );
		$this->assertSame( array(), $transport->calls_to( 'pick_order_app' ) );
	}
}
