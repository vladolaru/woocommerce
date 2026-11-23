<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\HttpClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\DisputeEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\PaymentIntentEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderCardInfo;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Tests\Internal\Payments\PlatformRequestClaimGuard;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeHttpClient;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use Automattic\WooCommerce\Testing\Tools\TestingContainer;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Exercise the order claim permissions through provider code and the real API client.
 */
class PlatformRequestClaimTest extends WC_Unit_Test_Case {
	use WithOrderPaymentClaim;

	/** @var FakeHttpClient Platform transport. */
	private FakeHttpClient $http;

	/**
	 * Start with an active card gateway and a cold account cache.
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::ACTIVE );
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enabled'   => 'yes',
				'test_mode' => 'yes',
			)
		);
		update_option( 'wcpay_onboarding_test_mode', 'yes' );
		delete_option( 'wcpay_account_data' );
		$this->http = new FakeHttpClient();
		$container  = wc_get_container();
		$this->assertInstanceOf( TestingContainer::class, $container );
		$container->replace( HttpClient::class, $this->http );
		$this->reset_container_resolutions();
	}

	/**
	 * Release the request's transport and services.
	 */
	public function tearDown(): void {
		$this->reset_container_replacements();
		$this->reset_container_resolutions();
		parent::tearDown();
	}

	/**
	 * Checkout reads the account during availability. Preparing Level 3 data uses the same account.
	 * The only sends during the first charge are creating its customer and the charge itself.
	 */
	public function test_checkout_uses_the_account_already_read_for_availability(): void {
		$order                 = $this->order();
		$this->http->responses = array(
			$this->account_response(),
			$this->response( array( 'id' => 'cus_first' ) ),
			$this->response(
				array(
					'id'            => 'pi_first',
					'status'        => 'requires_action',
					'client_secret' => 'secret',
				)
			),
		);
		$gateway               = new WooPaymentsGateway();
		$this->assertTrue( $gateway->is_available() );
		$this->assertSame( array( 'accounts' ), $this->routes() );
		$_POST['wcpay-payment-method'] = 'pm_card';
		try {
			$gateway->process_payment( $order->get_id() );
		} finally {
			unset( $_POST['wcpay-payment-method'] );
		}
		$this->assertSame( array( 'accounts', 'customers', 'intentions' ), $this->routes() );
		$body = json_decode( (string) $this->http->last_body, true );
		$this->assertSame( (string) $order->get_id(), $body['level3']['merchant_reference'] );
	}

