<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Webhook;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\HeldCapture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\OrderPin;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\RefundLock;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use WC_Helper_Product;
use WC_Order;
use WC_Product;
use WP_Error;
use WP_REST_Request;

/**
 * The store states, orders, webhook requests and PayPal capture answers the collecting webhook and reconcile tests share.
 */
trait WebhookFixtures {

	/**
	 * The product the held orders buy, with managed stock.
	 *
	 * @var WC_Product|null
	 */
	private ?WC_Product $stocked_product = null;

	/**
	 * Put the store in the collecting state.
	 */
	private function set_collecting(): void {
		$this->set_wallet_option(
			Options::COLLECTING,
			array(
				'payee_email' => 'payee@example.com',
				'tracking_id' => 'TRACK-OURS',
				'environment' => 'sandbox',
				'payee_bound' => true,
			)
		);
	}

	/**
	 * Put the store in the platform-connected state.
	 */
	private function set_platform_connected(): void {
		$this->set_wallet_option(
			Options::PLATFORM,
			array(
				'merchant_id' => 'M-CONNECTED',
				'tracking_id' => 'TRACK-OURS',
				'payee_email' => 'payee@example.com',
				'environment' => 'sandbox',
			)
		);
	}

	/**
	 * The product the held orders buy: 10 in stock, stock managed.
	 *
	 * @return WC_Product
	 */
	private function stocked_product(): WC_Product {
		if ( null === $this->stocked_product ) {
			update_option( 'woocommerce_manage_stock', 'yes' );
			$this->stocked_product = WC_Helper_Product::create_simple_product();
			$this->stocked_product->set_manage_stock( true );
			$this->stocked_product->set_stock_quantity( 10 );
			$this->stocked_product->save();
		}

		return $this->stocked_product;
	}

	/**
	 * The stock of the product the held orders buy, read fresh.
	 *
	 * @return int
	 */
	private function stock(): int {
		return (int) wc_get_product( $this->stocked_product()->get_id() )->get_stock_quantity();
	}

	/**
	 * A wallet order for two of the stocked product, put on hold (which reduces the stock), with a PayPal order ID and
	 * a capture ID, optionally pinned and held.
	 *
	 * @param string      $suffix The suffix of the PayPal order and capture IDs.
	 * @param string|null $app    The pinned app, or null for no pin.
	 * @param bool        $held   Whether the order carries the held meta.
	 * @return WC_Order
	 */
	private function wallet_order( string $suffix = '1', ?string $app = PlatformTransport::APP_MERCHANT_APP, bool $held = true ): WC_Order {
		$order = wc_create_order();
		$order->add_product( $this->stocked_product(), 2 );
		$order->set_payment_method( PayPalGateway::ID );
		$order->calculate_totals();
		$order->update_meta_data( PayPalGateway::ORDER_ID_META_KEY, 'PP-ORDER-' . $suffix );
		$order->set_transaction_id( 'CAPTURE-' . $suffix );
		if ( null !== $app ) {
			OrderPin::record( $order, $app );
		}
		$order->save();
		$order->update_status( OrderStatus::ON_HOLD );

		if ( $held ) {
			$order->update_meta_data( RefundLock::HELD_CAPTURE_META_KEY, 'UNILATERAL' );
			$order->update_meta_data( HeldCapture::HELD_AT_META_KEY, (string) ( time() - HOUR_IN_SECONDS ) );
			$order->update_meta_data( HeldCapture::CAPTURE_ID_META_KEY, 'CAPTURE-' . $suffix );
			$order->save();
		}

		return wc_get_order( $order->get_id() );
	}

	/**
	 * A capture resource of a webhook event for an order.
	 *
	 * @param WC_Order $order           The order.
	 * @param string   $paypal_order_id The related PayPal order ID, or an empty string for none.
	 * @param string   $status          The capture status.
	 * @return array
	 */
	private function capture_resource( WC_Order $order, string $paypal_order_id, string $status = 'COMPLETED' ): array {
		$event_resource = array(
			'id'        => $order->get_transaction_id(),
			'status'    => $status,
			'custom_id' => (string) $order->get_id(),
			'amount'    => array(
				'currency_code' => 'USD',
				'value'         => '20.00',
			),
		);
		if ( '' !== $paypal_order_id ) {
			$event_resource['supplementary_data'] = array( 'related_ids' => array( 'order_id' => $paypal_order_id ) );
		}

		return $event_resource;
	}

