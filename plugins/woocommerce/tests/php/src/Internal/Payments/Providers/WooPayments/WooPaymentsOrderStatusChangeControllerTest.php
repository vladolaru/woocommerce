<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderStatusChangeController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderStatusChangeProjectionService;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticNativeRuntimeArbiter;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsOrderStatusChangeController class.
 */
class WooPaymentsOrderStatusChangeControllerTest extends WC_Unit_Test_Case {

	private const SCRIPT_HANDLE = 'wc-admin-woopayments-order-status-change';

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsOrderStatusChangeController
	 */
	private $sut;

	/**
	 * Script entries handed to the fake asset registrar.
	 *
	 * @var string[]
	 */
	private array $registered_entries = array();

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		if ( $this->sut instanceof WooPaymentsOrderStatusChangeController ) {
			remove_action( 'admin_enqueue_scripts', array( $this->sut, 'handle_admin_enqueue_scripts' ) );
		}

		wp_dequeue_script( self::SCRIPT_HANDLE );
		wp_deregister_script( self::SCRIPT_HANDLE );

		unset( $GLOBALS['theorder'] );
		set_current_screen( 'front' );

		parent::tearDown();
	}

	/**
	 * @testdox Should not register or enqueue anything when the native runtime does not own payments.
	 */
	public function test_does_nothing_when_native_does_not_own_the_runtime(): void {
		$this->sut = $this->create_controller( false );
		$this->set_current_order( $this->create_order() );
		$this->set_order_edit_screen( 'shop_order' );

		$this->sut->register();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Re-firing WordPress' hook to prove the controller never joins it.
		do_action( 'admin_enqueue_scripts' );

		$this->assertFalse(
			has_action( 'admin_enqueue_scripts', array( $this->sut, 'handle_admin_enqueue_scripts' ) ),
			'A plugin-owned runtime should not register the native confirmation script hook.'
		);
		$this->assertFalse( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) );
	}

	/**
	 * @testdox Should register the admin enqueue hook once when the native runtime owns payments.
	 */
	public function test_registers_the_admin_enqueue_hook_once(): void {
		$this->sut = $this->create_controller( true );

		$this->sut->register();
		$this->sut->register();

		$this->assertSame(
			10,
			has_action( 'admin_enqueue_scripts', array( $this->sut, 'handle_admin_enqueue_scripts' ) ),
			'Native-owned runtime should register the confirmation script hook.'
		);
	}

	/**
	 * @testdox Should do nothing outside the order edit screens.
	 */
	public function test_does_nothing_outside_the_order_edit_screens(): void {
		$this->sut = $this->create_controller( true );
		$this->set_current_order( $this->create_order() );
		set_current_screen( 'dashboard' );

		$this->sut->handle_admin_enqueue_scripts();

		$this->assertSame( array(), $this->registered_entries, 'No asset should be registered off the order screen.' );
		$this->assertFalse( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) );
	}

	/**
	 * @testdox Should do nothing when no order is being edited.
	 */
	public function test_does_nothing_when_no_order_is_being_edited(): void {
		$this->sut = $this->create_controller( true );
		$this->set_order_edit_screen( 'shop_order' );

		$this->sut->handle_admin_enqueue_scripts();

		$this->assertSame( array(), $this->registered_entries );
		$this->assertFalse( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) );
	}

	/**
	 * @testdox Should do nothing for an order paid with another gateway.
	 */
	public function test_does_nothing_for_a_non_woopayments_order(): void {
		$this->sut = $this->create_controller( true );
		$this->set_current_order( $this->create_order( 'bacs' ) );
		$this->set_order_edit_screen( 'shop_order' );

		$this->sut->handle_admin_enqueue_scripts();

		$this->assertSame( array(), $this->registered_entries );
		$this->assertFalse( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) );
	}

	/**
	 * @testdox Should do nothing when the confirmation script bundle has not been built.
	 */
	public function test_does_nothing_when_the_script_bundle_is_not_built(): void {
		$this->sut = $this->create_controller( true, false );
		$this->set_current_order( $this->create_order() );
		$this->set_order_edit_screen( 'shop_order' );

		$this->sut->handle_admin_enqueue_scripts();

		$this->assertSame( array(), $this->registered_entries, 'A missing bundle should degrade to no modal, not a fatal.' );
		$this->assertFalse( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ) );
	}

	/**
	 * @testdox Should enqueue the confirmation script with the projected config on the order edit screens.
	 *
	 * @dataProvider order_edit_screen_provider
	 *
	 * @param int $screen_index Index into the supported order edit screen IDs.
	 */
	public function test_enqueues_the_confirmation_script_with_the_projected_config( int $screen_index ): void {
		$screen_ids = $this->get_order_edit_screen_ids();
		if ( ! isset( $screen_ids[ $screen_index ] ) ) {
			$this->markTestSkipped( 'This installation does not expose a second order edit screen.' );
		}

		$order = $this->create_order();
		$order->set_status( 'processing' );
		$order->save();

		$this->sut = $this->create_controller( true );
		$this->set_current_order( $order );
		$this->set_order_edit_screen( $screen_ids[ $screen_index ] );

		$this->sut->handle_admin_enqueue_scripts();

		$this->assertSame( array( 'woopayments-order-status-change' ), $this->registered_entries );
		$this->assertTrue( wp_script_is( self::SCRIPT_HANDLE, 'enqueued' ), 'The confirmation script should be enqueued.' );

		$inline = $this->get_inline_script();
		$this->assertStringContainsString( 'window.woocommerceWooPaymentsOrderStatusChange = ', $inline );
		$this->assertStringNotContainsString( '"refund_amount":"25"', $inline, 'Amounts must not cross as strings.' );
		$this->assertStringContainsString( '"can_refund":false', $inline, 'Booleans must cross as booleans.' );

		$config = $this->parse_emitted_config( $inline );

		$this->assertSame(
			array( 'order_status', 'can_refund', 'refund_amount', 'formatted_refund_amount', 'refunded_amount' ),
			array_keys( $config ),
			'The config contract is consumed by the browser and must not drift.'
		);
		$this->assertSame( 'wc-processing', $config['order_status'] );
		$this->assertIsBool( $config['can_refund'], 'The browser branches on can_refund being false.' );
		$this->assertIsString( $config['formatted_refund_amount'] );
		$this->assertStringNotContainsString( '&', $config['formatted_refund_amount'], 'The browser renders this as text, so entities must already be decoded.' );

		// JSON has no int/float distinction, so an integral amount encodes as `25` and PHP decodes it
		// back as an int. What the contract needs is that it crosses unquoted, i.e. the browser gets a
		// number rather than a string - which the raw assertions above and below pin directly.
		$this->assertIsNotString( $config['refund_amount'], 'The browser branches on refund_amount not being positive.' );
		$this->assertEqualsWithDelta( 25.0, $config['refund_amount'], 0.001 );
		$this->assertStringContainsString( '"refund_amount":25', $inline, 'Amounts must cross as JSON numbers.' );
		$this->assertIsNotString( $config['refunded_amount'] );
		$this->assertEqualsWithDelta( 0.0, $config['refunded_amount'], 0.001 );
		$this->assertStringContainsString( '"refunded_amount":0', $inline, 'Amounts must cross as JSON numbers.' );
	}

	/**
	 * Supported order edit screens.
	 *
	 * @return array<string,array{0:int}>
	 */
	public function order_edit_screen_provider(): array {
		return array(
			'first order edit screen'  => array( 0 ),
			'second order edit screen' => array( 1 ),
		);
	}

	/**
	 * Get the inline script emitted before the confirmation script.
	 *
	 * @return string
	 */
	private function get_inline_script(): string {
		$chunks = wp_scripts()->get_data( self::SCRIPT_HANDLE, 'before' );

		$this->assertIsArray( $chunks, 'The confirmation config should be emitted as an inline script.' );

		return implode( "\n", array_filter( $chunks, 'is_string' ) );
	}

	/**
	 * Parse the config the browser actually receives out of the emitted inline script.
	 *
	 * @param string $inline Emitted inline script.
	 * @return array<string,mixed>
	 */
	private function parse_emitted_config( string $inline ): array {
		$assignment = 'window.woocommerceWooPaymentsOrderStatusChange = ';
		$offset     = strpos( $inline, $assignment );

		$this->assertNotFalse( $offset, 'The config assignment should be present in the inline script.' );

		$json   = rtrim( trim( substr( $inline, $offset + strlen( $assignment ) ) ), ';' );
		$config = json_decode( $json, true );

		$this->assertSame( JSON_ERROR_NONE, json_last_error(), 'The emitted config should be valid JSON.' );
		$this->assertIsArray( $config );

		return $config;
	}

	/**
	 * Create the System Under Test.
	 *
	 * @param bool $native_register  Whether native owns the runtime.
	 * @param bool $asset_available  Whether the script bundle is built.
	 * @return WooPaymentsOrderStatusChangeController
	 */
	private function create_controller( bool $native_register, bool $asset_available = true ): WooPaymentsOrderStatusChangeController {
		$controller = new WooPaymentsOrderStatusChangeController();
		$controller->init(
			new StaticNativeRuntimeArbiter( $native_register ),
			wc_get_container()->get( WooPaymentsOrderStatusChangeProjectionService::class )
		);

		$controller->set_asset_available_resolver(
			function () use ( $asset_available ) {
				return $asset_available;
			}
		);
		$controller->set_asset_registrar(
			function ( string $entry ) {
				$this->registered_entries[] = $entry;
				wp_register_script( self::SCRIPT_HANDLE, '', array(), '1.0.0', true );
			}
		);

		return $controller;
	}

	/**
	 * Create a saved order with a known total.
	 *
	 * @param string $payment_method Order payment method ID.
	 * @return WC_Order
	 */
	private function create_order( string $payment_method = 'woocommerce_payments' ): WC_Order {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );

		$order->set_payment_method( $payment_method );
		$order->set_total( '25.00' );
		$order->save();

		return $order;
	}

	/**
	 * Make an order the one being edited.
	 *
	 * @param WC_Order $order Order being edited.
	 */
	private function set_current_order( WC_Order $order ): void {
		$GLOBALS['theorder'] = $order;
	}

	/**
	 * Put the request on an order edit screen.
	 *
	 * @param string $screen_id Order edit screen ID.
	 */
	private function set_order_edit_screen( string $screen_id ): void {
		set_current_screen( $screen_id );

		$this->assertSame( $screen_id, get_current_screen()->id, 'The test needs the order edit screen to be current.' );
	}

	/**
	 * Get order edit screen IDs that should receive the confirmation script.
	 *
	 * @return string[]
	 */
	private function get_order_edit_screen_ids(): array {
		$screen_ids = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screen_ids[] = wc_get_page_screen_id( 'shop-order' );
		}

		return array_values( array_unique( array_filter( $screen_ids ) ) );
	}
}
