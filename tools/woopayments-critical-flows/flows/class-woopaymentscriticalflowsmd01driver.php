<?php
/**
 * Independent MD-01 dispute-created state and browser-session driver.
 *
 * @package WooCommerce\Tools\WooPaymentsCriticalFlows
 */

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;

/**
 * Project provider-backed dispute state without retaining customer PII.
 */
final class WooPaymentsCriticalFlowsMd01Driver {

	private const BASELINE_SCHEMA = 'woopayments_md01_baseline.v1';
	private const PROBE_SCHEMA    = 'woopayments_md01_probe.v1';
	private const MAX_LIST_PAGES  = 10;
	private const LIST_PAGE_SIZE  = 100;

	/**
	 * Run one state or exact-session action.
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

		if ( 'baseline' === $action ) {
			self::emit( self::baseline( $store, $run_stamp ) );
			return;
		}
		if ( 'probe' === $action ) {
			self::emit(
				self::probe(
					$store,
					$run_stamp,
					absint( $tool_args[3] ?? 0 ),
					(string) ( $tool_args[4] ?? '' ),
					(string) ( $tool_args[5] ?? '' )
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
	 * Capture aggregate state before creating the exact dispute.
	 *
	 * @param string $store     Store role.
	 * @param string $run_stamp Runner invocation stamp.
	 * @return array<string,mixed>
	 */
	private static function baseline( string $store, string $run_stamp ): array {
		$payload = array(
			'schema'        => self::BASELINE_SCHEMA,
			'store'         => $store,
			'run_stamp'     => $run_stamp,
			'runtime_owner' => self::runtime_owner(),
			'summary'       => self::empty_summary(),
			'status_counts' => self::empty_status_counts(),
			'blockers'      => array(),
		);

		try {
			$payload['summary'] = self::disputes_summary();
		} catch ( Throwable $throwable ) {
			$payload['blockers'][] = 'disputes_summary_unavailable';
		}
		try {
			$payload['status_counts'] = self::dispute_status_counts( $store );
		} catch ( Throwable $throwable ) {
			$payload['blockers'][] = 'dispute_status_counts_unavailable';
		}

		return $payload;
	}

	/**
	 * Capture the exact driven order and dispute end state.
	 *
	 * @param string $store              Store role.
	 * @param string $run_stamp          Runner invocation stamp.
	 * @param int    $order_id           Exact driven order ID.
	 * @param string $expected_charge_id Exact driven charge ID.
	 * @param string $expected_intent_id Exact driven intent ID.
	 * @return array<string,mixed>
	 */
	private static function probe( string $store, string $run_stamp, int $order_id, string $expected_charge_id, string $expected_intent_id ): array {
		$payload = array(
			'schema'        => self::PROBE_SCHEMA,
			'store'         => $store,
			'run_stamp'     => $run_stamp,
			'runtime_owner' => self::runtime_owner(),
			'order'         => self::empty_order( $order_id ),
			'dispute'       => self::empty_dispute(),
			'summary'       => self::empty_summary(),
			'status_counts' => self::empty_status_counts(),
			'blockers'      => array(),
		);

		if ( $order_id <= 0 || 1 !== preg_match( '/^(?:ch|py)_[A-Za-z0-9_]+$/', $expected_charge_id ) || 1 !== preg_match( '/^pi_[A-Za-z0-9_]+$/', $expected_intent_id ) ) {
			$payload['blockers'][] = 'invalid_driven_identity';
			return $payload;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return $payload;
		}

		$payload['order'] = self::project_order( $order );
		try {
			$payload['dispute'] = self::find_dispute( $expected_charge_id );
		} catch ( Throwable $throwable ) {
			$payload['blockers'][] = 'disputes_api_unavailable';
		}
		try {
			$payload['summary'] = self::disputes_summary();
		} catch ( Throwable $throwable ) {
			$payload['blockers'][] = 'disputes_summary_unavailable';
		}
		try {
			$payload['status_counts'] = self::dispute_status_counts( $store );
		} catch ( Throwable $throwable ) {
			$payload['blockers'][] = 'dispute_status_counts_unavailable';
		}

		return $payload;
	}

