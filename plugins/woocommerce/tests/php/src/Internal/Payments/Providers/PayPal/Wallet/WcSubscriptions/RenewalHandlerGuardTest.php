<?php
/**
 * Tests for the guard of the PayPal wallet renewal handler.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\OrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Payer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PaymentSource;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PurchaseUnit;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PayerFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PurchaseUnitFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ShippingPreferenceFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\FundingSource\FundingSourceRenderer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\Environment;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor\AuthorizedPaymentsProcessor;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcPaymentTokens\PaymentTokenPayPal;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcPaymentTokens\WooCommercePaymentTokens;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\RenewalHandler;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Mockery\MockInterface;
use WC_Order;
use WC_Subscription;
use WcSubscriptionsDouble;

/**
 * The renewal handler charges the saved PayPal payment method of a renewal order, unless PayPal itself bills the
 * subscription: a subscription that carries the "ppcp_subscription" meta must never be charged a second time here.
 *
 * Runs over real users, orders and vaulted payment tokens. WooCommerce Subscriptions is not loaded in core's suite, so
 * its functions and its subscription class come from a double; PayPal's order endpoint and the processor that captures
 * authorized payments are Mockery mocks.
 *
 * @group paypal-wallet
 */
class RenewalHandlerGuardTest extends WalletTestCase {

	/**
	 * The order endpoint.
	 *
	 * @var OrderEndpoint&MockInterface
	 */
	private $order_endpoint;

	/**
	 * The purchase unit factory.
	 *
	 * @var PurchaseUnitFactory&MockInterface
	 */
	private $purchase_unit_factory;

	/**
	 * The processor for authorized payments.
	 *
	 * @var AuthorizedPaymentsProcessor&MockInterface
	 */
	private $authorized_payments_processor;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockInterface
	 */
	private $logger;

	/**
	 * The System Under Test.
	 *
	 * @var RenewalHandler
	 */
	private $sut;

