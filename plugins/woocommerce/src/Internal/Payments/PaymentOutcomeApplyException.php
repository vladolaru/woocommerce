<?php
/**
 * PaymentOutcomeApplyException class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use RuntimeException;
use Throwable;

/**
 * Thrown when a provider returned a durable outcome but applying it to the order failed.
 *
 * Before throwing, the processing service tried to save the outcome's reference on the order and logged
 * the failure. The save is best effort and can fail; was_reconciliation_context_persisted() says whether it
 * did. The caller decides what the order and the shopper see; the original failure is the previous exception.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
final class PaymentOutcomeApplyException extends RuntimeException {

	/**
	 * Provider outcome that could not be applied.
	 *
	 * @var PaymentOutcome
	 */
	private PaymentOutcome $outcome;

	/**
	 * Whether the outcome's reference was saved on the order before this was thrown.
	 *
	 * @var bool
	 */
	private bool $reconciliation_context_persisted;

	/**
	 * Constructor.
	 *
	 * @param PaymentOutcome $outcome                          Provider outcome that could not be applied.
	 * @param Throwable      $failure                          Failure raised while applying it.
	 * @param bool           $reconciliation_context_persisted Whether the outcome's reference was saved on the order.
	 */
	public function __construct( PaymentOutcome $outcome, Throwable $failure, bool $reconciliation_context_persisted ) {
		parent::__construct( $failure->getMessage(), 0, $failure );
		$this->outcome                          = $outcome;
		$this->reconciliation_context_persisted = $reconciliation_context_persisted;
	}

	/**
	 * Get the provider outcome that could not be applied.
	 *
	 * @return PaymentOutcome
	 */
	public function get_outcome(): PaymentOutcome {
		return $this->outcome;
	}

	/**
	 * Tell whether the outcome's reference was saved on the order before this was thrown.
	 *
	 * @return bool
	 */
	public function was_reconciliation_context_persisted(): bool {
		return $this->reconciliation_context_persisted;
	}

	/**
	 * Get the failure raised while applying the outcome.
	 *
	 * @return Throwable
	 */
	public function get_failure(): Throwable {
		$failure = $this->getPrevious();

		return null !== $failure ? $failure : $this;
	}
}
