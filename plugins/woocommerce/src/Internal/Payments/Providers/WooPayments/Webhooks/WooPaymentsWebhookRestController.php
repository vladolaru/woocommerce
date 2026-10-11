<?php
/**
 * WooPaymentsWebhookRestController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use InvalidArgumentException;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Native WooPayments webhook REST controller.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsWebhookRestController implements RegisterHooksInterface {

	/**
	 * Runtime owner arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $arbiter;

	/**
	 * Event ingestor.
	 *
	 * @var WooPaymentsEventIngestor
	 */
	private WooPaymentsEventIngestor $event_ingestor;

	/**
	 * Webhook reliability service, which retries events that fail to process.
	 *
	 * @var WooPaymentsWebhookReliabilityService
	 */
	private WooPaymentsWebhookReliabilityService $reliability_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsRuntimeArbiter            $arbiter             Runtime owner arbiter.
	 * @param WooPaymentsEventIngestor             $event_ingestor      Event ingestor.
	 * @param WooPaymentsWebhookReliabilityService $reliability_service Webhook reliability service.
	 */
	final public function init( WooPaymentsRuntimeArbiter $arbiter, WooPaymentsEventIngestor $event_ingestor, WooPaymentsWebhookReliabilityService $reliability_service ): void {
		$this->arbiter             = $arbiter;
		$this->event_ingestor      = $event_ingestor;
		$this->reliability_service = $reliability_service;
	}

	/**
	 * Register REST hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		if ( false === has_action( 'rest_api_init', array( $this, 'register_routes' ) ) ) {
			add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		}
	}

	/**
	 * Register the WooPayments-compatible webhook route.
	 *
	 * Auth model: this route is intentionally gated on `manage_woocommerce` and is NOT a public,
	 * unauthenticated delivery endpoint. It mirrors the WooPayments plugin's webhook controller,
	 * which gates the same `wc/v3/payments/webhook` route on `current_user_can( 'manage_woocommerce' )`
	 * (see WC_REST_Payments_Webhook_Controller / WC_Payments_REST_Controller::check_permission()).
	 *
	 * The platform pushes each event to this route as a Jetpack-signed request made as the connection
	 * owner, so the request runs as an administrator. An event whose push fails in transport is kept
	 * by the platform and pulled later by {@see WooPaymentsWebhookReliabilityService}. There is no
	 * shared webhook signing secret, so this route cannot verify a payload signature; the capability
	 * gate is the trust boundary, and loosening it would open an unverified event-injection surface.
	 */
	public function register_routes(): void {
		register_rest_route(
			'wc/v3',
			'/payments/webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				// Intentionally gated on `manage_woocommerce` (parity with the WooPayments plugin): platform
				// pushes arrive signed as the connection owner. Do not loosen this gate; see the method doc-block.
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
			)
		);
	}

	/**
	 * Handle a webhook delivery.
	 *
	 * Reached only after the `manage_woocommerce` permission gate in {@see self::register_routes()}.
	 * The payload is not signature-verified because no shared signing secret exists for this route;
	 * the capability gate is the trust boundary. The platform takes any reply in the expected envelope,
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
			// The refusing code logs nothing, as on the client; this one line names the event so a refused event stays traceable.
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
		try {
			wc_get_logger()->error(
				sprintf(
					'' === $event_id ? 'Failed processing a WooPayments webhook event.%2$s' : 'Failed processing event %1$s.%2$s',
					$event_id,
					$refused ? WooPaymentsEventIngestor::get_refusal_reason_for_log( $exception ) : ''
				),
				array_merge( WooPaymentsLogger::get_failure_context( $exception ), array( 'source' => WooPaymentsLogger::SOURCE ) )
			);
		} catch ( Throwable $logger_exception ) {
			unset( $logger_exception );
		}
	}
}
