<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Settings\PaymentsProviders\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsAdminNoticesPassthrough;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyAdminNoticesController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCurrencyComplianceNotice;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverController;
use WC_Settings_Payment_Gateways;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsAdminNoticesPassthrough class.
 */
class WooPaymentsAdminNoticesPassthroughTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsAdminNoticesPassthrough
	 */
	private $sut;

	/**
	 * Currency compliance notice callback owner.
	 *
	 * @var WooPaymentsCurrencyComplianceNotice
	 */
	private $currency_notice;

	/**
	 * Multi-currency notices callback owner.
	 *
	 * @var MultiCurrencyAdminNoticesController
	 */
	private $multi_currency_notices;

	/**
	 * Cutover notices callback owner (switch in progress, reconnect, completed).
	 *
	 * @var WooPaymentsCutoverController
	 */
	private $cutover_notices;

	/**
	 * Request query before the test.
	 *
	 * @var array<string,mixed>
	 */
	private array $original_get = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WC_ABSPATH . 'includes/admin/settings/class-wc-settings-payment-gateways.php';

		$this->original_get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// Core's Payments tab strips non-core notices on `in_admin_header`.
		new WC_Settings_Payment_Gateways();

		$this->currency_notice        = new WooPaymentsCurrencyComplianceNotice();
		$this->multi_currency_notices = new MultiCurrencyAdminNoticesController();
		add_action( 'admin_notices', array( $this->currency_notice, 'display_not_supported_currency_notice' ), 9999 );
		add_action( 'admin_notices', array( $this->currency_notice, 'display_isk_decimal_notice' ) );
		add_action( 'admin_notices', array( $this->multi_currency_notices, 'handle_admin_notices' ) );
		$this->cutover_notices = new WooPaymentsCutoverController();
		add_action( 'admin_notices', array( $this->cutover_notices, 'output_admin_notices' ) );

		$this->sut = new WooPaymentsAdminNoticesPassthrough();
		$this->sut->register();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		global $current_tab, $current_section;

		try {
			$_GET            = $this->original_get;
			$current_tab     = null;
			$current_section = null;
			set_current_screen( 'front' );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Should keep WooPayments' own admin notices on a native WooPayments route of the Payments tab.
	 *
	 * @testWith ["/woopayments/settings"]
	 *           ["/woopayments/settings/fraud-protection"]
	 *           ["/woopayments/overview"]
	 *           ["/woopayments/transactions/details"]
	 *
	 * @param string $path The native route path.
	 */
	public function test_keeps_woopayments_notices_on_native_routes( string $path ): void {
		$foreign_notice = static function () {};
		add_action( 'admin_notices', $foreign_notice );
		$this->open_payments_tab( $path );

		do_action( 'in_admin_header' );

		$this->assertSame( 9999, has_action( 'admin_notices', array( $this->currency_notice, 'display_not_supported_currency_notice' ) ), 'The unsupported currency notice should keep its priority.' );
		$this->assertSame( 10, has_action( 'admin_notices', array( $this->currency_notice, 'display_isk_decimal_notice' ) ), 'The ISK decimal notice should be kept.' );
		$this->assertSame( 10, has_action( 'admin_notices', array( $this->multi_currency_notices, 'handle_admin_notices' ) ), 'The multi-currency notices should be kept.' );
		$this->assertSame( 10, has_action( 'admin_notices', array( $this->cutover_notices, 'output_admin_notices' ) ), 'The cutover notices should be kept.' );
		$this->assertFalse( has_action( 'admin_notices', $foreign_notice ), 'Other plugins\' notices should still be stripped.' );
	}

	/**
	 * @testdox Should leave core's stripping in place on Payments tab routes that are not native WooPayments pages.
	 *
	 * @testWith [""]
	 *           ["/offline"]
	 *           ["/woopayments/onboarding"]
	 *
	 * @param string $path The route path.
	 */
	public function test_leaves_notices_stripped_on_other_payments_routes( string $path ): void {
		$this->open_payments_tab( $path );

		do_action( 'in_admin_header' );

		$this->assertFalse( has_action( 'admin_notices', array( $this->currency_notice, 'display_not_supported_currency_notice' ) ), 'The unsupported currency notice should be stripped.' );
		$this->assertFalse( has_action( 'admin_notices', array( $this->currency_notice, 'display_isk_decimal_notice' ) ), 'The ISK decimal notice should be stripped.' );
		$this->assertFalse( has_action( 'admin_notices', array( $this->multi_currency_notices, 'handle_admin_notices' ) ), 'The multi-currency notices should be stripped.' );
		$this->assertFalse( has_action( 'admin_notices', array( $this->cutover_notices, 'output_admin_notices' ) ), 'The cutover notices should be stripped.' );
	}

	/**
	 * Open a Payments settings tab route.
	 *
	 * @param string $path The route path; empty for the Payments tab itself.
	 */
	private function open_payments_tab( string $path ): void {
		global $current_tab, $current_section;

		$_GET = array(
			'page' => 'wc-settings',
			'tab'  => 'checkout',
		);
		if ( '' !== $path ) {
			$_GET['path'] = $path;
		}
		$current_tab     = 'checkout';
		$current_section = '';
		set_current_screen( 'woocommerce_page_wc-settings' );
	}
}
