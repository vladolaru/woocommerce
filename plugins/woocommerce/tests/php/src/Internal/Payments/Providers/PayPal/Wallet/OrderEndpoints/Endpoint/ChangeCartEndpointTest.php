<?php
/**
 * Tests for the change-cart endpoint.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint;

use Automattic\WooCommerce\Internal\Caches\ProductCache;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PurchaseUnit;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PurchaseUnitFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Exception\NonceValidationException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\ChangeCartEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\RequestData;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Helper\CartProductsHelper;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Mockery\MockInterface;
use WC_Data_Store;
use WC_Helper_Product;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WC_Shipping;

/**
 * Replacing the cart with the posted products: simple and variable products go into the real cart, the shipping is
 * reset unless the request keeps it, and the response carries the purchase unit of the new cart. The endpoint ends the
 * request with a real wp_send_json_*().
 *
 * A booking product with its posted booking data is not covered: that needs the WooCommerce Bookings function that reads
 * the data, which core's suite does not load. What a booking product does without it is pinned instead.
 *
 * @group paypal-wallet
 */
class ChangeCartEndpointTest extends WalletTestCase {

	/**
	 * The request reader mock.
	 *
	 * @var RequestData&MockInterface
	 */
	private $request_data;

	/**
	 * The shipping mock.
	 *
	 * @var WC_Shipping&MockInterface
	 */
	private $shipping;

	/**
	 * The purchase unit factory mock.
	 *
	 * @var PurchaseUnitFactory&MockInterface
	 */
	private $purchase_unit_factory;

	/**
	 * The System Under Test.
	 *
	 * @var ChangeCartEndpoint
	 */
	private $sut;

	/**
	 * Build the endpoint over the real cart, with a session and an empty cart of its own.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->use_own_wc_session();
		if ( ! WC()->cart ) {
			wc_load_cart();
		}
		WC()->cart->empty_cart();

		$this->request_data          = $this->mock( RequestData::class );
		$this->shipping              = $this->mock( WC_Shipping::class );
		$this->purchase_unit_factory = $this->mock( PurchaseUnitFactory::class );

		$this->sut = new ChangeCartEndpoint(
			WC()->cart,
			$this->shipping,
			$this->request_data,
			$this->purchase_unit_factory,
			new CartProductsHelper( WC_Data_Store::load( 'product' ) ),
			$this->mock( LoggerInterface::class )->shouldIgnoreMissing()
		);
	}

	/**
	 * Make the request carry the given data. The endpoint reads the request twice.
	 *
	 * @param array $data The request data.
	 */
	private function stub_request( array $data ): void {
		$this->request_data->shouldReceive( 'read_request' )->times( 2 )->with( ChangeCartEndpoint::nonce() )->andReturn( $data );
	}

	/**
	 * Make the purchase unit factory answer with a purchase unit that serialises to the given array.
	 *
	 * @param array $purchase_unit The purchase unit as an array.
	 */
	private function stub_purchase_unit( array $purchase_unit ): void {
		$pu = $this->mock( PurchaseUnit::class );
		$pu->shouldReceive( 'to_array' )->andReturn( $purchase_unit );
		$this->purchase_unit_factory->shouldReceive( 'from_wc_cart' )->once()->andReturn( $pu );
	}

	/**
	 * Run the handler and return the JSON it sent.
	 *
	 * @return array
	 */
	private function run_handler(): array {
		return $this->run_ajax_handler( array( $this->sut, 'handle_request' ) );
	}

	/**
	 * A saved variable product with one variation per given size.
	 *
	 * @param string[] $sizes The sizes.
	 * @return array{0: WC_Product_Variable, 1: array<string, int>} The product and the variation IDs by size.
	 */
	private function create_variable_product( array $sizes ): array {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( $sizes );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$product = new WC_Product_Variable();
		$product->set_name( 'Variable product' );
		$product->set_attributes( array( $attribute ) );
		$product->save();

		$variation_ids = array();
		foreach ( $sizes as $size ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_attributes( array( 'size' => $size ) );
			$variation->set_regular_price( '15' );
			$variation->save();
			$variation_ids[ $size ] = $variation->get_id();
		}

		return array( wc_get_product( $product->get_id() ), $variation_ids );
	}

	/**
	 * The quantity in the cart of each product, by product and variation ID.
	 *
	 * @return array<string, int>
	 */
	private function cart_quantities(): array {
		$quantities = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$quantities[ $item['product_id'] . ':' . $item['variation_id'] ] = $item['quantity'];
		}

