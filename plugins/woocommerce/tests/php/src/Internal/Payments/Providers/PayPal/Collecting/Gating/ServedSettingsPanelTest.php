<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Gating;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating\PlatformServedOnboardingRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating\PlatformServedSettingsRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsModel;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface\HoldsWalletState;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use WP_REST_Request;

/**
 * Tests for the data the wallet's settings app receives on a store the platform serves: the intent, saved PayPal and
 * Venmo, the SDK v6 buttons, the collecting key of its script data and the hidden Order Intent block.
 *
 * @group paypal-wallet
 */
class ServedSettingsPanelTest extends WalletTestCase {
	use BootsCollectingContainer;
	use HoldsWalletState;

	/**
	 * The handle of the settings app's script and stylesheet.
	 */
	private const HANDLE = 'ppcp-admin-settings';

	/**
	 * The merchant's stored settings: authorize-only, virtual-order capture and saved PayPal and Venmo all on.
	 */
	private const STORED_SETTINGS = array(
		'authorize_only'         => true,
		'capture_virtual_orders' => true,
		'save_paypal_and_venmo'  => true,
		'brand_name'             => 'Stored brand',
	);

	/**
	 * The wallet's stored onboarding profile: the wizard was never completed.
	 */
	private const STORED_ONBOARDING = array(
		'completed'       => false,
		'step'            => 2,
		'gateways_synced' => false,
	);

	/**
	 * The option the wallet's onboarding profile is stored in.
	 */
	private const ONBOARDING_OPTION = 'woocommerce-ppcp-data-onboarding';

	/**
	 * Store the merchant settings and register the settings app's handles, as the wallet's ScriptDataHandler does.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_wallet_option( SettingsModel::OPTION_KEY, self::STORED_SETTINGS );
		$this->set_wallet_option( self::ONBOARDING_OPTION, self::STORED_ONBOARDING );
		wp_register_script( self::HANDLE, 'https://example.org/settings.js', array(), '1', true );
		wp_register_style( self::HANDLE, 'https://example.org/settings.css', array(), '1' );
	}

	/**
	 * Take the handles out again: the script and style registries are not reset between tests.
	 */
	public function tearDown(): void {
		try {
			wp_deregister_script( self::HANDLE );
			wp_deregister_style( self::HANDLE );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Boot a container for a collecting store over a ready fake transport.
	 *
	 * @return ContainerInterface
	 */
	private function boot_collecting_store(): ContainerInterface {
		$this->set_collecting();

		return $this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) );
	}

	/**
	 * The `before` inline script of the settings app, joined.
	 *
	 * @return string
	 */
	private function inline_script(): string {
		$before = wp_scripts()->get_data( self::HANDLE, 'before' );

		return is_array( $before ) ? implode( "\n", array_filter( $before, 'is_string' ) ) : '';
	}

	/**
	 * @testdox Should read authorize-only as off and the intent as capture for every reader of the settings provider, without writing the stored setting.
	 */
	public function test_provider_reads_capture_while_served(): void {
		$container = $this->boot_collecting_store();

		$provider = $container->get( 'settings.settings-provider' );
		$this->assertFalse( $provider->authorize_only() );
		$this->assertSame( 'capture', $provider->payment_intent(), 'CapturePayPalPayment::create_order() reads this' );
		$columns = array( 'order_status' => 'Status' );
		$this->assertSame( $columns, $container->get( 'wcgateway.admin.orders-payment-status-column' )->register( $columns ), 'No "Payment Captured" column for an authorize-only intent' );
		$this->assertSame( self::STORED_SETTINGS, get_option( SettingsModel::OPTION_KEY ), 'The stored setting is unchanged' );
	}

	/**
	 * @testdox Should keep the stored authorize-only setting for a first-party connected store.
	 */
	public function test_provider_keeps_authorize_only_for_a_first_party_store(): void {
		$this->set_first_party_connected();

		$this->assertSame( 'authorize', $this->boot_container()->get( 'settings.settings-provider' )->payment_intent() );
	}

