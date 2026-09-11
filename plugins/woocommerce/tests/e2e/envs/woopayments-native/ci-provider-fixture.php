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
	/** @var self|null */
	private static $registered_instance;

	private const STATE_OPTION       = 'e2e_woopayments_native_provider_state';
	private const REQUEST_LOG_OPTION = 'e2e_woopayments_native_request_log';
	private const FAILURE_LOG_OPTION = 'e2e_woopayments_native_failure_log';
	private const BLOG_ID            = 777;
	private const REQUIRED_ROUTES    = array(
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

	/**
	 * Registers the identity, transport, and Stripe adapter test seams.
	 */
	public function register(): void {
		self::$registered_instance = $this;
		add_filter( 'pre_option_jetpack_options', array( $this, 'jetpack_options' ) );
		add_filter( 'pre_option_jetpack_private_options', array( $this, 'jetpack_private_options' ) );
		add_filter( 'pre_option_wcpay_account_data', array( $this, 'account_cache' ) );
		add_filter( 'pre_http_request', array( $this, 'intercept' ), PHP_INT_MIN, 3 );
		add_filter( 'script_loader_src', array( $this, 'stripe_adapter_src' ), PHP_INT_MAX, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_stripe_adapter' ), 0 );
		add_action( 'rest_api_init', array( $this, 'register_audit_route' ) );
	}

	/** @return self|null */
	public static function registered_instance(): ?self {
		return self::$registered_instance;
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
	 * Captures the activation cache and establishes the connected cache through the real account service.
	 *
	 * @param callable $refresh_account_data Forced account-service refresh callback returning account data.
	 * @return array<string,mixed>
	 * @throws RuntimeException When the refresh does not establish a connected physical cache.
	 */
	public function prepare_physical_account_cache_for_run( callable $refresh_account_data ): array {
		$callback = array( $this, 'account_cache' );
		$removed  = remove_filter( 'pre_option_wcpay_account_data', $callback );
		try {
			$missing                                     = new stdClass();
			$physical                                    = get_option( 'wcpay_account_data', $missing );
			$test_mode_premise                           = get_option( 'wcpay_onboarding_test_mode', $missing );
			$state                                       = $this->state();
			$state['pre_fixture_physical_account_cache'] = array(
				'exists'     => $missing !== $physical,
				'value'      => $missing === $physical ? null : $physical,
				'normalized' => $this->normalize_account_cache( $missing === $physical ? null : $physical ),
			);
			$state['pre_fixture_test_mode_premise']      = array(
				'exists' => $missing !== $test_mode_premise,
				'value'  => $missing === $test_mode_premise ? null : $test_mode_premise,
			);
			unset( $state['physical_account_cache_restoration'], $state['test_mode_premise_restoration'] );
			update_option( self::STATE_OPTION, $state );
			update_option( 'wcpay_onboarding_test_mode', 'yes', false );
			$cache_deleted = wp_cache_delete( 'wcpay_onboarding_test_mode', 'options' );
			unset( $cache_deleted );
			if ( 'yes' !== get_option( 'wcpay_onboarding_test_mode', 'no' ) ) {
				throw new RuntimeException( 'The WooPayments test-mode onboarding premise could not be established.' );
			}

			$account       = $refresh_account_data();
			$physical      = get_option( 'wcpay_account_data', null );
			$physical_data = is_array( $physical ) && is_array( $physical['data'] ?? null ) ? $physical['data'] : array();
			if ( '' === (string) ( $account['account_id'] ?? '' ) || ( $physical_data['account_id'] ?? null ) !== $account['account_id'] || true === ( $physical['errored'] ?? false ) ) {
				throw new RuntimeException( 'The real account refresh did not establish a connected physical WooPayments cache.' );
			}

			$state                                    = $this->state();
			$state['physical_account_cache_baseline'] = $this->normalize_account_cache( $physical );
			update_option( self::STATE_OPTION, $state );
			return $account;
		} finally {
			if ( $removed ) {
				add_filter( 'pre_option_wcpay_account_data', $callback );
			}
		}
	}

	/**
	 * Finalizes a fixture run by restoring the exact account cache captured before installation.
	 *
	 * @return array{exists:bool,value:mixed,normalized:array<int|string,mixed>,run_normalized:array<int|string,mixed>,run_restored:bool,pre_fixture_restored:bool}
	 * @throws RuntimeException When fixture preparation did not capture an account cache.
	 */
	public function restore_pre_fixture_physical_account_cache(): array {
		$state             = $this->state();
		$pre_fixture_cache = $state['pre_fixture_physical_account_cache'] ?? null;
		if ( ! is_array( $pre_fixture_cache ) || ! is_bool( $pre_fixture_cache['exists'] ?? null ) || ! array_key_exists( 'value', $pre_fixture_cache ) ) {
			throw new RuntimeException( 'The pre-fixture WooPayments account cache was not captured.' );
		}

		$run_cache             = $this->physical_account_cache_snapshot();
		$run_normalized        = $run_cache['normalized'];
		$run_baseline          = is_array( $state['physical_account_cache_baseline'] ?? null ) ? $state['physical_account_cache_baseline'] : array();
		$run_restored          = $run_normalized === $run_baseline;
		$restoration           = $this->restore_physical_account_cache( $pre_fixture_cache['exists'], $pre_fixture_cache['value'] );
		$pre_cache_restored    = $restoration['exists'] === $pre_fixture_cache['exists']
			&& $restoration['value'] === $pre_fixture_cache['value'];
		$pre_fixture_test_mode = $state['pre_fixture_test_mode_premise'] ?? null;
		if ( ! is_array( $pre_fixture_test_mode ) || ! is_bool( $pre_fixture_test_mode['exists'] ?? null ) || ! array_key_exists( 'value', $pre_fixture_test_mode ) ) {
			throw new RuntimeException( 'The pre-fixture WooPayments test-mode premise was not captured.' );
		}
		$test_mode_run_enabled  = 'yes' === get_option( 'wcpay_onboarding_test_mode', 'no' );
		$test_mode_restoration  = $this->restore_option( 'wcpay_onboarding_test_mode', $pre_fixture_test_mode['exists'], $pre_fixture_test_mode['value'] );
		$test_mode_pre_restored = $test_mode_restoration['exists'] === $pre_fixture_test_mode['exists']
			&& $test_mode_restoration['value'] === $pre_fixture_test_mode['value'];

		$state['physical_account_cache_restoration'] = array(
			'run_normalized' => $run_normalized,
			'run_restored'   => $run_restored,
		);
		$state['test_mode_premise_restoration']      = array(
			'run_enabled'          => $test_mode_run_enabled,
			'pre_fixture_restored' => $test_mode_pre_restored,
		);
		update_option( self::STATE_OPTION, $state );
		return array_merge(
			$restoration,
			array(
				'run_normalized'       => $run_normalized,
				'run_restored'         => $run_restored,
				'pre_fixture_restored' => $pre_cache_restored,
			)
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
		$jetpack_report_paths = array(
			'/wpcom/v2/sites/' . self::BLOG_ID . '/jetpack-active-connected-plugins',
			'/wpcom/v2/sites/' . self::BLOG_ID . '/jetpack-package-versions',
		);
		if ( in_array( $path, $jetpack_report_paths, true ) ) {
			if ( 'POST' !== $method ) {
				return $this->failure( 'unsupported_method', "Unsupported fixture method: $method $path" );
			}
			$query_validation = $this->validate_signing_envelope( $method, $path, $query );
			if ( $query_validation instanceof WP_Error ) {
				return $query_validation;
			}
			$report = $this->decode_json_object( $body, "$method $path" );
			if ( $report instanceof WP_Error ) {
				return $report;
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
			case 'GET payment_method_promotions':
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
				return $this->response( $state['account'] );
			case 'POST accounts/store_setup':
				return $this->response( array() );
			case 'POST compatibility':
				return $this->response( array( 'result' => 'ok' ) );
			case 'POST accounts/platform_checkout':
				$state['woopay_webhook_secret_hash']                     = hash( 'sha256', $payload['webhook_secret'] );
				$state['expected_retained_state']['webhook_secret_hash'] = $state['woopay_webhook_secret_hash'];
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
				return $this->response( array( 'ruleset_config' => $state['fraud_ruleset']['ruleset_config'] ) );
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
		$missing                 = array_values( array_diff( self::REQUIRED_ROUTES, array_unique( $covered ) ) );
		$state                   = $this->state();
		$mutable_state           = $this->canonicalize(
			array(
				'account'       => $state['account'] ?? null,
				'settings'      => $state['settings'] ?? null,
				'fraud_ruleset' => $state['fraud_ruleset'] ?? null,
			)
		);
		$baseline_state          = is_array( $state['audit_baseline'] ?? null ) ? $this->canonicalize( $state['audit_baseline'] ) : array();
		$retained_state          = array( 'webhook_secret_hash' => $state['woopay_webhook_secret_hash'] ?? null );
		$expected_retain         = is_array( $state['expected_retained_state'] ?? null ) ? $this->canonicalize( $state['expected_retained_state'] ) : array();
		$physical_snapshot       = $this->physical_account_cache_snapshot();
		$physical_cache          = $physical_snapshot['normalized'];
		$physical_cache_baseline = is_array( $state['physical_account_cache_baseline'] ?? null ) ? $state['physical_account_cache_baseline'] : array();
		$run_cache_restored      = $physical_cache === $physical_cache_baseline;
		$pre_fixture_restored    = true;
		$pre_fixture_cache       = $state['pre_fixture_physical_account_cache'] ?? null;
		if ( is_array( $pre_fixture_cache ) && is_bool( $pre_fixture_cache['exists'] ?? null ) && array_key_exists( 'value', $pre_fixture_cache ) ) {
			$restoration = $state['physical_account_cache_restoration'] ?? null;
			if ( is_array( $restoration ) && is_bool( $restoration['run_restored'] ?? null ) && is_array( $restoration['run_normalized'] ?? null ) ) {
				$run_cache_restored   = $restoration['run_restored'];
				$physical_cache       = $physical_snapshot['normalized'];
				$run_normalized       = $restoration['run_normalized'];
				$pre_fixture_restored = $physical_snapshot['exists'] === $pre_fixture_cache['exists']
					&& $physical_snapshot['value'] === $pre_fixture_cache['value'];
			} else {
				$run_normalized       = $physical_cache;
				$pre_fixture_restored = false;
			}
		} else {
			$run_normalized = $physical_cache;
		}
		$physical_cache_restored = $run_cache_restored && $pre_fixture_restored;
		$test_mode_run_enabled   = true;
		$test_mode_pre_restored  = true;
		$pre_fixture_test_mode   = $state['pre_fixture_test_mode_premise'] ?? null;
		if ( is_array( $pre_fixture_test_mode ) && is_bool( $pre_fixture_test_mode['exists'] ?? null ) && array_key_exists( 'value', $pre_fixture_test_mode ) ) {
			$test_mode_restoration = $state['test_mode_premise_restoration'] ?? null;
			if ( is_array( $test_mode_restoration ) && is_bool( $test_mode_restoration['run_enabled'] ?? null ) && is_bool( $test_mode_restoration['pre_fixture_restored'] ?? null ) ) {
				$missing_option         = new stdClass();
				$current_test_mode      = get_option( 'wcpay_onboarding_test_mode', $missing_option );
				$test_mode_run_enabled  = $test_mode_restoration['run_enabled'];
				$test_mode_pre_restored = $test_mode_restoration['pre_fixture_restored']
					&& ( $missing_option !== $current_test_mode ) === $pre_fixture_test_mode['exists']
					&& ( $missing_option === $current_test_mode ? null : $current_test_mode ) === $pre_fixture_test_mode['value'];
			} else {
				$test_mode_run_enabled  = 'yes' === get_option( 'wcpay_onboarding_test_mode', 'no' );
				$test_mode_pre_restored = false;
			}
		}
		$test_mode_premise_restored = $test_mode_run_enabled && $test_mode_pre_restored;
		$state_restored             = $mutable_state === $baseline_state
			&& $this->canonicalize( $retained_state ) === $expected_retain
			&& $physical_cache_restored
			&& $test_mode_premise_restored;

		return array(
			'requests'               => $requests,
			'failures'               => $failures,
			'required_routes'        => self::REQUIRED_ROUTES,
			'missing_routes'         => $missing,
			'coverage'               => array() === $missing,
			'state_restored'         => $state_restored,
			'retained_state'         => array(
				'webhook_secret_hash' => null === $retained_state['webhook_secret_hash'] ? null : '(sha256)',
			),
			'physical_account_cache' => array(
				'ignored_fields'       => array( 'fetched' ),
				'normalized'           => $physical_cache,
				'run_normalized'       => $run_normalized,
				'run_restored'         => $run_cache_restored,
				'pre_fixture_restored' => $pre_fixture_restored,
				'restored'             => $physical_cache_restored,
			),
			'test_mode_premise'      => array(
				'run_enabled'          => $test_mode_run_enabled,
				'pre_fixture_restored' => $test_mode_pre_restored,
				'restored'             => $test_mode_premise_restored,
			),
			'clean'                  => array() === $failures && array() === $missing && $state_restored,
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
		$account                                  = array(
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
			'statement_descriptor_kanji' => '',
			'statement_descriptor_kana'  => '',
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
		$settings                                 = array(
			'test_mode'                       => true,
			'statement_descriptor'            => 'NATIVE CI',
			'statement_descriptor_kanji'      => '',
			'statement_descriptor_kana'       => '',
			'business_name'                   => 'Native CI store',
			'business_url'                    => 'https://example.test',
			'business_support_address'        => array( 'country' => 'US' ),
			'business_support_email'          => 'support@example.test',
			'business_support_phone'          => '+10000000000',
			'branding_logo'                   => '',
			'branding_icon'                   => '',
			'branding_primary_color'          => '#000000',
			'branding_secondary_color'        => '#ffffff',
			'communications_email'            => 'owner@example.test',
			'deposit_schedule_interval'       => 'daily',
			'deposit_schedule_monthly_anchor' => 1,
			'deposit_schedule_weekly_anchor'  => 'monday',
		);
		$fraud_ruleset                            = array(
			'ruleset_config' => array(),
			'test_mode'      => true,
		);
		$state                                    = array(
			'account'                    => $account,
			'settings'                   => $settings,
			'woopay_webhook_secret_hash' => null,
			'expected_retained_state'    => array( 'webhook_secret_hash' => null ),
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
			'fraud_ruleset'              => $fraud_ruleset,
		);
		$state['audit_baseline']                  = $this->canonicalize(
			array(
				'account'       => $account,
				'settings'      => $settings,
				'fraud_ruleset' => $fraud_ruleset,
			)
		);
		$state['physical_account_cache_baseline'] = $this->normalize_account_cache(
			array(
				'data'               => $account,
				'fetched'            => 0,
				'errored'            => false,
				'consecutive_errors' => 0,
			)
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
	 * Validates the route and Jetpack signing boundary.
	 *
	 * @param string                  $method HTTP method.
	 * @param string                  $route Fixture route.
	 * @param array<int|string,mixed> $query Request query.
	 * @return true|WP_Error
	 */
	private function validate_query( string $method, string $route, array $query ) {
		$key   = "$method $route";
		$known = array(
			'GET accounts',
			'GET transactions',
			'GET transactions/summary',
			'GET authorizations/summary',
			'GET deposits/overview-all',
			'GET deposits',
			'GET deposits/summary',
			'GET disputes',
			'GET disputes/summary',
			'GET disputes/status_counts',
			'GET fraud_ruleset',
			'GET woopay/compatibility',
			'GET payment_method_promotions',
			'GET transact/currency/rates',
			'GET public/payment_methods/recommended',
			'GET public/accounts/fraud_services',
			'GET public/incentives',
			'GET public/onboarding/fields_data',
			'POST accounts',
			'POST accounts/store_setup',
			'POST accounts/platform_checkout',
			'POST compatibility',
			'POST fraud_ruleset',
		);
		if ( ! in_array( $key, $known, true ) ) {
			return $this->failure( 'unknown_request', "Unrecognized WooPayments fixture request: $method $route" );
		}

		$unsigned = array( 'GET public/payment_methods/recommended', 'GET public/accounts/fraud_services', 'GET public/incentives' );
		if ( in_array( $key, $unsigned, true ) ) {
			return true;
		}

		return $this->validate_signing_envelope( $method, $route, $query );
	}

	/**
	 * Validates that Jetpack supplied its complete signing envelope.
	 *
	 * @param string                  $method HTTP method.
	 * @param string                  $route Fixture route.
	 * @param array<int|string,mixed> $query Request query.
	 * @return true|WP_Error
	 */
	private function validate_signing_envelope( string $method, string $route, array $query ) {
		foreach ( array( 'body-hash', 'nonce', 'signature', 'timestamp', 'token' ) as $signing_key ) {
			if ( ! array_key_exists( $signing_key, $query ) || ! is_scalar( $query[ $signing_key ] ) ) {
				return $this->failure( 'invalid_query', "Fixture requires the Jetpack signing envelope for $method $route" );
			}
			if ( '' === (string) $query[ $signing_key ] && ( 'body-hash' !== $signing_key || 'GET' !== $method ) ) {
				return $this->failure( 'invalid_query', "Fixture requires nonempty Jetpack signing values for $method $route" );
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
		$decoded = $this->decode_json_object( $body, "$method $route" );
		if ( $decoded instanceof WP_Error ) {
			return $decoded;
		}
		if ( ! array_key_exists( 'test_mode', $decoded ) || ! is_bool( $decoded['test_mode'] ) ) {
			return $this->failure( 'invalid_body', "Fixture requires a boolean test_mode for $method $route" );
		}
		if ( 'accounts/platform_checkout' === $route ) {
			if ( ! isset( $decoded['webhook_secret'] ) || ! is_string( $decoded['webhook_secret'] ) || '' === $decoded['webhook_secret'] ) {
				return $this->failure( 'invalid_body', "Fixture requires a nonempty webhook secret for $method $route" );
			}
		}
		return $decoded;
	}

	/**
	 * Decodes a JSON object without policing producer-owned fields.
	 *
	 * @param string $body Request body.
	 * @param string $label Request label.
	 * @return array<string,mixed>|WP_Error
	 */
	private function decode_json_object( string $body, string $label ) {
		$object  = json_decode( $body );
		$decoded = json_decode( $body, true );
		if ( ! $object instanceof stdClass || ! is_array( $decoded ) ) {
			return $this->failure( 'invalid_body', "Fixture requires a JSON object body for $label" );
		}

		return $decoded;
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
	 * Reads physical account-cache existence and value without the connected fixture filter.
	 *
	 * @return array{exists:bool,value:mixed,normalized:array<int|string,mixed>}
	 */
	private function physical_account_cache_snapshot(): array {
		$callback = array( $this, 'account_cache' );
		$removed  = remove_filter( 'pre_option_wcpay_account_data', $callback );
		try {
			$missing = new stdClass();
			$current = get_option( 'wcpay_account_data', $missing );
			return array(
				'exists'     => $missing !== $current,
				'value'      => $missing === $current ? null : $current,
				'normalized' => $this->normalize_account_cache( $missing === $current ? null : $current ),
			);
		} finally {
			if ( $removed ) {
				add_filter( 'pre_option_wcpay_account_data', $callback );
			}
		}
	}

	/**
	 * Restores the physical account cache captured before fixture installation.
	 *
	 * @param bool  $existed Whether the option existed.
	 * @param mixed $value Exact captured option value.
	 * @return array{exists:bool,value:mixed,normalized:array<int|string,mixed>}
	 */
	private function restore_physical_account_cache( bool $existed, $value ): array {
		$callback = array( $this, 'account_cache' );
		$removed  = remove_filter( 'pre_option_wcpay_account_data', $callback );
		try {
			if ( $existed ) {
				update_option( 'wcpay_account_data', $value );
			} else {
				delete_option( 'wcpay_account_data' );
			}
			$cache_deleted = wp_cache_delete( 'wcpay_account_data', 'options' );
			unset( $cache_deleted );
			$missing = new stdClass();
			$current = get_option( 'wcpay_account_data', $missing );
			return array(
				'exists'     => $missing !== $current,
				'value'      => $missing === $current ? null : $current,
				'normalized' => $this->normalize_account_cache( $missing === $current ? null : $current ),
			);
		} finally {
			if ( $removed ) {
				add_filter( 'pre_option_wcpay_account_data', $callback );
			}
		}
	}

	/**
	 * Restores one exact option value captured before fixture installation.
	 *
	 * @param string $name Option name.
	 * @param bool   $existed Whether the option existed.
	 * @param mixed  $value Exact captured option value.
	 * @return array{exists:bool,value:mixed}
	 */
	private function restore_option( string $name, bool $existed, $value ): array {
		if ( $existed ) {
			update_option( $name, $value );
		} else {
			delete_option( $name );
		}
		$cache_deleted = wp_cache_delete( $name, 'options' );
		unset( $cache_deleted );
		$missing = new stdClass();
		$current = get_option( $name, $missing );
		return array(
			'exists' => $missing !== $current,
			'value'  => $missing === $current ? null : $current,
		);
	}

	/**
	 * Canonicalizes the account cache while excluding its volatile fetch timestamp.
	 *
	 * @param mixed $cache Account cache option value.
	 * @return array<int|string,mixed>
	 */
	private function normalize_account_cache( $cache ): array {
		if ( ! is_array( $cache ) ) {
			return array( 'value' => $cache );
		}
		unset( $cache['fetched'] );
		return $this->canonicalize( $cache );
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
