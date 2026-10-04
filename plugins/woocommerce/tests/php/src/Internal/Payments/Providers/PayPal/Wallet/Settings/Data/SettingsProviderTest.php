<?php
/**
 * Tests for the settings provider (ported from the extension's SettingsProviderTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Data
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Data;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\OnboardingProfile;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\PayLaterMessagingSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\PaymentSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsModel;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\StylingSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\DTO\LocationStylingDTO;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\DTO\MerchantConnectionDTO;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Every convenience method of the provider delegates to the right model, and the two rules the provider adds itself.
 *
 * The delegation rows of the card methods the cards cut removed are gone with them (the models keep their accessors).
 *
 * The `show_fastlane_watermark` row went with the Fastlane cut (the provider method and the model getter are gone).
 *
 * @group paypal-wallet
 */
class SettingsProviderTest extends WalletTestCase {

	private const EXPECTED_VALUE_STRING = 'EXPECTED_VALUE';
	private const EXPECTED_VALUE_BOOL   = true;
	private const EXPECTED_VALUE_ARRAY  = array();
	private const EXPECTED_VALUE_INT    = 2;

	/**
	 * The system under test.
	 *
	 * @var SettingsProvider
	 */
	private $provider;

	/**
	 * The general settings model mock.
	 *
	 * @var mixed
	 */
	private GeneralSettings $general_settings;
	/**
	 * The onboarding profile mock.
	 *
	 * @var mixed
	 */
	private OnboardingProfile $onboarding_profile;
	/**
	 * The payment settings model mock.
	 *
	 * @var mixed
	 */
	private PaymentSettings $payment_settings;
	/**
	 * The settings model mock.
	 *
	 * @var mixed
	 */
	private SettingsModel $settings_model;
	/**
	 * The styling settings model mock.
	 *
	 * @var mixed
	 */
	private StylingSettings $styling_settings;
	/**
	 * The Pay Later messaging settings model mock.
	 *
	 * @var mixed
	 */
	private PayLaterMessagingSettings $paylater_messaging_settings;

	/**
	 * Build the provider over mocked models.
	 */
	public function setUp(): void {
		parent::setUp();
		// The DTO keeps the extension's class name and is aliased into the wallet namespace by this loader at boot.
		require_once WC_ABSPATH . 'src/Internal/Payments/Providers/PayPal/Wallet/SerializedClasses/load.php';

		$this->general_settings            = $this->mock( GeneralSettings::class );
		$this->onboarding_profile          = $this->mock( OnboardingProfile::class );
		$this->payment_settings            = $this->mock( PaymentSettings::class );
		$this->settings_model              = $this->mock( SettingsModel::class );
		$this->styling_settings            = $this->mock( StylingSettings::class );
		$this->paylater_messaging_settings = $this->mock( PayLaterMessagingSettings::class );

		$this->provider = new SettingsProvider(
			$this->general_settings,
			$this->onboarding_profile,
			$this->payment_settings,
			$this->settings_model,
			$this->styling_settings,
			$this->paylater_messaging_settings
		);
	}

	/**
	 * Test SettingsProvider delegates the calls to the model.
	 *
	 * @dataProvider settings_method_provider
	 *
	 * @testdox Should delegate $provider_method to $model::$model_method.
	 *
	 * @param string $provider_method The method name in the SettingsProvider class.
	 * @param string $model_method    The method name of the mocked model.
	 * @param mixed  $expected_value  The expected return value of the mocked model method.
	 * @param string $model           The model mapped in the provider.
	 */
	public function test_settings_method_delegation(
		string $provider_method,
		string $model_method,
		$expected_value,
		string $model
	): void {
		// Access the mocked model, for example $this->general_settings.
		$target_mock = $this->$model;

		// The model should receive the model method call and return the expected value.
		$target_mock->shouldReceive( $model_method )->andReturn( $expected_value );

		// Call the method in the provider class.
		$result = $this->provider->$provider_method();

		$this->assertSame( $expected_value, $result );
	}

	/**
	 * Data provider for SettingsProvider class.
	 */
	public function settings_method_provider(): array {
		return array_merge(
			$this->get_model_data( $this->get_general_settings_data(), 'general_settings' ),
			$this->get_model_data( $this->get_onboarding_profile_data(), 'onboarding_profile' ),
			$this->get_model_data( $this->get_payment_settings_data(), 'payment_settings' ),
			$this->get_model_data( $this->get_settings_model_data(), 'settings_model' ),
			$this->get_model_data( $this->get_styling_settings_data(), 'styling_settings' ),
			$this->get_model_data( $this->get_paylater_messaging_settings_data(), 'paylater_messaging_settings' ),
		);
	}

