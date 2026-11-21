<?php
/**
 * Subscription relationships registry that reports each lookup.
 *
 * @package WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures;

/**
 * Stands in for the `ORDER_SUBSCRIPTIONS` registry of `WooCommerceSubscriptionsDoubles`, so a test sees each
 * `wcs_get_subscriptions_for_order()` lookup when it happens and answers it.
 *
 * @implements \ArrayAccess<int,array<string,array<int,int>>>
 */
final class SubscriptionRelationshipsProbe implements \ArrayAccess {

	/**
	 * Called with the order ID of each lookup; returns that order's relationships keyed by relation type.
	 *
	 * @var callable
	 * @phpstan-var callable(int): array<string,array<int,int>>
	 */
	private $on_lookup;

	/**
	 * Constructor.
	 *
	 * @param callable $on_lookup Called with the order ID of each lookup; returns that order's relationships.
	 * @phpstan-param callable(int): array<string,array<int,int>> $on_lookup
	 */
	public function __construct( callable $on_lookup ) {
		$this->on_lookup = $on_lookup;
	}

	/**
	 * Every order may have relationships; the lookup decides.
	 *
	 * @param mixed $offset Order ID.
	 * @return bool
	 */
	public function offsetExists( $offset ): bool {
		unset( $offset );

		return true;
	}

	/**
	 * Report the lookup and return the order's relationships.
	 *
	 * @param mixed $offset Order ID.
	 * @return array<string,array<int,int>>
	 */
	#[\ReturnTypeWillChange]
	public function offsetGet( $offset ) {
		return ( $this->on_lookup )( absint( $offset ) );
	}

	/**
	 * The registry is read-only.
	 *
	 * @param mixed $offset Order ID.
	 * @param mixed $value  Relationships.
	 */
	public function offsetSet( $offset, $value ): void {
		unset( $offset, $value );
	}

	/**
	 * The registry is read-only.
	 *
	 * @param mixed $offset Order ID.
	 */
	public function offsetUnset( $offset ): void {
		unset( $offset );
	}
}
