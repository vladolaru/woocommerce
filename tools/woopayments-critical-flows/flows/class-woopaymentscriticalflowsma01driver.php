<?php
/**
 * Deterministic MA-01 access-control probe.
 *
 * @package WooCommerce\Tools\WooPaymentsCriticalFlows
 */

/**
 * Project secret-free REST and authenticated-page access-control facts.
 */
final class WooPaymentsCriticalFlowsMa01Driver {

	private const RAW_SCHEMA     = 'woopayments_ma01_probe.v1';
	private const CUSTOMER_LOGIN = 'ma01-customer';
	private const CUSTOMER_EMAIL = 'ma01-customer@example.com';
	private const MAX_REDIRECTS  = 3;
	private const NO_PROXY       = '127.0.0.1,localhost,wordpress';

	private const EXTERNAL_ORIGINS = array(
		'ref'    => 'http://localhost:8082',
		'target' => 'http://store8889.localhost:8889',
	);

	private const INTERNAL_TRANSPORTS = array(
		'ref'    => 'http://127.0.0.1',
		'target' => 'http://wordpress',
	);

	private const REQUESTED_PATHS = array(
		'ref'    => '/wp-admin/admin.php?page=wc-admin&path=/payments/overview',
		'target' => '/wp-admin/admin.php?page=wc-admin&path=/woopayments/overview',
	);

	private const ROUTES = array(
		'transactions' => '/wc/v3/payments/transactions',
		'deposits'     => '/wc/v3/payments/deposits',
		'disputes'     => '/wc/v3/payments/disputes',
	);

	/**
	 * Execute one access-control probe.
	 *
	 * @internal
	 *
	 * @param array<int,string> $tool_args WP-CLI eval-file arguments.
	 */
	public static function run( array $tool_args ): void {
		$store           = (string) ( $tool_args[0] ?? '' );
		$action          = (string) ( $tool_args[1] ?? '' );
		$run_stamp       = (string) ( $tool_args[2] ?? '' );
		$external_origin = (string) ( $tool_args[3] ?? '' );
		$payload         = self::initial_payload( $store, $run_stamp );

		if ( ! isset( self::EXTERNAL_ORIGINS[ $store ] ) ) {
			$payload['blockers'][] = 'invalid_store';
		}
		if ( 'probe' !== $action ) {
			$payload['blockers'][] = 'invalid_action';
		}
		if ( 1 !== preg_match( '/^[0-9]{8}T[0-9]{6}Z-[0-9]+$/', $run_stamp ) ) {
			$payload['blockers'][] = 'invalid_run_stamp';
		}
		if ( isset( self::EXTERNAL_ORIGINS[ $store ] ) && self::EXTERNAL_ORIGINS[ $store ] !== $external_origin ) {
			$payload['blockers'][] = 'invalid_external_origin';
		}
		if ( ! empty( $payload['blockers'] ) ) {
			self::emit( $payload );
			return;
		}

		$payload['runtime_owner'] = self::runtime_owner();
		$expected_owner           = 'ref' === $store ? 'plugin' : 'native';
		if ( $expected_owner !== $payload['runtime_owner'] ) {
			$payload['blockers'][] = 'runtime_owner_unavailable';
			self::emit( $payload );
			return;
		}

		try {
			$customer_ensure = self::ensure_customer();
		} catch ( Throwable $throwable ) {
			$payload['blockers'][] = 'customer_fixture_unavailable';
			self::emit( $payload );
			return;
		}
		$customer = is_array( $customer_ensure ) ? ( $customer_ensure['user'] ?? null ) : null;
		if ( ! $customer instanceof WP_User ) {
			$payload['blockers'][] = 'customer_fixture_unavailable';
			self::emit( $payload );
			return;
		}

		$admin = get_user_by( 'id', 1 );
		if ( ! $admin instanceof WP_User ) {
			$payload['blockers'][] = 'administrator_fixture_unavailable';
			self::emit( $payload );
			return;
		}

		$payload['fixture'] = array(
			'customer_login'              => self::CUSTOMER_LOGIN,
			'customer_roles'              => array_values( $customer->roles ),
			'customer_preexisting'        => true === ( $customer_ensure['preexisting'] ?? false ),
			'customer_created'            => true === ( $customer_ensure['created'] ?? false ),
			'customer_manage_woocommerce' => user_can( $customer, 'manage_woocommerce' ), // phpcs:ignore WordPress.WP.Capabilities.Unknown
			'admin_manage_woocommerce'    => user_can( $admin, 'manage_woocommerce' ), // phpcs:ignore WordPress.WP.Capabilities.Unknown
		);

		$store_currency = strtolower( (string) get_woocommerce_currency() );
		if ( 1 !== preg_match( '/^[a-z]{3}$/', $store_currency ) ) {
			$payload['blockers'][] = 'store_currency_unavailable';
			self::emit( $payload );
			return;
		}

		self::probe_routes( $payload, $customer, $admin, $store_currency );

		$session_manager = WP_Session_Tokens::get_instance( $customer->ID );
		$session_token   = '';
		try {
			$session_token = $session_manager->create( time() + ( 5 * MINUTE_IN_SECONDS ) );
			if ( ! is_string( $session_token ) || '' === $session_token ) {
				$session_token         = '';
				$payload['blockers'][] = 'temporary_session_unavailable';
			} else {
				$payload['http'] = self::authenticated_http_probe(
					$store,
					$external_origin,
					$customer->ID,
					$session_token
				);
			}
		} catch ( Throwable $throwable ) {
			$payload['blockers'][] = 'authenticated_http_probe_unavailable';
		} finally {
			if ( '' !== $session_token ) {
				try {
					$session_manager->destroy( $session_token );
					$payload['exact_session_cleanup'] = false === $session_manager->verify( $session_token );
				} catch ( Throwable $throwable ) {
					$payload['exact_session_cleanup'] = false;
				}
			}
		}

		if ( ! $payload['exact_session_cleanup'] ) {
			$payload['blockers'][] = 'exact_session_cleanup_unproven';
		}
		self::emit( $payload );
	}

