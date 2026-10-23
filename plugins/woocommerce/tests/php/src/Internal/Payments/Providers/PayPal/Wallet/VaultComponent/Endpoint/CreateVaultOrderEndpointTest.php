<?php
/**
 * Tests for the vault component create-order endpoint.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\VaultComponent\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\VaultComponent\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\OrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PaymentSource;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PurchaseUnit;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PurchaseUnitFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ShippingPreferenceFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\RequestData;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\VaultComponent\Endpoint\CreateVaultOrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcPaymentTokens\PaymentTokenPayPal;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcPaymentTokens\PaymentTokenVenmo;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Exception;
use Mockery\MockInterface;
use WC_Payment_Token;

/**
 * The create-order call of the vault component: the request body is stripped of what would auto-capture the order, and
 * the order is created with a hint to open the paysheet on the buyer's saved PayPal account. The saved account is a
 * real WooCommerce payment token of a real user; the PayPal order endpoint and the request reading are Mockery doubles.
 *
 * @group paypal-wallet
 */
class CreateVaultOrderEndpointTest extends WalletTestCase {

	private const BODY_FILTER = 'ppcp_create_order_request_body_data';

	/**
	 * The request data double.
	 *
	 * @var RequestData&MockInterface
	 */
	private $request_data;

	/**
	 * The order endpoint double.
	 *
	 * @var OrderEndpoint&MockInterface
	 */
	private $order_endpoint;

	/**
	 * The purchase unit factory double.
	 *
	 * @var PurchaseUnitFactory&MockInterface
	 */
	private $purchase_unit_factory;

	/**
	 * The shipping preference factory double.
	 *
	 * @var ShippingPreferenceFactory&MockInterface
	 */
	private $shipping_preference_factory;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockInterface
	 */
	private $logger;

	/**
	 * The endpoint under test.
	 *
	 * @var CreateVaultOrderEndpoint
	 */
	private CreateVaultOrderEndpoint $endpoint;

