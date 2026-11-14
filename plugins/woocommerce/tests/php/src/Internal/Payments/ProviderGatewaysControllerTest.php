<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\ProviderGatewaysController;
use Automattic\WooCommerce\Internal\Payments\PaymentGatewayProviderInterface;
use WC_Payment_Gateway;
use WC_Unit_Test_Case;

/**
 * Tests for the ProviderGatewaysController class.
 */
class ProviderGatewaysControllerTest extends WC_Unit_Test_Case {

	/**
	 * @testdox A lazy provider is built once, on the first gateway list build its check passes for, and only after a passing check at registration added the hook (registration passes: $passes_at_registration; builds: $build_checks).
	 * @testWith [true, [true, true], true, [1, 1], [1, 1]]
	 *           [true, [true, false], true, [1, 0], [1, 1]]
	 *           [false, [true], false, [0], [0]]
	 *           [true, [false, true], true, [0, 1], [0, 1]]
	 *
	 * @param bool   $passes_at_registration Whether the check passes when the controller registers.
	 * @param bool[] $build_checks           Whether the check passes at each later gateway list build.
	 * @param bool   $expect_hook            Whether registration adds the gateway list hook.
	 * @param int[]  $expected_gateways      Number of gateways each build adds.
	 * @param int[]  $expected_builds        Number of provider builds after each gateway list build.
	 */
	public function test_resolves_a_lazy_provider_once_when_the_gateway_list_is_built( bool $passes_at_registration, array $build_checks, bool $expect_hook, array $expected_gateways, array $expected_builds ): void {
		$gateway = $this->create_gateway( 'woocommerce_payments' );
		$passes  = $passes_at_registration;
		$calls   = 0;
		$sut     = new ProviderGatewaysController();
		$sut->set_provider(
			static function () use ( &$calls, $gateway ): PaymentGatewayProviderInterface {
				++$calls;
				return new StaticProvider( true, array( $gateway ) );
			},
			static function () use ( &$passes ): bool {
				return $passes;
			}
		);

		$sut->register();
		$this->assertSame( $expect_hook, (bool) has_filter( 'woocommerce_payment_gateways', array( $sut, 'add_provider_gateways' ) ) );
		$this->assertSame( 0, $calls, 'Registering the gateway hook must not build the provider.' );

		foreach ( $build_checks as $build => $check ) {
			$passes = $check;
			$this->assertSame( 1 === $expected_gateways[ $build ] ? array( $gateway ) : array(), $this->apply_payment_gateways_filter(), "Unexpected gateways at build {$build}." );
			$this->assertSame( $expected_builds[ $build ], $calls, "Unexpected provider builds after build {$build}." );
		}
	}

	/**
	 * @testdox Should register the provider's gateways when the built-in runtime owns the site, without asking whether the provider can process payments ($can_process_payments).
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $can_process_payments Whether the provider can process payments.
	 */
	public function test_registers_provider_gateways_without_consulting_provider_readiness( bool $can_process_payments ): void {
		$gateway  = $this->create_gateway( 'woocommerce_payments' );
		$provider = new StaticProvider( $can_process_payments, array( $gateway ) );
		$sut      = new ProviderGatewaysController();
		$sut->set_provider( static fn(): PaymentGatewayProviderInterface => $provider, static fn(): bool => true );

		$sut->register();

		$this->assertSame( 10, has_filter( 'woocommerce_payment_gateways', array( $sut, 'add_provider_gateways' ) ) );
		$this->assertSame( array( $gateway ), $this->apply_payment_gateways_filter() );
		$this->assertSame( 0, $provider->can_process_payments_calls, 'Gateway registration must not consult provider readiness.' );
	}

	/**
	 * @testdox The controller owns a gateway ID only while its provider's check passes and the provider lists that gateway.
	 */
	public function test_owns_only_its_providers_gateways_while_the_check_passes(): void {
		$gateway = $this->create_gateway( 'woocommerce_payments' );
		$passes  = true;
		$sut     = new ProviderGatewaysController();
		$sut->set_provider(
			static fn(): PaymentGatewayProviderInterface => new StaticProvider( true, array( $gateway ) ),
			static function () use ( &$passes ): bool {
				return $passes;
			}
		);

		$owned       = $sut->owns_gateway( 'woocommerce_payments' );
		$other       = $sut->owns_gateway( 'cod' );
		$passes      = false;
		$after_check = $sut->owns_gateway( 'woocommerce_payments' );

		$this->assertTrue( $owned );
		$this->assertFalse( $other );
		$this->assertFalse( $after_check );
	}

