<?php
/**
 * WooPaymentsRedirectReturnController tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\TransientRowLock;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeeDetailsNoteController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentConfirmationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentRequestBuilder;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectApplier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentMethodDetailsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRedirectReturnController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticWooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use Throwable;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsRedirectReturnController class.
 */
class WooPaymentsRedirectReturnControllerTest extends WC_Unit_Test_Case {

	use ProviderTextLogAssertions;

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsRedirectReturnController|null
	 */
	private $sut;

	/**
	 * Original query parameters.
	 *
	 * @var array<string,mixed>
	 */
	private array $original_get = array();

	/**
	 * Original request method.
	 *
	 * @var mixed
	 */
	private $original_request_method;

	/**
	 * Whether the request method existed before the test.
	 *
	 * @var bool
	 */
	private bool $original_request_method_was_set = false;

	/**
	 * Original HPOS datastore caching option.
	 *
	 * @var mixed
	 */
	private $original_hpos_cache_option;

	/**
	 * Whether this test changed the HPOS datastore caching option.
	 *
	 * @var bool
	 */
	private bool $hpos_cache_option_changed = false;

	/**
	 * Original My Account page option.
	 *
	 * @var mixed
	 */
	private $original_myaccount_page_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->original_get               = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test fixture preserves globals for cleanup.
		$this->original_hpos_cache_option = get_option( CustomOrdersTableController::HPOS_DATASTORE_CACHING_ENABLED_OPTION, null );
		$this->original_myaccount_page_id = get_option( 'woocommerce_myaccount_page_id', null );
		$_GET                             = array();

