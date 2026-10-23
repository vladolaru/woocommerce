<?php
/**
 * Tests for the registration of the wallet's settings page assets.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\PartnerAttribution;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\ScriptDataHandler;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use WP_Scripts;
use WP_Styles;

/**
 * The settings app is a lazy chunk of the admin client's Payments settings route; the page gets its data and stylesheet
 * from the `ppcp-admin-settings` handles, which register the admin client's `paypal-wallet-settings` build.
 *
 * @group paypal-wallet
 */
class ScriptDataHandlerTest extends WalletTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var ScriptDataHandler
	 */
	private $sut;

	/**
	 * The script and style registries before the test, restored in tearDown.
	 *
	 * @var array{scripts: mixed, styles: mixed}
	 */
	private $saved_registries;

	/**
	 * Give the test empty script and style registries and create the System Under Test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->saved_registries = array(
			'scripts' => $GLOBALS['wp_scripts'] ?? null,
			'styles'  => $GLOBALS['wp_styles'] ?? null,
		);
		$GLOBALS['wp_scripts']  = new WP_Scripts(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A registry no other test has filled; tearDown restores the original.
		$GLOBALS['wp_styles']   = new WP_Styles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- As above.

		$this->sut = new ScriptDataHandler(
			false,
			'US',
			'PARTNERMERCHANTID',
			array( 'en_US' => 'English (United States)' ),
			$this->mock( PartnerAttribution::class ),
			$this->mock( SettingsProvider::class )
		);
	}

	/**
	 * Put the script and style registries back.
	 */
	public function tearDown(): void {
		try {
			foreach ( array( 'scripts', 'styles' ) as $type ) {
				if ( null === $this->saved_registries[ $type ] ) {
					unset( $GLOBALS[ "wp_$type" ] );
				} else {
					$GLOBALS[ "wp_$type" ] = $this->saved_registries[ $type ]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the registry.
				}
			}
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Should register the settings script from the admin client's paypal-wallet-settings build.
	 */
	public function test_registers_the_settings_script_from_the_admin_build(): void {
		$this->sut->localize_scripts( 'woocommerce_page_wc-settings' );

		$script = wp_scripts()->query( 'ppcp-admin-settings', 'registered' );
		$this->assertNotFalse( $script, 'The settings script must be registered' );
		$this->assertStringEndsWith( 'assets/client/admin/wp-admin-scripts/paypal-wallet-settings.js', (string) $script->src );
		$this->assertTrue( wp_script_is( 'ppcp-admin-settings', 'enqueued' ) );
	}

	/**
	 * @testdox Should register the settings stylesheet from the same build under the same handle.
	 */
	public function test_registers_the_settings_stylesheet_from_the_admin_build(): void {
		$this->sut->localize_scripts( 'woocommerce_page_wc-settings' );

		$style = wp_styles()->query( 'ppcp-admin-settings', 'registered' );
		$this->assertNotFalse( $style, 'The settings stylesheet must be registered' );
		$this->assertStringEndsWith( 'assets/client/admin/paypal-wallet-settings/style.css', (string) $style->src );
		$this->assertSame( 'replace', wp_styles()->get_data( 'ppcp-admin-settings', 'rtl' ), 'Right-to-left pages must get style-rtl.css' );
		$this->assertTrue( wp_style_is( 'ppcp-admin-settings', 'enqueued' ) );
	}

	/**
	 * @testdox Should take the settings script's dependencies from the asset file webpack wrote next to it.
	 */
	public function test_takes_the_script_dependencies_from_the_asset_file(): void {
		$asset_path = WC_ADMIN_ABSPATH . WC_ADMIN_DIST_JS_FOLDER . 'wp-admin-scripts/paypal-wallet-settings.asset.php';
		if ( ! is_readable( $asset_path ) ) {
			$this->markTestSkipped( 'The admin client is not built: no paypal-wallet-settings.asset.php to read.' );
		}
		$asset = require $asset_path;

		$this->sut->localize_scripts( 'woocommerce_page_wc-settings' );

		$script = wp_scripts()->query( 'ppcp-admin-settings', 'registered' );
		$this->assertNotFalse( $script, 'The settings script must be registered' );
		$this->assertContains( 'wp-blob', $script->deps, 'Only the asset file lists wp-blob; the fallback lists nothing' );
		$this->assertSame( $asset['dependencies'], $script->deps );
	}

	/**
	 * @testdox Should localize ppcpSettings onto the settings script, with the images the admin build copies.
	 */
	public function test_localizes_the_settings_data_onto_the_settings_script(): void {
		$this->sut->localize_scripts( 'woocommerce_page_wc-settings' );

		$data = (string) wp_scripts()->get_data( 'ppcp-admin-settings', 'data' );
		$this->assertStringStartsWith( 'var ppcpSettings = ', $data );
		$settings = json_decode( substr( $data, strlen( 'var ppcpSettings = ' ), -1 ), true );
		$this->assertIsArray( $settings, 'ppcpSettings must be a JSON object' );
		$this->assertStringEndsWith( 'assets/client/admin/paypal-wallet-settings/images/', $settings['assets']['imagesUrl'] );
		$this->assertSame( 'US', $settings['storeCountry'] );
	}

	/**
	 * @testdox Should register nothing outside the WooCommerce settings screen.
	 */
	public function test_registers_nothing_on_another_screen(): void {
		$this->sut->localize_scripts( 'dashboard' );

		$this->assertFalse( wp_script_is( 'ppcp-admin-settings', 'registered' ) );
		$this->assertFalse( wp_style_is( 'ppcp-admin-settings', 'registered' ) );
	}
}
