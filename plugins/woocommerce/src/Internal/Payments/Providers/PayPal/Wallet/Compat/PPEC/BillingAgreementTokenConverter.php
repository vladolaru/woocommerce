<?php
declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\PPEC;

use Exception;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PaymentMethodTokensEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PaymentSource;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Repository\CustomerRepository;

/**
 * Converts a PayPal Express Checkout billing agreement into a Vault v3 payment token.
 */
class BillingAgreementTokenConverter {

	/**
	 * The payment method tokens endpoint.
	 *
	 * @var PaymentMethodTokensEndpoint
	 */
	private PaymentMethodTokensEndpoint $payment_method_tokens_endpoint;

	/**
	 * The customer repository.
	 *
	 * @var CustomerRepository
	 */
	private CustomerRepository $customer_repository;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * BillingAgreementTokenConverter constructor.
	 *
	 * @param PaymentMethodTokensEndpoint $payment_method_tokens_endpoint The payment method tokens endpoint.
	 * @param CustomerRepository          $customer_repository            The customer repository.
	 * @param LoggerInterface             $logger                         The logger.
	 */
	public function __construct(
		PaymentMethodTokensEndpoint $payment_method_tokens_endpoint,
		CustomerRepository $customer_repository,
		LoggerInterface $logger
	) {
		$this->payment_method_tokens_endpoint = $payment_method_tokens_endpoint;
		$this->customer_repository            = $customer_repository;
		$this->logger                         = $logger;
	}

	/**
	 * Converts a billing agreement into a Vault v3 payment token.
	 *
	 * @param string $billing_agreement_id The PayPal billing agreement ID.
	 * @param int    $user_id              The ID of the user the token belongs to.
	 * @return string|null The vault token ID on success, null on failure.
	 */
	public function convert( string $billing_agreement_id, int $user_id ): ?string {
		try {
			$payment_source = new PaymentSource(
				'token',
				(object) array(
					'id'   => $billing_agreement_id,
					'type' => 'BILLING_AGREEMENT',
				)
			);

			// Only a real PayPal customer ID is usable here; passing the local
			// fallback ID would be rejected by the Vault API. An empty string
			// lets PayPal assign a customer, whose ID is stored below.
			$customer_id = $this->customer_repository->paypal_customer_id_for_user( $user_id );
			$result      = $this->payment_method_tokens_endpoint->create_payment_token( $payment_source, $customer_id );

			if ( empty( $result->id ) ) {
				$this->logger->error(
					sprintf( 'Vault token creation for Billing Agreement %s returned no token ID.', $billing_agreement_id )
				);
				return null;
			}

			if ( isset( $result->customer->id ) ) {
				update_user_meta( $user_id, '_ppcp_target_customer_id', $result->customer->id );
			}

			$this->logger->info(
				sprintf(
					'Successfully converted Billing Agreement %s to Vault v3 token %s for user %d.',
					$billing_agreement_id,
					$result->id,
					$user_id
				)
			);

			return $result->id;
		} catch ( Exception $exception ) {
			$this->logger->error(
				sprintf(
					'Failed to convert Billing Agreement %s to Vault v3 token for user %d: %s',
					$billing_agreement_id,
					$user_id,
					$exception->getMessage()
				)
			);

			return null;
		}
	}
}
