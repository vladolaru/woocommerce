<?php
/**
 * Tests for the settings and gateway wiring without the optional modules.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\MessagesApply;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition\FeaturesDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition\PaymentMethodsDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition\TodosDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\PaymentSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\TodosModel;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\FeaturesEligibilityService;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\PaymentMethodsEligibilityService;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\TodosEligibilityService;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Mockery\MockInterface;
use RuntimeException;

/**
 * The settings and gateway wiring must resolve when the extension's optional modules are not loaded: no
 * service-not-found, and every optional feature reads as not eligible.
 *
 * The webhook handler list is covered in `WebhookHandlersServicesTest`, next to the webhooks module.
 *
 * @group paypal-wallet
 */
class OptionalModulesServicesTest extends WalletTestCase {

	/**
	 * A container that serves only what the wallet modules register.
	 *
	 * @param array<string, mixed> $services The services by ID.
	 * @return ContainerInterface&MockInterface
	 */
	private function wallet_only_container( array $services ) {
		$container = $this->mock( ContainerInterface::class );

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
	 * @testdox Should resolve the features eligibility service without optional modules and offer only PayPal and Venmo vaulting.
	 */
	public function test_features_eligibility_resolves_without_optional_modules(): void {
		$checks = $this->features_eligibility_service()->get_eligibility_checks();

		$this->assertTrue( $checks[ FeaturesDefinition::FEATURE_SAVE_PAYPAL_AND_VENMO ]() );
		$this->assertTrue( $checks[ FeaturesDefinition::FEATURE_PAY_LATER_MESSAGING ]() );
	}

	/**
	 * @testdox Should resolve the payment methods eligibility service without optional modules.
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
	 * @testdox Should resolve the payment methods definition without optional modules.
	 */
	public function test_methods_definition_resolves_without_optional_modules(): void {
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
	 * @testdox Should build a feature eligibility list of callables without optional modules.
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
	 * The inputs that give every positional boolean of the todos eligibility service a known value, so a shifted argument
	 * in the real services file changes the result. The defaults are the first scenario of `data_todo_wirings()`.
	 *
	 * @param array<string, mixed> $overrides Inputs to replace: `country`, `any_pay_later`, `pay_later`,
	 *                                        `buttons` and `installments`.
	 * @return array<string, mixed>
	 */
	private function todo_inputs( array $overrides = array() ): array {
		return array_merge(
			array(
				'country'       => 'MX',
				'any_pay_later' => false,
				'pay_later'     => array(
					'product'  => true,
					'cart'     => false,
					'checkout' => true,
				),
				'buttons'       => array(
					'cart_enabled'           => true,
					'block_checkout_enabled' => false,
					'product_enabled'        => true,
				),
				'installments'  => false,
			),
			$overrides
		);
	}

	/**
	 * The todos eligibility service the Settings module builds, from the given inputs.
	 *
	 * @param array<string, mixed> $inputs The inputs, as returned by `todo_inputs()`.
	 * @return TodosEligibilityService
	 */
	private function todos_eligibility_service( array $inputs = array() ): TodosEligibilityService {
		$inputs  = $this->todo_inputs( $inputs );
		$general = $this->mock( GeneralSettings::class );
		$general->shouldReceive( 'get_merchant_country' )->andReturn( $inputs['country'] );

		$container = $this->wallet_only_container(
			array(
				'settings.service.pay_later_status'      => array(
					'statuses'                    => $inputs['pay_later'],
					'is_enabled_for_any_location' => $inputs['any_pay_later'],
				),
				'settings.service.button_locations'      => $inputs['buttons'],
				'settings.service.merchant_capabilities' => array(
					FeaturesDefinition::FEATURE_INSTALLMENTS => $inputs['installments'],
				),
				'settings.data.general'                  => $general,
				'button.helper.messages-apply'           => $this->mock( MessagesApply::class ),
			)
		);

		$service = $this->module_services( 'Settings' )['settings.service.todos_eligibilities']( $container );
		$this->assertInstanceOf( TodosEligibilityService::class, $service );

		return $service;
	}

	/**
	 * Three scenarios, so that each of the nine positions is seen both true and false and no shifted argument can pass.
	 *
	 * @return array<string, array{array<string, mixed>, array<string, bool>}>
	 */
	public function data_todo_wirings(): array {
		return array(
			'Mexico, partly enabled'                 => array(
				array(),
				array(
					'enable_pay_later_messaging'           => false,
					'add_pay_later_messaging_product_page' => false,
					'add_pay_later_messaging_cart'         => true,
					'add_pay_later_messaging_checkout'     => false,
					'add_paypal_buttons_cart'              => false,
					'add_paypal_buttons_block_checkout'    => true,
					'add_paypal_buttons_product'           => false,
					'enable_installments'                  => true,
					'apply_for_working_capital'            => false,
				),
			),
			'United States, the opposite locations'  => array(
				$this->todo_inputs(
					array(
						'country'      => 'US',
						'pay_later'    => array(
							'product'  => false,
							'cart'     => true,
							'checkout' => false,
						),
						'buttons'      => array(
							'cart_enabled'           => false,
							'block_checkout_enabled' => true,
							'product_enabled'        => false,
						),
						'installments' => true,
					)
				),
				array(
					'enable_pay_later_messaging'           => false,
					'add_pay_later_messaging_product_page' => true,
					'add_pay_later_messaging_cart'         => false,
					'add_pay_later_messaging_checkout'     => true,
					'add_paypal_buttons_cart'              => true,
					'add_paypal_buttons_block_checkout'    => false,
					'add_paypal_buttons_product'           => true,
					'enable_installments'                  => false,
					'apply_for_working_capital'            => true,
				),
			),
			'Pay Later messaging on at one location' => array(
				$this->todo_inputs( array( 'any_pay_later' => true ) ),
				array(
					'enable_pay_later_messaging'           => true,
					'add_pay_later_messaging_product_page' => false,
					'add_pay_later_messaging_cart'         => false,
					'add_pay_later_messaging_checkout'     => false,
					'add_paypal_buttons_cart'              => false,
					'add_paypal_buttons_block_checkout'    => true,
					'add_paypal_buttons_product'           => false,
					'enable_installments'                  => true,
					'apply_for_working_capital'            => false,
				),
			),
		);
	}

	/**
	 * @testdox Should wire every todo eligibility to its own input and offer no Apple Pay or Google Pay todo.
	 * @dataProvider data_todo_wirings
	 *
	 * @param array<string, mixed> $inputs   The inputs of the scenario.
	 * @param array<string, bool>  $expected The eligibility of every todo.
	 */
	public function test_todos_eligibility_wiring_has_no_digital_wallet_todo_and_no_shifted_argument( array $inputs, array $expected ): void {
		$checks = array_map(
			static fn( callable $check ): bool => (bool) $check(),
			$this->todos_eligibility_service( $inputs )->get_eligibility_checks()
		);

		$this->assertSame( $expected, $checks );

		foreach ( array_keys( $checks ) as $todo_id ) {
			$this->assertDoesNotMatchRegularExpression( '/apple|google|digital_wallets/', $todo_id );
		}
	}

	/**
	 * @testdox Should resolve the todos definition with the kept todos and no Apple Pay or Google Pay todo.
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
				'enable_pay_later_messaging',
				'add_pay_later_messaging_product_page',
				'add_pay_later_messaging_cart',
				'add_pay_later_messaging_checkout',
				'add_paypal_buttons_cart',
				'add_paypal_buttons_block_checkout',
				'add_paypal_buttons_product',
				'enable_installments',
				'apply_for_working_capital',
				'check_settings_after_migration',
			),
			$todo_ids
		);
	}
}
