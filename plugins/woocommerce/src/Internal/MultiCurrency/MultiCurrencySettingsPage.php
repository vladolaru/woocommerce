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
 * The Multi-currency tab of the WooCommerce settings: a settings page holding the rows it is given, without the standard
 * save button when asked.
 *
 * @since 11.0.0
 * @internal
 */
class MultiCurrencySettingsPage extends \WC_Settings_Page {

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
	 * @param string                         $label            Settings tab label.
	 * @param bool                           $hide_save_button Whether the page hides the standard save button.
	 * @param array<int,array<string,mixed>> $settings         Settings rows.
	 */
	public function __construct( string $label, bool $hide_save_button, array $settings ) {
		$this->id               = MultiCurrencySettingsController::TAB_ID;
		$this->label            = $label;
		$this->hide_save_button = $hide_save_button;
		$this->settings         = $settings;

		parent::__construct();
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
