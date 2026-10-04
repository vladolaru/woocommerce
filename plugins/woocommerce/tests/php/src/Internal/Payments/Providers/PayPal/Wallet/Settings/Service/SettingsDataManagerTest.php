<?php
/**
 * Tests for the settings defaults manager (ported from the extension's SettingsDataManagerTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition\PaymentMethodsDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\OnboardingProfile;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\PaymentSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsModel;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\StylingSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\DTO\ConfigurationFlagsDTO;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\SettingsDataManager;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Mockery\MockInterface;
use ReflectionMethod;

/**
 * Which payment methods the onboarding defaults switch on, and what a reconnect leaves alone.
 *
 * The Pay Later and reconnect cases and the gateway toggle cases pin what toggle_payment_gateways() does. The default
 * location styling case pins the methods each location starts with.
 *
 * The Fastlane watermark case pins that onboarding still switches on the extension's `fastlane_display_watermark`
 * (shared stored value, ruling R82): the model keeps `set_fastlane_display_watermark()` as the one writer, and the stub
 * with its recorder in setUp records what it receives.
 *
 * @group paypal-wallet
 */
class SettingsDataManagerTest extends WalletTestCase {

	/**
	 * The payment methods model mock.
	 *
	 * @var PaymentSettings&MockInterface
	 */
	private $payment_methods;

	/**
	 * The styling settings mock.
	 *
	 * @var StylingSettings&MockInterface
	 */
	private $styling_settings;

	/**
	 * The onboarding profile mock.
	 *
	 * @var OnboardingProfile&MockInterface
	 */
	private $onboarding_profile;

	/**
	 * The System Under Test.
	 *
	 * @var SettingsDataManager
	 */
	private $sut;

	/**
	 * Whether payment_methods::save() ran; the default stub accepts every call.
	 *
	 * @var bool
	 */
	private bool $payment_methods_saved = false;

	/**
	 * Every value passed to set_fastlane_display_watermark().
	 *
	 * @var bool[]
	 */
	private array $watermark_values = array();

	/**
	 * Build the manager over mocked models.
	 */
	public function setUp(): void {
		parent::setUp();

		$methods_definition = $this->mock( PaymentMethodsDefinition::class );
		// Includes 'pay-later' so the skip in toggle_payment_gateways()'s disable loop is exercised.
		$methods_definition->shouldReceive( 'group_paypal_methods' )->andReturn(
			array(
				array( 'id' => PayPalGateway::ID ),
				array( 'id' => 'venmo' ),
				array( 'id' => 'pay-later' ),
			)
		);
		$methods_definition->shouldReceive( 'group_card_methods' )->andReturn( array() );
		$methods_definition->shouldReceive( 'group_apms' )->andReturn( array() );

		$this->payment_methods = $this->mock( PaymentSettings::class );
		$this->payment_methods->shouldReceive( 'set_fastlane_display_watermark' )->andReturnUsing(
			function ( bool $value ): void {
				$this->watermark_values[] = $value;
			}
		);
		$this->payment_methods->shouldReceive( 'save' )->andReturnUsing(
			function (): void {
				$this->payment_methods_saved = true;
			}
		);

		$this->onboarding_profile = $this->mock( OnboardingProfile::class );
		$this->styling_settings   = $this->mock( StylingSettings::class );

		$this->sut = new SettingsDataManager(
			$methods_definition,
			$this->onboarding_profile,
			$this->mock( GeneralSettings::class ),
			$this->mock( SettingsModel::class ),
			$this->styling_settings,
			$this->payment_methods,
			array()
		);
	}

	/**
	 * Drive the protected toggle_payment_gateways() directly with the given flags.
	 *
	 * @param ConfigurationFlagsDTO $flags The configuration flags.
	 */
	private function toggle_payment_gateways( ConfigurationFlagsDTO $flags ): void {
		$method = new ReflectionMethod( SettingsDataManager::class, 'toggle_payment_gateways' );
		$method->setAccessible( true );
		$method->invoke( $this->sut, $flags );
	}

