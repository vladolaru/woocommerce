<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Settings\PaymentsProviders;

use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\PayPal;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\PaymentGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletRuntimeArbiter;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Payment_Gateway;
use WC_Unit_Test_Case;

/**
 * PayPal payment gateway provider service test.
 *
 * @class PayPal
 */
class PayPalTest extends WC_Unit_Test_Case {

	/**
	 * @var PayPal
	 */
	protected $sut;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new PayPal( wc_get_container()->get( LegacyProxy::class ) );
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		remove_all_filters( PayPalWalletRuntimeArbiter::FILTER_ENABLED );
		wc_get_container()->get( PayPalWalletRuntimeArbiter::class )->invalidate();

		parent::tearDown();
	}

	/**
	 * Build a fake gateway with the extension's ID.
	 *
	 * @return WC_Payment_Gateway
	 */
	private function fake_ppcp_gateway(): WC_Payment_Gateway {
		$gateway               = $this->getMockBuilder( WC_Payment_Gateway::class )->onlyMethods( array( 'get_method_title' ) )->getMock();
		$gateway->id           = 'ppcp-gateway';
		$gateway->method_title = 'PayPal';
		$gateway->method( 'get_method_title' )->willReturn( 'PayPal' );
		return $gateway;
	}

	/**
	 * @testdox Should title the row PayPal Wallet and blank the plugin file when native owns the site.
	 */
	public function test_native_owned_row_is_core_provided(): void {
		add_filter( PayPalWalletRuntimeArbiter::FILTER_ENABLED, '__return_true' );
		wc_get_container()->get( PayPalWalletRuntimeArbiter::class )->invalidate();

		$gateway = $this->fake_ppcp_gateway();

		$this->assertSame( 'PayPal Wallet', $this->sut->get_title( $gateway ) );
		$this->assertSame( '', $this->sut->get_plugin_details( $gateway )['file'], 'A core-provided row must have no deactivate action' );
	}

	/**
	 * @testdox Should leave title and plugin details untouched when native does not own the site.
	 */
	public function test_non_native_row_is_unchanged(): void {
		add_filter( PayPalWalletRuntimeArbiter::FILTER_ENABLED, '__return_false' );
		wc_get_container()->get( PayPalWalletRuntimeArbiter::class )->invalidate();

		$gateway = $this->fake_ppcp_gateway();

		// What the parent provider returns, bypassing the override.
		$parent_details = ( new PaymentGateway( wc_get_container()->get( LegacyProxy::class ) ) )->get_plugin_details( $gateway );

		$this->assertSame( 'PayPal', $this->sut->get_title( $gateway ) );
		$this->assertSame( $parent_details, $this->sut->get_plugin_details( $gateway ) );
	}
}
