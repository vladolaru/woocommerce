<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayThemeAppearance;
use WP_Theme_JSON_Data;
use WP_UnitTestCase;

/**
 * Tests for the WooPay appearance computed from the theme.
 */
class WooPaymentsWooPayThemeAppearanceTest extends WP_UnitTestCase {

	/**
	 * Style handles registered by a test.
	 *
	 * @var string[]
	 */
	private array $style_handles = array();

	/**
	 * Theme styles merged into the theme's theme.json by a test.
	 *
	 * @var array<string,mixed>
	 */
	private array $theme_styles = array();

	/**
	 * Remove the registered styles and the theme.json filter.
	 */
	public function tearDown(): void {
		foreach ( $this->style_handles as $handle ) {
			wp_deregister_style( $handle );
		}
		remove_filter( 'wp_theme_json_data_theme', array( $this, 'merge_theme_styles' ) );
		wp_clean_theme_json_cache();
		parent::tearDown();
	}

	/**
	 * Merge the test's styles into the theme's theme.json data.
	 *
	 * @param WP_Theme_JSON_Data $theme_json Theme data.
	 * @return WP_Theme_JSON_Data
	 */
	public function merge_theme_styles( $theme_json ) {
		return $theme_json->update_with(
			array(
				'version'  => 2,
				'settings' => array(
					'color' => array(
						'palette' => array(
							array(
								'slug'  => 'contrast',
								'color' => '#123456',
								'name'  => 'Contrast',
							),
						),
					),
				),
				'styles'   => $this->theme_styles,
			)
		);
	}

	/**
	 * Font stylesheet sources and whether WooPay may load them.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function provider_font_sources(): array {
		return array(
			'allowed https host'         => array( 'https://fonts.googleapis.com/css2?family=Inter', true ),
			'another allowed host'       => array( 'https://fonts.bunny.net/css?family=inter', true ),
			'host not on the allowlist'  => array( 'https://fonts.example.com/inter.css', false ),
			'allowed host over http'     => array( 'http://fonts.googleapis.com/css2?family=Inter', false ),
			'allowlisted name as subdir' => array( 'https://evil.example.com/fonts.googleapis.com/inter.css', false ),
		);
	}

	/**
	 * @testdox Only https stylesheets from the font host allowlist go to WooPay.
	 *
	 * @dataProvider provider_font_sources
	 *
	 * @param string $src        Stylesheet URL.
	 * @param bool   $is_allowed Whether WooPay may load it.
	 */
	public function test_font_rules_keep_only_https_allowlisted_hosts( string $src, bool $is_allowed ): void {
		$this->register_style( 'test-font', $src );

		$sources = array_column( ( new WooPaymentsWooPayThemeAppearance() )->get_font_rules_from_registered_styles(), 'cssSrc' );

		$this->assertSame( $is_allowed, in_array( $src, $sources, true ) );
	}

	/**
	 * @testdox At most ten font stylesheets go to WooPay.
	 */
	public function test_font_rules_are_capped_at_ten(): void {
		for ( $i = 0; $i < 12; $i++ ) {
			$this->register_style( 'test-font-' . $i, 'https://fonts.googleapis.com/css2?family=Font' . $i );
		}

		$this->assertCount( 10, ( new WooPaymentsWooPayThemeAppearance() )->get_font_rules_from_registered_styles() );
	}

	/**
	 * @testdox A dark theme gets the night theme and its page background in a transparent input.
	 */
	public function test_dark_theme_with_transparent_inputs(): void {
		$rules = $this->compute_with_theme_styles(
			array(
				'color'    => array(
					'background' => '#111111',
					'text'       => '#eeeeee',
				),
				'elements' => array(
					'textInput' => array( 'color' => array( 'background' => 'transparent' ) ),
				),
			)
		);

		$this->assertSame( 'night', $rules['theme'] );
		$this->assertSame( '#111111', $rules['variables']['colorBackground'] );
		$this->assertSame( '#111111', $rules['rules']['.Input']['backgroundColor'] );
	}

	/**
	 * @testdox A light theme gets the stripe theme and a white transparent input.
	 */
	public function test_light_theme_with_transparent_inputs(): void {
		$rules = $this->compute_with_theme_styles(
			array(
				'color'    => array( 'background' => '#fafafa' ),
				'elements' => array(
					'textInput' => array( 'color' => array( 'background' => 'transparent' ) ),
				),
			)
		);

		$this->assertSame( 'stripe', $rules['theme'] );
		$this->assertSame( '#ffffff', $rules['rules']['.Input']['backgroundColor'] );
	}

	/**
	 * @testdox Preset colors, an oklch input background and a currentColor link resolve to fixed values.
	 */
	public function test_preset_oklch_and_current_color_resolve(): void {
		$rules = $this->compute_with_theme_styles(
			array(
				'color'    => array(
					'background' => '#ffffff',
					'text'       => 'var:preset|color|contrast',
				),
				'elements' => array(
					'link'      => array( 'color' => array( 'text' => 'currentColor' ) ),
					'textInput' => array( 'color' => array( 'background' => 'oklch(from #336699 l c h)' ) ),
				),
			)
		);

		$this->assertSame( '#123456', strtolower( $rules['variables']['colorText'] ) );
		// currentColor would re-resolve inside WooPay; it is pinned to the text color.
		$this->assertSame( '#123456', strtolower( $rules['rules']['.Link']['color'] ) );
		$this->assertSame( '#336699', strtolower( $rules['rules']['.Input']['backgroundColor'] ) );
	}

	/**
	 * Register a stylesheet for one test.
	 *
	 * @param string $handle Style handle.
	 * @param string $src    Stylesheet URL.
	 */
	private function register_style( string $handle, string $src ): void {
		wp_register_style( $handle, $src, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Font CDN URLs carry no version.
		$this->style_handles[] = $handle;
	}

	/**
	 * Compute the appearance with the given styles merged into the theme's theme.json.
	 *
	 * @param array<string,mixed> $styles theme.json styles.
	 * @return array<string,mixed>
	 */
	private function compute_with_theme_styles( array $styles ): array {
		$this->theme_styles = $styles;
		add_filter( 'wp_theme_json_data_theme', array( $this, 'merge_theme_styles' ) );
		wp_clean_theme_json_cache();

		return ( new WooPaymentsWooPayThemeAppearance() )->compute_from_theme();
	}
}
