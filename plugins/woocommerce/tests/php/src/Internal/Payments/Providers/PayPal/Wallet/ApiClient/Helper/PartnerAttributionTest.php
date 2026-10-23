<?php
/**
 * Tests for the partner attribution helper.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\PartnerAttribution;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Enum\InstallationPathEnum;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The BN code that attributes a PayPal onboarding to the path the merchant installed through. It lives in an option, so
 * these cases write and read the real option (under a name of their own, deleted when the test ends). The class has no
 * filter of its own, so there is no hook to add or remove here; the option write is observed through the filter
 * WordPress runs before every update_option().
 *
 * @group paypal-wallet
 */
class PartnerAttributionTest extends WalletTestCase {

	private const OPTION_NAME     = 'test_ppcp_bn_code';
	private const DEFAULT_BN_CODE = 'Woo_PPCP';

	/**
	 * BN codes mapping.
	 *
	 * @var array
	 */
	private array $bn_codes = array(
		InstallationPathEnum::CORE_PROFILER    => 'WooPPCP_Ecom_PS_CoreProfiler',
		InstallationPathEnum::PAYMENT_SETTINGS => 'WooPPCP_Ecom_PS_CoreProfiler',
	);

	/**
	 * Start without the option.
	 */
	public function setUp(): void {
		parent::setUp();

		delete_option( self::OPTION_NAME );
	}

	/**
	 * Delete the option the code under test wrote.
	 */
	public function tearDown(): void {
		delete_option( self::OPTION_NAME );

		parent::tearDown();
	}

	/**
	 * The System Under Test over the mapping above.
	 *
	 * @return PartnerAttribution
	 */
	private function make_sut(): PartnerAttribution {
		return new PartnerAttribution( self::OPTION_NAME, $this->bn_codes, self::DEFAULT_BN_CODE );
	}

	/**
	 * Tests initializing BN code when it's not already set.
	 *
	 * @testdox Should store the BN code of the installation path when none is stored yet.
	 */
	public function test_initialize_bn_code_sets_bn_code_if_not_present(): void {
		$writes = $this->spy_filter( 'pre_update_option_' . self::OPTION_NAME );

		$this->make_sut()->initialize_bn_code( InstallationPathEnum::CORE_PROFILER );

		$this->assertCount( 1, $writes, 'The option should be written once' );
		$this->assertSame( 'WooPPCP_Ecom_PS_CoreProfiler', get_option( self::OPTION_NAME ) );
	}

	/**
	 * Tests that initialize_bn_code does nothing if the BN code is already set.
	 *
	 * @testdox Should leave a stored BN code alone.
	 */
	public function test_initialize_bn_code_does_not_update_if_already_set(): void {
		$this->set_wallet_option( self::OPTION_NAME, 'WooPPCP_Ecom_PS_CoreProfiler' );
		$writes = $this->spy_filter( 'pre_update_option_' . self::OPTION_NAME );

		$this->make_sut()->initialize_bn_code( InstallationPathEnum::CORE_PROFILER );

		$this->assertCount( 0, $writes, 'The option should not be written' );
		$this->assertSame( 'WooPPCP_Ecom_PS_CoreProfiler', get_option( self::OPTION_NAME ) );
	}

	/**
	 * @testdox Should do nothing for an installation path that has no BN code.
	 */
	public function test_initialize_bn_code_ignores_an_unknown_installation_path(): void {
		$writes = $this->spy_filter( 'pre_update_option_' . self::OPTION_NAME );

		$this->make_sut()->initialize_bn_code( 'unknown-path' );

		$this->assertCount( 0, $writes, 'The option should not be written' );
		$this->assertFalse( get_option( self::OPTION_NAME ) );
	}

	/**
	 * Tests retrieving the BN Code.
	 *
	 * @testdox Should return the BN code that is stored.
	 */
	public function test_get_bn_code_returns_persisted_value(): void {
		$this->set_wallet_option( self::OPTION_NAME, 'WooPPCP_Ecom_PS_CoreProfiler' );

		$this->assertSame( 'WooPPCP_Ecom_PS_CoreProfiler', $this->make_sut()->get_bn_code() );
	}

	/**
	 * @testdox Should return the default BN code when none is stored.
	 */
	public function test_get_bn_code_returns_default_when_not_set(): void {
		$this->assertSame( self::DEFAULT_BN_CODE, $this->make_sut()->get_bn_code() );
	}

	/**
	 * @testdox Should return the default BN code when the stored one is not in the mapping.
	 */
	public function test_get_bn_code_returns_default_for_a_stored_code_outside_the_mapping(): void {
		$this->set_wallet_option( self::OPTION_NAME, 'Some_Other_BN_Code' );

		$this->assertSame( self::DEFAULT_BN_CODE, $this->make_sut()->get_bn_code() );
	}
}
