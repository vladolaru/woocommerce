<?php
/**
 * Get payment intent by ID.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain;

/**
 * Get payment intent by ID.
 *
 * @since 11.4.0
 */
final class GetPaymentIntent extends AbstractAbility {
	protected const ID_FIELD = 'payment_intent_id';
	protected const ROUTE    = '/wc/v3/payments/payment_intents';

	/**
	 * Get the public WordPress ability name.
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce-payments/get-payment-intent';
	}

	/**
	 * Define the merchant-facing text.
	 *
	 * @since 11.4.0
	 */
	protected static function get_own_args(): array {
		return array(
			'label'        => __( 'Get payment intent by ID', 'woocommerce' ),
			'description'  => __( 'Look up a single payment intent by Stripe ID (pi_…). Answers \'what is the state of intent pi_X?\' during incident response.', 'woocommerce' ),
			'input_schema' => self::get_id_input_schema( __( 'Stripe payment intent ID (typically `pi_…`). Stripe ID prefixes are not contractually stable, so this field is not pattern-validated.', 'woocommerce' ) ),
		);
	}
}
