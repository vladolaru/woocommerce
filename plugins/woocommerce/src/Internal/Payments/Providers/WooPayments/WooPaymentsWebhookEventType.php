<?php
/**
 * WooPaymentsWebhookEventType class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * Platform events routed to WooPayments webhook handlers.
 *
 * @since 11.4.0
 * @internal
 */
final class WooPaymentsWebhookEventType {

	/**
	 * A dispute closed.
	 *
	 * @var string
	 */
	public const CHARGE_DISPUTE_CLOSED = 'charge.dispute.closed';

	/**
	 * A new dispute opened against a charge.
	 *
	 * @var string
	 */
	public const CHARGE_DISPUTE_CREATED = 'charge.dispute.created';

	/**
	 * Funds from a disputed charge were returned.
	 *
	 * @var string
	 */
	public const CHARGE_DISPUTE_FUNDS_REINSTATED = 'charge.dispute.funds_reinstated';

	/**
	 * Funds from a disputed charge were withdrawn.
	 *
	 * @var string
	 */
	public const CHARGE_DISPUTE_FUNDS_WITHDRAWN = 'charge.dispute.funds_withdrawn';

	/**
	 * The platform updated a dispute.
	 *
	 * @var string
	 */
	public const CHARGE_DISPUTE_UPDATED = 'charge.dispute.updated';

	/**
	 * An uncaptured charge expired.
	 *
	 * @var string
	 */
	public const CHARGE_EXPIRED = 'charge.expired';

	/**
	 * The platform updated a charge refund.
	 *
	 * @var string
	 */
	public const CHARGE_REFUND_UPDATED = 'charge.refund.updated';

	/**
	 * A charge was partially or fully refunded.
	 *
	 * @var string
	 */
	public const CHARGE_REFUNDED = 'charge.refunded';

	/**
	 * An invoice was paid.
	 *
	 * @var string
	 */
	public const INVOICE_PAID = 'invoice.paid';

	/**
	 * An invoice payment attempt failed.
	 *
	 * @var string
	 */
	public const INVOICE_PAYMENT_FAILED = 'invoice.payment_failed';

	/**
	 * A subscription invoice is coming due.
	 *
	 * @var string
	 */
	public const INVOICE_UPCOMING = 'invoice.upcoming';

	/**
	 * The amount available to capture changed.
	 *
	 * @var string
	 */
	public const PAYMENT_INTENT_AMOUNT_CAPTURABLE_UPDATED = 'payment_intent.amount_capturable_updated';

	/**
	 * A payment attempt failed.
	 *
	 * @var string
	 */
	public const PAYMENT_INTENT_PAYMENT_FAILED = 'payment_intent.payment_failed';

	/**
	 * A payment intent succeeded.
	 *
	 * @var string
	 */
	public const PAYMENT_INTENT_SUCCEEDED = 'payment_intent.succeeded';

	/**
	 * Radar issued an early fraud warning.
	 *
	 * @var string
	 */
	public const RADAR_EARLY_FRAUD_WARNING_CREATED = 'radar.early_fraud_warning.created';

	/**
	 * Radar updated an early fraud warning.
	 *
	 * @var string
	 */
	public const RADAR_EARLY_FRAUD_WARNING_UPDATED = 'radar.early_fraud_warning.updated';
}
