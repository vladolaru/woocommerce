<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\TransportLog;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\EarlyFraudWarningEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\EventIngestor;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\EventOrderResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\OtherChargeRecorder;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\PaymentIntentEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\RefundEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminMenuBadgeService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeeDetailsNoteController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\DisputeEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsNotificationEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRemoteNoteService;

/**
 * Shared fixtures for building event handlers used to wire a native WooPayments event ingestor.
 *
 * Used by {@see Webhooks\EventIngestorTest} and by the dedicated
 * {@see WooPaymentsAccountEventHandlerTest}, both of which construct a full ingestor to
 * exercise their target handler through the same production dispatch path.
 */
trait WooPaymentsEventHandlerTestTrait {

	/**
	 * Build a dispute event handler wired to the supplied API client.
	 *
	 * @param Client $api_client Native WooPayments API client.
	 * @return DisputeEventHandler
	 */
	private function create_dispute_event_handler( Client $api_client ): DisputeEventHandler {
		$handler = new DisputeEventHandler();
		$handler->init(
			$api_client,
			wc_get_container()->get( WooPaymentsDatabaseCache::class ),
			wc_get_container()->get( WooPaymentsOrderNoteService::class ),
			wc_get_container()->get( OrderPaymentLock::class ),
			wc_get_container()->get( WooPaymentsPersistenceVocabulary::class ),
			wc_get_container()->get( EventOrderResolver::class ),
			wc_get_container()->get( OtherChargeRecorder::class )
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
	 * The lifecycle service and the API client go to the payment intent event handler the ingestor routes to.
	 *
	 * @param OrderPaymentLifecycleService        $lifecycle_service                 Order lifecycle service.
	 * @param Client                              $api_client                        Native WooPayments API client.
	 * @param DisputeEventHandler                 $dispute_event_handler             Dispute event handler.
	 * @param RefundEventHandler                  $refund_event_handler              Refund event handler.
	 * @param WooPaymentsAccountEventHandler      $account_event_handler             Account event handler.
	 * @param WooPaymentsNotificationEventHandler $notification_event_handler        Notification event handler.
	 * @param EarlyFraudWarningEventHandler|null  $early_fraud_warning_event_handler Early fraud warning handler, or null for the container's.
	 * @return EventIngestor
	 */
	private function build_event_ingestor( OrderPaymentLifecycleService $lifecycle_service, Client $api_client, DisputeEventHandler $dispute_event_handler, RefundEventHandler $refund_event_handler, WooPaymentsAccountEventHandler $account_event_handler, WooPaymentsNotificationEventHandler $notification_event_handler, ?EarlyFraudWarningEventHandler $early_fraud_warning_event_handler = null ): EventIngestor {
		$container      = wc_get_container();
		$intent_handler = new PaymentIntentEventHandler();
		$intent_handler->init(
			$lifecycle_service,
			$api_client,
			$container->get( WooPaymentsOrderDataService::class ),
			$container->get( WooPaymentsAccountService::class ),
			$container->get( WooPaymentsOrderNoteService::class ),
			$container->get( WooPaymentsAdminMenuBadgeService::class ),
			$container->get( EventOrderResolver::class ),
			$container->get( OtherChargeRecorder::class ),
			$container->get( WooPaymentsFeeDetailsNoteController::class ),
			$container->get( OrderPaymentLock::class ),
			$container->get( WooPaymentsPersistenceVocabulary::class )
		);
		$ingestor = new EventIngestor();
		$ingestor->init(
			$container->get( TransportLog::class ),
			$dispute_event_handler,
			$refund_event_handler,
			$account_event_handler,
			$notification_event_handler,
			$container->get( WooPaymentsAccountService::class ),
			$early_fraud_warning_event_handler ?? $container->get( EarlyFraudWarningEventHandler::class ),
			$intent_handler
		);

		return $ingestor;
	}
}
