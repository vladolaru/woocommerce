<?php
/**
 * Tests for the Pay Later messaging eligibility check.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Helper
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Helper;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\MessagesApply;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Helper\MessagesEligibility;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\SettingsStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\FreeTrialSubscriptionHelper;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Mockery\MockInterface;
use WC_Helper_Product;

/**
 * The chain of conditions behind Pay Later messaging and the two merchant filters that hide it. The class has no card branch.
 *
 * @group paypal-wallet
 */
class MessagesEligibilityTest extends WalletTestCase {

	private const RENDER_FILTER = 'woocommerce_paypal_payments_should_render_pay_later_messaging';

	/**
	 * The settings provider mock.
	 *
	 * @var SettingsProvider&MockInterface
	 */
	private $settings_provider;

	/**
	 * The settings status mock.
	 *
	 * @var SettingsStatus&MockInterface
	 */
	private $settings_status;

	/**
	 * The messages apply mock.
	 *
	 * @var MessagesApply&MockInterface
	 */
	private $messages_apply;

	/**
	 * The free trial helper mock.
	 *
	 * @var FreeTrialSubscriptionHelper&MockInterface
	 */
	private $free_trial_helper;

	/**
	 * Build the collaborators.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->settings_provider = $this->mock( SettingsProvider::class );
		$this->settings_status   = $this->mock( SettingsStatus::class );
		$this->messages_apply    = $this->mock( MessagesApply::class );
		$this->free_trial_helper = $this->mock( FreeTrialSubscriptionHelper::class );
	}

	/**
	 * @testdox Should report messaging as not enabled for an empty location without consulting anything else.
	 */
	public function test_returns_false_for_empty_location_without_checking_anything_else(): void {
		$render_calls = 0;
		add_filter(
			self::RENDER_FILTER,
			static function ( $render ) use ( &$render_calls ) {
				++$render_calls;
				return $render;
			}
		);
		$this->settings_provider->shouldNotReceive( 'gateway_enabled' );

		$result = $this->create_sut()->is_enabled_for_location( '' );

		$this->assertFalse( $result );
		$this->assertSame( 0, $render_calls, 'The render filter must not run for an empty location' );
	}

	/**
	 * @testdox Should decide messaging eligibility from the whole chain: $scenario.
	 * @dataProvider eligibility_chain_data
	 *
	 * @param string $scenario  What differs from the happy path.
	 * @param array  $overrides Conditions that deviate from the happy path.
	 * @param bool   $expected  Whether messaging is enabled.
	 */
	public function test_eligibility_chain( string $scenario, array $overrides, bool $expected ): void {
		unset( $scenario );
		$this->stub_happy_path( $overrides );

		$result = $this->create_sut()->is_enabled_for_location( 'checkout' );

		$this->assertSame( $expected, $result );
	}

	/**
	 * Scenarios of the chain.
	 *
	 * @return array
	 */
	public function eligibility_chain_data(): array {
		return array(
			'the shared should-render filter disables messaging entirely' => array( 'render filter off', array( 'render_filter' => false ), false ),
			'the PayPal gateway itself is disabled' => array( 'gateway disabled', array( 'gateway_enabled' => false ), false ),
			'pay later messaging is switched off in the settings' => array( 'messaging off', array( 'messaging_enabled' => false ), false ),
			'no locations have pay later messaging configured' => array( 'no locations', array( 'has_locations' => false ), false ),
			'the buyer country is not eligible for pay later messaging' => array( 'country not eligible', array( 'for_country' => false ), false ),
			'the cart is a free trial subscription' => array( 'free trial cart', array( 'free_trial_cart' => true ), false ),
			'every condition passes and the location itself is enabled' => array( 'all pass', array(), true ),
		);
	}

	/**
	 * @testdox Should apply the product filter with the product and its raw price when hiding messaging for a product.
	 */
	public function test_is_hidden_for_product_context_applies_product_filter_with_context_data(): void {
		$product = WC_Helper_Product::create_simple_product( true, array( 'regular_price' => '19.99' ) );
		$this->go_to( get_permalink( $product->get_id() ) );

		$received = array();
		add_filter(
			'woocommerce_paypal_payments_product_buttons_paylater_disabled',
			static function ( $hidden, $context ) use ( &$received ) {
				$received = array( $hidden, $context );
				return true;
			},
			10,
			2
		);

		$result = $this->create_sut()->is_hidden( 'product' );

		$this->assertTrue( $result );
		$this->assertFalse( $received[0] );
		$this->assertSame( $product->get_id(), $received[1]['product']->get_id() );
		$this->assertSame( 19.99, $received[1]['order_total'] );
	}

	/**
	 * @testdox Should apply the generic filter with the context string when hiding messaging outside a product page.
	 */
	public function test_is_hidden_for_non_product_context_applies_generic_filter_with_context_string(): void {
		$received = array();
		add_filter(
			'woocommerce_paypal_payments_buttons_paylater_disabled',
			static function ( $hidden, $context ) use ( &$received ) {
				$received = array( $hidden, $context );
				return true;
			},
			10,
			2
		);

		$result = $this->create_sut()->is_hidden( 'cart' );

		$this->assertTrue( $result );
		$this->assertSame( array( false, 'cart' ), $received );
	}

	/**
	 * @testdox Should default to not hidden when no filter intervenes.
	 */
	public function test_is_hidden_defaults_to_false_when_no_filter_intervenes(): void {
		$this->go_to( '/' );

		$this->assertFalse( $this->create_sut()->is_hidden( 'product' ) );
		$this->assertFalse( $this->create_sut()->is_hidden( 'checkout' ) );
	}

	/**
	 * Build the system under test.
	 *
	 * @return MessagesEligibility
	 */
	private function create_sut(): MessagesEligibility {
		return new MessagesEligibility(
			$this->settings_provider,
			$this->settings_status,
			$this->messages_apply,
			$this->free_trial_helper
		);
	}

	/**
	 * Make every condition of the chain pass, so one override isolates the condition under test.
	 *
	 * @param array $overrides Condition name to the value it takes instead.
	 */
	private function stub_happy_path( array $overrides = array() ): void {
		$render = $overrides['render_filter'] ?? true;
		add_filter(
			self::RENDER_FILTER,
			static function () use ( $render ) {
				return $render;
			}
		);

		$this->settings_provider->shouldReceive( 'gateway_enabled' )
			->with( PayPalGateway::ID )
			->andReturn( $overrides['gateway_enabled'] ?? true );
		$this->settings_status->shouldReceive( 'is_pay_later_messaging_enabled' )
			->andReturn( $overrides['messaging_enabled'] ?? true );
		$this->settings_status->shouldReceive( 'has_pay_later_messaging_locations' )
			->andReturn( $overrides['has_locations'] ?? true );
		$this->messages_apply->shouldReceive( 'for_country' )
			->andReturn( $overrides['for_country'] ?? true );
		$this->free_trial_helper->shouldReceive( 'is_free_trial_cart' )
			->andReturn( $overrides['free_trial_cart'] ?? false );
		$this->settings_status->shouldReceive( 'is_pay_later_messaging_enabled_for_location' )
			->andReturn( $overrides['enabled_for_location'] ?? true );
	}
}
