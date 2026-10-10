<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\State;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Tests for the Options class.
 *
 * @group paypal-wallet
 */
class OptionsTest extends WalletTestCase {

	/**
	 * Run a reader with a cold options cache and record the SQL it runs.
	 *
	 * @param callable $reader The reader to run.
	 * @param mixed    $value  Receives what the reader returned.
	 * @return string[] The SQL statements the reader ran.
	 */
	private function record_queries_of( callable $reader, &$value ): array {
		wp_load_alloptions(); // WordPress loads the autoloaded options once per request, before any plugin code.
		wp_cache_delete( 'notoptions', 'options' ); // A fresh request without a persistent object cache knows of no missing option.
		$queries = array();
		$record  = static function ( $sql ) use ( &$queries ) {
			$queries[] = (string) $sql;
			return $sql;
		};
		add_filter( 'query', $record );

		try {
			$value = $reader();
		} finally {
			remove_filter( 'query', $record );
		}

		return $queries;
	}

	/**
	 * @testdox Should read a collecting or platform option that is not in the autoloaded set as empty, with no query.
	 */
	public function test_state_options_read_from_the_autoloaded_set_only(): void {
		$this->set_wallet_option( Options::COLLECTING, array( 'payee_email' => 'payee@example.com' ) );
		wp_set_option_autoload( Options::COLLECTING, false );

		$queries = $this->record_queries_of( array( new Options(), 'collecting' ), $value );

		$this->assertSame( array(), $value );
		$this->assertSame( array(), $queries );
	}

	/**
	 * @testdox Should read a platform option that is not in the autoloaded set as empty, with no query.
	 */
	public function test_the_platform_option_reads_from_the_autoloaded_set_only(): void {
		$this->set_wallet_option( Options::PLATFORM, array( 'merchant_id' => 'M2' ) );
		wp_set_option_autoload( Options::PLATFORM, false );

		$queries = $this->record_queries_of( array( new Options(), 'platform' ), $value );

		$this->assertSame( array(), $value );
		$this->assertSame( array(), $queries );
	}

	/**
	 * @testdox Should set the autoload flag back on every state option that exists outside the autoloaded set, and report their names.
	 */
	public function test_heal_autoload_restores_the_flag(): void {
		$this->set_wallet_option( Options::COLLECTING, array( 'payee_email' => 'payee@example.com' ) );
		$this->set_wallet_option( Options::FIRST_ORDER, 12 );
		wp_set_option_autoload( Options::COLLECTING, false );
		wp_set_option_autoload( Options::FIRST_ORDER, false );
		$sut = new Options();
		$this->assertFalse( $sut->has_autoloaded( Options::COLLECTING ) );

		$healed = $sut->heal_autoload();

		$this->assertSame( array( Options::COLLECTING, Options::FIRST_ORDER ), $healed );
		$this->assertTrue( $sut->has_autoloaded( Options::COLLECTING ), 'Seen in the same request' );
		$this->assertSame( 12, $sut->first_order_id() );
		$this->assertSame( ConnectionState::COLLECTING, ( new ConnectionState( $sut ) )->resolve() );
	}

	/**
	 * @testdox Should heal nothing, and write nothing, when the options are autoloaded or absent.
	 */
	public function test_heal_autoload_is_a_no_op_otherwise(): void {
		$this->assertSame( array(), ( new Options() )->heal_autoload(), 'Absent' );
		$this->set_wallet_option( Options::COLLECTING, array( 'payee_email' => 'payee@example.com' ) );
		$this->assertSame( array(), ( new Options() )->heal_autoload(), 'Autoloaded' );
	}
}
