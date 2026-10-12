<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\State;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CryptoCheckMarker;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FixedHeldOrders;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface\HoldsWalletState;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Tests for the Pay with Crypto check's stored answer: written for the platform merchant, counted only while it matches
 * the platform option, and deleted with the collecting state.
 *
 * @group paypal-wallet
 */
class CryptoCheckMarkerTest extends WalletTestCase {
	use HoldsWalletState;

	/**
	 * The System Under Test.
	 *
	 * @var CryptoCheckMarker
	 */
	private CryptoCheckMarker $sut;

	/**
	 * Build the SUT over the stored options.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new CryptoCheckMarker( new Options() );
	}

	/**
	 * @testdox Should record the answer for the platform merchant and environment, autoloaded, and read it back.
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $available The answer.
	 */
	public function test_records_and_reads_the_answer( bool $available ): void {
		$this->set_platform_connected();

		$this->assertTrue( $this->sut->record( $available, 'sandbox' ) );

		$stored = get_option( Options::PWC_CHECK );
		$this->assertSame( array( 'available', 'checked_at', 'environment', 'merchant_id' ), array_keys( $stored ) );
		$this->assertSame( $available, $stored['available'] );
		$this->assertEqualsWithDelta( time(), $stored['checked_at'], 5 );
		$this->assertSame( 'sandbox', $stored['environment'] );
		$this->assertSame( 'M2', $stored['merchant_id'] );
		$this->assertArrayHasKey( Options::PWC_CHECK, wp_load_alloptions(), 'Autoloaded' );
		$this->assertSame( $stored, $this->sut->current() );
		$this->assertSame( $available, $this->sut->is_available() );
	}

	/**
	 * @testdox Should write nothing without a platform merchant and environment.
	 */
	public function test_writes_nothing_without_a_platform_merchant(): void {
		$this->set_collecting();

		$this->assertFalse( $this->sut->record( true, 'sandbox' ) );

		$this->assertFalse( get_option( Options::PWC_CHECK ) );
	}

	/**
	 * @testdox Should write nothing for an answer from another environment than the platform connection's.
	 */
	public function test_writes_nothing_for_another_environment(): void {
		$this->set_platform_connected();

		$this->assertFalse( $this->sut->record( true, 'production' ) );

		$this->assertFalse( get_option( Options::PWC_CHECK ) );
	}

	/**
	 * @testdox Should name the platform merchant and environment, or empty strings without a platform option.
	 */
	public function test_names_the_platform_merchant_and_environment(): void {
		$this->assertSame( '', $this->sut->platform_merchant_id() );
		$this->assertSame( '', $this->sut->platform_environment() );

		$this->set_platform_connected();

		$this->assertSame( 'M2', $this->sut->platform_merchant_id() );
		$this->assertSame( 'sandbox', $this->sut->platform_environment() );
	}

	/**
	 * @testdox Should read no answer, and write nothing, once the stored one does not match the platform option: $scenario.
	 * @testWith ["another merchant"]
	 *           ["another environment"]
	 *           ["no platform option"]
	 *           ["malformed"]
	 *
	 * @param string $scenario The scenario.
	 */
	public function test_a_mismatched_answer_reads_as_none( string $scenario ): void {
		if ( 'no platform option' !== $scenario ) {
			$this->set_platform_connected();
		}
		$stored = array(
			'available'   => true,
			'checked_at'  => 1,
			'environment' => 'another environment' === $scenario ? 'production' : 'sandbox',
			'merchant_id' => 'another merchant' === $scenario ? 'OTHER' : 'M2',
		);
		if ( 'malformed' === $scenario ) {
			$stored['available'] = 'yes';
		}
		$this->set_wallet_option( Options::PWC_CHECK, $stored );

		$this->assertNull( $this->sut->current() );
		$this->assertFalse( $this->sut->is_available() );
		$this->assertSame( $stored, get_option( Options::PWC_CHECK ), 'Nothing is written' );
	}

	/**
	 * @testdox Should delete the answer with the collecting state.
	 */
	public function test_is_deleted_with_the_collecting_state(): void {
		$this->set_platform_connected();
		$this->sut->record( true, 'sandbox' );

		( new CollectingState( new Options(), new FixedHeldOrders( 0 ) ) )->abandon( CollectingState::ABANDON_FIRST_PARTY );

		$this->assertFalse( get_option( Options::PWC_CHECK ) );
	}
}
