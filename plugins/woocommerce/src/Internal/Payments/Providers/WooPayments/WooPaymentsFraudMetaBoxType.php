<?php
/**
 * WooPaymentsFraudMetaBoxType class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * Fraud panels shown on the order screen.
 *
 * @since 11.4.0
 * @internal
 */
final class WooPaymentsFraudMetaBoxType {

	/**
	 * The fraud rules allowed the payment.
	 *
	 * @var string
	 */
	public const ALLOW = 'allow';

	/**
	 * The fraud rules blocked the payment.
	 *
	 * @var string
	 */
	public const BLOCK = 'block';

	/**
	 * The shopper used a non-card payment method.
	 *
	 * @var string
	 */
	public const NOT_CARD = 'not_card';

	/**
	 * The payment has started.
	 *
	 * @var string
	 */
	public const PAYMENT_STARTED = 'payment_started';

	/**
	 * The payment awaits fraud review.
	 *
	 * @var string
	 */
	public const REVIEW = 'review';

	/**
	 * The payment was approved after review.
	 *
	 * @var string
	 */
	public const REVIEW_ALLOWED = 'review_allowed';

	/**
	 * The payment was blocked after review.
	 *
	 * @var string
	 */
	public const REVIEW_BLOCKED = 'review_blocked';

	/**
	 * The authorization expired during review.
	 *
	 * @var string
	 */
	public const REVIEW_EXPIRED = 'review_expired';

	/**
	 * The reviewed payment failed.
	 *
	 * @var string
	 */
	public const REVIEW_FAILED = 'review_failed';

	/**
	 * The customer paid at a terminal.
	 *
	 * @var string
	 */
	public const TERMINAL_PAYMENT = 'terminal_payment';
}
