<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Transport;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\NotReadyTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FixedHeldOrders;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Tests for the transport that stands in until the platform credentials are configured.
 *
 * @group paypal-wallet
 */
class NotReadyTransportTest extends WalletTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var NotReadyTransport
	 */
	private NotReadyTransport $sut;

	/**
	 * Build the transport over the stored collecting state.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new NotReadyTransport( new CollectingState( new Options(), new FixedHeldOrders( 0 ) ) );
	}

	/**
	 * @testdox Should not be ready.
	 */
	public function test_is_not_ready(): void {
		$this->assertFalse( $this->sut->is_ready() );
	}

	/**
	 * @testdox Should answer the collecting state's environment without throwing.
	 * @testWith ["sandbox"]
	 *           ["production"]
	 *
	 * @param string $environment The stored environment.
	 */
	public function test_environment_is_the_collecting_state_environment( string $environment ): void {
		$this->set_wallet_option(
			Options::COLLECTING,
			array(
				'payee_email' => 'payee@example.com',
				'environment' => $environment,
			)
		);

		$this->assertSame( $environment, $this->sut->environment() );
	}

	/**
	 * @testdox Should throw the wallet's runtime exception from every call that needs the platform, and send nothing.
	 * @dataProvider provide_calls_that_need_the_platform
	 *
	 * @param string $method The method.
	 * @param array  $args   The arguments.
	 */
	public function test_calls_that_need_the_platform_throw( string $method, array $args ): void {
		try {
			$this->sut->{$method}( ...$args );
			$this->fail( "$method must throw while the transport is not configured" );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'The PayPal wallet platform transport is not configured.', $exception->getMessage() );
		}
		$this->assertSame( array(), $this->http_requests );
	}

	/**
	 * Every method that needs the platform, with arguments.
	 *
	 * @return array<string, array{0: string, 1: array}>
	 */
	public function provide_calls_that_need_the_platform(): array {
		$app = PlatformTransport::APP_PLATFORM;

		return array(
			'pick_order_app'        => array( 'pick_order_app', array( 'payee@example.com' ) ),
			'sdk_client_id'         => array( 'sdk_client_id', array( $app ) ),
			'bearer'                => array( 'bearer', array( $app ) ),
			'host'                  => array( 'host', array( $app ) ),
			'assertion_header'      => array( 'assertion_header', array( $app ) ),
			'partner_merchant_id'   => array( 'partner_merchant_id', array() ),
			'referral_link'         => array( 'referral_link', array( 'abc', 'https://example.com/return' ) ),
			'seller_status'         => array( 'seller_status', array( 'abc' ) ),
			'webhook_subscriptions' => array( 'webhook_subscriptions', array() ),
			'subscribe_webhooks'    => array( 'subscribe_webhooks', array( 'https://example.com/hook' ) ),
			'unsubscribe_webhooks'  => array( 'unsubscribe_webhooks', array() ),
			'verify_webhook'        => array( 'verify_webhook', array( $app, array(), '{}' ) ),
		);
	}
}
