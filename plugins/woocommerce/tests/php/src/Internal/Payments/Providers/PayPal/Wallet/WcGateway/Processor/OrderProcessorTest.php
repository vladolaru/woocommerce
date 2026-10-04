<?php
/**
 * Characterization tests for the PayPal wallet order processor (written in core; the extension has no equivalent).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\OrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Amount;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Authorization;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\AuthorizationStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Capture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\CaptureStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Item;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Money;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Payer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PayerName;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Payments;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PaymentSource;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PurchaseUnit;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\PayPalApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ExperienceContextBuilder;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\OrderFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PayerFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PurchaseUnitFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ShippingPreferenceFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\OrderHelper;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\SessionHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Exception\PayPalOrderMissingException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\Environment;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor\AuthorizedPaymentsProcessor;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor\OrderProcessor;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\SubscriptionHelper;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use ArrayObject;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Exception;
use Mockery;
use Mockery\MockInterface;
use Throwable;
use WC_Helper_Product;
use WC_Order;

/**
 * What the PayPal path of the order processor does today when it processes a WooCommerce order: the status the order ends
 * in, the meta it writes, the notes it adds, the hooks it fires and the calls it makes to PayPal, for an order that is
 * captured and for one that is only authorized.
 *
 * @group paypal-wallet
 */
class OrderProcessorTest extends WalletTestCase {

	private const PAYPAL_ORDER_ID = 'PP-ORDER-1';

	/**
	 * The session handler mock.
	 *
	 * @var SessionHandler&MockInterface
	 */
	private $session_handler;

	/**
	 * The order endpoint mock.
	 *
	 * @var OrderEndpoint&MockInterface
	 */
	private $order_endpoint;

	/**
	 * The order factory mock.
	 *
	 * @var OrderFactory&MockInterface
	 */
	private $order_factory;

	/**
	 * The authorized payments processor mock.
	 *
	 * @var AuthorizedPaymentsProcessor&MockInterface
	 */
	private $authorized_payments_processor;

	/**
	 * The settings provider mock.
	 *
	 * @var SettingsProvider&MockInterface
	 */
	private $settings_provider;

	/**
	 * The subscription helper mock.
	 *
	 * @var SubscriptionHelper&MockInterface
	 */
	private $subscription_helper;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockInterface
	 */
	private $logger;

	/**
	 * The processor under test.
	 *
	 * @var OrderProcessor
	 */
	private $sut;

	/**
	 * Build the processor over mocked PayPal collaborators. The environment is a sandbox, nothing is captured early.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->session_handler               = $this->mock( SessionHandler::class );
		$this->order_endpoint                = $this->mock( OrderEndpoint::class );
		$this->order_factory                 = $this->mock( OrderFactory::class );
		$this->authorized_payments_processor = $this->mock( AuthorizedPaymentsProcessor::class );
		$this->settings_provider             = $this->mock( SettingsProvider::class );
		$this->subscription_helper           = $this->mock( SubscriptionHelper::class );
		$this->logger                        = $this->mock( LoggerInterface::class )->shouldIgnoreMissing();

		$this->settings_provider->shouldReceive( 'capture_virtual_orders' )->andReturn( false )->byDefault();
		$this->subscription_helper->shouldReceive( 'has_subscription' )->andReturn( false )->byDefault();

		$this->sut = new OrderProcessor(
			$this->session_handler,
			$this->order_endpoint,
			$this->order_factory,
			$this->authorized_payments_processor,
			$this->settings_provider,
			$this->logger,
			new Environment( true ),
			$this->subscription_helper,
			new OrderHelper(),
			$this->mock( PurchaseUnitFactory::class ),
			$this->mock( PayerFactory::class ),
			$this->mock( ShippingPreferenceFactory::class ),
			$this->mock( ExperienceContextBuilder::class )
		);
	}

	/**
	 * Stop the test's request variables from leaking into later tests.
	 */
	public function tearDown(): void {
		unset( $_POST['paypal_order_id'], $_GET['wc-ajax'] );
		parent::tearDown();
	}

	/**
	 * @testdox Should capture an approved CAPTURE order, store the capture ID as the transaction ID, note it and complete the payment.
	 */
	public function test_capture_completes_the_order(): void {
		$wc_order = $this->create_wc_order();
		$this->expect_capture_flow( $wc_order, CaptureStatus::COMPLETED );

		$this->sut->process( $wc_order );

		$reloaded = wc_get_order( $wc_order->get_id() );
		$this->assertSame( 'processing', $reloaded->get_status(), 'A paid order with a physical product moves to processing' );
		$this->assertSame( 'CAP-1', $reloaded->get_transaction_id() );
		$this->assertTrue( $reloaded->is_paid() );
		$this->assert_order_has_note_containing( $wc_order->get_id(), 'PayPal transaction ID: CAP-1' );
	}

