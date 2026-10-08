<?php
/**
 * Class WC_Settings_Payment_Gateways_Test file.
 *
 * @package WooCommerce\Tests\Settings
 */

use Automattic\WooCommerce\Admin\Settings\SettingsSection;
use Automattic\WooCommerce\Admin\Settings\SettingsSectionInterface;
use Automattic\WooCommerce\Admin\Settings\SettingsSectionRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Testing\Tools\CodeHacking\Hacks\FunctionsMockerHack;
use Automattic\WooCommerce\Testing\Tools\CodeHacking\Hacks\StaticMockerHack;

require_once __DIR__ . '/class-wc-settings-unit-test-case.php';

/**
 * Unit tests for the WC_Settings_Payment_Gateways class.
 */
class WC_Settings_Payment_Gateways_Test extends WC_Settings_Unit_Test_Case {

	/**
	 * Setup test case.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// Make sure the class file is loaded.
		require_once WC_ABSPATH . 'includes/admin/settings/class-wc-settings-payment-gateways.php';
		SettingsSectionRegistry::get_instance()->unregister_all();
	}

	/**
	 * Tear down test case.
	 */
	public function tearDown(): void {
		SettingsSectionRegistry::get_instance()->unregister_all();
		remove_all_filters( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER );
		remove_all_filters( 'experimental_woocommerce_admin_payment_reactify_render_sections' );
		$this->reset_legacy_proxy_mocks();

		parent::tearDown();
	}

	/**
	 * @testdox get_sections should get all the existing sections.
	 */
	public function test_get_sections() {
		$sut = new WC_Settings_Payment_Gateways();

		$section_names = array_keys( $sut->get_sections() );

		$expected = array(
			'',
		);

		$this->assertEquals( $expected, $section_names );
	}

	/**
	 * get_settings should trigger the appropriate filter depending on the requested section name.
	 *
	 * @testWith ["woocommerce_com", "woocommerce_get_settings_checkout"]
	 *
	 * @param string $section_name The section name to test getting the settings for.
	 * @param string $filter_name The name of the filter that is expected to be triggered.
	 */
	public function test_get_settings_triggers_filter( $section_name, $filter_name ) {
		$actual_settings_via_filter = null;

		add_filter(
			$filter_name,
			function ( $settings ) use ( &$actual_settings_via_filter ) {
				$actual_settings_via_filter = $settings;

				return $settings;
			},
			10,
			1
		);

		$sut = new WC_Settings_Payment_Gateways();

		$actual_settings_returned = $sut->get_settings_for_section( $section_name );
		remove_all_filters( $filter_name );

		$this->assertSame( $actual_settings_returned, $actual_settings_via_filter );
	}

	/**
	 * @testdox get_settings('') should return all the settings for the default section.
	 */
	public function test_get_default_settings_returns_all_settings() {
		$sut = new WC_Settings_Payment_Gateways();

		$settings              = $sut->get_settings_for_section( '' );
		$setting_ids_and_types = $this->get_ids_and_types( $settings );

		$expected = array(
			'payment_gateways_options' => 'sectionend',
			''                         => 'title',
		);

		$this->assertEquals( $expected, $setting_ids_and_types );
	}

	/**
	 * @testdox Output should render registered checkout settings sections.
	 */
	public function test_output_renders_registered_checkout_settings_section(): void {
		global $current_section;
		$current_section = 'acme_payments';

		SettingsSectionRegistry::get_instance()->register( $this->get_registered_payment_section( 'acme_payments' ) );
		$disable_reactified_sections = static function () {
			return array();
		};
		add_filter( 'experimental_woocommerce_admin_payment_reactify_render_sections', $disable_reactified_sections );

		$sut = $this->getMockBuilder( WC_Settings_Payment_Gateways::class )
			->setMethods( array( 'run_gateway_admin_options' ) )
			->getMock();
		$sut->expects( $this->never() )->method( 'run_gateway_admin_options' );

		try {
			ob_start();
			$sut->output();
			$output = ob_get_clean();
		} finally {
			remove_filter( 'experimental_woocommerce_admin_payment_reactify_render_sections', $disable_reactified_sections );
		}

		$this->assertStringContainsString( 'name="registered_acme_payments_setting"', $output );
	}

