<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Compat;

use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Compat\WooPaymentsExtensionFacadeLoader;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
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
	 * Register the production loader while the built-in owns an active store.
	 */
	public function setUp(): void {
		parent::setUp();
		$container = wc_get_container();
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$container->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		$container->get( WooPaymentsSetupTier::class )->write_tier( WooPaymentsSetupTier::ACTIVE );
		$container->get( WooPaymentsExtensionFacadeLoader::class )->load();

		$this->setExpectedDeprecated( 'WC_Payments::mode' );
	}

	/**
	 * Read the five answers in the same order on active and dormant stores.
	 *
	 * @param Mode $mode The mode returned to an integration.
	 * @return array
	 */
	private function read_mode( Mode $mode ): array {
		foreach ( array( 'is_live', 'is_test', 'is_dev', 'is_test_mode_onboarding', 'get_dev_mode_triggers' ) as $reader ) {
			$this->setExpectedDeprecated( Mode::class . '::' . $reader );
		}
		return array( $mode->is_live(), $mode->is_test(), $mode->is_dev(), $mode->is_test_mode_onboarding(), $mode->get_dev_mode_triggers() );
	}

	/**
	 * @testdox Integrations share one mode object and see the account's current answers on every read.
	 */
	public function test_current_mode(): void {
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
	 * @testdox An integration holding the mode object can keep reading it after the store becomes dormant.
	 * @testWith ["disabled"]
	 *           ["available"]
	 *
	 * @param string $tier Dormant setup tier.
	 */
	public function test_dormant_mode( string $tier ): void {
		$mode      = WC_Payments::mode();
		$container = wc_get_container();
		$setup     = $container->get( WooPaymentsSetupTier::class );
		$setup->write_tier( $tier );
		$this->assertSame( $tier, $setup->get_effective_tier() );

		$guard = $this->createMock( TestingContainer::class );
		$guard->method( 'get' )->with( $this->logicalNot( $this->equalTo( WooPaymentsAccountService::class ) ) )->willReturnCallback( array( $container, 'get' ) );
		$GLOBALS['wc_container'] = $guard;
		try {
			$this->assertSame( array( true, false, false, false, array() ), $this->read_mode( $mode ) );
		} finally {
			$GLOBALS['wc_container'] = $container;
		}
	}

	/**
	 * @testdox Each public facade call emits exactly its own deprecation notice.
	 */
	public function test_mode_notices(): void {
		$notices = array();
		add_action(
			'deprecated_function_run',
			static function ( $name ) use ( &$notices ) {
				$notices[] = $name;
			}
		);
		$this->read_mode( WC_Payments::mode() );
		$this->assertSame(
			array( 'WC_Payments::mode', Mode::class . '::is_live', Mode::class . '::is_test', Mode::class . '::is_dev', Mode::class . '::is_test_mode_onboarding', Mode::class . '::get_dev_mode_triggers' ),
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
