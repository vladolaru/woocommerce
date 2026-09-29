<?php
/**
 * WooPaymentsTrackingInfoService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the platform's site tracking info (`GET tracking/info`) through the plugin's `wcpay_tracking_info_cache` option.
 *
 * Mirrors the plugin's `WC_Payments_Account::get_tracking_info()` and its `Database_Cache` entry (11.1.0): a month-long
 * cache, a 2/5/10/15 minute backoff after errors, stale data kept on errors, and no refresh during cron or AJAX.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsTrackingInfoService {

	private const CACHE_OPTION = 'wcpay_tracking_info_cache';

	private const ERRORED_TTL_LADDER = array(
		2 * MINUTE_IN_SECONDS,
		5 * MINUTE_IN_SECONDS,
		10 * MINUTE_IN_SECONDS,
		15 * MINUTE_IN_SECONDS,
	);

	/**
	 * Native WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsApiClient $api_client Native WooPayments API client.
	 */
	final public function init( WooPaymentsApiClient $api_client ): void {
		$this->api_client = $api_client;
	}

	/**
	 * Get the cached platform tracking info, refreshing it when it is missing or expired.
	 *
	 * @return array<string,mixed>|null The tracking info, or null before the site is connected or while nothing valid is cached.
	 */
	public function get_tracking_info(): ?array {
		if ( ! $this->api_client->is_available() ) {
			return null;
		}

		$cache_contents = get_option( self::CACHE_OPTION, false );
		$data           = is_array( $cache_contents ) && isset( $cache_contents['data'] ) && is_array( $cache_contents['data'] ) ? $cache_contents['data'] : null;

		if ( ! $this->should_refresh( $cache_contents ) ) {
			return $data;
		}

		$errored = false;
		try {
			$data = $this->api_client->get_tracking_info();
		} catch ( Throwable $exception ) {
			$errored = true;
		}

		$previous_errors = is_array( $cache_contents ) && isset( $cache_contents['consecutive_errors'] ) ? (int) $cache_contents['consecutive_errors'] : 0;
		$result          = update_option(
			self::CACHE_OPTION,
			array(
				'data'               => $data,
				'fetched'            => time(),
				'errored'            => $errored,
				'consecutive_errors' => $errored ? $previous_errors + 1 : 0,
			),
			false
		);
		if ( false !== $result ) {
			wp_cache_delete( self::CACHE_OPTION, 'options' );
		}

		return $data;
	}

	/**
	 * Tell whether the cached tracking info should be fetched again.
	 *
	 * @param mixed $cache_contents Cache wrapper read from the option.
	 * @return bool
	 */
	private function should_refresh( $cache_contents ): bool {
		if ( defined( 'DOING_CRON' ) || wp_doing_ajax() ) {
			return false;
		}

		if ( ! is_array( $cache_contents ) || ! array_key_exists( 'data', $cache_contents ) || ! isset( $cache_contents['fetched'] ) || ! array_key_exists( 'errored', $cache_contents ) ) {
			return true;
		}

		if ( ! $cache_contents['errored'] && ! is_array( $cache_contents['data'] ) ) {
			return true;
		}

		if ( $cache_contents['errored'] ) {
			$index = max( 0, min( count( self::ERRORED_TTL_LADDER ) - 1, (int) ( $cache_contents['consecutive_errors'] ?? 0 ) - 1 ) );
			$ttl   = self::ERRORED_TTL_LADDER[ $index ];
		} else {
			$ttl = MONTH_IN_SECONDS;
		}

		/**
		 * Filters the WooPayments database cache TTL, like the plugin's `Database_Cache` filter.
		 *
		 * @since 11.0.0
		 *
		 * @param int                 $ttl            Cache TTL in seconds.
		 * @param string              $key            Cache option key.
		 * @param array<string,mixed> $cache_contents Cache wrapper.
		 */
		$ttl = (int) apply_filters( 'wcpay_database_cache_ttl', $ttl, self::CACHE_OPTION, $cache_contents );

		return ( (int) $cache_contents['fetched'] + $ttl ) < time();
	}
}
