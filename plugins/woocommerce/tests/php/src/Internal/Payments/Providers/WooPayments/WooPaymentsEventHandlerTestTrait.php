<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsEarlyFraudWarningEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsEventIngestor;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsEventOrderResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsOtherChargeRecorder;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsRefundEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminMenuBadgeService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDisputeCacheService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsDisputeEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsNotificationEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectApplier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRemoteNoteService;
use Automattic\WooCommerce\Proxies\LegacyProxy;

/**
 * Shared fixtures for building event handlers used to wire a native WooPayments event ingestor.
 *
 * Used by {@see Webhooks\WooPaymentsEventIngestorTest} and by the dedicated
 * {@see WooPaymentsAccountEventHandlerTest}, both of which construct a full ingestor to
 * exercise their target handler through the same production dispatch path.
 */
trait WooPaymentsEventHandlerTestTrait {

	/**
	 * Build a dispute event handler wired to the supplied API client.
	 *
	 * @param WooPaymentsApiClient $api_client Native WooPayments API client.
	 * @return WooPaymentsDisputeEventHandler
	 */
	private function create_dispute_event_handler( WooPaymentsApiClient $api_client ): WooPaymentsDisputeEventHandler {
		$handler = new WooPaymentsDisputeEventHandler();
		$handler->init(
			$api_client,
			wc_get_container()->get( WooPaymentsDisputeCacheService::class ),
			wc_get_container()->get( WooPaymentsOrderNoteService::class ),
			wc_get_container()->get( OrderPaymentLock::class ),
			wc_get_container()->get( WooPaymentsPersistenceVocabulary::class ),
			wc_get_container()->get( WooPaymentsEventOrderResolver::class ),
			wc_get_container()->get( WooPaymentsOtherChargeRecorder::class )
		);

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

	/**
	 * Build an event ingestor from the given handlers and collaborators, with the rest from the container.
	 *
	 * @param OrderPaymentLifecycleService                  $lifecycle_service                 Order lifecycle service.
	 * @param LegacyProxy                                   $legacy_proxy                      Legacy proxy.
	 * @param WooPaymentsApiClient                          $api_client                        Native WooPayments API client.
	 * @param WooPaymentsDisputeEventHandler                $dispute_event_handler             Dispute event handler.
	 * @param WooPaymentsRefundEventHandler                 $refund_event_handler              Refund event handler.
	 * @param WooPaymentsAccountEventHandler                $account_event_handler             Account event handler.
	 * @param WooPaymentsNotificationEventHandler           $notification_event_handler        Notification event handler.
	 * @param WooPaymentsEarlyFraudWarningEventHandler|null $early_fraud_warning_event_handler Early fraud warning handler, or null for the container's.
	 * @return WooPaymentsEventIngestor
	 */
	private function build_event_ingestor( OrderPaymentLifecycleService $lifecycle_service, LegacyProxy $legacy_proxy, WooPaymentsApiClient $api_client, WooPaymentsDisputeEventHandler $dispute_event_handler, WooPaymentsRefundEventHandler $refund_event_handler, WooPaymentsAccountEventHandler $account_event_handler, WooPaymentsNotificationEventHandler $notification_event_handler, ?WooPaymentsEarlyFraudWarningEventHandler $early_fraud_warning_event_handler = null ): WooPaymentsEventIngestor {
		$container = wc_get_container();
		$ingestor  = new WooPaymentsEventIngestor();
		$ingestor->init(
			$lifecycle_service,
			$legacy_proxy,
			$api_client,
			$dispute_event_handler,
			$refund_event_handler,
			$account_event_handler,
			$notification_event_handler,
			$container->get( WooPaymentsOrderDataService::class ),
			$container->get( WooPaymentsAccountService::class ),
			$container->get( WooPaymentsOrderEffectApplier::class ),
			$container->get( WooPaymentsOrderNoteService::class ),
			$container->get( WooPaymentsAdminMenuBadgeService::class ),
			$early_fraud_warning_event_handler ?? $container->get( WooPaymentsEarlyFraudWarningEventHandler::class ),
			$container->get( WooPaymentsEventOrderResolver::class ),
			$container->get( WooPaymentsOtherChargeRecorder::class )
		);

		return $ingestor;
	}
}
