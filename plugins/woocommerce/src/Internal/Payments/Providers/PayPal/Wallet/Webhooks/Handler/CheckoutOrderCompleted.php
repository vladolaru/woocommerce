<?php
/**
 * Handles the Webhook CHECKOUT.ORDER.COMPLETED
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler;

use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class CheckoutOrderCompleted
 */
class CheckoutOrderCompleted implements RequestHandler {

	use RequestHandlerTrait;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * CheckoutOrderCompleted constructor.
	 *
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	/**
	 * The event types a handler handles.
	 *
	 * @return string[]
	 */
	public function event_types(): array {
		return array(
			'CHECKOUT.ORDER.COMPLETED',
		);
	}

	/**
	 * Whether a handler is responsible for a given request or not.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return bool
	 */
	public function responsible_for_request( WP_REST_Request $request ): bool {
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
		$custom_ids = $this->get_wc_order_ids_from_request( $request );
		if ( empty( $custom_ids ) ) {
			return $this->no_custom_ids_response( $request );
		}

		$wc_orders = $this->get_wc_orders_from_custom_ids( $custom_ids );
		if ( ! $wc_orders ) {
			return $this->no_wc_orders_response( $request );
		}

		foreach ( $wc_orders as $wc_order ) {
			$wc_order->payment_complete();

			$this->logger->info(
				sprintf(
					'Order %s has been updated through PayPal',
					(string) $wc_order->get_id()
				)
			);
		}

		return $this->success_response();
	}
}