	/**
	 * Drive the protected apply_payment_methods() directly.
	 *
	 * @param ConfigurationFlagsDTO $flags The configuration flags.
	 */
	private function apply_payment_methods( ConfigurationFlagsDTO $flags ): void {
		$method = new ReflectionMethod( SettingsDataManager::class, 'apply_payment_methods' );
		$method->setAccessible( true );
		$method->invoke( $this->sut, $flags );
	}

	/**
	 * Record every toggle_method_state() call.
	 *
	 * @return \ArrayObject Method ID to the last state it was set to.
	 */
	private function record_toggles(): \ArrayObject {
		$toggled_states = new \ArrayObject();
		$this->payment_methods->shouldReceive( 'toggle_method_state' )->andReturnUsing(
			static function ( string $method_id, bool $enabled ) use ( $toggled_states ): void {
				$toggled_states[ $method_id ] = $enabled;
			}
		);

		return $toggled_states;
	}

	/**
	 * @testdox Should never toggle Pay Later when syncing gateways: business seller $is_business_seller, cards $use_card_payments, subscriptions $use_subscriptions (wallet).
	 * @dataProvider gateway_sync_flag_provider
	 *
	 * @param bool $is_business_seller Whether the merchant is a business seller.
	 * @param bool $use_card_payments  Whether the merchant wants card payments.
	 * @param bool $use_subscriptions  Whether the merchant uses subscriptions.
	 */
	public function test_pay_later_untouched_by_gateway_sync( bool $is_business_seller, bool $use_card_payments, bool $use_subscriptions ): void {
		$toggled_states = $this->record_toggles();

		$flags                     = new ConfigurationFlagsDTO();
		$flags->is_business_seller = $is_business_seller;
		$flags->use_card_payments  = $use_card_payments;
		$flags->use_subscriptions  = $use_subscriptions;

		$this->toggle_payment_gateways( $flags );

		$this->assertArrayNotHasKey( 'pay-later', $toggled_states->getArrayCopy() );
	}

	/**
	 * Flag combinations.
	 *
	 * @return array
	 */
	public function gateway_sync_flag_provider(): array {
		return array(
			'business seller, cards, subscriptions'       => array( true, true, true ),
			'business seller, cards, no subscriptions'    => array( true, true, false ),
			'business seller, no cards, subscriptions'    => array( true, false, true ),
			'business seller, no cards, no subscriptions' => array( true, false, false ),
			'casual seller, cards, subscriptions'         => array( false, true, true ),
			'casual seller, cards, no subscriptions'      => array( false, true, false ),
			'casual seller, no cards, subscriptions'      => array( false, false, true ),
			'casual seller, no cards, no subscriptions'   => array( false, false, false ),
		);
	}

	/**
	 * @testdox Should enable Pay Later and persist the payment methods when a new merchant completes onboarding (wallet).
	 */
	public function test_apply_payment_methods_enables_pay_later(): void {
		$toggled_states = $this->record_toggles();

		$this->apply_payment_methods( new ConfigurationFlagsDTO() );

		$this->assertTrue( $toggled_states['pay-later'] ?? false );
		$this->assertTrue( $this->payment_methods_saved );
	}