	/**
	 * Project the exact WooCommerce order and dispute-created notes.
	 *
	 * @param WC_Order $order Order object.
	 * @return array<string,mixed>
	 */
	private static function project_order( WC_Order $order ): array {
		$created_note_count = 0;
		$created_dispute    = false;
		$reason_context     = false;
		$response_context   = false;
		$on_hold_transition = 'on-hold' === $order->get_status();
		$safe_excerpt       = '';

		foreach (
			wc_get_order_notes(
				array(
					'order_id' => $order->get_id(),
					'limit'    => 50,
					'orderby'  => 'date_created',
					'order'    => 'DESC',
				)
			) as $note
		) {
			$content       = isset( $note->content ) ? trim( (string) $note->content ) : '';
			$plain_content = trim( preg_replace( '/\s+/', ' ', wp_specialchars_decode( wp_strip_all_tags( $content ), ENT_QUOTES ) ) ?? '' );
			$content_lower = strtolower( $plain_content );
			$is_created    = str_contains( $content_lower, 'payment has been disputed for' );
			if ( $is_created ) {
				++$created_note_count;
				$created_dispute  = true;
				$reason_context   = $reason_context || str_contains( $content_lower, 'with reason "' );
				$response_context = $response_context || str_contains( $content_lower, 'response due by ' );
				if ( '' === $safe_excerpt ) {
					$safe_excerpt = mb_substr( $plain_content, 0, 512 );
				}
			}
			if ( str_contains( $content_lower, 'order status changed from processing to on hold' ) || str_contains( $content_lower, 'order status changed from processing to on-hold' ) ) {
				$on_hold_transition = true;
			}
		}

		$edit_url  = (string) $order->get_edit_order_url();
		$edit_path = self::project_local_admin_path( $edit_url );
		if ( '' === $edit_path ) {
			$edit_path = '/wp-admin/admin.php?page=wc-orders&action=edit&id=' . $order->get_id();
		}
		$decimals    = max( 0, (int) wc_get_price_decimals() );
		$total_minor = (int) round( (float) $order->get_total() * ( 10 ** $decimals ) );

		return array(
			'id'          => (int) $order->get_id(),
			'exists'      => true,
			'status'      => (string) $order->get_status(),
			'currency'    => strtoupper( (string) $order->get_currency() ),
			'total_minor' => $total_minor,
			'charge_id'   => (string) $order->get_meta( '_charge_id', true ),
			'intent_id'   => (string) $order->get_meta( '_intent_id', true ),
			'edit_path'   => $edit_path,
			'notes'       => array(
				'created_dispute'      => $created_dispute,
				'reason_context'       => $reason_context,
				'response_due_context' => $response_context,
				'on_hold_transition'   => $on_hold_transition,
				'created_note_count'   => $created_note_count,
				'safe_excerpt'         => $safe_excerpt,
			),
		);
	}

