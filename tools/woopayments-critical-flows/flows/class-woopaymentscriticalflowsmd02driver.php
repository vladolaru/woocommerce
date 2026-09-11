<?php
/**
 * MD-02 dispute draft state and exact browser-session adapter.
 *
 * @package WooCommerce\Tools\WooPaymentsCriticalFlows
 */

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;

/**
 * Projects exact dispute state without retaining customer PII.
 */
final class WooPaymentsCriticalFlowsMd02Driver {

	private const STATE_SCHEMA = 'woopayments_md02_state_raw.v1';

	private const FILE_EVIDENCE_KEYS = array(
		'access_activity_log',
		'cancellation_policy',
		'cancellation_policy_disclosure',
		'customer_communication',
		'customer_signature',
		'duplicate_charge_documentation',
		'receipt',
		'refund_policy',
		'refund_policy_disclosure',
		'service_documentation',
		'shipping_documentation',
		'uncategorized_file',
	);

	/**
	 * Runs one state or caller-owned session action.
	 *
	 * @internal
	 *
	 * @param array<int,string> $tool_args WP-CLI eval-file arguments.
	 */
	public static function run( array $tool_args ): void {
		$store     = (string) ( $tool_args[0] ?? '' );
		$action    = (string) ( $tool_args[1] ?? '' );
		$run_stamp = (string) ( $tool_args[2] ?? '' );

		if ( ! in_array( $store, array( 'ref', 'target' ), true ) || 1 !== preg_match( '/^[0-9]{8}T[0-9]{6}Z-[0-9]+$/', $run_stamp ) ) {
			self::emit(
				array(
					'success'  => false,
					'blockers' => array( 'invalid_run_binding' ),
				)
			);
			return;
		}

		if ( 'probe' === $action ) {
			self::emit(
				self::probe(
					$store,
					$run_stamp,
					(string) ( $tool_args[3] ?? '' ),
					absint( $tool_args[4] ?? 0 ),
					(string) ( $tool_args[5] ?? '' ),
					(string) ( $tool_args[6] ?? '' ),
					(string) ( $tool_args[7] ?? '' )
				)
			);
			return;
		}

		if ( 'create-auth-session' === $action ) {
			self::emit(
				self::create_auth_session(
					absint( $tool_args[3] ?? 0 ),
					(string) ( $tool_args[4] ?? '' ),
					(string) ( $tool_args[5] ?? '' )
				)
			);
			return;
		}

		if ( 'destroy-auth-session' === $action ) {
			self::emit(
				self::destroy_auth_session(
					absint( $tool_args[3] ?? 0 ),
					(string) ( $tool_args[4] ?? '' )
				)
			);
			return;
		}

		self::emit(
			array(
				'success'  => false,
				'blockers' => array( 'invalid_action' ),
			)
		);
	}

