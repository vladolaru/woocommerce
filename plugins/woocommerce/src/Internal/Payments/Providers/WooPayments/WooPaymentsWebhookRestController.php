<?php
/**
 * WooPaymentsWebhookRestController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
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
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * Event ingestor.
	 *
	 * @var WooPaymentsEventIngestor
	 */
	private WooPaymentsEventIngestor $event_ingestor;

	/**
	 * WooPayments legacy runtime.
	 *
	 * @var WooPaymentsLegacyRuntime
	 */
	private WooPaymentsLegacyRuntime $legacy_runtime;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter $arbiter        Runtime owner arbiter.
	 * @param WooPaymentsEventIngestor     $event_ingestor Event ingestor.
	 * @param WooPaymentsLegacyRuntime     $legacy_runtime WooPayments legacy runtime.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter, WooPaymentsEventIngestor $event_ingestor, WooPaymentsLegacyRuntime $legacy_runtime ): void {
		$this->arbiter        = $arbiter;
		$this->event_ingestor = $event_ingestor;
		$this->legacy_runtime = $legacy_runtime;
	}

	/**
	 * Register REST hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
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
	 * Authoritative ingestion path: native platform event ingestion flows through
	 * {@see WooPaymentsWebhookReliabilityService}, the authenticated pull path that fetches
	 * failed/missed events from the platform via the API client and replays them through the
	 * ingestor on Action Scheduler jobs. There is no shared webhook signing secret available to
	 * the store, so this route cannot (and must not) verify a signature; loosening the capability
	 * gate would turn it into an unverified event-injection surface. Keep the gate as-is.
	 */
	public function register_routes(): void {
		register_rest_route(
			'wc/v3',
			'/payments/webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				// Intentionally gated on `manage_woocommerce` (parity with the WooPayments plugin).
				// Real platform events arrive via the authenticated WooPaymentsWebhookReliabilityService
				// pull path; do not loosen this gate. See the method doc-block for the full rationale.
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
	 * the capability gate is the trust boundary, and the authenticated pull path
	 * ({@see WooPaymentsWebhookReliabilityService}) remains the authoritative ingestion route.
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

		try {
			$this->event_ingestor->process( is_array( $payload ) ? $payload : array() );
			return new WP_REST_Response( array( 'result' => 'success' ), 200 );
		} catch ( InvalidArgumentException $exception ) {
			$this->log_webhook_exception( $exception );
			return new WP_REST_Response( array( 'result' => 'bad_request' ), 400 );
		} catch ( Throwable $exception ) {
			$this->log_webhook_exception( $exception );
			return new WP_REST_Response( array( 'result' => 'error' ), 500 );
		}
	}

	/**
	 * Log a webhook processing exception.
	 *
	 * @param Throwable $exception Webhook processing exception.
	 */
	private function log_webhook_exception( Throwable $exception ): void {
		$logger = $this->legacy_runtime->get_logger();
		if ( ! is_object( $logger ) || ! is_callable( array( $logger, 'error' ) ) ) {
			return;
		}

		try {
			$logger->error(
				$exception->getMessage(),
				array(
					'source' => 'native-payments-webhook',
				)
			);
		} catch ( Throwable $logger_exception ) {
			unset( $logger_exception );
		}
	}
}