	/**
	 * @testdox Should not render WooPayments settings as a React section while the plugin owns the runtime.
	 */
	public function test_woopayments_section_is_not_reactified_while_plugin_owns_runtime() {
		$this->set_runtime_owner( WooPaymentsRuntimeArbiter::OWNER_EXTENSION );

		$sut = new WC_Settings_Payment_Gateways();

		$this->assertFalse( $sut->should_render_react_section( WC_Settings_Payment_Gateways::WOOPAYMENTS_SECTION_NAME ) );
	}

	/**
	 * @testdox Should render WooPayments settings as a React section when native owns the runtime.
	 */
	public function test_woopayments_section_is_reactified_when_native_owns_runtime() {
		$this->set_runtime_owner( WooPaymentsRuntimeArbiter::OWNER_BUILTIN );

		$sut = new WC_Settings_Payment_Gateways();

		$this->assertTrue( $sut->should_render_react_section( WC_Settings_Payment_Gateways::WOOPAYMENTS_SECTION_NAME ) );
	}

	/**
	 * @testdox Should not render WooPayments settings as a React section when no runtime owns the site.
	 */
	public function test_woopayments_section_is_not_reactified_when_no_runtime_owns_site() {
		$this->set_runtime_owner( WooPaymentsRuntimeArbiter::OWNER_NONE );

		$sut = new WC_Settings_Payment_Gateways();

		$this->assertFalse( $sut->should_render_react_section( WC_Settings_Payment_Gateways::WOOPAYMENTS_SECTION_NAME ) );
	}

	/**
	 * @testdox Should not allow the optional sections filter to force WooPayments reactification when native does not own the runtime.
	 *
	 * @testWith ["plugin"]
	 *           ["none"]
	 *
	 * @param string $owner Runtime owner.
	 */
	public function test_woopayments_section_filter_cannot_bypass_runtime_ownership( string $owner ): void {
		$this->set_runtime_owner( $owner );
		add_filter(
			'experimental_woocommerce_admin_payment_reactify_render_sections',
			static function () {
				return array( WC_Settings_Payment_Gateways::WOOPAYMENTS_SECTION_NAME );
			}
		);

		$sut = new WC_Settings_Payment_Gateways();

		$this->assertFalse( $sut->should_render_react_section( WC_Settings_Payment_Gateways::WOOPAYMENTS_SECTION_NAME ) );
	}

	/**
	 * @testdox Should render the WooPayments React root for the WooPayments settings section.
	 */
	public function test_woopayments_section_outputs_react_root() {
		global $current_section;
		$current_section = 'woocommerce_payments';
		$this->set_runtime_owner( WooPaymentsRuntimeArbiter::OWNER_BUILTIN );
		$sut = new WC_Settings_Payment_Gateways();

		ob_start();
		$sut->output();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="experimental_wc_settings_payments_woocommerce_payments"', $output );
	}

	/**
	 * @testdox Should emit WooPayments gateway notices inside the native React settings section.
	 */
	public function test_woopayments_section_outputs_gateway_admin_notices() {
		global $current_section;
		$current_section = WC_Settings_Payment_Gateways::WOOPAYMENTS_SECTION_NAME;
		$this->set_runtime_owner( WooPaymentsRuntimeArbiter::OWNER_BUILTIN );
		$notice_callback = static function () {
			echo '<div id="native-woopayments-notice">Notice</div>';
		};
		add_action( 'woocommerce_woocommerce_payments_admin_notices', $notice_callback );

		try {
			$sut = new WC_Settings_Payment_Gateways();
			ob_start();
			$sut->output();
			$output = ob_get_clean();

			$this->assertStringContainsString( 'id="native-woopayments-notice"', $output );
		} finally {
			remove_action( 'woocommerce_woocommerce_payments_admin_notices', $notice_callback );
		}
	}

