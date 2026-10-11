<?php
/**
 * Tests for the Pay with Crypto gateway.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\PayWithCrypto
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\PayWithCrypto;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\Orders;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Amount;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\ExperienceContext;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PurchaseUnit;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ExperienceContextBuilder;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PurchaseUnitFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayWithCrypto\PWCGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\TransactionUrlProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor\RefundProcessor;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Mockery\MockInterface;
use WC_Order;

/**
 * The gateway turns a USD order into a PayPal order with the crypto payment source and sends the shopper to the payer
 * action link, refuses any other currency, and hands refunds to the refund processor.
 *
 * @group paypal-wallet
 */
class PWCGatewayTest extends WalletTestCase {

	/**
	 * The Orders endpoint mock.
	 *
	 * @var Orders&MockInterface
	 */
	private $orders;

	/**
	 * The purchase unit factory mock.
	 *
	 * @var PurchaseUnitFactory&MockInterface
	 */
	private $purchase_units;

	/**
	 * The refund processor mock.
	 *
	 * @var RefundProcessor&MockInterface
	 */
	private $refunds;

	/**
	 * The transaction URL provider mock.
	 *
	 * @var TransactionUrlProvider&MockInterface
	 */
	private $transaction_urls;

	/**
	 * The experience context builder mock.
	 *
	 * @var ExperienceContextBuilder&MockInterface
	 */
	private $experience_contexts;

	/**
	 * The gateway under test.
	 *
	 * @var PWCGateway
	 */
	private PWCGateway $gateway;

	/**
	 * Build the gateway over mocks, with an empty cart and no notices.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->orders              = $this->mock( Orders::class );
		$this->purchase_units      = $this->mock( PurchaseUnitFactory::class );
		$this->refunds             = $this->mock( RefundProcessor::class );
		$this->transaction_urls    = $this->mock( TransactionUrlProvider::class );
		$this->experience_contexts = $this->mock( ExperienceContextBuilder::class );

		$asset_getter = $this->mock( AssetGetter::class );
		$asset_getter->shouldReceive( 'get_static_asset_url' )->with( 'images/pwc.svg' )->andReturn( 'https://example.test/static/images/pwc.svg' );

		$this->gateway = new PWCGateway(
			$asset_getter,
			$this->orders,
			$this->purchase_units,
			$this->refunds,
			$this->transaction_urls,
			$this->experience_contexts
		);

		$this->use_own_wc_session();
		if ( ! WC()->cart ) {
			wc_load_cart();
		}
		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	/**
	 * Drop the notices the test added.
	 */
	public function tearDown(): void {
		wc_clear_notices();
		delete_option( 'woocommerce_ppcp-pwc_settings' );

		parent::tearDown();
	}

	/**
	 * An order with a billing name and country, in the given currency.
	 *
	 * @param string $currency The currency code.
	 * @return WC_Order
	 */
	private function make_order( string $currency ): WC_Order {
		$order = wc_create_order();
		$order->set_currency( $currency );
		$order->set_billing_country( 'DE' );
		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_last_name( 'Lovelace' );
		$order->save();

		return $order;
	}

	/**
	 * Make the purchase unit factory and the experience context builder answer for an order.
	 */
	private function stub_request_parts(): void {
		$amount = $this->mock( Amount::class );
		$amount->shouldReceive( 'to_array' )->andReturn(
			array(
				'currency_code' => 'USD',
				'value'         => '10.00',
				'breakdown'     => array( 'item_total' => array() ),
			)
		);

		$purchase_unit = $this->mock( PurchaseUnit::class );
		$purchase_unit->shouldReceive( 'amount' )->andReturn( $amount );
		$purchase_unit->shouldReceive( 'reference_id' )->andReturn( 'default' );
		$purchase_unit->shouldReceive( 'invoice_id' )->andReturn( 'WC-INV-1' );
		$this->purchase_units->shouldReceive( 'from_wc_order' )->andReturn( $purchase_unit );

		$context = $this->mock( ExperienceContext::class );
		$context->shouldReceive( 'to_array' )->andReturn( array( 'locale' => 'en-US' ) );
		$this->experience_contexts->shouldReceive( 'with_custom_return_url' )->andReturnSelf();
		$this->experience_contexts->shouldReceive( 'with_custom_cancel_url' )->andReturnSelf();
		$this->experience_contexts->shouldReceive( 'with_current_locale' )->andReturnSelf();
		$this->experience_contexts->shouldReceive( 'build' )->andReturn( $context );
	}

	/**
	 * The text of the error notices.
	 *
	 * @return string[]
	 */
	private function error_notices(): array {
		return array_column( wc_get_notices( 'error' ), 'notice' );
	}

	/**
	 * @testdox Should identify as the ppcp-pwc gateway with the Pay with Crypto defaults and the icon of the static assets.
	 */
	public function test_identifies_itself_with_the_defaults(): void {
		$this->assertSame( 'ppcp-pwc', PWCGateway::ID );
		$this->assertSame( PWCGateway::ID, $this->gateway->id );
		$this->assertSame( 'Pay with Crypto', $this->gateway->get_title() );
		$this->assertSame( 'Pay with top wallets and coins.', $this->gateway->get_description() );
		$this->assertSame( 'https://example.test/static/images/pwc.svg', $this->gateway->icon );
		$this->assertSame( array( 'refunds', 'products' ), $this->gateway->supports );
	}

