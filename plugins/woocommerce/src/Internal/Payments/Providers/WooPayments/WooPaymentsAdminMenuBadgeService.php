<?php
/**
 * WooPaymentsAdminMenuBadgeService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Provides source-backed WooPayments admin menu badge counts.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsAdminMenuBadgeService {

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Native WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * Database cache the counts are read through.
	 *
	 * @var WooPaymentsDatabaseCache
	 */
	private WooPaymentsDatabaseCache $database_cache;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 * @param WooPaymentsApiClient      $api_client      WooPayments API client.
	 * @param WooPaymentsDatabaseCache  $database_cache  Database cache.
	 */
	final public function init( WooPaymentsAccountService $account_service, WooPaymentsApiClient $api_client, WooPaymentsDatabaseCache $database_cache ): void {
		$this->account_service = $account_service;
		$this->api_client      = $api_client;
		$this->database_cache  = $database_cache;
	}

	/**
	 * Get the number of disputes awaiting a merchant response.
	 *
	 * @return int
	 */
	public function get_disputes_awaiting_response_count(): int {
		$cache_key = $this->account_service->is_test_mode_enabled() ? WooPaymentsDatabaseCache::DISPUTE_STATUS_COUNTS_KEY_TEST_MODE : WooPaymentsDatabaseCache::DISPUTE_STATUS_COUNTS_KEY;
		$counts    = $this->get_cached_array(
			$cache_key,
			function (): array {
				return $this->api_client->get_dispute_status_counts();
			}
		);

		return max(
			0,
			(int) ( $counts['needs_response'] ?? 0 ) + (int) ( $counts['warning_needs_response'] ?? 0 )
		);
	}

	/**
	 * Get the disputes awaiting a response, soonest due first.
	 *
	 * Ports client 11.1.0 `WC_Payments_Task_Disputes::get_disputes_needing_response()`: the first 50 disputes in
	 * the `wcpay_active_dispute_cache` option, refreshed at most once per cache TTL and cleared by dispute events.
	 *
	 * @since 11.2.0
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_active_disputes(): array {
		$disputes = $this->get_cached_array(
			WooPaymentsDatabaseCache::ACTIVE_DISPUTES_KEY,
			function (): array {
				$response = $this->api_client->get_disputes(
					array(
						'pagesize' => 50,
						'search'   => array( 'warning_needs_response', 'needs_response' ),
					)
				);
				$disputes = is_array( $response['data'] ?? null ) ? $response['data'] : array();

				usort(
					$disputes,
					static function ( $a, $b ): int {
						return new \DateTime( (string) ( $a['due_by'] ?? '' ) ) <=> new \DateTime( (string) ( $b['due_by'] ?? '' ) );
					}
				);

				return $disputes;
			}
		);

		return array_values( array_filter( $disputes, 'is_array' ) );
	}

	/**
	 * Get the number of uncaptured manual-capture transactions.
	 *
	 * @return int
	 */
	public function get_uncaptured_transactions_count(): int {
		if ( ! $this->is_manual_capture_enabled() ) {
			return 0;
		}

		$cache_key = $this->account_service->is_test_mode_enabled() ? WooPaymentsDatabaseCache::AUTHORIZATION_SUMMARY_KEY_TEST_MODE : WooPaymentsDatabaseCache::AUTHORIZATION_SUMMARY_KEY;
		$summary   = $this->get_cached_array(
			$cache_key,
			function (): array {
				return $this->api_client->get_authorizations_summary();
			}
		);

		return max( 0, (int) ( $summary['count'] ?? 0 ) );
	}

	/**
	 * Invalidate both authorization-summary caches so the next read refetches.
	 *
	 * Captures, cancellations and expiries made from the platform dashboard or the mobile apps reach the store only through
	 * the capture-affecting webhooks, which delete both the live and test-mode entries.
	 */
	public function invalidate_authorization_summary_caches(): void {
		$this->database_cache->delete( WooPaymentsDatabaseCache::AUTHORIZATION_SUMMARY_KEY );
		$this->database_cache->delete( WooPaymentsDatabaseCache::AUTHORIZATION_SUMMARY_KEY_TEST_MODE );
	}

	/**
	 * Tell whether manual capture is enabled.
	 *
	 * @return bool
	 */
	public function is_manual_capture_enabled(): bool {
		return 'yes' === (string) $this->account_service->get_gateway_setting( 'manual_capture', 'no' );
	}

	/**
	 * Get an array through the database cache, logging a refresh that throws.
	 *
	 * A failed refresh keeps the last valid data. These entries keep their 24-hour TTL whether the last refresh failed or not.
	 *
	 * @param string   $key   Cache key.
	 * @param callable $fetch Fetches the array from the platform.
	 * @return array<string,mixed>
	 */
	private function get_cached_array( string $key, callable $fetch ): array {
		$data = $this->database_cache->get_or_add(
			$key,
			static function () use ( $key, $fetch ) {
				try {
					return $fetch();
				} catch ( Throwable $exception ) {
					wc_get_container()->get( WooPaymentsLogger::class )->log_throwable( 'Failed to refresh a WooPayments admin cache.', $exception, array( 'cache_key' => $key ) );
					return null;
				}
			},
			'is_array'
		);

		return is_array( $data ) ? $data : array();
	}
}
