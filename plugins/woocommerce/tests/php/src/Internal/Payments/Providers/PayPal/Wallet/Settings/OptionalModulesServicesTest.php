<?php
/**
 * Tests for the settings and gateway wiring without the optional modules (ported from the extension's
 * OptionalModulesServicesTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\MessagesApply;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ModuleAvailability;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition\FeaturesDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition\PaymentMethodsDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition\TodosDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\PaymentSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsModel;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\TodosModel;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\FeaturesEligibilityService;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\PaymentMethodsEligibilityService;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\TodosEligibilityService;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\SubscriptionHelper;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Mockery\MockInterface;
use RuntimeException;

/**
 * The settings and gateway wiring must resolve when the optional modules (Fastlane, local APMs,
 * order tracking, PayPal Subscriptions) are not loaded: no service-not-found, and every optional feature reads as not
 * eligible.
 *
 * Only the four settings and gateway wiring cases are ported. The extension's fifth case (the webhook handler list
 * without PayPal Subscriptions) belongs to the webhooks module, which no cut touches. The extension's card capability
 * assertion is gone with the cards cut.
 *
 * @group paypal-wallet
 */
class OptionalModulesServicesTest extends WalletTestCase {

	/**
	 * A container that serves only what the wallet modules register, plus 'ppcp.module-availability' built on it.
	 *
	 * @param array<string, mixed> $services The services by ID.
	 * @return ContainerInterface&MockInterface
	 */
	private function wallet_only_container( array $services ) {
		$container = $this->mock( ContainerInterface::class );

		$services['ppcp.module-availability'] = new ModuleAvailability( $container );

		$container->shouldReceive( 'has' )->andReturnUsing(
			static function ( string $id ) use ( $services ): bool {
				return array_key_exists( $id, $services );
			}
		);
		$container->shouldReceive( 'get' )->andReturnUsing(
			static function ( string $id ) use ( $services ) {
				if ( ! array_key_exists( $id, $services ) ) {
					throw new RuntimeException( "Service $id not found" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				}

				return $services[ $id ];
			}
		);

		return $container;
	}

	/**
	 * The services of the given wallet module.
	 *
	 * @param string $module The module directory under Wallet/, for example "Settings".
	 * @return array<string, callable>
	 */
	private function module_services( string $module ): array {
		return require WC_ABSPATH . "src/Internal/Payments/Providers/PayPal/Wallet/$module/services.php";
	}

	/**
	 * The features eligibility service the Settings module builds without the optional modules.
	 *
	 * @return FeaturesEligibilityService
	 */
	private function features_eligibility_service(): FeaturesEligibilityService {
		$messages_apply = $this->mock( MessagesApply::class );
		$messages_apply->shouldReceive( 'for_country' )->andReturn( true );
		$container = $this->wallet_only_container(
			array(
				'button.helper.messages-apply'  => $messages_apply,
				'save-payment-methods.eligible' => true,
				'api.merchant.country'          => 'US',
			)
		);

		$service = $this->module_services( 'Settings' )['settings.service.features_eligibilities']( $container );
		$this->assertInstanceOf( FeaturesEligibilityService::class, $service );

		return $service;
	}

	/**
	 * @testdox Should resolve the features eligibility service without optional modules and offer only PayPal and Venmo vaulting (wallet).
	 */
	public function test_features_eligibility_resolves_without_optional_modules(): void {
		$checks = $this->features_eligibility_service()->get_eligibility_checks();

		$this->assertTrue( $checks[ FeaturesDefinition::FEATURE_SAVE_PAYPAL_AND_VENMO ]() );
		$this->assertTrue( $checks[ FeaturesDefinition::FEATURE_PAY_LATER_MESSAGING ]() );
	}

	/**
	 * @testdox Should resolve the payment methods eligibility service without optional modules (wallet).
	 */
	public function test_payment_methods_eligibility_resolves_without_optional_modules(): void {
		$container = $this->wallet_only_container(
			array(
				'api.merchant.country' => 'US',
			)
		);

		$service = $this->module_services( 'Settings' )['settings.service.payment_methods_eligibilities']( $container );

		$this->assertInstanceOf( PaymentMethodsEligibilityService::class, $service );
		$this->assertTrue( $service->get_eligibility_checks()['venmo']() );
	}

	/**
	 * @testdox Should resolve the payment methods definition without Fastlane (wallet).
	 */
	public function test_methods_definition_resolves_without_fastlane(): void {
		$container = $this->wallet_only_container(
			array(
				'settings.data.payment' => $this->mock( PaymentSettings::class ),
				'settings.data.general' => $this->mock( GeneralSettings::class ),
			)
		);

		$definition = $this->module_services( 'Settings' )['settings.data.definition.methods']( $container );

		$this->assertInstanceOf( PaymentMethodsDefinition::class, $definition );
	}

	/**
	 * The feature eligibility list the gateway module builds without the optional modules.
	 *
	 * @return array<string, callable>
	 */
	private function gateway_feature_eligibility_list(): array {
		$container = $this->wallet_only_container(
			array(
				'save-payment-methods.eligibility.check' => static fn(): bool => true,
				'wcgateway.contact-module.eligibility.check' => static fn(): bool => false,
			)
		);

		return $this->module_services( 'WcGateway' )['wcgateway.feature-eligibility.list']( $container );
	}

	/**
	 * @testdox Should build a feature eligibility list of callables without optional modules (wallet).
	 */
	public function test_gateway_feature_eligibility_list_resolves_without_optional_modules(): void {
		$list = $this->gateway_feature_eligibility_list();

		$this->assertNotEmpty( $list );
		foreach ( $list as $feature => $check ) {
			$this->assertIsCallable( $check, "$feature must be a callable" );
		}
		$this->assertTrue( $list[ FeaturesDefinition::FEATURE_SAVE_PAYPAL_AND_VENMO ]() );
	}

	/**
	 * The todos eligibility service the Settings module builds, from inputs that give every positional boolean a
	 * known value, so a shifted argument in the real services file changes the result.
	 *
	 * @return TodosEligibilityService
	 */
	private function todos_eligibility_service(): TodosEligibilityService {
		delete_option( 'woocommerce_ppcp-recaptcha_settings' );

		$general = $this->mock( GeneralSettings::class );
		$general->shouldReceive( 'get_merchant_country' )->andReturn( 'MX' );
		$settings = $this->mock( SettingsModel::class );
		$settings->shouldReceive( 'get_stay_updated' )->andReturn( true );
		$subscriptions = $this->mock( SubscriptionHelper::class );
		$subscriptions->shouldReceive( 'plugin_is_active' )->andReturn( true );

		$container = $this->wallet_only_container(
			array(
				'settings.service.pay_later_status'      => array(
					'statuses'                    => array(
						'product'  => true,
						'cart'     => false,
						'checkout' => true,
					),
					'is_enabled_for_any_location' => false,
				),
				'settings.service.button_locations'      => array(
					'cart_enabled'           => true,
					'block_checkout_enabled' => false,
					'product_enabled'        => true,
				),
				'settings.service.gateways_status'       => array( FeaturesDefinition::FEATURE_PAY_WITH_CRYPTO => false ),
				'settings.service.merchant_capabilities' => array(
					FeaturesDefinition::FEATURE_INSTALLMENTS => false,
					FeaturesDefinition::FEATURE_PAY_WITH_CRYPTO => true,
				),
				'settings.data.settings'                 => $settings,
				'settings.data.general'                  => $general,
				'button.helper.messages-apply'           => $this->mock( MessagesApply::class ),
				'save-payment-methods.eligible'          => false,
				'wc-subscriptions.helper'                => $subscriptions,
				'ppcp-local-apms.pwc.eligibility.check'  => static fn(): bool => true,
			)
		);

		$service = $this->module_services( 'Settings' )['settings.service.todos_eligibilities']( $container );
		$this->assertInstanceOf( TodosEligibilityService::class, $service );

		return $service;
	}

	/**
	 * @testdox Should wire every todo eligibility to its own input and offer no Apple Pay or Google Pay todo (wallet).
	 */
	public function test_todos_eligibility_wiring_has_no_digital_wallet_todo_and_no_shifted_argument(): void {
		$checks = array_map(
			static fn( callable $check ): bool => (bool) $check(),
			$this->todos_eligibility_service()->get_eligibility_checks()
		);

		$this->assertSame(
			array(
				'enable_fastlane'                      => false,
				'enable_pay_later_messaging'           => false,
				'add_pay_later_messaging_product_page' => false,
				'add_pay_later_messaging_cart'         => true,
				'add_pay_later_messaging_checkout'     => false,
				'configure_paypal_subscription'        => true,
				'add_paypal_buttons_cart'              => false,
				'add_paypal_buttons_block_checkout'    => true,
				'add_paypal_buttons_product'           => false,
				'enable_installments'                  => true,
				'apply_for_working_capital'            => false,
				'enable_pwc'                           => true,
				'apply_for_pwc'                        => false,
				'enable_recaptcha_protection'          => true,
			),
			$checks
		);

		foreach ( array_keys( $checks ) as $todo_id ) {
			$this->assertDoesNotMatchRegularExpression( '/apple|google|digital_wallets/', $todo_id );
		}
	}

	/**
	 * @testdox Should resolve the todos definition with the kept todos and no Apple Pay or Google Pay todo (wallet).
	 */
	public function test_todos_definition_lists_only_the_kept_todos(): void {
		$container = $this->wallet_only_container(
			array(
				'settings.service.todos_eligibilities' => $this->todos_eligibility_service(),
				'settings.data.general'                => $this->mock( GeneralSettings::class ),
				'settings.data.todos'                  => $this->mock( TodosModel::class ),
			)
		);

		$definition = $this->module_services( 'Settings' )['settings.data.definition.todos']( $container );
		$this->assertInstanceOf( TodosDefinition::class, $definition );

		$todo_ids = array_keys( $definition->get() );

		$this->assertEqualsCanonicalizing(
			array(
				'enable_fastlane',
				'enable_pay_later_messaging',
				'add_pay_later_messaging_product_page',
				'add_pay_later_messaging_cart',
				'add_pay_later_messaging_checkout',
				'configure_paypal_subscription',
				'add_paypal_buttons_cart',
				'add_paypal_buttons_block_checkout',
				'add_paypal_buttons_product',
				'enable_installments',
				'apply_for_working_capital',
				'enable_pwc',
				'apply_for_pwc',
				'enable_recaptcha_protection',
				'check_settings_after_migration',
			),
			$todo_ids
		);
	}
}