	/**
	 * Find exactly one dispute by direct charge-ID comparison across bounded pages.
	 *
	 * @param string $charge_id Exact provider charge ID.
	 * @return array<string,mixed>
	 * @throws RuntimeException When the disputes list response is unavailable.
	 */
	private static function find_dispute( string $charge_id ): array {
		$matches       = array();
		$pages_scanned = 0;
		for ( $page = 1; $page <= self::MAX_LIST_PAGES; ++$page ) {
			/**
			 * Typed disputes-list request.
			 *
			 * @var WP_REST_Request<array<string, mixed>> $request
			 */
			$request = new WP_REST_Request( 'GET', '/wc/v3/payments/disputes' );
			$request->set_query_params(
				array(
					'page'      => $page,
					'pagesize'  => self::LIST_PAGE_SIZE,
					'sort'      => 'created',
					'direction' => 'desc',
				)
			);
			$response = self::dispatch_as_admin( $request );
			$data     = $response->get_data();
			$rows     = is_array( $data ) && is_array( $data['data'] ?? null ) ? $data['data'] : null;
			if ( 200 !== $response->get_status() || ! is_array( $rows ) ) {
				throw new RuntimeException( 'Disputes list response is unavailable.' );
			}
			$pages_scanned = $page;
			foreach ( $rows as $row ) {
				if ( is_array( $row ) && (string) ( $row['charge_id'] ?? '' ) === $charge_id ) {
					$matches[] = $row;
				}
			}
			if ( count( $rows ) < self::LIST_PAGE_SIZE ) {
				break;
			}
		}

		if ( 1 !== count( $matches ) ) {
			$empty                  = self::empty_dispute();
			$empty['match_count']   = min( 20, count( $matches ) );
			$empty['pages_scanned'] = max( 1, $pages_scanned );
			return $empty;
		}

		$row = $matches[0];
		return array(
			'found'         => true,
			'match_count'   => 1,
			'pages_scanned' => max( 1, $pages_scanned ),
			'id'            => (string) ( $row['dispute_id'] ?? '' ),
			'charge_id'     => (string) ( $row['charge_id'] ?? '' ),
			'status'        => (string) ( $row['status'] ?? '' ),
			'amount'        => (int) ( $row['amount'] ?? 0 ),
			'currency'      => strtolower( (string) ( $row['currency'] ?? '' ) ),
			'reason'        => (string) ( $row['reason'] ?? '' ),
			'due_by'        => (string) ( $row['due_by'] ?? '' ),
			'order_number'  => absint( $row['order_number'] ?? 0 ),
		);
	}

