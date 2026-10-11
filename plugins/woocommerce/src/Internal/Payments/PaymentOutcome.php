<?php
/**
 * PaymentOutcome class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use InvalidArgumentException;

/**
 * Holds the result of a provider payment operation: its status, references, and the data the runtime applies to the order.
 *
 * @since 11.0.0
 * @internal
 */
class PaymentOutcome {

	/**
	 * Payment completed.
	 *
	 * @var string
	 */
	public const STATUS_COMPLETED = 'completed';

	/**
	 * Payment authorized but not captured.
	 *
	 * @var string
	 */
	public const STATUS_AUTHORIZED = 'authorized';

	/**
	 * Payment is pending asynchronous provider completion.
	 *
	 * @var string
	 */
	public const STATUS_PENDING_ASYNC = 'pending_async';

	/**
	 * Customer must be redirected.
	 *
	 * @var string
	 */
	public const STATUS_REQUIRES_REDIRECT = 'requires_redirect';

	/**
	 * Customer action is required.
	 *
	 * @var string
	 */
	public const STATUS_REQUIRES_CUSTOMER_ACTION = 'requires_customer_action';

	/**
	 * Payment failed.
	 *
	 * @var string
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * Payment authorization was canceled.
	 *
	 * @var string
	 */
	public const STATUS_CANCELED = 'canceled';

	/**
	 * No external payment was needed.
	 *
	 * @var string
	 */
	public const STATUS_NO_EXTERNAL_PAYMENT = 'no_external_payment';

	/**
	 * Checkout outcomes and completed captures and cancels: order meta keys to delete (ignored for a failed capture or cancel).
	 *
	 * @var string
	 */
	public const DATA_META_TO_DELETE = 'meta_to_delete';

	/**
	 * Checkout, capture and cancel outcomes: order note.
	 *
	 * @var string
	 */
	public const DATA_NOTE = 'note';

	/**
	 * Checkout, capture and cancel outcomes: stable order note type.
	 *
	 * @var string
	 */
	public const DATA_NOTE_TYPE = 'note_type';

	/**
	 * Checkout, capture and cancel outcomes: other texts of the same note the order may already carry, such as the note
	 * in another language or without its currency code, so the note is added once.
	 *
	 * @var string
	 */
	public const DATA_NOTE_EQUIVALENTS = 'note_equivalents';

	/**
	 * Refund outcomes: refund meta updates.
	 *
	 * @var string
	 */
	public const DATA_REFUND_META = 'refund_meta';

	/**
	 * Refund outcomes: parent order meta updates.
	 *
	 * @var string
	 */
	public const DATA_ORDER_META = 'order_meta';

	/**
	 * Refund outcomes: refund order note.
	 *
	 * @var string
	 */
	public const DATA_REFUND_NOTE = 'refund_note';

	/**
	 * Refund outcomes: stable refund note identity.
	 *
	 * @since 11.2.0
	 *
	 * @var string
	 */
	public const DATA_REFUND_NOTE_IDENTITY = 'refund_note_identity';

	/**
	 * Refund outcomes: other texts of the same refund note the order may already carry, such as the note in another
	 * language or without its currency code, so the note is added once.
	 *
	 * @since 11.2.0
	 *
	 * @var string
	 */
	public const DATA_REFUND_NOTE_EQUIVALENTS = 'refund_note_equivalents';

	/**
	 * Failed outcomes: provider error code.
	 *
	 * @var string
	 */
	public const DATA_ERROR_CODE = 'error_code';

	/**
	 * Failed outcomes: provider error message.
	 *
	 * @var string
	 */
	public const DATA_ERROR_MESSAGE = 'error_message';

	/**
	 * Checkout outcomes: checkout redirect URL override.
	 *
	 * @var string
	 */
	public const DATA_CHECKOUT_REDIRECT = 'checkout_redirect';

	/**
	 * Failed outcomes: flags that the outcome must not change the order status.
	 *
	 * A payment refused before processing (for example by fraud screening) records
	 * its meta and note effects while leaving the order status for the merchant to
	 * decide; the checkout result is still a failure for the shopper.
	 *
	 * @var string
	 */
	public const DATA_PRESERVE_ORDER_STATUS = 'preserve_order_status';

	/**
	 * Checkout outcomes: flags that another request paid the order while this one waited for the payment lock.
	 *
	 * Nothing was charged; the checkout sends the shopper to the order-received page. The status stays completed because
	 * the order is paid, so a caller that does not read the flag still treats the checkout as paid; the flag tells one
	 * that does that this request charged nothing.
	 *
	 * @since 11.2.0
	 * @var string
	 */
	public const DATA_ORDER_PAID_BY_ANOTHER_REQUEST = 'order_paid_by_another_request';

