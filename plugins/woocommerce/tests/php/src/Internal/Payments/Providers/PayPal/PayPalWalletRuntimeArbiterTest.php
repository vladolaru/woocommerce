<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletRuntimeArbiter;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Testing\Tools\DependencyManagement\MockableLegacyProxy;
use WC_Unit_Test_Case;

/**
 * Tests for the PayPalWalletRuntimeArbiter class.
 */
class PayPalWalletRuntimeArbiterTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var PayPalWalletRuntimeArbiter
	 */
	private $sut;

	/**
	 * The mockable legacy proxy.
	 *
	 * @var MockableLegacyProxy
	 */
	private $legacy_proxy;

	/**
	 * Options the mocked get_option returns.
	 *
	 * @var array<string, mixed>
	 */
	private $options = array();

	/**
	 * Plugins the mocked active_plugins option lists.
	 *
	 * @var string[]
	 */
	private $active_plugins = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->options        = array();
		$this->active_plugins = array();
		$this->legacy_proxy   = wc_get_container()->get( LegacyProxy::class );
		$this->legacy_proxy->register_function_mocks(
			array(
				'get_option'      => function ( $name, $default_value = false ) {
					if ( 'active_plugins' === $name ) {
						return $this->active_plugins;
					}
					return $this->options[ $name ] ?? $default_value;
				},
				'is_multisite'    => function () {
					return false;
				},
				'get_site_option' => function ( $name, $default_value = false ) {
					return $default_value;
				},
			)
		);
		$this->sut = new PayPalWalletRuntimeArbiter();
		$this->sut->init( $this->legacy_proxy );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		try {
			$this->legacy_proxy->reset();
			remove_all_filters( PayPalWalletRuntimeArbiter::FILTER_ENABLED );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Should report the extension as owner whenever its plugin file is active, even if native is enabled.
	 */
	public function test_extension_owns_when_active(): void {
		$this->active_plugins                                        = array( PayPalWalletRuntimeArbiter::EXTENSION_PLUGIN_FILE );
		$this->options[ PayPalWalletRuntimeArbiter::ENABLED_OPTION ] = 'yes';

		$this->assertSame( PayPalWalletRuntimeArbiter::OWNER_EXTENSION, $this->sut->get_runtime_owner() );
		$this->assertTrue( $this->sut->is_extension_active() );
		$this->assertFalse( $this->sut->should_native_register(), 'Native must stay dormant while the extension is active' );
	}

	/**
	 * @testdox Should report native as owner when the extension is inactive and the enabled option is yes.
	 */
	public function test_native_owns_when_enabled_and_extension_inactive(): void {
		$this->options[ PayPalWalletRuntimeArbiter::ENABLED_OPTION ] = 'yes';

		$this->assertSame( PayPalWalletRuntimeArbiter::OWNER_NATIVE, $this->sut->get_runtime_owner() );
		$this->assertTrue( $this->sut->should_native_register() );
	}

	/**
	 * @testdox Should report no owner when the extension is inactive and native is not enabled.
	 */
	public function test_none_owns_by_default(): void {
		$this->assertSame( PayPalWalletRuntimeArbiter::OWNER_NONE, $this->sut->get_runtime_owner() );
		$this->assertFalse( $this->sut->should_native_register() );
	}

	/**
	 * @testdox Should treat the kill switch as disabling native even when the enabled option is yes.
	 */
	public function test_kill_switch_disables_native(): void {
		$this->options[ PayPalWalletRuntimeArbiter::ENABLED_OPTION ]     = 'yes';
		$this->options[ PayPalWalletRuntimeArbiter::KILL_SWITCH_OPTION ] = '1';

		$this->assertFalse( $this->sut->is_native_enabled() );
		$this->assertSame( PayPalWalletRuntimeArbiter::OWNER_NONE, $this->sut->get_runtime_owner() );
	}

	/**
	 * @testdox Should let the filter override the stored options in both directions.
	 */
	public function test_filter_has_final_word(): void {
		add_filter( PayPalWalletRuntimeArbiter::FILTER_ENABLED, '__return_true' );
		$this->assertTrue( $this->sut->is_native_enabled(), 'Filter should enable native with no option set' );

		remove_filter( PayPalWalletRuntimeArbiter::FILTER_ENABLED, '__return_true' );
		add_filter( PayPalWalletRuntimeArbiter::FILTER_ENABLED, '__return_false' );
		$this->options[ PayPalWalletRuntimeArbiter::ENABLED_OPTION ] = 'yes';
		$this->assertFalse( $this->sut->is_native_enabled(), 'Filter should disable native despite the option' );
	}

	/**
	 * @testdox Should memoize the owner for the request until invalidated.
	 */
	public function test_owner_is_memoized_until_invalidated(): void {
		$this->options[ PayPalWalletRuntimeArbiter::ENABLED_OPTION ] = 'yes';
		$this->assertSame( PayPalWalletRuntimeArbiter::OWNER_NATIVE, $this->sut->get_runtime_owner() );

		$this->active_plugins = array( PayPalWalletRuntimeArbiter::EXTENSION_PLUGIN_FILE );
		$this->assertSame( PayPalWalletRuntimeArbiter::OWNER_NATIVE, $this->sut->get_runtime_owner(), 'Memoized value should survive an option change' );

		$this->sut->invalidate();
		$this->assertSame( PayPalWalletRuntimeArbiter::OWNER_EXTENSION, $this->sut->get_runtime_owner(), 'Invalidate should re-evaluate' );
	}
}
