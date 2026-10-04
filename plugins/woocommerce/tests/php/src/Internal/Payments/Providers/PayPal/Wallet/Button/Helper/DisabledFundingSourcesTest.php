<?php
/**
 * Tests for the disabled funding sources.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Button\Helper
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Button\Helper;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\DisabledFundingSources;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\DTO\LocationStylingDTO;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Mockery\MockInterface;

/**
 * The `disable-funding` list the PayPal SDK URL carries: Venmo from the merchant's setting, `card` always, and every
 * source for a free-trial cart.
 *
 * @group paypal-wallet
 */
class DisabledFundingSourcesTest extends WalletTestCase {

	/**
	 * The settings provider mock.
	 *
	 * @var SettingsProvider&MockInterface
	 */
	private $settings_provider;

	/**
	 * Build the collaborators with Venmo on.
	 */
	public function setUp(): void {
		parent::setUp();

		// The DTO keeps the extension's class name and is aliased into the wallet namespace by this loader at boot.
		require_once WC_ABSPATH . 'src/Internal/Payments/Providers/PayPal/Wallet/SerializedClasses/load.php';

		$this->settings_provider = $this->mock( SettingsProvider::class );
		$this->settings_provider->shouldReceive( 'venmo_enabled' )->andReturn( true )->byDefault();
		$this->settings_provider->shouldReceive( 'button_styling' )->andReturn( new LocationStylingDTO( '', true, array( 'venmo' ) ) )->byDefault();
	}

	/**
	 * Build the system under test.
	 *
	 * @param array $funding_sources All funding sources by key.
	 * @return DisabledFundingSources
	 */
	private function create_sut( array $funding_sources = array() ): DisabledFundingSources {
		return new DisabledFundingSources( $this->settings_provider, $funding_sources );
	}

	/**
	 * Build the system under test with its free-trial check forced on, so the branch runs without WooCommerce
	 * Subscriptions and a cart.
	 *
	 * @param array $funding_sources All funding sources by key.
	 * @return DisabledFundingSources
	 */
	private function make_free_trial_sut( array $funding_sources ): DisabledFundingSources {
		return new class( $this->settings_provider, $funding_sources ) extends DisabledFundingSources {
			/**
			 * Every cart is a free trial.
			 *
			 * @return bool
			 */
			protected function is_free_trial_cart(): bool {
				return true;
			}
		};
	}

	/**
	 * Make is_checkout() answer as the test says.
	 *
	 * @param bool $is_checkout Whether the page is a checkout page.
	 */
	private function stub_is_checkout( bool $is_checkout ): void {
		add_filter(
			'woocommerce_is_checkout',
			static function () use ( $is_checkout ) {
				return $is_checkout;
			}
		);
	}

	/**
	 * Card funding is always disabled, whatever the page or the checkout type.
	 *
	 * @testdox Should disable card on $context, on a checkout page: $is_checkout.
	 * @dataProvider provider_contexts
	 *
	 * @param string $context     The render context.
	 * @param bool   $is_checkout Whether the page is a checkout page.
	 */
	public function test_card_is_always_disabled( string $context, bool $is_checkout ): void {
		$this->stub_is_checkout( $is_checkout );

		$this->assertContains( 'card', $this->create_sut( array( 'card' => 'Credit or debit cards' ) )->sources( $context ) );
		$this->assertContains( 'card', $this->create_sut()->sources( $context ) );
	}

	/**
	 * Data provider for the render contexts.
	 *
	 * @return array
	 */
	public function provider_contexts(): array {
		return array(
			'classic checkout'  => array( 'checkout', true ),
			'checkout block'    => array( 'checkout-block', true ),
			'cart block'        => array( 'cart-block', false ),
			'product page'      => array( 'product', false ),
			'classic cart page' => array( 'cart', false ),
		);
	}

	/**
	 * @testdox Should add card and the sources a block checkout does not allow.
	 */
	public function test_block_checkout_disables_card_and_the_sources_it_does_not_allow(): void {
		$this->stub_is_checkout( true );
		$sut = $this->create_sut(
			array(
				'card'   => 'Credit or debit cards',
				'paypal' => 'PayPal',
				'foo'    => 'Bar',
			)
		);

		$this->assertEquals( array( 'card', 'foo' ), $sut->sources( 'checkout-block' ) );
	}

	/**
	 * @testdox Should disable Venmo when the Venmo setting is off.
	 */
	public function test_venmo_disabled_when_setting_is_false(): void {
		$this->settings_provider = $this->mock( SettingsProvider::class );
		$this->settings_provider->shouldReceive( 'venmo_enabled' )->andReturn( false );
		$this->settings_provider->shouldReceive( 'button_styling' )->andReturn( new LocationStylingDTO( '', true, array( 'venmo' ) ) );
		$this->stub_is_checkout( true );

		$this->assertContains( 'venmo', $this->create_sut()->sources( 'checkout-block' ) );
	}

	/**
	 * @testdox Should disable Venmo for a location whose styling does not list it.
	 */
	public function test_venmo_disabled_for_location_when_not_in_styling_methods(): void {
		$this->settings_provider->shouldReceive( 'button_styling' )->andReturn( new LocationStylingDTO( '', true, array() ) );
		$this->stub_is_checkout( true );

		$this->assertContains( 'venmo', $this->create_sut()->sources( 'checkout-block' ) );
	}

	/**
	 * @testdox Should leave Venmo enabled when the Venmo setting is on.
	 */
	public function test_venmo_enabled_when_setting_is_true(): void {
		$this->stub_is_checkout( true );

		$this->assertNotContains( 'venmo', $this->create_sut()->sources( 'checkout-block' ) );
	}

	/**
	 * @testdox Should disable every funding source except PayPal on a free-trial cart.
	 */
	public function test_free_trial_disables_every_source(): void {
		$sut = $this->make_free_trial_sut(
			array(
				'card'   => 'Credit or debit cards',
				'paypal' => 'PayPal',
				'venmo'  => 'Venmo',
				'foo'    => 'Bar',
			)
		);

		$this->stub_is_checkout( true );

		$this->assertEquals( array( 'card', 'venmo', 'foo' ), array_values( $sut->sources( 'checkout' ) ) );
	}
}
