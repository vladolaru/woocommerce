<?php
/**
 * List authorizations.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain;

/**
 * List authorizations.
 *
 * @since 11.4.0
 */
final class GetAuthorizations extends AbstractAbility {
	protected const ROUTE          = '/wc/v3/payments/authorizations';
	protected const COLLECTION_KEY = 'authorizations';

	/**
	 * Get the public WordPress ability name.
	 *
	 * @since 11.4.0
	 */
	public static function get_name(): string {
		return 'woocommerce-payments/get-authorizations';
	}

	/**
	 * Define the merchant-facing text and list query.
	 *
	 * @since 11.4.0
	 */
	protected static function get_own_args(): array {
		return array(
			'label'        => __( 'List authorizations', 'woocommerce' ),
			'description'  => __( 'List uncaptured card authorizations. Answers \'which authorizations expire soon / still need capture?\'.', 'woocommerce' ),
			'input_schema' => array(
				'type'                 => 'object',
				'default'              => new \stdClass(),
				'properties'           => self::get_list_input_properties(),
				'additionalProperties' => false,
			),
		);
	}
}
