<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySettingsController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySettingsPage;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyCacheRenderingService;
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

		unset( $GLOBALS['hide_save_button'] );
		Constants::clear_single_constant( 'WP_CLI' );
		Constants::clear_single_constant( 'WPCOM_JOBS' );
		global $wp_actions;
		unset( $wp_actions['upgrader_process_complete'] );

		parent::tearDown();
	}

	/**
	 * @testdox Should not register hooks when plugin owns runtime.
	 */
	public function test_does_not_register_hooks_when_plugin_owns_runtime(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_EXTENSION );

		$sut->register();

		$this->assertFalse( has_filter( 'woocommerce_get_settings_pages', array( $sut, 'handle_woocommerce_get_settings_pages' ) ) );
		$this->assertFalse( has_action( 'admin_print_scripts', array( $sut, 'handle_admin_print_scripts' ) ) );
		$this->assertFalse( has_action( 'woocommerce_admin_field_wcpay_multi_currency_settings_page', array( $sut, 'render_settings_container' ) ) );
		$this->assertFalse( has_action( 'admin_enqueue_scripts', array( $sut, 'handle_admin_enqueue_scripts' ) ) );
		$this->assertFalse( has_action( 'admin_init', array( $sut, 'handle_admin_init' ) ) );
	}

	/**
	 * @testdox Should register settings hooks when core owns runtime.
	 */
	public function test_registers_settings_hooks_when_core_owns_runtime(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );

		$sut->register();
		$sut->register();

		$this->assertSame( 10, has_filter( 'woocommerce_get_settings_pages', array( $sut, 'handle_woocommerce_get_settings_pages' ) ) );
		$this->assertSame( 10, has_action( 'admin_print_scripts', array( $sut, 'handle_admin_print_scripts' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_admin_field_wcpay_multi_currency_settings_page', array( $sut, 'render_settings_container' ) ) );
		$this->assertFalse( has_action( 'woocommerce_admin_field_wcpay_currencies_settings_onboarding_cta', array( $sut, 'render_onboarding_cta' ) ) );
		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( $sut, 'handle_admin_enqueue_scripts' ) ) );
		$this->assertSame( 10, has_action( 'admin_init', array( $sut, 'handle_admin_init' ) ) );
	}

	/**
	 * @testdox Should run cache auto-detection only for an authorized core admin request.
	 */
	public function test_runs_cache_auto_detection_only_for_an_authorized_core_admin_request(): void {
		$service = $this->getMockBuilder( MultiCurrencyCacheRenderingService::class )
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
		$service = $this->getMockBuilder( MultiCurrencyCacheRenderingService::class )
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
	 * @testdox Should register settings page without a provider dependency.
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
	 * @testdox Should register the settings page when no provider is connected.
	 */
	public function test_registers_the_settings_page_when_no_provider_is_connected(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );

		$settings_pages = $sut->handle_woocommerce_get_settings_pages( array() );
		$settings_page  = $settings_pages[0];

		$this->assertInstanceOf( MultiCurrencySettingsPage::class, $settings_page );
		$this->assertSame( 'wcpay_multi_currency', $settings_page->get_id() );
		$this->assertSame( array( array( 'type' => 'wcpay_multi_currency_settings_page' ) ), $settings_page->get_settings() );
		$this->assertTrue( $GLOBALS['hide_save_button'] );
	}

	/**
	 * @testdox Should render settings container and hide save button.
	 */
	public function test_renders_settings_container_and_hides_save_button(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );

		ob_start();
		$sut->render_settings_container();
		$markup = ob_get_clean();

		$this->assertSame(
			'<div id="wcpay_multi_currency_settings_container" class="wc-settings-prevent-change-event" aria-describedby="wcpay_multi_currency_settings_container-description"></div>',
			$markup
		);
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
		$sut->set_emoji_detection_script_printer(
			static function (): void {
				echo '<script>wpemojiSettings</script>';
			}
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
		$sut->set_emoji_detection_script_printer(
			static function (): void {
				echo '<script>wpemojiSettings</script>';
			}
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
		$controller              = new MultiCurrencySettingsController();
		$cache_rendering_service = $options['cache_rendering_service'] ?? null;
		$controller->init( $this->create_arbiter( $owner ), $cache_rendering_service );
		$controller->set_admin_request_resolver(
			static fn(): bool => (bool) ( $options['is_admin'] ?? true )
		);
		$controller->set_current_tab_resolver(
			static fn(): string => (string) ( $options['current_tab'] ?? 'wcpay_multi_currency' )
		);
		$controller->set_current_screen_base_resolver(
			static fn(): string => (string) ( $options['screen_base'] ?? 'woocommerce_page_wc-settings' )
		);
		$controller->set_asset_available_resolver(
			static fn(): bool => (bool) ( $options['asset_available'] ?? false )
		);

		$controller->set_asset_registrar(
			static function ( array $manifest ) use ( &$options ): void {
				if ( isset( $options['registered_assets'] ) && is_array( $options['registered_assets'] ) ) {
					$options['registered_assets'][] = $manifest;
				}
			}
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
			public function should_core_register(): bool {
				return MultiCurrencyRuntimeArbiter::OWNER_BUILTIN === $this->owner;
			}
		};
	}
}
