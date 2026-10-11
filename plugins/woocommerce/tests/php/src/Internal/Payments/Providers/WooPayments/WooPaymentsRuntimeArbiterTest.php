<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsRuntimeArbiter class.
 *
 * The mutual-exclusion invariant: exactly one payments runtime (the WooPayments plugin or
 * core-native) owns a site, and the plugin wins whenever it is active.
 */
class WooPaymentsRuntimeArbiterTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = wc_get_container()->get( WooPaymentsRuntimeArbiter::class );
		$this->sut->invalidate();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_woopayments_builtin_enabled' );
		delete_option( 'woocommerce_woopayments_builtin_kill_switch' );
		remove_all_filters( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER );
		remove_all_filters( 'option_woocommerce_woopayments_builtin_enabled' );
		remove_all_filters( 'option_woocommerce_woopayments_builtin_kill_switch' );
		$this->sut->invalidate();
		$this->reset_legacy_proxy_mocks();
		parent::tearDown();
	}

	/**
	 * Control every WooPayments-plugin detection signal in a single mock registration.
	 *
	 * Every signal is mocked together so an "absent" plugin is absent on every signal — the
	 * real test process may have WC_Payments loaded or WCPAY_PLUGIN_FILE defined, which would
	 * otherwise trip a fallback.
	 *
	 * @param bool   $in_list          Whether the plugin is in the per-site active-plugins list.
	 * @param bool   $network          Whether the plugin is in the network active-sitewide-plugins list.
	 * @param bool   $class_loaded     Whether the WC_Payments bootstrap class is loaded.
	 * @param bool   $constant_defined Whether the WooPayments include-time constant is defined.
	 * @param string $entry            The plugin's active-plugins entry.
	 */
	private function fake_plugin( bool $in_list = false, bool $network = false, bool $class_loaded = false, bool $constant_defined = false, string $entry = WooPaymentsRuntimeArbiter::PLUGIN_FILE ): void {
		$this->register_legacy_proxy_function_mocks(
			array(
				'get_option'      => function ( $name, $default_value = false ) use ( $in_list, $entry ) {
					if ( 'active_plugins' === $name ) {
						return $in_list ? array( $entry ) : array();
					}
					return get_option( $name, $default_value );
				},
				'get_site_option' => function ( $name, $default_value = false ) use ( $network, $entry ) {
					if ( 'active_sitewide_plugins' === $name ) {
						return $network ? array( $entry => 1234567890 ) : array();
					}
					return get_site_option( $name, $default_value );
				},
				'is_multisite'    => function () use ( $network ) {
					return $network || is_multisite();
				},
				'class_exists'    => function ( $class_name, $autoload = true ) use ( $class_loaded ) {
					if ( 'WC_Payments' === ltrim( (string) $class_name, '\\' ) ) {
						return $class_loaded;
					}
					return class_exists( $class_name, $autoload );
				},
				'defined'         => function ( $constant_name ) use ( $constant_defined ) {
					if ( 'WCPAY_PLUGIN_FILE' === $constant_name ) {
						return $constant_defined;
					}
					return defined( $constant_name );
				},
			)
		);
	}

	/**
	 * Enable the native runtime feature flag.
	 */
	private function enable_native_runtime(): void {
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
	}

	/**
	 * @testdox Owner is none when the plugin is absent and native is disabled.
	 */
	public function test_owner_is_none_when_plugin_absent_and_native_disabled(): void {
		$this->fake_plugin();

		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_NONE, $this->sut->get_runtime_owner(), 'With no plugin and native off, nobody owns the runtime.' );
		$this->assertFalse( $this->sut->is_builtin_owner(), 'Native must not register when it does not own the runtime.' );
		$this->assertFalse( $this->sut->is_extension_owner(), 'The plugin does not own the runtime when it is absent.' );
	}

	/**
	 * @testdox Owner is the plugin when the plugin is active in the per-site list.
	 */
	public function test_owner_is_plugin_when_plugin_active(): void {
		$this->fake_plugin( true );

		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_EXTENSION, $this->sut->get_runtime_owner(), 'An active plugin owns the runtime.' );
		$this->assertTrue( $this->sut->is_extension_owner(), 'The plugin owns the runtime when active.' );
		$this->assertFalse( $this->sut->is_builtin_owner(), 'Native must register nothing while the plugin owns the runtime.' );
	}

	/**
	 * @testdox Owner is the plugin when the plugin is network-activated.
	 */
	public function test_owner_is_plugin_when_network_active(): void {
		$this->fake_plugin( false, true );

		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_EXTENSION, $this->sut->get_runtime_owner(), 'A network-activated plugin owns the runtime.' );
		$this->assertTrue( $this->sut->is_extension_owner(), 'A network-activated plugin owns the runtime.' );
		$this->assertFalse( $this->sut->is_builtin_owner(), 'Native must not register while a network-activated plugin owns the runtime.' );
	}

	/**
	 * @testdox Owner resolution on a single site does not read the network plugin list.
	 */
	public function test_single_site_owner_resolution_skips_network_plugin_list(): void {
		$network_reads = 0;
		$this->register_legacy_proxy_function_mocks(
			array(
				'get_option'      => function ( $name, $default_value = false ) {
					return 'active_plugins' === $name ? array() : get_option( $name, $default_value );
				},
				'get_site_option' => function ( $name, $default_value = false ) use ( &$network_reads ) {
					++$network_reads;
					return get_site_option( $name, $default_value );
				},
				'is_multisite'    => function () {
					return false;
				},
				'defined'         => function ( $constant_name ) {
					return 'WCPAY_PLUGIN_FILE' === $constant_name ? false : defined( $constant_name );
				},
			)
		);

		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_NONE, $this->sut->get_runtime_owner() );
		$this->assertSame( 0, $network_reads );
	}

	/**
	 * @testdox The plugin is detected via its include-time constant for a non-standard install.
	 */
	public function test_plugin_detected_via_include_time_constant_for_non_standard_install(): void {
		$this->fake_plugin( false, false, false, true );
		$this->enable_native_runtime();

		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_EXTENSION, $this->sut->get_runtime_owner(), 'The include-time constant detects a plugin outside the standard active-plugins entry.' );
		$this->assertFalse( $this->sut->is_builtin_owner(), 'Native must not register when the plugin is detected by the fallback signal.' );
	}

	/**
	 * @testdox A loaded bootstrap class alone does not establish plugin ownership.
	 */
	public function test_loaded_bootstrap_class_alone_does_not_establish_plugin_ownership(): void {
		$this->fake_plugin( false, false, true, false );
		$this->enable_native_runtime();

		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_BUILTIN, $this->sut->get_runtime_owner(), 'The legacy bootstrap class is not an ownership fallback.' );
		$this->assertTrue( $this->sut->is_builtin_owner(), 'Native should register when only the removed fallback signal is present.' );
	}

	/**
	 * @testdox Plugin wins even when the native runtime is enabled.
	 */
	public function test_plugin_wins_even_when_native_enabled(): void {
		$this->fake_plugin( true );
		$this->enable_native_runtime();

		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_EXTENSION, $this->sut->get_runtime_owner(), 'Plugin-wins is the only allowed state while the plugin is active.' );
		$this->assertFalse( $this->sut->is_builtin_owner(), 'Native must not register even when enabled, as long as the plugin is active.' );
	}

	/**
	 * @testdox A network-activated plugin wins even when native is enabled.
	 */
	public function test_network_active_plugin_wins_when_native_enabled(): void {
		$this->fake_plugin( false, true );
		$this->enable_native_runtime();

		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_EXTENSION, $this->sut->get_runtime_owner(), 'Network-active detection must keep plugin-wins even when native is enabled.' );
		$this->assertFalse( $this->sut->is_builtin_owner(), 'Native must not register on a network-activated-plugin site.' );
	}

	/**
	 * @testdox Owner is native when the plugin is absent and native is enabled.
	 */
	public function test_owner_is_native_when_plugin_absent_and_native_enabled(): void {
		$this->fake_plugin();
		$this->enable_native_runtime();

		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_BUILTIN, $this->sut->get_runtime_owner(), 'Native owns the runtime when the plugin is gone and native is enabled.' );
		$this->assertTrue( $this->sut->is_builtin_owner(), 'Native must register when it owns the runtime.' );
		$this->assertFalse( $this->sut->is_extension_owner(), 'The plugin does not own the runtime when absent.' );
	}

	/**
	 * @testdox Runtime ownership is computed once for repeated owner and helper calls.
	 */
	public function test_runtime_ownership_is_computed_once_for_repeated_owner_and_helper_calls(): void {
		$this->fake_plugin();
		$native_enabled_queries = 0;
		add_filter(
			WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER,
			static function () use ( &$native_enabled_queries ): bool {
				++$native_enabled_queries;
				return true;
			}
		);

		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_BUILTIN, $this->sut->get_runtime_owner() );
		$this->assertTrue( $this->sut->is_builtin_owner() );
		$this->assertFalse( $this->sut->is_extension_owner() );
		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_BUILTIN, $this->sut->get_runtime_owner() );
		$this->assertSame( 1, $native_enabled_queries, 'The owner decision should be resolved once for all repeated owner and helper calls.' );
	}

	/**
	 * @testdox Explicit invalidation recomputes the runtime owner.
	 */
	public function test_explicit_invalidation_recomputes_the_runtime_owner(): void {
		$this->fake_plugin();
		$native_enabled = false;
		add_filter(
			WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER,
			static function () use ( &$native_enabled ): bool {
				return $native_enabled;
			}
		);

		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_NONE, $this->sut->get_runtime_owner() );
		$native_enabled = true;
		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_NONE, $this->sut->get_runtime_owner(), 'The current request keeps its first owner decision until explicitly invalidated.' );

		$this->sut->invalidate();

		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_BUILTIN, $this->sut->get_runtime_owner() );
	}

	/**
	 * @testdox Runtime ownership memoization is isolated by blog.
	 * @group multisite
	 */
	public function test_runtime_ownership_memoization_is_isolated_by_blog(): void {
		$this->skipWithoutMultisite();
		$this->fake_plugin();
		$native_enabled_queries = 0;
		$main_blog_id           = get_current_blog_id();
		add_filter(
			WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER,
			static function () use ( &$native_enabled_queries, $main_blog_id ): bool {
				++$native_enabled_queries;
				return get_current_blog_id() !== $main_blog_id;
			}
		);

		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_NONE, $this->sut->get_runtime_owner() );
		$blog_id = self::factory()->blog->create();
		try {
			switch_to_blog( $blog_id );
			$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_BUILTIN, $this->sut->get_runtime_owner() );
			restore_current_blog();

			$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_NONE, $this->sut->get_runtime_owner() );
			$this->assertSame( 2, $native_enabled_queries, 'Each blog should resolve and retain its own owner decision.' );
		} finally {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}
			$this->sut->invalidate( $blog_id );
			wpmu_delete_blog( $blog_id, true );
		}
	}

	/**
	 * @testdox The plugin in a renamed folder owns the runtime.
	 */
	public function test_plugin_in_renamed_folder_owns_the_runtime(): void {
		$this->fake_plugin( true, false, false, false, 'woopayments/woocommerce-payments.php' );
		$this->enable_native_runtime();

		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_EXTENSION, $this->sut->get_runtime_owner(), 'A copy in a renamed folder loads after WooCommerce, so only its list entry can keep native from registering too.' );
	}

	/**
	 * @testdox The plugin network-activated from a renamed folder owns the runtime.
	 */
	public function test_network_plugin_in_renamed_folder_owns_the_runtime(): void {
		$this->fake_plugin( false, true, false, false, 'woopayments/woocommerce-payments.php' );
		$this->enable_native_runtime();

		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_EXTENSION, $this->sut->get_runtime_owner(), 'The network list is matched by main file name as well.' );
	}

	/**
	 * @testdox An active plugin with another main file name is not WooPayments.
	 */
	public function test_other_plugin_main_file_is_not_woopayments(): void {
		$this->fake_plugin( true, false, false, false, 'woocommerce-payments-dev-tools/woocommerce-payments-dev-tools.php' );
		$this->enable_native_runtime();

		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_BUILTIN, $this->sut->get_runtime_owner(), 'Only the WooPayments main file name marks the plugin as active.' );
	}

	/**
	 * @testdox is_builtin_enabled reflects the feature flag independently of ownership.
	 */
	public function test_is_builtin_enabled_reflects_flag(): void {
		$this->fake_plugin( true );

		$this->assertFalse( $this->sut->is_builtin_enabled(), 'The native flag is off by default.' );

		$this->enable_native_runtime();

		$this->assertTrue( $this->sut->is_builtin_enabled(), 'The native flag reports enabled even while the plugin still owns the runtime.' );
	}

	/**
	 * @testdox An absent native runtime option fails closed.
	 */
	public function test_absent_native_runtime_option_fails_closed(): void {
		$this->fake_plugin();

		$this->assertFalse( $this->sut->is_builtin_enabled(), 'An absent native runtime option must remain disabled until the upgrade enables it.' );
		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_NONE, $this->sut->get_runtime_owner(), 'With no plugin and an absent native runtime option, nobody owns the runtime.' );
	}

	/**
	 * @testdox An enabled native runtime option enables native when the plugin is absent.
	 */
	public function test_enabled_native_runtime_option_enables_native_when_plugin_is_absent(): void {
		$this->fake_plugin();
		update_option( 'woocommerce_woopayments_builtin_enabled', 'yes' );

		$this->assertTrue( $this->sut->is_builtin_enabled(), 'The native runtime option should enable native payments.' );
		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_BUILTIN, $this->sut->get_runtime_owner(), 'Native should own the runtime when the plugin is absent and the option is enabled.' );
	}

	/**
	 * @testdox The option-backed kill switch makes an enabled native runtime filter default false.
	 */
	public function test_kill_switch_option_disables_native_filter_default(): void {
		$this->fake_plugin();
		update_option( 'woocommerce_woopayments_builtin_enabled', 'yes' );
		update_option( 'woocommerce_woopayments_builtin_kill_switch', true );
		$filter_default = null;
		add_filter(
			WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER,
			static function ( bool $enabled ) use ( &$filter_default ): bool {
				$filter_default = $enabled;
				return $enabled;
			}
		);

		$this->assertFalse( $this->sut->is_builtin_enabled() );
		$this->assertFalse( $filter_default, 'The kill switch must hand the filter a false default even when the enabled option is on.' );
	}

	/**
	 * @testdox The runtime filter retains final authority over the option-backed kill switch.
	 */
	public function test_native_enabled_filter_can_override_kill_switch_option(): void {
		$this->fake_plugin();
		update_option( 'woocommerce_woopayments_builtin_kill_switch', true );
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );

		$this->assertTrue( $this->sut->is_builtin_enabled() );
		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_BUILTIN, $this->sut->get_runtime_owner() );
	}

	/**
	 * @testdox The kill switch stays off for stored false-like values.
	 * @testWith ["no"]
	 *           ["false"]
	 *           ["0"]
	 *           [""]
	 *
	 * @param string $stored Stored kill-switch value.
	 */
	public function test_kill_switch_stays_off_for_false_like_values( string $stored ): void {
		$this->fake_plugin();
		update_option( 'woocommerce_woopayments_builtin_enabled', 'yes' );
		update_option( 'woocommerce_woopayments_builtin_kill_switch', $stored );

		$this->assertTrue( $this->sut->is_builtin_enabled(), "A kill switch stored as '{$stored}' must leave native enabled." );
	}

	/**
	 * @testdox The kill switch engages for stored true-like values.
	 * @testWith ["yes"]
	 *           ["true"]
	 *           ["1"]
	 *
	 * @param string $stored Stored kill-switch value.
	 */
	public function test_kill_switch_engages_for_true_like_values( string $stored ): void {
		$this->fake_plugin();
		update_option( 'woocommerce_woopayments_builtin_enabled', 'yes' );
		update_option( 'woocommerce_woopayments_builtin_kill_switch', $stored );

		$this->assertFalse( $this->sut->is_builtin_enabled(), "A kill switch stored as '{$stored}' must disable native." );
	}

	/**
	 * @testdox A kill switch stored as an array reads as on when non-empty and off when empty, without an error.
	 */
	public function test_kill_switch_reads_a_stored_array_as_bool(): void {
		$this->fake_plugin();
		update_option( 'woocommerce_woopayments_builtin_enabled', 'yes' );

		update_option( 'woocommerce_woopayments_builtin_kill_switch', array( 'on' ) );
		$this->assertTrue( $this->sut->is_kill_switch_active(), 'A non-empty array must read as on.' );
		$this->assertFalse( $this->sut->is_builtin_enabled(), 'A non-empty array must disable native.' );

		update_option( 'woocommerce_woopayments_builtin_kill_switch', array() );
		$this->assertFalse( $this->sut->is_kill_switch_active(), 'An empty array must read as off.' );
		$this->assertTrue( $this->sut->is_builtin_enabled(), 'An empty array must leave native enabled.' );
	}
}
