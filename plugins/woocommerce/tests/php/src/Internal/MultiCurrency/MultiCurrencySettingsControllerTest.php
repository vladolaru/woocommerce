<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySettingsController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySettingsPage;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\CacheRenderingService;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencySettingsController class.
 */
class MultiCurrencySettingsControllerTest extends WC_Unit_Test_Case {

	/**
	 * Hooks touched by the settings controller.
	 *
	 * @var string[]
	 */
	private array $hooks = array(
		'woocommerce_get_settings_pages',
		'admin_print_scripts',
		'woocommerce_admin_field_wcpay_multi_currency_settings_page',
		'admin_enqueue_scripts',
		'admin_init',
	);

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->hooks as $hook ) {
			remove_all_filters( $hook );
		}

		unset( $GLOBALS['hide_save_button'], $GLOBALS['current_tab'] );
		$this->reset_legacy_proxy_mocks();
		Constants::clear_single_constant( 'WP_CLI' );
		Constants::clear_single_constant( 'WPCOM_JOBS' );
		global $wp_actions;
		unset( $wp_actions['upgrader_process_complete'] );

		parent::tearDown();
	}

	/**
	 * @testdox Should register exactly the settings hooks the owner allows, once: $_dataName.
	 * @dataProvider provide_settings_hooks_owners
	 *
	 * @param string                            $owner    Runtime owner.
	 * @param array<string,array<string,int[]>> $expected Every hook the controller registers.
	 */
	public function test_registers_the_settings_hooks_its_owner_allows( string $owner, array $expected ): void {
		$sut = $this->create_controller( $owner );

		$sut->register();
		$sut->register();

		$this->assertSame( $expected, RegisteredHooks::of( $sut ) );
	}

	/**
	 * Owners and the hooks each registers: the settings page and its scripts under core, nothing under the extension.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function provide_settings_hooks_owners(): array {
		return array(
			'core owns'          => array(
				MultiCurrencyRuntimeArbiter::OWNER_BUILTIN,
				array(
					'admin_enqueue_scripts'          => array( 'handle_admin_enqueue_scripts' => array( 10, 1 ) ),
					'admin_init'                     => array( 'handle_admin_init' => array( 10, 1 ) ),
					'admin_print_scripts'            => array( 'handle_admin_print_scripts' => array( 10, 1 ) ),
					'woocommerce_admin_field_wcpay_multi_currency_settings_page' => array( 'render_settings_container' => array( 10, 1 ) ),
					'woocommerce_get_settings_pages' => array( 'handle_woocommerce_get_settings_pages' => array( 10, 1 ) ),
				),
			),
			'the extension owns' => array( MultiCurrencyRuntimeArbiter::OWNER_EXTENSION, array() ),
		);
	}

	/**
	 * @testdox Should run cache auto-detection only for an authorized core admin request.
	 */
	public function test_runs_cache_auto_detection_only_for_an_authorized_core_admin_request(): void {
		$service = $this->getMockBuilder( CacheRenderingService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'maybe_auto_enable_cache_rendering_mode' ) )
			->getMock();
		$service->expects( $this->once() )->method( 'maybe_auto_enable_cache_rendering_mode' );
		$sut     = $this->create_controller(
			MultiCurrencyRuntimeArbiter::OWNER_BUILTIN,
			array( 'cache_rendering_service' => $service )
		);
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$sut->register();
		$this->assertSame( 10, has_action( 'admin_init', array( $sut, 'handle_admin_init' ) ) );
		$sut->handle_admin_init();
	}

	/**
	 * @testdox Should not run cache auto-detection for a $role $label.
	 * @testWith ["administrator", "outside an admin request", false]
	 *           ["subscriber", "in an admin request", true]
	 *
	 * @param string $role     The current user's role.
	 * @param string $label    Readable case name.
	 * @param bool   $is_admin Whether the request is an admin request.
	 */
	public function test_does_not_run_cache_auto_detection_outside_an_authorized_admin_request( string $role, string $label, bool $is_admin ): void {
		unset( $label );
		$service = $this->getMockBuilder( CacheRenderingService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'maybe_auto_enable_cache_rendering_mode' ) )
			->getMock();
		$service->expects( $this->never() )->method( 'maybe_auto_enable_cache_rendering_mode' );
		$sut     = $this->create_controller(
			MultiCurrencyRuntimeArbiter::OWNER_BUILTIN,
			array(
				'cache_rendering_service' => $service,
				'is_admin'                => $is_admin,
			)
		);
		$user_id = $this->factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );

		$sut->handle_admin_init();
	}

	/**
	 * @testdox Should not register cache auto-detection when no runtime owns multi-currency.
	 */
	public function test_does_not_register_cache_auto_detection_without_a_runtime_owner(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_NONE );

		$sut->register();

		$this->assertFalse( has_action( 'admin_init', array( $sut, 'handle_admin_init' ) ) );
	}

	/**
	 * @testdox Should register the settings page while no provider is connected.
	 */
	public function test_registers_settings_page_without_a_provider_dependency(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );

		$settings_pages = $sut->handle_woocommerce_get_settings_pages( array( 'existing' ) );
		$settings_page  = $settings_pages[1];

		$this->assertSame( 'existing', $settings_pages[0] );
		$this->assertInstanceOf( MultiCurrencySettingsPage::class, $settings_page );
		$this->assertSame( 'wcpay_multi_currency', $settings_page->get_id() );
		$this->assertSame( 'Multi-currency', $settings_page->get_label() );
		$this->assertSame(
			array(
				array(
					'type' => 'wcpay_multi_currency_settings_page',
				),
			),
			$settings_page->get_settings()
		);
		$this->assertTrue( $GLOBALS['hide_save_button'] );
	}

	/**
	 * @testdox Should not add the settings page on a $label request.
	 * @testWith ["WP-CLI"]
	 *           ["WordPress.com jobs"]
	 *           ["upgrader"]
	 *
	 * @param string $label The request kind.
	 */
	public function test_does_not_add_the_settings_page_on_cli_jobs_or_upgrader_requests( string $label ): void {
		global $wp_actions;
		if ( 'WP-CLI' === $label ) {
			Constants::set_constant( 'WP_CLI', true );
		} elseif ( 'WordPress.com jobs' === $label ) {
			Constants::set_constant( 'WPCOM_JOBS', true );
		} else {
			$wp_actions['upgrader_process_complete'] = 1; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Marks this request as one where the upgrader ran.
		}
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );

		$this->assertSame( array( 'existing' ), $sut->handle_woocommerce_get_settings_pages( array( 'existing' ) ) );
	}

	/**
	 * @testdox Should render settings container and hide save button.
	 */
	public function test_renders_settings_container_and_hides_save_button(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );

		ob_start();
		$sut->render_settings_container();
		$markup = ob_get_clean();

		// One empty div, compared by tag and attribute set: WordPress 7.2 prints the attributes in another order.
		$this->assertMatchesRegularExpression( '#^<div\b[^>]*></div>$#', $markup );
		$processor = new \WP_HTML_Tag_Processor( $markup );
		$this->assertTrue( $processor->next_tag() );
		$this->assertSame( 'DIV', $processor->get_tag() );
		$attribute_names = $processor->get_attribute_names_with_prefix( '' );
		sort( $attribute_names );
		$this->assertSame( array( 'aria-describedby', 'class', 'id' ), $attribute_names );
		$this->assertSame( 'wcpay_multi_currency_settings_container', $processor->get_attribute( 'id' ) );
		$this->assertSame( 'wc-settings-prevent-change-event', $processor->get_attribute( 'class' ) );
		$this->assertSame( 'wcpay_multi_currency_settings_container-description', $processor->get_attribute( 'aria-describedby' ) );
		$this->assertTrue( $GLOBALS['hide_save_button'] );
	}

	/**
	 * @testdox Should print emoji detection script only on settings page.
	 */
	public function test_prints_emoji_detection_script_only_on_settings_page(): void {
		$sut = $this->create_controller(
			MultiCurrencyRuntimeArbiter::OWNER_BUILTIN,
			array(
				'is_admin'    => true,
				'current_tab' => 'wcpay_multi_currency',
				'screen_base' => 'woocommerce_page_wc-settings',
			)
		);
		$this->register_legacy_proxy_function_mocks(
			array(
				'print_emoji_detection_script' => static function (): void {
					echo '<script>wpemojiSettings</script>';
				},
			)
		);

		ob_start();
		$sut->handle_admin_print_scripts();
		$settings_page_output = ob_get_clean();

		$sut = $this->create_controller(
			MultiCurrencyRuntimeArbiter::OWNER_BUILTIN,
			array(
				'is_admin'    => true,
				'current_tab' => 'checkout',
				'screen_base' => 'woocommerce_page_wc-settings',
			)
		);
		$this->register_legacy_proxy_function_mocks(
			array(
				'print_emoji_detection_script' => static function (): void {
					echo '<script>wpemojiSettings</script>';
				},
			)
		);

		ob_start();
		$sut->handle_admin_print_scripts();
		$other_page_output = ob_get_clean();

		$this->assertStringContainsString( 'wpemojiSettings', $settings_page_output );
		$this->assertSame( '', $other_page_output );
	}

	/**
	 * @testdox Should not register admin assets when $label.
	 * @testWith ["the bundle is missing", "wcpay_multi_currency", false]
	 *           ["another settings tab is open", "checkout", true]
	 *
	 * @param string $label           Readable case name.
	 * @param string $current_tab     The settings tab.
	 * @param bool   $asset_available Whether the built bundle exists.
	 */
	public function test_does_not_register_admin_assets_off_the_tab_or_without_the_bundle( string $label, string $current_tab, bool $asset_available ): void {
		unset( $label );
		$registered_assets = array();
		$sut               = $this->create_controller(
			MultiCurrencyRuntimeArbiter::OWNER_BUILTIN,
			array(
				'current_tab'       => $current_tab,
				'asset_available'   => $asset_available,
				'registered_assets' => &$registered_assets,
			)
		);

		$sut->handle_admin_enqueue_scripts();

		$this->assertSame( array(), $registered_assets );
	}

	/**
	 * @testdox Should enqueue admin assets when bundle is available.
	 */
	public function test_enqueues_admin_assets_when_bundle_is_available(): void {
		$registered_assets = array();
		$sut               = $this->create_controller(
			MultiCurrencyRuntimeArbiter::OWNER_BUILTIN,
			array(
				'asset_available'   => true,
				'registered_assets' => &$registered_assets,
			)
		);

		$sut->handle_admin_enqueue_scripts();

		$this->assertSame(
			array(
				array(
					'script' => array(
						'entry'  => 'multi-currency-settings',
						'handle' => 'wc-admin-multi-currency-settings',
					),
					'style'  => array(
						'entry'  => 'multi-currency-settings',
						'file'   => 'style',
						'handle' => 'wc-admin-multi-currency-settings',
					),
				),
			),
			$registered_assets
		);
	}

	/**
	 * @testdox Only the Multi-currency tab of the WooCommerce settings screen is the settings page: $label.
	 * @testWith ["Multi-currency tab", "wcpay_multi_currency", "woocommerce_page_wc-settings", true]
	 *           ["another tab", "checkout", "woocommerce_page_wc-settings", false]
	 *           ["another screen", "wcpay_multi_currency", "dashboard", false]
	 *
	 * @param string $label       Readable case name.
	 * @param string $current_tab The settings tab.
	 * @param string $screen_base The screen base.
	 * @param bool   $expected    Whether it is the settings page.
	 */
	public function test_tells_the_multi_currency_settings_page( string $label, string $current_tab, string $screen_base, bool $expected ): void {
		unset( $label );

		$this->assertSame( $expected, MultiCurrencySettingsController::is_multi_currency_settings_page( $current_tab, $screen_base ) );
	}

	/**
	 * Create a settings controller.
	 *
	 * @param string              $owner   Runtime owner.
	 * @param array<string,mixed> $options Controller options.
	 * @return MultiCurrencySettingsController
	 */
	private function create_controller( string $owner, array $options = array() ): MultiCurrencySettingsController {
		// An admin request on the given screen; outside admin no screen is set.
		if ( (bool) ( $options['is_admin'] ?? true ) ) {
			set_current_screen( (string) ( $options['screen_base'] ?? 'woocommerce_page_wc-settings' ) );
		}
		$GLOBALS['current_tab'] = (string) ( $options['current_tab'] ?? 'wcpay_multi_currency' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The settings tab WooCommerce sets; unset in tearDown().
		$asset_available        = (bool) ( $options['asset_available'] ?? false );
		$this->register_legacy_proxy_function_mocks(
			array(
				'file_exists' => static function ( string $path ) use ( $asset_available ): bool {
					unset( $path );

					return $asset_available;
				},
			)
		);

		// The registration reads the built admin bundle, which CI's PHP job lacks, so the double records the manifest instead.
		$controller = new class(
			static function ( array $manifest ) use ( &$options ): void {
				if ( isset( $options['registered_assets'] ) && is_array( $options['registered_assets'] ) ) {
					$options['registered_assets'][] = $manifest;
				}
			}
		) extends MultiCurrencySettingsController {
			/**
			 * Records each asset manifest the controller registers.
			 *
			 * @var callable
			 */
			private $record;

			/**
			 * Constructor.
			 *
			 * @param callable $record Records each asset manifest the controller registers.
			 */
			public function __construct( callable $record ) {
				$this->record = $record;
			}

			/**
			 * Record the asset manifest instead of registering it.
			 *
			 * @param array<string,array<string,string>> $assets The script and style entries, files and handles.
			 */
			protected function register_admin_assets( array $assets ): void {
				call_user_func( $this->record, $assets );
			}
		};
		$controller->init(
			$this->create_arbiter( $owner ),
			wc_get_container()->get( LegacyProxy::class ),
			$options['cache_rendering_service'] ?? wc_get_container()->get( CacheRenderingService::class )
		);

		return $controller;
	}

	/**
	 * Create a static multi-currency runtime arbiter.
	 *
	 * @param string $owner Runtime owner.
	 * @return MultiCurrencyRuntimeArbiter
	 */
	private function create_arbiter( string $owner ): MultiCurrencyRuntimeArbiter {
		return new class( $owner ) extends MultiCurrencyRuntimeArbiter {
			/**
			 * Runtime owner.
			 *
			 * @var string
			 */
			private string $owner;

			/**
			 * Constructor.
			 *
			 * @param string $owner Runtime owner.
			 */
			public function __construct( string $owner ) {
				$this->owner = $owner;
			}

			/**
			 * Get the multi-currency runtime owner for the current site.
			 *
			 * @return string
			 */
			public function get_runtime_owner(): string {
				return $this->owner;
			}

			/**
			 * Tell whether core multi-currency may register hooks.
			 *
			 * @return bool
			 */
			public function is_builtin_owner(): bool {
				return MultiCurrencyRuntimeArbiter::OWNER_BUILTIN === $this->owner;
			}
		};
	}
}