	/**
	 * @testdox Should write the PayPal order ID, intent, sandbox payment mode, payment source and payer email as meta for a captured order, and no captured flag.
	 */
	public function test_capture_writes_the_paypal_meta(): void {
		$wc_order = $this->create_wc_order();
		$this->expect_capture_flow( $wc_order, CaptureStatus::COMPLETED );

		$this->sut->process( $wc_order );

		$reloaded = wc_get_order( $wc_order->get_id() );
		$this->assertSame( self::PAYPAL_ORDER_ID, $reloaded->get_meta( PayPalGateway::ORDER_ID_META_KEY ) );
		$this->assertSame( 'CAPTURE', $reloaded->get_meta( PayPalGateway::INTENT_META_KEY ) );
		$this->assertSame( 'sandbox', $reloaded->get_meta( PayPalGateway::ORDER_PAYMENT_MODE_META_KEY ) );
		$this->assertSame( 'paypal', $reloaded->get_meta( PayPalGateway::ORDER_PAYMENT_SOURCE_META_KEY ) );
		$this->assertSame( 'payer@example.com', $reloaded->get_meta( PayPalGateway::ORDER_PAYER_EMAIL_META_KEY ) );
		$this->assertSame( '', $reloaded->get_meta( AuthorizedPaymentsProcessor::CAPTURED_META_KEY ), 'The captured flag belongs to authorized orders only' );
		$this->assertSame( '', $this->processing_lock( $wc_order->get_id() ), 'The processing lock should be released' );
	}

	/**
	 * @testdox Should fire the order created, order captured and after processor actions for a captured order.
	 */
	public function test_capture_fires_the_actions(): void {
		$wc_order = $this->create_wc_order();
		$captured = $this->expect_capture_flow( $wc_order, CaptureStatus::COMPLETED );
		$recorder = $this->record_actions(
			array(
				'woocommerce_paypal_payments_woocommerce_order_created',
				'woocommerce_paypal_payments_order_captured',
				'woocommerce_paypal_payments_after_order_processor',
				'woocommerce_paypal_payments_order_authorized',
			)
		);

		$this->sut->process( $wc_order );

		$fired = $recorder->getArrayCopy();

		$this->assertSame(
			array(
				'woocommerce_paypal_payments_woocommerce_order_created',
				'woocommerce_paypal_payments_order_captured',
				'woocommerce_paypal_payments_after_order_processor',
			),
			array_column( $fired, 'hook' ),
			'The actions fire in this order, and the authorized action does not fire'
		);
		$this->assertSame( $captured, $fired[2]['args'][1], 'The after processor action receives the captured PayPal order' );
		$this->assertSame( $wc_order->get_id(), $fired[2]['args'][0]->get_id() );
	}

	/**
	 * @testdox Should patch the PayPal order from the WooCommerce order first and capture the patched order, with the line item names filtered only on the way out.
	 */
	public function test_capture_patches_the_order_before_capturing(): void {
		$wc_order = $this->create_wc_order();
		$item     = array_values( $wc_order->get_items() )[0];
		$name     = $item->get_name();
		add_filter(
			'woocommerce_paypal_payments_order_line_item_name',
			static function ( $original ) {
				return 'Filtered ' . $original;
			}
		);

		$fetched = $this->create_paypal_order( 'CAPTURE', OrderStatus::APPROVED );
		$updated = $this->create_paypal_order( 'CAPTURE', OrderStatus::SAVED );
		$patched = $this->create_paypal_order( 'CAPTURE', OrderStatus::CREATED );

		$this->session_handler->shouldReceive( 'order' )->andReturn( $fetched );
		$this->order_endpoint->shouldReceive( 'order' )->with( self::PAYPAL_ORDER_ID )->andReturn( $fetched );

		$name_sent_to_paypal = null;
		$this->order_factory
			->expects( 'from_wc_order' )
			->once()
			->with( Mockery::on( fn ( $order ) => $order->get_id() === $wc_order->get_id() ), $this->same_as( $fetched ) )
			->andReturnUsing(
				function ( WC_Order $order ) use ( &$name_sent_to_paypal, $updated ): Order {
					$name_sent_to_paypal = array_values( $order->get_items() )[0]->get_name();
					return $updated;
				}
			);
		$this->order_endpoint->expects( 'patch_order_with' )->once()->with( $this->same_as( $fetched ), $this->same_as( $updated ) )->andReturn( $patched );
		$this->order_endpoint->expects( 'capture' )->once()->with( $this->same_as( $patched ) )->andReturn( $this->create_captured_order( CaptureStatus::COMPLETED ) );

		$this->sut->process( $wc_order );

		$this->assertSame( 'Filtered ' . $name, $name_sent_to_paypal, 'The filtered name is what PayPal gets' );
		$this->assertSame( $name, array_values( $wc_order->get_items() )[0]->get_name(), 'The WooCommerce item keeps its own name' );
	}

