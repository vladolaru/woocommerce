<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Logging;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Logging\RedactingLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\LoggerBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\RecordingLogger;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * Tests that the wallet's endpoints, failing on a store the platform serves, write no credential to the log.
 *
 * @group paypal-wallet
 */
class LoggerExtensionTest extends WalletTestCase {
	use BootsCollectingContainer;

	/**
	 * The logger the wallet's own logger is replaced with, under the collecting module's extension.
	 *
	 * @var RecordingLogger
	 */
	private RecordingLogger $recorder;

	/**
	 * Set up the recording logger.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->recorder = new RecordingLogger();
	}

	/**
	 * Boot the container with the fake transport and the recording logger under the collecting module.
	 *
	 * @return ContainerInterface
	 */
	private function boot(): ContainerInterface {
		return $this->boot_container(
			array( new TransportBindingModule( new FakePlatformTransport() ) ),
			array( new LoggerBindingModule( $this->recorder ) )
		);
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
	 * Assert the warning the endpoint logged kept its request args but lost the bearer.
	 */
	private function assert_failure_logged_without_token(): void {
		$warnings = array_values(
			array_filter(
				$this->recorder->records,
				static function ( array $record ): bool {
					return 'warning' === $record['level'] && isset( $record['context']['args'] );
				}
			)
		);
		$this->assertNotEmpty( $warnings, 'The endpoint logs its failure with the request args' );
		$this->assertSame( '[redacted]', $warnings[0]['context']['args']['headers']['Authorization'] );
		$this->assertStringNotContainsString( 'token-', $this->recorder->as_text(), 'No bearer token reaches the log' );
	}

	/**
	 * @testdox Should log a failed seller status call on a platform-connected store without its bearer token.
	 */
	public function test_failed_seller_status_logs_no_token(): void {
		$this->set_wallet_option(
			Options::PLATFORM,
			array(
				'merchant_id' => 'M2',
				'environment' => 'sandbox',
			)
		);
		$this->stub_http( $this->http_response( 400, '{"name":"INVALID_REQUEST","message":"bad"}' ) );

		try {
			$this->boot()->get( 'api.endpoint.partners' )->seller_status();
			$this->fail( 'The failed call throws' );
		} catch ( RuntimeException $exception ) {
			$this->assert_failure_logged_without_token();
		}
		$this->assertCount( 1, $this->http_requests, 'The call was sent, so it was the endpoint that logged' );
	}

	/**
	 * @testdox Should log a failed order fetch on a collecting store without its bearer token.
	 */
	public function test_failed_order_fetch_logs_no_token(): void {
		$this->set_collecting();
		$this->stub_http( $this->http_response( 404, '{"name":"RESOURCE_NOT_FOUND","message":"missing"}' ) );

		try {
			$this->boot()->get( 'api.endpoint.order' )->order( 'ORDER-1' );
			$this->fail( 'The failed call throws' );
		} catch ( RuntimeException $exception ) {
			$this->assert_failure_logged_without_token();
		}
	}

	/**
	 * @testdox Should keep the wallet's own logger for a store the platform does not serve.
	 */
	public function test_store_not_served_keeps_the_wallets_logger(): void {
		$logger = $this->boot()->get( 'woocommerce.logger.woocommerce' );

		$this->assertSame( $this->recorder, $logger );
		$this->assertNotInstanceOf( RedactingLogger::class, $logger );
	}
}