	/**
	 * Build the exact raw evidence shape with unavailable observation defaults.
	 *
	 * @param string $store     Store role.
	 * @param string $run_stamp Runner invocation stamp.
	 * @return array<string,mixed>
	 */
	private static function initial_payload( string $store, string $run_stamp ): array {
		$routes = array();
		foreach ( self::ROUTES as $name => $path ) {
			$routes[ $name ] = array(
				'path'     => $path,
				'customer' => array(
					'status'                => 0,
					'code'                  => '',
					'standard_error'        => false,
					'financial_list_absent' => false,
				),
				'admin'    => array(
					'status'                => 0,
					'well_formed_data_list' => false,
					'list_envelope'         => false,
				),
			);
		}

		return array(
			'schema'                => self::RAW_SCHEMA,
			'store'                 => $store,
			'run_stamp'             => $run_stamp,
			'runtime_owner'         => '',
			'fixture'               => array(
				'customer_login'              => self::CUSTOMER_LOGIN,
				'customer_roles'              => array(),
				'customer_preexisting'        => false,
				'customer_created'            => false,
				'customer_manage_woocommerce' => false,
				'admin_manage_woocommerce'    => false,
			),
			'routes'                => $routes,
			'http'                  => array(
				'requested_path'    => self::REQUESTED_PATHS[ $store ] ?? '',
				'final_path'        => '',
				'requested_status'  => 0,
				'final_status'      => 0,
				'redirect_count'    => 0,
				'transport_errors'  => array(),
				'logged_in_marker'  => false,
				'logout_marker'     => false,
				'login_form_marker' => false,
				'permission_marker' => false,
				'app_marker'        => false,
			),
			'exact_session_cleanup' => false,
			'blockers'              => array(),
		);
	}

