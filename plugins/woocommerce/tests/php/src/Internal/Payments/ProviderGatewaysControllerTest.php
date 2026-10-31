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
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'woocommerce_payment_gateways' );
		parent::tearDown();
	}

	/**
	 * @testdox Should not register the built-in gateway when built-in runtime does not own the site.
	 */
	public function test_does_not_register_when_builtin_runtime_does_not_own_site(): void {
		$sut = new ProviderGatewaysController();
		$sut->set_provider( static fn(): PaymentGatewayProviderInterface => new StaticProvider( true ), static fn(): bool => false );

		$sut->register();

		$this->assertFalse( has_filter( 'woocommerce_payment_gateways', array( $sut, 'add_provider_gateways' ) ) );
	}

	/**
	 * @testdox Should resolve a lazy provider once, the first time the gateway list is built.
	 */
	public function test_resolves_a_lazy_provider_once_when_the_gateway_list_is_built(): void {
		$gateway = $this->create_gateway( 'woocommerce_payments' );
		$calls   = 0;
		$sut     = new ProviderGatewaysController();
		$sut->set_provider(
			static function () use ( &$calls, $gateway ): PaymentGatewayProviderInterface {
				++$calls;
				return new StaticProvider( true, array( $gateway ) );
			},
			static fn(): bool => true
		);

		$sut->register();
		$this->assertSame( 0, $calls, 'Registering the gateway hook must not build the provider.' );

		$this->assertSame( array( $gateway ), $this->apply_payment_gateways_filter() );
		$this->assertSame( array( $gateway ), $this->apply_payment_gateways_filter() );
		$this->assertSame( 1, $calls, 'The provider must be built once.' );
	}

	/**
	 * @testdox A provider whose check stops passing after its first gateway list build adds no gateway to a later build.
	 */
	public function test_a_provider_whose_check_fails_later_adds_no_gateway_to_a_later_build(): void {
		$gateway       = $this->create_gateway( 'woocommerce_payments' );
		$owns_gateways = true;
		$calls         = 0;
		$sut           = new ProviderGatewaysController();
		$sut->set_provider(
			static function () use ( &$calls, $gateway ): PaymentGatewayProviderInterface {
				++$calls;
				return new StaticProvider( true, array( $gateway ) );
			},
			static function () use ( &$owns_gateways ): bool {
				return $owns_gateways;
			}
		);
		$sut->register();

		$this->assertSame( array( $gateway ), $this->apply_payment_gateways_filter(), 'The first build must add the gateway while the check passes.' );

		$owns_gateways = false;

		$this->assertSame( array(), $this->apply_payment_gateways_filter(), 'A later build must add no gateway once the check fails.' );
		$this->assertSame( 1, $calls, 'The provider is built once, on the first build.' );
	}

	/**
	 * @testdox A provider whose check fails at registration adds no gateway hook and is never built.
	 */
	public function test_a_provider_whose_check_fails_at_registration_adds_no_hook_and_is_not_built(): void {
		$gateway = $this->create_gateway( 'woocommerce_payments' );
		$calls   = 0;
		$sut     = new ProviderGatewaysController();
		$sut->set_provider(
			static function () use ( &$calls, $gateway ): PaymentGatewayProviderInterface {
				++$calls;
				return new StaticProvider( true, array( $gateway ) );
			},
			static fn(): bool => false
		);

		$sut->register();

		$this->assertFalse( has_filter( 'woocommerce_payment_gateways', array( $sut, 'add_provider_gateways' ) ) );
		$this->assertSame( array(), $this->apply_payment_gateways_filter() );
		$this->assertSame( 0, $calls, 'A provider whose check fails must not be built.' );
	}

	/**
	 * @testdox A provider whose check stops passing before the first gateway list build is not built, and is built on a later build where the check passes.
	 */
	public function test_a_provider_whose_check_fails_before_the_first_build_is_not_built(): void {
		$gateway       = $this->create_gateway( 'woocommerce_payments' );
		$owns_gateways = true;
		$calls         = 0;
		$sut           = new ProviderGatewaysController();
		$sut->set_provider(
			static function () use ( &$calls, $gateway ): PaymentGatewayProviderInterface {
				++$calls;
				return new StaticProvider( true, array( $gateway ) );
			},
			static function () use ( &$owns_gateways ): bool {
				return $owns_gateways;
			}
		);
		$sut->register();

		$owns_gateways = false;

		$this->assertSame( array(), $this->apply_payment_gateways_filter(), 'A build after the check fails must add no gateway.' );
		$this->assertSame( 0, $calls, 'A provider whose check fails must not be built.' );

		$owns_gateways = true;

		$this->assertSame( array( $gateway ), $this->apply_payment_gateways_filter(), 'A later build where the check passes must add the gateway.' );
		$this->assertSame( 1, $calls, 'The provider is built on the first build where its check passes.' );
	}

	/**
	 * @testdox Should preserve gateway registration when the provider cannot currently process payments.
	 */
	public function test_registers_gateway_when_provider_cannot_process_payments(): void {
		$gateway = $this->create_gateway( 'woocommerce_payments' );
		$sut     = new ProviderGatewaysController();
		$sut->set_provider( static fn(): PaymentGatewayProviderInterface => new StaticProvider( false, array( $gateway ) ), static fn(): bool => true );

		$sut->register();

		$this->assertSame( 10, has_filter( 'woocommerce_payment_gateways', array( $sut, 'add_provider_gateways' ) ) );
		$this->assertSame( array( $gateway ), $this->apply_payment_gateways_filter() );
	}

	/**
	 * @testdox Should keep provider readiness out of the gateway identity boundary.
	 */
	public function test_gateway_registration_does_not_consult_provider_readiness(): void {
		$gateway  = $this->create_gateway( 'woocommerce_payments' );
		$provider = new StaticProvider( false, array( $gateway ) );
		$sut      = new ProviderGatewaysController();
		$sut->set_provider( static fn(): PaymentGatewayProviderInterface => $provider, static fn(): bool => true );

		$sut->register();

		$this->assertSame( array( $gateway ), $this->apply_payment_gateways_filter() );
		$this->assertSame( 0, $provider->can_process_payments_calls );
	}

	/**
	 * @testdox Should register contract-provided gateways when built-in runtime owns the site.
	 */
	public function test_registers_contract_provided_gateways_when_builtin_runtime_owns_site(): void {
		$gateway = $this->create_gateway( 'woocommerce_payments' );
		$sut     = new ProviderGatewaysController();
		$sut->set_provider( static fn(): PaymentGatewayProviderInterface => new StaticProvider( true, array( $gateway ) ), static fn(): bool => true );

		$sut->register();

		$this->assertSame( 10, has_filter( 'woocommerce_payment_gateways', array( $sut, 'add_provider_gateways' ) ) );

		$this->assertSame( array( $gateway ), $this->apply_payment_gateways_filter() );
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
	 * @testdox Should not duplicate provider-supplied gateway instances.
	 */
	public function test_add_provider_gateways_does_not_duplicate_gateway_instance(): void {
		$gateway = $this->create_gateway( 'woocommerce_payments' );
		$sut     = new ProviderGatewaysController();
		$sut->set_provider( static fn(): PaymentGatewayProviderInterface => new StaticProvider( true, array( $gateway ) ), static fn(): bool => true );

		$gateways = $sut->add_provider_gateways( array( $gateway ) );

		$this->assertSame( array( $gateway ), $gateways );
	}

	/**
	 * @testdox Should not add a second gateway with an already registered gateway ID.
	 */
	public function test_add_provider_gateways_does_not_add_a_gateway_with_a_registered_id(): void {
		$registered_gateway = new class() extends WC_Payment_Gateway {
			/**
			 * Constructor.
			 */
			public function __construct() {
				$this->id = 'woocommerce_payments';
			}
		};
		$provider_gateway   = $this->create_gateway( 'woocommerce_payments' );
		$sut                = new ProviderGatewaysController();
		$sut->set_provider( static fn(): PaymentGatewayProviderInterface => new StaticProvider( true, array( $provider_gateway ) ), static fn(): bool => true );

		$this->assertNotSame( get_class( $registered_gateway ), get_class( $provider_gateway ), 'The two gateways must differ in class so only the ID check can match them.' );

		$gateways = $sut->add_provider_gateways( array( $registered_gateway ) );

		$this->assertSame( array( $registered_gateway ), $gateways, 'A gateway instance with the same ID must keep the registered one and skip the provider copy.' );
	}

	/**
	 * @testdox Should not add a gateway whose class is already registered by name.
	 */
	public function test_add_provider_gateways_does_not_add_a_gateway_registered_by_class_name(): void {
		$provider_gateway = $this->create_gateway( 'woocommerce_payments' );
		$sut              = new ProviderGatewaysController();
		$sut->set_provider( static fn(): PaymentGatewayProviderInterface => new StaticProvider( true, array( $provider_gateway ) ), static fn(): bool => true );

		$gateways = $sut->add_provider_gateways( array( 'WC_Gateway_BACS', get_class( $provider_gateway ) ) );

		$this->assertSame( array( 'WC_Gateway_BACS', get_class( $provider_gateway ) ), $gateways, 'WooCommerce instantiates a class-name entry itself, so the provider instance must not be added too.' );
	}

	/**
	 * @testdox Should treat a non-array gateway list from an earlier callback as empty.
	 * @testWith [null]
	 *           ["WC_Gateway_BACS"]
	 *
	 * @param mixed $gateways Value returned by an earlier callback.
	 */
	public function test_add_provider_gateways_treats_a_non_array_gateway_list_as_empty( $gateways ): void {
		$gateway = $this->create_gateway( 'woocommerce_payments' );
		$sut     = new ProviderGatewaysController();
		$sut->set_provider( static fn(): PaymentGatewayProviderInterface => new StaticProvider( true, array( $gateway ) ), static fn(): bool => true );

		$this->assertSame( array( $gateway ), $sut->add_provider_gateways( $gateways ) );
	}

	/**
	 * @testdox Should return an empty list for a non-array gateway list when built-in does not own the site.
	 */
	public function test_add_provider_gateways_returns_an_empty_list_for_a_non_array_when_builtin_does_not_own_the_site(): void {
		$gateway = $this->create_gateway( 'woocommerce_payments' );
		$sut     = new ProviderGatewaysController();
		$sut->set_provider( static fn(): PaymentGatewayProviderInterface => new StaticProvider( true, array( $gateway ) ), static fn(): bool => false );

		$this->assertSame( array(), $sut->add_provider_gateways( null ) );
	}

	/**
	 * @testdox Should remain resolvable by the WooCommerce runtime container.
	 */
	public function test_runtime_container_resolves_the_controller(): void {
		$this->assertInstanceOf(
			ProviderGatewaysController::class,
			wc_get_container()->get( ProviderGatewaysController::class )
		);
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