	/**
	 * @testdox Should put the order on hold with "Awaiting payment." and keep the capture ID when PayPal reports the capture as pending.
	 */
	public function test_pending_capture_puts_the_order_on_hold(): void {
		$wc_order = $this->create_wc_order();
		$this->expect_capture_flow( $wc_order, CaptureStatus::PENDING );

		$this->sut->process( $wc_order );

		$reloaded = wc_get_order( $wc_order->get_id() );
		$this->assertSame( 'on-hold', $reloaded->get_status() );
		$this->assertSame( 'CAP-1', $reloaded->get_transaction_id() );
		$this->assert_order_has_note_containing( $wc_order->get_id(), 'Awaiting payment.' );
	}

	/**
	 * @testdox Should fail the order, store no transaction ID, release the lock and throw the decline message when PayPal declines the capture.
	 */
	public function test_declined_capture_fails_the_order(): void {
		$wc_order = $this->create_wc_order();
		$this->expect_capture_flow( $wc_order, CaptureStatus::DECLINED );

		$error = $this->process_expecting_failure( $wc_order );

		$this->assertInstanceOf( RuntimeException::class, $error );
		$this->assertSame( 'Payment provider declined the payment, please use a different payment method.', $error->getMessage() );
		$reloaded = wc_get_order( $wc_order->get_id() );
		$this->assertSame( 'failed', $reloaded->get_status() );
		$this->assertSame( '', $reloaded->get_transaction_id(), 'A declined capture is not the order\'s transaction' );
		$this->assert_order_has_note_containing( $wc_order->get_id(), 'Could not capture the payment.' );
		$this->assertSame( '', $this->processing_lock( $wc_order->get_id() ), 'The lock is released on failure too' );
	}

	/**
	 * @testdox Should authorize an AUTHORIZE order, put it on hold with the authorization ID as the transaction ID and flag it as not captured.
	 */
	public function test_authorize_puts_the_order_on_hold(): void {
		$wc_order = $this->create_wc_order();
		$this->expect_authorize_flow( $wc_order );
		$this->authorized_payments_processor->shouldNotReceive( 'capture_authorized_payment' );

		$this->sut->process( $wc_order );

		$reloaded = wc_get_order( $wc_order->get_id() );
		$this->assertSame( 'on-hold', $reloaded->get_status() );
		$this->assertSame( 'AUTH-1', $reloaded->get_transaction_id() );
		$this->assertSame( 'AUTHORIZE', $reloaded->get_meta( PayPalGateway::INTENT_META_KEY ) );
		$this->assertSame( self::PAYPAL_ORDER_ID, $reloaded->get_meta( PayPalGateway::ORDER_ID_META_KEY ) );
		$this->assertSame( 'false', $reloaded->get_meta( AuthorizedPaymentsProcessor::CAPTURED_META_KEY ) );
		$this->assertSame( '', $reloaded->get_meta( '_ppcp_captured_vault_webhook' ), 'Only orders with a subscription get the vault webhook flag' );
		$this->assert_order_has_note_containing( $wc_order->get_id(), 'PayPal transaction ID: AUTH-1' );
		$this->assert_order_has_note_containing( $wc_order->get_id(), 'Awaiting payment.' );
	}

	/**
	 * @testdox Should fire the authorized action and not the captured action for an authorized order.
	 */
	public function test_authorize_fires_the_authorized_action(): void {
		$wc_order = $this->create_wc_order();
		$this->expect_authorize_flow( $wc_order );
		$recorder = $this->record_actions(
			array(
				'woocommerce_paypal_payments_order_captured',
				'woocommerce_paypal_payments_order_authorized',
				'woocommerce_paypal_payments_after_order_processor',
			)
		);

		$this->sut->process( $wc_order );

		$fired = $recorder->getArrayCopy();

		$this->assertSame(
			array( 'woocommerce_paypal_payments_order_authorized', 'woocommerce_paypal_payments_after_order_processor' ),
			array_column( $fired, 'hook' )
		);
		$this->assertInstanceOf( Authorization::class, $fired[0]['args'][1] );
		$this->assertSame( 'AUTH-1', $fired[0]['args'][1]->id() );
	}

