<?php
/**
 * WooPaymentsWooPayAppearanceStore class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendStylesService;

/**
 * Stores the WooPay checkout appearance and font rules that a merchant or a shopper page sends, after checking them
 * against the allowed rules, properties and font hosts, and stamps them with the frontend styles cache version so a
 * theme or style change discards them. On a block theme with nothing stored for the current version, it computes the
 * appearance from the theme's global styles (WooPaymentsWooPayThemeAppearance) and stores it.
 *
 * Client 11.1.0 keeps the stored appearance in `WC_Payments_Styles_Cache` (includes/class-wc-payments-styles-cache.php:69-88)
 * and checks the writes in `WooPay_Session` (includes/woopay/class-woopay-session.php:1209, :1262).
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsWooPayAppearanceStore {

	private const APPEARANCE_OPTION = 'wcpay_woopay_checkout_appearance';

	/**
	 * Shared frontend styles service, whose cache version stamps the stored appearance.
	 *
	 * @var WooPaymentsFrontendStylesService
	 */
	private WooPaymentsFrontendStylesService $frontend_styles_service;

	/**
	 * Block-theme WooPay appearance calculator.
	 *
	 * @var WooPaymentsWooPayThemeAppearance|null
	 */
	private ?WooPaymentsWooPayThemeAppearance $theme_appearance = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsFrontendStylesService $frontend_styles_service Shared frontend styles service.
	 */
	final public function init( WooPaymentsFrontendStylesService $frontend_styles_service ): void {
		$this->frontend_styles_service = $frontend_styles_service;
	}

	/**
	 * Save WooPay appearance data.
	 *
	 * @param array<string,mixed>             $appearance Appearance data.
	 * @param array<int,array<string,string>> $font_rules Font rules.
	 */
	public function save_woopay_appearance( array $appearance, array $font_rules = array() ): void {
		$appearance = $this->sanitize_array_recursive( $appearance );
		if ( ! $this->validate_appearance_schema( $appearance ) ) {
			return;
		}

		update_option(
			self::APPEARANCE_OPTION,
			array(
				'appearance' => $appearance,
				'font_rules' => $this->sanitize_woopay_font_rules( $font_rules ),
				'version'    => $this->get_appearance_version(),
			),
			false
		);
	}

	/**
	 * Get WooPay appearance data.
	 *
	 * On a block theme with no appearance stored for the current styles version, the appearance is computed from the
	 * theme's global styles and stored with its font rules, as client 11.1.0 `WC_Payments_Styles_Cache::get_woopay_appearance()`
	 * does (class-wc-payments-styles-cache.php:69-88).
	 *
	 * @return array<string,mixed>
	 */
	public function get_woopay_appearance(): array {
		$appearance = $this->get_stored_woopay_appearance();
		if ( array() !== $appearance || ! wp_is_block_theme() ) {
			return $appearance;
		}

		// save_woopay_appearance() validates the computed appearance and stores nothing when it fails the schema.
		$theme_appearance = $this->get_theme_appearance();
		$this->save_woopay_appearance( $theme_appearance->compute_from_theme(), $theme_appearance->get_font_rules_from_registered_styles() );

		return $this->get_stored_woopay_appearance();
	}

	/**
	 * Get the WooPay appearance to send to the browser or WooPay, or null when there is none.
	 *
	 * Client 11.1.0 sends null then (class-wc-payments-styles-cache.php:87). An empty array prints as `[]`, which JS reads as
	 * an appearance, so the classic checkout would skip its DOM fallback (woopayments-checkout.js getWooPayEmailInputAppearance()).
	 *
	 * @return array<string,mixed>|null
	 */
	public function get_woopay_appearance_or_null(): ?array {
		$appearance = $this->get_woopay_appearance();

		return array() === $appearance ? null : $appearance;
	}

	/**
	 * Get the WooPay appearance stored for the current styles version.
	 *
	 * @return array<string,mixed>
	 */
	private function get_stored_woopay_appearance(): array {
		$stored = get_option( self::APPEARANCE_OPTION, array() );

		if ( isset( $stored['appearance'] ) && is_array( $stored['appearance'] ) ) {
			return $this->has_current_appearance_version( $stored ) ? $stored['appearance'] : array();
		}

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Get the block-theme WooPay appearance calculator.
	 *
	 * @return WooPaymentsWooPayThemeAppearance
	 */
	private function get_theme_appearance(): WooPaymentsWooPayThemeAppearance {
		if ( null === $this->theme_appearance ) {
			$this->theme_appearance = wc_get_container()->get( WooPaymentsWooPayThemeAppearance::class );
		}

		return $this->theme_appearance;
	}

	/**
	 * Get WooPay font rules.
	 *
	 * @return array<int,array<string,string>>
	 */
	public function get_woopay_font_rules(): array {
		$stored = get_option( self::APPEARANCE_OPTION, array() );

		return isset( $stored['font_rules'] ) && is_array( $stored['font_rules'] ) && $this->has_current_appearance_version( $stored )
			? $this->sanitize_woopay_font_rules( $stored['font_rules'] )
			: array();
	}

	/**
	 * Conditionally save WooPay shopper appearance data.
	 *
	 * @param array<string,mixed>             $appearance Appearance data.
	 * @param array<int,array<string,string>> $font_rules Font rules.
	 * @return bool
	 */
	public function maybe_save_woopay_appearance( array $appearance, array $font_rules = array() ): bool {
		$appearance = $this->sanitize_array_recursive( $appearance );
		if ( ! $this->validate_appearance_schema( $appearance ) || array() !== $this->get_woopay_appearance() ) {
			return false;
		}

		$this->save_woopay_appearance( $appearance, $font_rules );

		return true;
	}

	/**
	 * Validate WooPay appearance data against the preserved schema.
	 *
	 * @param array<string,mixed> $appearance Appearance data.
	 * @return bool
	 */
	public function validate_appearance_schema( array $appearance ): bool {
		$allowed_top_keys = array( 'variables', 'theme', 'labels', 'rules' );
		foreach ( array_keys( $appearance ) as $key ) {
			if ( ! in_array( $key, $allowed_top_keys, true ) ) {
				return false;
			}
		}

		if ( isset( $appearance['theme'] ) && ! in_array( $appearance['theme'], array( 'stripe', 'night' ), true ) ) {
			return false;
		}

		if ( isset( $appearance['labels'] ) && ! in_array( $appearance['labels'], array( 'floating', 'above' ), true ) ) {
			return false;
		}

		if ( isset( $appearance['variables'] ) ) {
			if ( ! is_array( $appearance['variables'] ) ) {
				return false;
			}

			$allowed_variables = array( 'colorBackground', 'colorText', 'fontFamily', 'fontSizeBase' );
			foreach ( array_keys( $appearance['variables'] ) as $key ) {
				if ( ! in_array( $key, $allowed_variables, true ) ) {
					return false;
				}
			}

			if ( ! $this->validate_string_values( $appearance['variables'] ) ) {
				return false;
			}
		}

		if ( isset( $appearance['rules'] ) ) {
			if ( ! is_array( $appearance['rules'] ) ) {
				return false;
			}

			$allowed_rules      = $this->get_allowed_appearance_rules();
			$allowed_properties = $this->get_allowed_appearance_properties();
			foreach ( $appearance['rules'] as $rule_key => $rule_value ) {
				if ( ! in_array( $rule_key, $allowed_rules, true ) || ! is_array( $rule_value ) ) {
					return false;
				}

				foreach ( array_keys( $rule_value ) as $property ) {
					if ( ! in_array( $property, $allowed_properties, true ) ) {
						return false;
					}
				}

				if ( ! $this->validate_string_values( $rule_value ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Sanitize WooPay appearance font rules.
	 *
	 * @param array<int,mixed> $font_rules Font rules.
	 * @return array<int,array<string,string>>
	 */
	public function sanitize_woopay_font_rules( array $font_rules ): array {
		$sanitized = array();
		foreach ( array_slice( $font_rules, 0, 10 ) as $rule ) {
			if ( ! is_array( $rule ) || ! isset( $rule['cssSrc'] ) || ! is_string( $rule['cssSrc'] ) ) {
				continue;
			}

			$url  = esc_url_raw( $rule['cssSrc'], array( 'https' ) );
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( is_string( $host ) && in_array( $host, $this->get_allowed_font_domains(), true ) ) {
				$sanitized[] = array( 'cssSrc' => $url );
			}
		}

		return $sanitized;
	}

	/**
	 * Recursively sanitize appearance data.
	 *
	 * @param array<string,mixed> $data Appearance data.
	 * @return array<string,mixed>
	 */
	public function sanitize_array_recursive( array $data ): array {
		$sanitized = array();

		foreach ( $data as $key => $value ) {
			$sanitized[ sanitize_text_field( (string) $key ) ] = is_array( $value )
				? $this->sanitize_array_recursive( $value )
				: sanitize_text_field( (string) $value );
		}

		return $sanitized;
	}

	/**
	 * Validate that all appearance values are short strings.
	 *
	 * @param array<mixed> $values Values to validate.
	 * @return bool
	 */
	private function validate_string_values( array $values ): bool {
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || strlen( $value ) > 200 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get the current WooPay appearance version.
	 *
	 * @return string
	 */
	private function get_appearance_version(): string {
		return $this->frontend_styles_service->get_styles_cache_version();
	}

	/**
	 * Check whether stored WooPay appearance data matches the active styles cache.
	 *
	 * @param array<mixed> $stored Stored appearance option.
	 * @return bool
	 */
	private function has_current_appearance_version( array $stored ): bool {
		return isset( $stored['version'] ) && is_scalar( $stored['version'] ) && (string) $stored['version'] === $this->get_appearance_version();
	}

	/**
	 * Get allowed WooPay appearance rule selectors.
	 *
	 * @return array<int,string>
	 */
	private function get_allowed_appearance_rules(): array {
		return array(
			'.Input',
			'.Input--invalid',
			'.Label',
			'.Label--resting',
			'.Label--floating',
			'.Text',
			'.Text--redirect',
			'.Block',
			'.Tab',
			'.Tab:hover',
			'.Tab--selected',
			'.TabIcon',
			'.TabIcon:hover',
			'.TabIcon--selected',
			'.TabLabel',
			'.Heading',
			'.Header',
			'.Footer',
			'.Footer-link',
			'.Footer--link',
			'.Button',
			'.Link',
			'.Container',
		);
	}

	/**
	 * Get allowed WooPay appearance CSS properties.
	 *
	 * @return array<int,string>
	 */
	private function get_allowed_appearance_properties(): array {
		return array(
			'color',
			'backgroundColor',
			'fontFamily',
			'fontSize',
			'fontWeight',
			'fontVariation',
			'lineHeight',
			'letterSpacing',
			'padding',
			'paddingTop',
			'paddingRight',
			'paddingBottom',
			'paddingLeft',
			'border',
			'borderTop',
			'borderRight',
			'borderBottom',
			'borderLeft',
			'borderColor',
			'borderStyle',
			'borderWidth',
			'borderTopColor',
			'borderTopStyle',
			'borderTopWidth',
			'borderRightColor',
			'borderRightStyle',
			'borderRightWidth',
			'borderBottomColor',
			'borderBottomStyle',
			'borderBottomWidth',
			'borderLeftColor',
			'borderLeftStyle',
			'borderLeftWidth',
			'borderRadius',
			'borderTopLeftRadius',
			'borderTopRightRadius',
			'borderBottomRightRadius',
			'borderBottomLeftRadius',
			'outline',
			'outlineColor',
			'outlineWidth',
			'outlineStyle',
			'outlineOffset',
			'boxShadow',
			'textDecoration',
			'textShadow',
			'textTransform',
			'transition',
			'transform',
			'-webkit-font-smoothing',
			'-moz-osx-font-smoothing',
		);
	}

	/**
	 * Get allowed WooPay appearance font domains.
	 *
	 * @return array<int,string>
	 */
	private function get_allowed_font_domains(): array {
		return array(
			'fonts.googleapis.com',
			'fonts.gstatic.com',
			'use.typekit.net',
			'fonts.bunny.net',
			'fonts.wp.com',
		);
	}
}
