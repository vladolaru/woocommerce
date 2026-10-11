<?php
/**
 * StripeBillingApi class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;

defined( 'ABSPATH' ) || exit;

/**
 * The platform calls Stripe Billing makes: products, prices, subscriptions, invoices, and the charge and transaction updates.
 *
 * Paths, methods and parameters are client 11.1.0's (`includes/wc-payment-api/class-wc-payments-api-client.php`).
 * Failures surface as `WooPaymentsApiException`, except the two the module handles, which become `StripeBillingException`.
 *
 * @since 11.2.0
 * @internal
 */
class StripeBillingApi {

	/**
	 * Start of the platform message for a customer that already has subscriptions in another currency.
	 */
	private const CANNOT_COMBINE_CURRENCIES_MESSAGE = 'You cannot combine currencies on a single customer.';

	/**
	 * Platform API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsApiClient $api_client Platform API client.
	 */
	final public function init( WooPaymentsApiClient $api_client ): void {
		$this->api_client = $api_client;
	}

	/**
	 * Get a Stripe product.
	 *
	 * @param string $product_id Stripe product ID.
	 * @return array<string,mixed>
	 * @throws WooPaymentsApiException When the request fails.
	 */
	public function get_product_by_id( string $product_id ): array {
		return $this->send_array( array(), 'products/' . $this->id( $product_id ), 'GET' );
	}

	/**
	 * Create a Stripe product and its price.
	 *
	 * @param array<string,mixed> $product_data Product and price data.
	 * @return array<string,mixed> The `wcpay_product_id` and `wcpay_price_id`.
	 * @throws WooPaymentsApiException When the request fails.
	 */
	public function create_product( array $product_data ): array {
		return $this->send_array( $product_data, 'products', 'POST' );
	}

	/**
	 * Update a Stripe product, creating a new price when the price data changed.
	 *
	 * @param string              $product_id   Stripe product ID.
	 * @param array<string,mixed> $product_data Product and price data.
	 * @return array<string,mixed>
	 * @throws WooPaymentsApiException When the ID is empty or the request fails.
	 */
	public function update_product( string $product_id, array $product_data = array() ): array {
		$this->require_id( $product_id, __( 'Product ID is required', 'woocommerce' ), 'wcpay_mandatory_product_id_missing' );

		return $this->send_array( $product_data, 'products/' . $this->id( $product_id ), 'POST' );
	}

	/**
	 * Update a Stripe price.
	 *
	 * @param string              $price_id   Stripe price ID.
	 * @param array<string,mixed> $price_data Price data.
	 * @throws WooPaymentsApiException When the ID is empty or the request fails.
	 */
	public function update_price( string $price_id, array $price_data = array() ): void {
		$this->require_id( $price_id, __( 'Price ID is required', 'woocommerce' ), 'wcpay_mandatory_price_id_missing' );

		$this->send_array( $price_data, 'products/prices/' . $this->id( $price_id ), 'POST' );
	}

	/**
	 * Get a Stripe subscription.
	 *
	 * @param string $subscription_id Stripe subscription ID.
	 * @return array<string,mixed>
	 * @throws WooPaymentsApiException When the request fails.
	 */
	public function get_subscription( string $subscription_id ): array {
		return $this->send_array( array(), 'subscriptions/' . $this->id( $subscription_id ), 'GET' );
	}

	/**
	 * Create a Stripe subscription.
	 *
	 * @param array<string,mixed> $data Subscription data.
	 * @return array<string,mixed>
	 * @throws StripeBillingException When the amount is too small or the customer's currency differs.
	 * @throws WooPaymentsApiException When the request fails otherwise.
	 */
	public function create_subscription( array $data ): array {
		return $this->send_array( $data, 'subscriptions', 'POST' );
	}

