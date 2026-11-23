<?php
/**
 * Registered WooPayments charge refund behavior.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Abilities\Domain;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain\RefundCharge;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\HttpClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\RefundEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentOperationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Abilities\AbilityTestCase;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeHttpClient;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\RecordingWcLogger;
use Automattic\WooCommerce\Testing\Tools\TestingContainer;
use WC_Order;

/** Test the ability through the real charge refund client with an isolated platform transport. */
class RefundChargeTest extends AbilityTestCase {
	private const INPUT  = array(
		'charge_id'       => 'ch_ability',
		'idempotency_key' => 'K',
	);
	private const DIGEST = 'wc-refund-86be9a55762d316a3026c2836d044f5fc76e34da10e1b45feee5f18be7edb177';

	/** @var FakeHttpClient Platform transport. */
	private $http;

	/** @var array Original gateways. */
	private $gateways;

	/** @var \WP_Ability Registered refund ability. */
	private $sut;

	/** Install the provider and use the real client with a fake transport. */
	public function setUp(): void {
		parent::setUp();
		$this->http = new FakeHttpClient();
		$container  = wc_get_container();
		$this->assertInstanceOf( TestingContainer::class, $container );
		$container->replace( HttpClient::class, $this->http );
		$this->reset_container_resolutions();
		$this->gateways = WC()->payment_gateways()->payment_gateways;
		$this->install_root();
		add_filter( self::GATE, '__return_true' );
		$ability = wp_get_ability( self::PREFIX . 'refund-charge' );
		$this->assertInstanceOf( \WP_Ability::class, $ability );
		$this->sut = $ability;
	}

	/** Restore gateways and transport. */
	public function tearDown(): void {
		try {
			WC()->payment_gateways()->payment_gateways = $this->gateways;
			$this->reset_container_replacements();
			$this->reset_container_resolutions();
		} finally {
			parent::tearDown();
		}
	}

	/** Create the order that holds the ability's charge. */
	private function create_holder(): WC_Order {
		WC()->payment_gateways()->payment_gateways[] = new WooPaymentsGateway();
		$order                                       = new WC_Order();
		$order->set_currency( 'USD' );
		$order->set_total( '10.00' );
		$order->set_payment_method( 'woocommerce_payments' );
		$order->update_meta_data( '_charge_id', 'ch_ability' );
		$order->save();
		return $order;
	}

	/**
	 * Supply the transport's next JSON response.
	 *
	 * @param array $body Platform response.
	 * @param int   $status HTTP status.
	 */
	private function respond( array $body, int $status = 200 ): void {
		$this->http->response = array(
			'response' => array( 'code' => $status ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $body ),
		);
	}

