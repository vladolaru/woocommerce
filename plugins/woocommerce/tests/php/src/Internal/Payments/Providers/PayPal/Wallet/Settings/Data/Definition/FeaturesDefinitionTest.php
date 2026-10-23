<?php
/**
 * Tests for the features definition.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition\FeaturesDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\FeaturesEligibilityService;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;

/**
 * The feature cards of the settings app: which features exist, which of them the store is eligible for, and what each one
 * reports as enabled from the merchant capabilities.
 *
 * @group paypal-wallet
 */
class FeaturesDefinitionTest extends WalletTestCase {

	/**
	 * Build the definition over the given eligibility answers and merchant capabilities.
	 *
	 * @param array $eligible     Eligibility per feature key: save, pay_later, installments, pwc. Missing ones are false.
	 * @param array $capabilities Merchant capabilities per feature key. Missing ones are false.
	 * @return FeaturesDefinition
	 */
	private function create_definition( array $eligible = array(), array $capabilities = array() ): FeaturesDefinition {
		$eligible = array_merge(
			array(
				'save'         => false,
				'pay_later'    => false,
				'installments' => false,
				'pwc'          => false,
			),
			$eligible
		);

		$eligibilities = new FeaturesEligibilityService(
			$eligible['save'],
			$eligible['pay_later'],
			$eligible['installments'],
			$eligible['pwc']
		);

		$settings = $this->mock( GeneralSettings::class );
		$settings->shouldReceive( 'get_woo_settings' )->andReturn( array( 'country' => 'US' ) );

		$capabilities = array_merge(
			array(
				FeaturesDefinition::FEATURE_SAVE_PAYPAL_AND_VENMO => false,
				FeaturesDefinition::FEATURE_PAY_LATER_MESSAGING => false,
				FeaturesDefinition::FEATURE_INSTALLMENTS => false,
				FeaturesDefinition::FEATURE_PAY_WITH_CRYPTO => false,
			),
			$capabilities
		);

		return new FeaturesDefinition(
			$eligibilities,
			$settings,
			$capabilities,
			$this->mock( LoggerInterface::class )->shouldIgnoreMissing()
		);
	}

	/**
	 * @testdox Should offer the Save PayPal and Venmo, Pay Later and Installments cards to a store that is eligible for all of them.
	 */
	public function test_the_wallet_cards_are_offered_when_eligible(): void {
		$features = $this->create_definition(
			array(
				'save'         => true,
				'pay_later'    => true,
				'installments' => true,
			)
		)->eligible_features();

		$this->assertSame(
			array(
				FeaturesDefinition::FEATURE_SAVE_PAYPAL_AND_VENMO,
				FeaturesDefinition::FEATURE_PAY_LATER_MESSAGING,
				FeaturesDefinition::FEATURE_INSTALLMENTS,
			),
			array_keys( $features ),
			'Exactly the three wallet cards should be listed, in this order'
		);
	}

	/**
	 * @testdox Should hide a card when the store is not eligible for it.
	 */
	public function test_a_card_without_eligibility_is_hidden(): void {
		$features = $this->create_definition( array( 'pay_later' => true ) )->eligible_features();

		$this->assertArrayHasKey( FeaturesDefinition::FEATURE_PAY_LATER_MESSAGING, $features );
		$this->assertArrayNotHasKey( FeaturesDefinition::FEATURE_SAVE_PAYPAL_AND_VENMO, $features );
		$this->assertArrayNotHasKey( FeaturesDefinition::FEATURE_INSTALLMENTS, $features );
	}

	/**
	 * @testdox Should report each wallet card as enabled from its own merchant capability.
	 */
	public function test_the_wallet_cards_report_their_own_capability(): void {
		$features = $this->create_definition(
			array(),
			array(
				FeaturesDefinition::FEATURE_SAVE_PAYPAL_AND_VENMO => true,
				FeaturesDefinition::FEATURE_PAY_LATER_MESSAGING => false,
				FeaturesDefinition::FEATURE_INSTALLMENTS => true,
			)
		)->all_available_features();

		$this->assertTrue( $features[ FeaturesDefinition::FEATURE_SAVE_PAYPAL_AND_VENMO ]['enabled'] );
		$this->assertFalse( $features[ FeaturesDefinition::FEATURE_PAY_LATER_MESSAGING ]['enabled'] );
		$this->assertTrue( $features[ FeaturesDefinition::FEATURE_INSTALLMENTS ]['enabled'] );
	}

