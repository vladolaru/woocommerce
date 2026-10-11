<?php
/**
 * WooPaymentsWooPayThemeAppearance class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay;

use WP_Block_Patterns_Registry;

/**
 * Computes the WooPay checkout appearance from a block theme's global styles.
 *
 * On block themes the store can describe its look to WooPay before any shopper or merchant page has measured it in the
 * browser. Ported from client 11.1.0 `WC_Payments_Styles_Cache::compute_woopay_appearance_from_theme()` and its helpers
 * (class-wc-payments-styles-cache.php:153-338, :525-1219).
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsWooPayThemeAppearance {

	/**
	 * Font CDN hosts whose stylesheets WooPay may load.
	 *
	 * @var string[]
	 */
	private const ALLOWED_FONT_DOMAINS = array(
		'fonts.googleapis.com',
		'fonts.gstatic.com',
		'use.typekit.net',
		'fonts.bunny.net',
		'fonts.wp.com',
	);

	/**
	 * Compute the WooPay appearance from `theme.json` global styles.
	 *
	 * @return array<string,mixed> The appearance object (variables, theme, labels, rules).
	 */
	public function compute_from_theme(): array {
		$styles    = $this->get_global_styles( array(), array( 'transforms' => array( 'resolve-variables' ) ) );
		$tp_styles = $this->get_global_styles(
			array(),
			array(
				'block_name' => 'core/template-part',
				'transforms' => array( 'resolve-variables' ),
			)
		);

		$bg_color   = $this->resolve_style_value( $this->get( $styles, 'color.background', '#ffffff' ), '#ffffff', $styles );
		$text_color = $this->resolve_style_value( $this->get( $styles, 'color.text', '#000000' ), '#000000', $styles );
		$link_color = $this->resolve_style_value(
			$this->get( $styles, 'elements.link.color.text', $this->get( $styles, 'elements.a.color.text', $text_color ) ),
			$text_color,
			$styles
		);

		// `currentColor` is relative and would re-resolve wrong inside WooPay; pin it now.
		$link_color = $this->resolve_current_color( $link_color, $text_color );

		$font_family = $this->resolve_style_value( $this->get( $styles, 'typography.fontFamily', 'inherit' ), 'inherit', $styles );
		$font_size   = $this->resolve_style_value( $this->get( $styles, 'typography.fontSize', '16px' ), '16px', $styles );

		$heading_color       = $this->resolve_style_value( $this->get( $styles, 'elements.heading.color.text', $text_color ), $text_color, $styles );
		$heading_font_family = $this->resolve_style_value( $this->get( $styles, 'elements.heading.typography.fontFamily', $font_family ), $font_family, $styles );

		$button_bg_color    = $this->resolve_style_value( $this->get( $styles, 'elements.button.color.background', $bg_color ), $bg_color, $styles );
		$button_text_color  = $this->resolve_style_value( $this->get( $styles, 'elements.button.color.text', $text_color ), $text_color, $styles );
		$button_font_size   = $this->resolve_style_value( $this->get( $styles, 'elements.button.typography.fontSize', $font_size ), $font_size, $styles );
		$button_font_family = $this->resolve_style_value( $this->get( $styles, 'elements.button.typography.fontFamily', $font_family ), $font_family, $styles );

		// theme.json names the text input element `textInput`; some themes use `input`.
		$input_el = $this->get( $styles, 'elements.textInput', $this->get( $styles, 'elements.input', array() ) );
		$input_el = is_array( $input_el ) ? $input_el : array();

		// Most block themes leave input backgrounds transparent, which shows the page background inside WooPay; fall back to
		// settings.custom.input-background, then white on light themes or the page background on dark ones.
		$custom_settings   = function_exists( 'wp_get_global_settings' ) ? wp_get_global_settings( array( 'custom' ) ) : array();
		$custom_settings   = is_array( $custom_settings ) ? $custom_settings : array();
		$input_bg_default  = $this->is_color_light( $bg_color ) ? '#ffffff' : $bg_color;
		$input_bg_raw      = $this->get( $input_el, 'color.background', $custom_settings['input-background'] ?? $input_bg_default );
		$input_bg_resolved = $this->resolve_vars_in_expression( $this->resolve_style_value( $input_bg_raw, $input_bg_default, $styles ) );

		// Only well-formed hex and rgb()/rgba() land verbatim; oklch() expressions are evaluated.
		if ( preg_match( '/^(#[0-9a-f]{3}([0-9a-f]{3})?([0-9a-f]{2})?|rgba?\([\d\s.,%\/]+\))$/i', $input_bg_resolved ) ) {
			$input_bg_color = $input_bg_resolved;
		} else {
			$input_bg_color = $this->resolve_oklch( $input_bg_resolved ) ?? $input_bg_default;
		}
		$input_text_color    = $this->resolve_style_value( $this->get( $input_el, 'color.text', $text_color ), $text_color, $styles );
		$input_border_color  = $this->resolve_style_value( $this->get( $input_el, 'border.color', $text_color ), $text_color, $styles );
		$input_border_radius = $this->resolve_style_value( $this->get( $input_el, 'border.radius', '0px' ), '0px', $styles );

		$checkout_colors   = $this->get_checkout_section_colors();
		$header_colors     = $checkout_colors['header'] ?? array();
		$footer_colors     = $checkout_colors['footer'] ?? array();
		$header_bg_color   = $header_colors['background'] ?? $this->resolve_style_value( $this->get( $tp_styles, 'color.background', $bg_color ), $bg_color, $tp_styles );
		$header_text_color = $header_colors['text'] ?? $this->resolve_style_value( $this->get( $tp_styles, 'color.text', $text_color ), $text_color, $tp_styles );

		$error_color = '#df1b41';

		return array(
			'variables' => array(
				'colorBackground' => $bg_color,
				'colorText'       => $text_color,
				'fontFamily'      => $font_family,
				'fontSizeBase'    => $font_size,
			),
			'theme'     => $this->is_color_light( $bg_color ) ? 'stripe' : 'night',
			'labels'    => 'floating',
			'rules'     => array(
				'.Input'          => array(
					'color'             => $input_text_color,
					'fontFamily'        => $font_family,
					'fontSize'          => $font_size,
					'borderColor'       => $input_border_color,
					'borderBottomColor' => $input_border_color,
					'borderRadius'      => $input_border_radius,
					'backgroundColor'   => $input_bg_color,
				),
				'.Input--invalid' => array(
					'borderBottomColor' => $error_color,
				),
				'.Label'          => array(
					'color'      => $text_color,
					'fontFamily' => $font_family,
					'fontSize'   => $font_size,
				),
				'.Text'           => array(
					'color'      => $text_color,
					'fontFamily' => $font_family,
					'fontSize'   => $font_size,
				),
				'.Heading'        => array(
					'color'      => $heading_color,
					'fontFamily' => $heading_font_family,
				),
				'.Header'         => array(
					'backgroundColor' => $header_bg_color,
					'color'           => $header_text_color,
				),
				'.Footer'         => array(
					'backgroundColor' => $footer_colors['background'] ?? $bg_color,
					'color'           => $footer_colors['text'] ?? $text_color,
				),
				'.Footer-link'    => array(
					'color' => $this->resolve_current_color( $footer_colors['text'] ?? $link_color, $text_color ),
				),
				'.Button'         => array(
					'color'           => $button_text_color,
					'backgroundColor' => $button_bg_color,
					'fontFamily'      => $button_font_family,
					'fontSize'        => $button_font_size,
				),
				'.Link'           => array(
					'color'      => $link_color,
					'fontFamily' => $font_family,
				),
				'.Tab'            => array(
					'color'           => $text_color,
					'backgroundColor' => $bg_color,
					'fontFamily'      => $font_family,
				),
				'.Block'          => array(
					'backgroundColor' => $bg_color,
				),
			),
		);
	}

	/**
	 * Get the font CDN stylesheets among the registered styles, for WooPay to load.
	 *
	 * @return array<int,array<string,string>> Up to ten font rules, each with a `cssSrc` key.
	 */
	public function get_font_rules_from_registered_styles(): array {
		$font_rules = array();
		foreach ( wp_styles()->registered as $style ) {
			if ( empty( $style->src ) || ! is_string( $style->src ) ) {
				continue;
			}
			$url  = esc_url_raw( $style->src, array( 'https' ) );
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( is_string( $host ) && in_array( $host, self::ALLOWED_FONT_DOMAINS, true ) ) {
				$font_rules[] = array( 'cssSrc' => $url );
			}
		}

		return array_slice( $font_rules, 0, 10 );
	}

	/**
	 * Read global styles as an array.
	 *
	 * @param array<int,string>   $path    Styles path.
	 * @param array<string,mixed> $context Styles context.
	 * @return array<string,mixed>
	 */
	private function get_global_styles( array $path, array $context ): array {
		$styles = wp_get_global_styles( $path, $context );

		return is_array( $styles ) ? $styles : array();
	}

	/**
	 * Read a dotted path from an array.
	 *
	 * @param array<mixed> $source        Source array.
	 * @param string       $path          Dotted path.
	 * @param mixed        $default_value Value when the path is missing or null.
	 * @return mixed
	 */
	private function get( array $source, string $path, $default_value ) {
		$value = _wp_array_get( $source, explode( '.', $path ), null );

		return null === $value ? $default_value : $value;
	}

	/**
	 * Tell whether a hex color is light (brightness over 125, the tinycolor formula). Unparseable colors count as light.
	 *
	 * @param string $color Hex color.
	 * @return bool
	 */
	private function is_color_light( string $color ): bool {
		$rgb = $this->parse_color( $color );
		if ( null === $rgb ) {
			return true;
		}

		return ( $rgb[0] * 299 + $rgb[1] * 587 + $rgb[2] * 114 ) / 1000 > 125;
	}

	/**
	 * Parse a #rgb or #rrggbb color into 0-255 channels.
	 *
	 * @param string $color Hex color.
	 * @return array{0:int,1:int,2:int}|null
	 */
	private function parse_color( string $color ): ?array {
		$color = ltrim( $color, '#' );
		if ( 3 === strlen( $color ) ) {
			$color = $color[0] . $color[0] . $color[1] . $color[1] . $color[2] . $color[2];
		}
		if ( 6 !== strlen( $color ) || ! ctype_xdigit( $color ) ) {
			return null;
		}

		return array(
			(int) hexdec( substr( $color, 0, 2 ) ),
			(int) hexdec( substr( $color, 2, 2 ) ),
			(int) hexdec( substr( $color, 4, 2 ) ),
		);
	}

	/**
	 * Resolve a theme style value to a string, following `{ "ref": "styles.x.y" }` references in the styles context.
	 *
	 * @param mixed               $value          Style value.
	 * @param string              $default_value  Value when it cannot be resolved to a string.
	 * @param array<string,mixed> $styles_context Resolved styles subtree.
	 * @return string
	 */
	private function resolve_style_value( $value, string $default_value, array $styles_context = array() ): string {
		if ( is_array( $value ) && isset( $value['ref'] ) && is_string( $value['ref'] ) ) {
			$path = explode( '.', $value['ref'] );
			if ( 'styles' === $path[0] ) {
				array_shift( $path );
			}
			$value = _wp_array_get( $styles_context, $path );
		}

		return is_string( $value ) ? $value : $default_value;
	}

	/**
	 * Replace the CSS `currentColor` keyword with the theme text color (black if that is itself `currentColor`).
	 *
	 * @param string $color    Color value.
	 * @param string $fallback Theme text color.
	 * @return string
	 */
	private function resolve_current_color( string $color, string $fallback ): string {
		if ( 0 !== strcasecmp( trim( $color ), 'currentcolor' ) ) {
			return $color;
		}

		return 0 === strcasecmp( trim( $fallback ), 'currentcolor' ) ? '#000000' : $fallback;
	}

	/**
	 * Replace every `var(--wp--preset--*)` token inside a CSS expression with its preset value.
	 *
	 * @param string $value CSS expression.
	 * @return string
	 */
	private function resolve_vars_in_expression( string $value ): string {
		return (string) preg_replace_callback(
			'/var\(\s*--[^)]+\)/',
			fn( array $matches ): string => $this->resolve_css_var( $matches[0] ),
			$value
		);
	}

	/**
	 * Resolve a `var(--wp--preset--*)` value from the global settings presets; other values pass through.
	 *
	 * @param string $value CSS value.
	 * @return string
	 */
	private function resolve_css_var( string $value ): string {
		if ( 0 !== strpos( $value, 'var(' ) || ! preg_match( '/var\(\s*(--[^,)]+)/', $value, $matches ) ) {
			return $value;
		}

		$preset_map = array(
			'--wp--preset--font-family--' => 'typography.fontFamilies',
			'--wp--preset--font-size--'   => 'typography.fontSizes',
			'--wp--preset--color--'       => 'color.palette',
		);

		foreach ( $preset_map as $prefix => $settings_path ) {
			if ( 0 !== strpos( $matches[1], $prefix ) ) {
				continue;
			}

			$settings = wp_get_global_settings( explode( '.', $settings_path ) );
			if ( is_array( $settings ) ) {
				$resolved = $this->find_preset_value( $settings, substr( $matches[1], strlen( $prefix ) ) );
				if ( null !== $resolved ) {
					return $resolved;
				}
			}
		}

		return $value;
	}

	/**
	 * Find a preset's value by slug in flat or origin-keyed (default, theme, custom) preset lists.
	 *
	 * @param array<mixed> $settings Presets.
	 * @param string       $slug     Preset slug.
	 * @return string|null
	 */
	private function find_preset_value( array $settings, string $slug ): ?string {
		foreach ( $settings as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			if ( isset( $entry['slug'] ) && $entry['slug'] === $slug ) {
				$value = $entry['fontFamily'] ?? $entry['size'] ?? $entry['color'] ?? null;

				return is_string( $value ) ? $value : null;
			}

			if ( ! isset( $entry['slug'] ) ) {
				$nested = $this->find_preset_value( $entry, $slug );
				if ( null !== $nested ) {
					return $nested;
				}
			}
		}

		return null;
	}

	/**
	 * Evaluate a CSS relative color `oklch(from <hex> <L> <C> <H>)` to #rrggbb, where each channel is l, c, h, a number
	 * or calc() with one multiplication.
	 *
	 * @param string $value CSS value.
	 * @return string|null
	 */
	private function resolve_oklch( string $value ): ?string {
		$expr = '(calc\([^)]+\)|\S+)';
		if ( ! preg_match( '/^oklch\(\s*from\s+(\S+)\s+' . $expr . '\s+' . $expr . '\s+' . $expr . '\s*\)$/', $value, $m ) ) {
			return null;
		}

		$rgb = $this->parse_color( $m[1] );
		if ( null === $rgb ) {
			return null;
		}

		// sRGB to linear sRGB.
		$lin = array_map(
			static function ( int $v ): float {
				$v /= 255.0;
				return ( $v <= 0.04045 ) ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 );
			},
			$rgb
		);

		// Linear sRGB to OKLab, with a sign-preserving cube root.
		$cbrt = static fn( float $v ): float => ( $v < 0 ) ? -pow( -$v, 1.0 / 3.0 ) : pow( $v, 1.0 / 3.0 );
		$l_   = $cbrt( 0.4122214708 * $lin[0] + 0.5363325363 * $lin[1] + 0.0514459929 * $lin[2] );
		$m_   = $cbrt( 0.2119034982 * $lin[0] + 0.6806995451 * $lin[1] + 0.1073969566 * $lin[2] );
		$s_   = $cbrt( 0.0883024619 * $lin[0] + 0.2817188376 * $lin[1] + 0.6299787005 * $lin[2] );

		$ok_l = 0.2104542553 * $l_ + 0.7936177850 * $m_ - 0.0040720468 * $s_;
		$ok_a = 1.9779984951 * $l_ - 2.4285922050 * $m_ + 0.4505937099 * $s_;
		$ok_b = 0.0259040371 * $l_ + 0.7827717662 * $m_ - 0.8086757660 * $s_;

		// OKLab to OKLch.
		$lch_c = sqrt( $ok_a * $ok_a + $ok_b * $ok_b );
		$lch_h = rad2deg( atan2( $ok_b, $ok_a ) );
		if ( $lch_h < 0 ) {
			$lch_h += 360;
		}

		$new_l = $this->evaluate_channel_expr( $m[2], $ok_l, $lch_c, $lch_h );
		$new_c = $this->evaluate_channel_expr( $m[3], $ok_l, $lch_c, $lch_h );
		$new_h = $this->evaluate_channel_expr( $m[4], $ok_l, $lch_c, $lch_h );
		if ( null === $new_l || null === $new_c || null === $new_h ) {
			return null;
		}

		$new_l = max( 0, min( 1, $new_l ) );
		$new_c = max( 0, min( 0.5, $new_c ) );

		// OKLch to OKLab to linear sRGB.
		$ok_a2 = $new_c * cos( deg2rad( $new_h ) );
		$ok_b2 = $new_c * sin( deg2rad( $new_h ) );
		$l_    = ( $new_l + 0.3963377774 * $ok_a2 + 0.2158037573 * $ok_b2 ) ** 3;
		$m_    = ( $new_l - 0.1055613458 * $ok_a2 - 0.0638541728 * $ok_b2 ) ** 3;
		$s_    = ( $new_l - 0.0894841775 * $ok_a2 - 1.2914855480 * $ok_b2 ) ** 3;

		$to_srgb = static function ( float $v ): int {
			$v = max( 0, min( 1, $v ) );
			$v = ( $v <= 0.0031308 ) ? $v * 12.92 : 1.055 * pow( $v, 1.0 / 2.4 ) - 0.055;
			return (int) round( $v * 255 );
		};

		return sprintf(
			'#%02x%02x%02x',
			$to_srgb( 4.0767416621 * $l_ - 3.3077115913 * $m_ + 0.2309699292 * $s_ ),
			$to_srgb( -1.2684380046 * $l_ + 2.6097574011 * $m_ - 0.3413193965 * $s_ ),
			$to_srgb( -0.0041960863 * $l_ - 0.7034186147 * $m_ + 1.7076147010 * $s_ )
		);
	}

	/**
	 * Evaluate one OKLch channel expression: l, c, h, a number, or calc() multiplying a channel by a number.
	 *
	 * @param string $expr  Channel expression.
	 * @param float  $lch_l L channel.
	 * @param float  $lch_c C channel.
	 * @param float  $lch_h H channel.
	 * @return float|null
	 */
	private function evaluate_channel_expr( string $expr, float $lch_l, float $lch_c, float $lch_h ): ?float {
		$expr     = trim( $expr );
		$channels = array(
			'l' => $lch_l,
			'c' => $lch_c,
			'h' => $lch_h,
		);

		if ( isset( $channels[ $expr ] ) ) {
			return $channels[ $expr ];
		}

		if ( is_numeric( $expr ) ) {
			return (float) $expr;
		}

		if ( preg_match( '/^calc\(\s*([a-z]+)\s*\*\s*([\d.]+)\s*\)$/', $expr, $cm ) ) {
			return isset( $channels[ $cm[1] ] ) ? $channels[ $cm[1] ] * (float) $cm[2] : null;
		}

		if ( preg_match( '/^calc\(\s*([\d.]+)\s*\*\s*([a-z]+)\s*\)$/', $expr, $cm ) ) {
			return isset( $channels[ $cm[2] ] ) ? $channels[ $cm[2] ] * (float) $cm[1] : null;
		}

		return null;
	}

	/**
	 * Get the header and footer colors of the checkout page template.
	 *
	 * A section is a `core/template-part` with area header or footer, an inline block whose metadata categories say header
	 * or footer, or a block with that tagName; the outermost match per area wins.
	 *
	 * @return array<string,array<string,string>> Map of 'header'|'footer' to `background` and `text` colors.
	 */
	private function get_checkout_section_colors(): array {
		$template = get_block_template( get_stylesheet() . '//page-checkout' ) ?? get_block_template( 'woocommerce//page-checkout' );
		if ( ! $template || empty( $template->content ) ) {
			return array();
		}

		$blocks   = $this->flatten_blocks( $this->resolve_pattern_blocks( parse_blocks( $template->content ) ) );
		$sections = array();
		foreach ( $blocks as $block ) {
			$block_name = $block['blockName'] ?? '';
			if ( empty( $block_name ) ) {
				continue;
			}

			$area = $this->classify_block_area( $block );
			if ( null === $area || isset( $sections[ $area ] ) ) {
				continue;
			}

			$colors = 'core/template-part' === $block_name && ! empty( $block['attrs']['slug'] )
				? $this->get_template_part_colors( (string) $block['attrs']['slug'], (string) ( $block['attrs']['theme'] ?? '' ) )
				: $this->extract_block_colors( $block );

			if ( ! empty( $colors ) ) {
				$sections[ $area ] = $colors;
			}
		}

		return $sections;
	}

	/**
	 * Tell whether a block is a header or footer section.
	 *
	 * @param array<string,mixed> $block Parsed block.
	 * @return string|null 'header', 'footer' or null.
	 */
	private function classify_block_area( array $block ): ?string {
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();

		if ( 'core/template-part' === ( $block['blockName'] ?? '' ) && ! empty( $attrs['slug'] ) ) {
			$area = $attrs['area'] ?? null;
			if ( ! $area ) {
				$part = get_block_template( get_stylesheet() . '//' . $attrs['slug'], 'wp_template_part' );
				if ( ! $part && ! empty( $attrs['theme'] ) ) {
					$part = get_block_template( $attrs['theme'] . '//' . $attrs['slug'], 'wp_template_part' );
				}
				$area = $part ? $part->area : null;
			}

			return in_array( $area, array( 'header', 'footer' ), true ) ? $area : null;
		}

		$categories = $attrs['metadata']['categories'] ?? array();
		$categories = is_array( $categories ) ? $categories : array();
		if ( in_array( 'footer', $categories, true ) ) {
			return 'footer';
		}
		if ( in_array( 'header', $categories, true ) ) {
			return 'header';
		}

		$tag = $attrs['tagName'] ?? '';

		return in_array( $tag, array( 'header', 'footer' ), true ) ? $tag : null;
	}

	/**
	 * Get a template part's colors from its primary block.
	 *
	 * @param string $slug  Template part slug.
	 * @param string $theme Theme the template-part block names.
	 * @return array<string,string>
	 */
	private function get_template_part_colors( string $slug, string $theme = '' ): array {
		$template = get_block_template( get_stylesheet() . '//' . $slug, 'wp_template_part' );
		if ( ( ! $template || empty( $template->content ) ) && '' !== $theme ) {
			$template = get_block_template( $theme . '//' . $slug, 'wp_template_part' );
		}
		if ( ! $template || empty( $template->content ) ) {
			return array();
		}

		$target = $this->find_primary_block( $this->resolve_pattern_blocks( parse_blocks( $template->content ) ) );

		return null === $target ? array() : $this->extract_block_colors( $target );
	}

	/**
	 * Find a template part's primary block: the first top-level block holding navigation, the site title or logo, else the
	 * last top-level group, else the first block.
	 *
	 * @param array<int|string,array<string,mixed>> $blocks Parsed blocks.
	 * @return array<string,mixed>|null
	 */
	private function find_primary_block( array $blocks ): ?array {
		$nav_markers = array( 'core/navigation', 'core/site-title', 'core/site-logo' );
		$last_group  = null;
		$first_block = null;

		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}

			$first_block = $first_block ?? $block;
			if ( 'core/group' === $block['blockName'] ) {
				$last_group = $block;
			}

			if ( $this->block_contains_any( $block, $nav_markers ) ) {
				return $block;
			}
		}

		return $last_group ?? $first_block;
	}

	/**
	 * Tell whether a block or any block inside it has one of the given names.
	 *
	 * @param array<string,mixed> $block       Parsed block.
	 * @param string[]            $block_names Block names.
	 * @return bool
	 */
	private function block_contains_any( array $block, array $block_names ): bool {
		if ( in_array( $block['blockName'] ?? null, $block_names, true ) ) {
			return true;
		}

		foreach ( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array() as $inner ) {
			if ( is_array( $inner ) && $this->block_contains_any( $inner, $block_names ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Replace `core/pattern` references with the registered pattern's blocks.
	 *
	 * @param array<int|string,array<string,mixed>> $blocks Parsed blocks.
	 * @return array<int,array<string,mixed>>
	 */
	private function resolve_pattern_blocks( array $blocks ): array {
		$registry = WP_Block_Patterns_Registry::get_instance();
		$resolved = array();

		foreach ( $blocks as $block ) {
			$slug = $block['attrs']['slug'] ?? '';
			if ( 'core/pattern' !== ( $block['blockName'] ?? '' ) || empty( $slug ) ) {
				if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
					$block['innerBlocks'] = $this->resolve_pattern_blocks( $block['innerBlocks'] );
				}
				$resolved[] = $block;
				continue;
			}

			if ( ! $registry->is_registered( $slug ) ) {
				$resolved[] = $block;
				continue;
			}

			$pattern = $registry->get_registered( $slug );
			if ( ! empty( $pattern['content'] ) ) {
				foreach ( parse_blocks( $pattern['content'] ) as $pattern_block ) {
					$resolved[] = $pattern_block;
				}
			}
		}

		return $resolved;
	}

	/**
	 * Flatten a block tree, parents before their children.
	 *
	 * @param array<int|string,array<string,mixed>> $blocks Parsed blocks.
	 * @return array<int,array<string,mixed>>
	 */
	private function flatten_blocks( array $blocks ): array {
		$flat = array();
		foreach ( $blocks as $block ) {
			$flat[] = $block;
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$flat = array_merge( $flat, $this->flatten_blocks( $block['innerBlocks'] ) );
			}
		}

		return $flat;
	}

	/**
	 * Get a block's background and text colors from its preset and inline color attributes and its style variation.
	 *
	 * @param array<string,mixed> $block Parsed block.
	 * @return array<string,string>
	 */
	private function extract_block_colors( array $block ): array {
		$attrs  = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$colors = array();

		foreach ( array(
			'background' => 'backgroundColor',
			'text'       => 'textColor',
		) as $key => $preset_attr ) {
			if ( ! empty( $attrs[ $preset_attr ] ) && is_string( $attrs[ $preset_attr ] ) ) {
				$reference = 'var(--wp--preset--color--' . $attrs[ $preset_attr ] . ')';
				$resolved  = $this->resolve_css_var( $reference );
				if ( $reference !== $resolved ) {
					$colors[ $key ] = $resolved;
				}
			}

			$inline = $attrs['style']['color'][ $key ] ?? null;
			if ( ! empty( $inline ) && is_string( $inline ) ) {
				$colors[ $key ] = $this->resolve_css_var( $inline );
			}
		}

		// A block style variation (e.g. is-style-section-1) fills the colors not set inline.
		if ( ! empty( $attrs['className'] ) && is_string( $attrs['className'] ) && ! empty( $block['blockName'] ) ) {
			$colors = array_merge( $this->get_style_variation_colors( (string) $block['blockName'], $attrs['className'] ), $colors );
		}

		return $colors;
	}

	/**
	 * Get the colors of the first block style variation named by a class that defines any.
	 *
	 * @param string $block_name Block name.
	 * @param string $class_name The block's className attribute.
	 * @return array<string,string>
	 */
	private function get_style_variation_colors( string $block_name, string $class_name ): array {
		if ( ! function_exists( 'wp_get_block_style_variation_name_from_class' ) ) {
			return array();
		}

		$variation_names = wp_get_block_style_variation_name_from_class( $class_name );
		foreach ( is_array( $variation_names ) ? $variation_names : array() as $variation ) {
			$variation_color = wp_get_global_styles( array( 'variations', $variation, 'color' ), array( 'block_name' => $block_name ) );
			if ( ! is_array( $variation_color ) ) {
				continue;
			}

			$colors = array();
			foreach ( array( 'background', 'text' ) as $key ) {
				if ( ! empty( $variation_color[ $key ] ) && is_string( $variation_color[ $key ] ) ) {
					$colors[ $key ] = $this->resolve_css_var( $variation_color[ $key ] );
				}
			}

			if ( ! empty( $colors ) ) {
				return $colors;
			}
		}

		return array();
	}
}
