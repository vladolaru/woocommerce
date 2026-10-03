<?php
/**
 * WooPaymentsIntentMappingContext class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * Immutable runtime facts supplied to the pure WooPayments intent codec.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
final class WooPaymentsIntentMappingContext {

	/**
	 * Provider intent type.
	 *
	 * @var string
	 */
	private string $intent_type;

	/**
	 * Order ID.
	 *
	 * @var int
	 */
	private int $order_id;

	/**
	 * Order-received URL.
	 *
	 * @var string
	 */
	private string $order_received_url;

	/**
	 * Sanitized provider redirect URL.
	 *
	 * @var string
	 */
	private string $provider_redirect_url;

	/**
	 * Submitted payment credential.
	 *
	 * @var string
	 */
	private string $payment_credential;

	/**
	 * Customer ID used for the request.
	 *
	 * @var string
	 */
	private string $fallback_customer_id;

	/**
	 * Prebuilt customer-action redirect.
	 *
	 * @var string
	 */
	private string $customer_action_redirect;

	/**
	 * Persisted order total.
	 *
	 * @var float
	 */
	private float $order_total;

	/**
	 * Persisted provider intent ID.
	 *
	 * @var string
	 */
	private string $persisted_intent_id;

	/**
	 * Persisted payment method ID.
	 *
	 * @var string
	 */
	private string $persisted_payment_method_id;

	/**
	 * Persisted intention status.
	 *
	 * @var string
	 */
	private string $persisted_intention_status;

	/**
	 * Constructor.
	 *
	 * @param string $intent_type                 Provider intent type.
	 * @param int    $order_id                    Order ID.
	 * @param string $order_received_url          Order-received URL.
	 * @param string $payment_credential          Submitted payment credential.
	 * @param string $fallback_customer_id        Customer ID used for the request.
	 * @param string $customer_action_redirect    Prebuilt customer-action redirect.
	 * @param float  $order_total                 Persisted order total.
	 * @param string $persisted_intent_id         Persisted provider intent ID.
	 * @param string $persisted_payment_method_id Persisted payment method ID.
	 * @param string $persisted_intention_status  Persisted intention status.
	 * @param string $provider_redirect_url        Sanitized provider redirect URL.
	 */
	private function __construct(
		string $intent_type,
		int $order_id,
		string $order_received_url,
		string $payment_credential,
		string $fallback_customer_id,
		string $customer_action_redirect,
		float $order_total,
		string $persisted_intent_id,
		string $persisted_payment_method_id,
		string $persisted_intention_status,
		string $provider_redirect_url
	) {
		$this->intent_type                 = $intent_type;
		$this->order_id                    = $order_id;
		$this->order_received_url          = $order_received_url;
		$this->payment_credential          = $payment_credential;
		$this->fallback_customer_id        = $fallback_customer_id;
		$this->customer_action_redirect    = $customer_action_redirect;
		$this->order_total                 = $order_total;
		$this->persisted_intent_id         = $persisted_intent_id;
		$this->persisted_payment_method_id = $persisted_payment_method_id;
		$this->persisted_intention_status  = $persisted_intention_status;
		$this->provider_redirect_url       = $provider_redirect_url;
	}

	/**
	 * Build context for a native provider response.
	 *
	 * @param int    $order_id                 Order ID.
	 * @param string $order_received_url       Order-received URL.
	 * @param string $payment_credential       Submitted payment credential.
	 * @param string $fallback_customer_id     Customer ID used for the request.
	 * @param string $customer_action_redirect Prebuilt customer-action redirect.
	 * @param string $intent_type              Provider intent type.
	 * @param string $provider_redirect_url    Sanitized provider redirect URL.
	 * @return self
	 */
	public static function for_native(
		int $order_id,
		string $order_received_url,
		string $payment_credential = '',
		string $fallback_customer_id = '',
		string $customer_action_redirect = '',
		string $intent_type = 'pi',
		string $provider_redirect_url = ''
	): self {
		return new self(
			$intent_type,
			$order_id,
			$order_received_url,
			$payment_credential,
			$fallback_customer_id,
			$customer_action_redirect,
			0.0,
			'',
			'',
			'',
			$provider_redirect_url
		);
	}

	/**
	 * Build context from a post-bridge legacy order snapshot.
	 *
	 * @param int    $order_id                    Order ID.
	 * @param string $order_received_url          Order-received URL.
	 * @param float  $order_total                 Order total.
	 * @param string $persisted_intent_id         Persisted provider intent ID.
	 * @param string $persisted_payment_method_id Persisted payment method ID.
	 * @param string $persisted_intention_status  Persisted intention status.
	 * @return self
	 */
	public static function for_legacy(
		int $order_id,
		string $order_received_url,
		float $order_total,
		string $persisted_intent_id = '',
		string $persisted_payment_method_id = '',
		string $persisted_intention_status = ''
	): self {
		return new self(
			'pi',
			$order_id,
			$order_received_url,
			'',
			'',
			'',
			$order_total,
			$persisted_intent_id,
			$persisted_payment_method_id,
			$persisted_intention_status,
			''
		);
	}

	/**
	 * Get the sanitized provider redirect URL.
	 *
	 * @return string
	 */
	public function get_provider_redirect_url(): string {
		return $this->provider_redirect_url;
	}

	/**
	 * Get the provider intent type.
	 *
	 * @return string
	 */
	public function get_intent_type(): string {
		return $this->intent_type;
	}

	/**
	 * Get the order ID.
	 *
	 * @return int
	 */
	public function get_order_id(): int {
		return $this->order_id;
	}

	/**
	 * Get the order-received URL.
	 *
	 * @return string
	 */
	public function get_order_received_url(): string {
		return $this->order_received_url;
	}

	/**
	 * Get the submitted payment credential.
	 *
	 * @return string
	 */
	public function get_payment_credential(): string {
		return $this->payment_credential;
	}

	/**
	 * Get the fallback customer ID.
	 *
	 * @return string
	 */
	public function get_fallback_customer_id(): string {
		return $this->fallback_customer_id;
	}

	/**
	 * Get the prebuilt customer-action redirect.
	 *
	 * @return string
	 */
	public function get_customer_action_redirect(): string {
		return $this->customer_action_redirect;
	}

	/**
	 * Get the persisted order total.
	 *
	 * @return float
	 */
	public function get_order_total(): float {
		return $this->order_total;
	}

	/**
	 * Get the persisted provider intent ID.
	 *
	 * @return string
	 */
	public function get_persisted_intent_id(): string {
		return $this->persisted_intent_id;
	}

	/**
	 * Get the persisted payment method ID.
	 *
	 * @return string
	 */
	public function get_persisted_payment_method_id(): string {
		return $this->persisted_payment_method_id;
	}

	/**
	 * Get the persisted intention status.
	 *
	 * @return string
	 */
	public function get_persisted_intention_status(): string {
		return $this->persisted_intention_status;
	}
}
