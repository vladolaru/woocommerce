<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentMethodDetailsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSessionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsAmazonPayToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsLinkToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsSepaToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenClassMapController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticNativeRuntimeArbiter;
use RuntimeException;
use WC_Order;
use WC_Payment_Token_CC;
use WC_Payment_Token_ECheck;
use WC_Payment_Tokens;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsTokenService class.
 */
class WooPaymentsTokenServiceTest extends WC_Unit_Test_Case {

	use ProviderTextLogAssertions;

	/**
	 * Token services created during a test.
	 *
	 * @var WooPaymentsTokenService[]
	 */
	private array $created_services = array();

	/**
	 * Gateway initializer registered by a test, removed on tear down.
	 *
	 * @var callable|null
	 */
	private $gateway_initializer = null;

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$this->reset_container_replacements();
		foreach ( $this->created_services as $service ) {
			remove_action( 'woocommerce_payment_token_deleted', array( $service, 'handle_woocommerce_payment_token_deleted' ), 10 );
			remove_action( 'woocommerce_payment_token_set_default', array( $service, 'handle_woocommerce_payment_token_set_default' ), 10 );
			remove_filter( 'woocommerce_get_customer_payment_tokens', array( $service, 'handle_woocommerce_get_customer_payment_tokens' ), 10 );
			remove_filter( 'woocommerce_payment_methods_list_item', array( $service, 'handle_woocommerce_payment_methods_list_item' ), 10 );
			remove_filter( 'woocommerce_get_credit_card_type_label', array( $service, 'normalize_saved_method_label' ), 10 );
		}
		$this->created_services = array();
		if ( null !== $this->gateway_initializer ) {
			remove_action( 'wc_payment_gateways_initialized', $this->gateway_initializer, 100 );
			$this->gateway_initializer                 = null;
			WC()->payment_gateways()->payment_gateways = array();
			WC()->payment_gateways()->init();
		}
		remove_all_filters( 'wcpay_dev_mode' );
		delete_option( 'wcpay_pm_customer_1' );
		delete_option( 'wcpay_pm_customer_2' );
		delete_option( 'not_wcpay_pm_customer' );
		remove_all_filters( 'pre_option_wcpay_pm_customer_1' );
		remove_all_filters( 'woocommerce_payment_token_class' );
		remove_all_filters( 'woocommerce_woopayments_related_subscriptions_for_order' );
		unset( $GLOBALS['wcpay_test_order_subscription_relationships'] );
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
	 * @testdox Resolving a card token should not read enabled-method settings.
	 */
	public function test_resolves_card_token_without_reading_enabled_method_settings(): void {
		$user_id = $this->factory()->user->create();
		$token   = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_saved' );
		$sut     = $this->create_service( array(), null, null, $this->create_account_service_that_must_not_read_enabled_methods() );

		$this->assertSame( 'pm_saved', $sut->resolve_payment_method_id_from_token_id( (string) $token->get_id(), $user_id ) );
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
	 * @testdox Related subscription lookups include parent, switch, and renewal relationships.
	 */
	public function test_get_related_subscriptions_for_order_includes_renewal_relationships(): void {
		$this->ensure_wcs_subscriptions_for_order_double();
		$order         = $this->create_woopayments_order();
		$parent        = $this->create_woopayments_order();
		$switch        = $this->create_woopayments_order();
		$renewal       = $this->create_woopayments_order();
		$unrelated     = $this->create_woopayments_order();
		$relationships = array(
			'parent'  => array( $parent->get_id() ),
			'switch'  => array( $switch->get_id() ),
			'renewal' => array( $renewal->get_id() ),
		);

		$GLOBALS['wcpay_test_order_subscription_relationships'] = array( $order->get_id() => $relationships );
		$related = $this->create_service()->get_related_subscriptions_for_order( $order );

		$this->assertSame( array( $parent->get_id(), $switch->get_id(), $renewal->get_id() ), array_map( static fn( WC_Order $subscription ): int => $subscription->get_id(), $related ) );
		$this->assertNotContains( $unrelated->get_id(), array_map( static fn( WC_Order $subscription ): int => $subscription->get_id(), $related ) );
	}

	/**
	 * @testdox The subscriptions lookup double accepts an order object.
	 */
	public function test_wcs_subscriptions_for_order_double_accepts_order_object(): void {
		$this->ensure_wcs_subscriptions_for_order_double();
		$order         = $this->create_woopayments_order();
		$parent        = $this->create_woopayments_order();
		$renewal       = $this->create_woopayments_order();
		$relationships = array(
			'parent'  => array( $parent->get_id() ),
			'renewal' => array( $renewal->get_id() ),
		);

		$GLOBALS['wcpay_test_order_subscription_relationships'] = array( $order->get_id() => $relationships );
		$related = wcs_get_subscriptions_for_order( $order, array( 'order_type' => array( 'parent', 'renewal' ) ) );

		$this->assertSame( array( $parent->get_id(), $renewal->get_id() ), array_map( static fn( WC_Order $subscription ): int => $subscription->get_id(), $related ) );
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
	 * @testdox Resolving a new token returns the authoritative payment-method details with it.
	 */
	public function test_resolves_new_token_with_authoritative_payment_method_details(): void {
		$user_id = $this->factory()->user->create();
		$details = array(
			'id'   => 'pm_explicit_result',
			'type' => 'card',
			'card' => array(
				'brand'     => 'visa',
				'last4'     => '4242',
				'exp_month' => 12,
				'exp_year'  => 2030,
			),
		);
		$sut     = $this->create_service( array( 'pm_explicit_result' => $details ) );

		$result = $sut->resolve_token_and_payment_method_details_for_user( 'pm_explicit_result', $user_id );

		$this->assertSame( array( 'token', 'payment_method_details' ), array_keys( $result ) );
		$this->assertInstanceOf( WC_Payment_Token_CC::class, $result['token'] );
		$this->assertSame( $details, $result['payment_method_details'] );
	}

	/**
	 * @testdox Rejects details returned for a different provider payment method.
	 */
	public function test_rejects_payment_method_details_with_a_mismatched_response_id(): void {
		$user_id = $this->factory()->user->create();
		$sut     = $this->create_service(
			array(
				'pm_requested' => array(
					'id'   => 'pm_returned',
					'type' => 'card',
					'card' => array(
						'brand'     => 'visa',
						'last4'     => '4242',
						'exp_month' => 12,
						'exp_year'  => 2030,
					),
				),
			)
		);

		$result = $sut->resolve_token_and_payment_method_details_for_user( 'pm_requested', $user_id );

		$this->assertNull( $result['token'] );
		$this->assertSame( array(), $result['payment_method_details'] );
		$this->assertSame( array(), WC_Payment_Tokens::get_customer_tokens( $user_id ) );
	}

	/**
	 * @testdox Binds a missing provider response ID to the requested payment method.
	 */
	public function test_binds_a_missing_payment_method_response_id_to_the_requested_id(): void {
		$user_id = $this->factory()->user->create();
		$sut     = $this->create_service(
			array(
				'pm_requested' => array(
					'id'   => '',
					'type' => 'card',
					'card' => array(
						'brand'     => 'visa',
						'last4'     => '4242',
						'exp_month' => 12,
						'exp_year'  => 2030,
					),
				),
			)
		);

		$result = $sut->resolve_token_and_payment_method_details_for_user( 'pm_requested', $user_id );

		$this->assertInstanceOf( WC_Payment_Token_CC::class, $result['token'] );
		$this->assertSame( 'pm_requested', $result['token']->get_token() );
		$this->assertSame( 'pm_requested', $result['payment_method_details']['id'] );
	}

	/**
	 * @testdox The token-only method returns the token from the explicit resolution result.
	 */
	public function test_token_only_method_returns_the_token_from_the_explicit_resolution_result(): void {
		$user_id = $this->factory()->user->create();
		$token   = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_wrapper_contract' );
		$sut     = new class( $token ) extends WooPaymentsTokenService {
			/** @var WC_Payment_Token_CC */
			private WC_Payment_Token_CC $token;

			/** @var int */
			public int $resolution_calls = 0;

			/**
			 * @param WC_Payment_Token_CC $token Token returned by the explicit result method.
			 */
			public function __construct( WC_Payment_Token_CC $token ) {
				$this->token = $token;
			}

			/**
			 * @param string $payment_method_id Provider payment method ID.
			 * @param int    $resolved_user_id  User ID.
			 * @param bool   $include_existing_token_details Whether existing-token details are requested.
			 * @return array{token:\WC_Payment_Token|null,payment_method_details:array<string,mixed>}
			 */
			public function resolve_token_and_payment_method_details_for_user( string $payment_method_id, int $resolved_user_id, bool $include_existing_token_details = false ): array {
				unset( $include_existing_token_details );
				unset( $payment_method_id, $resolved_user_id );
				++$this->resolution_calls;

				return array(
					'token'                  => $this->token,
					'payment_method_details' => array( 'type' => 'card' ),
				);
			}
		};

		$this->assertSame( $token, $sut->get_or_create_token_for_user( 'pm_wrapper_contract', $user_id ) );
		$this->assertSame( 1, $sut->resolution_calls );
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
	 * @testdox A dependency that cannot be built fails the token service's resolution instead of being skipped.
	 *
	 * Client 11.1.0 requires its API client and customer service in the constructor (`class-wc-payments-token-service.php:49-52`),
	 * so a dependency that cannot be built fatals when the service is created. Native's container builds every `init()`
	 * argument the same way; no lookup later swallows the failure and skips the detach, sync or fetch (unit 2a-9a).
	 */
	public function test_dependency_that_cannot_be_built_fails_the_token_service_resolution(): void {
		// The typed account-service arguments of the token service and its dependencies refuse this object with a TypeError.
		wc_get_container()->replace( WooPaymentsAccountService::class, new \stdClass() );
		wc_get_container()->reset_all_resolved();

		$thrown = null;
		try {
			wc_get_container()->get( WooPaymentsTokenService::class );
		} catch ( \Throwable $throwable ) {
			$thrown = $throwable;
		} finally {
			$this->reset_container_replacements();
			wc_get_container()->reset_all_resolved();
		}

		$this->assertInstanceOf( \TypeError::class, $thrown );
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
		$this->register_card_gateway_id();
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
		$this->register_card_gateway_id();
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
	 * @testdox Should preserve and hide a disabled SEPA token from all-gateway listings.
	 */
	public function test_reconcile_preserves_and_hides_disabled_sepa_tokens_from_all_gateway_listings(): void {
		$this->register_card_gateway_id();
		$user_id    = $this->factory()->user->create();
		$sepa_token = $this->create_sepa_token( $user_id, 'pm_disabled_sepa' );
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

		$this->register_token_class_map();
		wp_set_current_user( $user_id );
		$customer_service = $this->create_reconciling_customer_service(
			'cus_1',
			array(
				'card'       => array(),
				'sepa_debit' => array(),
			)
		);
		$this->create_service( array(), $api_client, $customer_service, $this->create_account_service_with_enabled_methods( array( 'card' ) ) );
		$updated_token_ids   = array();
		$record_token_update = static function ( int $token_id ) use ( &$updated_token_ids ): void {
			$updated_token_ids[] = $token_id;
		};
		add_action( 'woocommerce_payment_token_updated', $record_token_update );

		try {
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token filter.
			$tokens = apply_filters( 'woocommerce_get_customer_payment_tokens', array( $sepa_token->get_id() => $sepa_token ), $user_id, '' );
		} finally {
			remove_action( 'woocommerce_payment_token_updated', $record_token_update );
		}

		$stored_token = \WC_Payment_Tokens::get( $sepa_token->get_id() );

		$this->assertArrayNotHasKey( $sepa_token->get_id(), $tokens, 'Disabled SEPA tokens must not be returned for My Account or all-gateway checkout listings.' );
		$this->assertInstanceOf( WooPaymentsSepaToken::class, $stored_token, 'Disabled SEPA tokens must remain stored locally.' );
		$this->assertSame( '', $stored_token->get_meta( '_wcpay_payment_method_disabled', true ), 'Deriving disabled state from settings must not add token metadata.' );
		$this->assertSame( array(), $updated_token_ids, 'Listing a disabled token must not write to it.' );
		$this->assertSame( array( 'card' => 1 ), $customer_service->fetch_counts, 'Disabled SEPA must not be fetched during all-gateway reconciliation.' );
		$this->assertSame( array(), $api_client->detached_payment_method_ids, 'Preserving a disabled token must not trigger a remote detach.' );
	}

	/**
	 * Client 11.1.0 retrieves SEPA for the SEPA gateway whether or not SEPA is enabled; only Link, which rides the card
	 * gateway, needs its setting (includes/class-wc-payments-token-service.php:360-377). The gateway-scoped read then
	 * returns the stored tokens the provider still has plus the new ones (`:199-250`), so the SEPA gateway's own token
	 * selector, such as Edit Subscription's, lists them (review 36 F2).
	 *
	 * @testdox Should sync a disabled SEPA gateway's saved methods with the provider and return them to that gateway.
	 */
	public function test_reconcile_syncs_disabled_sepa_tokens_for_their_gateway_and_returns_them(): void {
		$user_id    = $this->factory()->user->create();
		$sepa_token = $this->create_sepa_token( $user_id, 'pm_kept_sepa' );
		$gone_token = $this->create_sepa_token( $user_id, 'pm_detached_sepa' );

		$this->register_token_class_map();
		wp_set_current_user( $user_id );
		$customer_service = $this->create_reconciling_customer_service(
			'cus_1',
			array(
				'sepa_debit' => array(
					array(
						'id'         => 'pm_kept_sepa',
						'type'       => 'sepa_debit',
						'sepa_debit' => array( 'last4' => '6789' ),
					),
					array(
						'id'         => 'pm_new_sepa',
						'type'       => 'sepa_debit',
						'sepa_debit' => array( 'last4' => '3000' ),
					),
				),
			)
		);
		$sut              = $this->create_service( array(), null, $customer_service, $this->create_account_service_with_enabled_methods( array( 'card' ) ) );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token filter.
		$tokens = apply_filters(
			'woocommerce_get_customer_payment_tokens',
			array(
				$sepa_token->get_id() => $sepa_token,
				$gone_token->get_id() => $gone_token,
			),
			$user_id,
			'woocommerce_payments_sepa_debit'
		);
		remove_filter( 'woocommerce_get_customer_payment_tokens', array( $sut, 'handle_woocommerce_get_customer_payment_tokens' ), 10 );
		$stored = array_map(
			static fn( $token ) => $token->get_token(),
			WC_Payment_Tokens::get_customer_tokens( $user_id, 'woocommerce_payments_sepa_debit' )
		);
		sort( $stored );

		$this->assertSame( array( 'sepa_debit' => 1 ), $customer_service->fetch_counts, 'The SEPA gateway must fetch SEPA even while SEPA is disabled.' );
		$this->assertSame( array( 'pm_kept_sepa', 'pm_new_sepa' ), $stored, 'Local SEPA tokens must match the provider: a new one added, a detached one removed.' );
		$returned = array_map( static fn( $token ) => $token->get_token(), array_values( $tokens ) );
		sort( $returned );
		$this->assertSame( array( 'pm_kept_sepa', 'pm_new_sepa' ), $returned, 'The SEPA gateway gets its synced tokens back while SEPA is disabled, as on the client.' );
	}

	/**
	 * @testdox Should hide disabled tokens from settings when reconciliation cannot reach the provider.
	 */
	public function test_reconcile_hides_disabled_tokens_when_reconciliation_skips_or_provider_fetch_fails(): void {
		$this->register_card_gateway_id();
		$user_id    = $this->factory()->user->create();
		$sepa_token = $this->create_sepa_token( $user_id, 'pm_disabled_sepa' );

		$this->register_token_class_map();
		$customer_service               = $this->create_reconciling_customer_service( 'cus_1', array() );
		$customer_service->fail_fetches = true;
		$this->create_service( array(), null, $customer_service, $this->create_account_service_with_enabled_methods( array( 'card' ) ) );

		wp_set_current_user( 0 );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token filter.
		$skipped_tokens = apply_filters( 'woocommerce_get_customer_payment_tokens', array( $sepa_token->get_id() => $sepa_token ), $user_id, '' );

		wp_set_current_user( $user_id );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token filter.
		$failed_tokens = apply_filters( 'woocommerce_get_customer_payment_tokens', array( $sepa_token->get_id() => $sepa_token ), $user_id, '' );
		$stored_token  = \WC_Payment_Tokens::get( $sepa_token->get_id() );

		$this->assertArrayNotHasKey( $sepa_token->get_id(), $skipped_tokens, 'Settings-disabled tokens must stay hidden when reconciliation is skipped.' );
		$this->assertArrayNotHasKey( $sepa_token->get_id(), $failed_tokens, 'Settings-disabled tokens must stay hidden when the provider fetch fails.' );
		$this->assertInstanceOf( WooPaymentsSepaToken::class, $stored_token, 'A provider outage must not delete settings-disabled tokens.' );
		$this->assertSame( '', $stored_token->get_meta( '_wcpay_payment_method_disabled', true ), 'Filtering from settings must not persist token metadata.' );
	}

	/**
	 * @testdox Should restore a disabled SEPA token after re-enable without token or cache writes.
	 */
	public function test_reconcile_restores_disabled_sepa_token_after_reenable_without_writes(): void {
		$this->register_card_gateway_id();
		$user_id    = $this->factory()->user->create();
		$sepa_token = $this->create_sepa_token( $user_id, 'pm_reenabled_sepa' );
		$sepa_data  = array(
			'id'         => 'pm_reenabled_sepa',
			'type'       => 'sepa_debit',
			'sepa_debit' => array( 'last4' => '6789' ),
		);

		$this->register_token_class_map();
		wp_set_current_user( $user_id );
		$customer_service = $this->create_reconciling_customer_service(
			'cus_1',
			array(
				'card'       => array(),
				'sepa_debit' => array( $sepa_data ),
			)
		);
		update_user_meta(
			$user_id,
			'_wcpay_payment_methods',
			array(
				'customer_id'               => 'cus_1',
				'payment_method_card'       => array(),
				'payment_method_sepa_debit' => array( $sepa_data ),
			)
		);
		$updated_token_ids   = array();
		$record_token_update = static function ( int $token_id ) use ( &$updated_token_ids ): void {
			$updated_token_ids[] = $token_id;
		};
		add_action( 'woocommerce_payment_token_updated', $record_token_update );

		try {
			$disabled_service = $this->create_service( array(), null, $customer_service, $this->create_account_service_with_enabled_methods( array( 'card' ) ) );
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token filter.
			$disabled_tokens = apply_filters( 'woocommerce_get_customer_payment_tokens', array( $sepa_token->get_id() => $sepa_token ), $user_id, '' );
			$disabled_cache  = get_user_meta( $user_id, '_wcpay_payment_methods', true );

			remove_filter( 'woocommerce_get_customer_payment_tokens', array( $disabled_service, 'handle_woocommerce_get_customer_payment_tokens' ), 10 );
			$enabled_service = $this->create_service( array(), null, $customer_service, $this->create_account_service_with_enabled_methods( array( 'card', 'sepa_debit' ) ) );
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token filter.
			$enabled_tokens = apply_filters( 'woocommerce_get_customer_payment_tokens', array( $sepa_token->get_id() => $sepa_token ), $user_id, '' );
			$stored_token   = \WC_Payment_Tokens::get( $sepa_token->get_id() );
		} finally {
			remove_action( 'woocommerce_payment_token_updated', $record_token_update );
		}

		$this->assertArrayNotHasKey( $sepa_token->get_id(), $disabled_tokens, 'Disabled SEPA tokens must stay hidden while their type is disabled.' );
		$this->assertSame( array(), $disabled_cache['payment_method_card'] ?? null, 'Disabling SEPA must retain unrelated cached card data.' );
		$this->assertSame( array( $sepa_data ), $disabled_cache['payment_method_sepa_debit'] ?? null, 'Disabled-state filtering must not mutate the existing provider cache.' );
		$this->assertArrayHasKey( $sepa_token->get_id(), $enabled_tokens, 'Changing the enabled-method setting must immediately restore the token to listings.' );
		$this->assertInstanceOf( WooPaymentsSepaToken::class, $stored_token, 'A re-enabled token must remain stored.' );
		$this->assertSame( '', $stored_token->get_meta( '_wcpay_payment_method_disabled', true ), 'Re-enable must not need persisted marker cleanup.' );
		$this->assertSame( array(), $updated_token_ids, 'Disable and re-enable listing reads must not write to the payment token.' );
		$this->assertSame( array(), $customer_service->fetch_counts, 'The unchanged provider cache may satisfy reconciliation after re-enable.' );

		remove_filter( 'woocommerce_get_customer_payment_tokens', array( $enabled_service, 'handle_woocommerce_get_customer_payment_tokens' ), 10 );
	}

	/**
	 * @testdox Should hide disabled SEPA without changing cached provider data when another provider fetch fails.
	 */
	public function test_reconcile_hides_disabled_sepa_without_changing_cache_when_provider_fetch_fails(): void {
		$this->register_card_gateway_id();
		$user_id    = $this->factory()->user->create();
		$sepa_token = $this->create_sepa_token( $user_id, 'pm_failed_cache_sepa' );
		$sepa_data  = array(
			'id'         => 'pm_failed_cache_sepa',
			'type'       => 'sepa_debit',
			'sepa_debit' => array( 'last4' => '6789' ),
		);

		$this->register_token_class_map();
		wp_set_current_user( $user_id );
		$customer_service = $this->create_reconciling_customer_service(
			'cus_1',
			array(
				'card'       => array(),
				'link'       => array(),
				'sepa_debit' => array( $sepa_data ),
			)
		);
		update_user_meta(
			$user_id,
			'_wcpay_payment_methods',
			array(
				'customer_id'               => 'cus_1',
				'payment_method_card'       => array(),
				'payment_method_sepa_debit' => array( $sepa_data ),
			)
		);

		$customer_service->fail_fetches = true;
		$disabled_service               = $this->create_service( array(), null, $customer_service, $this->create_account_service_with_enabled_methods( array( 'card', 'link' ) ) );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token filter.
		$disabled_tokens = apply_filters( 'woocommerce_get_customer_payment_tokens', array( $sepa_token->get_id() => $sepa_token ), $user_id, '' );
		$failed_cache    = get_user_meta( $user_id, '_wcpay_payment_methods', true );

		$customer_service->fail_fetches = false;
		remove_filter( 'woocommerce_get_customer_payment_tokens', array( $disabled_service, 'handle_woocommerce_get_customer_payment_tokens' ), 10 );
		$enabled_service = $this->create_service( array(), null, $customer_service, $this->create_account_service_with_enabled_methods( array( 'card', 'link', 'sepa_debit' ) ) );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token filter.
		$enabled_tokens = apply_filters( 'woocommerce_get_customer_payment_tokens', array( $sepa_token->get_id() => $sepa_token ), $user_id, '' );
		$stored_token   = \WC_Payment_Tokens::get( $sepa_token->get_id() );

		$this->assertArrayNotHasKey( $sepa_token->get_id(), $disabled_tokens, 'Disabled tokens must remain hidden when the provider fetch fails.' );
		$this->assertSame( array(), $failed_cache['payment_method_card'] ?? null, 'A failed provider fetch must retain unrelated cached card data.' );
		$this->assertSame( array( $sepa_data ), $failed_cache['payment_method_sepa_debit'] ?? null, 'Settings-derived filtering must not change cached SEPA data.' );
		$this->assertArrayHasKey( $sepa_token->get_id(), $enabled_tokens, 'The matching token must return after re-enable and provider recovery.' );
		$this->assertInstanceOf( WooPaymentsSepaToken::class, $stored_token, 'The recovered token must remain stored.' );
		$this->assertSame( '', $stored_token->get_meta( '_wcpay_payment_method_disabled', true ), 'Provider recovery must not need persisted marker cleanup.' );
		$this->assertSame(
			array(
				'link' => 1,
			),
			$customer_service->fetch_counts,
			'Re-enable may use unchanged cached SEPA data after the unrelated provider request recovers.'
		);

		remove_filter( 'woocommerce_get_customer_payment_tokens', array( $enabled_service, 'handle_woocommerce_get_customer_payment_tokens' ), 10 );
	}

	/**
	 * @testdox Should delete a local token only after an enabled provider response proves it detached.
	 */
	public function test_reconcile_deletes_tokens_absent_from_an_authoritative_enabled_response(): void {
		$this->register_card_gateway_id();
		$user_id    = $this->factory()->user->create();
		$sepa_token = $this->create_sepa_token( $user_id, 'pm_detached_sepa' );
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

		$this->register_token_class_map();
		wp_set_current_user( $user_id );
		$customer_service = $this->create_reconciling_customer_service(
			'cus_1',
			array(
				'card'       => array(),
				'sepa_debit' => array(),
			)
		);
		$this->create_service( array(), $api_client, $customer_service, $this->create_account_service_with_enabled_methods( array( 'card', 'sepa_debit' ) ) );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token filter.
		$tokens = apply_filters( 'woocommerce_get_customer_payment_tokens', array( $sepa_token->get_id() => $sepa_token ), $user_id, '' );

		$this->assertArrayNotHasKey( $sepa_token->get_id(), $tokens, 'Tokens absent from an authoritative provider response must not be listed.' );
		$this->assertNull( \WC_Payment_Tokens::get( $sepa_token->get_id() ), 'A token absent from an enabled-type provider response must be deleted locally.' );
		$this->assertSame(
			array(
				'card'       => 1,
				'sepa_debit' => 1,
			),
			$customer_service->fetch_counts,
			'Deletion requires the enabled token type to be retrieved from the provider.'
		);
		$this->assertSame( array(), $api_client->detached_payment_method_ids, 'Authoritative local pruning must not attempt a remote detach.' );
	}

	/**
	 * @testdox Should reject settings-disabled tokens at checkout while retaining order-attached renewal resolution.
	 */
	public function test_rejects_settings_disabled_tokens_for_direct_checkout_but_resolves_them_for_order_renewals(): void {
		$user_id      = $this->factory()->user->create();
		$sepa_token   = $this->create_sepa_token( $user_id, 'pm_renewal_sepa' );
		$order        = wc_create_order();
		$disabled_sut = $this->create_service( array(), null, null, $this->create_account_service_with_enabled_methods( array( 'card' ) ) );
		$enabled_sut  = $this->create_service( array(), null, null, $this->create_account_service_with_enabled_methods( array( 'card', 'sepa_debit' ) ) );

		$this->register_token_class_map();
		$order->add_payment_token( $sepa_token );
		$order->save();

		$this->assertSame( '', $disabled_sut->resolve_payment_method_id_from_token_id( (string) $sepa_token->get_id(), $user_id ), 'Settings-disabled tokens must not resolve from direct shopper checkout input.' );
		$this->assertSame( '', $disabled_sut->resolve_payment_method_type_from_token_id( (string) $sepa_token->get_id(), $user_id ), 'Settings-disabled tokens must not resolve a direct shopper payment-method type.' );
		$this->assertSame( 'pm_renewal_sepa', $disabled_sut->resolve_payment_method_id_from_order_token_id( (string) $sepa_token->get_id(), $order ), 'Order-attached renewal resolution must retain the provider payment method ID.' );
		$this->assertSame( 'sepa_debit', $disabled_sut->resolve_payment_method_type_from_order_token_id( (string) $sepa_token->get_id(), $order ), 'Order-attached renewal resolution must retain the payment method type.' );
		$this->assertSame( 'pm_renewal_sepa', $enabled_sut->resolve_payment_method_id_from_token_id( (string) $sepa_token->get_id(), $user_id ), 'Re-enable must immediately restore direct shopper token resolution.' );
		$this->assertSame( 'sepa_debit', $enabled_sut->resolve_payment_method_type_from_token_id( (string) $sepa_token->get_id(), $user_id ), 'Re-enable must immediately restore the direct shopper payment-method type.' );
	}

	/**
	 * @testdox Saving a new token should bust the cached provider list so reconciliation cannot delete it.
	 */
	public function test_reconcile_keeps_tokens_created_after_the_cache_was_warmed(): void {
		$this->register_card_gateway_id();
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
		$this->register_card_gateway_id();
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
		$this->register_card_gateway_id();
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );
		$local_token = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_local' );

		$customer_service               = $this->create_reconciling_customer_service( 'cus_1', array() );
		$customer_service->fail_fetches = true;
		$this->create_service( array(), null, $customer_service, $this->create_account_service_with_enabled_methods( array( 'card' ) ) );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token filter.
		$tokens = apply_filters( 'woocommerce_get_customer_payment_tokens', array( $local_token->get_id() => $local_token ), $user_id, '' );

		$this->assertSame( 1, $customer_service->failed_fetch_attempts, 'The provider fetch must have been attempted.' );
		$this->assertArrayHasKey( $local_token->get_id(), $tokens, 'A provider outage must degrade to the locally stored list.' );
		$this->assertNotNull( \WC_Payment_Tokens::get( $local_token->get_id() ), 'A provider outage must not delete local tokens.' );
	}

	/**
	 * @testdox A platform error fetching the customer's payment methods is logged with its status and code, never its message.
	 */
	public function test_reconcile_fetch_failure_log_leaves_out_platform_text(): void {
		self::enable_woopayments_debug_logging();
		$this->register_card_gateway_id();
		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );
		$local_token                     = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_local' );
		$customer_service                = $this->create_reconciling_customer_service( 'cus_1', array() );
		$customer_service->fail_fetches  = true;
		$customer_service->fetch_failure = self::make_provider_error();
		$this->create_service( array(), null, $customer_service, $this->create_account_service_with_enabled_methods( array( 'card' ) ) );
		$logger = RecordingWcLogger::install();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token filter.
		apply_filters( 'woocommerce_get_customer_payment_tokens', array( $local_token->get_id() => $local_token ), $user_id, '' );

		$context = $this->get_logged_context( $logger, 'Failed to fetch payment methods for customer.' );
		$this->assertSame( array( 404, 'resource_missing' ), array( $context['http_status'], $context['error_code'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * @testdox A platform error detaching a deleted token's payment method is logged with its status and code, never its message.
	 */
	public function test_detach_failure_log_leaves_out_platform_text(): void {
		self::enable_woopayments_debug_logging();
		$user_id    = $this->factory()->user->create();
		$api_client = new class() extends WooPaymentsApiClient {
			/**
			 * Fail the detach as the platform does.
			 *
			 * @param string $payment_method_id Payment method ID.
			 * @throws \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException Always.
			 */
			public function detach_payment_method( string $payment_method_id ): array {
				unset( $payment_method_id );
				throw WooPaymentsTokenServiceTest::make_provider_error();
			}
		};
		$sut        = $this->create_service(
			array(
				'pm_sepa' => array(
					'id'         => 'pm_sepa',
					'type'       => 'sepa_debit',
					'sepa_debit' => array( 'last4' => '6789' ),
				),
			),
			$api_client,
			null,
			$this->create_account_service( true )
		);
		$token      = $sut->get_or_create_token_for_user( 'pm_sepa', $user_id );
		$logger     = RecordingWcLogger::install();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token deletion hook.
		do_action( 'woocommerce_payment_token_deleted', $token->get_id(), $token );

		$context = $this->get_logged_context( $logger, 'Error detaching payment method.' );
		$this->assertSame( array( 404, 'resource_missing' ), array( $context['http_status'], $context['error_code'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * @testdox A platform error setting the customer's default payment method is logged with its status and code, never its message.
	 */
	public function test_set_default_failure_log_leaves_out_platform_text(): void {
		self::enable_woopayments_debug_logging();
		$user_id          = $this->factory()->user->create();
		$customer_service = new class() extends WooPaymentsCustomerService {
			/**
			 * Get a customer ID for a user.
			 *
			 * @param int|null $user_id User ID.
			 * @return string|null
			 */
			public function get_customer_id_by_user_id( ?int $user_id ): ?string {
				unset( $user_id );
				return 'cus_test';
			}

			/**
			 * Fail the update as the platform does.
			 *
			 * @param string $customer_id       Customer ID.
			 * @param string $payment_method_id Payment method ID.
			 * @return void
			 */
			public function set_default_payment_method_for_customer( string $customer_id, string $payment_method_id ): void {
				unset( $customer_id, $payment_method_id );
				throw WooPaymentsTokenServiceTest::make_provider_error();
			}
		};
		$sut              = $this->create_service(
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
		$token            = $sut->get_or_create_token_for_user( 'pm_sepa', $user_id );
		$logger           = RecordingWcLogger::install();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered default-token hook.
		do_action( 'woocommerce_payment_token_set_default', $token->get_id(), $token );

		$context = $this->get_logged_context( $logger, 'Error setting native WooPayments default payment method.' );
		$this->assertSame( array( 404, 'resource_missing' ), array( $context['http_status'], $context['error_code'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * @testdox Reconciliation should re-register the token filter after adding tokens.
	 */
	public function test_reconcile_reregisters_the_filter_after_adding_tokens(): void {
		$this->register_card_gateway_id();
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
		$this->register_card_gateway_id();
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
	 * @testdox Empty token listings should not read enabled-method settings.
	 */
	public function test_empty_token_listing_does_not_read_enabled_method_settings(): void {
		$user_id = $this->factory()->user->create();
		wp_set_current_user( 0 );
		$this->create_service( array(), null, null, $this->create_account_service_that_must_not_read_enabled_methods() );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered token filter.
		$tokens = apply_filters( 'woocommerce_get_customer_payment_tokens', array(), $user_id, '' );

		$this->assertSame( array(), $tokens );
	}

	/**
	 * @testdox Reconciliation should not run once the unpaginated token page is full.
	 */
	public function test_reconcile_skips_at_the_token_page_limit(): void {
		$this->register_card_gateway_id();
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
		$this->create_service( array(), null, null, $this->create_account_service_with_enabled_methods( array( 'card', 'link', 'sepa_debit' ) ) );

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
	 * @testdox A card-gateway read leaves out a saved Link token while Link is disabled.
	 *
	 * Link rides the card gateway, so client 11.1.0 retrieves it for that gateway only while Link is enabled
	 * (`class-wc-payments-token-service.php:366-369`) and drops the stored Link token from the read (`:199-250`). Native
	 * keeps the token stored and leaves it out of the read, so the card gateway's checkout and selectors match the client.
	 */
	public function test_card_gateway_read_leaves_out_link_tokens_while_link_is_disabled(): void {
		$user_id    = $this->factory()->user->create();
		$card_token = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_card' );
		$link_token = new WooPaymentsLinkToken();
		$link_token->set_gateway_id( OrderPaymentStore::GATEWAY_ID );
		$link_token->set_user_id( $user_id );
		$link_token->set_token( 'pm_link' );
		$link_token->set_email( 'buyer@example.com' );
		$link_token->save();
		$this->create_service( array(), null, null, $this->create_account_service_with_enabled_methods( array( 'card' ) ) );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered customer-token filter.
		$result = apply_filters(
			'woocommerce_get_customer_payment_tokens',
			array(
				$card_token->get_id() => $card_token,
				$link_token->get_id() => $link_token,
			),
			$user_id,
			OrderPaymentStore::GATEWAY_ID
		);

		$this->assertSame( array( $card_token->get_id() ), array_keys( $result ) );
	}

	/**
	 * @testdox A card-gateway read leaves out a SEPA token stored under the card gateway while SEPA is disabled.
	 *
	 * Client 11.1.0 fetches only the card gateway's own types for a card-gateway read (`class-wc-payments-token-service.php:360-377`)
	 * and drops every stored token it did not fetch (`:199-250`), so a SEPA token filed under the card gateway never reaches
	 * the card checkout. Native keeps the row and leaves it out while SEPA is disabled (review 37 F3).
	 */
	public function test_card_gateway_read_leaves_out_a_sepa_token_filed_under_the_card_gateway_while_sepa_is_disabled(): void {
		$user_id    = $this->factory()->user->create();
		$card_token = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_card' );
		$sepa_token = new WooPaymentsSepaToken();
		$sepa_token->set_gateway_id( OrderPaymentStore::GATEWAY_ID );
		$sepa_token->set_user_id( $user_id );
		$sepa_token->set_token( 'pm_sepa_under_card' );
		$sepa_token->set_last4( '6789' );
		$sepa_token->save();
		$this->create_service( array(), null, null, $this->create_account_service_with_enabled_methods( array( 'card' ) ) );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test exercises the registered customer-token filter.
		$result = apply_filters(
			'woocommerce_get_customer_payment_tokens',
			array(
				$card_token->get_id() => $card_token,
				$sepa_token->get_id() => $sepa_token,
			),
			$user_id,
			OrderPaymentStore::GATEWAY_ID
		);

		$this->assertSame( array( $card_token->get_id() ), array_keys( $result ) );
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
		// My Account and the Store API render the brand through core's label helper, which title-cases unknown brands;
		// the client restores its own casing (client 11.1.0 `includes/class-wc-payments-token-service.php:65-66,529-549`).
		$this->assertSame( $expected_method['brand'], wc_get_credit_card_type_label( $result['method']['brand'] ), 'The rendered label must keep the brand casing.' );
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
	 * @testdox Should sync the token to every subscription created from one parent order, not only the first.
	 */
	public function test_syncs_token_to_every_subscription_created_from_one_parent_order(): void {
		$this->ensure_wcs_subscriptions_for_order_double();
		$user_id = $this->factory()->user->create();
		$token   = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_multi' );
		$parent  = $this->create_woopayments_order();
		$monthly = $this->create_woopayments_order();
		$yearly  = $this->create_woopayments_order();

		// Related through the same parent order (for example via a later gateway switch), but
		// not paid through WooPayments: the client's own token-repair comment in
		// trait-wc-payment-gateway-wcpay-subscriptions.php warns against "silently re-pointing
		// sibling subscriptions the customer has since moved to a different [gateway]", so this
		// one must be left untouched.
		$other_gateway = wc_create_order();
		$other_gateway->set_payment_method( 'bacs' );
		$other_gateway->save();

		$relationships = array(
			'parent' => array( $monthly->get_id(), $yearly->get_id(), $other_gateway->get_id() ),
		);

		$GLOBALS['wcpay_test_order_subscription_relationships'] = array( $parent->get_id() => $relationships );

		$sut     = $this->create_service();
		$related = $sut->get_related_subscriptions_for_order( $parent );
		$this->assertCount( 3, $related, 'The subscriptions lookup double must return all three subscriptions created from the parent order, or the rest of this test is vacuous.' );
		$this->assertSame(
			3,
			count( array_unique( array_map( static fn( WC_Order $subscription ): int => $subscription->get_id(), $related ) ) ),
			'The lookup double must return three distinct subscriptions.'
		);

		$sut->sync_related_subscriptions_payment_token( $parent, $token, 'pm_multi', 'cus_multi' );

		$monthly       = wc_get_order( $monthly->get_id() );
		$yearly        = wc_get_order( $yearly->get_id() );
		$other_gateway = wc_get_order( $other_gateway->get_id() );
		$this->assertInstanceOf( WC_Order::class, $monthly );
		$this->assertInstanceOf( WC_Order::class, $yearly );
		$this->assertInstanceOf( WC_Order::class, $other_gateway );
		$monthly_active_token = $sut->get_active_token_for_order( $monthly );
		$yearly_active_token  = $sut->get_active_token_for_order( $yearly );
		$this->assertInstanceOf( \WC_Payment_Token::class, $monthly_active_token, 'The monthly subscription should carry the token as its active token.' );
		$this->assertInstanceOf( \WC_Payment_Token::class, $yearly_active_token, 'The yearly subscription should carry the token as its active token.' );
		$this->assertSame( $token->get_id(), $monthly_active_token->get_id() );
		$this->assertSame( $token->get_id(), $yearly_active_token->get_id() );
		$this->assertSame( array(), $other_gateway->get_payment_tokens(), 'A related subscription paid through another gateway must not receive the WooPayments token.' );
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
	 * @testdox The filtered read should pick the customer id with the plugin's mode rule, not the account's liveness.
	 * @dataProvider provide_mode_cases
	 *
	 * Source: client 11.1.0 class-wc-payments-token-service.php:199-256 leaves tokens the provider still holds untouched;
	 * class-wc-payments-customer-service.php:392-396 keys the customer id by WC_Payments::mode()->is_test(),
	 * which reads the gateway test_mode setting unless onboarding test mode is on (includes/core/class-mode.php maybe_init()).
	 *
	 * @param string $onboarding_test_mode Onboarding test mode option value.
	 * @param string $test_mode_setting    Gateway settings test_mode value.
	 * @param bool   $account_is_live      Cached account liveness.
	 * @param string $mode_customer_id     Customer id the client's mode rule selects.
	 */
	public function test_filtered_read_resolves_customer_id_with_the_plugin_mode_rule( string $onboarding_test_mode, string $test_mode_setting, bool $account_is_live, string $mode_customer_id ): void {
		add_filter( 'wcpay_dev_mode', '__return_false' );
		update_option( 'wcpay_onboarding_test_mode', $onboarding_test_mode );
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => $test_mode_setting ) );
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id' => 'acct_mode',
					'is_live'    => $account_is_live,
				),
				'fetched' => time(),
				'errored' => false,
			)
		);

		$user_id = $this->factory()->user->create();

		list( $first, $second ) = $this->create_plugin_era_cards_with_second_default( $user_id );
		update_user_option( $user_id, WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, 'cus_test' );
		update_user_option( $user_id, WooPaymentsCustomerService::LIVE_CUSTOMER_ID_OPTION, 'cus_live' );
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		$api_client = new class( $mode_customer_id, array( $this->card_payment_method( 'pm_plugin_first' ), $this->card_payment_method( 'pm_plugin_second' ) ) ) extends WooPaymentsApiClient {
			/**
			 * Customer id that holds the cards.
			 *
			 * @var string
			 */
			private string $holder;

			/**
			 * Cards held by that customer.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $cards;

			/**
			 * Constructor.
			 *
			 * @param string                         $holder Customer id that holds the cards.
			 * @param array<int,array<string,mixed>> $cards  Cards held by that customer.
			 */
			public function __construct( string $holder, array $cards ) {
				$this->holder = $holder;
				$this->cards  = $cards;
			}

			/**
			 * List a customer's payment methods.
			 *
			 * @param string $customer_id Customer id.
			 * @param string $type        Payment method type.
			 * @param int    $limit       Page size.
			 * @return array<string,mixed>
			 */
			public function get_payment_methods( string $customer_id, string $type, int $limit = 100 ): array {
				unset( $limit );

				return array( 'data' => $customer_id === $this->holder && 'card' === $type ? $this->cards : array() );
			}
		};

		// A connected store: without a connection the account read returns no account, like the client.
		$connected_api_client = $this->createMock( WooPaymentsApiClient::class );
		$connected_api_client->method( 'is_available' )->willReturn( true );
		wc_get_container()->replace( WooPaymentsApiClient::class, $connected_api_client );
		$account_service = new WooPaymentsAccountService();
		$account_service->init( new LegacyProxy() );
		$customer_service = new WooPaymentsCustomerService();
		$customer_service->init( $api_client, $account_service, new WooPaymentsSessionService() );
		$this->create_service( array(), $api_client, $customer_service, $account_service );

		$this->assertSame( $account_is_live, $account_service->get_account_is_live(), 'The fixture must hold a valid cached account with this liveness.' );
		$this->assert_plugin_era_default_survives_filtered_read( $user_id, $first->get_id(), $second->get_id() );
	}

	/**
	 * Mode inputs paired with the customer id the client's mode rule selects.
	 *
	 * A cached test account is only valid under onboarding test mode (client includes/class-wc-payments-account.php:2554-2590),
	 * which then forces test mode, so the live-setting row pairs a test account with onboarding test mode.
	 *
	 * @return array<string,array{0:string,1:string,2:bool,3:string}>
	 */
	public function provide_mode_cases(): array {
		return array(
			'onboarding test mode overrides a live setting' => array( 'yes', 'no', false, 'cus_test' ),
			'test setting on a live account' => array( 'no', 'yes', true, 'cus_test' ),
			'live setting on a live account' => array( 'no', 'no', true, 'cus_live' ),
		);
	}

	/**
	 * @testdox A cached provider list for the same customer that lacks a stored card should drop that card, as the plugin does.
	 *
	 * Source: client 11.1.0 class-wc-payments-token-service.php:271-290 serves `_wcpay_payment_methods` for a matching customer id,
	 * and :241-247 deletes every stored token the list does not name.
	 */
	public function test_filtered_read_with_a_stale_same_customer_cache_drops_the_missing_card(): void {
		$user_id = $this->factory()->user->create();

		list( $first, $second ) = $this->create_plugin_era_cards_with_second_default( $user_id );
		update_user_meta(
			$user_id,
			'_wcpay_payment_methods',
			array(
				'customer_id'         => 'cus_1',
				'payment_method_card' => array( $this->card_payment_method( 'pm_plugin_first' ) ),
			)
		);
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		$customer_service = $this->create_reconciling_customer_service( 'cus_1', array( 'card' => array( $this->card_payment_method( 'pm_plugin_first' ), $this->card_payment_method( 'pm_plugin_second' ) ) ) );
		$this->create_service( array(), null, $customer_service, $this->create_account_service_with_enabled_methods( array( 'card' ) ) );

		$tokens = WC_Payment_Tokens::get_customer_tokens( $user_id );

		$this->assertSame( array( $first->get_id() ), array_keys( $tokens ), 'Only the card the cached list names must remain.' );
		$this->assertNull( WC_Payment_Tokens::get( $second->get_id() ), 'The card missing from the cached list is deleted, default or not.' );
		$this->assertSame( array(), $customer_service->fetch_counts, 'A cache for the same customer is served without a provider call.' );
	}

	/**
	 * @testdox A filtered read of all gateways should write no token rows while the card gateway is not registered.
	 *
	 * Source: client 11.1.0 class-wc-payments.php:581, :730 and :961 build the token filter and register the card
	 * gateway together, so the plugin never syncs a read from which core hid the stored card rows.
	 */
	public function test_filtered_read_writes_nothing_when_the_card_gateway_is_not_registered(): void {
		global $wpdb;

		$user_id = $this->factory()->user->create();
		$first   = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_plugin_first' );
		$second  = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_plugin_second' );
		WC_Payment_Tokens::set_users_default( $user_id, $second->get_id() );
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		$customer_service = $this->create_reconciling_customer_service( 'cus_1', array( 'card' => array( $this->card_payment_method( 'pm_plugin_first' ), $this->card_payment_method( 'pm_plugin_second' ) ) ) );
		$this->create_service( array(), null, $customer_service, $this->create_account_service_with_enabled_methods( array( 'card' ) ) );
		$this->assertNotContains( OrderPaymentStore::GATEWAY_ID, WC()->payment_gateways()->get_payment_gateway_ids(), 'The fixture must leave the card gateway unregistered.' );

		$tokens = WC_Payment_Tokens::get_customer_tokens( $user_id );

		$this->assertSame( array(), $tokens, 'Core hides the unregistered gateway rows and the filter must not add any.' );
		$this->assertSame(
			array( (string) $first->get_id(), (string) $second->get_id() ),
			$wpdb->get_col( $wpdb->prepare( "SELECT token_id FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE user_id = %d ORDER BY token_id", $user_id ) ),
			'The read must not persist new token rows for the provider cards.'
		);
	}

	/**
	 * Create two plugin-era card tokens on the card gateway, the second one default.
	 *
	 * @param int $user_id Shopper user ID.
	 * @return WC_Payment_Token_CC[]
	 */
	private function create_plugin_era_cards_with_second_default( int $user_id ): array {
		$this->register_card_gateway_id();
		$first  = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_plugin_first' );
		$second = $this->create_card_token( $user_id, OrderPaymentStore::GATEWAY_ID, 'pm_plugin_second' );
		WC_Payment_Tokens::set_users_default( $user_id, $second->get_id() );

		return array( $first, $second );
	}

	/**
	 * Register a gateway with the card gateway ID, as a native request does before any token read.
	 *
	 * WC_Payment_Token_Data_Store::get_tokens() only returns tokens whose gateway is registered.
	 */
	private function register_card_gateway_id(): void {
		$this->gateway_initializer = static function ( \WC_Payment_Gateways $wc_payment_gateways ): void {
			$gateway = new class() extends \WC_Payment_Gateway {
				/**
				 * Constructor.
				 */
				public function __construct() {
					$this->id = OrderPaymentStore::GATEWAY_ID;
				}
			};

			$wc_payment_gateways->payment_gateways = array( $gateway );
		};
		add_action( 'wc_payment_gateways_initialized', $this->gateway_initializer, 100 );
		WC()->payment_gateways()->payment_gateways = array();
		WC()->payment_gateways()->init();
	}

	/**
	 * Assert the filtered read returns the same two tokens with the second still default, in memory and in the table.
	 *
	 * @param int $user_id   Shopper user ID.
	 * @param int $first_id  First token ID.
	 * @param int $second_id Second (default) token ID.
	 */
	private function assert_plugin_era_default_survives_filtered_read( int $user_id, int $first_id, int $second_id ): void {
		global $wpdb;

		$tokens = WC_Payment_Tokens::get_customer_tokens( $user_id );
		$ids    = array_keys( $tokens );
		sort( $ids );

		$this->assertSame( array( $first_id, $second_id ), $ids, 'The read must return the same stored tokens, not re-created ones.' );
		$this->assertTrue( $tokens[ $second_id ]->is_default(), 'The plugin-era default must stay default in the returned object.' );
		$this->assertFalse( $tokens[ $first_id ]->is_default() );
		$this->assertSame(
			'1',
			(string) $wpdb->get_var( $wpdb->prepare( "SELECT is_default FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE token_id = %d", $second_id ) ),
			'The default column must still read 1 after the filtered read.'
		);
	}

	/**
	 * Build a provider card payment method.
	 *
	 * @param string $payment_method_id Provider payment method ID.
	 * @return array<string,mixed>
	 */
	private function card_payment_method( string $payment_method_id ): array {
		return array(
			'id'   => $payment_method_id,
			'type' => 'card',
			'card' => array(
				'brand'     => 'visa',
				'last4'     => '4242',
				'exp_month' => 12,
				'exp_year'  => 2030,
			),
		);
	}

	/**
	 * @testdox A PHP error fetching a payment method stops a new token and leaves display reads empty, logged whatever the setting.
	 *
	 * Client 11.1.0 add_payment_method_to_user() does not catch the fetch (`class-wc-payments-token-service.php:136`), so a
	 * PHP error stops the token, and every client caller catches only Exception (review 34 F1). Native keeps a display read
	 * going without the details, as it does for an Exception, and logs the error with its class. The token path rethrows
	 * without logging, so its caller writes the only line (review 35 F8).
	 */
	public function test_php_error_fetching_payment_method_stops_new_token_only(): void {
		$user_id = self::factory()->user->create();
		$error   = new \TypeError( 'Return value must be of type array, null returned' );
		$sut     = new WooPaymentsTokenService();
		$sut->init(
			new class( $error ) extends WooPaymentsPaymentMethodDetailsService {
				/**
				 * Error to throw.
				 *
				 * @var \TypeError
				 */
				private \TypeError $error;

				/**
				 * Constructor.
				 *
				 * @param \TypeError $error Error to throw.
				 */
				public function __construct( \TypeError $error ) {
					$this->error = $error;
				}

				// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- Test double always throws.
				/**
				 * Throw the PHP error.
				 *
				 * @param string $payment_method_id Payment method ID.
				 * @return array<string,mixed>
				 * @throws \TypeError Always.
				 */
				public function get_payment_method_details( string $payment_method_id ): array {
					unset( $payment_method_id );
					throw $this->error;
				}
				// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
			},
			new StaticNativeRuntimeArbiter( true ),
			wc_get_container()->get( WooPaymentsApiClient::class ),
			wc_get_container()->get( WooPaymentsCustomerService::class ),
			wc_get_container()->get( WooPaymentsAccountService::class )
		);
		$this->created_services[] = $sut;
		$logger                   = RecordingWcLogger::install();

		$this->assertSame( array(), $sut->get_payment_method_details_for_display( 'pm_display' ) );
		$thrown = null;
		try {
			$sut->get_or_create_token_for_user( 'pm_new_token', $user_id );
		} catch ( \Throwable $throwable ) {
			$thrown = $throwable;
		}

		$this->assertSame( $error, $thrown );
		$this->assertSame( array(), WC_Payment_Tokens::get_customer_tokens( $user_id ) );
		$lines = array_values( array_filter( $logger->lines, static fn( array $line ): bool => 'woopayments' === $line[2] && str_starts_with( $line[1], 'Error retrieving WooPayments payment method details for ' ) ) );
		$this->assertCount( 1, $lines, 'The display read logs the PHP error with debug logging off; the token path leaves it to its caller.' );
		$this->assertSame( 'error', $lines[0][0] );
		$this->assertStringContainsString( 'pm_display', $lines[0][1] );
		$this->assertSame( 'TypeError', $logger->contexts[ array_search( $lines[0], $logger->lines, true ) ]['exception'] ?? '' );
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
		$sut->init( $details_service, $arbiter ?? new StaticNativeRuntimeArbiter( true ), $api_client ?? wc_get_container()->get( WooPaymentsApiClient::class ), $customer_service ?? wc_get_container()->get( WooPaymentsCustomerService::class ), $account_service ?? wc_get_container()->get( WooPaymentsAccountService::class ) );
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
			 * What a failing fetch throws, when not the default.
			 *
			 * @var \Throwable|null
			 */
			public ?\Throwable $fetch_failure = null;

			/**
			 * Number of fetches that failed.
			 *
			 * @var int
			 */
			public int $failed_fetch_attempts = 0;

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
					++$this->failed_fetch_attempts;
					throw $this->fetch_failure ?? new RuntimeException( 'Provider unavailable.' );
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
	 * Create an account service double that rejects enabled-method settings reads.
	 *
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service_that_must_not_read_enabled_methods(): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_gateway_setting' ) )
			->getMock();
		$account_service->expects( $this->never() )->method( 'get_gateway_setting' );

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
	 * Ensure a WCS subscriptions-for-order double with WCS relationship defaults exists.
	 *
	 * @return void
	 */
	private function ensure_wcs_subscriptions_for_order_double(): void {
		if ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return;
		}

		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; tests need its public order lookup contract.
		eval( 'namespace { function wcs_get_subscriptions_for_order( $order_id, $args = array() ) { $order_id = is_object( $order_id ) && method_exists( $order_id, "get_id" ) ? $order_id->get_id() : absint( $order_id ); $order_types = $args["order_type"] ?? array( "parent", "switch" ); $order_types = is_array( $order_types ) ? $order_types : array( $order_types ); $relationships = $GLOBALS["wcpay_test_order_subscription_relationships"][ $order_id ] ?? array(); $order_types = in_array( "any", $order_types, true ) ? array_keys( $relationships ) : $order_types; $ids = array(); foreach ( $order_types as $order_type ) { $ids = array_merge( $ids, $relationships[ $order_type ] ?? array() ); } return array_values( array_filter( array_map( "wc_get_order", array_unique( array_map( "absint", $ids ) ) ) ) ); } }' );
	}

	/**
	 * Create a WooPayments test order.
	 *
	 * @return WC_Order
	 */
	private function create_woopayments_order(): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$order->save();

		return $order;
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
	 * Create a persisted SEPA token.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $token_id Provider token ID.
	 * @return WooPaymentsSepaToken
	 */
	private function create_sepa_token( int $user_id, string $token_id ): WooPaymentsSepaToken {
		$token = new WooPaymentsSepaToken();
		$token->set_gateway_id( OrderPaymentStore::GATEWAY_ID_PREFIX . 'sepa_debit' );
		$token->set_user_id( $user_id );
		$token->set_token( $token_id );
		$token->set_last4( '6789' );
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
