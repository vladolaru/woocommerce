<?php
/**
 * Deterministic SP-01 saved-card state driver.
 *
 * @package WooCommerce\Tools\WooPaymentsCriticalFlows
 */

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use WCPay\Core\Server\Request\Create_And_Confirm_Setup_Intention;

/**
 * File-local driver for the SP-01 deterministic state exercise.
 */
final class WooPaymentsCriticalFlowsSp01Driver {

	/**
	 * Run the isolated fixture and emit its result.
	 *
	 * @internal
	 *
	 * @param string $store_role Store role.
	 */
	public static function run( string $store_role ): void {
		$payload = self::empty_payload( $store_role );

		self::load_plugin_helpers();

		if ( ! in_array( $store_role, array( 'ref', 'target' ), true ) ) {
			$payload['blockers'][] = 'The store role must be ref or target.';
		}
		if ( ! function_exists( 'WC' ) || ! class_exists( 'WC_Payment_Tokens' ) ) {
			$payload['blockers'][] = 'WooCommerce payment token APIs are unavailable.';
		}

		$plugin_active                       = function_exists( 'is_plugin_active' ) && is_plugin_active( 'woocommerce-payments/woocommerce-payments.php' );
		$runtime_owner                       = $plugin_active ? 'plugin' : 'native';
		$expected_owner                      = 'ref' === $store_role ? 'plugin' : 'native';
		$payload['fixture']['runtime_owner'] = $runtime_owner;

		if ( '' !== $store_role && $expected_owner !== $runtime_owner ) {
			$payload['blockers'][] = sprintf(
				'Expected %1$s runtime ownership for %2$s, observed %3$s.',
				$expected_owner,
				$store_role,
				$runtime_owner
			);
		}

		foreach ( self::missing_runtime_services( $runtime_owner ) as $missing_service ) {
			$payload['blockers'][] = $missing_service;
		}

		$secret_key = '';
		$account_id = '';
		try {
			$secret_key = self::get_stripe_test_secret_key();
			$account_id = self::get_connected_account_id();
		} catch ( Throwable $throwable ) {
			$payload['blockers'][] = $throwable->getMessage();
		}

		if ( empty( $payload['blockers'] ) ) {
			try {
				$payload['observed']['latest_charge_before'] = self::get_latest_charge_id( $secret_key, $account_id );
			} catch ( Throwable $throwable ) {
				$payload['blockers'][] = 'Could not establish the provider charge baseline: ' . $throwable->getMessage();
			}
		}

		$user = null;
		if ( empty( $payload['blockers'] ) ) {
			try {
				$user = self::create_customer_user( $store_role );
				wp_set_current_user( $user->ID );
				$payload['fixture']['user_id']        = (int) $user->ID;
				$payload['observed']['orders_before'] = self::get_customer_order_ids( (int) $user->ID );
				$tokens_before                        = WC_Payment_Tokens::get_customer_tokens( (int) $user->ID );
				if ( ! empty( $tokens_before ) || ! empty( $payload['observed']['orders_before'] ) ) {
					$payload['blockers'][] = 'Fresh SP-01 customer is not isolated from saved tokens and orders.';
				}
			} catch ( Throwable $throwable ) {
				$payload['blockers'][] = 'Could not create the isolated SP-01 customer fixture: ' . $throwable->getMessage();
			}
		}

		$created_payment_method_id = '';
		if ( empty( $payload['blockers'] ) && $user instanceof WP_User ) {
			try {
				$created_payment_method_id               = self::create_card_payment_method( $secret_key, $account_id, $user );
				$payload['fixture']['payment_method_id'] = $created_payment_method_id;
			} catch ( Throwable $throwable ) {
				$payload['blockers'][] = 'Could not provision the provider card fixture: ' . $throwable->getMessage();
			}
		}

		$runtime_result = array(
			'customer_id'       => '',
			'payment_method_id' => '',
			'setup_intent_id'   => '',
			'status'            => '',
		);
		if ( empty( $payload['blockers'] ) && $user instanceof WP_User ) {
			try {
				$runtime_result = 'plugin' === $runtime_owner
					? self::run_plugin_setup( $user, $created_payment_method_id )
					: self::run_native_setup( $user, $created_payment_method_id );
			} catch ( Throwable $throwable ) {
				$message = 'Active WooPayments SetupIntent/token flow threw ' . get_class( $throwable ) . ': ' . $throwable->getMessage();
				if ( self::is_infrastructure_failure( $throwable ) ) {
					$payload['blockers'][] = $message;
				} else {
					$payload['errors'][] = $message;
				}
			}
		}

		$payload['fixture']['customer_id']          = (string) $runtime_result['customer_id'];
		$payload['fixture']['payment_method_id']    = (string) $runtime_result['payment_method_id'];
		$payload['fixture']['setup_intent_id']      = (string) $runtime_result['setup_intent_id'];
		$payload['observed']['setup_intent_status'] = (string) $runtime_result['status'];
		$payload['observed']['mapped_customer_id']  = $user instanceof WP_User
		? self::get_mapped_customer_id( (int) $user->ID )
		: '';
		$provider_payment_method                    = array();
		$provider_setup_intent                      = array();

		if ( empty( $payload['blockers'] ) && empty( $payload['errors'] ) ) {
			try {
				$provider_payment_method = self::stripe_request(
					'GET',
					'/v1/payment_methods/' . rawurlencode( (string) $runtime_result['payment_method_id'] ),
					$secret_key,
					$account_id
				);
				$provider_setup_intent   = self::stripe_request(
					'GET',
					'/v1/setup_intents/' . rawurlencode( (string) $runtime_result['setup_intent_id'] ),
					$secret_key,
					$account_id
				);
			} catch ( Throwable $throwable ) {
				$payload['blockers'][] = 'Could not observe the provider end-state: ' . $throwable->getMessage();
			}
		}

		if ( $user instanceof WP_User ) {
			$tokens                             = self::get_woopayments_tokens( (int) $user->ID );
			$payload['observed']['token_count'] = count( $tokens );
			$payload['checks']['exactly_one_woopayments_token'] = 1 === count( $tokens );
			if ( 1 === count( $tokens ) ) {
				$token                                    = $tokens[0];
				$payload['fixture']['token_id']           = (int) $token->get_id();
				$payload['observed']['token_provider_id'] = (string) $token->get_token();
				$payload['observed']['token_card_type']   = $token instanceof WC_Payment_Token_CC ? strtolower( (string) $token->get_card_type() ) : '';
				$payload['observed']['token_last4']       = $token instanceof WC_Payment_Token_CC ? (string) $token->get_last4() : '';
				$payload['checks']['visa_4242_token']     = $token instanceof WC_Payment_Token_CC
					&& 'visa' === $payload['observed']['token_card_type']
					&& '4242' === $payload['observed']['token_last4'];
				$payload['checks']['provider_payment_method_id'] = 1 === preg_match( '/^pm_[A-Za-z0-9]{8,}$/', $payload['observed']['token_provider_id'] )
					&& $payload['observed']['token_provider_id'] === (string) $runtime_result['payment_method_id']
					&& $payload['observed']['token_provider_id'] === $created_payment_method_id;
			}

			$payload['observed']['orders_after']    = self::get_customer_order_ids( (int) $user->ID );
			$payload['checks']['no_orders_created'] = empty( $payload['observed']['orders_before'] ) && empty( $payload['observed']['orders_after'] );
		}

		if ( ! empty( $provider_payment_method ) && ! empty( $provider_setup_intent ) ) {
			$provider_customer_id                          = self::scalar_field( $provider_payment_method, 'customer' );
			$provider_intent_customer_id                   = self::scalar_field( $provider_setup_intent, 'customer' );
			$provider_intent_payment_id                    = self::scalar_field( $provider_setup_intent, 'payment_method' );
			$provider_intent_status                        = self::scalar_field( $provider_setup_intent, 'status' );
			$payload['observed']['provider_customer_id']   = $provider_customer_id;
			$payload['observed']['provider_intent_status'] = $provider_intent_status;
			$payload['checks']['customer_binding']         = '' !== (string) $runtime_result['customer_id']
				&& (string) $runtime_result['customer_id'] === $payload['observed']['mapped_customer_id']
				&& (string) $runtime_result['customer_id'] === $provider_customer_id
				&& (string) $runtime_result['customer_id'] === $provider_intent_customer_id
				&& (string) $runtime_result['payment_method_id'] === $provider_intent_payment_id;
			$payload['checks']['setup_intent_succeeded']   = 'succeeded' === (string) $runtime_result['status']
				&& 'succeeded' === $provider_intent_status;
		}

		if ( '' !== $secret_key && '' !== $account_id ) {
			try {
				$payload['observed']['latest_charge_after'] = self::get_latest_charge_id( $secret_key, $account_id );
				$payload['checks']['no_charge_created']     = $payload['observed']['latest_charge_before'] === $payload['observed']['latest_charge_after'];
			} catch ( Throwable $throwable ) {
				$payload['blockers'][] = 'Could not observe the provider charge end-state: ' . $throwable->getMessage();
			}
		}

		foreach ( $payload['checks'] as $check => $passed ) {
			if ( ! $passed ) {
				$payload['errors'][] = 'SP-01 state check failed: ' . $check;
			}
		}

		$payload['status'] = ! empty( $payload['blockers'] ) ? 'blocked' : ( empty( $payload['errors'] ) ? 'pass' : 'fail' );
		self::emit( $payload );
	}

