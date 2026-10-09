<?php
/**
 * ForeignEventGuard class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Webhook;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\RequestHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\RequestHandlerTrait;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Answers another store's capture and checkout events with success and does nothing with them.
 *
 * The platform apps' subscriptions receive every event of their app, for every store. The wallet's own handlers find the
 * order by the event's custom ID, so another store's event whose custom ID happens to be a local order ID would act on
 * that order. This handler runs first and takes such an event: one that names no wallet order of this store, or names
 * one whose PayPal order or capture is not the event's. A checkout event that names only a cart session is left to the
 * wallet, which matches it to its own session. An event for a pending order that has no PayPal order ID saved yet is
 * answered with a 503, so PayPal retries it.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class ForeignEventGuard implements RequestHandler {
	use RequestHandlerTrait;

	/**
	 * The guards.
	 *
	 * @var Guards
	 */
	private Guards $guards;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param Guards          $guards The guards.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct( Guards $guards, LoggerInterface $logger ) {
		$this->guards = $guards;
		$this->logger = $logger;
	}

	/**
	 * {@inheritDoc}
	 */
	public function event_types(): array {
		return array_merge( Guards::CAPTURE_EVENTS, Guards::CHECKOUT_EVENTS );
	}

	/**
	 * Whether the event is a capture or checkout event that is not this store's.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return bool
	 */
	public function responsible_for_request( WP_REST_Request $request ): bool {
		$event_type     = $request['event_type'];
		$event_resource = $request['resource'];
		if ( ! in_array( $event_type, $this->event_types(), true ) || ! is_array( $event_resource ) ) {
			return false;
		}

		$order = $this->guards->order_for_event( $event_resource );
		if ( null === $order ) {
			// A checkout event for an order not created yet names only the buyer's cart session.
			return ! ( in_array( $event_type, Guards::CHECKOUT_EVENTS, true ) && ! $this->names_an_order( $event_resource ) );
		}

		return in_array( $event_type, Guards::CHECKOUT_EVENTS, true )
			? ! $this->guards->checkout_event_matches_order( $event_resource, $order )
			: ! $this->guards->capture_event_matches_order( $event_resource, $order );
	}

	/**
	 * Answer another store's event with success, changing nothing; ask PayPal to retry an event for one of this store's
	 * orders that does not hold its PayPal order ID yet.
	 *
	 * The wallet keeps the PayPal order ID in memory during the capture and saves it afterwards, so a capture event can
	 * arrive for a pending order before the ID is saved. Dropping it would leave the order pending if the checkout
	 * request then dies; a 503 makes PayPal deliver it again, by which time the ID is saved. An order whose saved ID
	 * differs is another store's and is dropped.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function handle_request( WP_REST_Request $request ): WP_REST_Response {
		$event_resource = $request['resource'];
		$order          = is_array( $event_resource ) ? $this->guards->order_for_event( $event_resource ) : null;
		if ( null !== $order && $this->guards->awaits_paypal_order_id( $order ) ) {
			$message = sprintf( 'PayPal webhook event %s names WooCommerce order #%d, which has no PayPal order ID yet: PayPal is asked to retry.', (string) $request['id'], $order->get_id() );
			$this->logger->info( $message );

			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $message,
				),
				503
			);
		}

		$this->logger->info( sprintf( 'Ignored PayPal webhook event %s of type %s: it is not for an order of this store.', (string) $request['id'], (string) $request['event_type'] ) );

		return $this->success_response();
	}

	/**
	 * Whether a checkout resource names a WooCommerce order: a custom ID that is an order ID.
	 *
	 * @param array $event_resource The event resource.
	 * @return bool
	 */
	private function names_an_order( array $event_resource ): bool {
		$custom_ids = array( $event_resource['custom_id'] ?? '' );
		foreach ( (array) ( $event_resource['purchase_units'] ?? array() ) as $unit ) {
			$custom_ids[] = is_array( $unit ) ? ( $unit['custom_id'] ?? '' ) : '';
		}
		foreach ( $custom_ids as $custom_id ) {
			if ( is_string( $custom_id ) && '' !== $custom_id && ctype_digit( $custom_id ) ) {
				return true;
			}
		}

		return false;
	}
}