	/**
	 * Idempotently ensure the dedicated customer without modifying an existing user.
	 *
	 * @return array{user:WP_User,preexisting:bool,created:bool}|null
	 */
	private static function ensure_customer(): ?array {
		$customer = get_user_by( 'login', self::CUSTOMER_LOGIN );
		if ( $customer instanceof WP_User ) {
			return array(
				'user'        => $customer,
				'preexisting' => true,
				'created'     => false,
			);
		}

		$password = wp_generate_password( 32, true, true );
		$user_id  = wp_insert_user(
			array(
				'user_login' => self::CUSTOMER_LOGIN,
				'user_email' => self::CUSTOMER_EMAIL,
				'user_pass'  => $password,
				'role'       => 'customer',
			)
		);
		unset( $password );
		if ( is_wp_error( $user_id ) || ! is_int( $user_id ) || $user_id <= 0 ) {
			return null;
		}

		$customer = get_user_by( 'id', $user_id );
		if ( ! $customer instanceof WP_User ) {
			return null;
		}
		return array(
			'user'        => $customer,
			'preexisting' => false,
			'created'     => true,
		);
	}

	/**
	 * Dispatch the exact UI-shaped list routes as both actors.
	 *
	 * @param array<string,mixed> $payload        Raw evidence payload.
	 * @param WP_User             $customer       Dedicated customer.
	 * @param WP_User             $admin          Administrator user 1.
	 * @param string              $store_currency Lowercase store currency.
	 */
	private static function probe_routes( array &$payload, WP_User $customer, WP_User $admin, string $store_currency ): void {
		$queries = array(
			'transactions' => array(
				'page'              => 1,
				'pagesize'          => 1,
				'sort'              => 'date',
				'direction'         => 'desc',
				'store_currency_is' => $store_currency,
			),
			'deposits'     => array(
				'page'              => 1,
				'pagesize'          => 1,
				'sort'              => 'date',
				'direction'         => 'desc',
				'store_currency_is' => $store_currency,
			),
			'disputes'     => array(
				'page'     => 1,
				'pagesize' => 1,
			),
		);

		foreach ( self::ROUTES as $name => $path ) {
			try {
				$payload['routes'][ $name ]['customer'] = self::customer_route_result(
					self::dispatch_route( $customer->ID, $path, $queries[ $name ] )
				);
				$payload['routes'][ $name ]['admin']    = self::admin_route_result(
					self::dispatch_route( $admin->ID, $path, $queries[ $name ] )
				);
			} catch ( Throwable $throwable ) {
				$payload['blockers'][] = $name . '_route_probe_unavailable';
			}
		}
	}

	/**
	 * Dispatch one internal REST request as an exact actor and restore the caller.
	 *
	 * @param int                 $user_id User ID.
	 * @param string              $path    REST path.
	 * @param array<string,mixed> $query   Query parameters.
	 * @return WP_REST_Response
	 * @throws RuntimeException When WordPress cannot return a REST response.
	 */
	private static function dispatch_route( int $user_id, string $path, array $query ): WP_REST_Response {
		$previous_user_id = get_current_user_id();
		try {
			wp_set_current_user( $user_id );
			$request = new WP_REST_Request( 'GET', $path );
			$request->set_query_params( $query );
			$response = rest_do_request( $request );
			if ( is_wp_error( $response ) || ! $response instanceof WP_REST_Response ) {
				throw new RuntimeException( 'REST dispatch failed.' );
			}
			return $response;
		} finally {
			wp_set_current_user( $previous_user_id );
		}
	}

