<?php
/**
 * WooPaymentsPaymentMethodDetailsService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Exception;
use Throwable;

/**
 * Fetches a payment method's details from the platform, through the WooPayments extension's API client while the
 * extension is loaded.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsPaymentMethodDetailsService {

	/**
	 * The WooPaymentsExtensionRuntime instance.
	 *
	 * @var WooPaymentsExtensionRuntime
	 */
	private WooPaymentsExtensionRuntime $extension_runtime;

	/**
	 * WooPayments API client.
	 *
	 * @var Client
	 */
	private Client $api_client;

	/**
	 * Runtime owner arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $arbiter;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsExtensionRuntime $extension_runtime The WooPaymentsExtensionRuntime instance.
	 * @param Client                      $api_client     WooPayments API client.
	 * @param WooPaymentsRuntimeArbiter   $arbiter        Runtime owner arbiter.
	 */
	final public function init( WooPaymentsExtensionRuntime $extension_runtime, Client $api_client, WooPaymentsRuntimeArbiter $arbiter ): void {
		$this->extension_runtime = $extension_runtime;
		$this->api_client        = $api_client;
		$this->arbiter           = $arbiter;
	}

	/**
	 * Get a payment method's details, or none when they cannot be fetched.
	 *
	 * @since 11.0.0
	 *
	 * A failed fetch is logged and returns no details. A PHP Error is not caught: client 11.1.0's callers catch only
	 * Exception (`class-wc-payments-token-service.php:136` does not catch at all), so the caller decides.
	 *
	 * @param string $payment_method_id Payment method ID.
	 * @return array<string,mixed>
	 * @throws Throwable A PHP Error raised while fetching the payment method.
	 */
	public function get_payment_method_details( string $payment_method_id ): array {
		try {
			return $this->fetch_payment_method_details( $payment_method_id );
		} catch ( Exception $exception ) {
			$this->log_fetch_error( $payment_method_id, $exception );
			return array();
		}
	}

	/**
	 * Get payment method details, letting every failure through to the caller.
	 *
	 * For core's PaymentInfo, which logs a failed fetch under `payment-info` whatever the WooPayments logging setting.
	 * With neither the WooPayments extension loaded nor the built-in WooPayments owning payments, there is nothing to ask,
	 * so it returns no details without a request.
	 *
	 * @since 11.2.0
	 *
	 * @param string $payment_method_id Payment method ID.
	 * @return array<string,mixed>
	 * @throws Throwable When the fetch fails.
	 */
	public function fetch_payment_method_details( string $payment_method_id ): array {
		if ( '' === $payment_method_id ) {
			return array();
		}

		$plugin_runtime_loaded = $this->extension_runtime->is_loaded();
		if ( ! $plugin_runtime_loaded && ! $this->arbiter->is_builtin_owner() ) {
			return array();
		}

		if ( ! $plugin_runtime_loaded ) {
			return $this->api_client->get_payment_method( $payment_method_id );
		}

		$api_client = $this->extension_runtime->get_payments_api_client();
		if ( ! is_object( $api_client ) || ! is_callable( array( $api_client, 'get_payment_method' ) ) ) {
			return array();
		}

		$details = $api_client->get_payment_method( $payment_method_id );

		return is_array( $details ) ? $details : array();
	}

	/**
	 * Log a failed payment method details fetch only when WooPayments logging is on, as client 11.1.0's callers do (gw:5086),
	 * with the platform's status and code instead of its message.
	 *
	 * @param string    $payment_method_id Payment method ID.
	 * @param Exception $exception         Exception.
	 */
	private function log_fetch_error( string $payment_method_id, Exception $exception ): void {
		wc_get_container()->get( WooPaymentsLogger::class )->log_throwable(
			'Error retrieving WooPayments payment method details for ' . $payment_method_id . '.',
			$exception,
			array( 'payment_method_id' => $payment_method_id )
		);
	}
}
