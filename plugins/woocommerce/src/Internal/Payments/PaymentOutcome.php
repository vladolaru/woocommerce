<?php
/**
 * PaymentOutcome class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use InvalidArgumentException;

/**
 * Neutral result of a provider payment operation.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class PaymentOutcome {

	/**
	 * Payment completed.
	 *
	 * @var string
	 */
	const STATUS_COMPLETED = 'completed';

	/**
	 * Payment authorized but not captured.
	 *
	 * @var string
	 */
	const STATUS_AUTHORIZED = 'authorized';

	/**
	 * Payment is pending asynchronous provider completion.
	 *
	 * @var string
	 */
	const STATUS_PENDING_ASYNC = 'pending_async';

	/**
	 * Customer must be redirected.
	 *
	 * @var string
	 */
	const STATUS_REQUIRES_REDIRECT = 'requires_redirect';

	/**
	 * Customer action is required.
	 *
	 * @var string
	 */
	const STATUS_REQUIRES_CUSTOMER_ACTION = 'requires_customer_action';

	/**
	 * Payment failed.
	 *
	 * @var string
	 */
	const STATUS_FAILED = 'failed';

	/**
	 * Payment authorization was canceled.
	 *
	 * @var string
	 */
	const STATUS_CANCELED = 'canceled';

	/**
	 * No external payment was needed.
	 *
	 * @var string
	 */
	const STATUS_NO_EXTERNAL_PAYMENT = 'no_external_payment';

	/**
	 * Additional data key: order/refund meta updates.
	 *
	 * @var string
	 */
	public const DATA_META = 'meta';

	/**
	 * Additional data key: order meta keys to delete.
	 *
	 * @var string
	 */
	public const DATA_META_TO_DELETE = 'meta_to_delete';

	/**
	 * Additional data key: order note.
	 *
	 * @var string
	 */
	public const DATA_NOTE = 'note';

	/**
	 * Additional data key: stable order note type.
	 *
	 * @var string
	 */
	public const DATA_NOTE_TYPE = 'note_type';

	/**
	 * Additional data key: exact equivalent order-note renderings.
	 *
	 * @var string
	 */
	public const DATA_NOTE_EQUIVALENTS = 'note_equivalents';

	/**
	 * Additional data key: refund meta updates.
	 *
	 * @var string
	 */
	public const DATA_REFUND_META = 'refund_meta';

	/**
	 * Additional data key: parent order meta updates for a refund.
	 *
	 * @var string
	 */
	public const DATA_ORDER_META = 'order_meta';

	/**
	 * Additional data key: refund order note.
	 *
	 * @var string
	 */
	public const DATA_REFUND_NOTE = 'refund_note';

	/**
	 * Additional data key: provider error code.
	 *
	 * @var string
	 */
	public const DATA_ERROR_CODE = 'error_code';

	/**
	 * Additional data key: provider error message.
	 *
	 * @var string
	 */
	public const DATA_ERROR_MESSAGE = 'error_message';

	/**
	 * Additional data key: safe shopper-facing error message.
	 *
	 * @var string
	 */
	public const DATA_SHOPPER_ERROR_MESSAGE = 'shopper_error_message';

	/**
	 * Additional data key: checkout redirect URL override.
	 *
	 * @var string
	 */
	public const DATA_CHECKOUT_REDIRECT = 'checkout_redirect';

	/**
	 * Data key flagging that a failed outcome must not transition the order status.
	 *
	 * A payment refused before processing (for example by fraud screening) records
	 * its meta and note effects while leaving the order status for the merchant to
	 * decide; the checkout result is still a failure for the shopper.
	 *
	 * @var string
	 */
	public const DATA_PRESERVE_ORDER_STATUS = 'preserve_order_status';

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
	 * Additional neutral outcome data.
	 *
	 * @var array<string,mixed>
	 */
	private array $data;

	/**
	 * Request-scoped provider effect plan.
	 *
	 * @var ProviderOperationEffectPlan|null
	 */
	private ?ProviderOperationEffectPlan $effect_plan = null;

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
	 * Get additional neutral outcome data.
	 *
	 * @return array<string,mixed>
	 */
	public function get_data(): array {
		return $this->data;
	}

	/**
	 * Return a copy carrying a request-scoped provider effect plan.
	 *
	 * The plan is intentionally excluded from outcome data and serialization.
	 *
	 * @param ProviderOperationEffectPlan $effect_plan Provider effect plan.
	 * @return self
	 */
	public function with_effect_plan( ProviderOperationEffectPlan $effect_plan ): self {
		$outcome              = clone $this;
		$outcome->effect_plan = $effect_plan;

		return $outcome;
	}

	/**
	 * Get the request-scoped provider effect plan.
	 *
	 * @return ProviderOperationEffectPlan|null
	 */
	public function get_effect_plan(): ?ProviderOperationEffectPlan {
		return $this->effect_plan;
	}

	/**
	 * Convert the outcome to a machine-readable array.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'status'              => $this->status,
			'provider_payment_id' => $this->provider_payment_id,
			'redirect_url'        => $this->redirect_url,
			'payment_method_id'   => $this->payment_method_id,
			'customer_id'         => $this->customer_id,
			'data'                => $this->data,
		);
	}
}
