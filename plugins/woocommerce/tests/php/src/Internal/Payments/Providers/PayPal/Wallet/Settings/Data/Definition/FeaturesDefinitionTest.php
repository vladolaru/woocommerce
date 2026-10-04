<?php
/**
 * Tests for the features definition (core-only characterization: the extension has no test for it).
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
	 * @param array $eligible     Eligibility per feature key: save, pay_later, installments. Missing ones are false.
	 * @param array $capabilities Merchant capabilities per feature key. Missing ones are false.
	 * @return FeaturesDefinition
	 */
	private function create_definition( array $eligible = array(), array $capabilities = array() ): FeaturesDefinition {
		$eligible = array_merge(
			array(
				'save'         => false,
				'pay_later'    => false,
				'installments' => false,
			),
			$eligible
		);

		$eligibilities = new FeaturesEligibilityService(
			$eligible['save'],
			$eligible['pay_later'],
			$eligible['installments']
		);

		$settings = $this->mock( GeneralSettings::class );
		$settings->shouldReceive( 'get_woo_settings' )->andReturn( array( 'country' => 'US' ) );

		$capabilities = array_merge(
			array(
				FeaturesDefinition::FEATURE_SAVE_PAYPAL_AND_VENMO => false,
				FeaturesDefinition::FEATURE_PAY_LATER_MESSAGING => false,
				FeaturesDefinition::FEATURE_INSTALLMENTS => false,
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
	 * @testdox Should offer the Save PayPal and Venmo, Pay Later and Installments cards to a store that is eligible for all of them (wallet).
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
	 * @testdox Should hide a card when the store is not eligible for it (wallet).
	 */
	public function test_a_card_without_eligibility_is_hidden(): void {
		$features = $this->create_definition( array( 'pay_later' => true ) )->eligible_features();

		$this->assertArrayHasKey( FeaturesDefinition::FEATURE_PAY_LATER_MESSAGING, $features );
		$this->assertArrayNotHasKey( FeaturesDefinition::FEATURE_SAVE_PAYPAL_AND_VENMO, $features );
		$this->assertArrayNotHasKey( FeaturesDefinition::FEATURE_INSTALLMENTS, $features );
	}

	/**
	 * @testdox Should report each wallet card as enabled from its own merchant capability (wallet).
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
	 * @testdox Should let a listener change the features list through woocommerce_paypal_payments_features_list (wallet).
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
	 * @testdox Should answer false for a feature that has no eligibility check (wallet).
	 */
	public function test_an_unknown_feature_is_not_eligible(): void {
		$this->assertFalse( $this->create_definition()->is_feature_eligible( 'not_a_feature' ) );
	}

	/**
	 * @testdox Should define no Pay with Crypto card, even when every eligibility check passes (wallet).
	 */
	public function test_there_is_no_pay_with_crypto_card(): void {
		$features = $this->create_definition(
			array(
				'save'         => true,
				'pay_later'    => true,
				'installments' => true,
			)
		);

		$this->assertArrayNotHasKey( 'pwc', $features->all_available_features() );
	}
}
