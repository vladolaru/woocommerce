<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentMethodDetailsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticNativeRuntimeArbiter;
use RuntimeException;
use WC_Order;
use WC_Payment_Token_CC;
use WC_Payment_Token_ECheck;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsTokenService class.
 */
class WooPaymentsTokenServiceTest extends WC_Unit_Test_Case {

	/**
	 * Token services created during a test.
	 *
	 * @var WooPaymentsTokenService[]
	 */
	private array $created_services = array();

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->created_services as $service ) {
			remove_action( 'woocommerce_payment_token_deleted', array( $service, 'handle_woocommerce_payment_token_deleted' ), 10 );
			remove_action( 'woocommerce_payment_token_set_default', array( $service, 'handle_woocommerce_payment_token_set_default' ), 10 );
			remove_filter( 'woocommerce_get_customer_payment_tokens', array( $service, 'handle_woocommerce_get_customer_payment_tokens' ), 10 );
			remove_filter( 'woocommerce_payment_methods_list_item', array( $service, 'handle_woocommerce_payment_methods_list_item' ), 10 );
		}
		$this->created_services = array();
		delete_option( 'wcpay_pm_customer_1' );
		delete_option( 'wcpay_pm_customer_2' );
		delete_option( 'not_wcpay_pm_customer' );
		remove_all_filters( 'pre_option_wcpay_pm_customer_1' );
		parent::tearDown();
	}

	/**
	 * @testdox Should resolve a WooCommerce payment token ID to its provider payment method ID.
	 */
	public function test_resolves_payment_method_id_from_owned_token(): void {
		$user_id = $this->factory()->user->create();
		$token   = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_saved' );
		$sut     = $this->create_service();

		$result = $sut->resolve_payment_method_id_from_token_id( (string) $token->get_id(), $user_id );

		$this->assertSame( 'pm_saved', $result, 'Owned WooPayments tokens should resolve to the provider payment method ID.' );
	}

	/**
	 * @testdox Should reject tokens that belong to another user or gateway.
	 */
	public function test_rejects_unowned_or_wrong_gateway_tokens(): void {
		$user_id       = $this->factory()->user->create();
		$other_user_id = $this->factory()->user->create();
		$owned_token   = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_owned' );
		$other_gateway = $this->create_card_token( $user_id, 'cheque', 'pm_cheque' );
		$sut           = $this->create_service();

		$this->assertSame( '', $sut->resolve_payment_method_id_from_token_id( (string) $owned_token->get_id(), $other_user_id ), 'Tokens owned by another customer should not resolve.' );
		$this->assertSame( '', $sut->resolve_payment_method_id_from_token_id( (string) $other_gateway->get_id(), $user_id ), 'Tokens for another gateway should not resolve.' );
		$this->assertSame( '', $sut->resolve_payment_method_id_from_token_id( '999999', $user_id ), 'Missing token IDs should not resolve.' );
	}

	/**
	 * @testdox Should resolve native WooPayments tokens already attached to a renewal order.
	 */
	public function test_resolves_payment_method_id_from_order_attached_token(): void {
		$user_id       = $this->factory()->user->create();
		$other_user_id = $this->factory()->user->create();
		$token         = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_attached' );
		$unattached    = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_unattached' );
		$wrong_gateway = $this->create_card_token( $user_id, 'cheque', 'pm_cheque' );
		$order         = wc_create_order();
		$sut           = $this->create_service();

		$order->set_customer_id( $other_user_id );
		$order->add_payment_token( $token );
		$order->add_payment_token( $wrong_gateway );
		$order->save();

		$this->assertSame( 'pm_attached', $sut->resolve_payment_method_id_from_order_token_id( (string) $token->get_id(), $order ) );
		$this->assertSame( '', $sut->resolve_payment_method_id_from_order_token_id( (string) $unattached->get_id(), $order ), 'Tokens not attached to the order should not resolve.' );
		$this->assertSame( '', $sut->resolve_payment_method_id_from_order_token_id( (string) $wrong_gateway->get_id(), $order ), 'Non-WooPayments tokens attached to the order should not resolve.' );
	}

	/**
	 * @testdox Should create a card token from WooPayments payment method details.
	 */
	public function test_creates_card_token_from_payment_method_details(): void {
		$user_id = $this->factory()->user->create();
		$sut     = $this->create_service(
			array(
				'pm_new' => array(
					'id'   => 'pm_new',
					'type' => 'card',
					'card' => array(
						'display_brand' => 'Visa',
						'brand'         => 'visa',
						'last4'         => '4242',
						'exp_month'     => 7,
						'exp_year'      => 2032,
						'wallet'        => array(
							'type' => 'apple_pay',
						),
					),
				),
			)
		);

		$token = $sut->get_or_create_card_token_for_user( 'pm_new', $user_id );

		$this->assertInstanceOf( WC_Payment_Token_CC::class, $token );
		$this->assertGreaterThan( 0, $token->get_id(), 'Created tokens should be persisted.' );
		$this->assertSame( OrderPaymentStore::GATEWAY_ID, $token->get_gateway_id() );
		$this->assertSame( $user_id, $token->get_user_id() );
		$this->assertSame( 'pm_new', $token->get_token() );
		$this->assertSame( 'visa', $token->get_card_type() );
		$this->assertSame( '4242', $token->get_last4() );
		$this->assertSame( '07', $token->get_expiry_month() );
		$this->assertSame( '2032', $token->get_expiry_year() );
		$this->assertSame( 'apple_pay', $token->get_meta( '_wcpay_wallet_type', true ) );
	}

	/**
	 * @testdox Should reuse an existing token for the same provider payment method.
	 */
	public function test_reuses_existing_customer_token(): void {
		$user_id        = $this->factory()->user->create();
		$existing_token = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_existing' );
		$sut            = $this->create_service(
			array(
				'pm_existing' => array(
					'id'   => 'pm_existing',
					'type' => 'card',
					'card' => array(
						'brand'     => 'mastercard',
						'last4'     => '4444',
						'exp_month' => 12,
						'exp_year'  => 2031,
					),
				),
			)
		);

		$token = $sut->get_or_create_card_token_for_user( 'pm_existing', $user_id );

		$this->assertInstanceOf( WC_Payment_Token_CC::class, $token );
		$this->assertSame( $existing_token->get_id(), $token->get_id(), 'Existing tokens should be reused instead of duplicated.' );
	}

	/**
	 * @testdox Should return null when payment method details are not tokenizable card details.
	 */
	public function test_returns_null_for_missing_card_details(): void {
		$user_id = $this->factory()->user->create();
		$sut     = $this->create_service(
			array(
				'pm_incomplete' => array(
					'id'   => 'pm_incomplete',
					'type' => 'card',
					'card' => array(
						'brand'     => 'visa',
						'exp_month' => 1,
						'exp_year'  => 2030,
					),
				),
			)
		);

		$this->assertNull( $sut->get_or_create_card_token_for_user( 'pm_incomplete', $user_id ), 'Incomplete card details should not create invalid WooCommerce tokens.' );
		$this->assertNull( $sut->get_or_create_card_token_for_user( '', $user_id ), 'Empty payment method IDs should not create tokens.' );
		$this->assertNull( $sut->get_or_create_card_token_for_user( 'pm_incomplete', 0 ), 'Guest customers cannot receive saved card tokens.' );
	}

	/**
	 * @testdox Should register supported saved-payment-method lifecycle hooks.
	 */
	public function test_registers_supported_saved_payment_method_lifecycle_hooks(): void {
		$sut = $this->create_service();

		$this->assertSame( 10, has_action( 'woocommerce_payment_token_deleted', array( $sut, 'handle_woocommerce_payment_token_deleted' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_payment_token_set_default', array( $sut, 'handle_woocommerce_payment_token_set_default' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_get_customer_payment_tokens', array( $sut, 'handle_woocommerce_get_customer_payment_tokens' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_payment_methods_list_item', array( $sut, 'handle_woocommerce_payment_methods_list_item' ) ) );
	}

	/**
	 * @testdox Should not register saved-payment-method lifecycle hooks when native does not own runtime.
	 */
	public function test_registers_no_saved_payment_method_lifecycle_hooks_when_native_does_not_own_runtime(): void {
		$sut = $this->create_service( array(), null, null, null, new StaticNativeRuntimeArbiter( false ) );

		$this->assertFalse( has_action( 'woocommerce_payment_token_deleted', array( $sut, 'handle_woocommerce_payment_token_deleted' ) ) );
		$this->assertFalse( has_action( 'woocommerce_payment_token_set_default', array( $sut, 'handle_woocommerce_payment_token_set_default' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_get_customer_payment_tokens', array( $sut, 'handle_woocommerce_get_customer_payment_tokens' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_payment_methods_list_item', array( $sut, 'handle_woocommerce_payment_methods_list_item' ) ) );
	}

	/**
	 * @testdox Should clear cached payment methods when a native WooPayments card token is deleted.
	 */
	public function test_clears_cached_payment_methods_when_native_card_token_is_deleted(): void {
		$user_id       = $this->factory()->user->create();
		$other_user_id = $this->factory()->user->create();
		$native_token  = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_delete' );
		$other_token   = $this->create_card_token( $user_id, 'cheque', 'pm_cheque' );
		update_user_meta( $user_id, '_wcpay_payment_methods', array( 'pm_delete' ) );
		update_user_meta( $other_user_id, '_wcpay_payment_methods', array( 'pm_other' ) );
		$this->create_service();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token deletion hook.
		do_action( 'woocommerce_payment_token_deleted', $native_token->get_id(), $native_token );

		$this->assertSame( '', get_user_meta( $user_id, '_wcpay_payment_methods', true ), 'Deleting a native card token should clear that customer cache.' );
		$this->assertSame( array( 'pm_other' ), get_user_meta( $other_user_id, '_wcpay_payment_methods', true ), 'Deleting one customer token should not clear another customer cache.' );

		update_user_meta( $user_id, '_wcpay_payment_methods', array( 'pm_keep' ) );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token deletion hook.
		do_action( 'woocommerce_payment_token_deleted', $other_token->get_id(), $other_token );

		$this->assertSame( array( 'pm_keep' ), get_user_meta( $user_id, '_wcpay_payment_methods', true ), 'Deleting a non-WooPayments token should not clear WooPayments caches.' );
	}

	/**
	 * @testdox Should detach native WooPayments card payment methods when a saved token is deleted.
	 */
	public function test_detaches_native_card_payment_methods_when_token_is_deleted(): void {
		$user_id      = $this->factory()->user->create();
		$native_token = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_delete' );
		$api_client   = new class() extends WooPaymentsApiClient {
			/**
			 * Detached payment method IDs.
			 *
			 * @var string[]
			 */
			public array $detached_payment_method_ids = array();

			/**
			 * Detach a payment method.
			 *
			 * @param string $payment_method_id Payment method ID.
			 * @return array<string,mixed>
			 */
			public function detach_payment_method( string $payment_method_id ): array {
				$this->detached_payment_method_ids[] = $payment_method_id;

				return array( 'id' => $payment_method_id );
			}
		};
		$this->create_service( array(), $api_client, null, $this->create_account_service( true ) );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token deletion hook.
		do_action( 'woocommerce_payment_token_deleted', $native_token->get_id(), $native_token );

		$this->assertSame( array( 'pm_delete' ), $api_client->detached_payment_method_ids );
	}

	/**
	 * @testdox Should not detach live payment methods from admin screens on non-production environments.
	 */
	public function test_does_not_detach_live_payment_methods_from_non_production_admin_screens(): void {
		$user_id      = $this->factory()->user->create();
		$native_token = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_live' );
		$api_client   = new class() extends WooPaymentsApiClient {
			/**
			 * Detached payment method IDs.
			 *
			 * @var string[]
			 */
			public array $detached_payment_method_ids = array();

			/**
			 * Detach a payment method.
			 *
			 * @param string $payment_method_id Payment method ID.
			 * @return array<string,mixed>
			 */
			public function detach_payment_method( string $payment_method_id ): array {
				$this->detached_payment_method_ids[] = $payment_method_id;

				return array( 'id' => $payment_method_id );
			}
		};
		$this->create_service( array(), $api_client, null, $this->create_account_service( false ) );
		set_current_screen( 'users' );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token deletion hook.
		do_action( 'woocommerce_payment_token_deleted', $native_token->get_id(), $native_token );

		$this->assertSame( array(), $api_client->detached_payment_method_ids );
	}

	/**
	 * @testdox Should clear cached payment methods when a native WooPayments card token becomes default.
	 */
	public function test_clears_cached_payment_methods_when_native_card_token_becomes_default(): void {
		$user_id      = $this->factory()->user->create();
		$native_token = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_default' );
		$other_token  = $this->create_card_token( $user_id, 'cheque', 'pm_cheque' );
		update_user_meta( $user_id, '_wcpay_payment_methods', array( 'pm_default' ) );
		$this->create_service();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered default-token hook.
		do_action( 'woocommerce_payment_token_set_default', $native_token->get_id(), $native_token );

		$this->assertSame( '', get_user_meta( $user_id, '_wcpay_payment_methods', true ), 'Setting a native card token as default should clear that customer cache.' );

		update_user_meta( $user_id, '_wcpay_payment_methods', array( 'pm_keep' ) );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered default-token hook.
		do_action( 'woocommerce_payment_token_set_default', $other_token->get_id(), $other_token );

		$this->assertSame( array( 'pm_keep' ), get_user_meta( $user_id, '_wcpay_payment_methods', true ), 'Setting a non-WooPayments token as default should not clear WooPayments caches.' );
	}

	/**
	 * @testdox Should set the remote customer default payment method when a native card token becomes default.
	 */
	public function test_sets_remote_default_payment_method_when_native_card_token_becomes_default(): void {
		$user_id          = $this->factory()->user->create();
		$native_token     = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_default' );
		$customer_service = new class() extends WooPaymentsCustomerService {
			/**
			 * Customer IDs keyed by WordPress user ID.
			 *
			 * @var array<int,string>
			 */
			public array $customer_ids_by_user_id = array();

			/**
			 * Default payment method updates.
			 *
			 * @var array<int,array{customer_id:string,payment_method_id:string}>
			 */
			public array $default_payment_methods = array();

			/**
			 * Get a customer ID for a user.
			 *
			 * @param int|null $user_id User ID.
			 * @return string|null
			 */
			public function get_customer_id_by_user_id( ?int $user_id ): ?string {
				return null === $user_id ? null : $this->customer_ids_by_user_id[ $user_id ] ?? null;
			}

			/**
			 * Set a payment method as default for a customer.
			 *
			 * @param string $customer_id       Customer ID.
			 * @param string $payment_method_id Payment method ID.
			 * @return void
			 */
			public function set_default_payment_method_for_customer( string $customer_id, string $payment_method_id ): void {
				$this->default_payment_methods[] = array(
					'customer_id'       => $customer_id,
					'payment_method_id' => $payment_method_id,
				);
			}
		};
		$customer_service->customer_ids_by_user_id[ $user_id ] = 'cus_test';
		$this->create_service( array(), null, $customer_service );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered default-token hook.
		do_action( 'woocommerce_payment_token_set_default', $native_token->get_id(), $native_token );

		$this->assertSame(
			array(
				array(
					'customer_id'       => 'cus_test',
					'payment_method_id' => 'pm_default',
				),
			),
			$customer_service->default_payment_methods
		);
	}

	/**
	 * @testdox Should keep only locally supported WooPayments card tokens in native token lists.
	 */
	public function test_keeps_only_supported_native_card_tokens_in_customer_token_lists(): void {
		$user_id      = $this->factory()->user->create();
		$card_token   = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_card' );
		$bank_token   = $this->create_echeck_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_bank' );
		$input_tokens = array(
			$card_token->get_id() => $card_token,
			$bank_token->get_id() => $bank_token,
		);
		$this->create_service();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered customer-token filter.
		$result = apply_filters( 'woocommerce_get_customer_payment_tokens', $input_tokens, $user_id, OrderPaymentStore::GATEWAY_ID );

		$this->assertArrayHasKey( $card_token->get_id(), $result, 'Native WooPayments card tokens should remain available.' );
		$this->assertArrayNotHasKey( $bank_token->get_id(), $result, 'Native WooPayments should not expose unsupported non-card tokens.' );
	}

	/**
	 * @testdox Should format wallet-backed WooPayments card tokens in saved payment method lists.
	 */
	public function test_formats_wallet_backed_card_tokens_in_saved_payment_method_lists(): void {
		$user_id = $this->factory()->user->create();
		$token   = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_wallet' );
		$token->add_meta_data( '_wcpay_wallet_type', 'apple_pay', true );
		$token->save();
		$item = array(
			'method' => array(
				'brand' => 'Visa',
				'last4' => '4242',
			),
		);
		$this->create_service();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered saved-method list filter.
		$result = apply_filters( 'woocommerce_payment_methods_list_item', $item, $token );

		$this->assertSame( 'Apple Pay Visa', $result['method']['brand'] );
		$this->assertSame( '4242', $result['method']['last4'] );
	}

	/**
	 * @testdox Should attach a persisted token to an order.
	 */
	public function test_attaches_token_to_order(): void {
		$user_id = $this->factory()->user->create();
		$token   = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_order' );
		$order   = wc_create_order();
		$sut     = $this->create_service();

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertTrue( $sut->attach_token_to_order( $order, $token ), 'Persisted tokens should attach to orders.' );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertContains( $token->get_id(), $order->get_payment_tokens(), 'The order should store the attached token ID.' );
	}

	/**
	 * @testdox Should clear preserved WooPayments payment method caches for all users.
	 */
	public function test_clear_all_cached_payment_methods_removes_preserved_cache_entries(): void {
		$user_id       = $this->factory()->user->create();
		$other_user_id = $this->factory()->user->create();
		update_user_meta( $user_id, '_wcpay_payment_methods', array( 'pm_user' ) );
		update_user_meta( $other_user_id, '_wcpay_payment_methods', array( 'pm_other' ) );
		update_user_meta( $user_id, '_not_wcpay_payment_methods', array( 'pm_keep' ) );
		update_option( 'wcpay_pm_customer_1', array( 'pm_cached_1' ) );
		update_option( 'wcpay_pm_customer_2', array( 'pm_cached_2' ) );
		update_option( 'not_wcpay_pm_customer', 'keep' );

		$sut = $this->create_service();
		$sut->clear_all_cached_payment_methods();

		$this->assertSame( '', get_user_meta( $user_id, '_wcpay_payment_methods', true ) );
		$this->assertSame( '', get_user_meta( $other_user_id, '_wcpay_payment_methods', true ) );
		$this->assertSame( array( 'pm_keep' ), get_user_meta( $user_id, '_not_wcpay_payment_methods', true ) );
		$this->assertFalse( get_option( 'wcpay_pm_customer_1' ) );
		$this->assertFalse( get_option( 'wcpay_pm_customer_2' ) );
		$this->assertSame( 'keep', get_option( 'not_wcpay_pm_customer' ) );
	}

	/**
	 * @testdox Should fail closed when a selected cached payment-method option cannot be deleted.
	 */
	public function test_clear_all_cached_payment_methods_fails_closed_when_option_delete_does_not_stick(): void {
		update_option( 'wcpay_pm_customer_1', array( 'pm_cached_1' ) );
		add_filter(
			'pre_option_wcpay_pm_customer_1',
			static function () {
				return array( 'pm_cached_1' );
			}
		);

		$sut = $this->create_service();

		$this->expectException( RuntimeException::class );

		$sut->clear_all_cached_payment_methods();
	}

	/**
	 * Create the system under test.
	 *
	 * @param array<string,array<string,mixed>> $payment_method_details Payment method details keyed by ID.
	 * @param WooPaymentsApiClient|null         $api_client             Optional native API client.
	 * @param WooPaymentsCustomerService|null   $customer_service       Optional native customer service.
	 * @param WooPaymentsAccountService|null    $account_service        Optional native account service.
	 * @param NativePaymentsRuntimeArbiter|null $arbiter                Optional native runtime arbiter.
	 * @return WooPaymentsTokenService
	 */
	private function create_service( array $payment_method_details = array(), ?WooPaymentsApiClient $api_client = null, ?WooPaymentsCustomerService $customer_service = null, ?WooPaymentsAccountService $account_service = null, ?NativePaymentsRuntimeArbiter $arbiter = null ): WooPaymentsTokenService {
		$details_service = new class( $payment_method_details ) extends WooPaymentsPaymentMethodDetailsService {
			/**
			 * Payment method details keyed by ID.
			 *
			 * @var array<string,array<string,mixed>>
			 */
			private array $payment_method_details;

			/**
			 * Constructor.
			 *
			 * @param array<string,array<string,mixed>> $payment_method_details Payment method details keyed by ID.
			 */
			public function __construct( array $payment_method_details ) {
				$this->payment_method_details = $payment_method_details;
			}

			/**
			 * Get payment method details.
			 *
			 * @param string $payment_method_id Payment method ID.
			 * @return array<string,mixed>
			 */
			public function get_payment_method_details( string $payment_method_id ): array {
				return $this->payment_method_details[ $payment_method_id ] ?? array();
			}
		};

		$sut = new WooPaymentsTokenService();
		$sut->init( $details_service, $arbiter ?? new StaticNativeRuntimeArbiter( true ), $api_client, $customer_service, $account_service );
		$this->created_services[] = $sut;

		return $sut;
	}

	/**
	 * Create an account service mock.
	 *
	 * @param bool $test_mode Whether test mode is enabled.
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service( bool $test_mode ): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->method( 'is_test_mode_enabled' )->willReturn( $test_mode );

		return $account_service;
	}

	/**
	 * Create a persisted credit-card token.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $gateway_id Gateway ID.
	 * @param string $token_id   Provider token ID.
	 * @return WC_Payment_Token_CC
	 */
	private function create_card_token( int $user_id, string $gateway_id, string $token_id ): WC_Payment_Token_CC {
		$token = new WC_Payment_Token_CC();
		$token->set_gateway_id( $gateway_id );
		$token->set_user_id( $user_id );
		$token->set_token( $token_id );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->save();

		return $token;
	}

	/**
	 * Create a persisted eCheck token.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $gateway_id Gateway ID.
	 * @param string $token_id   Provider token ID.
	 * @return WC_Payment_Token_ECheck
	 */
	private function create_echeck_token( int $user_id, string $gateway_id, string $token_id ): WC_Payment_Token_ECheck {
		$token = new WC_Payment_Token_ECheck();
		$token->set_gateway_id( $gateway_id );
		$token->set_user_id( $user_id );
		$token->set_token( $token_id );
		$token->set_last4( '6789' );
		$token->save();

		return $token;
	}
}
