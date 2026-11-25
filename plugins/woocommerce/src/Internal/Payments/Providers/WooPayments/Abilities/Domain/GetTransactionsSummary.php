<?php
/**
 * Get transactions summary.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain;

/**
 * Get transactions summary.
 *
 * @since 11.4.0
 */
final class GetTransactionsSummary extends AbstractAbility {
	protected const ROUTE = '/wc/v3/payments/transactions/summary';

	/**
	 * Get the public WordPress ability name.
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce-payments/get-transactions-summary';
	}

	/**
	 * Define the merchant-facing text.
	 *
	 * @since 11.4.0
	 */
	protected static function get_own_args(): array {
		return array(
			'label'        => __( 'Get transactions summary', 'woocommerce' ),
			'description'  => __( 'Return aggregate counts and totals for a transactions filter. Answers \'how many transactions / how much volume in this window?\' without paging the list.', 'woocommerce' ),
			'input_schema' => array(
				'type'                 => 'object',
				'default'              => new \stdClass(),
				'properties'           => GetTransactions::get_filter_properties(),
				'additionalProperties' => false,
			),
		);
	}
}
