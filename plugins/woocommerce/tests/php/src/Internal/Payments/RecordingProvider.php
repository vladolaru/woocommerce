<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\ProviderInterface;
use Automattic\WooCommerce\Internal\Payments\ProviderOutcomeMetadataMapperInterface;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOutcomeMetadataMapper;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;

/**
 * Test provider that records operation calls.
 */
class RecordingProvider implements ProviderInterface, ProviderOutcomeMetadataMapperInterface {

	/**
	 * Outcome returned by all operations.
	 *
	 * @var PaymentOutcome
	 */
	private PaymentOutcome $outcome;

	/**
	 * Whether a zero-total checkout reaches charge().
	 *
	 * @var bool
	 */
	private bool $supports_zero_amount_setup;

	/**
	 * Number of charge calls.
	 *
	 * @var int
	 */
	public int $charge_calls = 0;

	/**
	 * Number of refund calls.
	 *
	 * @var int
	 */
	public int $refund_calls = 0;

	/**
	 * Number of capture calls.
	 *
	 * @var int
	 */
	public int $capture_calls = 0;

	/**
	 * Number of cancel calls.
	 *
	 * @var int
	 */
	public int $cancel_calls = 0;

	/**
	 * Last idempotency key received.
	 *
	 * @var string
	 */
	public string $last_idempotency_key = '';

	/**
	 * Constructor.
	 *
	 * @param PaymentOutcome $outcome                    Outcome returned by operations.
	 * @param bool           $supports_zero_amount_setup Whether a zero-total checkout reaches charge().
	 */
	public function __construct( PaymentOutcome $outcome, bool $supports_zero_amount_setup = false ) {
		$this->outcome                    = $outcome;
		$this->supports_zero_amount_setup = $supports_zero_amount_setup;
	}

	/**
	 * Get the provider/gateway ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return WooPaymentsPersistenceVocabulary::GATEWAY_ID;
	}

	/**
	 * Tell whether a zero-total checkout reaches charge().
	 *
	 * @param PaymentOperationContext $context Checkout payment context.
	 * @return bool
	 */
	public function supports_zero_amount_setup( PaymentOperationContext $context ): bool {
		unset( $context );

		return $this->supports_zero_amount_setup;
	}

	/**
	 * Get the provider persistence profile.
	 *
	 * @return ProviderPersistenceVocabularyInterface
	 */
	public function get_persistence_vocabulary(): ProviderPersistenceVocabularyInterface {
		return new WooPaymentsPersistenceVocabulary();
	}

	/**
	 * Map a neutral outcome to order metadata with the WooPayments vocabulary this double persists under.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return array<string,string>
	 */
	public function get_outcome_meta( PaymentOutcome $outcome ): array {
		return ( new WooPaymentsOutcomeMetadataMapper() )->get_outcome_meta( $outcome );
	}

	/**
	 * Map a failed capture or cancel outcome to order metadata with the WooPayments vocabulary.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return array<string,string>
	 */
	public function get_failed_capture_or_cancel_outcome_meta( PaymentOutcome $outcome ): array {
		return ( new WooPaymentsOutcomeMetadataMapper() )->get_failed_capture_or_cancel_outcome_meta( $outcome );
	}

	/**
	 * Get payment gateway instances registered by the provider.
	 *
	 * @return array<int,\WC_Payment_Gateway>
	 */
	public function get_payment_gateways(): array {
		return array();
	}

	/**
	 * Charge an order through the provider.
	 *
	 * @param PaymentOperationContext $context         Payment context.
	 * @param string                  $idempotency_key Deterministic idempotency key.
	 * @return PaymentOutcome
	 */
	public function charge( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
		++$this->charge_calls;
		$this->last_idempotency_key = $idempotency_key;

		return $this->outcome;
	}

	/**
	 * Capture a previously authorized payment through the provider.
	 *
	 * @param PaymentOperationContext $context       Payment context.
	 * @param string                  $operation_key Deterministic idempotency key.
	 * @return PaymentOutcome
	 */
	public function capture( PaymentOperationContext $context, string $operation_key ): PaymentOutcome {
		++$this->capture_calls;
		$this->last_idempotency_key = $operation_key;

		return $this->outcome;
	}

	/**
	 * Cancel a previously authorized payment through the provider.
	 *
	 * @param PaymentOperationContext $context       Payment context.
	 * @param string                  $operation_key Deterministic idempotency key.
	 * @return PaymentOutcome
	 */
	public function cancel( PaymentOperationContext $context, string $operation_key ): PaymentOutcome {
		++$this->cancel_calls;
		$this->last_idempotency_key = $operation_key;

		return $this->outcome;
	}

	/**
	 * Refund a payment through the provider.
	 *
	 * @param PaymentOperationContext $context         Payment context.
	 * @param string                  $idempotency_key Key minted fresh for this refund call.
	 * @return PaymentOutcome
	 */
	public function refund( PaymentOperationContext $context, string $idempotency_key ): PaymentOutcome {
		++$this->refund_calls;
		$this->last_idempotency_key = $idempotency_key;

		return $this->outcome;
	}
}
