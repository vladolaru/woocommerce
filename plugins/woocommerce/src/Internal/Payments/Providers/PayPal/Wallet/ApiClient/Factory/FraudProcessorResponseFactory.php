<?php
/**
 * The FraudProcessorResponseFactory Factory.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory;

use stdClass;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\FraudProcessorResponse;

/**
 * Class FraudProcessorResponseFactory
 */
class FraudProcessorResponseFactory {

	/**
	 * Returns a FraudProcessorResponse object based off a PayPal Response.
	 *
	 * @param stdClass $data The JSON object.
	 *
	 * @return FraudProcessorResponse
	 */
	public function from_paypal_response( stdClass $data ): FraudProcessorResponse {
		$avs_code      = ( $data->avs_code ?? null ) ?: null;
		$cvv_code      = ( $data->cvv_code ?? null ) ?: null;
		$response_code = ( $data->response_code ?? null ) ?: null;

		return new FraudProcessorResponse( $avs_code, $cvv_code, $response_code );
	}
}
