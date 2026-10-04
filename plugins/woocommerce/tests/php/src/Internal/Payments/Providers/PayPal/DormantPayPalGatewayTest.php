<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\DormantPayPalGateway;
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
}
