<?php
/**
 * WooPaymentsFraudOutcome class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * Decisions returned by the fraud ruleset.
 *
 * @since 11.4.0
 * @internal
 */
final class WooPaymentsFraudOutcome {

	/**
	 * Allow the payment.
	 *
	 * @var string
	 */
	public const ALLOW = 'allow';

	/**
	 * Block the payment.
	 *
	 * @var string
	 */
	public const BLOCK = 'block';

	/**
	 * Hold the payment for review.
	 *
	 * @var string
	 */
	public const REVIEW = 'review';
}
