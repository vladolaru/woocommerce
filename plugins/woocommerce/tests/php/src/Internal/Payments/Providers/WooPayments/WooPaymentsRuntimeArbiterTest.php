<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use WC_Payment_Token_CC;
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
	 * Turn the WooPayments feature on.
	 */
	private function enable_native_runtime(): void {
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
	}

	/**
	 * Answer every read of the WooPayments feature option from a callback, on every site, without writing the option.
	 *
	 * A write would reach the verified-email restore service, which resets the owner memo this file tests.
	 *
	 * @param callable $read Returns the stored value.
	 */
	private function answer_feature_reads( callable $read ): void {
		$this->sut->invalidate();
		add_filter( 'pre_option_' . WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, $read );
	}

	/**
	 * @testdox Owner is none when the plugin is absent and native is disabled.
	 */
	public function test_owner_is_none_when_plugin_absent_and_native_disabled(): void {
		$this->fake_plugin();
		$this->answer_feature_reads( static fn() => 'no' );

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

		$this->answer_feature_reads( static fn() => 'no' );

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
	 * @testdox The active plugin wins whatever the WooPayments feature says ($_dataName).
	 * @testWith ["yes"]
	 *           ["no"]
	 *           [null]
	 *
	 * @param string|null $stored Stored feature option, or null when absent.
	 */
	public function test_plugin_wins_whatever_the_feature_says( ?string $stored ): void {
		$this->fake_plugin( true );
		if ( null === $stored ) {
			delete_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION );
		} else {
			update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, $stored );
		}
		$this->sut->invalidate();

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
		$feature_reads = 0;
		$this->answer_feature_reads(
			static function () use ( &$feature_reads ): string {
				++$feature_reads;
				return 'yes';
			}
		);

		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_BUILTIN, $this->sut->get_runtime_owner() );
		$this->assertTrue( $this->sut->is_builtin_owner() );
		$this->assertFalse( $this->sut->is_extension_owner() );
		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_BUILTIN, $this->sut->get_runtime_owner() );
		$this->assertSame( 1, $feature_reads, 'The owner decision should be resolved once for all repeated owner and helper calls.' );
	}

	/**
	 * @testdox Explicit invalidation recomputes the runtime owner.
	 */
	public function test_explicit_invalidation_recomputes_the_runtime_owner(): void {
		$this->fake_plugin();
		$feature_on = false;
		$this->answer_feature_reads(
			static function () use ( &$feature_on ): string {
				return $feature_on ? 'yes' : 'no';
			}
		);

		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_NONE, $this->sut->get_runtime_owner() );
		$feature_on = true;
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
		$feature_reads = 0;
		$main_blog_id  = get_current_blog_id();
		$this->answer_feature_reads(
			static function () use ( &$feature_reads, $main_blog_id ): string {
				++$feature_reads;
				return get_current_blog_id() !== $main_blog_id ? 'yes' : 'no';
			}
		);

		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_NONE, $this->sut->get_runtime_owner() );
		$blog_id = self::factory()->blog->create();
		try {
			switch_to_blog( $blog_id );
			$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_BUILTIN, $this->sut->get_runtime_owner() );
			restore_current_blog();

			$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_NONE, $this->sut->get_runtime_owner() );
			$this->assertSame( 2, $feature_reads, 'Each blog should resolve and retain its own owner decision.' );
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
	 * Builds before the WooPayments feature read a support kill switch. The install seeds it as '0' but never overwrites a
	 * stored '1', so a store can keep one after upgrading.
	 *
	 * @testdox A kill switch stored by an earlier build does not turn the built-in WooPayments off.
	 */
	public function test_stored_legacy_kill_switch_does_not_disable_the_builtin(): void {
		$this->fake_plugin();
		update_option( 'woocommerce_woopayments_builtin_kill_switch', '1' );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$this->sut->invalidate();

		$this->assertTrue( $this->sut->is_builtin_enabled() );
		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_BUILTIN, $this->sut->get_runtime_owner() );
	}

	/**
	 * @testdox is_builtin_enabled() reads the WooPayments feature option, whoever owns payments ($_dataName).
	 * @dataProvider provide_feature_option_values
	 *
	 * @param string|null $stored          Stored feature option, or null when absent.
	 * @param bool        $plugin_active   Whether the WooPayments extension is active.
	 * @param bool        $expected        Whether the built-in WooPayments reads as enabled.
	 */
	public function test_is_builtin_enabled_reads_the_feature_option( ?string $stored, bool $plugin_active, bool $expected ): void {
		$this->fake_plugin( $plugin_active );
		if ( null === $stored ) {
			delete_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION );
		} else {
			update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, $stored );
		}
		$this->sut->invalidate();

		$this->assertSame( $expected, $this->sut->is_builtin_enabled() );
		$this->assertSame( $expected && ! $plugin_active ? WooPaymentsRuntimeArbiter::OWNER_BUILTIN : ( $plugin_active ? WooPaymentsRuntimeArbiter::OWNER_EXTENSION : WooPaymentsRuntimeArbiter::OWNER_NONE ), $this->sut->get_runtime_owner() );
	}

	/**
	 * Stored feature option values with and without the WooPayments extension.
	 *
	 * @return array<string,array{0:string|null,1:bool,2:bool}>
	 */
	public function provide_feature_option_values(): array {
		return array(
			'on, no extension'      => array( 'yes', false, true ),
			'off, no extension'     => array( 'no', false, false ),
			'absent, no extension'  => array( null, false, false ),
			'on, extension active'  => array( 'yes', true, true ),
			'off, extension active' => array( 'no', true, false ),
		);
	}

	/**
	 * The built-in WooPayments writes the extension's gateway ids, so orders and tokens prove nothing about the extension.
	 *
	 * @testdox has_run_woopayments_extension() counts only the extension's version option ($_dataName).
	 * @dataProvider provide_extension_evidence
	 *
	 * @param bool $version_recorded Whether the extension's version option is stored.
	 * @param bool $expected         Whether the store reads as having run the extension.
	 */
	public function test_has_run_woopayments_extension_counts_only_the_version_option( bool $version_recorded, bool $expected ): void {
		$order = wc_create_order();
		$order->set_payment_method( 'woocommerce_payments' );
		$order->save();
		$token = new WC_Payment_Token_CC();
		$token->set_gateway_id( 'woocommerce_payments' );
		$token->set_user_id( self::factory()->user->create() );
		$token->set_token( 'pm_builtin_only' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->save();
		if ( $version_recorded ) {
			update_option( 'woocommerce_woocommerce_payments_version', '11.1.0' );
		} else {
			delete_option( 'woocommerce_woocommerce_payments_version' );
		}

		$this->assertSame( $expected, $this->sut->has_run_woopayments_extension() );
	}

	/**
	 * Stores with a built-in WooPayments order and token, with and without the extension's version option.
	 *
	 * @return array<string,array{0:bool,1:bool}>
	 */
	public function provide_extension_evidence(): array {
		return array(
			'version option recorded'         => array( true, true ),
			'only built-in orders and tokens' => array( false, false ),
		);
	}
}
