<?php
/**
 * Tests for the product status result cache.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper;

use ArrayObject;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ProductStatusResultCache;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The cache of the seller's product statuses. It stores them in one real transient, so each case works against that
 * transient and a stored value really outlives the cache object that wrote it. Only the clock is replaced, so the
 * expiry cases do not have to wait.
 *
 * @group paypal-wallet
 */
class ProductStatusResultCacheTest extends WalletTestCase {

	private const TRANSIENT = 'woocommerce-ppcp-cache-product-status';

	/**
	 * Start from an empty transient and have the test base delete it afterwards.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->set_wallet_transient( self::TRANSIENT, array() );
	}

	/**
	 * A cache that reads its time from the given clock instead of the system time while the clock holds a time.
	 *
	 * @param ArrayObject $clock An ArrayObject whose "now" entry is the time to use, or 0 for the system time.
	 * @return ProductStatusResultCache
	 */
	private function make_cache( ArrayObject $clock ): ProductStatusResultCache {
		return new class( $clock ) extends ProductStatusResultCache {

			/**
			 * The clock.
			 *
			 * @var ArrayObject
			 */
			private ArrayObject $clock;

			/**
			 * Hold the clock.
			 *
			 * @param ArrayObject $clock The clock.
			 */
			public function __construct( ArrayObject $clock ) {
				$this->clock = $clock;
			}

			/**
			 * The time of the clock, or the system time when the clock is not set.
			 *
			 * @return int
			 */
			protected function get_time(): int {
				return $this->clock['now'] > 0 ? $this->clock['now'] : parent::get_time();
			}
		};
	}

	/**
	 * A clock that is not set, so the cache uses the system time.
	 *
	 * @return ArrayObject
	 */
	private function make_clock(): ArrayObject {
		return new ArrayObject( array( 'now' => 0 ) );
	}

	/**
	 * @testdox Should return an empty string for a key that was never stored.
	 */
	public function test_get_returns_empty_string_for_non_existent_key(): void {
		$testee = $this->make_cache( $this->make_clock() );

		$this->assertSame( '', $testee->get( 'non_existent_key' ) );
	}

	/**
	 * @testdox Should return the value that was stored.
	 */
	public function test_set_stores_value_and_get_retrieves_it(): void {
		$testee = $this->make_cache( $this->make_clock() );

		$testee->set( 'test_key', 'test_value' );

		$this->assertSame( 'test_value', $testee->get( 'test_key' ) );
	}

	/**
	 * @testdox Should forget a value that was cleared.
	 */
	public function test_clear_removes_value(): void {
		$testee = $this->make_cache( $this->make_clock() );

		$testee->set( 'test_key', 'test_value' );
		$testee->clear( 'test_key' );

		$this->assertSame( '', $testee->get( 'test_key' ) );
	}

	/**
	 * @testdox Should hand a stored value to another cache object, because the transient holds it.
	 */
	public function test_data_persists_across_instances(): void {
		$cache1 = $this->make_cache( $this->make_clock() );
		$cache1->set( 'test_key', 'test_value' );

		$cache2 = $this->make_cache( $this->make_clock() );

		$this->assertSame( 'test_value', $cache2->get( 'test_key' ) );
	}

	/**
	 * @testdox Should keep each key apart and let the last value of a key win.
	 */
	public function test_handles_multiple_keys_independently(): void {
		$testee = $this->make_cache( $this->make_clock() );

		$testee->set( 'key1', 'dummy' );
		$testee->set( 'key1', 'value1' );
		$testee->set( 'key2', 'value2' );
		$testee->set( 'key3', 'value3' );

		$this->assertSame( 'value1', $testee->get( 'key1' ) );
		$this->assertSame( 'value2', $testee->get( 'key2' ) );
		$this->assertSame( 'value3', $testee->get( 'key3' ) );
	}

	/**
	 * @testdox Should expire each key on its own lifetime.
	 */
	public function test_multiple_keys_with_different_expirations(): void {
		$clock  = new ArrayObject( array( 'now' => 1000 ) );
		$testee = $this->make_cache( $clock );

		$testee->set( 'short_ttl', 'value1', 30 );
		$testee->set( 'long_ttl', 'value2', 120 );

		$clock['now'] = 1050;

		$this->assertSame( '', $testee->get( 'short_ttl' ) );
		$this->assertSame( 'value2', $testee->get( 'long_ttl' ) );
	}