		$this->original_request_method_was_set = array_key_exists( 'REQUEST_METHOD', $GLOBALS['_SERVER'] );
		$this->original_request_method         = $GLOBALS['_SERVER']['REQUEST_METHOD'] ?? null;
		$GLOBALS['_SERVER']['REQUEST_METHOD']  = 'GET';
		WC()->cart->empty_cart();
		wc_clear_notices();
		// The client writes redirect-return errors only with debug logging on (gw:2429, src/Internal/Logger.php:64-91).
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		// A redirect return that redirects would exit; stop it at wp_redirect instead.
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );
	}

	/**
	 * Stop a redirect before the controller exits.
	 *
	 * @param string $location Redirect location.
	 * @throws RedirectReturnRedirectIntercepted Always.
	 */
	public function intercept_redirect( $location ) {
		throw new RedirectReturnRedirectIntercepted( (string) $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test double carries the raw location for assertions; never output.
	}

	/**
	 * Run the controller and return the location it redirected to.
	 *
	 * @return string
	 */
	private function handle_wp_expecting_redirect(): string {
		try {
			$this->sut->handle_wp();
		} catch ( RedirectReturnRedirectIntercepted $redirect ) {
			return $redirect->location;
		}

		$this->fail( 'The redirect return should redirect the shopper.' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		if ( $this->sut instanceof WooPaymentsRedirectReturnController ) {
			remove_action( 'wp', array( $this->sut, 'handle_wp' ) );
		}

		global $wp;
		unset( $wp->query_vars['order-received'] );
		unset( $wp->query_vars['payment-methods'] );
		set_query_var( 'order-received', '' );
		$_GET = $this->original_get;
		if ( ! $this->original_request_method_was_set ) {
			unset( $GLOBALS['_SERVER']['REQUEST_METHOD'] );
		} else {
			$GLOBALS['_SERVER']['REQUEST_METHOD'] = $this->original_request_method;
		}
		remove_all_filters( 'woocommerce_is_order_received_page' );
		remove_all_filters( 'woocommerce_logging_class' );
		remove_all_filters( 'wcpay_dev_mode' );
		unset( $GLOBALS['wcpay_test_renewal_order_ids'], $GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ] );
		WC()->cart->empty_cart();
		wc_clear_notices();
		wp_set_current_user( 0 );
		if ( null === $this->original_myaccount_page_id ) {
			delete_option( 'woocommerce_myaccount_page_id' );
		} else {
			update_option( 'woocommerce_myaccount_page_id', $this->original_myaccount_page_id );
		}
		if ( $this->hpos_cache_option_changed ) {
			if ( null === $this->original_hpos_cache_option ) {
				delete_option( CustomOrdersTableController::HPOS_DATASTORE_CACHING_ENABLED_OPTION );
			} else {
				update_option( CustomOrdersTableController::HPOS_DATASTORE_CACHING_ENABLED_OPTION, $this->original_hpos_cache_option );
			}
			wc_get_container()->reset_all_resolved();
		}
		parent::tearDown();
	}

	/**
	 * @testdox A Create Account POST retaining a valid redirect query is left for Core without payment processing.
	 */
	public function test_handle_wp_ignores_create_account_post_with_retained_redirect_query(): void {
		$order = $this->create_order();
		$order->set_billing_email( 'guest@example.com' );
		$order->save();
		$product = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $product->get_id() );

		$api_client                 = new RedirectReturnApiClientStub();
		$api_client->payment_intent = $this->successful_payment_intent( $order, 'pi_create_account', 'pm_create_account' );
		$confirmation_owner         = $this->createMock( WooPaymentsIntentConfirmationService::class );
		$confirmation_owner->expects( $this->never() )->method( 'confirm_fetched_intent_for_order' );
		$this->sut = $this->create_controller( true, $confirmation_owner, $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_create_account' );

		$original_post    = $GLOBALS['_POST'];
		$original_request = $GLOBALS['_REQUEST'];
		$form_post        = array(
			'create-account' => '1',
			'email'          => 'guest@example.com',
			'password'       => 'guest-password',
			'_wpnonce'       => wp_create_nonce( 'wc_create_account' ),
		);
		try {
			$GLOBALS['_SERVER']['REQUEST_METHOD'] = 'POST';
			$GLOBALS['_POST']                     = $form_post;
			$GLOBALS['_REQUEST']                  = array_merge( $GLOBALS['_GET'], $form_post );

			$this->sut->handle_wp();

			$reloaded = wc_get_order( $order->get_id() );
			$this->assertInstanceOf( WC_Order::class, $reloaded );
			$this->assertSame( 0, $api_client->payment_intent_reads );
			$this->assertSame( 'pending', $reloaded->get_status() );
			$this->assertSame( 1, WC()->cart->get_cart_contents_count() );
			$this->assertSame( $form_post, $GLOBALS['_POST'] );
			$this->assertSame( $form_post['_wpnonce'], $GLOBALS['_REQUEST']['_wpnonce'] );
		} finally {
			$GLOBALS['_POST']    = $original_post;
			$GLOBALS['_REQUEST'] = $original_request;
		}
	}

	/**
	 * @testdox Non-GET and malformed request methods never process a redirect return.
	 * @dataProvider invalid_redirect_request_method_provider
	 *
	 * @param mixed $request_method Request method fixture.
	 */
	public function test_handle_wp_ignores_invalid_redirect_request_methods( $request_method ): void {
		$order   = $this->create_order();
		$product = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $product->get_id() );

		$api_client                 = new RedirectReturnApiClientStub();
		$api_client->payment_intent = $this->successful_payment_intent( $order, 'pi_invalid_method', 'pm_invalid_method' );
		$confirmation_owner         = $this->createMock( WooPaymentsIntentConfirmationService::class );
		$confirmation_owner->expects( $this->never() )->method( 'confirm_fetched_intent_for_order' );
		$this->sut = $this->create_controller( true, $confirmation_owner, $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_invalid_method' );

		if ( null === $request_method ) {
			unset( $GLOBALS['_SERVER']['REQUEST_METHOD'] );
		} else {
			$GLOBALS['_SERVER']['REQUEST_METHOD'] = $request_method;
		}

		$this->sut->handle_wp();

		$reloaded = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 0, $api_client->payment_intent_reads );
		$this->assertSame( 'pending', $reloaded->get_status() );
		$this->assertSame( 1, WC()->cart->get_cart_contents_count() );
	}

	/**
	 * @testdox A malformed request-method sanitizer result fails closed before redirect processing.
	 */
	public function test_handle_wp_ignores_malformed_sanitized_request_method(): void {
		$order   = $this->create_order();
		$product = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $product->get_id() );

		$api_client                 = new RedirectReturnApiClientStub();
		$api_client->payment_intent = $this->successful_payment_intent( $order, 'pi_malformed_method', 'pm_malformed_method' );
		$confirmation_owner         = $this->createMock( WooPaymentsIntentConfirmationService::class );
		$confirmation_owner->expects( $this->never() )->method( 'confirm_fetched_intent_for_order' );
		$this->sut = $this->create_controller( true, $confirmation_owner, $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_malformed_method' );

		$filter = static function ( $sanitized_value, $original_value ) {
			return 'GET' === $original_value ? array( 'GET' ) : $sanitized_value;
		};
		add_filter( 'sanitize_text_field', $filter, 10, 2 );
		try {
			$this->sut->handle_wp();

			$reloaded = wc_get_order( $order->get_id() );
			$this->assertInstanceOf( WC_Order::class, $reloaded );
			$this->assertSame( 0, $api_client->payment_intent_reads );
			$this->assertSame( 'pending', $reloaded->get_status() );
			$this->assertCount( 0, $reloaded->get_payment_tokens() );
			$this->assertSame( 1, WC()->cart->get_cart_contents_count() );
		} finally {
			remove_filter( 'sanitize_text_field', $filter, 10 );
		}
	}

	/**
	 * Invalid redirect request methods.
	 *
	 * @return array<string,array{request_method:mixed}>
	 */
	public function invalid_redirect_request_method_provider(): array {
		return array(
			'PUT'        => array( 'request_method' => 'PUT' ),
			'empty'      => array( 'request_method' => '' ),
			'missing'    => array( 'request_method' => null ),
			'non-scalar' => array( 'request_method' => array( 'GET' ) ),
		);
	}

	/**
	 * @testdox A valid positive-total redirect return confirms the order, saves the token, and empties the cart.
	 * @dataProvider valid_redirect_request_method_provider
	 *
	 * @param string $request_method Request method fixture.
	 */
	public function test_handle_wp_confirms_positive_total_redirect_return( string $request_method ): void {
		$GLOBALS['_SERVER']['REQUEST_METHOD'] = $request_method;
		$user_id                              = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order                                = $this->create_order( '50.00', $user_id, true );
		$product                              = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $product->get_id() );

		$api_client                 = new RedirectReturnApiClientStub();
		$api_client->payment_intent = $this->successful_payment_intent( $order, 'pi_return', 'pm_return' );
		$token_service              = $this->create_token_service(
			array(
				'pm_return' => array(
					'id'   => 'pm_return',
					'type' => 'card',
					'card' => array(
						'brand'     => 'visa',
						'last4'     => '4242',
						'exp_month' => 12,
						'exp_year'  => 2035,
					),
				),
			)
		);
		$confirmation_owner         = $this->create_confirmation_service( $api_client, $token_service );
		$this->sut                  = $this->create_controller( true, $confirmation_owner, $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_return', true );

		$this->sut->handle_wp();
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'processing', $reloaded->get_status() );
		$this->assertSame( 1, $api_client->payment_intent_reads );
		$this->assertSame( 'pi_return', $api_client->last_payment_intent_id );
		$this->assertCount( 1, $reloaded->get_payment_tokens() );
		$this->assertSame( 0, WC()->cart->get_cart_contents_count() );
	}

	/**
	 * @testdox A token-save error on the redirect return is logged and the order still completes, even for a recurring order.
	 *
	 * Client 11.1.0 process_redirect_payment() catches the token-save exception, logs "Error when saving payment method: ..."
	 * at info level through its gated Logger (gw:4312) and goes on to update the order from the intent (gw:2389-2406). Only the order-status
	 * callback stops a recurring order on that error (gw:4309-4321). Native logs the platform error's status and code, never its message.
	 */
	public function test_handle_wp_completes_order_when_token_save_fails(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order   = $this->create_order( '50.00', $user_id, true );
		WooCommerceSubscriptionsDoubles::load_renewal_detector();
		$GLOBALS['wcpay_test_renewal_order_ids'] = array( $order->get_id() );

		$api_client                 = new RedirectReturnApiClientStub();
		$api_client->payment_intent = $this->successful_payment_intent( $order, 'pi_token_fails', 'pm_token_fails' );
		$token_service              = new class() extends WooPaymentsTokenService {
			/**
			 * Fail every token save.
			 *
			 * @param string $payment_method_id Payment method ID.
			 * @param int    $user_id           User ID.
			 * @throws \RuntimeException Always.
			 */
			public function get_or_create_token_for_user( string $payment_method_id, int $user_id ): ?\WC_Payment_Token {
				unset( $payment_method_id, $user_id );
				// The token save fetches the payment method from the platform; its error carries the platform's text.
				throw WooPaymentsRedirectReturnControllerTest::make_provider_error();
			}
		};
		$token_service->init( $this->createMock( WooPaymentsPaymentMethodDetailsService::class ), new StaticWooPaymentsRuntimeArbiter( true ), wc_get_container()->get( WooPaymentsApiClient::class ), wc_get_container()->get( WooPaymentsCustomerService::class ), wc_get_container()->get( WooPaymentsAccountService::class ), wc_get_container()->get( WooPaymentsOrderDataService::class ) );
		$logger = new RedirectReturnRecordingLogger();
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		$this->sut = $this->create_controller( true, $this->create_confirmation_service( $api_client, $token_service ), $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_token_fails', true );

		$this->sut->handle_wp();
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'processing', $reloaded->get_status() );
		$this->assertCount( 0, $reloaded->get_payment_tokens() );
		$this->assertSame( array(), wc_get_notices( 'error' ) );
		// Only the WooPayments lines: core's order emails log under their own source, and whether they send depends on the
		// mailer's first load, which an earlier test in the process may already have done with its hooks since restored.
		$woopayments_info = array_filter( $logger->info_calls, static fn( array $call ): bool => WooPaymentsLogger::SOURCE === ( $call['context']['source'] ?? '' ) );
		$this->assertSame( array( 'Error when saving payment method.' ), array_column( $woopayments_info, 'message' ) );
		$this->assertSame( array( 404, 'resource_missing' ), array( reset( $woopayments_info )['context']['http_status'], reset( $woopayments_info )['context']['error_code'] ) );
		$written = (string) wp_json_encode( $logger );
		foreach ( array( 'No such customer', 'shopper@example.com', 'pay.example.test', 'sk_test_leak123' ) as $provider_text ) {
			$this->assertStringNotContainsString( $provider_text, $written );
		}
	}

	/**
	 * Valid redirect request methods.
	 *
	 * @return array<string,array{request_method:string}>
	 */
	public function valid_redirect_request_method_provider(): array {
		return array(
			'canonical GET'  => array( 'request_method' => 'GET' ),
			'lowercase get'  => array( 'request_method' => 'get' ),
			'mixed case GeT' => array( 'request_method' => 'GeT' ),
		);
	}

	/**
	 * @testdox A redirect-method return confirms the same intent and transitions the order to processing.
	 *
	 * Oracle: WooPayments 11.1.0 `class-wc-payment-gateway-wcpay.php:2321-2407` (redirect return
	 * confirmation reads the fetched charge back onto the order), `class-wc-payments-order-service.php:403-409`
	 * (the succeeded status transitions to `mark_payment_completed`, moving the order to
	 * `processing`), and `:1351` (`attach_intent_info_to_order`, which persists `_intent_id` and
	 * `_charge_id` from the fetched intent and its latest charge).
	 *
	 * The `Alipay (REC-RM)` row is T.3 Task 4's RECORD swap (`plan-task-t3.md`): the real recorded
	 * PaymentIntent REC-RM's Alipay handle-redirect return produced
	 * (`Fixtures/rec-t3-redirect-alipay.json`, pair `alipay_redirect_return_succeeded`), obtained
	 * from one API-only GET of the intent a real Playwright run's hosted authorize left behind. That
	 * recording's shopper is a guest, so this row gives the test order's customer a WordPress user
	 * (the plan's recording notes) and adds the missing "no token for non-reusable methods on
	 * return" assertion (client `gw:2321-2407`): Alipay is not a reusable payment method type, so
	 * the redirect return must save no token for the customer. This test order's total is set to the
	 * recording's own 12.00 USD (matching `amount: 1200`), and the recorded intent's single
	 * `metadata.order_id` field is rewritten to this order's id: `order_matches_intent()` compares
	 * the fetched intent's `metadata.order_id` against the order the return URL names, so a
	 * recording captured against a different order needs that one substitution to pass the match;
	 * no other recorded field is altered.
	 *
	 * @dataProvider redirect_method_return_provider
	 *
	 * @param string $method       Split gateway payment method ID.
	 * @param string $charge_id    Provider charge ID fixture (Bancontact settles under a `py_` prefix).
	 * @param bool   $use_recorded Whether to feed REC-RM's recorded PaymentIntent instead of a hand-built one.
	 */
	public function test_handle_wp_confirms_redirect_method_return( string $method, string $charge_id, bool $use_recorded = false ): void {
		$customer_id = $use_recorded ? self::factory()->user->create() : 0;
		$order       = $this->create_order( $use_recorded ? '12.00' : '50.00', $customer_id, true );
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . $method );
		$order->save();

		$intent_id = 'pi_redirect';
		if ( $use_recorded ) {
			$recorded                         = $this->load_recorded_redirect_alipay_entry();
			$recorded['metadata']['order_id'] = (string) $order->get_id();
			$intent_id                        = (string) $recorded['id'];
		}

		$api_client                 = new RedirectReturnApiClientStub();
		$api_client->payment_intent = $use_recorded ? $recorded : $this->redirect_method_payment_intent( $order, $intent_id, $method, $charge_id );
		// The REC-RM row feeds the token service the real recorded payment-method details (type
		// alipay) and requests a save, so "0 tokens" proves Alipay's non-reusable type is refused
		// (client gw:2321-2407), not that no save was ever asked for.
		$token_service      = $use_recorded
			? $this->create_token_service( array( (string) $recorded['payment_method'] => $recorded['charges']['data'][0]['payment_method_details'] ) )
			: null;
		$confirmation_owner = $this->create_confirmation_service( $api_client, $token_service );
		$this->sut          = $this->create_controller( true, $confirmation_owner, $api_client );
		$this->set_payment_intent_return_request( $order, $intent_id, $use_recorded );

		$this->sut->handle_wp();
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'processing', $reloaded->get_status() );
		$this->assertSame( 1, $api_client->payment_intent_reads );
		$this->assertSame( $intent_id, $api_client->last_payment_intent_id );
		$this->assertSame( $intent_id, $reloaded->get_meta( '_intent_id', true ) );
		$this->assertSame( $charge_id, $reloaded->get_meta( '_charge_id', true ) );

		if ( $use_recorded ) {
			global $wpdb;
			// Read the row count directly: the token data store only returns gateways
			// registered in this request, and the test runtime registers none.
			$this->assertSame(
				'0',
				$wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE user_id = %d", $customer_id ) ),
				'REC-RM: a non-reusable redirect method (Alipay) must save no token, of any gateway, for the customer on return.'
			);
		}
	}

	/**
	 * Redirect-method return fixtures.
	 *
	 * @return array<string,array{0:string,1:string,2?:bool}>
	 */
	public function redirect_method_return_provider(): array {
		return array(
			'Alipay'            => array( 'alipay', 'ch_redirect' ),
			'Affirm'            => array( 'affirm', 'ch_redirect' ),
			'Cash App Afterpay' => array( 'afterpay_clearpay', 'ch_redirect' ),
			'Bancontact'        => array( 'bancontact', 'py_redirect' ),
			'Alipay (REC-RM)'   => array( 'alipay', 'py_3UJjDMBzWlxcwgpP1P6NMtfy', true ),
		);
	}

	/**
	 * Load REC-RM's recorded Alipay redirect-return PaymentIntent body.
	 *
	 * @return array<string,mixed>
	 */
	private function load_recorded_redirect_alipay_entry(): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$contents = file_get_contents( __DIR__ . '/Fixtures/rec-t3-redirect-alipay.json' );
		$this->assertIsString( $contents );
		$decoded = json_decode( $contents, true );
		$this->assertIsArray( $decoded );

		foreach ( $decoded['entries'] as $entry ) {
			if ( is_array( $entry ) && ( $entry['pair'] ?? '' ) === 'alipay_redirect_return_succeeded' ) {
				return $entry['response']['body'];
			}
		}

		$this->fail( "REC-RM fixture has no entry for pair 'alipay_redirect_return_succeeded'." );
	}

	/**
	 * @testdox An invalid redirect nonce is a no-op and does not terminate the request.
	 */
	public function test_handle_wp_ignores_invalid_nonce_without_dying(): void {
		$order              = $this->create_order();
		$api_client         = new RedirectReturnApiClientStub();
		$confirmation_owner = $this->createMock( WooPaymentsIntentConfirmationService::class );
		$confirmation_owner->expects( $this->never() )->method( 'confirm_fetched_intent_for_order' );
		$this->sut = $this->create_controller( true, $confirmation_owner, $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_invalid_nonce' );
		$_GET['_wpnonce'] = 'invalid';

		$this->sut->handle_wp();

		$this->assertSame( 0, $api_client->payment_intent_reads );
		$this->assertSame( 'pending', $order->get_status() );
	}

	/**
	 * @testdox A redirect return with the wrong order key is a no-op.
	 */
	public function test_handle_wp_ignores_wrong_order_key(): void {
		$order              = $this->create_order();
		$api_client         = new RedirectReturnApiClientStub();
		$confirmation_owner = $this->createMock( WooPaymentsIntentConfirmationService::class );
		$confirmation_owner->expects( $this->never() )->method( 'confirm_fetched_intent_for_order' );
		$this->sut = $this->create_controller( true, $confirmation_owner, $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_wrong_key' );
		$_GET['key'] = 'wc_order_wrong';

		$this->sut->handle_wp();

		$this->assertSame( 0, $api_client->payment_intent_reads );
		$this->assertSame( 'pending', $order->get_status() );
	}

	/**
	 * @testdox PaymentIntent metadata for another order is logged, leaves the order unfailed and returns the shopper to checkout with the mismatch flag and the cart kept.
	 *
	 * Client 11.1.0: validate_order_id_received_vs_intent_meta_order_id() logs and throws the mismatch exception
	 * (gw:674-692, 2357-2359); the catch skips mark_payment_failed() for it, adds the notice and redirects to the
	 * checkout URL with `upe_process_redirect_order_id_mismatched=yes` (gw:2428-2456, constant gw:118).
	 */
	public function test_handle_wp_rejects_mismatched_payment_intent_metadata(): void {
		$order   = $this->create_order();
		$product = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $product->get_id() );
		$api_client                 = new RedirectReturnApiClientStub();
		$api_client->payment_intent = $this->successful_payment_intent( $order, 'pi_mismatch', 'pm_mismatch' );
		$api_client->payment_intent['metadata']['order_id'] = $order->get_id() + 1;

		$confirmation_owner = $this->createMock( WooPaymentsIntentConfirmationService::class );
		$confirmation_owner->expects( $this->never() )->method( 'confirm_fetched_intent_for_order' );
		$logger = new RedirectReturnRecordingLogger();
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		$this->sut = $this->create_controller( true, $confirmation_owner, $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_mismatch' );

		$location = $this->handle_wp_expecting_redirect();
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertSame( add_query_arg( 'upe_process_redirect_order_id_mismatched', 'yes', wc_get_checkout_url() ), $location );
		$this->assert_single_error_notice( "We're not able to process this payment due to the order ID mismatch. Please try again later." );
		$this->assertSame( 1, WC()->cart->get_cart_contents_count(), 'The cart must survive a rejected redirect return.' );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'pending', $reloaded->get_status() );
		$this->assertSame( 'pi_mismatch', $reloaded->get_meta( '_intent_id', true ) );
		$this->assertCount( 1, $logger->error_calls );
		$this->assertSame( 'woopayments', $logger->error_calls[0]['context']['source'] );
	}

	/**
	 * @testdox A non-numeric PaymentIntent metadata order ID is rejected before mutation.
	 */
	public function test_handle_wp_rejects_non_numeric_payment_intent_metadata_order_id(): void {
		$order                      = $this->create_order();
		$api_client                 = new RedirectReturnApiClientStub();
		$api_client->payment_intent = $this->successful_payment_intent( $order, 'pi_malformed_order_id', 'pm_malformed' );
		$api_client->payment_intent['metadata']['order_id'] = $order->get_id() . 'junk';

		$confirmation_owner = $this->createMock( WooPaymentsIntentConfirmationService::class );
		$confirmation_owner->method( 'confirm_fetched_intent_for_order' )
			->willReturnCallback(
				static function ( WC_Order $confirmed_order ): void {
					$confirmed_order->update_status( 'processing' );
				}
			);
		$logger = new RedirectReturnRecordingLogger();
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		$this->sut = $this->create_controller( true, $confirmation_owner, $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_malformed_order_id' );

		$location = $this->handle_wp_expecting_redirect();
		$reloaded = wc_get_order( $order->get_id() );

		// Client gw:675-676 reads a non-numeric order ID as 0, a mismatch.
		$this->assertSame( add_query_arg( 'upe_process_redirect_order_id_mismatched', 'yes', wc_get_checkout_url() ), $location );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'pending', $reloaded->get_status() );
		$this->assertCount( 1, $logger->error_calls );
	}

	/**
	 * @testdox An old redirect attempt cannot fetch or confirm a newer order intent.
	 */
	public function test_handle_wp_rejects_old_payment_intent_attempt_before_fetch(): void {
		$order = $this->create_order();
		$order->update_meta_data( '_intent_id', 'pi_current' );
		$order->save();

		$api_client                 = new RedirectReturnApiClientStub();
		$api_client->payment_intent = $this->successful_payment_intent( $order, 'pi_old', 'pm_old' );
		$confirmation_calls         = 0;
		$confirmation_owner         = $this->createMock( WooPaymentsIntentConfirmationService::class );
		$confirmation_owner->method( 'confirm_fetched_intent_for_order' )
			->willReturnCallback(
				static function ( WC_Order $confirmed_order ) use ( &$confirmation_calls ): void {
					++$confirmation_calls;
					$confirmed_order->update_status( 'processing' );
				}
			);
		$logger = new RedirectReturnRecordingLogger();
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		$this->sut = $this->create_controller( true, $confirmation_owner, $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_old' );

		$this->sut->handle_wp();
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 0, $api_client->payment_intent_reads );
		$this->assertSame( 0, $confirmation_calls );
		$this->assertSame( 'pending', $reloaded->get_status() );
		$this->assertCount( 1, $logger->error_calls );
	}

	/**
	 * @testdox A fetched intent with another identity is rejected before mutation.
	 */
	public function test_handle_wp_rejects_fetched_payment_intent_identity_mismatch(): void {
		$order = $this->create_order();
		$order->update_meta_data( '_intent_id', 'pi_expected' );
		$order->save();

		$api_client                 = new RedirectReturnApiClientStub();
		$api_client->payment_intent = $this->successful_payment_intent( $order, 'pi_other', 'pm_other' );
		$confirmation_calls         = 0;
		$confirmation_owner         = $this->createMock( WooPaymentsIntentConfirmationService::class );
		$confirmation_owner->method( 'confirm_fetched_intent_for_order' )
			->willReturnCallback(
				static function ( WC_Order $confirmed_order ) use ( &$confirmation_calls ): void {
					++$confirmation_calls;
					$confirmed_order->update_status( 'processing' );
				}
			);
		$logger = new RedirectReturnRecordingLogger();
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		$this->sut = $this->create_controller( true, $confirmation_owner, $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_expected' );

		$location = $this->handle_wp_expecting_redirect();
		$reloaded = wc_get_order( $order->get_id() );

		// The fetched intent belongs to another payment, so it gets the client's order-mismatch outcome (gw:2428-2456).
		$this->assertSame( add_query_arg( 'upe_process_redirect_order_id_mismatched', 'yes', wc_get_checkout_url() ), $location );

		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 1, $api_client->payment_intent_reads );
		$this->assertSame( 'pi_expected', $api_client->last_payment_intent_id );
		$this->assertSame( 0, $confirmation_calls );
		$this->assertSame( 'pending', $reloaded->get_status() );
		$this->assertCount( 1, $logger->error_calls );
	}

	/**
	 * @testdox A non-native order cannot fetch or confirm a WooPayments redirect intent.
	 */
	public function test_handle_wp_rejects_non_native_order_before_fetch(): void {
		$order = $this->create_order();
		$order->set_payment_method( 'bacs' );
		$order->update_meta_data( '_intent_id', 'pi_non_native' );
		$order->save();

		$api_client                 = new RedirectReturnApiClientStub();
		$api_client->payment_intent = $this->successful_payment_intent( $order, 'pi_non_native', 'pm_non_native' );
		$confirmation_calls         = 0;
		$confirmation_owner         = $this->createMock( WooPaymentsIntentConfirmationService::class );
		$confirmation_owner->method( 'confirm_fetched_intent_for_order' )
			->willReturnCallback(
				static function () use ( &$confirmation_calls ): void {
					++$confirmation_calls;
				}
			);
		$this->sut = $this->create_controller( true, $confirmation_owner, $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_non_native' );

		$this->sut->handle_wp();
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 0, $api_client->payment_intent_reads );
		$this->assertSame( 0, $confirmation_calls );
		$this->assertSame( 'pending', $reloaded->get_status() );
	}

	/**
	 * @testdox A webhook transition during intent fetch prevents stale confirmation and cart clearing.
	 */
	public function test_handle_wp_reloads_order_after_fetch_before_confirmation(): void {
		$order = $this->create_order();
		$order->update_meta_data( '_intent_id', 'pi_webhook_race' );
		$order->save();
		$product = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $product->get_id() );

		$api_client                 = new RedirectReturnApiClientStub();
		$api_client->payment_intent = $this->successful_payment_intent( $order, 'pi_webhook_race', 'pm_webhook_race' );

		$api_client->before_payment_intent_return = static function () use ( $order ): void {
			$fresh_order = wc_get_order( $order->get_id() );
			if ( $fresh_order instanceof WC_Order ) {
				$fresh_order->set_status( 'processing' );
				$fresh_order->save();
			}
		};

		$confirmation_calls = 0;
		$confirmation_owner = $this->createMock( WooPaymentsIntentConfirmationService::class );
		$confirmation_owner->method( 'confirm_fetched_intent_for_order' )
			->willReturnCallback(
				static function () use ( &$confirmation_calls ): void {
					++$confirmation_calls;
				}
			);
		$this->sut = $this->create_controller( true, $confirmation_owner, $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_webhook_race' );

		$this->sut->handle_wp();
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 1, $api_client->payment_intent_reads );
		$this->assertSame( 0, $confirmation_calls );
		$this->assertSame( 'processing', $reloaded->get_status() );
		$this->assertSame( 1, WC()->cart->get_cart_contents_count() );
	}

	/**
	 * @testdox Cross-request authoritative intent changes bypass primed caches before confirmation.
	 */
	public function test_handle_wp_invalidates_cross_request_order_caches_after_fetch(): void {
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			update_option( CustomOrdersTableController::HPOS_DATASTORE_CACHING_ENABLED_OPTION, 'yes' );
			wc_get_container()->reset_all_resolved();
			$this->hpos_cache_option_changed = true;
		}

		$order = $this->create_order();
		$order->update_meta_data( '_intent_id', 'pi_cached_attempt' );
		$order->save();

		$primed_order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $primed_order );
		$this->assertSame( 'pending', $primed_order->get_status() );
		$this->assertSame( 'pi_cached_attempt', $primed_order->get_meta( '_intent_id', true ) );
		get_post( $order->get_id() );

		$product = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $product->get_id() );

		$raw_update_succeeded       = false;
		$api_client                 = new RedirectReturnApiClientStub();
		$api_client->payment_intent = $this->successful_payment_intent( $order, 'pi_cached_attempt', 'pm_cached_attempt' );

		$api_client->before_payment_intent_return = function () use ( $order, &$raw_update_succeeded ): void {
			$raw_update_succeeded = $this->update_authoritative_order_intent_without_cache_invalidation( $order->get_id(), 'pi_new_attempt' );
		};

		$confirmation_calls = 0;
		$confirmation_owner = $this->createMock( WooPaymentsIntentConfirmationService::class );
		$confirmation_owner->method( 'confirm_fetched_intent_for_order' )
			->willReturnCallback(
				static function () use ( &$confirmation_calls ): void {
					++$confirmation_calls;
				}
			);
		$logger = new RedirectReturnRecordingLogger();
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		$this->sut = $this->create_controller( true, $confirmation_owner, $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_cached_attempt' );

		$this->sut->handle_wp();

		$this->assertTrue( $raw_update_succeeded );
		$this->assertSame( 1, $api_client->payment_intent_reads );
		$this->assertSame( 0, $confirmation_calls );
		$this->assertSame( 1, WC()->cart->get_cart_contents_count() );
		$this->assertCount( 1, $logger->error_calls );
	}

	/**
	 * @testdox Paid and on-hold orders do not fetch or reconfirm redirect intents.
	 * @dataProvider terminal_order_status_provider
	 *
	 * @param string $status Protected order status.
	 */
	public function test_handle_wp_ignores_already_transitioned_order( string $status ): void {
		$order = $this->create_order();
		$order->set_status( $status );
		$order->save();
		$api_client         = new RedirectReturnApiClientStub();
		$confirmation_owner = $this->createMock( WooPaymentsIntentConfirmationService::class );
		$confirmation_owner->expects( $this->never() )->method( 'confirm_fetched_intent_for_order' );
		$this->sut = $this->create_controller( true, $confirmation_owner, $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_existing' );

		$this->sut->handle_wp();

		$this->assertSame( 0, $api_client->payment_intent_reads );
		$this->assertSame( $status, $order->get_status() );
	}

	/**
	 * Protected order statuses.
	 *
	 * @return array<string,array{status:string}>
	 */
	public function terminal_order_status_provider(): array {
		return array(
			'processing' => array( 'status' => 'processing' ),
			'completed'  => array( 'status' => 'completed' ),
			'on hold'    => array( 'status' => 'on-hold' ),
		);
	}

	/**
	 * @testdox An API failure fetching the intent fails the order, adds the filtered notice and redirects to checkout with the cart kept.
	 *
	 * Client 11.1.0 process_redirect_payment(): Get_Intention::send() throws inside the try (gw:2341-2345). The catch
	 * (gw:2428-2456) calls mark_payment_failed() with a null status and charge and "UPE payment failed: <message>"
	 * (os:463-478, 2106-2127), adds get_filtered_error_message() as an error notice (utils:769-776 for the connection
	 * error) and redirects to wc_get_checkout_url() and exits, before core's template_redirect cart clearing.
	 */
	public function test_handle_wp_fails_order_and_redirects_to_checkout_on_api_failure(): void {
		$order   = $this->create_order();
		$product = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $product->get_id() );
		$api_client            = new RedirectReturnApiClientStub();
		$api_client->exception = new WooPaymentsApiException( 'Transport unavailable.', 'wcpay_http_request_failed', 503 );
		$confirmation_owner    = $this->createMock( WooPaymentsIntentConfirmationService::class );
		$confirmation_owner->expects( $this->never() )->method( 'confirm_fetched_intent_for_order' );
		$logger = new RedirectReturnRecordingLogger();
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		$this->sut = $this->create_controller( true, $confirmation_owner, $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_api_error' );

		$location = $this->handle_wp_expecting_redirect();
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertSame( wc_get_checkout_url(), $location );
		$this->assert_single_error_notice( 'There was an error while processing this request. If you continue to see this notice, please contact the admin.' );
		$this->assertSame( 1, WC()->cart->get_cart_contents_count(), 'The cart must survive a failed redirect return.' );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'failed', $reloaded->get_status() );
		$this->assert_failed_note_with_message( $reloaded, 'pi_api_error', 'UPE payment failed: Transport unavailable.' );
		$this->assertCount( 1, $logger->error_calls );
		// Client gw:2429 writes Logger::exception() (includes/class-logger.php:100-112).
		$this->assertSame( 'Error occurred during the redirect payment process.', $logger->error_calls[0]['message'] );
		$this->assertSame( WooPaymentsApiException::class, $logger->error_calls[0]['context']['exception'] ?? '' );
		// The platform's message stays out of the log; its status and code go in.
		$this->assertSame( array( 503, 'wcpay_http_request_failed' ), array( $logger->error_calls[0]['context']['http_status'] ?? null, $logger->error_calls[0]['context']['error_code'] ?? null ) );
		$this->assertStringNotContainsString( 'Transport unavailable', (string) wp_json_encode( $logger ) );
		$this->assertArrayHasKey( 'code', $logger->error_calls[0]['context'] );
		$this->assertArrayHasKey( 'trace', $logger->error_calls[0]['context'] );
	}

	/**
	 * @testdox With debug logging off, an API failure fetching the intent still fails the order but writes no log line.
	 *
	 * Client 11.1.0 logs it through Logger::exception() (gw:2429), which writes only with debug logging on or in dev mode
	 * (`src/Internal/Logger.php:64-91`).
	 */
	public function test_handle_wp_does_not_log_api_fetch_failure_with_logging_off(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'no' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$order                 = $this->create_order();
		$api_client            = new RedirectReturnApiClientStub();
		$api_client->exception = new WooPaymentsApiException( 'Transport unavailable.', 'wcpay_http_request_failed', 503 );
		$logger                = new RedirectReturnRecordingLogger();
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		$this->sut = $this->create_controller( true, null, $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_api_error' );

		$this->assertSame( wc_get_checkout_url(), $this->handle_wp_expecting_redirect() );
		$this->assertSame( 'failed', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( array(), $logger->error_calls );
	}

	/**
	 * @testdox A non-API error fetching the intent shows only the generic notice and is logged with its class, never its message, even with debug logging off.
	 *
	 * Decided divergence (monitor ruling 2026-10-04): client 11.1.0 shows a non-API exception's raw message
	 * (get_filtered_error_message(), utils:770-800, via gw:2447) and logs it only with debug logging on. Native shows the
	 * generic notice and always logs the throwable's class, code and trace (review 67 F9).
	 */
	public function test_handle_wp_hides_non_api_fetch_error_and_always_logs_it(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'no' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$order                 = $this->create_order();
		$api_client            = new RedirectReturnApiClientStub();
		$api_client->exception = new \RuntimeException( 'Undefined array key "client_secret" in /var/www/html/api.php' );
		$logger                = new RedirectReturnRecordingLogger();
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		$this->sut = $this->create_controller( true, null, $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_runtime_error' );

		$location = $this->handle_wp_expecting_redirect();

		$this->assertSame( wc_get_checkout_url(), $location );
		$this->assert_single_error_notice( "We're not able to process this payment. Please try again later." );
		$this->assertCount( 1, $logger->error_calls );
		$this->assertSame( 'Error fetching the intent for native WooPayments redirect return.', $logger->error_calls[0]['message'] );
		$this->assertSame( \RuntimeException::class, $logger->error_calls[0]['context']['exception'] ?? null );
		$this->assertSame( array( $order->get_id(), 'pi_runtime_error', WooPaymentsLogger::SOURCE ), array( $logger->error_calls[0]['context']['order_id'] ?? null, $logger->error_calls[0]['context']['intent_id'] ?? null, $logger->error_calls[0]['context']['source'] ?? null ) );
		$this->assertStringNotContainsString( 'client_secret', (string) wp_json_encode( $logger ) );
	}

	/**
	 * @testdox A failed intent fetch leaves an order $change during the fetch untouched and stays on order-received.
	 *
	 * Decided divergence (money hazard): client 11.1.0 fails the order read at request start (gw:2428-2444), and its paid
	 * check covers only processing and completed (os:2863-2880), so an authorized charge would end on a failed order.
	 * An order now bound to another intent belongs to another attempt, as in the client's early return (gw:2305-2307).
	 * The order is read again under the order payment lock, so an on-hold a webhook writes after the fetch and before the
	 * failure takes the lock is kept too (review 33 F1).
	 *
	 * @testWith ["a webhook put on hold", "on-hold"]
	 *           ["another payment attempt bound to its intent", "pending"]
	 *           ["a webhook put on hold just before the lock", "on-hold"]
	 *
	 * @param string $change          What happened to the order during the fetch.
	 * @param string $expected_status Order status after the return.
	 */
	public function test_handle_wp_keeps_order_held_during_failed_fetch( string $change, string $expected_status ): void {
		$order                                    = $this->create_order();
		$api_client                               = new RedirectReturnApiClientStub();
		$api_client->exception                    = new WooPaymentsApiException( 'Request timed out.', 'wcpay_http_request_failed', 504 );
		$api_client->before_payment_intent_return = static function () use ( $order, $change ): void {
			$concurrent_order = wc_get_order( $order->get_id() );
			if ( 'a webhook put on hold' === $change ) {
				$concurrent_order->update_status( 'on-hold' );
				return;
			}
			if ( 'another payment attempt bound to its intent' === $change ) {
				$concurrent_order->update_meta_data( '_intent_id', 'pi_other' );
				$concurrent_order->save();
			}
		};

		$container = wc_get_container();
		if ( 'a webhook put on hold just before the lock' === $change ) {
			// A webhook takes the order payment lock, writes on-hold and releases it right before this return claims it.
			$webhook_first_store = new RedirectReturnWebhookFirstOrderPaymentLock();
			$webhook_first_store->init( new TransientRowLock() );
			$container->replace( OrderPaymentLock::class, $webhook_first_store );
		}
		$this->sut = $this->create_controller( true, null, $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_held_during_fetch' );

		try {
			$this->sut->handle_wp();
		} finally {
			$container->reset_replacement( OrderPaymentLock::class );
		}
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( $expected_status, $reloaded->get_status() );
		$this->assertSame( array(), wc_get_notices( 'error' ) );
		$failure_notes = array_filter(
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
			static fn( $note ): bool => false !== strpos( $note->content, '<strong>failed</strong>' )
		);
		$this->assertSame( array(), $failure_notes );
	}

	/**
	 * @testdox A failed intent fetch while $holder holds the order payment lock leaves the order to it and stays on order-received.
	 *
	 * Better than the client (review 35 F4): client 11.1.0 skips the failure on a locked order (os:2747-2758) but still
	 * sends the shopper to checkout with the error notice (gw:2447-2454), where a resubmit can pay again while the holder
	 * is still at work. Native writes no failure and no notice, lets the redirect return end on order-received, and logs
	 * the refusal under the WooPayments source with the holder's operation from the lock record, whatever the setting.
	 *
	 * @testWith ["payment status update"]
	 *           ["checkout"]
	 *
	 * @param string $holder Operation recorded by the lock's holder.
	 */
	public function test_handle_wp_leaves_a_locked_order_to_the_lock_holder_after_a_failed_fetch( string $holder ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'no' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$order                 = $this->create_order();
		$api_client            = new RedirectReturnApiClientStub();
		$api_client->exception = new WooPaymentsApiException( 'Request timed out.', 'wcpay_http_request_failed', 504 );
		$store                 = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary            = new WooPaymentsPersistenceVocabulary();
		$lock_token            = $store->claim( $order, $vocabulary, 'pi_locked_return', $holder );
		$this->assertNotNull( $lock_token );
		$logger = new RedirectReturnRecordingLogger();
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		$this->sut = $this->create_controller( true, null, $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_locked_return' );

		try {
			$this->sut->handle_wp();
		} finally {
			$store->release( $order, $vocabulary, $lock_token );
		}
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'pending', $reloaded->get_status() );
		$this->assertSame( array(), wc_get_notices( 'error' ) );
		$failure_notes = array_filter(
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
			static fn( $note ): bool => false !== strpos( $note->content, '<strong>failed</strong>' )
		);
		$this->assertSame( array(), $failure_notes );
		$this->assertCount( 1, $logger->warning_calls );
		$this->assertMatchesRegularExpression( '/^order payment lock refused: order ' . $order->get_id() . ', refused redirect return failure, held by ' . $holder . ' for \d+s$/', $logger->warning_calls[0]['message'] );
		$this->assertSame( $holder, $logger->warning_calls[0]['context']['holder_operation'] ?? null );
		$this->assertSame( 'pi_locked_return', $logger->warning_calls[0]['context']['lock_value'] ?? null );
		$this->assertSame( 'woopayments', $logger->warning_calls[0]['context']['source'] ?? null );
	}

	/**
	 * @testdox The intent error's log line checks each code field on its own: $_dataName.
	 *
	 * Stripe's `last_payment_error` carries `type`, `code`, `decline_code` and `message` (API reference, PaymentIntent
	 * object); client 11.1.0 logs its message (gw:2376-2378). Native logs the two codes, each only when listed, so a
	 * free-text value in either field is logged as unknown_error.
	 *
	 * @dataProvider intent_error_code_fields
	 *
	 * @param string $code                  The error's `code`.
	 * @param string $decline_code          The error's `decline_code`.
	 * @param string $expected_code         Expected logged error_code.
	 * @param string $expected_decline_code Expected logged decline_code.
	 */
	public function test_intent_error_log_line_filters_each_code_field( string $code, string $decline_code, string $expected_code, string $expected_decline_code ): void {
		$order                      = $this->create_order();
		$api_client                 = new RedirectReturnApiClientStub();
		$api_client->payment_intent = array(
			'id'                 => 'pi_return_failed',
			'status'             => 'requires_payment_method',
			'currency'           => 'usd',
			'amount'             => 5000,
			'customer'           => 'cus_return',
			'payment_method'     => null,
			'metadata'           => array( 'order_id' => $order->get_id() ),
			'last_payment_error' => array(
				'type'         => 'card_error',
				'code'         => $code,
				'decline_code' => $decline_code,
				'message'      => 'Your card was declined.',
			),
		);
		$this->sut                  = $this->create_controller( true, $this->create_confirmation_service( $api_client ), $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_return_failed' );
		self::enable_woopayments_debug_logging();
		$logger = new RedirectReturnRecordingLogger();
		add_filter( 'woocommerce_logging_class', static fn() => $logger );

		$this->handle_wp_expecting_redirect();

		$lines = array_values( array_filter( $logger->info_calls, static fn( array $call ): bool => 'Error when processing payment.' === $call['message'] ) );
		$this->assertCount( 1, $lines );
		$this->assertSame( $expected_code, $lines[0]['context']['error_code'] ?? '' );
		$this->assertSame( $expected_decline_code, $lines[0]['context']['decline_code'] ?? '' );
		$logged = (string) wp_json_encode( $logger );
		foreach ( self::$provider_leak_fragments as $fragment ) {
			$this->assertStringNotContainsString( $fragment, $logged );
		}
	}

	/**
	 * A free-text value in one code field, next to a listed value in the other.
	 *
	 * @return array<string,array{string,string,string,string}>
	 */
	public function intent_error_code_fields(): array {
		$free_text = "No such customer: 'cus_123'; ask shopper@example.com, see https://pay.example.test/r?key=sk_test_leak123";

		return array(
			'free text in code'         => array( $free_text, 'insufficient_funds', 'unknown_error', 'insufficient_funds' ),
			'free text in decline_code' => array( 'card_declined', $free_text, 'card_declined', 'unknown_error' ),
		);
	}

	/**
	 * @testdox A positive-total return whose PaymentIntent carries a payment error fails the order, adds the client notice and redirects to checkout with the cart kept.
	 *
	 * Client 11.1.0 process_redirect_payment(): a non-empty last_payment_error (gw:2351, 2376-2382) throws "We're not
	 * able to process this payment. Please try again later."; the catch (gw:2428-2456) calls mark_payment_failed() with
	 * the "UPE payment failed: ..." message (os:463-478, 2106-2127), adds the message as an error notice and redirects to
	 * wc_get_checkout_url() and exits, before core's template_redirect cart clearing.
	 */
	public function test_handle_wp_redirects_failed_payment_intent_return_to_checkout(): void {
		$order   = $this->create_order();
		$product = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $product->get_id() );
		$api_client                 = new RedirectReturnApiClientStub();
		$api_client->payment_intent = array(
			'id'                 => 'pi_return_failed',
			'status'             => 'requires_payment_method',
			'currency'           => 'usd',
			'amount'             => 5000,
			'customer'           => 'cus_return',
			'payment_method'     => null,
			'metadata'           => array( 'order_id' => $order->get_id() ),
			'last_payment_error' => array(
				'type'         => 'card_error',
				'code'         => 'card_declined',
				'decline_code' => 'generic_decline',
				'message'      => 'Your card was declined.',
			),
		);
		$this->sut                  = $this->create_controller( true, $this->create_confirmation_service( $api_client ), $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_return_failed' );
		self::enable_woopayments_debug_logging();
		$logger = new RedirectReturnRecordingLogger();
		add_filter( 'woocommerce_logging_class', static fn() => $logger );

		$location = $this->handle_wp_expecting_redirect();
		$reloaded = wc_get_order( $order->get_id() );

		// Client gw:2378 logs the error's message; native logs its listed codes, and generic_decline is not listed.
		$intent_error_lines = array_values( array_filter( $logger->info_calls, static fn( array $call ): bool => 'Error when processing payment.' === $call['message'] ) );
		$this->assertCount( 1, $intent_error_lines );
		$this->assertSame( 'card_declined', $intent_error_lines[0]['context']['error_code'] ?? '' );
		$this->assertSame( 'unknown_error', $intent_error_lines[0]['context']['decline_code'] ?? '' );
		$this->assertStringNotContainsString( 'Your card was declined', (string) wp_json_encode( $logger ) );

		$this->assertSame( wc_get_checkout_url(), $location );
		$this->assert_single_error_notice( "We're not able to process this payment. Please try again later." );
		$this->assertSame( 1, WC()->cart->get_cart_contents_count(), 'The cart must survive a failed redirect return.' );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'failed', $reloaded->get_status() );
		$this->assert_failed_note_with_message( $reloaded, 'pi_return_failed', "UPE payment failed: We're not able to process this payment. Please try again later." );
	}

	/**
	 * @testdox A failure write that throws on a failed return is logged whatever the logging setting, and the shopper still goes back to checkout.
	 *
	 * Client 11.1.0 calls mark_payment_failed() inside its catch (gw:2440) with no catch around it, so a second failure
	 * there is visible. Native keeps the redirect (no money moved) and writes one always-on error line, since the order
	 * should be failed but is not (review 41 F2).
	 */
	public function test_handle_wp_always_logs_a_failure_write_that_throws(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'no' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$order                      = $this->create_order();
		$api_client                 = new RedirectReturnApiClientStub();
		$api_client->payment_intent = array(
			'id'                 => 'pi_write_throws',
			'status'             => 'requires_payment_method',
			'currency'           => 'usd',
			'amount'             => 5000,
			'customer'           => 'cus_return',
			'payment_method'     => null,
			'metadata'           => array( 'order_id' => $order->get_id() ),
			'last_payment_error' => array(
				'type'    => 'card_error',
				'code'    => 'card_declined',
				'message' => 'Your card was declined.',
			),
		);
		$lifecycle                  = $this->getMockBuilder( OrderPaymentLifecycleService::class )->onlyMethods( array( 'apply_under_lock' ) )->getMock();
		$lifecycle->method( 'apply_under_lock' )->willThrowException( new \RuntimeException( 'Database write failed.' ) );
		$logger = new RedirectReturnRecordingLogger();
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		$this->sut = $this->create_controller( true, $this->create_confirmation_service( $api_client ), $api_client, null, null, $lifecycle );
		$this->set_payment_intent_return_request( $order, 'pi_write_throws' );

		$location = $this->handle_wp_expecting_redirect();
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertSame( wc_get_checkout_url(), $location );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'pending', $reloaded->get_status() );
		$write_failures = array_values(
			array_filter(
				$logger->error_calls,
				static fn( array $call ): bool => \RuntimeException::class === ( $call['context']['exception'] ?? '' )
			)
		);
		$this->assertCount( 1, $write_failures, 'One line, written with debug logging off.' );
		$this->assertSame( WooPaymentsLogger::SOURCE, $write_failures[0]['context']['source'] ?? '' );
		$this->assertSame( $order->get_id(), $write_failures[0]['context']['order_id'] ?? null );
		$this->assertStringContainsString( 'raised RuntimeException: Database write failed.', (string) $write_failures[0]['message'] );
	}

	/**
	 * @testdox A canceled PaymentIntent that carries a payment error fails the order without cancelling it first.
	 *
	 * Client 11.1.0 process_redirect_payment() throws on any last_payment_error before reading the status (gw:2376-2382),
	 * so mark_payment_failed() moves the order from pending straight to failed (gw:2428-2446, os:463-478).
	 */
	public function test_handle_wp_fails_canceled_payment_intent_with_error_without_cancelling(): void {
		$order                      = $this->create_order();
		$api_client                 = new RedirectReturnApiClientStub();
		$api_client->payment_intent = array(
			'id'                 => 'pi_canceled_with_error',
			'status'             => 'canceled',
			'currency'           => 'usd',
			'amount'             => 5000,
			'customer'           => 'cus_return',
			'payment_method'     => null,
			'metadata'           => array( 'order_id' => $order->get_id() ),
			'last_payment_error' => array(
				'type'    => 'card_error',
				'code'    => 'card_declined',
				'message' => 'Your card was declined.',
			),
		);
		$cancellations              = 0;
		add_action(
			'woocommerce_order_status_cancelled',
			static function () use ( &$cancellations ): void {
				++$cancellations;
			}
		);
		$this->sut = $this->create_controller( true, $this->create_confirmation_service( $api_client ), $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_canceled_with_error' );

		$location = $this->handle_wp_expecting_redirect();

		$this->assertSame( wc_get_checkout_url(), $location );
		$reloaded = wc_get_order( $order->get_id() );
		$this->assertSame( 'failed', $reloaded->get_status() );
		$this->assert_failed_note_with_message( $reloaded, 'pi_canceled_with_error', "UPE payment failed: We're not able to process this payment. Please try again later." );
		$this->assertSame( 0, $cancellations, 'The order must not pass through cancelled.' );
	}

	/**
	 * @testdox A canceled SetupIntent without an error lands on order-received without a checkout redirect or notice.
	 *
	 * Client 11.1.0: with no setup error nothing throws (gw:2376), update_order_status_from_intent() cancels the order
	 * (os:400-402) and process_redirect_payment() returns without redirecting (gw:2406-2427).
	 */
	public function test_handle_wp_does_not_redirect_canceled_setup_intent_without_error(): void {
		$order                    = $this->create_order( '0.00' );
		$api_client               = new RedirectReturnApiClientStub();
		$api_client->setup_intent = array(
			'id'             => 'seti_canceled_no_redirect',
			'status'         => 'canceled',
			'customer'       => 'cus_setup',
			'payment_method' => 'pm_setup',
		);
		$this->sut                = $this->create_controller( true, $this->create_confirmation_service( $api_client ), $api_client );
		$this->set_setup_intent_return_request( $order, 'seti_canceled_no_redirect' );

		$this->sut->handle_wp();

		$this->assertSame( array(), wc_get_notices( 'error' ) );
		$this->assertSame( 'cancelled', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * @testdox A failure while confirming a return whose intent holds no money fails the order with the client note, adds the notice and returns to checkout with the cart kept.
	 * @dataProvider confirmation_failure_provider
	 *
	 * Client 11.1.0 process_redirect_payment(): any Exception inside the try (gw:2321-2427) reaches the catch (gw:2428-2455),
	 * which calls mark_payment_failed() with "UPE payment failed: <message>" and the fetched status (os:463-478, 2889-2895),
	 * adds the filtered notice and redirects to wc_get_checkout_url(). A PHP Error fatals on the client (catch Exception);
	 * native takes the same path and logs it whatever the logging setting. The notice for a non-API throwable is the
	 * generic message (decided divergence, monitor ruling 2026-10-04). The intent here is requires_action, which has moved
	 * no money; an intent that has is covered by test_handle_wp_keeps_the_order_when_confirmation_throws_after_money_moved().
	 *
	 * @param Throwable $failure          What the order payment lifecycle throws.
	 * @param bool      $logged_always    Whether the failure is logged with debug logging off.
	 */
	public function test_handle_wp_fails_order_and_returns_to_checkout_when_confirmation_throws( Throwable $failure, bool $logged_always ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'no' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$order   = $this->create_order();
		$product = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $product->get_id() );
		$api_client                  = new RedirectReturnApiClientStub();
		$api_client->payment_intent  = array_merge(
			$this->successful_payment_intent( $order, 'pi_confirm_throws', 'pm_confirm_throws' ),
			array(
				'status'      => 'requires_action',
				'next_action' => array( 'type' => 'use_stripe_sdk' ),
				'charges'     => array(
					'total_count' => 0,
					'data'        => array(),
				),
			)
		);
		$fee_details_note_controller = $this->createMock( WooPaymentsFeeDetailsNoteController::class );
		$fee_details_note_controller->method( 'apply_and_schedule_fee_details_with_lock' )->willThrowException( $failure );
		$logger = new RedirectReturnRecordingLogger();
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		$this->sut = $this->create_controller( true, $this->create_confirmation_service( $api_client, null, $fee_details_note_controller ), $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_confirm_throws' );

		$location = $this->handle_wp_expecting_redirect();
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertSame( wc_get_checkout_url(), $location );
		$this->assert_single_error_notice( "We're not able to process this payment. Please try again later." );
		$this->assertSame( 1, WC()->cart->get_cart_contents_count(), 'The cart must survive a failed redirect return.' );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'failed', $reloaded->get_status() );
		$this->assert_failed_note_with_message( $reloaded, 'pi_confirm_throws', 'UPE payment failed: ' . $failure->getMessage() );
		$this->assertSame( 'requires_action', $reloaded->get_meta( '_intention_status', true ) );
		$logged_classes = array_map( static fn( array $call ) => $call['context']['exception'] ?? '', $logger->error_calls );
		$this->assertSame( $logged_always ? array( get_class( $failure ) ) : array(), $logged_classes );
	}

	/**
	 * @testdox A $failure_label while confirming a $intent_status return leaves the pending order unfailed on order-received, with no notice and an always-on log line naming the error class.
	 * @dataProvider money_moved_confirmation_failure_provider
	 *
	 * Better than the client (monitor ruling 2026-10-05): client 11.1.0 fails the order for any Exception in the try
	 * (gw:2428-2455) and fatals on a PHP Error, so a succeeded, requires_capture or processing intent can end on a failed
	 * order with the money taken. Native leaves the order for the webhook or, when the platform could not deliver it, the
	 * failed-event fetch, as at checkout (review 34 F2, checkout ruling 4).
	 *
	 * @param string    $intent_status Fetched intent status.
	 * @param string    $failure_label What the order payment lifecycle throws, for the test name.
	 * @param Throwable $failure       What the order payment lifecycle throws.
	 */
	public function test_handle_wp_keeps_the_order_when_confirmation_throws_after_money_moved( string $intent_status, string $failure_label, Throwable $failure ): void {
		unset( $failure_label );
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'no' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$order                       = $this->create_order();
		$api_client                  = new RedirectReturnApiClientStub();
		$api_client->payment_intent  = array_merge(
			$this->successful_payment_intent( $order, 'pi_money_moved', 'pm_money_moved' ),
			array( 'status' => $intent_status )
		);
		$fee_details_note_controller = $this->createMock( WooPaymentsFeeDetailsNoteController::class );
		$fee_details_note_controller->method( 'apply_and_schedule_fee_details_with_lock' )->willThrowException( $failure );
		$logger = new RedirectReturnRecordingLogger();
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		$this->sut = $this->create_controller( true, $this->create_confirmation_service( $api_client, null, $fee_details_note_controller ), $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_money_moved' );

		try {
			$this->sut->handle_wp();
		} catch ( RedirectReturnRedirectIntercepted $redirect ) {
			$this->fail( 'The shopper must stay on order-received, not go to ' . $redirect->location );
		}
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'pending', $reloaded->get_status() );
		$this->assertSame( array(), wc_get_notices( 'error' ) );
		$failure_notes = array_filter(
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
			static fn( $note ): bool => false !== strpos( $note->content, '<strong>failed</strong>' )
		);
		$this->assertSame( array(), $failure_notes );
		$this->assertCount( 1, $logger->error_calls, 'One line, written with debug logging off.' );
		$this->assertSame( get_class( $failure ), $logger->error_calls[0]['context']['exception'] ?? '' );
		$this->assertSame( WooPaymentsLogger::SOURCE, $logger->error_calls[0]['context']['source'] ?? '' );
		$this->assertStringContainsString( 'raised ' . get_class( $failure ) . '.', (string) $logger->error_calls[0]['message'] );
		$this->assertStringNotContainsString( $failure->getMessage(), (string) wp_json_encode( $logger ) );
		foreach ( self::$provider_leak_fragments as $fragment ) {
			$this->assertStringNotContainsString( $fragment, (string) wp_json_encode( $logger ) );
		}
	}

	/**
	 * Intent statuses that have moved or held money, with what stops the confirmation.
	 *
	 * @return array<string,array{0:string,1:string,2:Throwable}>
	 */
	public function money_moved_confirmation_failure_provider(): array {
		return array(
			'succeeded, lifecycle failure'        => array( 'succeeded', 'lifecycle failure', new \RuntimeException( 'Order payment lifecycle write failed.' ) ),
			'requires_capture, lifecycle failure' => array( 'requires_capture', 'lifecycle failure', new \RuntimeException( 'Order payment lifecycle write failed.' ) ),
			'processing, lifecycle failure'       => array( 'processing', 'lifecycle failure', new \RuntimeException( 'Order payment lifecycle write failed.' ) ),
			'succeeded, PHP error'                => array( 'succeeded', 'PHP error', new \TypeError( 'Return value must be of type array, null returned' ) ),
			'succeeded, platform error'           => array( 'succeeded', 'platform error', self::make_provider_error() ),
		);
	}

	/**
	 * Failures the order payment lifecycle can raise while the return is confirmed.
	 *
	 * @return array<string,array{0:Throwable,1:bool}>
	 */
	public function confirmation_failure_provider(): array {
		return array(
			'lifecycle failure' => array( new \RuntimeException( 'Order payment lifecycle write failed.' ), false ),
			'PHP error'         => array( new \TypeError( 'Return value must be of type array, null returned' ), true ),
		);
	}

	/**
	 * @testdox A PHP error after the order already shows the payment keeps the shopper on order-received without failing the order.
	 *
	 * Decided (review 34 F2, checkout ruling 4): native does not fail an order a fresh locked read shows as paid or held.
	 * The client would fatal on the Error (catch Exception, gw:2428) and leave the order as it was.
	 */
	public function test_handle_wp_keeps_an_order_that_already_shows_the_payment_after_a_php_error(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'no' ) );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$order                      = $this->create_order( '50.00', 0, true );
		$api_client                 = new RedirectReturnApiClientStub();
		$api_client->payment_intent = $this->successful_payment_intent( $order, 'pi_paid_then_error', 'pm_paid_then_error' );
		$throw_once                 = static function (): void {
			throw new \TypeError( 'Third-party processing callback failed' );
		};
		add_action( 'woocommerce_order_status_processing', $throw_once );
		$logger = new RedirectReturnRecordingLogger();
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		$this->sut = $this->create_controller( true, $this->create_confirmation_service( $api_client ), $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_paid_then_error' );

		try {
			$this->sut->handle_wp();
		} finally {
			remove_action( 'woocommerce_order_status_processing', $throw_once );
		}
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertSame( 'processing', $reloaded->get_status() );
		$this->assertSame( array(), wc_get_notices( 'error' ) );
		$failure_notes = array_filter(
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
			static fn( $note ): bool => false !== strpos( $note->content, '<strong>failed</strong>' )
		);
		$this->assertSame( array(), $failure_notes );
		$this->assertSame( array( \TypeError::class ), array_map( static fn( array $call ) => $call['context']['exception'] ?? '', $logger->error_calls ) );
	}

	/**
	 * @testdox A PaymentIntent error fails the order once, with only the client note and none of the intent's details.
	 *
	 * Client 11.1.0 throws on the intent error before any order write (gw:2377-2382), so mark_payment_failed() is the
	 * only write: failed status, the note and `_intention_status` (gw:2444, os:463-478, 2889-2895).
	 */
	public function test_handle_wp_fails_payment_intent_error_once(): void {
		$order                      = $this->create_order();
		$api_client                 = new RedirectReturnApiClientStub();
		$api_client->payment_intent = array(
			'id'                 => 'pi_failed_once',
			'status'             => 'requires_payment_method',
			'currency'           => 'usd',
			'amount'             => 5000,
			'customer'           => 'cus_return',
			'payment_method'     => null,
			'metadata'           => array( 'order_id' => $order->get_id() ),
			'last_payment_error' => array(
				'type'    => 'card_error',
				'code'    => 'card_declined',
				'message' => 'Your card was declined.',
			),
		);
		$failed_transitions         = 0;
		$count_failed               = static function () use ( &$failed_transitions ): void {
			++$failed_transitions;
		};
		add_action( 'woocommerce_order_status_failed', $count_failed );
		$this->sut = $this->create_controller( true, $this->create_confirmation_service( $api_client ), $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_failed_once' );

		try {
			$this->assertSame( wc_get_checkout_url(), $this->handle_wp_expecting_redirect() );
		} finally {
			remove_action( 'woocommerce_order_status_failed', $count_failed );
		}
		$reloaded      = wc_get_order( $order->get_id() );
		$failure_notes = array_values(
			array_filter(
				array_map( static fn( $note ) => $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ),
				static fn( string $note ): bool => false !== strpos( $note, '<strong>failed</strong>' )
			)
		);

		$this->assertSame( 1, $failed_transitions );
		$this->assertCount( 1, $failure_notes, implode( ' | ', $failure_notes ) );
		$this->assert_failed_note_with_message( $reloaded, 'pi_failed_once', "UPE payment failed: We're not able to process this payment. Please try again later." );
		$this->assertSame( 'requires_payment_method', $reloaded->get_meta( '_intention_status', true ) );
		// The client throws before attach_intent_info_to_order() (gw:2399), so nothing from the intent but its status is written.
		$this->assertSame( array( '_intent_id', '_intention_status' ), array_values( array_filter( array_column( array_map( static fn( $meta ) => $meta->get_data(), $reloaded->get_meta_data() ), 'key' ), static fn( string $key ): bool => '_' === $key[0] && ! str_ends_with( $key, '_address_index' ) ) ) );
	}

	/**
	 * @testdox A PaymentIntent error while another request holds the order payment lock leaves the order alone but still returns to checkout.
	 *
	 * Client 11.1.0: mark_payment_failed() skips a locked order (os:463-466, 2747-2758), and the catch still adds the notice
	 * and redirects to checkout (gw:2447-2454). The intent error proves this payment failed, so a resubmit cannot pay twice.
	 */
	public function test_handle_wp_returns_payment_intent_error_to_checkout_while_the_order_is_locked(): void {
		$order                      = $this->create_order();
		$api_client                 = new RedirectReturnApiClientStub();
		$api_client->payment_intent = array(
			'id'                 => 'pi_error_locked',
			'status'             => 'requires_payment_method',
			'currency'           => 'usd',
			'amount'             => 5000,
			'customer'           => 'cus_return',
			'payment_method'     => null,
			'metadata'           => array( 'order_id' => $order->get_id() ),
			'last_payment_error' => array(
				'type'    => 'card_error',
				'code'    => 'card_declined',
				'message' => 'Your card was declined.',
			),
		);
		$store                      = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary                 = new WooPaymentsPersistenceVocabulary();
		$lock_token                 = $store->claim( $order, $vocabulary, 'pi_error_locked', 'payment status update' );
		$this->assertNotNull( $lock_token );
		$this->sut = $this->create_controller( true, $this->create_confirmation_service( $api_client ), $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_error_locked' );

		try {
			$location = $this->handle_wp_expecting_redirect();
		} finally {
			$store->release( $order, $vocabulary, $lock_token );
		}

		$this->assertSame( wc_get_checkout_url(), $location );
		$this->assert_single_error_notice( "We're not able to process this payment. Please try again later." );
		$this->assertSame( 'pending', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * @testdox A requires_action return without an error stays on order-received with the order pending, not failed.
	 *
	 * Client 11.1.0 marks the payment started (os:418-427) and does not fail it (gw:2406-2427). Its next-action redirect
	 * cannot complete on order-received, so native keeps the shopper there until the webhook settles the order (decided,
	 * monitor ruling on area 2a f18). Confirmation must not report the status as a failure to the redirect return.
	 */
	public function test_handle_wp_keeps_requires_action_return_pending_on_order_received(): void {
		$order                      = $this->create_order();
		$api_client                 = new RedirectReturnApiClientStub();
		$api_client->payment_intent = array(
			'id'             => 'pi_requires_action',
			'status'         => 'requires_action',
			'client_secret'  => 'pi_requires_action_secret_example',
			'currency'       => 'usd',
			'amount'         => 5000,
			'customer'       => 'cus_return',
			'payment_method' => 'pm_requires_action',
			'metadata'       => array( 'order_id' => $order->get_id() ),
			'next_action'    => array( 'type' => 'use_stripe_sdk' ),
		);
		$this->sut                  = $this->create_controller( true, $this->create_confirmation_service( $api_client ), $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_requires_action' );

		$this->sut->handle_wp();

		$this->assertSame( 'pending', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( array(), wc_get_notices( 'error' ) );
		$failure_notes = array_filter(
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
			static fn( $note ): bool => false !== strpos( $note->content, '<strong>failed</strong>' )
		);
		$this->assertSame( array(), $failure_notes );
	}

	/**
	 * Assert the request queued exactly one error notice with the given text.
	 *
	 * @param string $expected Expected notice text.
	 */
	private function assert_single_error_notice( string $expected ): void {
		$this->assertSame( array( $expected ), array_column( wc_get_notices( 'error' ), 'notice' ) );
	}

	/**
	 * Assert the order carries the client's payment-failure note ending with the given message (os:2106-2127).
	 *
	 * @param WC_Order $order     Order object.
	 * @param string   $intent_id Intent ID shown in the note.
	 * @param string   $message   Message appended to the note.
	 */
	private function assert_failed_note_with_message( WC_Order $order, string $intent_id, string $message ): void {
		$notes   = array_map( static fn( $note ) => $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$matches = array_filter(
			$notes,
			static fn( string $note ): bool => 0 === strpos( $note, 'A payment of ' . wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) . ' <strong>failed</strong> using WooPayments (' )
				&& false !== strpos( $note, $intent_id . '</' )
				&& str_ends_with( $note, '). ' . $message )
		);
		$this->assertCount( 1, $matches, 'Expected one failure note ending with "' . $message . '"; notes: ' . implode( ' | ', $notes ) );
	}

	/**
	 * @testdox Plugin-owned runtime registers no redirect-return hook.
	 */
	public function test_registers_no_hook_when_native_does_not_own_runtime(): void {
		$this->sut = $this->create_controller( false );

		$this->sut->register();

		$this->assertFalse( has_action( 'wp', array( $this->sut, 'handle_wp' ) ) );
	}

	/**
	 * @testdox Native runtime registers the redirect-return callback once on wp.
	 */
	public function test_registers_wp_hook_once_when_native_owns_runtime(): void {
		$this->sut = $this->create_controller( true );

		$this->sut->register();
		$this->sut->register();

		$this->assertSame( 10, has_action( 'wp', array( $this->sut, 'handle_wp' ) ) );
		$this->assertSame( 1, $this->count_registered_wp_callbacks() );
	}

	/**
	 * @testdox Zero-total setup-intent returns confirm without PaymentIntent metadata comparison.
	 */
	public function test_handle_wp_confirms_zero_total_setup_intent_without_metadata(): void {
		$order                    = $this->create_order( '0.00' );
		$api_client               = new RedirectReturnApiClientStub();
		$api_client->setup_intent = array(
			'id'             => 'seti_return',
			'status'         => 'succeeded',
			'customer'       => 'cus_setup',
			'payment_method' => 'pm_setup',
		);
		$confirmation_owner       = $this->create_confirmation_service( $api_client );
		$this->sut                = $this->create_controller( true, $confirmation_owner, $api_client );
		$this->set_setup_intent_return_request( $order, 'seti_return' );

		$this->sut->handle_wp();
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'completed', $reloaded->get_status() );
		$this->assertSame( 1, $api_client->setup_intent_reads );
		$this->assertSame( 'seti_return', $reloaded->get_meta( '_intent_id', true ) );
	}

	/**
	 * @testdox A zero-total return naming a SetupIntent the order does not carry is rejected before fetch: $_dataName.
	 *
	 * Authorized divergence from client 11.1.0, which skips this binding for zero-total orders (`gw:2302-2306`,
	 * pending woocommerce-payments#6575). Native writes the SetupIntent to `_intent_id` before the shopper leaves
	 * checkout, so a leaked `seti_` id cannot be redeemed against another shopper's order.
	 *
	 * @dataProvider unbound_setup_intent_provider
	 *
	 * @param string $order_intent_id SetupIntent the order carries, or '' for none.
	 */
	public function test_handle_wp_rejects_zero_total_setup_intent_not_bound_to_order( string $order_intent_id ): void {
		$order = $this->create_order( '0.00' );
		if ( '' !== $order_intent_id ) {
			$order->update_meta_data( '_intent_id', $order_intent_id );
			$order->save();
		}
		$api_client               = new RedirectReturnApiClientStub();
		$api_client->setup_intent = array(
			'id'             => 'seti_foreign',
			'status'         => 'succeeded',
			'customer'       => 'cus_foreign',
			'payment_method' => 'pm_foreign',
		);
		$logger                   = new RedirectReturnRecordingLogger();
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		$this->sut = $this->create_controller( true, $this->create_confirmation_service( $api_client ), $api_client );
		$this->set_setup_intent_return_request( $order, 'seti_foreign', false );

		$this->sut->handle_wp();
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 0, $api_client->setup_intent_reads, 'An unbound SetupIntent must not be fetched.' );
		$this->assertSame( 'pending', $reloaded->get_status() );
		$this->assertSame( $order_intent_id, $reloaded->get_meta( '_intent_id', true ) );
		$this->assertSame( '', $reloaded->get_meta( '_payment_method_id', true ) );
		$this->assertSame( '', $reloaded->get_meta( '_stripe_customer_id', true ) );
		$this->assertSame( array(), $reloaded->get_payment_tokens() );
		$this->assertSame(
			array( sprintf( 'Native WooPayments redirect intent seti_foreign did not match order %d.', $order->get_id() ) ),
			array_column( $logger->error_calls, 'message' )
		);
	}

	/**
	 * @testdox A zero-total return is rejected when a newer checkout attempt replaces the order's SetupIntent during the fetch.
	 */
	public function test_handle_wp_rejects_zero_total_setup_intent_replaced_during_fetch(): void {
		$order                                  = $this->create_order( '0.00' );
		$api_client                             = new RedirectReturnApiClientStub();
		$api_client->setup_intent               = array(
			'id'             => 'seti_old',
			'status'         => 'succeeded',
			'customer'       => 'cus_setup',
			'payment_method' => 'pm_old',
		);
		$api_client->before_setup_intent_return = static function () use ( $order ): void {
			$fresh_order = wc_get_order( $order->get_id() );
			if ( $fresh_order instanceof WC_Order ) {
				$fresh_order->update_meta_data( '_intent_id', 'seti_newer' );
				$fresh_order->save();
			}
		};
		$this->sut                              = $this->create_controller( true, $this->create_confirmation_service( $api_client ), $api_client );
		$this->set_setup_intent_return_request( $order, 'seti_old' );

		$this->sut->handle_wp();
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 1, $api_client->setup_intent_reads );
		$this->assertSame( 'pending', $reloaded->get_status() );
		$this->assertSame( 'seti_newer', $reloaded->get_meta( '_intent_id', true ) );
		$this->assertSame( '', $reloaded->get_meta( '_payment_method_id', true ) );
	}

	/**
	 * Zero-total orders whose stored intent is not the returned SetupIntent.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function unbound_setup_intent_provider(): array {
		return array(
			'order carries another SetupIntent' => array( 'seti_own' ),
			'order carries no intent'           => array( '' ),
		);
	}

	/**
	 * @testdox A zero-total return is rejected when the SetupIntent belongs to another customer: $_dataName.
	 *
	 * Authorized divergence from client 11.1.0 (`gw:2302-2306`, `gw:2355-2357` fetch the SetupIntent without any
	 * order binding). Same rule as add_payment_method(): reject only a real mismatch between two known customer IDs.
	 *
	 * @dataProvider foreign_setup_intent_customer_provider
	 *
	 * @param string $order_customer `_stripe_customer_id` on the order, or '' for none.
	 * @param string $user_customer  Stored customer ID of the order's user, or '' for none.
	 */
	public function test_handle_wp_rejects_zero_total_setup_intent_of_another_customer( string $order_customer, string $user_customer ): void {
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order   = $this->create_order( '0.00', $user_id );
		if ( '' !== $order_customer ) {
			$order->update_meta_data( '_stripe_customer_id', $order_customer );
			$order->save();
		}
		$api_client               = new RedirectReturnApiClientStub();
		$api_client->setup_intent = array(
			'id'             => 'seti_bound',
			'status'         => 'succeeded',
			'customer'       => 'cus_foreign',
			'payment_method' => 'pm_foreign',
		);
		$logger                   = new RedirectReturnRecordingLogger();
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		$this->sut = $this->create_controller( true, $this->create_confirmation_service( $api_client ), $api_client, null, $this->create_customer_service( $user_id, $user_customer ) );
		$this->set_setup_intent_return_request( $order, 'seti_bound' );

		$location = $this->handle_wp_expecting_redirect();
		$reloaded = wc_get_order( $order->get_id() );

		// The SetupIntent belongs to another customer, so it gets the client's order-mismatch outcome (gw:2428-2456).
		$this->assertSame( add_query_arg( 'upe_process_redirect_order_id_mismatched', 'yes', wc_get_checkout_url() ), $location );
		$this->assert_single_error_notice( "We're not able to process this payment due to the order ID mismatch. Please try again later." );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 1, $api_client->setup_intent_reads );
		$this->assertSame( 'pending', $reloaded->get_status() );
		$this->assertSame( '', $reloaded->get_meta( '_payment_method_id', true ) );
		$this->assertSame( $order_customer, $reloaded->get_meta( '_stripe_customer_id', true ) );
		$this->assertSame( array(), $reloaded->get_payment_tokens() );
		$this->assertSame(
			array( sprintf( 'Native WooPayments redirect intent seti_bound did not match order %d.', $order->get_id() ) ),
			array_column( $logger->error_calls, 'message' )
		);
	}

	/**
	 * Known order customers that differ from the SetupIntent's customer.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function foreign_setup_intent_customer_provider(): array {
		return array(
			'order customer differs'                    => array( 'cus_order', '' ),
			'order user customer differs'               => array( '', 'cus_user' ),
			'order customer differs, user one does not' => array( 'cus_order', 'cus_foreign' ),
		);
	}

	/**
	 * @testdox A zero-total return is confirmed when the SetupIntent customer is the order's or is not known: $_dataName.
	 *
	 * @dataProvider own_setup_intent_customer_provider
	 *
	 * @param string $order_customer `_stripe_customer_id` on the order, or '' for none.
	 * @param string $user_customer  Stored customer ID of the order's user, or '' for none.
	 */
	public function test_handle_wp_confirms_zero_total_setup_intent_of_the_order_customer( string $order_customer, string $user_customer ): void {
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order   = $this->create_order( '0.00', $user_id );
		if ( '' !== $order_customer ) {
			$order->update_meta_data( '_stripe_customer_id', $order_customer );
			$order->save();
		}
		$api_client               = new RedirectReturnApiClientStub();
		$api_client->setup_intent = array(
			'id'             => 'seti_own',
			'status'         => 'succeeded',
			'customer'       => 'cus_own',
			'payment_method' => 'pm_own',
		);
		$this->sut                = $this->create_controller( true, $this->create_confirmation_service( $api_client ), $api_client, null, $this->create_customer_service( $user_id, $user_customer ) );
		$this->set_setup_intent_return_request( $order, 'seti_own' );

		$this->sut->handle_wp();
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'completed', $reloaded->get_status() );
		$this->assertSame( 'pm_own', $reloaded->get_meta( '_payment_method_id', true ) );
		$this->assertSame( 'cus_own', $reloaded->get_meta( '_stripe_customer_id', true ) );
	}

	/**
	 * Order customers the SetupIntent's customer may be confirmed against.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function own_setup_intent_customer_provider(): array {
		return array(
			'order customer matches'                   => array( 'cus_own', '' ),
			'order customer matches, user one differs' => array( 'cus_own', 'cus_user' ),
			'order user customer matches'              => array( '', 'cus_own' ),
			'no customer known'                        => array( '', '' ),
		);
	}

	/**
	 * @testdox Zero-total setup-intent returns fail the order when the SetupIntent carries a setup error ($status).
	 *
	 * Source: client 11.1.0 `process_redirect_payment()`. For a zero-total order it reads `get_last_setup_error()`
	 * (`gw:2361-2374`) and throws `upe_payment_intent_error` (`gw:2376-2382`). The catch calls
	 * `mark_payment_failed()` with the intent's status and an empty charge ID (`gw:2428-2446`), which fails the
	 * order and stores the status (`os:463-478`, `os:2889-2895`). The AJAX path keeps the same intent pending.
	 * `mark_payment_failed()` writes the failed note (`os:2106-2130`) ending "UPE payment failed: <exception message>".
	 *
	 * @dataProvider setup_error_status_provider
	 *
	 * @param string $status SetupIntent status.
	 */
	public function test_handle_wp_fails_zero_total_setup_intent_with_setup_error( string $status ): void {
		$order                    = $this->create_order( '0.00' );
		$api_client               = new RedirectReturnApiClientStub();
		$api_client->setup_intent = array(
			'id'               => 'seti_return_error',
			'status'           => $status,
			'customer'         => 'cus_setup',
			'payment_method'   => 'pm_setup',
			'last_setup_error' => array(
				'type'    => 'invalid_request_error',
				'code'    => 'setup_intent_authentication_failure',
				'message' => 'We are unable to authenticate your payment method.',
			),
		);
		$confirmation_owner       = $this->create_confirmation_service( $api_client );
		$this->sut                = $this->create_controller( true, $confirmation_owner, $api_client );
		$this->set_setup_intent_return_request( $order, 'seti_return_error' );

		$location = $this->handle_wp_expecting_redirect();
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertSame( wc_get_checkout_url(), $location );
		$this->assert_single_error_notice( "We're not able to process this payment. Please try again later." );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'failed', $reloaded->get_status() );
		$this->assertSame( 1, $api_client->setup_intent_reads );
		$this->assertSame( $status, $reloaded->get_meta( '_intention_status', true ) );
		$notes = array_map(
			static fn( $note ) => $note->content,
			wc_get_order_notes( array( 'order_id' => $reloaded->get_id() ) )
		);
		$this->assertContains(
			'A payment of ' . wc_price( 0, array( 'currency' => $reloaded->get_currency() ) ) . " <strong>failed</strong> using WooPayments (<code>seti_return_error</code>). UPE payment failed: We're not able to process this payment. Please try again later.",
			$notes
		);
	}

	/**
	 * SetupIntent statuses the client's redirect return fails when a setup error is present.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function setup_error_status_provider(): array {
		return array(
			'requires_payment_method' => array( 'requires_payment_method' ),
			'requires_action'         => array( 'requires_action' ),
			'canceled'                => array( 'canceled' ),
		);
	}

	/**
	 * @testdox Zero-total setup-intent returns cancel the order with the cancellation note for a canceled SetupIntent without a setup error.
	 *
	 * Source: client 11.1.0 `process_redirect_payment()`. With no `get_last_setup_error()` (`gw:2361-2376`) it calls
	 * `update_order_status_from_intent()` (`gw:2406`), which sends `canceled` to `mark_payment_capture_cancelled()`
	 * (`os:400-402`): the cancellation note (`os:2315-2330`, ID in a code element per `utils:1056-1058`) and a
	 * cancelled order (`os:1533-1554`).
	 */
	public function test_handle_wp_cancels_zero_total_order_for_canceled_setup_intent(): void {
		$order                    = $this->create_order( '0.00' );
		$api_client               = new RedirectReturnApiClientStub();
		$api_client->setup_intent = array(
			'id'             => 'seti_return_canceled',
			'status'         => 'canceled',
			'customer'       => 'cus_setup',
			'payment_method' => 'pm_setup',
		);
		$confirmation_owner       = $this->create_confirmation_service( $api_client );
		$this->sut                = $this->create_controller( true, $confirmation_owner, $api_client );
		$this->set_setup_intent_return_request( $order, 'seti_return_canceled' );

		$this->sut->handle_wp();
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'cancelled', $reloaded->get_status() );
		$notes = array_map(
			static fn( $note ) => $note->content,
			wc_get_order_notes( array( 'order_id' => $reloaded->get_id() ) )
		);
		$this->assertContains( 'Payment authorization was successfully <strong>cancelled</strong> (<code>seti_return_canceled</code>).', $notes );
	}

	/**
	 * @testdox A zero-total recurring redirect return exposes card identity before status and payment-complete observers.
	 */
	public function test_handle_wp_redirects_zero_total_recurring_setup_intent_through_the_pre_lifecycle_identity_owner(): void {
		$user_id      = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order        = $this->create_order( '0.00', $user_id );
		$subscription = $this->create_order( '0.00', $user_id );
		$order->set_payment_method_title( 'Card' );
		$order->save();
		$subscription->set_payment_method_title( 'Card' );
		$subscription->save();
		$details                  = array(
			'id'   => 'pm_redirect_card',
			'type' => 'card',
			'card' => array(
				'brand'     => 'visa',
				'network'   => 'visa',
				'funding'   => 'credit',
				'last4'     => '4242',
				'exp_month' => 12,
				'exp_year'  => 2030,
			),
		);
		$api_client               = new RedirectReturnApiClientStub();
		$api_client->setup_intent = array(
			'id'             => 'seti_redirect_card',
			'status'         => 'succeeded',
			'customer'       => 'cus_redirect_card',
			'payment_method' => 'pm_redirect_card',
		);
		$details_reads            = new \ArrayObject( array( 0 ) );
		$token_service            = $this->create_token_service( array( 'pm_redirect_card' => $details ), $details_reads );
		$confirmation_owner       = $this->create_confirmation_service( $api_client, $token_service );
		$this->sut                = $this->create_controller( true, $confirmation_owner, $api_client, $token_service );
		$observed                 = array();
		WooCommerceSubscriptionsDoubles::load_order_detector();
		WooCommerceSubscriptionsDoubles::load_order_subscriptions();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ] = array( $order->get_id() => array( 'parent' => array( $subscription->get_id() ) ) );
		$capture_observer_state    = static function ( int $order_id, string $hook ) use ( $order, $subscription, &$observed ): void {
			if ( $order->get_id() !== $order_id ) {
				return;
			}

			$reloaded_order        = wc_get_order( $order_id );
			$reloaded_subscription = wc_get_order( $subscription->get_id() );
			if ( ! $reloaded_order instanceof WC_Order || ! $reloaded_subscription instanceof WC_Order ) {
				return;
			}

			$order_token_ids = $reloaded_order->get_payment_tokens();
			$active_token_id = end( $order_token_ids );
			$active_token    = false === $active_token_id ? null : \WC_Payment_Tokens::get( (int) $active_token_id );
			$observed[]      = array(
				'hook'                        => $hook,
				'order_gateway'               => $reloaded_order->get_payment_method(),
				'order_title'                 => $reloaded_order->get_payment_method_title(),
				'order_last4'                 => $reloaded_order->get_meta( 'last4', true ),
				'order_card_brand'            => $reloaded_order->get_meta( '_card_brand', true ),
				'order_details'               => json_decode( (string) $reloaded_order->get_meta( '_wcpay_payment_method_details', true ), true ),
				'order_payment_method'        => $reloaded_order->get_meta( '_payment_method_id', true ),
				'order_customer'              => $reloaded_order->get_meta( '_stripe_customer_id', true ),
				'order_token_ids'             => $order_token_ids,
				'active_token_id'             => $active_token instanceof \WC_Payment_Token ? $active_token->get_id() : 0,
				'active_token_provider'       => $active_token instanceof \WC_Payment_Token ? $active_token->get_token() : '',
				'subscription_gateway'        => $reloaded_subscription->get_payment_method(),
				'subscription_title'          => $reloaded_subscription->get_payment_method_title(),
				'subscription_token_ids'      => $reloaded_subscription->get_payment_tokens(),
				'subscription_payment_method' => $reloaded_subscription->get_meta( '_payment_method_id', true ),
				'subscription_customer'       => $reloaded_subscription->get_meta( '_stripe_customer_id', true ),
				'subscription_last4'          => $reloaded_subscription->get_meta( 'last4', true ),
				'subscription_card_brand'     => $reloaded_subscription->get_meta( '_card_brand', true ),
				'subscription_details'        => $reloaded_subscription->get_meta( '_wcpay_payment_method_details', true ),
			);
		};
		$status_observer           = static function ( int $order_id ) use ( $capture_observer_state ): void {
			$capture_observer_state( $order_id, 'status' );
		};
		$payment_complete_observer = static function ( int $order_id ) use ( $capture_observer_state ): void {
			$capture_observer_state( $order_id, 'payment_complete' );
		};
		add_action( 'woocommerce_order_status_completed', $status_observer, 1 );
		add_action( 'woocommerce_payment_complete', $payment_complete_observer, 1 );
		$this->set_setup_intent_return_request( $order, 'seti_redirect_card' );

		try {
			$this->sut->handle_wp();
		} finally {
			remove_action( 'woocommerce_order_status_completed', $status_observer, 1 );
			remove_action( 'woocommerce_payment_complete', $payment_complete_observer, 1 );
		}

		$order        = wc_get_order( $order->get_id() );
		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertInstanceOf( WC_Order::class, $subscription );
		$token_ids       = $order->get_payment_tokens();
		$active_token    = $token_service->get_active_token_for_order( $order );
		$active_token_id = $active_token instanceof \WC_Payment_Token ? $active_token->get_id() : 0;
		$this->assertInstanceOf( \WC_Payment_Token::class, $active_token );
		$this->assertSame( 'pm_redirect_card', $active_token->get_token() );
		$this->assertSame(
			array(
				array(
					'hook'                        => 'status',
					'order_gateway'               => WooPaymentsPersistenceVocabulary::GATEWAY_ID,
					'order_title'                 => 'Visa credit card',
					'order_last4'                 => '4242',
					'order_card_brand'            => 'visa',
					'order_details'               => array(
						'type' => 'card',
						'card' => $details['card'],
					),
					'order_payment_method'        => 'pm_redirect_card',
					'order_customer'              => 'cus_redirect_card',
					'order_token_ids'             => $token_ids,
					'active_token_id'             => $active_token_id,
					'active_token_provider'       => 'pm_redirect_card',
					'subscription_gateway'        => WooPaymentsPersistenceVocabulary::GATEWAY_ID,
					'subscription_title'          => 'Visa credit card',
					'subscription_token_ids'      => $token_ids,
					'subscription_payment_method' => 'pm_redirect_card',
					'subscription_customer'       => 'cus_redirect_card',
					'subscription_last4'          => '',
					'subscription_card_brand'     => '',
					'subscription_details'        => '',
				),
				array(
					'hook'                        => 'payment_complete',
					'order_gateway'               => WooPaymentsPersistenceVocabulary::GATEWAY_ID,
					'order_title'                 => 'Visa credit card',
					'order_last4'                 => '4242',
					'order_card_brand'            => 'visa',
					'order_details'               => array(
						'type' => 'card',
						'card' => $details['card'],
					),
					'order_payment_method'        => 'pm_redirect_card',
					'order_customer'              => 'cus_redirect_card',
					'order_token_ids'             => $token_ids,
					'active_token_id'             => $active_token_id,
					'active_token_provider'       => 'pm_redirect_card',
					'subscription_gateway'        => WooPaymentsPersistenceVocabulary::GATEWAY_ID,
					'subscription_title'          => 'Visa credit card',
					'subscription_token_ids'      => $token_ids,
					'subscription_payment_method' => 'pm_redirect_card',
					'subscription_customer'       => 'cus_redirect_card',
					'subscription_last4'          => '',
					'subscription_card_brand'     => '',
					'subscription_details'        => '',
				),
			),
			$observed
		);
		$this->assertSame( 1, $api_client->setup_intent_reads );
		$this->assertSame( 1, $details_reads[0] );
		$this->assertSame( 'completed', $order->get_status() );
		$this->assertCount( 1, $token_ids );
	}

	/**
	 * @testdox A successful setup-intent return on the payment-methods page adds a notice and clears the current user's cached methods.
	 */
	public function test_handle_wp_maps_successful_account_setup_intent_return(): void {
		$user_id            = self::factory()->user->create( array( 'role' => 'customer' ) );
		$api_client         = new RedirectReturnApiClientStub();
		$confirmation_owner = $this->createMock( WooPaymentsIntentConfirmationService::class );
		$confirmation_owner->expects( $this->never() )->method( 'confirm_fetched_intent_for_order' );
		$token_service = new RedirectReturnTokenServiceStub();
		$this->sut     = $this->create_controller( true, $confirmation_owner, $api_client, $token_service );
		wp_set_current_user( $user_id );
		$this->set_payment_methods_page_context();
		$_GET = array(
			'setup_intent'               => 'seti_account',
			'setup_intent_client_secret' => 'seti_account_secret_example',
			'redirect_status'            => 'succeeded',
		);

		$this->sut->handle_wp();

		$success_notices = wc_get_notices( 'success' );
		$this->assertCount( 1, $success_notices );
		$this->assertSame( 'Payment method successfully added.', $success_notices[0]['notice'] );
		$this->assertSame( array( $user_id ), $token_service->cleared_user_ids );
		$this->assertSame( 0, $api_client->payment_intent_reads );
		$this->assertSame( 0, $api_client->setup_intent_reads );
	}

	/**
	 * @testdox A payment-methods page return without a successful complete SetupIntent is a no-op and never falls through to order processing.
	 *
	 * @dataProvider invalid_account_setup_intent_return_provider
	 *
	 * @param array<string,string> $setup_return SetupIntent return query values.
	 */
	public function test_handle_wp_ignores_invalid_account_setup_intent_return_without_order_processing( array $setup_return ): void {
		$order                      = $this->create_order();
		$api_client                 = new RedirectReturnApiClientStub();
		$api_client->payment_intent = $this->successful_payment_intent( $order, 'pi_account_fallthrough', 'pm_account' );
		$confirmation_owner         = $this->createMock( WooPaymentsIntentConfirmationService::class );
		$confirmation_owner->expects( $this->never() )->method( 'confirm_fetched_intent_for_order' );
		$token_service = new RedirectReturnTokenServiceStub();
		$this->sut     = $this->create_controller( true, $confirmation_owner, $api_client, $token_service );
		$this->set_payment_methods_page_context();
		$this->set_payment_intent_return_request( $order, 'pi_account_fallthrough' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test fixture extends the redirect query.
		$_GET = array_merge( $_GET, $setup_return );

		$this->sut->handle_wp();

		$this->assertSame( 0, $api_client->payment_intent_reads );
		$this->assertSame( 0, $api_client->setup_intent_reads );
		$this->assertSame( array(), $token_service->cleared_user_ids );
		$this->assertSame( array(), wc_get_notices( 'success' ) );
	}

	/**
	 * Provide incomplete and non-succeeded account SetupIntent returns.
	 *
	 * @return array<string,array{setup_return:array<string,string>}>
	 */
	public function invalid_account_setup_intent_return_provider(): array {
		return array(
			'missing setup intent'        => array(
				'setup_return' => array(
					'setup_intent'               => '',
					'setup_intent_client_secret' => 'seti_account_secret_example',
					'redirect_status'            => 'succeeded',
				),
			),
			'missing setup client secret' => array(
				'setup_return' => array(
					'setup_intent'               => 'seti_account',
					'setup_intent_client_secret' => '',
					'redirect_status'            => 'succeeded',
				),
			),
			'missing redirect status'     => array(
				'setup_return' => array(
					'setup_intent'               => 'seti_account',
					'setup_intent_client_secret' => 'seti_account_secret_example',
					'redirect_status'            => '',
				),
			),
			'non-succeeded status'        => array(
				'setup_return' => array(
					'setup_intent'               => 'seti_account',
					'setup_intent_client_secret' => 'seti_account_secret_example',
					'redirect_status'            => 'failed',
				),
			),
		);
	}

	/**
	 * Create the redirect-return controller.
	 *
	 * @param bool                                      $native_owner       Whether native owns runtime.
	 * @param WooPaymentsIntentConfirmationService|null $confirmation_owner Intent confirmation service.
	 * @param WooPaymentsApiClient|null                 $api_client         API client.
	 * @param WooPaymentsTokenService|null              $token_service      Token service.
	 * @param WooPaymentsCustomerService|null           $customer_service   Customer service.
	 * @param OrderPaymentLifecycleService|null         $lifecycle_service  Order payment lifecycle service, or the container's.
	 * @return WooPaymentsRedirectReturnController
	 */
	private function create_controller( bool $native_owner, ?WooPaymentsIntentConfirmationService $confirmation_owner = null, ?WooPaymentsApiClient $api_client = null, ?WooPaymentsTokenService $token_service = null, ?WooPaymentsCustomerService $customer_service = null, ?OrderPaymentLifecycleService $lifecycle_service = null ): WooPaymentsRedirectReturnController {
		$arbiter = $this->createMock( WooPaymentsRuntimeArbiter::class );
		$arbiter->method( 'is_builtin_owner' )->willReturn( $native_owner );

		$controller = new WooPaymentsRedirectReturnController();
		$controller->init(
			$arbiter,
			$confirmation_owner ?? $this->createMock( WooPaymentsIntentConfirmationService::class ),
			$api_client ?? new RedirectReturnApiClientStub(),
			$token_service ?? $this->createMock( WooPaymentsTokenService::class ),
			$customer_service ?? $this->createMock( WooPaymentsCustomerService::class ),
			$lifecycle_service ?? wc_get_container()->get( OrderPaymentLifecycleService::class ),
			new WooPaymentsOrderNoteService()
		);

		return $controller;
	}

	/**
	 * Create a customer service that knows one user's stored customer ID.
	 *
	 * @param int    $user_id     User ID.
	 * @param string $customer_id Stored customer ID, or '' for none.
	 * @return WooPaymentsCustomerService
	 */
	private function create_customer_service( int $user_id, string $customer_id ): WooPaymentsCustomerService {
		$customer_service = $this->createMock( WooPaymentsCustomerService::class );
		$customer_service->method( 'get_persisted_customer_id_by_user_id' )
			->willReturnCallback( static fn( ?int $requested_user_id ): ?string => $user_id === $requested_user_id && '' !== $customer_id ? $customer_id : null );

		return $customer_service;
	}

	/**
	 * Set My Account payment-methods page context.
	 */
	private function set_payment_methods_page_context(): void {
		$myaccount_page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		update_option( 'woocommerce_myaccount_page_id', $myaccount_page_id );
		$this->go_to( get_permalink( $myaccount_page_id ) );

		global $wp;
		$wp->query_vars['payment-methods'] = '';

		$this->assertTrue( is_payment_methods_page(), 'Test fixture should establish the My Account payment-methods page.' );
	}

	/**
	 * Create the intent confirmation service.
	 *
	 * @param WooPaymentsApiClient                     $api_client                  API client.
	 * @param WooPaymentsTokenService|null             $token_service               Token service.
	 * @param WooPaymentsFeeDetailsNoteController|null $fee_details_note_controller Fee details note controller the confirmation applies events with.
	 * @return WooPaymentsIntentConfirmationService
	 */
	private function create_confirmation_service( WooPaymentsApiClient $api_client, ?WooPaymentsTokenService $token_service = null, ?WooPaymentsFeeDetailsNoteController $fee_details_note_controller = null ): WooPaymentsIntentConfirmationService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_mode', 'get_account_country' ) )
			->getMock();
		$account_service->method( 'get_mode' )->willReturn( 'test' );
		$account_service->method( 'get_account_country' )->willReturn( 'US' );
		$token_service  = $token_service ?? $this->create_token_service();
		$effect_applier = new WooPaymentsOrderEffectApplier();
		$effect_applier->init(
			$token_service,
			new WooPaymentsOrderDataService(),
			$account_service,
			new WooPaymentsOrderNoteService(),
			new WooPaymentsPaymentMethodRegistry(),
			wc_get_container()->get( WooPaymentsActionSchedulerService::class )
		);

		$service = new WooPaymentsIntentConfirmationService();
		$service->init(
			$api_client,
			$fee_details_note_controller ?? wc_get_container()->get( WooPaymentsFeeDetailsNoteController::class ),
			$token_service,
			$account_service,
			$effect_applier,
			wc_get_container()->get( WooPaymentsIntentRequestBuilder::class )
		);

		return $service;
	}

	/**
	 * Create a token service test double.
	 *
	 * @param array<string,array<string,mixed>> $payment_method_details Payment method details.
	 * @param \ArrayObject<int,int>|null        $details_reads          Optional payment-method detail read counter.
	 * @return WooPaymentsTokenService
	 */
	private function create_token_service( array $payment_method_details = array(), ?\ArrayObject $details_reads = null ): WooPaymentsTokenService {
		$details_service = new class( $payment_method_details, $details_reads ) extends WooPaymentsPaymentMethodDetailsService {
			/** @var array<string,array<string,mixed>> */
			private array $details;

			/** @var \ArrayObject<int,int>|null */
			private ?\ArrayObject $details_reads;

			/**
			 * @param array<string,array<string,mixed>> $details Payment method details.
			 * @param \ArrayObject<int,int>|null        $details_reads Optional payment-method detail read counter.
			 */
			public function __construct( array $details, ?\ArrayObject $details_reads ) {
				$this->details       = $details;
				$this->details_reads = $details_reads;
			}

			/**
			 * @param string $payment_method_id Payment method ID.
			 * @return array<string,mixed>
			 */
			public function get_payment_method_details( string $payment_method_id ): array {
				if ( $this->details_reads instanceof \ArrayObject ) {
					++$this->details_reads[0];
				}

				return $this->details[ $payment_method_id ] ?? array();
			}
		};
		$token_service   = new WooPaymentsTokenService();
		$token_service->init( $details_service, new StaticWooPaymentsRuntimeArbiter( true ), wc_get_container()->get( WooPaymentsApiClient::class ), wc_get_container()->get( WooPaymentsCustomerService::class ), wc_get_container()->get( WooPaymentsAccountService::class ), wc_get_container()->get( WooPaymentsOrderDataService::class ) );

		return $token_service;
	}

	/**
	 * Create a WooPayments order.
	 *
	 * @param string $total       Order total.
	 * @param int    $customer_id Customer ID.
	 * @param bool   $with_item   Whether to add a processing-requiring item.
	 * @return WC_Order
	 */
	private function create_order( string $total = '50.00', int $customer_id = 0, bool $with_item = false ): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_customer_id( $customer_id );
		$order->set_currency( 'USD' );
		if ( $with_item ) {
			$product = \WC_Helper_Product::create_simple_product();
			$product->set_regular_price( $total );
			$product->save();
			$order->add_product( $product );
			$order->calculate_totals();
		} else {
			$order->set_total( $total );
		}
		$order->save();

		return $order;
	}

	/**
	 * Build a successful PaymentIntent response.
	 *
	 * @param WC_Order $order             Order object.
	 * @param string   $intent_id         Intent ID.
	 * @param string   $payment_method_id Payment method ID.
	 * @return array<string,mixed>
	 */
	private function successful_payment_intent( WC_Order $order, string $intent_id, string $payment_method_id ): array {
		return array(
			'id'             => $intent_id,
			'status'         => 'succeeded',
			'currency'       => 'usd',
			'amount'         => 5000,
			'customer'       => 'cus_return',
			'payment_method' => $payment_method_id,
			'metadata'       => array( 'order_id' => $order->get_id() ),
			'charges'        => array(
				'total_count' => 1,
				'data'        => array(
					array(
						'id'                     => 'ch_return',
						'payment_method'         => $payment_method_id,
						'payment_method_details' => array(
							'type' => 'card',
							'card' => array(
								'brand'   => 'visa',
								'funding' => 'credit',
								'last4'   => '4242',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Build a successful redirect-method PaymentIntent response.
	 *
	 * @param WC_Order $order     Order object.
	 * @param string   $intent_id Intent ID.
	 * @param string   $method    Split gateway payment method ID.
	 * @param string   $charge_id Provider charge ID.
	 * @return array<string,mixed>
	 */
	private function redirect_method_payment_intent( WC_Order $order, string $intent_id, string $method, string $charge_id ): array {
		return array(
			'id'             => $intent_id,
			'status'         => 'succeeded',
			'currency'       => strtolower( (string) $order->get_currency() ),
			'amount'         => 5000,
			'customer'       => 'cus_return',
			'payment_method' => 'pm_' . $method,
			'metadata'       => array( 'order_id' => $order->get_id() ),
			'charges'        => array(
				'total_count' => 1,
				'data'        => array(
					array(
						'id'                     => $charge_id,
						'payment_method'         => 'pm_' . $method,
						'payment_method_details' => array(
							'type' => $method,
						),
					),
				),
			),
		);
	}

	/**
	 * Set a PaymentIntent redirect-return request.
	 *
	 * @param WC_Order $order               Order object.
	 * @param string   $intent_id           Intent ID.
	 * @param bool     $save_payment_method Whether saving was requested.
	 */
	private function set_payment_intent_return_request( WC_Order $order, string $intent_id, bool $save_payment_method = false ): void {
		if ( 0.0 < (float) $order->get_total() && '' === (string) $order->get_meta( '_intent_id', true ) ) {
			$order->update_meta_data( '_intent_id', $intent_id );
			$order->save();
		}

		$this->set_order_received_context( $order );
		$_GET = array(
			'wc_payment_method'            => WooPaymentsPersistenceVocabulary::GATEWAY_ID,
			'_wpnonce'                     => wp_create_nonce( 'wcpay_process_redirect_order_nonce' ),
			'payment_intent'               => $intent_id,
			'payment_intent_client_secret' => $intent_id . '_secret_example',
			'key'                          => $order->get_order_key(),
		);
		if ( $save_payment_method ) {
			$_GET['save_payment_method'] = 'yes';
		}
	}

	/**
	 * Set a SetupIntent redirect-return request.
	 *
	 * Checkout writes the SetupIntent to `_intent_id` before the shopper is redirected, so the request binds it by default.
	 *
	 * @param WC_Order $order       Order object.
	 * @param string   $intent_id   Intent ID.
	 * @param bool     $bind_intent Whether to store the intent on an order that carries none.
	 */
	private function set_setup_intent_return_request( WC_Order $order, string $intent_id, bool $bind_intent = true ): void {
		if ( $bind_intent && '' === (string) $order->get_meta( '_intent_id', true ) ) {
			$order->update_meta_data( '_intent_id', $intent_id );
			$order->save();
		}

		$this->set_order_received_context( $order );
		$_GET = array(
			'wc_payment_method'          => WooPaymentsPersistenceVocabulary::GATEWAY_ID,
			'_wpnonce'                   => wp_create_nonce( 'wcpay_process_redirect_order_nonce' ),
			'setup_intent'               => $intent_id,
			'setup_intent_client_secret' => $intent_id . '_secret_example',
			'key'                        => $order->get_order_key(),
		);
	}

	/**
	 * Set order-received query context.
	 *
	 * @param WC_Order $order Order object.
	 */
	private function set_order_received_context( WC_Order $order ): void {
		global $wp;
		$wp->query_vars['order-received'] = $order->get_id();
		set_query_var( 'order-received', $order->get_id() );
		add_filter( 'woocommerce_is_order_received_page', '__return_true' );
	}

	/**
	 * Update authoritative order intent storage without WooCommerce cache invalidation.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $intent_id Replacement intent ID.
	 * @return bool
	 */
	private function update_authoritative_order_intent_without_cache_invalidation( int $order_id, string $intent_id ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_value,WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Raw meta mutation intentionally emulates another request without WooCommerce cache invalidation.
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$updated = $wpdb->update(
				$wpdb->prefix . 'wc_orders_meta',
				array( 'meta_value' => $intent_id ),
				array(
					'order_id' => $order_id,
					'meta_key' => '_intent_id',
				),
				array( '%s' ),
				array( '%d', '%s' )
			);
		} else {
			$updated = $wpdb->update(
				$wpdb->postmeta,
				array( 'meta_value' => $intent_id ),
				array(
					'post_id'  => $order_id,
					'meta_key' => '_intent_id',
				),
				array( '%s' ),
				array( '%d', '%s' )
			);
		}
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_value,WordPress.DB.SlowDBQuery.slow_db_query_meta_key

		return 1 === $updated;
	}

	/**
	 * Count registrations of this controller on wp.
	 *
	 * @return int
	 */
	private function count_registered_wp_callbacks(): int {
		global $wp_filter;
		$count = 0;
		foreach ( $wp_filter['wp']->callbacks[10] ?? array() as $callback ) {
			if ( array( $this->sut, 'handle_wp' ) === ( $callback['function'] ?? null ) ) {
				++$count;
			}
		}

		return $count;
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound,Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName,Squiz.Commenting.FunctionComment.Missing -- Focused test doubles are local to this controller test.

/**
 * API client stub for redirect-return tests.
 */
class RedirectReturnApiClientStub extends WooPaymentsApiClient {
	/** @var array<string,mixed> */
	public array $payment_intent = array();

	/** @var array<string,mixed> */
	public array $setup_intent = array();

	/** @var Throwable|null */
	public ?Throwable $exception = null;

	/** @var int */
	public int $payment_intent_reads = 0;

	/** @var int */
	public int $setup_intent_reads = 0;

	/** @var string */
	public string $last_payment_intent_id = '';

	/** @var string */
	public string $last_setup_intent_id = '';

	/** @var callable|null */
	public $before_payment_intent_return = null;

	/** @var callable|null */
	public $before_setup_intent_return = null;

	/**
	 * @param string $intent_id Intent ID.
	 * @return array<string,mixed>
	 */
	public function get_payment_intention( string $intent_id ): array {
		++$this->payment_intent_reads;
		$this->last_payment_intent_id = $intent_id;
		if ( is_callable( $this->before_payment_intent_return ) ) {
			call_user_func( $this->before_payment_intent_return, $intent_id );
		}
		if ( $this->exception instanceof Throwable ) {
			throw $this->exception;
		}

		return $this->payment_intent;
	}

	/**
	 * @param string $setup_intent_id SetupIntent ID.
	 * @return array<string,mixed>
	 */
	public function get_setup_intention( string $setup_intent_id ): array {
		++$this->setup_intent_reads;
		$this->last_setup_intent_id = $setup_intent_id;
		if ( is_callable( $this->before_setup_intent_return ) ) {
			call_user_func( $this->before_setup_intent_return, $setup_intent_id );
		}

		return $this->setup_intent;
	}
}

/**
 * Thrown by the test's wp_redirect filter so a redirect does not exit.
 */
class RedirectReturnRedirectIntercepted extends \RuntimeException {
	/** @var string */
	public string $location;

	/**
	 * @param string $location Redirect location.
	 */
	public function __construct( string $location ) {
		parent::__construct( 'Redirect intercepted: ' . $location );
		$this->location = $location;
	}
}

/**
 * Order payment store where a webhook settles the order on hold just before the first claim made for the redirect return.
 */
class RedirectReturnWebhookFirstOrderPaymentLock extends OrderPaymentLock {
	/** @var bool */
	private bool $webhook_ran = false;

	/**
	 * Let a webhook take the lock, write on-hold and release it, then claim the lock for the caller.
	 *
	 * @param WC_Order                               $order               Order object.
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
	 * @param string|null                            $payment_reference   Payment reference.
	 * @param string                                 $operation           Operation claiming the lock.
	 * @return string|null
	 */
	public function claim( WC_Order $order, ProviderPersistenceVocabularyInterface $persistence_vocabulary, ?string $payment_reference, string $operation ): ?string {
		if ( ! $this->webhook_ran ) {
			$this->webhook_ran = true;
			$webhook_token     = parent::claim( $order, $persistence_vocabulary, $payment_reference, 'payment status update' );
			if ( null !== $webhook_token ) {
				wc_get_order( $order->get_id() )->update_status( 'on-hold' );
				parent::release( $order, $persistence_vocabulary, $webhook_token );
			}
		}

		return parent::claim( $order, $persistence_vocabulary, $payment_reference, $operation );
	}
}

/**
 * Token service stub that records per-user cache clearing.
 */
class RedirectReturnTokenServiceStub extends WooPaymentsTokenService {
	/** @var array<int,int> */
	public array $cleared_user_ids = array();

	/**
	 * @param int $user_id User ID.
	 */
	public function clear_cached_payment_methods_for_user( int $user_id ): void {
		$this->cleared_user_ids[] = $user_id;
	}
}

/**
 * Logger that records redirect-return errors.
 */
class RedirectReturnRecordingLogger implements \WC_Logger_Interface {
	/** @var array<int,array{message:mixed,context:array<string,mixed>}> */
	public array $error_calls = array();

	/** @var array<int,array{message:mixed,context:array<string,mixed>}> */
	public array $info_calls = array();

	/** @var array<int,array{message:mixed,context:array<string,mixed>}> */
	public array $warning_calls = array();

	public function add( $handle, $message, $level = \WC_Log_Levels::NOTICE ) {
		unset( $handle, $message, $level );
		return true;
	}

	public function log( $level, $message, $context = array() ) {
		if ( \WC_Log_Levels::ERROR === $level ) {
			$this->error( $message, $context );
		} elseif ( \WC_Log_Levels::INFO === $level ) {
			$this->info( $message, $context );
		} elseif ( \WC_Log_Levels::WARNING === $level ) {
			$this->warning( $message, $context );
		}
	}

	public function emergency( $message, $context = array() ) {
		unset( $message, $context );
	}

	public function alert( $message, $context = array() ) {
		unset( $message, $context );
	}

	public function critical( $message, $context = array() ) {
		unset( $message, $context );
	}

	public function notice( $message, $context = array() ) {
		unset( $message, $context );
	}

	public function debug( $message, $context = array() ) {
		unset( $message, $context );
	}

	public function info( $message, $context = array() ) {
		$this->info_calls[] = array(
			'message' => $message,
			'context' => $context,
		);
	}

	public function warning( $message, $context = array() ) {
		$this->warning_calls[] = array(
			'message' => $message,
			'context' => $context,
		);
	}

	public function error( $message, $context = array() ) {
		$this->error_calls[] = array(
			'message' => $message,
			'context' => $context,
		);
	}
}

// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound,Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName,Squiz.Commenting.FunctionComment.Missing