	/**
	 * Create the initial result payload.
	 *
	 * @param string $store_role Store role.
	 * @return array<string,mixed>
	 */
	private static function empty_payload( string $store_role ): array {
		return array(
			'schema'   => 'woopayments_sp01_deterministic.v1',
			'status'   => 'blocked',
			'checks'   => array(
				'exactly_one_woopayments_token' => false,
				'visa_4242_token'               => false,
				'provider_payment_method_id'    => false,
				'customer_binding'              => false,
				'setup_intent_succeeded'        => false,
				'no_orders_created'             => false,
				'no_charge_created'             => false,
			),
			'fixture'  => array(
				'role'              => $store_role,
				'runtime_owner'     => '',
				'user_id'           => 0,
				'token_id'          => 0,
				'customer_id'       => '',
				'payment_method_id' => '',
				'setup_intent_id'   => '',
			),
			'observed' => array(
				'orders_before'          => array(),
				'orders_after'           => array(),
				'latest_charge_before'   => '',
				'latest_charge_after'    => '',
				'token_count'            => 0,
				'token_provider_id'      => '',
				'token_card_type'        => '',
				'token_last4'            => '',
				'setup_intent_status'    => '',
				'provider_intent_status' => '',
				'provider_customer_id'   => '',
				'mapped_customer_id'     => '',
			),
			'blockers' => array(),
			'errors'   => array(),
		);
	}

