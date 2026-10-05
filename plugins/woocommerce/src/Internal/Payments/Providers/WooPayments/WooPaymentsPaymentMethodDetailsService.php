<?php
/**
 * WooPaymentsPaymentMethodDetailsService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Exception;
use Throwable;

/**
 * Retrieves WooPayments payment method details through a namespaced core seam.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsPaymentMethodDetailsService {

	/**
	 * WooPayments legacy runtime.
	 *
	 * @var WooPaymentsLegacyRuntime
	 */
	private WooPaymentsLegacyRuntime $legacy_runtime;

	/**
	 * Native WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsLegacyRuntime     $legacy_runtime WooPayments legacy runtime.
	 * @param WooPaymentsApiClient         $api_client     Native WooPayments API client.
	 * @param NativePaymentsRuntimeArbiter $arbiter        Runtime owner arbiter.
	 */
	final public function init( WooPaymentsLegacyRuntime $legacy_runtime, WooPaymentsApiClient $api_client, NativePaymentsRuntimeArbiter $arbiter ): void {
		$this->legacy_runtime = $legacy_runtime;
		$this->api_client     = $api_client;
		$this->arbiter        = $arbiter;
	}

	/**
	 * Get payment method details from the active WooPayments runtime or native transport.
	 *
	 * @since 11.0.0
	 *
	 * A failed fetch is logged and returns no details. A PHP Error is not caught: the client's callers catch only
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
	 * For core's PaymentInfo, which logs a failed fetch under `payment-info` whatever the WooPayments logging setting,
	 * as trunk does (review 37 F4). With neither the plugin nor native running there is nothing to ask, so it returns no
	 * details without a request, as trunk's PaymentInfo did without the plugin.
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

		$plugin_runtime_loaded = $this->legacy_runtime->is_loaded();
		if ( ! $plugin_runtime_loaded && ! $this->arbiter->should_native_register() ) {
			return array();
		}

		if ( ! $plugin_runtime_loaded ) {
			return $this->api_client->get_payment_method( $payment_method_id );
		}

		$api_client = $this->legacy_runtime->get_payments_api_client();
		if ( ! is_object( $api_client ) || ! is_callable( array( $api_client, 'get_payment_method' ) ) ) {
			return array();
		}

		$details = $api_client->get_payment_method( $payment_method_id );

		return is_array( $details ) ? $details : array();
	}

	/**
	 * Log a payment method details fetch error through the gated WooPayments logger, as the client's callers do (gw:5088),
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
