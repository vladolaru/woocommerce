<?php
/**
 * Builds the API bearer for a set of credentials.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory;

use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\Bearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\ConnectBearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\PayPalBearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\TokenRateLimiter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\Cache;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\InMemoryCache;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;

/**
 * Produces the bearer that matches the supplied credentials.
 *
 * Credential presence is the connection signal: with both a client ID and secret
 * the factory builds an authenticated PayPalBearer, otherwise it returns a
 * login-only ConnectBearer (nothing is connected, so there is nothing to
 * authenticate with). This mirrors PayPalBearer itself, which cannot fetch a token
 * without credentials.
 *
 * By default the PayPalBearer gets an isolated in-memory token cache and static
 * credentials, scoping its token to the passed credentials so it never mixes with
 * another account's cached token. Pass a persistent cache and a settings provider
 * to reproduce the shared, connection-bound bearer instead; when the settings
 * provider resolves credentials dynamically, still pass the current client ID and
 * secret so the bearer-type decision stays correct.
 */
class PayPalBearerFactory {

	/**
	 * The token rate limiter.
	 *
	 * @var TokenRateLimiter
	 */
	private TokenRateLimiter $rate_limiter;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * PayPalBearerFactory constructor.
	 *
	 * @param TokenRateLimiter $rate_limiter The token rate limiter.
	 * @param LoggerInterface  $logger The logger.
	 */
	public function __construct( TokenRateLimiter $rate_limiter, LoggerInterface $logger ) {
		$this->rate_limiter = $rate_limiter;
		$this->logger       = $logger;
	}

	/**
	 * Builds the bearer for the given credentials.
	 *
	 * Returns an authenticated PayPalBearer when both credentials are present,
	 * otherwise a login-only ConnectBearer.
	 *
	 * @param string            $host          The PayPal API host.
	 * @param string            $client_id     The client ID.
	 * @param string            $client_secret The client secret.
	 * @param ?Cache            $cache         Token cache; defaults to an isolated in-memory cache.
	 * @param ?SettingsProvider $settings      Resolves credentials dynamically when provided.
	 */
	public function create(
		string $host = '',
		string $client_id = '',
		string $client_secret = '',
		?Cache $cache = null,
		?SettingsProvider $settings = null
	): Bearer {
		if ( '' === $client_id || '' === $client_secret ) {
			return new ConnectBearer();
		}

		return new PayPalBearer(
			$cache ?? new InMemoryCache(),
			$host,
			$client_id,
			$client_secret,
			$this->logger,
			$settings,
			$this->rate_limiter
		);
	}
}
