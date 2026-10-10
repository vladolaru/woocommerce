<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsApiException class.
 */
class WooPaymentsApiExceptionTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Charge failure ambiguity should distinguish transport uncertainty from definitive provider responses.
	 * @dataProvider charge_failure_ambiguity_provider
	 *
	 * @param string $error_code Provider error code.
	 * @param int    $http_code Provider HTTP code.
	 * @param string $error_type Provider error type.
	 * @param bool   $expected Whether the failure is ambiguous.
	 */
	public function test_charge_failure_ambiguity( string $error_code, int $http_code, string $error_type, bool $expected ): void {
		$exception = new WooPaymentsApiException( 'Request failed.', $error_code, $http_code, $error_type );

		$this->assertSame( $expected, $exception->has_ambiguous_outcome(), 'Only a failure that may have charged should retain a charge idempotency key.' );
	}

	/**
	 * Provide ambiguous and definitive charge failures.
	 *
	 * The platform passes Stripe's status and error body through unchanged (wpcom `wcpay/class-base-controller.php:476-490`
	 * `stripe_proxy_request()`), Stripe's idempotency docs treat a 500 as indeterminate, and the platform can fail after its
	 * Stripe call (`Platform_Failure_Exception`, 502), so a 5xx with a readable body is as ambiguous as one without. Stripe's `idempotency_key_in_use` (409) means a request
	 * under the same key is still running, for example after a connection reset and the same-key transport retry.
	 *
	 * @return array<string,array{string,int,string,bool}>
	 */
	public function charge_failure_ambiguity_provider(): array {
		return array(
			'failed transport request'      => array( 'http_request_failed', 0, '', true ),
			'unexecuted transport request'  => array( 'http_request_not_executed', 0, '', true ),
			'unparseable server response'   => array( 'wcpay_unparseable_or_null_body', 500, '', true ),
			'unstructured server response'  => array( 'wcpay_client_error_code_missing', 503, '', true ),
			'unparseable conflict response' => array( 'wcpay_unparseable_or_null_body', 409, '', false ),
			'structured server response'    => array( 'api_connection_error', 502, '', true ),
			'server error with a body'      => array( 'api_error', 500, 'api_error', true ),
			'in-flight idempotency key'     => array( 'idempotency_key_in_use', 409, 'invalid_request_error', true ),
			'idempotency body mismatch'     => array( 'idempotency_error', 400, 'idempotency_error', false ),
			'card decline'                  => array( 'card_declined', 402, 'card_error', false ),
			'local readiness failure'       => array( 'wcpay_wpcom_not_connected', 409, '', false ),
		);
	}
}
