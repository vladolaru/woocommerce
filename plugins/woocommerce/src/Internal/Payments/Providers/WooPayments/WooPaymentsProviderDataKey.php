<?php
/**
 * WooPaymentsProviderDataKey class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * Provider-specific fields passed with a WooPayments operation.
 *
 * @since 11.4.0
 * @internal
 */
final class WooPaymentsProviderDataKey {

	/**
	 * CVC confirmation supplied for the payment.
	 *
	 * @var string
	 */
	public const CVC_CONFIRMATION = 'cvc_confirmation';

	/**
	 * Checkout fingerprint used for fraud assessment.
	 *
	 * @var string
	 */
	public const FINGERPRINT = 'fingerprint';

	/**
	 * Mandate authorizing a subscription renewal.
	 *
	 * @var string
	 */
	public const RENEWAL_MANDATE = 'renewal_mandate';

	/**
	 * Display name of the saved renewal method.
	 *
	 * @var string
	 */
	public const SAVED_PAYMENT_METHOD_DISPLAY_NAME = 'saved_payment_method_display_name';

	/**
	 * Whether this operation charges a scheduled renewal.
	 *
	 * @var string
	 */
	public const SCHEDULED_SUBSCRIPTION_PAYMENT = 'scheduled_subscription_payment';
}
