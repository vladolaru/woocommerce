<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletBootstrap;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\WalletStubsModule;
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
	 * @param bool $native_owns Whether the arbiter should say native owns the site.
	 */
	private function build_sut( bool $native_owns ): void {
		$arbiter = $this->getMockBuilder( PayPalWalletRuntimeArbiter::class )
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( $native_owns );

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
		$stub_file = ( new \ReflectionClass( WalletStubsModule::class ) )->getFileName();
		foreach ( WalletStubsModule::STUBBED_SERVICE_IDS as $id ) {
			$this->assertTrue( $container->has( $id ), "$id must be registered" );
			$service = $container->get( $id );
			$this->assertIsCallable( $service, "$id must resolve to a callable" );
			$this->assertFalse( $service(), "$id must report not eligible" );
			// The loaded module provides a real service for the same ID; the stub must win.
			$this->assertSame( $stub_file, ( new \ReflectionFunction( \Closure::fromCallable( $service ) ) )->getFileName(), "$id must come from the stub, not the module" );
		}
		foreach ( WalletStubsModule::STUBBED_FLAG_IDS as $id ) {
			// The real local APM check is true unless the merchant country is RU, BR or JP, so false proves the stub won.
			$this->assertFalse( $container->get( $id ), "$id must be the stubbed bool false" );
		}
		$this->assertSame( 10, has_filter( 'woocommerce_paypal_payments_modules', array( $this->sut, 'filter_modules' ) ), 'The module filter must be in place' );
		$this->assertSame( 10, has_filter( 'woocommerce.feature-flags.woocommerce_paypal_payments.card_fields_enabled', '__return_false' ), 'Feature flags must be forced off' );
		$this->assertFalse( has_filter( 'woocommerce.feature-flags.woocommerce_paypal_payments.applepay_enabled' ), 'Wallet flags must be left alone' );
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
	 * @param string $function_name The function name the guard looks for.
	 * @return PayPalWalletBootstrap
	 */
	private function build_guarded_sut( string $function_name ): PayPalWalletBootstrap {
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
		// Native ownership is forced on so a skipped boot can only be the guard's doing.
		$arbiter = $this->getMockBuilder( PayPalWalletRuntimeArbiter::class )
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( true );
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
