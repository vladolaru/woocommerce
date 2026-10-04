<?php
/**
 * Tests for the payment methods eligibility rules (ported from the extension's PaymentMethodsEligibilityServiceTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Applepay\ApplePayGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Axo\Gateway\AxoGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Googlepay\GooglePayGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\BancontactGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\BlikGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\EPSGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\IDealGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\MultibancoGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\MyBankGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\OXXOGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\P24Gateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\PayUponInvoice\PayUponInvoiceGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\PWCGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\TrustlyGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition\FeaturesDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\PaymentMethodsEligibilityService;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Which payment methods a merchant is eligible for, by country, capabilities and module availability.
 *
 * The Apple Pay, Google Pay, Fastlane and local payment method rows go with tasks 5, 6 and 8.
 *
 * @group paypal-wallet
 */
class PaymentMethodsEligibilityServiceTest extends WalletTestCase {

	/**
	 * @testdox Should offer the local payment methods in Mexico, and OXXO too (apm).
	 */
	public function test_apm_mexico(): void {
		$checks = $this->create_service( 'MX' )->get_eligibility_checks();

		$this->assertTrue( $checks[ OXXOGateway::ID ]() );
		$this->assertFalse( $checks[ PWCGateway::ID ]() );
		foreach ( $this->generic_apm_ids() as $id ) {
			$this->assertTrue( $checks[ $id ](), "$id must be eligible" );
		}
	}

	/**
	 * @testdox Should offer the local payment methods outside Mexico, without OXXO (apm).
	 */
	public function test_apm_no_mexico(): void {
		$checks = $this->create_service( 'US' )->get_eligibility_checks();

		$this->assertFalse( $checks[ OXXOGateway::ID ]() );
		$this->assertFalse( $checks[ PWCGateway::ID ]() );
		foreach ( $this->generic_apm_ids() as $id ) {
			$this->assertTrue( $checks[ $id ](), "$id must be eligible" );
		}
	}

	/**
	 * @testdox Should offer no local payment method when the merchant is not APM eligible (apm).
	 */
	public function test_apm_not_eligible(): void {
		$checks = $this->create_service( 'US', false )->get_eligibility_checks();

		$this->assertFalse( $checks[ OXXOGateway::ID ]() );
		$this->assertFalse( $checks[ PWCGateway::ID ]() );
		foreach ( $this->generic_apm_ids() as $id ) {
			$this->assertFalse( $checks[ $id ](), "$id must not be eligible" );
		}
	}

	/**
	 * @testdox Should keep Pay with Crypto off without the capability (apm).
	 */
	public function test_pwc_disabled(): void {
		$this->assertFalse( $this->create_service()->get_eligibility_checks()[ PWCGateway::ID ]() );
	}

	/**
	 * @testdox Should offer Pay with Crypto with the capability (apm).
	 */
	public function test_pwc_enabled(): void {
		$service = $this->create_service( 'US', true, true, true, true, array( FeaturesDefinition::FEATURE_PAY_WITH_CRYPTO => true ) );

		$this->assertTrue( $service->get_eligibility_checks()[ PWCGateway::ID ]() );
	}

	/**
	 * @testdox Should offer Pay upon Invoice in Germany (apm).
	 */
	public function test_pay_upon_invoice_germany(): void {
		$this->assertTrue( $this->create_service( 'DE' )->get_eligibility_checks()[ PayUponInvoiceGateway::ID ]() );
	}

	/**
	 * @testdox Should not offer Pay upon Invoice outside Germany (apm).
	 */
	public function test_pay_upon_invoice_not_germany(): void {
		$this->assertFalse( $this->create_service( 'US' )->get_eligibility_checks()[ PayUponInvoiceGateway::ID ]() );
	}

	/**
	 * @testdox Should offer Fastlane when its module check passes (fastlane).
	 */
	public function test_axo_active(): void {
		$this->assertTrue( $this->create_service()->get_eligibility_checks()[ AxoGateway::ID ]() );
	}

	/**
	 * @testdox Should not offer Fastlane when its module check fails (fastlane).
	 */
	public function test_axo_inactive(): void {
		$this->assertFalse( $this->create_service( 'US', true, false )->get_eligibility_checks()[ AxoGateway::ID ]() );
	}

	/**
	 * @testdox Should offer Apple Pay and Google Pay when both are available (apple/google).
	 */
	public function test_apple_and_google_active(): void {
		$checks = $this->create_service( 'US' )->get_eligibility_checks();

		$this->assertTrue( $checks[ ApplePayGateway::ID ]() );
		$this->assertTrue( $checks[ GooglePayGateway::ID ]() );
	}

	/**
	 * @testdox Should not offer Apple Pay and Google Pay when neither is available (apple/google).
	 */
	public function test_apple_and_google_inactive(): void {
		$checks = $this->create_service( 'US', true, true, false, false )->get_eligibility_checks();

		$this->assertFalse( $checks[ ApplePayGateway::ID ]() );
		$this->assertFalse( $checks[ GooglePayGateway::ID ]() );
	}

	/**
	 * @testdox Should offer Venmo to US merchants (wallet).
	 */
	public function test_venmo_us(): void {
		$this->assertTrue( $this->create_service( 'US' )->get_eligibility_checks()['venmo']() );
	}

	/**
	 * @testdox Should not offer Venmo outside the US (wallet).
	 */
	public function test_venmo_not_us(): void {
		$this->assertFalse( $this->create_service( 'CA' )->get_eligibility_checks()['venmo']() );
	}

	/**
	 * The local payment method gateways every APM-eligible merchant gets, OXXO and crypto aside.
	 *
	 * @return string[]
	 */
	private function generic_apm_ids(): array {
		return array(
			BancontactGateway::ID,
			BlikGateway::ID,
			EPSGateway::ID,
			IDealGateway::ID,
			MyBankGateway::ID,
			P24Gateway::ID,
			TrustlyGateway::ID,
			MultibancoGateway::ID,
		);
	}

	/**
	 * Build the service.
	 *
	 * @param string $country_code          The merchant country.
	 * @param bool   $apm_enabled           Whether the merchant is APM eligible.
	 * @param bool   $axo_enabled           Whether the Fastlane module check passes.
	 * @param bool   $apple_pay_enabled     Whether Apple Pay is available.
	 * @param bool   $google_pay_enabled    Whether Google Pay is available.
	 * @param array  $merchant_capabilities The merchant capabilities.
	 * @return PaymentMethodsEligibilityService
	 */
	private function create_service(
		string $country_code = 'US',
		bool $apm_enabled = true,
		bool $axo_enabled = true,
		bool $apple_pay_enabled = true,
		bool $google_pay_enabled = true,
		array $merchant_capabilities = array()
	): PaymentMethodsEligibilityService {
		return new PaymentMethodsEligibilityService(
			$country_code,
			$apm_enabled,
			$merchant_capabilities,
			fn() => $axo_enabled,
			$apple_pay_enabled,
			$google_pay_enabled
		);
	}
}
