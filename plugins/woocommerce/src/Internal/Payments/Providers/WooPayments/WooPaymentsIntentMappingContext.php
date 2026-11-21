<?php
/**
 * WooPaymentsIntentMappingContext class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * The order facts the codec needs to map an intent.
 *
 * @since 11.0.0
 * @internal
 */
final class WooPaymentsIntentMappingContext {

	/**
	 * Provider intent type.
	 *
	 * @var string
	 */
	private string $intent_type;

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
	 * Constructor.
	 *
	 * @param string $order_received_url       Order-received URL.
	 * @param string $payment_credential       Submitted payment credential.
	 * @param string $fallback_customer_id     Customer ID used for the request.
	 * @param string $customer_action_redirect Prebuilt customer-action redirect.
	 * @param string $intent_type              Provider intent type.
	 * @param string $provider_redirect_url    Sanitized provider redirect URL.
	 */
	public function __construct(
		string $order_received_url,
		string $payment_credential = '',
		string $fallback_customer_id = '',
		string $customer_action_redirect = '',
		string $intent_type = 'pi',
		string $provider_redirect_url = ''
	) {
		$this->intent_type              = $intent_type;
		$this->order_received_url       = $order_received_url;
		$this->payment_credential       = $payment_credential;
		$this->fallback_customer_id     = $fallback_customer_id;
		$this->customer_action_redirect = $customer_action_redirect;
		$this->provider_redirect_url    = $provider_redirect_url;
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
}
