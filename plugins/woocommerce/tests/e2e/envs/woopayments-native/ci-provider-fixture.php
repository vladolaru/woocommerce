<?php
/**
 * Plugin Name: WooPayments native secretless CI provider fixture
 * Description: Fail-closed provider transport and Jetpack identity for native WooPayments E2E tests.
 *
 * @package woopayments-native-ci-fixture
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Serves deterministic WooPayments provider responses to the secretless CI lane.
 */
final class WooCommerce_WooPayments_Native_CI_Provider_Fixture {

	private const STATE_OPTION         = 'e2e_woopayments_native_provider_state';
	private const REQUEST_LOG_OPTION   = 'e2e_woopayments_native_request_log';
	private const FAILURE_LOG_OPTION   = 'e2e_woopayments_native_failure_log';
	private const BLOG_ID              = 777;
	private const CONNECTION_VERSION   = '6.19.2';
	private const REQUIRED_ROUTES      = array(
		'GET accounts',
		'GET transactions',
		'GET transactions/summary',
		'GET authorizations/summary',
		'GET deposits/overview-all',
		'GET deposits',
		'GET deposits/summary',
		'GET disputes',
		'POST accounts',
	);
	private const ACCOUNT_WRITE_SCHEMA = array(
		'test_mode'                       => 'bool',
		'statement_descriptor'            => 'string',
		'statement_descriptor_kanji'      => 'string',
		'statement_descriptor_kana'       => 'string',
		'business_name'                   => 'string',
		'business_url'                    => 'string',
		'business_support_address'        => 'array',
		'business_support_email'          => 'string',
		'business_support_phone'          => 'string',
		'branding_logo'                   => 'string',
		'branding_icon'                   => 'string',
		'branding_primary_color'          => 'string',
		'branding_secondary_color'        => 'string',
		'communications_email'            => 'string',
		'deposit_schedule_interval'       => 'string',
		'deposit_schedule_monthly_anchor' => 'int_or_null',
		'deposit_schedule_weekly_anchor'  => 'string',
	);

