<?php
/**
 * Characterization tests for the PayPal request the wallet's create-order endpoint builds (written in core; the extension
 * has no equivalent).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\OrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Amount;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Money;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PurchaseUnit;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\AddressFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\AmountFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ContactPreferenceFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ExperienceContextBuilder;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ItemFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\MoneyFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\OrderFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PatchCollectionFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PayerFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PaymentsFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PurchaseUnitFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ReturnUrlFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ShippingFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ShippingOptionFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ShippingPreferenceFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\CurrencyGetter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\PaymentLevelEligibility;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\PaymentLevelHelper;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Session\CartData;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Session\CartDataFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Session\CartDataTransientStorage;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\CreateOrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\RequestData;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Helper\EarlyOrderHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\SessionHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\FraudNet\FraudNet;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\MerchantDetails;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Shipping\ShippingCallbackUrlFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\SubscriptionHelper;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\NullLogger;
use ArrayObject;
use Mockery;
use Mockery\MockInterface;
use WC_Helper_Product;
use WC_Helper_Shipping;
use WC_Order;
use WC_Session_Handler;
use WPDieException;

/**
 * The PayPal order request the create-order endpoint sends for a PayPal wallet checkout: the endpoint runs for real over
 * the real purchase unit, shipping preference, return URL, contact preference, experience context and payer factories and
 * the real order endpoint, and the tests read the body of the HTTP request that reaches PayPal. Only the request reader,
 * the session, the early order handler, the cart storage, the order factory (which parses PayPal's reply) and the settings
 * are doubles.
 *
 * Card funding is deliberately not covered here.
 *
 * @group paypal-wallet
 */
class CreateOrderEndpointPayloadTest extends WalletTestCase {

	private const HOST = 'https://api.paypal.test/';

	/**
	 * The request reader mock.
	 *
	 * @var RequestData&MockInterface
	 */
	private $request_data;

	/**
	 * The session handler mock.
	 *
	 * @var SessionHandler&MockInterface
	 */
	private $session_handler;

	/**
	 * The early order handler mock.
	 *
	 * @var EarlyOrderHandler&MockInterface
	 */
	private $early_order_handler;

	/**
	 * The order factory mock, which turns PayPal's reply into an order entity.
	 *
	 * @var OrderFactory&MockInterface
	 */
	private $order_factory;

	/**
	 * The settings provider mock.
	 *
	 * @var SettingsProvider&MockInterface
	 */
	private $settings_provider;

	/**
	 * The cart storage mock.
	 *
	 * @var CartDataTransientStorage&MockInterface
	 */
	private $cart_data_storage;

	/**
	 * The cart data mock the cart data factory hands out.
	 *
	 * @var CartData&MockInterface
	 */
	private $cart_data;

	/**
	 * The ID of the logged in shopper.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * The session the test replaced, put back on tear down.
	 *
	 * @var mixed
	 */
	private $original_session;

