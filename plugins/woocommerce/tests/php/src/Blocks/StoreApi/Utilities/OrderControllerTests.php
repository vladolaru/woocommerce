<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Blocks\StoreApi\Utilities;

use WC_Helper_Order;
use WC_Helper_Product;
use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Utilities\LocalPickupUtils;
use Automattic\WooCommerce\StoreApi\Utilities\OrderController;
use Automattic\WooCommerce\Utilities\ShippingUtil;
use Automattic\WooCommerce\RestApi\UnitTests\Helpers\CouponHelper;

/**
 * OrderControllerTests class.
 */
class OrderControllerTests extends \WC_Unit_Test_Case {
	/**
	 * Shipping options and transients changed by the deterministic fixture.
	 */
	private const SHIPPING_OPTION_NAMES = array(
		'woocommerce_flat_rate_settings',
		'woocommerce_flat_rate',
		'_transient_shipping-transient-version',
		'_transient_timeout_shipping-transient-version',
		'_transient_wc_shipping_method_count',
		'_transient_timeout_wc_shipping_method_count',
	);

	/**
	 * The system under test.
	 *
	 * @var OrderController
	 */
	private $sut;

	/**
	 * Shipping options and transients that the deterministic fixture changes.
	 *
	 * @var array<string, array{exists: bool, value: mixed}>
	 */
	private $original_shipping_options = array();

	/**
	 * Shipping option state before this class runs.
	 *
	 * @var array<string, array{exists: bool, value: mixed}>
	 */
	private static $shipping_options_before_class = array();

	/**
	 * Capture exact shipping state before any test fixture runs.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$shipping_options_before_class = self::capture_shipping_options();
	}

	/**
	 * Assert that the class restored the exact shipping state it inherited.
	 */
	public static function tearDownAfterClass(): void {
		try {
			self::assertSame( self::$shipping_options_before_class, self::capture_shipping_options(), 'OrderControllerTests must restore the exact shipping option and transient state it inherited.' );
		} finally {
			self::$shipping_options_before_class = array();
			parent::tearDownAfterClass();
		}
	}

