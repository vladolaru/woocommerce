<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsBootstrap;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsState;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionRenewalHooks;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGatewayListController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use WC_Payment_Gateway;
use WC_Unit_Test_Case;
use WP_REST_Request;

/**
 * Tests for the WooPaymentsGatewayListController class.
 *
 * The client hides its split gateways from the Settings > Payments list (client 11.1.0 `includes/class-wc-payments.php:742,1024-1031`).
 */
class WooPaymentsGatewayListControllerTest extends WC_Unit_Test_Case {

	/**
	 * Original WooCommerce payment gateway collection.
	 *
	 * @var array<int|string,mixed>
	 */
	private array $original_payment_gateways;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_payment_gateways = WC()->payment_gateways()->payment_gateways;
		add_filter( 'pre_http_request', array( $this, 'block_outbound_http' ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		WC()->payment_gateways()->payment_gateways = $this->original_payment_gateways;
		// The bootstrap cases boot native payments for one request; drop what that leaves for the rest of the process.
		wc_get_container()->reset_all_replacements();
		wc_get_container()->reset_all_resolved();
		$GLOBALS['wp_rest_server'] = null;

		$renewal_hooks = new \ReflectionProperty( WooPaymentsSubscriptionRenewalHooks::class, 'attached' );
		$renewal_hooks->setAccessible( true );
		$renewal_hooks->setValue( null, false );
		$fallback_hooks = new \ReflectionProperty( NativeWooPaymentsGateway::class, 'classic_checkout_fallback_hooks_added' );
		$fallback_hooks->setAccessible( true );
		$fallback_hooks->setValue( null, false );

		parent::tearDown();
	}

	/**
	 * Block outbound HTTP.
	 *
	 * @return \WP_Error
	 */
	public function block_outbound_http(): \WP_Error {
		return new \WP_Error( 'blocked', 'Outbound HTTP is blocked in this test.' );
	}

	/**
	 * @testdox Should hide the settings display hook behind native ownership and attach it before other listeners.
	 */
	public function test_registers_the_settings_display_hook_only_when_native_owns_payments(): void {
		$sut   = $this->create_controller( true );
		$other = $this->create_controller( false );

		$sut->register();
		$sut->register();
		$other->register();

		$this->assertSame( 5, has_action( 'woocommerce_admin_field_payment_gateways', array( $sut, 'handle_woocommerce_admin_field_payment_gateways' ) ) );
		$this->assertFalse( has_action( 'woocommerce_admin_field_payment_gateways', array( $other, 'handle_woocommerce_admin_field_payment_gateways' ) ) );
	}

	/**
	 * @testdox Should be a bootstrap root wherever the WooPayments gateway is registered, and nowhere else.
	 */
	public function test_is_rooted_with_every_gateway_registration(): void {
		foreach ( WooPaymentsProvider::get_bootstrap_root_matrix() as $state => $request_groups ) {
			foreach ( $request_groups as $request_type => $roots ) {
				$this->assertSame(
					in_array( WooPaymentsProvider::class, $roots, true ),
					in_array( WooPaymentsGatewayListController::class, $roots, true ),
					"$state $request_type"
				);
			}
		}
		$this->assertContains( WooPaymentsGatewayListController::class, WooPaymentsProvider::get_bootstrap_root_matrix()[ NativePaymentsState::ACTIVE ]['rest'] );
	}

	/**
	 * @testdox Should retain the canonical and unrelated gateways while removing native split gateways from settings.
	 */
	public function test_projects_only_the_canonical_native_gateway_for_settings_display(): void {
		$canonical = $this->create_native_gateway( 'woocommerce_payments' );
		$afterpay  = $this->create_native_gateway( 'woocommerce_payments_afterpay_clearpay' );
		$klarna    = $this->create_native_gateway( 'woocommerce_payments_klarna' );
		$unrelated = $this->create_unrelated_gateway( 'bacs' );
		$sut       = $this->create_controller( true );

		WC()->payment_gateways()->payment_gateways = array(
			2  => $canonical,
			7  => $afterpay,
			11 => $unrelated,
			14 => $klarna,
		);

		$sut->handle_woocommerce_admin_field_payment_gateways();

		$this->assertSame(
			array(
				2  => $canonical,
				11 => $unrelated,
			),
			WC()->payment_gateways()->payment_gateways
		);
	}

	/**
	 * @testdox Should not change the settings collection when the canonical native gateway is absent.
	 */
	public function test_does_not_change_settings_collection_when_canonical_gateway_is_absent(): void {
		$afterpay  = $this->create_native_gateway( 'woocommerce_payments_afterpay_clearpay' );
		$unrelated = $this->create_unrelated_gateway( 'bacs' );
		$sut       = $this->create_controller( true );
		$gateways  = array(
			7  => $afterpay,
			11 => $unrelated,
		);

		WC()->payment_gateways()->payment_gateways = $gateways;

		$sut->handle_woocommerce_admin_field_payment_gateways();

		$this->assertSame( $gateways, WC()->payment_gateways()->payment_gateways );
	}

	/**
	 * @testdox Should leave the settings collection alone when native does not own payments.
	 */
	public function test_does_not_change_settings_collection_when_native_does_not_own_payments(): void {
		$gateways = array(
			2 => $this->create_native_gateway( 'woocommerce_payments' ),
			7 => $this->create_native_gateway( 'woocommerce_payments_klarna' ),
		);
		$sut      = $this->create_controller( false );

		WC()->payment_gateways()->payment_gateways = $gateways;

		$sut->handle_woocommerce_admin_field_payment_gateways();

		$this->assertSame( $gateways, WC()->payment_gateways()->payment_gateways );
	}

	/**
	 * @testdox The Settings > Payments providers REST route of an active native store lists the WooPayments card gateway and none of its split gateways, as the client does.
	 */
	public function test_providers_rest_route_lists_only_the_main_woopayments_gateway(): void {
		$this->arrange_native_owner( NativePaymentsState::ACTIVE );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->run_bootstrap( '__return_true' );
		$this->reload_payment_gateways();
		$registered_split_ids = $this->get_split_gateway_ids( array_keys( WC()->payment_gateways()->payment_gateways() ) );
		$this->assertNotEmpty( $registered_split_ids, 'The store must register split gateways for checkout, or this test proves nothing.' );

		$response = rest_do_request( new WP_REST_Request( 'POST', '/wc-admin/settings/payments/providers' ) );

		$this->assertSame( 200, $response->get_status() );
		$provider_ids = array_column( $response->get_data()['providers'], 'id' );
		$this->assertContains( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $provider_ids );
		$this->assertSame( array(), $this->get_split_gateway_ids( $provider_ids ), 'Split gateways must not be listed as providers of their own.' );
	}

	/**
	 * @testdox Checkout lists every WooPayments gateway at the saved position of the card gateway, in the provider's order, as the client does.
	 */
	public function test_checkout_places_the_woopayments_block_at_the_saved_card_gateway_position(): void {
		$this->arrange_native_owner( NativePaymentsState::ACTIVE );
		$this->run_bootstrap( '__return_false' );
		update_option(
			'woocommerce_gateway_order',
			array(
				'bacs'   => 0,
				WooPaymentsPersistenceVocabulary::GATEWAY_ID => 1,
				'cheque' => 2,
			)
		);

		$this->reload_payment_gateways();

		$block = $this->get_provider_gateway_ids();
		$ids   = array_keys( WC()->payment_gateways()->payment_gateways() );
		$this->assertSame( 'bacs', $ids[0] );
		$this->assertSame( $block, array_slice( $ids, 1, count( $block ) ), 'Every WooPayments gateway must follow the card gateway, in the provider order.' );
		$this->assertSame( 'cheque', $ids[ 1 + count( $block ) ] );
	}

	/**
	 * @testdox Checkout lists the WooPayments gateways first when no gateway order is saved, as the client does.
	 */
	public function test_checkout_lists_the_woopayments_block_first_without_a_saved_order(): void {
		$this->arrange_native_owner( NativePaymentsState::ACTIVE );
		$this->run_bootstrap( '__return_false' );
		delete_option( 'woocommerce_gateway_order' );

		$this->reload_payment_gateways();

		$block = $this->get_provider_gateway_ids();
		$ids   = array_keys( WC()->payment_gateways()->payment_gateways() );
		$this->assertSame( $block, array_slice( $ids, 0, count( $block ) ) );
		$this->assertContains( 'bacs', array_slice( $ids, count( $block ) ), 'Other gateways must follow the WooPayments block.' );
	}

	/**
	 * @testdox Checkout lists the WooPayments gateways before the saved gateways when the saved order lacks the card gateway, as the client does.
	 */
	public function test_checkout_lists_the_woopayments_block_first_when_the_saved_order_lacks_the_card_gateway(): void {
		$this->arrange_native_owner( NativePaymentsState::ACTIVE );
		$this->run_bootstrap( '__return_false' );
		update_option(
			'woocommerce_gateway_order',
			array(
				'bacs'   => 0,
				'cheque' => 1,
			)
		);

		$this->reload_payment_gateways();

		$block = $this->get_provider_gateway_ids();
		$ids   = array_keys( WC()->payment_gateways()->payment_gateways() );
		$this->assertSame( array_merge( $block, array( 'bacs', 'cheque' ) ), array_slice( $ids, 0, count( $block ) + 2 ) );
	}

	/**
	 * @testdox Orders the WooPayments gateways as the client's order_woopayments_gateways() does.
	 * @testWith [{"bacs": 0, "cheque": 1}, {"woocommerce_payments": 0, "woocommerce_payments_klarna": 1, "woocommerce_payments_ideal": 2, "bacs": 3, "cheque": 4}]
	 *           [{"bacs": 0, "woocommerce_payments": 1, "cheque": 2}, {"bacs": 0, "woocommerce_payments": 1, "woocommerce_payments_klarna": 2, "woocommerce_payments_ideal": 3, "cheque": 4}]
	 *           [[], {"woocommerce_payments": 0, "woocommerce_payments_klarna": 1, "woocommerce_payments_ideal": 2}]
	 *           [{"woocommerce_payments_klarna": 0, "bacs": 1, "woocommerce_payments": 2, "cheque": 3, "woocommerce_payments_ideal": 4}, {"woocommerce_payments_klarna": 0, "bacs": 1, "woocommerce_payments": 2, "woocommerce_payments_ideal": 3, "cheque": 4}]
	 *           [{"bacs": 0, "woocommerce_payments_ideal": 1}, {"woocommerce_payments": 0, "woocommerce_payments_klarna": 1, "woocommerce_payments_ideal": 2, "bacs": 3}]
	 *           [{"cheque": 2, "woocommerce_payments": 1, "bacs": 0}, {"bacs": 0, "woocommerce_payments": 1, "woocommerce_payments_klarna": 2, "woocommerce_payments_ideal": 3, "cheque": 4}]
	 *           [{"bacs": 1, "woocommerce_payments": 1, "cheque": 2}, {"woocommerce_payments": 0, "woocommerce_payments_klarna": 1, "woocommerce_payments_ideal": 2, "cheque": 3}]
	 *           [false, {"woocommerce_payments": 0, "woocommerce_payments_klarna": 1, "woocommerce_payments_ideal": 2, "0": 3}]
	 *
	 * @param mixed             $ordering Saved or default gateway order.
	 * @param array<string,int> $expected Expected gateway order.
	 */
	public function test_orders_woopayments_gateways_like_the_client( $ordering, array $expected ): void {
		$result = WooPaymentsGatewayListController::order_woopayments_gateways(
			$ordering,
			array( 'woocommerce_payments', 'woocommerce_payments_klarna', 'woocommerce_payments_ideal' )
		);

		$this->assertSame( $expected, $result );
	}

	/**
	 * @testdox Should attach the gateway order filters at the client's priorities only when native owns payments.
	 */
	public function test_registers_the_gateway_order_filters_only_when_native_owns_payments(): void {
		$sut   = $this->create_controller( true );
		$other = $this->create_controller( false );

		$sut->register();
		$other->register();

		$this->assertSame( 2, has_filter( 'option_woocommerce_gateway_order', array( $sut, 'handle_gateway_order_option' ) ) );
		$this->assertSame( 3, has_filter( 'default_option_woocommerce_gateway_order', array( $sut, 'handle_gateway_order_option' ) ) );
		$this->assertFalse( has_filter( 'option_woocommerce_gateway_order', array( $other, 'handle_gateway_order_option' ) ) );
		$this->assertFalse( has_filter( 'default_option_woocommerce_gateway_order', array( $other, 'handle_gateway_order_option' ) ) );
	}

	/**
	 * @testdox Should return the gateway order unchanged when native does not own payments or the provider publishes no gateways.
	 */
	public function test_returns_the_gateway_order_unchanged_without_native_ownership_or_provider_gateways(): void {
		$ordering = array(
			'bacs'   => 0,
			'cheque' => 1,
		);
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )->onlyMethods( array( 'get_payment_gateways' ) )->getMock();
		$provider->method( 'get_payment_gateways' )->willReturn( array() );
		wc_get_container()->replace( WooPaymentsProvider::class, $provider );

		$this->assertSame( $ordering, $this->create_controller( false )->handle_gateway_order_option( $ordering ) );
		$this->assertSame( $ordering, $this->create_controller( true )->handle_gateway_order_option( $ordering ) );
	}

