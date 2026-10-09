<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface\Dismissals;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Tests for the per-user dismissals of the PayPal Wallet setup surfaces.
 *
 * @group paypal-wallet
 */
class DismissalsTest extends WalletTestCase {
	use HoldsWalletState;

	/**
	 * The System Under Test.
	 *
	 * @var Dismissals
	 */
	private $sut;

	/**
	 * The user who dismisses.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Create a user.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut     = new Dismissals();
		$this->user_id = self::factory()->user->create();
	}

	/**
	 * @testdox Should store the latest order ID seen in the surface's user meta key.
	 */
	public function test_dismiss_stores_the_latest_order_id(): void {
		$this->assertTrue( $this->sut->dismiss( 'row-notice', $this->user_id, 41 ) );

		$this->assertSame( '41', get_user_meta( $this->user_id, 'wc_paypal_wallet_dismissed_row-notice', true ) );
	}

	/**
	 * @testdox Should fall back to the first-order ID when no order ID is given.
	 */
	public function test_dismiss_falls_back_to_the_first_order(): void {
		$this->set_first_order( 17 );

		$this->sut->dismiss( 'row-notice', $this->user_id );

		$this->assertSame( '17', get_user_meta( $this->user_id, 'wc_paypal_wallet_dismissed_row-notice', true ) );
	}

	/**
	 * @testdox Should treat a surface as dismissed until a newer order arrives: dismissed at $stored, asked since $since.
	 * @testWith [41, 41, true]
	 *           [41, 40, true]
	 *           [41, 42, false]
	 *
	 * @param int  $stored   The order ID stored at dismissal.
	 * @param int  $since    The latest order ID the surface knows of.
	 * @param bool $expected Whether it is still dismissed.
	 */
	public function test_is_dismissed_until_a_newer_order_arrives( int $stored, int $since, bool $expected ): void {
		$this->sut->dismiss( 'row-notice', $this->user_id, $stored );

		$this->assertSame( $expected, $this->sut->is_dismissed( 'row-notice', $this->user_id, $since ) );
	}

	/**
	 * @testdox Should keep dismissals apart per surface and per user.
	 */
	public function test_dismissals_are_per_surface_and_per_user(): void {
		$other = self::factory()->user->create();
		$this->sut->dismiss( 'row-notice', $this->user_id, 5 );

		$this->assertFalse( $this->sut->is_dismissed( 'plugins-notice', $this->user_id, 5 ), 'Another surface is not dismissed' );
		$this->assertFalse( $this->sut->is_dismissed( 'row-notice', $other, 5 ), 'Another user has not dismissed' );
	}

	/**
	 * @testdox Should store nothing and report not dismissed for a missing user or an empty surface.
	 */
	public function test_ignores_a_missing_user_or_surface(): void {
		$this->assertFalse( $this->sut->dismiss( 'row-notice', 0, 5 ) );
		$this->assertFalse( $this->sut->dismiss( '', $this->user_id, 5 ) );
		$this->assertFalse( $this->sut->is_dismissed( 'row-notice', 0, 5 ) );
		$this->assertFalse( $this->sut->is_dismissed( '', $this->user_id, 5 ) );
	}
}
