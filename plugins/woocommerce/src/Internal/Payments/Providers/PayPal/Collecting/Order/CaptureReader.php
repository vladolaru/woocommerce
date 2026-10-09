<?php
/**
 * CaptureReader class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\OrderAppContext;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\RequestTrait;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Capture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\PayPalApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\CaptureFactory;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use stdClass;
use WC_Order;

/**
 * Reads an order's capture from PayPal through the app the order is pinned to.
 *
 * A PayPal order, and its captures, answer only the app that created them, so the read never follows the store's current
 * state: an order pinned to the merchant app is read through the merchant app even after the store is platform connected.
 * An order with no pin is read through the platform app, as OrderPin reads it.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class CaptureReader {
	use RequestTrait;

	/**
	 * The platform transport.
	 *
	 * @var PlatformTransport
	 */
	private PlatformTransport $transport;

	/**
	 * The order app context, entered for the read so a retry is signed by the same app.
	 *
	 * @var OrderAppContext
	 */
	private OrderAppContext $context;

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
	 * @param PlatformTransport $transport       The platform transport.
	 * @param OrderAppContext   $context         The order app context.
	 * @param CaptureFactory    $capture_factory The wallet's capture factory.
	 * @param LoggerInterface   $logger          The logger.
	 */
	public function __construct( PlatformTransport $transport, OrderAppContext $context, CaptureFactory $capture_factory, LoggerInterface $logger ) {
		$this->transport       = $transport;
		$this->context         = $context;
		$this->capture_factory = $capture_factory;
		$this->logger          = $logger;
	}

	/**
	 * The ID of the capture an order holds: the held capture's, else the order's transaction ID.
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Order $order The order.
	 * @return string
	 */
	public static function capture_id( WC_Order $order ): string {
		$held = $order->get_meta( HeldCapture::CAPTURE_ID_META_KEY, true );

		return is_string( $held ) && '' !== $held ? $held : $order->get_transaction_id();
	}

	/**
	 * Read the order's capture through its pinned app.
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Order $order The order.
	 * @return array{capture: Capture, create_time: int} The capture, and PayPal's create time as a UTC timestamp (0 when
	 *                                                   PayPal gave none).
	 *
	 * @throws RuntimeException When the order has no capture or the read fails.
	 * @throws PayPalApiException When PayPal answers with an error.
	 */
	public function read( WC_Order $order ): array {
		$capture_id = self::capture_id( $order );
		if ( '' === $capture_id ) {
			throw new RuntimeException( 'The order has no PayPal capture to read.' );
		}

		$app         = OrderPin::app( $order );
		$was_entered = $this->context->is_entered();
		$previous    = $this->context->current();
		$this->context->enter( $app );
		try {
			$response = $this->request(
				trailingslashit( $this->transport->host( $app ) ) . 'v2/payments/captures/' . rawurlencode( $capture_id ),
				array(
					'method'  => 'GET',
					'headers' => array(
						'Authorization' => 'Bearer ' . $this->transport->bearer( $app )->bearer()->token(),
						'Content-Type'  => 'application/json',
					),
				)
			);
		} finally {
			if ( $was_entered ) {
				$this->context->enter( $previous );
			} else {
				$this->context->reset();
			}
		}

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'Could not read the PayPal capture.' );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$json   = json_decode( wp_remote_retrieve_body( $response ) );
		if ( 200 !== $status || ! $json instanceof stdClass ) {
			$this->logger->warning( sprintf( 'Reading the PayPal capture of WooCommerce order #%1$d through the %2$s app answered %3$d.', $order->get_id(), $app, $status ) );
			throw new PayPalApiException( $json instanceof stdClass ? $json : null, $status ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Carries the decoded PayPal response object, not text; the message is built in PayPalApiException::__construct().
		}

		$create_time = is_string( $json->create_time ?? null ) ? strtotime( $json->create_time ) : false;

		return array(
			'capture'     => $this->capture_factory->from_paypal_response( $json ),
			'create_time' => false === $create_time ? 0 : $create_time,
		);
	}
}
