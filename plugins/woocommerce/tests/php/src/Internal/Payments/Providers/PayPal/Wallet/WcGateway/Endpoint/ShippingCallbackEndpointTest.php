<?php
/**
 * Tests for the PayPal shipping callback endpoint.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Amount;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\AmountFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ItemFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\MoneyFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\CurrencyGetter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Session\CartData;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Session\CartDataTransientStorage;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Endpoint\ShippingCallbackEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Endpoint\CartEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Entity\Cart;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Entity\CartResponse;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Entity\CartTotals;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Entity\Money as StoreApiMoney;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Factory\CartFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Factory\CartTotalsFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Factory\MoneyFactory as StoreApiMoneyFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Factory\ShippingRate;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Factory\ShippingRatesFactory;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\NullLogger;
use Exception;
use Mockery\MockInterface;
use WP_REST_Request;
use WpOrg\Requests\Utility\CaseInsensitiveDictionary;

/**
 * The callback PayPal calls when the buyer picks an address or a shipping option in the PayPal window: it updates the
 * customer of the cart through the Store API and answers with the shipping options and the new total, or with a 422
 * when PayPal cannot be answered. The Store API cart endpoint and the amount factory are Mockery doubles in the unit
 * cases; the countries and their states are the real WooCommerce ones, the PayPal order lookup runs over real
 * transients, and one case runs the real cart endpoint over a stubbed HTTP layer. The endpoint never reads the
 * shopper's own cart or session: it works from the cart token that travels in the request.
 *
 * @group paypal-wallet
 */
class ShippingCallbackEndpointTest extends WalletTestCase {

	private const CART_TOKEN      = 'wc-cart-token';
	private const PAYPAL_ORDER_ID = '5O190127TN364715T';

	/**
	 * The cart endpoint double.
	 *
	 * @var CartEndpoint&MockInterface
	 */
	private $cart_endpoint;

	/**
	 * The amount factory double.
	 *
	 * @var AmountFactory&MockInterface
	 */
	private $amount_factory;

	/**
	 * The cart data storage, over real transients.
	 *
	 * @var CartDataTransientStorage
	 */
	private CartDataTransientStorage $cart_data_storage;

	/**
	 * The endpoint under test.
	 *
	 * @var ShippingCallbackEndpoint
	 */
	private ShippingCallbackEndpoint $sut;

	/**
	 * Build the endpoint over the doubles.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->cart_endpoint     = $this->mock( CartEndpoint::class );
		$this->amount_factory    = $this->mock( AmountFactory::class );
		$this->cart_data_storage = new CartDataTransientStorage();

		$this->sut = new ShippingCallbackEndpoint(
			$this->cart_endpoint,
			$this->amount_factory,
			new NullLogger(),
			$this->cart_data_storage
		);
	}

	/**
	 * Store a cart for a PayPal order, the way creating the order does, and have the test base delete its transients.
	 *
	 * @param string $paypal_order_id The PayPal order ID.
	 */
	private function store_cart_for_order( string $paypal_order_id ): void {
		$cart_data = new CartData( array(), array(), true, 0, 'cart-hash' );
		$cart_data->generate_key();
		$cart_data->set_paypal_order_id( $paypal_order_id );

		$this->set_wallet_transient( (string) $cart_data->key(), '' );
		// The storage's own key for the lookup by PayPal order ID, repeated here so the transient is cleaned up.
		$this->set_wallet_transient( 'ppcp_cart_by_order_' . $paypal_order_id, '' );
		$this->cart_data_storage->save( $cart_data );
	}