	/**
	 * @testdox Should answer the settings app's settings with authorize-only and virtual-order capture off while served.
	 */
	public function test_settings_rest_answers_the_capture_intent(): void {
		$endpoint = $this->boot_collecting_store()->get( 'settings.rest.settings' );

		$this->assertInstanceOf( PlatformServedSettingsRestEndpoint::class, $endpoint );
		$data = $endpoint->get_details()->get_data()['data'];
		$this->assertFalse( $data['authorizeOnly'] );
		$this->assertFalse( $data['captureVirtualOrders'] );
		$this->assertSame( 'Stored brand', $data['brandName'], 'The other settings are the stored ones' );
	}

	/**
	 * @testdox Should save the settings app's other settings but keep the stored intent settings while served.
	 */
	public function test_settings_rest_save_keeps_the_stored_intent(): void {
		$endpoint = $this->boot_collecting_store()->get( 'settings.rest.settings' );
		$request  = new WP_REST_Request( 'POST', '/wc/v3/wc_paypal/settings' );
		$request->set_param( 'authorizeOnly', false );
		$request->set_param( 'captureVirtualOrders', false );
		$request->set_param( 'brandName', 'New brand' );

		$endpoint->update_details( $request );

		$stored = get_option( SettingsModel::OPTION_KEY );
		$this->assertTrue( $stored['authorize_only'], 'The panel\'s hidden off value is not saved over the merchant\'s' );
		$this->assertTrue( $stored['capture_virtual_orders'] );
		$this->assertSame( 'New brand', $stored['brand_name'] );
	}

	/**
	 * @testdox Should answer the settings app's common details with saved PayPal and Venmo hidden, and keep the SDK v6 buttons off, while served.
	 */
	public function test_common_rest_hides_vaulting_and_v6_buttons_stay_off(): void {
		$container = $this->boot_collecting_store();

		$body = $container->get( 'settings.rest.common' )->get_details()->get_data();

		$this->assertTrue( $body['success'] );
		$features = $body['features'] ?? array();
		$this->assertEmpty( $features['save_paypal_and_venmo']['enabled'] ?? false, 'The Save PayPal and Venmo block renders only when this is enabled' );
		$this->assertFalse( $container->get( 'sdk-v6.buttons-available' ) );
	}

	/**
	 * @testdox Should add the collecting panel data to the settings app's script data and hide the Order Intent block, while served.
	 */
	public function test_script_data_carries_the_collecting_key(): void {
		$this->boot_collecting_store();

		do_action( 'woocommerce_paypal_payments_settings_scripts_enqueued' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Fired as the wallet's ScriptDataHandler fires it.

		$script = $this->inline_script();
		$this->assertSame( 1, preg_match( '/window\.ppcpSettings\.collecting = (\{.*\});/s', $script, $matches ), 'The key is set after the localized ppcpSettings' );
		$collecting = json_decode( $matches[1], true );
		$this->assertSame( ConnectionState::COLLECTING, $collecting['state'] );
		$this->assertSame( 'payee@example.com', $collecting['payee_email'] );
		$this->assertTrue( $collecting['transport_ready'] );
		$this->assertArrayHasKey( 'merchant_state', $collecting );
		$this->assertArrayHasKey( 'held_orders', $collecting );
		$this->assertSame( 'Webhooks are managed by WooCommerce while PayPal Wallet setup is in progress.', $collecting['webhooks_note'] );
		$styles = wp_styles()->get_data( self::HANDLE, 'after' );
		$this->assertStringContainsString( '.ppcp--order-intent', implode( "\n", (array) $styles ) );
	}

	/**
	 * @testdox Should give a platform-connected store the webhooks note without the setup clause.
	 */
	public function test_script_data_webhooks_note_once_platform_connected(): void {
		$this->set_platform_connected();
		$this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) );

		do_action( 'woocommerce_paypal_payments_settings_scripts_enqueued' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Fired as the wallet's ScriptDataHandler fires it.

		$this->assertSame( 1, preg_match( '/window\.ppcpSettings\.collecting = (\{.*\});/s', $this->inline_script(), $matches ) );
		$this->assertSame( 'Webhooks are managed by WooCommerce.', json_decode( $matches[1], true )['webhooks_note'] );
	}

