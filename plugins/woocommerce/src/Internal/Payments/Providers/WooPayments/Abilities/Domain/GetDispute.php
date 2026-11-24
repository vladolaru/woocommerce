<?php
/**
 * Get dispute by ID.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\DisputeIdentifier;

/**
 * Get dispute by ID.
 *
 * @since 11.4.0
 */
final class GetDispute extends AbstractAbility {
	protected const ID_FIELD = 'dispute_id';
	protected const ROUTE    = '/wc/v3/payments/disputes';

	/**
	 * Get the public WordPress ability name.
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce-payments/get-dispute';
	}

	/**
	 * Define the merchant-facing text.
	 *
	 * @since 11.4.0
	 */
	protected static function get_own_args(): array {
		return array(
			'label'        => __( 'Get dispute by ID', 'woocommerce' ),
			'description'  => __( 'Look up a single dispute by ID. Answers \'what evidence is needed for dispute du_X and by when?\'.', 'woocommerce' ),
			'input_schema' => self::get_id_input_schema( __( 'Stripe dispute ID using letters, digits, or underscores. The word `summary` is reserved.', 'woocommerce' ) ),
		);
	}

	/**
	 * Limit the lookup to IDs served by the dispute detail route.
	 *
	 * @since 11.4.0
	 *
	 * @param mixed $input Validated ability input.
	 * @return array|\WP_Error Dispute details or an input or route error.
	 */
	public static function execute( $input = null ) {
		$params = (array) $input;
		$id     = $params[ self::ID_FIELD ] ?? null;
		if ( is_string( $id ) && '' !== $id && ! DisputeIdentifier::is_accepted( $id ) ) {
			return new \WP_Error( 'wcpay_invalid_dispute_id', __( 'Enter a valid dispute ID.', 'woocommerce' ), array( 'status' => 400 ) );
		}
		return parent::execute( $params );
	}
}
