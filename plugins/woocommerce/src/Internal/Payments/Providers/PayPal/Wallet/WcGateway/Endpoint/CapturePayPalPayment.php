<?php

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Endpoint;

use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use RuntimeException;
use WC_Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\Bearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\RequestTrait;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PurchaseUnit;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\PayPalApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\OrderFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PurchaseUnitFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use WP_Error;

/**
 * Class CapturePayPalPayment.
 */
class CapturePayPalPayment {

	use RequestTrait;

	/**
	 * The host.
	 *
	 * @var string
	 */
	private string $host;

	/**
	 * The bearer.
	 *
	 * @var Bearer
	 */
	private Bearer $bearer;

	/**
	 * The order factory.
	 *
	 * @var OrderFactory
	 */
	private OrderFactory $order_factory;

	/**
	 * The purchase unit factory.
	 *
	 * @var PurchaseUnitFactory
	 */
	private PurchaseUnitFactory $purchase_unit_factory;

	/**
	 * The settings provider.
	 *
	 * @var SettingsProvider
	 */
	private SettingsProvider $settings_provider;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * CapturePayPalPayment constructor.
	 *
	 * @param string              $host                  The host.
	 * @param Bearer              $bearer                The bearer.
	 * @param OrderFactory        $order_factory         The order factory.
	 * @param PurchaseUnitFactory $purchase_unit_factory The purchase unit factory.
	 * @param SettingsProvider    $settings_provider     The settings provider.
	 * @param LoggerInterface     $logger                The logger.
	 */
	public function __construct(
		string $host,
		Bearer $bearer,
		OrderFactory $order_factory,
		PurchaseUnitFactory $purchase_unit_factory,
		SettingsProvider $settings_provider,
		LoggerInterface $logger
	) {
		$this->host                  = $host;
		$this->bearer                = $bearer;
		$this->order_factory         = $order_factory;
		$this->purchase_unit_factory = $purchase_unit_factory;
		$this->settings_provider     = $settings_provider;
		$this->logger                = $logger;
	}

	/**
	 * Creates PayPal order from the given PayPal/Venmo vault ID.
	 *
	 * The custom_id/invoice_id fields are not accepted as parameters here:
	 * the PayPal Orders v2 API only reads them from inside a purchase
	 * unit, never from the request root, so from_wc_order() populates
	 * them directly on the purchase unit built above.
	 *
	 * @param string   $vault_id            The vault id.
	 * @param WC_Order $wc_order            The wc order.
	 * @param string   $payment_source_name The payment source name.
	 * @throws RuntimeException When request fails.
	 */
	public function create_order(
		string $vault_id,
		WC_Order $wc_order,
		string $payment_source_name = 'paypal'
	): Order {
		$intent = strtoupper( $this->settings_provider->payment_intent() ) === 'AUTHORIZE' ? 'AUTHORIZE' : 'CAPTURE';
		$items  = array( $this->purchase_unit_factory->from_wc_order( $wc_order ) );

		$data = array(
			'intent'         => $intent,
			'purchase_units' => array_map(
				static function ( PurchaseUnit $item ): array {
					return $item->to_array();
				},
				$items
			),
			'payment_source' => array(
				$payment_source_name => array(
					'vault_id'           => $vault_id,
					'experience_context' => array(
						'payment_method_preference' => 'IMMEDIATE_PAYMENT_REQUIRED',
					),
				),
			),
		);

		$bearer = $this->bearer->bearer();
		$url    = trailingslashit( $this->host ) . 'v2/checkout/orders';
		$args   = array(
			'method'  => 'POST',
			'headers' => array(
				'Authorization'     => 'Bearer ' . $bearer->token(),
				'Content-Type'      => 'application/json',
				'PayPal-Request-Id' => uniqid( 'ppcp-', true ),
			),
			'body'    => wp_json_encode( $data ),
		);

		$json = $this->execute_order_request( $url, $args );

		return $this->order_factory->from_paypal_response( $json );
	}

	/**
	 * Executes the order creation request, retrying once on an incomplete response.
	 *
	 * PayPal's v2/checkout/orders endpoint intermittently returns a success status
	 * with a response body missing the required id field. A single retry with a new
	 * idempotency key recovers from this transient issue.
	 *
	 * @param string $url  The request URL.
	 * @param array  $args The request arguments.
	 * @return \stdClass Decoded response with a valid id.
	 *
	 * @throws RuntimeException When the response is incomplete after retry or PayPal returns a non-success status.
	 */
	private function execute_order_request( string $url, array $args ): \stdClass {
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			if ( $attempt > 0 ) {
				$this->logger->info( 'Retrying PayPal order creation after incomplete response.' );
				$args['headers']['PayPal-Request-Id'] = uniqid( 'ppcp-', true );
			}

			$response = $this->request( $url, $args );
			if ( $response instanceof WP_Error ) {
				throw new RuntimeException( esc_html( $response->get_error_message() ) );
			}

			$json        = json_decode( $response['body'] );
			$status_code = (int) wp_remote_retrieve_response_code( $response );
			if ( ! in_array( $status_code, array( 200, 201 ), true ) ) {
				$error = new PayPalApiException( $json, $status_code );
				$this->logger->warning( $error->getMessage() );
				throw $error;
			}

			if ( $json instanceof \stdClass && isset( $json->id ) ) {
				return $json;
			}

			$this->logger->warning(
				'PayPal order response missing id.',
				array(
					'status_code'   => $status_code,
					'response_body' => $response['body'],
				)
			);
		}

		throw new RuntimeException( 'PayPal order response missing id after retry.' );
	}
}
