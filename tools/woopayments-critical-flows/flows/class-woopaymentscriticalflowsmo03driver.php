<?php
/**
 * Deterministic MO-03 payment-details state driver.
 *
 * @package WooCommerce\Tools\WooPaymentsCriticalFlows
 */

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;

/**
 * Project the exact manual-authorization order, provider, and list state.
 */
final class WooPaymentsCriticalFlowsMo03Driver {

	private const STATE_SCHEMA = 'woopayments_mo03_state.v1';
	private const LIST_ROUTE   = '/wc/v3/payments/authorizations';
	private const PAGE_SIZE    = 100;
	private const MAX_PAGES    = 50;
	private const NOTE_LIMIT   = 100;

	/**
	 * Execute one read-only snapshot.
	 *
	 * @internal
	 *
	 * @param array<int,string> $tool_args WP-CLI eval-file arguments.
	 */
	public static function run( array $tool_args ): void {
		$store    = (string) ( $tool_args[0] ?? '' );
		$order_id = (int) ( $tool_args[1] ?? 0 );
		$phase    = (string) ( $tool_args[2] ?? '' );

		self::snapshot( $store, $order_id, $phase );
	}

	/**
	 * Emit one state projection tied to the exact authorizations endpoint row.
	 *
	 * @param string $store    Store role.
	 * @param int    $order_id Order ID.
	 * @param string $phase    Pre/post phase.
	 */
	private static function snapshot( string $store, int $order_id, string $phase ): void {
		$owner   = self::runtime_owner();
		$payload = array(
			'schema'         => self::STATE_SCHEMA,
			'status'         => 'raw',
			'blockers'       => array(),
			'store'          => $store,
			'phase'          => $phase,
			'runtime_owner'  => $owner,
			'order'          => array(),
			'provider'       => array(),
			'authorizations' => array(),
			'notes'          => array(),
		);

		if ( ! in_array( $store, array( 'ref', 'target' ), true ) ) {
			$payload['blockers'][] = 'Store must be ref or target.';
		}
		if ( ! in_array( $phase, array( 'pre', 'post' ), true ) ) {
			$payload['blockers'][] = 'Phase must be pre or post.';
		}
		if ( $order_id <= 0 ) {
			$payload['blockers'][] = 'A positive order ID is required.';
		}

		$expected_owner = 'ref' === $store ? 'plugin' : 'native';
		if ( $owner !== $expected_owner ) {
			$payload['blockers'][] = 'The active WooPayments runtime owner is not the expected store owner.';
		}

		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof WC_Order ) {
			$payload['blockers'][] = 'The manual-capture order could not be loaded.';
		}

		if ( ! empty( $payload['blockers'] ) ) {
			$payload['status'] = 'blocked';
			self::emit( $payload );
			return;
		}

		$order_created    = $order->get_date_created();
		$payload['order'] = array(
			'id'               => $order->get_id(),
			'created'          => $order_created instanceof WC_DateTime ? $order_created->getTimestamp() : 0,
			'status'           => $order->get_status(),
			'paid'             => $order->is_paid(),
			'currency'         => strtoupper( $order->get_currency() ),
			'total_minor'      => self::amount_to_minor( $order->get_total() ),
			'payment_method'   => $order->get_payment_method(),
			'intent_id'        => (string) $order->get_meta( '_intent_id', true ),
			'charge_id'        => (string) $order->get_meta( '_charge_id', true ),
			'intention_status' => (string) $order->get_meta( '_intention_status', true ),
		);
		try {
			$payload['notes'] = self::note_counts( $order->get_id() );
		} catch ( RuntimeException $exception ) {
			$payload['status']     = 'blocked';
			$payload['blockers'][] = $exception->getMessage();
			self::emit( $payload );
			return;
		}

		if ( preg_match( '/^pi_[A-Za-z0-9_]+$/', $payload['order']['intent_id'] ) ) {
			try {
				$payload['provider'] = 'native' === $owner
					? self::native_provider_state( $payload['order']['intent_id'] )
					: self::plugin_provider_state( $payload['order']['intent_id'] );
			} catch ( Throwable $throwable ) {
				$payload['status']     = 'blocked';
				$payload['blockers'][] = sprintf( 'Provider state query failed (%s).', get_class( $throwable ) );
			}
		} else {
			$payload['provider'] = self::empty_provider_state();
		}

		if ( 'blocked' !== $payload['status'] ) {
			try {
				$payload['authorizations'] = self::authorizations_state(
					$order->get_id(),
					$payload['order']['intent_id'],
					$payload['order']['charge_id']
				);
			} catch ( Throwable $throwable ) {
				$payload['status']     = 'blocked';
				$payload['blockers'][] = sprintf( 'Authorizations endpoint query failed (%s).', get_class( $throwable ) );
			}
		}

