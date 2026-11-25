<?php
/**
 * Accept a WooPayments dispute.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\DisputeIdentifier;
use WP_Error;

/**
 * Concede a dispute through the same route as the merchant's dispute screen.
 *
 * @since 11.4.0
 */
final class AcceptDispute extends AbstractAbility {
	/**
	 * Get the public WordPress ability name.
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce-payments/accept-dispute';
	}

	/**
	 * Describe the final close and its required dispute ID.
	 *
	 * @since 11.4.0
	 */
	protected static function get_own_args(): array {
		return array(
			'label'         => __( 'Accept a dispute', 'woocommerce' ),
			'description'   => __( 'Accept (concede) a dispute. The disputed funds are forfeited to the cardholder permanently. This cannot be undone.', 'woocommerce' ),
			'input_schema'  => array(
				'type'                 => 'object',
				'required'             => array( 'dispute_id' ),
				'properties'           => array(
					'dispute_id' => array(
						'type'        => 'string',
						'description' => __( 'Stripe dispute ID (typically `du_…` or legacy `dp_…`). Stripe ID prefixes are not contractually stable, so this field is not pattern-validated.', 'woocommerce' ),
					),
				),
				'additionalProperties' => false,
			),
			'output_schema' => array(),
			'meta'          => self::get_private_write_meta(),
		);
	}

	/**
	 * Close the given dispute once through the merchant route.
	 *
	 * @since 11.4.0
	 *
	 * @param mixed $input Dispute parameters.
	 * @return array|WP_Error Closed dispute or the reason the close failed.
	 */
	public static function execute( $input = null ) {
		if ( ! is_array( $input ) || ! isset( $input['dispute_id'] ) || ! is_string( $input['dispute_id'] ) || '' === $input['dispute_id'] ) {
			return new WP_Error( 'wcpay_missing_dispute_id', __( 'A dispute_id is required to accept a dispute.', 'woocommerce' ) );
		}

		$dispute_id = $input['dispute_id'];
		if ( ! DisputeIdentifier::is_accepted( $dispute_id ) ) {
			return new WP_Error( 'wcpay_dispute_close_failed', __( 'Route param validation failed.', 'woocommerce' ) );
		}

		return self::delegate_write_to_rest_controller(
			'/wc/v3/payments/disputes/' . rawurlencode( $dispute_id ) . '/close',
			'wcpay_dispute_close_failed',
			'Dispute close'
		);
	}
}
