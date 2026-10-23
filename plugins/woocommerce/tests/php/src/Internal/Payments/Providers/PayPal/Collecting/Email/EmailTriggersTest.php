<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Email;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Email\EmailTriggers;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface\OwnerIndependent;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\RecordingLogger;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface\HoldsWalletState;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use RuntimeException;

/**
 * Tests for the listeners that send the two admin emails from the collecting module's actions.
 *
 * @group paypal-wallet
 */
class EmailTriggersTest extends WalletTestCase {
	use HoldsWalletState;

	/**
	 * The logger the listeners write to.
	 *
	 * @var RecordingLogger
	 */
	private RecordingLogger $logger;

	/**
	 * The System Under Test.
	 *
	 * @var EmailTriggers
	 */
	private $sut;

	/**
	 * Put both emails in the mailer, and make sending fail.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_first_order( 7 );
		add_filter( 'woocommerce_email_classes', array( new OwnerIndependent(), 'register_emails' ) );
		WC()->mailer()->init();
		add_filter(
			'pre_wp_mail',
			static function () {
				throw new RuntimeException( 'the mail server is down' );
			}
		);
		$this->logger = new RecordingLogger();
		$this->sut    = new EmailTriggers( $this->logger );
	}

	/**
	 * Put the mailer back on the hooks the framework restored.
	 */
	public function tearDown(): void {
		try {
			parent::tearDown();
		} finally {
			WC()->mailer()->init();
		}
	}

	/**
	 * @testdox Should log a warning and let nothing escape when sending the first-order email fails.
	 */
	public function test_a_failed_first_order_email_is_logged_and_contained(): void {
		$order = wc_create_order();

		$this->sut->handle_woocommerce_paypal_wallet_first_order( $order, null );

		$this->assertCount( 1, $this->logger->records );
		$this->assertSame( 'warning', $this->logger->records[0]['level'] );
		$this->assertStringContainsString( 'the mail server is down', $this->logger->records[0]['message'] );
	}

	/**
	 * @testdox Should log a warning and let nothing escape when sending the returned-payment email fails.
	 */
	public function test_a_failed_returned_payment_email_is_logged_and_contained(): void {
		$order = wc_create_order();

		$this->sut->handle_woocommerce_paypal_wallet_held_payment_returned( $order, 'refunded' );

		$this->assertCount( 1, $this->logger->records );
		$this->assertStringContainsString( 'the mail server is down', $this->logger->records[0]['message'] );
	}

	/**
	 * @testdox Should do nothing, and log nothing, for something that is not an order.
	 */
	public function test_ignores_a_non_order(): void {
		$this->sut->handle_woocommerce_paypal_wallet_first_order( null, null );
		$this->sut->handle_woocommerce_paypal_wallet_held_payment_returned( 5, 'refunded' );

		$this->assertSame( array(), $this->logger->records );
	}
}