	/**
	 * @testdox A shop manager's charge refund holds its order lock, sends the caller's digest, and records one refund across retries and webhook delivery.
	 * @testWith [500]
	 *           [1000]
	 *
	 * @param int $amount Requested partial or full amount in cents.
	 */
	public function test_registered_ability_refunds_under_the_charge_holders_lock( int $amount ): void {
		$this->login_as_role( 'shop_manager' );
		$order      = $this->create_holder();
		$lock       = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$held       = array();
		add_filter(
			'wcpay_api_request_response',
			static function ( $response ) use ( &$held, $order, $lock, $vocabulary ) {
				$held[] = $lock->is_held( $order, $vocabulary );
				return $response;
			}
		);

		$refund = array(
			'id'       => 're_ability',
			'status'   => 'succeeded',
			'amount'   => $amount,
			'currency' => 'usd',
			'charge'   => 'ch_ability',
		);
		$charge = array(
			'id'       => 'ch_ability',
			'status'   => 'succeeded',
			'captured' => true,
			'amount'   => 1000,
			'currency' => 'usd',
			'refunds'  => array( 'data' => array( $refund ) ),
			'metadata' => array(
				'order_key' => $order->get_order_key(),
				'order_id'  => $order->get_id(),
			),
		);
		$answer = $refund + array( 'payment_intent' => array( 'charges' => array( 'data' => array( $charge ) ) ) );
		$this->respond( $answer );
		$input = self::INPUT + array(
			'amount' => $amount,
			'reason' => 'requested_by_customer',
		);

		$this->assertSame( $answer, $this->sut->execute( $input ) );
		$this->assertSame( array( true ), $held );
		$this->assertFalse( $lock->is_held( $order, $vocabulary ) );
		$this->assertSame( 'POST', $this->http->last_method );
		$this->assertSame( '/sites/123/wcpay/refunds', $this->http->last_path );
		$this->assertSame( self::DIGEST, $this->http->last_headers['Idempotency-Key'] );
		$this->assertIsString( $this->http->last_body );
		$body = json_decode( $this->http->last_body, true );
		$this->assertSame( 'ch_ability', $body['charge'] );
		$this->assertSame( $amount, $body['amount'] );
		$this->assertSame( 'requested_by_customer', $body['reason'] );
		$this->assertSame( 'woopayments_ability', $body['metadata']['refund_source'] );
		$this->assertSame( 'requested_by_customer', $body['metadata']['merchant_refund_reason'] );
		$this->assertSame( self::DIGEST, $body['metadata']['refund_attempt'] );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertCount( 1, $order->get_refunds() );
		$this->assertSame( 're_ability', $order->get_refunds()[0]->get_meta( '_wcpay_refund_id' ) );
		$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'id' );
		$retry = $this->sut->execute( $input );
		if ( 1000 === $amount ) {
			$this->assertSame( 'refunded', $order->get_status() );
			$this->assertWPError( $retry );
			$this->assertSame( 'wcpay_refund_failed', $retry->get_error_code() );
			$this->assertSame( 'The refund amount is not valid.', $retry->get_error_message() );
			$this->assertSame( 1, $this->http->request_count );
		} else {
			$this->assertSame( $answer, $retry );
			$this->assertSame( 2, $this->http->request_count );
			$this->assertSame( self::DIGEST, $this->http->last_headers['Idempotency-Key'] );
		}
		wc_get_container()->get( RefundEventHandler::class )->process( 'charge.refunded', $charge );
		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $amount / 100.0, (float) $order->get_total_refunded() );
		$this->assertCount( 1, $order->get_refunds() );
		$this->assertSame( $notes, wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'id' ) );
	}

	/**
	 * @testdox Omitting the amount refunds an unowned charge, including when the stable key is zero or blank space.
	 * @testWith ["0","wc-refund-5feceb66ffc86f38d952786c6d696c79c2dbc239dd4e91b46729d73a27fb57e9"]
	 *           ["  ","wc-refund-6c179f21e6f62b629055d8ab40f454ed02e48b68563913473b857d3638e23b28"]
	 *
	 * @param string $key    Raw caller key.
	 * @param string $digest Expected wire key.
	 */
	public function test_full_refund_without_an_order( string $key, string $digest ): void {
		$this->respond( array( 'id' => 're_unowned' ) );
		$orders = array(
			'type'   => array( 'shop_order', 'shop_order_refund' ),
			'status' => 'any',
			'limit'  => -1,
			'return' => 'ids',
		);
		$before = wc_get_orders( $orders );
		$result = $this->sut->execute(
			array(
				'charge_id'       => 'py_unowned',
				'idempotency_key' => $key,
			)
		);
		$this->assertSame( array( 'id' => 're_unowned' ), $result );
		$this->assertIsString( $this->http->last_body );
		$body = json_decode( $this->http->last_body, true );
		$this->assertSame( 'py_unowned', $body['charge'] );
		$this->assertArrayHasKey( 'amount', $body );
		$this->assertNull( $body['amount'] );
		$this->assertSame( $digest, $this->http->last_headers['Idempotency-Key'] );
		$this->assertSame( $digest, $body['metadata']['refund_attempt'] );
		$this->assertSame( 'woopayments_ability', $body['metadata']['refund_source'] );
		$this->assertSame( $before, wc_get_orders( $orders ) );
	}

	/**
	 * @testdox The public schema, the callback's empty-key check, and the refund request's identifier check refuse invalid input before transport.
	 * @testWith [{"charge_id":"ch_ability"},"ability_invalid_input"]
	 *           [{"charge_id":"ch_ability","idempotency_key":42},"ability_invalid_input"]
	 *           [{"charge_id":"ch_ability","idempotency_key":""},"wcpay_missing_idempotency_key"]
	 *           [{"charge_id":"ch_ability","idempotency_key":"K","amount":0},"ability_invalid_input"]
	 *           [{"charge_id":"ch_ability","idempotency_key":"K","reason":"other"},"ability_invalid_input"]
	 *           [{"charge_id":"ch_","idempotency_key":"K"},"wcpay_refund_failed"]
	 *
	 * @param array  $input Input for the registered ability.
	 * @param string $code  Expected error.
	 */
	public function test_invalid_input( array $input, string $code ): void {
		$result = $this->sut->execute( $input );
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( 0, $this->http->request_count );
	}

	/**
	 * @testdox The callback reports missing charge before missing key when used directly.
	 * @testWith [{},"wcpay_missing_charge_id"]
	 *           [{"charge_id":123},"wcpay_missing_charge_id"]
	 *           [{"charge_id":"ch_ability"},"wcpay_missing_idempotency_key"]
	 *           [{"charge_id":"ch_ability","idempotency_key":9},"wcpay_missing_idempotency_key"]
	 *
	 * @param array  $input Input passed directly to the callback.
	 * @param string $code  Expected error.
	 */
	public function test_callback_refusals( array $input, string $code ): void {
		$result = RefundCharge::execute( $input );
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( 0, $this->http->request_count );
	}

	/**
	 * @testdox Refund request validation preserves the exception's code and message for the caller and writes only a safe identifier to the ability log.
	 * @testWith ["0",500,"wcpay_core_invalid_request_parameter_stripe_id","Empty parameter is not allowed","another value"]
	 *           ["ch_person@example.test",500,"wcpay_core_invalid_request_parameter_stripe_id","ch_person@example.test is not a valid Stripe identifier","another value"]
	 *           ["ch_ability",-1,"wcpay_core_invalid_request_parameter_order","Invalid number passed. Number -1 needs to be larger than 0","ch_ability"]
	 *
	 * @param string $charge_id Charge ID passed directly to the callback.
	 * @param int    $amount    Amount passed directly to the callback.
	 * @param string $code      Exception code.
	 * @param string $message   Exception message.
	 * @param string $logged_id Identifier safe to log.
	 */
	public function test_validation_exception_details( string $charge_id, int $amount, string $code, string $message, string $logged_id ): void {
		$logger = RecordingWcLogger::install();
		$result = RefundCharge::execute(
			array(
				'charge_id'       => $charge_id,
				'amount'          => $amount,
				'idempotency_key' => 'private-caller-key',
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'wcpay_refund_failed', $result->get_error_code() );
		$this->assertSame( $message, $result->get_error_message() );
		$exception = $result->get_error_data()['exception'] ?? null;
		$this->assertInstanceOf( ApiException::class, $exception );
		$this->assertSame( $code, $exception->get_error_code() );
		$this->assertSame( $message, $exception->getMessage() );
		$this->assert_ability_log( $logger, $logged_id );
		$this->assertSame( 0, $this->http->request_count );
	}

	/** A malformed, oversized charge ID must not be copied into the ability log. */
	public function test_oversized_charge_id_is_omitted_from_log(): void {
		$logger = RecordingWcLogger::install();
		$result = $this->sut->execute(
			array(
				'charge_id'       => 'ch_' . str_repeat( 'a', 251 ),
				'idempotency_key' => 'refund',
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'wcpay_refund_failed', $result->get_error_code() );
		$this->assert_ability_log( $logger, 'another value' );
	}

	/**
	 * Check every ability log entry, including the context, for untrusted inputs.
	 *
	 * @param RecordingWcLogger $logger    Test logger.
	 * @param string            $logged_id Expected safe identifier.
	 */
	private function assert_ability_log( RecordingWcLogger $logger, string $logged_id ): void {
		$entries = array();
		foreach ( $logger->lines as $index => $line ) {
			if ( 'woopayments-abilities' === $line[2] ) {
				$entries[] = array( $line, $logger->contexts[ $index ] );
			}
		}
		$this->assertSame(
			array(
				array(
					array( 'error', 'Refund failed for charge ' . $logged_id . '.', 'woopayments-abilities' ),
					array( 'source' => 'woopayments-abilities' ),
				),
			),
			$entries
		);
	}

	/**
	 * @testdox A customer, guest, or manager whose store withdrew opt-in cannot refund.
	 * @testWith ["customer"]
	 *           ["guest"]
	 *           ["opt-out"]
	 *
	 * @param string $caller Caller or store state.
	 */
	public function test_refused_permission( string $caller ): void {
		if ( 'opt-out' === $caller ) {
			add_filter( self::GATE, '__return_false', 20 );
		} elseif ( 'guest' === $caller ) {
			wp_set_current_user( 0 );
		} else {
			$this->login_as_role( $caller );
		}

		$result = $this->sut->execute( self::INPUT );
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( 0, $this->http->request_count );
	}

	/**
	 * @testdox A retained ability cannot refund after the current store leaves the connected tier.
	 */
	public function test_execution_rechecks_current_site_tier(): void {
		$this->assertTrue( $this->sut->check_permissions( self::INPUT ) );
		$this->assertTrue( wc_get_container()->get( WooPaymentsSetupTier::class )->write_tier( WooPaymentsSetupTier::AVAILABLE ) );

		$result = $this->sut->execute( self::INPUT );
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( 0, $this->http->request_count );
	}

	/**
	 * @testdox A previously discovered ability cannot refund on an unconnected subsite even when the merchant can manage that site.
	 * @group multisite
	 */
	public function test_execution_rechecks_subsite_tier(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires a second site.' );
		}
		$blog_id = self::factory()->blog->create();
		$this->assertIsInt( $blog_id );
		add_user_to_blog( $blog_id, get_current_user_id(), 'administrator' );
		switch_to_blog( $blog_id );
		try {
			update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
			update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::AVAILABLE );
			wp_get_current_user()->add_cap( 'manage_woocommerce' );
			$this->assertTrue( current_user_can( 'manage_woocommerce' ) );
			$result = $this->sut->execute( self::INPUT );
			$this->assertWPError( $result );
			$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
			$this->assertSame( 0, $this->http->request_count );
		} finally {
			restore_current_blog();
			wpmu_delete_blog( $blog_id, true );
		}
	}

	/**
	 * @testdox The ability refuses a held refund attempt or an omitted amount on an owned charge, preserves the record, and reports the reason.
	 * @testWith [true,"This order's last refund attempt is not settled yet. Refund it from the order page, which settles it first."]
	 *           [false,"Give the amount to refund: this charge belongs to an order."]
	 *
	 * @param bool   $held Whether an earlier attempt is unsettled.
	 * @param string $message Shared refund refusal.
	 */
	public function test_order_refusal( bool $held, string $message ): void {
		$order  = $this->create_holder();
		$logger = RecordingWcLogger::install();
		$input  = self::INPUT;
		$record = '';
		if ( $held ) {
			$record = array(
				'order_id'  => $order->get_id(),
				'charge_id' => 'ch_ability',
				'key'       => 'merchant-attempt',
				'request'   => array( 'charge' => 'ch_ability' ),
				'sent'      => true,
				'failed_at' => time(),
			);
			$order->update_meta_data( WooPaymentsPaymentOperationService::REFUND_AMBIGUITY_META, $record );
			$order->save_meta_data();
			$input['amount'] = 500;
		}

		$result = $this->sut->execute( $input );
		$this->assertWPError( $result );
		$this->assertSame( 'wcpay_refund_failed', $result->get_error_code() );
		$this->assertSame( $message, $result->get_error_message() );
		$this->assert_ability_log( $logger, 'ch_ability' );
		$this->assertSame( 0, $this->http->request_count );
		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( $record, $order->get_meta( WooPaymentsPaymentOperationService::REFUND_AMBIGUITY_META ) );
		$this->assertCount( 0, $order->get_refunds() );
	}

	/**
	 * @testdox A platform rejection or transport throwable is logged and returned to the ability caller as a refund failure.
	 * @testWith [false,"Error: Different parameters used for this key."]
	 *           [true,"Transport interrupted."]
	 *
	 * @param bool   $throws  Whether the transport throws.
	 * @param string $message Expected error text.
	 */
	public function test_refund_failure( bool $throws, string $message ): void {
		$logger = RecordingWcLogger::install();
		$this->respond( array( 'id' => 're_original' ) );
		$this->assertSame( array( 'id' => 're_original' ), $this->sut->execute( self::INPUT + array( 'amount' => 500 ) ) );
		$this->respond(
			array(
				'code'    => 'wcpay_bad_request',
				'message' => 'Different parameters used for this key.',
				'data'    => array( 'status' => 400 ),
			),
			400
		);
		$interruption = new \Error( 'Transport interrupted.' );
		add_filter(
			'wcpay_api_request_response',
			static function ( $response ) use ( $throws, $interruption ) {
				if ( $throws ) {
					throw $interruption;
				}
				return $response;
			}
		);

		$result = $this->sut->execute( self::INPUT + array( 'amount' => 501 ) );
		$this->assertWPError( $result );
		$this->assertSame( 'wcpay_refund_failed', $result->get_error_code() );
		$this->assertSame( $message, $result->get_error_message() );
		$exception = $result->get_error_data()['exception'] ?? null;
		if ( $throws ) {
			$this->assertSame( $interruption, $exception );
		} else {
			$this->assertInstanceOf( ApiException::class, $exception );
			$this->assertSame( 'wcpay_bad_request', $exception->get_error_code() );
			$this->assertSame( 400, $exception->get_http_code() );
			$this->assertSame( $message, $exception->getMessage() );
		}
		$this->assertSame( 2, $this->http->request_count );
		$this->assertSame( self::DIGEST, $this->http->last_headers['Idempotency-Key'] );
		$this->assert_ability_log( $logger, 'ch_ability' );
	}
}
