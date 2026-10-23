<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Email;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Email\FirstOrderEmail;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletBootstrap;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface\HoldsWalletState;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Tests for the admin email sent when the first wallet order arrives before setup is complete.
 *
 * @group paypal-wallet
 */
class FirstOrderEmailTest extends WalletTestCase {
	use CapturesMail;
	use HoldsWalletState;

	/**
	 * The System Under Test.
	 *
	 * @var FirstOrderEmail
	 */
	private $sut;

	/**
	 * Build the email and catch what it sends.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->capture_mail();
		$this->sut = new FirstOrderEmail();
	}

	/**
	 * @testdox Should be the wc_paypal_wallet_first_order email: enabled, to the admin email, with the agreed subject and heading.
	 */
	public function test_describes_itself(): void {
		$this->assertSame( 'wc_paypal_wallet_first_order', $this->sut->id );
		$this->assertTrue( $this->sut->is_enabled() );
		$this->assertSame( get_option( 'admin_email' ), $this->sut->get_recipient() );
		$this->assertSame( 'An order is waiting: set up PayPal Wallet to receive it', $this->sut->get_subject() );
		$this->assertSame( 'An order is waiting for PayPal Wallet setup', $this->sut->get_heading() );
	}

	/**
	 * @testdox Should load its templates from the collecting folder and add nothing to core's email templates.
	 */
	public function test_templates_live_under_the_collecting_folder(): void {
		$base = rtrim( $this->sut->template_base, '/' );

		$this->assertStringEndsWith( '/Internal/Payments/Providers/PayPal/Collecting/Email/templates', $base );
		$this->assertFileExists( $base . '/' . $this->sut->template_html );
		$this->assertFileExists( $base . '/' . $this->sut->template_plain );
		$this->assertFileDoesNotExist( WC()->plugin_path() . '/templates/emails/paypal-wallet-first-order.php' );
		$this->assertFileDoesNotExist( WC()->plugin_path() . '/templates/emails/plain/paypal-wallet-first-order.php' );
	}

	/**
	 * @testdox Should send one HTML email to the admin that names the order, the deadline of a held payment and the setup link.
	 */
	public function test_sends_one_html_email_for_a_held_order(): void {
		$this->set_wallet_option( 'timezone_string', 'UTC' );
		$this->set_wallet_option( 'date_format', 'F j, Y' );
		$order       = $this->held_order( 1790000000 );
		$this->mails = array(); // Putting the order on hold sent core's own new-order email.

		$this->sut->trigger( $order );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( array( get_option( 'admin_email' ) ), (array) $this->mails[0]['to'] );
		$this->assertSame( 'An order is waiting: set up PayPal Wallet to receive it', $this->mails[0]['subject'] );
		$message = $this->mails[0]['message'];
		$this->assertStringContainsString( 'An order is waiting for PayPal Wallet setup', $message );
		$this->assertStringContainsString( 'A customer placed order #' . $order->get_order_number() . ' and paid using PayPal Wallet. To receive the payment, connect PayPal Wallet to your store and complete the setup.', $message );
		$this->assertStringContainsString( 'PayPal returns the payment to the customer on October 21, 2026 if setup is not completed.', $message );
		$this->assertStringContainsString( 'href="' . esc_attr( PayPalWalletBootstrap::get_settings_url() ) . '"', $message );
		$this->assertStringContainsString( 'Complete setup', $message );
	}

	/**
	 * @testdox Should leave the deadline out for an order that was paid at once.
	 */
	public function test_names_no_deadline_for_an_order_paid_at_once(): void {
		$order = wc_create_order();
		$order->save();
		$this->mails = array();

		$this->sut->trigger( $order );

		$this->assertCount( 1, $this->mails );
		$this->assertStringNotContainsString( 'PayPal returns the payment', $this->mails[0]['message'] );
	}

	/**
	 * @testdox Should send the plain-text variant when the email type is plain.
	 */
	public function test_sends_plain_text(): void {
		$this->set_wallet_option( 'timezone_string', 'UTC' );
		$this->set_wallet_option( 'date_format', 'F j, Y' );
		$this->set_wallet_option( 'woocommerce_wc_paypal_wallet_first_order_settings', array( 'email_type' => 'plain' ) );
		$order       = $this->held_order( 1790000000 );
		$this->mails = array(); // Putting the order on hold sent core's own new-order email.

		( new FirstOrderEmail() )->trigger( $order );

		$this->assertCount( 1, $this->mails );
		$message = preg_replace( '/\s+/', ' ', $this->mails[0]['message'] ); // The plain text is wrapped at 70 columns.
		$this->assertStringNotContainsString( '<p', $message );
		$this->assertStringContainsString( 'An order is waiting for PayPal Wallet setup', $message );
		$this->assertStringContainsString( 'A customer placed order #' . $order->get_order_number() . ' and paid using PayPal Wallet. To receive the payment, connect PayPal Wallet to your store and complete the setup.', $message );
		$this->assertStringContainsString( 'PayPal returns the payment to the customer on October 21, 2026 if setup is not completed.', $message );
		$this->assertStringContainsString( 'Complete setup: ' . PayPalWalletBootstrap::get_settings_url(), $message );
	}

	/**
	 * @testdox Should send nothing when the merchant turned the email off.
	 */
	public function test_sends_nothing_when_disabled(): void {
		$this->set_wallet_option( 'woocommerce_wc_paypal_wallet_first_order_settings', array( 'enabled' => 'no' ) );

		$order       = $this->held_order();
		$this->mails = array(); // Putting the order on hold sent core's own new-order email.

		( new FirstOrderEmail() )->trigger( $order );

		$this->assertSame( array(), $this->mails );
	}

	/**
	 * @testdox Should send nothing for something that is not an order.
	 */
	public function test_ignores_a_non_order(): void {
		$this->sut->trigger( null );

		$this->assertSame( array(), $this->mails );
	}

	/**
	 * @testdox Should resolve a theme override of the HTML template through woocommerce/emails/ in the theme.
	 */
	public function test_a_theme_can_override_the_template(): void {
		$theme = trailingslashit( get_temp_dir() ) . 'pp-wallet-theme-' . wp_generate_password( 8, false );
		wp_mkdir_p( $theme . '/woocommerce/emails' );
		file_put_contents( $theme . '/woocommerce/emails/paypal-wallet-first-order.php', '<?php echo "THEME OVERRIDE";' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A fixture in the temp folder.
		$override = static function () use ( $theme ) {
			return $theme;
		};
		add_filter( 'stylesheet_directory', $override );
		add_filter( 'template_directory', $override );

		try {
			$located = wc_locate_template( $this->sut->template_html, '', $this->sut->template_base );
			$html    = $this->sut->get_content_html();
		} finally {
			remove_filter( 'stylesheet_directory', $override );
			remove_filter( 'template_directory', $override );
			wp_delete_file( $theme . '/woocommerce/emails/paypal-wallet-first-order.php' );
			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing the fixture folders from the temp folder.
			rmdir( $theme . '/woocommerce/emails' );
			rmdir( $theme . '/woocommerce' );
			rmdir( $theme );
			// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}

		$this->assertSame( $theme . '/woocommerce/emails/paypal-wallet-first-order.php', $located );
		$this->assertSame( 'THEME OVERRIDE', $html );
	}
}
