<?php
/**
 * Builds a PartnersEndpoint with a dedicated bearer for given credentials.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory;

use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PartnersEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\Cache;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\FailureRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\EnvironmentConfig;

/**
 * Builds a PartnersEndpoint for an explicit set of merchant credentials, backed
 * by its own freshly minted bearer.
 *
 * The container's shared PartnersEndpoint binds its bearer, host and merchant ID
 * to the connection state that existed when it was first resolved during the
 * request. This factory takes the credentials as arguments instead, so a caller
 * can obtain a working endpoint for any credentials, regardless of the active
 * connection or when in the request it is called.
 */
class PartnersEndpointFactory {

	/**
	 * PayPal API host, per environment.
	 *
	 * @var EnvironmentConfig<string>
	 */
	private EnvironmentConfig $paypal_host;

	/**
	 * Partner merchant ID, per environment.
	 *
	 * @var EnvironmentConfig<string>
	 */
	private EnvironmentConfig $partner_id;

	/**
	 * The seller status factory.
	 *
	 * @var SellerStatusFactory
	 */
	private SellerStatusFactory $seller_status_factory;

	/**
	 * The failure registry.
	 *
	 * @var FailureRegistry
	 */
	private FailureRegistry $failure_registry;

	/**
	 * The cache.
	 *
	 * @var Cache
	 */
	private Cache $cache;

	/**
	 * The bearer factory.
	 *
	 * @var PayPalBearerFactory
	 */
	private PayPalBearerFactory $bearer_factory;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * PartnersEndpointFactory constructor.
	 *
	 * @param EnvironmentConfig   $paypal_host The PayPal API host, per environment.
	 * @param EnvironmentConfig   $partner_id The partner merchant ID, per environment.
	 * @param SellerStatusFactory $seller_status_factory The seller status factory.
	 * @param FailureRegistry     $failure_registry The failure registry.
	 * @param Cache               $cache The cache.
	 * @param PayPalBearerFactory $bearer_factory The bearer factory.
	 * @param LoggerInterface     $logger The logger.
	 *
	 * @phpstan-param EnvironmentConfig<string> $paypal_host
	 * @phpstan-param EnvironmentConfig<string> $partner_id
	 */
	public function __construct(
		EnvironmentConfig $paypal_host,
		EnvironmentConfig $partner_id,
		SellerStatusFactory $seller_status_factory,
		FailureRegistry $failure_registry,
		Cache $cache,
		PayPalBearerFactory $bearer_factory,
		LoggerInterface $logger
	) {
		$this->paypal_host           = $paypal_host;
		$this->partner_id            = $partner_id;
		$this->seller_status_factory = $seller_status_factory;
		$this->failure_registry      = $failure_registry;
		$this->cache                 = $cache;
		$this->bearer_factory        = $bearer_factory;
		$this->logger                = $logger;
	}

	/**
	 * Builds a PartnersEndpoint for the given merchant credentials.
	 *
	 * @param bool   $is_sandbox    Whether the credentials are for the sandbox.
	 * @param string $client_id     The merchant client ID.
	 * @param string $client_secret The merchant client secret.
	 * @param string $merchant_id   The merchant ID.
	 */
	public function create(
		bool $is_sandbox,
		string $client_id,
		string $client_secret,
		string $merchant_id
	): PartnersEndpoint {
		$host = (string) $this->paypal_host->get_value( $is_sandbox );

		$bearer = $this->bearer_factory->create( $host, $client_id, $client_secret );

		return new PartnersEndpoint(
			$host,
			$bearer,
			$this->logger,
			$this->seller_status_factory,
			(string) $this->partner_id->get_value( $is_sandbox ),
			$merchant_id,
			$this->failure_registry,
			$this->cache
		);
	}
}