	/**
	 * Outcome status.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Provider payment ID.
	 *
	 * @var string
	 */
	private string $provider_payment_id;

	/**
	 * Redirect URL.
	 *
	 * @var string
	 */
	private string $redirect_url;

	/**
	 * Payment method ID.
	 *
	 * @var string
	 */
	private string $payment_method_id;

	/**
	 * Customer ID.
	 *
	 * @var string
	 */
	private string $customer_id;

	/**
	 * Additional outcome data.
	 *
	 * @var array<string,mixed>
	 */
	private array $data;

	/**
	 * Request-scoped provider effect plan.
	 *
	 * @var object|null
	 */
	private ?object $effect_plan = null;

	/**
	 * Constructor.
	 *
	 * @param string              $status              Outcome status.
	 * @param string              $provider_payment_id Provider payment ID.
	 * @param string              $redirect_url        Redirect URL.
	 * @param string              $payment_method_id   Payment method ID.
	 * @param string              $customer_id         Customer ID.
	 * @param array<string,mixed> $data                Additional neutral outcome data.
	 * @throws InvalidArgumentException If the status is unknown.
	 */
	public function __construct( string $status, string $provider_payment_id = '', string $redirect_url = '', string $payment_method_id = '', string $customer_id = '', array $data = array() ) {
		if ( ! in_array( $status, self::get_valid_statuses(), true ) ) {
			throw new InvalidArgumentException( esc_html( "Unknown payment outcome status: {$status}" ) );
		}

		$this->status              = $status;
		$this->provider_payment_id = $provider_payment_id;
		$this->redirect_url        = $redirect_url;
		$this->payment_method_id   = $payment_method_id;
		$this->customer_id         = $customer_id;
		$this->data                = $data;
	}

	/**
	 * Get valid outcome statuses.
	 *
	 * @return string[]
	 */
	public static function get_valid_statuses(): array {
		return array(
			self::STATUS_COMPLETED,
			self::STATUS_AUTHORIZED,
			self::STATUS_PENDING_ASYNC,
			self::STATUS_REQUIRES_REDIRECT,
			self::STATUS_REQUIRES_CUSTOMER_ACTION,
			self::STATUS_FAILED,
			self::STATUS_CANCELED,
			self::STATUS_NO_EXTERNAL_PAYMENT,
		);
	}

	/**
	 * Tell whether the outcome represents a successful payment operation.
	 *
	 * @return bool
	 */
	public function is_successful(): bool {
		return in_array(
			$this->status,
			array(
				self::STATUS_COMPLETED,
				self::STATUS_AUTHORIZED,
				self::STATUS_NO_EXTERNAL_PAYMENT,
			),
			true
		);
	}

	/**
	 * Get the outcome status.
	 *
	 * @return string
	 */
	public function get_status(): string {
		return $this->status;
	}

	/**
	 * Get the provider payment ID.
	 *
	 * It is the provider's ID for what the operation made: the payment for checkout, capture and cancel outcomes, and the
	 * refund for refund outcomes.
	 *
	 * @return string
	 */
	public function get_provider_payment_id(): string {
		return $this->provider_payment_id;
	}

	/**
	 * Get the redirect URL.
	 *
	 * @return string
	 */
	public function get_redirect_url(): string {
		return $this->redirect_url;
	}

	/**
	 * Get the payment method ID.
	 *
	 * @return string
	 */
	public function get_payment_method_id(): string {
		return $this->payment_method_id;
	}

	/**
	 * Get the customer ID.
	 *
	 * @return string
	 */
	public function get_customer_id(): string {
		return $this->customer_id;
	}

	/**
	 * Get additional outcome data.
	 *
	 * @return array<string,mixed>
	 */
	public function get_data(): array {
		return $this->data;
	}

	/**
	 * Return a copy carrying a request-scoped provider effect plan.
	 *
	 * Request-scoped data the provider hands from its call to its own effect appliers; the runtime carries it and never reads it.
	 *
	 * @param object $effect_plan Provider effect plan.
	 * @return self
	 */
	public function with_effect_plan( object $effect_plan ): self {
		$outcome              = clone $this;
		$outcome->effect_plan = $effect_plan;

		return $outcome;
	}

	/**
	 * Get the request-scoped provider effect plan.
	 *
	 * @return object|null
	 */
	public function get_effect_plan(): ?object {
		return $this->effect_plan;
	}
}