		return $quantities;
	}

	/**
	 * @testdox Should put the posted product in the cart, with its quantity, and answer with the purchase unit of the cart.
	 */
	public function test_products_default(): void {
		$product = WC_Helper_Product::create_simple_product();
		$this->stub_request(
			array(
				'products' => array(
					array(
						'quantity' => 2,
						'id'       => $product->get_id(),
					),
				),
			)
		);
		$this->shipping->shouldReceive( 'reset_shipping' )->once();
		$this->stub_purchase_unit( array( 'reference_id' => 'default' ) );

		$response = $this->run_handler();

		$this->assertTrue( $response['success'] );
		$this->assertSame( array( array( 'reference_id' => 'default' ) ), $response['data'] );
		$this->assertSame( array( $product->get_id() . ':0' => 2 ), $this->cart_quantities() );
	}

	/**
	 * @testdox Should add a variable product with the variation that matches the posted attributes, next to a simple product.
	 */
	public function test_products_variation(): void {
		$simple                     = WC_Helper_Product::create_simple_product();
		list( $variable, $by_size ) = $this->create_variable_product( array( 'small', 'large' ) );
		$this->stub_request(
			array(
				'products' => array(
					array(
						'quantity' => 2,
						'id'       => $simple->get_id(),
					),
					array(
						'quantity'   => 3,
						'id'         => $variable->get_id(),
						'variations' => array(
							array(
								'name'  => 'attribute_size',
								'value' => 'large',
							),
						),
					),
				),
			)
		);
		$this->shipping->shouldReceive( 'reset_shipping' )->once();
		$this->stub_purchase_unit( array( 'reference_id' => 'default' ) );

		$response = $this->run_handler();

		$this->assertTrue( $response['success'] );
		$this->assertSame(
			array(
				$simple->get_id() . ':0' => 2,
				$variable->get_id() . ':' . $by_size['large'] => 3,
			),
			$this->cart_quantities()
		);
	}

	/**
	 * @testdox Should empty the cart before adding the posted products, so earlier items do not stay.
	 */
	public function test_replaces_the_items_of_the_cart(): void {
		$earlier = WC_Helper_Product::create_simple_product();
		$posted  = WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $earlier->get_id(), 4 );
		$this->stub_request(
			array(
				'products' => array(
					array(
						'quantity' => 1,
						'id'       => $posted->get_id(),
					),
				),
			)
		);
		$this->shipping->shouldReceive( 'reset_shipping' )->once();
		$this->stub_purchase_unit( array() );

		$this->run_handler();

		$this->assertSame( array( $posted->get_id() . ':0' => 1 ), $this->cart_quantities() );
	}

	/**
	 * @testdox Should reset the shipping unless the request asks to keep it: keepShipping is $keep_shipping, so reset_shipping() runs $expected_resets time(s).
	 * @dataProvider keep_shipping_data
	 *
	 * @param bool $keep_shipping    The keepShipping flag of the request.
	 * @param int  $expected_resets  How many times the shipping is reset.
	 */
	public function test_resets_shipping_unless_the_request_keeps_it( bool $keep_shipping, int $expected_resets ): void {
		$product = WC_Helper_Product::create_simple_product();
		$this->stub_request(
			array(
				'keepShipping' => $keep_shipping,
				'products'     => array(
					array(
						'quantity' => 1,
						'id'       => $product->get_id(),
					),
				),
			)
		);
		$this->shipping->shouldReceive( 'reset_shipping' )->times( $expected_resets );
		$this->stub_purchase_unit( array() );

		$response = $this->run_handler();

		$this->assertTrue( $response['success'] );
	}

	/**
	 * Values of the keepShipping flag.
	 *
	 * @return array
	 */
	public function keep_shipping_data(): array {
		return array(
			'keepShipping false' => array( false, 1 ),
			'keepShipping true'  => array( true, 0 ),
		);
	}

	/**
	 * @testdox Should keep the shipping method the shopper chose when the cart is replaced.
	 */
	public function test_keeps_the_chosen_shipping_methods_across_the_change(): void {
		$product = WC_Helper_Product::create_simple_product();
		WC()->session->set( 'chosen_shipping_methods', array( 'flat_rate:1' ) );
		$this->stub_request(
			array(
				'keepShipping' => true,
				'products'     => array(
					array(
						'quantity' => 1,
						'id'       => $product->get_id(),
					),
				),
			)
		);
		$this->stub_purchase_unit( array() );

		$this->run_handler();

		$this->assertSame( array( 'flat_rate:1' ), WC()->session->get( 'chosen_shipping_methods' ) );
	}

	/**
	 * @testdox Should empty the cart but keep the persistent cart of the logged-in user.
	 */
	public function test_empties_the_cart_but_keeps_the_persistent_cart(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$meta_key = '_woocommerce_persistent_cart_' . get_current_blog_id();
		update_user_meta( $user_id, $meta_key, array( 'cart' => array( 'earlier-item' => array( 'quantity' => 1 ) ) ) );
		$product = WC_Helper_Product::create_simple_product();
		$product->set_stock_status( 'outofstock' );
		$product->save();
		$emptied = $this->spy_filter( 'woocommerce_before_cart_emptied' );
		$this->stub_request(
			array(
				'products' => array(
					array(
						'quantity' => 1,
						'id'       => $product->get_id(),
					),
				),
			)
		);
		$this->shipping->shouldReceive( 'reset_shipping' )->once();
		$this->purchase_unit_factory->shouldNotReceive( 'from_wc_cart' );

		$response = $this->run_handler();

		$this->assertFalse( $response['success'] );
		$cleared_persistent_cart = array_map(
			static function ( $call ) {
				return $call[0];
			},
			$emptied->getArrayCopy()
		);
		$this->assertSame( array( false ), $cleared_persistent_cart, 'The cart is emptied once, without clearing the persistent cart' );
		$this->assertSame( array( 'cart' => array( 'earlier-item' => array( 'quantity' => 1 ) ) ), get_user_meta( $user_id, $meta_key, true ), 'The persistent cart stays as it was' );
	}

	/**
	 * @testdox Should answer with the notice of the cart and leave the cart empty when a product cannot be added.
	 */
	public function test_answers_with_the_cart_notice_when_a_product_cannot_be_added(): void {
		$product = WC_Helper_Product::create_simple_product();
		$product->set_stock_status( 'outofstock' );
		$product->save();
		$this->stub_request(
			array(
				'products' => array(
					array(
						'quantity' => 1,
						'id'       => $product->get_id(),
					),
				),
			)
		);
		$this->shipping->shouldReceive( 'reset_shipping' )->once();
		$this->purchase_unit_factory->shouldNotReceive( 'from_wc_cart' );

		$response = $this->run_handler();

		$this->assertFalse( $response['success'] );
		$this->assertStringContainsString( 'out of stock', $response['data']['message'] );
		$this->assertSame( array(), $this->cart_quantities() );
	}

	/**
	 * Without WooCommerce Bookings the cart cannot take a booking product.
	 *
	 * @testdox Should answer with an error and leave the cart empty when a booking product is posted and WooCommerce Bookings is not active.
	 */
	public function test_a_booking_product_is_rejected_without_woocommerce_bookings(): void {
		$product = WC_Helper_Product::create_simple_product();
		$this->booking_type_for( $product );
		$this->stub_request(
			array(
				'products' => array(
					array(
						'quantity' => 1,
						'id'       => $product->get_id(),
						'booking'  => array( '_duration' => 2 ),
					),
				),
			)
		);
		$this->shipping->shouldReceive( 'reset_shipping' )->once();
		$this->purchase_unit_factory->shouldNotReceive( 'from_wc_cart' );

		$response = $this->run_handler();

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Something went wrong. Action aborted', $response['data']['message'] );
		$this->assertSame( array(), $this->cart_quantities() );
	}

	/**
	 * Make WooCommerce load the given product as a booking product.
	 *
	 * @param WC_Product $product The saved product.
	 */
	private function booking_type_for( WC_Product $product ): void {
		$booking_class = get_class(
			new class() extends WC_Product_Simple {
				/**
				 * The product type.
				 *
				 * @return string
				 */
				public function get_type() {
					return 'booking';
				}
			}
		);

		add_filter(
			'woocommerce_product_type_query',
			static function ( $type, $product_id ) use ( $product ) {
				return $product_id === $product->get_id() ? 'booking' : $type;
			},
			10,
			2
		);
		add_filter(
			'woocommerce_product_class',
			static function ( $classname, $product_type ) use ( $booking_class ) {
				return 'booking' === $product_type ? $booking_class : $classname;
			},
			10,
			2
		);
		// The product instance cache would hand back the simple product saved a moment ago.
		wc_get_container()->get( ProductCache::class )->remove( $product->get_id() );
		$this->assertTrue( wc_get_product( $product->get_id() )->is_type( 'booking' ), 'The product loads as a booking product' );
	}

	/**
	 * @testdox Should answer with an error and leave the cart alone when the request names no product.
	 */
	public function test_answers_with_an_error_when_no_products_are_posted(): void {
		$earlier = WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $earlier->get_id(), 1 );
		$this->stub_request( array() );
		$this->shipping->shouldNotReceive( 'reset_shipping' );
		$this->purchase_unit_factory->shouldNotReceive( 'from_wc_cart' );

		$response = $this->run_handler();

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Necessary fields not defined. Action aborted.', $response['data']['message'] );
		$this->assertSame( array( $earlier->get_id() . ':0' => 1 ), $this->cart_quantities() );
	}

	/**
	 * @testdox Should answer with an error message and leave the cart alone when the nonce is invalid.
	 */
	public function test_answers_with_an_error_when_the_nonce_is_invalid(): void {
		$earlier = WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $earlier->get_id(), 1 );
		$this->request_data->shouldReceive( 'read_request' )->once()->andThrow( new NonceValidationException( 'Could not validate nonce.' ) );
		$this->shipping->shouldNotReceive( 'reset_shipping' );

		$response = $this->run_handler();

		$this->assertFalse( $response['success'] );
		$this->assertSame( array( 'message' => 'Could not validate nonce.' ), $response['data'] );
		$this->assertSame( array( $earlier->get_id() . ':0' => 1 ), $this->cart_quantities() );
	}
}
