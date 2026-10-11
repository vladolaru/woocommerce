<?php
/**
 * ProviderInterface interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

/**
 * Runs a payment provider's charge, refund, capture and cancel calls for the payments runtime.
 *
 * It extends PaymentGatewayProviderInterface, so the same provider also publishes its gateways. A provider needs no
 * special exception type: an exception from an operation becomes a failed outcome with its message, and with its
 * get_error_code() result when it has that method.
 *
 * @since 11.0.0
 * @internal
 */
interface ProviderInterface extends PaymentGatewayProviderInterface {

	/**
	 * Tell whether a zero-total checkout with a payment credential should reach the provider's charge().
	 *
	 * A provider that returns true decides in charge() what a zero-amount payment needs. A zero-total checkout charges
	 * nothing, so the provider's call can only set up the payment method for later payments. The runtime calls charge()
	 * for a zero-total checkout only when this returns true and the context carries a payment method ID or a saved
	 * payment token; returning false completes the order without a provider call.
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
	 * @param string                  $idempotency_key Key minted fresh for this payment attempt, also the order payment lock's
	 *                                                 value. Retries within the attempt reuse it; a new attempt gets a new key.
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
