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

	/**
	 * @testdox A failure with code "$error_code" and status $http_code converts to the REST error $expected_code with status $expected_status.
	 * @dataProvider rest_error_provider
	 *
	 * @param string $error_code      Provider error code.
	 * @param int    $http_code       Provider HTTP code.
	 * @param string $expected_code   Expected REST error code.
	 * @param int    $expected_status Expected REST status.
	 */
	public function test_to_wp_error_answers_a_4xx_or_5xx_status( string $error_code, int $http_code, string $expected_code, int $expected_status ): void {
		$error = ( new WooPaymentsApiException( 'Request failed.', $error_code, $http_code ) )->to_wp_error();

		$this->assertSame( $expected_code, $error->get_error_code() );
		$this->assertSame( 'Request failed.', $error->get_error_message() );
		$this->assertSame( array( 'status' => $expected_status ), $error->get_error_data() );
	}

	/**
	 * Provide platform failures at and around the 400-599 bounds, an unreadable 200 answer, a request that failed before
	 * any answer (status 0) and a failure without a code.
	 *
	 * @return array<string,array{string,int,string,int}>
	 */
	public function rest_error_provider(): array {
		return array(
			'missing resource' => array( 'resource_missing', 404, 'resource_missing', 404 ),
			'lowest 4xx'       => array( 'invalid_request_error', 400, 'invalid_request_error', 400 ),
			'highest 5xx'      => array( 'server_error', 599, 'server_error', 599 ),
			'below the range'  => array( 'redirect', 399, 'redirect', 500 ),
			'above the range'  => array( 'out_of_range', 600, 'out_of_range', 500 ),
			'unreadable 200'   => array( 'wcpay_unparseable_or_null_body', 200, 'wcpay_unparseable_or_null_body', 500 ),
			'no answer'        => array( 'wcpay_client_unable_to_encode_json', 0, 'wcpay_client_unable_to_encode_json', 500 ),
			'no code'          => array( '', 404, 'wcpay_api_error', 404 ),
		);
	}

	/**
	 * The extension's REST base answers the in-person payment routes with the failure's code and message and no status
	 * (includes/admin/class-wc-payments-rest-controller.php:48-57), which WordPress sends as 500; it keeps no error for an
	 * empty code.
	 *
	 * @testdox A failure with code "$error_code" and status $http_code converts to the REST error "$expected_code" without a status.
	 * @dataProvider rest_error_without_status_provider
	 *
	 * @param string $error_code       Provider error code.
	 * @param int    $http_code        Provider HTTP code.
	 * @param string $expected_code    Expected REST error code.
	 * @param string $expected_message Expected REST error message.
	 */
	public function test_to_wp_error_without_status_keeps_the_code_and_message( string $error_code, int $http_code, string $expected_code, string $expected_message ): void {
		$error = ( new WooPaymentsApiException( 'Request failed.', $error_code, $http_code ) )->to_wp_error_without_status();

		$this->assertSame( $expected_code, $error->get_error_code() );
		$this->assertSame( $expected_message, $error->get_error_message() );
		$this->assertNull( $error->get_error_data() );
	}

	/**
	 * Provide a platform refusal, a missing resource, an unreadable 200 answer and a failure without a code.
	 *
	 * @return array<string,array{string,int,string,string}>
	 */
	public function rest_error_without_status_provider(): array {
		return array(
			'refused request'  => array( 'invalid_request_error', 400, 'invalid_request_error', 'Request failed.' ),
			'missing resource' => array( 'resource_missing', 404, 'resource_missing', 'Request failed.' ),
			'unreadable 200'   => array( 'wcpay_unparseable_or_null_body', 200, 'wcpay_unparseable_or_null_body', 'Request failed.' ),
			'no code'          => array( '', 404, '', '' ),
		);
	}
}
