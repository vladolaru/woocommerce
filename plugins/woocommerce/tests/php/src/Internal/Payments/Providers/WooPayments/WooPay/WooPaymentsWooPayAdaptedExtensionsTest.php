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
	 * @testdox Should track the affiliate conversion carried on a WooPay order request without forcing its eligibility: $_dataName.
	 *
	 * Affiliate for WooCommerce applies its own rules, its self-referral setting included, unless the call forces
	 * is_affiliate_eligible (AFWC 9.17.0 includes/class-afwc-api.php:609-616); client 11.1.0 forces it
	 * (includes/woopay/class-woopay-adapted-extensions.php:205).
	 *
	 * @dataProvider provide_affiliate_parameters
	 *
	 * @param string $parameter    The request's affiliate parameter.
	 * @param int    $affiliate_id The affiliate ID the conversion names.
	 */
	public function test_update_order_extension_data_tracks_affiliate_conversion( string $parameter, int $affiliate_id ): void {
		$this->install_affiliate_for_woocommerce_double();
		$_GET['affiliate'] = $parameter;

		( new WooPaymentsWooPayAdaptedExtensions() )->update_order_extension_data( 1001 );

		$this->assertSame(
			array(
				array(
					'order_id'     => 1001,
					'affiliate_id' => $affiliate_id,
					'type'         => '',
					'params'       => array(),
				),
			),
			FakeWooPayAffiliateApi::$conversions
		);
	}

	/**
	 * Affiliate parameters and the affiliate ID each must pass.
	 *
	 * @return array<string,array{string,int}>
	 */
	public function provide_affiliate_parameters(): array {
		return array(
			'does not force eligibility' => array( '42', 42 ),
			'still passes the affiliate' => array( ' 7 ', 7 ),
		);
	}

	/**
	 * @testdox Should not track a conversion when the request carries no affiliate.
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
	 *
	 * The adapter asks LegacyProxy whether the extension's constant is defined, and the mock is reset after every test.
	 * The helper function and API class stay defined for later tests, but nothing reaches them without that constant.
	 */
	private function install_affiliate_for_woocommerce_double(): void {
		$this->register_legacy_proxy_function_mocks(
			array(
				'defined' => static fn( $constant_name ) => 'AFWC_PLUGIN_FILE' === $constant_name || defined( $constant_name ),
			)
		);
		if ( ! function_exists( 'afwc_get_referrer_id' ) ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Extension function double; inert unless a test reports the extension's constant as defined.
			eval( 'function afwc_get_referrer_id() { return 42; }' );
		}
		if ( ! class_exists( 'AFWC_API', false ) ) {
			class_alias( FakeWooPayAffiliateApi::class, 'AFWC_API' );
		}
	}
}
