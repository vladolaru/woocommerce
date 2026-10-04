<?php
/**
 * Tests for the contact preference factory.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\ExperienceContext;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ContactPreferenceFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\MerchantDetails;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Picks the contact preference of the experience context for a payment source.
 *
 * @group paypal-wallet
 */
class ContactPreferenceFactoryTest extends WalletTestCase {

	/**
	 * @testdox Should pick the contact preference from the payment source, the module setting and the merchant's eligibility.
	 *
	 * @dataProvider data_from_state
	 *
	 * @param string      $payment_source_key           The payment source.
	 * @param bool        $is_contact_module_active     Whether the contact module is active.
	 * @param bool        $is_eligible_for_contact_module Whether the merchant is eligible for the contact module.
	 * @param string|null $expected_result              The expected preference.
	 */
	public function test_from_state( string $payment_source_key, bool $is_contact_module_active, bool $is_eligible_for_contact_module, ?string $expected_result ): void {
		$merchant_details = $this->mock( MerchantDetails::class );
		$merchant_details->shouldReceive( 'is_eligible_for' )->andReturn( $is_eligible_for_contact_module );

		$testee = new ContactPreferenceFactory( $is_contact_module_active, $merchant_details );

		$this->assertEquals( $expected_result, $testee->from_state( $payment_source_key ) );
	}

	/**
	 * PayPal and Venmo update the contact info, unless the module is off or the merchant is not eligible. Any other
	 * payment source has no contact preference.
	 *
	 * @return array<string, array>
	 */
	public function data_from_state(): array {
		$update  = ExperienceContext::CONTACT_PREFERENCE_UPDATE_CONTACT_INFO;
		$no_info = ExperienceContext::CONTACT_PREFERENCE_NO_CONTACT_INFO;

		return array(
			'paypal, active and eligible'      => array( 'paypal', true, true, $update ),
			'venmo, active and eligible'       => array( 'venmo', true, true, $update ),
			'paypal, module off'               => array( 'paypal', false, true, $no_info ),
			'paypal, not eligible'             => array( 'paypal', true, false, $no_info ),
			'paypal, module off, not eligible' => array( 'paypal', false, false, $no_info ),
			'oxxo, active and eligible'        => array( 'oxxo', true, true, null ),
			'oxxo, module off'                 => array( 'oxxo', false, true, null ),
			'oxxo, module off, not eligible'   => array( 'oxxo', false, false, null ),
		);
	}
}
