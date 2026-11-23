<?php
/**
 * Refund a WooPayments charge.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentDetailsRestController;
use WP_Error;

/**
 * Refund a charge using the caller's stable key.
 *
 * @since 11.4.0
 */
final class RefundCharge extends AbstractAbility {
	/**
	 * Get the public WordPress ability name.
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce-payments/refund-charge';
	}

	/**
	 * Define the refund inputs and write permissions advertised to clients.
	 *
	 * @since 11.4.0
	 */
	protected static function get_own_args(): array {
		return array(
			'label'        => __( 'Refund a charge', 'woocommerce' ),
			'description'  => __( 'Create a full or partial refund on a Stripe charge. Amount is in minor units (e.g. cents). Send the same idempotency_key with the same request again and no second refund is made: you get the first answer back, or a refusal that says why when the first refund is already recorded on the order.', 'woocommerce' ),
			'input_schema' => array(
				'type'                 => 'object',
				'required'             => array( 'charge_id', 'idempotency_key' ),
				'properties'           => array(
					'charge_id'       => array(
						'type'        => 'string',
						'pattern'     => '^(ch_|py_)',
						'description' => __( 'Stripe charge ID to refund (ch_… or py_…).', 'woocommerce' ),
					),
					'amount'          => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Amount to refund in minor units (e.g. cents). Omit for a full refund.', 'woocommerce' ),
					),
					'reason'          => array(
						'type'        => 'string',
						'enum'        => array( 'duplicate', 'fraudulent', 'requested_by_customer' ),
						'description' => __( 'Optional refund reason.', 'woocommerce' ),
					),
					'idempotency_key' => array(
						'type'        => 'string',
						'description' => __( 'Required. A stable, caller-unique key so duplicate retries dedupe to the original refund instead of creating a second one. Reuse the exact same key when retrying the same logical refund.', 'woocommerce' ),
					),
				),
				'additionalProperties' => false,
			),
			'meta'         => array(
				'annotations'  => array(
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => true,
				),
				'show_in_rest' => true,
				'mcp'          => array( 'public' => false ),
			),
		);
	}

	/**
	 * Refund the charge under the owning order's lock when one exists.
	 *
	 * @since 11.4.0
	 *
	 * @param mixed $input Ability input after schema validation.
	 * @return array|WP_Error Platform refund or a refusal.
	 */
	public static function execute( $input = null ) {
		if ( ! is_array( $input ) || ! isset( $input['charge_id'] ) || ! is_string( $input['charge_id'] ) || '' === $input['charge_id'] ) {
			return new WP_Error( 'wcpay_missing_charge_id', __( 'A charge_id is required to create a refund.', 'woocommerce' ) );
		}
		if ( ! isset( $input['idempotency_key'] ) || ! is_string( $input['idempotency_key'] ) || '' === $input['idempotency_key'] ) {
			return new WP_Error( 'wcpay_missing_idempotency_key', __( 'An idempotency_key is required so refund retries are safe.', 'woocommerce' ) );
		}

		$charge_id = $input['charge_id'];
		$amount    = isset( $input['amount'] ) ? (int) $input['amount'] : null;
		$reason    = isset( $input['reason'] ) && is_string( $input['reason'] ) ? $input['reason'] : null;
		$data      = null;
		try {
			self::validate_refund_request( $charge_id, $amount );

			$refund = wc_get_container()->get( WooPaymentsPaymentDetailsRestController::class )->refund_charge_without_order( $charge_id, $amount, $reason, 'woopayments_ability', $input['idempotency_key'] );
			if ( ! is_wp_error( $refund ) ) {
				return $refund;
			}
			$message = $refund->get_error_message();
		} catch ( \Throwable $failure ) {
			$message = $failure->getMessage();
			$data    = array( 'exception' => $failure );
		}

		$log_id = strlen( $charge_id ) <= 253 ? WooPaymentsLogger::get_loggable_provider_id( $charge_id ) : 'another value';
		wc_get_logger()->error( sprintf( 'Refund failed for charge %s.', $log_id ), array( 'source' => 'woopayments-abilities' ) );
		return new WP_Error( 'wcpay_refund_failed', $message, $data );
	}

	/**
	 * Apply the extension's refund request checks after the public ability schema checks the prefix.
	 *
	 * @param string   $charge_id Charge ID.
	 * @param int|null $amount    Refund amount in minor units.
	 * @throws ApiException If the charge ID or amount cannot be sent.
	 */
	private static function validate_refund_request( string $charge_id, ?int $amount ): void {
		if ( '0' === $charge_id ) {
			throw new ApiException( esc_html__( 'Empty parameter is not allowed', 'woocommerce' ), 'wcpay_core_invalid_request_parameter_stripe_id' );
		}
		if ( ! preg_match( '/^(ch|py)_\w{1,250}$/', $charge_id ) ) {
			/* translators: %s: Invalid Stripe ID. */
			throw new ApiException( esc_html( sprintf( __( '%s is not a valid Stripe identifier', 'woocommerce' ), $charge_id ) ), 'wcpay_core_invalid_request_parameter_stripe_id' );
		}
		if ( null !== $amount && $amount <= 0 ) {
			/* translators: 1: Invalid refund amount, 2: Lower bound. */
			throw new ApiException( esc_html( sprintf( __( 'Invalid number passed. Number %1$s needs to be larger than %2$s', 'woocommerce' ), $amount, 0 ) ), 'wcpay_core_invalid_request_parameter_order' );
		}
	}
}
