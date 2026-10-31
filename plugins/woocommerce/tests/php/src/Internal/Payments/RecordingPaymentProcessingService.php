<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\ProviderInterface;

/**
 * Recording processing service for gateway tests.
 */
class RecordingPaymentProcessingService extends PaymentProcessingService {

	/**
	 * Last checkout context.
	 *
	 * @var PaymentOperationContext|null
	 */
	public ?PaymentOperationContext $last_checkout_context = null;

	/**
	 * Last refund context.
	 *
	 * @var PaymentOperationContext|null
	 */
	public ?PaymentOperationContext $last_refund_context = null;

	/**
	 * Number of checkout processing attempts.
	 *
	 * @var int
	 */
	public int $checkout_attempt_count = 0;

	/**
	 * Checkout outcome returned by the recording service.
	 *
	 * @var PaymentOutcome
	 */
	public PaymentOutcome $checkout_outcome;

	/**
	 * Exception thrown by checkout processing instead of returning the outcome, when set.
	 *
	 * @var \Exception|null
	 */
	public ?\Exception $checkout_exception = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->checkout_outcome = new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_recorded' );
	}

	/**
	 * Process checkout payment and return the neutral outcome.
	 *
	 * @param PaymentOperationContext $context  Payment context.
	 * @param ProviderInterface       $provider Provider.
	 * @return PaymentOutcome
	 * @throws \Exception When a checkout exception is configured.
	 */
	public function process_checkout_outcome( PaymentOperationContext $context, ProviderInterface $provider ): PaymentOutcome {
		$this->last_checkout_context = $context;
		++$this->checkout_attempt_count;

		if ( null !== $this->checkout_exception ) {
			throw $this->checkout_exception;
		}

		return $this->checkout_outcome;
	}

	/**
	 * Process a refund through a provider.
	 *
	 * @param PaymentOperationContext $context  Payment context.
	 * @param ProviderInterface       $provider Provider.
	 * @return bool
	 */
	public function process_refund( PaymentOperationContext $context, ProviderInterface $provider ) {
		$this->last_refund_context = $context;

		return true;
	}
}