	/**
	 * A request with the given decoded body and the fixed cart token, the way the PayPal shipping callback sends them.
	 *
	 * @param array $params The body parameters.
	 * @return WP_REST_Request
	 */
	private function build_request( array $params ): WP_REST_Request {
		$params['cart_token'] = self::CART_TOKEN;

		$request = new WP_REST_Request( 'POST', '/paypal/v1/shipping-callback' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$request->set_body( (string) wp_json_encode( $params ) );

		return $request;
	}

	/**
	 * A Store API cart response with the given shipping rates.
	 *
	 * @param ShippingRate[] $shipping_rates The shipping rates.
	 * @return CartResponse
	 */
	private function cart_response_with_rates( array $shipping_rates ): CartResponse {
		$cart = new Cart( $this->mock( CartTotals::class ), $shipping_rates );

		return new CartResponse( $cart, self::CART_TOKEN );
	}

	/**
	 * A Store API shipping rate.
	 *
	 * @param string $rate_id     The rate ID.
	 * @param string $name        The name.
	 * @param int    $price_cents The price in cents.
	 * @param bool   $selected    Whether the rate is selected.
	 * @return ShippingRate
	 */
	private function shipping_rate( string $rate_id, string $name, int $price_cents, bool $selected = true ): ShippingRate {
		return new ShippingRate(
			$rate_id,
			$name,
			$selected,
			new StoreApiMoney( (string) $price_cents, 'USD', 2 ),
			new StoreApiMoney( '0', 'USD', 2 )
		);
	}

	/**
	 * Expect one customer update with exactly the given address fields and answer it with one flat rate. The fields are
	 * compared strictly, so a field that is null instead of an empty string, or an extra field, fails the case.
	 *
	 * @param array $address The expected address fields.
	 */
	private function expect_customer_update( array $address ): void {
		$this->cart_endpoint
			->shouldReceive( 'update_customer' )
			->once()
			->withArgs(
				static function ( $cart_token, $fields ) use ( $address ): bool {
					return self::CART_TOKEN === $cart_token && array( 'shipping_address' => $address ) === $fields;
				}
			)
			->andReturn( $this->cart_response_with_rates( array( $this->shipping_rate( 'flat_rate:1', 'Flat rate', 500 ) ) ) );
	}

	/**
	 * Let the amount factory answer with an empty amount, for the cases that do not look at it.
	 */
	private function allow_any_amount(): void {
		$amount = $this->mock( Amount::class );
		$amount->shouldReceive( 'to_array' )->andReturn( array() );
		$this->amount_factory->shouldReceive( 'from_store_api_cart' )->andReturn( $amount );
	}

	/**
	 * @testdox Should accept a request with a cart token and a PayPal order ID that has a stored cart.
	 */
	public function test_verify_request_returns_true_when_order_id_is_known(): void {
		$this->store_cart_for_order( self::PAYPAL_ORDER_ID );

		$request = $this->build_request( array( 'id' => self::PAYPAL_ORDER_ID ) );

		$this->assertTrue( $this->sut->verify_request( $request ) );
	}

	/**
	 * @testdox Should refuse a request whose PayPal order ID has no stored cart (unknown or fabricated order).
	 */
	public function test_verify_request_returns_false_when_order_id_is_unknown(): void {
		$this->store_cart_for_order( self::PAYPAL_ORDER_ID );

		$request = $this->build_request( array( 'id' => 'UNKNOWN_ORDER_ID' ) );

		$this->assertFalse( $this->sut->verify_request( $request ) );
	}

	/**
	 * The storage is a double that fails the test when it is asked, so each row proves the guard on the parameters and
	 * not just a missing stored cart.
	 *
	 * @testdox Should refuse a request with an empty cart token or an empty PayPal order ID without consulting the storage.
	 *
	 * @dataProvider data_empty_required_params
	 *
	 * @param string $cart_token The cart token.
	 * @param string $order_id   The PayPal order ID.
	 */
	public function test_verify_request_returns_false_when_required_params_are_empty( string $cart_token, string $order_id ): void {
		$storage = $this->mock( CartDataTransientStorage::class );
		$storage->shouldNotReceive( 'get_by_paypal_order_id' );
		$sut = new ShippingCallbackEndpoint( $this->cart_endpoint, $this->amount_factory, new NullLogger(), $storage );

		$request = new WP_REST_Request( 'POST', '/paypal/v1/shipping-callback' );
		$request->set_param( 'cart_token', $cart_token );
		$request->set_param( 'id', $order_id );

		$this->assertFalse( $sut->verify_request( $request ) );
	}

	/**
	 * The request parameters that make a request unusable.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function data_empty_required_params(): array {
		return array(
			'empty cart token'              => array( '', self::PAYPAL_ORDER_ID ),
			'empty PayPal order ID'         => array( self::CART_TOKEN, '' ),
			'empty cart token and order ID' => array( '', '' ),
		);
	}

	/**
	 * GIVEN a PayPal shipping callback with a full address and a purchase unit reference
	 * WHEN handle_request() processes it and the Store API returns a shipping rate
	 * THEN the response is a 200 carrying the PayPal order id, the given reference_id
	 *      and the shipping options converted from the Store API rate
	 * AND the address forwarded to update_customer() never carries address_line_1/2,
	 *      which are PayPal field names the Store API does not understand
	 *
	 * @testdox Should answer 200 with the order ID, the reference ID and the shipping options on the happy path.
	 */
	public function test_handle_request_returns_success_payload_on_happy_path(): void {
		$request = $this->build_request(
			array(
				'id'               => self::PAYPAL_ORDER_ID,
				'purchase_units'   => array( array( 'reference_id' => 'PUI-1' ) ),
				'shipping_address' => array(
					'country_code'   => 'US',
					'admin_area_1'   => 'CA',
					'admin_area_2'   => 'Beverly Hills',
					'postal_code'    => '90210',
					'address_line_1' => '1 Hollywood Blvd',
					'address_line_2' => 'Suite 1',
				),
			)
		);

		$this->expect_customer_update(
			array(
				'country'  => 'US',
				'state'    => 'CA',
				'city'     => 'Beverly Hills',
				'postcode' => '90210',
			)
		);
		$this->cart_endpoint->shouldNotReceive( 'select_shipping_rate' );

		$amount = $this->mock( Amount::class );
		$amount->shouldReceive( 'to_array' )->andReturn(
			array(
				'currency_code' => 'USD',
				'value'         => '5.00',
			)
		);
		$this->amount_factory->shouldReceive( 'from_store_api_cart' )->andReturn( $amount );

		$response = $this->sut->handle_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( self::PAYPAL_ORDER_ID, $data['id'] );
		$this->assertSame( 'PUI-1', $data['purchase_units'][0]['reference_id'] );
		$this->assertSame(
			array(
				'currency_code' => 'USD',
				'value'         => '5.00',
			),
			$data['purchase_units'][0]['amount']
		);
		$this->assertSame( 'flat_rate:1', $data['purchase_units'][0]['shipping_options'][0]['id'] );
	}

	/**
	 * GIVEN a purchase unit that carries admin_area_1 "CA" for country_code "IE" (the state
	 *       value that used to crash the callback because Ireland has no such state)
	 * WHEN handle_request() converts the address for the Store API
	 * THEN the unrecognised state is dropped rather than forwarded, and the request still
	 *      succeeds instead of surfacing the Store API's invalid_state rejection as a fatal
	 *
	 * @testdox Should drop a state the country does not have instead of failing.
	 */
	public function test_handle_request_drops_unknown_state_instead_of_failing(): void {
		$request = $this->build_request(
			array(
				'id'               => self::PAYPAL_ORDER_ID,
				'shipping_address' => array(
					'country_code' => 'IE',
					'admin_area_1' => 'CA',
				),
			)
		);

		$this->expect_customer_update(
			array(
				'country'  => 'IE',
				'state'    => '',
				'city'     => '',
				'postcode' => '',
			)
		);
		$this->allow_any_amount();

		$response = $this->sut->handle_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * GIVEN a state value that matches the code or the name of a WooCommerce state for the country
	 * WHEN handle_request() converts the address for the Store API
	 * THEN the state is converted to its WooCommerce code before being forwarded
	 *
	 * @testdox Should convert the state to its WooCommerce code before forwarding it.
	 *
	 * @dataProvider data_state_conversion
	 *
	 * @param string $country        The country code.
	 * @param string $admin_area_1   The state PayPal sent.
	 * @param string $expected_state The state forwarded to the Store API.
	 */
	public function test_handle_request_converts_state_for_wc( string $country, string $admin_area_1, string $expected_state ): void {
		$request = $this->build_request(
			array(
				'id'               => self::PAYPAL_ORDER_ID,
				'shipping_address' => array(
					'country_code' => $country,
					'admin_area_1' => $admin_area_1,
				),
			)
		);

		$this->expect_customer_update(
			array(
				'country'  => $country,
				'state'    => $expected_state,
				'city'     => '',
				'postcode' => '',
			)
		);
		$this->allow_any_amount();

		$response = $this->sut->handle_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * The states PayPal sent and the WooCommerce state codes they lead to, over the real state lists.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public function data_state_conversion(): array {
		return array(
			'state code is upper-cased and passed through' => array( 'US', 'ca', 'CA' ),
			'state name is converted to its code'          => array( 'US', 'California', 'CA' ),
			'state name is matched case-insensitively'     => array( 'US', 'california', 'CA' ),
			'a code with a country prefix is passed through' => array( 'DE', 'de-by', 'DE-BY' ),
			'a state name with an accent is converted'     => array( 'DE', 'Baden-Württemberg', 'DE-BW' ),
		);
	}

	/**
	 * GIVEN a country that has no WooCommerce state list (France; this assumes WooCommerce lists no states for FR)
	 * WHEN handle_request() converts the address for the Store API
	 * THEN the state value is forwarded unchanged, since there is nothing to validate it against
	 *
	 * @testdox Should leave the state untouched when the country has no state list.
	 */
	public function test_handle_request_leaves_state_untouched_when_country_has_no_state_list(): void {
		$request = $this->build_request(
			array(
				'id'               => self::PAYPAL_ORDER_ID,
				'shipping_address' => array(
					'country_code' => 'FR',
					'admin_area_1' => 'Ile-de-France',
				),
			)
		);

		$this->expect_customer_update(
			array(
				'country'  => 'FR',
				'state'    => 'Ile-de-France',
				'city'     => '',
				'postcode' => '',
			)
		);
		$this->allow_any_amount();

		$response = $this->sut->handle_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * GIVEN a callback payload with no shipping_address at all
	 * WHEN handle_request() builds the address for the Store API
	 * THEN an address of empty fields is forwarded instead of a fatal on a missing array key
	 *
	 * @testdox Should forward an address of empty fields when the payload has no shipping address.
	 */
	public function test_handle_request_defaults_shipping_address_when_missing(): void {
		$request = $this->build_request( array( 'id' => self::PAYPAL_ORDER_ID ) );

		$this->expect_customer_update(
			array(
				'country'  => '',
				'state'    => '',
				'city'     => '',
				'postcode' => '',
			)
		);
		$this->allow_any_amount();

		$response = $this->sut->handle_request( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * GIVEN a callback payload with no purchase_units entry
	 * WHEN handle_request() builds the success response
	 * THEN the purchase unit falls back to the reference_id "default"
	 *
	 * @testdox Should fall back to the reference ID "default" when the payload has no purchase unit.
	 */
	public function test_handle_request_defaults_reference_id_when_purchase_units_missing(): void {
		$request = $this->build_request( array( 'id' => self::PAYPAL_ORDER_ID ) );

		$this->cart_endpoint
			->shouldReceive( 'update_customer' )
			->once()
			->andReturn( $this->cart_response_with_rates( array( $this->shipping_rate( 'flat_rate:1', 'Flat rate', 500 ) ) ) );
		$this->allow_any_amount();

		$data = $this->sut->handle_request( $request )->get_data();

		$this->assertSame( 'default', $data['purchase_units'][0]['reference_id'] );
	}

	/**
	 * GIVEN the Store API rejects the customer address update (e.g. an invalid address field)
	 * WHEN handle_request() calls update_customer()
	 * THEN the exception does not escape as a fatal; a 422 ADDRESS_ERROR response is returned
	 *
	 * @testdox Should answer 422 ADDRESS_ERROR when the Store API rejects the customer update.
	 */
	public function test_handle_request_returns_422_when_update_customer_fails(): void {
		$request = $this->build_request( array( 'id' => self::PAYPAL_ORDER_ID ) );

		$this->cart_endpoint
			->shouldReceive( 'update_customer' )
			->once()
			->andThrow( new Exception( 'cart/update-customer request return error: rest_invalid_param' ) );
		$this->cart_endpoint->shouldNotReceive( 'select_shipping_rate' );

		$response = $this->sut->handle_request( $request );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'ADDRESS_ERROR', $response->get_data()['details'][0]['issue'] );
	}

	/**
	 * GIVEN the Store API accepted the address but returned no shipping rates for it
	 * WHEN handle_request() inspects the cart response
	 * THEN a 422 ADDRESS_ERROR response is returned and no shipping rate is selected
	 *
	 * @testdox Should answer 422 ADDRESS_ERROR and select no rate when the Store API returns no shipping rates.
	 */
	public function test_handle_request_returns_422_when_no_shipping_rates_found(): void {
		$request = $this->build_request(
			array(
				'id'              => self::PAYPAL_ORDER_ID,
				'shipping_option' => array( 'id' => 'flat_rate:1' ),
			)
		);

		$this->cart_endpoint
			->shouldReceive( 'update_customer' )
			->once()
			->andReturn( $this->cart_response_with_rates( array() ) );
		$this->cart_endpoint->shouldNotReceive( 'select_shipping_rate' );

		$response = $this->sut->handle_request( $request );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'ADDRESS_ERROR', $response->get_data()['details'][0]['issue'] );
	}

	/**
	 * GIVEN the buyer picked a shipping option that the Store API then rejects
	 * WHEN handle_request() calls select_shipping_rate()
	 * THEN the exception does not escape as a fatal; a 422 METHOD_UNAVAILABLE response is returned
	 *
	 * @testdox Should answer 422 METHOD_UNAVAILABLE when the Store API rejects the chosen shipping option.
	 */
	public function test_handle_request_returns_422_when_select_shipping_rate_fails(): void {
		$request = $this->build_request(
			array(
				'id'              => self::PAYPAL_ORDER_ID,
				'shipping_option' => array( 'id' => 'flat_rate:2' ),
			)
		);

		$this->cart_endpoint
			->shouldReceive( 'update_customer' )
			->once()
			->andReturn( $this->cart_response_with_rates( array( $this->shipping_rate( 'flat_rate:1', 'Flat rate', 500 ) ) ) );
		$this->cart_endpoint
			->shouldReceive( 'select_shipping_rate' )
			->once()
			->with( self::CART_TOKEN, 0, 'flat_rate:2' )
			->andThrow( new Exception( 'cart/select-shipping-rate request return error: rest_invalid_param' ) );

		$response = $this->sut->handle_request( $request );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'METHOD_UNAVAILABLE', $response->get_data()['details'][0]['issue'] );
	}

	/**
	 * @testdox Should answer with the cart that the chosen shipping option produced, not the one before the choice.
	 */
	public function test_handle_request_answers_with_the_cart_after_selecting_the_shipping_option(): void {
		$request = $this->build_request(
			array(
				'id'              => self::PAYPAL_ORDER_ID,
				'shipping_option' => array( 'id' => 'flat_rate:2' ),
			)
		);

		$this->cart_endpoint
			->shouldReceive( 'update_customer' )
			->once()
			->andReturn( $this->cart_response_with_rates( array( $this->shipping_rate( 'flat_rate:1', 'Flat rate', 500 ) ) ) );
		$this->cart_endpoint
			->shouldReceive( 'select_shipping_rate' )
			->once()
			->with( self::CART_TOKEN, 0, 'flat_rate:2' )
			->andReturn(
				$this->cart_response_with_rates(
					array(
						$this->shipping_rate( 'flat_rate:1', 'Flat rate', 500, false ),
						$this->shipping_rate( 'flat_rate:2', 'Express', 1200 ),
					)
				)
			);
		$this->allow_any_amount();

		$response = $this->sut->handle_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$options = $response->get_data()['purchase_units'][0]['shipping_options'];
		$this->assertSame( array( 'flat_rate:1', 'flat_rate:2' ), array_column( $options, 'id' ) );
		$this->assertSame( array( false, true ), array_column( $options, 'selected' ), 'The chosen option should be the selected one' );
	}

	/**
	 * Runs the real cart endpoint, its factories and the real amount factory over a stubbed HTTP layer that plays the
	 * Store API, with a buyer who picked an option.
	 *
	 * @testdox Should call the Store API with the cart token and the converted address, then answer with its totals and rates.
	 */
	public function test_handle_request_over_the_real_store_api_client(): void {
		$cart_response = static function ( string $selected_rate_id ): array {
			$rates      = array();
			$rate_table = array(
				'flat_rate:1' => array( 'Flat rate', '500' ),
				'flat_rate:2' => array( 'Express', '1200' ),
			);
			foreach ( $rate_table as $rate_id => $rate ) {
				$rates[] = array(
					'rate_id'             => $rate_id,
					'name'                => $rate[0],
					'selected'            => $rate_id === $selected_rate_id,
					'price'               => $rate[1],
					'taxes'               => '0',
					'currency_code'       => 'USD',
					'currency_minor_unit' => 2,
				);
			}
			$shipping = 'flat_rate:2' === $selected_rate_id ? '1200' : '500';

			return array(
				'totals'         => array(
					'total_items'         => '1000',
					'total_items_tax'     => '0',
					'total_fees'          => '0',
					'total_fees_tax'      => '0',
					'total_discount'      => '0',
					'total_discount_tax'  => '0',
					'total_shipping'      => $shipping,
					'total_shipping_tax'  => '0',
					'total_price'         => (string) ( 1000 + (int) $shipping ),
					'total_tax'           => '0',
					'currency_code'       => 'USD',
					'currency_minor_unit' => 2,
				),
				'shipping_rates' => array( array( 'shipping_rates' => $rates ) ),
			);
		};

		$store_api_url = rest_url( '/wc/store/v1/' );
		$this->stub_http(
			function ( $request, $url ) use ( $cart_response, $store_api_url ) {
				$selected = $store_api_url . 'cart/select-shipping-rate' === $url ? 'flat_rate:2' : 'flat_rate:1';
				$response = $this->http_response( 200, (string) wp_json_encode( $cart_response( $selected ) ) );

				$response['headers'] = new CaseInsensitiveDictionary( array( 'cart-token' => self::CART_TOKEN ) );

				return $response;
			}
		);

		$money_factory = new StoreApiMoneyFactory();
		$sut           = new ShippingCallbackEndpoint(
			new CartEndpoint(
				new CartFactory( new CartTotalsFactory( $money_factory ), new ShippingRatesFactory( $money_factory ) ),
				new NullLogger()
			),
			new AmountFactory( $this->mock( ItemFactory::class ), $this->mock( MoneyFactory::class ), $this->mock( CurrencyGetter::class ) ),
			new NullLogger(),
			$this->cart_data_storage
		);

		$request = $this->build_request(
			array(
				'id'               => self::PAYPAL_ORDER_ID,
				'purchase_units'   => array( array( 'reference_id' => 'default' ) ),
				'shipping_address' => array(
					'country_code' => 'US',
					'admin_area_1' => 'California',
					'admin_area_2' => 'Beverly Hills',
					'postal_code'  => '90210',
				),
				'shipping_option'  => array( 'id' => 'flat_rate:2' ),
			)
		);

		$response = $sut->handle_request( $request );

		$this->assertCount( 2, $this->http_requests, 'One request updates the customer, one selects the rate' );
		$update = $this->http_requests[0];
		$this->assertSame( $store_api_url . 'cart/update-customer', $update['url'] );
		$this->assertSame( 'POST', $update['request']['method'] );
		$this->assertSame( self::CART_TOKEN, $update['request']['headers']['cart-token'] );
		$this->assertSame(
			array(
				'shipping_address' => array(
					'country'  => 'US',
					'state'    => 'CA',
					'city'     => 'Beverly Hills',
					'postcode' => '90210',
				),
			),
			json_decode( $update['request']['body'], true )
		);
		$select = $this->http_requests[1];
		$this->assertSame( $store_api_url . 'cart/select-shipping-rate', $select['url'] );
		$this->assertSame(
			array(
				'package_id' => 0,
				'rate_id'    => 'flat_rate:2',
			),
			json_decode( $select['request']['body'], true )
		);

		$this->assertSame( 200, $response->get_status() );
		$unit = $response->get_data()['purchase_units'][0];
		$this->assertSame( '22.00', $unit['amount']['value'], 'The total is the items plus the chosen rate' );
		$this->assertSame( array( 'flat_rate:1', 'flat_rate:2' ), array_column( $unit['shipping_options'], 'id' ) );
		$this->assertSame( array( false, true ), array_column( $unit['shipping_options'], 'selected' ) );
	}
}