	/**
	 * @testdox Should also flag the captured vault webhook as pending when the authorized order has a subscription.
	 */
	public function test_authorize_flags_the_vault_webhook_for_a_subscription(): void {
		$wc_order = $this->create_wc_order();
		$this->expect_authorize_flow( $wc_order );
		$this->subscription_helper->shouldReceive( 'has_subscription' )->with( $wc_order->get_id() )->andReturn( true );

		$this->sut->process( $wc_order );

		$this->assertSame( 'false', wc_get_order( $wc_order->get_id() )->get_meta( '_ppcp_captured_vault_webhook' ) );
	}

	/**
	 * @testdox Should capture the authorized payment right away only when the setting is on and no unit has physical goods ($name).
	 *
	 * @dataProvider data_early_capture
	 *
	 * @param string $name            Case name.
	 * @param string $category        The item category the PayPal order reports.
	 * @param bool   $capture_virtual The "capture virtual orders" setting.
	 * @param bool   $expect_capture  Whether the authorized payment is captured at once.
	 */
	public function test_authorize_captures_early_only_for_virtual_orders( string $name, string $category, bool $capture_virtual, bool $expect_capture ): void {
		unset( $name );
		$wc_order = $this->create_wc_order();
		$this->expect_authorize_flow( $wc_order, $category );
		$this->settings_provider->shouldReceive( 'capture_virtual_orders' )->andReturn( $capture_virtual );

		if ( $expect_capture ) {
			$this->authorized_payments_processor
				->expects( 'capture_authorized_payment' )
				->once()
				->with( Mockery::on( fn ( $order ) => $order instanceof WC_Order && $order->get_id() === $wc_order->get_id() ) );
		} else {
			$this->authorized_payments_processor->shouldNotReceive( 'capture_authorized_payment' );
		}

		$this->sut->process( $wc_order );
	}

	/**
	 * Cases for the early capture of authorized orders.
	 *
	 * @return array<string, array>
	 */
	public function data_early_capture(): array {
		return array(
			'digital goods, setting on'   => array( 'digital goods, setting on', Item::DIGITAL_GOODS, true, true ),
			'digital goods, setting off'  => array( 'digital goods, setting off', Item::DIGITAL_GOODS, false, false ),
			'physical goods, setting on'  => array( 'physical goods, setting on', Item::PHYSICAL_GOODS, true, false ),
			'physical goods, setting off' => array( 'physical goods, setting off', Item::PHYSICAL_GOODS, false, false ),
		);
	}

	/**
	 * @testdox Should never capture early for a CAPTURE order, even with the setting on and only digital goods.
	 */
	public function test_capture_order_is_never_captured_early(): void {
		$wc_order = $this->create_wc_order();
		$this->expect_capture_flow( $wc_order, CaptureStatus::COMPLETED, Item::DIGITAL_GOODS );
		$this->settings_provider->shouldReceive( 'capture_virtual_orders' )->andReturn( true );
		$this->authorized_payments_processor->shouldNotReceive( 'capture_authorized_payment' );

		$this->sut->process( $wc_order );
	}

	/**
	 * @testdox Should fetch the PayPal order by the ID stored on the WooCommerce order when the session holds none.
	 */
	public function test_fetches_the_paypal_order_by_the_stored_id(): void {
		$wc_order = $this->create_wc_order();
		$wc_order->update_meta_data( PayPalGateway::ORDER_ID_META_KEY, self::PAYPAL_ORDER_ID );
		$wc_order->save();
		$this->session_handler->shouldReceive( 'order' )->andReturn( null );
		$this->expect_capture_calls_after_session( $this->create_paypal_order( 'CAPTURE', OrderStatus::APPROVED ), CaptureStatus::COMPLETED, 2 );

		$this->sut->process( $wc_order );

		$this->assertSame( 'CAP-1', wc_get_order( $wc_order->get_id() )->get_transaction_id() );
	}

	/**
	 * @testdox Should fetch the PayPal order by the posted paypal_order_id when neither the session nor the order holds one.
	 */
	public function test_fetches_the_paypal_order_by_the_posted_id(): void {
		$wc_order                 = $this->create_wc_order();
		$_POST['paypal_order_id'] = self::PAYPAL_ORDER_ID;
		$this->session_handler->shouldReceive( 'order' )->andReturn( null );
		$this->expect_capture_calls_after_session( $this->create_paypal_order( 'CAPTURE', OrderStatus::APPROVED ), CaptureStatus::COMPLETED, 2 );

		$this->sut->process( $wc_order );

		$this->assertSame( 'CAP-1', wc_get_order( $wc_order->get_id() )->get_transaction_id() );
	}

