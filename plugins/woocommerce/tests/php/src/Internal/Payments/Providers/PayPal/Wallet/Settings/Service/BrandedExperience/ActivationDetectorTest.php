<?php
/**
 * Tests for the branded experience activation detector.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\BrandedExperience
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\BrandedExperience;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Enum\InstallationPathEnum;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\BrandedExperience\ActivationDetector;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The installation path of the wallet: the one WooCommerce attached when it suggested the extension, or direct.
 *
 * @group paypal-wallet
 */
class ActivationDetectorTest extends WalletTestCase {

	private const OPTION = 'woocommerce_paypal_branded';

	/**
	 * The System Under Test.
	 *
	 * @var ActivationDetector
	 */
	private $sut;

	/**
	 * Start with no attachment stored.
	 */
	public function setUp(): void {
		parent::setUp();

		delete_option( self::OPTION );
		$this->sut = new ActivationDetector();
	}

	/**
	 * @testdox Should report the direct path when WooCommerce attached no installation path.
	 */
	public function test_returns_direct_if_not_installed_via_woocommerce_paths(): void {
		$this->assertSame( InstallationPathEnum::DIRECT, $this->sut->detect_activation_path() );
	}

	/**
	 * @testdox Should report the core profiler path when WooCommerce attached the payments settings suggestion.
	 */
	public function test_returns_core_profiler_if_installed_via_woocommerce_path(): void {
		$this->set_wallet_option( self::OPTION, 'payments_settings' );

		$this->assertSame( InstallationPathEnum::CORE_PROFILER, $this->sut->detect_activation_path() );
	}

	/**
	 * @testdox Should report the direct path for an attachment it does not know.
	 */
	public function test_returns_direct_for_an_unknown_attachment(): void {
		$this->set_wallet_option( self::OPTION, 'something_else' );

		$this->assertSame( InstallationPathEnum::DIRECT, $this->sut->detect_activation_path() );
	}
}
