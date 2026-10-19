<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Admin;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\WooPaymentsAdminNoticesPassthrough;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyAdminNoticesController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\WooPaymentsCurrencyComplianceNotice;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverStateStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
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
	 * @testdox Should keep WooPayments' own admin notices, but not the multi-currency ones, on a WooPayments route of the Payments tab.
	 *
	 * @testWith ["/woopayments/settings"]
	 *           ["/woopayments/settings/fraud-protection"]
	 *           ["/woopayments/overview"]
	 *           ["/woopayments/transactions/details"]
	 *
	 * @param string $path The native route path.
	 */
	public function test_keeps_woopayments_notices_on_woopayments_routes( string $path ): void {
		$foreign_notice = static function () {};
		add_action( 'admin_notices', $foreign_notice );
		$this->open_payments_tab( $path );

		do_action( 'in_admin_header' );

		$this->assertSame( 9999, has_action( 'admin_notices', array( $this->currency_notice, 'display_not_supported_currency_notice' ) ), 'The unsupported currency notice should keep its priority.' );
		$this->assertSame( 10, has_action( 'admin_notices', array( $this->currency_notice, 'display_isk_decimal_notice' ) ), 'The ISK decimal notice should be kept.' );
		$this->assertFalse( has_action( 'admin_notices', array( $this->multi_currency_notices, 'handle_admin_notices' ) ), 'The multi-currency notices should follow core\'s stripping (owner decision N-285).' );
		$this->assertSame( 10, has_action( 'admin_notices', array( $this->cutover_notices, 'output_admin_notices' ) ), 'The cutover notices should be kept.' );
		$this->assertFalse( has_action( 'admin_notices', $foreign_notice ), 'Other plugins\' notices should still be stripped.' );
	}

	/**
	 * @testdox Should leave core's stripping in place on Payments tab routes that are not WooPayments pages.
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
	 * A store that ran the extension and deactivated it by hand has no WooPayments row on the Payments tab; the switch notice
	 * is its one offer there (N-506 (a), N-507). The passthrough comes from the setup tier's admin class list, as in the
	 * bootstrap, so the row fails if the waiting store stops loading it or the passthrough stops keeping the notice.
	 *
	 * @testdox On the Payments tab itself only the switch notice is kept, and only on a store waiting for its switch ($_dataName).
	 * @testWith ["a store waiting for its switch", true]
	 *           ["a store that never ran the extension", false]
	 *
	 * @param string $label         Case label.
	 * @param bool   $extension_ran Whether the extension's version option is stored.
	 */
	public function test_keeps_the_switch_notice_on_the_payments_tab_only_while_the_store_waits( string $label, bool $extension_ran ): void {
		unset( $label );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::ACTIVE );
		update_option( WooPaymentsCutoverStateStore::OPTION_NAME, WooPaymentsCutoverStateStore::ABSENT_RECORD );
		if ( $extension_ran ) {
			update_option( 'woocommerce_woocommerce_payments_version', '11.1.0' );
		} else {
			delete_option( 'woocommerce_woocommerce_payments_version' );
		}
		$arbiter = wc_get_container()->get( WooPaymentsRuntimeArbiter::class );
		$tier    = wc_get_container()->get( WooPaymentsSetupTier::class );
		$arbiter->invalidate();
		$tier->invalidate();
		// Only the passthrough the admin class list loads takes part.
		remove_action( 'in_admin_header', array( $this->sut, 'keep_woopayments_notices' ), PHP_INT_MAX - 1 );
		$loaded = null;
		if ( in_array( WooPaymentsAdminNoticesPassthrough::class, $tier->get_classes_for_request( 'admin' ), true ) ) {
			$loaded = wc_get_container()->get( WooPaymentsAdminNoticesPassthrough::class );
			$loaded->register();
		}
		$this->open_payments_tab( '' );

		try {
			do_action( 'in_admin_header' );
		} finally {
			if ( null !== $loaded ) {
				remove_action( 'in_admin_header', array( $loaded, 'keep_woopayments_notices' ), PHP_INT_MAX - 1 );
			}
			$arbiter->invalidate();
			$tier->invalidate();
		}

		$this->assertSame( $extension_ran ? 10 : false, has_action( 'admin_notices', array( $this->cutover_notices, 'output_admin_notices' ) ), 'The switch notice.' );
		$this->assertFalse( has_action( 'admin_notices', array( $this->currency_notice, 'display_isk_decimal_notice' ) ), 'Only the switch notice is kept on the Payments tab.' );
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
