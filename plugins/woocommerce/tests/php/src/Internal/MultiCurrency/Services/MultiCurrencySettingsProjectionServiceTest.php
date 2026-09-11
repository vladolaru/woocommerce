<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencySettingsProjectionService;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencySettingsProjectionService class.
 */
class MultiCurrencySettingsProjectionServiceTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should project the settings page manifest regardless of provider state.
	 */
	public function test_projects_settings_page_manifest_regardless_of_provider_state(): void {
		$manifest = MultiCurrencySettingsProjectionService::get_settings_page_manifest(
			false,
			false,
			false
		);

		$this->assertSame( 'wcpay_multi_currency', $manifest['id'] );
		$this->assertSame( 'Multi-currency', $manifest['label'] );
		$this->assertSame( 'settings', $manifest['mode'] );
		$this->assertTrue( $manifest['hide_save_button'] );
		$this->assertSame(
			array(
				array(
					'type' => 'wcpay_multi_currency_settings_page',
				),
			),
			$manifest['settings']
		);
		$this->assertSame(
			$manifest,
			MultiCurrencySettingsProjectionService::get_settings_page_manifest( false, false, false )
		);
	}

	/**
	 * @testdox Should omit boot contexts that must not instantiate settings pages.
	 */
	public function test_omits_boot_contexts_that_must_not_instantiate_settings_pages(): void {
		$this->assertSame(
			array(),
			MultiCurrencySettingsProjectionService::get_settings_page_manifest( true, false, false )
		);
		$this->assertSame(
			array(),
			MultiCurrencySettingsProjectionService::get_settings_page_manifest( false, true, false )
		);
		$this->assertSame(
			array(),
			MultiCurrencySettingsProjectionService::get_settings_page_manifest( false, false, true )
		);
	}

	/**
	 * @testdox Should project settings hooks container and page detection.
	 */
	public function test_projects_settings_hooks_container_and_page_detection(): void {
		$this->assertSame(
			array(
				'actions' => array(
					array(
						'hook'     => 'admin_print_scripts',
						'callback' => 'maybe_add_print_emoji_detection_script',
						'priority' => 10,
					),
					array(
						'hook'     => 'woocommerce_admin_field_wcpay_multi_currency_settings_page',
						'callback' => 'render_settings_container',
						'priority' => 10,
					),
				),
			),
			MultiCurrencySettingsProjectionService::get_hook_manifest()
		);
		$this->assertSame(
			'<div id="wcpay_multi_currency_settings_container" class="wc-settings-prevent-change-event" aria-describedby="wcpay_multi_currency_settings_container-description"></div>',
			MultiCurrencySettingsProjectionService::get_settings_container_markup()
		);
		$this->assertTrue(
			MultiCurrencySettingsProjectionService::is_multi_currency_settings_page(
				true,
				'wcpay_multi_currency',
				'woocommerce_page_wc-settings'
			)
		);
		$this->assertFalse(
			MultiCurrencySettingsProjectionService::is_multi_currency_settings_page(
				true,
				'checkout',
				'woocommerce_page_wc-settings'
			)
		);
		$this->assertFalse(
			MultiCurrencySettingsProjectionService::is_multi_currency_settings_page(
				false,
				'wcpay_multi_currency',
				'woocommerce_page_wc-settings'
			)
		);
	}

	/**
	 * @testdox Should project admin assets and JS config flag.
	 */
	public function test_projects_admin_assets_and_js_config_flag(): void {
		$asset_manifest = MultiCurrencySettingsProjectionService::get_admin_asset_manifest();

		$this->assertTrue( MultiCurrencySettingsProjectionService::should_enqueue_admin_assets( 'wcpay_multi_currency' ) );
		$this->assertFalse( MultiCurrencySettingsProjectionService::should_enqueue_admin_assets( 'checkout' ) );
		$this->assertSame(
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
			$asset_manifest
		);
		$this->assertSame(
			array(
				'foo'                    => 'bar',
				'isMultiCurrencyEnabled' => true,
			),
			MultiCurrencySettingsProjectionService::add_props_to_wcpay_js_config( array( 'foo' => 'bar' ) )
		);
	}
}
