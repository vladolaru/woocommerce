<?php
/**
 * WooPayments ability registration lifecycle.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Abilities;

use Automattic\WooCommerce\Internal\Abilities\Domain\ProductsQuery;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;

/** Registration timing, opt-in and ownership. */
class AbilitiesRegistrarTest extends AbilityTestCase {
	private const CLASS_FILTER = 'woocommerce_ability_definition_classes';

	/**
	 * @testdox Reading the opt-in at boot misses a later opt-in, and limiting registration to web requests hides all reads from CLI.
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
	 * @testdox Enabling the filter by default exposes payment reads before the store opts in.
	 */
	public function test_default_gate(): void {
		$this->install_root();
		$prior = array( ProductsQuery::class );
		$this->assertSame( $prior, apply_filters( self::CLASS_FILTER, $prior ) );
		$this->assertSame( array(), $this->provider_names() );
	}

	/**
	 * @testdox Treating zero as false hides the reads, and replacing the class list removes an earlier definition.
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
			Domain\GetDisputes::class,
			Domain\GetDisputesSummary::class,
			Domain\GetDispute::class,
			Domain\GetCharge::class,
			Domain\GetPaymentIntent::class,
			Domain\GetTimeline::class,
			Domain\GetDeposits::class,
			Domain\GetDepositsSummary::class,
		);
		add_filter( self::GATE, static fn() => $value );
		$this->assertSame( $expected, apply_filters( self::CLASS_FILTER, $prior ) );
	}

	/**
	 * @testdox Merging a non-array value loses the earlier filter’s result.
	 */
	public function test_non_array_filter(): void {
		$this->install_root();
		add_filter( self::GATE, '__return_true' );
		$sentinel = new \stdClass();
		add_filter( self::CLASS_FILTER, static fn() => $sentinel, 5 );
		$this->assertSame( $sentinel, apply_filters( self::CLASS_FILTER, array() ) );
	}

	/**
	 * @testdox Loading the registrar on an available store publishes reads without their routes.
	 */
	public function test_available_store(): void {
		$this->assert_registry_absent( WooPaymentsSetupTier::AVAILABLE );
	}

	/**
	 * @testdox Loading the registrar on a disabled store publishes reads without their routes.
	 */
	public function test_disabled_store(): void {
		$this->assert_registry_absent( WooPaymentsSetupTier::DISABLED );
	}

	/**
	 * @testdox Using the stored active tier publishes built-in reads on an extension-owned store.
	 */
	public function test_extension_owner(): void {
		$this->assert_registry_absent( WooPaymentsSetupTier::ACTIVE, true );
	}