	/**
	 * Get the gateway IDs the WooPayments provider publishes, in its order.
	 *
	 * @return array<int,string>
	 */
	private function get_provider_gateway_ids(): array {
		$ids = array_map(
			static fn( WC_Payment_Gateway $gateway ): string => $gateway->id,
			wc_get_container()->get( WooPaymentsProvider::class )->get_payment_gateways()
		);
		$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $ids[0] ?? null, 'The provider must publish the card gateway first.' );
		$this->assertGreaterThan( 1, count( $ids ), 'The provider must publish split gateways, or the order tests prove nothing.' );

		return $ids;
	}

	/**
	 * Keep only WooPayments split gateway IDs.
	 *
	 * @param array<int,mixed> $ids Gateway or provider IDs.
	 * @return array<int,string>
	 */
	private function get_split_gateway_ids( array $ids ): array {
		return array_values(
			array_filter(
				$ids,
				static fn( $id ): bool => is_string( $id ) && str_starts_with( $id, WooPaymentsPersistenceVocabulary::GATEWAY_ID . '_' )
			)
		);
	}

	/**
	 * Build WooCommerce's gateway list again, as a new request would after the bootstrap ran.
	 */
	private function reload_payment_gateways(): void {
		WC()->payment_gateways()->payment_gateways = array();
		WC()->payment_gateways()->init();
	}

	/**
	 * Make native the payments owner with the given stored tier.
	 *
	 * @param string $state Stored native tier.
	 */
	private function arrange_native_owner( string $state ): void {
		update_option( 'active_plugins', array_values( array_diff( (array) get_option( 'active_plugins', array() ), array( NativePaymentsRuntimeArbiter::PLUGIN_FILE ) ) ) );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		update_option( NativePaymentsState::OPTION_NAME, $state, true );
		wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->invalidate();
		wc_get_container()->get( NativePaymentsState::class )->invalidate();
	}

	/**
	 * Run the native payments bootstrap with the WooPayments root matrix, as WooCommerce does when it loads.
	 *
	 * @param callable $is_rest_api_request Whether the request is a REST request.
	 */
	private function run_bootstrap( callable $is_rest_api_request ): void {
		( new NativePaymentsBootstrap(
			array( WooPaymentsProvider::class, 'get_bootstrap_root_matrix' ),
			static fn(): array => array()
		) )->register( wc_get_container(), $is_rest_api_request );
	}

	/**
	 * Create the controller under test.
	 *
	 * @param bool $native_register Whether native owns payments.
	 * @return WooPaymentsGatewayListController
	 */
	private function create_controller( bool $native_register ): WooPaymentsGatewayListController {
		$arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( $native_register );

		$controller = new WooPaymentsGatewayListController();
		$controller->init( $arbiter );

		return $controller;
	}

	/**
	 * Create a constructor-free native gateway identity.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return NativeWooPaymentsGateway
	 */
	private function create_native_gateway( string $gateway_id ): NativeWooPaymentsGateway {
		$gateway     = $this->getMockBuilder( NativeWooPaymentsGateway::class )
			->disableOriginalConstructor()
			->getMock();
		$gateway->id = $gateway_id;

		return $gateway;
	}

	/**
	 * Create a non-WooPayments gateway identity.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return WC_Payment_Gateway
	 */
	private function create_unrelated_gateway( string $gateway_id ): WC_Payment_Gateway {
		return new class( $gateway_id ) extends WC_Payment_Gateway {
			/**
			 * Set the gateway ID.
			 *
			 * @param string $gateway_id Gateway ID.
			 */
			public function __construct( string $gateway_id ) {
				$this->id = $gateway_id;
			}
		};
	}
}
