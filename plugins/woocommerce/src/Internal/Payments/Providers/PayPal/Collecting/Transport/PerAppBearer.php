<?php
/**
 * PerAppBearer class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\Bearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\PayPalBearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\TokenRateLimiter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Token;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\Cache;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;

/**
 * The wallet's PayPalBearer for one platform app, with the token and the token rate limit cached under the app's own keys.
 *
 * The wallet's bearer caches under its own key and reads the first-party credentials from its settings. This one gets
 * the app's credentials directly and no settings, and never reads or writes the wallet's cache, so an app's token and the
 * first-party token cannot overwrite each other.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class PerAppBearer implements Bearer {

	/**
	 * The wallet's bearer, built for the app.
	 *
	 * @var PayPalBearer
	 */
	private PayPalBearer $bearer;

	/**
	 * The app's token cache.
	 *
	 * @var Cache
	 */
	private Cache $cache;

	/**
	 * Constructor.
	 *
	 * @param string          $app           One of the PlatformTransport::APP_ constants.
	 * @param string          $host          The PayPal API host.
	 * @param string          $client_id     The app's client ID.
	 * @param string          $client_secret The app's client secret.
	 * @param LoggerInterface $logger        The logger.
	 */
	public function __construct( string $app, string $host, string $client_id, string $client_secret, LoggerInterface $logger ) {
		$this->cache  = new Cache( self::token_prefix( $app ) );
		$this->bearer = new PayPalBearer(
			$this->cache,
			$host,
			$client_id,
			$client_secret,
			$logger,
			null,
			new TokenRateLimiter( new Cache( self::rate_prefix( $app ) ), $logger )
		);
	}

	/**
	 * The app's token, from its cache or freshly issued.
	 *
	 * @since 11.3.0
	 *
	 * @return Token
	 *
	 * @throws RuntimeException When no token can be issued.
	 */
	public function bearer(): Token {
		return $this->bearer->bearer();
	}

	/**
	 * Drop the app's cached token, so the next call issues a new one.
	 *
	 * @since 11.3.0
	 */
	public function forget(): void {
		$this->cache->delete( PayPalBearer::CACHE_KEY );
	}

	/**
	 * Delete every app's cached token and token rate-limit state, for a store that no longer uses the platform apps.
	 *
	 * The known keys are deleted one by one, which also reaches a persistent object cache; the prefix sweep then catches
	 * any other key stored in the options table.
	 *
	 * @since 11.3.0
	 */
	public static function forget_stored_tokens(): void {
		foreach ( array( PlatformTransport::APP_PLATFORM, PlatformTransport::APP_MERCHANT_APP ) as $app ) {
			$tokens = new Cache( self::token_prefix( $app ) );
			$rates  = new Cache( self::rate_prefix( $app ) );
			$tokens->delete( PayPalBearer::CACHE_KEY );
			$rates->delete( PayPalBearer::RATE_LIMIT_SCOPE . TokenRateLimiter::STATE_KEY_SUFFIX );
			$tokens->flush();
			$rates->flush();
		}
	}

	/**
	 * The transient prefix of an app's token.
	 *
	 * @param string $app One of the PlatformTransport::APP_ constants.
	 * @return string
	 */
	private static function token_prefix( string $app ): string {
		return 'wc_paypal_wallet_bearer_' . $app . '_';
	}

	/**
	 * The transient prefix of an app's token rate-limit state.
	 *
	 * @param string $app One of the PlatformTransport::APP_ constants.
	 * @return string
	 */
	private static function rate_prefix( string $app ): string {
		return 'wc_paypal_wallet_rate_' . $app . '_';
	}
}
