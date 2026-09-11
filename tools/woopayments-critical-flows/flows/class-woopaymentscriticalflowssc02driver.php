<?php
/**
 * Deterministic SC-02 Store API checkout state collector.
 *
 * @package WooCommerce\Tools\WooPaymentsCriticalFlows
 */

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;

/**
 * Project bounded preflight, exact-order, and provider evidence for SC-02.
 */
final class WooPaymentsCriticalFlowsSc02Driver {

	private const SCHEMA             = 'woopayments_sc02_state_raw.v1';
	private const SKU                = 'test-lab-beaker-001';
	private const GATEWAY_ID         = 'woocommerce_payments';
	private const MAX_LINE_ITEMS     = 20;
	private const MAX_RESPONSE_BYTES = 262144;

	/**
	 * Dispatch one read-only evidence collection mode.
	 *
	 * @internal
	 *
	 * @param array<int,string> $tool_args WP-CLI eval-file arguments.
	 */
	public static function run( array $tool_args ): void {
		$mode      = (string) ( $tool_args[0] ?? '' );
		$store     = (string) ( $tool_args[1] ?? '' );
		$run_stamp = (string) ( $tool_args[2] ?? '' );

		if ( ! self::valid_binding( $store, $run_stamp ) ) {
			self::emit( self::blocked_dispatch_payload( $mode, $store, $run_stamp, 'Invalid SC-02 run binding.' ) );
			return;
		}

		if ( ! self::has_context_key() ) {
			self::emit( self::blocked_dispatch_payload( $mode, $store, $run_stamp, 'SC-02 evidence context was unavailable.' ) );
			return;
		}

		if ( 'preflight' === $mode && 3 === count( $tool_args ) ) {
			self::emit( self::preflight( $store, $run_stamp ) );
			return;
		}

		if ( 'post' === $mode && 6 === count( $tool_args ) ) {
			self::emit(
				self::post(
					$store,
					$run_stamp,
					(string) $tool_args[3],
					absint( $tool_args[4] ),
					absint( $tool_args[5] )
				)
			);
			return;
		}

		self::emit( self::blocked_dispatch_payload( $mode, $store, $run_stamp, 'Unknown SC-02 state mode.' ) );
	}

	/**
	 * Collect immutable readiness facts without changing store state.
	 *
	 * @param string $store     Store role.
	 * @param string $run_stamp Current run stamp.
	 * @return array<string,mixed>
	 */
	private static function preflight( string $store, string $run_stamp ): array {
		$owner    = self::runtime_owner();
		$blockers = array();
		$gateway  = array(
			'id'        => '',
			'class'     => '',
			'available' => false,
			'test_mode' => false,
			'connected' => false,
		);
		$product  = array(
			'id'          => 0,
			'sku'         => '',
			'price'       => '',
			'purchasable' => false,
			'in_stock'    => false,
		);

		if ( self::expected_owner( $store ) !== $owner ) {
			$blockers[] = 'The active WooPayments runtime owner was invalid.';
		}

		$gateway_instance = self::gateway();
		if ( $gateway_instance instanceof WC_Payment_Gateway ) {
			$gateway['id']    = (string) $gateway_instance->id;
			$gateway['class'] = get_class( $gateway_instance );
			try {
				$gateway['available'] = true === $gateway_instance->is_available();
			} catch ( Throwable $throwable ) {
				$blockers[] = 'WooPayments gateway readiness could not be observed.';
			}
			$gateway['test_mode'] = self::gateway_test_mode( $gateway_instance, $owner );
			$gateway['connected'] = self::gateway_connected( $gateway_instance, $owner );
		} else {
			$blockers[] = 'WooPayments gateway could not be resolved.';
		}

		$product_id       = wc_get_product_id_by_sku( self::SKU );
		$product_instance = $product_id > 0 ? wc_get_product( $product_id ) : false;
		if ( $product_instance instanceof WC_Product ) {
			$product = array(
				'id'          => $product_instance->get_id(),
				'sku'         => (string) $product_instance->get_sku(),
				'price'       => number_format( (float) $product_instance->get_price(), 2, '.', '' ),
				'purchasable' => true === $product_instance->is_purchasable(),
				'in_stock'    => true === $product_instance->is_in_stock(),
			);
		} else {
			$blockers[] = 'The fixed SC-02 product could not be resolved.';
		}

		$account_id = self::connected_account_id( $owner );

		return array(
			'schema'               => self::SCHEMA,
			'phase'                => 'preflight',
			'store'                => $store,
			'run_stamp'            => $run_stamp,
			'runtime_owner'        => $owner,
			'gateway'              => $gateway,
			'product'              => $product,
			'store_currency'       => function_exists( 'get_woocommerce_currency' ) ? strtoupper( get_woocommerce_currency() ) : '',
			'connected_account_id' => $account_id,
			'blockers'             => array_values( array_unique( $blockers ) ),
		);
	}