	/**
	 * Attach a model into the test data.
	 *
	 * @param array  $data  The test rows.
	 * @param string $model The model name.
	 *
	 * @return array
	 */
	private function get_model_data( array $data, string $model ): array {
		return array_map(
			function ( array $method_data ) use ( $model ) {
				$method_data['model'] = $model;

				return $method_data;
			},
			$data
		);
	}

	/**
	 * Test data for the GeneralSettings model.
	 * @return array
	 * @see GeneralSettings
	 */
	private function get_general_settings_data(): array {
		return array(
			array(
				'provider_method' => 'use_sandbox',
				'model_method'    => 'get_sandbox',
				'expected_value'  => self::EXPECTED_VALUE_BOOL,
			),
			array(
				'provider_method' => 'woo_settings',
				'model_method'    => 'get_woo_settings',
				'expected_value'  => self::EXPECTED_VALUE_ARRAY,
			),
			array(
				'provider_method' => 'merchant_data',
				'model_method'    => 'get_merchant_data',
				'expected_value'  => new MerchantConnectionDTO( true, '', '', '' ),
			),
			array(
				'provider_method' => 'sandbox_merchant',
				'model_method'    => 'is_sandbox_merchant',
				'expected_value'  => self::EXPECTED_VALUE_BOOL,
			),
			array(
				'provider_method' => 'merchant_connected',
				'model_method'    => 'is_merchant_connected',
				'expected_value'  => self::EXPECTED_VALUE_BOOL,
			),
			array(
				'provider_method' => 'business_seller',
				'model_method'    => 'is_business_seller',
				'expected_value'  => self::EXPECTED_VALUE_BOOL,
			),
			array(
				'provider_method' => 'casual_seller',
				'model_method'    => 'is_casual_seller',
				'expected_value'  => self::EXPECTED_VALUE_BOOL,
			),
			array(
				'provider_method' => 'merchant_id',
				'model_method'    => 'get_merchant_id',
				'expected_value'  => self::EXPECTED_VALUE_STRING,
			),
			array(
				'provider_method' => 'merchant_email',
				'model_method'    => 'get_merchant_email',
				'expected_value'  => self::EXPECTED_VALUE_STRING,
			),
			array(
				'provider_method' => 'merchant_country',
				'model_method'    => 'get_merchant_country',
				'expected_value'  => self::EXPECTED_VALUE_STRING,
			),
			array(
				'provider_method' => 'own_brand_only',
				'model_method'    => 'own_brand_only',
				'expected_value'  => self::EXPECTED_VALUE_BOOL,
			),
			array(
				'provider_method' => 'installation_path',
				'model_method'    => 'get_installation_path',
				'expected_value'  => self::EXPECTED_VALUE_STRING,
			),
		);
	}

	/**
	 * Test data for the OnboardingProfile model.
	 * @return array
	 * @see OnboardingProfile
	 */
	private function get_onboarding_profile_data(): array {
		return array(
			array(
				'provider_method' => 'onboarding_completed',
				'model_method'    => 'get_completed',
				'expected_value'  => self::EXPECTED_VALUE_BOOL,
			),
			array(
				'provider_method' => 'onboarding_step',
				'model_method'    => 'get_step',
				'expected_value'  => self::EXPECTED_VALUE_INT,
			),
			array(
				'provider_method' => 'products',
				'model_method'    => 'get_products',
				'expected_value'  => self::EXPECTED_VALUE_ARRAY,
			),
			array(
				'provider_method' => 'flags',
				'model_method'    => 'get_flags',
				'expected_value'  => self::EXPECTED_VALUE_ARRAY,
			),
			array(
				'provider_method' => 'setup_done',
				'model_method'    => 'is_setup_done',
				'expected_value'  => self::EXPECTED_VALUE_BOOL,
			),
			array(
				'provider_method' => 'gateways_synced',
				'model_method'    => 'is_gateways_synced',
				'expected_value'  => self::EXPECTED_VALUE_BOOL,
			),
			array(
				'provider_method' => 'gateways_refreshed',
				'model_method'    => 'is_gateways_refreshed',
				'expected_value'  => self::EXPECTED_VALUE_BOOL,
			),
		);
	}

