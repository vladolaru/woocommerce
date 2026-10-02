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
	 * Whether the mocked is_multisite returns true.
	 *
	 * @var bool
	 */
	private $multisite = false;

	/**
	 * Network-active plugins the mocked active_sitewide_plugins option lists, keyed by plugin file.
	 *
	 * @var array<string, int>
	 */
	private $sitewide_plugins = array();

	/**
	 * The blog ID the mocked get_current_blog_id returns.
	 *
	 * @var int
	 */
	private $blog_id = 1;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->options          = array();
		$this->active_plugins   = array();
		$this->blog_id          = 1;
		$this->multisite        = false;
		$this->sitewide_plugins = array();
		$this->legacy_proxy     = wc_get_container()->get( LegacyProxy::class );
		$this->legacy_proxy->register_function_mocks(
			array(
				'get_option'          => function ( $name, $default_value = false ) {
					if ( 'active_plugins' === $name ) {
						return $this->active_plugins;
					}
					return $this->options[ $name ] ?? $default_value;
				},
				'get_current_blog_id' => function () {
					return $this->blog_id;
				},
				'is_multisite'        => function () {
					return $this->multisite;
				},
				'get_site_option'     => function ( $name, $default_value = false ) {
					if ( 'active_sitewide_plugins' === $name ) {
						return $this->sitewide_plugins;
					}
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

	/**
	 * @testdox Should report the extension as owner on multisite when it is network-active, read as a plugin-file key.
	 */
	public function test_extension_owns_when_network_active(): void {
		$this->multisite        = true;
		$this->sitewide_plugins = array( PayPalWalletRuntimeArbiter::EXTENSION_PLUGIN_FILE => 1234567890 );

		$this->assertTrue( $this->sut->is_extension_active() );
		$this->assertSame( PayPalWalletRuntimeArbiter::OWNER_EXTENSION, $this->sut->get_runtime_owner() );
	}

	/**
	 * @testdox Should ignore network-active plugins when the install is not multisite.
	 */
	public function test_network_active_list_ignored_on_single_site(): void {
		$this->sitewide_plugins = array( PayPalWalletRuntimeArbiter::EXTENSION_PLUGIN_FILE => 1234567890 );

		$this->assertFalse( $this->sut->is_extension_active() );
	}

	/**
	 * @testdox Should memoize the owner per blog so one blog's cached owner does not leak to another.
	 */
	public function test_owner_memo_is_keyed_by_blog(): void {
		$this->options[ PayPalWalletRuntimeArbiter::ENABLED_OPTION ] = 'yes';
		$this->blog_id = 1;
		$this->assertSame( PayPalWalletRuntimeArbiter::OWNER_NATIVE, $this->sut->get_runtime_owner() );

		$this->active_plugins = array( PayPalWalletRuntimeArbiter::EXTENSION_PLUGIN_FILE );
		$this->blog_id        = 2;
		$this->assertSame( PayPalWalletRuntimeArbiter::OWNER_EXTENSION, $this->sut->get_runtime_owner(), 'A second blog should be evaluated on its own' );

		$this->blog_id = 1;
		$this->assertSame( PayPalWalletRuntimeArbiter::OWNER_NATIVE, $this->sut->get_runtime_owner(), 'The first blog should keep its memoized owner' );
	}

	/**
	 * @testdox Should invalidate only the requested blog's memoized owner.
	 */
	public function test_invalidate_targets_one_blog(): void {
		$this->options[ PayPalWalletRuntimeArbiter::ENABLED_OPTION ] = 'yes';
		$this->blog_id = 1;
		$this->sut->get_runtime_owner();
		$this->blog_id = 2;
		$this->sut->get_runtime_owner();

		$this->active_plugins = array( PayPalWalletRuntimeArbiter::EXTENSION_PLUGIN_FILE );
		$this->sut->invalidate( 2 );

		$this->assertSame( PayPalWalletRuntimeArbiter::OWNER_EXTENSION, $this->sut->get_runtime_owner(), 'Blog 2 should be re-evaluated' );
		$this->blog_id = 1;
		$this->assertSame( PayPalWalletRuntimeArbiter::OWNER_NATIVE, $this->sut->get_runtime_owner(), 'Blog 1 should stay memoized' );
	}
}