	/**
	 * @testdox Should throw "Could not retrieve PayPal order." when the stored PayPal order cannot be fetched.
	 */
	public function test_throws_when_the_stored_order_cannot_be_fetched(): void {
		$wc_order = $this->create_wc_order();
		$wc_order->update_meta_data( PayPalGateway::ORDER_ID_META_KEY, self::PAYPAL_ORDER_ID );
		$wc_order->save();
		$this->session_handler->shouldReceive( 'order' )->andReturn( null );
		$this->order_endpoint->expects( 'order' )->with( self::PAYPAL_ORDER_ID )->andThrow( new RuntimeException( 'nope' ) );
		$this->order_endpoint->shouldNotReceive( 'capture' );

		$error = $this->process_expecting_failure( $wc_order );

		$this->assertSame( 'Could not retrieve PayPal order.', $error->getMessage() );
		$this->assertSame( '', $this->processing_lock( $wc_order->get_id() ) );
	}

	/**
	 * @testdox Should throw the missing order exception, call no PayPal endpoint and release the lock when no PayPal order ID is anywhere.
	 */
	public function test_throws_when_there_is_no_paypal_order_id(): void {
		$wc_order = $this->create_wc_order();
		$this->session_handler->shouldReceive( 'order' )->andReturn( null );
		$this->order_endpoint->shouldNotReceive( 'order' );
		$this->order_endpoint->shouldNotReceive( 'capture' );
		$this->logger->shouldNotReceive( 'warning' );

		$error = $this->process_expecting_failure( $wc_order );

		$this->assertInstanceOf( PayPalOrderMissingException::class, $error );
		$this->assertStringContainsString( 'There was an error processing your order.', $error->getMessage() );
		$this->assertSame( '', $this->processing_lock( $wc_order->get_id() ) );
	}

	/**
	 * @testdox Should log a warning naming the order when the PayPal return URL arrives without a PayPal order ID.
	 */
	public function test_warns_when_the_return_url_has_no_paypal_order_id(): void {
		$wc_order        = $this->create_wc_order();
		$_GET['wc-ajax'] = 'ppc-return-url';
		$this->session_handler->shouldReceive( 'order' )->andReturn( null );
		$this->logger->expects( 'warning' )->once()->with( sprintf( 'No PayPal order ID found for WooCommerce order #%d.', $wc_order->get_id() ) );

		$this->assertInstanceOf( PayPalOrderMissingException::class, $this->process_expecting_failure( $wc_order ) );
	}

	/**
	 * @testdox Should skip an order that already has a transaction ID without touching the session or PayPal.
	 */
	public function test_skips_an_order_with_a_transaction_id(): void {
		$wc_order = $this->create_wc_order();
		$wc_order->set_transaction_id( 'EARLIER-TX' );
		$wc_order->save();
		$this->session_handler->shouldNotReceive( 'order' );
		$this->order_endpoint->shouldNotReceive( 'order' );
		$this->order_endpoint->shouldNotReceive( 'capture' );

		$this->sut->process( $wc_order );

		$reloaded = wc_get_order( $wc_order->get_id() );
		$this->assertSame( 'pending', $reloaded->get_status() );
		$this->assertSame( 'EARLIER-TX', $reloaded->get_transaction_id() );
	}

	/**
	 * @testdox Should skip an order whose processing lock is held, and leave the lock to its holder.
	 */
	public function test_skips_an_order_that_is_being_processed(): void {
		$wc_order = $this->create_wc_order();
		$wc_order->update_meta_data( '_ppcp_processing', (string) ( time() + 300 ) );
		$wc_order->save();
		$this->session_handler->shouldNotReceive( 'order' );
		$this->order_endpoint->shouldNotReceive( 'capture' );
		$this->logger->expects( 'warning' )->once()->with( sprintf( 'Order #%d is already being processed (lock active), skipping payment processing.', $wc_order->get_id() ) );

		$this->sut->process( $wc_order );

		$this->assertNotSame( '', $this->processing_lock( $wc_order->get_id() ), 'The holder\'s lock stays' );
	}

	/**
	 * @testdox Should take over a lock that has expired and process the order.
	 */
	public function test_takes_over_an_expired_lock(): void {
		$wc_order = $this->create_wc_order();
		$wc_order->update_meta_data( '_ppcp_processing', (string) ( time() - 60 ) );
		$wc_order->save();
		$this->expect_capture_flow( $wc_order, CaptureStatus::COMPLETED );

		$this->sut->process( $wc_order );

		$reloaded = wc_get_order( $wc_order->get_id() );
		$this->assertSame( 'CAP-1', $reloaded->get_transaction_id() );
		$this->assertSame( '', $this->processing_lock( $wc_order->get_id() ), 'The lock is released after processing' );
	}

