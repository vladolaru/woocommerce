<?php
/**
 * Deterministic MO-01 manual-capture state driver.
 *
 * @package WooCommerce\Tools\WooPaymentsCriticalFlows
 */

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;

/**
 * Read one order and its provider intent into a secret-free state projection.
 */
final class WooPaymentsCriticalFlowsMo01StateDriver {

	private const SCHEMA = 'woopayments_mo01_state.v1';

	/**
	 * Execute the read-only state probe.
	 *
	 * @internal
	 *
	 * @param array<int,string> $tool_args WP-CLI eval-file arguments.
	 */
	public static function run( array $tool_args ): void {
		$store    = (string) ( $tool_args[0] ?? '' );
		$order_id = (int) ( $tool_args[1] ?? 0 );
		$phase    = (string) ( $tool_args[2] ?? '' );
		$owner    = self::runtime_owner();
		$payload  = array(
			'schema'        => self::SCHEMA,
			'status'        => 'raw',
			'blockers'      => array(),
			'store'         => $store,
			'phase'         => $phase,
			'runtime_owner' => $owner,
			'order'         => array(),
			'provider'      => array(),
			'notes'         => array(),
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

		$payload['order'] = array(
			'id'               => $order->get_id(),
			'status'           => $order->get_status(),
			'paid'             => $order->is_paid(),
			'currency'         => strtoupper( $order->get_currency() ),
			'total_minor'      => self::amount_to_minor( $order->get_total() ),
			'payment_method'   => $order->get_payment_method(),
			'intent_id'        => (string) $order->get_meta( '_intent_id', true ),
			'charge_id'        => (string) $order->get_meta( '_charge_id', true ),
			'intention_status' => (string) $order->get_meta( '_intention_status', true ),
		);
		$payload['notes'] = self::note_counts( $order->get_id() );

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

		self::emit( $payload );
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
	 * Return a complete projection for a locally missing or malformed intent ID.
	 *
	 * This is product state, not transport unavailability; the external normalizer
	 * grades the empty projection as FAIL against the MO-01 contract.
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
	 * Count lifecycle notes without emitting customer or order-note content.
	 *
	 * @param int $order_id Order ID.
	 * @return array<string,int>
	 */
	private static function note_counts( int $order_id ): array {
		$counts = array(
			'authorization_count'   => 0,
			'capture_success_count' => 0,
			'capture_failure_count' => 0,
		);
		$notes  = wc_get_order_notes( array( 'order_id' => $order_id ) );

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
	 * @param array<string,mixed> $payload State payload.
	 */
	private static function emit( array $payload ): void {
		WP_CLI::line( wp_json_encode( $payload ) );
	}
}

WooPaymentsCriticalFlowsMo01StateDriver::run( $args );
