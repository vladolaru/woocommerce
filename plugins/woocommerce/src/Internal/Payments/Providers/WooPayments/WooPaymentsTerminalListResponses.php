<?php
/**
 * WooPaymentsTerminalListResponses class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Utilities\ArrayUtil;

/**
 * Reads the items of a list the platform answers for card readers and terminal locations, and of the receipt lists.
 *
 * @since 11.2.0
 * @internal
 */
final class WooPaymentsTerminalListResponses {

	/**
	 * Extract a list from common WPCOM collection envelopes.
	 *
	 * @param array<string,mixed> $response Response.
	 * @return array<int,array<string,mixed>>
	 */
	public static function items( array $response ): array {
		foreach ( array( 'data', 'readers', 'locations', 'terminal_locations' ) as $key ) {
			if ( isset( $response[ $key ] ) && is_array( $response[ $key ] ) ) {
				return self::array_items( $response[ $key ] );
			}
		}

		return ArrayUtil::array_is_list( $response ) ? self::array_items( $response ) : array();
	}

	/**
	 * Normalize list items to arrays.
	 *
	 * @param array<mixed> $items Items.
	 * @return array<int,array<string,mixed>>
	 */
	public static function array_items( array $items ): array {
		return array_values(
			array_filter(
				$items,
				static function ( $item ): bool {
					return is_array( $item );
				}
			)
		);
	}
}