	/**
	 * @testdox Should log a warning and write nothing when the PayPal order is already completed.
	 */
	public function test_skips_a_completed_paypal_order(): void {
		$wc_order  = $this->create_wc_order();
		$completed = $this->create_paypal_order( 'CAPTURE', OrderStatus::COMPLETED );
		$this->session_handler->shouldReceive( 'order' )->andReturn( $completed );
		$this->order_endpoint->expects( 'order' )->with( self::PAYPAL_ORDER_ID )->andReturn( $completed );
		$this->order_endpoint->shouldNotReceive( 'capture' );
		$this->order_endpoint->shouldNotReceive( 'patch_order_with' );
		$this->logger->expects( 'warning' )->once()->with( 'Could not process PayPal completed order #' . self::PAYPAL_ORDER_ID . ', Status: COMPLETED' );

		$this->sut->process( $wc_order );

		$reloaded = wc_get_order( $wc_order->get_id() );
		$this->assertSame( 'pending', $reloaded->get_status() );
		$this->assertSame( '', $reloaded->get_meta( PayPalGateway::ORDER_ID_META_KEY ) );
		$this->assertSame( '', $this->processing_lock( $wc_order->get_id() ) );
	}

	/**
	 * @testdox Should throw "The payment is not ready for processing yet." and capture nothing when a PayPal wallet order with physical goods is not approved.
	 */
	public function test_throws_when_the_paypal_order_is_not_approved(): void {
		$wc_order = $this->create_wc_order();
		$waiting  = $this->create_paypal_order( 'CAPTURE', OrderStatus::PAYER_ACTION_REQUIRED );
		$this->session_handler->shouldReceive( 'order' )->andReturn( $waiting );
		$this->order_endpoint->expects( 'order' )->with( self::PAYPAL_ORDER_ID )->andReturn( $waiting );
		$this->order_endpoint->shouldNotReceive( 'patch_order_with' );
		$this->order_endpoint->shouldNotReceive( 'capture' );

		$error = $this->process_expecting_failure( $wc_order );

		$this->assertSame( 'The payment is not ready for processing yet.', $error->getMessage() );
		$reloaded = wc_get_order( $wc_order->get_id() );
		$this->assertSame( 'pending', $reloaded->get_status() );
		$this->assertSame( '', $this->processing_lock( $wc_order->get_id() ) );
	}

	/**
	 * @testdox Should let a PayPal error from the order patch through, capture nothing and release the lock.
	 */
	public function test_patch_failure_stops_the_processing(): void {
		$wc_order = $this->create_wc_order();
		$fetched  = $this->create_paypal_order( 'CAPTURE', OrderStatus::APPROVED );
		$this->session_handler->shouldReceive( 'order' )->andReturn( $fetched );
		$this->order_endpoint->shouldReceive( 'order' )->with( self::PAYPAL_ORDER_ID )->andReturn( $fetched );
		$this->order_factory->shouldReceive( 'from_wc_order' )->andReturn( $this->create_paypal_order( 'CAPTURE', OrderStatus::APPROVED ) );
		$this->order_endpoint->expects( 'patch_order_with' )->andThrow( new PayPalApiException( null, 500 ) );
		$this->order_endpoint->shouldNotReceive( 'capture' );

		$error = $this->process_expecting_failure( $wc_order );

		$this->assertInstanceOf( PayPalApiException::class, $error );
		$this->assertSame( 500, $error->status_code() );
		$this->assertSame( '', $this->processing_lock( $wc_order->get_id() ) );
	}

	/**
	 * @testdox Should store the meta, transaction ID and status for an order PayPal already captured, without calling PayPal.
	 */
	public function test_process_captured_and_authorized_for_a_captured_order(): void {
		$wc_order = $this->create_wc_order();
		$captured = $this->create_captured_order( CaptureStatus::COMPLETED );
		$this->order_endpoint->shouldNotReceive( 'capture' );
		$this->order_endpoint->shouldNotReceive( 'authorize' );
		$this->order_endpoint->shouldNotReceive( 'patch_order_with' );

		$this->sut->process_captured_and_authorized( $wc_order, $captured );

		$reloaded = wc_get_order( $wc_order->get_id() );
		$this->assertSame( 'processing', $reloaded->get_status() );
		$this->assertSame( 'CAP-1', $reloaded->get_transaction_id() );
		$this->assertSame( 'CAPTURE', $reloaded->get_meta( PayPalGateway::INTENT_META_KEY ) );
		$this->assertSame( 'sandbox', $reloaded->get_meta( PayPalGateway::ORDER_PAYMENT_MODE_META_KEY ) );
	}

