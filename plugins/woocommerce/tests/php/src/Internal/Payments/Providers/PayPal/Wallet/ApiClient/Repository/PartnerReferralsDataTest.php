<?php
/**
 * Tests for the partner referral payload.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Repository
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Repository;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\DccApplies;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Repository\PartnerReferralsData;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Mockery\MockInterface;

/**
 * The body core sends PayPal at onboarding (the partner referral request): products, capabilities, first-party features
 * and the return URL. One case pins the seller nonce; the rest pin the payload core asks PayPal for. It has no Pay upon
 * Invoice block: the wallet never asks for that product.
 *
 * @group paypal-wallet
 */
class PartnerReferralsDataTest extends WalletTestCase {

	private const HOOKS = array(
		'ppcp_partner_referrals_data',
		'woocommerce_paypal_payments_partner_config_override_return_url',
		'woocommerce_paypal_payments_partner_config_override_return_url_description',
	);

	/**
	 * The DCC applies helper mock.
	 *
	 * @var DccApplies&MockInterface
	 */
	private $dcc_applies;

	/**
	 * The System Under Test.
	 *
	 * @var PartnerReferralsData
	 */
	private $sut;

	/**
	 * Build the repository over mocks, with no other listener on the payload hooks.
	 */
	public function setUp(): void {
		parent::setUp();

		// The test case restores the hooks after each test.
		foreach ( self::HOOKS as $hook ) {
			remove_all_filters( $hook );
		}

		$this->dcc_applies = $this->mock( DccApplies::class );

		$this->sut = new PartnerReferralsData( $this->dcc_applies );
	}

	/**
	 * The return URL the payload carries for the given onboarding token.
	 *
	 * @param string $token The onboarding token.
	 * @return string
	 */
	private function expected_return_url( string $token ): string {
		return add_query_arg(
			array( 'ppcpToken' => $token ),
			admin_url( 'admin.php?page=wc-settings&tab=checkout&section=ppcp-gateway' )
		);
	}

	/**
	 * @testdox Should include the seller nonce it is given in the first-party details.
	 */
	public function test_data_includes_seller_nonce_from_argument(): void {
		$seller_nonce = bin2hex( random_bytes( 32 ) );
		$this->dcc_applies->shouldReceive( 'for_country_currency' )->andReturn( false );

		$result = $this->sut->data( array(), '', null, true, $seller_nonce );

		$details = $result['operations'][0]['api_integration_preference']['rest_api_integration']['first_party_details'];
		$this->assertSame( $seller_nonce, $details['seller_nonce'] );
	}

	/**
	 * @testdox Should ask for Express Checkout with the plain first-party features, no Payment Methods product and no capabilities outside an advanced card country.
	 */
	public function test_payload_outside_an_advanced_card_country(): void {
		$this->dcc_applies->shouldReceive( 'for_country_currency' )->andReturn( false );

		$result = $this->sut->data( array(), 'token-1', null, true, 'nonce-1' );

		$this->assertSame( array( 'EXPRESS_CHECKOUT' ), $result['products'] );
		$this->assertNotContains( 'PAYMENT_METHODS', $result['products'] );
		$this->assertArrayNotHasKey( 'capabilities', $result, 'An empty capabilities list must be left out' );
		$this->assertSame( $this->expected_return_url( 'token-1' ), $result['partner_config_override']['return_url'] );
		$this->assertTrue( $result['partner_config_override']['show_add_credit_card'] );
		$this->assertSame(
			array(
				array(
					'type'    => 'SHARE_DATA_CONSENT',
					'granted' => true,
				),
			),
			$result['legal_consents']
		);
		$details = $result['operations'][0]['api_integration_preference']['rest_api_integration'];
		$this->assertSame( 'API_INTEGRATION', $result['operations'][0]['operation'] );
		$this->assertSame( 'PAYPAL', $details['integration_method'] );
		$this->assertSame( 'FIRST_PARTY', $details['integration_type'] );
		$this->assertSame(
			array( 'PAYMENT', 'REFUND', 'ADVANCED_TRANSACTIONS_SEARCH', 'TRACKING_SHIPMENT_READWRITE', 'BILLING_AGREEMENT', 'VAULT', 'FUTURE_PAYMENT' ),
			$details['first_party_details']['features']
		);
	}

	/**
	 * @testdox Should leave vaulting out of the first-party features when the merchant does not want card payments.
	 */
	public function test_payload_without_card_payments_has_no_vault_features(): void {
		$this->dcc_applies->shouldReceive( 'for_country_currency' )->andReturn( false );

		$result = $this->sut->data( array(), '', null, false, '' );

		$features = $result['operations'][0]['api_integration_preference']['rest_api_integration']['first_party_details']['features'];
		$this->assertSame( array( 'PAYMENT', 'REFUND', 'ADVANCED_TRANSACTIONS_SEARCH', 'TRACKING_SHIPMENT_READWRITE', 'BILLING_AGREEMENT' ), $features );
		$this->assertFalse( $result['partner_config_override']['show_add_credit_card'] );
	}

	/**
	 * @testdox Should keep the products it is given outside an advanced card country.
	 */
	public function test_payload_keeps_the_given_products(): void {
		$this->dcc_applies->shouldReceive( 'for_country_currency' )->andReturn( false );

		$result = $this->sut->data( array( 'EXPRESS_CHECKOUT', 'ADVANCED_VAULTING' ), '', null, true, '' );

		$this->assertSame( array( 'EXPRESS_CHECKOUT', 'ADVANCED_VAULTING' ), $result['products'] );
	}

	/**
	 * @testdox Should ask for PPCP with advanced vaulting and the advanced wallet vaulting capability in an advanced card country.
	 */
	public function test_payload_in_an_advanced_card_country(): void {
		$this->dcc_applies->shouldReceive( 'for_country_currency' )->andReturn( true );

		$result = $this->sut->data( array( 'EXPRESS_CHECKOUT' ), '', null, true, '' );

		$this->assertSame( array( 'PPCP', 'ADVANCED_VAULTING' ), $result['products'] );
		$this->assertSame( array( 'PAYPAL_WALLET_VAULTING_ADVANCED' ), $result['capabilities'] );
	}

	/**
	 * @testdox Should let a filter change the whole payload before the onboarding token is added to the return URL.
	 */
	public function test_payload_filter_runs_before_the_token_is_added(): void {
		$this->dcc_applies->shouldReceive( 'for_country_currency' )->andReturn( false );
		add_filter(
			'ppcp_partner_referrals_data',
			static function ( array $payload ): array {
				$payload['partner_config_override']['return_url'] = 'https://example.com/back';
				$payload['capabilities']                          = array( 'CUSTOM' );
				return $payload;
			}
		);

		$result = $this->sut->data( array(), 'token-2', null, true, '' );

		$this->assertSame( 'https://example.com/back?ppcpToken=token-2', $result['partner_config_override']['return_url'] );
		$this->assertSame( array( 'CUSTOM' ), $result['capabilities'] );
	}
}
