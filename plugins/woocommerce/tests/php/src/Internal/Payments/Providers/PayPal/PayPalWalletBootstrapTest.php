<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal;

use Automattic\WooCommerce\Admin\Features\OnboardingTasks\TaskLists;
use Automattic\WooCommerce\Blocks\AssetsController;
use Automattic\WooCommerce\Blocks\Package as BlocksPackage;
use Automattic\WooCommerce\Internal\Features\BlockEditorUnifiedAssets;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\CollectingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\RefundLock;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\DormantPayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletBootstrap;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\Migration\MigrationManager;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\SettingsModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WCGatewayModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FixedHeldOrders;
use WC_Unit_Test_Case;

/**
 * Tests for the PayPalWalletBootstrap class.
 */
class PayPalWalletBootstrapTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var PayPalWalletBootstrap
	 */
	private $sut;

	/**
	 * Saved request state, restored in tearDown.
	 *
	 * @var array
	 */
	private $saved_request = array();

	/**
	 * Save the request globals the activation guard reads.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->saved_request = array(
			'post'    => $_POST, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Saving test state.
			'get'     => $_GET, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Saving test state.
			'request' => $_REQUEST, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Saving test state.
			'pagenow' => $GLOBALS['pagenow'] ?? null,
			'server'  => $_SERVER,
			'screen'  => $GLOBALS['current_screen'] ?? null,
			'scripts' => $GLOBALS['wp_scripts'] ?? null,
		);
	}

	/**
	 * Restore the request globals.
	 */
	public function tearDown(): void {
		$_POST    = $this->saved_request['post'];
		$_GET     = $this->saved_request['get'];
		$_REQUEST = $this->saved_request['request'];
		if ( null === $this->saved_request['pagenow'] ) {
			unset( $GLOBALS['pagenow'] );
		} else {
			$GLOBALS['pagenow'] = $this->saved_request['pagenow']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the admin page.
		}
		$_SERVER = $this->saved_request['server'];
		if ( null === $this->saved_request['screen'] ) {
			unset( $GLOBALS['current_screen'] );
		} else {
			$GLOBALS['current_screen'] = $this->saved_request['screen']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the screen.
		}
		if ( null === $this->saved_request['scripts'] ) {
			unset( $GLOBALS['wp_scripts'] );
		} else {
			$GLOBALS['wp_scripts'] = $this->saved_request['scripts']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the script registry.
		}
		parent::tearDown();
	}

	/**
	 * Simulate a plugins.php request with the given query args.
	 *
	 * @param array $args Query args.
	 * @param bool  $post Whether the args arrive by POST (bulk form) rather than GET (link).
	 */
	private function set_plugins_request( array $args, bool $post = false ): void {
		$_GET               = $post ? array() : $args;
		$_POST              = $post ? $args : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Simulating a request.
		$_REQUEST           = $args;
		$GLOBALS['pagenow'] = 'plugins.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the admin page.
	}

	/**
	 * Build the SUT with an arbiter stub that answers as instructed.
	 *
	 * @param bool   $native_owns    Whether the arbiter should say native owns the site.
	 * @param string $owner          The runtime owner the arbiter reports.
	 * @param bool   $native_enabled Whether the arbiter reports native as enabled.
	 */
	private function build_sut( bool $native_owns, string $owner = PayPalWalletRuntimeArbiter::OWNER_NONE, bool $native_enabled = false ): void {
		$arbiter = $this->getMockBuilder( PayPalWalletRuntimeArbiter::class )
			->onlyMethods( array( 'should_native_register', 'get_runtime_owner', 'is_native_enabled' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( $native_owns );
		$arbiter->method( 'get_runtime_owner' )->willReturn( $owner );
		$arbiter->method( 'is_native_enabled' )->willReturn( $native_enabled );

		$this->sut = new PayPalWalletBootstrap();
		$this->sut->init( $arbiter );
	}

	/**
	 * Store the shared settings option as a connected merchant, in the shape the wallet's GeneralSettings model saves it.
	 *
	 * The wallet derives "connected" from the merchant email, merchant ID, client ID and client secret all being set.
	 */
	private function set_connected_merchant_option(): void {
		update_option(
			'woocommerce-ppcp-data-common',
			array(
				'merchant_connected' => true,
				'sandbox_merchant'   => true,
				'merchant_id'        => 'TESTMERCHANTID',
				'merchant_email'     => 'merchant@example.com',
				'client_id'          => 'test-client-id',
				'client_secret'      => 'test-client-secret',
			)
		);
	}

	/**
	 * Count the callbacks attached to every hook, to prove a request attached nothing.
	 *
	 * @return array<string, int>
	 */
	private function count_hook_callbacks(): array {
		$counts = array();
		foreach ( $GLOBALS['wp_filter'] as $hook => $hook_object ) {
			$counts[ $hook ] = array_sum( array_map( 'count', $hook_object->callbacks ) );
		}
		return $counts;
	}

	/**
	 * The class that owns a hook callback: the object or class of a method callable, the scope class of a closure, or the
	 * function name of a plain function.
	 *
	 * @param mixed $function_callback The callback as stored in the hook.
	 * @return string
	 */
	private function get_callback_owner( $function_callback ): string {
		if ( $function_callback instanceof \Closure ) {
			$scope = ( new \ReflectionFunction( $function_callback ) )->getClosureScopeClass();
			return null === $scope ? 'closure without a scope class' : $scope->getName();
		}
		if ( is_array( $function_callback ) ) {
			return is_object( $function_callback[0] ) ? get_class( $function_callback[0] ) : (string) $function_callback[0];
		}
		return is_string( $function_callback ) ? $function_callback : 'unknown callback';
	}

	/**
	 * @testdox Should hook plugins_loaded at priority 10 when registered.
	 */
	public function test_register_hooks_plugins_loaded(): void {
		$this->build_sut( false );
		$this->sut->register();

		$this->assertSame( 10, has_action( 'plugins_loaded', array( $this->sut, 'maybe_boot' ) ) );
	}

	/**
	 * @testdox Should not boot and not add wallet filters when the extension owns the site.
	 */
	public function test_does_not_boot_when_extension_owns(): void {
		$this->build_sut( false );
		$this->sut->maybe_boot();

		$this->assertFalse( $this->sut->is_booted() );
		$this->assertFalse( has_filter( 'woocommerce_paypal_payments_modules' ), 'No wallet filter must be added while dormant' );
		$this->assertFalse( has_filter( 'woocommerce.feature-flags.woocommerce_paypal_payments.card_fields_enabled' ) );
	}

	/**
	 * @testdox Should keep the PayPal webhooks across a hand-back when the extension owns and native is enabled.
	 */
	public function test_keeps_webhooks_on_handback_when_extension_owns_and_native_is_enabled(): void {
		$this->build_sut( false, PayPalWalletRuntimeArbiter::OWNER_EXTENSION, true );
		$this->sut->maybe_boot();

		$this->assertFalse( $this->sut->is_booted() );
		$this->assertSame( 10, has_filter( 'woocommerce_paypal_payments_skip_webhook_unregister_on_deactivate', '__return_true' ), 'Core will own the next request, so the extension must not delete the webhooks' );
	}

	/**
	 * @testdox Should let the extension delete its webhooks when native is not enabled.
	 */
	public function test_does_not_keep_webhooks_when_native_is_disabled(): void {
		$this->build_sut( false, PayPalWalletRuntimeArbiter::OWNER_EXTENSION, false );
		$this->sut->maybe_boot();

		$this->assertFalse( has_filter( 'woocommerce_paypal_payments_skip_webhook_unregister_on_deactivate' ) );
	}

	/**
	 * @testdox Should keep the PayPal webhooks even when the extension's own copy is already loaded, as on its deactivation request.
	 */
	public function test_keeps_webhooks_when_extension_is_loaded_and_native_is_enabled(): void {
		$sut = $this->build_guarded_sut( 'wp_parse_args', false, PayPalWalletRuntimeArbiter::OWNER_EXTENSION, true );
		$sut->maybe_boot();

		$this->assertFalse( $sut->is_booted() );
		$this->assertSame( 10, has_filter( 'woocommerce_paypal_payments_skip_webhook_unregister_on_deactivate', '__return_true' ), 'The block must run before the loaded-elsewhere return' );
	}

	/**
	 * @testdox Should not boot when the request activates the extension.
	 */
	public function test_does_not_boot_when_activating_extension(): void {
		$this->build_sut( true );
		$this->set_plugins_request(
			array(
				'action' => 'activate',
				'plugin' => PayPalWalletRuntimeArbiter::EXTENSION_PLUGIN_FILE,
			)
		);

		$this->sut->maybe_boot();

		$this->assertTrue( $this->sut->is_extension_activation_request() );
		$this->assertFalse( $this->sut->is_booted() );
		$this->assertFalse( has_filter( 'woocommerce_paypal_payments_modules' ) );
	}

	/**
	 * @testdox Should not boot when a bulk activation includes the extension.
	 */
	public function test_does_not_boot_when_bulk_activating_extension(): void {
		$this->build_sut( true );
		$this->set_plugins_request(
			array(
				'action'  => 'activate-selected',
				'checked' => array( 'akismet/akismet.php', PayPalWalletRuntimeArbiter::EXTENSION_PLUGIN_FILE ),
			),
			true
		);

		$this->sut->maybe_boot();

		$this->assertTrue( $this->sut->is_extension_activation_request() );
		$this->assertFalse( $this->sut->is_booted() );
		$this->assertFalse( has_filter( 'woocommerce_paypal_payments_modules' ) );
	}

	/**
	 * @testdox Should not treat activating another plugin, or another page, as an extension activation.
	 */
	public function test_activation_guard_ignores_other_plugins_and_pages(): void {
		$this->build_sut( true );

		$this->set_plugins_request(
			array(
				'action' => 'activate',
				'plugin' => 'akismet/akismet.php',
			)
		);
		$this->assertFalse( $this->sut->is_extension_activation_request() );

		$this->set_plugins_request(
			array(
				'action'  => 'activate-selected',
				'checked' => array( 'akismet/akismet.php' ),
			)
		);
		$this->assertFalse( $this->sut->is_extension_activation_request() );

		$this->set_plugins_request(
			array(
				'action' => 'activate',
				'plugin' => PayPalWalletRuntimeArbiter::EXTENSION_PLUGIN_FILE,
			)
		);
		$GLOBALS['pagenow'] = 'index.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the admin page.
		$this->assertFalse( $this->sut->is_extension_activation_request() );
	}

	/**
	 * @testdox Should ignore a nested checked array without raising notices.
	 */
	public function test_activation_guard_ignores_nested_checked_entries(): void {
		$this->build_sut( true );
		$this->set_plugins_request(
			array(
				'action'  => 'activate-selected',
				'checked' => array( array( PayPalWalletRuntimeArbiter::EXTENSION_PLUGIN_FILE ) ),
			),
			true
		);

		$this->assertFalse( $this->sut->is_extension_activation_request() );
	}

	/**
	 * @testdox Should not treat a request without an action as an extension activation.
	 */
	public function test_activation_guard_is_false_without_action(): void {
		$this->build_sut( true );
		$this->set_plugins_request( array() );

		$this->assertFalse( $this->sut->is_extension_activation_request() );
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should boot the forked wallet once when native owns the site, registering the PayPal gateway and only wallet modules.
	 */
	public function test_boots_the_forked_wallet_when_native_owns(): void {
		$this->set_connected_merchant_option();
		$this->build_sut( true );

		$this->sut->maybe_boot();
		$this->sut->maybe_boot(); // Second call must be a no-op.

		$this->assertTrue( $this->sut->is_booted() );
		$container = \Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PPCP::container();
		$this->assertTrue( $container->has( 'wcgateway.paypal-gateway' ), 'The gateway service must exist' );
		$this->assertSame( \Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WalletProperties::EXTENSION_VERSION, $container->get( 'ppcp.plugin-version' ) );
		$this->assertFalse( $container->has( 'ppcp.path-to-plugin-main-file' ), 'The fork has no plugin main file' );
		$absent_ids = array(
			'applepay.available',
			'googlepay.available',
			'axo.available',
			'axoblock.available',
			'card-fields.eligibility.check',
			'ppcp-local-apms.available',
			'order-tracking.available',
			'paypal-subscriptions.available',
			'agentic.logger.default', // The store-sync module's prefix.
		);
		foreach ( $absent_ids as $id ) {
			$this->assertFalse( $container->has( $id ), "$id must not be registered: its module is not part of the fork" );
		}
		$removed_ids = array( 'api.endpoint.billing-plans', 'api.endpoint.catalog-products', 'api.factory.plan', 'api.factory.product', 'api.factory.billing-cycle', 'settings.data.fastlane', 'settings.service.data-migration.fastlane', 'settings.rest.migrate_to_acdc', 'settings.rest.agentic_beta_banner', 'settings.service.agentic-beta-eligibility', 'compat.assets', 'compat.asset_getter', 'ppcp.module-availability' );
		foreach ( $removed_ids as $id ) {
			$this->assertFalse( $container->has( $id ), "$id belongs to a feature the wallet does not ship and must not be registered" );
		}
		$this->assertInstanceOf( \Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\FeaturesEligibilityService::class, $container->get( 'settings.service.features_eligibilities' ) );
		$this->assertInstanceOf( \Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\PaymentMethodsEligibilityService::class, $container->get( 'settings.service.payment_methods_eligibilities' ) );
		foreach ( PayPalWalletBootstrap::get_extension_constants() as $name => $value ) {
			$this->assertTrue( defined( $name ), "$name must be defined after boot" );
		}
		$this->assertFalse( has_filter( 'woocommerce.feature-flags.woocommerce_paypal_payments.vault_component_enabled' ), 'Wallet flags are not forced' );
		$this->assertFalse( has_filter( 'woocommerce_paypal_payments_gateway_group_cards' ), 'The card group is empty by definition, with no filter attached' );
		$this->assertFalse( has_filter( 'woocommerce_paypal_payments_gateway_group_apm' ), 'The APM group is empty by definition, with no filter attached' );
		// The settings module filters this hook itself, so the shell must have attached nothing: the only owner is that module.
		$owners = array();
		foreach ( $GLOBALS['wp_filter']['woocommerce_paypal_payments_payment_methods']->callbacks ?? array() as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$owners[] = $this->get_callback_owner( $callback['function'] );
			}
		}
		$this->assertSame( array( SettingsModule::class ), array_values( array_unique( $owners ) ), 'Only the settings module filters the payment methods data, never the shell' );

		$methods = $container->get( 'settings.data.definition.methods' );
		$this->assertSame( array( 'ppcp-gateway', 'venmo', 'pay-later' ), array_column( $methods->group_paypal_methods(), 'id' ), 'The PayPal group lists the wallet methods and no card button' );
		$this->assertSame( array(), $methods->group_card_methods(), 'The cards group must be empty' );
		$this->assertSame( array(), $methods->group_apms(), 'The APM group must be empty' );

		// What is offered: card and wallet gateways are connection-gated by the extension's logic and the test store is not connected, so only the main PayPal gateway is observable.
		$offered_ids = array_map(
			static function ( $gateway ): string {
				return $gateway->id;
			},
			apply_filters( 'woocommerce_payment_gateways', array() ) // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		);
		$this->assertContains( 'ppcp-gateway', $offered_ids, 'The PayPal gateway must be offered' );
		$not_offered = array( 'ppcp-applepay', 'ppcp-googlepay', 'ppcp-axo-gateway', 'ppcp-credit-card-gateway', 'ppcp-card-button-gateway', 'ppcp-ideal', 'ppcp-bancontact', 'ppcp-pwc' );
		$this->assertSame( array(), array_values( array_intersect( $not_offered, $offered_ids ) ), 'No card, wallet or local APM gateway may be offered' );
	}

	/**
	 * The extension saves DTO objects in the options, and a stored object carries its class name, so core has to read the extension's class names.
	 *
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should read a Pay Later messaging option the extension stored, and write it back under the extension's class name.
	 */
	public function test_reads_and_writes_the_extensions_stored_objects(): void {
		$this->set_connected_merchant_option();
		$this->build_sut( true );
		$this->sut->maybe_boot();

		$messaging = unserialize( 'O:60:"WooCommerce\\PayPalCommerce\\Settings\\DTO\\PayLaterMessagingDTO":9:{s:8:"location";s:4:"cart";s:7:"enabled";b:1;s:6:"layout";s:4:"flex";s:9:"logo_type";s:6:"inline";s:13:"logo_position";s:4:"left";s:10:"text_color";s:5:"black";s:9:"text_size";s:2:"12";s:10:"flex_color";s:4:"blue";s:10:"flex_ratio";s:3:"8x1";}' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Fixture of the stored format.
		update_option( 'woocommerce-ppcp-data-paylater-messaging', array( 'cart' => $messaging ) );

		$settings = \Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PPCP::container()->get( 'settings.data.paylater-messaging-settings' );
		$settings->load();
		$cart = $settings->get_cart();

		$this->assertInstanceOf( \Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\DTO\PayLaterMessagingDTO::class, $cart );
		$this->assertSame( 'flex', $cart->layout, 'The stored values must come through' );

		$settings->save();
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'woocommerce-ppcp-data-paylater-messaging' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the stored bytes.
		$this->assertStringContainsString( 'O:60:"WooCommerce\\PayPalCommerce\\Settings\\DTO\\PayLaterMessagingDTO"', $raw, 'Core must write the extension\'s class name' );
		$this->assertStringNotContainsString( 'Automattic', $raw, 'No wallet-namespace class name may reach the stored value' );
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should let the modules filter add a module before boot.
	 */
	public function test_applies_the_modules_filter(): void {
		$seen = null;
		add_filter(
			'woocommerce_paypal_payments_modules',
			static function ( $modules ) use ( &$seen ) {
				$seen = $modules;
				return $modules;
			}
		);
		$this->set_connected_merchant_option();
		$this->build_sut( true );

		$this->sut->maybe_boot();

		remove_all_filters( 'woocommerce_paypal_payments_modules' );
		$this->assertIsArray( $seen, 'The modules filter must run with the module list' );
		$this->assertInstanceOf( \Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PluginModule::class, $seen[0] );
		$this->assertTrue( $this->sut->is_booted() );
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should ignore filtered modules that are not Modularity modules instead of fataling.
	 */
	public function test_ignores_filtered_modules_that_are_not_modularity_modules(): void {
		add_filter(
			'woocommerce_paypal_payments_modules',
			static function ( $modules ) {
				$modules[] = new \stdClass();
				$modules[] = 'not-a-module';
				$modules[] = new class() {
					/**
					 * Looks like a module by name but does not implement the interface.
					 *
					 * @return string
					 */
					public function id(): string {
						return 'foreign';
					}
				};
				return $modules;
			}
		);
		$this->set_connected_merchant_option();
		$this->build_sut( true );

		try {
			$this->sut->maybe_boot();
		} finally {
			remove_all_filters( 'woocommerce_paypal_payments_modules' );
		}

		$this->assertTrue( $this->sut->is_booted(), 'A foreign filtered element must not stop the boot' );
		$this->assertTrue( \Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PPCP::container()->has( 'wcgateway.paypal-gateway' ), 'The wallet modules must still be registered' );
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should not build the wallet package when the merchant is not connected and the request is not a wallet admin request.
	 */
	public function test_stays_dormant_when_not_connected(): void {
		delete_option( 'woocommerce-ppcp-data-common' );
		$GLOBALS['wp_scripts'] = new \WP_Scripts(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A registry no earlier test has filled; tearDown restores the original.
		$this->build_sut( true );
		$hooks_before = $this->count_hook_callbacks();

		$this->sut->maybe_boot();

		$this->assertTrue( $this->sut->is_dormant() );
		$this->assertFalse( $this->sut->is_booted() );
		$this->assertSame( $hooks_before, $this->count_hook_callbacks(), 'A dormant front-end request must attach no hook at all' );
		foreach ( array_keys( wp_scripts()->registered ) as $handle ) {
			$this->assertStringStartsNotWith( 'ppcp-', $handle, "No wallet handle may be registered when dormant ($handle)" );
			$this->assertStringStartsNotWith( 'wc-ppcp-', $handle, "No wallet handle may be registered when dormant ($handle)" );
		}
		$offered_ids = array_map(
			static function ( $gateway ): string {
				return is_object( $gateway ) ? (string) ( $gateway->id ?? '' ) : (string) $gateway;
			},
			apply_filters( 'woocommerce_payment_gateways', array() ) // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		);
		$this->assertNotContains( 'ppcp-gateway', $offered_ids, 'A dormant wallet registers no gateway on a front-end request' );
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should leave no wallet script handle registered on a dormant admin request, counting the handles core's blocks asset registration derives from the built files.
	 */
	public function test_registers_no_wallet_handle_on_a_dormant_admin_request(): void {
		delete_option( 'woocommerce-ppcp-data-common' );
		// The deprecated-handle shim that scans the blocks build only runs with unified editor assets on.
		update_option( BlockEditorUnifiedAssets::OPTION_NAME, 'yes' );
		set_current_screen( 'woocommerce_page_wc-settings' ); // is_admin() is true on a WP_Screen of the admin.
		$GLOBALS['wp_scripts'] = new \WP_Scripts(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A registry no earlier test has filled; tearDown restores the original.
		$this->build_sut( true );

		$this->sut->maybe_boot();
		BlocksPackage::container()->get( AssetsController::class )->register_assets();

		$this->assertTrue( $this->sut->is_dormant() );
		$this->assertArrayHasKey( 'wc-blocks', wp_scripts()->registered, 'The blocks assets must have been registered' );
		foreach ( array_keys( wp_scripts()->registered ) as $handle ) {
			$this->assertStringStartsNotWith( 'ppcp-', $handle, "No wallet handle may be registered when dormant ($handle)" );
			$this->assertStringStartsNotWith( 'wc-ppcp-', $handle, "No wallet handle may be registered when dormant ($handle)" );
		}
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should register the placeholder gateway on an admin request while dormant.
	 */
	public function test_registers_the_placeholder_gateway_in_admin_when_dormant(): void {
		delete_option( 'woocommerce-ppcp-data-common' );
		set_current_screen( 'woocommerce_page_wc-settings' ); // is_admin() is true on a WP_Screen of the admin.
		$this->build_sut( true );

		$this->sut->maybe_boot();

		$this->assertFalse( $this->sut->is_booted() );
		$gateways = apply_filters( 'woocommerce_payment_gateways', array() ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		$this->assertContains( DormantPayPalGateway::class, $gateways );
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should register the placeholder gateway on a wc-admin REST request while dormant, and on no other REST request.
	 *
	 * @testWith ["/wc-admin/settings/payments/providers", true]
	 *           ["/wc-analytics/reports/orders", true]
	 *           ["/wc/v3/orders", false]
	 *           ["/wc/store/v1/cart", false]
	 *
	 * @param string $route    The REST route of the request.
	 * @param bool   $expected Whether the placeholder is registered.
	 */
	public function test_registers_the_placeholder_gateway_for_wc_admin_rest_requests_only( string $route, bool $expected ): void {
		delete_option( 'woocommerce-ppcp-data-common' );
		$_SERVER['REQUEST_URI'] = '/' . rest_get_url_prefix() . $route;
		$this->build_sut( true );

		$this->sut->maybe_boot();

		$this->assertFalse( $this->sut->is_booted() );
		$gateways = apply_filters( 'woocommerce_payment_gateways', array() ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		$this->assertSame( $expected, in_array( DormantPayPalGateway::class, $gateways, true ) );
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should classify a request by the REST prefix segment wherever it sits in the path, as index permalinks and subdirectory installs put it.
	 *
	 * @testWith ["/index.php/wp-json/wc/v3/wc_paypal/onboarding", false, false]
	 *           ["/index.php/wp-json/paypal/v1/incoming", false, false]
	 *           ["/sub/index.php/wp-json/wc/v3/wc_paypal/onboarding?x=1", false, false]
	 *           ["/index.php/wp-json/wc-admin/settings/payments/providers", true, true]
	 *           ["/index.php/wp-json/wc/v3/orders", true, false]
	 *           ["/index.php/shop/", true, false]
	 *
	 * @param string $uri                 The request URI.
	 * @param bool   $expected_dormant    Whether the request is dormant.
	 * @param bool   $expected_placeholder Whether the placeholder gateway is registered.
	 */
	public function test_classifies_rest_requests_under_an_index_permalink_path( string $uri, bool $expected_dormant, bool $expected_placeholder ): void {
		delete_option( 'woocommerce-ppcp-data-common' );
		$_SERVER['REQUEST_URI'] = $uri;
		$this->build_sut( true );

		$this->assertSame( $expected_dormant, $this->sut->is_dormant() );
		$this->sut->maybe_boot();

		$gateways = apply_filters( 'woocommerce_payment_gateways', array() ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		$this->assertSame( $expected_placeholder, in_array( DormantPayPalGateway::class, $gateways, true ) );
		$this->assertSame( ! $expected_dormant, $this->sut->is_booted() );
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should boot for a merchant connected only through the legacy settings, on a front-end request.
	 */
	public function test_boots_for_a_merchant_connected_only_in_the_legacy_settings(): void {
		delete_option( 'woocommerce-ppcp-data-common' );
		update_option(
			'woocommerce-ppcp-settings',
			array(
				'client_id'     => 'legacy-client-id',
				'client_secret' => 'legacy-client-secret',
				'merchant_id'   => 'LEGACYMERCHANT',
			)
		);
		$this->build_sut( true );

		$this->assertFalse( $this->sut->is_dormant(), 'The legacy connection must boot the wallet so it can migrate' );
		$this->sut->maybe_boot();
		$this->assertTrue( $this->sut->is_booted() );
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should stay dormant when the migration is done, even with full legacy credentials and a shared option that says not connected.
	 */
	public function test_ignores_the_legacy_settings_once_the_migration_is_done(): void {
		delete_option( 'woocommerce-ppcp-data-common' );
		update_option(
			'woocommerce-ppcp-settings',
			array(
				'client_id'     => 'legacy-client-id',
				'client_secret' => 'legacy-client-secret',
				'merchant_id'   => 'LEGACYMERCHANT',
			)
		);
		update_option( MigrationManager::OPTION_NAME_MIGRATION_IS_DONE, '1' ); // The migration stores true; the next request reads it back as the string '1', which is what the wallet compares.
		$this->build_sut( true );

		$this->assertTrue( $this->sut->is_dormant(), 'After the migration only the shared option says whether the merchant is connected' );
		$this->sut->maybe_boot();
		$this->assertFalse( $this->sut->is_booted() );
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should stay dormant when the legacy settings lack a credential the migration needs.
	 *
	 * @testWith ["client_id"]
	 *           ["client_secret"]
	 *           ["merchant_id"]
	 *
	 * @param string $missing The legacy key that is left out.
	 */
	public function test_legacy_connection_needs_every_migration_credential( string $missing ): void {
		delete_option( 'woocommerce-ppcp-data-common' );
		$legacy = array(
			'client_id'     => 'legacy-client-id',
			'client_secret' => 'legacy-client-secret',
			'merchant_id'   => 'LEGACYMERCHANT',
		);
		unset( $legacy[ $missing ] );
		update_option( 'woocommerce-ppcp-settings', $legacy );
		$this->build_sut( true );

		$this->assertTrue( $this->sut->is_dormant() );
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should not read the legacy settings once the shared settings say connected.
	 */
	public function test_does_not_read_the_legacy_settings_when_the_shared_settings_say_connected(): void {
		$this->set_connected_merchant_option();
		$legacy_reads = 0;
		add_filter(
			'pre_option_woocommerce-ppcp-settings',
			static function () use ( &$legacy_reads ) {
				++$legacy_reads;
				return array();
			}
		);
		$this->build_sut( true );

		$this->assertFalse( $this->sut->is_dormant() );
		$this->assertSame( 0, $legacy_reads, 'The legacy option is only a second source for a store the shared option calls not connected' );

		delete_option( 'woocommerce-ppcp-data-common' );
		$this->assertTrue( $this->sut->is_dormant(), 'Precondition for the spy: it answers not connected when it is consulted' );
		$this->assertSame( 1, $legacy_reads, 'The legacy option is consulted when the shared one says not connected' );
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should add the placeholder gateway once and never beside a gateway that already has the wallet's ID.
	 */
	public function test_placeholder_gateway_is_never_added_twice(): void {
		$this->build_sut( true );

		$once        = $this->sut->register_dormant_gateway( array() );
		$twice       = $this->sut->register_dormant_gateway( $once );
		$real        = new class() extends \WC_Payment_Gateway {
			/**
			 * Stand in for the wallet's real gateway.
			 */
			public function __construct() {
				$this->id = 'ppcp-gateway';
			}
		};
		$beside_real = $this->sut->register_dormant_gateway( array( $real ) );

		$this->assertSame( array( DormantPayPalGateway::class ), $once );
		$this->assertSame( array( DormantPayPalGateway::class ), $twice );
		$this->assertSame( array( $real ), $beside_real );
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should boot a not-connected wallet on its own settings page.
	 */
	public function test_boots_dormant_wallet_on_its_settings_page(): void {
		delete_option( 'woocommerce-ppcp-data-common' );
		set_current_screen( 'woocommerce_page_wc-settings' );
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$_GET['path'] = '/paypal-wallet';
		$this->build_sut( true );

		$this->sut->maybe_boot();

		$this->assertFalse( $this->sut->is_dormant() );
		$this->assertTrue( $this->sut->is_booted() );
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should enqueue the settings app on $description: $expected.
	 * @testWith ["the Payments settings route", {"page": "wc-settings", "tab": "checkout", "path": "/paypal-wallet"}, true]
	 *           ["another WooCommerce settings tab", {"page": "wc-settings", "tab": "shipping"}, false]
	 *           ["another route of the Payments settings app", {"page": "wc-settings", "tab": "checkout", "path": "/offline"}, false]
	 *
	 * @param string $description What the request is.
	 * @param array  $query       The query arguments of the request.
	 * @param bool   $expected    Whether the settings app is enqueued.
	 */
	public function test_enqueues_the_settings_app_on_its_route_only( string $description, array $query, bool $expected ): void {
		$this->set_connected_merchant_option();
		set_current_screen( 'woocommerce_page_wc-settings' );
		$_GET                  = $query;
		$GLOBALS['wp_scripts'] = new \WP_Scripts(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A registry no earlier case has enqueued into; tearDown restores the saved one.
		$this->build_sut( true );
		$this->sut->maybe_boot();
		$this->assertTrue( $this->sut->is_booted() );

		do_action( 'admin_enqueue_scripts', 'woocommerce_page_wc-settings' );

		$this->assertSame( $expected, wp_script_is( 'ppcp-admin-settings', 'enqueued' ), $description );
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should share the ownership flag with the admin client on an admin request while core owns the wallet.
	 */
	public function test_shares_the_ownership_flag_with_the_admin_app(): void {
		delete_option( 'woocommerce-ppcp-data-common' );
		set_current_screen( 'woocommerce_page_wc-settings' );
		$this->build_sut( true );

		$this->sut->maybe_boot();

		$this->assertSame( 10, has_filter( 'woocommerce_admin_shared_settings', array( $this->sut, 'share_ownership_with_admin_app' ) ) );
		$settings = $this->sut->share_ownership_with_admin_app( array( 'currentUserId' => 1 ) );
		$this->assertTrue( $settings['paypalWalletOwned'] );
		$this->assertSame( 1, $settings['currentUserId'], 'The other shared settings must be kept' );
		$this->assertSame( 'not an array', $this->sut->share_ownership_with_admin_app( 'not an array' ), 'A value that is not an array must pass through' );
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should not share the ownership flag while the extension owns the wallet.
	 */
	public function test_does_not_share_the_ownership_flag_when_the_extension_owns(): void {
		set_current_screen( 'woocommerce_page_wc-settings' );
		$this->build_sut( false, PayPalWalletRuntimeArbiter::OWNER_EXTENSION );

		$this->sut->maybe_boot();

		$this->assertFalse( has_filter( 'woocommerce_admin_shared_settings', array( $this->sut, 'share_ownership_with_admin_app' ) ) );
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should boot a not-connected wallet on the legacy gateway settings section.
	 */
	public function test_boots_dormant_wallet_on_its_legacy_settings_section(): void {
		delete_option( 'woocommerce-ppcp-data-common' );
		set_current_screen( 'woocommerce_page_wc-settings' );
		$_GET['page']    = 'wc-settings';
		$_GET['tab']     = 'checkout';
		$_GET['section'] = 'ppcp-gateway';
		$this->build_sut( true );

		$this->sut->maybe_boot();

		$this->assertTrue( $this->sut->is_booted() );
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should stay dormant on the Payments settings list and on another gateway's settings section.
	 */
	public function test_stays_dormant_on_other_settings_screens(): void {
		delete_option( 'woocommerce-ppcp-data-common' );
		set_current_screen( 'woocommerce_page_wc-settings' );
		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$this->build_sut( true );
		$this->assertTrue( $this->sut->is_dormant(), 'The Payments settings list is not a wallet surface' );

		$_GET['path'] = '/woopayments/onboarding';
		$this->assertTrue( $this->sut->is_dormant(), 'Another provider\'s route is not a wallet surface' );

		unset( $_GET['path'] );
		$_GET['section'] = 'bacs';
		$this->assertTrue( $this->sut->is_dormant(), 'Another gateway\'s section is not a wallet surface' );
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should boot a not-connected wallet for its REST namespaces.
	 *
	 * @testWith ["/wc/v3/wc_paypal/onboarding"]
	 *           ["/paypal/v1/incoming"]
	 *
	 * @param string $route The REST route of the request.
	 */
	public function test_boots_dormant_wallet_for_its_rest_namespace( string $route ): void {
		delete_option( 'woocommerce-ppcp-data-common' );
		$_SERVER['REQUEST_URI'] = '/' . rest_get_url_prefix() . $route;
		$this->build_sut( true );

		$this->sut->maybe_boot();

		$this->assertTrue( $this->sut->is_booted() );
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should boot a not-connected wallet for its REST route when only the plain-permalink rest_route query argument carries it.
	 */
	public function test_boots_dormant_wallet_for_a_plain_permalink_rest_route(): void {
		delete_option( 'woocommerce-ppcp-data-common' );
		$_GET['rest_route'] = '/wc/v3/wc_paypal/onboarding';
		$this->build_sut( true );

		$this->sut->maybe_boot();

		$this->assertFalse( $this->sut->is_dormant() );
		$this->assertTrue( $this->sut->is_booted() );
	}

	/**
	 * Each value routes to the wallet when it is a string, so the dormant outcome for an array is the guard's doing and
	 * not the request's. `sanitize_text_field()` also returns an empty string for an array, so this pins the outcome
	 * (dormant, no notice), not the `is_string()` check on its own.
	 *
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should ignore a path, section or REST route sent as an array and stay dormant instead of raising notices.
	 *
	 * @testWith ["path", "/paypal-wallet"]
	 *           ["section", "ppcp-gateway"]
	 *           ["rest_route", "/wc/v3/wc_paypal/onboarding"]
	 *
	 * @param string $key   The query argument sent as an array.
	 * @param string $value The string value that routes to the wallet.
	 */
	public function test_ignores_request_arguments_sent_as_arrays( string $key, string $value ): void {
		delete_option( 'woocommerce-ppcp-data-common' );
		set_current_screen( 'woocommerce_page_wc-settings' );
		$_GET['page'] = 'wc-settings';
		$this->build_sut( true );

		$_GET[ $key ] = $value;
		$this->assertFalse( $this->sut->is_dormant(), 'Control: the same argument as a string routes to the wallet' );

		$_GET[ $key ] = array( $value, '/paypal-wallet', 'ppcp-gateway', '/wc/v3/wc_paypal/onboarding' );
		$this->assertTrue( $this->sut->is_dormant() );
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should treat a request URI that is missing or not a string as no REST request.
	 *
	 * @testWith ["missing"]
	 *           ["array"]
	 *           ["integer"]
	 *
	 * @param string $shape How the request URI is malformed.
	 */
	public function test_ignores_a_request_uri_that_is_not_a_string( string $shape ): void {
		delete_option( 'woocommerce-ppcp-data-common' );
		unset( $_SERVER['REQUEST_URI'] );
		if ( 'array' === $shape ) {
			$_SERVER['REQUEST_URI'] = array( '/' . rest_get_url_prefix() . '/paypal/v1/incoming' );
		} elseif ( 'integer' === $shape ) {
			$_SERVER['REQUEST_URI'] = 5;
		}
		$this->build_sut( true );

		$this->assertTrue( $this->sut->is_dormant(), 'A malformed request URI is no wallet route' );
		$this->sut->maybe_boot();
		$this->assertFalse( $this->sut->is_booted() );
		$gateways = apply_filters( 'woocommerce_payment_gateways', array() ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		$this->assertNotContains( DormantPayPalGateway::class, $gateways, 'A malformed request URI is no wc-admin REST request' );
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should boot a connected wallet on any request.
	 */
	public function test_boots_connected_wallet_everywhere(): void {
		$this->set_connected_merchant_option();
		$this->build_sut( true );

		$this->sut->maybe_boot();

		$this->assertFalse( $this->sut->is_dormant() );
		$this->assertTrue( $this->sut->is_booted() );
		$gateways = apply_filters( 'woocommerce_payment_gateways', array() ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		$this->assertNotContains( DormantPayPalGateway::class, $gateways, 'The real gateway replaces the placeholder once connected' );
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should read an incomplete stored connection as not connected, whatever the stored merchant_connected flag says.
	 *
	 * @testWith ["merchant_email"]
	 *           ["merchant_id"]
	 *           ["client_id"]
	 *           ["client_secret"]
	 *
	 * @param string $missing The connection key that is left out.
	 */
	public function test_connection_needs_every_credential( string $missing ): void {
		$this->set_connected_merchant_option();
		$data = get_option( 'woocommerce-ppcp-data-common' );
		unset( $data[ $missing ] );
		update_option( 'woocommerce-ppcp-data-common', $data );
		$this->build_sut( true );

		$this->assertTrue( $this->sut->is_dormant(), 'The wallet derives connected from all four keys, not from the stored flag' );
	}

	/**
	 * A subclass points the guard at a function the test controls, so the real extension function is never declared here.
	 *
	 * @param string $function_name  The function name the guard looks for.
	 * @param bool   $native_owns    Whether the arbiter says native owns the site. Defaults to true so a skipped boot can only be the guard's doing.
	 * @param string $owner          The runtime owner the arbiter reports.
	 * @param bool   $native_enabled Whether the arbiter reports native as enabled.
	 * @return PayPalWalletBootstrap
	 */
	private function build_guarded_sut( string $function_name, bool $native_owns = true, string $owner = PayPalWalletRuntimeArbiter::OWNER_NONE, bool $native_enabled = false ): PayPalWalletBootstrap {
		$sut = new class( $function_name ) extends PayPalWalletBootstrap {
			/**
			 * Function name to look for.
			 *
			 * @var string
			 */
			private $function_name;

			/**
			 * Constructor.
			 *
			 * @param string $function_name The function name.
			 */
			public function __construct( string $function_name ) {
				$this->function_name = $function_name;
			}

			/**
			 * Return the test's function name.
			 *
			 * @return string
			 */
			protected function get_extension_init_function(): string {
				return $this->function_name;
			}
		};
		// Native ownership defaults to on so a skipped boot can only be the guard's doing.
		$arbiter = $this->getMockBuilder( PayPalWalletRuntimeArbiter::class )
			->onlyMethods( array( 'should_native_register', 'get_runtime_owner', 'is_native_enabled' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( $native_owns );
		$arbiter->method( 'get_runtime_owner' )->willReturn( $owner );
		$arbiter->method( 'is_native_enabled' )->willReturn( $native_enabled );
		$sut->init( $arbiter );
		return $sut;
	}

	/**
	 * @testdox Should report the extension as loaded elsewhere and skip the boot when its init function exists.
	 */
	public function test_skips_boot_when_extension_is_loaded_elsewhere(): void {
		$sut = $this->build_guarded_sut( 'wp_parse_args' ); // Any declared function stands in for the extension's init.

		$this->assertTrue( $sut->is_extension_loaded_elsewhere() );
		$sut->maybe_boot();
		$this->assertFalse( $sut->is_booted() );
	}

	/**
	 * @testdox Should not report the extension as loaded elsewhere when its init function is not declared.
	 */
	public function test_extension_not_loaded_elsewhere_when_function_is_missing(): void {
		$this->assertFalse( $this->build_guarded_sut( 'wc_test_no_such_extension_init' )->is_extension_loaded_elsewhere() );
		$this->build_sut( true );
		$this->assertFalse( $this->sut->is_extension_loaded_elsewhere(), 'The real extension function must not exist in the test process' );
	}

	/**
	 * Build the SUT with a collecting state whose held-orders count answers the given number.
	 *
	 * @param int $held_orders The number of held orders.
	 */
	private function build_sut_with_held_orders( int $held_orders ): void {
		$arbiter = $this->getMockBuilder( PayPalWalletRuntimeArbiter::class )
			->onlyMethods( array( 'should_native_register', 'get_runtime_owner', 'is_native_enabled' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( true );

		$this->sut = new class( $held_orders ) extends PayPalWalletBootstrap {
			/**
			 * The number of held orders.
			 *
			 * @var int
			 */
			private $held_orders;

			/**
			 * Constructor.
			 *
			 * @param int $held_orders The number of held orders.
			 */
			public function __construct( int $held_orders ) {
				$this->held_orders = $held_orders;
			}

			/**
			 * The collecting state, with the held-orders count the test chose.
			 *
			 * @return CollectingState
			 */
			protected function collecting_state(): CollectingState {
				return new CollectingState( new Options(), new FixedHeldOrders( $this->held_orders ) );
			}
		};
		$this->sut->init( $arbiter );
	}

	/**
	 * @testdox Should not be dormant when the store is collecting and the gateway is not disabled.
	 *
	 * @testWith ["missing"]
	 *           ["yes"]
	 *           ["no-key"]
	 *
	 * @param string $gateway_setting How the gateway's enabled setting is stored.
	 */
	public function test_collecting_store_is_not_dormant_unless_the_gateway_is_disabled( string $gateway_setting ): void {
		update_option( Options::COLLECTING, array( 'payee_email' => 'payee@example.com' ) );
		if ( 'yes' === $gateway_setting ) {
			update_option( 'woocommerce_ppcp-gateway_settings', array( 'enabled' => 'yes' ) );
		} elseif ( 'no-key' === $gateway_setting ) {
			update_option( 'woocommerce_ppcp-gateway_settings', array( 'title' => 'PayPal' ) );
		}
		$this->build_sut( true );

		$this->assertFalse( $this->sut->is_dormant() );
	}

	/**
	 * @testdox Should not be dormant when the stored gateway settings are not an array, as a missing row.
	 */
	public function test_collecting_store_with_malformed_gateway_settings_is_not_dormant(): void {
		update_option( Options::COLLECTING, array( 'payee_email' => 'payee@example.com' ) );
		update_option( 'woocommerce_ppcp-gateway_settings', 'garbage' );
		$this->build_sut( true );

		$this->assertFalse( $this->sut->is_dormant() );
	}

	/**
	 * @testdox Should be dormant when the store is served by the platform but the gateway is disabled.
	 *
	 * @testWith ["collecting"]
	 *           ["platform"]
	 *
	 * @param string $state The platform-served state.
	 */
	public function test_platform_served_store_is_dormant_while_the_gateway_is_disabled( string $state ): void {
		if ( 'collecting' === $state ) {
			update_option( Options::COLLECTING, array( 'payee_email' => 'payee@example.com' ) );
		} else {
			update_option( Options::PLATFORM, array( 'merchant_id' => 'M2' ) );
		}
		update_option( 'woocommerce_ppcp-gateway_settings', array( 'enabled' => 'no' ) );
		$this->build_sut( true );

		$this->assertTrue( $this->sut->is_dormant() );
	}

	/**
	 * @testdox Should not be dormant for a platform-connected store with the gateway enabled.
	 */
	public function test_platform_connected_store_is_not_dormant(): void {
		update_option( Options::PLATFORM, array( 'merchant_id' => 'M2' ) );
		$this->build_sut( true );

		$this->assertFalse( $this->sut->is_dormant() );
	}

	/**
	 * @testdox Should still boot a first-party connected store whose gateway is disabled, as before.
	 */
	public function test_first_party_connected_store_with_a_disabled_gateway_is_not_dormant(): void {
		$this->set_connected_merchant_option();
		update_option( 'woocommerce_ppcp-gateway_settings', array( 'enabled' => 'no' ) );
		$this->build_sut( true );

		$this->assertFalse( $this->sut->is_dormant() );
	}

	/**
	 * @testdox Should stay dormant with neither a collecting nor a platform option, as before.
	 */
	public function test_stays_dormant_without_collecting_or_platform_options(): void {
		update_option( 'woocommerce_ppcp-gateway_settings', array( 'enabled' => 'yes' ) );
		$this->build_sut( true );

		$this->assertTrue( $this->sut->is_dormant() );
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should boot a collecting store and append the collecting module after the wallet gateway module.
	 */
	public function test_boots_a_collecting_store_with_the_collecting_module_last(): void {
		update_option( Options::COLLECTING, array( 'payee_email' => 'payee@example.com' ) );
		$seen = null;
		add_filter(
			'woocommerce_paypal_payments_modules',
			static function ( $modules ) use ( &$seen ) {
				$seen = $modules;
				return $modules;
			},
			20
		);
		$this->build_sut( true );

		$this->sut->maybe_boot();

		$this->assertTrue( $this->sut->is_booted(), 'A collecting store boots without credentials' );
		$this->assertIsArray( $seen );
		$classes = array_map( 'get_class', $seen );
		$this->assertContains( CollectingModule::class, $classes );
		$this->assertGreaterThan( array_search( WCGatewayModule::class, $classes, true ), array_search( CollectingModule::class, $classes, true ), 'The collecting module comes after the wallet gateway module' );
		$this->assertSame( CollectingModule::class, end( $classes ), 'The collecting module is the last module' );
		$this->assertTrue( \Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PPCP::container()->has( 'collecting.state' ), 'The collecting services are registered' );
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should not add the collecting module for a first-party connected store.
	 */
	public function test_does_not_add_the_collecting_module_for_a_first_party_store(): void {
		$this->set_connected_merchant_option();
		update_option( Options::COLLECTING, array( 'payee_email' => 'payee@example.com' ) );
		$seen = null;
		add_filter(
			'woocommerce_paypal_payments_modules',
			static function ( $modules ) use ( &$seen ) {
				$seen = $modules;
				return $modules;
			},
			20
		);
		$this->build_sut( true );

		$this->sut->maybe_boot();

		$this->assertNotContains( CollectingModule::class, array_map( 'get_class', (array) $seen ) );
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should boot a collecting store as connected, with webhooks counted as registered and the SDK v6 buttons off.
	 */
	public function test_boots_a_collecting_store_as_connected(): void {
		update_option(
			Options::COLLECTING,
			array(
				'payee_email' => 'payee@example.com',
				'environment' => 'sandbox',
			)
		);
		update_option( 'woocommerce_ppcp-gateway_settings', array( 'enabled' => 'yes' ) );
		add_filter( 'woocommerce.feature-flags.woocommerce_paypal_payments.sdk_v6_enabled', '__return_true' );
		$this->build_sut( true );

		$this->sut->maybe_boot();

		$container = \Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PPCP::container();
		$this->assertTrue( $container->get( 'settings.flag.is-connected' ) );
		$this->assertTrue( $container->get( 'settings.environment' )->is_sandbox() );
		$this->assertTrue( $container->get( 'webhook.is-registered' ) );
		$this->assertFalse( $container->get( 'sdk-v6.buttons-available' ) );
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should register neither the old connect task nor the Pay Later task for a collecting store.
	 */
	public function test_collecting_store_registers_no_connect_or_pay_later_task(): void {
		update_option( Options::COLLECTING, array( 'payee_email' => 'payee@example.com' ) );
		$this->build_sut( true );
		if ( null === TaskLists::get_list( 'extended' ) ) {
			TaskLists::init_default_lists();
		}

		$this->sut->maybe_boot();
		$container  = \Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PPCP::container();
		$config_ids = array_column( $container->get( 'wcgateway.settings.wc-tasks.simple-redirect-tasks-config' ), 'id' );
		// The task registration runs on init; run only the wallet gateway module's callback.
		$ran = 0;
		foreach ( $GLOBALS['wp_filter']['init']->callbacks ?? array() as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( WCGatewayModule::class === $this->get_callback_owner( $callback['function'] ) ) {
					call_user_func( $callback['function'] );
					++$ran;
				}
			}
		}

		$this->assertSame( 1, $ran, 'The wallet gateway module registers its tasks from one init callback' );
		foreach ( array( 'connect-to-paypal-task', 'pay-later-messaging-task' ) as $id ) {
			$this->assertNotContains( $id, $config_ids, "$id must not be configured" );
			$this->assertFalse( TaskLists::get_list( 'extended' )->get_task( $id ), "$id must not be registered" );
		}
	}

	/**
	 * @testdox Should leave the modules filter alone when the store is dormant.
	 */
	public function test_attaches_no_modules_filter_callback_when_dormant(): void {
		$this->build_sut( true );

		$this->sut->maybe_boot();

		$this->assertFalse( has_filter( 'woocommerce_paypal_payments_modules', array( $this->sut, 'append_collecting_module' ) ) );
	}

	/**
	 * @testdox Should keep a list that is not an array unchanged when appending the collecting module.
	 */
	public function test_append_collecting_module_ignores_a_non_array(): void {
		$this->build_sut( true );

		$this->assertSame( 'oops', $this->sut->append_collecting_module( 'oops' ) );
	}

	/**
	 * @testdox Should register the takeover and gateway-disable listeners.
	 */
	public function test_register_hooks_the_collecting_listeners(): void {
		$this->build_sut( false );
		$this->sut->register();

		$this->assertSame( 10, has_action( 'activated_plugin', array( $this->sut, 'on_plugin_activated' ) ) );
		$this->assertSame( 10, has_action( 'update_option_woocommerce_ppcp-gateway_settings', array( $this->sut, 'on_gateway_settings_updated' ) ) );
		$this->assertSame( 10, has_action( 'add_option_woocommerce_ppcp-gateway_settings', array( $this->sut, 'on_gateway_settings_added' ) ) );
	}

	/**
	 * @testdox Should abandon the collecting state on takeover only when no orders are held.
	 *
	 * @testWith [0, false]
	 *           [1, true]
	 *
	 * @param int  $held_orders The number of held orders.
	 * @param bool $kept        Whether the collecting option stays.
	 */
	public function test_activating_the_extension_abandons_the_collecting_state_unless_orders_are_held( int $held_orders, bool $kept ): void {
		update_option( Options::COLLECTING, array( 'payee_email' => 'payee@example.com' ) );
		$this->build_sut_with_held_orders( $held_orders );

		$this->sut->on_plugin_activated( PayPalWalletRuntimeArbiter::EXTENSION_PLUGIN_FILE );

		$this->assertSame( $kept, (bool) get_option( Options::COLLECTING ) );
	}

	/**
	 * @testdox Should ignore the activation of any other plugin and a basename that is not a string.
	 */
	public function test_activating_another_plugin_keeps_the_collecting_state(): void {
		update_option( Options::COLLECTING, array( 'payee_email' => 'payee@example.com' ) );
		$this->build_sut_with_held_orders( 0 );

		$this->sut->on_plugin_activated( 'akismet/akismet.php' );
		$this->sut->on_plugin_activated( array( PayPalWalletRuntimeArbiter::EXTENSION_PLUGIN_FILE ) );

		$this->assertTrue( (bool) get_option( Options::COLLECTING ) );
	}

	/**
	 * @testdox Should leave the options alone when the extension is activated and there is no collecting state.
	 */
	public function test_activation_without_a_collecting_option_changes_nothing(): void {
		$this->build_sut_with_held_orders( 0 );

		$this->sut->on_plugin_activated( PayPalWalletRuntimeArbiter::EXTENSION_PLUGIN_FILE );

		$this->assertFalse( get_option( Options::COLLECTING ) );
	}

	/**
	 * @testdox Should abandon the collecting state through the real hooks when the gateway moves from enabled to disabled.
	 *
	 * @testWith ["yes"]
	 *           ["missing-key"]
	 *
	 * @param string $before How the gateway was enabled before.
	 */
	public function test_disabling_the_gateway_abandons_the_collecting_state( string $before ): void {
		update_option( Options::COLLECTING, array( 'payee_email' => 'payee@example.com' ) );
		update_option( 'woocommerce_ppcp-gateway_settings', 'yes' === $before ? array( 'enabled' => 'yes' ) : array( 'title' => 'PayPal' ) );
		$this->build_sut_with_held_orders( 0 );
		$this->sut->register();

		update_option( 'woocommerce_ppcp-gateway_settings', array( 'enabled' => 'no' ) );

		$this->assertFalse( get_option( Options::COLLECTING ) );
	}

	/**
	 * @testdox Should abandon the collecting state when the gateway row does not exist yet and is created disabled.
	 */
	public function test_creating_the_gateway_row_disabled_abandons_the_collecting_state(): void {
		update_option( Options::COLLECTING, array( 'payee_email' => 'payee@example.com' ) );
		delete_option( 'woocommerce_ppcp-gateway_settings' );
		$this->build_sut_with_held_orders( 0 );
		$this->sut->register();

		update_option( 'woocommerce_ppcp-gateway_settings', array( 'enabled' => 'no' ) );

		$this->assertFalse( get_option( Options::COLLECTING ), 'update_option() on an absent row only fires the add hook' );
	}

	/**
	 * @testdox Should keep the collecting state when the gateway row is created enabled, or disabled while orders are held.
	 *
	 * @testWith ["yes", 0]
	 *           ["no", 1]
	 *
	 * @param string $enabled     The enabled value the row is created with.
	 * @param int    $held_orders The number of held orders.
	 */
	public function test_creating_the_gateway_row_keeps_the_state_when_it_should( string $enabled, int $held_orders ): void {
		update_option( Options::COLLECTING, array( 'payee_email' => 'payee@example.com' ) );
		$this->build_sut_with_held_orders( $held_orders );

		$this->sut->on_gateway_settings_added( 'woocommerce_ppcp-gateway_settings', array( 'enabled' => $enabled ) );

		$this->assertTrue( (bool) get_option( Options::COLLECTING ) );
	}

	/**
	 * @testdox Should keep the collecting state when the gateway is disabled while orders are held.
	 */
	public function test_disabling_the_gateway_keeps_the_state_while_orders_are_held(): void {
		update_option( Options::COLLECTING, array( 'payee_email' => 'payee@example.com' ) );
		$this->build_sut_with_held_orders( 1 );

		$this->sut->on_gateway_settings_updated( array( 'enabled' => 'yes' ), array( 'enabled' => 'no' ) );

		$this->assertTrue( (bool) get_option( Options::COLLECTING ) );
	}

	/**
	 * @testdox Should keep the collecting state when the gateway settings change without a disable.
	 *
	 * @testWith ["yes", "yes"]
	 *           ["no", "no"]
	 *           ["no", "yes"]
	 *
	 * @param string $before The enabled value before.
	 * @param string $after  The enabled value after.
	 */
	public function test_other_gateway_setting_changes_keep_the_collecting_state( string $before, string $after ): void {
		update_option( Options::COLLECTING, array( 'payee_email' => 'payee@example.com' ) );
		$this->build_sut_with_held_orders( 0 );

		$this->sut->on_gateway_settings_updated( array( 'enabled' => $before ), array( 'enabled' => $after ) );

		$this->assertTrue( (bool) get_option( Options::COLLECTING ) );
	}

	/**
	 * @testdox Should ignore gateway settings values that are not arrays.
	 */
	public function test_gateway_settings_listener_ignores_non_array_values(): void {
		update_option( Options::COLLECTING, array( 'payee_email' => 'payee@example.com' ) );
		$this->build_sut_with_held_orders( 0 );

		$this->sut->on_gateway_settings_updated( array( 'enabled' => 'yes' ), 'no' );
		$this->sut->on_gateway_settings_updated( 'yes', array( 'enabled' => 'no' ) );

		$this->assertTrue( (bool) get_option( Options::COLLECTING ) );
	}

	/**
	 * @testdox Should build the default collecting state over the held-orders query: abandoned on takeover when none is held.
	 */
	public function test_default_collecting_state_is_abandoned_with_no_held_order(): void {
		update_option( Options::COLLECTING, array( 'payee_email' => 'payee@example.com' ) );
		$this->build_sut( false );

		$this->sut->on_plugin_activated( PayPalWalletRuntimeArbiter::EXTENSION_PLUGIN_FILE );

		$this->assertFalse( get_option( Options::COLLECTING ), 'With no held order the default factory deletes the state' );
	}

	/**
	 * @testdox Should build the default collecting state over the held-orders query: kept on takeover when an order is held.
	 */
	public function test_default_collecting_state_is_kept_with_a_held_order(): void {
		$order = wc_create_order();
		$order->set_payment_method( 'ppcp-gateway' );
		$order->update_meta_data( RefundLock::HELD_CAPTURE_META_KEY, 'UNILATERAL' );
		$order->set_status( 'on-hold' );
		$order->save();
		update_option( Options::COLLECTING, array( 'payee_email' => 'payee@example.com' ) );
		$this->build_sut( false );

		$this->sut->on_plugin_activated( PayPalWalletRuntimeArbiter::EXTENSION_PLUGIN_FILE );

		$this->assertTrue( (bool) get_option( Options::COLLECTING ), 'A held order keeps the state' );
	}
}
