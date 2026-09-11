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
 * Removes a WordPress filter registration.
 *
 * @param string   $name Filter name.
 * @param callable $callback Filter callback.
 * @param int      $priority Filter priority.
 */
function remove_filter( string $name, callable $callback, int $priority = 10 ): bool {
	global $filters;
	if ( ! isset( $filters[ $name ] ) || $filters[ $name ][0] !== $callback || $filters[ $name ][1] !== $priority ) {
		return false;
	}
	unset( $filters[ $name ] );
	return true;
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
	global $filters, $options;
	$pre_option = 'pre_option_' . $name;
	if ( isset( $filters[ $pre_option ] ) ) {
		return ( $filters[ $pre_option ][0] )( false );
	}
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
 * Deletes an option from private fixture state.
 *
 * @param string $name Option name.
 */
function delete_option( string $name ): bool {
	global $options;
	$existed = array_key_exists( $name, $options );
	unset( $options[ $name ] );
	return $existed;
}

/**
 * Invalidates an option object-cache entry in the standalone harness.
 *
 * @param string $key Cache key.
 * @param string $group Cache group.
 */
function wp_cache_delete( string $key, string $group = '' ): bool {
	unset( $key, $group );
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
 * Asserts exact standalone fixture values.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual Actual value.
 * @param string $message Failure message.
 * @throws RuntimeException When the values differ.
 */
function assert_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
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

/**
 * Builds one signed provider URL with deterministic ephemeral values.
 *
 * @param string               $route Site-scoped WooPayments route.
 * @param array<string,string> $business_query Exact business query.
 * @param string               $method HTTP method.
 */
function signed_provider_url( string $route, array $business_query = array(), string $method = 'GET' ): string {
	$query = array_merge(
		array(
			'body-hash' => 'POST' === $method ? 'body-hash' : '',
			'nonce'     => 'nonce',
			'signature' => 'signature',
			'timestamp' => '1',
			'token'     => 'dummyblog:1:0',
		),
		$business_query
	);

	return 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/' . $route . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
}

$fixture_filter = $filters['pre_option_wcpay_account_data'] ?? null;
if ( ! is_array( $fixture_filter ) || ! is_array( $fixture_filter[0] ) ) {
	throw new RuntimeException( 'Provider fixture account-cache filter was not registered.' );
}
$fixture = $fixture_filter[0][0] ?? null;
assert_true( $fixture instanceof WooCommerce_WooPayments_Native_CI_Provider_Fixture, 'registered account-cache filter must belong to the provider fixture' );
assert_true( WooCommerce_WooPayments_Native_CI_Provider_Fixture::registered_instance() === $fixture, 'fixture registry must expose the exact registered callback owner for physical seeding' );
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
$options['wcpay_account_data'] = $cache;
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

$baseline_provider_state                       = get_option( 'e2e_woopayments_native_provider_state' );
$baseline_request_log                          = get_option( 'e2e_woopayments_native_request_log', array() );
$baseline_failure_log                          = get_option( 'e2e_woopayments_native_failure_log', array() );
$options['e2e_woopayments_native_request_log'] = array(
	array(
		'method' => 'GET',
		'path'   => '/wpcom/v2/sites/777/wcpay/accounts',
	),
	array(
		'method' => 'GET',
		'path'   => '/wpcom/v2/sites/777/wcpay/transactions',
	),
	array(
		'method' => 'GET',
		'path'   => '/wpcom/v2/sites/777/wcpay/transactions/summary',
	),
	array(
		'method' => 'GET',
		'path'   => '/wpcom/v2/sites/777/wcpay/authorizations/summary',
	),
	array(
		'method' => 'GET',
		'path'   => '/wpcom/v2/sites/777/wcpay/deposits/overview-all',
	),
	array(
		'method' => 'GET',
		'path'   => '/wpcom/v2/sites/777/wcpay/deposits',
	),
	array(
		'method' => 'GET',
		'path'   => '/wpcom/v2/sites/777/wcpay/deposits/summary',
	),
	array(
		'method' => 'GET',
		'path'   => '/wpcom/v2/sites/777/wcpay/disputes',
	),
	array(
		'method' => 'POST',
		'path'   => '/wpcom/v2/sites/777/wcpay/accounts',
	),
);
$options['e2e_woopayments_native_failure_log'] = array();
$physical_cache_divergence                     = $cache;
$physical_cache_divergence['data']['business_profile']['name'] = 'Diverged physical cache';
$options['wcpay_account_data']                                 = $physical_cache_divergence;
assert_true( 'acct_native_ci' === get_option( 'wcpay_account_data' )['data']['account_id'], 'connected pre-option fixture must continue to mask physical cache reads' );
$physical_divergence_audit = $fixture->audit();
assert_true( true === $physical_divergence_audit['coverage'], 'physical-cache mutation proof must isolate restoration from route coverage' );
assert_true( false === $physical_divergence_audit['state_restored'], 'physical account-cache-only divergence must fail restoration' );
assert_true( false === $physical_divergence_audit['clean'], 'physical account-cache-only divergence must make the audit non-clean' );
$physical_cache_error_divergence                       = $cache;
$physical_cache_error_divergence['errored']            = true;
$physical_cache_error_divergence['consecutive_errors'] = 1;
$options['wcpay_account_data']                         = $physical_cache_error_divergence;
assert_true( false === $fixture->audit()['state_restored'], 'physical account-cache error fields must participate in restoration' );
$options['wcpay_account_data'] = array_replace( $cache, array( 'fetched' => $cache['fetched'] + 1 ) );
$volatile_timestamp_audit      = $fixture->audit();
assert_true( true === $volatile_timestamp_audit['state_restored'], 'physical account-cache fetched timestamp must be the only ignored field' );
$options['e2e_woopayments_native_request_log']               = $baseline_request_log;
$options['e2e_woopayments_native_failure_log']               = $baseline_failure_log;
$options['wcpay_account_data']                               = $cache;
$mutated_provider_state                                      = $baseline_provider_state;
$mutated_provider_state['account']['unexpected_account_key'] = true;
update_option( 'e2e_woopayments_native_provider_state', $mutated_provider_state );
assert_true( false === $fixture->audit()['state_restored'], 'an extra account key must fail complete provider-state restoration' );
$mutated_provider_state                                        = $baseline_provider_state;
$mutated_provider_state['settings']['unexpected_settings_key'] = true;
update_option( 'e2e_woopayments_native_provider_state', $mutated_provider_state );
assert_true( false === $fixture->audit()['state_restored'], 'an extra settings key must fail complete provider-state restoration' );
$mutated_provider_state = $baseline_provider_state;
$mutated_provider_state['fraud_ruleset']['unexpected_fraud_key'] = true;
update_option( 'e2e_woopayments_native_provider_state', $mutated_provider_state );
assert_true( false === $fixture->audit()['state_restored'], 'an extra fraud key must fail complete provider-state restoration' );
update_option( 'e2e_woopayments_native_provider_state', $baseline_provider_state );
assert_true( true === $fixture->audit()['state_restored'], 'restoring the complete provider snapshot must restore the audit baseline' );

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

$package_versions_url = 'https://public-api.wordpress.com/wpcom/v2/sites/777/jetpack-package-versions?body-hash=hash&nonce=nonce&signature=signature&timestamp=1&token=dummyblog%3A1%3A0&transport_metadata=ignored';
$package_versions     = $fixture->intercept(
	false,
	array(
		'method' => 'POST',
		'body'   => '{}',
	),
	$package_versions_url
);
assert_true( ! $package_versions instanceof WP_Error, 'Jetpack package-version reports must accept any parsable object while retaining the signing boundary' );
assert_true(
	$fixture->intercept(
		false,
		array(
			'method' => 'POST',
			'body'   => '{"package_versions":{"connection":"future-version"}}',
		),
		str_replace( '&nonce=nonce', '', $package_versions_url )
	) instanceof WP_Error,
	'Jetpack package-version report must fail closed when a required signature key is missing'
);

$active_plugins_url = str_replace( 'jetpack-package-versions', 'jetpack-active-connected-plugins', $package_versions_url );
$active_plugins     = $fixture->intercept(
	false,
	array(
		'method' => 'POST',
		'body'   => '{"future_active_plugin_shape":{"accepted":true}}',
	),
	$active_plugins_url
);
assert_true( ! $active_plugins instanceof WP_Error, 'Jetpack active-plugin reports must accept any parsable object while retaining the signing boundary' );
assert_true(
	$fixture->intercept(
		false,
		array( 'method' => 'GET' ),
		$active_plugins_url
	) instanceof WP_Error,
	'Jetpack active-plugin reports must fail closed for unsupported methods'
);

$store_setup_url      = 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/accounts/store_setup?body-hash=hash&nonce=nonce&signature=signature&timestamp=1&token=dummyblog%3A1%3A0';
$store_setup_body     = wp_json_encode(
	array(
		'snapshot'  => array( 'future_shape' => array( 'accepted' ) ),
		'test_mode' => true,
		'extra'     => 'ignored',
	)
);
$store_setup_response = $fixture->intercept(
	false,
	array(
		'method' => 'POST',
		'body'   => $store_setup_body,
	),
	$store_setup_url . '&transport_metadata=ignored'
);
assert_true( ! $store_setup_response instanceof WP_Error, 'store-setup transport must accept producer schema drift while retaining a parsable object and boolean mode' );
foreach ( array( '', '[]', '[{"snapshot":{}}]', '{"test_mode":"true"}' ) as $invalid_store_setup_body ) {
	assert_true(
		$fixture->intercept(
			false,
			array(
				'method' => 'POST',
				'body'   => $invalid_store_setup_body,
			),
			$store_setup_url
		) instanceof WP_Error,
		'store-setup transport must reject a malformed object or non-boolean mode'
	);
}
assert_true(
	$fixture->intercept(
		false,
		array(
			'method' => 'POST',
			'body'   => '{}',
		),
		str_replace( 'https://', 'http://', $package_versions_url )
	) instanceof WP_Error,
	'Jetpack package-version reports must remain confined to the exact HTTPS provider origin'
);

$recommendations = body( $fixture->intercept( false, array( 'method' => 'GET' ), 'https://public-api.wordpress.com/wpcom/v2/wcpay/payment_methods/recommended?country_code=CA&locale=fr_FR&transport_metadata=ignored' ) );
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
$incentives = body( $fixture->intercept( false, array( 'method' => 'GET' ), 'https://public-api.wordpress.com/wpcom/v2/wcpay/incentives?future_business_key=ignored' ) );
assert_true( array() === $incentives, 'known public routes must return deterministic responses without policing producer-owned query shapes' );

$pm_promotions = body(
	$fixture->intercept(
		false,
		array( 'method' => 'GET' ),
		signed_provider_url(
			'payment_method_promotions',
			array(
				'locale'    => 'fr_FR',
				'test_mode' => '1',
				'unknown'   => 'ignored',
			)
		)
	)
);
assert_true( array() === $pm_promotions, 'signed known routes must ignore producer-owned business query keys' );
assert_true(
	$fixture->intercept( false, array( 'method' => 'GET' ), 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/payment_method_promotions?locale=en_US&test_mode=1' ) instanceof WP_Error,
	'signed known routes must still fail closed without the Jetpack signing envelope'
);

$account = body(
	$fixture->intercept(
		false,
		array( 'method' => 'GET' ),
		signed_provider_url(
			'accounts',
			array(
				'test_mode'            => '1',
				'woocommerce_store_id' => 'store-id',
			)
		)
	)
);
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
$compatibility_data = array( 'future_snapshot' => array( 'accepted' ) );
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
		$compatibility_url . '&transport_metadata=ignored'
	)
);
assert_true( array( 'result' => 'ok' ) === $compatibility, 'compatibility sync must receive a deterministic success response' );
assert_true(
	$fixture->intercept(
		false,
		array(
			'method' => 'POST',
			'body'   => '{"compatibility_data":{},"test_mode":"true"}',
		),
		$compatibility_url
	) instanceof WP_Error,
	'compatibility sync must retain the boolean fixture-mode discriminator'
);

$currency_rates_url = 'https://public-api.wordpress.com/wpcom/v2/sites/777/transact/currency/rates?body-hash=&currency_from=usd&nonce=nonce&signature=signature&test_mode=1&timestamp=1&token=dummyblog%3A1%3A0';
$currency_rates     = body( $fixture->intercept( false, array( 'method' => 'GET' ), $currency_rates_url ) );
assert_true( 0.92 === $currency_rates['eur'] && 0.79 === $currency_rates['gbp'], 'currency-rate fixture must preserve the numeric provider map consumed by Core' );
assert_true( $fixture->intercept( false, array( 'method' => 'GET' ), str_replace( 'https://', 'http://', $currency_rates_url ) ) instanceof WP_Error, 'currency rates must remain confined to the exact HTTPS provider origin' );
assert_true( ! $fixture->intercept( false, array( 'method' => 'GET' ), str_replace( 'currency_from=usd', 'currency_from=eur', $currency_rates_url ) . '&unknown=ignored' ) instanceof WP_Error, 'currency-rate responses must tolerate producer-owned query variants' );

$transactions = body(
	$fixture->intercept(
		false,
		array( 'method' => 'GET' ),
		signed_provider_url(
			'transactions',
			array(
				'page'      => '1',
				'pagesize'  => '25',
				'sort'      => 'date',
				'direction' => 'desc',
				'limit'     => '100',
				'test_mode' => '1',
			)
		)
	)
);
assert_true( 1 === count( $transactions['data'] ), 'transaction list must be non-empty' );
body(
	$fixture->intercept(
		false,
		array( 'method' => 'GET' ),
		signed_provider_url(
			'transactions/summary',
			array(
				'page'      => '1',
				'pagesize'  => '25',
				'sort'      => 'date',
				'direction' => 'desc',
				'limit'     => '100',
				'test_mode' => '1',
			)
		)
	)
);
$authorizations_summary = body(
	$fixture->intercept(
		false,
		array( 'method' => 'GET' ),
		signed_provider_url(
			'authorizations/summary',
			array(
				'page'      => '1',
				'pagesize'  => '25',
				'sort'      => 'created',
				'direction' => 'desc',
				'limit'     => '100',
				'test_mode' => '1',
			)
		)
	)
);
assert_true(
	array(
		'count' => 0,
		'total' => 0,
	) === $authorizations_summary,
	'authorization summary must use the production count/total response schema'
);
$overview = body( $fixture->intercept( false, array( 'method' => 'GET' ), signed_provider_url( 'deposits/overview-all', array( 'test_mode' => '1' ) ) ) );
assert_true( isset( $overview['balance']['available'], $overview['balance']['pending'], $overview['balance']['instant'], $overview['deposit']['last_paid'], $overview['account']['default_currency'] ), 'deposits overview must expose every production balance section consumed by Core' );
$deposits = body(
	$fixture->intercept(
		false,
		array( 'method' => 'GET' ),
		signed_provider_url(
			'deposits',
			array(
				'page'      => '1',
				'pagesize'  => '25',
				'sort'      => 'date',
				'direction' => 'desc',
				'limit'     => '100',
				'test_mode' => '1',
			)
		)
	)
);
assert_true( 3 === count( $deposits['data'] ), 'the observed production payout-list query must return the deterministic deposits' );
$currency_deposits = body(
	$fixture->intercept(
		false,
		array( 'method' => 'GET' ),
		signed_provider_url(
			'deposits',
			array(
				'page'              => '1',
				'pagesize'          => '3',
				'sort'              => 'date',
				'direction'         => 'desc',
				'limit'             => '100',
				'store_currency_is' => 'usd',
				'test_mode'         => '1',
			)
		)
	)
);
assert_true( 3 === count( $currency_deposits['data'] ), 'the observed production balance query must accept the exact lowercase store currency' );
$paid    = body(
	$fixture->intercept(
		false,
		array( 'method' => 'GET' ),
		signed_provider_url(
			'deposits',
			array(
				'page'      => '1',
				'pagesize'  => '25',
				'sort'      => 'date',
				'direction' => 'desc',
				'limit'     => '100',
				'status_is' => 'paid',
				'test_mode' => '1',
			)
		)
	)
);
$pending = body(
	$fixture->intercept(
		false,
		array( 'method' => 'GET' ),
		signed_provider_url(
			'deposits',
			array(
				'page'      => '1',
				'pagesize'  => '25',
				'sort'      => 'date',
				'direction' => 'desc',
				'limit'     => '100',
				'status_is' => 'pending',
				'test_mode' => '1',
			)
		)
	)
);
assert_true( array( 'po_ci_paid' ) === array_column( $paid['data'], 'id' ), 'full paid production scope must contain only the paid fixture' );
assert_true( array( 'po_ci_pending', 'po_ci_pending_2' ) === array_column( $pending['data'], 'id' ), 'full pending production scope must contain both pending fixtures' );
$paid_summary    = body(
	$fixture->intercept(
		false,
		array( 'method' => 'GET' ),
		signed_provider_url(
			'deposits/summary',
			array(
				'status_is' => 'paid',
				'test_mode' => '1',
			)
		)
	)
);
$pending_summary = body(
	$fixture->intercept(
		false,
		array( 'method' => 'GET' ),
		signed_provider_url(
			'deposits/summary',
			array(
				'status_is' => 'pending',
				'test_mode' => '1',
			)
		)
	)
);
assert_true( array( 1, 9700, 'usd' ) === array( $paid_summary['count'], $paid_summary['total'], $paid_summary['currency'] ), 'paid summary must match only the paid fixture' );
assert_true( array( 2, 4000, 'usd' ) === array( $pending_summary['count'], $pending_summary['total'], $pending_summary['currency'] ), 'pending summary must match both pending fixtures' );
$deposits_summary = body( $fixture->intercept( false, array( 'method' => 'GET' ), signed_provider_url( 'deposits/summary', array( 'test_mode' => '1' ) ) ) );
assert_true( isset( $deposits_summary['count'], $deposits_summary['total'], $deposits_summary['currency'] ), 'deposits summary must expose the exact count, total, and currency fields consumed by Core' );
body(
	$fixture->intercept(
		false,
		array( 'method' => 'GET' ),
		signed_provider_url(
			'disputes',
			array(
				'page'      => '0',
				'pagesize'  => '25',
				'sort'      => 'created',
				'direction' => 'desc',
				'limit'     => '100',
				'test_mode' => '1',
			)
		)
	)
);
body(
	$fixture->intercept(
		false,
		array( 'method' => 'GET' ),
		signed_provider_url(
			'disputes',
			array(
				'page'      => '1',
				'pagesize'  => '25',
				'sort'      => 'created',
				'direction' => 'desc',
				'limit'     => '100',
				'test_mode' => '1',
			)
		)
	)
);
$disputes_summary = body(
	$fixture->intercept(
		false,
		array( 'method' => 'GET' ),
		signed_provider_url(
			'disputes/summary',
			array(
				'page'      => '1',
				'pagesize'  => '25',
				'sort'      => 'created',
				'direction' => 'desc',
				'limit'     => '100',
				'test_mode' => '1',
			)
		)
	)
);
assert_true(
	array(
		'count'    => 0,
		'total'    => 0,
		'currency' => 'usd',
	) === $disputes_summary,
	'disputes summary must expose the exact count, total, and currency fields consumed by Core'
);
$fraud_ruleset = body( $fixture->intercept( false, array( 'method' => 'GET' ), signed_provider_url( 'fraud_ruleset', array( 'test_mode' => '1' ) ) ) );
assert_true( array( 'ruleset_config' => array() ) === $fraud_ruleset, 'fraud settings fixture must match the real provider response envelope' );

$all_account_writes   = array(
	'test_mode'                       => true,
	'statement_descriptor'            => 'MUTATED CI',
	'statement_descriptor_kanji'      => '変更',
	'statement_descriptor_kana'       => 'ヘンコウ',
	'business_name'                   => 'Mutated native CI store',
	'business_url'                    => 'https://mutated.example.test',
	'business_support_address'        => array( 'country' => 'CA' ),
	'business_support_email'          => 'mutated@example.test',
	'business_support_phone'          => '+12223334444',
	'branding_logo'                   => 'logo_mutated',
	'branding_icon'                   => 'icon_mutated',
	'branding_primary_color'          => '#112233',
	'branding_secondary_color'        => '#445566',
	'communications_email'            => 'communications@example.test',
	'deposit_schedule_interval'       => 'weekly',
	'deposit_schedule_monthly_anchor' => null,
	'deposit_schedule_weekly_anchor'  => 'friday',
);
$all_account_response = body(
	$fixture->intercept(
		false,
		array(
			'method' => 'POST',
			'body'   => wp_json_encode( $all_account_writes ),
		),
		signed_provider_url( 'accounts', array(), 'POST' )
	)
);
assert_true( 'MUTATED CI' === $all_account_response['statement_descriptor'], 'statement settings writes must mutate provider state' );
assert_true( 'Mutated native CI store' === $all_account_response['business_profile']['name'], 'business-profile writes must mutate provider state' );
assert_true( '#112233' === $all_account_response['branding']['primary_color'], 'branding writes must mutate provider state' );
assert_true( 'communications@example.test' === $all_account_response['communications_email'], 'communication writes must mutate provider state' );
assert_true( 'weekly' === $all_account_response['deposits']['interval'], 'deposit-schedule writes must mutate provider state' );
assert_true( false === $fixture->audit()['state_restored'], 'every account write family must participate in complete restoration' );
$fixture->intercept(
	false,
	array(
		'method' => 'POST',
		'body'   => wp_json_encode( $baseline_provider_state['settings'] ),
	),
	signed_provider_url( 'accounts', array(), 'POST' )
);
assert_true( true === $fixture->audit()['state_restored'], 'restoring every account write family must restore the canonical baseline' );

$fixture->intercept(
	false,
	array(
		'method' => 'POST',
		'body'   => wp_json_encode(
			array(
				'ruleset_config' => array( array( 'key' => 'mutated' ) ),
				'test_mode'      => true,
			)
		),
	),
	signed_provider_url( 'fraud_ruleset', array(), 'POST' )
);
assert_true( false === $fixture->audit()['state_restored'], 'fraud writes must participate in complete restoration' );
$fixture->intercept(
	false,
	array(
		'method' => 'POST',
		'body'   => wp_json_encode( $baseline_provider_state['fraud_ruleset'] ),
	),
	signed_provider_url( 'fraud_ruleset', array(), 'POST' )
);
assert_true( true === $fixture->audit()['state_restored'], 'restoring fraud settings must restore the canonical baseline' );

$updated = body(
	$fixture->intercept(
		false,
		array(
			'method' => 'POST',
			'body'   => '{"test_mode":true,"business_name":"Updated native CI store"}',
		),
		signed_provider_url( 'accounts', array(), 'POST' )
	)
);
assert_true( 'Updated native CI store' === $updated['business_profile']['name'], 'settings writes must update private fixture state' );
assert_true( 'Updated native CI store' === get_option( 'e2e_woopayments_native_provider_state' )['settings']['business_name'], 'private state option must persist settings writes' );
assert_true( 'Native CI store' === $options['wcpay_account_data']['data']['business_profile']['name'], 'fixture provider writes must not mutate the physical production account cache' );
$refreshed = body(
	$fixture->intercept(
		false,
		array( 'method' => 'GET' ),
		signed_provider_url(
			'accounts',
			array(
				'test_mode'            => '1',
				'woocommerce_store_id' => 'store-id',
			)
		)
	)
);
assert_true( 'Updated native CI store' === $refreshed['business_profile']['name'], 'account refresh must observe the fixture write' );
$restored = body(
	$fixture->intercept(
		false,
		array(
			'method' => 'POST',
			'body'   => '{"business_name":"Native CI store","test_mode":true}',
		),
		signed_provider_url( 'accounts', array(), 'POST' )
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
$retained_state                               = get_option( 'e2e_woopayments_native_provider_state' );
$retained_hash                                = $retained_state['woopay_webhook_secret_hash'];
$retained_state['woopay_webhook_secret_hash'] = hash( 'sha256', 'unexpected-secret' );
update_option( 'e2e_woopayments_native_provider_state', $retained_state );
assert_true( false === $fixture->audit()['state_restored'], 'unexpected retained webhook state must fail restoration' );
$retained_state['woopay_webhook_secret_hash'] = $retained_hash;
update_option( 'e2e_woopayments_native_provider_state', $retained_state );
assert_true( true === $fixture->audit()['state_restored'], 'the explicitly retained webhook hash must match its expected final state' );
assert_true(
	! $fixture->intercept(
		false,
		array(
			'method' => 'POST',
			'body'   => '{"test_mode":true,"webhook_secret":"secret","unknown":true}',
		),
		$woopay_url . '&unknown=ignored'
	) instanceof WP_Error,
	'WooPay registration must tolerate producer-owned query and body extensions while redacting its consumed secret'
);
foreach (
	array(
		array( 'GET', $woopay_url, '' ),
		array( 'POST', str_replace( 'https://', 'http://', $woopay_url ), '{"test_mode":true,"webhook_secret":"secret"}' ),
		array( 'POST', $woopay_url, '{"test_mode":"true","webhook_secret":"secret"}' ),
		array( 'POST', $woopay_url, '{"test_mode":true,"webhook_secret":""}' ),
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
	array( 'GET', 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/accounts?test_mode=1', '' ),
	array( 'GET', 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/transactions?body-hash=&nonce=nonce&timestamp=1&token=token&page=1&pagesize=25&sort=date&direction=desc&limit=100&test_mode=1', '' ),
	array( 'POST', signed_provider_url( 'accounts', array(), 'POST' ), '{"test_mode":"true","business_name":"Native CI"}' ),
	array( 'POST', 'https://public-api.wordpress.com/wpcom/v2/sites/777/wcpay/accounts', '' ),
	array( 'POST', signed_provider_url( 'accounts', array(), 'POST' ), '[]' ),
	array( 'POST', signed_provider_url( 'accounts', array(), 'POST' ), '[{"business_name":"Native CI"}]' ),
	array( 'POST', str_replace( 'body-hash=body-hash', 'body-hash=', signed_provider_url( 'accounts', array(), 'POST' ) ), '{"test_mode":true,"business_name":"Native CI"}' ),
	array( 'POST', str_replace( 'body-hash=body-hash', 'body-hash=', signed_provider_url( 'fraud_ruleset', array(), 'POST' ) ), '{"ruleset_config":[],"test_mode":true}' ),
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
assert_true( array() !== $requests, 'provider requests must remain canonically recorded for audit coverage' );
$canonical_transactions = array_values(
	array_filter(
		$requests,
		static fn( array $request ): bool => '/wpcom/v2/sites/777/wcpay/transactions' === $request['path']
			&& 'GET' === $request['method']
	)
);
assert_true(
	array() !== $canonical_transactions && is_array( $canonical_transactions[0]['query'] ) && null === $canonical_transactions[0]['body'],
	'valid requests must use the canonical audit shape'
);
$failure_count = count( get_option( 'e2e_woopayments_native_failure_log', array() ) );
assert_true( 0 < $failure_count, 'fail-closed provider verdicts must remain auditable' );
$audit = $fixture->audit();
assert_true(
	! in_array( 'GET disputes/summary', $audit['required_routes'], true )
		&& ! in_array( 'GET transact/currency/rates', $audit['required_routes'], true ),
	'audit coverage must not require conditionally bypassed provider routes that no selected readonly contract exercises'
);
assert_true( true === $audit['coverage'], 'audit must require every readonly provider route family' );
assert_true( true === $audit['state_restored'], 'audit must require the private and physical fixture baselines to be restored' );
assert_true( true === $audit['physical_account_cache']['restored'], 'audit must expose normalized physical account-cache restoration' );
assert_true( array( 'fetched' ) === $audit['physical_account_cache']['ignored_fields'], 'audit must ignore only the physical cache fetch timestamp' );
assert_true( false === $audit['clean'], 'recorded fail-closed test probes must keep the audit non-clean' );

$fresh_activation_cache                        = array(
	'data'               => null,
	'fetched'            => 123,
	'errored'            => true,
	'consecutive_errors' => 1,
);
$options['wcpay_account_data']                 = $fresh_activation_cache;
$options['e2e_woopayments_native_failure_log'] = array();
$refreshed_account                             = $fixture->prepare_physical_account_cache_for_run(
	static function () use ( $fixture, &$filters ): array {
		assert_true( ! isset( $filters['pre_option_wcpay_account_data'] ), 'the connected pre-option filter must not mask the production cache refresh' );
		assert_same( 'yes', get_option( 'wcpay_onboarding_test_mode', '__missing__' ), 'fixture preparation must explicitly enable test-mode onboarding before refreshing a non-live account' );
		$response = $fixture->intercept( false, array( 'method' => 'GET' ), signed_provider_url( 'accounts', array( 'test_mode' => '1' ) ) );
		$account  = body( $response );
		update_option(
			'wcpay_account_data',
			array(
				'data'               => $account,
				'fetched'            => 456,
				'errored'            => false,
				'consecutive_errors' => 0,
			)
		);
		return $account;
	}
);
assert_true( 'acct_native_ci' === $refreshed_account['account_id'], 'fixture preparation must return the real refresh result' );
assert_same( false, $options['wcpay_account_data']['errored'], 'fixture preparation must replace the fresh-activation error for the readonly run' );
$midrun_audit = $fixture->audit();
assert_same( false, $midrun_audit['physical_account_cache']['pre_fixture_restored'], 'mid-run request inspection must not perform final cache restoration' );
assert_same( false, $midrun_audit['test_mode_premise']['pre_fixture_restored'], 'mid-run request inspection must not restore the test-mode premise before the readonly run ends' );
assert_same( false, $options['wcpay_account_data']['errored'], 'mid-run request inspection must leave the connected cache in place' );
$fixture->restore_pre_fixture_physical_account_cache();
$audit = $fixture->audit();
assert_true( true === $audit['clean'], 'a covered fixture run must remain clean after restoring the pre-fixture cache' );
assert_true( true === $audit['physical_account_cache']['run_restored'], 'the readonly run must end at its connected physical-cache baseline' );
assert_true( true === $audit['physical_account_cache']['pre_fixture_restored'], 'audit must restore the physical cache captured before fixture preparation' );
assert_true( true === $audit['physical_account_cache']['restored'], 'physical-cache restoration requires both run and pre-fixture restoration' );
assert_true( true === $audit['test_mode_premise']['pre_fixture_restored'], 'audit must restore the exact test-mode premise captured before fixture preparation' );
assert_same( $fresh_activation_cache, $options['wcpay_account_data'], 'audit must restore the exact fresh-activation error wrapper' );
assert_same( '__missing__', get_option( 'wcpay_onboarding_test_mode', '__missing__' ), 'audit must restore an originally absent test-mode onboarding option' );
assert_true( 'acct_native_ci' === get_option( 'wcpay_account_data' )['data']['account_id'], 'the connected pre-option filter must not mask the physical restoration assertion' );
update_option( 'wcpay_onboarding_test_mode', 'yes' );
$diverged_premise_audit = $fixture->audit();
assert_same( false, $diverged_premise_audit['test_mode_premise']['pre_fixture_restored'], 'audit must detect test-mode premise divergence after finalization' );
assert_same( false, $diverged_premise_audit['clean'], 'test-mode premise divergence must keep the final audit non-clean' );
delete_option( 'wcpay_onboarding_test_mode' );
assert_same( true, $fixture->audit()['clean'], 'restoring the test-mode premise must make the otherwise clean audit pass again' );

echo "ci-provider-fixture.php tests passed.\n";
