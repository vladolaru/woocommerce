<?php
/**
 * Local A5 WooPayments native transport continuity probe.
 *
 * Run through WP-CLI:
 * wp eval-file tools/woopayments-merge/a5-transport-continuity.php
 *
 * @package WooCommerce\Tools\WooPaymentsMerge
 */

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsHttpClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;

if ( ! class_exists( WooPaymentsApiClient::class ) || ! class_exists( WooPaymentsHttpClient::class ) || ! class_exists( WooPaymentsAccountService::class ) ) {
	woopayments_merge_a5_transport_emit(
		array(
			'ready'    => false,
			'failures' => array( 'native_woopayments_classes_unavailable' ),
		),
		1
	);
}

if ( ! class_exists( 'WooPaymentsMergeA5RecordingHttpClient' ) ) {
	/**
	 * Recording transport that prevents remote I/O.
	 */
	class WooPaymentsMergeA5RecordingHttpClient extends WooPaymentsHttpClient {
		/**
		 * Captured requests.
		 *
		 * @var array<int,array<string,mixed>>
		 */
		public array $requests = array();

		/**
		 * Whether the local fake transport is connected.
		 *
		 * @return bool
		 */
		public function is_connected(): bool {
			return true;
		}

		/**
		 * Return a deterministic fake blog ID.
		 *
		 * @return int|null
		 */
		public function get_blog_id(): ?int {
			return 123456789;
		}

		/**
		 * Capture the request and return a local response.
		 *
		 * @param string      $method HTTP method.
		 * @param string      $path Request path.
		 * @param array       $headers Request headers.
		 * @param string|null $body Request body.
		 * @param int         $timeout Timeout.
		 * @param bool        $use_user_token Whether user token auth was requested.
		 * @param bool        $blocking Whether the request blocks.
		 * @return array<string,mixed>
		 */
		public function request( string $method, string $path, array $headers = array(), ?string $body = null, int $timeout = 70, bool $use_user_token = false, bool $blocking = true ) {
			$this->requests[] = array(
				'method'         => $method,
				'path'           => $path,
				'headers'        => $headers,
				'body'           => null === $body ? null : json_decode( $body, true ),
				'timeout'        => $timeout,
				'use_user_token' => $use_user_token,
				'blocking'       => $blocking,
			);

			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( array( 'success' => true ) ),
			);
		}
	}
}

$transport       = new WooPaymentsMergeA5RecordingHttpClient();
$account_service = wc_get_container()->get( WooPaymentsAccountService::class );
$api_client      = new WooPaymentsApiClient();
$failures        = array();

$api_client->init( $transport, $account_service );

try {
	$api_client->update_account(
		array(
			'statement_descriptor' => 'A5 LOCAL PROBE',
		)
	);
} catch ( Throwable $e ) {
	$failures[] = 'probe_exception:' . $e->getMessage();
}

$request = $transport->requests[0] ?? null;
if ( ! is_array( $request ) ) {
	$failures[] = 'no_request_captured';
} else {
	$path    = (string) $request['path'];
	$headers = is_array( $request['headers'] ) ? $request['headers'] : array();

	if ( ! preg_match( '#^/sites/[0-9]+/wcpay/accounts$#', $path ) ) {
		$failures[] = 'unexpected_path';
	}

	if ( false !== strpos( $path, '/transact' ) ) {
		$failures[] = 'transact_path_observed';
	}

	foreach ( array( 'User-Agent', 'Content-Type', 'Idempotency-Key', 'X-Request-Initiated' ) as $header ) {
		if ( empty( $headers[ $header ] ) ) {
			$failures[] = 'missing_header:' . $header;
		}
	}

		if ( ! isset( $headers['Content-Type'] ) || 'application/json; charset=utf-8' !== $headers['Content-Type'] ) {
			$failures[] = 'unexpected_content_type';
		}

		if ( ! isset( $headers['User-Agent'] ) || 'WooCommerce Payments/10.8.0' !== $headers['User-Agent'] ) {
			$failures[] = 'unexpected_user_agent';
		}

		if ( empty( $headers['Idempotency-Key'] ) ) {
			$failures[] = 'empty_idempotency_key';
		}

	if ( isset( $request['body'] ) && is_array( $request['body'] ) && array_key_exists( 'idempotency_key', $request['body'] ) ) {
		$failures[] = 'idempotency_key_leaked_to_body';
	}

	if ( empty( $request['use_user_token'] ) ) {
		$failures[] = 'account_update_user_token_not_observed';
	}
}

$payload = array(
	'ready'             => empty( $failures ),
	'failures'          => array_values( array_unique( $failures ) ),
	'captured_requests' => array_map( 'woopayments_merge_a5_transport_sanitize_request', $transport->requests ),
);

woopayments_merge_a5_transport_emit( $payload, $payload['ready'] ? 0 : 1 );

/**
 * Keep captured request output non-secret.
 *
 * @param array<string,mixed> $request Request.
 * @return array<string,mixed>
 */
function woopayments_merge_a5_transport_sanitize_request( array $request ): array {
	return array(
		'method'         => (string) ( $request['method'] ?? '' ),
		'path'           => (string) ( $request['path'] ?? '' ),
		'headers'        => array_intersect_key(
			is_array( $request['headers'] ?? null ) ? $request['headers'] : array(),
			array_flip( array( 'User-Agent', 'Content-Type', 'Idempotency-Key', 'X-Request-Initiated' ) )
		),
		'body'           => woopayments_merge_a5_transport_redact( $request['body'] ?? null ),
		'timeout'        => (int) ( $request['timeout'] ?? 0 ),
		'use_user_token' => (bool) ( $request['use_user_token'] ?? false ),
		'blocking'       => (bool) ( $request['blocking'] ?? false ),
	);
}

/**
 * Redact defensive token-like body keys.
 *
 * @param mixed $value Value.
 * @return mixed
 */
function woopayments_merge_a5_transport_redact( $value ) {
	if ( is_array( $value ) ) {
		$redacted = array();
		foreach ( $value as $key => $child ) {
			$key_string = is_string( $key ) ? $key : (string) $key;
			$redacted[ $key ] = preg_match( '/secret|authorization|password|nonce|token/i', $key_string )
				? '[redacted]'
				: woopayments_merge_a5_transport_redact( $child );
		}
		return $redacted;
	}

	return $value;
}

/**
 * Emit JSON and exit through WP-CLI when available.
 *
 * @param array<string,mixed> $payload Payload.
 * @param int                 $exit_code Exit code.
 */
function woopayments_merge_a5_transport_emit( array $payload, int $exit_code ): void {
	$json = wp_json_encode( $payload, JSON_PRETTY_PRINT );
	if ( false === $json ) {
		$json      = '{"ready":false,"failures":["json_encode_failed"]}';
		$exit_code = 1;
	}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::line( $json );
		if ( 0 !== $exit_code ) {
			WP_CLI::halt( $exit_code );
		}
		return;
	}

	echo $json . PHP_EOL;
	exit( $exit_code );
}
