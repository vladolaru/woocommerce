<?php
/**
 * Get disputes summary.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain;

/**
 * Get disputes summary.
 *
 * @since 11.4.0
 */
final class GetDisputesSummary extends AbstractAbility {
	protected const ROUTE = '/wc/v3/payments/disputes/summary';

	/**
	 * Get the public WordPress ability name.
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce-payments/get-disputes-summary';
	}

	/**
	 * Define the merchant-facing text.
	 *
	 * @since 11.4.0
	 */
	protected static function get_own_args(): array {
		return array(
			'label'        => __( 'Get disputes summary', 'woocommerce' ),
			'description'  => __( 'Return aggregate counts of disputes by status. Answers \'how many disputes are pending response right now?\'.', 'woocommerce' ),
			'input_schema' => array(
				'type'                 => 'object',
				'default'              => new \stdClass(),
				'properties'           => GetDisputes::get_filter_properties(),
				'additionalProperties' => false,
			),
		);
	}
}