	/**
	 * The setup retry creates a replacement customer before sending the second SetupIntent.
	 */
	public function test_setup_replaces_a_missing_customer_under_the_checkout_claim(): void {
		$order = $this->order();
		$order->set_total( '0.00' );
		$order->save();
		update_user_option( $order->get_customer_id(), WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, 'cus_missing' );
		$this->http->responses = array(
			$this->account_response(),
			$this->response(
				array(
					'error' => array(
						'code'    => 'resource_missing',
						'message' => "No such customer: 'cus_missing'",
					),
				),
				404
			),
			$this->response( array( 'id' => 'cus_replaced' ) ),
			$this->response(
				array(
					'id'     => 'seti_retry',
					'status' => 'requires_action',
				)
			),
		);
		wc_get_container()->get( WooPaymentsAccountService::class )->get_cached_account_data();
		wc_get_container()->get( PaymentProcessingService::class )->process_checkout_outcome(
			PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'ctoken_setup' ),
			wc_get_container()->get( WooPaymentsProvider::class )
		);
		$this->assertSame( array( 'accounts', 'setup_intents', 'customers', 'setup_intents' ), $this->routes() );
	}

	/**
	 * Charge expiry settles the account, then reads the expired intent under the event's claim.
	 */
	public function test_expired_charge_reads_the_account_and_intent_under_its_claim(): void {
		$order = $this->order();
		$order->update_meta_data( '_charge_id', 'ch_expired' );
		$order->update_meta_data( '_intent_id', 'pi_expired' );
		$order->save();
		$this->http->responses = array(
			$this->account_response(),
			$this->response(
				array(
					'id'     => 'pi_expired',
					'status' => 'canceled',
				)
			),
		);
		wc_get_container()->get( PaymentIntentEventHandler::class )->process( 'charge.expired', array( 'id' => 'ch_expired' ) );
		$this->assertSame( array( 'accounts', 'intentions/pi_expired' ), $this->routes() );
	}

	/**
	 * A lost dispute fetches its own summary before writing the refund.
	 */
	public function test_lost_dispute_reads_its_summary_under_its_claim(): void {
		$order = $this->order();
		$order->update_meta_data( '_charge_id', 'ch_lost' );
		$order->update_meta_data( '_wcpay_open_dispute_ids', array( 'dp_lost' ) );
		$order->save();
		$this->http->response = $this->response(
			array(
				'disputed_amount' => 300,
				'currency'        => 'usd',
			)
		);
		wc_get_container()->get( DisputeEventHandler::class )->process(
			'charge.dispute.closed',
			array(
				'id'     => 'dp_lost',
				'charge' => 'ch_lost',
				'status' => 'lost',
			)
		);
		$this->assertSame( array( 'disputes/dp_lost/summary' ), $this->routes() );
	}

	/**
	 * An event with no local token looks up the saved methods and the card, then settles its account.
	 */
	public function test_recurring_payment_repairs_the_token_and_settles_under_claim(): void {
		WooCommerceSubscriptionsDoubles::load_renewal_detector();
		$order = $this->order();
		wp_set_current_user( $order->get_customer_id() );
		update_user_option( $order->get_customer_id(), WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, 'cus_renewal' );
		wc_get_container()->get( WooPaymentsTokenService::class )->register();
		$GLOBALS['wcpay_test_renewal_order_ids'] = array( $order->get_id() );
		$list                                    = $this->response( array( 'data' => array() ) );
		$card                                    = array(
			'brand'     => 'visa',
			'last4'     => '4242',
			'exp_month' => 12,
			'exp_year'  => 2035,
		);
		$this->http->responses                   = array(
			$list,
			$list,
			$list,
			$this->response(
				array(
					'id'   => 'pm_renewal',
					'type' => 'card',
					'card' => $card,
				)
			),
			$this->account_response(),
		);
		wc_get_container()->get( WooPaymentsOrderCardInfo::class )->register();
		try {
			$this->with_order_payment_claim(
				$order,
				'payment status update',
				static function () use ( $order, $card ) {
					wc_get_container()->get( PaymentIntentEventHandler::class )->apply_succeeded_payment_intent_under_lock(
						$order,
						array(
							'id'             => 'pi_renewal',
							'status'         => 'succeeded',
							'amount'         => 1000,
							'currency'       => 'usd',
							'payment_method' => 'pm_renewal',
							'charges'        => array(
								'data' => array(
									array(
										'id' => 'ch_renewal',
										'payment_method_details' => array(
											'type' => 'card',
											'card' => $card,
										),
									),
								),
							),
						)
					);
				}
			);
		} finally {
			unset( $GLOBALS['wcpay_test_renewal_order_ids'] );
			wp_set_current_user( 0 );
		}
		$this->assertContains( 'payment_methods', $this->routes() );
		$this->assertContains( 'payment_methods/pm_renewal', $this->routes() );
		$this->assertContains( 'accounts', $this->routes() );
		$this->assertNotEmpty( ( new WC_Order( $order->get_id() ) )->get_payment_tokens() );
	}

	/**
	 * A Failed email may fetch the card details omitted by the decline.
	 */
	public function test_failed_email_reads_missing_card_details_under_claim(): void {
		$order = $this->order();
		$order->update_meta_data( '_payment_method_id', 'pm_declined' );
		$order->save();
		wc_get_container()->get( WooPaymentsOrderCardInfo::class )->register();
		$this->http->response = $this->response(
			array(
				'type' => 'card',
				'card' => array(
					'brand' => 'visa',
					'last4' => '4242',
				),
			)
		);
		$order->set_payment_method_title( 'Card' );
		$order->save();
		add_action( 'woocommerce_email_order_details', array( WC()->mailer(), 'order_details' ), 10, 4 );
		$email         = new \WC_Email_Customer_Failed_Order();
		$email->object = $order;
		$this->with_order_payment_claim( $order, 'checkout', array( $email, 'get_content_html' ) );
		$this->assertSame( array( 'payment_methods/pm_declined' ), $this->routes() );
	}

	/**
	 * The same route from an unlisted caller fails only while this request owns the order.
	 */
	public function test_unlisted_customer_create_is_reported_only_inside_the_claim(): void {
		$order                = $this->order();
		$api                  = wc_get_container()->get( Client::class );
		$this->http->response = $this->response( array( 'id' => 'cus_guard' ) );
		$create               = static fn() => $api->create_customer( array() );
		$create();
		$this->with_order_payment_claim( $order, 'checkout', $create );
		$create();
		$this->expectException( \PHPUnit\Framework\AssertionFailedError::class );
		$this->expectExceptionMessageMatches( '/^checkout order ' . $order->get_id() . ': POST customers via [^\n]+$/D' );
		PlatformRequestClaimGuard::finish();
	}

	/**
	 * Create an unpaid card order for a new customer.
	 *
	 * @return WC_Order
	 */
	private function order(): WC_Order {
		$customer_id = self::factory()->user->create();
		$this->assertIsInt( $customer_id );
		$order = \WC_Helper_Order::create_order( $customer_id );
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_currency( 'USD' );
		$order->save();
		return $order;
	}

	/**
	 * Recorded active US account.
	 *
	 * @return array
	 */
	private function account_response(): array {
		$fixture = wp_json_file_decode( __DIR__ . '/Fixtures/platform-test-drive-account.json', array( 'associative' => true ) );
		return $this->response( $fixture['account'] );
	}

	/**
	 * Wrap the platform JSON in a transport response.
	 *
	 * @param array $body Payload.
	 * @param int   $code Status.
	 * @return array
	 */
	private function response( array $body, int $code = 200 ): array {
		return array(
			'body'     => wp_json_encode( $body ),
			'response' => array( 'code' => $code ),
			'headers'  => array( 'content-type' => 'application/json' ),
		);
	}

	/**
	 * Return the ordered platform routes without their query strings.
	 *
	 * @return string[]
	 */
	private function routes(): array {
		return array_map( static fn( array $request ): string => explode( '/wcpay/', (string) wp_parse_url( $request['path'], PHP_URL_PATH ) )[1], $this->http->requests );
	}
}
