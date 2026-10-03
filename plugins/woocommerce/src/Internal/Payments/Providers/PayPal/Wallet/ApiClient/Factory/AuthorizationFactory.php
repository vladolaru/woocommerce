<?php
/**
 * The Authorization factory.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Authorization;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\AuthorizationStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\AuthorizationStatusDetails;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;

/**
 * Class AuthorizationFactory
 */
class AuthorizationFactory {

	/**
	 * The FraudProcessorResponseFactory factory.
	 *
	 * @var FraudProcessorResponseFactory
	 */
	protected $fraud_processor_response_factory;

	/**
	 * AuthorizationFactory constructor.
	 *
	 * @param FraudProcessorResponseFactory $fraud_processor_response_factory The FraudProcessorResponseFactory factory.
	 */
	public function __construct( FraudProcessorResponseFactory $fraud_processor_response_factory ) {
		$this->fraud_processor_response_factory = $fraud_processor_response_factory;
	}

	/**
	 * Returns an Authorization based off a PayPal response.
	 *
	 * @param \stdClass $data The JSON object.
	 *
	 * @return Authorization
	 * @throws RuntimeException When JSON object is malformed.
	 */
	public function from_paypal_response( \stdClass $data ): Authorization {
		if ( ! isset( $data->id ) ) {
			throw new RuntimeException( 'Does not contain an id.' );
		}

		if ( ! isset( $data->status ) ) {
			throw new RuntimeException( 'Does not contain status.' );
		}

		$reason = $data->status_details->reason ?? null;

		$fraud_processor_response = isset( $data->processor_response ) ?
			$this->fraud_processor_response_factory->from_paypal_response( $data->processor_response )
			: null;

		return new Authorization(
			$data->id,
			new AuthorizationStatus(
				$data->status,
				$reason ? new AuthorizationStatusDetails( $reason ) : null
			),
			$fraud_processor_response
		);
	}
}
