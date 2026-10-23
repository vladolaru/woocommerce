<?php
/**
 * Tests for the partners endpoint factory.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\Bearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PartnersEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PartnersEndpointFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PayPalBearerFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\SellerStatusFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\Cache;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\FailureRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\EnvironmentConfig;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;

/**
 * Builds the partners endpoint for the sandbox or the live environment.
 *
 * @group paypal-wallet
 */
class PartnersEndpointFactoryTest extends WalletTestCase {

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
	 * @param EnvironmentConfig   $paypal_host     The PayPal host per environment.
	 * @param EnvironmentConfig   $partner_id      The partner ID per environment.
	 * @param PayPalBearerFactory $bearer_factory  The bearer factory.
	 * @return PartnersEndpointFactory
	 */
	private function make_sut( EnvironmentConfig $paypal_host, EnvironmentConfig $partner_id, PayPalBearerFactory $bearer_factory ): PartnersEndpointFactory {
		return new PartnersEndpointFactory(
			$paypal_host,
			$partner_id,
			$this->mock( SellerStatusFactory::class ),
			$this->mock( FailureRegistry::class ),
			$this->mock( Cache::class ),
			$bearer_factory,
			$this->logger
		);
	}

	/**
	 * Given merchant credentials and a host resolved per environment, create() returns a partners endpoint and asks the
	 * bearer factory for a bearer on the resolved host with those credentials.
	 *
	 * @testdox Should return a partners endpoint and build its bearer with the resolved host and the credentials.
	 *
	 * @dataProvider data_environment
	 *
	 * @param bool   $is_sandbox    Whether the environment is the sandbox.
	 * @param string $expected_host The host the environment resolves to.
	 */
	public function test_create_returns_partners_endpoint_and_forces_full_api_bearer( bool $is_sandbox, string $expected_host ): void {
		$paypal_host = $this->mock( EnvironmentConfig::class );
		$paypal_host->shouldReceive( 'get_value' )->with( $is_sandbox )->andReturn( $expected_host );

		$partner_id = $this->mock( EnvironmentConfig::class );
		$partner_id->shouldReceive( 'get_value' )->with( $is_sandbox )->andReturn( 'partner-id' );

		$bearer         = $this->mock( Bearer::class );
		$bearer_factory = $this->mock( PayPalBearerFactory::class );
		$bearer_factory->shouldReceive( 'create' )
			->once()
			->with( $expected_host, 'client-id', 'client-secret' )
			->andReturn( $bearer );

		$result = $this->make_sut( $paypal_host, $partner_id, $bearer_factory )->create(
			$is_sandbox,
			'client-id',
			'client-secret',
			'merchant-id'
		);

		$this->assertInstanceOf( PartnersEndpoint::class, $result );
	}

	/**
	 * The two environments and the host each resolves to.
	 *
	 * @return array<string, array>
	 */
	public function data_environment(): array {
		return array(
			'sandbox environment resolves sandbox host' => array( true, 'https://api-m.sandbox.paypal.com' ),
			'live environment resolves live host'       => array( false, 'https://api-m.paypal.com' ),
		);
	}
}
