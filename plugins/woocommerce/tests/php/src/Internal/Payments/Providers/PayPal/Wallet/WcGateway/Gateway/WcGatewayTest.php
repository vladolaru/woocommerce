<?php
/**
 * Tests for the PayPal wallet gateway (ported from the extension's WcGatewayTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\OrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PaymentTokensEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\PayPalApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\Context;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\SessionHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Endpoint\CapturePayPalPayment;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\FundingSource\FundingSourceRenderer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\TransactionUrlProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\Environment;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor\OrderProcessor;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor\RefundProcessor;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcPaymentTokens\PaymentTokenPayPal;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcPaymentTokens\WooCommercePaymentTokens;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\SubscriptionHelper;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Exception;
use Mockery;
use Mockery\MockInterface;
use WC_Order;
use WC_Session_Handler;

/**
 * The gateway's process_payment(), payment fields and funding-source titles, over a real WooCommerce order, session,
 * notices and payment tokens, with the gateway's own collaborators mocked.
 *
 * @group paypal-wallet
 */
class WcGatewayTest extends WalletTestCase {

	/**
	 * The funding source the session reports; tests assign it before building the gateway.
	 *
	 * @var string|null
	 */
	private $funding_source = null;

	/**
	 * The funding source renderer (real, over the settings provider mock).
	 *
	 * @var FundingSourceRenderer
	 */
	private $funding_source_renderer;

	/**
	 * The order processor mock.
	 *
	 * @var OrderProcessor&MockInterface
	 */
	private $order_processor;

	/**
	 * The settings provider mock.
	 *
	 * @var SettingsProvider&MockInterface
	 */
	private $settings_provider;

	/**
	 * The PayPal session handler mock.
	 *
	 * @var SessionHandler&MockInterface
	 */
	private $session_handler;

	/**
	 * The PayPal order the session handler returns by default.
	 *
	 * @var Order&MockInterface
	 */
	private $session_order;

	/**
	 * The refund processor mock.
	 *
	 * @var RefundProcessor&MockInterface
	 */
	private $refund_processor;

	/**
	 * The transaction URL provider mock.
	 *
	 * @var TransactionUrlProvider&MockInterface
	 */
	private $transaction_url_provider;

	/**
	 * The subscription helper mock.
	 *
	 * @var SubscriptionHelper&MockInterface
	 */
	private $subscription_helper;

	/**
	 * The environment mock.
	 *
	 * @var Environment&MockInterface
	 */
	private $environment;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockInterface
	 */
	private $logger;

	/**
	 * The payment tokens endpoint mock.
	 *
	 * @var PaymentTokensEndpoint&MockInterface
	 */
	private $payment_tokens_endpoint;

	/**
	 * The WooCommerce payment tokens service mock.
	 *
	 * @var WooCommercePaymentTokens&MockInterface
	 */
	private $wc_payment_tokens;

	/**
	 * The context helper mock.
	 *
	 * @var Context&MockInterface
	 */
	private $context;

	/**
	 * The vault payment capture endpoint mock.
	 *
	 * @var CapturePayPalPayment&MockInterface
	 */
	private $capture_paypal_payment;

	/**
	 * The session the test replaced, restored on tearDown.
	 *
	 * @var mixed
	 */
	private $original_session;

	/**
	 * The $_POST and $_REQUEST the test started with, restored on tearDown.
	 *
	 * @var array
	 */
	private $original_request = array();

	/**
	 * The query vars the test started with, restored on tearDown.
	 *
	 * @var array
	 */
	private $original_query_vars = array();

