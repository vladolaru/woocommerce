<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\ThemeAppearance;
use WP_Theme_JSON_Data;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPay appearance computed from the theme.
 */
class ThemeAppearanceTest extends WC_Unit_Test_Case {

	/**
	 * Theme styles merged into the theme's theme.json by a test.
	 *
	 * @var array<string,mixed>
	 */
	private array $theme_styles = array();

	/**
	 * Remove the theme.json filter.
	 */
	public function tearDown(): void {
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
	 * Registered stylesheet sources and the font rule sources WooPay gets from them.
	 *
	 * The host and URL rules are ThemeAppearance::get_allowed_font_url()'s, which AppearanceStoreTest covers case by case.
	 *
	 * @return array<string,array{0:array<int,string>,1:array<int,string>}>
	 */
	public function provider_font_sources(): array {
		$allowed = 'https://fonts.googleapis.com/css2?family=Inter';
		$twelve  = array_map( static fn( int $i ): string => 'https://fonts.googleapis.com/css2?family=Font' . $i, range( 0, 11 ) );

		return array(
			'an allowed https source'                    => array( array( $allowed ), array( $allowed ) ),
			'a host not on the allowlist'                => array( array( 'https://fonts.example.com/inter.css' ), array() ),
			'an allowed host over http'                  => array( array( 'http://fonts.googleapis.com/css2?family=Inter' ), array() ),
			'twelve allowed sources, the first ten kept' => array( $twelve, array_slice( $twelve, 0, 10 ) ),
		);
	}

	/**
	 * @testdox Only https stylesheets from the font host allowlist go to WooPay, at most ten: $_dataName.
	 *
	 * The test registers its stylesheets in an empty registry: WordPress's default styles include Google Fonts stylesheets.
	 *
	 * @dataProvider provider_font_sources
	 *
	 * @param array<int,string> $sources  Registered stylesheet URLs.
	 * @param array<int,string> $expected The font rule sources WooPay gets.
	 */
	public function test_font_rules_keep_only_https_allowlisted_hosts( array $sources, array $expected ): void {
		$original_styles                  = $GLOBALS['wp_styles'] ?? null;
		$GLOBALS['wp_styles']             = new \WP_Styles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Only this test's stylesheets are registered.
		$GLOBALS['wp_styles']->registered = array();
		try {
			foreach ( $sources as $i => $src ) {
				wp_register_style( 'test-font-' . $i, $src, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Font CDN URLs carry no version.
			}
			$kept = array_column( ( new ThemeAppearance() )->get_font_rules_from_registered_styles(), 'cssSrc' );
		} finally {
			$GLOBALS['wp_styles'] = $original_styles; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the shared registry.
		}

		$this->assertSame( $expected, $kept );
	}

	/**
	 * Page backgrounds of a theme with transparent inputs and the WooPay appearance each gets.
	 *
	 * @return array<string,array{0:array<string,string>,1:string,2:string,3:string|null}>
	 */
	public function provider_transparent_input_themes(): array {
		return array(
			'a dark theme gets the night theme and its page background in the input' => array(
				array(
					'background' => '#111111',
					'text'       => '#eeeeee',
				),
				'night',
				'#111111',
				'#111111',
			),
			'a light theme gets the stripe theme and a white input'                  => array( array( 'background' => '#fafafa' ), 'stripe', '#ffffff', null ),
		);
	}

	/**
	 * @testdox A theme with transparent inputs gets a WooPay theme and an input background for its page background: $_dataName.
	 *
	 * @dataProvider provider_transparent_input_themes
	 *
	 * @param array<string,string> $colors                    theme.json styles color.
	 * @param string               $expected_theme            The WooPay theme.
	 * @param string               $expected_input_background The input background color.
	 * @param string|null          $expected_page_background  The colorBackground variable, or null when not checked.
	 */
	public function test_theme_with_transparent_inputs( array $colors, string $expected_theme, string $expected_input_background, ?string $expected_page_background ): void {
		$rules = $this->compute_with_theme_styles(
			array(
				'color'    => $colors,
				'elements' => array(
					'textInput' => array( 'color' => array( 'background' => 'transparent' ) ),
				),
			)
		);

		$this->assertSame( $expected_theme, $rules['theme'] );
		if ( null !== $expected_page_background ) {
			$this->assertSame( $expected_page_background, $rules['variables']['colorBackground'] );
		}
		$this->assertSame( $expected_input_background, $rules['rules']['.Input']['backgroundColor'] );
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
	 * Compute the appearance with the given styles merged into the theme's theme.json.
	 *
	 * @param array<string,mixed> $styles theme.json styles.
	 * @return array<string,mixed>
	 */
	private function compute_with_theme_styles( array $styles ): array {
		$this->theme_styles = $styles;
		add_filter( 'wp_theme_json_data_theme', array( $this, 'merge_theme_styles' ) );
		wp_clean_theme_json_cache();

		return ( new ThemeAppearance() )->compute_from_theme();
	}
}
