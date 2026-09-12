<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySettingsController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySettingsPage;
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
		'wcpay_settings',
		'woocommerce_multi_currency_js_settings',
		'wcpay_js_settings',
		'admin_print_scripts',
		'woocommerce_admin_field_wcpay_multi_currency_settings_page',
		'admin_enqueue_scripts',
	);

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->hooks as $hook ) {
			remove_all_filters( $hook );
		}

		unset( $GLOBALS['hide_save_button'] );

		parent::tearDown();
	}

	/**
	 * @testdox Should not register hooks when plugin owns runtime.
	 */
	public function test_does_not_register_hooks_when_plugin_owns_runtime(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_PLUGIN );

		$sut->register();

		$this->assertFalse( has_filter( 'woocommerce_get_settings_pages', array( $sut, 'handle_woocommerce_get_settings_pages' ) ) );
		$this->assertFalse( has_filter( 'wcpay_settings', array( $sut, 'add_multi_currency_settings_config' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_multi_currency_js_settings', array( $sut, 'add_multi_currency_settings_config' ) ) );
		$this->assertFalse( has_filter( 'wcpay_js_settings', array( $sut, 'add_multi_currency_settings_config' ) ) );
		$this->assertFalse( has_action( 'admin_print_scripts', array( $sut, 'handle_admin_print_scripts' ) ) );
		$this->assertFalse( has_action( 'woocommerce_admin_field_wcpay_multi_currency_settings_page', array( $sut, 'render_settings_container' ) ) );
		$this->assertFalse( has_action( 'admin_enqueue_scripts', array( $sut, 'handle_admin_enqueue_scripts' ) ) );
	}

	/**
	 * @testdox Should register settings hooks when core owns runtime.
	 */
	public function test_registers_settings_hooks_when_core_owns_runtime(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );

		$sut->register();
		$sut->register();

		$this->assertSame( 10, has_filter( 'woocommerce_get_settings_pages', array( $sut, 'handle_woocommerce_get_settings_pages' ) ) );
		$this->assertFalse( has_filter( 'wcpay_settings', array( $sut, 'add_multi_currency_settings_config' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_multi_currency_js_settings', array( $sut, 'add_multi_currency_settings_config' ) ) );
		$this->assertFalse( has_filter( 'wcpay_js_settings', array( $sut, 'add_multi_currency_settings_config' ) ) );
		$this->assertSame( 10, has_action( 'admin_print_scripts', array( $sut, 'handle_admin_print_scripts' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_admin_field_wcpay_multi_currency_settings_page', array( $sut, 'render_settings_container' ) ) );
		$this->assertFalse( has_action( 'woocommerce_admin_field_wcpay_currencies_settings_onboarding_cta', array( $sut, 'render_onboarding_cta' ) ) );
		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( $sut, 'handle_admin_enqueue_scripts' ) ) );
	}

	/**
	 * @testdox Should preserve invalid settings returned by an earlier Core filter callback.
	 */
	public function test_preserves_invalid_settings_from_an_earlier_core_filter_callback(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );
		add_filter( 'woocommerce_multi_currency_js_settings', '__return_null', 5 );
		$sut->register();

		$config = apply_filters( 'woocommerce_multi_currency_js_settings', array( 'existingSetting' => true ) );

		$this->assertNull( $config );
	}

	/**
	 * @testdox Should register settings page without a provider dependency.
	 */
	public function test_registers_settings_page_without_a_provider_dependency(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );

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
	 * @testdox Should register the settings page when no provider is connected.
	 */
	public function test_registers_the_settings_page_when_no_provider_is_connected(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );

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
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );

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
			MultiCurrencyRuntimeArbiter::OWNER_CORE,
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
			MultiCurrencyRuntimeArbiter::OWNER_CORE,
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
	 * @testdox Should skip admin asset enqueue when bundle is missing.
	 */
	public function test_skips_admin_asset_enqueue_when_bundle_is_missing(): void {
		$registered_assets = array();
		$enqueued_assets   = array();
		$sut               = $this->create_controller(
			MultiCurrencyRuntimeArbiter::OWNER_CORE,
			array(
				'asset_available'   => false,
				'registered_assets' => &$registered_assets,
				'enqueued_assets'   => &$enqueued_assets,
			)
		);

		$sut->handle_admin_enqueue_scripts();

		$this->assertSame( array(), $registered_assets );
		$this->assertSame( array(), $enqueued_assets );
	}

	/**
	 * @testdox Should enqueue admin assets when bundle is available.
	 */
	public function test_enqueues_admin_assets_when_bundle_is_available(): void {
		$registered_assets = array();
		$enqueued_assets   = array();
		$sut               = $this->create_controller(
			MultiCurrencyRuntimeArbiter::OWNER_CORE,
			array(
				'asset_available'   => true,
				'registered_assets' => &$registered_assets,
				'enqueued_assets'   => &$enqueued_assets,
			)
		);

		$sut->handle_admin_enqueue_scripts();

		$this->assertCount( 1, $registered_assets );
		$this->assertSame( 'multi-currency-settings', $registered_assets[0]['script']['entry'] );
		$this->assertSame( 'wc-admin-multi-currency-settings', $registered_assets[0]['script']['handle'] );
		$this->assertSame( 'multi-currency-settings', $registered_assets[0]['style']['entry'] );
		$this->assertSame( 'style', $registered_assets[0]['style']['file'] );
		$this->assertSame( 'wc-admin-multi-currency-settings', $registered_assets[0]['style']['handle'] );
		$this->assertSame(
			array(
				'wc-admin-multi-currency-settings',
				'wc-admin-multi-currency-settings',
			),
			$enqueued_assets
		);
	}

	/**
	 * @testdox Should add multi-currency flag to WCPay JS config.
	 */
	public function test_adds_multi_currency_flag_to_wcpay_js_config(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_CORE );

		$this->assertSame(
			array(
				'foo'                    => 'bar',
				'isMultiCurrencyEnabled' => true,
			),
			$sut->add_multi_currency_settings_config( array( 'foo' => 'bar' ) )
		);
	}

	/**
	 * Create a settings controller.
	 *
	 * @param string              $owner   Runtime owner.
	 * @param array<string,mixed> $options Controller options.
	 * @return MultiCurrencySettingsController
	 */
	private function create_controller( string $owner, array $options = array() ): MultiCurrencySettingsController {
		$controller = new MultiCurrencySettingsController();
		$controller->init( $this->create_arbiter( $owner ) );
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
		$controller->set_asset_enqueuer(
			static function ( string $handle ) use ( &$options ): void {
				if ( isset( $options['enqueued_assets'] ) && is_array( $options['enqueued_assets'] ) ) {
					$options['enqueued_assets'][] = $handle;
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
				return MultiCurrencyRuntimeArbiter::OWNER_CORE === $this->owner;
			}
		};
	}
}
