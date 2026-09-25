<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDisputeCacheService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDisputeEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLegacyRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsNotificationEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRemoteNoteService;

/**
 * Shared fixtures for building event handlers used to wire a native WooPayments event ingestor.
 *
 * Used by {@see WooPaymentsEventIngestorTest} and by the dedicated
 * {@see WooPaymentsAccountEventHandlerTest}, both of which construct a full ingestor to
 * exercise their target handler through the same production dispatch path.
 */
trait WooPaymentsEventHandlerTestTrait {

	/**
	 * Build a dispute event handler wired to the supplied runtime and API client.
	 *
	 * @param WooPaymentsLegacyRuntime $runtime    WooPayments legacy runtime.
	 * @param WooPaymentsApiClient     $api_client Native WooPayments API client.
	 * @return WooPaymentsDisputeEventHandler
	 */
	private function create_dispute_event_handler( WooPaymentsLegacyRuntime $runtime, WooPaymentsApiClient $api_client ): WooPaymentsDisputeEventHandler {
		$handler = new WooPaymentsDisputeEventHandler();
		$handler->init( $runtime, $api_client, wc_get_container()->get( WooPaymentsDisputeCacheService::class ) );

		return $handler;
	}

	/**
	 * Build a notification event handler backed by a real remote note service.
	 *
	 * @return WooPaymentsNotificationEventHandler
	 */
	private function create_notification_event_handler(): WooPaymentsNotificationEventHandler {
		$handler = new WooPaymentsNotificationEventHandler();
		$handler->init( new WooPaymentsRemoteNoteService() );

		return $handler;
	}
}
