<?php
/**
 * Get payouts summary.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain;

/**
 * Get payouts summary.
 *
 * @since 11.4.0
 */
final class GetDepositsSummary extends AbstractAbility {
	protected const ROUTE = '/wc/v3/payments/deposits/summary';

	/**
	 * Get the public WordPress ability name.
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce-payments/get-deposits-summary';
	}

	/**
	 * Define the merchant-facing text.
	 *
	 * @since 11.4.0
	 */
	protected static function get_own_args(): array {
		return array(
			'label'        => __( 'Get payouts summary', 'woocommerce' ),
			'description'  => __( 'Return aggregate counts and totals of payouts by status and currency. Answers \'how many payouts have I received this month?\'.', 'woocommerce' ),
			'input_schema' => array(
				'type'                 => 'object',
				'default'              => new \stdClass(),
				'properties'           => GetDeposits::get_filter_properties(),
				'additionalProperties' => false,
			),
		);
	}
}