	/**
	 * @testdox Should touch no payment method state when a merchant who finished onboarding reconnects (wallet).
	 */
	public function test_set_defaults_for_new_merchant_keeps_pay_later_choice_on_reconnect(): void {
		$this->onboarding_profile->shouldReceive( 'is_setup_done' )->andReturn( true );
		$this->payment_methods->shouldNotReceive( 'toggle_method_state' );

		$this->sut->set_defaults_for_new_merchant( new ConfigurationFlagsDTO() );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @testdox Should switch the Fastlane watermark on by default when syncing gateways (wallet, the extension's stored value).
	 */
	public function test_gateway_sync_switches_the_fastlane_watermark_on(): void {
		$this->record_toggles();

		$this->toggle_payment_gateways( new ConfigurationFlagsDTO() );

		$this->assertSame( array( true ), $this->watermark_values );
	}

	/**
	 * @testdox Should always switch PayPal and Venmo on when syncing gateways (wallet).
	 */
	public function test_paypal_and_venmo_are_always_enabled_by_gateway_sync(): void {
		$toggled_states = $this->record_toggles();

		$this->toggle_payment_gateways( new ConfigurationFlagsDTO() );

		$this->assertTrue( $toggled_states[ PayPalGateway::ID ] ?? false );
		$this->assertTrue( $toggled_states['venmo'] ?? false );
	}

	/**
	 * @testdox Should leave the card gateways alone when syncing gateways for $seller who wants cards (wallet).
	 * @testWith ["a casual seller", false]
	 *           ["a business seller", true]
	 *
	 * @param string $seller             The seller kind, for the test name.
	 * @param bool   $is_business_seller Whether the seller is a business seller.
	 */
	public function test_card_gateways_are_not_toggled_by_gateway_sync( string $seller, bool $is_business_seller ): void {
		unset( $seller );
		$toggled_states = $this->record_toggles();

		$flags                     = new ConfigurationFlagsDTO();
		$flags->is_business_seller = $is_business_seller;
		$flags->use_card_payments  = true;
		$this->toggle_payment_gateways( $flags );

		$this->assertArrayNotHasKey( 'ppcp-credit-card-gateway', $toggled_states->getArrayCopy() );
		$this->assertArrayNotHasKey( 'ppcp-card-button-gateway', $toggled_states->getArrayCopy() );
	}

	/**
	 * Run toggle_payment_gateways() for the given seller and return the calls the local payment methods hook received.
	 *
	 * @param bool $is_business_seller Whether the merchant is a business seller.
	 * @return \ArrayObject One entry per call, holding the hook arguments.
	 */
	private function toggle_and_record_apm_hook( bool $is_business_seller ): \ArrayObject {
		$this->record_toggles();
		$calls = new \ArrayObject();
		add_action(
			'woocommerce_paypal_payments_toggle_payment_gateways_apms',
			static function () use ( $calls ): void {
				$calls[] = func_get_args();
			},
			10,
			3
		);

		$flags                     = new ConfigurationFlagsDTO();
		$flags->is_business_seller = $is_business_seller;
		$this->toggle_payment_gateways( $flags );

		return $calls;
	}

	/**
	 * @testdox Should keep firing the local payment methods hook for a business seller, with the payment methods model, the method list and the flags (wallet).
	 */
	public function test_apm_toggle_hook_is_fired_for_a_business_seller(): void {
		$calls = $this->toggle_and_record_apm_hook( true );

		$this->assertCount( 1, $calls );
		$this->assertSame( $this->payment_methods, $calls[0][0] );
		$this->assertIsArray( $calls[0][1] );
		$this->assertInstanceOf( ConfigurationFlagsDTO::class, $calls[0][2] );
	}

	/**
	 * @testdox Should not fire the local payment methods hook for a casual seller (wallet).
	 */
	public function test_apm_toggle_hook_is_not_fired_for_a_casual_seller(): void {
		$this->assertCount( 0, $this->toggle_and_record_apm_hook( false ) );
	}

	/**
	 * Drive the protected apply_location_styles() and return the styles it saved.
	 *
	 * @return array<string, object> The location styling DTOs by location.
	 */
	private function applied_location_styles(): array {
		$saved = array();
		$this->styling_settings->shouldReceive( 'from_array' )->once()->andReturnUsing(
			static function ( array $styles ) use ( &$saved ): void {
				$saved = $styles;
			}
		);
		$this->styling_settings->shouldReceive( 'save' )->once();

		$method = new ReflectionMethod( SettingsDataManager::class, 'apply_location_styles' );
		$method->setAccessible( true );
		$method->invoke( $this->sut, new ConfigurationFlagsDTO() );

		return $saved;
	}

	/**
	 * @testdox Should start every location with PayPal, Venmo and Pay Later among its methods (wallet).
	 */
	public function test_default_location_styles_list_the_paypal_venmo_and_pay_later_methods(): void {
		$styles = $this->applied_location_styles();

		$this->assertEqualsCanonicalizing( array( 'cart', 'classic_checkout', 'express_checkout', 'mini_cart', 'product' ), array_keys( $styles ) );
		foreach ( $styles as $location => $style ) {
			foreach ( array( PayPalGateway::ID, 'venmo', 'pay-later' ) as $method_id ) {
				$this->assertContains( $method_id, $style->methods, "$location should list $method_id" );
			}
		}
		$this->assertSame( array( PayPalGateway::ID, 'venmo', 'pay-later' ), $styles['product']->methods );
	}
}
