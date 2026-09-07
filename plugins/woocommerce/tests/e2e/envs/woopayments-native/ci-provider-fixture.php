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

	private const STATE_OPTION       = 'e2e_woopayments_native_provider_state';
	private const REQUEST_LOG_OPTION = 'e2e_woopayments_native_request_log';
	private const FAILURE_LOG_OPTION = 'e2e_woopayments_native_failure_log';
	private const BLOG_ID            = 777;

	/**
	 * Registers the identity, transport, and Stripe adapter test seams.
	 */
	public function register(): void {
		add_filter( 'pre_option_jetpack_options', array( $this, 'jetpack_options' ) );
		add_filter( 'pre_option_jetpack_private_options', array( $this, 'jetpack_private_options' ) );
		add_filter( 'pre_http_request', array( $this, 'intercept' ), PHP_INT_MIN, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_stripe_adapter' ), 0 );
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
			'blog_token'  => 'dummy.blog-token.1',
			'user_tokens' => array( 1 => 'dummy.user-token.1' ),
		);
	}

	/**
	 * @param mixed               $preempt Existing preempted response.
	 * @param array<string,mixed> $args HTTP arguments.
	 * @param string              $url Request URL.
	 * @return array<string,mixed>|WP_Error
	 */
	public function intercept( $preempt, array $args, string $url ) {
		unset( $preempt );
		$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		$body   = (string) ( $args['body'] ?? '' );
		$parsed = wp_parse_url( $url );
		$path   = is_array( $parsed ) ? (string) ( $parsed['path'] ?? '' ) : '';
		$query  = array();
		if ( is_array( $parsed ) ) {
			parse_str( (string) ( $parsed['query'] ?? '' ), $query );
		}

		$this->record_request( $method, $path, $body );
		if ( ! in_array( $method, array( 'GET', 'POST' ), true ) ) {
			return $this->failure( 'unsupported_method', "Unsupported fixture method: $method $path" );
		}
		$prefix = '/rest/v1.1/sites/' . self::BLOG_ID . '/wcpay/';
		if ( 0 !== strpos( $path, $prefix ) ) {
			return $this->failure( 'escaped_request', "Secretless WooPayments CI blocked external request: $method $url" );
		}
		$route   = substr( $path, strlen( $prefix ) );
		$payload = $this->decode_body( $method, $body, $route );
		if ( $payload instanceof WP_Error ) {
			return $payload;
		}

		$state = $this->state();
		switch ( "$method $route" ) {
			case 'GET accounts':
				return $this->response( $state['account'] );
			case 'POST accounts':
				$state['settings'] = array_replace_recursive( $state['settings'], $payload );
				$state['account']  = array_replace_recursive( $state['account'], $payload );
				update_option( self::STATE_OPTION, $state );
				return $this->response( $state['account'] );
			case 'GET transactions':
				return $this->response( array( 'data' => $state['transactions'] ) );
			case 'GET transactions/summary':
				return $this->response( $this->summary( $state['transactions'] ) );
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
			case 'GET disputes/status_counts':
				return $this->response(
					array(
						'needs_response' => 0,
						'under_review'   => 0,
					)
				);
			case 'GET fraud_ruleset':
				return $this->response( array( 'ruleset' => array() ) );
			case 'POST fraud_ruleset':
				$state['fraud_ruleset'] = $payload;
				update_option( self::STATE_OPTION, $state );
				return $this->response( $payload );
		}

		return $this->failure( 'unknown_request', "Unrecognized WooPayments fixture request: $method $route" );
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
			'is_live'                    => false,
			'test_publishable_key'       => 'pk_test_native_ci',
			'live_publishable_key'       => '',
			'statement_descriptor'       => 'NATIVE CI',
			'business_name'              => 'Native CI store',
			'deposit_schedule'           => array(
				'interval'       => 'daily',
				'weekly_anchor'  => 'monday',
				'monthly_anchor' => 1,
			),
			'deposit_delay'              => 2,
			'deposit_status'             => 'enabled',
			'deposit_restrictions'       => array(),
			'platform_checkout_eligible' => true,
			'capabilities'               => array(
				'card_payments'   => 'active',
				'klarna_payments' => 'active',
			),
			'supported_payment_methods'  => array( 'card', 'klarna' ),
		);
		$state   = array(
			'account'       => $account,
			'settings'      => $account,
			'transactions'  => array(
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
			'deposits'      => array(
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
			),
			'disputes'      => array(),
			'overview'      => array(
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
			'fraud_ruleset' => array(),
		);
		update_option( self::STATE_OPTION, $state );
		return $state;
	}

	/**
	 * Filters fixture rows by the requested status.
	 *
	 * @param array<int,array<string,mixed>> $rows Fixture rows.
	 * @param array<string,mixed>            $query Request query.
	 * @return array<int,array<string,mixed>>
	 */
	private function filter_status( array $rows, array $query ): array {
		$status = isset( $query['status_is'] ) ? (string) $query['status_is'] : '';
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
	 * Decodes and validates a request body.
	 *
	 * @param string $method HTTP method.
	 * @param string $body Request body.
	 * @param string $route Fixture route.
	 * @return array<string,mixed>|WP_Error
	 */
	private function decode_body( string $method, string $body, string $route ) {
		if ( 'GET' === $method ) {
			return array();
		}
		$decoded           = json_decode( $body, true );
		$is_non_empty_list = is_array( $decoded ) && array() !== $decoded && array_keys( $decoded ) === range( 0, count( $decoded ) - 1 );
		if ( ! is_array( $decoded ) || $is_non_empty_list ) {
			return $this->failure( 'invalid_body', "Fixture requires a JSON object body for $method $route" );
		}
		return $decoded;
	}

	/**
	 * Records one intercepted request for the post-run audit.
	 *
	 * @param string $method HTTP method.
	 * @param string $path Request path.
	 * @param string $body Request body.
	 */
	private function record_request( string $method, string $path, string $body ): void {
		$log   = get_option( self::REQUEST_LOG_OPTION, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'method' => $method,
			'path'   => $path,
			'body'   => $body,
		);
		update_option( self::REQUEST_LOG_OPTION, $log );
	}

	/**
	 * Creates a WordPress HTTP API response.
	 *
	 * @param array<string,mixed> $body Response body.
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

if ( defined( 'E2E_WOOPAYMENTS_NATIVE_FIXTURE' ) && true === E2E_WOOPAYMENTS_NATIVE_FIXTURE ) {
	( new WooCommerce_WooPayments_Native_CI_Provider_Fixture() )->register();
}