	/**
	 * Test data for the PaymentSettings model.
	 * @return array
	 * @see PaymentSettings
	 */
	private function get_payment_settings_data(): array {
		return array(
			array(
				'provider_method' => 'show_paypal_logo',
				'model_method'    => 'get_paypal_show_logo',
				'expected_value'  => self::EXPECTED_VALUE_BOOL,
			),
			array(
				'provider_method' => 'venmo_enabled',
				'model_method'    => 'get_venmo_enabled',
				'expected_value'  => self::EXPECTED_VALUE_BOOL,
			),
			array(
				'provider_method' => 'paylater_enabled',
				'model_method'    => 'get_paylater_enabled',
				'expected_value'  => self::EXPECTED_VALUE_BOOL,
			),
		);
	}

	/**
	 * Test data for the SettingsModel model.
	 * @return array
	 * @see SettingsModel
	 */
	private function get_settings_model_data(): array {
		return array(
			array(
				'provider_method' => 'invoice_prefix',
				'model_method'    => 'get_invoice_prefix',
				'expected_value'  => self::EXPECTED_VALUE_STRING,
			),
			array(
				'provider_method' => 'brand_name',
				'model_method'    => 'get_brand_name',
				'expected_value'  => self::EXPECTED_VALUE_STRING,
			),
			array(
				'provider_method' => 'soft_descriptor',
				'model_method'    => 'get_soft_descriptor',
				'expected_value'  => self::EXPECTED_VALUE_STRING,
			),
			array(
				'provider_method' => 'subtotal_adjustment',
				'model_method'    => 'get_subtotal_adjustment',
				'expected_value'  => self::EXPECTED_VALUE_STRING,
			),
			array(
				'provider_method' => 'landing_page',
				'model_method'    => 'get_landing_page',
				'expected_value'  => self::EXPECTED_VALUE_STRING,
			),
			array(
				'provider_method' => 'button_language',
				'model_method'    => 'get_button_language',
				'expected_value'  => self::EXPECTED_VALUE_STRING,
			),
			array(
				'provider_method' => 'authorize_only',
				'model_method'    => 'get_authorize_only',
				'expected_value'  => self::EXPECTED_VALUE_BOOL,
			),
			array(
				'provider_method' => 'capture_virtual_orders',
				'model_method'    => 'get_capture_virtual_orders',
				'expected_value'  => self::EXPECTED_VALUE_BOOL,
			),
			array(
				'provider_method' => 'save_paypal_and_venmo',
				'model_method'    => 'get_save_paypal_and_venmo',
				'expected_value'  => self::EXPECTED_VALUE_BOOL,
			),
			array(
				'provider_method' => 'instant_payments_only',
				'model_method'    => 'get_instant_payments_only',
				'expected_value'  => self::EXPECTED_VALUE_BOOL,
			),
			array(
				'provider_method' => 'enable_contact_module',
				'model_method'    => 'get_enable_contact_module',
				'expected_value'  => self::EXPECTED_VALUE_BOOL,
			),
			array(
				'provider_method' => 'enable_pay_now',
				'model_method'    => 'get_enable_pay_now',
				'expected_value'  => self::EXPECTED_VALUE_BOOL,
			),
			array(
				'provider_method' => 'enable_logging',
				'model_method'    => 'get_enable_logging',
				'expected_value'  => self::EXPECTED_VALUE_BOOL,
			),
			array(
				'provider_method' => 'stay_updated',
				'model_method'    => 'get_stay_updated',
				'expected_value'  => self::EXPECTED_VALUE_BOOL,
			),
		);
	}

	/**
	 * Test data for the StylingSettings model.
	 * @return array
	 * @see StylingSettings
	 */
	private function get_styling_settings_data(): array {
		// Data providers run before setUp(); the DTO is aliased into the wallet namespace by this loader.
		require_once WC_ABSPATH . 'src/Internal/Payments/Providers/PayPal/Wallet/SerializedClasses/load.php';

		$styling_dto = new LocationStylingDTO();

		return array(
			array(
				'provider_method' => 'styling_cart',
				'model_method'    => 'get_cart',
				'expected_value'  => $styling_dto,
			),
			array(
				'provider_method' => 'styling_classic_checkout',
				'model_method'    => 'get_classic_checkout',
				'expected_value'  => $styling_dto,
			),
			array(
				'provider_method' => 'styling_express_checkout',
				'model_method'    => 'get_express_checkout',
				'expected_value'  => $styling_dto,
			),
			array(
				'provider_method' => 'styling_mini_cart',
				'model_method'    => 'get_mini_cart',
				'expected_value'  => $styling_dto,
			),
			array(
				'provider_method' => 'styling_product',
				'model_method'    => 'get_product',
				'expected_value'  => $styling_dto,
			),
		);
	}