	/**
	 * Update a Stripe subscription.
	 *
	 * @param string              $subscription_id Stripe subscription ID.
	 * @param array<string,mixed> $data            Subscription data.
	 * @return array<string,mixed>
	 * @throws WooPaymentsApiException When the request fails.
	 */
	public function update_subscription( string $subscription_id, array $data ): array {
		return $this->send_array( $data, 'subscriptions/' . $this->id( $subscription_id ), 'POST' );
	}

	/**
	 * Cancel a Stripe subscription.
	 *
	 * @param string $subscription_id Stripe subscription ID.
	 * @return array<string,mixed>
	 * @throws WooPaymentsApiException When the request fails.
	 */
	public function cancel_subscription( string $subscription_id ): array {
		return $this->send_array( array(), 'subscriptions/' . $this->id( $subscription_id ), 'DELETE' );
	}

	/**
	 * Update a Stripe subscription item.
	 *
	 * @param string              $subscription_item_id Stripe subscription item ID.
	 * @param array<string,mixed> $data                 Item data.
	 * @return array<string,mixed>
	 * @throws WooPaymentsApiException When the request fails.
	 */
	public function update_subscription_item( string $subscription_item_id, array $data ): array {
		return $this->send_array( $data, 'subscriptions/items/' . $this->id( $subscription_item_id ), 'POST' );
	}

	/**
	 * Pay a Stripe invoice.
	 *
	 * @param string              $invoice_id Stripe invoice ID.
	 * @param array<string,mixed> $data       Payment data, such as `paid_out_of_band`.
	 * @return array<string,mixed>
	 * @throws WooPaymentsApiException When the request fails.
	 */
	public function charge_invoice( string $invoice_id, array $data = array() ): array {
		return $this->send_array( $data, 'invoices/' . $this->id( $invoice_id ) . '/pay', 'POST' );
	}

	/**
	 * Update a Stripe invoice.
	 *
	 * @param string              $invoice_id Stripe invoice ID.
	 * @param array<string,mixed> $data       Invoice data.
	 * @return array<string,mixed>
	 * @throws WooPaymentsApiException When the request fails.
	 */
	public function update_invoice( string $invoice_id, array $data = array() ): array {
		return $this->send_array( $data, 'invoices/' . $this->id( $invoice_id ), 'POST' );
	}

	/**
	 * Get the platform's minimum recurring amount for a currency.
	 *
	 * @param string $currency Currency code.
	 * @return int Minimum amount in the currency's smallest unit.
	 * @throws WooPaymentsApiException When the request fails.
	 */
	public function get_currency_minimum_recurring_amount( string $currency ): int {
		return (int) $this->send( array(), 'subscriptions/minimum_amount/' . $this->id( $currency ), 'GET' );
	}

	/**
	 * Update a charge.
	 *
	 * @param string              $charge_id Charge ID.
	 * @param array<string,mixed> $data      Charge data.
	 * @return array<string,mixed>
	 * @throws WooPaymentsApiException When the request fails.
	 */
	public function update_charge( string $charge_id, array $data = array() ): array {
		return $this->send_array( $data, 'charges/' . $this->id( $charge_id ), 'POST' );
	}

	/**
	 * Update a transaction.
	 *
	 * @param string              $transaction_id Transaction ID.
	 * @param array<string,mixed> $data           Transaction data.
	 * @return array<string,mixed>
	 * @throws WooPaymentsApiException When the request fails.
	 */
	public function update_transaction( string $transaction_id, array $data = array() ): array {
		return $this->send_array( $data, 'transactions/' . $this->id( $transaction_id ), 'POST' );
	}

	/**
	 * Send a request whose answer is a platform object.
	 *
	 * @param array<string,mixed> $params Request params.
	 * @param string              $api    API path.
	 * @param string              $method HTTP method.
	 * @return array<string,mixed>
	 * @throws StripeBillingException When the amount is too small or the customer's currency differs.
	 * @throws WooPaymentsApiException When the request fails otherwise, or the answer is not an object.
	 */
	private function send_array( array $params, string $api, string $method ): array {
		$body = $this->send( $params, $api, $method );
		if ( ! is_array( $body ) ) {
			throw $this->unexpected_body_exception();
		}

		return $body;
	}

