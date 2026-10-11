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
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\PaymentRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\FeaturesEligibilityService;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\PaymentMethodsEligibilityService;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\TodosEligibilityService;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Mockery\MockInterface;
use RuntimeException;
use WP_REST_Response;

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
	 * @param array<string, mixed> $extra_services Services to add, such as the Pay with Crypto eligibility check.
	 * @return FeaturesEligibilityService
	 */
	private function features_eligibility_service( array $extra_services = array() ): FeaturesEligibilityService {
		$messages_apply = $this->mock( MessagesApply::class );
		$messages_apply->shouldReceive( 'for_country' )->andReturn( true );
		$container = $this->wallet_only_container(
			array_merge(
				array(
					'button.helper.messages-apply'  => $messages_apply,
					'save-payment-methods.eligible' => true,
					'api.merchant.country'          => 'US',
				),
				$extra_services
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
		$this->assertFalse( $checks[ FeaturesDefinition::FEATURE_PAY_WITH_CRYPTO ](), 'Without the Pay with Crypto module the card is not eligible' );
	}

	/**
	 * @testdox Should make the Pay with Crypto card eligible from the Pay with Crypto module's eligibility check.
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $eligible What the module's eligibility check answers.
	 */
	public function test_features_eligibility_reads_the_pwc_check( bool $eligible ): void {
		$checks = $this->features_eligibility_service( array( 'ppcp-local-apms.pwc.eligibility.check' => $eligible ) )->get_eligibility_checks();

		$this->assertSame( $eligible, $checks[ FeaturesDefinition::FEATURE_PAY_WITH_CRYPTO ]() );
	}

	/**
	 * @testdox Should resolve the payment methods eligibility service without optional modules.
	 */
	public function test_payment_methods_eligibility_resolves_without_optional_modules(): void {
		$container = $this->wallet_only_container(
			array(
				'api.merchant.country'                   => 'US',
				'settings.service.merchant_capabilities' => array( FeaturesDefinition::FEATURE_PAY_WITH_CRYPTO => true ),
			)
		);

		$service = $this->module_services( 'Settings' )['settings.service.payment_methods_eligibilities']( $container );

		$this->assertInstanceOf( PaymentMethodsEligibilityService::class, $service );
		$this->assertTrue( $service->get_eligibility_checks()['venmo']() );
		$this->assertFalse( $service->get_eligibility_checks()['ppcp-pwc'](), 'Without the Pay with Crypto module the APMs are not eligible' );
	}

	/**
	 * @testdox Should offer Pay with Crypto from the merchant capability and the module's APM eligibility check.
	 * @testWith [true, true, true]
	 *           [false, true, false]
	 *           [true, false, false]
	 *
	 * @param bool $capability The merchant's Pay with Crypto capability.
	 * @param bool $apm        What the module's APM eligibility check answers.
	 * @param bool $expected   Whether Pay with Crypto is offered.
	 */
	public function test_payment_methods_eligibility_reads_the_pwc_capability_and_apm_check( bool $capability, bool $apm, bool $expected ): void {
		$container = $this->wallet_only_container(
			array(
				'api.merchant.country'                   => 'US',
				'settings.service.merchant_capabilities' => array( FeaturesDefinition::FEATURE_PAY_WITH_CRYPTO => $capability ),
				'ppcp-local-apms.eligibility.check'      => $apm,
			)
		);

		$service = $this->module_services( 'Settings' )['settings.service.payment_methods_eligibilities']( $container );

		$this->assertSame( $expected, $service->get_eligibility_checks()['ppcp-pwc']() );
	}

	/**
	 * @testdox Should read the Pay with Crypto gateway state from the payment settings endpoint.
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $enabled Whether the gateway is enabled in the payment settings.
	 */
	public function test_gateways_status_reads_the_pwc_gateway( bool $enabled ): void {
		$endpoint = $this->mock( PaymentRestEndpoint::class );
		$endpoint->shouldReceive( 'get_details' )->andReturn(
			new WP_REST_Response(
				array(
					'success' => true,
					'data'    => array( 'ppcp-pwc' => array( 'enabled' => $enabled ) ),
				)
			)
		);

		$status = $this->module_services( 'Settings' )['settings.service.gateways_status']( $this->wallet_only_container( array( 'settings.rest.payment' => $endpoint ) ) );

		$this->assertSame( array( 'pwc' => $enabled ), $status );
	}

	/**
	 * @testdox Should read the Pay with Crypto capability into the merchant capabilities.
	 */
	public function test_merchant_capabilities_carry_the_pwc_capability(): void {
		remove_all_filters( 'woocommerce_paypal_payments_rest_common_merchant_features' );
		add_filter(
			'woocommerce_paypal_payments_rest_common_merchant_features',
			static fn(): array => array( FeaturesDefinition::FEATURE_PAY_WITH_CRYPTO => array( 'enabled' => true ) )
		);

		$capabilities = $this->module_services( 'Settings' )['settings.service.merchant_capabilities']( $this->wallet_only_container( array() ) );

		$this->assertTrue( $capabilities[ FeaturesDefinition::FEATURE_PAY_WITH_CRYPTO ] );
		$this->assertFalse( $capabilities[ FeaturesDefinition::FEATURE_INSTALLMENTS ] );
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
	 *                                        `buttons`, `installments`, `pwc` (the capability), `pwc_gateway`
	 *                                        (the gateway's enabled state) and `pwc_check` (the module's
	 *                                        eligibility check, or null when the module is absent).
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
				'pwc'           => true,
				'pwc_gateway'   => false,
				'pwc_check'     => true,
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

		$services = array(
			'settings.service.pay_later_status'      => array(
				'statuses'                    => $inputs['pay_later'],
				'is_enabled_for_any_location' => $inputs['any_pay_later'],
			),
			'settings.service.button_locations'      => $inputs['buttons'],
			'settings.service.gateways_status'       => array( 'pwc' => $inputs['pwc_gateway'] ),
			'settings.service.merchant_capabilities' => array(
				FeaturesDefinition::FEATURE_INSTALLMENTS => $inputs['installments'],
				FeaturesDefinition::FEATURE_PAY_WITH_CRYPTO => $inputs['pwc'],
			),
			'settings.data.general'                  => $general,
			'button.helper.messages-apply'           => $this->mock( MessagesApply::class ),
		);
		if ( null !== $inputs['pwc_check'] ) {
			$services['ppcp-local-apms.pwc.eligibility.check'] = $inputs['pwc_check'];
		}

		$container = $this->wallet_only_container( $services );

		$service = $this->module_services( 'Settings' )['settings.service.todos_eligibilities']( $container );
		$this->assertInstanceOf( TodosEligibilityService::class, $service );

		return $service;
	}

	/**
	 * Five scenarios, so that each of the ten positions is seen both true and false and no shifted argument can pass. The
	 * last three switch off one input of the Pay with Crypto to-do each.
	 *
	 * @return array<string, array{array<string, mixed>, array<string, bool>}>
	 */
	public function data_todo_wirings(): array {
		return array(
			'Mexico, partly enabled'                     => array(
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
					'enable_pwc'                           => true,
				),
			),
			'United States, the opposite locations'      => array(
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
						'pwc_gateway'  => true,
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
					'enable_pwc'                           => false,
				),
			),
			'Pay Later messaging on at one location'     => array(
				$this->todo_inputs(
					array(
						'any_pay_later' => true,
						'pwc'           => false,
					)
				),
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
					'enable_pwc'                           => false,
				),
			),
			'Pay with Crypto not eligible for the store' => array(
				$this->todo_inputs( array( 'pwc_check' => false ) ),
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
					'enable_pwc'                           => false,
				),
			),
			'Without the Pay with Crypto module'         => array(
				$this->todo_inputs( array( 'pwc_check' => null ) ),
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
					'enable_pwc'                           => false,
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
				'enable_pwc',
				'check_settings_after_migration',
			),
			$todo_ids
		);
	}
}
