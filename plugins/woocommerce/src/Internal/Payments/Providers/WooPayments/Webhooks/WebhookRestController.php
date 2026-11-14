<?php
/**
 * WebhookRestController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use InvalidArgumentException;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Receives WooPayments webhook events from the platform on `wc/v3/payments/webhook` and passes them to the event ingestor.
 *
 * @since 11.0.0
 * @internal
 */
class WebhookRestController implements RegisterHooksInterface {

	/**
	 * Event ingestor.
	 *
	 * @var EventIngestor
	 */
	private EventIngestor $event_ingestor;

	/**
	 * Webhook reliability service, which retries events that fail to process.
	 *
	 * @var WebhookReliabilityService
	 */
	private WebhookReliabilityService $reliability_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param EventIngestor             $event_ingestor      Event ingestor.
	 * @param WebhookReliabilityService $reliability_service Webhook reliability service.
	 */
	final public function init( EventIngestor $event_ingestor, WebhookReliabilityService $reliability_service ): void {
		$this->event_ingestor      = $event_ingestor;
		$this->reliability_service = $reliability_service;
	}

	/**
	 * Register REST hooks.
	 *
	 * The route registers on its own rest_api_init callback, as in client 11.1.0 (class-wc-payments.php:860, :2360-2361),
	 * so a plugin that empties WooCommerce's list of REST controllers does not remove it.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the webhook route.
	 *
	 * The route requires `manage_woocommerce` and is not a public, unauthenticated delivery endpoint.
	 * Client 11.1.0 checks the same capability on it (class-wc-rest-payments-webhook-controller.php:64,
	 * class-wc-payments-rest-controller.php:63-65).
	 *
	 * The platform pushes each event to this route as a Jetpack-signed request made as the connection
	 * owner, so the request runs as an administrator. An event whose push fails in transport is kept
	 * by the platform and pulled later by {@see WebhookReliabilityService}. There is no
	 * shared webhook signing secret, so this route cannot verify a payload signature; the capability
	 * check is the trust boundary, and loosening it would let unverified events in.
	 */
	public function register_routes(): void {
		register_rest_route(
			'wc/v3',
			'/payments/webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				// Requires `manage_woocommerce`: platform pushes arrive signed as the connection owner.
				// Do not loosen this check; see the method docblock.
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
			)
		);
	}

	/**
	 * Handle a webhook delivery.
	 *
	 * Reached only after the `manage_woocommerce` check in {@see self::register_routes()}; with no shared
	 * signing secret, that check is the trust boundary. The platform takes any reply in the expected envelope,
	 * including an error, as delivered, so a failed event that is retried is queued here by the store.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response
	 */
	public function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_body_params();
		}

		$payload = is_array( $payload ) ? $payload : array();
		try {
			$this->event_ingestor->process( $payload );
			return new WP_REST_Response( array( 'result' => 'success' ), 200 );
		} catch ( InvalidArgumentException $exception ) {
			// The code that refuses an event logs nothing, so this one line names the event and keeps a refused event traceable.
			$event_id = is_scalar( $payload['id'] ?? null ) ? (string) $payload['id'] : '';
			$this->log_webhook_exception( $exception, $event_id, true );
			return new WP_REST_Response( array( 'result' => 'bad_request' ), 400 );
		} catch ( Throwable $exception ) {
			$this->log_webhook_exception( $exception );
			// The platform counts this reply as delivered, so the store retries the event itself.
			$this->reliability_service->retry_failed_event( $payload, $exception );
			return new WP_REST_Response( array( 'result' => 'error' ), 500 );
		}
	}

	/**
	 * Log a webhook processing exception.
	 *
	 * A refused event keeps its reason, as the pull path's line does ("Failed processing event {id}. Reason: {reason}"). Any other
	 * failure's message is left out, since processing calls the platform and its errors carry the platform's text; the class,
	 * code, trace and the platform's status and code are logged instead.
	 *
	 * @param Throwable $exception Webhook processing exception.
	 * @param string    $event_id  Event ID, when known.
	 * @param bool      $refused   Whether the ingestor refused the event (an InvalidArgumentException).
	 */
	private function log_webhook_exception( Throwable $exception, string $event_id = '', bool $refused = false ): void {
		wc_get_logger()->error(
			sprintf(
				'' === $event_id ? 'Failed processing a WooPayments webhook event.%2$s' : 'Failed processing event %1$s.%2$s',
				$event_id,
				$refused ? EventIngestor::get_refusal_reason_for_log( $exception ) : ''
			),
			array_merge( WooPaymentsLogger::get_failure_context( $exception ), array( 'source' => WooPaymentsLogger::SOURCE ) )
		);
	}
}
