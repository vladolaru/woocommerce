<?php
/**
 * Tests for the PayPal bearer factory.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\ConnectBearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\PayPalBearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\TokenRateLimiter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PayPalBearerFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\Cache;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;

/**
 * Builds the bearer that authenticates API calls: the full one with client credentials, the login-only one without.
 *
 * @group paypal-wallet
 */
class PayPalBearerFactoryTest extends WalletTestCase {

	/**
	 * The logger the factory writes to.
	 *
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * Set up the logger.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->logger = $this->mock( LoggerInterface::class )->shouldIgnoreMissing();
	}

	/**
	 * The factory under test.
	 *
	 * @return PayPalBearerFactory
	 */
	private function make_sut(): PayPalBearerFactory {
		return new PayPalBearerFactory( $this->mock( TokenRateLimiter::class ), $this->logger );
	}

	/**
	 * Given a host and client credentials, create() returns a PayPal bearer only when both the client ID and the secret
	 * are present, and a login-only connect bearer otherwise.
	 *
	 * @testdox Should decide the bearer type by whether both client credentials are present.
	 *
	 * @dataProvider data_credential_presence
	 *
	 * @param string $client_id      The client ID.
	 * @param string $client_secret  The client secret.
	 * @param string $expected_class The expected bearer class.
	 */
	public function test_create_decides_bearer_type_by_credential_presence( string $client_id, string $client_secret, string $expected_class ): void {
		$result = $this->make_sut()->create( 'https://example.com', $client_id, $client_secret );

		$this->assertInstanceOf( $expected_class, $result );
	}

	/**
	 * The credential combinations and the bearer each gives.
	 *
	 * @return array<string, array>
	 */
	public function data_credential_presence(): array {
		return array(
			'client id and secret present returns PayPalBearer' => array( 'client-id', 'client-secret', PayPalBearer::class ),
			'empty client id returns ConnectBearer'     => array( '', 'client-secret', ConnectBearer::class ),
			'empty client secret returns ConnectBearer' => array( 'client-id', '', ConnectBearer::class ),
			'no credentials returns ConnectBearer'      => array( '', '', ConnectBearer::class ),
		);
	}

	/**
	 * @testdox Should still return a PayPal bearer when given an explicit cache and settings provider.
	 */
	public function test_create_with_explicit_cache_and_settings_returns_pay_pal_bearer(): void {
		$result = $this->make_sut()->create(
			'https://example.com',
			'client-id',
			'client-secret',
			$this->mock( Cache::class ),
			$this->mock( SettingsProvider::class )
		);

		$this->assertInstanceOf( PayPalBearer::class, $result );
	}
}
