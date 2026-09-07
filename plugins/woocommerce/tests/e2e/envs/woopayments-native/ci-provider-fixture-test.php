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
/** @var ArrayObject<string,array{0:callable,1:int,2:int}> $filters */
$filters = new ArrayObject();

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
 * @param string    $name Option name.
 * @param mixed     $value Option value.
 * @param bool|null $autoload Whether WordPress should autoload the option.
 */
function update_option( string $name, $value, ?bool $autoload = null ): bool {
	global $options;
	unset( $autoload );
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

/**
 * Returns the deterministic MU-plugin URL used by the standalone test.
 *
 * @param string $file Plugin file path.
 */
function plugin_dir_url( string $file ): string {
	unset( $file );
	return 'http://fixture.test/wp-content/mu-plugins/';
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
assert_true(
	isset( $filters['script_loader_src'] ) && PHP_INT_MAX === $filters['script_loader_src'][1] && 2 === $filters['script_loader_src'][2],
	'fixture must filter the final script source with both source and handle'
);
assert_true(
	'http://fixture.test/wp-content/mu-plugins/stripe-messaging-adapter.js' === $fixture->stripe_adapter_src( 'https://js.stripe.com/v3/', 'stripe' ),
	'exact stripe handle must emit the local MU adapter URL'
);
assert_true(
	'https://cdn.example.test/unrelated.js' === $fixture->stripe_adapter_src( 'https://cdn.example.test/unrelated.js', 'unrelated' ),
	'unrelated script handles and sources must remain unchanged'
);
$private_options  = $fixture->jetpack_private_options();
$blog_token_parts = explode( '.', $private_options['blog_token'] );
assert_true(
	2 === count( $blog_token_parts ) && '' !== $blog_token_parts[0] && '' !== $blog_token_parts[1],
	'dummy Jetpack blog token must satisfy the two-part key.secret parser'
);
$user_token_parts = explode( '.', $private_options['user_tokens'][1] );
assert_true(
	3 === count( $user_token_parts ) && '' !== $user_token_parts[0] && '' !== $user_token_parts[1] && '1' === $user_token_parts[2],
	'dummy Jetpack user token must satisfy the stored key.secret.user_id parser before signing reduces it to key.secret'
);
$cache = $fixture->account_cache();
assert_true( isset( $filters['pre_option_wcpay_account_data'] ), 'fixture must own account-cache reads for consistent provider state' );
assert_true( 'acct_native_ci' === $cache['data']['account_id'], 'fixture account cache must establish a connected account' );
assert_true( true === $cache['data']['payments_enabled'], 'fixture account cache must establish payment readiness' );
assert_true( '+10000000000' === $cache['data']['business_profile']['support_phone'], 'fixture account must use Core\'s explicitly valid test-account support phone' );
assert_true( array( 'card', 'klarna' ) === array_keys( $cache['data']['fees'] ), 'fixture account fees must make card and Klarna available through the real settings computation' );
assert_true(
	array( 'usd', 'eur', 'aud', 'cad', 'chf', 'gbp', 'jpy', 'nzd', 'sek' ) === $cache['data']['customer_currencies']['supported'],
	'fixture account must advertise every currency exercised by the readonly catalog contracts'
);
assert_true(
	array(
		'account_status' => array( 'text' => 'Enabled' ),
		'payout_status'  => array( 'text' => 'Enabled' ),
		'banner'         => null,
	) === $cache['data']['account_details'],
	'fixture account must carry the exact account-details structure projected by the real overview service'
);

$passthrough       = array( 'fixture_passthrough' => true );
$requests_before   = get_option( 'e2e_woopayments_native_request_log', array() );
$failures_before   = get_option( 'e2e_woopayments_native_failure_log', array() );
$ambient_responses = array(
	$fixture->intercept( $passthrough, array( 'method' => 'GET' ), 'https://example.test/unrelated' ),
	$fixture->intercept( $passthrough, array( 'method' => 'DELETE' ), 'https://public-api.wordpress.com.evil.test/wpcom/v2/sites/777/wcpay/accounts' ),
);
assert_true(
	array( $passthrough, $passthrough ) === $ambient_responses,
	'non-provider hosts must preserve the existing preempted response unchanged'
);
assert_true(
	get_option( 'e2e_woopayments_native_request_log', array() ) === $requests_before
		&& get_option( 'e2e_woopayments_native_failure_log', array() ) === $failures_before,
	'non-provider traffic must remain unrecorded by the provider fixture'
);

$package_versions_url = 'https://public-api.wordpress.com/wpcom/v2/sites/777/jetpack-package-versions?body-hash=hash&nonce=nonce&signature=signature&timestamp=1&token=dummyblog%3A1%3A0';
$package_versions     = $fixture->intercept(
	false,
	array(
		'method' => 'POST',
		'body'   => '{"package_versions":{"connection":"6.19.2"}}',
	),
	$package_versions_url
);
assert_true( ! $package_versions instanceof WP_Error, 'exact Jetpack package-version report must receive a deterministic response' );
$reordered_package_versions = $fixture->intercept(
	false,
	array(
		'method' => 'POST',
		'body'   => '{"package_versions":{"connection":"6.19.2"}}',
	),
	'https://public-api.wordpress.com/wpcom/v2/sites/777/jetpack-package-versions?token=dummyblog%3A1%3A0&timestamp=1&signature=signature&nonce=nonce&body-hash=hash'
);
assert_true( ! $reordered_package_versions instanceof WP_Error, 'Jetpack signature keys must be validated as a canonical set, independent of transport order' );
assert_true(
	$fixture->intercept(
		false,
		array(
			'method' => 'POST',
			'body'   => '{"package_versions":{"connection":"6.19.2"}}',
		),
		str_replace( '&nonce=nonce', '', $package_versions_url )
	) instanceof WP_Error,
	'Jetpack package-version report must fail closed when a required signature key is missing'
);

$store_setup_snapshot = array(
	'gateway'                                     => array(
		'enabled'              => true,
		'test_mode'            => true,
		'test_mode_onboarding' => true,
	),
	'payment_methods'                             => array(
		'available'  => array( 'card', 'klarna' ),
		'enabled'    => array( 'card', 'klarna' ),
		'disabled'   => array(),
		'duplicates' => array(),
	),
	'provider_capabilities'                       => array(
		'available' => array( 'card_payments', 'klarna_payments' ),
		'enabled'   => array( 'card_payments', 'klarna_payments' ),
		'disabled'  => array(),
	),
	'express_checkout_in_payment_methods_enabled' => 'no',
	'saved_cards_enabled'                         => true,
	'manual_capture_enabled'                      => false,
	'debug_log_enabled'                           => false,
	'payment_request'                             => array(
		'enabled'              => false,
		'enabled_locations'    => array(),
		'button_type'          => 'default',
		'button_size'          => 'medium',
		'button_theme'         => 'dark',
		'button_border_radius' => 4,
	),
	'woopay'                                      => array(
		'enabled'                 => true,
		'enabled_locations'       => array( 'product', 'cart', 'checkout' ),
		'store_logo'              => '',
		'custom_message'          => '',
		'invalid_extension_found' => false,
	),
	'multi_currency_enabled'                      => true,
	'stripe_billing_enabled'                      => false,
	'plugin'                                      => array(
		'version'              => '11.2.0',
		'activation_timestamp' => null,
	),
	'wp_setup'                                    => array(
		'name'           => 'WooCommerce Core E2E Test Suite',
		'url'            => 'http://localhost:18086',
		'active_theme'   => array( 'name' => 'Twenty Twenty-Three' ),
		'active_plugins' => array(),
		'version'        => '7.1',
		'locale'         => 'en_US',
	),
	'wc_setup'                                    => array(
		'version'                     => '11.2.0',
		'store_id'                    => 'store-id',
		'currency'                    => 'USD',
		'tracking_enabled'            => false,
		'registered_payment_gateways' => array( 'woocommerce_payments' ),
		'enabled_payment_gateways'    => array( 'woocommerce_payments' ),
		'wc_subscriptions_active'     => false,
		'wc_subscriptions_version'    => '',
	),
);
$store_setup_url      = 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/accounts/store_setup?body-hash=hash&nonce=nonce&signature=signature&timestamp=1&token=dummyblog%3A1%3A0';
$store_setup_body     = wp_json_encode(
	array(
		'snapshot'  => $store_setup_snapshot,
		'test_mode' => true,
	)
);
$store_setup_response = $fixture->intercept(
	false,
	array(
		'method' => 'POST',
		'body'   => $store_setup_body,
	),
	$store_setup_url
);
assert_true( ! $store_setup_response instanceof WP_Error, 'exact production store-setup snapshot must receive a deterministic response: ' . ( $store_setup_response instanceof WP_Error ? $store_setup_response->message : '' ) );
$invalid_store_setup_bodies = array(
	wp_json_encode(
		array(
			'snapshot'  => $store_setup_snapshot,
			'test_mode' => 'true',
		)
	),
	wp_json_encode(
		array(
			'snapshot'  => array_merge( $store_setup_snapshot, array( 'unknown' => true ) ),
			'test_mode' => true,
		)
	),
	wp_json_encode(
		array(
			'snapshot'  => array_diff_key( $store_setup_snapshot, array( 'gateway' => true ) ),
			'test_mode' => true,
		)
	),
	wp_json_encode(
		array(
			'snapshot'  => array_merge( $store_setup_snapshot, array( 'gateway' => array() ) ),
			'test_mode' => true,
		)
	),
	wp_json_encode(
		array(
			'snapshot'  => $store_setup_snapshot,
			'test_mode' => true,
			'unknown'   => true,
		)
	),
);
foreach ( $invalid_store_setup_bodies as $invalid_store_setup_body ) {
	assert_true(
		$fixture->intercept(
			false,
			array(
				'method' => 'POST',
				'body'   => $invalid_store_setup_body,
			),
			$store_setup_url
		) instanceof WP_Error,
		'mutated store-setup snapshot must fail closed'
	);
}
assert_true(
	$fixture->intercept(
		false,
		array(
			'method' => 'POST',
			'body'   => $store_setup_body,
		),
		$store_setup_url . '&unknown=1'
	) instanceof WP_Error,
	'store-setup request must reject unknown query keys'
);
foreach (
	array(
		array( str_replace( 'https://', 'http://', $package_versions_url ), '{"package_versions":{"connection":"6.19.2"}}' ),
		array( $package_versions_url . '&unknown=1', '{"package_versions":{"connection":"6.19.2"}}' ),
		array( $package_versions_url, '{"package_versions":{"connection":"6.19.2"},"unknown":true}' ),
		array( $package_versions_url, '{"package_versions":{"connection":"0.0.0"}}' ),
	) as list( $url, $request_body )
) {
	assert_true(
		$fixture->intercept(
			false,
			array(
				'method' => 'POST',
				'body'   => $request_body,
			),
			$url
		) instanceof WP_Error,
		"mutated Jetpack package-version report must fail closed: $url $request_body"
	);
}

$recommendations = body( $fixture->intercept( false, array( 'method' => 'GET' ), 'https://public-api.wordpress.com/wpcom/v2/wcpay/payment_methods/recommended?country_code=US&locale=en_US' ) );
assert_true(
	array(
		array(
			'id'    => 'card',
			'title' => 'Cards',
		),
		array(
			'id'    => 'klarna',
			'title' => 'Klarna',
		),
	) === $recommendations,
	'public payment recommendations must use the production id/title response schema'
);
$fraud_services = body( $fixture->intercept( false, array( 'method' => 'GET' ), 'https://public-api.wordpress.com/wpcom/v2/wcpay/accounts/fraud_services' ) );
assert_true( array() !== $fraud_services, 'public fraud-service configuration must be deterministic and non-empty' );

$account = body( $fixture->intercept( false, array( 'method' => 'GET' ), 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/accounts?test_mode=1' ) );
assert_true( 'acct_native_ci' === $account['account_id'], 'account fixture must be deterministic' );
assert_true( true === $fixture->audit()['state_restored'], 'untouched fixture state must satisfy the audit baseline' );

$woopay_compatibility_url = 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/woopay/compatibility?body-hash=&nonce=nonce&signature=signature&test_mode=1&timestamp=1&token=dummyblog%3A1%3A0';
$woopay_compatibility     = body( $fixture->intercept( false, array( 'method' => 'GET' ), $woopay_compatibility_url ) );
assert_true(
	array(
		'incompatible_extensions' => array(),
		'adapted_extensions'      => array(),
		'available_countries'     => array( 'US' ),
	) === $woopay_compatibility,
	'WooPay compatibility must use the exact response vocabulary consumed by Core'
);

$compatibility_url  = 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/compatibility?body-hash=hash&nonce=nonce&signature=signature&timestamp=1&token=dummyblog%3A1%3A0';
$compatibility_data = array(
	'woopayments_version'    => '11.2.0-dev',
	'woocommerce_version'    => '11.2.0-dev',
	'woocommerce_permalinks' => array( 'product_base' => 'product' ),
	'woocommerce_shop'       => 'http://localhost:18086/shop/',
	'woocommerce_cart'       => 'http://localhost:18086/cart/',
	'woocommerce_checkout'   => 'http://localhost:18086/checkout/',
	'blog_theme'             => 'twentytwentythree',
	'active_plugins'         => array( 'woocommerce/woocommerce.php' ),
	'post_types_count'       => array(
		'attachment' => 0,
		'page'       => 5,
		'post'       => 1,
		'product'    => 3,
	),
);
$compatibility      = body(
	$fixture->intercept(
		false,
		array(
			'method' => 'POST',
			'body'   => wp_json_encode(
				array(
					'compatibility_data' => $compatibility_data,
					'test_mode'          => true,
				)
			),
		),
		$compatibility_url
	)
);
assert_true( array( 'result' => 'ok' ) === $compatibility, 'compatibility sync must receive a deterministic success response' );
foreach (
	array(
		array( 'GET', $woopay_compatibility_url . '&unknown=1', '' ),
		array(
			'POST',
			$compatibility_url . '&unknown=1',
			wp_json_encode(
				array(
					'compatibility_data' => $compatibility_data,
					'test_mode'          => true,
				)
			),
		),
		array(
			'POST',
			$compatibility_url,
			wp_json_encode(
				array(
					'compatibility_data' => $compatibility_data,
					'test_mode'          => 'true',
				)
			),
		),
		array(
			'POST',
			$compatibility_url,
			wp_json_encode(
				array(
					'compatibility_data' => array_diff_key( $compatibility_data, array( 'blog_theme' => true ) ),
					'test_mode'          => true,
				)
			),
		),
	) as list( $method, $url, $request_body )
) {
	assert_true(
		$fixture->intercept(
			false,
			array(
				'method' => $method,
				'body'   => $request_body,
			),
			$url
		) instanceof WP_Error,
		"mutated compatibility request must fail closed: $method $url"
	);
}

$currency_rates_url = 'https://public-api.wordpress.com/wpcom/v2/sites/777/transact/currency/rates?body-hash=&currency_from=usd&nonce=nonce&signature=signature&test_mode=1&timestamp=1&token=dummyblog%3A1%3A0';
$currency_rates     = body( $fixture->intercept( false, array( 'method' => 'GET' ), $currency_rates_url ) );
assert_true( 0.92 === $currency_rates['eur'] && 0.79 === $currency_rates['gbp'], 'currency-rate fixture must preserve the numeric provider map consumed by Core' );
foreach (
	array(
		str_replace( 'https://', 'http://', $currency_rates_url ),
		str_replace( 'currency_from=usd', 'currency_from=eur', $currency_rates_url ),
		$currency_rates_url . '&unknown=1',
	) as $invalid_currency_rates_url
) {
	assert_true( $fixture->intercept( false, array( 'method' => 'GET' ), $invalid_currency_rates_url ) instanceof WP_Error, "mutated currency-rate request must fail closed: $invalid_currency_rates_url" );
}

$transactions = body( $fixture->intercept( false, array( 'method' => 'GET' ), 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/transactions?page=1&pagesize=25&sort=date&direction=desc&limit=100&test_mode=1' ) );
assert_true( 1 === count( $transactions['data'] ), 'transaction list must be non-empty' );
body( $fixture->intercept( false, array( 'method' => 'GET' ), 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/transactions/summary?page=1&pagesize=25&sort=date&direction=desc&limit=100&test_mode=1' ) );
$authorizations_summary = body( $fixture->intercept( false, array( 'method' => 'GET' ), 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/authorizations/summary?page=1&pagesize=25&sort=created&direction=desc&limit=100&test_mode=1' ) );
assert_true(
	array(
		'count' => 0,
		'total' => 0,
	) === $authorizations_summary,
	'authorization summary must use the production count/total response schema'
);
$overview = body( $fixture->intercept( false, array( 'method' => 'GET' ), 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/deposits/overview-all?test_mode=1' ) );
assert_true( isset( $overview['balance']['available'], $overview['balance']['pending'], $overview['balance']['instant'], $overview['deposit']['last_paid'], $overview['account']['default_currency'] ), 'deposits overview must expose every production balance section consumed by Core' );
foreach (
	array(
		'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/deposits/overview-all?test_mode=0',
		'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/deposits/overview-all?test_mode=1&unknown=1',
	) as $invalid_overview_url
) {
	assert_true( $fixture->intercept( false, array( 'method' => 'GET' ), $invalid_overview_url ) instanceof WP_Error, "mutated deposits-overview query must fail closed: $invalid_overview_url" );
}
$deposits = body( $fixture->intercept( false, array( 'method' => 'GET' ), 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/deposits?page=1&pagesize=25&sort=date&direction=desc&limit=100&test_mode=1' ) );
assert_true( 3 === count( $deposits['data'] ), 'the observed production payout-list query must return the deterministic deposits' );
$currency_deposits = body( $fixture->intercept( false, array( 'method' => 'GET' ), 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/deposits?page=1&pagesize=3&sort=date&direction=desc&store_currency_is=usd&test_mode=1' ) );
assert_true( 3 === count( $currency_deposits['data'] ), 'the observed production balance query must accept the exact lowercase store currency' );
foreach (
	array(
		'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/deposits?page=1&pagesize=25&sort=date&direction=asc&limit=100&test_mode=1',
		'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/deposits?page=1&pagesize=25&sort=date&direction=desc&limit=25&test_mode=1',
		'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/deposits?page=1&pagesize=3&sort=date&direction=desc&store_currency_is=USD&test_mode=1',
		'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/deposits?page=1&pagesize=3&sort=date&direction=desc&store_currency_is=&test_mode=1',
		'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/deposits?page=1&pagesize=25&sort=date&direction=desc&limit=100&test_mode=1&unknown=1',
	) as $invalid_deposits_url
) {
	assert_true(
		$fixture->intercept( false, array( 'method' => 'GET' ), $invalid_deposits_url ) instanceof WP_Error,
		"mutated production payout-list query must fail closed: $invalid_deposits_url"
	);
}

$paid_query    = 'page=1&pagesize=25&sort=date&direction=desc&limit=100&status_is=paid&test_mode=1';
$pending_query = 'page=1&pagesize=25&sort=date&direction=desc&limit=100&status_is=pending&test_mode=1';
$paid          = body( $fixture->intercept( false, array( 'method' => 'GET' ), 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/deposits?' . $paid_query ) );
$pending       = body( $fixture->intercept( false, array( 'method' => 'GET' ), 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/deposits?' . $pending_query ) );
assert_true( array( 'po_ci_paid' ) === array_column( $paid['data'], 'id' ), 'full paid production scope must contain only the paid fixture' );
assert_true( array( 'po_ci_pending', 'po_ci_pending_2' ) === array_column( $pending['data'], 'id' ), 'full pending production scope must contain both pending fixtures' );
$paid_summary    = body( $fixture->intercept( false, array( 'method' => 'GET' ), 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/deposits/summary?status_is=paid&test_mode=1' ) );
$pending_summary = body( $fixture->intercept( false, array( 'method' => 'GET' ), 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/deposits/summary?status_is=pending&test_mode=1' ) );
assert_true( array( 1, 9700, 'usd' ) === array( $paid_summary['count'], $paid_summary['total'], $paid_summary['currency'] ), 'paid summary must match only the paid fixture' );
assert_true( array( 2, 4000, 'usd' ) === array( $pending_summary['count'], $pending_summary['total'], $pending_summary['currency'] ), 'pending summary must match both pending fixtures' );
$deposits_summary = body( $fixture->intercept( false, array( 'method' => 'GET' ), 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/deposits/summary?test_mode=1' ) );
assert_true( isset( $deposits_summary['count'], $deposits_summary['total'], $deposits_summary['currency'] ), 'deposits summary must expose the exact count, total, and currency fields consumed by Core' );
foreach (
	array(
		'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/deposits/summary?test_mode=0',
		'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/deposits/summary?test_mode=1&unknown=1',
	) as $invalid_deposits_summary_url
) {
	assert_true( $fixture->intercept( false, array( 'method' => 'GET' ), $invalid_deposits_summary_url ) instanceof WP_Error, "mutated deposits-summary query must fail closed: $invalid_deposits_summary_url" );
}
$disputes_query = 'page=1&pagesize=25&sort=created&direction=desc&limit=100&test_mode=1';
body( $fixture->intercept( false, array( 'method' => 'GET' ), 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/disputes?' . $disputes_query ) );
$disputes_summary = body( $fixture->intercept( false, array( 'method' => 'GET' ), 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/disputes/summary?' . $disputes_query ) );
assert_true(
	array(
		'count'    => 0,
		'total'    => 0,
		'currency' => 'usd',
	) === $disputes_summary,
	'disputes summary must expose the exact count, total, and currency fields consumed by Core'
);
foreach (
	array(
		'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/disputes?page=1&pagesize=25&sort=created&direction=asc&limit=100&test_mode=1',
		'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/disputes?page=1&pagesize=25&sort=created&direction=desc&limit=25&test_mode=1',
		'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/disputes/summary?page=1&pagesize=25&sort=created&direction=asc&limit=100&test_mode=1',
		'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/disputes/summary?page=1&pagesize=25&sort=created&direction=desc&limit=25&test_mode=1',
		'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/disputes/summary?' . $disputes_query . '&unknown=1',
	) as $invalid_disputes_url
) {
	assert_true( $fixture->intercept( false, array( 'method' => 'GET' ), $invalid_disputes_url ) instanceof WP_Error, "mutated production disputes query must fail closed: $invalid_disputes_url" );
}
$fraud_ruleset = body( $fixture->intercept( false, array( 'method' => 'GET' ), 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/fraud_ruleset?test_mode=1' ) );
assert_true( array( 'ruleset_config' => array() ) === $fraud_ruleset, 'fraud settings fixture must match the real provider response envelope' );

$updated = body(
	$fixture->intercept(
		false,
		array(
			'method' => 'POST',
			'body'   => '{"test_mode":true,"business_name":"Updated native CI store"}',
		),
		'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/accounts'
	)
);
assert_true( 'Updated native CI store' === $updated['business_profile']['name'], 'settings writes must update private fixture state' );
assert_true( 'Updated native CI store' === get_option( 'e2e_woopayments_native_provider_state' )['settings']['business_name'], 'private state option must persist settings writes' );
assert_true( 'Updated native CI store' === get_option( 'wcpay_account_data' )['data']['business_profile']['name'], 'provider writes must synchronize the production account cache' );
$refreshed = body( $fixture->intercept( false, array( 'method' => 'GET' ), 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/accounts?test_mode=1' ) );
assert_true( 'Updated native CI store' === $refreshed['business_profile']['name'], 'account refresh must observe the fixture write' );
$restored = body(
	$fixture->intercept(
		false,
		array(
			'method' => 'POST',
			'body'   => '{"business_name":"Native CI store","test_mode":true}',
		),
		'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/accounts'
	)
);
assert_true( 'Native CI store' === $restored['business_profile']['name'], 'provider state must be restored after the write proof' );
assert_true( true === $fixture->audit()['state_restored'], 'provider write and restore must satisfy the audit baseline' );

$account_before_woopay = $fixture->account_cache();
$woopay_secret         = 'generated-woopay-webhook-secret';
$woopay_url            = 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/accounts/platform_checkout?body-hash=hash&nonce=nonce&signature=signature&timestamp=1&token=dummyblog%3A1%3A0';
$woopay_response       = body(
	$fixture->intercept(
		false,
		array(
			'method' => 'POST',
			'body'   => wp_json_encode(
				array(
					'test_mode'      => true,
					'webhook_secret' => $woopay_secret,
				)
			),
		),
		$woopay_url
	)
);
assert_true( array( 'result' => 'success' ) === $woopay_response, 'WooPay webhook registration must match the provider success response' );
assert_true( hash( 'sha256', $woopay_secret ) === get_option( 'e2e_woopayments_native_provider_state' )['woopay_webhook_secret_hash'], 'WooPay provider state must retain only a one-way secret hash' );
assert_true( $account_before_woopay === $fixture->account_cache(), 'WooPay webhook registration must not mutate the production account cache' );
assert_true( true === $fixture->audit()['state_restored'], 'WooPay webhook registration must preserve the restorable account/settings baseline' );
foreach (
	array(
		array( 'GET', $woopay_url, '' ),
		array( 'POST', str_replace( 'https://', 'http://', $woopay_url ), '{"test_mode":true,"webhook_secret":"secret"}' ),
		array( 'POST', $woopay_url . '&unknown=1', '{"test_mode":true,"webhook_secret":"secret"}' ),
		array( 'POST', $woopay_url, '{"test_mode":"true","webhook_secret":"secret"}' ),
		array( 'POST', $woopay_url, '{"test_mode":true,"webhook_secret":""}' ),
		array( 'POST', $woopay_url, '{"test_mode":true,"webhook_secret":"secret","unknown":true}' ),
	) as list( $method, $url, $request_body )
) {
	assert_true(
		$fixture->intercept(
			false,
			array(
				'method' => $method,
				'body'   => $request_body,
			),
			$url
		) instanceof WP_Error,
		"mutated WooPay registration must fail closed: $method $url $request_body"
	);
}
$woopay_requests = array_values(
	array_filter(
		get_option( 'e2e_woopayments_native_request_log', array() ),
		static fn( array $request ): bool => '/wpcom/v2/sites/777/wcpay/accounts/platform_checkout' === $request['path']
	)
);
assert_true( '(redacted)' === $woopay_requests[0]['body']['webhook_secret'], 'canonical audit must redact the WooPay webhook secret' );
assert_true( false === strpos( wp_json_encode( $woopay_requests ), $woopay_secret ), 'canonical audit must never retain the raw WooPay webhook secret' );

$unknown = $fixture->intercept( false, array( 'method' => 'GET' ), 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/unrecognized' );
assert_true( $unknown instanceof WP_Error, 'unknown provider requests must fail closed' );

$allowed_path = '/wpcom/v2/sites/777/wcpay/accounts?test_mode=1';
$bypasses     = array(
	'http://public-api.wordpress.com' . $allowed_path,
	'https://user@public-api.wordpress.com' . $allowed_path,
	'https://public-api.wordpress.com:443' . $allowed_path,
	'https://public-api.wordpress.com' . $allowed_path . '#fragment',
);
foreach ( $bypasses as $bypass ) {
	assert_true( $fixture->intercept( false, array( 'method' => 'GET' ), $bypass ) instanceof WP_Error, "origin bypass must fail closed: $bypass" );
}

$invalid_contracts = array(
	array( 'GET', 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/accounts?test_mode=1&unknown=1', '' ),
	array( 'GET', 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/transactions?page=1&pagesize=25&sort=date&direction=asc&limit=100&test_mode=1', '' ),
	array( 'GET', 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/transactions?page=1&pagesize=25&sort=date&direction=desc&limit=25&test_mode=1', '' ),
	array( 'GET', 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/transactions?page=1&pagesize=25&sort=date&direction=desc&limit=100&test_mode=1&unknown=1', '' ),
	array( 'GET', 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/transactions/summary?page=1&pagesize=25&sort=date&direction=asc&limit=100&test_mode=1', '' ),
	array( 'GET', 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/transactions/summary?page=1&pagesize=25&sort=date&direction=desc&limit=25&test_mode=1', '' ),
	array( 'GET', 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/transactions/summary?page=1&pagesize=25&sort=date&direction=desc&limit=100&test_mode=1&unknown=1', '' ),
	array( 'GET', 'http://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/authorizations/summary?page=1&pagesize=25&sort=created&direction=desc&limit=100&test_mode=1', '' ),
	array( 'GET', 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/authorizations/summary?page=1&pagesize=25&sort=created&direction=asc&limit=100&test_mode=1', '' ),
	array( 'GET', 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/authorizations/summary?page=1&pagesize=25&sort=created&direction=desc&limit=25&test_mode=1', '' ),
	array( 'GET', 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/authorizations/summary?page=1&pagesize=25&sort=created&direction=desc&limit=100&test_mode=1&unknown=1', '' ),
	array( 'POST', 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/accounts', '{"test_mode":true,"unknown":1}' ),
	array( 'POST', 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/accounts', '{"test_mode":"true","business_name":"Native CI"}' ),
	array( 'POST', 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/accounts', '' ),
	array( 'POST', 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/accounts', '[]' ),
	array( 'POST', 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/accounts', '[{"business_name":"Native CI"}]' ),
);
foreach ( $invalid_contracts as list( $method, $url, $request_body ) ) {
	assert_true(
		$fixture->intercept(
			false,
			array(
				'method' => $method,
				'body'   => $request_body,
			),
			$url
		) instanceof WP_Error,
		"invalid route contract must fail closed: $method $url $request_body"
	);
}

$requests = get_option( 'e2e_woopayments_native_request_log', array() );
assert_true( count( $requests ) === 86, 'every provider request must be recorded' );
$canonical_transactions = array_values(
	array_filter(
		$requests,
		static fn( array $request ): bool => '/wpcom/v2/sites/777/wcpay/transactions' === $request['path']
			&& 'GET' === $request['method']
			&& array(
				'direction' => 'desc',
				'limit'     => '100',
				'page'      => '1',
				'pagesize'  => '25',
				'sort'      => 'date',
				'test_mode' => '1',
			) === $request['query']
	)
);
assert_true(
	1 === count( $canonical_transactions ) && null === $canonical_transactions[0]['body'],
	'valid requests must use the canonical audit shape'
);
assert_true( count( get_option( 'e2e_woopayments_native_failure_log', array() ) ) === 59, 'fail-closed provider verdicts must remain auditable' );
$audit = $fixture->audit();
assert_true(
	! in_array( 'GET disputes/summary', $audit['required_routes'], true )
		&& ! in_array( 'GET transact/currency/rates', $audit['required_routes'], true ),
	'audit coverage must not require conditionally bypassed provider routes that no selected readonly contract exercises'
);
assert_true( true === $audit['coverage'], 'audit must require every readonly provider route family' );
assert_true( true === $audit['state_restored'], 'audit must require the private fixture baseline to be restored' );
assert_true( false === $audit['clean'], 'recorded fail-closed test probes must keep the audit non-clean' );

echo "ci-provider-fixture.php tests passed.\n";