	/**
	 * Project a customer REST response without retaining financial data.
	 *
	 * @param WP_REST_Response $response REST response.
	 * @return array<string,mixed>
	 */
	private static function customer_route_result( WP_REST_Response $response ): array {
		$status           = (int) $response->get_status();
		$data             = $response->get_data();
		$code             = is_array( $data ) && is_string( $data['code'] ?? null ) ? $data['code'] : '';
		$top_level_exact  = is_array( $data )
			&& 3 === count( $data )
			&& array_key_exists( 'code', $data )
			&& array_key_exists( 'message', $data )
			&& array_key_exists( 'data', $data );
		$error_data       = $top_level_exact && is_array( $data['data'] ) ? $data['data'] : null;
		$error_data_exact = is_array( $error_data )
			&& 1 === count( $error_data )
			&& array_key_exists( 'status', $error_data )
			&& is_int( $error_data['status'] )
			&& $status === $error_data['status'];
		$standard_error   = $top_level_exact
			&& is_string( $data['code'] )
			&& is_string( $data['message'] )
			&& $error_data_exact;
		return array(
			'status'                => $status,
			'code'                  => $code,
			'standard_error'        => $standard_error,
			'financial_list_absent' => $standard_error,
		);
	}

	/**
	 * Project an administrator REST response without retaining list rows.
	 *
	 * @param WP_REST_Response $response REST response.
	 * @return array<string,mixed>
	 */
	private static function admin_route_result( WP_REST_Response $response ): array {
		$data        = $response->get_data();
		$data_list   = is_array( $data ) && is_array( $data['data'] ?? null ) ? $data['data'] : null;
		$list_values = is_array( $data_list ) && array_is_list( $data_list );
		$rows_valid  = $list_values && count( $data_list ) === count( array_filter( $data_list, 'is_array' ) );
		$total_valid = ! is_array( $data ) || ! array_key_exists( 'total_count', $data ) || is_int( $data['total_count'] );
		return array(
			'status'                => (int) $response->get_status(),
			'well_formed_data_list' => $rows_valid,
			'list_envelope'         => is_array( $data ) && $list_values && $total_valid,
		);
	}