	/**
	 * @testdox Reading the main site’s connection state hides reads on connected subsites or exposes them on unconnected ones.
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
	 * @testdox Keeping the bootstrap site’s tier or opt-in gives the wrong abilities after switching sites.
	 * @group multisite
	 * @dataProvider switched_discovery_sites
	 *
	 * @param string $tier             Stored tier on the discovery site.
	 * @param bool   $extension_active Whether the extension owns payments there.
	 * @param bool   $opt_in           The discovery site's opt-in.
	 * @param array  $expected         Expected WooPayments names.
	 */
	public function test_discovery_after_switch( string $tier, bool $extension_active, bool $opt_in, array $expected ): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires two sites on a network.' );
		}

		$blog_id    = self::factory()->blog->create();
		$gate_sites = array();
		add_filter(
			self::GATE,
			static function () use ( &$gate_sites, $blog_id, $opt_in ) {
				$gate_sites[] = get_current_blog_id();
				return get_current_blog_id() === $blog_id ? $opt_in : ! $opt_in;
			}
		);
		$this->install_root();
		switch_to_blog( $blog_id );
		try {
			update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
			update_option( WooPaymentsSetupTier::OPTION_NAME, $tier );
			update_option( 'active_plugins', $extension_active ? array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) : array() );
			$this->assertSame( $expected, $this->provider_names() );
			$this->assertSame( array( $blog_id ), $gate_sites );
		} finally {
			restore_current_blog();
			wpmu_delete_blog( $blog_id, true );
		}
	}

	/**
	 * @testdox Execution trusts a registry built on another site when it skips the current site's opt-in or setup tier.
	 * @group multisite
	 * @testWith [false]
	 *           [true]
	 *
	 * @param bool $opt_in Whether the second site opts in despite being unconnected.
	 */
	public function test_execution_after_switch( bool $opt_in ): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires two sites on a network.' );
		}

		$blog_id = self::factory()->blog->create();
		add_filter( self::GATE, static fn() => get_current_blog_id() !== $blog_id || $opt_in );
		$this->install_root();
		$ability = wp_get_ability( self::PREFIX . 'get-account' );
		$this->assertTrue( $ability->check_permissions() );

		add_user_to_blog( $blog_id, get_current_user_id(), 'administrator' );
		switch_to_blog( $blog_id );
		try {
			update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
			update_option( WooPaymentsSetupTier::OPTION_NAME, $opt_in ? WooPaymentsSetupTier::AVAILABLE : WooPaymentsSetupTier::CONNECTED );
			wp_get_current_user()->add_cap( 'manage_woocommerce' );
			$this->assertTrue( current_user_can( 'manage_woocommerce' ) );
			add_filter( 'rest_pre_dispatch', static fn() => new \WP_Error( 'unexpected_dispatch', 'A retained ability reached REST.' ) );
			$error = $ability->execute( array() );
			$this->assertWPError( $error );
			$this->assertSame( 'ability_invalid_permissions', $error->get_error_code() );
		} finally {
			restore_current_blog();
			wpmu_delete_blog( $blog_id, true );
		}
	}

	/**
	 * @testdox A read discovered on a connected single site refuses to run after the account disconnects.
	 */
	public function test_retained_read_after_disconnect(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Requires a single site.' );
		}

		add_filter( self::GATE, '__return_true' );
		$this->install_root();
		$ability = wp_get_ability( self::PREFIX . 'get-account' );
		$this->assertInstanceOf( \WP_Ability::class, $ability );
		$this->assertTrue( $ability->check_permissions() );

		$tier = wc_get_container()->get( WooPaymentsSetupTier::class );
		try {
			wc_get_container()->get( WooPaymentsAccountService::class )->overwrite_cache_with_no_account();
			$this->assertSame( WooPaymentsSetupTier::AVAILABLE, $tier->get_effective_tier() );
			add_filter( 'rest_pre_dispatch', static fn() => new \WP_Error( 'unexpected_dispatch', 'A disconnected read reached REST.' ) );
			$error = $ability->execute( array() );
			$this->assertWPError( $error );
			$this->assertSame( 'ability_invalid_permissions', $error->get_error_code() );
		} finally {
			$tier->invalidate();
		}
	}

	/**
	 * @testdox A retained read refuses on a disconnected subsite and runs again when the connected site is restored.
	 * @group multisite
	 */
	public function test_retained_read_after_restoring_connected_site(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires two sites on a network.' );
		}

		add_filter( self::GATE, '__return_true' );
		$this->install_root();
		$ability = wp_get_ability( self::PREFIX . 'get-account' );
		$this->assertInstanceOf( \WP_Ability::class, $ability );
		$blog_id = self::factory()->blog->create();
		$this->assertIsInt( $blog_id );
		add_user_to_blog( $blog_id, get_current_user_id(), 'administrator' );
		add_filter( 'rest_pre_dispatch', static fn() => new \WP_REST_Response( array( 'blog_id' => get_current_blog_id() ) ) );
		switch_to_blog( $blog_id );
		try {
			update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
			$this->assertTrue( wc_get_container()->get( WooPaymentsSetupTier::class )->write_tier( WooPaymentsSetupTier::AVAILABLE ) );
			wp_get_current_user()->add_cap( 'manage_woocommerce' );
			$this->assertTrue( current_user_can( 'manage_woocommerce' ) );
			$error = $ability->execute( array() );
			$this->assertWPError( $error );
			$this->assertSame( 'ability_invalid_permissions', $error->get_error_code() );
		} finally {
			restore_current_blog();
			wpmu_delete_blog( $blog_id, true );
		}

		$this->assertSame( array( 'blog_id' => get_current_blog_id() ), $ability->execute( array() ) );
	}

	/** Destination site configurations for first registry discovery after switching blogs. */
	public static function switched_discovery_sites(): array {
		return array(
			'unconnected site inherits bootstrap eligibility' => array( WooPaymentsSetupTier::AVAILABLE, false, true, array() ),
			'stored active tier hides extension ownership' => array( WooPaymentsSetupTier::ACTIVE, true, true, array() ),
			'bootstrap opt-out hides connected site'       => array( WooPaymentsSetupTier::CONNECTED, false, true, self::NAMES ),
			'bootstrap opt-in exposes opted-out active site' => array( WooPaymentsSetupTier::ACTIVE, false, false, array() ),
		);
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