	/**
	 * Build the endpoint over the doubles, and map the PayPal and Venmo token types to their classes, as the wallet
	 * does when it boots.
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter(
			'woocommerce_payment_token_class',
			static function ( $class_name ) {
				$map = array(
					'WC_Payment_Token_PayPal' => PaymentTokenPayPal::class,
					'WC_Payment_Token_Venmo'  => PaymentTokenVenmo::class,
				);

				return $map[ $class_name ] ?? $class_name;
			}
		);

		$this->request_data                = $this->mock( RequestData::class );
		$this->order_endpoint              = $this->mock( OrderEndpoint::class );
		$this->purchase_unit_factory       = $this->mock( PurchaseUnitFactory::class );
		$this->shipping_preference_factory = $this->mock( ShippingPreferenceFactory::class );
		$this->logger                      = $this->mock( LoggerInterface::class );

		$this->endpoint = new CreateVaultOrderEndpoint(
			$this->request_data,
			$this->order_endpoint,
			$this->purchase_unit_factory,
			$this->shipping_preference_factory,
			$this->logger
		);
	}

	/**
	 * A shopper who is logged in.
	 *
	 * @return int The user ID.
	 */
	private function log_in_a_shopper(): int {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Save a payment token of a user, in the vault of the PayPal gateway.
	 *
	 * @param WC_Payment_Token $token   The token, with no owner or gateway yet.
	 * @param int              $user_id The owner.
	 * @param string           $vault_id The vault ID.
	 */
	private function save_token( WC_Payment_Token $token, int $user_id, string $vault_id ): void {
		$token->set_gateway_id( PayPalGateway::ID );
		$token->set_user_id( $user_id );
		$token->set_token( $vault_id );
		$token->save();
	}

	/**
	 * Expect the read of the nonce-protected request and the build of the purchase unit, the way a checkout request
	 * reaches the endpoint.
	 *
	 * @return PurchaseUnit&MockInterface The purchase unit.
	 */
	private function expect_checkout_request() {
		$this->request_data->shouldReceive( 'read_request' )->once()->with( CreateVaultOrderEndpoint::nonce() )->andReturn( array() );

		$purchase_unit = $this->mock( PurchaseUnit::class );
		$this->purchase_unit_factory->shouldReceive( 'from_wc_cart' )->once()->andReturn( $purchase_unit );
		$this->shipping_preference_factory->shouldReceive( 'from_state' )->once()->with( $purchase_unit, 'checkout' )->andReturn( 'GET_FROM_FILE' );

		return $purchase_unit;
	}

	/**
	 * Expect the order to be created once, and capture the arguments of the call. The arguments are asserted after the
	 * handler returns, because the endpoint catches exceptions around the call and a failed assertion inside it would
	 * only show up as an error answer.
	 *
	 * @param array         $captured The arguments of the create call, filled while the handler runs.
	 * @param callable|null $during   Called while the create call runs, for a check of the state at that moment.
	 */
	private function expect_order_creation( array &$captured, ?callable $during = null ): void {
		$order = $this->mock( Order::class );
		$order->shouldReceive( 'id' )->andReturn( 'ORDER-1' );

		$this->order_endpoint
			->shouldReceive( 'create' )
			->once()
			->andReturnUsing(
				function ( ...$arguments ) use ( &$captured, $during, $order ) {
					$captured = $arguments;
					if ( $during ) {
						$during();
					}

					return $order;
				}
			);
	}

	/**
	 * Assert what the endpoint passed to the order creation, and give back the payment source it passed.
	 *
	 * @param PurchaseUnit $purchase_unit The purchase unit that must be sent.
	 * @param array        $captured      The arguments of the create call.
	 * @return PaymentSource|null
	 */
	private function assert_created_order( PurchaseUnit $purchase_unit, array $captured ): ?PaymentSource {
		$this->assertCount( 6, $captured, 'The order should be created with all six arguments' );
		list( $items, $shipping_preference, $payer, $payment_method, $request_data, $payment_source ) = $captured;
		$this->assertSame( array( $purchase_unit ), $items );
		$this->assertSame( 'GET_FROM_FILE', $shipping_preference );
		$this->assertNull( $payer );
		$this->assertSame( '', $payment_method );
		$this->assertSame( array(), $request_data );

		return $payment_source;
	}

	/**
	 * @testdox Should reduce the purchase units to the amount of the first one.
	 */
	public function test_strip_request_body_reduces_purchase_units_to_amount(): void {
		$data = array(
			'purchase_units' => array(
				array(
					'amount'      => array( 'value' => '10.00' ),
					'items'       => array( array( 'name' => 'A' ) ),
					'description' => 'something',
				),
			),
		);

		$result = $this->endpoint->strip_request_body( $data );

		$this->assertSame(
			array( array( 'amount' => array( 'value' => '10.00' ) ) ),
			$result['purchase_units']
		);
	}

	/**
	 * @testdox Should remove the payer.
	 */
	public function test_strip_request_body_removes_payer(): void {
		$data = array( 'payer' => array( 'email_address' => 'buyer@example.com' ) );

		$result = $this->endpoint->strip_request_body( $data );

		$this->assertArrayNotHasKey( 'payer', $result );
	}

	/**
	 * @testdox Should remove the PayPal token from a payment source that is an array.
	 */
	public function test_strip_request_body_removes_paypal_token_from_array_shape(): void {
		$data = array(
			'payment_source' => array(
				'paypal' => array(
					'token'              => array( 'id' => 'tok_1' ),
					'experience_context' => array( 'return_url' => 'https://example.com' ),
				),
			),
		);

		$result = $this->endpoint->strip_request_body( $data );

		$this->assertArrayNotHasKey( 'token', $result['payment_source']['paypal'] );
		$this->assertArrayHasKey( 'experience_context', $result['payment_source']['paypal'] );
	}

	/**
	 * @testdox Should remove the PayPal token from a payment source that is an object.
	 */
	public function test_strip_request_body_removes_paypal_token_from_object_shape(): void {
		$paypal = (object) array(
			'token'              => (object) array( 'id' => 'tok_1' ),
			'experience_context' => (object) array( 'return_url' => 'https://example.com' ),
		);
		$data   = array( 'payment_source' => array( 'paypal' => $paypal ) );

		$result = $this->endpoint->strip_request_body( $data );

		$this->assertFalse( property_exists( $result['payment_source']['paypal'], 'token' ) );
		$this->assertTrue( property_exists( $result['payment_source']['paypal'], 'experience_context' ) );
	}

	/**
	 * @testdox Should leave data it does not strip untouched.
	 */
	public function test_strip_request_body_leaves_unrelated_data_untouched(): void {
		$data = array( 'intent' => 'CAPTURE' );

		$result = $this->endpoint->strip_request_body( $data );

		$this->assertSame( array( 'intent' => 'CAPTURE' ), $result );
	}

	/**
	 * @testdox Should create the order with a hint for the paysheet to open on the buyer's saved PayPal account.
	 */
	public function test_handle_request_hints_the_saved_paypal_account_of_the_buyer(): void {
		$user_id = $this->log_in_a_shopper();
		$this->save_token( new PaymentTokenPayPal(), $user_id, 'VAULT-PP-1' );
		$purchase_unit = $this->expect_checkout_request();
		$captured      = array();
		$this->expect_order_creation( $captured );

		$response = $this->run_ajax_handler( array( $this->endpoint, 'handle_request' ) );

		$this->assertTrue( $response['success'] );
		$this->assertSame( array( 'id' => 'ORDER-1' ), $response['data'] );
		$payment_source = $this->assert_created_order( $purchase_unit, $captured );
		$this->assertInstanceOf( PaymentSource::class, $payment_source );
		$this->assertSame( 'paypal', $payment_source->name() );
		$context = $payment_source->properties()->experience_context;
		$this->assertSame( wc_get_checkout_url(), $context->return_url );
		$this->assertSame( wc_get_checkout_url(), $context->cancel_url );
		$this->assertSame( 'CONTINUE', $context->user_action );
		$this->assertSame( 'VAULT-PP-1', $context->preferred_payment_source->vault_id );
	}

	/**
	 * @testdox Should create the order without a payment source when the buyer has no saved PayPal account: $scenario.
	 *
	 * @dataProvider data_buyers_without_a_saved_paypal_account
	 *
	 * @param string $scenario The buyer.
	 */
	public function test_handle_request_creates_the_order_without_a_payment_source( string $scenario ): void {
		if ( 'a logged-in buyer with no token' === $scenario ) {
			$this->log_in_a_shopper();
		} elseif ( 'a logged-in buyer with a Venmo token only' === $scenario ) {
			$this->save_token( new PaymentTokenVenmo(), $this->log_in_a_shopper(), 'VAULT-VENMO-1' );
		}

		$purchase_unit = $this->expect_checkout_request();
		$captured      = array();
		$this->expect_order_creation( $captured );

		$response = $this->run_ajax_handler( array( $this->endpoint, 'handle_request' ) );

		$this->assertTrue( $response['success'] );
		$this->assertNull( $this->assert_created_order( $purchase_unit, $captured ) );
	}

	/**
	 * Buyers the endpoint cannot hint a saved PayPal account for.
	 *
	 * @return array<string, array{string}>
	 */
	public function data_buyers_without_a_saved_paypal_account(): array {
		return array(
			'a guest'                                   => array( 'a guest' ),
			'a logged-in buyer with no token'           => array( 'a logged-in buyer with no token' ),
			'a logged-in buyer with a Venmo token only' => array( 'a logged-in buyer with a Venmo token only' ),
		);
	}

	/**
	 * @testdox Should strip the request body of the order while the order is created, and only then.
	 */
	public function test_handle_request_strips_the_request_body_only_while_creating_the_order(): void {
		$purchase_unit = $this->expect_checkout_request();
		$this->assertFalse( has_filter( self::BODY_FILTER, array( $this->endpoint, 'strip_request_body' ) ), 'No stripping before the request' );

		$priority_while_creating = null;
		$captured                = array();
		$this->expect_order_creation(
			$captured,
			function () use ( &$priority_while_creating ) {
				$priority_while_creating = has_filter( self::BODY_FILTER, array( $this->endpoint, 'strip_request_body' ) );
			}
		);

		$response = $this->run_ajax_handler( array( $this->endpoint, 'handle_request' ) );

		$this->assertTrue( $response['success'] );
		$this->assert_created_order( $purchase_unit, $captured );
		$this->assertSame( 99, $priority_while_creating, 'The body should be stripped late, after the other listeners' );
		$this->assertFalse( has_filter( self::BODY_FILTER, array( $this->endpoint, 'strip_request_body' ) ), 'The stripping should end with the order creation' );
	}

	/**
	 * @testdox Should log the failure, answer with an error and stop stripping when PayPal fails to create the order.
	 */
	public function test_handle_request_answers_with_an_error_when_creating_the_order_fails(): void {
		$this->expect_checkout_request();
		$this->order_endpoint->shouldReceive( 'create' )->once()->andThrow( new Exception( 'PayPal said no.' ) );
		$this->logger->shouldReceive( 'error' )->once()->with( 'Vault Component: Failed to create order. PayPal said no.' );

		$response = $this->run_ajax_handler( array( $this->endpoint, 'handle_request' ) );

		$this->assertFalse( $response['success'] );
		$this->assertSame( array( 'message' => 'PayPal said no.' ), $response['data'] );
		$this->assertFalse( has_filter( self::BODY_FILTER, array( $this->endpoint, 'strip_request_body' ) ), 'The stripping should end although the creation failed' );
	}

	/**
	 * @testdox Should answer with an error, and build no purchase unit, when the request cannot be read.
	 */
	public function test_handle_request_answers_with_an_error_when_the_request_is_invalid(): void {
		$this->request_data->shouldReceive( 'read_request' )->once()->andThrow( new Exception( 'Invalid nonce.' ) );
		$this->purchase_unit_factory->shouldNotReceive( 'from_wc_cart' );
		$this->order_endpoint->shouldNotReceive( 'create' );
		$this->logger->shouldReceive( 'error' )->once()->with( 'Vault Component: Failed to create order. Invalid nonce.' );

		$response = $this->run_ajax_handler( array( $this->endpoint, 'handle_request' ) );

		$this->assertFalse( $response['success'] );
		$this->assertSame( array( 'message' => 'Invalid nonce.' ), $response['data'] );
	}
}
