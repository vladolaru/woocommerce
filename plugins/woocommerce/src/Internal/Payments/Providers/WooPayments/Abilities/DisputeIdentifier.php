<?php
/**
 * Dispute ID check shared by the WooPayments dispute abilities.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities;

/**
 * Tell whether a caller's dispute ID can be placed in a dispute route path.
 *
 * @since 11.4.0
 */
final class DisputeIdentifier {
	/** Fixed route names under the store's own disputes REST base, matched before the dispute ID route. */
	private const ROUTE_NAMES = array( 'summary', 'download' );

	/**
	 * Fixed route names under the platform's disputes path, served before a dispute ID there. Listed from the routes the
	 * platform's disputes controller registers.
	 */
	private const PLATFORM_ROUTE_NAMES = array( 'summary', 'status_counts', 'download' );

	/**
	 * Whether the ID holds only letters, digits and underscores and is none of the fixed route names, in any case.
	 *
	 * @since 11.4.0
	 *
	 * @param string $dispute_id Caller's dispute ID.
	 */
	public static function is_accepted( string $dispute_id ): bool {
		return 1 === preg_match( '/\A\w+\z/', $dispute_id )
			&& ! in_array( strtolower( $dispute_id ), array_merge( self::ROUTE_NAMES, self::PLATFORM_ROUTE_NAMES ), true );
	}
}
