<?php
/**
 * PlatformServedWebhookRegistrar class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Webhook;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\WebhookEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\WebhookFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\IncomingWebhookEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\OwnWebhookResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Status\WebhookSimulation;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\WebhookEventStorage;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\WebhookOrchestrator;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\WebhookRegistrar;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;

/**
 * The wallet's webhook registrar, doing nothing while the platform serves the store.
 *
 * The wallet's registrar deletes every webhook of its app that points at this store's listener and creates its own,
 * stored in `ppcp-webhook`. While the platform serves the store, the transport's subscriptions are the store's webhooks:
 * the registrar must neither delete them nor write `ppcp-webhook`. Registering then reports success, as the wallet's
 * "is registered" flag already does for such a store; unregistering does nothing. Once the store stops being served,
 * for example after a first-party connection in the same request, every call goes to the wallet's registrar.
 *
 * It extends the wallet's class because the wallet's consumers type and assert it.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class PlatformServedWebhookRegistrar extends WebhookRegistrar {

	/**
	 * The wallet's registrar.
	 *
	 * @var WebhookRegistrar
	 */
	private WebhookRegistrar $registrar;

	/**
	 * The connection state.
	 *
	 * @var ConnectionState
	 */
	private ConnectionState $connection_state;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $served_logger;

	/**
	 * Constructor. The wallet's collaborators go to the parent's constructor; none of the parent's methods is called.
	 *
	 * @param WebhookRegistrar        $registrar                  The wallet's registrar.
	 * @param ConnectionState         $connection_state           The connection state.
	 * @param WebhookFactory          $webhook_factory            The webhook factory.
	 * @param WebhookEndpoint         $endpoint                   The webhook API endpoint.
	 * @param IncomingWebhookEndpoint $incoming_webhook_endpoint  The incoming webhook endpoint.
	 * @param WebhookEventStorage     $last_webhook_event_storage The last webhook event storage.
	 * @param WebhookSimulation       $webhook_simulation         The simulation handler.
	 * @param WebhookOrchestrator     $webhook_orchestrator       The orchestrator.
	 * @param LoggerInterface         $logger                     The logger.
	 * @param OwnWebhookResolver      $own_webhook_resolver       The own-webhook resolver.
	 */
	public function __construct(
		WebhookRegistrar $registrar,
		ConnectionState $connection_state,
		WebhookFactory $webhook_factory,
		WebhookEndpoint $endpoint,
		IncomingWebhookEndpoint $incoming_webhook_endpoint,
		WebhookEventStorage $last_webhook_event_storage,
		WebhookSimulation $webhook_simulation,
		WebhookOrchestrator $webhook_orchestrator,
		LoggerInterface $logger,
		OwnWebhookResolver $own_webhook_resolver
	) {
		parent::__construct( $webhook_factory, $endpoint, $incoming_webhook_endpoint, $last_webhook_event_storage, $webhook_simulation, $webhook_orchestrator, $logger, $own_webhook_resolver );

		$this->registrar        = $registrar;
		$this->connection_state = $connection_state;
		$this->served_logger    = $logger;
	}

	/**
	 * Register the wallet's webhook, or report the platform's subscriptions as registered while the platform serves the
	 * store, without any request.
	 *
	 * @return bool
	 */
	public function register(): bool {
		if ( $this->connection_state->is_served_by_platform() ) {
			$this->served_logger->info( 'Webhook registration skipped: the platform subscriptions serve this store.' );
			return true;
		}

		return $this->registrar->register();
	}

	/**
	 * Unregister the wallet's webhook, or do nothing while the platform serves the store.
	 */
	public function unregister(): void {
		if ( $this->connection_state->is_served_by_platform() ) {
			$this->served_logger->info( 'Webhook unregistration skipped: the platform subscriptions serve this store.' );
			return;
		}

		$this->registrar->unregister();
	}
}
