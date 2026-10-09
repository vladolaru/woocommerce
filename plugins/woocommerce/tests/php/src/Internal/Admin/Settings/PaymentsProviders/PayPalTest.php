<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Settings\PaymentsProviders;

use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\PayPal;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\PaymentGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface\OwnerIndependent;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\DormantPayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletBootstrap;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PPCP;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use WC_Payment_Gateway;
use WC_Unit_Test_Case;

/**
 * PayPal payment gateway provider service test.
 *
 * @class PayPal
 */
class PayPalTest extends WC_Unit_Test_Case {

	/**
	 * @var PayPal
	 */
	protected $sut;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new PayPal( wc_get_container()->get( LegacyProxy::class ) );
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		remove_all_filters( PayPalWalletRuntimeArbiter::FILTER_ENABLED );
		wc_get_container()->get( PayPalWalletRuntimeArbiter::class )->invalidate();

		parent::tearDown();
	}

	/**
	 * Build a fake gateway with the extension's ID, mapped to a regular plugin file.
	 *
	 * @return WC_Payment_Gateway
	 */
	private function fake_ppcp_gateway(): WC_Payment_Gateway {
		$gateway     = $this->getMockBuilder( WC_Payment_Gateway::class )->onlyMethods( array( 'get_method_title' ) )->getMock();
		$gateway->id = 'ppcp-gateway';
		// Test hooks read by the base provider, so it reports a deactivatable plugin.
		$gateway->extension_type = PaymentsProviders::EXTENSION_TYPE_WPORG;
		$gateway->plugin_file    = 'woocommerce-paypal-payments/woocommerce-paypal-payments.php';
		$gateway->method_title   = 'PayPal';
		$gateway->method( 'get_method_title' )->willReturn( 'PayPal' );
		return $gateway;
	}

	/**
	 * Make the arbiter report that native owns the site.
	 */
	private function pin_native_ownership(): void {
		add_filter( PayPalWalletRuntimeArbiter::FILTER_ENABLED, '__return_true' );
		wc_get_container()->get( PayPalWalletRuntimeArbiter::class )->invalidate();
	}

	/**
	 * Set the wallet's static container, returning the previous one so a test can restore it.
	 *
	 * @param ContainerInterface|null $container The container, or null for a wallet that did not boot.
	 *
	 * @return ContainerInterface|null The previous container.
	 */
	private function swap_wallet_container( ?ContainerInterface $container ): ?ContainerInterface {
		$property = new \ReflectionProperty( PPCP::class, 'container' );
		$property->setAccessible( true );
		$previous = $property->getValue();
		$property->setValue( null, $container );

		return $previous;
	}

	/**
	 * Call the provider's private container lookup.
	 *
	 * @param WC_Payment_Gateway $gateway The gateway.
	 *
	 * @return ContainerInterface|null
	 */
	private function get_paypal_container( WC_Payment_Gateway $gateway ) {
		$method = new \ReflectionMethod( PayPal::class, 'get_paypal_container' );
		$method->setAccessible( true );

		return $method->invoke( $this->sut, $gateway );
	}

	/**
	 * Build a container that answers the connection-state service like the wallet's does.
	 *
	 * @param bool $connected Whether the state reports a connected account.
	 * @param bool $sandbox   Whether the state reports the sandbox.
	 *
	 * @return ContainerInterface
	 */
	private function fake_container_with_connection_state( bool $connected, bool $sandbox ): ContainerInterface {
		$state = new class( $connected, $sandbox ) {
			/**
			 * Connected flag.
			 *
			 * @var bool
			 */
			private $connected;

			/**
			 * Sandbox flag.
			 *
			 * @var bool
			 */
			private $sandbox;

			/**
			 * Constructor.
			 *
			 * @param bool $connected Connected flag.
			 * @param bool $sandbox   Sandbox flag.
			 */
			public function __construct( bool $connected, bool $sandbox ) {
				$this->connected = $connected;
				$this->sandbox   = $sandbox;
			}

			/**
			 * Whether connected.
			 *
			 * @return bool
			 */
			public function is_connected(): bool {
				return $this->connected;
			}

			/**
			 * Whether sandbox.
			 *
			 * @return bool
			 */
			public function is_sandbox(): bool {
				return $this->sandbox;
			}
		};

		return new class( $state ) implements ContainerInterface {
			/**
			 * The state service.
			 *
			 * @var object
			 */
			private $state;

			/**
			 * Constructor.
			 *
			 * @param object $state The state service.
			 */
			public function __construct( $state ) {
				$this->state = $state;
			}

			/**
			 * Whether the service exists.
			 *
			 * @param string $id Service ID.
			 *
			 * @return bool
			 */
			public function has( string $id ): bool {
				return 'settings.connection-state' === $id;
			}

			/**
			 * Get the service.
			 *
			 * @param string $id Service ID.
			 *
			 * @return object
			 */
			public function get( string $id ) {
				return $this->state;
			}
		};
	}

	/**
	 * Get the plugin details the base provider returns, bypassing the PayPal override.
	 *
	 * @param WC_Payment_Gateway $gateway The gateway.
	 *
	 * @return array
	 */
	private function parent_plugin_details( WC_Payment_Gateway $gateway ): array {
		return ( new PaymentGateway( wc_get_container()->get( LegacyProxy::class ) ) )->get_plugin_details( $gateway );
	}

	/**
	 * @testdox Should title the row PayPal Wallet and blank the plugin file when native owns the site.
	 */
	public function test_native_owned_row_is_core_provided(): void {
		add_filter( PayPalWalletRuntimeArbiter::FILTER_ENABLED, '__return_true' );
		wc_get_container()->get( PayPalWalletRuntimeArbiter::class )->invalidate();

		$gateway = $this->fake_ppcp_gateway();

		$parent_details = $this->parent_plugin_details( $gateway );
		$this->assertSame( 'woocommerce-paypal-payments/woocommerce-paypal-payments', $parent_details['file'], 'Precondition: the base provider reports a plugin file' );

		$this->assertSame( 'PayPal Wallet', $this->sut->get_title( $gateway ) );
		$this->assertSame( '', $this->sut->get_plugin_details( $gateway )['file'], 'A core-provided row must have no deactivate action' );
	}

	/**
	 * @testdox Should show the PayPal icon on the row when native owns the site.
	 */
	public function test_native_owned_row_uses_the_paypal_icon(): void {
		$this->pin_native_ownership();
		$gateway       = $this->fake_ppcp_gateway();
		$gateway->icon = 'https://example.com/extension-icon.svg';

		$this->assertSame( plugins_url( 'assets/images/onboarding/icons/paypal.svg', WC_PLUGIN_FILE ), $this->sut->get_icon( $gateway ) );
	}

	/**
	 * @testdox Should leave the icon of the extension's row untouched when native does not own the site.
	 */
	public function test_non_native_row_keeps_its_icon(): void {
		add_filter( PayPalWalletRuntimeArbiter::FILTER_ENABLED, '__return_false' );
		wc_get_container()->get( PayPalWalletRuntimeArbiter::class )->invalidate();
		$gateway       = $this->fake_ppcp_gateway();
		$gateway->icon = 'https://example.com/extension-icon.svg';

		$this->assertSame( 'https://example.com/extension-icon.svg', $this->sut->get_icon( $gateway ) );
	}

	/**
	 * @testdox Should describe the native-owned row by the payment methods it offers, not the gateway's shopper-facing text.
	 */
	public function test_native_owned_row_describes_the_wallet_methods(): void {
		$this->pin_native_ownership();
		$gateway              = $this->fake_ppcp_gateway();
		$gateway->description = 'Pay via PayPal.';

		$this->assertSame( 'Offer PayPal, Pay Later, and Venmo (US only) at checkout.', $this->sut->get_description( $gateway ) );
	}

	/**
	 * @testdox Should leave the description of the extension's row untouched when native does not own the site.
	 */
	public function test_non_native_row_keeps_its_description(): void {
		add_filter( PayPalWalletRuntimeArbiter::FILTER_ENABLED, '__return_false' );
		wc_get_container()->get( PayPalWalletRuntimeArbiter::class )->invalidate();
		$gateway              = $this->fake_ppcp_gateway();
		$gateway->description = 'Pay via PayPal.';

		$this->assertSame( 'Pay via PayPal.', $this->sut->get_description( $gateway ) );
	}

	/**
	 * @testdox Should leave title and plugin details untouched when native does not own the site.
	 */
	public function test_non_native_row_is_unchanged(): void {
		add_filter( PayPalWalletRuntimeArbiter::FILTER_ENABLED, '__return_false' );
		wc_get_container()->get( PayPalWalletRuntimeArbiter::class )->invalidate();

		$gateway = $this->fake_ppcp_gateway();

		$parent_details = $this->parent_plugin_details( $gateway );
		$this->assertNotSame( '', $parent_details['file'], 'Precondition: the base provider reports a plugin file' );

		$this->assertSame( 'PayPal', $this->sut->get_title( $gateway ) );
		$this->assertSame( $parent_details, $this->sut->get_plugin_details( $gateway ) );
	}

	/**
	 * @testdox Should read the connection state from core's wallet container when core owns the site and the wallet booted.
	 */
	public function test_reads_connection_state_from_the_core_wallet_container(): void {
		$this->pin_native_ownership();
		$gateway = $this->fake_ppcp_gateway();

		// The shared options the wallet's connection-state service reads, written before the boot builds it.
		update_option(
			'woocommerce-ppcp-data-common',
			array(
				'merchant_email'   => 'merchant@example.com',
				'merchant_id'      => 'MERCHANT123',
				'client_id'        => 'client-id',
				'client_secret'    => 'client-secret',
				'sandbox_merchant' => true,
			)
		);
		$arbiter = $this->getMockBuilder( PayPalWalletRuntimeArbiter::class )->onlyMethods( array( 'should_native_register', 'get_runtime_owner', 'is_native_enabled' ) )->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( true );
		$arbiter->method( 'get_runtime_owner' )->willReturn( PayPalWalletRuntimeArbiter::OWNER_NONE );
		$arbiter->method( 'is_native_enabled' )->willReturn( false );
		$bootstrap = new PayPalWalletBootstrap();
		$bootstrap->init( $arbiter );
		$bootstrap->maybe_boot();
		$this->assertTrue( $bootstrap->is_booted(), 'Precondition: the wallet booted in this request' );

		$this->assertSame( PPCP::container(), $this->get_paypal_container( $gateway ), 'The core wallet container must be the one used' );
		$this->assertTrue( $this->sut->is_account_connected( $gateway ), 'The stored connection must read as connected' );
		$this->assertTrue( $this->sut->is_in_test_mode( $gateway ), 'The stored sandbox merchant must read as test mode' );

		// The base provider answers true for a connected account whatever the options say, so a disconnected store tells the two sources apart.
		update_option( 'woocommerce-ppcp-data-common', array() );
		// A not-connected wallet only builds on its own surfaces, so a wallet REST route stands in for the onboarding request.
		$request_uri            = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Saving test state.
		$_SERVER['REQUEST_URI'] = '/' . rest_get_url_prefix() . '/wc/v3/wc_paypal/onboarding';
		try {
			$bootstrap = new PayPalWalletBootstrap();
			$bootstrap->init( $arbiter );
			$bootstrap->maybe_boot();
		} finally {
			if ( null === $request_uri ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $request_uri;
			}
		}
		$this->assertFalse( $this->sut->is_account_connected( $gateway ), 'A store without a stored connection must read as not connected' );
		$this->assertFalse( $this->sut->is_in_test_mode( $gateway ) );
	}

	/**
	 * @testdox Should not throw and should read the connection from the shared settings option when core owns the site but the wallet did not boot.
	 */
	public function test_reads_the_connection_from_the_option_when_core_owns_but_the_wallet_did_not_boot(): void {
		$this->pin_native_ownership();
		$gateway  = $this->fake_ppcp_gateway();
		$previous = $this->swap_wallet_container( null );

		try {
			delete_option( 'woocommerce-ppcp-data-common' );
			$container       = $this->get_paypal_container( $gateway );
			$connected_empty = $this->sut->is_account_connected( $gateway );
			$onboarded_empty = $this->sut->is_onboarding_completed( $gateway );
			$test_mode_empty = $this->sut->is_in_test_mode( $gateway );
			update_option( 'woocommerce-ppcp-data-common', $this->connected_option( true ) );
			$connected_stored  = $this->sut->is_account_connected( $gateway );
			$onboarded_stored  = $this->sut->is_onboarding_completed( $gateway );
			$test_mode_stored  = $this->sut->is_in_test_mode( $gateway );
			$test_mode_onboard = $this->sut->is_in_test_mode_onboarding( $gateway );
			update_option( 'woocommerce-ppcp-data-common', $this->connected_option( false ) );
			$test_mode_live = $this->sut->is_in_test_mode( $gateway );
			$connected_live = $this->sut->is_account_connected( $gateway );
		} finally {
			$this->swap_wallet_container( $previous );
		}

		$this->assertNull( $container, 'No container is obtainable, so the lookup must report that' );
		$this->assertFalse( $connected_empty, 'No stored connection reads as not connected' );
		$this->assertFalse( $onboarded_empty, 'No stored connection reads as not onboarded' );
		$this->assertFalse( $test_mode_empty );
		$this->assertTrue( $connected_stored, 'A stored connection reads as connected' );
		$this->assertTrue( $onboarded_stored );
		$this->assertTrue( $test_mode_stored, 'A stored sandbox merchant reads as test mode' );
		$this->assertTrue( $test_mode_onboard );
		$this->assertFalse( $test_mode_live, 'A stored live merchant is not test mode' );
		$this->assertTrue( $connected_live );
	}

	/**
	 * The shared settings option as a connected merchant, in the shape the wallet's GeneralSettings model saves it.
	 *
	 * @param bool $sandbox Whether the merchant is a sandbox account.
	 *
	 * @return array
	 */
	private function connected_option( bool $sandbox ): array {
		return array(
			'merchant_connected' => true,
			'sandbox_merchant'   => $sandbox,
			'merchant_id'        => 'TESTMERCHANTID',
			'merchant_email'     => 'merchant@example.com',
			'client_id'          => 'test-client-id',
			'client_secret'      => 'test-client-secret',
		);
	}

	/**
	 * @testdox Should read an incomplete stored connection as not connected, whatever the stored merchant_connected flag says.
	 */
	public function test_an_incomplete_stored_connection_is_not_connected(): void {
		$this->pin_native_ownership();
		$gateway  = $this->fake_ppcp_gateway();
		$previous = $this->swap_wallet_container( null );
		$data     = $this->connected_option( false );
		unset( $data['client_secret'] );
		update_option( 'woocommerce-ppcp-data-common', $data );

		try {
			$connected = $this->sut->is_account_connected( $gateway );
		} finally {
			$this->swap_wallet_container( $previous );
		}

		$this->assertFalse( $connected );
	}

	/**
	 * @testdox Should read a legacy-only connection as onboarded, and its sandbox flag, when core owns the site but the wallet did not boot.
	 */
	public function test_reads_a_legacy_only_connection_when_the_wallet_did_not_boot(): void {
		$this->pin_native_ownership();
		$gateway  = $this->fake_ppcp_gateway();
		$previous = $this->swap_wallet_container( null );
		delete_option( 'woocommerce-ppcp-data-common' );
		update_option(
			'woocommerce-ppcp-settings',
			array(
				'client_id'     => 'legacy-client-id',
				'client_secret' => 'legacy-client-secret',
				'merchant_id'   => 'LEGACYMERCHANT',
				'sandbox_on'    => true,
			)
		);

		try {
			$onboarded = $this->sut->is_onboarding_completed( $gateway );
			$test_mode = $this->sut->is_in_test_mode( $gateway );
		} finally {
			$this->swap_wallet_container( $previous );
		}

		$this->assertTrue( $onboarded, 'A store that boots for its legacy connection must not read as not onboarded' );
		$this->assertTrue( $test_mode );
	}

	/**
	 * @testdox Should treat the dormant placeholder as a row that needs setup, with both URLs on the wallet's settings route.
	 */
	public function test_the_dormant_placeholder_row_needs_setup(): void {
		$this->pin_native_ownership();
		delete_option( 'woocommerce-ppcp-data-common' );
		$gateway  = new DormantPayPalGateway();
		$previous = $this->swap_wallet_container( null );
		$url      = admin_url( 'admin.php?page=wc-settings&tab=checkout&path=/paypal-wallet' );

		try {
			$needs_setup = $this->sut->needs_setup( $gateway );
			$connected   = $this->sut->is_account_connected( $gateway );
			$onboarded   = $this->sut->is_onboarding_completed( $gateway );
			$onboarding  = $this->sut->get_onboarding_url( $gateway );
			$settings    = $this->sut->get_settings_url( $gateway );
			$title       = $this->sut->get_title( $gateway );
			$details     = $this->sut->get_plugin_details( $gateway );
			$icon        = $this->sut->get_icon( $gateway );
			$description = $this->sut->get_description( $gateway );
		} finally {
			$this->swap_wallet_container( $previous );
		}

		$this->assertTrue( $needs_setup, 'The list must offer Finish setup' );
		$this->assertFalse( $connected );
		$this->assertFalse( $onboarded );
		$this->assertSame( $url, $onboarding );
		$this->assertSame( $url, $settings );
		$this->assertSame( 'PayPal Wallet', $title );
		$this->assertSame( '', $details['file'], 'The placeholder row has no plugin to deactivate' );
		$this->assertSame( plugins_url( 'assets/images/onboarding/icons/paypal.svg', WC_PLUGIN_FILE ), $icon );
		$this->assertSame( 'Offer PayPal, Pay Later, and Venmo (US only) at checkout.', $description );
	}

	/**
	 * @testdox Should leave the settings and onboarding URLs of the extension's gateway untouched.
	 */
	public function test_non_placeholder_urls_are_unchanged(): void {
		$this->pin_native_ownership();
		$gateway = $this->fake_ppcp_gateway();

		$base = new PaymentGateway( wc_get_container()->get( LegacyProxy::class ) );

		$this->assertSame( $base->get_settings_url( $gateway ), $this->sut->get_settings_url( $gateway ) );
		$this->assertSame( $base->get_onboarding_url( $gateway ), $this->sut->get_onboarding_url( $gateway ) );
	}

	/**
	 * The wallet does not boot on update.php, on the extension's activation request, or when the extension runs from another folder, while the arbiter still says native owns the site.
	 *
	 * @testdox Should read the extension's container when core owns the site but the wallet did not boot.
	 */
	public function test_uses_the_extension_container_when_core_owns_but_the_wallet_did_not_boot(): void {
		$this->pin_native_ownership();
		$gateway  = $this->fake_ppcp_gateway();
		$previous = $this->swap_wallet_container( null );

		// Stand-in for the extension's PPCP class, which is not installed in the test environment. It serves the container set here and throws without one, as the real one does.
		if ( ! class_exists( '\WooCommerce\PayPalCommerce\PPCP', false ) ) {
			eval( 'namespace WooCommerce\PayPalCommerce; class PPCP { public static $container = null; public static function container() { if ( ! self::$container ) { throw new \LogicException( "No PPCP container" ); } return self::$container; } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Declares a stand-in for a class from a plugin that is not installed here.
		}
		$extension_ppcp = '\WooCommerce\PayPalCommerce\PPCP';
		if ( ! property_exists( $extension_ppcp, 'container' ) ) {
			$this->swap_wallet_container( $previous );
			$this->markTestSkipped( 'The real extension is loaded, so a stand-in container cannot be injected.' );
		}
		$extension_ppcp::$container = $this->fake_container_with_connection_state( false, true );

		try {
			$connected = $this->sut->is_account_connected( $gateway );
			$test_mode = $this->sut->is_in_test_mode( $gateway );
		} finally {
			$extension_ppcp::$container = null;
			$this->swap_wallet_container( $previous );
		}

		$this->assertFalse( $connected, 'The extension container says not connected, unlike the base provider default of true' );
		$this->assertTrue( $test_mode, 'The extension container says sandbox, unlike the base provider default' );
	}

	/**
	 * @testdox Should add the notice a filter returns to the row details as `_notice`, and nothing for a null or non-array answer.
	 * @testWith ["array", true]
	 *           ["null", false]
	 *           ["string", false]
	 *
	 * @param string $kind     What the filter returns.
	 * @param bool   $expected Whether `_notice` is present.
	 */
	public function test_adds_the_notice_from_the_filter( string $kind, bool $expected ): void {
		$gateway = $this->fake_ppcp_gateway();
		$notice  = array(
			'title'        => 'A title',
			'text'         => 'Some text',
			'action_label' => 'Do it',
			'action_url'   => 'https://example.com/do-it',
			'dismissible'  => true,
		);
		$seen    = array();
		add_filter(
			'woocommerce_paypal_wallet_provider_notice',
			static function ( $current, $gateway_id ) use ( $kind, $notice, &$seen ) {
				$seen = array( $current, $gateway_id );
				if ( 'array' === $kind ) {
					return $notice;
				}
				return 'string' === $kind ? 'not an array' : null;
			},
			10,
			2
		);

		$details = $this->sut->get_details( $gateway );

		$this->assertSame( array( null, 'ppcp-gateway' ), $seen, 'The filter starts from null and names the gateway' );
		$this->assertSame( $expected, array_key_exists( '_notice', $details ) );
		if ( $expected ) {
			$this->assertSame( $notice, $details['_notice'] );
		}
	}

	/**
	 * @testdox Should report the account as not connected while collecting, connected once the platform is connected, and leave other stores alone.
	 */
	public function test_account_connected_is_false_while_collecting(): void {
		$this->pin_native_ownership();
		$gateway  = $this->fake_ppcp_gateway();
		$previous = $this->swap_wallet_container( $this->fake_container_with_connection_state( true, true ) );

		try {
			$before = $this->sut->is_account_connected( $gateway );
			update_option( 'woocommerce_paypal_wallet_collecting', array( 'payee_email' => 'payee@example.com' ) );
			$collecting = $this->sut->is_account_connected( $gateway );
			update_option( 'woocommerce_paypal_wallet_platform', array( 'merchant_id' => 'M2' ) );
			$platform = $this->sut->is_account_connected( $gateway );
		} finally {
			delete_option( 'woocommerce_paypal_wallet_collecting' );
			delete_option( 'woocommerce_paypal_wallet_platform' );
			$this->swap_wallet_container( $previous );
		}

		$this->assertTrue( $before, 'No collecting state: the wallet\'s own answer' );
		$this->assertFalse( $collecting, 'Collecting: the badge reads Action needed from the first request' );
		$this->assertTrue( $platform, 'Platform connected: the wallet\'s own answer' );
	}

	/**
	 * @testdox Should run no query on the wallet options when the store has no wallet history.
	 */
	public function test_account_connected_runs_no_option_query_without_wallet_history(): void {
		$this->pin_native_ownership();
		$gateway  = $this->fake_ppcp_gateway();
		$previous = $this->swap_wallet_container( $this->fake_container_with_connection_state( true, true ) );
		wp_load_alloptions();
		$queries  = array();
		$recorder = static function ( $sql ) use ( &$queries ) {
			$queries[] = (string) $sql;
			return $sql;
		};
		add_filter( 'query', $recorder );

		try {
			$connected = $this->sut->is_account_connected( $gateway );
		} finally {
			remove_filter( 'query', $recorder );
			$this->swap_wallet_container( $previous );
		}

		$this->assertTrue( $connected );
		foreach ( $queries as $sql ) {
			$this->assertStringNotContainsString( 'woocommerce_paypal_wallet', $sql, 'No query for the collecting or platform option' );
		}
	}

	/**
	 * @testdox Should give the PayPal row both the setup notice and an account that is not connected, on a collecting store with a first order.
	 */
	public function test_collecting_row_carries_the_notice_and_the_action_needed_state(): void {
		$this->pin_native_ownership();
		$gateway  = $this->fake_ppcp_gateway();
		$previous = $this->swap_wallet_container( $this->fake_container_with_connection_state( true, true ) );
		update_option( 'woocommerce_paypal_wallet_collecting', array( 'payee_email' => 'payee@example.com' ), true );
		add_option( 'woocommerce_paypal_wallet_first_order', 7, '', true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		( new OwnerIndependent() )->register();

		try {
			$details = $this->sut->get_details( $gateway );
		} finally {
			wp_set_current_user( 0 );
			delete_option( 'woocommerce_paypal_wallet_collecting' );
			delete_option( 'woocommerce_paypal_wallet_first_order' );
			$this->swap_wallet_container( $previous );
		}

		$this->assertFalse( $details['state']['account_connected'] );
		$this->assertSame( 'Complete setup to receive your payment', $details['_notice']['title'] );
		$this->assertSame( 'A customer placed an order and paid using PayPal Wallet. To receive the payment, connect PayPal Wallet to your store and complete the setup.', $details['_notice']['text'] );
		$this->assertSame( 'Complete setup', $details['_notice']['action_label'] );
		$this->assertSame( PayPalWalletBootstrap::get_settings_url(), $details['_notice']['action_url'] );
	}

	/**
	 * @testdox Should leave the account state alone while collecting when the gateway is not core provided.
	 */
	public function test_account_connected_is_unchanged_for_a_gateway_core_does_not_provide(): void {
		$gateway = $this->fake_ppcp_gateway();
		$before  = $this->sut->is_account_connected( $gateway );
		update_option( 'woocommerce_paypal_wallet_collecting', array( 'payee_email' => 'payee@example.com' ) );

		try {
			$collecting = $this->sut->is_account_connected( $gateway );
		} finally {
			delete_option( 'woocommerce_paypal_wallet_collecting' );
		}

		$this->assertSame( $before, $collecting );
	}
}
