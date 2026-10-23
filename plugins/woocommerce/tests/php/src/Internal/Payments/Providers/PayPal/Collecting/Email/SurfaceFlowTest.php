<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Email;

use Automattic\WooCommerce\Admin\Features\OnboardingTasks\TaskLists;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\OrderPin;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface\InboxNote;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface\OwnerIndependent;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface\SetUpPayPalWalletTask;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Amount;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Capture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\CaptureStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\CaptureStatusDetails;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Money;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface\HoldsWalletState;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use WC_Order;

/**
 * The surfaces and the container-bound email listeners together, driven by the real held-capture listener.
 *
 * @group paypal-wallet
 */
class SurfaceFlowTest extends WalletTestCase {
	use BootsCollectingContainer;
	use CapturesMail;
	use HoldsWalletState;

	/**
	 * Register the surfaces and the collecting module, and catch mail.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->capture_mail();
		$this->set_collecting();
		( new OwnerIndependent() )->register();
		$this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) );
		WC()->mailer()->init();
	}

	/**
	 * Put the mailer back on the hooks the framework restores, and remove the note.
	 */
	public function tearDown(): void {
		try {
			InboxNote::possibly_delete_note();
		} finally {
			parent::tearDown();
			WC()->mailer()->init();
		}
	}

	/**
	 * A pinned wallet order.
	 *
	 * @return WC_Order
	 */
	private function wallet_order(): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( PayPalGateway::ID );
		$order->update_meta_data( PayPalGateway::ORDER_ID_META_KEY, 'PP-HELD-' . wp_generate_password( 6, false ) );
		OrderPin::record( $order, PlatformTransport::APP_MERCHANT_APP );
		$order->save();

		return $order;
	}

	/**
	 * A held capture.
	 *
	 * @param string $id The capture ID.
	 * @return Capture
	 */
	private function held_capture( string $id ): Capture {
		return new Capture(
			$id,
			new CaptureStatus( CaptureStatus::PENDING, new CaptureStatusDetails( 'UNILATERAL' ) ),
			new Amount( new Money( 10.0, 'USD' ) ),
			true,
			'',
			'',
			'',
			null,
			null
		);
	}

	/**
	 * @testdox Should send one email and add one note across two held orders: only the first order fires either.
	 */
	public function test_two_held_orders_give_one_email_and_one_note(): void {
		$first  = $this->wallet_order();
		$second = $this->wallet_order();

		do_action( 'woocommerce_paypal_wallet_capture_pending', $first, $this->held_capture( 'CAPTURE-1' ) );
		do_action( 'woocommerce_paypal_wallet_capture_pending', $second, $this->held_capture( 'CAPTURE-2' ) );

		$this->assertSame( (string) $first->get_id(), (string) get_option( Options::FIRST_ORDER ) );
		$this->assertCount( 1, $this->mails, 'One email for the first order only' );
		$this->assertSame( 'An order is waiting: set up PayPal Wallet to receive it', $this->mails[0]['subject'] );
		$this->assertStringContainsString( '#' . $first->get_order_number(), $this->mails[0]['message'] );
		$this->assertCount( 1, Notes::load_data_store()->get_notes_with_name( InboxNote::NOTE_NAME ), 'One note' );
	}

	/**
	 * @testdox Should show a dismissed Home task again when a second order is held, through the real held-capture listener.
	 */
	public function test_a_second_held_order_brings_the_dismissed_task_back(): void {
		$first  = $this->wallet_order();
		$second = $this->wallet_order();
		do_action( 'woocommerce_paypal_wallet_capture_pending', $first, $this->held_capture( 'CAPTURE-1' ) );
		$task = new SetUpPayPalWalletTask( TaskLists::get_list( 'extended' ) );
		$this->assertTrue( $task->can_view() );
		$this->assertTrue( $task->dismiss() );

		do_action( 'woocommerce_paypal_wallet_capture_pending', $second, $this->held_capture( 'CAPTURE-2' ) );

		$this->assertFalse( $task->is_dismissed() );
	}

	/**
	 * @testdox Should send the returned-payment email, with the reason, when PayPal returns a held payment.
	 */
	public function test_a_returned_payment_sends_its_email(): void {
		$order = $this->wallet_order();

		do_action( 'woocommerce_paypal_wallet_held_payment_returned', $order, 'declined' );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'PayPal did not release the payment for order #' . $order->get_order_number(), $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'PayPal denied the payment', $this->mails[0]['message'] );
	}

	/**
	 * @testdox Should send nothing and not fail when the hooks pass something that is not an order.
	 */
	public function test_ignores_a_non_order(): void {
		do_action( 'woocommerce_paypal_wallet_held_payment_returned', null, 'refunded' );
		do_action( 'woocommerce_paypal_wallet_held_payment_returned', 5, 'refunded' );

		$this->assertSame( array(), $this->mails );
	}
}
