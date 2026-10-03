<?php
/**
 * The Payments factory.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Authorization;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Capture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Refund;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Payments;

/**
 * Class PaymentsFactory
 */
class PaymentsFactory {

	/**
	 * The Authorization factory.
	 *
	 * @var AuthorizationFactory
	 */
	private $authorization_factory;

	/**
	 * The Capture factory.
	 *
	 * @var CaptureFactory
	 */
	private $capture_factory;

	/**
	 * The Refund factory.
	 *
	 * @var RefundFactory
	 */
	private $refund_factory;

	/**
	 * PaymentsFactory constructor.
	 *
	 * @param AuthorizationFactory $authorization_factory The Authorization factory.
	 * @param CaptureFactory       $capture_factory The Capture factory.
	 * @param RefundFactory        $refund_factory The Refund factory.
	 */
	public function __construct(
		AuthorizationFactory $authorization_factory,
		CaptureFactory $capture_factory,
		RefundFactory $refund_factory
	) {

		$this->authorization_factory = $authorization_factory;
		$this->capture_factory       = $capture_factory;
		$this->refund_factory        = $refund_factory;
	}

	/**
	 * Returns a Payments object based off a PayPal response.
	 *
	 * @param \stdClass $data The JSON object.
	 *
	 * @return Payments
	 */
	public function from_paypal_response( \stdClass $data ): Payments {
		$authorizations = array_map(
			function ( \stdClass $authorization ): Authorization {
				return $this->authorization_factory->from_paypal_response( $authorization );
			},
			isset( $data->authorizations ) ? $data->authorizations : array()
		);
		$captures       = array_map(
			function ( \stdClass $capture ): Capture {
				return $this->capture_factory->from_paypal_response( $capture );
			},
			isset( $data->captures ) ? $data->captures : array()
		);
		$refunds        = array_map(
			function ( \stdClass $refund ): Refund {
				return $this->refund_factory->from_paypal_response( $refund );
			},
			isset( $data->refunds ) ? $data->refunds : array()
		);
		$payments       = new Payments( $authorizations, $captures, $refunds );
		return $payments;
	}
}
