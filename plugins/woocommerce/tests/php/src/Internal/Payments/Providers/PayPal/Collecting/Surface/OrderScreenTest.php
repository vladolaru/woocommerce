<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\OrderPin;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface\OrderScreen;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface\OwnerIndependent;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletBootstrap;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use WC_Order;

/**
 * Tests for the order screen: the setup notice and the locked Refund button.
 *
 * @group paypal-wallet
 */
class OrderScreenTest extends WalletTestCase {
	use BootsCollectingContainer;
	use HoldsWalletState;

	private const D1  = 'To receive the payment, connect PayPal Wallet to your store and complete the setup.';
	private const TIP = 'Refunds are available once PayPal Wallet setup is complete';

	/**
	 * The System Under Test.
	 *
	 * @var OrderScreen
	 */
	private OrderScreen $sut;

	/**
	 * Build the order screen notice.
	 */
	public function setUp(): void {
		parent::setUp();
		// The byte comparison of the order items view depends on these store settings, so pin them.
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_currency_pos', 'left' );
		update_option( 'woocommerce_price_thousand_sep', ',' );
		update_option( 'woocommerce_price_decimal_sep', '.' );
		update_option( 'woocommerce_price_num_decimals', '2' );
		update_option( 'woocommerce_calc_taxes', 'no' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_default_country', 'US:CA' );
		add_filter( 'locale', array( $this, 'pinned_locale' ) );
		$this->sut = new OrderScreen();
	}

	/**
	 * The locale the byte comparison is made in.
	 *
	 * @return string
	 */
	public function pinned_locale(): string {
		return 'en_US';
	}

	/**
	 * Boot the wallet with the collecting module, as on a store the platform serves.
	 */
	private function boot_collecting_module(): void {
		$this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) );
	}

	/**
	 * What the notice prints for an order.
	 *
	 * @param WC_Order $order The order.
	 * @return string
	 */
	private function printed( WC_Order $order ): string {
		ob_start();
		$this->sut->handle_woocommerce_admin_order_data_after_payment_info( $order );

		return (string) ob_get_clean();
	}

	/**
	 * What the order-items meta box view prints for an order.
	 *
	 * @param WC_Order $order The order.
	 * @return string
	 */
	private function items_html( WC_Order $order ): string {
		ob_start();
		( static function ( $order ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The included view reads it.
			include WC_ABSPATH . 'includes/admin/meta-boxes/views/html-order-items.php';
		} )( $order );

		return (string) ob_get_clean();
	}

	/**
	 * A processing order paid with the wallet and recognised as a wallet order, not held.
	 *
	 * @return WC_Order
	 */
	private function wallet_order(): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( PayPalGateway::ID );
		$order->update_meta_data( PayPalGateway::ORDER_ID_META_KEY, 'PP-SCREEN-1' );
		$order->set_total( '25.00' );
		$order->set_status( 'processing' );
		$order->save();
		OrderPin::record( $order, PlatformTransport::APP_MERCHANT_APP );
		$order->save();

		return $order;
	}

	/**
	 * @testdox Should print the setup notice for a held order.
	 */
	public function test_notice_for_a_held_order(): void {
		$this->set_collecting();
		$html = $this->printed( $this->held_order() );

		$this->assertStringContainsString( self::D1, $html );
		$this->assertStringContainsString( 'wc-paypal-wallet-order-notice', $html );
		$this->assertStringNotContainsString( 'Confirm the email', $html );
		$this->assertStringContainsString( '<a class="button button-primary" href="' . esc_url( PayPalWalletBootstrap::get_settings_url() ) . '">Complete setup</a>', $html );
	}

	/**
	 * @testdox Should print the setup notice for a wallet order that is not held yet while the store collects.
	 */
	public function test_notice_for_a_wallet_order_while_collecting(): void {
		$this->set_collecting();

		$this->assertStringContainsString( self::D1, $this->printed( $this->wallet_order() ) );
	}

	/**
	 * @testdox Should print the confirm-the-email copy, naming the payee, when the cached seller status says payments are receivable but the email is not confirmed.
	 */
	public function test_state_two_copy_when_receivable_but_unconfirmed(): void {
		$this->set_collecting();
		$this->set_wallet_option(
			Options::SELLER_STATUS,
			array(
				'payments_receivable'     => true,
				'primary_email_confirmed' => false,
				'checked_at'              => time(),
			)
		);

		$html = $this->printed( $this->held_order() );

		$this->assertStringContainsString( 'Confirm the email PayPal sent to payee@example.com to release the payment.', $html );
		$this->assertStringNotContainsString( self::D1, $html );
		$this->assertStringNotContainsString( 'Complete setup', $html, 'Only the email confirmation releases the payment' );
	}

	/**
	 * @testdox Should print nothing for a held order once the store is connected: it waits for PayPal, not for the merchant.
	 * @testWith ["platform_connected"]
	 *           ["first_party_connected"]
	 *
	 * @param string $state The connected state.
	 */
	public function test_nothing_for_a_held_order_of_a_connected_store( string $state ): void {
		if ( 'platform_connected' === $state ) {
			$this->set_platform_connected();
		} else {
			$this->set_first_party_connected();
		}
		$this->set_wallet_option(
			Options::SELLER_STATUS,
			array(
				'payments_receivable'     => true,
				'primary_email_confirmed' => false,
				'checked_at'              => time(),
			)
		);

		$this->assertSame( '', $this->printed( $this->held_order() ) );
	}

	/**
	 * @testdox Should print the setup copy when the cached seller status is not receivable, or the email is confirmed.
	 * @testWith [false, false]
	 *           [false, true]
	 *           [true, true]
	 *
	 * @param bool $receivable Whether payments are receivable.
	 * @param bool $confirmed  Whether the email is confirmed.
	 */
	public function test_setup_copy_for_other_seller_statuses( bool $receivable, bool $confirmed ): void {
		$this->set_collecting();
		$this->set_wallet_option(
			Options::SELLER_STATUS,
			array(
				'payments_receivable'     => $receivable,
				'primary_email_confirmed' => $confirmed,
				'checked_at'              => time(),
			)
		);

		$html = $this->printed( $this->held_order() );

		$this->assertStringContainsString( self::D1, $html );
		$this->assertStringNotContainsString( 'Confirm the email', $html );
	}

	/**
	 * @testdox Should print nothing for an order another gateway paid, or a wallet order the store no longer locks.
	 */
	public function test_nothing_for_other_orders(): void {
		$this->set_collecting();
		$other = wc_create_order();
		$other->set_payment_method( 'bacs' );
		$other->save();
		$this->assertSame( '', $this->printed( $other ), 'Not a wallet order' );

		$this->set_platform_connected();
		delete_option( Options::COLLECTING );
		$this->assertSame( '', $this->printed( $this->wallet_order() ), 'A wallet order of a connected store, not held' );
		$this->assertSame( '', $this->sut->notice_html( null ), 'Not an order' );
	}

	/**
	 * @testdox Should say what the Plugins page says while the extension owns the wallet: the payment waits for the PayPal account, not for a connection.
	 */
	public function test_notice_while_the_extension_owns_the_wallet(): void {
		$this->set_collecting();
		$order = $this->held_order();

		$html = $this->as_extension_owner(
			function () use ( $order ): string {
				return $this->printed( $order );
			}
		);

		$this->assertStringContainsString( 'The payment for this order is waiting for the PayPal account payee@example.com to be set up and confirmed.', $html );
		$this->assertStringNotContainsString( self::D1, $html );
		$this->assertStringNotContainsString( 'Complete setup', $html, 'Setup cannot complete from the store while the extension owns the wallet' );
	}

	/**
	 * @testdox Should give the notice the admin's notice text color on the order edit screen only.
	 */
	public function test_notice_style_on_the_order_edit_screen(): void {
		wp_register_style( 'woocommerce_admin_styles', 'admin.css', array(), '1' );
		wp_enqueue_style( 'woocommerce_admin_styles' );

		set_current_screen( 'edit-post' );
		$this->sut->handle_admin_enqueue_scripts();
		$this->assertFalse( wp_styles()->get_data( 'woocommerce_admin_styles', 'after' ), 'Not on another screen' );

		set_current_screen( wc_get_page_screen_id( 'shop-order' ) );
		$this->sut->handle_admin_enqueue_scripts();
		$this->assertSame( array( '#order_data .wc-paypal-wallet-order-notice p { color: inherit; }' ), wp_styles()->get_data( 'woocommerce_admin_styles', 'after' ) );

		set_current_screen( 'front' );
		wp_dequeue_style( 'woocommerce_admin_styles' );
		wp_deregister_style( 'woocommerce_admin_styles' );
	}

	/**
	 * @testdox Should disable the Refund button, with the tooltip, for a held order.
	 */
	public function test_refund_button_is_disabled_for_a_held_order(): void {
		$this->set_collecting();
		$order = $this->held_order();
		$order->set_total( '25.00' );
		$order->save();
		$this->boot_collecting_module();
		( new OwnerIndependent() )->register();

		$html = $this->items_html( $order );

		$this->assertMatchesRegularExpression( '/<button type="button" class="button refund-items" disabled(="disabled")? title="' . preg_quote( self::TIP, '/' ) . '">Refund<\/button>/', $html );
	}

	/**
	 * @testdox Should render the order items view of a regular order byte for byte as before the change.
	 */
	public function test_regular_order_markup_is_unchanged(): void {
		$this->set_collecting();
		$this->boot_collecting_module();
		( new OwnerIndependent() )->register();
		$order = wc_create_order();
		$order->set_payment_method( 'bacs' );
		$order->set_total( '25.00' );
		$order->set_status( 'processing' );
		$order->save();

		$this->assertSame( file_get_contents( __DIR__ . '/fixtures/order-items-regular.html' ), $this->items_html( $order ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local fixture.
	}

	/**
	 * @testdox Should disable the Refund button of a held order when the collecting module is not booted, as when the extension owns the wallet or the gateway is off.
	 */
	public function test_refund_button_is_disabled_without_the_module(): void {
		$this->set_collecting();
		$order = $this->held_order();
		$order->set_total( '25.00' );
		$order->save();
		( new OwnerIndependent() )->register();

		$html = $this->items_html( $order );

		$this->assertMatchesRegularExpression( '/<button type="button" class="button refund-items" disabled(="disabled")? title="' . preg_quote( self::TIP, '/' ) . '">Refund<\/button>/', $html );
	}

	/**
	 * @testdox Should disable the Refund button of a held order after a first-party connection left the collecting state.
	 */
	public function test_refund_button_stays_disabled_after_a_first_party_connection(): void {
		$this->set_collecting();
		$this->set_first_order( 7 );
		$order = $this->held_order();
		$order->set_total( '25.00' );
		$order->save();
		$this->set_first_party_connected();
		delete_option( Options::COLLECTING );
		( new OwnerIndependent() )->register();

		$html = $this->items_html( $order );

		$this->assertMatchesRegularExpression( '/<button type="button" class="button refund-items" disabled(="disabled")? title="' . preg_quote( self::TIP, '/' ) . '">Refund<\/button>/', $html );
	}
}
