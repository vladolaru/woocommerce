<?php
/**
 * Handles the Webhook PAYMENT.CAPTURE.PENDING
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class PaymentCaptureCompleted
 */
class PaymentCapturePending implements RequestHandler {

	use RequestHandlerTrait;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * PaymentCaptureCompleted constructor.
	 *
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		LoggerInterface $logger
	) {
		$this->logger = $logger;
	}

	/**
	 * The event types a handler handles.
	 *
	 * @return string[]
	 */
	public function event_types(): array {
		return array( 'PAYMENT.CAPTURE.PENDING' );
	}

	/**
	 * Whether a handler is responsible for a given request or not.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return bool
	 */
	public function responsible_for_request( \WP_REST_Request $request ): bool {
		return in_array( $request['event_type'], $this->event_types(), true );
	}

	/**
	 * Responsible for handling the request.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return WP_REST_Response
	 */
	public function handle_request( WP_REST_Request $request ): WP_REST_Response {
		$order_id = null !== $request['resource'] && isset( $request['resource']['custom_id'] )
			? $request['resource']['custom_id']
			: 0;
		if ( ! $order_id ) {
			$message = sprintf(
				'No order for webhook event %s was found.',
				null !== $request['id'] && isset( $request['id'] ) ? $request['id'] : ''
			);
			return $this->failure_response( $message );
		}

		$resource = $request['resource'];
		if ( ! is_array( $resource ) ) {
			$message = 'Resource data not found in webhook request.';
			return $this->failure_response( $message );
		}

		$wc_order = wc_get_order( $order_id );
		if ( ! ( $wc_order instanceof \WC_Order ) ) {
			$message = sprintf(
				'WC order for PayPal ID %s not found.',
				null !== $request['resource'] && isset( $request['resource']['id'] ) ? $request['resource']['id'] : ''
			);

			return $this->failure_response( $message );
		}

		if ( $wc_order->get_status() === OrderStatus::PENDING ) {
			$wc_order->update_status( OrderStatus::ON_HOLD, __( 'Payment initiation was successful, and is waiting for the buyer to complete the payment.', 'woocommerce' ) );

		}

		return $this->success_response();
	}
}