	/**
	 * Collect the exact checkout order and directly linked Stripe objects.
	 *
	 * @param string $store      Store role.
	 * @param string $run_stamp  Current run stamp.
	 * @param string $run_token  Exact checkout customer-note token.
	 * @param int    $product_id Exact product ID from preflight/HTTP evidence.
	 * @param int    $order_id   Exact response order ID.
	 * @return array<string,mixed>
	 */
	private static function post( string $store, string $run_stamp, string $run_token, int $product_id, int $order_id ): array {
		$owner    = self::runtime_owner();
		$payload  = self::empty_post_payload( $store, $run_stamp, $owner );
		$expected = 'sc02-' . $run_stamp . '-' . $store;

		if ( self::expected_owner( $store ) !== $owner ) {
			$payload['blockers'][] = 'The active WooPayments runtime owner was invalid.';
		}
		if ( $run_token !== $expected || $product_id <= 0 || $order_id <= 0 ) {
			$payload['blockers'][] = 'The SC-02 post-checkout binding was invalid.';
			return $payload;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			$payload['blockers'][] = 'The exact Store API response order could not be loaded.';
			return $payload;
		}

		$items = $order->get_items( 'line_item' );
		if ( count( $items ) > self::MAX_LINE_ITEMS ) {
			$payload['blockers'][] = 'The exact order exceeded the bounded line-item projection.';
			return $payload;
		}

		$line_items = array();
		foreach ( $items as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$item_product = $item->get_product();
			$line_items[] = array(
				'product_id' => $item->get_product_id(),
				'sku'        => $item_product instanceof WC_Product ? (string) $item_product->get_sku() : '',
				'quantity'   => (int) $item->get_quantity(),
				'total'      => number_format( (float) $item->get_total(), 2, '.', '' ),
			);
		}

		$intent_id        = (string) $order->get_meta( '_intent_id', true );
		$charge_id        = (string) $order->get_meta( '_charge_id', true );
		$date_paid        = $order->get_date_paid();
		$payload['order'] = array(
			'id'                => $order->get_id(),
			'status'            => (string) $order->get_status(),
			'created_via'       => (string) $order->get_created_via(),
			'payment_method'    => (string) $order->get_payment_method(),
			'customer_id'       => (int) $order->get_customer_id(),
			'customer_note'     => (string) $order->get_customer_note(),
			'total'             => number_format( (float) $order->get_total(), 2, '.', '' ),
			'currency'          => strtoupper( (string) $order->get_currency() ),
			'date_paid_present' => $date_paid instanceof WC_DateTime,
			'transaction_id'    => (string) $order->get_transaction_id(),
			'intent_id'         => $intent_id,
			'charge_id'         => $charge_id,
			'line_items'        => $line_items,
		);

		if ( $order->get_customer_note() !== $run_token ) {
			return $payload;
		}

		if ( ! self::valid_intent_id( $intent_id ) || ! self::valid_charge_id( $charge_id ) ) {
			return $payload;
		}

		$account_id = self::connected_account_id( $owner );
		$secret_key = self::stripe_test_secret_key();
		if ( '' === $account_id || '' === $secret_key ) {
			$payload['blockers'][] = 'Provider observation failed.';
			return $payload;
		}

		$intent = self::stripe_object(
			'https://api.stripe.com/v1/payment_intents/' . rawurlencode( $intent_id ),
			$secret_key,
			$account_id
		);
		$charge = self::stripe_object(
			'https://api.stripe.com/v1/charges/' . rawurlencode( $charge_id ),
			$secret_key,
			$account_id
		);
		if ( null === $intent || null === $charge ) {
			$payload['blockers'][] = 'Provider observation failed.';
			return $payload;
		}

		$payload['provider'] = array(
			'intent' => self::project_intent( $intent ),
			'charge' => self::project_charge( $charge ),
		);

		return $payload;
	}