	/**
	 * @testdox Should flag an already authorized order as not captured and put it on hold.
	 */
	public function test_process_captured_and_authorized_for_an_authorized_order(): void {
		$wc_order   = $this->create_wc_order();
		$authorized = $this->create_authorized_order( Item::PHYSICAL_GOODS );

		$this->sut->process_captured_and_authorized( $wc_order, $authorized );

		$reloaded = wc_get_order( $wc_order->get_id() );
		$this->assertSame( 'on-hold', $reloaded->get_status() );
		$this->assertSame( 'AUTH-1', $reloaded->get_transaction_id() );
		$this->assertSame( 'AUTHORIZE', $reloaded->get_meta( PayPalGateway::INTENT_META_KEY ) );
		$this->assertSame( 'false', $reloaded->get_meta( AuthorizedPaymentsProcessor::CAPTURED_META_KEY ) );
	}

	/**
	 * A Mockery argument matcher for exactly this object, not one that merely holds the same data.
	 *
	 * @param object $expected The object.
	 * @return \Mockery\Matcher\Closure
	 */
	private function same_as( $expected ) {
		return Mockery::on(
			static function ( $actual ) use ( $expected ): bool {
				return $actual === $expected;
			}
		);
	}

	/**
	 * The value of the order's processing lock as stored, or an empty string when there is none.
	 *
	 * Read from the table the processor writes to rather than through the order, whose meta cache the processor's own
	 * SQL does not refresh.
	 *
	 * @param int $order_id The order ID.
	 * @return string
	 */
	private function processing_lock( int $order_id ): string {
		global $wpdb;

		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$table     = $wpdb->prefix . 'wc_orders_meta';
			$id_column = 'order_id';
		} else {
			$table     = $wpdb->postmeta;
			$id_column = 'post_id';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$table} WHERE {$id_column} = %d AND meta_key = '_ppcp_processing'", $order_id ) );
	}

	/**
	 * Process the order and hand back what it throws.
	 *
	 * @param WC_Order $wc_order The order.
	 * @return Throwable
	 */
	private function process_expecting_failure( WC_Order $wc_order ): Throwable {
		try {
			$this->sut->process( $wc_order );
		} catch ( Exception $error ) {
			return $error;
		}

		$this->fail( 'Processing should have thrown' );
	}

	/**
	 * Expect the whole CAPTURE flow for an approved order held in the session: fetch, patch, capture.
	 *
	 * @param WC_Order $wc_order       The WooCommerce order.
	 * @param string   $capture_status The status of the capture PayPal returns.
	 * @param string   $category       The category of the item the PayPal order carries.
	 * @return Order The captured PayPal order the capture call returns.
	 */
	private function expect_capture_flow( WC_Order $wc_order, string $capture_status, string $category = Item::PHYSICAL_GOODS ): Order {
		unset( $wc_order );
		$fetched = $this->create_paypal_order( 'CAPTURE', OrderStatus::APPROVED, $category );
		$this->session_handler->shouldReceive( 'order' )->andReturn( $fetched );

		return $this->expect_capture_calls_after_session( $fetched, $capture_status, 1, $category );
	}

	/**
	 * Expect the PayPal calls of a CAPTURE flow: the (re)fetch of the order, the patch and the capture.
	 *
	 * @param Order  $fetched        The approved PayPal order the endpoint returns.
	 * @param string $capture_status The status of the capture PayPal returns.
	 * @param int    $fetches        How many times the order is fetched.
	 * @param string $category       The category of the item the PayPal order carries.
	 * @return Order The captured PayPal order the capture call returns.
	 */
	private function expect_capture_calls_after_session( Order $fetched, string $capture_status, int $fetches, string $category = Item::PHYSICAL_GOODS ): Order {
		$captured = $this->create_captured_order( $capture_status, $category );

		$this->order_endpoint->expects( 'order' )->times( $fetches )->with( self::PAYPAL_ORDER_ID )->andReturn( $fetched );
		$this->order_factory->shouldReceive( 'from_wc_order' )->andReturn( $fetched );
		$this->order_endpoint->expects( 'patch_order_with' )->once()->andReturn( $fetched );
		$this->order_endpoint->expects( 'capture' )->once()->with( Mockery::type( Order::class ) )->andReturn( $captured );

		return $captured;
	}

	/**
	 * Expect the whole AUTHORIZE flow for an approved order held in the session: fetch, patch, authorize and, when
	 * the early capture asks, a second fetch.
	 *
	 * @param WC_Order $wc_order The WooCommerce order.
	 * @param string   $category The category of the item the PayPal order carries.
	 */
	private function expect_authorize_flow( WC_Order $wc_order, string $category = Item::PHYSICAL_GOODS ): void {
		unset( $wc_order );
		$fetched    = $this->create_paypal_order( 'AUTHORIZE', OrderStatus::APPROVED, $category );
		$authorized = $this->create_authorized_order( $category );

		$this->session_handler->shouldReceive( 'order' )->andReturn( $fetched );
		$this->order_endpoint->shouldReceive( 'order' )->with( self::PAYPAL_ORDER_ID )->andReturn( $fetched );
		$this->order_factory->shouldReceive( 'from_wc_order' )->andReturn( $fetched );
		$this->order_endpoint->expects( 'patch_order_with' )->once()->andReturn( $fetched );
		$this->order_endpoint->expects( 'authorize' )->once()->with( Mockery::type( Order::class ) )->andReturn( $authorized );
	}

	/**
	 * Record the given actions, with the arguments each fired with, in the order they fire.
	 *
	 * @param string[] $hooks The action names.
	 * @return ArrayObject A list that fills with array( 'hook' => string, 'args' => array ) entries.
	 */
	private function record_actions( array $hooks ): ArrayObject {
		$fired = new ArrayObject();
		foreach ( $hooks as $hook ) {
			add_action(
				$hook,
				static function ( ...$args ) use ( $hook, $fired ) {
					$fired->append(
						array(
							'hook' => $hook,
							'args' => $args,
						)
					);
				},
				10,
				5
			);
		}

		return $fired;
	}

	/**
	 * A saved pending order with one physical product, paid with the PayPal gateway.
	 *
	 * @return WC_Order
	 */
	private function create_wc_order(): WC_Order {
		$wc_order = wc_create_order();
		$wc_order->add_product( WC_Helper_Product::create_simple_product(), 1 );
		$wc_order->set_payment_method( PayPalGateway::ID );
		$wc_order->set_currency( 'USD' );
		$wc_order->calculate_totals();
		$wc_order->set_status( 'pending' );
		$wc_order->save();

		return $wc_order;
	}

	/**
	 * A PayPal order paid with a PayPal account, whose one purchase unit has one item and no payments yet.
	 *
	 * @param string        $intent   CAPTURE or AUTHORIZE.
	 * @param string        $status   An OrderStatus value.
	 * @param string        $category The item category.
	 * @param Payments|null $payments The payments the purchase unit holds.
	 * @return Order
	 */
	private function create_paypal_order( string $intent, string $status, string $category = Item::PHYSICAL_GOODS, ?Payments $payments = null ): Order {
		$item = new Item( 'Dummy product', new Money( 10.0, 'USD' ), 1, '', null, '', $category );

		return new Order(
			self::PAYPAL_ORDER_ID,
			array(
				new PurchaseUnit(
					new Amount( new Money( 10.0, 'USD' ) ),
					array( $item ),
					null,
					'default',
					'',
					'',
					'',
					'',
					$payments
				),
			),
			new OrderStatus( $status ),
			new PaymentSource( 'paypal', (object) array( 'email_address' => 'payer@example.com' ) ),
			new Payer( new PayerName( 'Jo', 'Doe' ), 'payer@example.com', 'PAYER-1' ),
			$intent
		);
	}

	/**
	 * The PayPal order the capture call returns: one capture with the given status.
	 *
	 * @param string $capture_status The capture status.
	 * @param string $category       The item category.
	 * @return Order
	 */
	private function create_captured_order( string $capture_status, string $category = Item::PHYSICAL_GOODS ): Order {
		$capture = new Capture(
			'CAP-1',
			new CaptureStatus( $capture_status ),
			new Amount( new Money( 10.0, 'USD' ) ),
			true,
			'',
			'',
			'',
			null,
			null
		);

		return $this->create_paypal_order( 'CAPTURE', OrderStatus::COMPLETED, $category, new Payments( array(), array( $capture ) ) );
	}

	/**
	 * The PayPal order the authorize call returns: one authorization in the created status.
	 *
	 * @param string $category The item category.
	 * @return Order
	 */
	private function create_authorized_order( string $category ): Order {
		$authorization = new Authorization( 'AUTH-1', new AuthorizationStatus( AuthorizationStatus::CREATED ), null );

		return $this->create_paypal_order( 'AUTHORIZE', OrderStatus::COMPLETED, $category, new Payments( array( $authorization ), array() ) );
	}

	/**
	 * Assert that one of the order's notes contains the text.
	 *
	 * @param int    $order_id The order ID.
	 * @param string $text     The expected text.
	 */
	private function assert_order_has_note_containing( int $order_id, string $text ): void {
		$matching = array_filter(
			wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order_id ) ), 'content' ),
			static function ( string $note ) use ( $text ): bool {
				return false !== strpos( $note, $text );
			}
		);

		$this->assertNotEmpty( $matching, "No order note contains '$text'" );
	}
}
