<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendAssets;
use WC_Unit_Test_Case;

/**
 * Tests for the registrar of the script handles the WooPayments shopper scripts share.
 */
class WooPaymentsFrontendAssetsTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsFrontendAssets
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new WooPaymentsFrontendAssets();
	}

	/**
	 * Deregister the shared script handles after each test.
	 */
	public function tearDown(): void {
		wp_deregister_script( WooPaymentsFrontendAssets::STRIPE_SCRIPT_HANDLE );
		wp_deregister_script( WooPaymentsFrontendAssets::APPEARANCE_SCRIPT_HANDLE );
		wp_deregister_script( WooPaymentsFrontendAssets::FINGERPRINT_SCRIPT_HANDLE );
		wp_deregister_script( WooPaymentsFrontendAssets::COMMON_SCRIPT_HANDLE );
		parent::tearDown();
	}

	/**
	 * @testdox Should register the $handle handle with its own source, and keep a registration made before it.
	 * @testWith ["register_stripe_script", "stripe", "https://js.stripe.com/v3/"]
	 *           ["register_appearance_script", "wc-woopayments-appearance", "/assets/js/frontend/utils/woopayments-appearance"]
	 *           ["register_fingerprint_script", "wc-woopayments-fingerprintjs", "/assets/js/fingerprintjs/fp.umd.min.js"]
	 *           ["register_common_script", "wc-woopayments-common", "/assets/client/blocks/wc-woopayments-common.js"]
	 *
	 * @param string $method Registration method.
	 * @param string $handle Script handle it registers.
	 * @param string $source Part of the script source it registers.
	 */
	public function test_registers_each_shared_handle_unless_already_registered( string $method, string $handle, string $source ): void {
		wp_deregister_script( $handle );

		$this->sut->$method();

		$this->assertTrue( wp_script_is( $handle, 'registered' ) );
		$this->assertStringContainsString( $source, wp_scripts()->registered[ $handle ]->src );

		wp_deregister_script( $handle );
		wp_register_script( $handle, 'https://example.com/registered-first.js', array(), '1.0', true );

		$this->sut->$method();

		$this->assertSame( 'https://example.com/registered-first.js', wp_scripts()->registered[ $handle ]->src, 'Another plugin registered the handle first.' );
	}

	/**
	 * @testdox Should register the shared shopper helpers without wp-polyfill, so classic pages gain no polyfill request.
	 */
	public function test_registers_the_common_helpers_without_dependencies(): void {
		$this->sut->register_common_script();

		$this->assertSame( array(), wp_scripts()->registered[ WooPaymentsFrontendAssets::COMMON_SCRIPT_HANDLE ]->deps );
	}
}