	/**
	 * Registers the identity, transport, and Stripe adapter test seams.
	 */
	public function register(): void {
		add_filter( 'pre_option_jetpack_options', array( $this, 'jetpack_options' ) );
		add_filter( 'pre_option_jetpack_private_options', array( $this, 'jetpack_private_options' ) );
		add_filter( 'pre_option_wcpay_account_data', array( $this, 'account_cache' ) );
		add_filter( 'pre_http_request', array( $this, 'intercept' ), PHP_INT_MIN, 3 );
		add_filter( 'script_loader_src', array( $this, 'stripe_adapter_src' ), PHP_INT_MAX, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_stripe_adapter' ), 0 );
		add_action( 'rest_api_init', array( $this, 'register_audit_route' ) );
	}

	/** @return array<string,mixed> */
	public function account_cache(): array {
		return array(
			'data'               => $this->state()['account'],
			'fetched'            => time(),
			'errored'            => false,
			'consecutive_errors' => 0,
		);
	}

	/**
	 * Registers the authenticated post-run audit route.
	 */
	public function register_audit_route(): void {
		register_rest_route(
			'wc-native-payments-e2e/v1',
			'/provider-fixture-audit',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'audit' ),
				// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce registers this capability for shop managers and administrators.
				'permission_callback' => static fn(): bool => current_user_can( 'manage_woocommerce' ),
			)
		);
	}

	/** @return array<string,mixed> */
	public function jetpack_options(): array {
		return array(
			'id'          => self::BLOG_ID,
			'master_user' => 1,
			'register'    => 'secretless-ci',
		);
	}

	/** @return array<string,mixed> */
	public function jetpack_private_options(): array {
		return array(
			'blog_token'  => 'dummyblog.blog-token',
			'user_tokens' => array( 1 => 'dummyuser.user-token.1' ),
		);
	}

	/**
	 * @param mixed               $preempt Existing preempted response.
	 * @param array<string,mixed> $args HTTP arguments.
	 * @param string              $url Request URL.
	 * @return mixed
	 */
	public function intercept( $preempt, array $args, string $url ) {
		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || 'public-api.wordpress.com' !== ( $parsed['host'] ?? '' ) ) {
			return $preempt;
		}

		$method       = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		$request_body = $args['body'] ?? '';
		$body         = is_string( $request_body ) ? $request_body : '';
		$path         = (string) ( $parsed['path'] ?? '' );
		$query        = array();
		parse_str( (string) ( $parsed['query'] ?? '' ), $query );

		$decoded_body = is_array( $request_body ) ? $request_body : ( '' === $body ? null : json_decode( $body, true ) );
		if ( ! is_array( $request_body ) && '' !== $body && JSON_ERROR_NONE !== json_last_error() ) {
			$decoded_body = $body;
		}
		$this->record_request( $method, $path, $query, $decoded_body );
		if ( ! in_array( $method, array( 'GET', 'POST' ), true ) ) {
			return $this->failure( 'unsupported_method', "Unsupported fixture method: $method $path" );
		}
		if (
			'https' !== ( $parsed['scheme'] ?? '' )
			|| isset( $parsed['user'] )
			|| isset( $parsed['pass'] )
			|| isset( $parsed['port'] )
			|| isset( $parsed['fragment'] )
		) {
			return $this->failure( 'escaped_request', "Secretless WooPayments CI blocked external request: $method $url" );
		}
		$package_versions_path = '/wpcom/v2/sites/' . self::BLOG_ID . '/jetpack-package-versions';
		if ( $package_versions_path === $path ) {
			if ( 'POST' !== $method ) {
				return $this->failure( 'unsupported_method', "Unsupported fixture method: $method $path" );
			}
			$signed_keys       = array( 'body-hash', 'nonce', 'signature', 'timestamp', 'token' );
			$actual_query_keys = array_keys( $query );
			sort( $signed_keys );
			sort( $actual_query_keys );
			if ( $signed_keys !== $actual_query_keys || array_filter( $query, static fn( $value ): bool => ! is_scalar( $value ) || '' === (string) $value ) ) {
				return $this->failure( 'invalid_query', "Fixture rejected query parameters for $method $path" );
			}
			$package_versions = json_decode( $body, true );
			if (
				array( 'package_versions' => array( 'connection' => self::CONNECTION_VERSION ) ) !== $package_versions
			) {
				return $this->failure( 'invalid_body', "Fixture rejected the package-version body for $method $path" );
			}

			return $this->response( array() );
		}
		$site_prefix     = '/wpcom/v2/sites/' . self::BLOG_ID . '/wcpay/';
		$transact_prefix = '/wpcom/v2/sites/' . self::BLOG_ID . '/transact/';
		$public_prefix   = '/wpcom/v2/wcpay/';
		if ( 0 === strpos( $path, $site_prefix ) ) {
			$route = substr( $path, strlen( $site_prefix ) );
		} elseif ( 0 === strpos( $path, $transact_prefix ) ) {
			$route = 'transact/' . substr( $path, strlen( $transact_prefix ) );
		} elseif ( 0 === strpos( $path, $public_prefix ) ) {
			$route = 'public/' . substr( $path, strlen( $public_prefix ) );
		} else {
			return $this->failure( 'unknown_request', "Unrecognized WooPayments fixture request: $method $path" );
		}
		$query_error = $this->validate_query( $method, $route, $query );
		if ( $query_error instanceof WP_Error ) {
			return $query_error;
		}
		$payload = $this->decode_body( $method, $body, $route );
		if ( $payload instanceof WP_Error ) {
			return $payload;
		}

		$state = $this->state();
		switch ( "$method $route" ) {
			case 'GET public/payment_methods/recommended':
				return $this->response(
					array(
						array(
							'id'    => 'card',
							'title' => 'Cards',
						),
						array(
							'id'    => 'klarna',
							'title' => 'Klarna',
						),
					)
				);
			case 'GET public/incentives':
			case 'GET public/onboarding/fields_data':
				return $this->response( array() );
			case 'GET public/accounts/fraud_services':
				return $this->response(
					array(
						'version'  => 1,
						'enabled'  => false,
						'services' => array(),
					)
				);
			case 'GET accounts':
				return $this->response( $state['account'] );
			case 'GET woopay/compatibility':
				return $this->response(
					array(
						'incompatible_extensions' => array(),
						'adapted_extensions'      => array(),
						'available_countries'     => array( 'US' ),
					)
				);
			case 'POST accounts':
				$state['settings'] = array_replace_recursive( $state['settings'], $payload );
				$state['account']  = $this->apply_account_write( $state['account'], $payload );
				update_option( self::STATE_OPTION, $state );
				update_option( 'wcpay_account_data', $this->account_cache(), false );
				return $this->response( $state['account'] );
			case 'POST accounts/store_setup':
				return $this->response( array() );
			case 'POST compatibility':
				return $this->response( array( 'result' => 'ok' ) );
			case 'POST accounts/platform_checkout':
				$state['woopay_webhook_secret_hash'] = hash( 'sha256', $payload['webhook_secret'] );
				update_option( self::STATE_OPTION, $state );
				return $this->response( array( 'result' => 'success' ) );
			case 'GET transactions':
				return $this->response( array( 'data' => $state['transactions'] ) );
			case 'GET transactions/summary':
				return $this->response( $this->summary( $state['transactions'] ) );
			case 'GET authorizations/summary':
				return $this->response(
					array(
						'count' => 0,
						'total' => 0,
					)
				);
			case 'GET deposits/overview-all':
				return $this->response( $state['overview'] );
			case 'GET deposits':
				return $this->response( array( 'data' => $this->filter_status( $state['deposits'], $query ) ) );
			case 'GET deposits/summary':
				return $this->response( $this->summary( $this->filter_status( $state['deposits'], $query ) ) );
			case 'GET disputes':
				return $this->response( array( 'data' => $state['disputes'] ) );
			case 'GET disputes/summary':
				return $this->response( $this->summary( $state['disputes'] ) );
			case 'GET transact/currency/rates':
				return $this->response(
					array(
						'aud' => 1.52,
						'cad' => 1.36,
						'chf' => 0.88,
						'eur' => 0.92,
						'gbp' => 0.79,
						'jpy' => 147.0,
						'nzd' => 1.65,
						'sek' => 10.4,
					)
				);
			case 'GET disputes/status_counts':
				return $this->response(
					array(
						'needs_response' => 0,
						'under_review'   => 0,
					)
				);
			case 'GET fraud_ruleset':
				return $this->response( array( 'ruleset_config' => array() ) );
			case 'POST fraud_ruleset':
				$state['fraud_ruleset'] = $payload;
				update_option( self::STATE_OPTION, $state );
				return $this->response( $payload );
		}

		return $this->failure( 'unknown_request', "Unrecognized WooPayments fixture request: $method $route" );
	}

	/** @return array<string,mixed> */
	public function audit(): array {
		$requests = get_option( self::REQUEST_LOG_OPTION, array() );
		$failures = get_option( self::FAILURE_LOG_OPTION, array() );
		$requests = is_array( $requests ) ? $requests : array();
		$failures = is_array( $failures ) ? $failures : array();
		$covered  = array();
		foreach ( $requests as $request ) {
			$path   = (string) ( $request['path'] ?? '' );
			$prefix = '/wpcom/v2/sites/' . self::BLOG_ID . '/wcpay/';
			if ( 0 === strpos( $path, $prefix ) ) {
				$covered[] = (string) ( $request['method'] ?? '' ) . ' ' . substr( $path, strlen( $prefix ) );
				continue;
			}
			$prefix = '/wpcom/v2/sites/' . self::BLOG_ID . '/transact/';
			if ( 0 === strpos( $path, $prefix ) ) {
				$covered[] = (string) ( $request['method'] ?? '' ) . ' transact/' . substr( $path, strlen( $prefix ) );
			}
		}
		$missing        = array_values( array_diff( self::REQUIRED_ROUTES, array_unique( $covered ) ) );
		$state          = $this->state();
		$state_restored = 'Native CI store' === ( $state['account']['business_profile']['name'] ?? null )
			&& 'Native CI store' === ( $state['settings']['business_name'] ?? null )
			&& array() === ( $state['fraud_ruleset'] ?? null );

		return array(
			'requests'        => $requests,
			'failures'        => $failures,
			'required_routes' => self::REQUIRED_ROUTES,
			'missing_routes'  => $missing,
			'coverage'        => array() === $missing,
			'state_restored'  => $state_restored,
			'clean'           => array() === $failures && array() === $missing && $state_restored,
		);
	}

	/**
	 * Registers the strict Stripe adapter for product messaging.
	 */
	public function register_stripe_adapter(): void {
		if ( wp_script_is( 'stripe', 'registered' ) ) {
			return;
		}
		wp_register_script( 'stripe', plugin_dir_url( __FILE__ ) . 'stripe-messaging-adapter.js', array(), '1', true );
	}

	/**
	 * Forces the final exact Stripe handle to the local CI adapter.
	 *
	 * @param string $src Script source URL.
	 * @param string $handle Registered script handle.
	 */
	public function stripe_adapter_src( string $src, string $handle ): string {
		if ( 'stripe' !== $handle ) {
			return $src;
		}

		return plugin_dir_url( __FILE__ ) . 'stripe-messaging-adapter.js';
	}

	/** @return array<string,mixed> */
	private function state(): array {
		$state = get_option( self::STATE_OPTION, array() );
		if ( is_array( $state ) && isset( $state['account'], $state['transactions'], $state['deposits'] ) ) {
			return $state;
		}
		$account = array(
			'account_id'                 => 'acct_native_ci',
			'country'                    => 'US',
			'default_currency'           => 'usd',
			'payments_enabled'           => true,
			'payouts_enabled'            => true,
			'details_submitted'          => true,
			'is_live'                    => false,
			'test_publishable_key'       => 'pk_test_native_ci',
			'live_publishable_key'       => '',
			'statement_descriptor'       => 'NATIVE CI',
			'business_profile'           => array(
				'name'            => 'Native CI store',
				'url'             => 'https://example.test',
				'support_address' => array( 'country' => 'US' ),
				'support_email'   => 'support@example.test',
				'support_phone'   => '+10000000000',
			),
			'branding'                   => array(
				'logo'            => '',
				'icon'            => '',
				'primary_color'   => '#000000',
				'secondary_color' => '#ffffff',
			),
			'communications_email'       => 'owner@example.test',
			'store_currencies'           => array( 'default' => 'usd' ),
			'customer_currencies'        => array( 'supported' => array( 'usd', 'eur', 'aud', 'cad', 'chf', 'gbp', 'jpy', 'nzd', 'sek' ) ),
			'account_details'            => array(
				'account_status' => array( 'text' => 'Enabled' ),
				'payout_status'  => array( 'text' => 'Enabled' ),
				'banner'         => null,
			),
			'deposits'                   => array(
				'interval'                 => 'daily',
				'weekly_anchor'            => 'monday',
				'monthly_anchor'           => 1,
				'delay_days'               => 2,
				'status'                   => 'enabled',
				'restrictions'             => '',
				'completed_waiting_period' => true,
			),
			'platform_checkout_eligible' => true,
			'capabilities'               => array(
				'card_payments'   => 'active',
				'klarna_payments' => 'active',
			),
			'supported_payment_methods'  => array( 'card', 'klarna' ),
			'fees'                       => array(
				'card'   => array(),
				'klarna' => array(),
			),
		);
		$state   = array(
			'account'                    => $account,
			'settings'                   => array( 'business_name' => 'Native CI store' ),
			'woopay_webhook_secret_hash' => null,
			'transactions'               => array(
				array(
					'id'             => 'ch_ci_1',
					'transaction_id' => 'ch_ci_1',
					'type'           => 'charge',
					'amount'         => 10000,
					'net'            => 9700,
					'fee'            => 300,
					'currency'       => 'usd',
					'created'        => 1704067200,
					'date'           => 1704067200,
					'status'         => 'succeeded',
				),
			),
			'deposits'                   => array(
				array(
					'id'           => 'po_ci_paid',
					'status'       => 'paid',
					'amount'       => 9700,
					'currency'     => 'usd',
					'date'         => 1704153600,
					'arrival_date' => 1704240000,
				),
				array(
					'id'           => 'po_ci_pending',
					'status'       => 'pending',
					'amount'       => 2500,
					'currency'     => 'usd',
					'date'         => 1704326400,
					'arrival_date' => 1704412800,
				),
				array(
					'id'           => 'po_ci_pending_2',
					'status'       => 'pending',
					'amount'       => 1500,
					'currency'     => 'usd',
					'date'         => 1704499200,
					'arrival_date' => 1704585600,
				),
			),
			'disputes'                   => array(),
			'overview'                   => array(
				'balance' => array(
					'available' => array(
						array(
							'amount'   => 9700,
							'currency' => 'usd',
						),
					),
					'pending'   => array(
						array(
							'amount'   => 2500,
							'currency' => 'usd',
						),
					),
					'instant'   => array(),
				),
				'deposit' => array(
					'last_paid' => array(
						array(
							'amount'   => 9700,
							'currency' => 'usd',
						),
					),
				),
				'account' => array( 'default_currency' => 'usd' ),
			),
			'fraud_ruleset'              => array(),
		);
		update_option( self::STATE_OPTION, $state );
		return $state;
	}

	/**
	 * Applies the provider's flat account-settings write vocabulary to its nested account response.
	 *
	 * @param array<string,mixed> $account Account response state.
	 * @param array<string,mixed> $payload Validated provider write.
	 * @return array<string,mixed>
	 */
	private function apply_account_write( array $account, array $payload ): array {
		$paths = array(
			'business_name'                   => array( 'business_profile', 'name' ),
			'business_url'                    => array( 'business_profile', 'url' ),
			'business_support_address'        => array( 'business_profile', 'support_address' ),
			'business_support_email'          => array( 'business_profile', 'support_email' ),
			'business_support_phone'          => array( 'business_profile', 'support_phone' ),
			'branding_logo'                   => array( 'branding', 'logo' ),
			'branding_icon'                   => array( 'branding', 'icon' ),
			'branding_primary_color'          => array( 'branding', 'primary_color' ),
			'branding_secondary_color'        => array( 'branding', 'secondary_color' ),
			'deposit_schedule_interval'       => array( 'deposits', 'interval' ),
			'deposit_schedule_monthly_anchor' => array( 'deposits', 'monthly_anchor' ),
			'deposit_schedule_weekly_anchor'  => array( 'deposits', 'weekly_anchor' ),
		);
		foreach ( $payload as $key => $value ) {
			if ( isset( $paths[ $key ] ) ) {
				list( $section, $field )       = $paths[ $key ];
				$account[ $section ][ $field ] = $value;
			} elseif ( 'test_mode' !== $key ) {
				$account[ $key ] = $value;
			}
		}

		return $account;
	}

	/**
	 * Filters fixture rows by the requested status.
	 *
	 * @param array<int,array<string,mixed>> $rows Fixture rows.
	 * @param array<int|string,mixed>        $query Request query.
	 * @return array<int,array<string,mixed>>
	 */
	private function filter_status( array $rows, array $query ): array {
		$status = isset( $query['status_is'] ) && is_scalar( $query['status_is'] ) ? (string) $query['status_is'] : '';
		return '' === $status ? $rows : array_values( array_filter( $rows, static fn( array $row ): bool => (string) ( $row['status'] ?? '' ) === $status ) );
	}

	/**
	 * Summarizes fixture rows.
	 *
	 * @param array<int,array<string,mixed>> $rows Fixture rows.
	 * @return array<string,mixed>
	 */
	private function summary( array $rows ): array {
		return array(
			'count'    => count( $rows ),
			'total'    => array_sum( array_map( static fn( array $row ): int => (int) ( $row['amount'] ?? 0 ), $rows ) ),
			'currency' => 'usd',
		);
	}

	/**
	 * Validates the query vocabulary for one supported route.
	 *
	 * @param string                  $method HTTP method.
	 * @param string                  $route Fixture route.
	 * @param array<int|string,mixed> $query Request query.
	 * @return true|WP_Error
	 */
	private function validate_query( string $method, string $route, array $query ) {
		$common_list = array( 'page', 'pagesize', 'sort', 'date_before', 'date_after', 'search', 'currency', 'test_mode' );
		$signed      = array( 'body-hash', 'nonce', 'signature', 'timestamp', 'token' );
		$schemas     = array(
			'GET accounts'                           => array_merge( $signed, array( 'test_mode', 'woocommerce_store_id' ) ),
			'GET transactions'                       => array_merge( $signed, $common_list, array( 'match', 'type', 'deposit_id', 'direction', 'limit' ) ),
			'GET transactions/summary'               => array_merge( $signed, $common_list, array( 'match', 'type', 'deposit_id', 'direction', 'limit' ) ),
			'GET authorizations/summary'             => array_merge( $signed, $common_list, array( 'match', 'direction', 'limit' ) ),
			'GET deposits/overview-all'              => array_merge( $signed, array( 'test_mode' ) ),
			'GET deposits'                           => array_merge( $signed, $common_list, array( 'status_is', 'direction', 'limit', 'store_currency_is' ) ),
			'GET deposits/summary'                   => array_merge( $signed, $common_list, array( 'status_is' ) ),
			'GET disputes'                           => array_merge( $signed, $common_list, array( 'status_is', 'status_not', 'direction', 'limit' ) ),
			'GET disputes/summary'                   => array_merge( $signed, $common_list, array( 'status_is', 'status_not', 'direction', 'limit' ) ),
			'GET transact/currency/rates'            => array_merge( $signed, array( 'currency_from', 'test_mode' ) ),
			'GET disputes/status_counts'             => array_merge( $signed, array( 'test_mode' ) ),
			'GET fraud_ruleset'                      => array_merge( $signed, array( 'test_mode' ) ),
			'GET woopay/compatibility'               => array_merge( $signed, array( 'test_mode' ) ),
			'POST accounts'                          => $signed,
			'POST accounts/store_setup'              => $signed,
			'POST accounts/platform_checkout'        => $signed,
			'POST compatibility'                     => $signed,
			'POST fraud_ruleset'                     => $signed,
			'GET public/payment_methods/recommended' => array( 'country_code', 'locale' ),
			'GET public/accounts/fraud_services'     => array(),
			'GET public/incentives'                  => array( 'active_for', 'country', 'locale', 'has_orders', 'has_payments', 'has_wcpay' ),
			'GET public/onboarding/fields_data'      => array( 'body-hash', 'locale', 'nonce', 'signature', 'test_mode', 'timestamp', 'token' ),
		);
		$key         = "$method $route";
		if ( ! isset( $schemas[ $key ] ) ) {
			return $this->failure( 'unknown_request', "Unrecognized WooPayments fixture request: $method $route" );
		}
		$unknown = array_diff( array_keys( $query ), $schemas[ $key ] );
		foreach ( $query as $value ) {
			if ( ! is_scalar( $value ) ) {
				$unknown[] = 'non_scalar_value';
			}
		}
		if ( array() !== $unknown ) {
			return $this->failure( 'invalid_query', "Fixture rejected query parameters for $method $route: " . implode( ', ', $unknown ) );
		}
		if ( isset( $query['test_mode'] ) && '1' !== $query['test_mode'] ) {
			return $this->failure( 'invalid_query', "Fixture rejected query values for $method $route" );
		}
		if ( in_array( $key, array( 'GET woopay/compatibility', 'POST accounts/store_setup', 'POST accounts/platform_checkout', 'POST compatibility' ), true ) ) {
			$actual_keys   = array_keys( $query );
			$expected_keys = 'GET woopay/compatibility' === $key ? array_merge( $signed, array( 'test_mode' ) ) : $signed;
			sort( $actual_keys );
			sort( $expected_keys );
			$required_values = array_diff_key( $query, array( 'body-hash' => true ) );
			if ( $actual_keys !== $expected_keys || array_filter( $required_values, static fn( $value ): bool => ! is_scalar( $value ) || '' === (string) $value ) ) {
				return $this->failure( 'invalid_query', "Fixture rejected query parameters for $method $route" );
			}
		}
		if (
			in_array( $key, array( 'GET transactions', 'GET transactions/summary', 'GET authorizations/summary', 'GET deposits', 'GET disputes', 'GET disputes/summary' ), true )
			&& (
				( isset( $query['direction'] ) && 'desc' !== $query['direction'] )
				|| ( isset( $query['limit'] ) && '100' !== $query['limit'] )
			)
		) {
			return $this->failure( 'invalid_query', "Fixture rejected query values for $method $route" );
		}
		if ( 'GET authorizations/summary' === $key && isset( $query['sort'] ) && 'created' !== $query['sort'] ) {
			return $this->failure( 'invalid_query', "Fixture rejected query values for $method $route" );
		}
		if ( 'GET deposits' === $key && isset( $query['store_currency_is'] ) && 'usd' !== $query['store_currency_is'] ) {
			return $this->failure( 'invalid_query', "Fixture rejected query values for $method $route" );
		}
		if ( 'GET transact/currency/rates' === $key ) {
			$actual_keys   = array_keys( $query );
			$expected_keys = array_merge( $signed, array( 'currency_from', 'test_mode' ) );
			sort( $actual_keys );
			sort( $expected_keys );
			$required_values = array_diff_key( $query, array( 'body-hash' => true ) );
			if (
				$actual_keys !== $expected_keys
				|| 'usd' !== ( $query['currency_from'] ?? null )
				|| '1' !== ( $query['test_mode'] ?? null )
				|| array_filter( $required_values, static fn( $value ): bool => ! is_scalar( $value ) || '' === (string) $value )
			) {
				return $this->failure( 'invalid_query', "Fixture rejected query values for $method $route" );
			}
		}

		return true;
	}

	/**
	 * Decodes and validates a request body.
	 *
	 * @param string $method HTTP method.
	 * @param string $body Request body.
	 * @param string $route Fixture route.
	 * @return array<string,mixed>|WP_Error
	 */
	private function decode_body( string $method, string $body, string $route ) {
		if ( 'GET' === $method ) {
			if ( '' !== $body ) {
				return $this->failure( 'invalid_body', "Fixture rejected a body for $method $route" );
			}
			return array();
		}
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) || array() === $decoded || $this->is_list( $decoded ) ) {
			return $this->failure( 'invalid_body', "Fixture requires a JSON object body for $method $route" );
		}
		if ( 0 === strpos( $route, 'public/' ) ) {
			return $this->failure( 'invalid_body', "Fixture rejected a body for $method $route" );
		}
		if ( 'accounts' === $route ) {
			return $this->validate_typed_object( $decoded, self::ACCOUNT_WRITE_SCHEMA, "$method $route" );
		}
		if ( 'accounts/store_setup' === $route ) {
			return $this->validate_store_setup( $decoded, "$method $route" );
		}
		if ( 'accounts/platform_checkout' === $route ) {
			$result = $this->validate_exact_typed_object(
				$decoded,
				array(
					'test_mode'      => 'bool',
					'webhook_secret' => 'string',
				),
				"$method $route"
			);
			if ( $result instanceof WP_Error || '' === $decoded['webhook_secret'] ) {
				return $result instanceof WP_Error ? $result : $this->failure( 'invalid_body', "Fixture rejected an empty webhook secret for $method $route" );
			}
			return $result;
		}
		if ( 'compatibility' === $route ) {
			return $this->validate_compatibility_data( $decoded, "$method $route" );
		}
		if ( 'fraud_ruleset' === $route ) {
			return $this->validate_typed_object(
				$decoded,
				array(
					'ruleset_config' => 'list',
					'test_mode'      => 'bool',
				),
				"$method $route"
			);
		}
		return $decoded;
	}

	/**
	 * Validates the compatibility snapshot emitted by Core's queue service.
	 *
	 * @param array<string,mixed> $payload Request object.
	 * @param string              $label Request label.
	 * @return array<string,mixed>|WP_Error
	 */
	private function validate_compatibility_data( array $payload, string $label ) {
		$result = $this->validate_exact_typed_object(
			$payload,
			array(
				'compatibility_data' => 'array',
				'test_mode'          => 'bool',
			),
			$label
		);
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$data   = $payload['compatibility_data'];
		$result = $this->validate_exact_typed_object(
			$data,
			array(
				'woopayments_version'    => 'string',
				'woocommerce_version'    => 'string',
				'woocommerce_permalinks' => 'array',
				'woocommerce_shop'       => 'string',
				'woocommerce_cart'       => 'string',
				'woocommerce_checkout'   => 'string',
				'blog_theme'             => 'string',
				'active_plugins'         => 'list',
				'post_types_count'       => 'array',
			),
			"$label compatibility_data"
		);
		if ( $result instanceof WP_Error ) {
			return $result;
		}
		foreach ( $data['active_plugins'] as $plugin ) {
			if ( ! is_string( $plugin ) || '' === $plugin ) {
				return $this->failure( 'invalid_body', "Fixture rejected active plugin data for $label" );
			}
		}
		foreach ( $data['post_types_count'] as $post_type => $count ) {
			if ( ! is_string( $post_type ) || '' === $post_type || ! is_int( $count ) || 0 > $count ) {
				return $this->failure( 'invalid_body', "Fixture rejected post type counts for $label" );
			}
		}

		return $payload;
	}

	/**
	 * Validates the exact store-setup snapshot emitted by Core.
	 *
	 * Values may vary by runner, while the producer's required structure and types must not.
	 *
	 * @param array<string,mixed> $payload Request object.
	 * @param string              $label Request label.
	 * @return array<string,mixed>|WP_Error
	 */
	private function validate_store_setup( array $payload, string $label ) {
		$result = $this->validate_exact_typed_object(
			$payload,
			array(
				'snapshot'  => 'array',
				'test_mode' => 'bool',
			),
			$label
		);
		if ( $result instanceof WP_Error ) {
			return $result;
		}
		$snapshot = $payload['snapshot'];
		$result   = $this->validate_exact_typed_object(
			$snapshot,
			array(
				'gateway'                => 'array',
				'payment_methods'        => 'array',
				'provider_capabilities'  => 'array',
				'express_checkout_in_payment_methods_enabled' => 'scalar',
				'saved_cards_enabled'    => 'bool',
				'manual_capture_enabled' => 'bool',
				'debug_log_enabled'      => 'bool',
				'payment_request'        => 'array',
				'woopay'                 => 'array',
				'multi_currency_enabled' => 'bool',
				'stripe_billing_enabled' => 'bool',
				'plugin'                 => 'array',
				'wp_setup'               => 'array',
				'wc_setup'               => 'array',
			),
			"$label snapshot"
		);
		if ( $result instanceof WP_Error ) {
			return $result;
		}
		$schemas = array(
			'gateway'               => array(
				'enabled'              => 'bool',
				'test_mode'            => 'bool',
				'test_mode_onboarding' => 'bool',
			),
			'payment_methods'       => array(
				'available'  => 'list',
				'enabled'    => 'list',
				'disabled'   => 'list',
				'duplicates' => 'list',
			),
			'provider_capabilities' => array(
				'available' => 'list',
				'enabled'   => 'list',
				'disabled'  => 'list',
			),
			'payment_request'       => array(
				'enabled'              => 'bool',
				'enabled_locations'    => 'list',
				'button_type'          => 'string',
				'button_size'          => 'string',
				'button_theme'         => 'string',
				'button_border_radius' => 'scalar',
			),
			'woopay'                => array(
				'enabled'                 => 'bool',
				'enabled_locations'       => 'list',
				'store_logo'              => 'string',
				'custom_message'          => 'string',
				'invalid_extension_found' => 'bool',
			),
			'plugin'                => array(
				'version'              => 'string',
				'activation_timestamp' => 'scalar_or_null',
			),
			'wp_setup'              => array(
				'name'           => 'string',
				'url'            => 'string',
				'active_theme'   => 'array',
				'active_plugins' => 'list',
				'version'        => 'string',
				'locale'         => 'string',
			),
			'wc_setup'              => array(
				'version'                     => 'string',
				'store_id'                    => 'scalar_or_null',
				'currency'                    => 'string',
				'tracking_enabled'            => 'bool',
				'registered_payment_gateways' => 'list',
				'enabled_payment_gateways'    => 'list',
				'wc_subscriptions_active'     => 'bool',
				'wc_subscriptions_version'    => 'string',
			),
		);
		foreach ( $schemas as $key => $schema ) {
			$result = $this->validate_exact_typed_object( $snapshot[ $key ], $schema, "$label snapshot.$key" );
			if ( $result instanceof WP_Error ) {
				return $result;
			}
		}

		return $payload;
	}

	/**
	 * Validates an object with no missing or additional keys.
	 *
	 * @param array<string,mixed>  $payload Request object.
	 * @param array<string,string> $schema Required key types.
	 * @param string               $label Request label.
	 * @return array<string,mixed>|WP_Error
	 */
	private function validate_exact_typed_object( array $payload, array $schema, string $label ) {
		$actual   = array_keys( $payload );
		$expected = array_keys( $schema );
		sort( $actual );
		sort( $expected );
		if ( $actual !== $expected ) {
			return $this->failure( 'invalid_body', "Fixture rejected required body keys for $label" );
		}

		return $this->validate_typed_object( $payload, $schema, $label );
	}

	/**
	 * Validates an object against an exact key and type schema.
	 *
	 * @param array<string,mixed>  $payload Request object.
	 * @param array<string,string> $schema Allowed key types.
	 * @param string               $label Request label.
	 * @return array<string,mixed>|WP_Error
	 */
	private function validate_typed_object( array $payload, array $schema, string $label ) {
		$unknown = array_diff( array_keys( $payload ), array_keys( $schema ) );
		if ( array() !== $unknown ) {
			return $this->failure( 'invalid_body', "Fixture rejected body keys for $label: " . implode( ', ', $unknown ) );
		}
		foreach ( $payload as $key => $value ) {
			$type  = $schema[ $key ];
			$valid = ( 'bool' === $type && is_bool( $value ) )
				|| ( 'string' === $type && is_string( $value ) )
				|| ( 'scalar' === $type && is_scalar( $value ) )
				|| ( 'scalar_or_null' === $type && ( is_scalar( $value ) || null === $value ) )
				|| ( 'array' === $type && is_array( $value ) && ! $this->is_list( $value ) )
				|| ( 'list' === $type && is_array( $value ) && $this->is_list( $value ) )
				|| ( 'int_or_null' === $type && ( is_int( $value ) || null === $value ) );
			if ( ! $valid ) {
				return $this->failure( 'invalid_body', "Fixture rejected body type for $label key $key" );
			}
		}
		return $payload;
	}

	/**
	 * Determines whether an array uses consecutive integer keys.
	 *
	 * @param array<int|string,mixed> $value Array to inspect.
	 */
	private function is_list( array $value ): bool {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Records one intercepted request for the post-run audit.
	 *
	 * @param string                  $method HTTP method.
	 * @param string                  $path Request path.
	 * @param array<int|string,mixed> $query Request query.
	 * @param mixed                   $body Request body.
	 */
	private function record_request( string $method, string $path, array $query, $body ): void {
		$query = $this->canonicalize( $query );
		if ( is_array( $body ) ) {
			if ( array_key_exists( 'webhook_secret', $body ) ) {
				$body['webhook_secret'] = '(redacted)';
			}
			$body = $this->canonicalize( $body );
		}
		$log   = get_option( self::REQUEST_LOG_OPTION, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'method' => $method,
			'path'   => $path,
			'query'  => $query,
			'body'   => $body,
		);
		update_option( self::REQUEST_LOG_OPTION, $log );
	}

	/**
	 * Sorts associative fixture data recursively for stable audit output.
	 *
	 * @param array<int|string,mixed> $value Fixture data.
	 * @return array<int|string,mixed>
	 */
	private function canonicalize( array $value ): array {
		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$value[ $key ] = $this->canonicalize( $item );
			}
		}
		if ( ! $this->is_list( $value ) ) {
			ksort( $value );
		}
		return $value;
	}

	/**
	 * Creates a WordPress HTTP API response.
	 *
	 * @param array<int|string,mixed> $body Response body.
	 * @return array<string,mixed>
	 */
	private function response( array $body ): array {
		return array(
			'headers'  => array( 'content-type' => 'application/json; charset=utf-8' ),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Records and returns a fail-closed fixture error.
	 *
	 * @param string $code Failure code.
	 * @param string $message Failure message.
	 */
	private function failure( string $code, string $message ): WP_Error {
		$failures   = get_option( self::FAILURE_LOG_OPTION, array() );
		$failures   = is_array( $failures ) ? $failures : array();
		$failures[] = array(
			'code'    => $code,
			'message' => $message,
		);
		update_option( self::FAILURE_LOG_OPTION, $failures );
		return new WP_Error( 'woopayments_native_ci_' . $code, $message );
	}
}

if ( defined( 'E2E_WOOPAYMENTS_NATIVE_FIXTURE' ) && (bool) constant( 'E2E_WOOPAYMENTS_NATIVE_FIXTURE' ) ) {
	( new WooCommerce_WooPayments_Native_CI_Provider_Fixture() )->register();
}
