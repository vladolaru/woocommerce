<?php
/**
 * HeldCapturePending class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Webhook;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\HeldCapture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\HeldSettlement;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\RefundLock;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\CaptureFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\RequestHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\RequestHandlerTrait;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use stdClass;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Records a capture PayPal holds for the payee from PAYMENT.CAPTURE.PENDING, for an order the checkout request did not
 * record, as when it died between PayPal's capture answer and the order update.
 *
 * It takes a pending capture whose reason is a hold for the payee (UNILATERAL or PAYEE_SETUP_PENDING) and runs the same
 * bookkeeping as the checkout: the held meta, the held note and the first order, and puts a pending or failed order on
 * hold. An order that already settled (paid, or returned) is left alone, so a late or retried delivery holds nothing.
 * The wallet's own pending handler, and its "waiting for the buyer" note, keep every other pending capture.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class HeldCapturePending implements RequestHandler {
	use RequestHandlerTrait;

	/**
	 * The status-details reasons that mean PayPal holds the payment for the payee.
	 */
	private const HELD_REASONS = array( 'UNILATERAL', HeldCapture::REASON_PAYEE_SETUP_PENDING );

	/**
	 * The statuses of an order whose payment has not settled: waiting for payment, failed by a checkout that threw after
	 * PayPal's answer, or already on hold.
	 */
	private const UNSETTLED_STATUSES = array( OrderStatus::PENDING, OrderStatus::FAILED, OrderStatus::ON_HOLD );

	/**
	 * The guards.
	 *
	 * @var Guards
	 */
	private Guards $guards;

	/**
	 * The held-capture bookkeeping.
	 *
	 * @var HeldCapture
	 */
	private HeldCapture $held_capture;

	/**
	 * The wallet's capture factory.
	 *
	 * @var CaptureFactory
	 */
	private CaptureFactory $capture_factory;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param Guards          $guards          The guards.
	 * @param HeldCapture     $held_capture    The held-capture bookkeeping.
	 * @param CaptureFactory  $capture_factory The wallet's capture factory.
	 * @param LoggerInterface $logger          The logger.
	 */
	public function __construct( Guards $guards, HeldCapture $held_capture, CaptureFactory $capture_factory, LoggerInterface $logger ) {
		$this->guards          = $guards;
		$this->held_capture    = $held_capture;
		$this->capture_factory = $capture_factory;
		$this->logger          = $logger;
	}

	/**
	 * {@inheritDoc}
	 */
	public function event_types(): array {
		return array( 'PAYMENT.CAPTURE.PENDING' );
	}

	/**
	 * Whether the event is a capture PayPal holds for the payee, for a wallet order of this store.
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
		if ( ! in_array( $resource['status_details']['reason'] ?? null, self::HELD_REASONS, true ) ) {
			return false;
		}

		return null !== $this->guards->order_for_event( $resource );
	}

	/**
	 * Record the held capture on its order, through the same action the checkout fires, and put a pending or failed order
	 * on hold. A settled order is left alone.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function handle_request( WP_REST_Request $request ): WP_REST_Response {
		$resource = $request['resource'];
		$order    = is_array( $resource ) ? $this->guards->order_for_event( $resource ) : null;
		if ( null === $order || ! $this->guards->capture_event_matches_order( (array) $resource, $order ) ) {
			$this->logger->info( sprintf( 'Ignored PayPal webhook event %s: its capture is not for a wallet order of this store.', (string) $request['id'] ) );
			return $this->success_response();
		}
		if ( ! empty( $order->get_meta( RefundLock::HELD_CAPTURE_META_KEY, true ) ) ) {
			// The checkout request recorded it.
			return $this->success_response();
		}
		// A late or retried delivery for an order that already settled (paid, or returned and cancelled) holds nothing.
		if ( '' !== (string) $order->get_meta( HeldSettlement::RETURNED_META_KEY, true ) || ! $order->has_status( self::UNSETTLED_STATUSES ) ) {
			$this->logger->info( sprintf( 'Ignored PayPal webhook event %1$s: WooCommerce order #%2$d has already settled.', (string) $request['id'], $order->get_id() ) );
			return $this->success_response();
		}

		try {
			$data = json_decode( (string) wp_json_encode( $resource ) );
			if ( ! $data instanceof stdClass ) {
				return $this->failure_response( 'The pending capture could not be read.' );
			}
			$data->final_capture = $data->final_capture ?? false;
			$capture             = $this->capture_factory->from_paypal_response( $data );

			/** This action is documented in src/Internal/Payments/Providers/PayPal/Wallet/WcGateway/Processor/PaymentsStatusHandlingTrait.php */
			do_action( 'woocommerce_paypal_wallet_capture_pending', $order, $capture ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingSinceComment

			// The checkout's bookkeeping used this request's time; PayPal's create time can only move the deadline earlier.
			$created = is_string( $resource['create_time'] ?? null ) ? strtotime( $resource['create_time'] ) : false;
			if ( false !== $created && $created > 0 ) {
				$this->held_capture->record( $order, $capture, $created );
			}
			if ( $order->has_status( array( OrderStatus::PENDING, OrderStatus::FAILED ) ) ) {
				$order->update_status( OrderStatus::ON_HOLD );
			}
		} catch ( Throwable $throwable ) {
			return $this->failure_response( sprintf( 'Could not record the held capture of WooCommerce order #%d: %s: %s', $order->get_id(), get_class( $throwable ), $throwable->getMessage() ) );
		}

		$this->logger->info( sprintf( 'Held capture of WooCommerce order #%d recorded from PayPal webhook event %s.', $order->get_id(), (string) $request['id'] ) );

		return $this->success_response();
	}
}