	/**
	 * Set up before test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// The fixtures in this class do not provide phone numbers, so make the
		// phone field optional as other Store API test classes do. Without this
		// the class only passes when run after a class that already did so.
		// The per-test database rollback restores the option.
		update_option( 'woocommerce_checkout_phone_field', 'optional' );

		$this->original_shipping_options = self::capture_shipping_options();

		\WC_Helper_Shipping::create_simple_flat_rate();
		\WC_Cache_Helper::get_transient_version( 'shipping', true );
		delete_transient( 'wc_shipping_method_count' );
		WC()->cart->empty_cart();
		WC()->shipping()->reset_shipping();
		$this->sut = new class() extends OrderController {
			/**
			 * Check all required address fields are set and return errors if not. Parent is protected.
			 *
			 * @param \WC_Order $order Order object.
			 * @param string    $address_type billing or shipping address, used in error messages.
			 * @param \WP_Error $errors Error object.
			 */
			public function validate_address_fields( \WC_Order $order, $address_type, \WP_Error $errors ) { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found
				parent::validate_address_fields( $order, $address_type, $errors );
			}
		};
	}

	/**
	 * Tear down after test.
	 */
	public function tearDown(): void {
		try {
			WC()->cart->empty_cart();
			WC()->shipping()->reset_shipping();
			WC()->shipping()->unregister_shipping_methods();

			self::restore_shipping_options( $this->original_shipping_options );
		} finally {
			WC()->countries->locale          = null;
			$this->sut                       = null;
			$this->original_shipping_options = array();
			parent::tearDown();
		}
	}

	/**
	 * test_validate_existing_order_before_payment_valid_data.
	 */
	public function test_validate_existing_order_before_payment_valid_data() {
		$order = WC_Helper_Order::create_order();
		$this->set_shipping_address( $order );
		$order->save();

		$this->assertNull( $this->sut->validate_existing_order_before_payment( $order ) );
	}

	/**
	 * test_validate_selected_shipping_methods_throws
	 */
	public function test_validate_selected_shipping_methods_throws() {
		$this->expectException( RouteException::class );
		$this->sut->validate_selected_shipping_methods( true, array( false ) );
		$this->sut->validate_selected_shipping_methods( true, null );
	}

	/**
	 * test_validate_selected_shipping_methods.
	 */
	public function test_validate_selected_shipping_methods() {
		// Add a flat rate to the default zone.
		$flat_rate    = WC()->shipping()->get_shipping_methods()['flat_rate'];
		$default_zone = \WC_Shipping_Zones::get_zone( 0 );
		$default_zone->add_shipping_method( $flat_rate->id );
		$default_zone->save();

		$registered_methods = \WC_Shipping_Zones::get_zone( 0 )->get_shipping_methods();
		$valid_method       = array_shift( $registered_methods );

		$this->assertNull( $this->sut->validate_selected_shipping_methods( true, array( $valid_method->id . ':' . $valid_method->instance_id ) ) );
		$this->assertNull( $this->sut->validate_selected_shipping_methods( false, array( 'free-shipping' ) ) );
	}

	/**
	 * test_validate_order_before_payment_invalid_coupon_usage_limit.
	 */
	public function test_validate_order_before_payment_invalid_coupon_usage_limit() {
		$this->expectException( RouteException::class );
		$this->expectExceptionCode( 409 );
		$this->expectExceptionMessage( '"limited-coupon" was removed from the cart. Usage limit for coupon &quot;limited-coupon&quot; has been reached.' );

		$order = WC_Helper_Order::create_order();

		// Create a coupon with usage limit of 1 and mark it as used.
		$coupon = CouponHelper::create_coupon(
			'limited-coupon',
			'publish',
			array( 'usage_limit_per_user' => 1 )
		);
		$coupon->increase_usage_count( $order->get_billing_email() );
		$order->apply_coupon( $coupon );
		$order->save();

		try {
			$this->sut->validate_order_before_payment( $order );
		} finally {
			$this->assertEmpty( $order->get_coupon_codes() );
		}
	}

	/**
	 * test_validate_order_before_payment_invalid_coupons.
	 */
	public function test_validate_order_before_payment_invalid_coupons() {
		$this->expectException( RouteException::class );
		$this->expectExceptionCode( 409 );
		$this->expectExceptionMessage( '"fake-coupon" was removed from the cart. Please enter a valid email at checkout to use coupon code &quot;fake-coupon&quot;.' );

		$order       = WC_Helper_Order::create_order();
		$coupon      = CouponHelper::create_coupon( 'fake-coupon', 'publish', array( 'customer_email' => 'random-email@example.com' ) );
		$coupon_item = new \WC_Order_Item_Coupon();
		$coupon_item->set_code( $coupon->get_code() );
		$order->add_item( $coupon_item );
		$order->save();
		$this->assertEquals( array( 'fake-coupon' ), $order->get_coupon_codes() );

		$class = new OrderController();
		try {
			$class->validate_order_before_payment( $order );
		} finally {
			$this->assertEmpty( $order->get_coupon_codes() );
		}
	}

	/**
	 * test_validate_existing_order_before_payment_invalid_coupons.
	 */
	public function test_validate_existing_order_before_payment_invalid_coupons() {
		$this->expectException( RouteException::class );
		$this->expectExceptionCode( 409 );
		$this->expectExceptionMessage( '"fake-coupon" was removed from the order. Please enter a valid email at checkout to use coupon code &quot;fake-coupon&quot;.' );

		$order       = WC_Helper_Order::create_order();
		$coupon      = CouponHelper::create_coupon( 'fake-coupon', 'publish', array( 'customer_email' => 'random-email@example.com' ) );
		$coupon_item = new \WC_Order_Item_Coupon();
		$coupon_item->set_code( $coupon->get_code() );
		$order->add_item( $coupon_item );
		$order->save();
		$this->assertEquals( array( 'fake-coupon' ), $order->get_coupon_codes() );

		try {
			$this->sut->validate_existing_order_before_payment( $order );
		} finally {
			$this->assertEmpty( $order->get_coupon_codes() );
		}
	}

	/**
	 * @testdox Existing-order validation keeps the coupon when the order already recorded its usage.
	 */
	public function test_validate_existing_order_before_payment_keeps_coupon_when_usage_recorded() {
		$coupon = CouponHelper::create_coupon( 'recorded-coupon', 'publish', array( 'usage_limit' => 1 ) );
		$coupon->increase_usage_count();

		$order = WC_Helper_Order::create_order();
		$this->set_shipping_address( $order );
		$item = new \WC_Order_Item_Coupon();
		$item->set_code( $coupon->get_code() );
		$order->add_item( $item );
		$order->set_recorded_coupon_usage_counts( true );
		$order->save();

		$this->assertNull( $this->sut->validate_existing_order_before_payment( $order ) );
		$this->assertEquals( array( 'recorded-coupon' ), $order->get_coupon_codes() );
	}

	/**
	 * @testdox Stripping an exhausted coupon from a draft does not change its usage count.
	 */
	public function test_validate_existing_order_before_payment_does_not_decrement_usage_count() {
		$coupon = CouponHelper::create_coupon( 'draft-global', 'publish', array( 'usage_limit' => 1 ) );
		$coupon->increase_usage_count();
		$this->assertEquals( 1, ( new \WC_Coupon( 'draft-global' ) )->get_usage_count() );

		$order = WC_Helper_Order::create_order();
		$item  = new \WC_Order_Item_Coupon();
		$item->set_code( $coupon->get_code() );
		$order->add_item( $item );
		$order->save();

		try {
			$this->sut->validate_existing_order_before_payment( $order );
			$this->fail( 'Expected a RouteException for the exhausted coupon.' );
		} catch ( RouteException $e ) {
			$this->assertEquals( 409, $e->getCode() );
		}

		$this->assertEmpty( $order->get_coupon_codes() );
		$this->assertEquals( 1, ( new \WC_Coupon( 'draft-global' ) )->get_usage_count(), 'usage_count must not be decremented for a draft that never recorded it' );
	}

	/**
	 * test_validate_order_before_payment_invalid_email.
	 */
	public function test_validate_order_before_payment_invalid_email() {
		$this->expectException( RouteException::class );
		$this->expectExceptionCode( 400 );
		$this->expectExceptionMessage( 'A valid email address is required' );

		$order = new \WC_Order();
		$order->set_status( OrderStatus::PENDING );
		$order->save();

		$this->sut->validate_order_before_payment( $order );
	}

	/**
	 * @testdox Rejects an invalid shipping country for ordinary checkout with a selected non-local-pickup rate.
	 */
	public function test_validate_order_before_payment_invalid_addresses() {
		$this->expectException( RouteException::class );
		$this->expectExceptionCode( 400 );
		$this->expectExceptionMessage( 'Sorry, we do not ship orders to the provided country (Invalid)' );

		$order = WC_Helper_Order::create_order();
		$order->set_shipping_country( 'Invalid' );
		$order->save();

		/** @var \WC_Order_Item_Product $item */
		$array = $order->get_items();
		$item  = reset( $array );
		$this->assertInstanceOf( \WC_Order_Item_Product::class, $item );

		$cart_item_key = WC()->cart->add_to_cart( $item->get_product()->get_id() );
		$this->select_shipping_rate( 'flat_rate' );
		$selected_shipping_rates = ShippingUtil::get_selected_shipping_rates_from_packages( WC()->shipping()->get_packages() );

		$this->assertNotFalse( $cart_item_key, 'The invalid-country checkout fixture product must be added to the cart.' );
		$this->assertGreaterThan( 0, wc_get_shipping_method_count( true ), 'The invalid-country checkout fixture must have shipping enabled.' );
		$this->assertTrue( WC()->cart->needs_shipping(), 'The invalid-country checkout fixture must need shipping.' );
		$this->assertCount( 1, $selected_shipping_rates, 'The invalid-country checkout fixture must select one shipping rate.' );
		$this->assertSame( 'flat_rate', reset( $selected_shipping_rates )->get_method_id(), 'The selected fixture rate must not be local pickup.' );

		$this->sut->validate_order_before_payment( $order );
	}

	/**
	 * @testdox Rejects an invalid shipping country for an existing non-local-pickup order with no selected live rate.
	 */
	public function test_validate_existing_order_before_payment_invalid_addresses() {
		$this->expectException( RouteException::class );
		$this->expectExceptionCode( 400 );
		$this->expectExceptionMessage( 'Sorry, we do not ship orders to the provided country (Invalid)' );

		$order = WC_Helper_Order::create_order();
		$order->set_shipping_country( 'Invalid' );
		$order->save();
		WC()->shipping()->reset_shipping();
		$selected_shipping_rates = ShippingUtil::get_selected_shipping_rates_from_packages( WC()->shipping()->get_packages() );
		$shipping_methods        = $order->get_shipping_methods();

		$this->assertTrue( $order->needs_shipping(), 'The invalid-country existing-order fixture must need shipping.' );
		$this->assertEmpty( $selected_shipping_rates, 'The existing-order fixture must not inherit a live selected shipping rate.' );
		$this->assertNotEmpty( $shipping_methods, 'The existing-order fixture must contain a persisted shipping method.' );
		foreach ( $shipping_methods as $shipping_method ) {
			$this->assertNotContains( $shipping_method->get_method_id(), LocalPickupUtils::get_local_pickup_method_ids(), 'Every persisted fixture method must be non-local pickup.' );
		}

		// validate_addresses() inspects the selected shipping rates from the global cart's
		// packages even for existing orders, so the cart must contain the shippable product
		// for the shipping country check to run.
		/** @var \WC_Order_Item_Product $item */
		$array = $order->get_items();
		$item  = reset( $array );
		$this->assertInstanceOf( \WC_Order_Item_Product::class, $item );

		WC()->cart->add_to_cart( $item->get_product()->get_id() );

		$this->sut->validate_existing_order_before_payment( $order );
	}

	/**
	 * @testdox Allows an invalid shipping country for an existing local-pickup order with no selected live rate.
	 */
	public function test_validate_existing_local_pickup_order_allows_invalid_shipping_country_without_selected_rates(): void {
		$order = WC_Helper_Order::create_order();
		$this->set_shipping_address(
			$order,
			array(
				'country' => 'Invalid',
				'phone'   => '555-555-5555',
			)
		);

		$shipping_methods = $this->set_persisted_shipping_method_id( $order, 'local_pickup' );
		$this->assertNotEmpty( $shipping_methods, 'The existing-order fixture must contain a persisted shipping method.' );
		$order->save();

		WC()->shipping()->reset_shipping();
		$selected_shipping_rates = ShippingUtil::get_selected_shipping_rates_from_packages( WC()->shipping()->get_packages() );

		$this->assertTrue( $order->needs_shipping(), 'The local-pickup existing-order fixture must contain a shippable product.' );
		$this->assertEmpty( $selected_shipping_rates, 'The local-pickup existing-order fixture must not inherit a live selected shipping rate.' );
		$this->assertNull( $this->sut->validate_existing_order_before_payment( $order ) );
	}

	/**
	 * @testdox Uses a live local-pickup rate instead of an ordinary checkout order's persisted non-local method.
	 */
	public function test_validate_order_before_payment_uses_live_shipping_rate_authority(): void {
		$order = WC_Helper_Order::create_order();
		$this->set_shipping_address(
			$order,
			array(
				'country' => 'Invalid',
				'phone'   => '555-555-5555',
			)
		);
		$order->save();

		$shipping_methods = $order->get_shipping_methods();
		$this->assertNotEmpty( $shipping_methods, 'The ordinary checkout fixture must contain a persisted shipping method.' );
		foreach ( $shipping_methods as $shipping_method ) {
			$this->assertNotContains( $shipping_method->get_method_id(), LocalPickupUtils::get_local_pickup_method_ids(), 'The persisted fixture method must conflict with the live local-pickup rate.' );
		}

		/** @var \WC_Order_Item_Product $item */
		$order_items   = $order->get_items();
		$item          = reset( $order_items );
		$cart_item_key = WC()->cart->add_to_cart( $item->get_product()->get_id() );
		$this->select_shipping_rate( 'local_pickup' );

		$this->assertNotFalse( $cart_item_key, 'The ordinary checkout fixture product must be added to the cart.' );
		$this->assertNull( $this->sut->validate_order_before_payment( $order ) );
	}

	/**
	 * @testdox Ignores a live local-pickup rate when an existing order has persisted non-local delivery.
	 */
	public function test_validate_existing_non_local_order_ignores_live_local_pickup_rate(): void {
		$this->expectException( RouteException::class );
		$this->expectExceptionCode( 400 );
		$this->expectExceptionMessage( 'Sorry, we do not ship orders to the provided country (Invalid)' );

		$order = WC_Helper_Order::create_order();
		$this->set_shipping_address(
			$order,
			array(
				'country' => 'Invalid',
				'phone'   => '555-555-5555',
			)
		);
		$order->save();
		$this->select_shipping_rate( 'local_pickup' );
		$shipping_methods        = $order->get_shipping_methods();
		$selected_shipping_rates = ShippingUtil::get_selected_shipping_rates_from_packages( WC()->shipping()->get_packages() );

		$this->assertNotEmpty( $shipping_methods, 'The existing order must contain persisted non-local delivery.' );
		$this->assertNotContains( reset( $shipping_methods )->get_method_id(), LocalPickupUtils::get_local_pickup_method_ids(), 'The persisted order method must be non-local pickup.' );
		$this->assertSame( 'local_pickup', reset( $selected_shipping_rates )->get_method_id(), 'The unrelated live rate must conflict as local pickup.' );

		$this->sut->validate_existing_order_before_payment( $order );
	}

	/**
	 * @testdox Ignores a live non-local rate when an existing order has persisted local pickup.
	 */
	public function test_validate_existing_local_pickup_order_ignores_live_non_local_rate(): void {
		$order = WC_Helper_Order::create_order();
		$this->set_shipping_address(
			$order,
			array(
				'country' => 'Invalid',
				'phone'   => '555-555-5555',
			)
		);
		$shipping_methods = $this->set_persisted_shipping_method_id( $order, 'local_pickup' );
		$order->save();
		$this->select_shipping_rate( 'flat_rate' );
		$selected_shipping_rates = ShippingUtil::get_selected_shipping_rates_from_packages( WC()->shipping()->get_packages() );

		$this->assertSame( 'local_pickup', reset( $shipping_methods )->get_method_id(), 'The persisted order method must be local pickup.' );
		$this->assertSame( 'flat_rate', reset( $selected_shipping_rates )->get_method_id(), 'The unrelated live rate must conflict as non-local delivery.' );

		$this->assertNull( $this->sut->validate_existing_order_before_payment( $order ) );
	}

	/**
	 * @testdox Rejects an invalid shipping country when an existing shippable order has no persisted or live shipping methods.
	 */
	public function test_validate_existing_order_rejects_invalid_country_without_any_shipping_methods(): void {
		$this->expectException( RouteException::class );
		$this->expectExceptionCode( 400 );
		$this->expectExceptionMessage( 'Sorry, we do not ship orders to the provided country (Invalid)' );

		$order = WC_Helper_Order::create_order();
		$order->set_shipping_country( 'Invalid' );
		foreach ( $order->get_shipping_methods() as $shipping_method ) {
			$order->remove_item( $shipping_method->get_id() );
		}
		$order->save();
		WC()->shipping()->reset_shipping();

		$this->assertTrue( $order->needs_shipping(), 'The method-less existing-order fixture must contain a shippable product.' );
		$this->assertEmpty( $order->get_shipping_methods(), 'The existing order must not contain a persisted shipping method.' );
		$this->assertEmpty( ShippingUtil::get_selected_shipping_rates_from_packages( WC()->shipping()->get_packages() ), 'The existing-order fixture must not inherit a live selected shipping rate.' );

		$this->sut->validate_existing_order_before_payment( $order );
	}

	/**
	 * @testdox Existing-order validators continue to dispatch protected address-validation overrides.
	 */
	public function test_existing_order_validators_dispatch_validate_addresses_override(): void {
		$controller = new class() extends OrderController {
			/**
			 * Number of protected address-validation override calls.
			 *
			 * @var int
			 */
			public $validate_addresses_calls = 0;

			/**
			 * Record dynamic dispatch through the protected extension point.
			 *
			 * @param \WC_Order $order Order object.
			 * @param bool      $needs_shipping Whether the order needs shipping.
			 */
			protected function validate_addresses( \WC_Order $order, bool $needs_shipping ) { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found
				++$this->validate_addresses_calls;
			}
		};

		$order = WC_Helper_Order::create_order();
		$this->set_shipping_address( $order, array( 'phone' => '555-555-5555' ) );
		$order->save();

		$controller->validate_existing_order_before_payment( $order );
		$this->assertSame( 1, $controller->validate_addresses_calls, 'Existing-order payment validation must dispatch validate_addresses() exactly once.' );
		$controller->validate_existing_order_before_update( $order );
		$this->assertSame( 2, $controller->validate_addresses_calls, 'Existing-order update validation must dispatch validate_addresses() exactly once.' );
	}

	/**
	 * @testdox Existing-order shipping-method authority is restored after address validation throws.
	 */
	public function test_existing_order_shipping_method_authority_is_restored_after_exception(): void {
		$order = WC_Helper_Order::create_order();
		$this->set_shipping_address(
			$order,
			array(
				'country' => 'Invalid',
				'phone'   => '555-555-5555',
			)
		);
		$order->save();
		$this->select_shipping_rate( 'local_pickup' );

		try {
			$this->sut->validate_existing_order_before_payment( $order );
			$this->fail( 'Persisted non-local delivery must reject the invalid shipping country.' );
		} catch ( RouteException $error ) {
			$this->assertSame( 400, $error->getCode() );
		}

		/** @var \WC_Order_Item_Product $item */
		$order_items   = $order->get_items();
		$item          = reset( $order_items );
		$cart_item_key = WC()->cart->add_to_cart( $item->get_product()->get_id() );
		$this->select_shipping_rate( 'local_pickup' );

		$this->assertNotFalse( $cart_item_key, 'The ordinary checkout fixture product must be added to the cart.' );
		$this->assertNull( $this->sut->validate_order_before_payment( $order ), 'Ordinary checkout must regain live local-pickup authority after the existing-order exception.' );
	}

	/**
	 * test_validate_order_before_payment_invalid_billing_country.
	 */
	public function test_validate_order_before_payment_invalid_billing_country() {
		$this->expectException( RouteException::class );
		$this->expectExceptionCode( 400 );
		$this->expectExceptionMessage( 'Sorry, we do not allow orders from the provided country (Invalid)' );

		$order = WC_Helper_Order::create_order();
		$order->set_billing_country( 'Invalid' );
		$this->set_shipping_address( $order );
		$order->save();

		$this->sut->validate_order_before_payment( $order );
	}

	/**
	 * test_validate_order_before_payment_missing_required_billing_fields.
	 */
	public function test_validate_order_before_payment_missing_required_billing_fields() {
		$this->expectException( RouteException::class );
		$this->expectExceptionCode( 400 );
		$this->expectExceptionMessage( 'There was a problem with the provided billing address: First name is required, Last name is required' );

		$order = WC_Helper_Order::create_order();
		// Clear required billing fields.
		$order->set_billing_first_name( '' );
		$order->set_billing_last_name( '' );
		$this->set_shipping_address( $order );
		$order->save();

		$this->sut->validate_order_before_payment( $order );
	}

	/**
	 * test_validate_order_before_payment_valid_coupon.
	 */
	public function test_validate_order_before_payment_valid_coupon() {
		$order = WC_Helper_Order::create_order();
		$this->set_shipping_address( $order );

		// Create a coupon without restrictions.
		$coupon = CouponHelper::create_coupon( 'valid-coupon' );
		$order->apply_coupon( $coupon );
		$order->save();

		$this->sut->validate_order_before_payment( $order );
		$this->assertEquals( array( 'valid-coupon' ), $order->get_coupon_codes() );
	}

	/**
	 * test_validate_address_fields_valid_address.
	 */
	public function test_validate_address_fields_valid_address() {
		$order = WC_Helper_Order::create_order();
		$this->set_shipping_address( $order );
		$order->save();

		$errors = new \WP_Error();
		$this->sut->validate_address_fields( $order, 'shipping', $errors );

		$this->assertEmpty( $errors->get_error_messages() );
	}

	/**
	 * test_validate_address_fields_invalid_address.
	 */
	public function test_validate_address_fields_invalid_address() {
		$order = WC_Helper_Order::create_order();
		$this->set_shipping_address(
			$order,
			[
				'postcode' => '',
			]
		);
		$order->save();

		$errors = new \WP_Error();
		$this->sut->validate_address_fields( $order, 'shipping', $errors );
		$this->assertEquals( 'ZIP Code is required', $errors->get_error_message() );
	}
	/**
	 * test_validate_address_fields_invalid_address.
	 */
	public function test_validate_address_fields_required_hidden_fields_not_validates() {
		$order = WC_Helper_Order::create_order();
		$this->set_shipping_address(
			$order,
			[
				'postcode' => '',
			]
		);
		$order->save();

		/**
		 * Hide the postcode field for US locale.
		 *
		 * @param array $locales All country locales.
		 *
		 * @return array
		 */
		$hide_postcode = function ( $locales ) {
			$locales['US']['postcode']['hidden'] = true;
			return $locales;
		};

		add_filter( 'woocommerce_get_country_locale', $hide_postcode );

		$errors = new \WP_Error();
		$this->sut->validate_address_fields( $order, 'shipping', $errors );
		$this->assertEmpty( $errors->get_error_messages() );
		remove_filter( 'woocommerce_get_country_locale', $hide_postcode );
	}

	/**
	 * @testdox create_order_from_cart() removes its woocommerce_default_order_status filter even when the order update throws.
	 */
	public function test_create_order_from_cart_removes_default_order_status_filter_on_exception(): void {
		$hook           = 'woocommerce_default_order_status';
		$filters_before = has_filter( $hook );

		$product = WC_Helper_Product::create_simple_product();
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $product->get_id() );
		$this->assertFalse( WC()->cart->is_empty(), 'The cart must be non-empty so create_order_from_cart() reaches the filter logic instead of throwing for an empty cart.' );

		$thrower = static function () {
			throw new \RuntimeException( 'Forced failure during totals calculation.' );
		};
		add_action( 'woocommerce_before_calculate_totals', $thrower );

		$threw = false;
		try {
			$this->sut->create_order_from_cart();
		} catch ( \Throwable $e ) {
			$threw = true;
		} finally {
			remove_action( 'woocommerce_before_calculate_totals', $thrower );
		}

		$this->assertTrue( $threw, 'The injected exception should propagate out of create_order_from_cart().' );
		$this->assertSame(
			$filters_before,
			has_filter( $hook ),
			'create_order_from_cart() must remove the woocommerce_default_order_status filter even when the order update throws.'
		);
	}

	/**
	 * @testdox create_order_from_cart() leaves no woocommerce_default_order_status callbacks registered, so the filter chain does not grow across calls.
	 */
	public function test_create_order_from_cart_removes_default_order_status_filter(): void {
		$hook           = 'woocommerce_default_order_status';
		$filters_before = has_filter( $hook );

		$product = WC_Helper_Product::create_simple_product();
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $product->get_id() );
		$this->assertFalse( WC()->cart->is_empty(), 'The cart must be non-empty so create_order_from_cart() runs to completion.' );

		$this->sut->create_order_from_cart();
		$this->sut->create_order_from_cart();

		$this->assertSame(
			$filters_before,
			has_filter( $hook ),
			'create_order_from_cart() must remove its woocommerce_default_order_status filter; the chain must not grow across repeated calls.'
		);
	}

	/**
	 * Helper method to set shipping address on an order.
	 *
	 * @param \WC_Order $order Order object.
	 * @param array     $override_data Optional data to override the default shipping address.
	 */
	private function set_shipping_address( \WC_Order $order, $override_data = [] ) {
		$order->set_shipping_country( 'US' );
		$order->set_shipping_first_name( 'John' );
		$order->set_shipping_last_name( 'Doe' );
		$order->set_shipping_address_1( '123 Test St' );
		$order->set_shipping_city( 'Test City' );
		$order->set_shipping_state( 'CA' );
		$order->set_shipping_postcode( '12345' );
		$order->set_shipping_phone( '555-32123' );

		foreach ( $override_data as $key => $value ) {
			$order->{"set_shipping_$key"}( $value );
		}
	}

	/**
	 * Set every persisted order shipping method to one method ID.
	 *
	 * @param \WC_Order $order Order object.
	 * @param string    $method_id Shipping method ID.
	 * @return \WC_Order_Item_Shipping[]
	 */
	private function set_persisted_shipping_method_id( \WC_Order $order, string $method_id ): array {
		$shipping_methods = $order->get_shipping_methods();
		foreach ( $shipping_methods as $shipping_method ) {
			$shipping_method->set_method_id( $method_id );
			$shipping_method->save();
		}

		return $shipping_methods;
	}

	/**
	 * Select a deterministic shipping rate.
	 *
	 * @param string $method_id Shipping method ID.
	 */
	private function select_shipping_rate( string $method_id ): void {
		$rate = new \WC_Shipping_Rate( $method_id . ':1', 'Shipping rate', 0, array(), $method_id, 1 );

		WC()->shipping()->packages = array(
			array(
				'rates' => array(
					$rate->get_id() => $rate,
				),
			),
		);
		WC()->session->set( 'chosen_shipping_methods', array( $rate->get_id() ) );
	}

	/**
	 * Capture exact option existence and values for the shipping fixture.
	 *
	 * @return array<string, array{exists: bool, value: mixed}>
	 */
	private static function capture_shipping_options(): array {
		$captured_options      = array();
		$missing_option_marker = new \stdClass();

		foreach ( self::SHIPPING_OPTION_NAMES as $option_name ) {
			$option_value                     = get_option( $option_name, $missing_option_marker );
			$option_exists                    = $missing_option_marker !== $option_value;
			$captured_options[ $option_name ] = array(
				'exists' => $option_exists,
				'value'  => $option_exists ? $option_value : null,
			);
		}

		return $captured_options;
	}

	/**
	 * Restore exact option existence and values for the shipping fixture.
	 *
	 * @param array<string, array{exists: bool, value: mixed}> $captured_options Captured shipping options.
	 */
	private static function restore_shipping_options( array $captured_options ): void {
		foreach ( $captured_options as $option_name => $original_option ) {
			if ( $original_option['exists'] ) {
				update_option( $option_name, $original_option['value'] );
			} else {
				delete_option( $option_name );
			}
		}
	}
}