	/**
	 * Build the failure for an answer of the wrong type.
	 *
	 * @return WooPaymentsApiException
	 */
	private function unexpected_body_exception(): WooPaymentsApiException {
		return new WooPaymentsApiException( __( 'Unable to decode response from WooPayments.', 'woocommerce' ), 'wcpay_unparseable_or_null_body' );
	}

	/**
	 * Send a request and turn the two failures the module handles into a `StripeBillingException`.
	 *
	 * @param array<string,mixed> $params Request params.
	 * @param string              $api    API path.
	 * @param string              $method HTTP method.
	 * @return mixed Decoded answer.
	 * @throws StripeBillingException When the amount is too small or the customer's currency differs.
	 * @throws WooPaymentsApiException When the request fails otherwise.
	 */
	private function send( array $params, string $api, string $method ) {
		try {
			return $this->api_client->send_site_request( $params, $api, $method );
		} catch ( WooPaymentsApiException $exception ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Rethrows a platform failure; the message is application data, not HTML output.
			throw $this->map_exception( $exception );
		}
	}

	/**
	 * Map a platform failure to the module's exception when the module handles it (client 11.1.0 `request()` `:2845-2895`).
	 *
	 * @param WooPaymentsApiException $exception Platform failure.
	 * @return \RuntimeException
	 */
	private function map_exception( WooPaymentsApiException $exception ): \RuntimeException {
		$data = $exception->get_error_data();
		if ( StripeBillingException::AMOUNT_TOO_SMALL === $exception->get_error_code() && isset( $data['minimum_amount'], $data['currency'] ) ) {
			return new StripeBillingException(
				$exception->getMessage(),
				StripeBillingException::AMOUNT_TOO_SMALL,
				array(
					'minimum_amount' => $data['minimum_amount'],
					'currency'       => (string) $data['currency'],
				),
				$exception
			);
		}

		$message = $exception->getMessage();
		$offset  = strpos( $message, self::CANNOT_COMBINE_CURRENCIES_MESSAGE );
		if ( 'invalid_request_error' === $exception->get_error_code() && false !== $offset ) {
			$platform_message = substr( $message, $offset );
			// The currency closes the message: "... in usd." (client 11.1.0 `request()`).
			$currency = strtoupper( substr( substr( $platform_message, -4 ), 0, 3 ) );
			if ( array_key_exists( $currency, get_woocommerce_currencies() ) ) {
				return new StripeBillingException( $platform_message, StripeBillingException::CANNOT_COMBINE_CURRENCIES, array( 'currency' => $currency ), $exception );
			}
		}

		return $exception;
	}

	/**
	 * Refuse an empty ID, as the client does for products and prices.
	 *
	 * @param string $id         Stripe ID.
	 * @param string $message    Failure message.
	 * @param string $error_code Failure code.
	 * @throws WooPaymentsApiException When the ID is empty or only whitespace.
	 */
	private function require_id( string $id, string $message, string $error_code ): void {
		if ( '' === trim( $id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is internal application state, not HTML output.
			throw new WooPaymentsApiException( $message, $error_code, 400 );
		}
	}

	/**
	 * Validate a Stripe ID used in a path, as the client does.
	 *
	 * @param string $id Stripe ID.
	 * @return string The ID.
	 * @throws WooPaymentsApiException When the ID is empty or has characters other than letters, digits and underscores.
	 */
	private function id( string $id ): string {
		if ( ! preg_match( '/^\w+$/', $id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is internal application state, not HTML output.
			throw new WooPaymentsApiException( __( 'Route param validation failed.', 'woocommerce' ), 'wcpay_route_validation_failure', 400 );
		}

		return $id;
	}
}