	/**
	 * @testdox Should report the wallet's onboarding as completed and the gateways as synced while served ($state), and write nothing on a save.
	 * @testWith ["collecting"]
	 *           ["platform"]
	 *
	 * @param string $state The served state.
	 */
	public function test_onboarding_reads_completed_while_served( string $state ): void {
		if ( 'collecting' === $state ) {
			$this->set_collecting();
		} else {
			$this->set_platform_connected();
		}
		$endpoint = $this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) )->get( 'settings.rest.onboarding' );

		$this->assertInstanceOf( PlatformServedOnboardingRestEndpoint::class, $endpoint );
		$data = $endpoint->get_details()->get_data()['data'];
		$this->assertTrue( $data['completed'] );
		$this->assertTrue( $data['gatewaysSynced'] );
		$this->assertSame( 2, $data['step'], 'The other fields are the stored ones' );

		$request = new WP_REST_Request( 'POST', '/wc/v3/wc_paypal/onboarding' );
		$request->set_param( 'completed', true );
		$request->set_param( 'gatewaysSynced', true );
		$saved = $endpoint->update_details( $request )->get_data()['data'];

		$this->assertTrue( $saved['completed'] );
		$this->assertSame( self::STORED_ONBOARDING, get_option( self::ONBOARDING_OPTION ), 'The wallet\'s onboarding profile is never written while served' );
	}

	/**
	 * @testdox Should read the wallet's onboarding as stored, and save it, for a store the platform does not serve: $state.
	 * @testWith ["dormant"]
	 *           ["first_party"]
	 *
	 * @param string $state The state.
	 */
	public function test_onboarding_is_unchanged_when_not_served( string $state ): void {
		if ( 'first_party' === $state ) {
			$this->set_first_party_connected();
		}
		$endpoint = $this->boot_container()->get( 'settings.rest.onboarding' );

		$this->assertNotInstanceOf( PlatformServedOnboardingRestEndpoint::class, $endpoint );
		$this->assertFalse( $endpoint->get_details()->get_data()['data']['completed'] );
	}

	/**
	 * @testdox Should load every route the settings app reads on a collecting store without a request to PayPal.
	 */
	public function test_settings_app_routes_load_without_paypal_calls(): void {
		$this->boot_collecting_store();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$stored_before             = $this->stored_wallet_options();
		$previous_server           = $GLOBALS['wp_rest_server'] ?? null;
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh server fires rest_api_init, which registers the routes of the container booted above.
		$statuses                  = array();
		try {
			foreach ( array( 'onboarding', 'common', 'common/merchant', 'webhooks', 'settings', 'payment', 'styling', 'todos', 'features', 'pay_later_messaging' ) as $route ) {
				$statuses[ $route ] = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/wc_paypal/' . $route ) )->get_status();
			}
		} finally {
			$GLOBALS['wp_rest_server'] = $previous_server; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring test state.
		}

		$this->assertSame( array_fill_keys( array_keys( $statuses ), 200 ), $statuses );
		$this->assertSame( array(), $this->http_requests, 'No route reached PayPal or the connect service' );
		$this->assertNotSame( array(), $stored_before, 'The store has wallet options to compare' );
		$this->assertSame( $stored_before, $this->stored_wallet_options(), 'No woocommerce-ppcp-* option and no ppcp-webhook is written' );
	}

	/**
	 * Every stored woocommerce-ppcp-* option and ppcp-webhook, by name, as the database holds them.
	 *
	 * @return array<string, string>
	 */
	private function stored_wallet_options(): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A test snapshot of the raw rows, past the options cache.
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name = %s ORDER BY option_name",
				$wpdb->esc_like( 'woocommerce-ppcp-' ) . '%',
				'ppcp-webhook'
			),
			ARRAY_A
		);

		return array_column( $rows, 'option_value', 'option_name' );
	}

	/**
	 * @testdox Should add no collecting key and hide nothing for a store the platform does not serve.
	 */
	public function test_script_data_is_untouched_when_not_served(): void {
		$this->set_first_party_connected();
		$this->boot_container();

		do_action( 'woocommerce_paypal_payments_settings_scripts_enqueued' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Fired as the wallet's ScriptDataHandler fires it.

		$this->assertStringNotContainsString( 'collecting', $this->inline_script() );
		$this->assertFalse( wp_styles()->get_data( self::HANDLE, 'after' ) );
		$this->assertFalse( get_option( Options::COLLECTING ) );
	}
}