	/**
	 * Load the WooCommerce Subscriptions double, map the token class and build the handler over mocks.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__ ) . '/Doubles/WcSubscriptionsDouble.php';
		if ( ! WcSubscriptionsDouble::in_effect() ) {
			$this->markTestSkipped( 'WooCommerce Subscriptions is loaded: its own functions answer, not the double.' );
		}

		// WooCommerce loads a saved token through the class this filter names; the wallet's module registers it.
		add_filter(
			'woocommerce_payment_token_class',
			static function ( $class_name ) {
				return 'WC_Payment_Token_PayPal' === $class_name ? PaymentTokenPayPal::class : $class_name;
			}
		);

		$this->order_endpoint                = $this->mock( OrderEndpoint::class );
		$this->purchase_unit_factory         = $this->mock( PurchaseUnitFactory::class );
		$this->authorized_payments_processor = $this->mock( AuthorizedPaymentsProcessor::class );
		$this->logger                        = $this->mock( LoggerInterface::class );

		$environment = $this->mock( Environment::class );
		$environment->shouldReceive( 'is_sandbox' )->andReturn( true );

		$settings_provider = $this->mock( SettingsProvider::class );
		$settings_provider->shouldReceive( 'capture_virtual_orders' )->andReturn( true );

		$payer_factory = $this->mock( PayerFactory::class );
		$payer_factory->shouldReceive( 'from_customer' )->andReturn( $this->mock( Payer::class ) );

		$shipping_preference_factory = $this->mock( ShippingPreferenceFactory::class );
		$shipping_preference_factory->shouldReceive( 'from_state' )->andReturn( 'NO_SHIPPING' );

		$wc_payment_tokens = $this->mock( WooCommercePaymentTokens::class );
		$wc_payment_tokens->shouldReceive( 'customer_tokens' )->andReturnUsing(
			static function (): array {
				return array( array( 'id' => 'VAULT-1' ) );
			}
		);

		$this->sut = new RenewalHandler(
			$this->logger,
			$this->order_endpoint,
			$this->purchase_unit_factory,
			$shipping_preference_factory,
			$payer_factory,
			$environment,
			$settings_provider,
			$this->authorized_payments_processor,
			$this->mock( FundingSourceRenderer::class ),
			$wc_payment_tokens
		);
	}

	/**
	 * Forget the subscriptions.
	 */
	public function tearDown(): void {
		try {
			WcSubscriptionsDouble::reset();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * A renewal order of a customer who has a vaulted PayPal payment method, and the subscription it renews.
	 *
	 * @param string|null $ppcp_subscription The "ppcp_subscription" meta of the subscription, null for none.
	 * @return WC_Order The renewal order.
	 */
	private function renewal_order_of_subscription_with_meta( ?string $ppcp_subscription ): WC_Order {
		$user_id = self::factory()->user->create();

		$token = new PaymentTokenPayPal();
		$token->set_token( 'VAULT-1' );
		$token->set_user_id( $user_id );
		$token->set_gateway_id( PayPalGateway::ID );
		$token->save();

		$subscription = new WC_Subscription();
		if ( null !== $ppcp_subscription ) {
			$subscription->update_meta_data( 'ppcp_subscription', $ppcp_subscription );
		}

		$renewal_order = wc_create_order();
		$renewal_order->set_customer_id( $user_id );
		$renewal_order->set_payment_method( PayPalGateway::ID );
		$renewal_order->save();

		WcSubscriptionsDouble::register_renewal( $renewal_order, array( 7001 => $subscription ) );

		return $renewal_order;
	}

	/**
	 * A PayPal order for an authorization that PayPal reports, with no purchase units.
	 *
	 * @return Order&MockInterface
	 */
	private function authorize_order(): Order {
		$order = $this->mock( Order::class );
		$order->shouldReceive( 'id' )->andReturn( 'PAYPAL-ORDER-1' );
		$order->shouldReceive( 'intent' )->andReturn( 'AUTHORIZE' );
		$order->shouldReceive( 'payment_source' )->andReturn( null );
		$order->shouldReceive( 'payer' )->andReturn( null );
		$order->shouldReceive( 'purchase_units' )->andReturn( array() );

		return $order;
	}

	/**
	 * @testdox Should neither create a PayPal order nor touch the WooCommerce order when the subscription carries a "ppcp_subscription" meta.
	 */
	public function test_renewal_of_a_subscription_that_paypal_bills_is_not_charged(): void {
		$renewal_order = $this->renewal_order_of_subscription_with_meta( 'I-PAYPAL-PLAN' );
		$status_before = $renewal_order->get_status();

		$this->purchase_unit_factory->shouldNotReceive( 'from_wc_order' );
		$this->order_endpoint->shouldNotReceive( 'create' );
		$this->authorized_payments_processor->shouldNotReceive( 'capture_authorized_payment' );
		$this->logger->shouldNotReceive( 'error' );

		$this->sut->renew( $renewal_order );

		$this->assertSame( $status_before, wc_get_order( $renewal_order->get_id() )->get_status(), 'The renewal order should keep its status' );
		$this->assertSame( '', wc_get_order( $renewal_order->get_id() )->get_meta( PayPalGateway::ORDER_ID_META_KEY ), 'No PayPal order should be recorded' );
	}

	/**
	 * @testdox Should charge the vaulted PayPal token and capture the authorization when the subscription has no "ppcp_subscription" meta.
	 */
	public function test_renewal_of_a_subscription_without_paypal_billing_is_charged_with_the_vaulted_token(): void {
		$renewal_order = $this->renewal_order_of_subscription_with_meta( null );
		$paypal_order  = $this->authorize_order();

		$this->purchase_unit_factory->shouldReceive( 'from_wc_order' )->once()->with( $renewal_order )->andReturn( $this->mock( PurchaseUnit::class ) );
		$payment_source = null;
		$this->order_endpoint->shouldReceive( 'create' )->once()->andReturnUsing(
			function ( array $items, string $shipping_preference, $payer, string $payment_method, array $request_data, $source ) use ( $paypal_order, &$payment_source ) {
				$payment_source = $source;

				return $paypal_order;
			}
		);
		$this->order_endpoint->shouldReceive( 'order' )->with( 'PAYPAL-ORDER-1' )->andReturn( $paypal_order );
		$this->authorized_payments_processor->shouldReceive( 'capture_authorized_payment' )->once()->with( $renewal_order )->andReturn( true );
		$this->logger->shouldReceive( 'info' )->once();
		$this->logger->shouldNotReceive( 'error' );

		$this->sut->renew( $renewal_order );

		$this->assertInstanceOf( PaymentSource::class, $payment_source );
		$this->assertSame( 'paypal', $payment_source->name() );
		$this->assertSame( 'VAULT-1', $payment_source->properties()->vault_id, 'The vaulted token should pay' );
		$this->assertSame( 'PAYPAL-ORDER-1', wc_get_order( $renewal_order->get_id() )->get_meta( PayPalGateway::ORDER_ID_META_KEY ), 'The PayPal order should be recorded on the renewal order' );
	}

	/**
	 * @testdox Should charge the renewal when the subscription's "ppcp_subscription" meta is empty.
	 */
	public function test_renewal_of_a_subscription_with_an_empty_paypal_meta_is_charged(): void {
		$renewal_order = $this->renewal_order_of_subscription_with_meta( '' );

		$this->purchase_unit_factory->shouldReceive( 'from_wc_order' )->once()->andReturn( $this->mock( PurchaseUnit::class ) );
		$this->order_endpoint->shouldReceive( 'create' )->once()->andReturn( $this->authorize_order() );
		$this->order_endpoint->shouldReceive( 'order' )->andReturn( $this->authorize_order() );
		$this->authorized_payments_processor->shouldReceive( 'capture_authorized_payment' )->once()->andReturn( true );
		$this->logger->shouldReceive( 'info' );

		$this->sut->renew( $renewal_order );

		$this->assertSame( 'PAYPAL-ORDER-1', wc_get_order( $renewal_order->get_id() )->get_meta( PayPalGateway::ORDER_ID_META_KEY ) );
	}
}