	/**
	 * Build the collaborators, a real customer session and the request state the gateway reads.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_session = WC()->session;
		WC()->session           = new WC_Session_Handler();

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Saving test state.
		$this->original_request = array(
			'post'    => $_POST,
			'request' => $_REQUEST,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		$this->original_query_vars = $GLOBALS['wp']->query_vars;

		// The wallet registers this mapping when it boots; the container is not booted in this test.
		add_filter(
			'woocommerce_payment_token_class',
			function ( $class_name ) {
				return 'WC_Payment_Token_PayPal' === $class_name ? PaymentTokenPayPal::class : $class_name;
			}
		);

		$this->order_processor          = $this->mock( OrderProcessor::class );
		$this->settings_provider        = $this->mock( SettingsProvider::class );
		$this->session_handler          = $this->mock( SessionHandler::class );
		$this->refund_processor         = $this->mock( RefundProcessor::class );
		$this->transaction_url_provider = $this->mock( TransactionUrlProvider::class );
		$this->subscription_helper      = $this->mock( SubscriptionHelper::class );
		$this->environment              = $this->mock( Environment::class );
		$this->logger                   = $this->mock( LoggerInterface::class )->shouldIgnoreMissing();
		$this->payment_tokens_endpoint  = $this->mock( PaymentTokensEndpoint::class );
		$this->wc_payment_tokens        = $this->mock( WooCommercePaymentTokens::class );
		$this->context                  = $this->mock( Context::class );
		$this->capture_paypal_payment   = $this->mock( CapturePayPalPayment::class );

		$this->settings_provider->shouldReceive( 'paypal_gateway_title' )->andReturn( 'PayPal' );
		$this->settings_provider->shouldReceive( 'paypal_gateway_description' )->andReturn( 'Pay via PayPal.' );
		$this->settings_provider->shouldReceive( 'merchant_email' )->andReturn( '' );

		$this->funding_source_renderer = new FundingSourceRenderer(
			$this->settings_provider,
			array(
				'venmo'    => 'Venmo',
				'paylater' => 'Pay Later',
				'blik'     => 'BLIK',
			)
		);

		$this->session_handler
			->shouldReceive( 'funding_source' )
			->andReturnUsing(
				function () {
					return $this->funding_source;
				}
			);

		$this->session_order = $this->mock( Order::class );
		$this->session_order->shouldReceive( 'status' )->andReturn( new OrderStatus( OrderStatus::APPROVED ) );
		$this->session_handler->shouldReceive( 'order' )->andReturn( $this->session_order )->byDefault();
	}

	/**
	 * Restore the globals, the session and the notices the test touched.
	 */
	public function tearDown(): void {
		try {
			wc_clear_notices();
			WC()->session = $this->original_session;
			$_POST        = $this->original_request['post']; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Restoring test state.
			$_REQUEST     = $this->original_request['request']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Restoring test state.

			$GLOBALS['wp']->query_vars = $this->original_query_vars;
			wp_set_current_user( 0 );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * The arguments of the gateway constructor, in the constructor's order.
	 *
	 * @return array
	 */
	private function gateway_arguments(): array {
		return array(
			$this->funding_source_renderer,
			$this->order_processor,
			$this->settings_provider,
			$this->session_handler,
			$this->refund_processor,
			true,
			$this->transaction_url_provider,
			$this->subscription_helper,
			$this->environment,
			$this->logger,
			'DE',
			static function ( $id ) {
				return 'checkoutnow=' . $id;
			},
			$this->payment_tokens_endpoint,
			$this->wc_payment_tokens,
			new AssetGetter( 'http://example.com', '/plugin/', 'module' ),
			false,
			$this->capture_paypal_payment,
			$this->mock( OrderEndpoint::class ),
			$this->context,
		);
	}

	/**
	 * Build the gateway under test.
	 *
	 * @return PayPalGateway
	 */
	private function create_gateway(): PayPalGateway {
		return new PayPalGateway( ...$this->gateway_arguments() );
	}

	/**
	 * Build the gateway double that counts the saved-method calls.
	 *
	 * @return SpyablePayPalGateway
	 */
	private function create_spy_gateway(): SpyablePayPalGateway {
		return new SpyablePayPalGateway( ...$this->gateway_arguments() );
	}

	/**
	 * Build the gateway double that treats $0 orders as free trials.
	 *
	 * @return PayPalGatewayFreeTrialStub
	 */
	private function create_free_trial_gateway(): PayPalGatewayFreeTrialStub {
		return new PayPalGatewayFreeTrialStub( ...$this->gateway_arguments() );
	}

	/**
	 * A saved pending order paid with this gateway.
	 *
	 * @param int   $customer_id The customer ID (0 for a guest).
	 * @param float $total       The order total.
	 * @return WC_Order
	 */
	private function create_order( int $customer_id = 0, float $total = 10.0 ): WC_Order {
		$order = wc_create_order( array( 'customer_id' => $customer_id ) );
		$order->set_payment_method( PayPalGateway::ID );
		$order->set_total( (string) $total );
		$order->save();

		return $order;
	}

	/**
	 * A saved PayPal token owned by the user.
	 *
	 * @param int $user_id The owner.
	 * @return PaymentTokenPayPal
	 */
	private function create_paypal_token( int $user_id ): PaymentTokenPayPal {
		$token = new PaymentTokenPayPal();
		$token->set_gateway_id( PayPalGateway::ID );
		$token->set_token( 'vault-' . wp_generate_uuid4() );
		$token->set_user_id( $user_id );
		$token->set_email( 'buyer@example.com' );
		$token->save();

		return $token;
	}

	/**
	 * The current state of an order, read back from the database.
	 *
	 * @param int $order_id The order ID.
	 * @return WC_Order
	 */
	private function reload_order( int $order_id ): WC_Order {
		$order = wc_get_order( $order_id );
		$this->assertInstanceOf( WC_Order::class, $order, 'The order should still exist' );

		return $order;
	}

	/**
	 * The text of the notes on an order.
	 *
	 * @param int $order_id The order ID.
	 * @return string[]
	 */
	private function order_notes( int $order_id ): array {
		return wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order_id ) ), 'content' );
	}

	/**
	 * Assert that one of the order's notes contains the text (a status change note prefixes the reason it was given).
	 *
	 * @param int    $order_id The order ID.
	 * @param string $text     The expected text.
	 */
	private function assert_order_has_note_containing( int $order_id, string $text ): void {
		$matching = array_filter(
			$this->order_notes( $order_id ),
			function ( string $note ) use ( $text ): bool {
				return false !== strpos( $note, $text );
			}
		);

		$this->assertNotEmpty( $matching, "No order note contains '$text'" );
	}

	/**
	 * The text of the error notices the shopper would see.
	 *
	 * @return string[]
	 */
	private function error_notices(): array {
		return wp_list_pluck( wc_get_notices( 'error' ), 'notice' );
	}

	/**
	 * Make the request look like the Pay for Order page.
	 *
	 * @param int $order_id The order being paid.
	 */
	private function simulate_pay_for_order_page( int $order_id ): void {
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		$GLOBALS['wp']->query_vars['order-pay'] = $order_id;
	}

	/**
	 * Flag the session so a failed checkout order is deleted, as the failed-capture handler does.
	 */
	private function flag_order_for_deletion_on_failure(): void {
		WC()->session->set( 'ppcp_delete_wc_order_on_payment_failure', true );
	}

	/**
	 * @testdox Should process the order and return the order-received URL as the redirect.
	 */
	public function test_process_payment_success(): void {
		$order = $this->create_order( 1 );

		$this->order_processor->expects( 'process' )->once()->andReturn( true );
		$this->session_handler->shouldReceive( 'destroy_session_data' )->once();

		$result = $this->create_gateway()->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( $this->create_gateway()->get_return_url( $order ), $result['redirect'] );
	}

	/**
	 * @testdox Should return a failure with a notice when the order does not exist.
	 */
	public function test_process_payment_order_not_found(): void {
		$this->session_handler->shouldReceive( 'destroy_session_data' )->once();

		$result = $this->create_gateway()->process_payment( 987654321 );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( wc_get_checkout_url(), $result['redirect'] );
		$this->assertArrayHasKey( 'errorMessage', $result );
		$this->assertSame( array( $result['errorMessage'] ), $this->error_notices() );
	}

	/**
	 * @testdox Should fail the order, show the error and return a failure when the order processor throws.
	 */
	public function test_process_payment_fails(): void {
		$order = $this->create_order( 1 );

		$this->order_processor->expects( 'process' )->andThrow( new Exception( 'some-error' ) );
		$this->session_handler->shouldReceive( 'destroy_session_data' )->once();

		$result = $this->create_gateway()->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( wc_get_checkout_url(), $result['redirect'] );
		$this->assertSame( 'some-error', $result['errorMessage'] );
		$this->assertSame( array( 'some-error' ), $this->error_notices() );
		$this->assertSame( 'failed', $this->reload_order( $order->get_id() )->get_status() );
	}

	/**
	 * A freshly created checkout order is deleted when the failed-capture handler flagged it and the page is not Pay for Order.
	 *
	 * @testdox Should delete the transient checkout order when payment fails and the session flags it for deletion.
	 */
	public function test_process_payment_failure_deletes_fresh_order_during_checkout(): void {
		$order = $this->create_order( 1 );
		$this->flag_order_for_deletion_on_failure();

		$this->order_processor->allows( 'process' )->andThrow( new Exception( 'capture-validation-error' ) );
		$this->session_handler->allows( 'destroy_session_data' );

		$result = $this->create_gateway()->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertFalse( wc_get_order( $order->get_id() ), 'The checkout order should be deleted' );
		$this->assertFalse( WC()->session->get( 'ppcp_delete_wc_order_on_payment_failure' ), 'The deletion flag should be reset' );
	}

	/**
	 * A pre-existing order opened on the Pay for Order page is never deleted: it is marked failed so the customer can retry.
	 *
	 * @testdox Should keep the order, marked failed, when payment fails on the Pay for Order page even if the session flags deletion.
	 */
	public function test_process_payment_failure_keeps_order_on_pay_for_order_page(): void {
		$order = $this->create_order( 1 );
		$this->flag_order_for_deletion_on_failure();
		$this->simulate_pay_for_order_page( $order->get_id() );

		$this->order_processor->allows( 'process' )->andThrow( new Exception( 'capture-validation-error' ) );
		$this->session_handler->allows( 'destroy_session_data' );

		$result = $this->create_gateway()->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( 'failed', $this->reload_order( $order->get_id() )->get_status() );
	}

	/**
	 * A recoverable PayPal retry issue below the give-up threshold keeps the order as it is, notes the reason and sends
	 * the customer back to PayPal.
	 *
	 * @testdox Should keep the order pending, note the reason and redirect to PayPal on a recoverable $issue issue with retries left.
	 *
	 * @dataProvider data_for_retry_error_issue
	 *
	 * @param string $issue            The PayPal issue code.
	 * @param string $expected_message The note the issue maps to.
	 */
	public function test_process_payment_keeps_order_pending_on_recoverable_retry_issue( string $issue, string $expected_message ): void {
		$order = $this->create_order( 1 );

		$this->order_processor->expects( 'process' )->andThrow( $this->retry_exception( $issue ) );

		$this->session_handler->shouldReceive( 'increment_insufficient_funding_tries' )->once();
		$this->session_handler->shouldReceive( 'insufficient_funding_tries' )->andReturn( 1 );
		$this->session_handler->shouldNotReceive( 'destroy_session_data' );
		$this->session_order->shouldReceive( 'id' )->andReturn( 'PAYPAL-ORDER-ID' );

		$result = $this->create_gateway()->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'checkoutnow=PAYPAL-ORDER-ID', $result['redirect'] );
		$this->assertSame( 'pending', $this->reload_order( $order->get_id() )->get_status(), 'The order should not be marked failed' );
		$this->assertContains( $expected_message . ' Additional action needed.', $this->order_notes( $order->get_id() ) );
	}

	/**
	 * Once the retry count reaches 3 the retry is exhausted and the order fails.
	 *
	 * @testdox Should fail the order on a $issue issue once the retries are exhausted.
	 *
	 * @dataProvider data_for_retry_error_issue
	 *
	 * @param string $issue            The PayPal issue code.
	 * @param string $expected_message The note the issue maps to.
	 */
	public function test_process_payment_fails_order_when_retry_issue_retries_exhausted( string $issue, string $expected_message ): void {
		$order = $this->create_order( 1 );

		$this->order_processor->expects( 'process' )->andThrow( $this->retry_exception( $issue ) );

		$this->session_handler->shouldReceive( 'increment_insufficient_funding_tries' )->once();
		$this->session_handler->shouldReceive( 'insufficient_funding_tries' )->andReturn( 3 );
		$this->session_handler->shouldReceive( 'destroy_session_data' )->once();

		$result = $this->create_gateway()->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( 'failed', $this->reload_order( $order->get_id() )->get_status() );
		$this->assert_order_has_note_containing( $order->get_id(), $expected_message . ' Additional action needed.' );
	}

	/**
	 * With no PayPal order left in the session there is nothing to retry against, so the order fails.
	 *
	 * @testdox Should fail the order on a $issue issue when the session no longer holds a PayPal order.
	 *
	 * @dataProvider data_for_retry_error_issue
	 *
	 * @param string $issue            The PayPal issue code.
	 * @param string $expected_message The note the issue maps to.
	 */
	public function test_process_payment_fails_order_when_retry_issue_session_order_missing( string $issue, string $expected_message ): void {
		$order = $this->create_order( 1 );

		$this->order_processor->expects( 'process' )->andThrow( $this->retry_exception( $issue ) );

		$this->session_handler->shouldReceive( 'increment_insufficient_funding_tries' )->once();
		$this->session_handler->shouldReceive( 'insufficient_funding_tries' )->andReturn( 1 );
		$this->session_handler->shouldReceive( 'order' )->andReturn( null );
		$this->session_handler->shouldReceive( 'destroy_session_data' )->once();

		$result = $this->create_gateway()->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( 'failed', $this->reload_order( $order->get_id() )->get_status() );
		$this->assert_order_has_note_containing( $order->get_id(), $expected_message . ' Additional action needed.' );
	}

	/**
	 * PayPal issues that allow a retry, with the note each maps to.
	 *
	 * @return array<string, array<string>>
	 */
	public function data_for_retry_error_issue(): array {
		return array(
			'instrument declined'   => array( 'INSTRUMENT_DECLINED', 'Instrument declined.' ),
			'payer action required' => array( 'PAYER_ACTION_REQUIRED', 'Payer action required, possibly overcharge.' ),
		);
	}

	/**
	 * A PayPal API exception carrying one issue.
	 *
	 * @param string $issue The PayPal issue code.
	 * @return PayPalApiException
	 */
	private function retry_exception( string $issue ): PayPalApiException {
		$response          = new \stdClass();
		$response->details = array(
			(object) array(
				'issue'       => $issue,
				'description' => 'Additional action needed.',
			),
		);

		return new PayPalApiException( $response, 422 );
	}

	/**
	 * The title and description follow the funding source the buyer approved, when the session holds an approved order.
	 *
	 * @testdox Should title the gateway "$title" and describe it "$description" for the funding source "$funding_source".
	 *
	 * @dataProvider data_for_funding_source
	 *
	 * @param string|null $funding_source The funding source in the session.
	 * @param string      $title          The expected title.
	 * @param string      $description    The expected description.
	 */
	public function test_funding_source( $funding_source, string $title, string $description ): void {
		$this->funding_source = $funding_source;

		$gateway = $this->create_gateway();

		$this->assertSame( $title, $gateway->title );
		$this->assertSame( $description, $gateway->description );
	}

	/**
	 * Funding sources with the title and description they produce.
	 *
	 * @return array<string, array>
	 */
	public function data_for_funding_source(): array {
		return array(
			'none'            => array( null, 'PayPal', 'Pay via PayPal.' ),
			'venmo'           => array( 'venmo', 'Venmo', 'Pay via Venmo.' ),
			'pay later'       => array( 'paylater', 'Pay Later', 'Pay via Pay Later.' ),
			'blik via PayPal' => array( 'blik', 'BLIK (via PayPal)', 'Pay via BLIK.' ),
			'unknown source'  => array( 'qwerty', 'PayPal', 'Pay via PayPal.' ),
		);
	}

	/**
	 * Render the payment fields of a spy gateway on the checkout page.
	 *
	 * @param bool $continuation Whether a PayPal continuation is in progress.
	 * @param bool $vaulting     Whether the merchant enabled saving PayPal and Venmo.
	 * @return SpyablePayPalGateway
	 */
	private function render_payment_fields( bool $continuation, bool $vaulting ): SpyablePayPalGateway {
		$this->context->shouldReceive( 'is_paypal_continuation' )->andReturn( $continuation );
		$this->settings_provider->shouldReceive( 'save_paypal_and_venmo' )->andReturn( $vaulting );
		add_filter( 'woocommerce_is_checkout', '__return_true' );

		$gateway = $this->create_spy_gateway();
		$gateway->set_test_supports( array( 'tokenization' ) );

		ob_start();
		$gateway->payment_fields();
		ob_end_clean();

		return $gateway;
	}

	/**
	 * During a PayPal continuation the payment source is already chosen, so the saved-method UI would only render stray
	 * radio buttons.
	 *
	 * @testdox Should not render the saved-method UI during a PayPal continuation.
	 */
	public function test_payment_fields_during_continuation_flow_suppresses_saved_method_ui(): void {
		$gateway = $this->render_payment_fields( true, true );

		$this->assertSame( 0, $gateway->tokenization_script_call_count, 'tokenization_script() must not be called during PayPal continuation flow' );
		$this->assertSame( 0, $gateway->saved_payment_methods_call_count, 'saved_payment_methods() must not be called during PayPal continuation flow' );
	}

	/**
	 * @testdox Should render the saved-method UI on a normal checkout with vaulting enabled.
	 */
	public function test_payment_fields_on_normal_checkout_renders_saved_method_ui(): void {
		$gateway = $this->render_payment_fields( false, true );

		$this->assertSame( 1, $gateway->tokenization_script_call_count, 'tokenization_script() must be called on a normal checkout with vaulting enabled' );
		$this->assertSame( 1, $gateway->saved_payment_methods_call_count, 'saved_payment_methods() must be called on a normal checkout with vaulting enabled' );
	}

	/**
	 * @testdox Should not render the saved-method UI when vaulting is disabled.
	 */
	public function test_payment_fields_with_vaulting_disabled_suppresses_saved_method_ui(): void {
		$gateway = $this->render_payment_fields( false, false );

		$this->assertSame( 0, $gateway->tokenization_script_call_count, 'tokenization_script() must not be called when vaulting is disabled' );
		$this->assertSame( 0, $gateway->saved_payment_methods_call_count, 'saved_payment_methods() must not be called when vaulting is disabled' );
	}

	/**
	 * WC Subscriptions zeroes the order total during a change-payment request, and PayPal rejects $0 create-order calls
	 * with CANNOT_BE_ZERO_OR_NEGATIVE, so the saved token the current user owns is attached without a capture.
	 *
	 * @testdox Should attach a saved PayPal token the current user owns when changing a subscription's payment, without capturing.
	 */
	public function test_process_payment_attaches_token_when_ownership_check_passes_for_change_payment(): void {
		$user_id = $this->factory->user->create();
		wp_set_current_user( $user_id );
		$token = $this->create_paypal_token( $user_id );
		$order = $this->create_order( $user_id );

		$_POST['woocommerce_change_payment']    = '1';
		$_POST['wc-ppcp-gateway-payment-token'] = (string) $token->get_id();

		$this->subscription_helper->shouldReceive( 'has_subscription' )->with( $order->get_id() )->andReturn( true );
		$this->subscription_helper->shouldReceive( 'is_subscription_change_payment' )->andReturn( true );
		$this->session_handler->shouldReceive( 'destroy_session_data' )->once();
		$this->capture_paypal_payment->shouldNotReceive( 'create_order' );

		$result = $this->create_gateway()->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( array( $token->get_id() ), $this->reload_order( $order->get_id() )->get_payment_tokens() );
	}

	/**
	 * @testdox Should reject a token owned by another user when changing a subscription's payment, attaching nothing and capturing nothing.
	 */
	public function test_process_payment_rejects_token_owned_by_different_user_during_change_payment(): void {
		$user_id  = $this->factory->user->create();
		$other_id = $this->factory->user->create();
		wp_set_current_user( $user_id );
		$token = $this->create_paypal_token( $other_id );
		$order = $this->create_order( $user_id );

		$_POST['woocommerce_change_payment']    = '1';
		$_POST['wc-ppcp-gateway-payment-token'] = (string) $token->get_id();

		$this->subscription_helper->shouldReceive( 'has_subscription' )->with( $order->get_id() )->andReturn( true );
		$this->subscription_helper->shouldReceive( 'is_subscription_change_payment' )->andReturn( true );
		$this->session_handler->shouldNotReceive( 'destroy_session_data' );
		$this->capture_paypal_payment->shouldNotReceive( 'create_order' );

		$result = $this->create_gateway()->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( array(), $this->reload_order( $order->get_id() )->get_payment_tokens() );
		$this->assertSame( array( 'Could not change payment.' ), $this->error_notices() );
	}

	/**
	 * A buyer who just vaulted their PayPal account through the save-payment flow submits a $0 free-trial order. PayPal's
	 * payment-tokens list is eventually consistent and can omit a just-created token, so the local WC token is trusted
	 * directly and the order completes without consulting PayPal for the token list.
	 *
	 * @testdox Should complete a free-trial order from the local PayPal token without asking PayPal for the token list.
	 */
	public function test_process_payment_completes_free_trial_when_local_paypal_token_exists(): void {
		$user_id = $this->factory->user->create();
		$this->create_paypal_token( $user_id );
		$order = $this->create_order( $user_id, 0.0 );

		$_POST = array( 'ppcp-funding-source' => 'paypal' );

		$this->subscription_helper->shouldReceive( 'paypal_subscription_id' )->andReturn( '' );
		$this->payment_tokens_endpoint->shouldNotReceive( 'payment_tokens_for_customer' );
		$this->session_handler->allows( 'destroy_session_data' );

		$result = $this->create_free_trial_gateway()->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertTrue( $this->reload_order( $order->get_id() )->is_paid(), 'The free-trial order should be completed' );
	}

	/**
	 * @testdox Should fail a free-trial order with no local and no remote PayPal token.
	 */
	public function test_process_payment_fails_free_trial_when_no_local_or_remote_paypal_token(): void {
		$user_id = $this->factory->user->create();
		update_user_meta( $user_id, '_ppcp_target_customer_id', 'CUST123' );
		$order = $this->create_order( $user_id, 0.0 );

		$_POST = array( 'ppcp-funding-source' => 'paypal' );

		$this->subscription_helper->shouldReceive( 'paypal_subscription_id' )->andReturn( '' );
		$this->payment_tokens_endpoint->shouldReceive( 'payment_tokens_for_customer' )->with( 'CUST123' )->once()->andReturn( array() );
		$this->session_handler->allows( 'destroy_session_data' );

		$result = $this->create_free_trial_gateway()->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertFalse( $this->reload_order( $order->get_id() )->is_paid() );
		$this->assertSame( 'failed', $this->reload_order( $order->get_id() )->get_status() );
		$this->assertSame( array( 'No saved PayPal account.' ), $this->error_notices() );
	}

	/**
	 * A first-time free-trial buyer has no saved account anywhere. The save-payment-methods module hooks the
	 * woocommerce_paypal_payments_free_trial_vault_redirect_url filter to build a vault-approval URL; when it supplies
	 * one the buyer goes to PayPal to approve saving the account instead of the order failing.
	 *
	 * @testdox Should redirect a first-time free-trial buyer to the vault-approval URL the filter supplies.
	 */
	public function test_process_payment_redirects_free_trial_when_vault_redirect_filter_returns_url(): void {
		$user_id = $this->factory->user->create();
		$order   = $this->create_order( $user_id, 0.0 );

		$_POST              = array( 'ppcp-funding-source' => 'paypal' );
		$vault_redirect_url = 'https://www.paypal.com/agreements/approve?token=XYZ';
		$received           = array();

		add_filter(
			'woocommerce_paypal_payments_free_trial_vault_redirect_url',
			function ( $url, $wc_order ) use ( $vault_redirect_url, &$received ) {
				$received = array( $url, $wc_order );
				return $vault_redirect_url;
			},
			10,
			2
		);

		$this->subscription_helper->shouldReceive( 'paypal_subscription_id' )->andReturn( '' );
		$this->payment_tokens_endpoint->shouldNotReceive( 'payment_tokens_for_customer' );
		$this->session_handler->allows( 'destroy_session_data' );

		$result = $this->create_free_trial_gateway()->process_payment( $order->get_id() );

		$this->assertSame(
			array(
				'result'   => 'success',
				'redirect' => $vault_redirect_url,
			),
			$result
		);
		$this->assertSame( '', $received[0], 'The filter should start from an empty URL' );
		$this->assertSame( $order->get_id(), $received[1]->get_id(), 'The filter should receive the pending order' );
		$this->assertFalse( $this->reload_order( $order->get_id() )->is_paid() );
	}

	/**
	 * @testdox Should still fail a first-time free-trial order when the vault-redirect filter supplies no URL.
	 */
	public function test_process_payment_fails_free_trial_when_vault_redirect_filter_returns_empty(): void {
		$user_id = $this->factory->user->create();
		$order   = $this->create_order( $user_id, 0.0 );

		$_POST = array( 'ppcp-funding-source' => 'paypal' );

		$this->subscription_helper->shouldReceive( 'paypal_subscription_id' )->andReturn( '' );
		$this->payment_tokens_endpoint->shouldNotReceive( 'payment_tokens_for_customer' );
		$this->session_handler->allows( 'destroy_session_data' );

		$result = $this->create_free_trial_gateway()->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( 'failed', $this->reload_order( $order->get_id() )->get_status() );
		$this->assertSame( array( 'No saved PayPal account.' ), $this->error_notices() );
	}
}
