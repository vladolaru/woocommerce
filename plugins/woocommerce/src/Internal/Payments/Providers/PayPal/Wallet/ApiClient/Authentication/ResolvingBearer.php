<?php

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication;

use Psr\Log\LoggerInterface;
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

	private ConnectionState $connection_state;

	private Cache $cache;

	private ApiHostResolver $host_resolver;

	private string $key;

	private string $secret;

	private LoggerInterface $logger;

	private ?SettingsProvider $settings;

	private TokenRateLimiter $rate_limiter;

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