		self::emit( $payload );
	}

	/**
	 * Scan the authorizations endpoint without trusting its optional order filter.
	 *
	 * @param int    $order_id  Exact order ID.
	 * @param string $intent_id Exact PaymentIntent ID.
	 * @param string $charge_id Exact charge ID.
	 * @return array<string,mixed>
	 * @throws RuntimeException When the endpoint cannot be fully scanned.
	 */
	private static function authorizations_state( int $order_id, string $intent_id, string $charge_id ): array {
		$matched       = array();
		$rows_scanned  = 0;
		$pages_scanned = 0;

		for ( $page = 1; $page <= self::MAX_PAGES; ++$page ) {
			$request = new WP_REST_Request( 'GET', self::LIST_ROUTE );
			$request->set_param( 'page', $page );
			$request->set_param( 'pagesize', self::PAGE_SIZE );
			$response = rest_do_request( $request );
			if ( is_wp_error( $response ) ) {
				throw new RuntimeException( 'The REST request returned a WP_Error.' );
			}
			$status = (int) $response->get_status();
			if ( 200 !== $status ) {
				throw new RuntimeException( sprintf( 'The REST route returned HTTP %d.', $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal WP-CLI diagnostic with an integer status.
			}
			$data = $response->get_data();
			$rows = is_array( $data ) && isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : null;
			if ( ! is_array( $rows ) ) {
				throw new RuntimeException( 'The REST route returned an invalid list envelope.' );
			}

			++$pages_scanned;
			$rows_scanned += count( $rows );
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				if (
					(string) ( $row['charge_id'] ?? '' ) === $charge_id
					&& (string) ( $row['payment_intent_id'] ?? '' ) === $intent_id
					&& (int) ( $row['order_id'] ?? 0 ) === $order_id
				) {
					$matched[] = self::authorization_row( $row );
				}
			}

			if ( count( $rows ) < self::PAGE_SIZE ) {
				break;
			}
			if ( self::MAX_PAGES === $page ) {
				throw new RuntimeException( 'The REST route exceeded the bounded pagination scan.' );
			}
		}

		return array(
			'route'             => self::LIST_ROUTE,
			'http_status'       => 200,
			'pages_scanned'     => $pages_scanned,
			'rows_scanned'      => $rows_scanned,
			'observed_at'       => time(),
			'exact_match_count' => count( $matched ),
			'matched_rows'      => $matched,
		);
	}

	/**
	 * Project one secret-free authorization row.
	 *
	 * @param array<string,mixed> $row Raw endpoint row.
	 * @return array<string,mixed>
	 */
	private static function authorization_row( array $row ): array {
		return array(
			'charge_id'             => (string) ( $row['charge_id'] ?? '' ),
			'payment_intent_id'     => (string) ( $row['payment_intent_id'] ?? '' ),
			'order_id'              => (int) ( $row['order_id'] ?? 0 ),
			'amount_minor'          => (int) ( $row['amount'] ?? 0 ),
			'amount_captured_minor' => (int) ( $row['amount_captured'] ?? 0 ),
			'currency'              => strtoupper( (string) ( $row['currency'] ?? '' ) ),
			'status'                => (string) ( $row['status'] ?? '' ),
			'created'               => self::created_timestamp( $row['created'] ?? null ),
		);
	}

	/**
	 * Normalize a numeric or ISO-8601 creation time to Unix seconds.
	 *
	 * @param mixed $value Raw creation value.
	 */
	private static function created_timestamp( $value ): int {
		if ( is_int( $value ) || ( is_string( $value ) && is_numeric( $value ) ) ) {
			$timestamp = (int) $value;
			return $timestamp > 100000000000 ? (int) floor( $timestamp / 1000 ) : $timestamp;
		}

		if ( is_string( $value ) ) {
			$timestamp = strtotime( $value );
			return false === $timestamp ? 0 : $timestamp;
		}

		return 0;
	}

	/**
	 * Determine active runtime ownership.
	 */
	private static function runtime_owner(): string {
		if ( class_exists( NativePaymentsRuntimeArbiter::class ) && function_exists( 'wc_get_container' ) ) {
			try {
				$owner = wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->get_runtime_owner();
				if ( in_array( $owner, array( 'plugin', 'native' ), true ) ) {
					return $owner;
				}
			} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Fall through to the plugin activation check.
			}
		}

		return class_exists( 'WC_Payments' ) ? 'plugin' : 'unknown';
	}

	/**
	 * Project native provider state.
	 *
	 * @param string $intent_id PaymentIntent ID.
	 * @return array<string,mixed>
	 * @throws Throwable When the native provider request cannot complete.
	 */
	private static function native_provider_state( string $intent_id ): array {
		$intent = wc_get_container()->get( WooPaymentsApiClient::class )->get_payment_intention( $intent_id );
		$charge = $intent['charges']['data'][0] ?? array();
		if ( ! is_array( $charge ) ) {
			$charge = array();
		}

		return array(
			'intent_id'                    => (string) ( $intent['id'] ?? '' ),
			'intent_status'                => (string) ( $intent['status'] ?? '' ),
			'intent_amount_minor'          => (int) ( $intent['amount'] ?? 0 ),
			'intent_currency'              => strtoupper( (string) ( $intent['currency'] ?? '' ) ),
			'charge_id'                    => (string) ( $charge['id'] ?? '' ),
			'charge_amount_minor'          => (int) ( $charge['amount'] ?? 0 ),
			'charge_amount_captured_minor' => (int) ( $charge['amount_captured'] ?? 0 ),
			'charge_captured'              => true === ( $charge['captured'] ?? false ),
			'charge_currency'              => strtoupper( (string) ( $charge['currency'] ?? '' ) ),
		);
	}

	/**
	 * Project plugin provider state.
	 *
	 * @param string $intent_id PaymentIntent ID.
	 * @return array<string,mixed>
	 * @throws RuntimeException When the plugin API client is unavailable.
	 */
	private static function plugin_provider_state( string $intent_id ): array {
		if ( ! class_exists( 'WC_Payments' ) || ! is_callable( array( 'WC_Payments', 'get_payments_api_client' ) ) ) {
			throw new RuntimeException( 'WooPayments API client is unavailable.' );
		}

		$intent = WC_Payments::get_payments_api_client()->get_intent( $intent_id );
		$charge = is_object( $intent ) && is_callable( array( $intent, 'get_charge' ) ) ? $intent->get_charge() : null;

		return array(
			'intent_id'                    => is_object( $intent ) && is_callable( array( $intent, 'get_id' ) ) ? (string) $intent->get_id() : '',
			'intent_status'                => is_object( $intent ) && is_callable( array( $intent, 'get_status' ) ) ? (string) $intent->get_status() : '',
			'intent_amount_minor'          => is_object( $intent ) && is_callable( array( $intent, 'get_amount' ) ) ? (int) $intent->get_amount() : 0,
			'intent_currency'              => is_object( $intent ) && is_callable( array( $intent, 'get_currency' ) ) ? strtoupper( (string) $intent->get_currency() ) : '',
			'charge_id'                    => is_object( $charge ) && is_callable( array( $charge, 'get_id' ) ) ? (string) $charge->get_id() : '',
			'charge_amount_minor'          => is_object( $charge ) && is_callable( array( $charge, 'get_amount' ) ) ? (int) $charge->get_amount() : 0,
			'charge_amount_captured_minor' => is_object( $charge ) && is_callable( array( $charge, 'get_amount_captured' ) ) ? (int) $charge->get_amount_captured() : 0,
			'charge_captured'              => is_object( $charge ) && is_callable( array( $charge, 'is_captured' ) ) && true === $charge->is_captured(),
			'charge_currency'              => is_object( $charge ) && is_callable( array( $charge, 'get_currency' ) ) ? strtoupper( (string) $charge->get_currency() ) : '',
		);
	}

	/**
	 * Return a complete missing-provider projection.
	 *
	 * @return array<string,mixed>
	 */
	private static function empty_provider_state(): array {
		return array(
			'intent_id'                    => '',
			'intent_status'                => '',
			'intent_amount_minor'          => 0,
			'intent_currency'              => '',
			'charge_id'                    => '',
			'charge_amount_minor'          => 0,
			'charge_amount_captured_minor' => 0,
			'charge_captured'              => false,
			'charge_currency'              => '',
		);
	}

	/**
	 * Count lifecycle notes without emitting note content.
	 *
	 * @param int $order_id Order ID.
	 * @return array<string,int>
	 * @throws RuntimeException When the bounded note scan is exceeded.
	 */
	private static function note_counts( int $order_id ): array {
		$counts = array(
			'authorization_count'   => 0,
			'capture_success_count' => 0,
			'capture_failure_count' => 0,
		);
		$notes  = wc_get_order_notes(
			array(
				'order_id' => $order_id,
				'limit'    => self::NOTE_LIMIT + 1,
			)
		);
		if ( count( $notes ) > self::NOTE_LIMIT ) {
			throw new RuntimeException( 'Order note scan exceeded the bounded collection limit.' );
		}

		foreach ( $notes as $note ) {
			$content = strtolower( html_entity_decode( wp_strip_all_tags( (string) ( $note->content ?? '' ) ), ENT_QUOTES, 'UTF-8' ) );
			if ( false !== strpos( $content, 'successfully captured' ) ) {
				++$counts['capture_success_count'];
			}
			if ( false !== strpos( $content, 'capture of' ) && false !== strpos( $content, 'failed' ) ) {
				++$counts['capture_failure_count'];
			}
			if ( false !== strpos( $content, 'authorized' ) && false === strpos( $content, 'unauthorized' ) ) {
				++$counts['authorization_count'];
			}
		}

		return $counts;
	}

	/**
	 * Convert the order amount to the store currency's minor unit.
	 *
	 * @param string $amount Decimal order amount.
	 */
	private static function amount_to_minor( string $amount ): int {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		return (int) round( (float) $amount * ( 10 ** $decimals ) );
	}

	/**
	 * Emit one JSON record.
	 *
	 * @param array<string,mixed> $payload Record.
	 */
	private static function emit( array $payload ): void {
		WP_CLI::line( wp_json_encode( $payload ) );
	}
}

WooPaymentsCriticalFlowsMo03Driver::run( $args );