	/**
	 * Projects the exact order-bound dispute state.
	 *
	 * @param string $store       Store role.
	 * @param string $run_stamp   Runner invocation stamp.
	 * @param string $phase       Probe phase.
	 * @param int    $order_id    Exact fixture order ID.
	 * @param string $charge_id   Exact provider charge ID.
	 * @param string $intent_id   Exact provider intent ID.
	 * @param string $dispute_id  Exact provider dispute ID.
	 * @return array<string,mixed>
	 */
	private static function probe( string $store, string $run_stamp, string $phase, int $order_id, string $charge_id, string $intent_id, string $dispute_id ): array {
		$blockers = array();
		$payload  = array(
			'schema'        => self::STATE_SCHEMA,
			'store'         => $store,
			'run_stamp'     => $run_stamp,
			'phase'         => $phase,
			'runtime_owner' => self::runtime_owner(),
			'identity'      => array(
				'order_id'   => $order_id,
				'charge_id'  => $charge_id,
				'intent_id'  => $intent_id,
				'dispute_id' => $dispute_id,
			),
			'lifecycle'     => array(
				'status'           => '',
				'due_by'           => 0,
				'past_due'         => false,
				'has_evidence'     => false,
				'submission_count' => -1,
			),
			'evidence'      => array(
				'product_description'         => '',
				'customer_name_present'       => false,
				'customer_name_matches_order' => false,
				'customer_name_hmac'          => '',
				'file_evidence_hmac'          => '',
				'decisive_state_hmac'         => '',
			),
			'metadata_keys' => array(),
			'blockers'      => &$blockers,
		);

		if (
			! in_array( $phase, array( 'pre', 'post', 'delayed' ), true )
			|| $order_id <= 0
			|| 1 !== preg_match( '/^(?:ch|py)_[A-Za-z0-9_]+$/', $charge_id )
			|| 1 !== preg_match( '/^pi_[A-Za-z0-9_]+$/', $intent_id )
			|| 1 !== preg_match( '/^[A-Za-z]{2,4}_[A-Za-z0-9_]+$/', $dispute_id )
		) {
			$blockers[] = 'invalid_fixture_identity';
			return $payload;
		}

		$context_key = (string) getenv( 'CRITICAL_FLOWS_RUN_CONTEXT_KEY' );
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $context_key ) ) {
			$blockers[] = 'run_context_key_unavailable';
			return $payload;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			$blockers[] = 'fixture_order_unavailable';
			return $payload;
		}

		$request  = new WP_REST_Request( 'GET', '/wc/v3/payments/disputes/' . rawurlencode( $dispute_id ) );
		$response = self::dispatch_as_admin( $request );
		$data     = $response->get_data();
		if ( 200 !== $response->get_status() || ! is_array( $data ) ) {
			$blockers[] = 'exact_dispute_unavailable';
			return $payload;
		}

		$charge            = is_array( $data['charge'] ?? null ) ? $data['charge'] : array();
		$response_order    = is_array( $data['order'] ?? null ) ? $data['order'] : array();
		$response_charge   = (string) ( $charge['id'] ?? '' );
		$response_intent   = (string) ( $data['payment_intent'] ?? '' );
		$response_order_id = absint( $response_order['id'] ?? 0 );
		if (
			(string) ( $data['id'] ?? '' ) !== $dispute_id
			|| $response_charge !== $charge_id
			|| $response_intent !== $intent_id
			|| $response_order_id !== $order_id
		) {
			$blockers[] = 'live_fixture_identity_mismatch';
		}

		$evidence         = is_array( $data['evidence'] ?? null ) ? $data['evidence'] : array();
		$evidence_details = is_array( $data['evidence_details'] ?? null ) ? $data['evidence_details'] : array();
		$metadata         = $data['metadata'] ?? array();
		if ( is_object( $metadata ) ) {
			$metadata = get_object_vars( $metadata );
		}
		if ( ! is_array( $metadata ) ) {
			$blockers[] = 'dispute_metadata_malformed';
			$metadata   = array();
		}
		$metadata_keys = array_map( 'strval', array_keys( $metadata ) );
		sort( $metadata_keys, SORT_STRING );

		$fixture_name  = trim( $order->get_formatted_billing_full_name() );
		$evidence_name = trim( (string) ( $evidence['customer_name'] ?? '' ) );
		$file_evidence = array();
		foreach ( self::FILE_EVIDENCE_KEYS as $field ) {
			$file_evidence[ $field ] = $evidence[ $field ] ?? '';
		}
		ksort( $file_evidence, SORT_STRING );

		$customer_name_hmac                        = self::context_hmac( 'customer-name', $evidence_name, $context_key );
		$file_evidence_hmac                        = self::context_hmac( 'file-evidence', $file_evidence, $context_key );
		$lifecycle                                 = array(
			'status'           => (string) ( $data['status'] ?? '' ),
			'due_by'           => (int) ( $evidence_details['due_by'] ?? 0 ),
			'past_due'         => true === ( $evidence_details['past_due'] ?? null ),
			'has_evidence'     => true === ( $evidence_details['has_evidence'] ?? null ),
			'submission_count' => (int) ( $evidence_details['submission_count'] ?? -1 ),
		);
		$projected_evidence                        = array(
			'product_description'         => (string) ( $evidence['product_description'] ?? '' ),
			'customer_name_present'       => '' !== $evidence_name,
			'customer_name_matches_order' => '' !== $fixture_name && '' !== $evidence_name && hash_equals( $fixture_name, $evidence_name ),
			'customer_name_hmac'          => $customer_name_hmac,
			'file_evidence_hmac'          => $file_evidence_hmac,
			'decisive_state_hmac'         => '',
		);
		$projected_evidence['decisive_state_hmac'] = self::context_hmac(
			'decisive-state',
			array(
				'identity'            => $payload['identity'],
				'lifecycle'           => $lifecycle,
				'product_description' => $projected_evidence['product_description'],
				'customer_name_hmac'  => $customer_name_hmac,
				'file_evidence_hmac'  => $file_evidence_hmac,
				'metadata_keys'       => $metadata_keys,
			),
			$context_key
		);

		$payload['lifecycle']     = $lifecycle;
		$payload['evidence']      = $projected_evidence;
		$payload['metadata_keys'] = $metadata_keys;
		return $payload;
	}

	/**
	 * Creates one caller-owned short-lived administrator session.
	 *
	 * @param int    $user_id       Exact administrator user ID.
	 * @param string $session_token Caller-owned session token.
	 * @param string $external_url  Browser-visible local URL.
	 * @return array<string,mixed>
	 */
	private static function create_auth_session( int $user_id, string $session_token, string $external_url ): array {
		$errors       = array();
		$auth_cookies = array();
		$expiration   = time() + HOUR_IN_SECONDS;
		$user         = $user_id > 0 ? get_userdata( $user_id ) : false;
		$url_parts    = wp_parse_url( $external_url );

		if ( ! $user instanceof WP_User || ! user_can( $user, 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			$errors[] = 'The exact WooCommerce administrator is unavailable.';
		}
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $session_token ) ) {
			$errors[] = 'The caller-owned session token is invalid.';
		}
		if ( ! self::is_local_url( $url_parts ) ) {
			$errors[] = 'The browser-visible local URL is invalid.';
		}
		if ( ! class_exists( 'WP_Session_Tokens' ) ) {
			$errors[] = 'WordPress session APIs are unavailable.';
		}

		if ( empty( $errors ) ) {
			$sessions = WP_Session_Tokens::get_instance( $user_id );
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- WordPress Core session hook.
			$session               = apply_filters( 'attach_session_information', array(), $user_id );
			$session['expiration'] = $expiration;
			$session['login']      = time();
			$sessions->update( $session_token, $session );
			if ( ! is_array( $sessions->get( $session_token ) ) ) {
				$errors[] = 'The short-lived administrator session could not be persisted.';
			}
			$cookie_schemes = array(
				'auth'        => defined( 'AUTH_COOKIE' ) ? AUTH_COOKIE : '',
				'secure_auth' => defined( 'SECURE_AUTH_COOKIE' ) ? SECURE_AUTH_COOKIE : '',
				'logged_in'   => defined( 'LOGGED_IN_COOKIE' ) ? LOGGED_IN_COOKIE : '',
			);
			foreach ( $cookie_schemes as $scheme => $internal_name ) {
				$cookie_value = wp_generate_auth_cookie( $user_id, $expiration, $scheme, $session_token );
				$cookie_name  = self::external_cookie_name( $internal_name, $external_url );
				if ( '' === $cookie_value || '' === $cookie_name ) {
					$errors[] = 'The short-lived administrator auth cookie set could not be created.';
					continue;
				}
				$auth_cookies[] = array(
					'scheme' => $scheme,
					'name'   => $cookie_name,
					'value'  => $cookie_value,
				);
			}
			if ( 3 !== count( $auth_cookies ) ) {
				$auth_cookies = array();
			}
		}

		return array(
			'success'      => empty( $errors ),
			'mode'         => 'create-auth-session',
			'user_id'      => $user_id,
			'expiration'   => $expiration,
			'auth_cookies' => $auth_cookies,
			'errors'       => $errors,
		);
	}

	/**
	 * Destroys only the exact caller-owned administrator session.
	 *
	 * @param int    $user_id       Exact administrator user ID.
	 * @param string $session_token Caller-owned session token.
	 * @return array<string,mixed>
	 */
	private static function destroy_auth_session( int $user_id, string $session_token ): array {
		$errors    = array();
		$destroyed = false;
		if ( $user_id <= 0 || 1 !== preg_match( '/^[a-f0-9]{64}$/', $session_token ) || ! class_exists( 'WP_Session_Tokens' ) ) {
			$errors[] = 'The exact caller-owned session binding is invalid.';
		} else {
			$sessions = WP_Session_Tokens::get_instance( $user_id );
			$sessions->destroy( $session_token );
			$destroyed = null === $sessions->get( $session_token );
			if ( ! $destroyed ) {
				$errors[] = 'The caller-owned administrator session still exists.';
			}
		}

		return array(
			'success'   => empty( $errors ) && $destroyed,
			'mode'      => 'destroy-auth-session',
			'user_id'   => $user_id,
			'destroyed' => $destroyed,
			'errors'    => $errors,
		);
	}

	/**
	 * Dispatches one internal REST request as the exact local administrator.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response
	 * @throws RuntimeException When the internal REST request cannot be dispatched.
	 */
	private static function dispatch_as_admin( WP_REST_Request $request ): WP_REST_Response {
		$previous_user = get_current_user_id();
		try {
			wp_set_current_user( 1 );
			$response = rest_do_request( $request );
			if ( is_wp_error( $response ) || ! $response instanceof WP_REST_Response ) {
				throw new RuntimeException( 'Internal REST dispatch failed.' );
			}
			return $response;
		} finally {
			wp_set_current_user( $previous_user );
		}
	}

	/**
	 * Creates a domain-separated HMAC without retaining source values.
	 *
	 * @param string $domain      Domain label.
	 * @param mixed  $value       Value to authenticate.
	 * @param string $context_key Hex-encoded run key.
	 * @return string
	 */
	private static function context_hmac( string $domain, $value, string $context_key ): string {
		return 'hmac-sha256:' . hash_hmac( 'sha256', $domain . "\0" . wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), hex2bin( $context_key ) );
	}

	/** Returns the runtime owner using the suite attribution rules. */
	private static function runtime_owner(): string {
		$active_plugins = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active_plugins = array_merge( $active_plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$owner = in_array( 'woocommerce-payments/woocommerce-payments.php', $active_plugins, true ) ? 'plugin' : 'none';
		if ( class_exists( NativePaymentsRuntimeArbiter::class ) && function_exists( 'wc_get_container' ) ) {
			try {
				$owner = (string) wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->get_runtime_owner();
			} catch ( Throwable $throwable ) {
				$owner = 'probe_failed';
			}
		}
		return $owner;
	}

	/**
	 * Maps an internal cookie name to the browser-visible local origin.
	 *
	 * @param string $internal_name Internal cookie name.
	 * @param string $external_url  Browser-visible local URL.
	 * @return string
	 */
	private static function external_cookie_name( string $internal_name, string $external_url ): string {
		if ( 1 !== preg_match( '/^(.+_)[0-9a-f]{32}$/i', $internal_name, $matches ) ) {
			return $internal_name;
		}
		return $matches[1] . md5( untrailingslashit( $external_url ) );
	}

	/**
	 * Tells whether URL parts identify an exact local HTTP origin.
	 *
	 * @param array<string,mixed>|false $parts Parsed URL parts.
	 * @return bool
	 */
	private static function is_local_url( $parts ): bool {
		if ( ! is_array( $parts ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) ) {
			return false;
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		return in_array( $host, array( 'localhost', '127.0.0.1' ), true ) || str_ends_with( $host, '.localhost' );
	}

	/**
	 * Emits one JSON object for the calling harness.
	 *
	 * @param array<string,mixed> $payload Payload.
	 */
	private static function emit( array $payload ): void {
		echo wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ) . PHP_EOL;
	}
}

WooPaymentsCriticalFlowsMd02Driver::run( $args ?? array() );
