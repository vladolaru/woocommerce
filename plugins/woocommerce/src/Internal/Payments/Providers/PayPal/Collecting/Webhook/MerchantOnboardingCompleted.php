<?php
/**
 * MerchantOnboardingCompleted class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Webhook;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\RequestHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\RequestHandlerTrait;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Completes the collecting state when the store's merchant finishes PayPal onboarding.
 *
 * The event only says onboarding finished for a tracking ID; the seller status decides. The state completes only when
 * the seller can receive payments, granted consent and confirmed the primary email, since PayPal keeps holding the money
 * until the email is confirmed. Another store's event, or an incomplete seller, changes nothing.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class MerchantOnboardingCompleted implements RequestHandler {
	use RequestHandlerTrait;

	/**
	 * The guards.
	 *
	 * @var Guards
	 */
	private Guards $guards;

	/**
	 * The collecting state.
	 *
	 * @var CollectingState
	 */
	private CollectingState $state;

	/**
	 * The platform transport.
	 *
	 * @var PlatformTransport
	 */
	private PlatformTransport $transport;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param Guards            $guards    The guards.
	 * @param CollectingState   $state     The collecting state.
	 * @param PlatformTransport $transport The platform transport.
	 * @param LoggerInterface   $logger    The logger.
	 */
	public function __construct( Guards $guards, CollectingState $state, PlatformTransport $transport, LoggerInterface $logger ) {
		$this->guards    = $guards;
		$this->state     = $state;
		$this->transport = $transport;
		$this->logger    = $logger;
	}

	/**
	 * {@inheritDoc}
	 */
	public function event_types(): array {
		return array( 'MERCHANT.ONBOARDING.COMPLETED' );
	}

	/**
	 * Whether the event is an onboarding-completed event.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return bool
	 */
	public function responsible_for_request( WP_REST_Request $request ): bool {
		return in_array( $request['event_type'], $this->event_types(), true );
	}

	/**
	 * Complete the collecting state when the event carries the store's tracking ID and the seller status is complete.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function handle_request( WP_REST_Request $request ): WP_REST_Response {
		$resource    = $request['resource'];
		$tracking_id = $this->state->tracking_id();
		if ( ! $this->state->is_collecting() || ! is_array( $resource ) || ! $this->guards->onboarding_event_is_ours( $resource, $tracking_id ) ) {
			$this->logger->info( sprintf( 'Ignored PayPal webhook event %s: it is not the onboarding of this collecting store.', (string) $request['id'] ) );
			return $this->success_response();
		}

		try {
			$status = $this->transport->seller_status( $tracking_id );
			if ( ! $status->is_complete() ) {
				$this->logger->info( 'PayPal onboarding finished, but the seller cannot receive payments yet: the collecting state continues.' );
				return $this->success_response();
			}
			$this->state->complete( $status->merchant_id() );
		} catch ( Throwable $throwable ) {
			return $this->failure_response( sprintf( 'Could not complete the PayPal wallet collecting state: %s: %s', get_class( $throwable ), $throwable->getMessage() ) );
		}

		$this->logger->info( 'PayPal onboarding complete: the store is platform connected.' );

		return $this->success_response();
	}
}