	/**
	 * Lifetimes, the time that passes, and what is left of the value afterwards.
	 *
	 * @return array<string, array{expiration: int, test_delay: int, value: string, expected_value: string}>
	 */
	public function data_expiration(): array {
		return array(
			'no delay'      => array(
				'expiration'     => 100,
				'test_delay'     => 0,
				'value'          => 'yes',
				'expected_value' => 'yes',
			),
			'short delay'   => array(
				'expiration'     => 100,
				'test_delay'     => 1,
				'value'          => 'yes',
				'expected_value' => 'yes',
			),
			'long delay'    => array(
				'expiration'     => 100,
				'test_delay'     => 99,
				'value'          => 'yes',
				'expected_value' => 'yes',
			),
			'full delay'    => array(
				'expiration'     => 100,
				'test_delay'     => 100,
				'value'          => 'yes',
				'expected_value' => 'yes',
			),
			'after delay'   => array(
				'expiration'     => 100,
				'test_delay'     => 101,
				'value'          => 'yes',
				'expected_value' => '',
			),
			'no expiration' => array(
				'expiration'     => 0,
				'test_delay'     => 999999,
				'value'          => 'permanent',
				'expected_value' => 'permanent',
			),
		);
	}

	/**
	 * @testdox Should return the value until its lifetime has passed, and a value without a lifetime for good.
	 * @dataProvider data_expiration
	 *
	 * @param int    $expiration     The lifetime in seconds, 0 for none.
	 * @param int    $test_delay     The seconds that pass before the value is read.
	 * @param string $value          The value.
	 * @param string $expected_value What the read returns.
	 */
	public function test_value_expiration_behavior( int $expiration, int $test_delay, string $value, string $expected_value ): void {
		$start_time = 1000;
		$clock      = new ArrayObject( array( 'now' => $start_time ) );
		$testee     = $this->make_cache( $clock );

		$testee->set( 'test_key', $value, $expiration );

		$clock['now'] = $start_time + $test_delay;

		$this->assertSame( $expected_value, $testee->get( 'test_key' ) );
	}

	/**
	 * Added for the real transient: what is stored is the plain array the cache keeps, one entry per key.
	 *
	 * @testdox Should write each entry into the transient with its value and the time it expires.
	 */
	public function test_set_writes_the_entry_into_the_transient(): void {
		$clock  = new ArrayObject( array( 'now' => 1000 ) );
		$testee = $this->make_cache( $clock );

		$testee->set( 'test_key', 'test_value', 100 );

		$this->assertSame(
			array(
				'test_key' => array(
					'value'      => 'test_value',
					'expires_at' => 1100,
				),
			),
			get_transient( self::TRANSIENT )
		);
	}

	/**
	 * Added for the real transient: another request, or an older version of the code, may have stored entries as objects.
	 *
	 * @testdox Should read entries that another request stored, as arrays or as objects.
	 */
	public function test_get_reads_entries_stored_as_arrays_and_objects(): void {
		$this->set_wallet_transient(
			self::TRANSIENT,
			array(
				'as_array'  => array(
					'value'      => 'from array',
					'expires_at' => 0,
				),
				'as_object' => (object) array(
					'value'      => 'from object',
					'expires_at' => 0,
				),
			)
		);

		$testee = $this->make_cache( $this->make_clock() );

		$this->assertSame( 'from array', $testee->get( 'as_array' ) );
		$this->assertSame( 'from object', $testee->get( 'as_object' ) );
	}

	/**
	 * Added for the real transient: an expired value is not only hidden, it is removed from what is stored.
	 *
	 * @testdox Should remove an expired entry from the transient when it is read.
	 */
	public function test_get_removes_an_expired_entry_from_the_transient(): void {
		$clock  = new ArrayObject( array( 'now' => 1000 ) );
		$testee = $this->make_cache( $clock );
		$testee->set( 'test_key', 'test_value', 10 );

		$clock['now'] = 1011;

		$this->assertSame( '', $testee->get( 'test_key' ) );
		$this->assertSame( array(), get_transient( self::TRANSIENT ) );
	}
}
