<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Compat;

use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Compat\WooPaymentsExtensionFacadeLoader;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Testing\Tools\TestingContainer;
use WC_Payments;
use WCPay\Core\Mode;
use WC_Unit_Test_Case;

/**
 * Mode compatibility for integrations that still call the extension's accessor.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class WooPaymentsModeFacadeTest extends WC_Unit_Test_Case {

	/**
	 * Run the facade loader on a store owned by the built-in.
	 *
	 * @param string $tier Setup tier for this request.
	 */
	private function load_facades( string $tier = WooPaymentsSetupTier::ACTIVE ): void {
		$container = wc_get_container();
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$container->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		$container->get( WooPaymentsSetupTier::class )->write_tier( $tier );
		$container->get( WooPaymentsExtensionFacadeLoader::class )->load();
	}

	/**
	 * Read the five mode answers exposed to integrations.
	 *
	 * @param Mode $mode The mode returned to an integration.
	 * @return array
	 */
	private function read_mode( Mode $mode ): array {
		foreach ( array( 'is_live', 'is_test', 'is_dev', 'is_test_mode_onboarding', 'get_dev_mode_triggers' ) as $reader ) {
			$this->setExpectedIncorrectUsage( Mode::class . '::' . $reader . '()' );
		}
		return array( $mode->is_live(), $mode->is_test(), $mode->is_dev(), $mode->is_test_mode_onboarding(), $mode->get_dev_mode_triggers() );
	}

	/**
	 * @testdox Integrations share one mode object and see the account's current answers on every read.
	 */
	public function test_current_mode(): void {
		$this->load_facades();
		$this->setExpectedIncorrectUsage( 'WC_Payments::mode()' );
		add_filter( 'wcpay_dev_mode', '__return_true' );
		$account = wc_get_container()->get( WooPaymentsAccountService::class );
		$mode    = WC_Payments::mode();
		$this->assertSame( $mode, WC_Payments::mode() );
		$this->assertNotEmpty( $account->get_dev_mode_triggers() );
		$this->assertSame(
			array( ! $account->is_test_mode_enabled(), $account->is_test_mode_enabled(), $account->is_dev_mode_enabled(), $account->is_test_mode_onboarding_enabled(), $account->get_dev_mode_triggers() ),
			$this->read_mode( $mode )
		);
		add_filter( 'wcpay_test_mode', '__return_false' );
		$this->assertSame( array( true, false, true, true, $account->get_dev_mode_triggers() ), $this->read_mode( $mode ) );
		add_filter( 'wcpay_test_mode_onboarding', '__return_false' );
		$this->assertSame( array( true, false, true, false, $account->get_dev_mode_triggers() ), $this->read_mode( $mode ) );
	}

	/**
	 * @testdox A mode object keeps reporting the account's mode after the setup tier drops.
	 * @testWith ["disabled", true]
	 *           ["available", false]
	 *
	 * @param string $tier     Setup tier after the drop.
	 * @param bool   $dev_mode Whether development mode enables test payments.
	 */
	public function test_mode_follows_account_after_tier_drop( string $tier, bool $dev_mode ): void {
		$this->load_facades();
		$this->setExpectedIncorrectUsage( 'WC_Payments::mode()' );
		add_filter( 'wcpay_dev_mode', $dev_mode ? '__return_true' : '__return_false' );
		update_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, array( 'test_mode' => 'yes' ) );

		$mode     = WC_Payments::mode();
		$account  = wc_get_container()->get( WooPaymentsAccountService::class );
		$expected = array( false, true, $dev_mode, $dev_mode, $account->get_dev_mode_triggers() );
		$this->assertSame( $expected, $this->read_mode( $mode ) );

		$setup = wc_get_container()->get( WooPaymentsSetupTier::class );
		$setup->write_tier( $tier );
		$this->assertSame( $tier, $setup->get_effective_tier() );
		$this->assertSame( $expected, $this->read_mode( $mode ) );
	}

	/**
	 * @testdox On a dormant request the facade loader leaves Mode unloaded and the account service unresolved.
	 * @testWith ["disabled"]
	 *           ["available"]
	 *
	 * @param string $tier Dormant setup tier.
	 */
	public function test_loader_leaves_mode_dormant( string $tier ): void {
		$original_container = wc_get_container();
		$container          = new class( $original_container ) extends TestingContainer {
			/** Check whether any dependency has resolved the account in this request. */
			public function has_resolved_account(): bool {
				return isset( $this->resolved_cache[ WooPaymentsAccountService::class ] );
			}
		};

		$GLOBALS['wc_container'] = $container;
		try {
			$this->load_facades( $tier );
			$this->assertFalse( class_exists( WC_Payments::class ) );
			$this->assertFalse( class_exists( Mode::class ) );
			$this->assertNotContains( WC_ABSPATH . 'includes/legacy/woopayments-compat/class-mode.php', get_included_files() );
			$this->assertFalse( $container->has_resolved_account() );
		} finally {
			$GLOBALS['wc_container'] = $original_container;
		}
	}

	/**
	 * @testdox Each public mode method warns once per request.
	 */
	public function test_mode_notices(): void {
		$this->load_facades();
		$this->setExpectedIncorrectUsage( 'WC_Payments::mode()' );
		$notices = array();
		add_action(
			'doing_it_wrong_run',
			static function ( $name ) use ( &$notices ) {
				$notices[] = $name;
			}
		);
		$this->read_mode( WC_Payments::mode() );
		$this->read_mode( WC_Payments::mode() );
		$this->assertSame(
			array( 'WC_Payments::mode()', Mode::class . '::is_live()', Mode::class . '::is_test()', Mode::class . '::is_dev()', Mode::class . '::is_test_mode_onboarding()', Mode::class . '::get_dev_mode_triggers()' ),
			$notices
		);
	}

	/**
	 * @testdox Declaring the mode facade preserves the settings provider's three answers for each payments owner.
	 * @testWith [false]
	 *           [true]
	 *
	 * @param bool $extension_owns Whether the extension supplies its mode service.
	 */
	public function test_settings_provider_mode( bool $extension_owns ): void {
		$this->load_facades();
		$this->setExpectedIncorrectUsage( 'WC_Payments::mode()' );
		$container = wc_get_container();
		$this->assertTrue( class_exists( WC_Payments::class ) );
		if ( $extension_owns ) {
			update_option( 'active_plugins', array( 'woocommerce-payments/woocommerce-payments.php' ) );
			$container->get( WooPaymentsRuntimeArbiter::class )->invalidate();
			// The extension owns the static accessor in this case. Its answers differ from the gateway fallback.
			$extension_mode = $this->getMockBuilder( \stdClass::class )->addMethods( array( 'is_test', 'is_dev', 'is_test_mode_onboarding' ) )->getMock();
			foreach ( array( 'is_test', 'is_dev', 'is_test_mode_onboarding' ) as $method ) {
				$extension_mode->method( $method )->willReturn( true );
			}
			$this->register_legacy_proxy_static_mocks( array( 'WC_Payments' => array( 'mode' => static fn() => $extension_mode ) ) );
		}

		add_filter( 'wcpay_dev_mode', '__return_false' );
		add_filter( 'wcpay_test_mode', '__return_false' );
		add_filter( 'wcpay_test_mode_onboarding', '__return_true' );
		$gateway  = $container->get( WooPaymentsGateway::class );
		$provider = $container->get( PaymentsProviders::class )->get_payment_gateway_provider_instance( $gateway->id );
		$answers  = static fn() => array( $provider->is_in_test_mode( $gateway ), $provider->is_in_dev_mode( $gateway ), $provider->is_in_test_mode_onboarding( $gateway ) );

		$this->assertFalse( class_exists( Mode::class, false ) );
		$this->assertSame( array( $extension_owns, $extension_owns, $extension_owns ), $answers() );
		$this->assertInstanceOf( Mode::class, WC_Payments::mode() );
		$this->assertSame( array( $extension_owns, $extension_owns, $extension_owns ), $answers() );
	}
}
