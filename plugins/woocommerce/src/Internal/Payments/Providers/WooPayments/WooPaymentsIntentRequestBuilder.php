<?php
/**
 * WooPaymentsIntentRequestBuilder class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use WC_Order;

/**
 * Adapts WooCommerce runtime state to WooPayments request payloads.
 *
 * This compatibility boundary is intentionally impure: it resolves tokens, settings, hooks,
 * nonces, request identity, and site data before transport.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsIntentRequestBuilder {

	/**
	 * Provider-data key for saved-token Stripe payment method type.
	 *
	 * @var string
	 */
	public const PROVIDER_DATA_SAVED_PAYMENT_METHOD_TYPE = 'saved_payment_method_type';

	/**
	 * Provider-data key for payments that must persist a reusable recurring credential.
	 *
	 * @var string
	 */
	public const PROVIDER_DATA_RECURRING_PAYMENT = 'recurring_payment';

	/**
	 * WooPayments client version advertised to the V1 API.
	 *
	 * @var string
	 */
	private const WCPAY_V1_CLIENT_CAPABILITY_VERSION = '10.8.0';

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * WooPayments order data service.
	 *
	 * @var WooPaymentsOrderDataService
	 */
	private WooPaymentsOrderDataService $order_data_service;

	/**
	 * WooPayments token service.
	 *
	 * @var WooPaymentsTokenService
	 */
	private WooPaymentsTokenService $token_service;

	/**
	 * WooPayments payment method registry.
	 *
	 * @var WooPaymentsPaymentMethodRegistry
	 */
	private WooPaymentsPaymentMethodRegistry $payment_method_registry;

	/**
	 * Level 3 data service.
	 *
	 * @var WooPaymentsLevel3Service
	 */
	private WooPaymentsLevel3Service $level3_service;

	/**
	 * Initialize the request builder.
	 *
	 * @internal
	 *
	 * @param WooPaymentsAccountService        $account_service         Account service.
	 * @param WooPaymentsOrderDataService      $order_data_service      Order data service.
	 * @param WooPaymentsTokenService          $token_service           Token service.
	 * @param WooPaymentsPaymentMethodRegistry $payment_method_registry Payment method registry.
	 * @param WooPaymentsLevel3Service|null    $level3_service          Level 3 data service.
	 */
	final public function init(
		WooPaymentsAccountService $account_service,
		WooPaymentsOrderDataService $order_data_service,
		WooPaymentsTokenService $token_service,
		WooPaymentsPaymentMethodRegistry $payment_method_registry,
		?WooPaymentsLevel3Service $level3_service = null
	): void {
		$this->account_service         = $account_service;
		$this->order_data_service      = $order_data_service;
		$this->token_service           = $token_service;
		$this->payment_method_registry = $payment_method_registry;
		if ( null !== $level3_service ) {
			$this->level3_service = $level3_service;
		}
	}

	/**
	 * Build the native WooPayments charge request payload.
	 *
	 * @param PaymentContext $context            Payment context.
	 * @param string         $payment_credential Payment method or confirmation token.
	 * @param string         $customer_id        Customer ID.
	 * @param bool           $is_recurring       Whether recurring handling is required.
	 * @return array<string,mixed>
	 */
	public function charge_request_data( PaymentContext $context, string $payment_credential, string $customer_id, bool $is_recurring ): array {
		$order                = $context->get_order();
		$payment_data         = $context->get_payment_data();
		$provider_data        = $context->get_provider_data();
		$is_renewal           = ! empty( $provider_data['scheduled_subscription_payment'] );
		$is_recurring         = $is_renewal || $is_recurring;
		$save_payment_method  = ! empty( $payment_data['save_payment_method'] ) || $is_recurring;
		$payment_type         = $is_recurring ? 'recurring' : 'single';
		$subscription_payment = $is_renewal ? 'renewal' : ( $is_recurring ? 'initial' : 'no' );
		$payment_method_types = $this->payment_method_types_for_request( $context, (string) $order->get_currency() );
		$request_data         = array(
			'amount'               => $this->order_data_service->prepare_amount( (float) $order->get_total(), (string) $order->get_currency() ),
			'capture_method'       => ! $is_renewal && 'yes' === $this->account_service->get_gateway_setting( 'manual_capture', 'no' ) ? 'manual' : 'automatic',
			'currency'             => strtolower( (string) $order->get_currency() ),
			'customer'             => $customer_id,
			'description'          => self::intent_description( (string) $order->get_order_number() ),
			'metadata'             => array_merge(
				self::metadata_from_order( $order, $payment_type, $subscription_payment ),
				self::fingerprint_metadata( $context )
			),
			'payment_method_types' => $payment_method_types,
		);

		if ( WooPaymentsIntentCodec::is_confirmation_token( $payment_credential ) ) {
			$request_data['confirmation_token'] = $payment_credential;
		} else {
			$request_data['payment_method'] = $payment_credential;
		}

		if ( ! empty( $provider_data['cvc_confirmation'] ) ) {
			$request_data['cvc_confirmation'] = (string) $provider_data['cvc_confirmation'];
		}

		if ( $is_renewal ) {
			$request_data['off_session'] = true;
			$renewal_mandate             = isset( $provider_data['renewal_mandate'] ) ? (string) $provider_data['renewal_mandate'] : '';
			if ( '' !== $renewal_mandate ) {
				$request_data['mandate'] = $renewal_mandate;
			}
		}

		if ( ! $is_renewal && $save_payment_method ) {
			$request_data['setup_future_usage'] = 'off_session';
		}

		$level3_data = $this->get_level3_service()->get_data_from_order( $order );
		if ( array() !== $level3_data ) {
			$request_data['level3'] = $level3_data;
		}

		if ( self::is_mandate_data_required( $payment_method_types ) ) {
			$request_data['mandate_data'] = self::mandate_data();
		}

		if ( self::is_redirect_return_url_required( $payment_method_types ) ) {
			$request_data['return_url'] = self::redirect_return_url( $order, $save_payment_method );
		}

		if ( self::is_using_saved_payment_token( $payment_data ) && ! preg_match( '/^(card_|src_)/', $payment_credential ) ) {
			$billing_details = $this->order_data_service->get_billing_data_from_order( $order );
			if ( ! empty( $billing_details ) ) {
				$request_data['payment_method_update_data'] = array( 'billing_details' => $billing_details );
			}
		}

		return WooPaymentsPlatformPaymentMethodContext::from_provider_data( $provider_data )->apply_to_request_data( $request_data );
	}

	/**
	 * Build the native WooPayments setup-intent request payload.
	 *
	 * @param PaymentContext $context            Payment context.
	 * @param string         $payment_credential Payment method or confirmation token.
	 * @param string         $customer_id        Customer ID.
	 * @param bool           $is_recurring       Whether recurring handling is required.
	 * @return array<string,mixed>
	 */
	public function setup_intent_request_data( PaymentContext $context, string $payment_credential, string $customer_id, bool $is_recurring ): array {
		$payment_type         = $is_recurring ? 'recurring' : 'single';
		$subscription_payment = 'recurring' === $payment_type ? 'initial' : 'no';
		$request_data         = array(
			'customer'             => $customer_id,
			'metadata'             => array_merge(
				self::metadata_from_order( $context->get_order(), $payment_type, $subscription_payment ),
				self::fingerprint_metadata( $context )
			),
			'payment_method_types' => $this->payment_method_types_for_request( $context, (string) $context->get_order()->get_currency() ),
		);

		if ( ! WooPaymentsIntentCodec::is_confirmation_token( $payment_credential ) ) {
			$request_data['payment_method'] = $payment_credential;
		}

		return WooPaymentsPlatformPaymentMethodContext::from_provider_data( $context->get_provider_data() )->apply_to_request_data( $request_data );
	}

	/**
	 * Build the WooPayments metadata payload for an order.
	 *
	 * @param WC_Order $order                Order being charged.
	 * @param string   $payment_type         Payment type slug.
	 * @param string   $subscription_payment Subscription payment type.
	 * @return array<string,mixed>
	 */
	public static function metadata_from_order( WC_Order $order, string $payment_type = 'single', string $subscription_payment = 'no' ): array {
		WooPaymentsPaymentType::register_legacy_alias();

		$payment_type         = 'recurring' === $payment_type ? WooPaymentsPaymentType::recurring() : WooPaymentsPaymentType::single();
		$subscription_payment = in_array( $subscription_payment, array( 'initial', 'renewal' ), true ) ? $subscription_payment : 'no';
		$metadata             = array(
			'customer_name'        => trim( sanitize_text_field( $order->get_billing_first_name() ) . ' ' . sanitize_text_field( $order->get_billing_last_name() ) ),
			'customer_email'       => sanitize_email( $order->get_billing_email() ),
			'site_url'             => esc_url( get_site_url() ),
			'order_id'             => $order->get_id(),
			'order_number'         => $order->get_order_number(),
			'order_key'            => $order->get_order_key(),
			'payment_type'         => $payment_type,
			'checkout_type'        => $order->get_created_via(),
			'client_version'       => self::WCPAY_V1_CLIENT_CAPABILITY_VERSION,
			'subscription_payment' => $subscription_payment,
		);

		if ( 'no' !== $subscription_payment ) {
			$metadata['payment_context'] = 'regular_subscription';
		}

		/**
		 * Filters the WooPayments metadata created from an order.
		 *
		 * @since 11.0.0
		 *
		 * @param array<string,mixed>   $metadata     Metadata being sent to WooPayments.
		 * @param WC_Order              $order        Order object.
		 * @param WooPaymentsPaymentType $payment_type Payment type.
		 */
		$metadata = apply_filters( 'wcpay_metadata_from_order', $metadata, $order, $payment_type );

		return is_array( $metadata ) ? $metadata : array();
	}

	/**
	 * Build the Stripe-dashboard intention description.
	 *
	 * Format matches the WooPayments plugin (no i18n on purpose — the text is
	 * only ever shown in the provider dashboard).
	 *
	 * @param string $order_number Order number (may differ from the ID).
	 * @return string
	 */
	public static function intent_description( string $order_number ): string {
		$domain_name = str_replace( array( 'https://', 'http://' ), '', get_site_url() );
		$blog_id     = class_exists( 'Jetpack_Options' ) ? \Jetpack_Options::get_option( 'id' ) : null;
		$blog_id     = is_numeric( $blog_id ) && (int) $blog_id > 0 ? (int) $blog_id : null;

		return sprintf(
			'Online Payment%s for %s%s',
			'' !== $order_number && '0' !== $order_number ? " for Order #$order_number" : '',
			$domain_name,
			null !== $blog_id ? " blog_id $blog_id" : ''
		);
	}

	/**
	 * Build the buyer-fingerprinting risk metadata for a payment context.
	 *
	 * Mirrors the WooPayments plugin's Buyer_Fingerprinting_Service: sha512 of
	 * the shopper IP, the browser-computed device fingerprint as the UA hash,
	 * the geolocated IP country, and the purchase size — with empty values
	 * dropped and the availability flag always set, so platform risk rules
	 * keyed on these fields score native charges the same as plugin charges.
	 * The purchase size prefers the live cart and falls back to the order's
	 * item count, which covers pay-for-order and off-session renewals.
	 *
	 * @param PaymentContext $context Payment context.
	 * @return array<string,mixed>
	 */
	private static function fingerprint_metadata( PaymentContext $context ): array {
		$provider_data = $context->get_provider_data();
		$fingerprint   = isset( $provider_data['fingerprint'] ) && is_scalar( $provider_data['fingerprint'] )
			? (string) $provider_data['fingerprint']
			: '';

		$cart_contents = null !== WC()->cart ? intval( WC()->cart->get_cart_contents_count() ) : null;
		if ( ! $cart_contents ) {
			$cart_contents = $context->get_order()->get_item_count();
		}

		$metadata = array_filter(
			array(
				'fraud_prevention_data_shopper_ip_hash' => hash( 'sha512', \WC_Geolocation::get_ip_address() ),
				'fraud_prevention_data_shopper_ua_hash' => $fingerprint,
				'fraud_prevention_data_ip_country'      => \WC_Geolocation::geolocate_ip( '', true )['country'],
				'fraud_prevention_data_cart_contents'   => $cart_contents,
			),
			static function ( $value ): bool {
				// Same semantics as the plugin's strlen filter: drop null,
				// false and empty strings while keeping zero values.
				return null !== $value && false !== $value && '' !== $value;
			}
		);

		$metadata['fraud_prevention_data_available'] = true;

		return $metadata;
	}

	/**
	 * Add saved-token payment method type to provider data when one can be resolved.
	 *
	 * @param PaymentContext $context Payment context.
	 * @return PaymentContext
	 */
	public function with_saved_payment_token_method_type( PaymentContext $context ): PaymentContext {
		$payment_data  = $context->get_payment_data();
		$payment_token = isset( $payment_data['payment_token'] ) ? (string) $payment_data['payment_token'] : '';
		if ( '' === $payment_token || 'new' === $payment_token ) {
			return $context;
		}

		$provider_data       = $context->get_provider_data();
		$payment_method_type = ! empty( $provider_data['scheduled_subscription_payment'] )
			? $this->token_service->resolve_payment_method_type_from_order_token_id( $payment_token, $context->get_order() )
			: $this->token_service->resolve_payment_method_type_from_token_id( $payment_token, $context->get_order()->get_user_id() );

		if ( '' === $payment_method_type ) {
			return $context;
		}

		$provider_data[ self::PROVIDER_DATA_SAVED_PAYMENT_METHOD_TYPE ] = $payment_method_type;

		return new PaymentContext(
			$context->get_order(),
			$context->get_gateway_id(),
			$context->get_payment_method_id(),
			$context->get_payment_data(),
			$provider_data,
			$context->get_amount()
		);
	}

	/**
	 * Get the submitted payment method or resolve the selected saved token.
	 *
	 * @param PaymentContext $context Payment context.
	 * @return string
	 */
	public function payment_credential_from_context( PaymentContext $context ): string {
		$payment_data  = $context->get_payment_data();
		$payment_token = isset( $payment_data['payment_token'] ) ? (string) $payment_data['payment_token'] : '';

		if ( '' !== $payment_token && 'new' !== $payment_token ) {
			if ( ! empty( $context->get_provider_data()['scheduled_subscription_payment'] ) ) {
				return $this->token_service->resolve_payment_method_id_from_order_token_id( $payment_token, $context->get_order() );
			}

			return $this->token_service->resolve_payment_method_id_from_token_id( $payment_token, $context->get_order()->get_user_id() );
		}

		return $context->get_payment_method_id();
	}

	/**
	 * Tell whether this payment must persist a token for a recurring order.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	public function is_recurring_payment( WC_Order $order ): bool {
		$is_recurring = false;
		if ( function_exists( 'wcs_order_contains_subscription' ) ) {
			$is_recurring = (bool) wcs_order_contains_subscription( $order->get_id() );
		}

		if ( ! $is_recurring && function_exists( 'wcs_order_contains_renewal' ) ) {
			$is_recurring = (bool) wcs_order_contains_renewal( $order->get_id() );
		}

		/**
		 * Filters whether a native WooPayments payment requires saved-token persistence.
		 *
		 * @since 11.0.0
		 *
		 * @param bool     $is_recurring Whether the order requires saved-token persistence.
		 * @param WC_Order $order        Order object.
		 */
		return (bool) apply_filters( 'woocommerce_woopayments_is_recurring_payment', $is_recurring, $order );
	}

	/**
	 * Get Stripe payment method types for a native WooPayments request.
	 *
	 * @param PaymentContext $context  Payment context.
	 * @param string         $currency Order currency.
	 * @return array<int,string>
	 */
	private function payment_method_types_for_request( PaymentContext $context, string $currency ): array {
		$provider_data             = $context->get_provider_data();
		$saved_payment_method_type = isset( $provider_data[ self::PROVIDER_DATA_SAVED_PAYMENT_METHOD_TYPE ] ) && is_scalar( $provider_data[ self::PROVIDER_DATA_SAVED_PAYMENT_METHOD_TYPE ] )
			? (string) $provider_data[ self::PROVIDER_DATA_SAVED_PAYMENT_METHOD_TYPE ]
			: '';

		if ( '' !== $saved_payment_method_type ) {
			return array( $saved_payment_method_type );
		}

		$split_gateway_payment_method_type = $this->payment_method_type_from_gateway_id( $context->get_gateway_id() );
		if ( '' !== $split_gateway_payment_method_type ) {
			return array( $split_gateway_payment_method_type );
		}

		$submitted_types = $provider_data[ WooPaymentsExpressPaymentMethodTypes::PROVIDER_DATA_KEY ] ?? array();
		$express_context = isset( $provider_data[ WooPaymentsExpressPaymentMethodTypes::PROVIDER_CONTEXT_KEY ] ) && is_scalar( $provider_data[ WooPaymentsExpressPaymentMethodTypes::PROVIDER_CONTEXT_KEY ] )
			? (string) $provider_data[ WooPaymentsExpressPaymentMethodTypes::PROVIDER_CONTEXT_KEY ]
			: 'checkout';
		$allowed_types   = WooPaymentsExpressPaymentMethodTypes::get_allowed_payment_method_types_for_account( $this->account_service, $express_context, $currency );
		$validated_types = WooPaymentsExpressPaymentMethodTypes::validate_submitted_payment_method_types( $submitted_types, $allowed_types );

		return empty( $validated_types ) ? array( WooPaymentsExpressPaymentMethodTypes::STRIPE_TYPE_CARD ) : $validated_types;
	}

	/**
	 * Resolve a Stripe payment method type from a split gateway ID.
	 *
	 * @param string $gateway_id WooPayments gateway ID.
	 * @return string
	 */
	private function payment_method_type_from_gateway_id( string $gateway_id ): string {
		if ( 0 !== strpos( $gateway_id, OrderPaymentStore::GATEWAY_ID_PREFIX ) ) {
			return '';
		}

		$payment_method_id = substr( $gateway_id, strlen( OrderPaymentStore::GATEWAY_ID_PREFIX ) );
		$definition        = $this->payment_method_registry->get( $payment_method_id );

		return null === $definition ? '' : $definition->get_stripe_payment_method_type();
	}

	/**
	 * Tell whether the request requires online mandate data.
	 *
	 * @param array<int,string> $payment_method_types Stripe payment method types.
	 * @return bool
	 */
	private static function is_mandate_data_required( array $payment_method_types ): bool {
		return in_array( 'sepa_debit', $payment_method_types, true ) || in_array( 'link', $payment_method_types, true );
	}

	/**
	 * Tell whether the request requires a redirect return URL.
	 *
	 * @param array<int,string> $payment_method_types Stripe payment method types.
	 * @return bool
	 */
	private static function is_redirect_return_url_required( array $payment_method_types ): bool {
		return in_array( 'amazon_pay', $payment_method_types, true )
			|| ( 1 === count( $payment_method_types ) && WooPaymentsExpressPaymentMethodTypes::STRIPE_TYPE_CARD !== ( $payment_method_types[0] ?? '' ) );
	}

	/**
	 * Build the WooPayments 10.8-compatible redirect return URL.
	 *
	 * @param WC_Order $order               Order being charged.
	 * @param bool     $save_payment_method Whether the return should preserve token-save context.
	 * @return string
	 */
	private static function redirect_return_url( WC_Order $order, bool $save_payment_method ): string {
		$query_args = array(
			'wc_payment_method' => OrderPaymentStore::GATEWAY_ID,
			'_wpnonce'          => wp_create_nonce( 'wcpay_process_redirect_order_nonce' ),
		);
		if ( $save_payment_method ) {
			$query_args['save_payment_method'] = 'yes';
		}

		return wp_sanitize_redirect(
			esc_url_raw(
				add_query_arg(
					$query_args,
					$order->get_checkout_order_received_url()
				)
			)
		);
	}

	/**
	 * Build online mandate acceptance data from the current request.
	 *
	 * @return array<string,mixed>
	 */
	private static function mandate_data(): array {
		return array(
			'customer_acceptance' => array(
				'type'   => 'online',
				'online' => array(
					'ip_address' => \WC_Geolocation::get_ip_address(),
					'user_agent' => 'WooCommerce Payments/' . self::WCPAY_V1_CLIENT_CAPABILITY_VERSION . '; ' . get_bloginfo( 'url' ),
				),
			),
		);
	}

	/**
	 * Tell whether checkout selected a saved payment token.
	 *
	 * @param array<string,mixed> $payment_data Payment data.
	 * @return bool
	 */
	private static function is_using_saved_payment_token( array $payment_data ): bool {
		$payment_token = isset( $payment_data['payment_token'] ) ? (string) $payment_data['payment_token'] : '';

		return '' !== $payment_token && 'new' !== $payment_token;
	}

	/**
	 * Get the Level 3 data service.
	 *
	 * @return WooPaymentsLevel3Service
	 */
	private function get_level3_service(): WooPaymentsLevel3Service {
		if ( ! isset( $this->level3_service ) ) {
			$this->level3_service = wc_get_container()->get( WooPaymentsLevel3Service::class );
		}

		return $this->level3_service;
	}
}
