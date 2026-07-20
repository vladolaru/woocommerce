<?php
/**
 * Allowlisted WP-CLI adapter for deterministic dispute-resolution evidence.
 *
 * Usage:
 *   wp --user=1 eval-file - submit <dispute-id> <won|lost> <marker> < class-woopayments-critical-flows-md-resolution-store.php
 *   wp --user=1 eval-file - probe <dispute-id> <order-id> <charge-id> <intent-id> <won|lost> < class-woopayments-critical-flows-md-resolution-store.php
 *
 * @package WooCommerce\Tests\CriticalFlows
 */

defined( 'ABSPATH' ) || exit;

/**
 * Execute one exact dispute submission or observation request.
 */
final class WooPayments_Critical_Flows_MD_Resolution_Store {

	/** Maximum order notes or refunds retained by a fresh fixture. */
	private const COLLECTION_LIMIT = 50;

	/**
	 * Execute the requested adapter action.
	 *
	 * @param array<int,string> $arguments WP-CLI eval-file arguments.
	 */
	public static function run( array $arguments ): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by WooCommerce.
			WP_CLI::error( 'WooCommerce management permission is required.' );
		}

		$action = isset( $arguments[0] ) && is_string( $arguments[0] ) ? $arguments[0] : '';
		if ( 'account' === $action && 2 === count( $arguments ) ) {
			self::account( $arguments[1] );
			return;
		}
		if ( 'submit' === $action && 4 === count( $arguments ) ) {
			self::submit( $arguments[1], $arguments[2], $arguments[3] );
			return;
		}
		if ( 'probe' === $action && 6 === count( $arguments ) ) {
			self::probe( $arguments[1], $arguments[2], $arguments[3], $arguments[4], $arguments[5] );
			return;
		}

		WP_CLI::error( 'The dispute-resolution adapter invocation is invalid.' );
	}

	/**
	 * Resolve the exact runtime owner's connected Stripe account.
	 *
	 * @param string $runtime_owner Expected runtime owner.
	 */
	private static function account( string $runtime_owner ): void {
		$account_id = '';
		if ( 'plugin' === $runtime_owner && class_exists( 'WC_Payments' ) && method_exists( 'WC_Payments', 'get_account_service' ) ) {
			$account_service = WC_Payments::get_account_service();
			if ( is_object( $account_service ) && method_exists( $account_service, 'get_stripe_account_id' ) ) {
				$account_id = (string) $account_service->get_stripe_account_id();
			}
		} elseif ( 'native' === $runtime_owner && function_exists( 'wc_get_container' ) ) {
			$account_class = 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsAccountService';
			if ( class_exists( $account_class ) ) {
				$account_service = wc_get_container()->get( $account_class );
				if ( is_object( $account_service ) && method_exists( $account_service, 'get_account_id' ) ) {
					$account_id = (string) $account_service->get_account_id();
				}
			}
		} else {
			WP_CLI::error( 'The runtime owner is invalid.' );
		}

		if ( 1 !== preg_match( '/^acct_[A-Za-z0-9]{8,}$/', $account_id ) ) {
			WP_CLI::error( 'The connected Stripe account is unavailable.' );
		}
		self::emit(
			array(
				'account_id'  => $account_id,
				'site_locale' => (string) get_locale(),
			)
		);
	}

	/**
	 * Submit one evidence marker through the preserved internal REST contract.
	 *
	 * @param string $dispute_id Dispute ID.
	 * @param string $outcome    Expected terminal outcome.
	 * @param string $marker     Deterministic evidence marker.
	 */
	private static function submit( string $dispute_id, string $outcome, string $marker ): void {
		self::validate_profile( $dispute_id, $outcome, $marker );

		$witness = array(
			'trusted'        => false,
			'response_class' => 'unknown',
			'submit'         => true,
			'marker'         => $marker,
			'http_status'    => 0,
			'dispute_id'     => $dispute_id,
		);

		try {
			$request = new WP_REST_Request( 'POST', '/wc/v3/payments/disputes/' . $dispute_id );
			$request->set_body_params(
				array(
					'evidence' => array(
						'uncategorized_text' => $marker,
					),
					'submit'   => true,
					'metadata' => array(),
				)
			);
			$response                  = rest_do_request( $request );
			$status                    = (int) $response->get_status();
			$witness['trusted']        = true;
			$witness['response_class'] = $status >= 200 && $status < 300 ? 'trusted_success' : 'trusted_failure';
			$witness['http_status']    = $status;
		} catch ( Throwable $throwable ) {
			// The request may have crossed the mutation boundary. Preserve ambiguity
			// without exposing a raw exception or automatically replaying it.
			unset( $throwable );
		}

		self::emit( $witness );
	}

	/**
	 * Observe the exact dispute and its exact bound WooCommerce order.
	 *
	 * @param string $dispute_id        Dispute ID.
	 * @param string $order_id_raw      Order ID.
	 * @param string $expected_charge   Expected charge ID.
	 * @param string $expected_intent   Expected payment-intent ID.
	 * @param string $outcome           Expected terminal outcome.
	 */
	private static function probe( string $dispute_id, string $order_id_raw, string $expected_charge, string $expected_intent, string $outcome ): void {
		self::validate_dispute_id( $dispute_id );
		if ( ! in_array( $outcome, array( 'won', 'lost' ), true ) ) {
			WP_CLI::error( 'The dispute outcome is invalid.' );
		}
		if ( ! ctype_digit( $order_id_raw ) || (int) $order_id_raw < 1 ) {
			WP_CLI::error( 'The order ID is invalid.' );
		}
		if ( 1 !== preg_match( '/^(?:ch|py)_[A-Za-z0-9_]+$/', $expected_charge ) ) {
			WP_CLI::error( 'The charge ID is invalid.' );
		}
		if ( 1 !== preg_match( '/^pi_[A-Za-z0-9_]+$/', $expected_intent ) ) {
			WP_CLI::error( 'The payment-intent ID is invalid.' );
		}

		$order_id = (int) $order_id_raw;
		$order    = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			WP_CLI::error( 'The exact WooCommerce order is unavailable.' );
		}

		$stored_charge = (string) $order->get_meta( '_charge_id', true );
		$stored_intent = (string) $order->get_meta( '_intent_id', true );
		if ( $expected_charge !== $stored_charge || $expected_intent !== $stored_intent ) {
			WP_CLI::error( 'The exact order payment identity does not match.' );
		}

		$request  = new WP_REST_Request( 'GET', '/wc/v3/payments/disputes/' . $dispute_id );
		$response = rest_do_request( $request );
		if ( 200 !== (int) $response->get_status() ) {
			WP_CLI::error( 'The exact dispute observation is unavailable.' );
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			WP_CLI::error( 'The dispute observation shape is invalid.' );
		}

		$response_dispute = self::scalar_string( $data, 'id' );
		$response_charge  = self::entity_id( $data['charge'] ?? null );
		$response_order   = isset( $data['order'] ) && is_array( $data['order'] ) ? (int) ( $data['order']['id'] ?? 0 ) : 0;
		if ( $dispute_id !== $response_dispute || $expected_charge !== $response_charge || $order_id !== $response_order ) {
			WP_CLI::error( 'The dispute response does not bind the exact fixture.' );
		}

		$notes           = self::note_families( $order_id, $data );
		$refunds         = self::refunds( $order );
		$currency        = strtolower( (string) $order->get_currency() );
		$amount_decimals = (int) wc_get_price_decimals();
		$order_total     = (int) round( (float) $order->get_total() * ( 10 ** $amount_decimals ) );

		self::emit(
			array(
				'available'         => true,
				'dispute_id'        => $response_dispute,
				'dispute_status'    => self::scalar_string( $data, 'status' ),
				'charge_id'         => $stored_charge,
				'intent_id'         => $stored_intent,
				'order_id'          => $order_id,
				'order_status'      => (string) $order->get_status(),
				'order_total_minor' => $order_total,
				'currency'          => $currency,
				'notes'             => $notes,
				'refunds'           => $refunds,
			)
		);
	}

	/**
	 * Validate a submission's immutable outcome/marker profile.
	 *
	 * @param string $dispute_id Dispute ID.
	 * @param string $outcome    Expected outcome.
	 * @param string $marker     Evidence marker.
	 */
	private static function validate_profile( string $dispute_id, string $outcome, string $marker ): void {
		self::validate_dispute_id( $dispute_id );
		$profiles = array(
			'won'  => 'winning_evidence',
			'lost' => 'losing_evidence',
		);
		if ( ! isset( $profiles[ $outcome ] ) || $profiles[ $outcome ] !== $marker ) {
			WP_CLI::error( 'The dispute outcome and evidence marker do not match.' );
		}
	}

	/**
	 * Validate one provider dispute ID.
	 *
	 * @param string $dispute_id Dispute ID.
	 */
	private static function validate_dispute_id( string $dispute_id ): void {
		if ( 1 !== preg_match( '/^(?:dp|du)_[A-Za-z0-9_]+$/', $dispute_id ) ) {
			WP_CLI::error( 'The dispute ID is invalid.' );
		}
	}

	/**
	 * Reduce a scalar or expanded provider entity to its ID.
	 *
	 * @param mixed $entity Provider entity.
	 * @return string
	 */
	private static function entity_id( $entity ): string {
		if ( is_scalar( $entity ) ) {
			return (string) $entity;
		}
		if ( is_array( $entity ) && isset( $entity['id'] ) && is_scalar( $entity['id'] ) ) {
			return (string) $entity['id'];
		}
		return '';
	}

	/**
	 * Read one required scalar array field.
	 *
	 * @param array<string,mixed> $data Source data.
	 * @param string              $key  Field name.
	 * @return string
	 */
	private static function scalar_string( array $data, string $key ): string {
		return isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? (string) $data[ $key ] : '';
	}

	/**
	 * Reduce bounded order notes to lifecycle-family booleans.
	 *
	 * @param int                 $order_id Order ID.
	 * @param array<string,mixed> $dispute Dispute response.
	 * @return array<string,bool>
	 */
	private static function note_families( int $order_id, array $dispute ): array {
		$order_notes = wc_get_order_notes(
			array(
				'order_id' => $order_id,
				'limit'    => 50,
				'orderby'  => 'date_created',
				'order'    => 'ASC',
			)
		);
		$families    = array(
			'created'            => false,
			'evidence_submitted' => false,
			'funds_reinstated'   => false,
			'fees_deducted'      => false,
		);
		$updated     = false;

		foreach ( $order_notes as $order_note ) {
			$content = isset( $order_note->content ) && is_string( $order_note->content ) ? wp_strip_all_tags( $order_note->content ) : '';
			if ( false !== stripos( $content, 'Payment has been disputed' ) || false !== stripos( $content, 'A payment inquiry has been raised' ) ) {
				$families['created'] = true;
			}
			if ( false !== stripos( $content, 'Payment dispute has been updated' ) ) {
				$updated = true;
			}
			if ( false !== stripos( $content, 'Payment dispute funds have been reinstated' ) ) {
				$families['funds_reinstated'] = true;
			}
			if ( false !== stripos( $content, 'Payment dispute and fees have been deducted' ) ) {
				$families['fees_deducted'] = true;
			}
		}

		$metadata                       = isset( $dispute['metadata'] ) && is_array( $dispute['metadata'] ) ? $dispute['metadata'] : array();
		$families['evidence_submitted'] = $updated && ! empty( $metadata['__evidence_submitted_at'] );
		return $families;
	}

	/**
	 * Reduce bounded refunds to amount and reason-family facts.
	 *
	 * @param WC_Order $order Exact order.
	 * @return array<int,array{amount_minor:int,reason_family:string}>
	 */
	private static function refunds( WC_Order $order ): array {
		$order_refunds = $order->get_refunds();
		if ( count( $order_refunds ) > self::COLLECTION_LIMIT ) {
			WP_CLI::error( 'The exact order refund collection exceeds the evidence bound.' );
		}
		$decimals = (int) wc_get_price_decimals();
		$refunds  = array();
		foreach ( $order_refunds as $refund ) {
			if ( ! $refund instanceof WC_Order_Refund ) {
				continue;
			}
			$reason    = (string) $refund->get_reason();
			$refunds[] = array(
				'amount_minor'  => (int) round( (float) $refund->get_amount() * ( 10 ** $decimals ) ),
				'reason_family' => false !== stripos( $reason, 'dispute' ) ? 'dispute' : 'other',
			);
		}
		return $refunds;
	}

	/**
	 * Emit only one allowlisted JSON object.
	 *
	 * @param array<string,mixed> $payload Allowlisted payload.
	 */
	private static function emit( array $payload ): void {
		$encoded = wp_json_encode( $payload );
		if ( false === $encoded ) {
			WP_CLI::error( 'The allowlisted evidence payload could not be encoded.' );
		}
		WP_CLI::line( $encoded );
	}
}

WooPayments_Critical_Flows_MD_Resolution_Store::run( isset( $args ) && is_array( $args ) ? $args : array() );
