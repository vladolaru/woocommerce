<?php
/**
 * WooPaymentsRedirectReturnController tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCheckoutAjaxController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLegacyRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectApplier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentMethodDetailsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRedirectReturnController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticNativeRuntimeArbiter;
use Throwable;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsRedirectReturnController class.
 */
class WooPaymentsRedirectReturnControllerTest extends WC_Unit_Test_Case {

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
		WC()->cart->empty_cart();
		wc_clear_notices();
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
		remove_all_filters( 'woocommerce_is_order_received_page' );
		remove_all_filters( 'woocommerce_logging_class' );
		remove_all_filters( 'woocommerce_native_woopayments_is_recurring_payment' );
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
	 * @testdox A valid positive-total redirect return confirms the order, saves the token, and empties the cart.
	 */
	public function test_handle_wp_confirms_positive_total_redirect_return(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order   = $this->create_order( '50.00', $user_id, true );
		$product = \WC_Helper_Product::create_simple_product();
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
		$confirmation_owner         = $this->create_confirmation_owner( $api_client, $token_service );
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
	 * @testdox An invalid redirect nonce is a no-op and does not terminate the request.
	 */
	public function test_handle_wp_ignores_invalid_nonce_without_dying(): void {
		$order              = $this->create_order();
		$api_client         = new RedirectReturnApiClientStub();
		$confirmation_owner = $this->createMock( WooPaymentsCheckoutAjaxController::class );
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
		$confirmation_owner = $this->createMock( WooPaymentsCheckoutAjaxController::class );
		$confirmation_owner->expects( $this->never() )->method( 'confirm_fetched_intent_for_order' );
		$this->sut = $this->create_controller( true, $confirmation_owner, $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_wrong_key' );
		$_GET['key'] = 'wc_order_wrong';

		$this->sut->handle_wp();

		$this->assertSame( 0, $api_client->payment_intent_reads );
		$this->assertSame( 'pending', $order->get_status() );
	}

	/**
	 * @testdox PaymentIntent metadata for another order is logged and rejected before mutation.
	 */
	public function test_handle_wp_rejects_mismatched_payment_intent_metadata(): void {
		$order                      = $this->create_order();
		$api_client                 = new RedirectReturnApiClientStub();
		$api_client->payment_intent = $this->successful_payment_intent( $order, 'pi_mismatch', 'pm_mismatch' );
		$api_client->payment_intent['metadata']['order_id'] = $order->get_id() + 1;

		$confirmation_owner = $this->createMock( WooPaymentsCheckoutAjaxController::class );
		$confirmation_owner->expects( $this->never() )->method( 'confirm_fetched_intent_for_order' );
		$logger = new RedirectReturnRecordingLogger();
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		$this->sut = $this->create_controller( true, $confirmation_owner, $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_mismatch' );

		$this->sut->handle_wp();
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'pending', $reloaded->get_status() );
		$this->assertSame( 'pi_mismatch', $reloaded->get_meta( '_intent_id', true ) );
		$this->assertCount( 1, $logger->error_calls );
		$this->assertSame( 'payment-info', $logger->error_calls[0]['context']['source'] );
	}

	/**
	 * @testdox A non-numeric PaymentIntent metadata order ID is rejected before mutation.
	 */
	public function test_handle_wp_rejects_non_numeric_payment_intent_metadata_order_id(): void {
		$order                      = $this->create_order();
		$api_client                 = new RedirectReturnApiClientStub();
		$api_client->payment_intent = $this->successful_payment_intent( $order, 'pi_malformed_order_id', 'pm_malformed' );
		$api_client->payment_intent['metadata']['order_id'] = $order->get_id() . 'junk';

		$confirmation_owner = $this->createMock( WooPaymentsCheckoutAjaxController::class );
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

		$this->sut->handle_wp();
		$reloaded = wc_get_order( $order->get_id() );

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
		$confirmation_owner         = $this->createMock( WooPaymentsCheckoutAjaxController::class );
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
		$confirmation_owner         = $this->createMock( WooPaymentsCheckoutAjaxController::class );
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

		$this->sut->handle_wp();
		$reloaded = wc_get_order( $order->get_id() );

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
		$confirmation_owner         = $this->createMock( WooPaymentsCheckoutAjaxController::class );
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
		$confirmation_owner = $this->createMock( WooPaymentsCheckoutAjaxController::class );
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
		$confirmation_owner = $this->createMock( WooPaymentsCheckoutAjaxController::class );
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
		$confirmation_owner = $this->createMock( WooPaymentsCheckoutAjaxController::class );
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
	 * @testdox API failures are logged once and leave the order unchanged.
	 */
	public function test_handle_wp_logs_api_failure_and_leaves_order_unchanged(): void {
		$order                 = $this->create_order();
		$api_client            = new RedirectReturnApiClientStub();
		$api_client->exception = new WooPaymentsApiException( 'Transport unavailable.', 'transport_unavailable', 503 );
		$confirmation_owner    = $this->createMock( WooPaymentsCheckoutAjaxController::class );
		$confirmation_owner->expects( $this->never() )->method( 'confirm_fetched_intent_for_order' );
		$logger = new RedirectReturnRecordingLogger();
		add_filter( 'woocommerce_logging_class', static fn() => $logger );
		$this->sut = $this->create_controller( true, $confirmation_owner, $api_client );
		$this->set_payment_intent_return_request( $order, 'pi_api_error' );

		$this->sut->handle_wp();
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'pending', $reloaded->get_status() );
		$this->assertCount( 1, $logger->error_calls );
		$this->assertStringContainsString( 'Transport unavailable.', $logger->error_calls[0]['message'] );
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
		$confirmation_owner       = $this->create_confirmation_owner( $api_client );
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
	 * @testdox A successful setup-intent return on the payment-methods page adds a notice and clears the current user's cached methods.
	 */
	public function test_handle_wp_maps_successful_account_setup_intent_return(): void {
		$user_id            = self::factory()->user->create( array( 'role' => 'customer' ) );
		$api_client         = new RedirectReturnApiClientStub();
		$confirmation_owner = $this->createMock( WooPaymentsCheckoutAjaxController::class );
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
		$confirmation_owner         = $this->createMock( WooPaymentsCheckoutAjaxController::class );
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
	 * @param bool                                   $native_owner       Whether native owns runtime.
	 * @param WooPaymentsCheckoutAjaxController|null $confirmation_owner Shared confirmation owner.
	 * @param WooPaymentsApiClient|null              $api_client         API client.
	 * @param WooPaymentsTokenService|null           $token_service      Token service.
	 * @return WooPaymentsRedirectReturnController
	 */
	private function create_controller( bool $native_owner, ?WooPaymentsCheckoutAjaxController $confirmation_owner = null, ?WooPaymentsApiClient $api_client = null, ?WooPaymentsTokenService $token_service = null ): WooPaymentsRedirectReturnController {
		$arbiter = $this->createMock( NativePaymentsRuntimeArbiter::class );
		$arbiter->method( 'should_native_register' )->willReturn( $native_owner );

		$controller = new WooPaymentsRedirectReturnController();
		$controller->init(
			$arbiter,
			$confirmation_owner ?? $this->createMock( WooPaymentsCheckoutAjaxController::class ),
			$api_client ?? new RedirectReturnApiClientStub(),
			$token_service ?? $this->createMock( WooPaymentsTokenService::class )
		);

		return $controller;
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
	 * Create the shared confirmation owner.
	 *
	 * @param WooPaymentsApiClient         $api_client    API client.
	 * @param WooPaymentsTokenService|null $token_service Token service.
	 * @return WooPaymentsCheckoutAjaxController
	 */
	private function create_confirmation_owner( WooPaymentsApiClient $api_client, ?WooPaymentsTokenService $token_service = null ): WooPaymentsCheckoutAjaxController {
		$arbiter = $this->createMock( NativePaymentsRuntimeArbiter::class );
		$arbiter->method( 'should_native_register' )->willReturn( true );
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_mode', 'get_account_country' ) )
			->getMock();
		$account_service->method( 'get_mode' )->willReturn( 'test' );
		$account_service->method( 'get_account_country' )->willReturn( 'US' );
		$token_service      = $token_service ?? $this->create_token_service();
		$order_data_service = new WooPaymentsOrderDataService();
		$registry           = new WooPaymentsPaymentMethodRegistry();
		$effect_applier     = new WooPaymentsOrderEffectApplier();
		$effect_applier->init(
			$token_service,
			$order_data_service,
			$account_service,
			wc_get_container()->get( WooPaymentsLegacyRuntime::class ),
			new WooPaymentsOrderNoteService(),
			$registry
		);

		$controller = new WooPaymentsCheckoutAjaxController();
		$controller->init(
			$arbiter,
			$api_client,
			$this->createMock( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService::class ),
			wc_get_container()->get( OrderPaymentLifecycleService::class ),
			$token_service,
			$account_service,
			$order_data_service,
			$registry,
			$effect_applier
		);

		return $controller;
	}

	/**
	 * Create a token service test double.
	 *
	 * @param array<string,array<string,mixed>> $payment_method_details Payment method details.
	 * @return WooPaymentsTokenService
	 */
	private function create_token_service( array $payment_method_details = array() ): WooPaymentsTokenService {
		$details_service = new class( $payment_method_details ) extends WooPaymentsPaymentMethodDetailsService {
			/** @var array<string,array<string,mixed>> */
			private array $details;

			/**
			 * @param array<string,array<string,mixed>> $details Payment method details.
			 */
			public function __construct( array $details ) {
				$this->details = $details;
			}

			/**
			 * @param string $payment_method_id Payment method ID.
			 * @return array<string,mixed>
			 */
			public function get_payment_method_details( string $payment_method_id ): array {
				return $this->details[ $payment_method_id ] ?? array();
			}
		};
		$token_service   = new WooPaymentsTokenService();
		$token_service->init( $details_service, new StaticNativeRuntimeArbiter( true ) );

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
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
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
			'wc_payment_method'            => OrderPaymentStore::GATEWAY_ID,
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
	 * @param WC_Order $order     Order object.
	 * @param string   $intent_id Intent ID.
	 */
	private function set_setup_intent_return_request( WC_Order $order, string $intent_id ): void {
		$this->set_order_received_context( $order );
		$_GET = array(
			'wc_payment_method'          => OrderPaymentStore::GATEWAY_ID,
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

	/**
	 * @param string $intent_id Intent ID.
	 * @return array<string,mixed>
	 */
	public function get_payment_intention( string $intent_id ): array {
		++$this->payment_intent_reads;
		$this->last_payment_intent_id = $intent_id;
		if ( $this->exception instanceof Throwable ) {
			throw $this->exception;
		}
		if ( is_callable( $this->before_payment_intent_return ) ) {
			call_user_func( $this->before_payment_intent_return, $intent_id );
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

		return $this->setup_intent;
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

	public function add( $handle, $message, $level = \WC_Log_Levels::NOTICE ) {
		unset( $handle, $message, $level );
		return true;
	}

	public function log( $level, $message, $context = array() ) {
		unset( $level, $message, $context );
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
		unset( $message, $context );
	}

	public function warning( $message, $context = array() ) {
		unset( $message, $context );
	}

	public function error( $message, $context = array() ) {
		$this->error_calls[] = array(
			'message' => $message,
			'context' => $context,
		);
	}
}

// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound,Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName,Squiz.Commenting.FunctionComment.Missing
