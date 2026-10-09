<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface\PluginsPageNotice;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletRuntimeArbiter;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Tests for the notice on the Plugins page while the PayPal Payments extension owns the wallet and orders are held.
 *
 * @group paypal-wallet
 */
class PluginsPageNoticeTest extends WalletTestCase {
	use HoldsWalletState;

	/**
	 * Sign in a shop manager, who sees the notice.
	 */
	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Sign out again.
	 */
	public function tearDown(): void {
		try {
			wp_set_current_user( 0 );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Build the notice over an arbiter that reports the given owner.
	 *
	 * @param string $owner One of the arbiter's OWNER_ constants.
	 * @return PluginsPageNotice
	 */
	private function sut( string $owner ): PluginsPageNotice {
		$arbiter = $this->getMockBuilder( PayPalWalletRuntimeArbiter::class )->onlyMethods( array( 'get_runtime_owner' ) )->getMock();
		$arbiter->method( 'get_runtime_owner' )->willReturn( $owner );

		return new PluginsPageNotice( null, null, $arbiter );
	}

	/**
	 * What the notice prints.
	 *
	 * @param PluginsPageNotice $sut The notice.
	 * @return string
	 */
	private function printed( PluginsPageNotice $sut ): string {
		ob_start();
		$sut->handle_admin_notices();

		return (string) ob_get_clean();
	}

	/**
	 * @testdox Should name the number of held orders and the payee while the extension owns the wallet.
	 */
	public function test_notice_with_two_held_orders(): void {
		$this->set_collecting();
		$this->held_order();
		$this->held_order();

		$html = $this->printed( $this->sut( PayPalWalletRuntimeArbiter::OWNER_EXTENSION ) );

		$this->assertStringContainsString( '2 orders paid with PayPal Wallet are waiting for the PayPal account payee@example.com to be set up and confirmed', $html );
		$this->assertStringContainsString( 'notice-warning', $html );
	}

	/**
	 * @testdox Should use the singular for one held order.
	 */
	public function test_notice_with_one_held_order(): void {
		$this->set_collecting();
		$this->held_order();

		$this->assertStringContainsString( '1 order paid with PayPal Wallet is waiting for the PayPal account payee@example.com to be set up and confirmed', $this->printed( $this->sut( PayPalWalletRuntimeArbiter::OWNER_EXTENSION ) ) );
	}

	/**
	 * @testdox Should print nothing when core owns the wallet or nobody does.
	 */
	public function test_nothing_outside_the_case(): void {
		$this->set_collecting();
		$this->held_order();
		$this->assertSame( '', $this->printed( $this->sut( PayPalWalletRuntimeArbiter::OWNER_NATIVE ) ), 'Core owns the wallet' );
		$this->assertSame( '', $this->printed( $this->sut( PayPalWalletRuntimeArbiter::OWNER_NONE ) ), 'Nobody owns the wallet' );
	}

	/**
	 * @testdox Should print nothing to a user who cannot manage WooCommerce.
	 */
	public function test_nothing_without_the_capability(): void {
		$this->set_collecting();
		$this->held_order();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( '', $this->printed( $this->sut( PayPalWalletRuntimeArbiter::OWNER_EXTENSION ) ) );
	}

	/**
	 * @testdox Should print nothing when no order is held.
	 */
	public function test_nothing_without_held_orders(): void {
		$this->set_collecting();
		$order = wc_create_order();
		$order->set_payment_method( 'bacs' );
		$order->save();

		$this->assertSame( '', $this->printed( $this->sut( PayPalWalletRuntimeArbiter::OWNER_EXTENSION ) ) );
	}

	/**
	 * @testdox Should hook the notice only when the Plugins page loads.
	 */
	public function test_hooks_the_notice_on_the_plugins_page_only(): void {
		$this->set_collecting();
		$sut = $this->sut( PayPalWalletRuntimeArbiter::OWNER_EXTENSION );
		$sut->register();

		$this->assertFalse( has_action( 'admin_notices', array( $sut, 'handle_admin_notices' ) ), 'Not before the Plugins page loads' );
		$this->assertSame( 10, has_action( 'load-plugins.php', array( $sut, 'handle_load_plugins_php' ) ) );

		do_action( 'load-plugins.php' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Core's hook, fired by the test.

		$this->assertSame( 10, has_action( 'admin_notices', array( $sut, 'handle_admin_notices' ) ) );
	}
}