	/**
	 * Perform one authenticated request through the fixed Docker-local transport.
	 *
	 * @param string $store           Store role.
	 * @param string $external_origin Browser-facing origin.
	 * @param int    $user_id         Customer ID.
	 * @param string $session_token   Exact temporary session token.
	 * @return array<string,mixed>
	 */
	private static function authenticated_http_probe( string $store, string $external_origin, int $user_id, string $session_token ): array {
		$requested_path = self::REQUESTED_PATHS[ $store ];
		$result         = array(
			'requested_path'    => $requested_path,
			'final_path'        => '',
			'requested_status'  => 0,
			'final_status'      => 0,
			'redirect_count'    => 0,
			'transport_errors'  => array(),
			'logged_in_marker'  => false,
			'logout_marker'     => false,
			'login_form_marker' => false,
			'permission_marker' => false,
			'app_marker'        => false,
		);
		$cookie_hash    = md5( $external_origin );
		$expiration     = time() + ( 5 * MINUTE_IN_SECONDS );
		$cookie_schemes = array(
			'wordpress_' . $cookie_hash           => 'auth',
			'wordpress_sec_' . $cookie_hash       => 'secure_auth',
			'wordpress_logged_in_' . $cookie_hash => 'logged_in',
		);
		$cookie_parts   = array();
		foreach ( $cookie_schemes as $cookie_name => $scheme ) {
			$cookie_value   = wp_generate_auth_cookie( $user_id, $expiration, $scheme, $session_token );
			$cookie_parts[] = $cookie_name . '=' . rawurlencode( $cookie_value );
		}
		$cookie_header           = implode( '; ', $cookie_parts );
		$external_parts          = wp_parse_url( $external_origin );
		$external_host           = (string) ( $external_parts['host'] ?? '' ) . ':' . (string) ( $external_parts['port'] ?? '' );
		$internal_origin         = self::INTERNAL_TRANSPORTS[ $store ];
		$current_path            = $requested_path;
		$previous_no_proxy       = getenv( 'NO_PROXY' );
		$previous_no_proxy_lower = getenv( 'no_proxy' );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Required process-local proxy boundary.
		putenv( 'NO_PROXY=' . self::NO_PROXY );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Required process-local proxy boundary.
		putenv( 'no_proxy=' . self::NO_PROXY );
		try {
			for ( $request_number = 0; $request_number <= self::MAX_REDIRECTS; ++$request_number ) {
				$internal_url = $internal_origin . $current_path;
				$response     = wp_remote_get(
					$internal_url,
					array(
						'redirection' => 0,
						'timeout'     => 15,
						'headers'     => array(
							'Host'   => $external_host,
							'Cookie' => $cookie_header,
						),
					)
				);
				if ( is_wp_error( $response ) ) {
					$result['transport_errors'][] = 'http_request_failed';
					break;
				}

				$status = (int) wp_remote_retrieve_response_code( $response );
				if ( 0 === $request_number ) {
					$result['requested_status'] = $status;
				}
				$result['final_status'] = $status;
				$result['final_path']   = self::project_path( $current_path );
				$location               = (string) wp_remote_retrieve_header( $response, 'location' );
				if ( ! in_array( $status, array( 301, 302, 303, 307, 308 ), true ) ) {
					self::project_body_markers( (string) wp_remote_retrieve_body( $response ), $result );
					break;
				}
				if ( '' === $location ) {
					$result['transport_errors'][] = 'redirect_location_missing';
					break;
				}
				if ( self::MAX_REDIRECTS === $request_number ) {
					$result['transport_errors'][] = 'redirect_limit_exceeded';
					break;
				}
				$redirect_path = self::redirect_path( $location, $external_origin, $current_path );
				if ( null === $redirect_path ) {
					$result['transport_errors'][] = 'redirect_not_same_origin';
					break;
				}
				$current_path = $redirect_path;
				++$result['redirect_count'];
			}
		} finally {
			self::restore_environment( 'NO_PROXY', $previous_no_proxy );
			self::restore_environment( 'no_proxy', $previous_no_proxy_lower );
		}

		return $result;
	}

	/**
	 * Resolve a relative or exact-origin absolute redirect to path and query only.
	 *
	 * @param string $location        Redirect location.
	 * @param string $external_origin Approved browser origin.
	 * @param string $current_path    Current path and query.
	 */
	private static function redirect_path( string $location, string $external_origin, string $current_path ): ?string {
		if ( '' === $location || 1 === preg_match( '/[\x00-\x20\x7f]/', $location ) || str_starts_with( $location, '//' ) ) {
			return null;
		}

		$parts = wp_parse_url( $location );
		if ( false === $parts ) {
			return null;
		}
		if ( isset( $parts['scheme'] ) || isset( $parts['host'] ) ) {
			if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
				return null;
			}
			$origin = (string) ( $parts['scheme'] ?? '' ) . '://' . (string) ( $parts['host'] ?? '' );
			if ( isset( $parts['port'] ) ) {
				$origin .= ':' . (string) $parts['port'];
			}
			if ( $external_origin !== $origin ) {
				return null;
			}
			$path = (string) ( $parts['path'] ?? '/' );
			return $path . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		}

		if ( str_starts_with( $location, '?' ) ) {
			return (string) strtok( $current_path, '?' ) . $location;
		}
		if ( str_starts_with( $location, '/' ) ) {
			return $location;
		}