	/**
	 * @testdox Should refuse an order that is not in USD with a notice and a failure result.
	 */
	public function test_refuses_a_non_usd_order(): void {
		$order = $this->make_order( 'EUR' );
		$this->orders->shouldNotReceive( 'create' );

		$result = $this->gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( wc_get_checkout_url(), $result['redirect'] );
		$this->assertSame( array( 'Crypto payments are only available for USD orders.' ), $this->error_notices() );
	}

	/**
	 * @testdox Should refuse an order that does not exist with a notice and a failure result.
	 */
	public function test_refuses_a_missing_order(): void {
		$this->orders->shouldNotReceive( 'create' );

		$result = $this->gateway->process_payment( 0 );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( array( 'Order not found.' ), $this->error_notices() );
	}

	/**
	 * @testdox Should create a PayPal order with the crypto payment source, put the order on hold and redirect to the payer action link.
	 */
	public function test_creates_the_paypal_order_and_redirects_to_the_payer_action(): void {
		$order = $this->make_order( 'USD' );
		$this->stub_request_parts();
		$sent = null;
		$this->orders->shouldReceive( 'create' )->once()->andReturnUsing(
			function ( array $body ) use ( &$sent ): array {
				$sent = $body;

				return array(
					'body' => (string) wp_json_encode(
						array(
							'id'    => 'PAYPAL-ORDER-1',
							'links' => array(
								array(
									'rel'  => 'self',
									'href' => 'https://api.example.test/orders/1',
								),
								array(
									'rel'  => 'payer-action',
									'href' => 'https://paypal.example.test/pay?token=1',
								),
							),
						)
					),
				);
			}
		);

		$result = $this->gateway->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'https://paypal.example.test/pay?token=1', $result['redirect'] );

		$this->assertSame( 'ORDER_COMPLETE_ON_PAYMENT_APPROVAL', $sent['processing_instruction'] );
		$this->assertSame( 'CAPTURE', $sent['intent'] );
		$this->assertSame(
			array(
				'reference_id' => 'default',
				'amount'       => array(
					'currency_code' => 'USD',
					'value'         => '10.00',
				),
				'custom_id'    => (string) $order->get_id(),
				'invoice_id'   => 'WC-INV-1',
			),
			$sent['purchase_units'][0]
		);
		$this->assertSame(
			array(
				'crypto' => array(
					'country_code'       => 'DE',
					'name'               => array(
						'given_name' => 'Ada',
						'surname'    => 'Lovelace',
					),
					'experience_context' => array( 'locale' => 'en-US' ),
				),
			),
			$sent['payment_source']
		);

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertSame( 'on-hold', $reloaded->get_status() );
		$this->assertSame( 'PAYPAL-ORDER-1', $reloaded->get_meta( PayPalGateway::ORDER_ID_META_KEY ) );
		$notes = implode( "\n", array_column( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
		$this->assertStringContainsString( 'Awaiting Pay with Crypto payment confirmation.', $notes );
		$this->assertSame( array(), $this->error_notices() );
	}

	/**
	 * @testdox Should fail with the retry notice when the PayPal response carries no payer action link.
	 */
	public function test_fails_when_the_response_has_no_payer_action_link(): void {
		$order = $this->make_order( 'USD' );
		$this->stub_request_parts();
		$this->orders->shouldReceive( 'create' )->once()->andReturn(
			array(
				'body' => (string) wp_json_encode(
					array(
						'id'    => 'PAYPAL-ORDER-2',
						'links' => array(
							array(
								'rel'  => 'self',
								'href' => 'https://api.example.test/orders/2',
							),
						),
					)
				),
			)
		);

		$result = $this->gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( wc_get_checkout_url(), $result['redirect'] );
		$this->assertSame( array( 'Payment failed. Please try again.' ), $this->error_notices() );
	}

	/**
	 * @testdox Should fail with the retry notice when PayPal rejects the order.
	 */
	public function test_fails_when_the_orders_endpoint_throws(): void {
		$order = $this->make_order( 'USD' );
		$this->stub_request_parts();
		$this->orders->shouldReceive( 'create' )->once()->andThrow( new \RuntimeException( 'PayPal is down' ) );

		$result = $this->gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( array( 'Payment failed. Please try again.' ), $this->error_notices() );
	}

	/**
	 * @testdox Should hand a refund to the refund processor.
	 */
	public function test_process_refund_delegates_to_the_refund_processor(): void {
		$order = $this->make_order( 'USD' );
		$this->refunds->shouldReceive( 'process' )->once()->with( \Mockery::on( fn( $arg ) => $arg instanceof WC_Order && $arg->get_id() === $order->get_id() ), 5.5, 'Changed my mind' )->andReturn( true );

		$this->assertTrue( $this->gateway->process_refund( $order->get_id(), 5.5, 'Changed my mind' ) );
	}

	/**
	 * @testdox Should not refund an order that does not exist.
	 */
	public function test_process_refund_returns_false_for_a_missing_order(): void {
		$this->refunds->shouldNotReceive( 'process' );

		$this->assertFalse( $this->gateway->process_refund( 0, 5.5 ) );
	}

	/**
	 * @testdox Should build the transaction URL from the provider's base.
	 */
	public function test_get_transaction_url_uses_the_providers_base(): void {
		$order = $this->make_order( 'USD' );
		$order->set_transaction_id( 'TX-1' );
		$this->transaction_urls->shouldReceive( 'get_transaction_url_base' )->once()->andReturn( 'https://paypal.example.test/activity/payment/%s' );

		$this->assertSame( 'https://paypal.example.test/activity/payment/TX-1', $this->gateway->get_transaction_url( $order ) );
	}
}
