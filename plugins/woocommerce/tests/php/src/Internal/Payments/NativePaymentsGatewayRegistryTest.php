<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsGatewayRegistry;
use Automattic\WooCommerce\Internal\Payments\PaymentGatewayProviderContract;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use WC_Payment_Gateway;
use WC_Unit_Test_Case;

/**
 * Tests for the NativePaymentsGatewayRegistry class.
 */
class NativePaymentsGatewayRegistryTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'woocommerce_payment_gateways' );
		parent::tearDown();
	}

	/**
	 * @testdox Should not register the native gateway when native runtime does not own the site.
	 */
	public function test_does_not_register_when_native_runtime_does_not_own_site(): void {
		$sut = new NativePaymentsGatewayRegistry();
		$sut->init( new StaticNativeRuntimeArbiter( false ) );
		$sut->register_provider( new StaticProvider( true ) );

		$sut->register();

		$this->assertFalse( has_filter( 'woocommerce_payment_gateways', array( $sut, 'register_gateway' ) ) );
	}

	/**
	 * @testdox Should preserve gateway registration when the provider cannot currently process payments.
	 */
	public function test_registers_gateway_when_provider_cannot_process_payments(): void {
		$gateway = $this->create_gateway( 'woocommerce_payments' );
		$sut     = new NativePaymentsGatewayRegistry();
		$sut->init( new StaticNativeRuntimeArbiter( true ) );
		$sut->register_provider( new StaticProvider( false, array( $gateway ) ) );

		$sut->register();

		$this->assertSame( 10, has_filter( 'woocommerce_payment_gateways', array( $sut, 'register_gateway' ) ) );
		$this->assertSame( array( $gateway ), $this->apply_payment_gateways_filter() );
	}

	/**
	 * @testdox Should keep provider readiness out of the gateway identity boundary.
	 */
	public function test_gateway_registration_does_not_consult_provider_readiness(): void {
		$gateway  = $this->create_gateway( 'woocommerce_payments' );
		$provider = new StaticProvider( false, array( $gateway ) );
		$sut      = new NativePaymentsGatewayRegistry();
		$sut->init( new StaticNativeRuntimeArbiter( true ) );
		$sut->register_provider( $provider );

		$sut->register();

		$this->assertSame( array( $gateway ), $this->apply_payment_gateways_filter() );
		$this->assertSame( 0, $provider->can_process_payments_calls );
	}

	/**
	 * @testdox Should register contract-provided gateways when native runtime owns the site.
	 */
	public function test_registers_contract_provided_gateways_when_native_runtime_owns_site(): void {
		$gateway = $this->create_gateway( 'woocommerce_payments' );
		$sut     = new NativePaymentsGatewayRegistry();
		$sut->init( new StaticNativeRuntimeArbiter( true ) );
		$sut->register_provider( new StaticProvider( true, array( $gateway ) ) );

		$sut->register();

		$this->assertSame( 10, has_filter( 'woocommerce_payment_gateways', array( $sut, 'register_gateway' ) ) );

		$this->assertSame( array( $gateway ), $this->apply_payment_gateways_filter() );
	}

	/**
	 * @testdox Processing providers satisfy the gateway registry contract through the interface hierarchy.
	 */
	public function test_processing_provider_contract_is_accepted_by_gateway_registry(): void {
		$provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED ) );
		$sut      = new NativePaymentsGatewayRegistry();
		$sut->init( new StaticNativeRuntimeArbiter( true ) );

		$this->assertInstanceOf( PaymentGatewayProviderContract::class, $provider );

		$sut->register_provider( $provider );

		$this->assertSame( array(), $sut->register_gateway( array() ) );
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
	public function test_register_gateway_adds_provider_supplied_gateway_instances(): void {
		$primary_gateway   = $this->create_gateway( 'woocommerce_payments' );
		$secondary_gateway = $this->create_gateway( 'woocommerce_payments_link' );
		$sut               = new NativePaymentsGatewayRegistry();
		$sut->init( new StaticNativeRuntimeArbiter( true ) );
		$sut->register_provider( new StaticProvider( true, array( $primary_gateway, $secondary_gateway ) ) );

		$gateways = $sut->register_gateway( array( 'WC_Gateway_BACS' ) );

		$this->assertSame( array( 'WC_Gateway_BACS', $primary_gateway, $secondary_gateway ), $gateways );
	}

	/**
	 * @testdox Should not duplicate provider-supplied gateway instances.
	 */
	public function test_register_gateway_does_not_duplicate_gateway_instance(): void {
		$gateway = $this->create_gateway( 'woocommerce_payments' );
		$sut     = new NativePaymentsGatewayRegistry();
		$sut->init( new StaticNativeRuntimeArbiter( true ) );
		$sut->register_provider( new StaticProvider( true, array( $gateway ) ) );

		$gateways = $sut->register_gateway( array( $gateway ) );

		$this->assertSame( array( $gateway ), $gateways );
	}

	/**
	 * @testdox Should collect gateways from every registered provider.
	 */
	public function test_register_gateway_collects_gateways_from_every_provider(): void {
		$primary_gateway   = $this->create_gateway( 'woocommerce_payments' );
		$secondary_gateway = $this->create_gateway( 'another_native_provider' );
		$sut               = new NativePaymentsGatewayRegistry();
		$sut->init( new StaticNativeRuntimeArbiter( true ) );
		$sut->register_provider( new StaticProvider( true, array( $primary_gateway ) ) );
		$sut->register_provider( new StaticProvider( true, array( $secondary_gateway ), 'another_provider' ) );

		$this->assertSame(
			array( $primary_gateway, $secondary_gateway ),
			$sut->register_gateway( array() )
		);
	}

	/**
	 * @testdox Should remain resolvable by the WooCommerce runtime container.
	 */
	public function test_runtime_container_can_resolve_registry(): void {
		$this->assertInstanceOf(
			NativePaymentsGatewayRegistry::class,
			wc_get_container()->get( NativePaymentsGatewayRegistry::class )
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