	/**
	 * @testdox Should not fire the WooPayments settings notices on the settings route while the plugin owns payments.
	 */
	public function test_woopayments_settings_route_does_not_fire_admin_notices_while_plugin_owns_runtime() {
		$this->set_runtime_owner( WooPaymentsRuntimeArbiter::OWNER_EXTENSION );

		$output = $this->render_with_woopayments_notice_counter( '', array( 'path' => '/woopayments/settings' ), $fired );

		$this->assertSame( 0, $fired, 'The plugin fires its own notices from its gateway settings screen, never on the core Payments page.' );
		$this->assertStringNotContainsString( 'id="woopayments-settings-notice"', $output );
	}

	/**
	 * @testdox Should fire the WooPayments settings notices once on the native settings route.
	 */
	public function test_woopayments_settings_route_fires_admin_notices_once_when_native_owns_runtime() {
		$this->set_runtime_owner( WooPaymentsRuntimeArbiter::OWNER_BUILTIN );

		$output = $this->render_with_woopayments_notice_counter( '', array( 'path' => '/woopayments/settings' ), $fired );

		$this->assertSame( 1, $fired );
		$this->assertLessThan( strpos( $output, 'id="experimental_wc_settings_payments_main"' ), strpos( $output, 'id="woopayments-settings-notice"' ), 'Notices render above the settings UI, as in the client.' );
	}

	/**
	 * @testdox Should fire the WooPayments settings notices before the classic native settings form, as the client's admin_options() does.
	 */
	public function test_classic_native_woopayments_settings_fire_admin_notices_before_the_form() {
		$this->set_runtime_owner( WooPaymentsRuntimeArbiter::OWNER_BUILTIN );
		$filter_callback = static function ( $fields ) {
			$fields['custom_extension_field'] = array(
				'title' => 'Custom extension field',
				'type'  => 'text',
			);

			return $fields;
		};
		add_filter( 'woocommerce_settings_api_form_fields_woocommerce_payments', $filter_callback );

		try {
			$output = $this->render_with_woopayments_notice_counter( WC_Settings_Payment_Gateways::WOOPAYMENTS_SECTION_NAME, array(), $fired, $this->create_woopayments_gateway_stub() );
		} finally {
			remove_filter( 'woocommerce_settings_api_form_fields_woocommerce_payments', $filter_callback );
		}

		$this->assertSame( 1, $fired );
		$this->assertStringContainsString( 'id="woopayments-gateway-admin-options"', $output );
		$this->assertLessThan( strpos( $output, 'id="woopayments-gateway-admin-options"' ), strpos( $output, 'id="woopayments-settings-notice"' ), 'Notices render above the settings form, as in the client.' );
	}

	/**
	 * @testdox Should leave the WooPayments settings notices to the plugin's own gateway screen while the plugin owns payments.
	 */
	public function test_classic_plugin_woopayments_settings_do_not_fire_admin_notices_from_core() {
		$this->set_runtime_owner( WooPaymentsRuntimeArbiter::OWNER_EXTENSION );

		$output = $this->render_with_woopayments_notice_counter( WC_Settings_Payment_Gateways::WOOPAYMENTS_SECTION_NAME, array(), $fired, $this->create_woopayments_gateway_stub() );

		$this->assertStringContainsString( 'id="woopayments-gateway-admin-options"', $output );
		$this->assertSame( 0, $fired, 'The plugin gateway fires the action itself; core must not fire it a second time.' );
	}

