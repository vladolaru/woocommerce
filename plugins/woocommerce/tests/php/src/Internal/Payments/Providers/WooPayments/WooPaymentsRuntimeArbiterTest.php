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
	 * The extension owns payments wherever it is found, whatever the feature says; without it, the feature decides between
	 * the built-in WooPayments and nothing. Each row keeps the feature setup of the case it came from.
	 *
	 * @testdox The runtime owner follows the extension's signals and the feature: $_dataName.
	 * @dataProvider provide_owner_states
	 *
	 * @param bool   $in_list          Whether the plugin is in the per-site active-plugins list.
	 * @param bool   $network          Whether the plugin is in the network active-sitewide-plugins list.
	 * @param bool   $class_loaded     Whether the WC_Payments bootstrap class is loaded.
	 * @param bool   $constant_defined Whether the WooPayments include-time constant is defined.
	 * @param string $entry            The plugin's active-plugins entry.
	 * @param string $feature          How the feature option is set: untouched, filtered off, enabled, stored on, stored off or absent.
	 * @param string $owner            Expected owner.
	 */
	public function test_runtime_owner_follows_the_extension_and_the_feature( bool $in_list, bool $network, bool $class_loaded, bool $constant_defined, string $entry, string $feature, string $owner ): void {
		$this->fake_plugin( $in_list, $network, $class_loaded, $constant_defined, $entry );
		if ( 'filtered off' === $feature ) {
			$this->answer_feature_reads( static fn() => 'no' );
		} elseif ( 'enabled' === $feature ) {
			$this->enable_native_runtime();
		} elseif ( 'stored on' === $feature || 'stored off' === $feature ) {
			update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'stored on' === $feature ? 'yes' : 'no' );
			$this->sut->invalidate();
		} elseif ( 'absent' === $feature ) {
			delete_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION );
			$this->sut->invalidate();
		}

		$this->assertSame( $owner, $this->sut->get_runtime_owner(), 'The owner of payments.' );
		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_BUILTIN === $owner, $this->sut->is_builtin_owner(), 'The built-in registers only while it owns payments.' );
		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_EXTENSION === $owner, $this->sut->is_extension_owner(), 'The extension owns payments only while it is found.' );
	}

	/**
	 * Extension signals and feature setups, with the owner each gives.
	 *
	 * @return array<string,array{0:bool,1:bool,2:bool,3:bool,4:string,5:string,6:string}>
	 */
	public function provide_owner_states(): array {
		$file      = WooPaymentsRuntimeArbiter::PLUGIN_FILE;
		$renamed   = 'woopayments/woocommerce-payments.php';
		$none      = WooPaymentsRuntimeArbiter::OWNER_NONE;
		$builtin   = WooPaymentsRuntimeArbiter::OWNER_BUILTIN;
		$extension = WooPaymentsRuntimeArbiter::OWNER_EXTENSION;

		return array(
			'no extension, feature off'                   => array( false, false, false, false, $file, 'filtered off', $none ),
			'extension in the site list'                  => array( true, false, false, false, $file, 'untouched', $extension ),
			'extension network-active'                    => array( false, true, false, false, $file, 'untouched', $extension ),
			'extension found by its include-time constant, feature on' => array( false, false, false, true, $file, 'enabled', $extension ),
			'only the bootstrap class loaded, feature on' => array( false, false, true, false, $file, 'enabled', $builtin ),
			'extension active, feature stored on'         => array( true, false, false, false, $file, 'stored on', $extension ),
			'extension active, feature stored off'        => array( true, false, false, false, $file, 'stored off', $extension ),
			'extension active, feature option absent'     => array( true, false, false, false, $file, 'absent', $extension ),
			'extension network-active, feature on'        => array( false, true, false, false, $file, 'enabled', $extension ),
			'no extension, feature on'                    => array( false, false, false, false, $file, 'enabled', $builtin ),
			'extension in a renamed folder, feature on'   => array( true, false, false, false, $renamed, 'enabled', $extension ),
			'extension network-active from a renamed folder, feature on' => array( false, true, false, false, $renamed, 'enabled', $extension ),
			'another plugin with a similar main file, feature on' => array( true, false, false, false, 'woocommerce-payments-dev-tools/woocommerce-payments-dev-tools.php', 'enabled', $builtin ),
		);
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
	 * @param string|null $version_recorded The extension's stored version option, or null when absent.
	 * @param bool        $expected         Whether the store reads as having run the extension.
	 */
	public function test_has_run_woopayments_extension_counts_only_the_version_option( ?string $version_recorded, bool $expected ): void {
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
		if ( null !== $version_recorded ) {
			update_option( 'woocommerce_woocommerce_payments_version', $version_recorded );
		} else {
			delete_option( 'woocommerce_woocommerce_payments_version' );
		}

		$this->assertSame( $expected, $this->sut->has_run_woopayments_extension() );
	}

	/**
	 * Stores with a built-in WooPayments order and token, with and without the extension's version option.
	 *
	 * Any recorded version counts, including one below the switch's minimum: the guard asks whether the extension ran, not
	 * whether the store can switch.
	 *
	 * @return array<string,array{0:string|null,1:bool}>
	 */
	public function provide_extension_evidence(): array {
		return array(
			'version option recorded'                   => array( '11.1.0', true ),
			'version below the switch minimum recorded' => array( '10.4.9', true ),
			'only built-in orders and tokens'           => array( null, false ),
		);
	}
}
