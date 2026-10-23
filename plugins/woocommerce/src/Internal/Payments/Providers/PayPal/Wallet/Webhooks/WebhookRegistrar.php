<?php

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks;

use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\WebhookEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\WebhookFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Status\WebhookSimulation;

/**
 * The WebhookRegistrar registers and unregisters webhooks with PayPal.
 */
class WebhookRegistrar {
	const EVENT_HOOK = 'ppcp-register-event';
	const KEY        = 'ppcp-webhook';

	/**
	 * The webhook factory.
	 *
	 * @var WebhookFactory
	 */
	private WebhookFactory $webhook_factory;

	/**
	 * The endpoint.
	 *
	 * @var WebhookEndpoint
	 */
	private WebhookEndpoint $endpoint;

	/**
	 * The incoming webhook endpoint.
	 *
	 * @var IncomingWebhookEndpoint
	 */
	private IncomingWebhookEndpoint $incoming_webhook_endpoint;

	/**
	 * The last webhook event storage.
	 *
	 * @var WebhookEventStorage
	 */
	private WebhookEventStorage $last_webhook_event_storage;

	/**
	 * The webhook simulation.
	 *
	 * @var WebhookSimulation
	 */
	private WebhookSimulation $webhook_simulation;

	/**
	 * The webhook orchestrator.
	 *
	 * @var WebhookOrchestrator
	 */
	private WebhookOrchestrator $webhook_orchestrator;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * The own webhook resolver.
	 *
	 * @var OwnWebhookResolver
	 */
	private OwnWebhookResolver $own_webhook_resolver;

	/**
	 * WebhookRegistrar constructor.
	 *
	 * @param WebhookFactory          $webhook_factory            The webhook factory.
	 * @param WebhookEndpoint         $endpoint                   The endpoint.
	 * @param IncomingWebhookEndpoint $incoming_webhook_endpoint  The incoming webhook endpoint.
	 * @param WebhookEventStorage     $last_webhook_event_storage The last webhook event storage.
	 * @param WebhookSimulation       $webhook_simulation         The webhook simulation.
	 * @param WebhookOrchestrator     $webhook_orchestrator       The webhook orchestrator.
	 * @param LoggerInterface         $logger                     The logger.
	 * @param OwnWebhookResolver      $own_webhook_resolver       The own webhook resolver.
	 */
	public function __construct(
		WebhookFactory $webhook_factory,
		WebhookEndpoint $endpoint,
		IncomingWebhookEndpoint $incoming_webhook_endpoint,
		WebhookEventStorage $last_webhook_event_storage,
		WebhookSimulation $webhook_simulation,
		WebhookOrchestrator $webhook_orchestrator,
		LoggerInterface $logger,
		OwnWebhookResolver $own_webhook_resolver
	) {

		$this->webhook_factory            = $webhook_factory;
		$this->endpoint                   = $endpoint;
		$this->incoming_webhook_endpoint  = $incoming_webhook_endpoint;
		$this->last_webhook_event_storage = $last_webhook_event_storage;
		$this->webhook_simulation         = $webhook_simulation;
		$this->webhook_orchestrator       = $webhook_orchestrator;
		$this->logger                     = $logger;
		$this->own_webhook_resolver       = $own_webhook_resolver;
	}

	/**
	 * Register Webhooks with PayPal.
	 *
	 * @return bool
	 */
	public function register(): bool {
		$result = $this->webhook_orchestrator->with_lock(
			'register',
			fn() => $this->do_register()
		);

		// If locked (null), treat as failure.
		return $result ?? false;
	}

	/**
	 * Unregister webhooks with PayPal.
	 */
	public function unregister(): void {
		$this->webhook_orchestrator->with_lock(
			'unregister',
			fn() => $this->do_unregister()
		);
	}

	/**
	 * Internal registration logic.
	 *
	 * @return bool
	 */
	private function do_register(): bool {
		$this->do_unregister();

		$webhook = $this->webhook_factory->for_url_and_events(
			$this->incoming_webhook_endpoint->url(),
			$this->incoming_webhook_endpoint->handled_event_types()
		);

		try {
			$created = $this->endpoint->create( $webhook );
			if ( empty( $created->id() ) ) {
				return false;
			}

			update_option(
				self::KEY,
				$created->to_array()
			);

			$this->last_webhook_event_storage->clear();

			// Check whether webhooks are arriving (e.g. for the Status page).
			$this->webhook_simulation->start( $created );

			$this->logger->info( 'Webhooks subscribed.' );
			return true;
		} catch ( RuntimeException $error ) {
			$this->logger->error( 'Failed to subscribe webhooks: ' . $error->getMessage() );
			return false;
		}
	}

	/**
	 * Internal unregister logic.
	 *
	 * Only webhooks that belong to this site are deleted. Webhooks registered for
	 * other sites or services on the same PayPal account are left untouched, so
	 * connecting a staging or secondary site to shared credentials no longer wipes
	 * the primary site's webhook. See GitHub issue #4604.
	 */
	private function do_unregister(): void {
		try {
			$webhooks = $this->endpoint->list();
			foreach ( $webhooks as $webhook ) {
				if ( ! $this->own_webhook_resolver->is_own( $webhook ) ) {
					$this->logger->warning(
						"Skipping deletion of webhook {$webhook->id()} ({$webhook->url()}): it belongs to a different site and is not managed by this install."
					);
					continue;
				}

				try {
					$this->endpoint->delete( $webhook );
				} catch ( RuntimeException $deletion_error ) {
					$this->logger->error( "Failed to delete webhook {$webhook->id()}: {$deletion_error->getMessage()}" );
				}
			}
		} catch ( RuntimeException $error ) {
			$this->logger->error( 'Failed to delete webhooks: ' . $error->getMessage() );
		}

		delete_option( self::KEY );
		$this->last_webhook_event_storage->clear();
		$this->logger->info( 'Webhooks deleted.' );
	}
}
