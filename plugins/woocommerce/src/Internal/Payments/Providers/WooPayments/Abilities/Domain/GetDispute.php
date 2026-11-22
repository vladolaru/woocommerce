<?php
/**
 * Get dispute by ID.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain;

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
			'input_schema' => self::get_id_input_schema( __( 'Stripe dispute ID (typically `du_…` or legacy `dp_…`). Stripe ID prefixes are not contractually stable, so this field is not pattern-validated.', 'woocommerce' ) ),
		);
	}
}
