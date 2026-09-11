<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayAdaptedExtensions;
use WC_Unit_Test_Case;

/**
 * Tests for WooPaymentsWooPayAdaptedExtensions.
 */
class WooPaymentsWooPayAdaptedExtensionsTest extends WC_Unit_Test_Case {

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		unset( $_GET['affiliate'] );
		FakeWooPayAffiliateApi::$conversions = array();
		parent::tearDown();
	}

	/**
	 * @testdox Should track the affiliate conversion carried on a WooPay order request.
	 *
	 * Defines the extension's constant and helper function, so it runs in its own process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_update_order_extension_data_tracks_affiliate_conversion(): void {
		$this->install_affiliate_for_woocommerce_double();
		$_GET['affiliate'] = '42';

		( new WooPaymentsWooPayAdaptedExtensions() )->update_order_extension_data( 1001 );

		$this->assertSame(
			array(
				array(
					'order_id'     => 1001,
					'affiliate_id' => 42,
					'used_coupon'  => '',
					'params'       => array( 'is_affiliate_eligible' => true ),
				),
			),
			FakeWooPayAffiliateApi::$conversions
		);
	}

	/**
	 * @testdox Should not track a conversion when the request carries no affiliate.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_update_order_extension_data_ignores_requests_without_affiliate(): void {
		$this->install_affiliate_for_woocommerce_double();

		( new WooPaymentsWooPayAdaptedExtensions() )->update_order_extension_data( 1001 );

		$this->assertSame( array(), FakeWooPayAffiliateApi::$conversions );
	}

	/**
	 * @testdox Should not track a conversion when Affiliate for WooCommerce is not active.
	 */
	public function test_update_order_extension_data_ignores_affiliate_without_extension(): void {
		$_GET['affiliate'] = '42';

		( new WooPaymentsWooPayAdaptedExtensions() )->update_order_extension_data( 1001 );

		$this->assertSame( array(), FakeWooPayAffiliateApi::$conversions );
	}

	/**
	 * Install the Affiliate for WooCommerce surface the adapter probes.
	 */
	private function install_affiliate_for_woocommerce_double(): void {
		if ( ! defined( 'AFWC_PLUGIN_FILE' ) ) {
			define( 'AFWC_PLUGIN_FILE', __FILE__ );
		}
		if ( ! function_exists( 'afwc_get_referrer_id' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Extension function double, confined to a separate test process.
			eval( 'function afwc_get_referrer_id() { return 42; }' );
		}
		if ( ! class_exists( 'AFWC_API', false ) ) {
			class_alias( FakeWooPayAffiliateApi::class, 'AFWC_API' );
		}
	}
}
