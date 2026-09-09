<?php
/**
 * Tests for the payments API.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Blocks\Payments;

use Automattic\WooCommerce\Blocks\Assets\Api as AssetApi;
use Automattic\WooCommerce\Blocks\Assets\AssetDataRegistry;
use Automattic\WooCommerce\Blocks\Package;
use Automattic\WooCommerce\Blocks\Payments\Api;
use Automattic\WooCommerce\Blocks\Payments\Integrations\BankTransfer;
use Automattic\WooCommerce\Blocks\Payments\Integrations\CashOnDelivery;
use Automattic\WooCommerce\Blocks\Payments\Integrations\Cheque;
use Automattic\WooCommerce\Blocks\Payments\Integrations\PayPal;
use Automattic\WooCommerce\Blocks\Payments\Integrations\WooPayments;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsState;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCheckoutBridge;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWooPaySessionService;
use WC_Unit_Test_Case;

/**
 * Tests for the payments API.
 */
class ApiTest extends WC_Unit_Test_Case {

	/** @var NativePaymentsState */
	private NativePaymentsState $state;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		$this->state = wc_get_container()->get( NativePaymentsState::class );
		$this->state->invalidate();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		Package::container( true );
		remove_all_filters( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED );
		delete_option( NativePaymentsState::OPTION_NAME );
		$this->state->invalidate();
		parent::tearDown();
	}

	/**
	 * @testdox Bundled integrations remain registered without resolving WooPayments below the active tier.
	 *
	 * @dataProvider non_active_states
	 *
	 * @param string $state Effective native payments tier.
	 */
	public function test_registers_bundled_integrations_without_resolving_woopayments_below_active_tier( string $state ): void {
		$woopayments_resolutions = 0;
		$this->state->write_state( $state );
		$this->register_blocks_integrations(
			static function () use ( &$woopayments_resolutions ): WooPayments {
				++$woopayments_resolutions;
				throw new \RuntimeException( 'WooPayments must not resolve below the active tier.' );
			}
		);
		$registry = new PaymentMethodRegistry();
		$sut      = new Api( $registry, $this->createMock( AssetDataRegistry::class ) );

		$sut->register_payment_method_integrations( $registry );

		$this->assertSame( 0, $woopayments_resolutions, 'WooPayments should not be requested below the active tier.' );
		$this->assertSame(
			array( 'cheque', 'paypal', 'bacs', 'cod' ),
			array_keys( $registry->get_all_registered() ),
			'Bundled payment integrations should remain available below the active tier.'
		);
	}

	/**
	 * @testdox Resolves and registers WooPayments once for the active tier.
	 */
	public function test_resolves_and_registers_woopayments_once_for_active_tier(): void {
		$woopayments_resolutions = 0;
		$woopayments             = new WooPayments(
			$this->createMock( AssetApi::class ),
			$this->createMock( NativePaymentsRuntimeArbiter::class ),
			$this->createMock( WooPaymentsCheckoutBridge::class ),
			$this->createMock( WooPaymentsProvider::class ),
			$this->createMock( WooPaymentsWooPaySessionService::class ),
			$this->createMock( WooPaymentsExpressCheckoutService::class )
		);
		$this->state->write_state( NativePaymentsState::ACTIVE );
		$this->register_blocks_integrations(
			static function () use ( &$woopayments_resolutions, $woopayments ): WooPayments {
				++$woopayments_resolutions;
				return $woopayments;
			}
		);
		$registry = new PaymentMethodRegistry();
		$sut      = new Api( $registry, $this->createMock( AssetDataRegistry::class ) );

		$sut->register_payment_method_integrations( $registry );

		$this->assertSame( 1, $woopayments_resolutions, 'WooPayments should resolve once for the active tier.' );
		$this->assertSame(
			array( 'cheque', 'paypal', 'bacs', 'cod', 'woocommerce_payments' ),
			array_keys( $registry->get_all_registered() ),
			'WooPayments should join the bundled integrations for the active tier.'
		);
	}

	/**
	 * Provide the tiers that must not resolve WooPayments Blocks integrations.
	 *
	 * @return array<string,array{string}>
	 */
	public static function non_active_states(): array {
		return array(
			'disabled'  => array( NativePaymentsState::DISABLED ),
			'available' => array( NativePaymentsState::AVAILABLE ),
			'connected' => array( NativePaymentsState::CONNECTED ),
		);
	}

	/**
	 * Register the blocks integrations used by the system under test.
	 *
	 * @param callable $woopayments_factory Factory for the WooPayments integration.
	 */
	private function register_blocks_integrations( callable $woopayments_factory ): void {
		$container = Package::container( true );
		$asset_api = $this->createMock( AssetApi::class );
		$container->register( Cheque::class, new Cheque( $asset_api ) );
		$container->register( PayPal::class, new PayPal( $asset_api ) );
		$container->register( BankTransfer::class, new BankTransfer( $asset_api ) );
		$container->register( CashOnDelivery::class, new CashOnDelivery( $asset_api ) );
		$container->register( WooPayments::class, $woopayments_factory );
	}
}