	/**
	 * Render the Payments settings page with a counting WooPayments notices callback attached.
	 *
	 * @param string                  $section Current section.
	 * @param array<string,string>    $query   Request query args.
	 * @param int|null                $fired   Receives the number of times the action fired.
	 * @param WC_Payment_Gateway|null $gateway Optional gateway to expose as the only loaded gateway.
	 * @return string
	 */
	private function render_with_woopayments_notice_counter( string $section, array $query, ?int &$fired, ?WC_Payment_Gateway $gateway = null ): string {
		global $current_section;

		$fired            = 0;
		$notice_callback  = static function () use ( &$fired ) {
			++$fired;
			echo '<div id="woopayments-settings-notice">Notice</div>';
		};
		$previous_get     = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$previous_section = $current_section;
		$gateways         = WC()->payment_gateways();
		$loaded_gateways  = $gateways->payment_gateways;
		$buffer_level     = ob_get_level();

		$_GET            = $query; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_section = $section;
		if ( null !== $gateway ) {
			$gateways->payment_gateways = array( $gateway );
		}
		add_action( 'woocommerce_woocommerce_payments_admin_notices', $notice_callback );

		try {
			$sut = new WC_Settings_Payment_Gateways();
			ob_start();
			$sut->output();

			return (string) ob_get_clean();
		} finally {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			remove_action( 'woocommerce_woocommerce_payments_admin_notices', $notice_callback );
			$gateways->payment_gateways = $loaded_gateways;
			$_GET                       = $previous_get;
			$current_section            = $previous_section;
		}
	}

	/**
	 * Create a WooPayments gateway stub whose settings screen marks where it renders.
	 *
	 * @return WC_Payment_Gateway
	 */
	private function create_woopayments_gateway_stub(): WC_Payment_Gateway {
		return new class() extends WC_Payment_Gateway {
			/**
			 * Constructor.
			 */
			public function __construct() {
				$this->id = WC_Settings_Payment_Gateways::WOOPAYMENTS_SECTION_NAME;
			}

			/**
			 * Output a marker instead of the settings form.
			 */
			public function admin_options() {
				echo '<div id="woopayments-gateway-admin-options"></div>';
			}
		};
	}

	/**
	 * @testdox Should preserve classic WooPayments settings field extensions.
	 */
	public function test_woopayments_section_preserves_classic_settings_field_extensions() {
		$filter_callback = static function ( $fields ) {
			$fields['custom_extension_field'] = array(
				'title' => 'Custom extension field',
				'type'  => 'text',
			);

			return $fields;
		};
		add_filter( 'woocommerce_settings_api_form_fields_woocommerce_payments', $filter_callback );

		try {
			$sut = new WC_Settings_Payment_Gateways();

			$this->assertFalse( $sut->should_render_react_section( 'woocommerce_payments' ) );
		} finally {
			remove_filter( 'woocommerce_settings_api_form_fields_woocommerce_payments', $filter_callback );
		}
	}

	/**
	 * @testdox Should allow the WooPayments React section to be removed through the optional sections filter.
	 */
	public function test_woopayments_section_can_be_removed_from_optional_reactified_sections() {
		$filter_callback = static function () {
			return array(
				WC_Settings_Payment_Gateways::COD_SECTION_NAME,
				WC_Settings_Payment_Gateways::BACS_SECTION_NAME,
				WC_Settings_Payment_Gateways::CHEQUE_SECTION_NAME,
			);
		};
		add_filter( 'experimental_woocommerce_admin_payment_reactify_render_sections', $filter_callback );

		$sut = new WC_Settings_Payment_Gateways();

		$this->assertFalse( $sut->should_render_react_section( 'woocommerce_payments' ) );

		remove_filter( 'experimental_woocommerce_admin_payment_reactify_render_sections', $filter_callback );
	}

