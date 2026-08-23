<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentMethodDetailsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsAmazonPayToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsLinkToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsSepaToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenClassMapController;
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
		remove_all_filters( 'woocommerce_payment_token_class' );
		remove_all_filters( 'woocommerce_woopayments_related_subscriptions_for_order' );
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
	 * @testdox Should create reusable non-card tokens from WooPayments payment method details.
	 *
	 * @dataProvider reusable_non_card_token_data
	 *
	 * @param string              $payment_method_id    Provider payment method ID.
	 * @param array<string,mixed> $payment_method       Payment method details.
	 * @param string              $expected_class       Expected token class.
	 * @param string              $expected_gateway_id  Expected WooPayments gateway ID.
	 * @param string              $expected_token_type  Expected token type.
	 * @param string              $metadata_getter      Token metadata getter.
	 * @param string              $expected_meta_value  Expected metadata value.
	 */
	public function test_creates_reusable_non_card_tokens_from_payment_method_details( string $payment_method_id, array $payment_method, string $expected_class, string $expected_gateway_id, string $expected_token_type, string $metadata_getter, string $expected_meta_value ): void {
		$user_id = $this->factory()->user->create();
		$sut     = $this->create_service(
			array(
				$payment_method_id => $payment_method,
			)
		);

		$token = $sut->get_or_create_token_for_user( $payment_method_id, $user_id );

		$this->assertInstanceOf( $expected_class, $token );
		$this->assertGreaterThan( 0, $token->get_id(), 'Created reusable tokens should be persisted.' );
		$this->assertSame( $expected_gateway_id, $token->get_gateway_id() );
		$this->assertSame( $expected_token_type, $token->get_type() );
		$this->assertSame( $user_id, $token->get_user_id() );
		$this->assertSame( $payment_method_id, $token->get_token() );
		$this->assertSame( $expected_meta_value, $token->{$metadata_getter}() );
	}

	/**
	 * Data provider for reusable non-card token creation.
	 *
	 * @return array<string,array{string,array<string,mixed>,string,string,string,string,string}>
	 */
	public function reusable_non_card_token_data(): array {
		return array(
			'SEPA token'       => array(
				'pm_sepa',
				array(
					'id'         => 'pm_sepa',
					'type'       => 'sepa_debit',
					'sepa_debit' => array(
						'last4' => '6789',
					),
				),
				WooPaymentsSepaToken::class,
				'woocommerce_payments_sepa_debit',
				'wcpay_sepa',
				'get_last4',
				'6789',
			),
			'Link token'       => array(
				'pm_link',
				array(
					'id'   => 'pm_link',
					'type' => 'link',
					'link' => array(
						'email' => 'buyer@example.com',
					),
				),
				WooPaymentsLinkToken::class,
				OrderPaymentStore::GATEWAY_ID,
				'wcpay_link',
				'get_email',
				'buyer@example.com',
			),
			'Amazon Pay token' => array(
				'pm_amazon',
				array(
					'id'              => 'pm_amazon',
					'type'            => 'amazon_pay',
					'billing_details' => array(
						'email' => 'buyer@example.com',
					),
				),
				WooPaymentsAmazonPayToken::class,
				'woocommerce_payments_amazon_pay',
				'wcpay_amazon_pay',
				'get_email',
				'***uyer@example.com',
			),
		);
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
	 * @testdox Should detach SEPA, Link and Amazon Pay payment methods when their saved tokens are deleted.
	 */
	public function test_detaches_non_card_payment_methods_when_tokens_are_deleted(): void {
		$user_id    = $this->factory()->user->create();
		$api_client = new class() extends WooPaymentsApiClient {
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
		$sut        = $this->create_service(
			array(
				'pm_sepa'   => array(
					'id'         => 'pm_sepa',
					'type'       => 'sepa_debit',
					'sepa_debit' => array( 'last4' => '6789' ),
				),
				'pm_link'   => array(
					'id'   => 'pm_link',
					'type' => 'link',
					'link' => array( 'email' => 'buyer@example.com' ),
				),
				'pm_amazon' => array(
					'id'              => 'pm_amazon',
					'type'            => 'amazon_pay',
					'billing_details' => array( 'email' => 'buyer@example.com' ),
				),
			),
			$api_client,
			null,
			$this->create_account_service( true )
		);

		foreach ( array( 'pm_sepa', 'pm_link', 'pm_amazon' ) as $payment_method_id ) {
			$token = $sut->get_or_create_token_for_user( $payment_method_id, $user_id );
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token deletion hook.
			do_action( 'woocommerce_payment_token_deleted', $token->get_id(), $token );
		}

		$this->assertSame( array( 'pm_sepa', 'pm_link', 'pm_amazon' ), $api_client->detached_payment_method_ids, 'Every reusable WooPayments token type must detach at the provider on deletion.' );
	}

	/**
	 * @testdox Should set the remote customer default payment method when a non-card token becomes default.
	 */
	public function test_sets_remote_default_payment_method_when_sepa_token_becomes_default(): void {
		$user_id          = $this->factory()->user->create();
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

		$sut = $this->create_service(
			array(
				'pm_sepa' => array(
					'id'         => 'pm_sepa',
					'type'       => 'sepa_debit',
					'sepa_debit' => array( 'last4' => '6789' ),
				),
			),
			null,
			$customer_service
		);

		$token = $sut->get_or_create_token_for_user( 'pm_sepa', $user_id );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered default-token hook.
		do_action( 'woocommerce_payment_token_set_default', $token->get_id(), $token );

		$this->assertSame(
			array(
				array(
					'customer_id'       => 'cus_test',
					'payment_method_id' => 'pm_sepa',
				),
			),
			$customer_service->default_payment_methods,
			'A non-card default must update the provider customer default payment method.'
		);
	}

	/**
	 * @testdox Should add provider payment methods that have no local token during reconciliation.
	 */
	public function test_reconcile_adds_provider_payment_methods_missing_locally(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$customer_service = $this->create_reconciling_customer_service(
			'cus_1',
			array(
				'card'       => array(
					array(
						'id'   => 'pm_remote_card',
						'type' => 'card',
						'card' => array(
							'brand'     => 'visa',
							'last4'     => '4242',
							'exp_month' => 12,
							'exp_year'  => 2030,
						),
					),
				),
				'sepa_debit' => array(
					array(
						'id'         => 'pm_remote_sepa',
						'type'       => 'sepa_debit',
						'sepa_debit' => array( 'last4' => '6789' ),
					),
				),
			)
		);

		$this->create_service( array(), null, $customer_service, $this->create_account_service_with_enabled_methods( array( 'card', 'sepa_debit' ) ) );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token filter.
		$tokens = apply_filters( 'woocommerce_get_customer_payment_tokens', array(), $user_id, '' );

		$token_ids = array();
		foreach ( $tokens as $token ) {
			$token_ids[ $token->get_token() ] = get_class( $token );
			$this->assertGreaterThan( 0, $token->get_id(), 'Reconciled tokens must be persisted.' );
			$this->assertSame( $user_id, $token->get_user_id() );
		}

		$this->assertSame( WC_Payment_Token_CC::class, $token_ids['pm_remote_card'] ?? null, 'A provider card with no local token must gain one.' );
		$this->assertSame( WooPaymentsSepaToken::class, $token_ids['pm_remote_sepa'] ?? null, 'A provider SEPA method with no local token must gain one.' );
	}

	/**
	 * @testdox Should delete local tokens whose payment method no longer exists at the provider, without detaching.
	 */
	public function test_reconcile_deletes_local_tokens_missing_at_provider_without_detach(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );
		$stale_token = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_gone' );

		$api_client = new class() extends WooPaymentsApiClient {
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

		$customer_service = $this->create_reconciling_customer_service( 'cus_1', array( 'card' => array() ) );
		$this->create_service( array(), $api_client, $customer_service, $this->create_account_service_with_enabled_methods( array( 'card' ) ) );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token filter.
		$tokens = apply_filters( 'woocommerce_get_customer_payment_tokens', array( $stale_token->get_id() => $stale_token ), $user_id, '' );

		$this->assertArrayNotHasKey( $stale_token->get_id(), $tokens, 'A token whose payment method the provider forgot must not render.' );
		$this->assertNull( \WC_Payment_Tokens::get( $stale_token->get_id() ), 'The stale local token must be deleted.' );
		$this->assertSame( array(), $api_client->detached_payment_method_ids, 'Pruning a provider-forgotten token must not detach anything remotely.' );
	}

	/**
	 * @testdox Saving a new token should bust the cached provider list so reconciliation cannot delete it.
	 */
	public function test_reconcile_keeps_tokens_created_after_the_cache_was_warmed(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$card_details     = array(
			'id'   => 'pm_new_card',
			'type' => 'card',
			'card' => array(
				'brand'     => 'visa',
				'last4'     => '4242',
				'exp_month' => 12,
				'exp_year'  => 2030,
			),
		);
		$customer_service = $this->create_reconciling_customer_service( 'cus_1', array( 'card' => array() ) );
		$sut              = $this->create_service( array( 'pm_new_card' => $card_details ), null, $customer_service, $this->create_account_service_with_enabled_methods( array( 'card' ) ) );

		// Warm the cache with an empty provider list (a My Account visit before saving).
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token filter.
		apply_filters( 'woocommerce_get_customer_payment_tokens', array(), $user_id, '' );

		// The provider attaches the payment method, then checkout saves it locally.
		$customer_service->payment_methods_by_type['card'] = array( $card_details );
		$token = $sut->get_or_create_token_for_user( 'pm_new_card', $user_id );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token filter.
		$tokens = apply_filters( 'woocommerce_get_customer_payment_tokens', array( $token->get_id() => $token ), $user_id, '' );

		$this->assertArrayHasKey( $token->get_id(), $tokens, 'A token saved after the cache was warmed must survive the next reconciliation.' );
		$this->assertNotNull( \WC_Payment_Tokens::get( $token->get_id() ) );
		$this->assertGreaterThanOrEqual( 2, $customer_service->fetch_counts['card'] ?? 0, 'Saving a token must invalidate the cached provider payment methods.' );
	}

	/**
	 * @testdox Should cache fetched provider payment methods per customer and bust on customer change.
	 */
	public function test_reconcile_caches_fetched_payment_methods_per_customer(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$customer_service = $this->create_reconciling_customer_service( 'cus_1', array( 'card' => array() ) );
		$this->create_service( array(), null, $customer_service, $this->create_account_service_with_enabled_methods( array( 'card' ) ) );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token filter.
		apply_filters( 'woocommerce_get_customer_payment_tokens', array(), $user_id, '' );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token filter.
		apply_filters( 'woocommerce_get_customer_payment_tokens', array(), $user_id, '' );

		$this->assertSame( array( 'card' => 1 ), $customer_service->fetch_counts, 'The second read must be served from the cached payment methods.' );

		$cache = get_user_meta( $user_id, '_wcpay_payment_methods', true );
		$this->assertSame( 'cus_1', $cache['customer_id'] ?? null );
		$this->assertSame( array(), $cache['payment_method_card'] ?? null );

		$customer_service->customer_id = 'cus_2';
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token filter.
		apply_filters( 'woocommerce_get_customer_payment_tokens', array(), $user_id, '' );

		$this->assertSame( array( 'card' => 2 ), $customer_service->fetch_counts, 'A different customer ID must bust the cached payment methods.' );
		$cache = get_user_meta( $user_id, '_wcpay_payment_methods', true );
		$this->assertSame( 'cus_2', $cache['customer_id'] ?? null );
	}

	/**
	 * @testdox Should return the locally stored tokens unchanged when the provider fetch fails.
	 */
	public function test_reconcile_fetch_failure_returns_local_tokens(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );
		$local_token = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_local' );

		$customer_service               = $this->create_reconciling_customer_service( 'cus_1', array() );
		$customer_service->fail_fetches = true;
		$this->create_service( array(), null, $customer_service, $this->create_account_service_with_enabled_methods( array( 'card' ) ) );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token filter.
		$tokens = apply_filters( 'woocommerce_get_customer_payment_tokens', array( $local_token->get_id() => $local_token ), $user_id, '' );

		$this->assertArrayHasKey( $local_token->get_id(), $tokens, 'A provider outage must degrade to the locally stored list.' );
		$this->assertNotNull( \WC_Payment_Tokens::get( $local_token->get_id() ), 'A provider outage must not delete local tokens.' );
	}

	/**
	 * @testdox Reconciliation should re-register the token filter after adding tokens.
	 */
	public function test_reconcile_reregisters_the_filter_after_adding_tokens(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$customer_service = $this->create_reconciling_customer_service(
			'cus_1',
			array(
				'card' => array(
					array(
						'id'   => 'pm_readd',
						'type' => 'card',
						'card' => array(
							'brand'     => 'visa',
							'last4'     => '4242',
							'exp_month' => 12,
							'exp_year'  => 2030,
						),
					),
				),
			)
		);
		$sut              = $this->create_service( array(), null, $customer_service, $this->create_account_service_with_enabled_methods( array( 'card' ) ) );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token filter.
		$tokens = apply_filters( 'woocommerce_get_customer_payment_tokens', array(), $user_id, '' );

		$this->assertCount( 1, $tokens, 'The reconcile run must have added the provider card.' );
		$this->assertSame( 10, has_filter( 'woocommerce_get_customer_payment_tokens', array( $sut, 'handle_woocommerce_get_customer_payment_tokens' ) ), 'The filter must be re-registered after the recursion guard removed it.' );
	}

	/**
	 * @testdox Reconciliation should not run for logged-out requests.
	 */
	public function test_reconcile_skips_when_no_user_is_logged_in(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( 0 );
		$local_token = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_local' );

		$customer_service = $this->create_reconciling_customer_service( 'cus_1', array( 'card' => array() ) );
		$this->create_service( array(), null, $customer_service, $this->create_account_service_with_enabled_methods( array( 'card' ) ) );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token filter.
		$tokens = apply_filters( 'woocommerce_get_customer_payment_tokens', array( $local_token->get_id() => $local_token ), $user_id, '' );

		$this->assertArrayHasKey( $local_token->get_id(), $tokens );
		$this->assertSame( array(), $customer_service->fetch_counts, 'Logged-out requests must not reach the provider.' );
	}

	/**
	 * @testdox Reconciliation should not run once the unpaginated token page is full.
	 */
	public function test_reconcile_skips_at_the_token_page_limit(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );
		update_option( 'posts_per_page', 1 );
		$local_token = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_local' );

		$customer_service = $this->create_reconciling_customer_service( 'cus_1', array( 'card' => array() ) );
		$this->create_service( array(), null, $customer_service, $this->create_account_service_with_enabled_methods( array( 'card' ) ) );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token filter.
		$tokens = apply_filters( 'woocommerce_get_customer_payment_tokens', array( $local_token->get_id() => $local_token ), $user_id, '' );

		$this->assertArrayHasKey( $local_token->get_id(), $tokens );
		$this->assertSame( array(), $customer_service->fetch_counts, 'A full first page of tokens must skip reconciliation.' );
	}

	/**
	 * @testdox Should only retrieve the payment method types belonging to the requested gateway.
	 */
	public function test_reconcile_scopes_types_to_the_requested_gateway(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$customer_service = $this->create_reconciling_customer_service(
			'cus_1',
			array(
				'card'       => array(),
				'link'       => array(),
				'sepa_debit' => array(),
			)
		);
		$this->create_service( array(), null, $customer_service, $this->create_account_service_with_enabled_methods( array( 'card', 'sepa_debit', 'link' ) ) );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token filter.
		apply_filters( 'woocommerce_get_customer_payment_tokens', array(), $user_id, OrderPaymentStore::GATEWAY_ID );

		$this->assertSame(
			array(
				'card' => 1,
				'link' => 1,
			),
			$customer_service->fetch_counts,
			'A card-gateway request must fetch card and Link only, never SEPA.'
		);
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
	 * @testdox Should keep only locally supported WooPayments tokens in native token lists.
	 */
	public function test_keeps_only_supported_native_tokens_in_customer_token_lists(): void {
		$user_id    = $this->factory()->user->create();
		$card_token = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_card' );
		$link_token = new WooPaymentsLinkToken();
		$bank_token = $this->create_echeck_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_bank' );
		$sepa_token = new WooPaymentsSepaToken();

		$link_token->set_gateway_id( OrderPaymentStore::GATEWAY_ID );
		$link_token->set_user_id( $user_id );
		$link_token->set_token( 'pm_link' );
		$link_token->set_email( 'buyer@example.com' );
		$link_token->save();

		$sepa_token->set_gateway_id( 'woocommerce_payments_sepa_debit' );
		$sepa_token->set_user_id( $user_id );
		$sepa_token->set_token( 'pm_sepa' );
		$sepa_token->set_last4( '6789' );
		$sepa_token->save();

		$input_tokens = array(
			$card_token->get_id() => $card_token,
			$link_token->get_id() => $link_token,
			$bank_token->get_id() => $bank_token,
		);
		$sepa_tokens  = array(
			$sepa_token->get_id() => $sepa_token,
		);
		$this->create_service();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered customer-token filter.
		$result = apply_filters( 'woocommerce_get_customer_payment_tokens', $input_tokens, $user_id, OrderPaymentStore::GATEWAY_ID );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered customer-token filter.
		$sepa_result = apply_filters( 'woocommerce_get_customer_payment_tokens', $sepa_tokens, $user_id, 'woocommerce_payments_sepa_debit' );

		$this->assertArrayHasKey( $card_token->get_id(), $result, 'Native WooPayments card tokens should remain available.' );
		$this->assertArrayHasKey( $link_token->get_id(), $result, 'Native WooPayments Link tokens should remain available under the base gateway.' );
		$this->assertArrayNotHasKey( $bank_token->get_id(), $result, 'Native WooPayments should not expose unsupported non-card tokens.' );
		$this->assertArrayHasKey( $sepa_token->get_id(), $sepa_result, 'Native WooPayments SEPA tokens should remain available under the SEPA gateway.' );
	}

	/**
	 * @testdox Should resolve reusable non-card WooPayments tokens attached to renewal orders.
	 */
	public function test_resolves_payment_method_id_from_order_attached_reusable_non_card_token(): void {
		$user_id = $this->factory()->user->create();
		$token   = new WooPaymentsSepaToken();
		$order   = wc_create_order();
		$sut     = $this->create_service();

		$this->register_token_class_map();

		$token->set_gateway_id( 'woocommerce_payments_sepa_debit' );
		$token->set_user_id( $user_id );
		$token->set_token( 'pm_sepa_saved' );
		$token->set_last4( '6789' );
		$token->save();

		$order->add_payment_token( $token );
		$order->save();

		$this->assertSame( 'pm_sepa_saved', $sut->resolve_payment_method_id_from_order_token_id( (string) $token->get_id(), $order ) );
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
	 * @testdox Should format native WooPayments non-card tokens in saved payment method lists.
	 * @dataProvider non_card_saved_payment_method_list_item_data
	 *
	 * @param string                           $payment_method_id Provider payment method ID.
	 * @param array<string,mixed>              $payment_method    Provider payment method details.
	 * @param array{brand:string,last4:string} $expected_method   Expected saved payment method fields.
	 */
	public function test_formats_non_card_tokens_in_saved_payment_method_lists( string $payment_method_id, array $payment_method, array $expected_method ): void {
		$user_id = $this->factory()->user->create();
		$sut     = $this->create_service(
			array(
				$payment_method_id => $payment_method,
			)
		);
		$token   = $sut->get_or_create_token_for_user( $payment_method_id, $user_id );

		$this->assertNotNull( $token, 'The payment method fixture should create a supported native WooPayments token.' );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered saved-method list filter.
		$result = apply_filters( 'woocommerce_payment_methods_list_item', array( 'method' => array() ), $token );

		$this->assertSame( $expected_method['brand'], $result['method']['brand'] );
		$this->assertSame( $expected_method['last4'], $result['method']['last4'] );
	}

	/**
	 * Data provider for non-card saved payment method list items.
	 *
	 * @return array<string,array{string,array<string,mixed>,array{brand:string,last4:string}}>
	 */
	public function non_card_saved_payment_method_list_item_data(): array {
		return array(
			'SEPA token'       => array(
				'pm_sepa',
				array(
					'id'         => 'pm_sepa',
					'type'       => 'sepa_debit',
					'sepa_debit' => array(
						'last4' => '6789',
					),
				),
				array(
					'brand' => 'SEPA IBAN',
					'last4' => '6789',
				),
			),
			'Link token'       => array(
				'pm_link',
				array(
					'id'   => 'pm_link',
					'type' => 'link',
					'link' => array(
						'email' => 'buyer@example.com',
					),
				),
				array(
					'brand' => 'Stripe Link email',
					'last4' => '***uyer@example.com',
				),
			),
			'Amazon Pay token' => array(
				'pm_amazon',
				array(
					'id'              => 'pm_amazon',
					'type'            => 'amazon_pay',
					'billing_details' => array(
						'email' => 'buyer@example.com',
					),
				),
				array(
					'brand' => 'Amazon Pay',
					'last4' => '***uyer@example.com',
				),
			),
		);
	}

	/**
	 * @testdox Should preserve last-attached active-token ordering on an order.
	 */
	public function test_attaches_token_to_order(): void {
		$user_id = $this->factory()->user->create();
		$token_a = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_order_a' );
		$token_b = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_order_b' );
		$order   = wc_create_order();
		$sut     = $this->create_service();

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertTrue( $sut->attach_token_to_order( $order, $token_a ), 'The first token should attach.' );
		$this->assertTrue( $sut->attach_token_to_order( $order, $token_b ), 'A changed token should become active.' );
		$this->assertTrue( $sut->attach_token_to_order( $order, $token_a ), 'A previously used token should be appended to reactivate it.' );
		$this->assertTrue( $sut->attach_token_to_order( $order, $token_a ), 'Repeating the active token should be a successful no-op.' );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( array( $token_a->get_id(), $token_b->get_id(), $token_a->get_id() ), array_values( $order->get_payment_tokens() ) );
		$this->assertSame( $token_a->get_id(), $sut->get_active_token_for_order( $order )->get_id() );
	}

	/**
	 * @testdox Should preserve last-attached active-token ordering on related subscriptions.
	 */
	public function test_syncs_active_token_order_to_related_subscriptions(): void {
		$user_id      = $this->factory()->user->create();
		$token_a      = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_subscription_a' );
		$token_b      = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_subscription_b' );
		$order        = wc_create_order();
		$subscription = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertInstanceOf( WC_Order::class, $subscription );
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$order->save();
		$subscription->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$subscription->add_payment_token( $token_a );
		$subscription->save();
		add_filter(
			'woocommerce_woopayments_related_subscriptions_for_order',
			static function () use ( $subscription ): array {
				return array( $subscription );
			}
		);

		$sut = $this->create_service();
		$sut->sync_related_subscriptions_payment_token( $order, $token_b, 'pm_subscription_b', 'cus_subscription' );
		$sut->sync_related_subscriptions_payment_token( $order, $token_a, 'pm_subscription_a', 'cus_subscription' );
		$sut->sync_related_subscriptions_payment_token( $order, $token_a, 'pm_subscription_a', 'cus_subscription' );

		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertInstanceOf( WC_Order::class, $subscription );
		$this->assertSame( array( $token_a->get_id(), $token_b->get_id(), $token_a->get_id() ), array_values( $subscription->get_payment_tokens() ) );
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
	 * Register the native WooPayments token class map for token-loading tests.
	 */
	private function register_token_class_map(): void {
		$controller = new WooPaymentsTokenClassMapController();
		$controller->init( new StaticNativeRuntimeArbiter( true ) );
		$controller->register();
	}

	/**
	 * Create a customer-service double that reconciles against fixture payment methods.
	 *
	 * @param string                                       $customer_id             Customer ID to report.
	 * @param array<string,array<int,array<string,mixed>>> $payment_methods_by_type Payment methods keyed by type.
	 * @return WooPaymentsCustomerService
	 */
	private function create_reconciling_customer_service( string $customer_id, array $payment_methods_by_type ) {
		return new class( $customer_id, $payment_methods_by_type ) extends WooPaymentsCustomerService {
			/**
			 * Customer ID to report.
			 *
			 * @var string
			 */
			public string $customer_id;

			/**
			 * Payment methods keyed by type.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $payment_methods_by_type;

			/**
			 * Fetch counts keyed by type.
			 *
			 * @var array<string,int>
			 */
			public array $fetch_counts = array();

			/**
			 * Whether fetches should fail.
			 *
			 * @var bool
			 */
			public bool $fail_fetches = false;

			/**
			 * Constructor.
			 *
			 * @param string                                       $customer_id             Customer ID to report.
			 * @param array<string,array<int,array<string,mixed>>> $payment_methods_by_type Payment methods keyed by type.
			 */
			public function __construct( string $customer_id, array $payment_methods_by_type ) {
				$this->customer_id             = $customer_id;
				$this->payment_methods_by_type = $payment_methods_by_type;
			}

			/**
			 * Get a customer ID for a user.
			 *
			 * @param int|null $user_id User ID.
			 * @return string|null
			 */
			public function get_customer_id_by_user_id( ?int $user_id ): ?string {
				unset( $user_id );

				return $this->customer_id;
			}

			/**
			 * Get payment methods for a customer.
			 *
			 * @param string $customer_id Customer ID.
			 * @param string $type        Payment method type.
			 * @return array<int,array<string,mixed>>
			 */
			public function get_payment_methods_for_customer( string $customer_id, string $type = 'card' ): array {
				unset( $customer_id );

				if ( $this->fail_fetches ) {
					throw new RuntimeException( 'Provider unavailable.' );
				}

				$this->fetch_counts[ $type ] = ( $this->fetch_counts[ $type ] ?? 0 ) + 1;

				return $this->payment_methods_by_type[ $type ] ?? array();
			}
		};
	}

	/**
	 * Create an account service double reporting enabled payment method IDs.
	 *
	 * @param string[] $enabled_method_ids Enabled payment method IDs.
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service_with_enabled_methods( array $enabled_method_ids ): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'get_gateway_setting' ) )
			->getMock();
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );
		$account_service->method( 'get_gateway_setting' )->willReturn( $enabled_method_ids );

		return $account_service;
	}

	/**
	 * Create an account service double.
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
