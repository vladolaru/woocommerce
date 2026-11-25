<?php
/**
 * WooPaymentsIntentStatus class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * States of payment and setup intents returned by the platform.
 *
 * @since 11.4.0
 * @internal
 */
final class WooPaymentsIntentStatus {

	/**
	 * The intent was canceled.
	 *
	 * @var string
	 */
	public const CANCELED = 'canceled';

	/**
	 * The platform is processing the intent.
	 *
	 * @var string
	 */
	public const PROCESSING = 'processing';

	/**
	 * The customer must complete another action.
	 *
	 * @var string
	 */
	public const REQUIRES_ACTION = 'requires_action';

	/**
	 * The authorized payment awaits capture.
	 *
	 * @var string
	 */
	public const REQUIRES_CAPTURE = 'requires_capture';

	/**
	 * The intent awaits confirmation.
	 *
	 * @var string
	 */
	public const REQUIRES_CONFIRMATION = 'requires_confirmation';

	/**
	 * The intent needs a payment method.
	 *
	 * @var string
	 */
	public const REQUIRES_PAYMENT_METHOD = 'requires_payment_method';

	/**
	 * The intent succeeded.
	 *
	 * @var string
	 */
	public const SUCCEEDED = 'succeeded';
}
