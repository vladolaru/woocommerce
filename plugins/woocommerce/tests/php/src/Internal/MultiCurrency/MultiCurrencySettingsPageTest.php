<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySettingsPage;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencySettingsPage class.
 */
class MultiCurrencySettingsPageTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Only an admin request on the Multi-currency tab of the WooCommerce settings screen is the settings page: $label.
	 * @testWith ["Multi-currency tab", true, "wcpay_multi_currency", "woocommerce_page_wc-settings", true]
	 *           ["another tab", true, "checkout", "woocommerce_page_wc-settings", false]
	 *           ["not an admin request", false, "wcpay_multi_currency", "woocommerce_page_wc-settings", false]
	 *
	 * @param string $label       Readable case name.
	 * @param bool   $is_admin    Whether the request is admin.
	 * @param string $current_tab The settings tab.
	 * @param string $screen_base The screen base.
	 * @param bool   $expected    Whether it is the settings page.
	 */
	public function test_tells_the_multi_currency_settings_page( string $label, bool $is_admin, string $current_tab, string $screen_base, bool $expected ): void {
		unset( $label );

		$this->assertSame( $expected, MultiCurrencySettingsPage::is_multi_currency_settings_page( $is_admin, $current_tab, $screen_base ) );
	}
}
