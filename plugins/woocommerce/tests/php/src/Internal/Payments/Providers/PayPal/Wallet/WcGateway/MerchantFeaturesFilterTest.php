<?php
/**
 * Tests for the merchant features filter that the gateway module registers.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\DccApplies;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ReferenceTransactionStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition\FeaturesDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Admin\FeesRenderer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\ApmCapabilityStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\DCCProductStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\InstallmentsProductStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WCGatewayModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * The gateway module answers the merchant features filter from the seller-status capabilities. Pay Later messaging reads
 * the alternative payment methods capability as a proxy, so it follows that capability on its own.
 *
 * @group paypal-wallet
 */
class MerchantFeaturesFilterTest extends WalletTestCase {

	/**
	 * The hook the module extends and the settings app reads.
	 */
	private const FILTER = 'woocommerce_paypal_payments_rest_common_merchant_features';

	/**
	 * Run the module over a container holding the given product statuses and return the filter's answer.
	 *
	 * @param array $flags     What the statuses report: connected, capability, dcc, dcc_applies, reference, installments,
	 *                         contact, save. Missing flags are off.
	 * @param array $features  What the filter receives.
	 * @return array
	 */
	private function features( array $flags, array $features = array() ): array {
		$flags = array_merge(
			array(
				'connected'    => true,
				'capability'   => false,
				'dcc'          => false,
				'dcc_applies'  => false,
				'reference'    => false,
				'installments' => false,
				'contact'      => false,
				'save'         => false,
			),
			$flags
		);

		$capability = $this->mock( ApmCapabilityStatus::class );
		$capability->shouldReceive( 'is_active' )->andReturn( $flags['capability'] );
		$dcc = $this->mock( DCCProductStatus::class );
		$dcc->shouldReceive( 'is_active' )->andReturn( $flags['dcc'] );
		$dcc_applies = $this->mock( DccApplies::class );
		$dcc_applies->shouldReceive( 'for_country_currency' )->andReturn( $flags['dcc_applies'] );
		$reference = $this->mock( ReferenceTransactionStatus::class );
		$reference->shouldReceive( 'reference_transaction_enabled' )->andReturn( $flags['reference'] );
		$installments = $this->mock( InstallmentsProductStatus::class );
		$installments->shouldReceive( 'is_active' )->andReturn( $flags['installments'] );

		$services = array(
			'wcgateway.admin.fees-renderer'              => $this->mock( FeesRenderer::class ),
			'settings.flag.is-connected'                 => $flags['connected'],
			'api.reference-transaction-status'           => $reference,
			'wcgateway.helper.dcc-product-status'        => $dcc,
			'api.helpers.dccapplies'                     => $dcc_applies,
			'wcgateway.apm-capability-status'            => $capability,
			'wcgateway.installments-product-status'      => $installments,
			'wcgateway.contact-module.eligibility.check' => static fn(): bool => $flags['contact'],
			'save-payment-methods.eligibility.check'     => static fn(): bool => $flags['save'],
		);

		$container = $this->mock( ContainerInterface::class );
		$container->shouldReceive( 'has' )->andReturnUsing(
			static function ( $id ) use ( $services ) {
				return isset( $services[ $id ] );
			}
		);
		$container->shouldReceive( 'get' )->andReturnUsing(
			static function ( $id ) use ( $services ) {
				return $services[ $id ];
			}
		);

		( new WCGatewayModule() )->run( $container );

		return apply_filters( self::FILTER, $features );
	}

	/**
	 * @testdox Should leave the features alone while the store is not connected to PayPal.
	 */
	public function test_a_store_that_is_not_connected_adds_no_features(): void {
		$features = $this->features(
			array(
				'connected'  => false,
				'capability' => true,
			),
			array( 'other' => array( 'enabled' => true ) )
		);

		$this->assertSame( array( 'other' => array( 'enabled' => true ) ), $features );
	}

	/**
	 * @testdox Should keep what other listeners already put in the features list.
	 */
	public function test_features_added_earlier_in_the_chain_are_kept(): void {
		$features = $this->features( array(), array( 'other' => array( 'enabled' => true ) ) );

		$this->assertSame( array( 'enabled' => true ), $features['other'] );
	}

	/**
	 * @testdox Should turn Pay Later messaging on exactly when the alternative payment methods capability is active.
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $capability What the capability status reports.
	 */
	public function test_pay_later_messaging_follows_the_capability( bool $capability ): void {
		$features = $this->features( array( 'capability' => $capability ) );

		$this->assertSame( $capability, $features[ FeaturesDefinition::FEATURE_PAY_LATER_MESSAGING ]['enabled'] );
	}

	/**
	 * @testdox Should turn Advanced Card Processing on only when the product is active and the store country and currency allow it.
	 * @testWith [true, true, true]
	 *           [true, false, false]
	 *           [false, true, false]
	 *
	 * @param bool $active   Whether the card product status is active.
	 * @param bool $applies  Whether the country and currency allow cards.
	 * @param bool $expected Whether the row is on.
	 */
	public function test_advanced_card_processing_needs_the_product_and_the_country( bool $active, bool $applies, bool $expected ): void {
		$features = $this->features(
			array(
				'dcc'         => $active,
				'dcc_applies' => $applies,
			)
		);

		$this->assertSame( $expected, $features[ FeaturesDefinition::FEATURE_ADVANCED_CREDIT_AND_DEBIT_CARDS ]['enabled'] );
	}

	/**
	 * @testdox Should turn Save PayPal and Venmo on only when reference transactions and the saving check both pass.
	 * @testWith [true, true, true]
	 *           [true, false, false]
	 *           [false, true, false]
	 *
	 * @param bool $reference Whether reference transactions are enabled.
	 * @param bool $save      Whether the saving check passes.
	 * @param bool $expected  Whether the row is on.
	 */
	public function test_save_paypal_and_venmo_needs_reference_transactions_and_the_check( bool $reference, bool $save, bool $expected ): void {
		$features = $this->features(
			array(
				'reference' => $reference,
				'save'      => $save,
			)
		);

		$this->assertSame( $expected, $features[ FeaturesDefinition::FEATURE_SAVE_PAYPAL_AND_VENMO ]['enabled'] );
	}

	/**
	 * @testdox Should report the Installments and Contact Module rows from their own checks.
	 */
	public function test_installments_and_contact_module_follow_their_checks(): void {
		$features = $this->features(
			array(
				'installments' => true,
				'contact'      => false,
			)
		);

		$this->assertTrue( $features[ FeaturesDefinition::FEATURE_INSTALLMENTS ]['enabled'] );
		$this->assertFalse( $features[ FeaturesDefinition::FEATURE_CONTACT_MODULE ]['enabled'] );
	}

	/**
	 * @testdox Should report exactly the kept feature rows and no Pay with Crypto row.
	 */
	public function test_the_features_list_has_no_pay_with_crypto_row(): void {
		$features = $this->features( array() );

		$this->assertArrayNotHasKey( 'pwc', $features );
		$this->assertEqualsCanonicalizing(
			array(
				FeaturesDefinition::FEATURE_SAVE_PAYPAL_AND_VENMO,
				FeaturesDefinition::FEATURE_ADVANCED_CREDIT_AND_DEBIT_CARDS,
				FeaturesDefinition::FEATURE_PAY_LATER_MESSAGING,
				FeaturesDefinition::FEATURE_INSTALLMENTS,
				FeaturesDefinition::FEATURE_CONTACT_MODULE,
			),
			array_keys( $features )
		);
	}
}
