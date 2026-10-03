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
 * The processing service has already persisted the outcome's reference and logged the failure.
 * The caller decides what the order and the shopper see; the original failure is the previous exception.
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
	 * Constructor.
	 *
	 * @param PaymentOutcome $outcome Provider outcome that could not be applied.
	 * @param Throwable      $failure Failure raised while applying it.
	 */
	public function __construct( PaymentOutcome $outcome, Throwable $failure ) {
		parent::__construct( $failure->getMessage(), 0, $failure );
		$this->outcome = $outcome;
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
	 * Get the failure raised while applying the outcome.
	 *
	 * @return Throwable
	 */
	public function get_failure(): Throwable {
		$failure = $this->getPrevious();

		return null !== $failure ? $failure : $this;
	}
}
