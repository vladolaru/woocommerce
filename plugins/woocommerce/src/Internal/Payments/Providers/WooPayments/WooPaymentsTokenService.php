<?php
/**
 * WooPaymentsTokenService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsAmazonPayToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsLinkToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsSepaToken;
use RuntimeException;
use Throwable;
use WC_Order;
use WC_Payment_Token;
use WC_Payment_Token_CC;
use WC_Payment_Tokens;

/**
 * Persists WooPayments card payment methods as WooCommerce payment tokens.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsTokenService {

	/**
	 * Preserved WooPayments cached payment-method user meta key.
	 *
	 * @var string
	 */
	private const CACHED_PAYMENT_METHODS_META_KEY = '_wcpay_payment_methods';

	private const CACHE_CLEAR_BATCH_SIZE = 500;

	private const PAYMENT_METHOD_TYPE_CARD = 'card';

	private const PAYMENT_METHOD_TYPE_CARD_PRESENT = 'card_present';

	private const PAYMENT_METHOD_TYPE_SEPA = 'sepa_debit';

	private const PAYMENT_METHOD_TYPE_LINK = 'link';

	private const PAYMENT_METHOD_TYPE_AMAZON_PAY = 'amazon_pay';

	private const GATEWAY_IDS_BY_PAYMENT_METHOD_TYPE = array(
		self::PAYMENT_METHOD_TYPE_CARD         => OrderPaymentStore::GATEWAY_ID,
		self::PAYMENT_METHOD_TYPE_CARD_PRESENT => OrderPaymentStore::GATEWAY_ID,
		self::PAYMENT_METHOD_TYPE_LINK         => OrderPaymentStore::GATEWAY_ID,
		self::PAYMENT_METHOD_TYPE_SEPA         => OrderPaymentStore::GATEWAY_ID_PREFIX . 'sepa_debit',
		self::PAYMENT_METHOD_TYPE_AMAZON_PAY   => OrderPaymentStore::GATEWAY_ID_PREFIX . 'amazon_pay',
	);

	private const RECONCILABLE_PAYMENT_METHOD_TYPES = array(
		self::PAYMENT_METHOD_TYPE_CARD,
		self::PAYMENT_METHOD_TYPE_SEPA,
		self::PAYMENT_METHOD_TYPE_LINK,
		self::PAYMENT_METHOD_TYPE_AMAZON_PAY,
	);

	private const PAYMENT_METHOD_TYPES_BY_TOKEN_TYPE = array(
		WooPaymentsSepaToken::TYPE      => self::PAYMENT_METHOD_TYPE_SEPA,
		WooPaymentsLinkToken::TYPE      => self::PAYMENT_METHOD_TYPE_LINK,
		WooPaymentsAmazonPayToken::TYPE => self::PAYMENT_METHOD_TYPE_AMAZON_PAY,
	);

	/**
	 * Payment method details service.
	 *
	 * @var WooPaymentsPaymentMethodDetailsService
	 */
	private WooPaymentsPaymentMethodDetailsService $payment_method_details_service;

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * Native API client.
	 *
	 * @var WooPaymentsApiClient|null
	 */
	private ?WooPaymentsApiClient $api_client = null;

	/**
	 * Native customer service.
	 *
	 * @var WooPaymentsCustomerService|null
	 */
	private ?WooPaymentsCustomerService $customer_service = null;

	/**
	 * Native account service.
	 *
	 * @var WooPaymentsAccountService|null
	 */
	private ?WooPaymentsAccountService $account_service = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsPaymentMethodDetailsService $payment_method_details_service Payment method details service.
	 * @param NativePaymentsRuntimeArbiter           $arbiter                        Runtime owner arbiter.
	 * @param WooPaymentsApiClient|null              $api_client                     Optional native API client.
	 * @param WooPaymentsCustomerService|null        $customer_service               Optional native customer service.
	 * @param WooPaymentsAccountService|null         $account_service                Optional native account service.
	 */
	final public function init( WooPaymentsPaymentMethodDetailsService $payment_method_details_service, NativePaymentsRuntimeArbiter $arbiter, ?WooPaymentsApiClient $api_client = null, ?WooPaymentsCustomerService $customer_service = null, ?WooPaymentsAccountService $account_service = null ): void {
		$this->payment_method_details_service = $payment_method_details_service;
		$this->arbiter                        = $arbiter;
		$this->api_client                     = $api_client;
		$this->customer_service               = $customer_service;
		$this->account_service                = $account_service;
		$this->register_hooks();
	}

	/**
	 * Register saved-payment-method lifecycle hooks.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		if ( false === has_action( 'woocommerce_payment_token_deleted', array( $this, 'handle_woocommerce_payment_token_deleted' ) ) ) {
			add_action( 'woocommerce_payment_token_deleted', array( $this, 'handle_woocommerce_payment_token_deleted' ), 10, 2 );
		}

		if ( false === has_action( 'woocommerce_payment_token_set_default', array( $this, 'handle_woocommerce_payment_token_set_default' ) ) ) {
			add_action( 'woocommerce_payment_token_set_default', array( $this, 'handle_woocommerce_payment_token_set_default' ), 10, 2 );
		}

		if ( false === has_filter( 'woocommerce_get_customer_payment_tokens', array( $this, 'handle_woocommerce_get_customer_payment_tokens' ) ) ) {
			add_filter( 'woocommerce_get_customer_payment_tokens', array( $this, 'handle_woocommerce_get_customer_payment_tokens' ), 10, 3 );
		}

		if ( false === has_filter( 'woocommerce_payment_methods_list_item', array( $this, 'handle_woocommerce_payment_methods_list_item' ) ) ) {
			add_filter( 'woocommerce_payment_methods_list_item', array( $this, 'handle_woocommerce_payment_methods_list_item' ), 10, 2 );
		}
	}

	/**
	 * Handle the woocommerce_payment_token_deleted hook.
	 *
	 * @internal
	 *
	 * @param int|string $token_id WooCommerce payment token ID.
	 * @param mixed      $token    Deleted payment token.
	 * @return void
	 */
	public function handle_woocommerce_payment_token_deleted( $token_id, $token ): void {
		unset( $token_id );

		if ( ! $this->is_supported_native_woopayments_token( $token ) ) {
			return;
		}

		if ( $this->should_skip_remote_detach_for_environment() ) {
			return;
		}

		$api_client = $this->get_api_client();
		if ( null !== $api_client ) {
			try {
				$api_client->detach_payment_method( (string) $token->get_token() );
			} catch ( Throwable $exception ) {
				wc_get_logger()->error(
					'Error detaching native WooPayments payment method: ' . $exception->getMessage(),
					array( 'source' => 'woopayments' )
				);
			}
		}

		$this->clear_cached_payment_methods_for_user( $token->get_user_id() );
	}

	/**
	 * Handle the woocommerce_payment_token_set_default hook.
	 *
	 * @internal
	 *
	 * @param int|string $token_id WooCommerce payment token ID.
	 * @param mixed      $token    Default payment token.
	 * @return void
	 */
	public function handle_woocommerce_payment_token_set_default( $token_id, $token ): void {
		unset( $token_id );

		if ( ! $this->is_supported_native_woopayments_token( $token ) ) {
			return;
		}

		$customer_service = $this->get_customer_service();
		if ( null !== $customer_service ) {
			$customer_id = $customer_service->get_customer_id_by_user_id( $token->get_user_id() );
			if ( null !== $customer_id ) {
				try {
					$customer_service->set_default_payment_method_for_customer( $customer_id, (string) $token->get_token() );
				} catch ( Throwable $exception ) {
					wc_get_logger()->error(
						'Error setting native WooPayments default payment method: ' . $exception->getMessage(),
						array( 'source' => 'woopayments' )
					);
				}
			}
		}

		$this->clear_cached_payment_methods_for_user( $token->get_user_id() );
	}

	/**
	 * Handle the woocommerce_get_customer_payment_tokens filter.
	 *
	 * @internal
	 *
	 * @param array<int|string,mixed> $tokens     Customer payment tokens.
	 * @param int|string              $user_id    WooCommerce user ID.
	 * @param string                  $gateway_id Requested gateway ID.
	 * @return array<int|string,mixed>
	 */
	public function handle_woocommerce_get_customer_payment_tokens( array $tokens, $user_id, string $gateway_id ): array {
		if ( 0 >= absint( $user_id ) || ( '' !== $gateway_id && ! $this->is_native_woopayments_gateway_id( $gateway_id ) ) ) {
			return $tokens;
		}

		$tokens = $this->reconcile_tokens_with_provider( $tokens, absint( $user_id ), $gateway_id );

		foreach ( $tokens as $token_key => $token ) {
			if (
				$token instanceof WC_Payment_Token
				&& $this->is_native_woopayments_gateway_id( $token->get_gateway_id() )
				&& ! $this->is_supported_native_woopayments_token( $token )
			) {
				unset( $tokens[ $token_key ] );
			}
		}

		return $tokens;
	}

	/**
	 * Handle the woocommerce_payment_methods_list_item filter.
	 *
	 * @internal
	 *
	 * @param array<string,mixed> $item          Saved payment method list item.
	 * @param mixed               $payment_token Payment token associated with the list item.
	 * @return array<string,mixed>
	 */
	public function handle_woocommerce_payment_methods_list_item( array $item, $payment_token ): array {
		if ( $this->is_supported_native_woopayments_token( $payment_token ) ) {
			if ( $payment_token instanceof WooPaymentsSepaToken ) {
				$item['method']['last4'] = $payment_token->get_last4();
				$item['method']['brand'] = esc_html__( 'SEPA IBAN', 'woocommerce' );

				return $item;
			}

			if ( $payment_token instanceof WooPaymentsLinkToken ) {
				$item['method']['last4'] = $payment_token->get_redacted_email();
				$item['method']['brand'] = esc_html__( 'Stripe Link email', 'woocommerce' );

				return $item;
			}

			if ( $payment_token instanceof WooPaymentsAmazonPayToken ) {
				$item['method']['last4'] = $payment_token->get_email();
				$item['method']['brand'] = esc_html__( 'Amazon Pay', 'woocommerce' );

				return $item;
			}
		}

		if ( ! $this->is_native_woopayments_card_token( $payment_token ) ) {
			return $item;
		}

		$wallet_type = $payment_token->get_meta( '_wcpay_wallet_type', true );
		$wallet_type = is_string( $wallet_type ) ? $wallet_type : '';
		if ( '' === $wallet_type ) {
			return $item;
		}

		$wallet_label = $this->get_wallet_label( $wallet_type );
		if ( '' === $wallet_label || ! isset( $item['method'] ) || ! is_array( $item['method'] ) ) {
			return $item;
		}

		$original_brand = isset( $item['method']['brand'] ) ? (string) $item['method']['brand'] : '';
		if ( '' !== $original_brand && 0 === strpos( $original_brand, $wallet_label . ' ' ) ) {
			return $item;
		}

		$item['method']['brand'] = trim(
			sprintf(
				/* translators: 1: wallet name, 2: card brand. */
				_x( '%1$s %2$s', 'Payment token with wallet', 'woocommerce' ),
				$wallet_label,
				$original_brand
			)
		);

		return $item;
	}

	/**
	 * Resolve a WooCommerce payment token ID to the provider payment method ID.
	 *
	 * @since 11.0.0
	 *
	 * @param string $token_id WooCommerce payment token ID.
	 * @param int    $user_id  Expected token owner user ID.
	 * @return string Provider payment method ID, or empty string when the token is invalid for WooPayments.
	 */
	public function resolve_payment_method_id_from_token_id( string $token_id, int $user_id ): string {
		$token = $this->get_valid_token_from_token_id( $token_id, $user_id );

		return $token instanceof WC_Payment_Token ? (string) $token->get_token() : '';
	}

	/**
	 * Resolve an order-attached WooCommerce payment token ID to the provider payment method ID.
	 *
	 * This is used for WC Subscriptions renewals, where Subscriptions has already attached the token
	 * to the renewal order and the renewal may not have the same runtime user context as checkout.
	 *
	 * @since 11.0.0
	 *
	 * @param string   $token_id WooCommerce payment token ID.
	 * @param WC_Order $order    Order that must contain the token.
	 * @return string Provider payment method ID, or empty string when the token is not attached to the order or is invalid for WooPayments.
	 */
	public function resolve_payment_method_id_from_order_token_id( string $token_id, WC_Order $order ): string {
		$token_id_int = absint( $token_id );
		if ( 0 >= $token_id_int || ! in_array( $token_id_int, array_map( 'absint', $order->get_payment_tokens() ), true ) ) {
			return '';
		}

		$token = WC_Payment_Tokens::get( $token_id_int );
		if ( ! $token instanceof WC_Payment_Token || ! $this->is_supported_native_woopayments_token( $token ) ) {
			return '';
		}

		return (string) $token->get_token();
	}

	/**
	 * Get a WooPayments token object by WooCommerce payment token ID.
	 *
	 * @since 11.0.0
	 *
	 * @param string $token_id WooCommerce payment token ID.
	 * @param int    $user_id  Expected token owner user ID.
	 * @return WC_Payment_Token|null Token object, or null when invalid.
	 */
	public function get_valid_token_from_token_id( string $token_id, int $user_id ): ?WC_Payment_Token {
		if ( 0 >= $user_id || '' === trim( $token_id ) ) {
			return null;
		}

		$token = WC_Payment_Tokens::get( absint( $token_id ) );
		if ( ! $token instanceof WC_Payment_Token ) {
			return null;
		}

		if ( ! $this->is_supported_native_woopayments_token( $token ) || $user_id !== $token->get_user_id() ) {
			return null;
		}

		return $token;
	}

	/**
	 * Resolve a WooCommerce payment token ID to the Stripe payment method type.
	 *
	 * @since 11.0.0
	 *
	 * @param string $token_id WooCommerce payment token ID.
	 * @param int    $user_id  Expected token owner user ID.
	 * @return string Stripe payment method type, or empty string when invalid.
	 */
	public function resolve_payment_method_type_from_token_id( string $token_id, int $user_id ): string {
		$token = $this->get_valid_token_from_token_id( $token_id, $user_id );

		return $token instanceof WC_Payment_Token ? $this->get_payment_method_type_for_token( $token ) : '';
	}

	/**
	 * Resolve an order-attached WooCommerce payment token ID to the Stripe payment method type.
	 *
	 * @since 11.0.0
	 *
	 * @param string   $token_id WooCommerce payment token ID.
	 * @param WC_Order $order    Order that must contain the token.
	 * @return string Stripe payment method type, or empty string when invalid.
	 */
	public function resolve_payment_method_type_from_order_token_id( string $token_id, WC_Order $order ): string {
		$token_id_int = absint( $token_id );
		if ( 0 >= $token_id_int || ! in_array( $token_id_int, array_map( 'absint', $order->get_payment_tokens() ), true ) ) {
			return '';
		}

		$token = WC_Payment_Tokens::get( $token_id_int );

		return $token instanceof WC_Payment_Token && $this->is_supported_native_woopayments_token( $token )
			? $this->get_payment_method_type_for_token( $token )
			: '';
	}

	/**
	 * Get the Stripe payment method type for a native WooPayments token.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Payment_Token $token Payment token.
	 * @return string Stripe payment method type, or empty string when unsupported.
	 */
	public function get_payment_method_type_for_token( WC_Payment_Token $token ): string {
		if ( ! $this->is_supported_native_woopayments_token( $token ) ) {
			return '';
		}

		if ( $token instanceof WC_Payment_Token_CC && OrderPaymentStore::GATEWAY_ID === $token->get_gateway_id() ) {
			return self::PAYMENT_METHOD_TYPE_CARD;
		}

		$token_type = (string) $token->get_type();

		return self::PAYMENT_METHOD_TYPES_BY_TOKEN_TYPE[ $token_type ] ?? '';
	}

	/**
	 * Get or create a saved card token for a user.
	 *
	 * @since 11.0.0
	 *
	 * @param string $payment_method_id Provider payment method ID.
	 * @param int    $user_id           User ID.
	 * @return WC_Payment_Token_CC|null Saved card token, or null when details are unavailable.
	 */
	public function get_or_create_card_token_for_user( string $payment_method_id, int $user_id ): ?WC_Payment_Token_CC {
		$token = $this->get_or_create_token_for_user( $payment_method_id, $user_id );

		return $token instanceof WC_Payment_Token_CC ? $token : null;
	}

	/**
	 * Get or create a saved token for a WooPayments reusable payment method.
	 *
	 * @since 11.0.0
	 *
	 * @param string $payment_method_id Provider payment method ID.
	 * @param int    $user_id           User ID.
	 * @return WC_Payment_Token|null Saved token, or null when details are unavailable.
	 */
	public function get_or_create_token_for_user( string $payment_method_id, int $user_id ): ?WC_Payment_Token {
		if ( 0 >= $user_id || '' === trim( $payment_method_id ) ) {
			return null;
		}

		$existing_native_token = $this->get_existing_native_token_for_user( $payment_method_id, $user_id );
		if ( $existing_native_token instanceof WC_Payment_Token ) {
			return $existing_native_token;
		}

		$payment_method = $this->payment_method_details_service->get_payment_method_details( $payment_method_id );
		$method_type    = isset( $payment_method['type'] ) ? (string) $payment_method['type'] : '';
		$gateway_id     = self::GATEWAY_IDS_BY_PAYMENT_METHOD_TYPE[ $method_type ] ?? '';
		if ( '' === $gateway_id ) {
			return null;
		}

		$provider_token = isset( $payment_method['id'] ) && '' !== (string) $payment_method['id']
			? (string) $payment_method['id']
			: $payment_method_id;
		$existing_token = $this->get_existing_token_for_user( $provider_token, $user_id, $gateway_id );
		if ( $existing_token instanceof WC_Payment_Token ) {
			return $existing_token;
		}

		$payment_method['id']   = $provider_token;
		$payment_method['type'] = $method_type;

		return $this->create_token_for_user_from_payment_method( $payment_method, $user_id );
	}

	/**
	 * Create a saved card token for a user.
	 *
	 * @param string              $provider_token Provider payment method ID.
	 * @param int                 $user_id        User ID.
	 * @param array<string,mixed> $payment_method Payment method details.
	 * @return WC_Payment_Token_CC|null
	 */
	private function create_card_token_for_user( string $provider_token, int $user_id, array $payment_method ): ?WC_Payment_Token_CC {
		$card_details = $this->get_card_details( $payment_method );
		if ( empty( $card_details ) ) {
			return null;
		}

		$card_type    = $this->get_card_type( $card_details );
		$last4        = isset( $card_details['last4'] ) ? (string) $card_details['last4'] : '';
		$expiry_month = isset( $card_details['exp_month'] ) ? (string) $card_details['exp_month'] : '';
		$expiry_year  = isset( $card_details['exp_year'] ) ? (string) $card_details['exp_year'] : '';

		if ( '' === $card_type || '' === $last4 || '' === $expiry_month || '' === $expiry_year ) {
			return null;
		}

		$token = new WC_Payment_Token_CC();
		$token->set_gateway_id( OrderPaymentStore::GATEWAY_ID );
		$token->set_user_id( $user_id );
		$token->set_token( $provider_token );
		$token->set_card_type( $card_type );
		$token->set_last4( $last4 );
		$token->set_expiry_month( $expiry_month );
		$token->set_expiry_year( $expiry_year );

		$wallet_type = $card_details['wallet']['type'] ?? '';
		if ( is_string( $wallet_type ) && '' !== $wallet_type ) {
			$token->add_meta_data( '_wcpay_wallet_type', $wallet_type, true );
		}

		$token->save();

		return $token;
	}

	/**
	 * Create a saved SEPA token for a user.
	 *
	 * @param string              $provider_token Provider payment method ID.
	 * @param int                 $user_id        User ID.
	 * @param array<string,mixed> $payment_method Payment method details.
	 * @return WooPaymentsSepaToken|null
	 */
	private function create_sepa_token_for_user( string $provider_token, int $user_id, array $payment_method ): ?WooPaymentsSepaToken {
		$sepa_details = isset( $payment_method['sepa_debit'] ) && is_array( $payment_method['sepa_debit'] ) ? $payment_method['sepa_debit'] : array();
		$last4        = isset( $sepa_details['last4'] ) ? (string) $sepa_details['last4'] : '';
		if ( '' === $last4 ) {
			return null;
		}

		$token = new WooPaymentsSepaToken();
		$token->set_gateway_id( self::GATEWAY_IDS_BY_PAYMENT_METHOD_TYPE[ self::PAYMENT_METHOD_TYPE_SEPA ] );
		$token->set_user_id( $user_id );
		$token->set_token( $provider_token );
		$token->set_last4( $last4 );
		$token->save();

		return $token;
	}

	/**
	 * Create a saved Link token for a user.
	 *
	 * @param string              $provider_token Provider payment method ID.
	 * @param int                 $user_id        User ID.
	 * @param array<string,mixed> $payment_method Payment method details.
	 * @return WooPaymentsLinkToken|null
	 */
	private function create_link_token_for_user( string $provider_token, int $user_id, array $payment_method ): ?WooPaymentsLinkToken {
		$link_details = isset( $payment_method['link'] ) && is_array( $payment_method['link'] ) ? $payment_method['link'] : array();
		$email        = isset( $link_details['email'] ) ? (string) $link_details['email'] : '';
		if ( '' === $email ) {
			return null;
		}

		$token = new WooPaymentsLinkToken();
		$token->set_gateway_id( self::GATEWAY_IDS_BY_PAYMENT_METHOD_TYPE[ self::PAYMENT_METHOD_TYPE_LINK ] );
		$token->set_user_id( $user_id );
		$token->set_token( $provider_token );
		$token->set_email( $email );
		$token->save();

		return $token;
	}

	/**
	 * Create a saved Amazon Pay token for a user.
	 *
	 * @param string              $provider_token Provider payment method ID.
	 * @param int                 $user_id        User ID.
	 * @param array<string,mixed> $payment_method Payment method details.
	 * @return WooPaymentsAmazonPayToken
	 */
	private function create_amazon_pay_token_for_user( string $provider_token, int $user_id, array $payment_method ): WooPaymentsAmazonPayToken {
		$billing_details = isset( $payment_method['billing_details'] ) && is_array( $payment_method['billing_details'] ) ? $payment_method['billing_details'] : array();
		$email           = isset( $billing_details['email'] ) ? (string) $billing_details['email'] : '';
		$token           = new WooPaymentsAmazonPayToken();
		$token->set_gateway_id( self::GATEWAY_IDS_BY_PAYMENT_METHOD_TYPE[ self::PAYMENT_METHOD_TYPE_AMAZON_PAY ] );
		$token->set_user_id( $user_id );
		$token->set_token( $provider_token );
		if ( '' !== $email ) {
			$token->set_email( $email );
		}
		$token->save();

		return $token;
	}

	/**
	 * Attach a payment token to an order.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order         $order Order object.
	 * @param WC_Payment_Token $token Payment token.
	 * @return bool True when the token was attached.
	 */
	public function attach_token_to_order( WC_Order $order, WC_Payment_Token $token ): bool {
		if ( 0 >= $token->get_id() ) {
			return false;
		}

		$active_token = $this->get_active_token_for_order( $order );
		if ( $active_token instanceof WC_Payment_Token && $token->get_id() === $active_token->get_id() ) {
			return true;
		}

		$result = $order->add_payment_token( $token );
		if ( false === $result ) {
			return false;
		}

		$order->save();

		return true;
	}

	/**
	 * Get the last attached payment token, which WooPayments treats as active.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order $order Order or subscription object.
	 * @return WC_Payment_Token|null
	 */
	public function get_active_token_for_order( WC_Order $order ): ?WC_Payment_Token {
		$token_ids = $order->get_payment_tokens();
		$token_id  = end( $token_ids );

		if ( false === $token_id ) {
			return null;
		}

		$token = WC_Payment_Tokens::get( absint( $token_id ) );

		return $token instanceof WC_Payment_Token ? $token : null;
	}

	/**
	 * Clear preserved WooPayments cached payment methods for all users.
	 *
	 * @since 11.0.0
	 * @return void
	 * @throws RuntimeException When cache cleanup cannot complete.
	 */
	public function clear_all_cached_payment_methods(): void {
		global $wpdb;

		$deleted_meta_rows = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from WordPress.
				"DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT %d",
				self::CACHED_PAYMENT_METHODS_META_KEY,
				self::CACHE_CLEAR_BATCH_SIZE
			)
		);
		$this->throw_on_database_error( false === $deleted_meta_rows, 'Failed to clear cached WooPayments payment methods.' );

		$option_names = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from WordPress.
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d",
				$wpdb->esc_like( 'wcpay_pm_' ) . '%',
				self::CACHE_CLEAR_BATCH_SIZE
			)
		);
		$this->throw_on_database_error( ! is_array( $option_names ), 'Failed to read cached WooPayments payment-method options.' );

		foreach ( $option_names as $option_name ) {
			delete_option( $option_name );
			if ( false !== get_option( $option_name, false ) ) {
				throw new RuntimeException( 'Failed to delete cached WooPayments payment-method option.' );
			}
		}

		if ( self::CACHE_CLEAR_BATCH_SIZE <= (int) $deleted_meta_rows || self::CACHE_CLEAR_BATCH_SIZE <= count( $option_names ) ) {
			throw new RuntimeException( 'WooPayments payment-method cache cleanup partially completed and should be retried.' );
		}
	}

	/**
	 * Clear preserved WooPayments cached payment methods for a user.
	 *
	 * @internal
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function clear_cached_payment_methods_for_user( int $user_id ): void {
		if ( 0 >= $user_id ) {
			return;
		}

		delete_user_meta( $user_id, self::CACHED_PAYMENT_METHODS_META_KEY );
	}

	/**
	 * Reconcile locally stored tokens with the provider's payment methods.
	 *
	 * Creates WooCommerce tokens for provider payment methods that have no local
	 * token and deletes local tokens whose payment method no longer exists at the
	 * provider (without detaching remotely). Provider failures degrade to the
	 * locally stored list.
	 *
	 * @param array<int|string,mixed> $tokens     Customer payment tokens.
	 * @param int                     $user_id    WooCommerce user ID.
	 * @param string                  $gateway_id Requested gateway ID, or '' for all.
	 * @return array<int|string,mixed>
	 */
	private function reconcile_tokens_with_provider( array $tokens, int $user_id, string $gateway_id ): array {
		if ( ! is_user_logged_in() ) {
			return $tokens;
		}

		if ( count( $tokens ) >= (int) get_option( 'posts_per_page' ) ) {
			// The tokens data store is unpaginated and only the first page is retrieved;
			// a full page of saved methods is an unsupported edge case for reconciliation.
			return $tokens;
		}

		$customer_service = $this->get_customer_service();
		if ( null === $customer_service ) {
			return $tokens;
		}

		try {
			$customer_id = $customer_service->get_customer_id_by_user_id( $user_id );
			if ( null === $customer_id ) {
				return $tokens;
			}

			$stored_tokens = array();
			foreach ( $tokens as $token ) {
				if ( $token instanceof WC_Payment_Token && $this->is_native_woopayments_gateway_id( $token->get_gateway_id() ) ) {
					$stored_tokens[ (string) $token->get_token() ] = $token;
				}
			}

			$payment_methods = $this->get_payment_methods_from_provider( $customer_service, $user_id, $customer_id, $gateway_id );
		} catch ( Throwable $exception ) {
			wc_get_logger()->error(
				'Failed to fetch payment methods for customer: ' . $exception->getMessage(),
				array( 'source' => 'woopayments' )
			);

			return $tokens;
		}

		// WC_Payment_Token::save() can re-enter this filter; keep it off while adding.
		remove_filter( 'woocommerce_get_customer_payment_tokens', array( $this, 'handle_woocommerce_get_customer_payment_tokens' ), 10 );
		foreach ( $payment_methods as $payment_method ) {
			if ( ! is_array( $payment_method ) || ! isset( $payment_method['type'], $payment_method['id'] ) ) {
				continue;
			}

			$payment_method_id = (string) $payment_method['id'];
			if ( isset( $stored_tokens[ $payment_method_id ] ) ) {
				unset( $stored_tokens[ $payment_method_id ] );
				continue;
			}

			$method_gateway_id = self::GATEWAY_IDS_BY_PAYMENT_METHOD_TYPE[ (string) $payment_method['type'] ] ?? '';
			if ( '' !== $gateway_id && $method_gateway_id !== $gateway_id ) {
				continue;
			}

			$token = $this->create_token_for_user_from_payment_method( $payment_method, $user_id );
			if ( $token instanceof WC_Payment_Token ) {
				$tokens[ $token->get_id() ] = $token;
			}
		}
		add_filter( 'woocommerce_get_customer_payment_tokens', array( $this, 'handle_woocommerce_get_customer_payment_tokens' ), 10, 3 );

		// Local tokens whose payment method the provider no longer holds: delete
		// locally without detaching (there is nothing left to detach remotely).
		remove_action( 'woocommerce_payment_token_deleted', array( $this, 'handle_woocommerce_payment_token_deleted' ), 10 );
		foreach ( $stored_tokens as $stored_token ) {
			unset( $tokens[ $stored_token->get_id() ] );
			$stored_token->delete();
		}
		add_action( 'woocommerce_payment_token_deleted', array( $this, 'handle_woocommerce_payment_token_deleted' ), 10, 2 );

		return $tokens;
	}

	/**
	 * Get the provider's payment methods for a customer, using the per-user cache.
	 *
	 * The cache lives in the `_wcpay_payment_methods` user meta, keyed by customer ID
	 * with one entry per payment method type; it is busted whenever the customer ID
	 * changes and cleared by the token lifecycle handlers.
	 *
	 * @param WooPaymentsCustomerService $customer_service Native customer service.
	 * @param int                        $user_id          WooCommerce user ID.
	 * @param string                     $customer_id      Provider customer ID.
	 * @param string                     $gateway_id       Requested gateway ID, or '' for all.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_payment_methods_from_provider( WooPaymentsCustomerService $customer_service, int $user_id, string $customer_id, string $gateway_id ): array {
		$types_to_retrieve = $this->get_retrievable_payment_method_types( $gateway_id );

		$cache = get_user_meta( $user_id, self::CACHED_PAYMENT_METHODS_META_KEY, true );
		if ( ! is_array( $cache ) || ! isset( $cache['customer_id'] ) || $cache['customer_id'] !== $customer_id ) {
			$cache = array( 'customer_id' => $customer_id );
		}

		$payment_methods = array();
		foreach ( $types_to_retrieve as $index => $type ) {
			if ( isset( $cache[ 'payment_method_' . $type ] ) && is_array( $cache[ 'payment_method_' . $type ] ) ) {
				$payment_methods = array_merge( $payment_methods, $cache[ 'payment_method_' . $type ] );
				unset( $types_to_retrieve[ $index ] );
			}
		}

		if ( array() === $types_to_retrieve ) {
			return $payment_methods;
		}

		foreach ( $types_to_retrieve as $type ) {
			$type_methods = $customer_service->get_payment_methods_for_customer( $customer_id, $type );

			$cache[ 'payment_method_' . $type ] = $type_methods;
			$payment_methods                    = array_merge( $payment_methods, $type_methods );
		}

		update_user_meta( $user_id, self::CACHED_PAYMENT_METHODS_META_KEY, $cache );

		return $payment_methods;
	}

	/**
	 * Get the payment method types to retrieve from the provider.
	 *
	 * With a gateway ID, only that gateway's types are retrieved (Link rides the card
	 * gateway, so its enablement is checked separately); without one, card is always
	 * retrieved plus every other reconcilable type that is enabled.
	 *
	 * @param string $gateway_id Requested gateway ID, or '' for all.
	 * @return string[]
	 */
	private function get_retrievable_payment_method_types( string $gateway_id ): array {
		$types = array();

		foreach ( self::RECONCILABLE_PAYMENT_METHOD_TYPES as $type ) {
			$type_gateway_id = self::GATEWAY_IDS_BY_PAYMENT_METHOD_TYPE[ $type ];

			if ( '' === $gateway_id ) {
				if ( self::PAYMENT_METHOD_TYPE_CARD !== $type && ! $this->is_payment_method_type_enabled( $type ) ) {
					continue;
				}
			} else {
				if ( $type_gateway_id !== $gateway_id ) {
					continue;
				}

				if ( self::PAYMENT_METHOD_TYPE_LINK === $type && ! $this->is_payment_method_type_enabled( $type ) ) {
					continue;
				}
			}

			$types[] = $type;
		}

		return $types;
	}

	/**
	 * Check if a payment method type is enabled in the gateway settings.
	 *
	 * @param string $payment_method_type Payment method type.
	 * @return bool
	 */
	private function is_payment_method_type_enabled( string $payment_method_type ): bool {
		$account_service = $this->get_account_service();
		if ( null === $account_service ) {
			return false;
		}

		$enabled_method_ids = $account_service->get_gateway_setting( 'upe_enabled_payment_method_ids', array( self::PAYMENT_METHOD_TYPE_CARD ) );

		return is_array( $enabled_method_ids ) && in_array( $payment_method_type, $enabled_method_ids, true );
	}

	/**
	 * Create a saved token for a user from fetched payment method details.
	 *
	 * @param array<string,mixed> $payment_method Payment method details including id and type.
	 * @param int                 $user_id        User ID.
	 * @return WC_Payment_Token|null
	 */
	private function create_token_for_user_from_payment_method( array $payment_method, int $user_id ): ?WC_Payment_Token {
		$method_type    = isset( $payment_method['type'] ) ? (string) $payment_method['type'] : '';
		$provider_token = isset( $payment_method['id'] ) ? (string) $payment_method['id'] : '';

		if ( '' === $provider_token || ! isset( self::GATEWAY_IDS_BY_PAYMENT_METHOD_TYPE[ $method_type ] ) ) {
			return null;
		}

		switch ( $method_type ) {
			case self::PAYMENT_METHOD_TYPE_CARD:
			case self::PAYMENT_METHOD_TYPE_CARD_PRESENT:
				return $this->create_card_token_for_user( $provider_token, $user_id, $payment_method );
			case self::PAYMENT_METHOD_TYPE_SEPA:
				return $this->create_sepa_token_for_user( $provider_token, $user_id, $payment_method );
			case self::PAYMENT_METHOD_TYPE_LINK:
				return $this->create_link_token_for_user( $provider_token, $user_id, $payment_method );
			case self::PAYMENT_METHOD_TYPE_AMAZON_PAY:
			default:
				return $this->create_amazon_pay_token_for_user( $provider_token, $user_id, $payment_method );
		}
	}

	/**
	 * Check if a token is a locally supported native WooPayments card token.
	 *
	 * @param mixed $token Payment token candidate.
	 * @return bool
	 */
	private function is_native_woopayments_card_token( $token ): bool {
		return $token instanceof WC_Payment_Token_CC && OrderPaymentStore::GATEWAY_ID === $token->get_gateway_id();
	}

	/**
	 * Check if a token is a locally supported native WooPayments reusable token.
	 *
	 * @param mixed $token Payment token candidate.
	 * @return bool
	 */
	private function is_supported_native_woopayments_token( $token ): bool {
		if ( ! $token instanceof WC_Payment_Token ) {
			return false;
		}

		if ( $this->is_native_woopayments_card_token( $token ) ) {
			return true;
		}

		if ( ! $this->is_native_woopayments_gateway_id( $token->get_gateway_id() ) ) {
			return false;
		}

		return $token instanceof WooPaymentsSepaToken
			|| $token instanceof WooPaymentsLinkToken
			|| $token instanceof WooPaymentsAmazonPayToken;
	}

	/**
	 * Check if a gateway ID belongs to native WooPayments reusable tokens.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return bool
	 */
	private function is_native_woopayments_gateway_id( string $gateway_id ): bool {
		return in_array( $gateway_id, array_unique( array_values( self::GATEWAY_IDS_BY_PAYMENT_METHOD_TYPE ) ), true );
	}

	/**
	 * Get a supported wallet label.
	 *
	 * @param string $wallet_type Wallet type.
	 * @return string
	 */
	private function get_wallet_label( string $wallet_type ): string {
		switch ( strtolower( $wallet_type ) ) {
			case 'apple_pay':
				return __( 'Apple Pay', 'woocommerce' );
			case 'google_pay':
				return __( 'Google Pay', 'woocommerce' );
		}

		return '';
	}

	/**
	 * Get the native API client when available.
	 *
	 * @return WooPaymentsApiClient|null
	 */
	private function get_api_client(): ?WooPaymentsApiClient {
		if ( null !== $this->api_client ) {
			return $this->api_client;
		}

		try {
			$this->api_client = wc_get_container()->get( WooPaymentsApiClient::class );
		} catch ( Throwable $exception ) {
			unset( $exception );
			return null;
		}

		return $this->api_client;
	}

	/**
	 * Get the native customer service when available.
	 *
	 * @return WooPaymentsCustomerService|null
	 */
	private function get_customer_service(): ?WooPaymentsCustomerService {
		if ( null !== $this->customer_service ) {
			return $this->customer_service;
		}

		try {
			$this->customer_service = wc_get_container()->get( WooPaymentsCustomerService::class );
		} catch ( Throwable $exception ) {
			unset( $exception );
			return null;
		}

		return $this->customer_service;
	}

	/**
	 * Get the native account service when available.
	 *
	 * @return WooPaymentsAccountService|null
	 */
	private function get_account_service(): ?WooPaymentsAccountService {
		if ( null !== $this->account_service ) {
			return $this->account_service;
		}

		try {
			$this->account_service = wc_get_container()->get( WooPaymentsAccountService::class );
		} catch ( Throwable $exception ) {
			unset( $exception );
			return null;
		}

		return $this->account_service;
	}

	/**
	 * Determine whether a remote detach should be skipped for the current environment.
	 *
	 * @return bool
	 */
	private function should_skip_remote_detach_for_environment(): bool {
		$account_service = $this->get_account_service();

		return null !== $account_service
			&& ! $account_service->is_test_mode_enabled()
			&& is_admin()
			&& 'production' !== wp_get_environment_type();
	}

	/**
	 * Throw when the last database operation failed.
	 *
	 * @param bool   $failed  Whether the operation failed by return value.
	 * @param string $message Error message.
	 * @return void
	 * @throws RuntimeException When the database operation failed.
	 */
	private function throw_on_database_error( bool $failed, string $message ): void {
		global $wpdb;

		if ( $failed || '' !== $wpdb->last_error ) {
			throw new RuntimeException( esc_html( $message ) );
		}
	}

	/**
	 * Copy a saved payment token and provider metadata to subscriptions related to an initial order.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order         $order             Parent order.
	 * @param WC_Payment_Token $token             Saved payment token.
	 * @param string           $payment_method_id Provider payment method ID.
	 * @param string           $customer_id       WooPayments customer ID.
	 */
	public function sync_related_subscriptions_payment_token( WC_Order $order, WC_Payment_Token $token, string $payment_method_id, string $customer_id ): void {
		if ( 0 >= $token->get_id() ) {
			return;
		}

		$provider_payment_method_id = '' !== $payment_method_id ? $payment_method_id : (string) $token->get_token();
		$provider_customer_id       = '' !== $customer_id ? $customer_id : (string) $order->get_meta( '_stripe_customer_id', true );

		foreach ( $this->get_related_subscriptions_for_order( $order ) as $subscription ) {
			if ( ! $subscription instanceof WC_Order || $order->get_payment_method() !== $subscription->get_payment_method() ) {
				continue;
			}

			$active_token = $this->get_active_token_for_order( $subscription );
			if ( ! $active_token instanceof WC_Payment_Token || $token->get_id() !== $active_token->get_id() ) {
				$subscription->add_payment_token( $token );
			}

			if ( '' !== $provider_payment_method_id ) {
				$subscription->update_meta_data( '_payment_method_id', $provider_payment_method_id );
			}

			if ( '' !== $provider_customer_id ) {
				$subscription->update_meta_data( '_stripe_customer_id', $provider_customer_id );
			}

			$subscription->save();
		}
	}

	/**
	 * Get an existing token for a provider payment method ID.
	 *
	 * @param string $payment_method_id Provider payment method ID.
	 * @param int    $user_id           User ID.
	 * @return WC_Payment_Token|null
	 */
	private function get_existing_native_token_for_user( string $payment_method_id, int $user_id ): ?WC_Payment_Token {
		foreach ( array_unique( array_values( self::GATEWAY_IDS_BY_PAYMENT_METHOD_TYPE ) ) as $gateway_id ) {
			$token = $this->get_existing_token_for_user( $payment_method_id, $user_id, $gateway_id );
			if ( $token instanceof WC_Payment_Token ) {
				return $token;
			}
		}

		return null;
	}

	/**
	 * Get an existing token for a provider payment method ID and gateway.
	 *
	 * @param string $payment_method_id Provider payment method ID.
	 * @param int    $user_id           User ID.
	 * @param string $gateway_id        Gateway ID.
	 * @return WC_Payment_Token|null
	 */
	private function get_existing_token_for_user( string $payment_method_id, int $user_id, string $gateway_id ): ?WC_Payment_Token {
		$tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, $gateway_id );
		foreach ( $tokens as $token ) {
			if ( $token instanceof WC_Payment_Token && $this->is_supported_native_woopayments_token( $token ) && $payment_method_id === (string) $token->get_token() ) {
				return $token;
			}
		}

		return null;
	}

	/**
	 * Get subscriptions related to an initial order.
	 *
	 * @param WC_Order $order Parent order.
	 * @return array<int,mixed>
	 */
	public function get_related_subscriptions_for_order( WC_Order $order ): array {
		$subscriptions = array();
		if ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			$subscriptions = wcs_get_subscriptions_for_order( $order->get_id() );
		}

		$subscriptions = is_array( $subscriptions ) ? $subscriptions : array();

		/**
		 * Filters native WooPayments subscriptions related to an initial order.
		 *
		 * @since 11.0.0
		 *
		 * @param array<int,mixed> $subscriptions Related subscriptions.
		 * @param WC_Order         $order         Parent order.
		 */
		$subscriptions = apply_filters( 'woocommerce_woopayments_related_subscriptions_for_order', $subscriptions, $order );

		return is_array( $subscriptions ) ? $subscriptions : array();
	}

	/**
	 * Get card details from a payment method payload.
	 *
	 * @param array<string,mixed> $payment_method Payment method details.
	 * @return array<string,mixed>
	 */
	private function get_card_details( array $payment_method ): array {
		$type = isset( $payment_method['type'] ) ? (string) $payment_method['type'] : '';

		if ( 'card' === $type && isset( $payment_method['card'] ) && is_array( $payment_method['card'] ) ) {
			return $payment_method['card'];
		}

		if ( 'card_present' === $type && isset( $payment_method['card_present'] ) && is_array( $payment_method['card_present'] ) ) {
			return $payment_method['card_present'];
		}

		return array();
	}

	/**
	 * Get the WooCommerce card type from Stripe card details.
	 *
	 * @param array<string,mixed> $card_details Card details.
	 * @return string
	 */
	private function get_card_type( array $card_details ): string {
		$preferred_network = '';
		if ( isset( $card_details['networks'] ) && is_array( $card_details['networks'] ) && isset( $card_details['networks']['preferred'] ) ) {
			$preferred_network = (string) $card_details['networks']['preferred'];
		}

		$card_type = isset( $card_details['display_brand'] ) ? (string) $card_details['display_brand'] : '';
		if ( '' === $card_type ) {
			$card_type = $preferred_network;
		}

		if ( '' === $card_type && isset( $card_details['brand'] ) ) {
			$card_type = (string) $card_details['brand'];
		}

		return strtolower( $card_type );
	}
}