	/**
	 * @testDox 'save' will trigger 'init' (and 'process_admin_options' if current section is the name of an existing gateway), and the appropriate actions.
	 *
	 * @testWith ["bacs", false]
	 *           ["wc_gateway_bacs", false]
	 *           ["", true]
	 *
	 * @param string $section_name The current section name.
	 * @param bool   $expect_to_run_process_admin_options Whether 'admin_options' is expected to be invoked in WC_Payment_Gateways or not.
	 */
	public function test_save_triggers_appropriate_gateway_methods_and_actions( $section_name, $expect_to_run_process_admin_options ) {
		global $current_section;
		$current_section = $section_name;

		$process_admin_options_invoked = false;
		$init_invoked                  = false;

		$gateway = WC_Payment_Gateways::instance()->payment_gateways()[ WC_Gateway_BACS::ID ];

		$payment_gateways = $this->getMockBuilder( WC_Payment_Gateways::class )
								 ->setMethods( array( 'process_admin_options', 'init', 'payment_gateways' ) )
								 ->getMock();

		$payment_gateways->method( 'process_admin_options' )
						->will(
							$this->returnCallback(
								function() use ( &$process_admin_options_invoked ) {
									$process_admin_options_invoked = true;
								}
							)
						);

		$payment_gateways->method( 'init' )
						->will(
							$this->returnCallback(
								function() use ( &$init_invoked ) {
									$init_invoked = true;
								}
							)
						);

		$payment_gateways->method( 'payment_gateways' )
						 ->willReturn( array( $gateway ) );

		StaticMockerHack::add_method_mocks(
			array(
				'WC_Payment_Gateways' => array(
					'instance' => function() use ( $payment_gateways ) {
						return $payment_gateways;
					},
				),
			)
		);

		$sut = new WC_Settings_Payment_Gateways();
		$sut->save();

		$this->assertTrue( $init_invoked );
		$this->assertEquals( $expect_to_run_process_admin_options, $process_admin_options_invoked );

		$this->assertEquals( '' === $section_name ? 0 : 1, did_action( 'woocommerce_update_options_payment_gateways_bacs' ) );
		$this->assertEquals( '' === $section_name ? 0 : 1, did_action( 'woocommerce_update_options_checkout_' . $section_name ) );
	}

	/**
	 * Build a registered payment settings section.
	 *
	 * @param string $section_id Section id.
	 * @return SettingsSectionInterface
	 */
	private function get_registered_payment_section( string $section_id ): SettingsSectionInterface {
		return new class( $section_id ) extends SettingsSection {
			/**
			 * Section id.
			 *
			 * @var string
			 */
			private string $section_id;

			/**
			 * Constructor.
			 *
			 * @param string $section_id Section id.
			 */
			public function __construct( string $section_id ) {
				$this->section_id = $section_id;
			}

			/**
			 * Get the parent page id.
			 *
			 * @return string
			 */
			public function get_parent_page_id(): string {
				return WC_Settings_Payment_Gateways::TAB_NAME;
			}

			/**
			 * Get the section id.
			 *
			 * @return string
			 */
			public function get_id(): string {
				return $this->section_id;
			}

			/**
			 * Get the section label.
			 *
			 * @return string
			 */
			public function get_label(): string {
				return 'Registered payment section';
			}

			/**
			 * Get legacy settings.
			 *
			 * @param WC_Settings_Page $parent_page Parent settings page.
			 * @return array
			 */
			public function get_settings( WC_Settings_Page $parent_page ): array {
				return array(
					array(
						'id'    => 'registered_' . $this->section_id . '_setting',
						'type'  => 'text',
						'title' => 'Registered payment section setting',
					),
				);
			}

		};
	}

	/**
	 * Set the payments runtime owner for the current test.
	 *
	 * @param string $owner Runtime owner.
	 */
	private function set_runtime_owner( string $owner ): void {
		$plugin_active = WooPaymentsRuntimeArbiter::OWNER_EXTENSION === $owner;
		$entry         = WooPaymentsRuntimeArbiter::PLUGIN_FILE;
		$this->register_legacy_proxy_function_mocks(
			array(
				'get_option'      => function ( $name, $default_value = false ) use ( $plugin_active, $entry ) {
					if ( 'active_plugins' === $name ) {
						return $plugin_active ? array( $entry ) : array();
					}
					return get_option( $name, $default_value );
				},
				'get_site_option' => function ( $name, $default_value = false ) {
					if ( 'active_sitewide_plugins' === $name ) {
						return array();
					}
					return get_site_option( $name, $default_value );
				},
				'class_exists'    => function ( $class_name, $autoload = true ) use ( $plugin_active ) {
					if ( 'WC_Payments' === ltrim( (string) $class_name, '\\' ) ) {
						return $plugin_active;
					}
					return class_exists( $class_name, $autoload );
				},
			)
		);

		if ( WooPaymentsRuntimeArbiter::OWNER_BUILTIN === $owner ) {
			add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
			return;
		}

		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_false' );
	}
}
