<?php
/**
 * WooPayments ability registration lifecycle.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Abilities;

use Automattic\WooCommerce\Internal\Abilities\Domain\ProductsQuery;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;

/** Registration timing, opt-in and ownership. */
class AbilitiesRegistrarTest extends AbilityTestCase {
	private const CLASS_FILTER = 'woocommerce_ability_definition_classes';

	/** Reading the opt-in during register misses a late opt-in. A misspelled hook or changed default breaks existing opt-ins. */
	public function test_late_opt_in(): void {
		$calls = 0;
		add_filter(
			self::GATE,
			function ( $enabled ) use ( &$calls ) {
				$this->assertFalse( $enabled );
				++$calls;
				return $enabled;
			}
		);
		$this->install_root();
		$this->assertSame( 0, $calls );
		add_filter( self::GATE, '__return_true' );
		$this->assertSame( self::NAMES, $this->provider_names() );
		$this->assertSame( 1, $calls );
	}

	/** A true default exposes all five reads without opt-in. */
	public function test_default_gate(): void {
		$this->install_root();
		$prior = array( ProductsQuery::class );
		$this->assertSame( $prior, apply_filters( self::CLASS_FILTER, $prior ) );
		$this->assertSame( array(), $this->provider_names() );
	}

	/**
	 * A truthiness check loses the zero opt-in, and replacing the list loses earlier providers.
	 *
	 * @testWith [true]
	 *           [1]
	 *           ["yes"]
	 *           [0]
	 *
	 * @param mixed $value Value returned by the opt-in filter.
	 */
	public function test_opt_in_values( $value ): void {
		$this->install_root();
		$prior    = array( ProductsQuery::class );
		$expected = array_merge( $prior, array( Domain\GetAccount::class, Domain\GetDepositsOverview::class, Domain\GetActiveLoanSummary::class, Domain\GetAuthorizations::class, Domain\GetAuthorizationsSummary::class ) );
		add_filter( self::GATE, static fn() => $value );
		$this->assertSame( $expected, apply_filters( self::CLASS_FILTER, $prior ) );
	}

	/** Merging a non-array sentinel loses the earlier filter's result. */
	public function test_non_array_filter(): void {
		$this->install_root();
		add_filter( self::GATE, '__return_true' );
		$sentinel = new \stdClass();
		add_filter( self::CLASS_FILTER, static fn() => $sentinel, 5 );
		$this->assertSame( $sentinel, apply_filters( self::CLASS_FILTER, array() ) );
	}

	/** The all-tier cutover loop leaks reads onto an available store. */
	public function test_available_store(): void {
		$this->assert_registry_absent( WooPaymentsSetupTier::AVAILABLE );
	}

	/** Registering the root unconditionally exposes reads on a disabled store. */
	public function test_disabled_store(): void {
		$this->assert_registry_absent( WooPaymentsSetupTier::DISABLED );
	}

	/** Selecting the stored active tier ignores extension ownership. */
	public function test_extension_owner(): void {
		$this->assert_registry_absent( WooPaymentsSetupTier::ACTIVE, true );
	}

	/**
	 * Check both individual names and any extra registrations under the prefix.
	 *
	 * @param string $tier             Stored setup tier.
	 * @param bool   $extension_active Whether the extension owns payments.
	 */
	private function assert_registry_absent( string $tier, bool $extension_active = false ): void {
		add_filter( self::GATE, '__return_true' );
		$this->install_root( $tier, $extension_active );
		foreach ( self::NAMES as $name ) {
			$this->assertFalse( wp_has_ability( self::PREFIX . $name ), $name );
		}
		$this->assertSame( array(), $this->provider_names() );
	}
}
