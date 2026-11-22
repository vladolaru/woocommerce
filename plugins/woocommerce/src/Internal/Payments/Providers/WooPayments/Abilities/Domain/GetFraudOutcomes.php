<?php
/**
 * List flagged (fraud outcome) transactions.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\FraudOutcomeTransactionsListRequest;

/**
 * List flagged (fraud outcome) transactions.
 *
 * @since 11.4.0
 */
final class GetFraudOutcomes extends AbstractAbility {
	protected const ROUTE          = '/wc/v3/payments/transactions/fraud-outcomes';
	protected const COLLECTION_KEY = 'fraud_outcomes';

	/**
	 * Get the public WordPress ability name.
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce-payments/get-fraud-outcomes';
	}

	/**
	 * Define the merchant-facing text.
	 *
	 * @since 11.4.0
	 */
	protected static function get_own_args(): array {
		return array(
			'label'        => __( 'List flagged (fraud outcome) transactions', 'woocommerce' ),
			'description'  => __( 'List transactions flagged by fraud protection for a given outcome status (e.g. block, review).', 'woocommerce' ),
			'input_schema' => array(
				'type'                 => 'object',
				'required'             => array( 'status' ),
				'properties'           => self::get_list_input_properties() + array(
					'status'      => array(
						'type'        => 'string',
						'enum'        => FraudOutcomeTransactionsListRequest::STATUSES,
						'description' => __( 'Fraud outcome status to filter by (e.g. block, review).', 'woocommerce' ),
					),
					'search'      => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'search_term' => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
			),
		);
	}
}
