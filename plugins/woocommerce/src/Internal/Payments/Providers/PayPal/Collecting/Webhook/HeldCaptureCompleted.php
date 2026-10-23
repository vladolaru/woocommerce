<?php
/**
 * HeldCaptureCompleted class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Webhook;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\HeldSettlement;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\HeldOrders;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\RequestHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\RequestHandlerTrait;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Completes a held order when PayPal completes its capture: the wallet's own PAYMENT.CAPTURE.COMPLETED handler moves
 * the order on and the held meta is released, so there is one completion path.
 *
 * It takes only events for an order that is still held; any other order is left to the wallet's handler, so a second
 * delivery of the event finds the order released and changes nothing.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class HeldCaptureCompleted implements RequestHandler {
	use RequestHandlerTrait;

	/**
	 * The guards.
	 *
	 * @var Guards
	 */
	private Guards $guards;

	/**
	 * The held orders.
	 *
	 * @var HeldOrders
	 */
	private HeldOrders $held_orders;

	/**
	 * The held-order settlement.
	 *
	 * @var HeldSettlement
	 */
	private HeldSettlement $settlement;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param Guards          $guards      The guards.
	 * @param HeldOrders      $held_orders The held orders.
	 * @param HeldSettlement  $settlement  The held-order settlement.
	 * @param LoggerInterface $logger      The logger.
	 */
	public function __construct( Guards $guards, HeldOrders $held_orders, HeldSettlement $settlement, LoggerInterface $logger ) {
		$this->guards      = $guards;
		$this->held_orders = $held_orders;
		$this->settlement  = $settlement;
		$this->logger      = $logger;
	}

	/**
	 * {@inheritDoc}
	 */
	public function event_types(): array {
		return array( 'PAYMENT.CAPTURE.COMPLETED' );
	}

	/**
	 * Whether the event completes a capture of an order that is still held.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return bool
	 */
	public function responsible_for_request( WP_REST_Request $request ): bool {
		$resource = $request['resource'];
		if ( ! in_array( $request['event_type'], $this->event_types(), true ) || ! is_array( $resource ) ) {
			return false;
		}
		$order = $this->guards->order_for_event( $resource );

		return null !== $order && $this->held_orders->is_held( $order );
	}

	/**
	 * Release the held order and complete it, when the event is for its capture.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function handle_request( WP_REST_Request $request ): WP_REST_Response {
		$resource = $request['resource'];
		$order    = is_array( $resource ) ? $this->guards->order_for_event( $resource ) : null;
		if ( null === $order || ! $this->guards->capture_event_matches_order( (array) $resource, $order ) ) {
			$this->logger->info( sprintf( 'Ignored PayPal webhook event %s: its capture is not for a held order of this store.', (string) $request['id'] ) );
			return $this->success_response();
		}

		try {
			$outcome = $this->settlement->complete( $order, $request );
		} catch ( Throwable $throwable ) {
			// The order stays held; the reconcile reads the capture again.
			return $this->failure_response( sprintf( 'Could not complete held WooCommerce order #%d: %s: %s', $order->get_id(), get_class( $throwable ), $throwable->getMessage() ) );
		}

		$this->logger->info( sprintf( 'Held WooCommerce order #%d settled from PayPal webhook event %s: %s.', $order->get_id(), (string) $request['id'], $outcome ) );

		return $this->success_response();
	}
}