	/**
	 * @testdox Should let a listener change the features list through woocommerce_paypal_payments_features_list.
	 */
	public function test_the_features_list_filter_is_applied(): void {
		add_filter(
			'woocommerce_paypal_payments_features_list',
			static function ( $items ) {
				$items['added_by_a_listener'] = array( 'title' => 'Added' );
				return $items;
			}
		);

		$features = $this->create_definition()->all_available_features();

		$this->assertArrayHasKey( 'added_by_a_listener', $features );
	}

	/**
	 * @testdox Should answer false for a feature that has no eligibility check.
	 */
	public function test_an_unknown_feature_is_not_eligible(): void {
		$this->assertFalse( $this->create_definition()->is_feature_eligible( 'not_a_feature' ) );
	}

	/**
	 * @testdox Should offer the Pay with Crypto card, first, only when its eligibility check passes.
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $eligible Whether the Pay with Crypto eligibility check passes.
	 */
	public function test_the_pay_with_crypto_card_follows_its_check( bool $eligible ): void {
		$features = $this->create_definition( array( 'pwc' => $eligible ) )->eligible_features();

		$this->assertSame( $eligible, array_key_exists( FeaturesDefinition::FEATURE_PAY_WITH_CRYPTO, $features ) );
		$this->assertSame( FeaturesDefinition::FEATURE_PAY_WITH_CRYPTO, array_key_first( $this->create_definition()->all_available_features() ), 'The card leads the list' );
	}

	/**
	 * @testdox Should report the Pay with Crypto card as enabled from the merchant's Pay with Crypto capability.
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $capability The merchant's Pay with Crypto capability.
	 */
	public function test_the_pay_with_crypto_card_reports_its_capability( bool $capability ): void {
		$features = $this->create_definition( array(), array( FeaturesDefinition::FEATURE_PAY_WITH_CRYPTO => $capability ) )->all_available_features();

		$this->assertSame( $capability, $features[ FeaturesDefinition::FEATURE_PAY_WITH_CRYPTO ]['enabled'] );
	}

	/**
	 * @testdox Should give the Pay with Crypto card a Configure button when enabled, the two Sign up URLs when disabled, and Learn more.
	 */
	public function test_the_pay_with_crypto_card_buttons(): void {
		$card = $this->create_definition( array( 'pwc' => true ) )->all_available_features()[ FeaturesDefinition::FEATURE_PAY_WITH_CRYPTO ];

		$this->assertSame( 'Pay with Crypto', $card['title'] );
		$this->assertSame( 'Enable customers to pay with cryptocurrency, and receive payments in USD in your PayPal balance.', $card['description'] );
		$this->assertCount( 3, $card['buttons'] );

		list( $configure, $sign_up, $learn_more ) = $card['buttons'];

		$this->assertSame( 'Configure', $configure['text'] );
		$this->assertSame( 'enabled', $configure['showWhen'] );
		$this->assertSame(
			array(
				'type'    => 'tab',
				'tab'     => 'payment_methods',
				'section' => 'ppcp-pwc',
			),
			$configure['action']
		);

		$this->assertSame( 'Sign up', $sign_up['text'] );
		$this->assertSame( 'disabled', $sign_up['showWhen'] );
		$this->assertSame(
			array(
				'sandbox' => 'https://www.sandbox.paypal.com/bizsignup/add-product?product=CRYPTO_PYMTS',
				'live'    => 'https://www.paypal.com/bizsignup/add-product?product=CRYPTO_PYMTS',
			),
			$sign_up['urls']
		);

		$this->assertSame( 'Learn more', $learn_more['text'] );
		$this->assertSame( 'https://www.paypal.com/us/digital-wallet/manage-money/crypto', $learn_more['url'] );
	}
}
