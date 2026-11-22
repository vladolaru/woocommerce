<?php
/**
 * Get timeline for payment intent.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain;

/**
 * Get timeline for payment intent.
 *
 * @since 11.4.0
 */
final class GetTimeline extends AbstractAbility {
	protected const ID_FIELD = 'intention_id';
	protected const ROUTE    = '/wc/v3/payments/timeline';

	/**
	 * Get the public WordPress ability name.
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce-payments/get-timeline';
	}

	/**
	 * Define the merchant-facing text.
	 *
	 * @since 11.4.0
	 */
	protected static function get_own_args(): array {
		return array(
			'label'        => __( 'Get timeline for payment intent', 'woocommerce' ),
			'description'  => __( 'Return the chronological event timeline for a payment intent (created → succeeded → refunded → disputed). Helps reconstruct what happened to one transaction. Takes `intention_id` (the same Stripe `pi_…` identifier accepted by `get-payment-intent` as `payment_intent_id` — both names exist because they mirror the underlying REST route parameters).', 'woocommerce' ),
			'input_schema' => self::get_id_input_schema( __( 'Stripe payment intent ID (typically `pi_…`). Same identifier accepted by `get-payment-intent` under the field name `payment_intent_id`. Stripe ID prefixes are not contractually stable, so this field is not pattern-validated.', 'woocommerce' ) ),
		);
	}
}