	/**
	 * Read the actionable dispute summary through the preserved REST contract.
	 *
	 * @return array<string,mixed>
	 * @throws RuntimeException When the summary response is unavailable.
	 */
	private static function disputes_summary(): array {
		/**
		 * Typed disputes-summary request.
		 *
		 * @var WP_REST_Request<array<string, mixed>> $request
		 */
		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/disputes/summary' );
		$request->set_query_params( array( 'status_is' => 'needs_response' ) );
		$response = self::dispatch_as_admin( $request );
		$data     = $response->get_data();
		if ( 200 !== $response->get_status() || ! is_array( $data ) || ! is_int( $data['count'] ?? null ) || ! is_array( $data['currencies'] ?? null ) ) {
			throw new RuntimeException( 'Disputes summary response is unavailable.' );
		}
		$currencies = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $currency ): string {
							return strtolower( (string) $currency );
						},
						$data['currencies']
					)
				)
			)
		);

		return array(
			'http_status' => (int) $response->get_status(),
			'count'       => max( 0, (int) $data['count'] ),
			'currencies'  => $currencies,
		);
	}

	/**
	 * Read raw provider status counts using each runtime's supported client.
	 *
	 * @param string $store Store role.
	 * @return array<string,int>
	 * @throws RuntimeException When the provider status-count response is unavailable.
	 */
	private static function dispute_status_counts( string $store ): array {
		if ( 'target' === $store ) {
			$counts = wc_get_container()->get( WooPaymentsApiClient::class )->get_dispute_status_counts();
		} else {
			if ( ! class_exists( '\\WCPay\\Core\\Server\\Request' ) ) {
				throw new RuntimeException( 'Reference status-count request is unavailable.' );
			}
			$request = \WCPay\Core\Server\Request::get( 'disputes/status_counts' );
			$request->assign_hook( 'wcpay_get_dispute_status_counts' );
			$counts = $request->send();
		}
		if ( ! is_array( $counts ) ) {
			throw new RuntimeException( 'Dispute status-count response is unavailable.' );
		}
		$needs_response         = max( 0, (int) ( $counts['needs_response'] ?? 0 ) );
		$warning_needs_response = max( 0, (int) ( $counts['warning_needs_response'] ?? 0 ) );

		return array(
			'needs_response'         => $needs_response,
			'warning_needs_response' => $warning_needs_response,
			'awaiting_response'      => $needs_response + $warning_needs_response,
		);
	}

	/**
	 * Dispatch an authenticated internal REST request and restore the caller.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 * @throws RuntimeException When the internal REST request fails.
	 */
	private static function dispatch_as_admin( WP_REST_Request $request ): WP_REST_Response {
		$previous_user = get_current_user_id();
		try {
			wp_set_current_user( 1 );
			$response = rest_do_request( $request );
			if ( is_wp_error( $response ) || ! $response instanceof WP_REST_Response ) {
				throw new RuntimeException( 'REST dispatch failed.' );
			}
			return $response;
		} finally {
			wp_set_current_user( $previous_user );
		}
	}

	/**
	 * Create one caller-owned short-lived administrator session.
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
	 * Destroy only the exact caller-owned administrator session.
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
	 * Map the internal WordPress logged-in cookie name to the browser origin.
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
	 * Tell whether parsed URL parts represent an exact local HTTP origin.
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
	 * Keep only a same-site wp-admin path and query.
	 *
	 * @param string $url Absolute admin URL.
	 * @return string
	 */
	private static function project_local_admin_path( string $url ): string {
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( ! str_starts_with( $path, '/wp-admin/' ) ) {
			return '';
		}
		return $path . ( '' !== $query ? '?' . $query : '' );
	}

	/** Return the runtime owner using the suite's attribution rules. */
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
	 * Return an empty disputes summary.
	 *
	 * @return array<string,mixed>
	 */
	private static function empty_summary(): array {
		return array(
			'http_status' => 0,
			'count'       => 0,
			'currencies'  => array(),
		);
	}

	/**
	 * Return empty dispute status counts.
	 *
	 * @return array<string,int>
	 */
	private static function empty_status_counts(): array {
		return array(
			'needs_response'         => 0,
			'warning_needs_response' => 0,
			'awaiting_response'      => 0,
		);
	}

	/**
	 * Return an empty exact-order projection.
	 *
	 * @param int $order_id Requested order ID.
	 * @return array<string,mixed>
	 */
	private static function empty_order( int $order_id ): array {
		return array(
			'id'          => $order_id,
			'exists'      => false,
			'status'      => '',
			'currency'    => 'USD',
			'total_minor' => 0,
			'charge_id'   => '',
			'intent_id'   => '',
			'edit_path'   => '/wp-admin/admin.php?page=wc-orders&action=edit&id=' . $order_id,
			'notes'       => array(
				'created_dispute'      => false,
				'reason_context'       => false,
				'response_due_context' => false,
				'on_hold_transition'   => false,
				'created_note_count'   => 0,
				'safe_excerpt'         => '',
			),
		);
	}

	/**
	 * Return an empty exact-dispute projection.
	 *
	 * @return array<string,mixed>
	 */
	private static function empty_dispute(): array {
		return array(
			'found'         => false,
			'match_count'   => 0,
			'pages_scanned' => 1,
			'id'            => '',
			'charge_id'     => '',
			'status'        => '',
			'amount'        => 0,
			'currency'      => '',
			'reason'        => '',
			'due_by'        => '',
			'order_number'  => 0,
		);
	}

	/**
	 * Emit one JSON object to WP-CLI.
	 *
	 * @param array<string,mixed> $payload Payload to emit.
	 */
	private static function emit( array $payload ): void {
		// @phpstan-ignore class.notFound (WP-CLI loads this runtime-only global class.)
		WP_CLI::line( wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ) );
	}
}

$tool_args = isset( $args ) && is_array( $args ) ? $args : array_slice( $argv ?? array(), 1 );
WooPaymentsCriticalFlowsMd01Driver::run( $tool_args );
