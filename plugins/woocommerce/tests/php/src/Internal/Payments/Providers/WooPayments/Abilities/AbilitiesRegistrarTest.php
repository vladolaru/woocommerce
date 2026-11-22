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

	/**
	 * Reading the opt-in during register misses a late opt-in. A misspelled hook or changed default breaks existing opt-ins.
	 *
	 * @testdox An opt-in added after WooPayments boots takes effect when the registry builds.
	 */
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

	/**
	 * A true default exposes all ten reads without opt-in.
	 *
	 * @testdox WooPayments leaves existing definitions alone until a store opts in.
	 */
	public function test_default_gate(): void {
		$this->install_root();
		$prior = array( ProductsQuery::class );
		$this->assertSame( $prior, apply_filters( self::CLASS_FILTER, $prior ) );
		$this->assertSame( array(), $this->provider_names() );
	}

	/**
	 * A truthiness check loses the zero opt-in, and replacing the list loses earlier providers.
	 *
	 * @testdox Each opt-in value except literal false appends the ten reads after existing definitions.
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
		$expected = array(
			ProductsQuery::class,
			Domain\GetAccount::class,
			Domain\GetDepositsOverview::class,
			Domain\GetActiveLoanSummary::class,
			Domain\GetAuthorizations::class,
			Domain\GetAuthorizationsSummary::class,
			Domain\GetTransactions::class,
			Domain\GetTransactionsSummary::class,
			Domain\GetFraudOutcomes::class,
			Domain\GetBalance::class,
			Domain\GetFeesSummary::class,
		);
		add_filter( self::GATE, static fn() => $value );
		$this->assertSame( $expected, apply_filters( self::CLASS_FILTER, $prior ) );
	}

	/**
	 * Merging a non-array sentinel loses the earlier filter's result.
	 *
	 * @testdox The registrar preserves a non-array value from an earlier filter.
	 */
	public function test_non_array_filter(): void {
		$this->install_root();
		add_filter( self::GATE, '__return_true' );
		$sentinel = new \stdClass();
		add_filter( self::CLASS_FILTER, static fn() => $sentinel, 5 );
		$this->assertSame( $sentinel, apply_filters( self::CLASS_FILTER, array() ) );
	}

	/**
	 * The all-tier cutover loop leaks reads onto an available store.
	 *
	 * @testdox An available store exposes no built-in WooPayments abilities.
	 */
	public function test_available_store(): void {
		$this->assert_registry_absent( WooPaymentsSetupTier::AVAILABLE );
	}

	/**
	 * Registering the root unconditionally exposes reads on a disabled store.
	 *
	 * @testdox A disabled store exposes no built-in WooPayments abilities.
	 */
	public function test_disabled_store(): void {
		$this->assert_registry_absent( WooPaymentsSetupTier::DISABLED );
	}

	/**
	 * Selecting the stored active tier ignores extension ownership.
	 *
	 * @testdox An extension-owned store with a stored active tier exposes no built-in WooPayments abilities.
	 */
	public function test_extension_owner(): void {
		$this->assert_registry_absent( WooPaymentsSetupTier::ACTIVE, true );
	}

	/**
	 * Reading the main site's tier can expose abilities on an unconnected subsite or hide them on a connected one.
	 *
	 * @testdox Discovery follows the subsite's own connection state on a network.
	 * @group multisite
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $connected Whether the subsite is connected.
	 */
	public function test_subsite_discovery( bool $connected ): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires two sites on a network.' );
		}

		update_option( WooPaymentsSetupTier::OPTION_NAME, $connected ? WooPaymentsSetupTier::AVAILABLE : WooPaymentsSetupTier::CONNECTED );
		$blog_id = self::factory()->blog->create();
		switch_to_blog( $blog_id );
		try {
			add_filter( self::GATE, '__return_true' );
			$this->install_root( $connected ? WooPaymentsSetupTier::CONNECTED : WooPaymentsSetupTier::AVAILABLE );
			$this->assertSame( $connected ? self::NAMES : array(), $this->provider_names() );
		} finally {
			restore_current_blog();
			wpmu_delete_blog( $blog_id, true );
		}
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