	/**
	 * Resolve the active gateway instance.
	 */
	private static function gateway(): ?WC_Payment_Gateway {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->payment_gateways() ) {
			return null;
		}

		$gateways = WC()->payment_gateways()->payment_gateways();
		$gateway  = $gateways[ self::GATEWAY_ID ] ?? null;
		return $gateway instanceof WC_Payment_Gateway ? $gateway : null;
	}

	/**
	 * Observe the active gateway's test-mode state.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway instance.
	 * @param string             $owner   Runtime owner.
	 */
	private static function gateway_test_mode( WC_Payment_Gateway $gateway, string $owner ): bool {
		try {
			if ( 'native' === $owner && is_callable( array( $gateway, 'is_test_mode' ) ) ) {
				return true === $gateway->is_test_mode();
			}
			if ( 'plugin' === $owner && class_exists( 'WC_Payments' ) && is_callable( array( 'WC_Payments', 'mode' ) ) ) {
				$mode = WC_Payments::mode();
				return is_object( $mode ) && is_callable( array( $mode, 'is_test' ) ) && true === $mode->is_test();
			}
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// An unobservable readiness fact remains false for the preflight oracle.
		}

		return false;
	}

	/**
	 * Observe whether the active gateway can reach a connected account.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway instance.
	 * @param string             $owner   Runtime owner.
	 */
	private static function gateway_connected( WC_Payment_Gateway $gateway, string $owner ): bool {
		try {
			if ( 'plugin' === $owner && is_callable( array( $gateway, 'is_connected' ) ) ) {
				return true === $gateway->is_connected();
			}
			if ( 'native' === $owner ) {
				$service = self::native_account_service();
				return $service instanceof WooPaymentsAccountService && $service->can_process_payments();
			}
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// An unobservable readiness fact remains false for the preflight oracle.
		}

		return false;
	}

	/**
	 * Resolve a validated connected-account ID without exposing account data.
	 *
	 * @param string $owner Runtime owner.
	 */
	private static function connected_account_id( string $owner ): string {
		$account_id = '';

		try {
			if ( 'native' === $owner ) {
				$service = self::native_account_service();
				if ( $service instanceof WooPaymentsAccountService ) {
					$account_id = $service->get_account_id();
				}
			} elseif ( 'plugin' === $owner && class_exists( 'WC_Payments' ) && is_callable( array( 'WC_Payments', 'get_account_service' ) ) ) {
				$service = WC_Payments::get_account_service();
				if ( is_object( $service ) && is_callable( array( $service, 'get_cached_account_data' ) ) ) {
					$data       = $service->get_cached_account_data();
					$account_id = is_array( $data ) && is_string( $data['account_id'] ?? null ) ? $data['account_id'] : '';
				}
			}
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			$account_id = '';
		}

		if ( '' === $account_id ) {
			$cache = get_option( 'wcpay_account_data', array() );
			if ( is_array( $cache ) && is_string( $cache['data']['account_id'] ?? null ) ) {
				$account_id = $cache['data']['account_id'];
			} elseif ( is_array( $cache ) && is_string( $cache['account_id'] ?? null ) ) {
				$account_id = $cache['account_id'];
			}
		}

		return 1 === preg_match( '/^acct_[A-Za-z0-9]{8,}$/', $account_id ) ? $account_id : '';
	}

	/**
	 * Resolve the native account service when available.
	 */
	private static function native_account_service(): ?WooPaymentsAccountService {
		if ( ! class_exists( WooPaymentsAccountService::class ) || ! function_exists( 'wc_get_container' ) ) {
			return null;
		}

		try {
			$service = wc_get_container()->get( WooPaymentsAccountService::class );
			return $service instanceof WooPaymentsAccountService ? $service : null;
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			return null;
		}
	}

	/**
	 * Resolve the local Stripe test secret without returning diagnostics.
	 */
	private static function stripe_test_secret_key(): string {
		$secret_key = getenv( 'STRIPE_TEST_SECRET_KEY' );
		if ( ! is_string( $secret_key ) || '' === $secret_key ) {
			$secret_key = get_option( 'wcpay_test_lab_stripe_key', '' );
		}

		return is_string( $secret_key ) && 0 === strpos( $secret_key, 'sk_test_' ) ? $secret_key : '';
	}

	/**
	 * Retrieve one exact Stripe object through a bounded, no-redirect request.
	 *
	 * @param string $url        Exact Stripe object URL.
	 * @param string $secret_key Local test secret.
	 * @param string $account_id Connected account ID.
	 * @return array<string,mixed>|null
	 */
	private static function stripe_object( string $url, string $secret_key, string $account_id ): ?array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 30,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_RESPONSE_BYTES,
				'headers'             => array(
					'Accept'         => 'application/json',
					'Authorization'  => 'Bearer ' . $secret_key,
					'Stripe-Account' => $account_id,
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === $body || strlen( $body ) >= self::MAX_RESPONSE_BYTES ) {
			return null;
		}

		$data = json_decode( $body, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Project a fixed, secret-free PaymentIntent field set.
	 *
	 * @param array<string,mixed> $data Provider response.
	 * @return array<string,mixed>
	 */
	private static function project_intent( array $data ): array {
		return array(
			'id'             => self::string_value( $data, 'id' ),
			'object'         => self::string_value( $data, 'object' ),
			'status'         => self::string_value( $data, 'status' ),
			'amount'         => self::integer_value( $data, 'amount' ),
			'currency'       => self::string_value( $data, 'currency' ),
			'latest_charge'  => self::string_value( $data, 'latest_charge' ),
			'payment_method' => self::string_value( $data, 'payment_method' ),
		);
	}

	/**
	 * Project a fixed, secret-free Charge field set.
	 *
	 * @param array<string,mixed> $data Provider response.
	 * @return array<string,mixed>
	 */
	private static function project_charge( array $data ): array {
		return array(
			'id'              => self::string_value( $data, 'id' ),
			'object'          => self::string_value( $data, 'object' ),
			'status'          => self::string_value( $data, 'status' ),
			'paid'            => true === ( $data['paid'] ?? false ),
			'amount'          => self::integer_value( $data, 'amount' ),
			'amount_captured' => self::integer_value( $data, 'amount_captured' ),
			'currency'        => self::string_value( $data, 'currency' ),
			'payment_intent'  => self::string_value( $data, 'payment_intent' ),
			'payment_method'  => self::string_value( $data, 'payment_method' ),
		);
	}

	/**
	 * Return one provider string only when its raw type is trustworthy.
	 *
	 * @param array<string,mixed> $data Provider response.
	 * @param string              $key  Field name.
	 */
	private static function string_value( array $data, string $key ): string {
		return is_string( $data[ $key ] ?? null ) ? $data[ $key ] : '';
	}

	/**
	 * Return one provider integer only when its raw type is trustworthy.
	 *
	 * @param array<string,mixed> $data Provider response.
	 * @param string              $key  Field name.
	 */
	private static function integer_value( array $data, string $key ): int {
		return is_int( $data[ $key ] ?? null ) ? $data[ $key ] : 0;
	}

	/**
	 * Determine the selected WooPayments runtime owner.
	 */
	private static function runtime_owner(): string {
		if ( class_exists( NativePaymentsRuntimeArbiter::class ) && function_exists( 'wc_get_container' ) ) {
			try {
				$owner = wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->get_runtime_owner();
				if ( in_array( $owner, array( 'plugin', 'native' ), true ) ) {
					return $owner;
				}
			} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Fall through to the legacy plugin presence check.
			}
		}

		return class_exists( 'WC_Payments' ) ? 'plugin' : 'unknown';
	}

	/**
	 * Build an exact empty post payload for bounded failures.
	 *
	 * @param string $store     Store role.
	 * @param string $run_stamp Current run stamp.
	 * @param string $owner     Runtime owner.
	 * @return array<string,mixed>
	 */
	private static function empty_post_payload( string $store, string $run_stamp, string $owner ): array {
		return array(
			'schema'        => self::SCHEMA,
			'phase'         => 'post',
			'store'         => $store,
			'run_stamp'     => $run_stamp,
			'runtime_owner' => $owner,
			'order'         => array(
				'id'                => 0,
				'status'            => '',
				'created_via'       => '',
				'payment_method'    => '',
				'customer_id'       => 0,
				'customer_note'     => '',
				'total'             => '',
				'currency'          => '',
				'date_paid_present' => false,
				'transaction_id'    => '',
				'intent_id'         => '',
				'charge_id'         => '',
				'line_items'        => array(),
			),
			'provider'      => array(
				'intent' => array(
					'id'             => '',
					'object'         => '',
					'status'         => '',
					'amount'         => 0,
					'currency'       => '',
					'latest_charge'  => '',
					'payment_method' => '',
				),
				'charge' => array(
					'id'              => '',
					'object'          => '',
					'status'          => '',
					'paid'            => false,
					'amount'          => 0,
					'amount_captured' => 0,
					'currency'        => '',
					'payment_intent'  => '',
					'payment_method'  => '',
				),
			),
			'blockers'      => array(),
		);
	}

	/**
	 * Return a mode-shaped blocked payload for dispatch failures.
	 *
	 * @param string $mode      Requested mode.
	 * @param string $store     Store role.
	 * @param string $run_stamp Run stamp.
	 * @param string $blocker   Safe blocker.
	 * @return array<string,mixed>
	 */
	private static function blocked_dispatch_payload( string $mode, string $store, string $run_stamp, string $blocker ): array {
		$owner = self::runtime_owner();
		if ( 'post' === $mode ) {
			$payload               = self::empty_post_payload( $store, $run_stamp, $owner );
			$payload['blockers'][] = $blocker;
			return $payload;
		}

		return array(
			'schema'               => self::SCHEMA,
			'phase'                => 'preflight',
			'store'                => $store,
			'run_stamp'            => $run_stamp,
			'runtime_owner'        => $owner,
			'gateway'              => array(
				'id'        => '',
				'class'     => '',
				'available' => false,
				'test_mode' => false,
				'connected' => false,
			),
			'product'              => array(
				'id'          => 0,
				'sku'         => '',
				'price'       => '',
				'purchasable' => false,
				'in_stock'    => false,
			),
			'store_currency'       => '',
			'connected_account_id' => '',
			'blockers'             => array( $blocker ),
		);
	}

	/**
	 * Validate the store and current-run identity.
	 *
	 * @param string $store     Store role.
	 * @param string $run_stamp Run stamp.
	 */
	private static function valid_binding( string $store, string $run_stamp ): bool {
		return in_array( $store, array( 'ref', 'target' ), true )
			&& 1 === preg_match( '/^[0-9]{8}T[0-9]{6}Z-[0-9]+$/', $run_stamp );
	}

	/**
	 * Validate the private run-context key without emitting it.
	 */
	private static function has_context_key(): bool {
		$key = getenv( 'CRITICAL_FLOWS_RUN_CONTEXT_KEY' );
		return is_string( $key ) && 1 === preg_match( '/^[0-9a-f]{64}$/', $key );
	}

	/**
	 * Return the owner required by a store role.
	 *
	 * @param string $store Store role.
	 */
	private static function expected_owner( string $store ): string {
		return 'ref' === $store ? 'plugin' : 'native';
	}

	/**
	 * Determine whether an ID has the exact PaymentIntent shape.
	 *
	 * @param string $intent_id PaymentIntent ID.
	 */
	private static function valid_intent_id( string $intent_id ): bool {
		return 1 === preg_match( '/^pi_[A-Za-z0-9_]+$/', $intent_id );
	}

	/**
	 * Determine whether an ID has the exact Charge shape.
	 *
	 * @param string $charge_id Charge ID.
	 */
	private static function valid_charge_id( string $charge_id ): bool {
		return 1 === preg_match( '/^ch_[A-Za-z0-9_]+$/', $charge_id );
	}

	/**
	 * Emit exactly one JSON line.
	 *
	 * @param array<string,mixed> $payload Evidence payload.
	 */
	private static function emit( array $payload ): void {
		WP_CLI::line( wp_json_encode( $payload ) );
	}
}
