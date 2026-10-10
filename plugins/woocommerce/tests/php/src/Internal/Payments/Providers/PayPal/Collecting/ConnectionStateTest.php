<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Tests for the ConnectionState class.
 *
 * @group paypal-wallet
 */
class ConnectionStateTest extends WalletTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var ConnectionState
	 */
	private $sut;

	/**
	 * Build the SUT.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new ConnectionState();
	}

	/**
	 * The first-party credentials of a connected merchant.
	 *
	 * @return array
	 */
	private function connected_data(): array {
		return array(
			'merchant_email'   => 'm@example.com',
			'merchant_id'      => 'M1',
			'client_id'        => 'id',
			'client_secret'    => 'secret',
			'sandbox_merchant' => true,
		);
	}

	/**
	 * The platform option of a connected merchant.
	 *
	 * @return array
	 */
	private function platform_data(): array {
		return array(
			'merchant_id'  => 'M2',
			'tracking_id'  => 't',
			'payee_email'  => 'p@example.com',
			'connected_at' => 1,
			'environment'  => 'sandbox',
		);
	}

	/**
	 * @testdox Should resolve to connected when first-party credentials exist, even with the platform and collecting options present.
	 */
	public function test_first_party_credentials_win_over_platform_and_collecting(): void {
		$this->set_wallet_option( 'woocommerce-ppcp-data-common', $this->connected_data() );
		$this->set_wallet_option( Options::PLATFORM, $this->platform_data() );
		$this->set_wallet_option( Options::COLLECTING, array( 'payee_email' => 'p@example.com' ) );

		$this->assertSame( ConnectionState::CONNECTED, $this->sut->resolve() );
		$this->assertFalse( $this->sut->is_served_by_platform(), 'A first-party store is not served by the platform' );
	}

	/**
	 * @testdox Should resolve to platform connected when only the platform option holds a merchant ID, even with a collecting option.
	 */
	public function test_platform_option_wins_over_collecting(): void {
		$this->set_wallet_option( Options::PLATFORM, $this->platform_data() );
		$this->set_wallet_option( Options::COLLECTING, array( 'payee_email' => 'p@example.com' ) );

		$this->assertSame( ConnectionState::PLATFORM_CONNECTED, $this->sut->resolve() );
		$this->assertTrue( $this->sut->is_served_by_platform() );
	}

	/**
	 * @testdox Should resolve to collecting when only the collecting option holds a payee email.
	 */
	public function test_collecting_option_alone_is_collecting(): void {
		$this->set_wallet_option( Options::COLLECTING, array( 'payee_email' => 'p@example.com' ) );

		$this->assertSame( ConnectionState::COLLECTING, $this->sut->resolve() );
		$this->assertTrue( $this->sut->is_served_by_platform() );
	}

	/**
	 * @testdox Should resolve to dormant when no option exists.
	 */
	public function test_no_options_is_dormant(): void {
		$this->assertSame( ConnectionState::DORMANT, $this->sut->resolve() );
		$this->assertFalse( $this->sut->is_served_by_platform() );
	}

	/**
	 * @testdox Should report a platform state from the platform and collecting options alone, ignoring first-party credentials.
	 */
	public function test_has_platform_state_ignores_first_party_credentials(): void {
		$this->set_wallet_option( 'woocommerce-ppcp-data-common', $this->connected_data() );
		$this->assertFalse( $this->sut->has_platform_state() );

		$this->set_wallet_option( Options::COLLECTING, array( 'payee_email' => 'p@example.com' ) );
		$this->assertTrue( $this->sut->has_platform_state() );
		$this->assertSame( ConnectionState::CONNECTED, $this->sut->resolve(), 'Precedence still puts first-party first' );
	}

	/**
	 * @testdox Should treat empty or malformed platform and collecting options as absent.
	 */
	public function test_empty_or_malformed_options_do_not_count(): void {
		$this->set_wallet_option( Options::PLATFORM, array( 'merchant_id' => '' ) );
		$this->set_wallet_option( Options::COLLECTING, 'not-an-array' );

		$this->assertSame( ConnectionState::DORMANT, $this->sut->resolve() );

		$this->set_wallet_option( Options::PLATFORM, array( 'merchant_id' => array( 'M2' ) ) );
		$this->set_wallet_option( Options::COLLECTING, array( 'payee_email' => '' ) );

		$this->assertSame( ConnectionState::DORMANT, $this->sut->resolve() );
	}

	/**
	 * @testdox Should not write any option while resolving, whether the options are present, malformed or absent.
	 *
	 * @testWith ["present"]
	 *           ["malformed"]
	 *           ["absent"]
	 *
	 * @param string $seed How the options are seeded.
	 */
	public function test_resolving_writes_nothing( string $seed ): void {
		if ( 'present' === $seed ) {
			$this->set_wallet_option( Options::COLLECTING, array( 'payee_email' => 'p@example.com' ) );
			$this->set_wallet_option( Options::PLATFORM, $this->platform_data() );
		} elseif ( 'malformed' === $seed ) {
			$this->set_wallet_option( Options::COLLECTING, 'not-an-array' );
			$this->set_wallet_option( Options::PLATFORM, array( 'tracking_id' => 't' ) );
		}
		$writes = 0;
		$count  = static function () use ( &$writes ) {
			++$writes;
		};
		foreach ( array( Options::COLLECTING, Options::PLATFORM ) as $name ) {
			add_action( 'add_option_' . $name, $count );
			add_action( 'delete_option_' . $name, $count );
		}
		$updates = array(
			$this->spy_filter( 'pre_update_option_' . Options::COLLECTING ),
			$this->spy_filter( 'pre_update_option_' . Options::PLATFORM ),
		);

		$this->sut->resolve();
		$this->sut->is_served_by_platform();
		$this->sut->has_platform_state();

		$this->assertSame( 0, $writes, 'Resolving must not add or delete an option' );
		$this->assertCount( 0, $updates[0], 'Resolving must not update the collecting option' );
		$this->assertCount( 0, $updates[1], 'Resolving must not update the platform option' );
	}
	/**
	 * @testdox Should resolve a store with neither option as dormant without a query, even when the options cache is cold.
	 */
	public function test_resolves_dormant_without_a_query_when_neither_option_exists(): void {
		delete_option( Options::COLLECTING );
		delete_option( Options::PLATFORM );
		wp_load_alloptions();
		wp_cache_delete( 'notoptions', 'options' ); // A fresh request without a persistent object cache knows of no missing option.
		$queries = array();
		$record  = static function ( $sql ) use ( &$queries ) {
			$queries[] = (string) $sql;
			return $sql;
		};
		add_filter( 'query', $record );

		try {
			$has_state = $this->sut->has_platform_state();
		} finally {
			remove_filter( 'query', $record );
		}

		$this->assertFalse( $has_state );
		$this->assertSame( array(), $queries, 'Neither option is in the autoloaded set, so nothing is queried' );
	}

	/**
	 * @testdox Should still read an autoloaded collecting or platform option.
	 */
	public function test_reads_the_autoloaded_options(): void {
		$this->set_wallet_option( Options::COLLECTING, array( 'payee_email' => 'p@example.com' ) );
		$this->assertSame( ConnectionState::COLLECTING, $this->sut->resolve() );

		$this->set_wallet_option( Options::PLATFORM, $this->platform_data() );
		$this->assertSame( ConnectionState::PLATFORM_CONNECTED, $this->sut->resolve() );
	}
	/**
	 * @testdox Should not query the platform option of a collecting store, which has none, with a cold options cache.
	 */
	public function test_a_collecting_store_does_not_query_the_absent_platform_option(): void {
		$this->set_wallet_option( Options::COLLECTING, array( 'payee_email' => 'p@example.com' ) );
		delete_option( Options::PLATFORM );
		wp_load_alloptions();
		wp_cache_delete( 'notoptions', 'options' );
		$queries = array();
		$record  = static function ( $sql ) use ( &$queries ) {
			$queries[] = (string) $sql;
			return $sql;
		};
		add_filter( 'query', $record );

		try {
			$state = $this->sut->resolve();
		} finally {
			remove_filter( 'query', $record );
		}

		$this->assertSame( ConnectionState::COLLECTING, $state );
		foreach ( $queries as $sql ) {
			$this->assertStringNotContainsString( Options::PLATFORM, $sql );
		}
	}

	/**
	 * @testdox Should read a collecting option that is stored but not autoloaded as dormant: the autoloaded set is the authority.
	 */
	public function test_an_option_that_is_not_autoloaded_reads_as_dormant(): void {
		$this->set_wallet_option( Options::COLLECTING, array( 'payee_email' => 'p@example.com' ) );
		wp_set_option_autoload( Options::COLLECTING, false );

		$this->assertSame( ConnectionState::DORMANT, $this->sut->resolve() );
	}
}