	/**
	 * Apply the payment gateways filter.
	 *
	 * @param array<int,mixed> $gateways Registered payment gateways.
	 * @return array<int,mixed>
	 */
	private function apply_payment_gateways_filter( array $gateways = array() ): array {
		/**
		 * Filters payment gateways registered with WooCommerce.
		 *
		 * @since 11.0.0
		 *
		 * @param array<int,mixed> $gateways Registered payment gateways.
		 */
		return apply_filters( 'woocommerce_payment_gateways', $gateways );
	}

	/**
	 * @testdox Should register all provider-supplied gateway instances.
	 */
	public function test_add_provider_gateways_adds_provider_supplied_gateway_instances(): void {
		$primary_gateway   = $this->create_gateway( 'woocommerce_payments' );
		$secondary_gateway = $this->create_gateway( 'woocommerce_payments_link' );
		$sut               = new ProviderGatewaysController();
		$sut->set_provider( static fn(): PaymentGatewayProviderInterface => new StaticProvider( true, array( $primary_gateway, $secondary_gateway ) ), static fn(): bool => true );

		$gateways = $sut->add_provider_gateways( array( 'WC_Gateway_BACS' ) );

		$this->assertSame( array( 'WC_Gateway_BACS', $primary_gateway, $secondary_gateway ), $gateways );
	}

	/**
	 * @testdox Should not add a provider gateway that is already registered as $registered_as.
	 * @testWith ["an instance with its ID"]
	 *           ["its class name"]
	 *
	 * @param string $registered_as How the gateway is already in the list.
	 */
	public function test_add_provider_gateways_does_not_add_a_gateway_with_a_registered_id( string $registered_as ): void {
		$provider_gateway = $this->create_gateway( 'woocommerce_payments' );
		$sut              = new ProviderGatewaysController();
		$sut->set_provider( static fn(): PaymentGatewayProviderInterface => new StaticProvider( true, array( $provider_gateway ) ), static fn(): bool => true );
		if ( 'its class name' === $registered_as ) {
			// WooCommerce instantiates a class-name entry itself, so the provider instance must not be added too.
			$registered = array( 'WC_Gateway_BACS', get_class( $provider_gateway ) );
		} else {
			$registered = array(
				new class() extends WC_Payment_Gateway {
					/**
					 * Constructor.
					 */
					public function __construct() {
						$this->id = 'woocommerce_payments';
					}
				},
			);
			$this->assertNotSame( get_class( $registered[0] ), get_class( $provider_gateway ), 'The two gateways must differ in class so only the ID check can match them.' );
		}

		$this->assertSame( $registered, $sut->add_provider_gateways( $registered ), 'The registered entry must stay and the provider copy be skipped.' );
	}

	/**
	 * @testdox Should treat a non-array gateway list from an earlier callback as empty, adding the provider gateway only while the check passes ($gateways, check passes: $passes).
	 * @testWith [null, true]
	 *           ["WC_Gateway_BACS", true]
	 *           [null, false]
	 *
	 * @param mixed $gateways Value returned by an earlier callback.
	 * @param bool  $passes   Whether the provider's check passes.
	 */
	public function test_add_provider_gateways_treats_a_non_array_gateway_list_as_empty( $gateways, bool $passes ): void {
		$gateway = $this->create_gateway( 'woocommerce_payments' );
		$sut     = new ProviderGatewaysController();
		$sut->set_provider( static fn(): PaymentGatewayProviderInterface => new StaticProvider( true, array( $gateway ) ), static fn(): bool => $passes );

		$this->assertSame( $passes ? array( $gateway ) : array(), $sut->add_provider_gateways( $gateways ) );
	}

	/**
	 * Create a test payment gateway instance.
	 *
	 * @param string $id Gateway ID.
	 * @return WC_Payment_Gateway
	 */
	private function create_gateway( string $id ): WC_Payment_Gateway {
		return new class( $id ) extends WC_Payment_Gateway {
			/**
			 * Constructor.
			 *
			 * @param string $id Gateway ID.
			 */
			public function __construct( string $id ) {
				$this->id = $id;
			}
		};
	}
}
