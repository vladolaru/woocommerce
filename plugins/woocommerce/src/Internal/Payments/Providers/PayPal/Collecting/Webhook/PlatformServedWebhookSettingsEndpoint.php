<?php
/**
 * PlatformServedWebhookSettingsEndpoint class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Webhook;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\WebhookEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\WebhookSettingsEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\IncomingWebhookEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\OwnWebhookResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Status\WebhookSimulation;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\WebhookRegistrar;
use Throwable;
use WP_REST_Response;

/**
 * The settings app's webhook status while the platform serves the store: the platform's subscriptions, from the stored
 * option, without a request to PayPal.
 *
 * The wallet's endpoint lists its app's webhooks with the merchant's token, which a store the platform serves does not
 * have, and reports "No webhooks found."; resubscribing would replace the platform's subscriptions. Here the status
 * reports the listener URL, the apps holding a subscription and `served_by_platform`, and resubscribing changes nothing
 * and returns the same status. Once the store stops being served, the wallet's own answers return.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class PlatformServedWebhookSettingsEndpoint extends WebhookSettingsEndpoint {

	/**
	 * The connection state.
	 *
	 * @var ConnectionState
	 */
	private ConnectionState $connection_state;

	/**
	 * The platform transport.
	 *
	 * @var PlatformTransport
	 */
	private PlatformTransport $transport;

	/**
	 * The incoming webhook endpoint, for the listener URL.
	 *
	 * @var IncomingWebhookEndpoint
	 */
	private IncomingWebhookEndpoint $incoming;

	/**
	 * Constructor.
	 *
	 * @param WebhookEndpoint         $webhook_endpoint     The webhook API endpoint.
	 * @param WebhookRegistrar        $webhook_registrar    The registrar.
	 * @param WebhookSimulation       $webhook_simulation   The simulation handler.
	 * @param OwnWebhookResolver      $own_webhook_resolver The own-webhook resolver.
	 * @param ConnectionState         $connection_state     The connection state.
	 * @param PlatformTransport       $transport            The platform transport.
	 * @param IncomingWebhookEndpoint $incoming             The incoming webhook endpoint.
	 */
	public function __construct(
		WebhookEndpoint $webhook_endpoint,
		WebhookRegistrar $webhook_registrar,
		WebhookSimulation $webhook_simulation,
		OwnWebhookResolver $own_webhook_resolver,
		ConnectionState $connection_state,
		PlatformTransport $transport,
		IncomingWebhookEndpoint $incoming
	) {
		parent::__construct( $webhook_endpoint, $webhook_registrar, $webhook_simulation, $own_webhook_resolver );

		$this->connection_state = $connection_state;
		$this->transport        = $transport;
		$this->incoming         = $incoming;
	}

	/**
	 * The webhook status: the platform's subscriptions while the platform serves the store, else the wallet's own.
	 *
	 * @return WP_REST_Response
	 */
	public function get_webhooks(): WP_REST_Response {
		if ( ! $this->connection_state->is_served_by_platform() ) {
			return parent::get_webhooks();
		}

		try {
			$apps = array_keys( $this->transport->webhook_subscriptions() );
		} catch ( Throwable $throwable ) {
			$apps = array();
		}

		return $this->return_success(
			array(
				'url'                => $this->incoming->url(),
				'events'             => array(),
				'served_by_platform' => true,
				'apps'               => $apps,
			)
		);
	}

	/**
	 * Resubscribe: nothing to do while the platform serves the store, which answers with the status; else the wallet's.
	 *
	 * @return WP_REST_Response
	 */
	public function resubscribe_webhooks(): WP_REST_Response {
		return $this->connection_state->is_served_by_platform() ? $this->get_webhooks() : parent::resubscribe_webhooks();
	}
}