	/** Load plugin helpers for runtime ownership checks. */
	private static function load_plugin_helpers(): void {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * Describe missing services for the selected runtime.
	 *
	 * @param string $runtime_owner Runtime owner.
	 * @return array<int,string>
	 */
	private static function missing_runtime_services( string $runtime_owner ): array {
		$missing = array();
		if ( 'plugin' === $runtime_owner ) {
			if ( ! class_exists( 'WC_Payments' ) || ! method_exists( 'WC_Payments', 'get_customer_service' ) || ! method_exists( 'WC_Payments', 'get_token_service' ) ) {
				$missing[] = 'WooPayments plugin customer and token services are unavailable.';
			} else {
				try {
					$customer_service = WC_Payments::get_customer_service();
					$token_service    = WC_Payments::get_token_service();
					if ( ! is_object( $customer_service ) || ! method_exists( $customer_service, 'create_customer_for_user' ) ) {
						$missing[] = 'WooPayments plugin customer service cannot create customer mappings.';
					}
					if ( ! is_object( $token_service ) || ! method_exists( $token_service, 'add_payment_method_to_user' ) ) {
						$missing[] = 'WooPayments plugin token service cannot persist payment methods.';
					}
				} catch ( Throwable $throwable ) {
					$missing[] = 'WooPayments plugin services could not be resolved: ' . $throwable->getMessage();
				}
			}
			if ( ! class_exists( Create_And_Confirm_Setup_Intention::class ) ) {
				$missing[] = 'WooPayments plugin SetupIntent transport is unavailable.';
			}
			return $missing;
		}

		$classes = array(
			WooPaymentsCustomerService::class,
			WooPaymentsApiClient::class,
			WooPaymentsTokenService::class,
		);
		if ( ! function_exists( 'wc_get_container' ) ) {
			return array( 'WooCommerce dependency container is unavailable for native WooPayments.' );
		}
		foreach ( $classes as $class_name ) {
			if ( ! class_exists( $class_name ) ) {
				$missing[] = 'Native WooPayments service is unavailable: ' . $class_name;
				continue;
			}
			try {
				$service = wc_get_container()->get( $class_name );
				if ( ! $service instanceof $class_name ) {
					$missing[] = 'Native WooPayments service resolved to the wrong type: ' . $class_name;
				}
			} catch ( Throwable $throwable ) {
				$missing[] = 'Native WooPayments service could not be resolved: ' . $class_name . ': ' . $throwable->getMessage();
			}
		}
		return $missing;
	}

	/**
	 * Determine whether a runtime exception proves missing local infrastructure.
	 *
	 * Provider HTTP responses remain product results; only connection-level
	 * failures are blockers after service resolution has passed.
	 *
	 * @param Throwable $throwable Runtime exception.
	 * @return bool
	 */
	private static function is_infrastructure_failure( Throwable $throwable ): bool {
		$message = strtolower( $throwable->getMessage() );
		$signals = array(
			'could not resolve host',
			'connection refused',
			'failed to connect',
			'name or service not known',
			'no route to host',
			'operation timed out',
			'request timed out',
			'curl error',
		);
		foreach ( $signals as $signal ) {
			if ( false !== strpos( $message, $signal ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve the local Stripe test key without exposing it.
	 *
	 * @return string
	 * @throws RuntimeException When no test key is configured.
	 */
	private static function get_stripe_test_secret_key(): string {
		$secret_key = getenv( 'STRIPE_TEST_SECRET_KEY' );
		if ( ! is_string( $secret_key ) || '' === $secret_key ) {
			$secret_key = get_option( 'wcpay_test_lab_stripe_key', '' );
		}
		if ( ! is_string( $secret_key ) || 0 !== strpos( $secret_key, 'sk_test_' ) ) {
			throw new RuntimeException( 'Stripe test secret key is not configured for the local Test Lab.' );
		}
		return $secret_key;
	}

	/**
	 * Resolve the connected account from the local cache.
	 *
	 * @return string
	 * @throws RuntimeException When the account is absent.
	 */
	private static function get_connected_account_id(): string {
		$cache      = get_option( 'wcpay_account_data', array() );
		$account_id = is_array( $cache ) && isset( $cache['data']['account_id'] ) && is_scalar( $cache['data']['account_id'] ) ? (string) $cache['data']['account_id'] : '';
		if ( 1 !== preg_match( '/^acct_[A-Za-z0-9]{8,}$/', $account_id ) ) {
			throw new RuntimeException( 'Connected WooPayments test account is missing from wcpay_account_data.' );
		}
		return $account_id;
	}

	/**
	 * Issue one local fixture/observation Stripe request.
	 *
	 * @param string              $method     HTTP method.
	 * @param string              $path       API path.
	 * @param string              $secret_key Test key.
	 * @param string              $account_id Connected account.
	 * @param array<string,mixed> $body       Form body.
	 * @return array<string,mixed>
	 * @throws RuntimeException When the request fails.
	 */
	private static function stripe_request( string $method, string $path, string $secret_key, string $account_id, array $body = array() ): array {
		$response = wp_remote_request(
			'https://api.stripe.com' . $path,
			array(
				'method'  => $method,
				'timeout' => 30,
				'headers' => array(
					'Authorization'  => 'Bearer ' . $secret_key,
					'Stripe-Account' => $account_id,
				),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'Stripe request failed: ' . $response->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal CLI diagnostic.
		}
		$code     = (int) wp_remote_retrieve_response_code( $response );
		$raw_body = (string) wp_remote_retrieve_body( $response );
		$data     = json_decode( $raw_body, true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$safe_body = substr( (string) preg_replace( '/\s+/', ' ', $raw_body ), 0, 300 );
			throw new RuntimeException( sprintf( 'Stripe request returned HTTP %1$d: %2$s', $code, $safe_body ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal CLI diagnostic.
		}
		return $data;
	}

	/**
	 * Return the newest connected-account charge ID.
	 *
	 * @param string $secret_key Stripe test key.
	 * @param string $account_id Connected account ID.
	 * @return string
	 */
	private static function get_latest_charge_id( string $secret_key, string $account_id ): string {
		$response = self::stripe_request( 'GET', '/v1/charges?limit=1', $secret_key, $account_id );
		return isset( $response['data'][0]['id'] ) && is_scalar( $response['data'][0]['id'] ) ? (string) $response['data'][0]['id'] : '';
	}

	/**
	 * Create an isolated customer fixture.
	 *
	 * @param string $store_role Store role.
	 * @return WP_User
	 * @throws RuntimeException When creation fails.
	 */
	private static function create_customer_user( string $store_role ): WP_User {
		$suffix  = strtolower( wp_generate_password( 12, false, false ) );
		$login   = 'wcpay_sp01_' . $store_role . '_' . $suffix;
		$email   = $login . '@example.test';
		$user_id = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 32, true, true ),
				'user_email' => $email,
				'first_name' => 'SP01',
				'last_name'  => 'Customer',
				'role'       => 'customer',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			throw new RuntimeException( 'WordPress user insertion failed: ' . $user_id->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal CLI diagnostic.
		}
		$billing = array(
			'billing_first_name' => 'SP01',
			'billing_last_name'  => 'Customer',
			'billing_email'      => $email,
			'billing_country'    => 'US',
			'billing_address_1'  => '60 29th Street',
			'billing_city'       => 'San Francisco',
			'billing_state'      => 'CA',
			'billing_postcode'   => '94110',
		);
		foreach ( $billing as $meta_key => $value ) {
			update_user_meta( (int) $user_id, $meta_key, $value );
		}
		$user = get_user_by( 'id', (int) $user_id );
		if ( ! $user instanceof WP_User ) {
			throw new RuntimeException( 'Created WordPress customer could not be loaded.' );
		}
		return $user;
	}

	/**
	 * Return at most one order ID for the isolated customer.
	 *
	 * @param int $user_id Customer user ID.
	 * @return array<int,int>
	 */
	private static function get_customer_order_ids( int $user_id ): array {
		$order_ids = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => 1,
				'return'      => 'ids',
			)
		);
		return array_map( 'absint', is_array( $order_ids ) ? $order_ids : array() );
	}

	/**
	 * Return saved WooPayments tokens for the customer.
	 *
	 * @param int $user_id Customer user ID.
	 * @return array<int,WC_Payment_Token>
	 */
	private static function get_woopayments_tokens( int $user_id ): array {
		return array_values(
			array_filter(
				WC_Payment_Tokens::get_customer_tokens( $user_id ),
				static function ( $token ): bool {
					return $token instanceof WC_Payment_Token && 'woocommerce_payments' === (string) $token->get_gateway_id();
				}
			)
		);
	}

	/**
	 * Return a scalar response field as a string.
	 *
	 * @param array<string,mixed> $data Response data.
	 * @param string              $key  Response key.
	 * @return string
	 */
	private static function scalar_field( array $data, string $key ): string {
		return isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? (string) $data[ $key ] : '';
	}

	/**
	 * Read the WooPayments customer mapping persisted for a user.
	 *
	 * @param int $user_id Customer user ID.
	 * @return string
	 */
	private static function get_mapped_customer_id( int $user_id ): string {
		$mapping_keys = array( '_wcpay_customer_id_test', '_wcpay_customer_id_live', '_wcpay_customer_id' );
		foreach ( $mapping_keys as $mapping_key ) {
			$customer_id = (string) get_user_option( $mapping_key, $user_id );
			if ( '' === $customer_id ) {
				$customer_id = (string) get_user_meta( $user_id, $mapping_key, true );
			}
			if ( '' !== $customer_id ) {
				return $customer_id;
			}
		}
		return '';
	}

	/**
	 * Create the connected-account Visa fixture.
	 *
	 * @param string  $secret_key Stripe test key.
	 * @param string  $account_id Connected account ID.
	 * @param WP_User $user       Fixture customer.
	 * @return string
	 * @throws RuntimeException When no PaymentMethod is returned.
	 */
	private static function create_card_payment_method( string $secret_key, string $account_id, WP_User $user ): string {
		$response          = self::stripe_request(
			'POST',
			'/v1/payment_methods',
			$secret_key,
			$account_id,
			array(
				'type'                   => 'card',
				'card[token]'            => 'tok_visa',
				'billing_details[name]'  => 'SP01 Customer',
				'billing_details[email]' => $user->user_email,
			)
		);
		$payment_method_id = self::scalar_field( $response, 'id' );
		if ( 1 !== preg_match( '/^pm_[A-Za-z0-9]{8,}$/', $payment_method_id ) ) {
			throw new RuntimeException( 'Stripe PaymentMethod creation returned no provider pm_ ID.' );
		}
		return $payment_method_id;
	}

	/**
	 * Attach and save the card through the standalone plugin runtime.
	 *
	 * @param WP_User $user              Fixture customer.
	 * @param string  $payment_method_id Provider PaymentMethod ID.
	 * @return array<string,string>
	 * @throws RuntimeException When setup or persistence is invalid.
	 */
	private static function run_plugin_setup( WP_User $user, string $payment_method_id ): array {
		$customer_service = WC_Payments::get_customer_service();
		$customer_id      = (string) $customer_service->get_customer_id_by_user_id( (int) $user->ID );
		if ( '' === $customer_id ) {
			$customer_data = WC_Payments_Customer_Service::map_customer_data( null, new WC_Customer( (int) $user->ID ) );
			$customer_id   = (string) $customer_service->create_customer_for_user( $user, $customer_data );
		}
		if ( 1 !== preg_match( '/^cus_[A-Za-z0-9]{8,}$/', $customer_id ) ) {
			throw new RuntimeException( 'WooPayments plugin did not map the user to a provider customer.' );
		}
		$request = Create_And_Confirm_Setup_Intention::create();
		$request->set_customer( $customer_id );
		$request->set_payment_method( $payment_method_id );
		$request->set_payment_method_types( array( 'card' ) );
		$request->assign_hook( 'woopayments_critical_flows_sp01_setup_intent_request' );
		$setup_intent = $request->send();
		if ( ! is_object( $setup_intent ) ) {
			throw new RuntimeException( 'WooPayments plugin SetupIntent transport returned no object.' );
		}
		$resolved_id = method_exists( $setup_intent, 'get_payment_method_id' ) ? (string) $setup_intent->get_payment_method_id() : '';
		$resolved_id = '' !== $resolved_id ? $resolved_id : $payment_method_id;
		$token       = WC_Payments::get_token_service()->add_payment_method_to_user( $resolved_id, $user );
		if ( ! $token instanceof WC_Payment_Token ) {
			throw new RuntimeException( 'WooPayments plugin token service returned no saved token.' );
		}
		return array(
			'customer_id'       => $customer_id,
			'payment_method_id' => $resolved_id,
			'setup_intent_id'   => method_exists( $setup_intent, 'get_id' ) ? (string) $setup_intent->get_id() : '',
			'status'            => method_exists( $setup_intent, 'get_status' ) ? (string) $setup_intent->get_status() : '',
		);
	}

	/**
	 * Attach and save the card through the native runtime.
	 *
	 * @param WP_User $user              Fixture customer.
	 * @param string  $payment_method_id Provider PaymentMethod ID.
	 * @return array<string,string>
	 * @throws RuntimeException When setup or persistence is invalid.
	 */
	private static function run_native_setup( WP_User $user, string $payment_method_id ): array {
		$customer_class   = WooPaymentsCustomerService::class;
		$api_class        = WooPaymentsApiClient::class;
		$token_class      = WooPaymentsTokenService::class;
		$container        = wc_get_container();
		$customer_service = $container->get( $customer_class );
		$api_client       = $container->get( $api_class );
		$token_service    = $container->get( $token_class );
		$customer_id      = (string) $customer_service->get_or_create_customer_id_for_user( (int) $user->ID );
		if ( 1 !== preg_match( '/^cus_[A-Za-z0-9]{8,}$/', $customer_id ) ) {
			throw new RuntimeException( 'Native WooPayments did not map the user to a provider customer.' );
		}
		$setup_intent = $api_client->create_and_confirm_setup_intention(
			array(
				'customer'             => $customer_id,
				'payment_method'       => $payment_method_id,
				'payment_method_types' => array( 'card' ),
			),
			'sp01_' . hash( 'sha256', $customer_id . '|' . $payment_method_id )
		);
		$resolved_id  = self::scalar_field( $setup_intent, 'payment_method' );
		$resolved_id  = '' !== $resolved_id ? $resolved_id : $payment_method_id;
		$token        = $token_service->get_or_create_card_token_for_user( $resolved_id, (int) $user->ID );
		if ( ! $token instanceof WC_Payment_Token_CC ) {
			throw new RuntimeException( 'Native WooPayments token service returned no saved card token.' );
		}
		return array(
			'customer_id'       => $customer_id,
			'payment_method_id' => $resolved_id,
			'setup_intent_id'   => self::scalar_field( $setup_intent, 'id' ),
			'status'            => self::scalar_field( $setup_intent, 'status' ),
		);
	}

	/**
	 * Emit one machine-readable result line.
	 *
	 * @param array<string,mixed> $result Result payload.
	 */
	private static function emit( array $result ): void {
		echo wp_json_encode( $result, JSON_UNESCAPED_SLASHES ) . "\n";
	}
}

$tool_args  = isset( $args ) && is_array( $args ) ? $args : array_slice( $argv ?? array(), 1 );
$store_role = isset( $tool_args[0] ) ? (string) $tool_args[0] : '';
WooPaymentsCriticalFlowsSp01Driver::run( $store_role );