	/**
	 * Log in a shopper, give WooCommerce a real session handler (the purchase unit's custom ID comes from it) and stub PayPal.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->user_id = $this->factory()->user->create();
		wp_set_current_user( $this->user_id );

		$this->original_session = WC()->session;
		WC()->session           = new WC_Session_Handler();

		if ( ! WC()->cart ) {
			wc_load_cart();
		}
		WC()->cart->empty_cart();

		$this->request_data        = $this->mock( RequestData::class );
		$this->session_handler     = $this->mock( SessionHandler::class )->shouldIgnoreMissing();
		$this->early_order_handler = $this->mock( EarlyOrderHandler::class );
		$this->order_factory       = $this->mock( OrderFactory::class );
		$this->settings_provider   = $this->mock( SettingsProvider::class );
		$this->cart_data_storage   = $this->mock( CartDataTransientStorage::class );
		$this->cart_data           = $this->mock( CartData::class );

		$this->settings_provider->shouldReceive( 'save_paypal_and_venmo' )->andReturn( false );
		$this->settings_provider->shouldReceive( 'brand_name' )->andReturn( 'Test Brand' );
		$this->settings_provider->shouldReceive( 'landing_page_enum' )->andReturn( 'LOGIN' );
		$this->settings_provider->shouldReceive( 'instant_payments_only' )->andReturn( false );
		$this->settings_provider->shouldReceive( 'is_payment_level_processing_enabled' )->andReturn( false );
		$this->early_order_handler->shouldReceive( 'should_create_early_order' )->andReturn( false );
		$this->cart_data->shouldReceive( 'generate_key' );
		$this->cart_data->shouldReceive( 'key' )->andReturn( 'cart-key-1' );

		$this->stub_http( $this->http_response( 201, wp_json_encode( array( 'id' => 'PP-CREATED-1' ) ) ) );
	}

	/**
	 * Put the session back and undo the shipping setup the tests may have made.
	 */
	public function tearDown(): void {
		try {
			WC()->session = $this->original_session;
			WC()->cart->empty_cart();
			WC_Helper_Shipping::delete_simple_flat_rate();

			$customer = WC()->customer;
			$customer->set_shipping_country( '' );
			$customer->set_shipping_state( '' );
			$customer->set_shipping_postcode( '' );
			$customer->set_shipping_city( '' );
			$customer->set_shipping_address_1( '' );
			$customer->set_shipping_first_name( '' );
			$customer->set_shipping_last_name( '' );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Should send PayPal a CAPTURE order for the cart with the amount breakdown, the items, the customer ID as custom ID and the PayPal payment source for a checkout button payment.
	 */
	public function test_checkout_request_for_the_cart(): void {
		$cart_item_key = $this->fill_cart();

		$response = $this->run_endpoint( $this->checkout_request() );

		$this->assertSame( array( 'id' => 'PP-CREATED-1', 'custom_id' => 'custom-1' ), $response['data'], 'The shopper gets the PayPal order ID and custom ID back' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertTrue( $response['success'] );

		$this->assertCount( 1, $this->http_requests, 'Exactly one request reaches PayPal' );
		$request = $this->http_requests[0];
		$this->assertSame( self::HOST . 'v2/checkout/orders', $request['url'] );
		$this->assertSame( 'POST', $request['request']['method'] );
		$headers = $request['request']['headers'];
		$this->assertSame( 'Bearer bearer', $headers['Authorization'] );
		$this->assertSame( 'application/json', $headers['Content-Type'] );
		$this->assertSame( 'return=representation', $headers['Prefer'] );
		$this->assertStringStartsWith( 'ppcp-', $headers['PayPal-Request-Id'] );
		$this->assertArrayNotHasKey( 'PayPal-Partner-Attribution-Id', $headers, 'No BN code was sent in the request' );

		$body = $this->sent_body();
		$this->assertSame( 'CAPTURE', $body['intent'] );
		$this->assertArrayNotHasKey( 'payer', $body, 'The button sent no payer' );
		$this->assertCount( 1, $body['purchase_units'] );
		$this->assertSame(
			array(
				'reference_id' => 'default',
				'amount'       => array(
					'currency_code' => 'USD',
					'value'         => '20.00',
					'breakdown'     => array(
						'item_total' => array(
							'currency_code' => 'USD',
							'value'         => '20.00',
						),
						'shipping'   => array(
							'currency_code' => 'USD',
							'value'         => '0.00',
						),
						'tax_total'  => array(
							'currency_code' => 'USD',
							'value'         => '0.00',
						),
					),
				),
				'description'  => '',
				'items'        => array(
					array(
						'name'          => 'Dummy Product',
						'unit_amount'   => array(
							'currency_code' => 'USD',
							'value'         => '10.00',
						),
						'quantity'      => 2,
						'description'   => '',
						'sku'           => 'PP-SKU-1',
						'category'      => 'PHYSICAL_GOODS',
						'url'           => get_permalink( WC()->cart->get_cart()[ $cart_item_key ]['product_id'] ),
						'cart_item_key' => $cart_item_key,
					),
				),
				'custom_id'    => 'pcp_customer_' . $this->user_id,
			),
			$body['purchase_units'][0],
			'The purchase unit for the cart: no shipping block, no invoice ID'
		);
		$this->assertSame( array( 'paypal' ), array_keys( $body['payment_source'] ) );
		$this->assertSame(
			array(
				'return_url'                => wc_get_checkout_url(),
				'cancel_url'                => wc_get_checkout_url(),
				'brand_name'                => 'Test Brand',
				'locale'                    => 'en-US',
				'landing_page'              => 'LOGIN',
				'shipping_preference'       => 'NO_SHIPPING',
				'user_action'               => 'PAY_NOW',
				'payment_method_preference' => 'UNRESTRICTED',
				'contact_preference'        => 'UPDATE_CONTACT_INFO',
			),
			$body['payment_source']['paypal']['experience_context']
		);
	}

	/**
	 * @testdox Should send the shipping address, its cost in the breakdown and SET_PROVIDED_ADDRESS when the cart needs shipping at checkout and the shopper has an address.
	 */
	public function test_checkout_request_with_a_shipping_address(): void {
		$this->fill_cart();
		$this->enable_flat_rate_shipping( 7 );

		$this->run_endpoint( $this->checkout_request() );

		$unit = $this->sent_body()['purchase_units'][0];
		$this->assertSame( '27.00', $unit['amount']['value'] );
		$this->assertSame( '7.00', $unit['amount']['breakdown']['shipping']['value'] );
		$this->assertSame(
			array(
				'name'    => array( 'full_name' => 'Jo Doe' ),
				'address' => array(
					'country_code'   => 'US',
					'address_line_1' => '1 Main St',
					'admin_area_1'   => 'NY',
					'admin_area_2'   => 'New York',
					'postal_code'    => '10001',
				),
			),
			$unit['shipping']
		);
		$this->assertSame( 'SET_PROVIDED_ADDRESS', $this->sent_body()['payment_source']['paypal']['experience_context']['shipping_preference'] );
	}

	/**
	 * @testdox Should use the product URL for return and cancel, CONTINUE as the user action and no custom ID when the payment starts on a product page.
	 */
	public function test_product_page_request(): void {
		$this->fill_cart();
		$product_url = 'https://example.com/product/dummy/';

		$this->run_endpoint(
			array(
				'context'        => 'product',
				'payment_method' => PayPalGateway::ID,
				'funding_source' => 'paypal',
				'purchase_units' => array( array( 'items' => array( array( 'url' => $product_url ) ) ) ),
			)
		);

		$body    = $this->sent_body();
		$context = $body['payment_source']['paypal']['experience_context'];
		$this->assertSame( $product_url, $context['return_url'] );
		$this->assertSame( $product_url, $context['cancel_url'] );
		$this->assertSame( 'CONTINUE', $context['user_action'] );
		$this->assertArrayNotHasKey( 'custom_id', $body['purchase_units'][0], 'A button outside checkout must not let webhooks complete the order' );
	}

	/**
	 * @testdox Should use the cart URL for return and cancel, CONTINUE and no custom ID when the payment starts on the cart.
	 */
	public function test_cart_page_request(): void {
		$this->fill_cart();

		$this->run_endpoint(
			array(
				'context'        => 'cart',
				'payment_method' => PayPalGateway::ID,
				'funding_source' => 'paypal',
			)
		);

		$body    = $this->sent_body();
		$context = $body['payment_source']['paypal']['experience_context'];
		$this->assertSame( wc_get_cart_url(), $context['return_url'] );
		$this->assertSame( wc_get_cart_url(), $context['cancel_url'] );
		$this->assertSame( 'CONTINUE', $context['user_action'] );
		$this->assertArrayNotHasKey( 'custom_id', $body['purchase_units'][0] );
	}

	/**
	 * @testdox Should let PayPal collect the address (GET_FROM_FILE) when the cart needs shipping and the button is not on the checkout.
	 */
	public function test_cart_page_with_shipping_lets_paypal_collect_the_address(): void {
		$this->fill_cart();
		$this->enable_flat_rate_shipping( 7 );

		$this->run_endpoint(
			array(
				'context'        => 'cart',
				'payment_method' => PayPalGateway::ID,
				'funding_source' => 'paypal',
			)
		);

		$this->assertSame( 'GET_FROM_FILE', $this->sent_body()['payment_source']['paypal']['experience_context']['shipping_preference'] );
	}

	/**
	 * @testdox Should name the payment source $expected_source for the $funding_source funding source and add the contact preference for it.
	 *
	 * @dataProvider data_funding_sources
	 *
	 * @param string $funding_source   The funding source the button reports.
	 * @param string $expected_source  The payment source key PayPal receives.
	 */
	public function test_payment_source_by_funding_source( string $funding_source, string $expected_source ): void {
		$this->fill_cart();

		$this->run_endpoint(
			array(
				'context'        => 'checkout',
				'payment_method' => PayPalGateway::ID,
				'funding_source' => $funding_source,
			)
		);

		$source = $this->sent_body()['payment_source'];
		$this->assertSame( array( $expected_source ), array_keys( $source ) );
		$this->assertSame( 'UPDATE_CONTACT_INFO', $source[ $expected_source ]['experience_context']['contact_preference'] );
	}

	/**
	 * Funding sources and the payment source each is sent as.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function data_funding_sources(): array {
		return array(
			'paypal'   => array( 'paypal', 'paypal' ),
			'paylater' => array( 'paylater', 'paypal' ),
			'venmo'    => array( 'venmo', 'venmo' ),
		);
	}

	/**
	 * @testdox Should send the BN code as the partner attribution header and keep it in the session when the request carries one.
	 */
	public function test_bn_code_is_sent_as_the_partner_attribution_header(): void {
		$this->fill_cart();
		$this->session_handler->expects( 'replace_bn_code' )->once()->with( 'BN-CODE-1' );

		$this->run_endpoint( $this->checkout_request() + array( 'bn_code' => 'BN-CODE-1' ) );

		$this->assertSame( 'BN-CODE-1', $this->http_requests[0]['request']['headers']['PayPal-Partner-Attribution-Id'] );
	}

	/**
	 * @testdox Should add the server-side shipping callback to the experience context only when the setting is on and PayPal collects the address.
	 *
	 * @dataProvider data_shipping_callback
	 *
	 * @param bool $callback_enabled The server-side shipping callback flag.
	 * @param bool $expect_callback  Whether the callback is in the request.
	 */
	public function test_shipping_callback( bool $callback_enabled, bool $expect_callback ): void {
		$this->fill_cart();
		$this->enable_flat_rate_shipping( 7 );

		$this->run_endpoint(
			array(
				'context'        => 'cart',
				'payment_method' => PayPalGateway::ID,
				'funding_source' => 'paypal',
			),
			$this->create_endpoint( true, $callback_enabled )
		);

		$context = $this->sent_body()['payment_source']['paypal']['experience_context'];
		if ( $expect_callback ) {
			$this->assertSame(
				array(
					'callback_events' => array( 'SHIPPING_ADDRESS', 'SHIPPING_OPTIONS' ),
					'callback_url'    => 'https://example.com/shipping-callback',
				),
				$context['order_update_callback_config']
			);
		} else {
			$this->assertArrayNotHasKey( 'order_update_callback_config', $context );
		}
	}

	/**
	 * Whether the shipping callback is on, and whether it ends up in the request.
	 *
	 * @return array<string, array{bool, bool}>
	 */
	public function data_shipping_callback(): array {
		return array(
			'flag on'  => array( true, true ),
			'flag off' => array( false, false ),
		);
	}

	/**
	 * @testdox Should store the PayPal order in the session with its funding source when a checkout payment uses a source that redirects, and not when it does not.
	 *
	 * @dataProvider data_session_storage
	 *
	 * @param string $funding_source The funding source.
	 * @param bool   $expect_stored  Whether the order is kept in the session.
	 */
	public function test_checkout_keeps_the_order_in_the_session_for_redirecting_sources( string $funding_source, bool $expect_stored ): void {
		$this->fill_cart();
		if ( $expect_stored ) {
			$this->session_handler->expects( 'replace_order' )->once()->with( Mockery::on( fn ( $order ) => 'PP-CREATED-1' === $order->id() ) );
			$this->session_handler->expects( 'replace_funding_source' )->once()->with( $funding_source );
		} else {
			$this->session_handler->shouldNotReceive( 'replace_order' );
			$this->session_handler->shouldNotReceive( 'replace_funding_source' );
		}

		$this->run_endpoint(
			array(
				'context'        => 'checkout',
				'payment_method' => PayPalGateway::ID,
				'funding_source' => $funding_source,
			)
		);
	}

	/**
	 * Funding sources and whether the checkout keeps the order in the session (the test endpoint lists "paypal" as the
	 * source that does not redirect).
	 *
	 * @return array<string, array{string, bool}>
	 */
	public function data_session_storage(): array {
		return array(
			'venmo redirects' => array( 'venmo', true ),
			'paypal does not' => array( 'paypal', false ),
		);
	}

	/**
	 * @testdox Should save the cart under its key with the new PayPal order ID, outside the pay for order page, so the shopper's cart survives a return in another browser.
	 */
	public function test_cart_is_saved_with_the_paypal_order_id(): void {
		$this->fill_cart();
		$this->cart_data->expects( 'set_paypal_order_id' )->once()->with( 'PP-CREATED-1' );
		$this->cart_data_storage->expects( 'save' )->once()->with( $this->cart_data );

		$this->run_endpoint( $this->checkout_request() );
	}

	/**
	 * @testdox Should build the request from the WooCommerce order, with its ID as custom ID, a prefixed invoice ID, the payer, the payment URL as return and cancel URL and SET_PROVIDED_ADDRESS, when paying for an existing order.
	 */
	public function test_pay_for_order_request(): void {
		$this->enable_flat_rate_shipping( 7 );
		$wc_order = $this->create_wc_order();
		$this->cart_data_storage->shouldNotReceive( 'save' );
		$created = $this->record_actions( 'woocommerce_paypal_payments_woocommerce_order_created' );

		$response = $this->run_endpoint(
			array(
				'context'        => 'pay-now',
				'payment_method' => PayPalGateway::ID,
				'funding_source' => 'paypal',
				'order_id'       => $wc_order->get_id(),
				'order_key'      => $wc_order->get_order_key(),
			)
		);

		$this->assertTrue( $response['success'] );
		$body = $this->sent_body();
		$unit = $body['purchase_units'][0];
		$this->assertSame( (string) $wc_order->get_id(), $unit['custom_id'] );
		$this->assertSame( 'WC-' . $wc_order->get_order_number(), $unit['invoice_id'] );
		$this->assertSame( '20.00', $unit['amount']['value'] );
		$this->assertSame( 'Dummy Product', $unit['items'][0]['name'] );
		$this->assertSame( 2, $unit['items'][0]['quantity'] );
		$this->assertSame( '1 Main St', $unit['shipping']['address']['address_line_1'] );
		$this->assertSame( 'buyer@example.com', $body['payer']['email_address'] );
		$this->assertSame( 'Jo', $body['payer']['name']['given_name'] );

		$context = $body['payment_source']['paypal']['experience_context'];
		$this->assertSame( $wc_order->get_checkout_payment_url(), $context['return_url'] );
		$this->assertSame( $wc_order->get_checkout_payment_url(), $context['cancel_url'] );
		$this->assertSame( 'SET_PROVIDED_ADDRESS', $context['shipping_preference'] );
		$this->assertSame( 'CONTINUE', $context['user_action'] );

		$reloaded = wc_get_order( $wc_order->get_id() );
		$this->assertSame( 'PP-CREATED-1', $reloaded->get_meta( PayPalGateway::ORDER_ID_META_KEY ), 'The PayPal order ID is stored on the WooCommerce order' );
		$this->assertSame( 'CAPTURE', $reloaded->get_meta( PayPalGateway::INTENT_META_KEY ) );
		$this->assertCount( 1, $created->getArrayCopy(), 'The order created action fires once' );
	}

	/**
	 * @testdox Should retry the request without the shipping block, once, when PayPal rejects the shipping address with a 422.
	 */
	public function test_retries_without_shipping_when_paypal_rejects_the_address(): void {
		$this->fill_cart();
		$this->enable_flat_rate_shipping( 7 );

		$calls = 0;
		$this->stub_http(
			function () use ( &$calls ) {
				++$calls;
				if ( 1 === $calls ) {
					return $this->http_response(
						422,
						wp_json_encode(
							array(
								'name'    => 'UNPROCESSABLE_ENTITY',
								'message' => 'The requested action could not be performed.',
								'details' => array(
									array(
										'field' => "/purchase_units/@reference_id=='default'/shipping/address/postal_code",
										'issue' => 'INVALID_PARAMETER_VALUE',
									),
								),
							)
						)
					);
				}

				return $this->http_response( 201, wp_json_encode( array( 'id' => 'PP-CREATED-1' ) ) );
			}
		);

		$response = $this->run_endpoint( $this->checkout_request() );

		$this->assertTrue( $response['success'] );
		$this->assertCount( 2, $this->http_requests, 'One retry, no more' );
		$first  = json_decode( $this->http_requests[0]['request']['body'], true );
		$second = json_decode( $this->http_requests[1]['request']['body'], true );
		$this->assertArrayHasKey( 'shipping', $first['purchase_units'][0] );
		$this->assertArrayNotHasKey( 'shipping', $second['purchase_units'][0], 'The retry drops the shipping block' );
		$this->assertSame( $first['payment_source'], $second['payment_source'], 'The retry keeps the payment source' );
	}

	/**
	 * @testdox Should answer with PayPal's error name, message, status and details, and send no retry, when PayPal rejects the order for another reason.
	 */
	public function test_paypal_error_is_passed_back_to_the_button(): void {
		$this->fill_cart();
		$this->stub_http(
			$this->http_response(
				400,
				wp_json_encode(
					array(
						'name'    => 'INVALID_REQUEST',
						'message' => 'Request is not well-formed.',
						'details' => array( array( 'issue' => 'MALFORMED_REQUEST' ) ),
					)
				)
			)
		);

		$response = $this->run_endpoint( $this->checkout_request() );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'INVALID_REQUEST', $response['data']['name'] );
		$this->assertSame( '[INVALID_REQUEST] Request is not well-formed.', $response['data']['message'] );
		$this->assertSame( 400, $response['data']['code'] );
		$this->assertSame( 'MALFORMED_REQUEST', $response['data']['details'][0]['issue'] );
		$this->assertCount( 1, $this->http_requests );
	}

	/**
	 * The request body of the checkout button for a PayPal payment.
	 *
	 * @return array
	 */
	private function checkout_request(): array {
		return array(
			'context'        => 'checkout',
			'payment_method' => PayPalGateway::ID,
			'funding_source' => 'paypal',
		);
	}

	/**
	 * Put two of a physical product in the cart.
	 *
	 * @return string The cart item key.
	 */
	private function fill_cart(): string {
		$product = WC_Helper_Product::create_simple_product( true, array( 'sku' => 'PP-SKU-1' ) );

		$key = WC()->cart->add_to_cart( $product->get_id(), 2 );
		WC()->cart->calculate_totals();

		return (string) $key;
	}

	/**
	 * Give the shopper a US shipping address and the store a flat rate, so the cart needs shipping.
	 *
	 * @param int $cost The flat rate.
	 */
	private function enable_flat_rate_shipping( int $cost ): void {
		WC_Helper_Shipping::create_simple_flat_rate( $cost );

		$customer = WC()->customer;
		$customer->set_shipping_country( 'US' );
		$customer->set_shipping_state( 'NY' );
		$customer->set_shipping_postcode( '10001' );
		$customer->set_shipping_city( 'New York' );
		$customer->set_shipping_address_1( '1 Main St' );
		$customer->set_shipping_first_name( 'Jo' );
		$customer->set_shipping_last_name( 'Doe' );

		WC()->cart->calculate_totals();
	}

	/**
	 * A saved order for two of a physical product, with a billing and a shipping address, owned by the logged in shopper.
	 *
	 * @return WC_Order
	 */
	private function create_wc_order(): WC_Order {
		$wc_order = wc_create_order( array( 'customer_id' => $this->user_id ) );
		$wc_order->add_product( WC_Helper_Product::create_simple_product( true, array( 'sku' => 'PP-SKU-2' ) ), 2 );
		$wc_order->set_payment_method( PayPalGateway::ID );
		$wc_order->set_currency( 'USD' );
		$wc_order->set_billing_first_name( 'Jo' );
		$wc_order->set_billing_last_name( 'Doe' );
		$wc_order->set_billing_email( 'buyer@example.com' );
		$wc_order->set_billing_country( 'US' );
		$wc_order->set_billing_address_1( '2 Billing Rd' );
		$wc_order->set_billing_city( 'New York' );
		$wc_order->set_billing_state( 'NY' );
		$wc_order->set_billing_postcode( '10001' );
		$wc_order->set_shipping_first_name( 'Jo' );
		$wc_order->set_shipping_last_name( 'Doe' );
		$wc_order->set_shipping_country( 'US' );
		$wc_order->set_shipping_address_1( '1 Main St' );
		$wc_order->set_shipping_city( 'New York' );
		$wc_order->set_shipping_state( 'NY' );
		$wc_order->set_shipping_postcode( '10001' );
		$wc_order->calculate_totals();
		$wc_order->save();

		return $wc_order;
	}

	/**
	 * Record the calls of an action.
	 *
	 * @param string $hook The action name.
	 * @return ArrayObject A list that fills with the argument list of each call.
	 */
	private function record_actions( string $hook ): ArrayObject {
		$calls = new ArrayObject();
		add_action(
			$hook,
			static function ( ...$args ) use ( $calls ) {
				$calls->append( $args );
			},
			10,
			5
		);

		return $calls;
	}

	/**
	 * The decoded body of the first request that reached PayPal.
	 *
	 * @return array
	 */
	private function sent_body(): array {
		$this->assertNotEmpty( $this->http_requests, 'A request should have reached PayPal' );

		return json_decode( $this->http_requests[0]['request']['body'], true );
	}

	/**
	 * Build the endpoint over the real factories and the real order endpoint.
	 *
	 * @param bool $handle_shipping_in_paypal Whether PayPal handles the shipping options.
	 * @param bool $server_side_callback      Whether the server-side shipping callback is on.
	 * @return CreateOrderEndpoint
	 */
	private function create_endpoint( bool $handle_shipping_in_paypal = false, bool $server_side_callback = false ): CreateOrderEndpoint {
		$currency        = new CurrencyGetter();
		$money_factory   = new MoneyFactory();
		$item_factory    = new ItemFactory( $currency );
		$address_factory = new AddressFactory();

		$purchase_unit_factory = new PurchaseUnitFactory(
			new AmountFactory( $item_factory, $money_factory, $currency ),
			$item_factory,
			new ShippingFactory( $address_factory, new ShippingOptionFactory( $money_factory ) ),
			$this->mock( PaymentsFactory::class ),
			new PaymentLevelHelper( $this->settings_provider ),
			new PaymentLevelEligibility( 'US', $currency ),
			$this->settings_provider,
			'WC-'
		);

		$callback_url_factory = $this->mock( ShippingCallbackUrlFactory::class );
		$callback_url_factory->shouldReceive( 'create' )->andReturn( 'https://example.com/shipping-callback' );

		$merchant_details = $this->mock( MerchantDetails::class );
		$merchant_details->shouldReceive( 'is_eligible_for' )->andReturn( true );

		$cart_data_factory = $this->mock( CartDataFactory::class );
		$cart_data_factory->shouldReceive( 'from_current_cart' )->andReturn( $this->cart_data );

		$this->order_factory->shouldReceive( 'from_paypal_response' )->andReturnUsing(
			static function ( $json ) {
				return new Order(
					$json->id,
					array( new PurchaseUnit( new Amount( new Money( 10.0, 'USD' ) ), array(), null, 'default', '', 'custom-1' ) ),
					new OrderStatus( OrderStatus::CREATED )
				);
			}
		);

		$order_endpoint = new OrderEndpoint(
			self::HOST,
			$this->make_bearer( false ),
			$this->order_factory,
			$this->mock( PatchCollectionFactory::class ),
			'CAPTURE',
			new NullLogger(),
			$this->mock( SubscriptionHelper::class ),
			false,
			$this->mock( FraudNet::class )
		);

		return new CreateOrderEndpoint(
			$this->request_data,
			$purchase_unit_factory,
			new ShippingPreferenceFactory(),
			new ReturnUrlFactory(),
			new ContactPreferenceFactory( true, $merchant_details ),
			new ExperienceContextBuilder( $this->settings_provider, $callback_url_factory ),
			$order_endpoint,
			new PayerFactory( $address_factory ),
			$this->session_handler,
			$this->settings_provider,
			$this->early_order_handler,
			$cart_data_factory,
			$this->cart_data_storage,
			false,
			false,
			array( 'checkout' ),
			$handle_shipping_in_paypal,
			$server_side_callback,
			array( 'paypal' ),
			new NullLogger()
		);
	}

	/**
	 * Run the endpoint the way the button's AJAX call does and return the JSON answer the shopper's browser would get.
	 *
	 * @param array                    $request The request the reader hands over.
	 * @param CreateOrderEndpoint|null $sut     The endpoint, or the default one.
	 * @return array The decoded answer, empty when the endpoint answered with nothing.
	 */
	private function run_endpoint( array $request, ?CreateOrderEndpoint $sut = null ): array {
		$sut = $sut ?? $this->create_endpoint();
		$this->request_data->shouldReceive( 'read_request' )->andReturn( $request );

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			static function () {
				return static function () {
					throw new WPDieException( 'ajax die' );
				};
			}
		);

		ob_start();
		try {
			$sut->handle_request();
		} catch ( WPDieException $died ) {
			unset( $died );
		} finally {
			$output = (string) ob_get_clean();
		}

		return (array) json_decode( $output, true );
	}
}
