<?php
/**
 * WooPaymentsTrackingInfoService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the platform's site tracking info (`GET tracking/info`) through the database cache.
 *
 * The info is kept a month, retried on the error backoff, and never refreshed during cron, AJAX or Action Scheduler
 * requests, like client 11.1.0 `WC_Payments_Account::get_tracking_info()` (`includes/class-wc-payments-account.php:3156-3169`).
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsTrackingInfoService {

	/**
	 * WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * Database cache the tracking info is read through.
	 *
	 * @var WooPaymentsDatabaseCache
	 */
	private WooPaymentsDatabaseCache $database_cache;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsApiClient     $api_client     WooPayments API client.
	 * @param WooPaymentsDatabaseCache $database_cache Database cache.
	 */
	final public function init( WooPaymentsApiClient $api_client, WooPaymentsDatabaseCache $database_cache ): void {
		$this->api_client     = $api_client;
		$this->database_cache = $database_cache;
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

		$data = $this->database_cache->get_or_add(
			WooPaymentsDatabaseCache::TRACKING_INFO_KEY,
			fn(): array => $this->api_client->get_tracking_info(),
			'is_array'
		);

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Get the tracking info only as it is already cached, without fetching a missing, expired or failed entry.
	 *
	 * For pages that do not record WooPayments events themselves but can open a WooPayments page without a reload.
	 *
	 * @return array<string,mixed>|null The cached tracking info, or null before the site is connected, with nothing cached, or
	 *                                  after a failed fetch.
	 */
	public function get_cached_tracking_info(): ?array {
		if ( ! $this->api_client->is_available() ) {
			return null;
		}

		$contents = $this->database_cache->get_contents( WooPaymentsDatabaseCache::TRACKING_INFO_KEY );
		if ( ! is_array( $contents ) || ! empty( $contents['errored'] ) || ! is_array( $contents['data'] ?? null ) ) {
			return null;
		}

		return $contents['data'];
	}
}
