<?php

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication;

use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Token;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ApiHostResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\Cache;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\ConnectionState;

/**
 * Resolves the API bearer to use for the current connection state.
 *
 * A Bearer that mirrors ApiHostResolver's approach for the host: is_connected()
 * decides between ConnectBearer and PayPalBearer on every call rather than
 * freezing that choice at construction time. Without this, a Bearer resolved
 * (e.g. via rest_api_init building the webhook controller) before
 * ConnectionState::connect() runs in the same request stays a ConnectBearer -
 * a hardcoded placeholder token - for the rest of that request, even after
 * the merchant is connected and api.host has moved on to the real PayPal API.
 */
class ResolvingBearer implements Bearer {

	/**
	 * The connection state.
	 *
	 * @var ConnectionState
	 */
	private ConnectionState $connection_state;

	/**
	 * The cache.
	 *
	 * @var Cache
	 */
	private Cache $cache;

	/**
	 * The API host resolver.
	 *
	 * @var ApiHostResolver
	 */
	private ApiHostResolver $host_resolver;

	/**
	 * The client ID.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * The client secret.
	 *
	 * @var string
	 */
	private string $secret;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * The settings provider.
	 *
	 * @var SettingsProvider|null
	 */
	private ?SettingsProvider $settings;

	/**
	 * The token rate limiter.
	 *
	 * @var TokenRateLimiter
	 */
	private TokenRateLimiter $rate_limiter;

	/**
	 * ResolvingBearer constructor.
	 *
	 * @param ConnectionState       $connection_state The connection state.
	 * @param Cache                 $cache The cache.
	 * @param ApiHostResolver       $host_resolver The API host resolver.
	 * @param string                $key The client ID.
	 * @param string                $secret The client secret.
	 * @param LoggerInterface       $logger The logger.
	 * @param SettingsProvider|null $settings The settings provider.
	 * @param TokenRateLimiter      $rate_limiter The token rate limiter.
	 */
	public function __construct(
		ConnectionState $connection_state,
		Cache $cache,
		ApiHostResolver $host_resolver,
		string $key,
		string $secret,
		LoggerInterface $logger,
		?SettingsProvider $settings,
		TokenRateLimiter $rate_limiter
	) {
		$this->connection_state = $connection_state;
		$this->cache            = $cache;
		$this->host_resolver    = $host_resolver;
		$this->key              = $key;
		$this->secret           = $secret;
		$this->logger           = $logger;
		$this->settings         = $settings;
		$this->rate_limiter     = $rate_limiter;
	}

	/**
	 * Returns the bearer to use right now.
	 *
	 * Must be called fresh for every request, not resolved once and cached -
	 * see the class docblock.
	 */
	public function bearer(): Token {
		if ( ! $this->connection_state->is_connected() ) {
			return ( new ConnectBearer() )->bearer();
		}

		return ( new PayPalBearer(
			$this->cache,
			$this->host_resolver->host(),
			$this->key,
			$this->secret,
			$this->logger,
			$this->settings,
			$this->rate_limiter
		) )->bearer();
	}
}
