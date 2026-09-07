<?php
/**
 * Standalone behavior test for the secretless provider fixture.
 *
 * @package woopayments-native-ci-fixture
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );
define( 'E2E_WOOPAYMENTS_NATIVE_FIXTURE', true );

$options = array();
$filters = array();

require __DIR__ . '/ci-provider-fixture-test-wp-error.php';

/**
 * Records a WordPress filter registration.
 *
 * @param string   $name Filter name.
 * @param callable $callback Filter callback.
 * @param int      $priority Filter priority.
 * @param int      $accepted_args Accepted argument count.
 */
function add_filter( string $name, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	global $filters;
	$filters[ $name ] = array( $callback, $priority, $accepted_args );
}

/**
 * Records a WordPress action registration.
 *
 * @param string   $name Action name.
 * @param callable $callback Action callback.
 * @param int      $priority Action priority.
 */
function add_action( string $name, callable $callback, int $priority = 10 ): void {
	add_filter( $name, $callback, $priority );
}

/**
 * Parses a URL like the WordPress wrapper.
 *
 * @param string $url URL to parse.
 * @return array<string,mixed>|false
 */
function wp_parse_url( string $url ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This standalone test provides the WordPress wrapper itself.
	return parse_url( $url );
}

/**
 * Reads an option from private fixture state.
 *
 * @param string $name Option name.
 * @param mixed  $default_value Default value.
 * @return mixed
 */
function get_option( string $name, $default_value = false ) {
	global $options;
	return $options[ $name ] ?? $default_value;
}

/**
 * Writes an option to private fixture state.
 *
 * @param string $name Option name.
 * @param mixed  $value Option value.
 */
function update_option( string $name, $value ): bool {
	global $options;
	$options[ $name ] = $value;
	return true;
}

/**
 * Encodes a value like the WordPress wrapper.
 *
 * @param mixed $value Value to encode.
 */
function wp_json_encode( $value ): string {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- This standalone test provides the WordPress wrapper itself.
	return (string) json_encode( $value, JSON_THROW_ON_ERROR );
}

/**
 * Escapes test failure text like the WordPress helper.
 *
 * @param string $text Text to escape.
 */
function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

require __DIR__ . '/ci-provider-fixture.php';

/**
 * Asserts a standalone fixture condition.
 *
 * @param bool   $condition Condition result.
 * @param string $message Failure message.
 * @throws RuntimeException When the condition is false.
 */
function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
}

/**
 * Decodes a fixture HTTP response body.
 *
 * @param array<string,mixed> $response Fixture response.
 * @return array<string,mixed>
 */
function body( $response ): array {
	return json_decode( $response['body'], true, 512, JSON_THROW_ON_ERROR );
}

$fixture = new WooCommerce_WooPayments_Native_CI_Provider_Fixture();
$account = body( $fixture->intercept( false, array( 'method' => 'GET' ), 'https://public-api.wordpress.com/rest/v1.1/sites/777/wcpay/accounts?test_mode=1' ) );
assert_true( 'acct_native_ci' === $account['account_id'], 'account fixture must be deterministic' );

$transactions = body( $fixture->intercept( false, array( 'method' => 'GET' ), 'https://public-api.wordpress.com/rest/v1.1/sites/777/wcpay/transactions?page=1' ) );
assert_true( 1 === count( $transactions['data'] ), 'transaction list must be non-empty' );

$paid    = body( $fixture->intercept( false, array( 'method' => 'GET' ), 'https://public-api.wordpress.com/rest/v1.1/sites/777/wcpay/deposits?status_is=paid' ) );
$pending = body( $fixture->intercept( false, array( 'method' => 'GET' ), 'https://public-api.wordpress.com/rest/v1.1/sites/777/wcpay/deposits?status_is=pending' ) );
assert_true( 'paid' === $paid['data'][0]['status'], 'paid scope must contain the paid fixture' );
assert_true( 'pending' === $pending['data'][0]['status'], 'pending scope must contain the pending fixture' );

$updated = body(
	$fixture->intercept(
		false,
		array(
			'method' => 'POST',
			'body'   => '{"manual_capture":true}',
		),
		'https://public-api.wordpress.com/rest/v1.1/sites/777/wcpay/accounts'
	)
);
assert_true( true === $updated['manual_capture'], 'settings writes must update private fixture state' );
assert_true( true === get_option( 'e2e_woopayments_native_provider_state' )['settings']['manual_capture'], 'private state option must persist settings writes' );
$refreshed = body( $fixture->intercept( false, array( 'method' => 'GET' ), 'https://public-api.wordpress.com/rest/v1.1/sites/777/wcpay/accounts?test_mode=1' ) );
assert_true( true === $refreshed['manual_capture'], 'account refresh must observe the fixture write' );

$unknown = $fixture->intercept( false, array( 'method' => 'GET' ), 'https://public-api.wordpress.com/rest/v1.1/sites/777/wcpay/unrecognized' );
$escaped = $fixture->intercept( false, array( 'method' => 'GET' ), 'https://example.com/escaped' );
assert_true( $unknown instanceof WP_Error && $escaped instanceof WP_Error, 'unknown and escaped requests must fail closed' );
assert_true( 8 === count( get_option( 'e2e_woopayments_native_request_log', array() ) ), 'every request must be recorded' );
assert_true( 2 === count( get_option( 'e2e_woopayments_native_failure_log', array() ) ), 'fail-closed verdicts must remain auditable' );

echo "ci-provider-fixture.php tests passed.\n";
