<?php
/**
 * List transactions.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain;

/**
 * List transactions.
 *
 * @since 11.4.0
 */
final class GetTransactions extends AbstractAbility {
	protected const ROUTE          = '/wc/v3/payments/transactions';
	protected const COLLECTION_KEY = 'transactions';

	/**
	 * Get the public WordPress ability name.
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce-payments/get-transactions';
	}

	/**
	 * Define the merchant-facing text.
	 *
	 * @since 11.4.0
	 */
	protected static function get_own_args(): array {
		return array(
			'label'        => __( 'List transactions', 'woocommerce' ),
			'description'  => __( 'List WooPayments transactions with filters (date range, type, source device, channel, customer country, risk level, currency, search). Answers \'show me transactions where X\'.', 'woocommerce' ),
			'input_schema' => array(
				'type'                 => 'object',
				'default'              => new \stdClass(),
				'properties'           => self::get_list_input_properties() + self::get_filter_properties(),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * Record selectors accepted by both transaction routes.
	 *
	 * @since 11.4.0
	 */
	public static function get_filter_properties(): array {
		$string = array( 'type' => 'string' );
		$list   = array(
			'type'  => 'array',
			'items' => $string,
		);
		return array(
			'match'                => $string + array( 'description' => __( 'Filter join mode (any|all).', 'woocommerce' ) ),
			'date_before'          => $string + array( 'description' => __( 'ISO-8601 date upper bound.', 'woocommerce' ) ),
			'date_after'           => $string + array( 'description' => __( 'ISO-8601 date lower bound.', 'woocommerce' ) ),
			'date_between'         => $list + array( 'description' => __( 'Two-element ISO date range [start, end].', 'woocommerce' ) ),
			'type_is'              => $string + array( 'description' => __( 'Transaction type filter (charge|refund|dispute|adjustment|…).', 'woocommerce' ) ),
			'source_device_is'     => $string,
			'channel_is'           => $string,
			'customer_country_is'  => $string,
			'risk_level_is'        => $string,
			'store_currency_is'    => $string,
			'customer_currency_is' => $string,
			'search'               => $list,
			'deposit_id'           => $string + array( 'description' => __( 'Filter to transactions belonging to a single payout (deposit) ID. Accepted by both the list and the summary endpoints.', 'woocommerce' ) ),
		);
	}
}
