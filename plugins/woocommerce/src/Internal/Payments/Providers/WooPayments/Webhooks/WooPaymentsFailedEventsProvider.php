<?php
/**
 * WooPaymentsFailedEventsProvider class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;

/**
 * Provides failed WooPayments webhook events for queue replay.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsFailedEventsProvider {

	/**
	 * Native WooPayments API client.
	 *
	 * @var WooPaymentsApiClient|null
	 */
	private ?WooPaymentsApiClient $api_client = null;

	/**
	 * Whether the last fetch from the platform failed.
	 *
	 * @var bool
	 */
	private bool $last_fetch_failed = false;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsApiClient $api_client Native API client.
	 */
	final public function init( WooPaymentsApiClient $api_client ): void {
		$this->api_client = $api_client;
	}

	/**
	 * Get failed webhook events.
	 *
	 * @since 11.0.0
	 *
	 * @return array{data:array<int,array<string,mixed>>,has_more:bool}
	 */
	public function get_failed_webhook_events(): array {
		$events = array(
			'data'     => array(),
			'has_more' => false,
		);

		$this->last_fetch_failed = false;
		if ( null !== $this->api_client ) {
			try {
				$events = $this->api_client->get_failed_webhook_events();
			} catch ( WooPaymentsApiException $exception ) {
				$this->last_fetch_failed = true;
				// The client appends the platform's message; native logs its status and code.
				wc_get_logger()->error(
					'Can not fetch failed events from the server.',
					array_merge( WooPaymentsLogger::get_failure_context( $exception ), array( 'source' => 'native-payments-webhook' ) )
				);
			}
		}
		return $this->normalize_events_page( $events );
	}

	/**
	 * Tell whether the last call to get_failed_webhook_events() failed to reach the platform.
	 *
	 * @since 11.2.0
	 *
	 * @return bool
	 */
	public function did_last_fetch_fail(): bool {
		return $this->last_fetch_failed;
	}

	/**
	 * Normalize a failed-events page into the supported response shape.
	 *
	 * @param mixed $events Failed-events page.
	 * @return array{data:array<int,array<string,mixed>>,has_more:bool}
	 */
	private function normalize_events_page( $events ): array {
		if ( ! is_array( $events ) ) {
			return array(
				'data'     => array(),
				'has_more' => false,
			);
		}

		$data = $events['data'] ?? array();

		return array(
			'data'     => is_array( $data ) ? array_values( array_filter( $data, 'is_array' ) ) : array(),
			'has_more' => (bool) ( $events['has_more'] ?? false ),
		);
	}
}
