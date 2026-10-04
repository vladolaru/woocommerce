<?php
/**
 * Tests for the button context helper (ported from the extension's ContextTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Button\Helper
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Button\Helper;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PaymentSource;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\Context;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\SessionHandler;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Mockery\MockInterface;

/**
 * The Site Editor check reads the real admin screen; the continuation check reads a mocked session handler (the PayPal
 * order the session holds).
 *
 * The four continuation cases are core-only additions that pin the rule the hosted-subscription cut left in place.
 *
 * @group paypal-wallet
 */
class ContextTest extends WalletTestCase {

	/**
	 * The admin screen the test found.
	 *
	 * @var mixed
	 */
	private $original_screen;

	/**
	 * Remember the screen.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_screen = $GLOBALS['current_screen'] ?? null;
	}

	/**
	 * Give the screen back.
	 */
	public function tearDown(): void {
		try {
			$GLOBALS['current_screen'] = $this->original_screen; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * A session handler mock whose PayPal order has the given status and payment source.
	 *
	 * @param string|null $order_status    The PayPal order status, or null for no order in the session.
	 * @param string      $source_name     The name of the order's payment source ('' for none).
	 * @param string|null $funding_source  The funding source kept in the session.
	 * @return SessionHandler&MockInterface
	 */
	private function session_handler( ?string $order_status, string $source_name = 'paypal', ?string $funding_source = 'paypal' ) {
		$session_handler = $this->mock( SessionHandler::class );
		$session_handler->allows( 'funding_source' )->andReturn( $funding_source );

		if ( null === $order_status ) {
			$session_handler->allows( 'order' )->andReturn( null );

			return $session_handler;
		}

		$order = $this->mock( Order::class );
		$order->allows( 'status' )->andReturn( new OrderStatus( $order_status ) );
		$order->allows( 'payment_source' )->andReturn( '' === $source_name ? null : new PaymentSource( $source_name, (object) array() ) );
		$session_handler->allows( 'order' )->andReturn( $order );

		return $session_handler;
	}

	/**
	 * @testdox Should report no Site Editor when there is no current admin screen (wallet).
	 */
	public function test_is_site_editor_false_when_no_current_screen(): void {
		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->assertFalse( Context::is_site_editor() );
	}

	/**
	 * @testdox Should report no Site Editor on the $base admin screen (wallet).
	 *
	 * @testWith ["post"]
	 *           ["widgets"]
	 *
	 * @param string $base The screen base.
	 */
	public function test_is_site_editor_false_when_screen_base_is_not_site_editor( string $base ): void {
		set_current_screen( $base );

		$this->assertFalse( Context::is_site_editor() );
	}

	/**
	 * @testdox Should report the Site Editor on the block-based Site Editor screen (wallet).
	 */
	public function test_is_site_editor_true_when_screen_base_is_site_editor(): void {
		set_current_screen( 'site-editor' );

		$this->assertTrue( Context::is_site_editor() );
	}

	/**
	 * @testdox Should not call a page a PayPal continuation when the session holds no PayPal order (wallet).
	 */
	public function test_no_order_in_session_is_not_a_continuation(): void {
		$sut = new Context( $this->session_handler( null ) );

		$this->assertFalse( $sut->is_paypal_continuation() );
	}

	/**
	 * @testdox Should call a page a PayPal continuation when the session holds an $status PayPal order (wallet).
	 *
	 * @testWith ["APPROVED"]
	 *           ["COMPLETED"]
	 *
	 * @param string $status The PayPal order status.
	 */
	public function test_approved_or_completed_order_is_a_continuation( string $status ): void {
		$sut = new Context( $this->session_handler( $status ) );

		$this->assertTrue( $sut->is_paypal_continuation() );
	}

	/**
	 * @testdox Should not call a page a PayPal continuation when the order is only saved (wallet).
	 */
	public function test_saved_order_is_not_a_continuation(): void {
		$sut = new Context( $this->session_handler( OrderStatus::SAVED ) );

		$this->assertFalse( $sut->is_paypal_continuation() );
	}

	/**
	 * @testdox Should ignore an approved order paid with a card payment source, or through the card funding source (wallet).
	 */
	public function test_card_payment_is_not_a_continuation(): void {
		$card_source = new Context( $this->session_handler( OrderStatus::APPROVED, 'card' ) );
		$card_button = new Context( $this->session_handler( OrderStatus::APPROVED, 'paypal', 'card' ) );

		$this->assertFalse( $card_source->is_paypal_continuation() );
		$this->assertFalse( $card_button->is_paypal_continuation() );
	}
}
