<?php
/**
 * Create payment token for guest user.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods\Endpoint;

use Exception;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PaymentMethodTokensEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PaymentSource;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Endpoint\EndpointInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\RequestData;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Exception\NonceValidationException;

/**
 * Class UpdateCustomerId
 */
class CreatePaymentTokenForGuest implements EndpointInterface {

	const ENDPOINT = 'ppc-update-customer-id';

	/**
	 * The request data.
	 *
	 * @var RequestData
	 */
	private $request_data;

	/**
	 * The payment method tokens endpoint.
	 *
	 * @var PaymentMethodTokensEndpoint
	 */
	private $payment_method_tokens_endpoint;

	/**
	 * CreatePaymentToken constructor.
	 *
	 * @param RequestData                 $request_data The request data.
	 * @param PaymentMethodTokensEndpoint $payment_method_tokens_endpoint The payment method tokens endpoint.
	 */
	public function __construct(
		RequestData $request_data,
		PaymentMethodTokensEndpoint $payment_method_tokens_endpoint
	) {
		$this->request_data                   = $request_data;
		$this->payment_method_tokens_endpoint = $payment_method_tokens_endpoint;
	}

	/**
	 * Returns the nonce.
	 *
	 * @return string
	 */
	public static function nonce(): string {
		return self::ENDPOINT;
	}

	/**
	 * Handles the request.
	 *
	 * @throws Exception On Error.
	 */
	public function handle_request(): void {
		try {
			$data = $this->request_data->read_request( $this->nonce() );
		} catch ( NonceValidationException $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ), 400 );
		}

		/**
		 * Suppress ArgumentTypeCoercion
		 *
		 * @psalm-suppress ArgumentTypeCoercion
		 */
		$payment_source = new PaymentSource(
			'token',
			(object) array(
				'id'   => $data['vault_setup_token'],
				'type' => 'SETUP_TOKEN',
			)
		);

		$result = $this->payment_method_tokens_endpoint->create_payment_token( $payment_source );
		WC()->session->set( 'ppcp_guest_payment_for_free_trial', $result );

		wp_send_json_success();
	}
}
