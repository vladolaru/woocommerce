<?php
/**
 * HeldCaptureReturned class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Webhook;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\CaptureReader;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\HeldSettlement;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\HeldOrders;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\RequestHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\RequestHandlerTrait;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Settles a held order when PayPal reports its capture reversed, refunded or denied, from the capture's status read again
 * through the order's pinned app rather than from the event type alone.
 *
 * A returned capture cancels the order with its stock restored; a completed one completes it; a pending one changes
 * nothing. An order whose payment was already returned is taken too and left alone, so a second delivery of the event
 * never reaches the wallet's refund or cancel handlers.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class HeldCaptureReturned implements RequestHandler {
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
	 * The capture reader.
	 *
	 * @var CaptureReader
	 */
	private CaptureReader $reader;

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
	 * @param CaptureReader   $reader      The capture reader.
	 * @param LoggerInterface $logger      The logger.
	 */
	public function __construct( Guards $guards, HeldOrders $held_orders, HeldSettlement $settlement, CaptureReader $reader, LoggerInterface $logger ) {
		$this->guards      = $guards;
		$this->held_orders = $held_orders;
		$this->settlement  = $settlement;
		$this->reader      = $reader;
		$this->logger      = $logger;
	}

	/**
	 * {@inheritDoc}
	 */
	public function event_types(): array {
		return array( 'PAYMENT.CAPTURE.REVERSED', 'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.DENIED' );
	}

	/**
	 * Whether the event is for an order that is held, or whose held payment PayPal already returned.
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

		return null !== $order && ( $this->held_orders->is_held( $order ) || $this->settlement->is_returned( $order ) );
	}

	/**
	 * Re-read the capture and settle the order from its status, when the event is for the order's capture.
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
		if ( $this->settlement->is_returned( $order ) || ! $this->held_orders->is_held( $order ) ) {
			return $this->success_response();
		}

		try {
			$read    = $this->reader->read( $order );
			$outcome = $this->settlement->apply( $order, $read['capture'], $read['create_time'] );
		} catch ( Throwable $throwable ) {
			// The order stays held; the reconcile reads the capture again.
			return $this->failure_response( sprintf( 'Could not settle held WooCommerce order #%d: %s: %s', $order->get_id(), get_class( $throwable ), $throwable->getMessage() ) );
		}

		$this->logger->info( sprintf( 'Held WooCommerce order #%d settled from PayPal webhook event %s: %s.', $order->get_id(), (string) $request['id'], $outcome ) );

		return $this->success_response();
	}
}
