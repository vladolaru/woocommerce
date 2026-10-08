<?php
/**
 * ProviderInterface interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

/**
 * Runs a payment provider's charge, refund, capture and cancel calls for the payments runtime.
 *
 * It extends PaymentGatewayProviderInterface, so the same provider also publishes its gateways. When an operation
 * throws, it fails with the exception message and, when the exception has get_error_code(), that error code.
 *
 * @since 11.0.0
 * @internal
 */
interface ProviderInterface extends PaymentGatewayProviderInterface {

	/**
	 * Tell whether a zero-total checkout with a payment credential should reach the provider's charge().
	 *
	 * A provider that returns true decides in charge() what a zero-amount payment needs (for example, a
	 * setup intent to save the payment method). Returning false completes the order without a provider call.
	 *
	 * @param PaymentOperationContext $context Checkout payment context.
	 * @return bool
	 */
	public function supports_zero_amount_setup( PaymentOperationContext $context ): bool;

	/**
	 * Get the provider's persistence vocabulary.
	 *
	 * @return ProviderPersistenceVocabularyInterface
	 *
	 * @since 11.0.0
	 */
	public function get_persistence_vocabulary(): ProviderPersistenceVocabularyInterface;

	/**
	 * Charge an order through the provider.
	 *
	 * @param PaymentOperationContext $context         Payment context.
	 * @param string                  $idempotency_key Key minted fresh for this payment attempt, also the order payment lock
	 *                                                 token. Retries within the attempt reuse it; a new attempt gets a new key.
	 *                                                 A provider may send instead a key it stored on the order for an earlier
	 *                                                 attempt whose outcome is still unknown.
	 * @return PaymentOutcome
	 */
	public function charge( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome;

	/**
	 * Capture a previously authorized payment through the provider.
	 *
	 * @param PaymentOperationContext $context         Payment context.
	 * @param string                  $idempotency_key Key minted fresh for this call, also the order payment lock's value; the
	 *                                                 provider may send it as its request key.
	 * @return PaymentOutcome
	 */
	public function capture( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome;

	/**
	 * Cancel a previously authorized payment through the provider.
	 *
	 * @param PaymentOperationContext $context         Payment context.
	 * @param string                  $idempotency_key Key minted fresh for this call, also the order payment lock's value; the
	 *                                                 provider may send it as its request key.
	 * @return PaymentOutcome
	 */
	public function cancel( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome;

	/**
	 * Refund a payment through the provider.
	 *
	 * @param PaymentOperationContext $context         Payment context.
	 * @param string                  $idempotency_key Key minted fresh for this refund call; send it as the provider request key.
	 * @return PaymentOutcome
	 */
	public function refund( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome;
}