		$current_url_path = (string) wp_parse_url( $current_path, PHP_URL_PATH );
		$directory        = substr( $current_url_path, 0, (int) strrpos( $current_url_path, '/' ) + 1 );
		return '/' . ltrim( $directory . $location, '/' );
	}

	/**
	 * Project a path while retaining only the non-secret routing query keys.
	 *
	 * @param string $path Path and query.
	 */
	private static function project_path( string $path ): string {
		$url_path = (string) wp_parse_url( $path, PHP_URL_PATH );
		$query    = (string) wp_parse_url( $path, PHP_URL_QUERY );
		if ( '' === $query ) {
			return '' !== $url_path ? $url_path : '/';
		}

		parse_str( $query, $query_values );
		$safe_query = array();
		foreach ( array( 'page', 'path' ) as $key ) {
			if ( isset( $query_values[ $key ] ) && is_string( $query_values[ $key ] ) ) {
				$value        = str_replace( '%2F', '/', rawurlencode( $query_values[ $key ] ) );
				$safe_query[] = $key . '=' . $value;
			}
		}
		return $url_path . ( ! empty( $safe_query ) ? '?' . implode( '&', $safe_query ) : '' );
	}

	/**
	 * Project only non-sensitive authentication, denial, and app markers.
	 *
	 * @param string              $response_body Response HTML held only in memory.
	 * @param array<string,mixed> $result        HTTP projection.
	 */
	private static function project_body_markers( string $response_body, array &$result ): void {
		$result['logged_in_marker']  = 1 === preg_match( '/<body\b[^>]*class=["\'][^"\']*\blogged-in\b/i', $response_body );
		$result['logout_marker']     = false !== stripos( $response_body, 'action=logout' )
			|| false !== stripos( $response_body, 'customer-logout' )
			|| false !== stripos( $response_body, 'woocommerce-MyAccount-navigation-link--customer-logout' );
		$result['login_form_marker'] = false !== stripos( $response_body, 'id="loginform"' )
			|| false !== stripos( $response_body, "id='loginform'" );
		$result['permission_marker'] = false !== stripos( $response_body, 'You need a higher level of permission' )
			|| false !== stripos( $response_body, 'Sorry, you are not allowed to access this page' );
		$result['app_marker']        = false !== stripos( $response_body, 'id="root"' )
			|| false !== stripos( $response_body, "id='root'" )
			|| false !== stripos( $response_body, 'id="woocommerce-admin-root"' )
			|| false !== stripos( $response_body, "id='woocommerce-admin-root'" )
			|| false !== stripos( $response_body, 'woocommerce-layout__primary' )
			|| false !== stripos( $response_body, 'woocommerce-payments-page' )
			|| false !== stripos( $response_body, 'woopaymentsSettings' );
	}

	/**
	 * Restore an environment variable without widening process scope.
	 *
	 * @param string       $name     Environment variable name.
	 * @param string|false $previous Previous value.
	 */
	private static function restore_environment( string $name, $previous ): void {
		if ( false === $previous ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Restore the process-local proxy boundary.
			putenv( $name );
			return;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Restore the process-local proxy boundary.
		putenv( $name . '=' . $previous );
	}

	/** Return the active WooPayments runtime owner using the runner's attribution rules. */
	private static function runtime_owner(): string {
		$active_plugins = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active_plugins = array_merge( $active_plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$owner = in_array( 'woocommerce-payments/woocommerce-payments.php', $active_plugins, true ) ? 'plugin' : 'none';
		if ( class_exists( '\\Automattic\\WooCommerce\\Internal\\Payments\\NativePaymentsRuntimeArbiter' ) && function_exists( 'wc_get_container' ) ) {
			try {
				$owner = (string) wc_get_container()->get( '\\Automattic\\WooCommerce\\Internal\\Payments\\NativePaymentsRuntimeArbiter' )->get_runtime_owner();
			} catch ( Throwable $throwable ) {
				$owner = 'probe_failed';
			}
		}
		return $owner;
	}

	/**
	 * Emit exactly one stable raw object.
	 *
	 * @param array<string,mixed> $payload Raw evidence payload.
	 */
	private static function emit( array $payload ): void {
		echo wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ) . "\n";
	}
}

$tool_args = isset( $args ) && is_array( $args ) ? $args : array_slice( $argv ?? array(), 1 );
WooPaymentsCriticalFlowsMa01Driver::run( $tool_args );
