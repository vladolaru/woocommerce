<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface\Dismissals;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface\ProviderRow;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletBootstrap;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Tests for the notice on the PayPal Wallet row of the Payments settings list.
 *
 * @group paypal-wallet
 */
class ProviderRowTest extends WalletTestCase {
	use HoldsWalletState;

	/**
	 * The System Under Test.
	 *
	 * @var ProviderRow
	 */
	private ProviderRow $sut;

	/**
	 * Build the row notice.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new ProviderRow();
	}

	/**
	 * Forget the user the test signed in.
	 */
	public function tearDown(): void {
		try {
			wp_set_current_user( 0 );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Sign in an administrator and return the user ID.
	 *
	 * @return int
	 */
	private function sign_in_admin(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * @testdox Should give a collecting store with a first order the "Complete setup to receive your payment" notice for the PayPal gateway row.
	 */
	public function test_notice_for_a_collecting_store_with_a_first_order(): void {
		$this->set_collecting();
		$this->set_first_order( 7 );
		$this->sign_in_admin();

		$notice = $this->sut->handle_woocommerce_paypal_wallet_provider_notice( null, 'ppcp-gateway' );

		$this->assertIsArray( $notice );
		$this->assertSame( 'Complete setup to receive your payment', $notice['title'] );
		$this->assertSame( 'A customer placed an order and paid using PayPal Wallet. To receive the payment, connect PayPal Wallet to your store and complete the setup.', $notice['text'] );
		$this->assertSame( 'Complete setup', $notice['action_label'] );
		$this->assertSame( PayPalWalletBootstrap::get_settings_url(), $notice['action_url'] );
		$this->assertTrue( $notice['dismissible'] );
		$this->assertStringContainsString( 'wc-ajax=wc_paypal_wallet_dismiss_notice', $notice['dismiss_url'] );
		$this->assertStringContainsString( '_wpnonce=', $notice['dismiss_url'] );
		$this->assertSame( array( 'title', 'text', 'action_label', 'action_url', 'dismissible', 'dismiss_url' ), array_keys( $notice ) );
	}

	/**
	 * @testdox Should give no notice on the extension's PayPal row while the extension owns the wallet: its setup route does not exist there.
	 */
	public function test_no_notice_while_the_extension_owns_the_wallet(): void {
		$this->set_collecting();
		$this->set_first_order( 7 );
		$this->sign_in_admin();

		$notice = $this->as_extension_owner(
			function () {
				return $this->sut->handle_woocommerce_paypal_wallet_provider_notice( null, 'ppcp-gateway' );
			}
		);

		$this->assertNull( $notice );
	}

	/**
	 * @testdox Should give no notice without a first order, for another gateway, or when the store is not collecting.
	 * @testWith ["no_order", "ppcp-gateway"]
	 *           ["collecting", "stripe"]
	 *           ["platform", "ppcp-gateway"]
	 *           ["dormant", "ppcp-gateway"]
	 *
	 * @param string $scenario   What the store has.
	 * @param string $gateway_id The gateway row.
	 */
	public function test_no_notice_outside_the_state( string $scenario, string $gateway_id ): void {
		if ( 'platform' === $scenario ) {
			$this->set_platform_connected();
			$this->set_first_order( 7 );
		} elseif ( 'collecting' === $scenario ) {
			$this->set_collecting();
			$this->set_first_order( 7 );
		} elseif ( 'no_order' === $scenario ) {
			$this->set_collecting();
		} else {
			$this->set_first_order( 7 );
		}
		$this->sign_in_admin();

		$this->assertNull( $this->sut->handle_woocommerce_paypal_wallet_provider_notice( null, $gateway_id ) );
	}

	/**
	 * @testdox Should keep a notice an earlier callback already set.
	 */
	public function test_keeps_an_existing_notice(): void {
		$this->set_collecting();
		$this->set_first_order( 7 );
		$this->sign_in_admin();
		$existing = array( 'title' => 'Mine' );

		$this->assertSame( $existing, $this->sut->handle_woocommerce_paypal_wallet_provider_notice( $existing, 'ppcp-gateway' ) );
		$this->assertSame( 'x', $this->sut->handle_woocommerce_paypal_wallet_provider_notice( 'x', 'ppcp-gateway' ) );
	}

	/**
	 * @testdox Should hide the notice for the user who dismissed it, show it to another user, and show it again for a newer first order.
	 */
	public function test_dismissal_is_per_user_until_a_newer_first_order(): void {
		$this->set_collecting();
		$this->set_first_order( 7 );
		$user_id = $this->sign_in_admin();
		( new Dismissals() )->dismiss( ProviderRow::SURFACE, $user_id );

		$this->assertNull( $this->sut->handle_woocommerce_paypal_wallet_provider_notice( null, 'ppcp-gateway' ), 'Dismissed by this user' );

		$this->sign_in_admin();
		$this->assertIsArray( $this->sut->handle_woocommerce_paypal_wallet_provider_notice( null, 'ppcp-gateway' ), 'Another user still sees it' );

		wp_set_current_user( $user_id );
		update_option( Options::FIRST_ORDER, 9 );
		$this->assertIsArray( $this->sut->handle_woocommerce_paypal_wallet_provider_notice( null, 'ppcp-gateway' ), 'A newer first order brings it back' );
	}

	/**
	 * @testdox Should store the dismissal for a signed-in shop manager with a valid nonce, and refuse a bad nonce, a missing nonce or a user without the capability.
	 */
	public function test_dismiss_from_request(): void {
		$this->set_collecting();
		$this->set_first_order( 7 );
		$user_id = $this->sign_in_admin();
		$this->assertFalse( $this->sut->dismiss_from_request( array( '_wpnonce' => 'bad' ) ), 'Bad nonce' );
		$this->assertFalse( $this->sut->dismiss_from_request( array() ), 'No nonce' );
		$this->assertFalse( ( new Dismissals() )->is_dismissed( ProviderRow::SURFACE, $user_id, 7 ) );

		// The nonce belongs to the user it was created for.
		$nonce = wp_create_nonce( ProviderRow::AJAX_ACTION );
		$this->assertTrue( $this->sut->dismiss_from_request( array( '_wpnonce' => $nonce ) ) );
		$this->assertTrue( ( new Dismissals() )->is_dismissed( ProviderRow::SURFACE, $user_id, 7 ) );

		$customer = self::factory()->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $customer );
		$this->assertFalse( $this->sut->dismiss_from_request( array( '_wpnonce' => wp_create_nonce( ProviderRow::AJAX_ACTION ) ) ), 'No manage_woocommerce capability' );
		$this->assertFalse( ( new Dismissals() )->is_dismissed( ProviderRow::SURFACE, $customer, 7 ) );
	}

	/**
	 * @testdox Should reach the notice through the provider row details of the PayPal gateway.
	 */
	public function test_notice_reaches_the_row_details(): void {
		$this->set_collecting();
		$this->set_first_order( 7 );
		$this->sign_in_admin();
		$this->sut->register();

		$notice = apply_filters( 'woocommerce_paypal_wallet_provider_notice', null, 'ppcp-gateway' );

		$this->assertSame( 'Complete setup to receive your payment', $notice['title'] );
	}
}
