<?php
/**
 * Get charge by ID.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain;

/**
 * Get charge by ID.
 *
 * @since 11.4.0
 */
final class GetCharge extends AbstractAbility {
	protected const ID_FIELD = 'charge_id';
	protected const ROUTE    = '/wc/v3/payments/charges';

	/**
	 * Get the public WordPress ability name.
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce-payments/get-charge';
	}

	/**
	 * Define the merchant-facing text.
	 *
	 * @since 11.4.0
	 */
	protected static function get_own_args(): array {
		return array(
			'label'        => __( 'Get charge by ID', 'woocommerce' ),
			'description'  => __( 'Look up a single charge by Stripe ID (ch_… or py_…). Answers \'what happened with charge ch_X?\'.', 'woocommerce' ),
			'input_schema' => self::get_id_input_schema( __( 'Stripe charge ID (typically `ch_…` or `py_…`). Stripe ID prefixes are not contractually stable, so this field is not pattern-validated.', 'woocommerce' ) ),
		);
	}
}