	/**
	 * A refund resource of a webhook event for an order's capture: no related IDs, the capture behind the "up" link.
	 *
	 * @param WC_Order $order      The order.
	 * @param string   $capture_id The refunded capture.
	 * @return array
	 */
	private function refund_resource( WC_Order $order, string $capture_id ): array {
		return array(
			'id'        => 'REFUND-1',
			'status'    => 'COMPLETED',
			'custom_id' => (string) $order->get_id(),
			'amount'    => array(
				'currency_code' => 'USD',
				'value'         => '20.00',
			),
			'links'     => array(
				array(
					'rel'  => 'self',
					'href' => 'https://api.sandbox.paypal.com/v2/payments/refunds/REFUND-1',
				),
				array(
					'rel'  => 'up',
					'href' => 'https://api.sandbox.paypal.com/v2/payments/captures/' . $capture_id,
				),
			),
		);
	}

	/**
	 * A webhook request as PayPal delivers it, with JSON body and signature headers.
	 *
	 * @param string $event_type The event type.
	 * @param array  $event_resource   The resource.
	 * @param string $event_id   The event ID.
	 * @return WP_REST_Request
	 */
	private function event_request( string $event_type, array $event_resource, string $event_id = 'WH-EVENT-1' ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/paypal/v1/incoming' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'PAYPAL-TRANSMISSION-ID', 'TX-1' );
		$request->set_header( 'PAYPAL-AUTH-ALGO', 'SHA256withRSA' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'id'            => $event_id,
					'create_time'   => '2026-10-09T11:01:25.611Z',
					'resource_type' => 'capture',
					'event_type'    => $event_type,
					'summary'       => 'An event',
					'resource'      => $event_resource,
				)
			)
		);

		return $request;
	}

	/**
	 * The JSON of a PayPal capture as GET /v2/payments/captures/{id} answers it.
	 *
	 * @param string      $id          The capture ID.
	 * @param string      $status      The status.
	 * @param string|null $reason      The status-details reason, or null for none.
	 * @param string      $create_time The create time.
	 * @return string
	 */
	private function capture_json( string $id, string $status, ?string $reason = null, string $create_time = '2026-10-01T10:00:00Z' ): string {
		$capture = array(
			'id'            => $id,
			'status'        => $status,
			'amount'        => array(
				'currency_code' => 'USD',
				'value'         => '20.00',
			),
			'final_capture' => true,
			'create_time'   => $create_time,
		);
		if ( null !== $reason ) {
			$capture['status_details'] = array( 'reason' => $reason );
		}

		return (string) wp_json_encode( $capture );
	}

	/**
	 * Answer capture reads from a map of JSON bodies by capture ID; every other request is refused.
	 *
	 * @param array<string, string> $captures The capture JSON by capture ID.
	 */
	private function stub_captures( array $captures ): void {
		$this->stub_http(
			function ( $request, $url ) use ( $captures ) {
				unset( $request );
				foreach ( $captures as $id => $json ) {
					if ( false !== strpos( $url, '/v2/payments/captures/' . $id ) ) {
						return $this->http_response( 200, $json );
					}
				}

				return new WP_Error( 'unrouted', "Unrouted $url" );
			}
		);
	}

	/**
	 * The recorded capture reads.
	 *
	 * @return array[]
	 */
	private function capture_reads(): array {
		return array_values(
			array_filter(
				$this->http_requests,
				static function ( array $entry ): bool {
					return false !== strpos( $entry['url'], '/v2/payments/captures/' );
				}
			)
		);
	}

	/**
	 * The text of an order's notes.
	 *
	 * @param WC_Order $order The order.
	 * @return string[]
	 */
	private function notes( WC_Order $order ): array {
		return array_map(
			static function ( $note ): string {
				return $note->content;
			},
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) )
		);
	}

	/**
	 * How many of an order's notes are exactly a text.
	 *
	 * @param WC_Order $order The order.
	 * @param string   $text  The text.
	 * @return int
	 */
	private function count_notes( WC_Order $order, string $text ): int {
		return count( array_keys( $this->notes( $order ), $text, true ) );
	}

	/**
	 * Whether an order still carries any of the held metas, read fresh.
	 *
	 * @param WC_Order $order The order.
	 * @return bool
	 */
	private function has_held_meta( WC_Order $order ): bool {
		$fresh = wc_get_order( $order->get_id() );
		foreach ( array( RefundLock::HELD_CAPTURE_META_KEY, HeldCapture::HELD_AT_META_KEY, HeldCapture::CAPTURE_ID_META_KEY ) as $key ) {
			if ( '' !== (string) $fresh->get_meta( $key, true ) ) {
				return true;
			}
		}

		return false;
	}
}
