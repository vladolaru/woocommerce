<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\RefundLock;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\HeldOrders;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\DormantPayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletBootstrap;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletRuntimeArbiter;
use WC_AJAX;
use WC_Unit_Test_Case;

/**
 * Tests for the DormantPayPalGateway class.
 *
 * @group paypal-wallet-boot
 */
class DormantPayPalGatewayTest extends WC_Unit_Test_Case {

	/**
	 * The shared settings option the PayPal wallet gateway stores its settings in.
	 */
	private const SETTINGS_OPTION = 'woocommerce_ppcp-gateway_settings';

	/**
	 * The System Under Test.
	 *
	 * @var DormantPayPalGateway
	 */
	private $sut;

	/**
	 * Saved request and gateway state, restored in tearDown.
	 *
	 * @var array
	 */
	private $saved = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->saved = array(
			'post'     => $_POST, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Saving test state.
			'request'  => $_REQUEST, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Saving test state.
			'gateways' => WC()->payment_gateways()->payment_gateways,
		);
		$this->sut   = new DormantPayPalGateway();
	}

	/**
	 * Restore the request globals and the registered gateways.
	 */
	public function tearDown(): void {
		$_POST                                     = $this->saved['post'];
		$_REQUEST                                  = $this->saved['request'];
		WC()->payment_gateways()->payment_gateways = $this->saved['gateways'];
		wc_clear_notices();
		parent::tearDown();
	}

	/**
	 * Read the stored bytes of the shared settings option, bypassing every cache.
	 *
	 * @return string|null
	 */
	private function read_stored_settings(): ?string {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::SETTINGS_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the stored bytes.
	}

	/**
	 * Store a collecting option the way a store keeps it after the merchant turned the gateway off with an order held.
	 *
	 * @return array The stored option.
	 */
	private function keep_a_collecting_option(): array {
		$collecting = array(
			'payee_email' => 'payee@example.com',
			'tracking_id' => str_repeat( 'a', 32 ),
			'environment' => 'sandbox',
			'payee_bound' => true,
		);
		update_option( Options::COLLECTING, $collecting, true );

		return $collecting;
	}

	/**
	 * Store a platform connection the way a store keeps it after the merchant turned the gateway off.
	 *
	 * @return array The stored option.
	 */
	private function keep_a_platform_option(): array {
		$platform = array(
			'merchant_id'  => 'M2',
			'tracking_id'  => str_repeat( 'b', 32 ),
			'payee_email'  => 'payee@example.com',
			'connected_at' => 1700000000,
			'environment'  => 'sandbox',
		);
		update_option( Options::PLATFORM, $platform, true );

		return $platform;
	}

	/**
	 * Build the shell with an arbiter that says core owns the wallet, and register its listeners.
	 *
	 * @return PayPalWalletBootstrap
	 */
	private function registered_shell(): PayPalWalletBootstrap {
		$arbiter = $this->getMockBuilder( PayPalWalletRuntimeArbiter::class )->onlyMethods( array( 'should_native_register' ) )->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( true );
		$shell = new PayPalWalletBootstrap();
		$shell->init( $arbiter );
		$shell->register();

		return $shell;
	}

	/**
	 * @testdox Should carry the PayPal gateway ID, a translated title, no fields and no supported features.
	 */
	public function test_identifies_as_the_paypal_wallet_placeholder(): void {
		$this->assertSame( 'ppcp-gateway', $this->sut->id );
		$this->assertSame( 'PayPal Wallet', $this->sut->get_method_title() );
		$this->assertFalse( $this->sut->has_fields );
		$this->assertSame( array(), $this->sut->supports );
		$this->assertSame( array(), $this->sut->get_form_fields() );
	}

	/**
	 * @testdox Should read as disabled and unavailable even when the shared settings option says enabled.
	 */
	public function test_is_never_enabled_or_available(): void {
		update_option( self::SETTINGS_OPTION, array( 'enabled' => 'yes' ) );
		$gateway = new DormantPayPalGateway();

		$this->assertSame( 'no', $gateway->enabled, 'The enabled property must be forced to no' );
		$this->assertSame( 'no', $gateway->settings['enabled'], 'The settings array must not show the stored yes either' );
		$this->assertSame( 'no', $gateway->get_option( 'enabled', 'no' ), 'The stored enabled value must not leak through get_option' );
		$this->assertFalse( $gateway->is_available(), 'The placeholder is never offered at checkout' );
	}

	/**
	 * @testdox Should report that it needs setup, which is what swaps the enable toggle for a setup button.
	 */
	public function test_needs_setup(): void {
		$this->assertTrue( $this->sut->needs_setup() );
	}

	/**
	 * @testdox Should still need setup, and refuse to be turned back on, on a store with neither a collecting nor a platform option.
	 */
	public function test_a_store_with_neither_option_cannot_be_turned_back_on(): void {
		delete_option( Options::COLLECTING );
		delete_option( Options::PLATFORM );

		$this->assertFalse( $this->sut->can_be_turned_back_on() );
		$this->assertTrue( $this->sut->needs_setup() );
	}

	/**
	 * @testdox Should need no setup while a platform connection is kept, so the Payments list can turn the gateway back on (Ruling 166).
	 */
	public function test_needs_no_setup_while_a_platform_option_is_kept(): void {
		$this->keep_a_platform_option();

		$this->assertTrue( $this->sut->can_be_turned_back_on() );
		$this->assertFalse( $this->sut->needs_setup() );
		$this->assertSame( 'no', $this->sut->get_option( 'enabled' ), 'It still reads as disabled' );
		$this->assertFalse( $this->sut->is_available(), 'It is still never offered at checkout' );
	}

	/**
	 * @testdox Should need no setup while a collecting option is kept, so the Payments list can turn the gateway back on (K8).
	 */
	public function test_needs_no_setup_while_a_collecting_option_is_kept(): void {
		$this->keep_a_collecting_option();

		$this->assertTrue( $this->sut->can_be_turned_back_on() );
		$this->assertFalse( $this->sut->needs_setup() );
		$this->assertSame( 'no', $this->sut->get_option( 'enabled' ), 'It still reads as disabled' );
		$this->assertFalse( $this->sut->is_available(), 'It is still never offered at checkout' );
	}

	/**
	 * @testdox Should fail a payment attempt with an error notice.
	 */
	public function test_process_payment_fails_with_a_notice(): void {
		wc_clear_notices();

		$result = $this->sut->process_payment( 0 );

		$this->assertSame( array( 'result' => 'failure' ), $result );
		$notices = wc_get_notices( 'error' );
		$this->assertCount( 1, $notices, 'The shopper must be told why the payment failed' );
		$this->assertSame( 'PayPal Wallet is not set up yet. Choose another payment method.', $notices[0]['notice'] );
	}

	/**
	 * @testdox Should print a link to the PayPal wallet settings route in its admin options.
	 */
	public function test_admin_options_link_to_the_wallet_settings(): void {
		ob_start();
		$this->sut->admin_options();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&path=/paypal-wallet' ) ), $output );
		$this->assertStringContainsString( 'Finish setup', $output );
	}

	/**
	 * @testdox Should not write the shared settings option from update_option or process_admin_options.
	 */
	public function test_never_writes_the_shared_settings_option_directly(): void {
		update_option( self::SETTINGS_OPTION, array( 'enabled' => 'yes' ) );
		$before = $this->read_stored_settings();
		$this->assertNotNull( $before, 'Precondition: the option is stored' );
		$_POST['woocommerce_ppcp-gateway_enabled'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Simulating a form post.

		$updated   = $this->sut->update_option( 'enabled', 'no' );
		$processed = $this->sut->process_admin_options();

		$this->assertFalse( $updated );
		$this->assertFalse( $processed );
		$this->assertSame( $before, $this->read_stored_settings(), 'The shared settings option must stay byte-identical' );
	}

	/**
	 * Drive WooCommerce's own gateway toggle, the AJAX handler the Payments settings list's enable button calls.
	 *
	 * @return array The decoded JSON the handler printed.
	 */
	private function run_enable_toggle(): array {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$nonce                                     = wp_create_nonce( 'woocommerce-toggle-payment-gateway-enabled' );
		$_POST['gateway_id']                       = 'ppcp-gateway';
		$_POST['security']                         = $nonce;
		$_REQUEST['security']                      = $nonce;
		WC()->payment_gateways()->payment_gateways = array( 'ppcp-gateway' => $this->sut );

		// wp_send_json_error() ends the request with wp_die() on an AJAX request; turn that into an exception.
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			static function () {
				return static function () {
					throw new \WPDieException( 'ajax die' );
				};
			}
		);

		ob_start();
		try {
			WC_AJAX::toggle_gateway_enabled();
		} catch ( \WPDieException $e ) {
			unset( $e ); // The handler always ends with wp_die().
		}
		$output = (string) ob_get_clean();

		return (array) json_decode( $output, true );
	}

	/**
	 * @testdox Should refuse the Payments list's enable toggle with needs_setup and leave the shared settings option byte-identical when the stored enabled value is "$stored".
	 *
	 * @testWith ["no"]
	 *           ["yes"]
	 *           [""]
	 *
	 * @param string $stored The stored enabled value, with an empty string meaning no stored value.
	 */
	public function test_enable_toggle_changes_nothing( string $stored ): void {
		if ( '' !== $stored ) {
			update_option(
				self::SETTINGS_OPTION,
				array(
					'enabled' => $stored,
					'title'   => 'PayPal',
				)
			);
		}
		$this->sut = new DormantPayPalGateway();
		$before    = $this->read_stored_settings();

		$response = $this->run_enable_toggle();

		$this->assertFalse( $response['success'] ?? null, 'The toggle must fail' );
		$this->assertSame( 'needs_setup', $response['data'] ?? null, 'The list reads needs_setup as "send the merchant to setup"' );
		$this->assertSame( $before, $this->read_stored_settings(), 'The shared settings option must stay byte-identical' );
		$this->assertSame( 'no', $this->sut->enabled );
	}

	/**
	 * @testdox Should let the Payments list's enable toggle turn the gateway back on while a collecting option is kept, keeping the other settings and the collecting option (K8).
	 */
	public function test_enable_toggle_turns_the_gateway_on_while_a_collecting_option_is_kept(): void {
		update_option(
			self::SETTINGS_OPTION,
			array(
				'enabled' => 'no',
				'title'   => 'PayPal',
			)
		);
		// The row first: with no order held, turning the gateway off with a collecting option kept would abandon it.
		$collecting = $this->keep_a_collecting_option();
		$this->sut  = new DormantPayPalGateway();

		$response = $this->run_enable_toggle();

		$this->assertTrue( $response['success'] ?? null, 'The toggle must succeed' );
		$this->assertTrue( $response['data'] ?? null, 'The list reads true as "now enabled"' );
		$stored = get_option( self::SETTINGS_OPTION );
		$this->assertSame( 'yes', $stored['enabled'] );
		$this->assertSame( 'PayPal', $stored['title'], 'The other settings are kept' );
		$this->assertSame( $collecting, get_option( Options::COLLECTING ), 'Same payee, tracking ID and bound flag' );
	}

	/**
	 * @testdox Should write nothing but the enabled flag turned on while a collecting option is kept.
	 */
	public function test_writes_only_the_enabled_flag_while_a_collecting_option_is_kept(): void {
		update_option(
			self::SETTINGS_OPTION,
			array(
				'enabled' => 'no',
				'title'   => 'PayPal',
			)
		);
		// The row first: with no order held, turning the gateway off with a collecting option kept would abandon it.
		$this->keep_a_collecting_option();
		$this->sut = new DormantPayPalGateway();
		$before    = $this->read_stored_settings();

		$_POST['woocommerce_ppcp-gateway_title'] = 'Changed'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Simulating a form post.

		$this->assertFalse( $this->sut->update_option( 'enabled', 'no' ) );
		$this->assertFalse( $this->sut->update_option( 'title', 'Changed' ) );
		$this->assertFalse( $this->sut->process_admin_options() );
		$this->assertSame( $before, $this->read_stored_settings(), 'The shared settings option must stay byte-identical' );
	}

	/**
	 * @testdox Should end a disable and re-enable round trip with an order held collecting again for the same payee, the order still held.
	 */
	public function test_disable_and_re_enable_round_trip_resumes_collecting_with_the_held_order(): void {
		$order = wc_create_order();
		$order->set_payment_method( 'ppcp-gateway' );
		$order->update_meta_data( RefundLock::HELD_CAPTURE_META_KEY, 'UNILATERAL' );
		$order->set_status( 'on-hold' );
		$order->save();
		$collecting = $this->keep_a_collecting_option();
		update_option( self::SETTINGS_OPTION, array( 'enabled' => 'yes' ) );
		$shell = $this->registered_shell();
		$this->assertFalse( $shell->is_dormant(), 'Precondition: a collecting store with the gateway on boots' );

		// The merchant turns PayPal off in the Payments list: WooCommerce's toggle saves the gateway row with enabled "no".
		update_option( self::SETTINGS_OPTION, array( 'enabled' => 'no' ) );
		$this->assertSame( $collecting, get_option( Options::COLLECTING ), 'The held order keeps the collecting option' );
		$this->assertTrue( $shell->is_dormant(), 'A collecting store with the gateway off is dormant' );
		$this->assertContains( DormantPayPalGateway::class, $shell->register_dormant_gateway( array() ), 'The list shows the placeholder' );

		// The merchant turns it on again from the placeholder row.
		$this->sut = new DormantPayPalGateway();
		$response  = $this->run_enable_toggle();

		$this->assertTrue( $response['success'] ?? null );
		$this->assertSame( 'yes', get_option( self::SETTINGS_OPTION )['enabled'] );
		$this->assertFalse( $shell->is_dormant(), 'The next request boots the served store again' );
		$this->assertSame( $collecting, get_option( Options::COLLECTING ), 'Still collecting for the same payee, tracking ID and bound flag' );
		$reloaded = wc_get_order( $order->get_id() );
		$this->assertTrue( ( new HeldOrders() )->is_held( $reloaded ), 'The order is still held' );
		$this->assertSame( 'on-hold', $reloaded->get_status() );
		$this->assertSame( 1, ( new HeldOrders() )->count() );
	}

	/**
	 * @testdox Should end a disable and re-enable round trip of a platform-connected store platform connected again, with the platform option untouched (Ruling 166).
	 */
	public function test_disable_and_re_enable_round_trip_of_a_platform_connected_store(): void {
		$platform = $this->keep_a_platform_option();
		update_option(
			self::SETTINGS_OPTION,
			array(
				'enabled' => 'yes',
				'title'   => 'PayPal',
			)
		);
		$shell = $this->registered_shell();
		$this->assertFalse( $shell->is_dormant(), 'Precondition: a platform-connected store with the gateway on boots' );

		// The merchant turns PayPal off in the Payments list: WooCommerce's toggle saves the gateway row with enabled "no".
		update_option(
			self::SETTINGS_OPTION,
			array(
				'enabled' => 'no',
				'title'   => 'PayPal',
			)
		);
		$this->assertSame( $platform, get_option( Options::PLATFORM ), 'Turning the gateway off keeps the platform connection' );
		$this->assertTrue( $shell->is_dormant(), 'A platform-connected store with the gateway off is dormant' );
		$this->assertContains( DormantPayPalGateway::class, $shell->register_dormant_gateway( array() ), 'The list shows the placeholder' );

		// The merchant turns it on again from the placeholder row.
		$this->sut = new DormantPayPalGateway();
		$response  = $this->run_enable_toggle();

		$this->assertTrue( $response['success'] ?? null, 'The toggle must succeed' );
		$this->assertTrue( $response['data'] ?? null );
		$stored = get_option( self::SETTINGS_OPTION );
		$this->assertSame( 'yes', $stored['enabled'] );
		$this->assertSame( 'PayPal', $stored['title'], 'The other settings are kept' );
		$this->assertFalse( $shell->is_dormant(), 'The next request boots the platform-connected store again' );
		$this->assertSame( $platform, get_option( Options::PLATFORM ), 'The platform connection is untouched' );
		$this->assertFalse( get_option( Options::COLLECTING ), 'No collecting option appears' );
	}

	/**
	 * @testdox Should leave a store with neither a collecting nor a platform option as it was: the toggle is refused and the row is byte-identical.
	 */
	public function test_enable_toggle_changes_nothing_on_a_store_with_neither_option(): void {
		delete_option( Options::COLLECTING );
		delete_option( Options::PLATFORM );
		update_option( self::SETTINGS_OPTION, array( 'enabled' => 'no' ) );
		$this->sut = new DormantPayPalGateway();
		$before    = $this->read_stored_settings();

		$response = $this->run_enable_toggle();

		$this->assertFalse( $response['success'] ?? null );
		$this->assertSame( 'needs_setup', $response['data'] ?? null );
		$this->assertFalse( $this->sut->update_option( 'enabled', 'yes' ), 'A direct write saves nothing either' );
		$this->assertSame( $before, $this->read_stored_settings() );
	}
}
