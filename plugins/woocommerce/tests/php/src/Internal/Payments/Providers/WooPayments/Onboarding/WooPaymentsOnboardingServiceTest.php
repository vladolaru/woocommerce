<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Onboarding;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Onboarding\WooPaymentsOnboardingService;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;

/**
 * Tests for the built-in WooPayments onboarding service.
 *
 * @class WooPaymentsOnboardingService
 */
class WooPaymentsOnboardingServiceTest extends WC_Unit_Test_Case {

	private const TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT = 'test_drive_account_settings_for_live_account';

	/**
	 * @var WooPaymentsOnboardingService
	 */
	protected WooPaymentsOnboardingService $sut;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new WooPaymentsOnboardingService();
		$this->sut->init( wc_get_container()->get( LegacyProxy::class ) );
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		delete_transient( self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT );

		parent::tearDown();
	}

	/**
	 * @testdox Native test-drive payment-method snapshots remain scoped to the active multisite blog.
	 * @group ms-required
	 */
	public function test_native_test_drive_payment_method_snapshot_is_site_scoped(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This assertion requires the multisite test configuration.' );
		}

		$main_site_id = get_current_blog_id();
		$subsite_id   = self::factory()->blog->create();
		set_transient(
			self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT,
			array( 'enabled_payment_methods' => array( 'card' ) ),
			HOUR_IN_SECONDS
		);

		switch_to_blog( $subsite_id );
		try {
			set_transient(
				self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT,
				array( 'enabled_payment_methods' => array( 'card', 'link' ) ),
				HOUR_IN_SECONDS
			);
			$this->assertSame( array( 'card', 'link' ), $this->invoke_private_method( 'get_test_drive_enabled_payment_method_ids' ) );
		} finally {
			restore_current_blog();
		}

		$this->assertSame( $main_site_id, get_current_blog_id() );
		$this->assertSame( array( 'card' ), $this->invoke_private_method( 'get_test_drive_enabled_payment_method_ids' ) );
	}

	/**
	 * @testdox Should autoload the onboarding test-mode option when persisting it, since storefront renders read it.
	 */
	public function test_set_native_onboarding_test_mode_autoloads_option(): void {
		delete_option( 'wcpay_onboarding_test_mode' );

		$this->invoke_private_method( 'set_native_onboarding_test_mode', array( true ) );

		$this->assertSame( 'yes', get_option( 'wcpay_onboarding_test_mode' ) );
		$this->assertArrayHasKey( 'wcpay_onboarding_test_mode', wp_load_alloptions( true ), 'Client 11.1.0 autoloads it (onboarding service :1086).' );
	}

	/**
	 * @testdox Should not autoload the Stripe-connected onboarding marker when persisting it.
	 */
	public function test_update_native_gateway_settings_after_test_account_init_does_not_autoload_stripe_connected_marker(): void {
		delete_option( '_wcpay_onboarding_stripe_connected' );

		$this->invoke_private_method(
			'update_native_gateway_settings_after_test_account_init',
			array( array( 'card_payments' => true ), array( 'is_live' => false ) )
		);

		$this->assertIsArray( get_option( '_wcpay_onboarding_stripe_connected' ) );
		$this->assertOptionNotAutoloaded( '_wcpay_onboarding_stripe_connected' );
	}

	/**
	 * Assert that a WordPress option is not flagged for autoload.
	 *
	 * Reads the raw autoload column to stay robust across WordPress versions:
	 * pre-6.6 stores 'no' while 6.6+ stores 'off'/'auto-off' for non-autoloaded options.
	 *
	 * @param string $option_name The option name to inspect.
	 * @return void
	 */
	private function assertOptionNotAutoloaded( string $option_name ): void {
		global $wpdb;

		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option_name )
		);

		$this->assertNotNull( $autoload, sprintf( 'Option %s was not persisted.', $option_name ) );
		$this->assertNotContains(
			$autoload,
			array( 'yes', 'on', 'auto', 'auto-on' ),
			sprintf( 'Option %s should not be autoloaded, got autoload value "%s".', $option_name, $autoload )
		);
	}

	/**
	 * Helper method to invoke private methods for testing.
	 *
	 * @param string $method_name The name of the private method.
	 * @param array  $args        The arguments to pass to the method.
	 * @return mixed The result of the method call.
	 */
	private function invoke_private_method( string $method_name, array $args = array() ) {
		$reflection = new \ReflectionClass( $this->sut );
		if ( ! $reflection->hasMethod( $method_name ) ) {
			$this->fail( sprintf( 'Method %s not found on %s', $method_name, get_class( $this->sut ) ) );
		}
		$method = $reflection->getMethod( $method_name );
		$method->setAccessible( true );
		return $method->invokeArgs( $this->sut, $args );
	}
}
