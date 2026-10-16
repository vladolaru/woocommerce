<?php
/**
 * PaymentOperationContext class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use WC_Order;

/**
 * Carries the order, gateway, amount and payment data for one checkout, refund, capture or cancel.
 *
 * @since 11.0.0
 * @internal
 */
class PaymentOperationContext {

	/**
	 * Payment data key the runtime sets on every refund: the ID of the WooCommerce refund row WooCommerce is refunding in
	 * this request, validated under the order payment lock before the provider call, or 0 when there is none.
	 *
	 * @since 11.2.0
	 */
	public const PAYMENT_DATA_REFUND_ID = 'wc_refund_id';

	/**
	 * Order being acted on.
	 *
	 * @var WC_Order
	 */
	private WC_Order $order;

	/**
	 * Gateway ID.
	 *
	 * @var string
	 */
	private string $gateway_id;

	/**
	 * Payment method ID.
	 *
	 * @var string
	 */
	private string $payment_method_id;

	/**
	 * Operation amount.
	 *
	 * @var float|null
	 */
	private ?float $amount;

	/**
	 * Generic payment-operation data.
	 *
	 * @var array<string,mixed>
	 */
	private array $payment_data;

	/**
	 * Provider-scoped data that must not leak into generic payments code.
	 *
	 * @var array<string,mixed>
	 */
	private array $provider_data;

	/**
	 * Constructor.
	 *
	 * @param WC_Order            $order             Order being acted on.
	 * @param string              $gateway_id        Gateway ID.
	 * @param string              $payment_method_id Payment method ID.
	 * @param array<string,mixed> $payment_data      Generic payment-operation data.
	 * @param array<string,mixed> $provider_data     Provider-scoped data.
	 * @param float|null          $amount            Operation amount.
	 */
	public function __construct( WC_Order $order, string $gateway_id, string $payment_method_id = '', array $payment_data = array(), array $provider_data = array(), ?float $amount = null ) {
		$this->order             = $order;
		$this->gateway_id        = $gateway_id;
		$this->payment_method_id = $payment_method_id;
		$this->payment_data      = $payment_data;
		$this->provider_data     = $provider_data;
		$this->amount            = $amount;
	}

	/**
	 * Create a checkout payment context.
	 *
	 * @param WC_Order            $order             Order being charged.
	 * @param string              $gateway_id        Gateway ID.
	 * @param string              $payment_method_id Payment method ID.
	 * @param array<string,mixed> $payment_data      Generic payment-operation data. The runtime reads 'payment_token': the saved
	 *                                               payment token ID the shopper chose, or 'new', the value WooCommerce's saved
	 *                                               payment methods field posts for a new method.
	 * @param array<string,mixed> $provider_data     Provider-scoped data.
	 * @return self
	 */
	public static function for_checkout( WC_Order $order, string $gateway_id, string $payment_method_id = '', array $payment_data = array(), array $provider_data = array() ): self {
		return new self( $order, $gateway_id, $payment_method_id, $payment_data, $provider_data );
	}

	/**
	 * Create a refund payment context.
	 *
	 * The amount and reason are also in the payment data, where providers read them.
	 *
	 * @param WC_Order            $order         Order being refunded.
	 * @param string              $gateway_id    Gateway ID.
	 * @param float               $amount        Refund amount.
	 * @param string              $reason        Refund reason.
	 * @param array<string,mixed> $provider_data Provider-scoped data.
	 * @return self
	 */
	public static function for_refund( WC_Order $order, string $gateway_id, float $amount, string $reason = '', array $provider_data = array() ): self {
		return new self(
			$order,
			$gateway_id,
			'',
			array(
				'amount' => $amount,
				'reason' => $reason,
			),
			$provider_data,
			$amount
		);
	}

	/**
	 * Create a capture payment context.
	 *
	 * @param WC_Order            $order         Order being captured.
	 * @param string              $gateway_id    Gateway ID.
	 * @param float|null          $amount        Capture amount, or null for the order total.
	 * @param array<string,mixed> $provider_data Provider-scoped data.
	 * @return self
	 */
	public static function for_capture( WC_Order $order, string $gateway_id, ?float $amount = null, array $provider_data = array() ): self {
		return new self( $order, $gateway_id, '', array(), $provider_data, $amount );
	}

	/**
	 * Create a cancel payment context.
	 *
	 * @param WC_Order            $order         Order whose authorization is being canceled.
	 * @param string              $gateway_id    Gateway ID.
	 * @param array<string,mixed> $provider_data Provider-scoped data.
	 * @return self
	 */
	public static function for_cancel( WC_Order $order, string $gateway_id, array $provider_data = array() ): self {
		return new self( $order, $gateway_id, '', array(), $provider_data );
	}

	/**
	 * Get the order.
	 *
	 * @return WC_Order
	 */
	public function get_order(): WC_Order {
		return $this->order;
	}

	/**
	 * Get the order ID.
	 *
	 * @return int
	 */
	public function get_order_id(): int {
		return (int) $this->order->get_id();
	}

	/**
	 * Get the gateway ID.
	 *
	 * @return string
	 */
	public function get_gateway_id(): string {
		return $this->gateway_id;
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
	 * Get the operation amount.
	 *
	 * @return float|null
	 */
	public function get_amount(): ?float {
		return $this->amount;
	}

	/**
	 * Get generic payment-operation data.
	 *
	 * @return array<string,mixed>
	 */
	public function get_payment_data(): array {
		return $this->payment_data;
	}

	/**
	 * Get provider-scoped data.
	 *
	 * @return array<string,mixed>
	 */
	public function get_provider_data(): array {
		return $this->provider_data;
	}

	/**
	 * Get a copy of this context for another object of the same order, such as one read again under the order payment lock.
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order $order Order object.
	 * @return self
	 */
	public function with_order( WC_Order $order ): self {
		return new self( $order, $this->gateway_id, $this->payment_method_id, $this->payment_data, $this->provider_data, $this->amount );
	}

	/**
	 * Get a copy of this context with more payment data.
	 *
	 * @since 11.2.0
	 *
	 * @param array<string,mixed> $payment_data Payment data to add; a key already present is replaced.
	 * @return self
	 */
	public function with_payment_data( array $payment_data ): self {
		return new self( $this->order, $this->gateway_id, $this->payment_method_id, array_merge( $this->payment_data, $payment_data ), $this->provider_data, $this->amount );
	}
}
