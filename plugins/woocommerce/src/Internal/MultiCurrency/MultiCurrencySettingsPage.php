<?php
/**
 * MultiCurrencySettingsPage class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

// WC_Settings_Page loads only through WC_Admin_Settings.
if ( ! class_exists( '\WC_Settings_Page', false ) ) {
	require_once WC_ABSPATH . 'includes/admin/settings/class-wc-settings-page.php';
}

/**
 * WooCommerce settings page adapter for native multi-currency settings rows.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencySettingsPage extends \WC_Settings_Page {

	/**
	 * The Multi-currency settings tab id, the WooPayments extension's.
	 */
	public const TAB_ID = 'wcpay_multi_currency';

	private const SETTINGS_SCREEN_BASE = 'woocommerce_page_wc-settings';

	/**
	 * Whether this page should hide the standard settings save button.
	 *
	 * @var bool
	 */
	private bool $hide_save_button = false;

	/**
	 * Settings rows for this page.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $settings = array();

	/**
	 * Constructor.
	 *
	 * @param string                         $id               Settings tab id.
	 * @param string                         $label            Settings tab label.
	 * @param bool                           $hide_save_button Whether the page hides the standard save button.
	 * @param array<int,array<string,mixed>> $settings         Settings rows.
	 */
	public function __construct( string $id, string $label, bool $hide_save_button, array $settings ) {
		$this->id               = $id;
		$this->label            = $label;
		$this->hide_save_button = $hide_save_button;
		$this->settings         = $settings;

		parent::__construct();
	}

	/**
	 * Get the URL of the Multi-currency settings tab.
	 *
	 * @return string
	 */
	public static function get_settings_page_url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=' . self::TAB_ID );
	}

	/**
	 * Tell whether the given admin screen is the Multi-currency settings tab.
	 *
	 * @param bool        $is_admin    Whether the current request is admin.
	 * @param string|null $current_tab Current settings tab.
	 * @param string|null $screen_base Current screen base.
	 * @return bool
	 */
	public static function is_multi_currency_settings_page( bool $is_admin, ?string $current_tab, ?string $screen_base ): bool {
		return $is_admin
			&& self::TAB_ID === $current_tab
			&& self::SETTINGS_SCREEN_BASE === $screen_base;
	}

	/**
	 * Get settings array.
	 *
	 * @param string $current_section Section being shown.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_settings( $current_section = '' ) {
		unset( $current_section );

		if ( $this->hide_save_button ) {
			$GLOBALS['hide_save_button'] = true;
		}

		return $this->settings;
	}
}