	/**
	 * Test data for the PayLaterMessagingSettings model.
	 * @return array
	 * @see PayLaterMessagingSettings
	 */
	private function get_paylater_messaging_settings_data(): array {
		return array(
			array(
				'provider_method' => 'pay_later_messaging_locations',
				'model_method'    => 'get_messaging_locations',
				'expected_value'  => self::EXPECTED_VALUE_ARRAY,
			),
		);
	}

	/**
	 * @testdox Should combine the stored capture-on-status-change value with the filter: stored $db_value, expected $expected.
	 * @dataProvider capture_on_status_change_cases
	 *
	 * @param bool      $db_value        The stored value.
	 * @param bool|null $filter_override What the filter returns, null for no override.
	 * @param bool      $expected        The expected answer.
	 */
	public function test_capture_on_status_change( bool $db_value, ?bool $filter_override, bool $expected ): void {
		$this->payment_settings
			->shouldReceive( 'get_capture_on_status_change' )
			->andReturn( $db_value );

		$received = array();
		add_filter(
			'woocommerce_paypal_payments_capture_on_status_change',
			static function ( $value ) use ( $filter_override, &$received ) {
				$received[] = $value;
				return $filter_override ?? $value;
			}
		);

		$result = $this->provider->capture_on_status_change();

		$this->assertSame( array( $db_value ), $received, 'The filter should receive the stored value, once.' );
		$this->assertSame( $expected, $result );
	}

	/**
	 * Stored value, filter override and the expectation.
	 *
	 * @return array
	 */
	public function capture_on_status_change_cases(): array {
		return array(
			'default'              => array(
				'db_value'        => true,
				'filter_override' => null,
				'expected'        => true,
			),
			'disable by migration' => array(
				'db_value'        => false,
				'filter_override' => null,
				'expected'        => false,
			),
			'disable by filter'    => array(
				'db_value'        => true,
				'filter_override' => false,
				'expected'        => false,
			),
			'enable by filter'     => array(
				'db_value'        => false,
				'filter_override' => true,
				'expected'        => true,
			),
		);
	}

	/**
	 * GIVEN a merchant connection with or without a client ID, and vaulting ("Save PayPal
	 *      and Venmo") on or off
	 * WHEN can_save_vault_token() is called
	 * THEN a vault token can only be saved when the merchant is connected (has a client ID)
	 *      and vaulting is enabled
	 *
	 * @testdox Should decide whether a vault token can be saved: client ID "$client_id", vaulting $save_paypal_and_venmo.
	 * @dataProvider can_save_vault_token_provider
	 *
	 * @param string $client_id             The merchant client ID.
	 * @param bool   $save_paypal_and_venmo Whether vaulting is on.
	 * @param bool   $expected              The expected answer.
	 */
	public function test_can_save_vault_token(
		string $client_id,
		bool $save_paypal_and_venmo,
		bool $expected
	): void {
		$this->general_settings
			->shouldReceive( 'get_merchant_data' )
			->andReturn( new MerchantConnectionDTO( true, $client_id, '', '' ) );

		$this->settings_model
			->shouldReceive( 'get_save_paypal_and_venmo' )
			->andReturn( $save_paypal_and_venmo );

		$this->assertSame( $expected, $this->provider->can_save_vault_token() );
	}

	/**
	 * Client ID, vaulting flag and the expectation.
	 *
	 * @return array
	 */
	public function can_save_vault_token_provider(): array {
		return array(
			'connected merchant, vaulting on'    => array(
				'client_id'             => 'client-id',
				'save_paypal_and_venmo' => true,
				'expected'              => true,
			),
			'connected merchant, vaulting off'   => array(
				'client_id'             => 'client-id',
				'save_paypal_and_venmo' => false,
				'expected'              => false,
			),
			'unconnected merchant, vaulting on'  => array(
				'client_id'             => '',
				'save_paypal_and_venmo' => true,
				'expected'              => false,
			),
			'unconnected merchant, vaulting off' => array(
				'client_id'             => '',
				'save_paypal_and_venmo' => false,
				'expected'              => false,
			),
		);
	}
}
