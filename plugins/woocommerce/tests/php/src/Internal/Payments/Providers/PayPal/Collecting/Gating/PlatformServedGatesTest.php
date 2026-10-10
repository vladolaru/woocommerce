<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Gating;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating\PlatformServedGates;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition\FeaturesDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition\TodosDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\TodosModel;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\FeaturesEligibilityService;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\TodosEligibilityService;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;

/**
 * Tests for the filters that keep authorize-only and saved PayPal and Venmo off while the platform serves the store.
 *
 * @group paypal-wallet
 */
class PlatformServedGatesTest extends WalletTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var PlatformServedGates
	 */
	private $sut;

	/**
	 * Build the SUT over the stored options.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new PlatformServedGates( new ConnectionState() );
	}

	/**
	 * Put the store in the collecting state.
	 */
	private function set_collecting(): void {
		$this->set_wallet_option( Options::COLLECTING, array( 'payee_email' => 'payee@example.com' ) );
	}

	/**
	 * @testdox Should force the capture intent in the case each call site uses while the platform serves the store.
	 *
	 * @testWith ["AUTHORIZE", "CAPTURE"]
	 *           ["CAPTURE", "CAPTURE"]
	 *           ["authorize", "capture"]
	 *           ["capture", "capture"]
	 *
	 * @param string $intent   The intent the call site passes.
	 * @param string $expected The intent the filter returns.
	 */
	public function test_forces_capture_while_collecting( string $intent, string $expected ): void {
		$this->set_collecting();

		$this->assertSame( $expected, $this->sut->handle_woocommerce_paypal_payments_order_intent( $intent ) );
	}

	/**
	 * @testdox Should answer the upper-case capture intent for a value that is not a string while the platform serves the store.
	 */
	public function test_forces_upper_case_capture_for_a_non_string(): void {
		$this->set_wallet_option( Options::PLATFORM, array( 'merchant_id' => 'M2' ) );

		$this->assertSame( 'CAPTURE', $this->sut->handle_woocommerce_paypal_payments_order_intent( null ) );
	}

	/**
	 * @testdox Should leave the intent alone when the platform does not serve the store.
	 */
	public function test_keeps_the_intent_when_not_served(): void {
		$this->assertSame( 'AUTHORIZE', $this->sut->handle_woocommerce_paypal_payments_order_intent( 'AUTHORIZE' ) );
	}

	/**
	 * @testdox Should mark saved PayPal and Venmo unavailable and keep the other features while the platform serves the store.
	 */
	public function test_hides_saved_paypal_and_venmo_while_collecting(): void {
		$this->set_collecting();

		$features = $this->sut->handle_woocommerce_paypal_payments_rest_common_merchant_features(
			array(
				'save_paypal_and_venmo' => array( 'enabled' => true ),
				'installments'          => array( 'enabled' => true ),
			)
		);

		$this->assertFalse( $features['save_paypal_and_venmo']['enabled'] );
		$this->assertTrue( $features['installments']['enabled'], 'Only the vault feature changes' );
	}

	/**
	 * @testdox Should leave the features alone when the platform does not serve the store, or when they are not an array.
	 */
	public function test_keeps_the_features_when_not_served_or_malformed(): void {
		$features = array( 'save_paypal_and_venmo' => array( 'enabled' => true ) );

		$this->assertSame( $features, $this->sut->handle_woocommerce_paypal_payments_rest_common_merchant_features( $features ) );

		$this->set_collecting();
		$this->assertSame( 'oops', $this->sut->handle_woocommerce_paypal_payments_rest_common_merchant_features( 'oops' ) );
	}

	/**
	 * The feature list the settings app receives, over a store eligible for every card, with the gate filter hooked.
	 *
	 * @return array The feature definitions keyed by feature ID.
	 */
	private function served_features_list(): array {
		$settings = $this->mock( GeneralSettings::class );
		$settings->shouldReceive( 'get_woo_settings' )->andReturn( array( 'country' => 'US' ) );
		$definition = new FeaturesDefinition(
			new FeaturesEligibilityService( true, true, true ),
			$settings,
			array(
				FeaturesDefinition::FEATURE_SAVE_PAYPAL_AND_VENMO => false,
				FeaturesDefinition::FEATURE_PAY_LATER_MESSAGING => false,
				FeaturesDefinition::FEATURE_INSTALLMENTS => false,
			),
			$this->mock( LoggerInterface::class )->shouldIgnoreMissing()
		);
		add_filter( 'woocommerce_paypal_payments_features_list', array( $this->sut, 'handle_woocommerce_paypal_payments_features_list' ), 100 );

		return $definition->all_available_features();
	}

	/**
	 * The to-do list the settings app receives, over a store eligible for every item, with the gate filter hooked.
	 *
	 * @return array The to-do definitions keyed by ID.
	 */
	private function served_todos_list(): array {
		$definition = new TodosDefinition(
			new TodosEligibilityService( true, true, true, true, true, true, true, true, true ),
			$this->mock( GeneralSettings::class ),
			$this->mock( TodosModel::class )
		);
		add_filter( 'woocommerce_paypal_payments_todos_list', array( $this->sut, 'handle_woocommerce_paypal_payments_todos_list' ), 100 );

		return $definition->get();
	}

	/**
	 * @testdox Should list no Save PayPal and Venmo or Installments card, which invite a PayPal sign-up, and keep Pay Later messaging while the platform serves the store.
	 */
	public function test_features_list_drops_the_sign_up_cards_while_served(): void {
		$this->set_collecting();

		$features = $this->served_features_list();

		$this->assertArrayNotHasKey( FeaturesDefinition::FEATURE_SAVE_PAYPAL_AND_VENMO, $features );
		$this->assertArrayNotHasKey( FeaturesDefinition::FEATURE_INSTALLMENTS, $features );
		$this->assertArrayHasKey( FeaturesDefinition::FEATURE_PAY_LATER_MESSAGING, $features, 'Pay Later messaging has no sign-up button, only its settings tab and a Learn more link' );
	}

	/**
	 * @testdox Should leave the features list as the wallet built it on a store with first-party credentials or no wallet state.
	 * @testWith ["first_party"]
	 *           ["dormant"]
	 *
	 * @param string $scenario What the store has.
	 */
	public function test_features_list_is_unchanged_when_not_served( string $scenario ): void {
		if ( 'first_party' === $scenario ) {
			$this->set_wallet_option( 'woocommerce-ppcp-data-common', array( 'merchant_connected' => true ) );
		}

		$features = $this->served_features_list();

		$this->assertSame(
			array( FeaturesDefinition::FEATURE_SAVE_PAYPAL_AND_VENMO, FeaturesDefinition::FEATURE_PAY_LATER_MESSAGING, FeaturesDefinition::FEATURE_INSTALLMENTS ),
			array_keys( $features )
		);
	}

	/**
	 * @testdox Should leave the features list alone when it is not an array.
	 */
	public function test_features_list_keeps_a_malformed_value(): void {
		$this->set_collecting();

		$this->assertSame( 'oops', $this->sut->handle_woocommerce_paypal_payments_features_list( 'oops' ) );
	}

	/**
	 * @testdox Should list no Working Capital or Installments to-do, which send the payee to a PayPal account, and keep the in-app items while the platform serves the store.
	 */
	public function test_todos_list_drops_the_paypal_account_items_while_served(): void {
		$this->set_collecting();

		$todos = $this->served_todos_list();

		$this->assertArrayNotHasKey( 'apply_for_working_capital', $todos );
		$this->assertArrayNotHasKey( 'enable_installments', $todos );
		$this->assertArrayHasKey( 'enable_pay_later_messaging', $todos );
		$this->assertArrayHasKey( 'add_paypal_buttons_cart', $todos );
	}

	/**
	 * @testdox Should leave the to-do list as the wallet built it when the platform does not serve the store.
	 */
	public function test_todos_list_is_unchanged_when_not_served(): void {
		$todos = $this->served_todos_list();

		$this->assertArrayHasKey( 'apply_for_working_capital', $todos );
		$this->assertArrayHasKey( 'enable_installments', $todos );
	}

	/**
	 * @testdox Should leave the to-do list alone when it is not an array.
	 */
	public function test_todos_list_keeps_a_malformed_value(): void {
		$this->set_collecting();

		$this->assertNull( $this->sut->handle_woocommerce_paypal_payments_todos_list( null ) );
	}
}
