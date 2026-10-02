<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletBootstrap;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletRuntimeArbiter;
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
	 * @testdox Should hook plugins_loaded at priority 10 when registered.
	 */
	public function test_register_hooks_plugins_loaded(): void {
		$this->build_sut( false );
		$this->sut->register();

		$this->assertSame( 10, has_action( 'plugins_loaded', array( $this->sut, 'maybe_boot' ) ) );
	}

	/**
	 * @testdox Should not boot and not add trimming filters when the extension owns the site.
	 */
	public function test_does_not_boot_when_extension_owns(): void {
		$this->build_sut( false );
		$this->sut->maybe_boot();

		$this->assertFalse( $this->sut->is_booted() );
		$this->assertFalse( has_filter( 'woocommerce_paypal_payments_modules' ), 'No trimming filter must be added while dormant' );
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
	 * @testdox Should drop the non-wallet modules and keep the wallet ones when filtering the module list.
	 */
	public function test_module_filter_drops_only_non_wallet_modules(): void {
		$this->build_sut( true );
		$kept    = new \stdClass();
		$dropped = $this->getMockBuilder( \stdClass::class )->setMockClassName( 'WooCommerce_PayPalCommerce_OrderTracking_OrderTrackingModule_Mock' )->getMock();

		$modules = array( $kept, $dropped );
		$result  = $this->sut->filter_modules( $modules, array( get_class( $dropped ) ) );

		$this->assertSame( array( $kept ), $result );
	}

	/**
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should name only module classes that exist in the vendored tree in the drop list.
	 */
	public function test_dropped_module_classes_exist_in_the_vendored_tree(): void {
		$autoload = PayPalWalletBootstrap::VENDORED_DIR . '/vendor/autoload.php';
		if ( ! file_exists( $autoload ) ) {
			$this->markTestSkipped( 'Vendored extension is not present.' );
		}
		if ( ! class_exists( '\WooCommerce\PayPalCommerce\PluginModule' ) ) {
			require $autoload;
		}
		foreach ( PayPalWalletBootstrap::DROPPED_MODULE_CLASSES as $class ) {
			$this->assertTrue( class_exists( $class ), "$class must exist in the vendored tree, or the filter drops nothing" );
		}
		$this->assertCount( 7, PayPalWalletBootstrap::DROPPED_MODULE_CLASSES );
	}

	/**
	 * @testdox Should remove the card button and keep the other methods when filtering the payment methods.
	 */
	public function test_payment_methods_filter_removes_only_the_card_button(): void {
		$this->build_sut( true );
		$methods = array(
			'__meta'                   => array( 'x' => 1 ),
			'ppcp-gateway'             => array( 'id' => 'ppcp-gateway' ),
			'venmo'                    => array( 'id' => 'venmo' ),
			'pay-later'                => array( 'id' => 'pay-later' ),
			'ppcp-card-button-gateway' => array( 'id' => 'ppcp-card-button-gateway' ),
			'paypalShowLogo'           => true,
		);

		$result = $this->sut->filter_payment_methods( $methods );

		unset( $methods['ppcp-card-button-gateway'] );
		$this->assertSame( $methods, $result );
	}

	/**
	 * @testdox Should return the input unchanged when the payment methods are not an array.
	 */
	public function test_payment_methods_filter_returns_non_array_unchanged(): void {
		$this->build_sut( true );

		$this->assertSame( 'oops', $this->sut->filter_payment_methods( 'oops' ) );
		$this->assertNull( $this->sut->filter_payment_methods( null ) );
	}

	/**
	 * Booting sets PPCP container process state, so this test is tagged to allow isolated runs.
	 *
	 * @group paypal-wallet-boot
	 *
	 * @testdox Should boot the vendored container once when native owns the site, registering the PayPal gateway.
	 */
	public function test_boots_vendored_extension_when_native_owns(): void {
		if ( ! file_exists( PayPalWalletBootstrap::VENDORED_DIR . '/vendor/autoload.php' ) ) {
			$this->markTestSkipped( 'Vendored extension is not present.' );
		}
		$this->build_sut( true );

		$this->sut->maybe_boot();
		$this->sut->maybe_boot(); // Second call must be a no-op.

		$this->assertTrue( $this->sut->is_booted() );
		$this->assertTrue( class_exists( '\WooCommerce\PayPalCommerce\PPCP' ) );
		$container = \WooCommerce\PayPalCommerce\PPCP::container();
		$this->assertTrue( $container->has( 'wcgateway.paypal-gateway' ), 'The vendored gateway service must exist' );
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
			$this->assertFalse( $container->has( $id ), "$id must not be registered: its module is dropped" );
		}
		$availability = $container->get( 'ppcp.module-availability' );
		$this->assertInstanceOf( \WooCommerce\PayPalCommerce\ModuleAvailability::class, $availability );
		$this->assertFalse( $availability->is_loaded( 'applepay' ) );
		$this->assertFalse( $availability->is_eligible( 'ppcp-local-apms' ) );
		$this->assertSame( 10, has_filter( 'woocommerce_paypal_payments_modules', array( $this->sut, 'filter_modules' ) ), 'The module filter must be in place' );
		foreach ( PayPalWalletBootstrap::DISABLED_FEATURE_FLAGS as $flag ) {
			$this->assertSame( 10, has_filter( 'woocommerce.feature-flags.woocommerce_paypal_payments.' . $flag, '__return_false' ), "$flag must be forced off" );
		}
		$this->assertFalse( has_filter( 'woocommerce.feature-flags.woocommerce_paypal_payments.vault_component_enabled' ), 'Wallet flags must be left alone' );
		$this->assertFalse( has_filter( 'woocommerce.feature-flags.woocommerce_paypal_payments.sdk_v6_enabled' ), 'Wallet flags must be left alone' );
		$this->assertSame( 10, has_filter( 'woocommerce_paypal_payments_gateway_group_cards', '__return_empty_array' ), 'The card group must stay empty' );
		$this->assertSame( 10, has_filter( 'woocommerce_paypal_payments_gateway_group_apm', '__return_empty_array' ), 'The APM group must stay empty' );
		$this->assertSame( 10, has_filter( 'woocommerce_paypal_payments_payment_methods', array( $this->sut, 'filter_payment_methods' ) ), 'The card button must be hidden from the settings data' );
		$this->assertContains( \WooCommerce\PayPalCommerce\WcGateway\Gateway\CardButtonGateway::ID, PayPalWalletBootstrap::HIDDEN_PAYMENT_METHOD_IDS, 'The hidden ID must match the vendored card button ID' );
		$this->assertInstanceOf( \WooCommerce\PayPalCommerce\Settings\Service\FeaturesEligibilityService::class, $container->get( 'settings.service.features_eligibilities' ) );
		$this->assertInstanceOf( \WooCommerce\PayPalCommerce\Settings\Service\PaymentMethodsEligibilityService::class, $container->get( 'settings.service.payment_methods_eligibilities' ) );

		// What is offered: the card, wallet and local APM gateways are connection-gated by the extension and the test store is not connected, so only the main PayPal gateway is observable.
		$offered_ids = array_map(
			static function ( $gateway ): string {
				return $gateway->id;
			},
			apply_filters( 'woocommerce_payment_gateways', array() ) // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		);
		$this->assertContains( \WooCommerce\PayPalCommerce\WcGateway\Gateway\PayPalGateway::ID, $offered_ids, 'The PayPal gateway must be offered' );
		$not_offered = array(
			\WooCommerce\PayPalCommerce\Applepay\ApplePayGateway::ID,
			\WooCommerce\PayPalCommerce\Googlepay\GooglePayGateway::ID,
			\WooCommerce\PayPalCommerce\Axo\Gateway\AxoGateway::ID,
			\WooCommerce\PayPalCommerce\WcGateway\Gateway\CreditCardGateway::ID,
			\WooCommerce\PayPalCommerce\WcGateway\Gateway\CardButtonGateway::ID,
			\WooCommerce\PayPalCommerce\LocalAlternativePaymentMethods\IDealGateway::ID,
			\WooCommerce\PayPalCommerce\LocalAlternativePaymentMethods\BancontactGateway::ID,
			\WooCommerce\PayPalCommerce\LocalAlternativePaymentMethods\PWCGateway::ID,
		);
		$this->assertSame( array(), array_values( array_intersect( $not_offered, $offered_ids ) ), 'No card, wallet or local APM gateway may be offered' );
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
	 * @testdox Should define the same constants, with the same values, as the vendored main file.
	 */
	public function test_constants_match_the_vendored_main_file(): void {
		$main_file = PayPalWalletBootstrap::VENDORED_DIR . '/woocommerce-paypal-payments.php';
		if ( ! file_exists( $main_file ) ) {
			$this->markTestSkipped( 'Vendored extension is not present.' );
		}
		$source = file_get_contents( $main_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local fixture.
		preg_match_all( "/^\s*(?:!\s*defined\(\s*'[A-Z_]+'\s*\)\s*&&\s*)?define\(\s*'([A-Z_]+)'\s*,\s*'([^']*)'\s*\)\s*;/m", $source, $matches, PREG_SET_ORDER );
		$expected = array();
		foreach ( $matches as $match ) {
			$expected[ $match[1] ] = $match[2];
		}

		$this->assertNotEmpty( $expected, 'The vendored main file must define constants' );
		$this->assertSame( $expected, PayPalWalletBootstrap::get_extension_constants() );
	}
}
