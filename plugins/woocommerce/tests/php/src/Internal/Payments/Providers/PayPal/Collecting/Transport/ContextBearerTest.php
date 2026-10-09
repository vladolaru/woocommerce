<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Transport;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\ContextBearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\NotReadyTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\OrderAppContext;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FixedHeldOrders;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Tests for the bearer that signs each call with the token of the app the call uses.
 *
 * @group paypal-wallet
 */
class ContextBearerTest extends WalletTestCase {

	/**
	 * The order app context.
	 *
	 * @var OrderAppContext
	 */
	private OrderAppContext $context;

	/**
	 * Put the store in the collecting state and build the context.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_wallet_option(
			Options::COLLECTING,
			array(
				'payee_email' => 'payee@example.com',
				'tracking_id' => 'abc',
				'environment' => 'sandbox',
				'payee_bound' => false,
			)
		);
		$this->context = new OrderAppContext();
	}

	/**
	 * Build the bearer over a transport.
	 *
	 * @param FakePlatformTransport $transport The transport.
	 * @return ContextBearer
	 */
	private function build_sut( FakePlatformTransport $transport ): ContextBearer {
		return new ContextBearer( $this->context, $transport, new CollectingState( new Options(), new FixedHeldOrders( 0 ) ) );
	}

	/**
	 * @testdox Should sign with the merchant app's token when the order context is the merchant app.
	 */
	public function test_uses_the_merchant_app_token_in_a_merchant_app_context(): void {
		$transport = new FakePlatformTransport( array( 'pick' => PlatformTransport::APP_PLATFORM ) );
		$this->context->enter( PlatformTransport::APP_MERCHANT_APP );

		$this->assertSame( 'token-merchant_app', $this->build_sut( $transport )->bearer()->token() );
		$this->assertSame( array(), $transport->calls_to( 'pick_order_app' ), 'An entered context is not re-picked' );
	}

	/**
	 * @testdox Should sign with the platform's token when the order context is the platform.
	 */
	public function test_uses_the_platform_token_in_a_platform_context(): void {
		$transport = new FakePlatformTransport( array( 'pick' => PlatformTransport::APP_MERCHANT_APP ) );
		$this->context->enter( PlatformTransport::APP_PLATFORM );

		$this->assertSame( 'token-platform', $this->build_sut( $transport )->bearer()->token() );
	}

	/**
	 * @testdox Should sign with the token of the app the transport picks for the payee when no order context is entered.
	 * @testWith ["merchant_app", "token-merchant_app"]
	 *           ["platform", "token-platform"]
	 *
	 * @param string $pick     The app the transport picks.
	 * @param string $expected The token the bearer hands out.
	 */
	public function test_uses_the_picked_app_without_an_order_context( string $pick, string $expected ): void {
		$transport = new FakePlatformTransport( array( 'pick' => $pick ) );

		$this->assertSame( $expected, $this->build_sut( $transport )->bearer()->token() );
		$this->assertSame( array( array( 'payee@example.com' ) ), $transport->calls_to( 'pick_order_app' ), 'The pick is for the store payee' );
	}

	/**
	 * @testdox Should follow the context when it changes between calls.
	 */
	public function test_resolves_the_app_on_every_call(): void {
		$transport = new FakePlatformTransport( array( 'pick' => PlatformTransport::APP_PLATFORM ) );
		$sut       = $this->build_sut( $transport );

		$first = $sut->bearer()->token();
		$this->context->enter( PlatformTransport::APP_MERCHANT_APP );
		$second = $sut->bearer()->token();

		$this->assertSame( array( 'token-platform', 'token-merchant_app' ), array( $first, $second ) );
	}

	/**
	 * @testdox Should throw the wallet's exception, the one its callers catch, when the transport is not ready.
	 */
	public function test_a_not_ready_transport_fails_with_the_wallets_exception(): void {
		$sut = new ContextBearer( $this->context, new NotReadyTransport( new CollectingState( new Options(), new FixedHeldOrders( 0 ) ) ), new CollectingState( new Options(), new FixedHeldOrders( 0 ) ) );
		$this->expectException( RuntimeException::class );

		$sut->bearer();
	}
}
