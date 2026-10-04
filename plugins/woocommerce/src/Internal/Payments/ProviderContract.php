<?php
/**
 * ProviderContract interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

/**
 * Internal processing contract implemented by native payments providers.
 *
 * Processing providers also publish their WooCommerce payment gateways through the
 * inherited gateway-provider identity contract. Keeping one interface hierarchy
 * lets the processing service and gateway registry consume the same provider
 * without duplicate interface declarations on each implementation.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
interface ProviderContract extends PaymentGatewayProviderContract {

	/**
	 * Tell whether a zero-total checkout with a payment credential should reach the provider's charge().
	 *
	 * A provider that returns true decides in charge() what a zero-amount payment needs (for example, a
	 * setup intent to save the payment method). Returning false completes the order without a provider call.
	 *
	 * @param PaymentContext $context Checkout payment context.
	 * @return bool
	 */
	public function supports_zero_amount_setup( PaymentContext $context ): bool;

	/**
	 * Get the provider persistence profile.
	 *
	 * @return ProviderPersistenceVocabulary
	 *
	 * @since 11.0.0
	 */
	public function get_persistence_profile(): ProviderPersistenceVocabulary;

	/**
	 * Charge an order through the provider.
	 *
	 * @param PaymentContext $context         Payment context.
	 * @param string         $idempotency_key Key minted fresh for this payment attempt, also the order payment lock
	 *                                        token. Retries within the attempt reuse it; a new attempt gets a new key.
	 *                                        A provider may send a key it kept on the order from an ambiguous earlier
	 *                                        attempt instead, until a definitive outcome (see PaymentOperationIdempotency).
	 * @return PaymentOutcome
	 */
	public function charge( PaymentContext $context, string $idempotency_key ): PaymentOutcome;

	/**
	 * Capture a previously authorized payment through the provider.
	 *
	 * @param PaymentContext $context         Payment context.
	 * @param string         $idempotency_key Deterministic operation key: the order payment lock token and log
	 *                                        correlation ID. Do not send it as the provider request key, or a
	 *                                        retry after a failure replays that failure.
	 * @return PaymentOutcome
	 */
	public function capture( PaymentContext $context, string $idempotency_key ): PaymentOutcome;

	/**
	 * Cancel a previously authorized payment through the provider.
	 *
	 * @param PaymentContext $context         Payment context.
	 * @param string         $idempotency_key Deterministic operation key: the order payment lock token and log
	 *                                        correlation ID. Do not send it as the provider request key, or a
	 *                                        retry after a failure replays that failure.
	 * @return PaymentOutcome
	 */
	public function cancel( PaymentContext $context, string $idempotency_key ): PaymentOutcome;

	/**
	 * Refund a payment through the provider.
	 *
	 * @param PaymentContext $context         Payment context.
	 * @param string         $idempotency_key Key minted fresh for this refund call; send it as the provider request key.
	 * @return PaymentOutcome
	 */
	public function refund( PaymentContext $context, string $idempotency_key ): PaymentOutcome;
}
