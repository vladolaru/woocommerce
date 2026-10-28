<?php
/**
 * StoreApiRequestHandler class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\Jetpack\Connection\Rest_Authentication;
use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSessionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsStoreApiRequestUtils;
use Automattic\WooCommerce\StoreApi\SessionHandler;
use Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils;
use Automattic\WooCommerce\StoreApi\Utilities\DraftOrderTrait;
use WC_Order;
use WP_Error;

/**
 * Handles the Store API requests WooPay's servers send for a shopper: authenticates each by its Cart-Token, or by the
 * blog-token signature while the platform keeps the store on WooPay's signed path, resolves the shopper from the
 * cart-token session or a WooPay-verified email, passes referral and affiliate data
 * to the order, makes a $0 subscription order take a payment method, notes a fatal error on the order, and lets the
 * classic checkout resume a WooPay draft order under the order payment lock.
 *
 * Client 11.1.0 keeps these hooks in `WooPay_Session` (includes/woopay/class-woopay-session.php:101-323, :1146) and the
 * draft reuse in `WC_Payments_WooPay_Direct_Checkout` (includes/class-wc-payments-woopay-direct-checkout.php:56-79).
 *
 * Its hooks are added by `SessionController`: `register()` adds the Store API request hooks, including
 * `determine_current_user` at priority 20 (client 11.1.0 `includes/woopay/class-woopay-session.php:58`), and
 * `register_woopay_hooks()` adds the draft order filter while WooPay direct checkout is enabled.
 *
 * @since 11.2.0
 * @internal
 */
class StoreApiRequestHandler {

	use DraftOrderTrait;

	/**
	 * Active extensions WooPay has adapted, synced from the platform by ExtensionSync.
	 */
	private const ENABLED_ADAPTED_EXTENSIONS_OPTION = 'woopay_enabled_adapted_extensions';
	/**
	 * Store API namespaces a cart token may name as its issuer.
	 */
	private const STORE_API_NAMESPACE_PATTERN = '@^(wc/store(/v[\d]+)?|store-api)$@';
	/**
	 * Order meta stowing the real customer id while a verified-email guest order is detached.
	 */
	private const MERCHANT_CUSTOMER_ID_META = 'woopay_merchant_customer_id';
	/**
	 * Scheduled event that restores a detached order's customer ID (VerifiedEmailRestoreService); the
	 * WooPayments extension schedules the same event.
	 */
	private const RESTORE_CUSTOMER_ID_HOOK = 'woopay_restore_order_customer_id';
	/**
	 * Operation name the WooPay draft-order takeover holds the order payment lock under.
	 */
	private const DRAFT_REUSE_OPERATION = 'WooPay draft reuse';

	/**
	 * WooPay eligibility rules.
	 *
	 * @var Eligibility
	 */
	private Eligibility $woopay_eligibility;

	/**
	 * WooPay adapted extensions registry.
	 *
	 * @var AdaptedExtensions
	 */
	private AdaptedExtensions $adapted_extensions;

	/**
	 * Order ID an in-flight WooPay Store API checkout is processing, for fatal capture.
	 *
	 * @var int|null
	 */
	private ?int $checkout_error_order_id = null;
	/**
	 * Whether the WooPay checkout fatal-capture shutdown handler is registered.
	 *
	 * @var bool
	 */
	private bool $is_error_handler_registered = false;

	/**
	 * WooPayments logger.
	 *
	 * @var WooPaymentsLogger
	 */
	private WooPaymentsLogger $logger;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param Eligibility       $woopay_eligibility WooPay eligibility rules.
	 * @param AdaptedExtensions $adapted_extensions WooPay adapted extensions registry.
	 * @param WooPaymentsLogger $logger             WooPayments logger.
	 */
	final public function init( Eligibility $woopay_eligibility, AdaptedExtensions $adapted_extensions, WooPaymentsLogger $logger ): void {
		$this->woopay_eligibility = $woopay_eligibility;
		$this->adapted_extensions = $adapted_extensions;
		$this->logger             = $logger;
	}

	/**
	 * Resolve the current user for WooPay-originated Store API requests.
	 *
	 * The request must carry a valid Cart-Token, or the blog-token signature a store still gets while the platform keeps it
	 * on WooPay's signed path; otherwise it dies with a 401 naming why, as client 11.2.0 does
	 * (class-woopay-session.php:152-172, :1394-1408). It then resolves to the shopper of its Cart-Token session, so the
	 * order keeps its customer. A signed request is marked through the `wcpay_is_woopay_store_api_request` filter.
	 *
	 * @param int|bool $user Current user ID resolved so far, or false.
	 * @return int|bool
	 *
	 * @since 11.0.0
	 */
	public function determine_current_user_for_woopay( $user ) {
		if ( ! $this->is_request_from_woopay() || ! WooPaymentsStoreApiRequestUtils::is_store_api_request() ) {
			return $user;
		}

		if ( ! $this->woopay_eligibility->is_woopay_enabled() ) {
			return $user;
		}

		if ( ! $this->is_valid_request_with_cart_token() && ! $this->has_valid_request_signature() ) {
			$code    = '' !== CartTokenUtils::get_request_cart_token() ? 'woopay_invalid_cart_token' : 'woopay_request_not_authenticated';
			$message = 'woopay_invalid_cart_token' === $code
				? __( 'The Cart-Token on this WooPay request is invalid or expired.', 'woocommerce' )
				: __( 'WooPay request is not signed correctly.', 'woocommerce' );
			$this->logger->log( 'WooPay request rejected: ' . $code );
			wp_die(
				esc_html( $message ),
				'',
				array(
					'response' => 401,
					'code'     => esc_html( $code ),
				)
			);
		}

		if ( $this->has_valid_request_signature() ) {
			add_filter( 'wcpay_is_woopay_store_api_request', '__return_true' );
		}

		$cart_token_user_id = $this->get_user_id_from_cart_token();
		if ( null === $cart_token_user_id ) {
			return $user;
		}

		return $cart_token_user_id;
	}

	/**
	 * Tell whether the current request originates from WooPay.
	 *
	 * @return bool
	 *
	 * @since 11.0.0
	 */
	public function is_request_from_woopay(): bool {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) && 'WooPay' === $_SERVER['HTTP_USER_AGENT'];
	}

	/**
	 * Tell whether the current request is signed with the connected blog token while the platform keeps this store on
	 * WooPay's signed path; off it, a signature authenticates nothing (client 11.2.0 class-woopay-session.php:1007-1027).
	 *
	 * @return bool
	 *
	 * @since 11.0.0
	 */
	public function has_valid_request_signature(): bool {
		if ( ! $this->woopay_eligibility->is_woopay_force_signed_requests_enabled() ) {
			return false;
		}

		$signed = Rest_Authentication::is_signed_with_blog_token();

		/**
		 * Filters whether a WooPay session request is signed with the connected blog token.
		 *
		 * Strengthen-only: the real blog-token check is authoritative. This filter can
		 * further restrict access but can never grant it when the request is unsigned.
		 *
		 * @param bool $signed Whether the request is signed.
		 *
		 * @since 11.0.0
		 */
		return $signed && (bool) apply_filters( 'wcpay_woopay_is_signed_with_blog_token', $signed );
	}

	/**
	 * Tell whether the current request carries a valid Cart-Token from a Store API issuer, which proves only that the caller
	 * holds that cart (client 11.2.0 class-woopay-session.php:1039-1041).
	 *
	 * @return bool
	 */
	private function is_valid_request_with_cart_token(): bool {
		return null !== $this->get_payload_from_cart_token();
	}

	/**
	 * Resolve the shopper user id from the request's Cart-Token session.
	 *
	 * @return int|null
	 *
	 * @since 11.0.0
	 */
	public function get_user_id_from_cart_token(): ?int {
		$payload = $this->get_payload_from_cart_token();
		if ( null === $payload ) {
			return null;
		}

		$session_handler = new SessionHandler();
		$session_data    = $session_handler->get_session( (string) $payload['user_id'] );
		$customer        = is_array( $session_data ) && isset( $session_data['customer'] )
			? maybe_unserialize( $session_data['customer'] )
			: null;
		if ( ! is_array( $customer ) ) {
			return null;
		}

		// An already-authenticated cart-token session carries the shopper's customer id.
		if ( is_numeric( $customer['id'] ?? null ) && intval( $customer['id'] ) > 0 ) {
			return intval( $customer['id'] );
		}

		$woopay_verified_email_address = $this->get_woopay_verified_email_address();
		$enabled_adapted_extensions    = get_option( self::ENABLED_ADAPTED_EXTENSIONS_OPTION, array() );

		// A WooPay-verified email matching the cart-token session's email resolves to the matching
		// store account without authentication, but only while an adapted extension is active: the verified-email flow
		// serves those extensions' account data (points, gift cards).
		if ( ( is_countable( $enabled_adapted_extensions ) ? count( $enabled_adapted_extensions ) : 0 ) > 0 && null !== $woopay_verified_email_address && ! empty( $customer['email'] ) ) {
			$user = get_user_by( 'email', $woopay_verified_email_address );

			if ( $woopay_verified_email_address === $customer['email'] && $user ) {
				// Remove the Gift Cards session cache so account gift cards load.
				add_filter( 'woocommerce_gc_account_session_timeout_minutes', '__return_false' );

				return $user->ID;
			}
		}

		return null;
	}

	/**
	 * Detach the customer id from a WooPay verified-email guest order until restoration runs.
	 *
	 * A guest whose verified email resolves to a store account places the order as that account; stowing
	 * the customer id lets the guest open the thank-you page without the login form, and the scheduled
	 * restore re-links the order ten minutes later.
	 *
	 * @param int|mixed $order_id Order ID from woocommerce_order_payment_status_changed.
	 *
	 * @since 11.0.0
	 */
	public function woopay_order_payment_status_changed( $order_id ): void {
		if ( ! $this->woopay_eligibility->is_woopay_enabled() ) {
			return;
		}

		if ( ! $this->is_request_from_woopay() || ! WooPaymentsStoreApiRequestUtils::is_store_api_request() ) {
			return;
		}

		// Cookie-attributed extensions get their order data from the WooPay request itself,
		// independently of the verified-email flow below.
		$this->adapted_extensions->update_order_extension_data( (int) $order_id );

		$woopay_verified_email_address = $this->get_woopay_verified_email_address();
		if ( null === $woopay_verified_email_address ) {
			return;
		}

		$enabled_adapted_extensions = get_option( self::ENABLED_ADAPTED_EXTENSIONS_OPTION, array() );
		if ( 0 === ( is_countable( $enabled_adapted_extensions ) ? count( $enabled_adapted_extensions ) : 0 ) ) {
			return;
		}

		$payload = $this->get_payload_from_cart_token();
		if ( null === $payload ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// A guest's cart token names "t_<hash>" while the verified email gave the order the matched account; a logged-in
		// shopper's token names that same user id, as a string, and the order keeps its customer.
		$user_is_logged_in = (string) $payload['user_id'] === (string) $order->get_customer_id();

		if ( ! $user_is_logged_in && $woopay_verified_email_address === $order->get_billing_email() ) {
			$order->add_meta_data( self::MERCHANT_CUSTOMER_ID_META, (string) $order->get_customer_id(), true );
			$order->set_customer_id( 0 );
			$order->save();

			wp_schedule_single_event( time() + 10 * MINUTE_IN_SECONDS, self::RESTORE_CUSTOMER_ID_HOOK, array( $order->get_id() ) );
		}
	}

	/**
	 * Credit an AutomateWoo Refer a Friend link referral on a WooPay order.
	 *
	 * The WooPay checkout runs without the shopper's cookies, so AutomateWoo cannot find the advocate it stores there;
	 * WooPay returns the advocate this store sent it as the `automatewoo_referral_id` query parameter, as in
	 * client 11.1.0 `WooPay_Session::automatewoo_refer_a_friend_referral_from_parameter()`
	 * (`includes/woopay/class-woopay-session.php:273-289`).
	 *
	 * @since 11.2.0
	 *
	 * @param mixed $advocate_id Advocate ID AutomateWoo resolved.
	 * @return mixed The advocate ID, false when a WooPay order carries none.
	 */
	public function automatewoo_refer_a_friend_referral_from_parameter( $advocate_id ) {
		if ( ! $this->is_request_from_woopay() || ! WooPaymentsStoreApiRequestUtils::is_store_api_request() || ! $this->woopay_eligibility->is_woopay_enabled() ) {
			return $advocate_id;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Signed WooPay server request; the parameter is only an advocate ID.
		if ( empty( $_GET['automatewoo_referral_id'] ) || ! is_scalar( $_GET['automatewoo_referral_id'] ) ) {
			return false;
		}

		return (int) wc_clean( wp_unslash( (string) $_GET['automatewoo_referral_id'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Make a WooPay $0 subscription order take a payment method.
	 *
	 * WooPay saves the payment method on the order the subscription renews from; WooCommerce Subscriptions may say a $0
	 * order needs no payment (manual renewals, $0 recurring totals, or with its "$0 Initial Checkout" setting on, which
	 * removes its own filter). Ported from client 11.1.0 `WooPay_Session::woopay_trial_subscriptions_handler()`
	 * (class-woopay-session.php:298-316).
	 *
	 * @since 11.2.0
	 *
	 * @param mixed $needs_payment                Whether the order needs payment.
	 * @param mixed $order                        The order.
	 * @param mixed $_unused_valid_order_statuses Statuses that need payment.
	 * @return mixed
	 */
	public function woopay_trial_subscriptions_handler( $needs_payment, $order, $_unused_valid_order_statuses = array() ) {
		unset( $_unused_valid_order_statuses );

		if ( ! $this->is_request_from_woopay() || ! WooPaymentsStoreApiRequestUtils::is_store_api_request() || ! $this->woopay_eligibility->is_woopay_enabled() ) {
			return $needs_payment;
		}

		if ( ! $order instanceof \WC_Order || (float) $order->get_total() > 0 ) {
			return $needs_payment;
		}

		return $this->woopay_eligibility->woopay_cart_contains_subscription() ? true : $needs_payment;
	}

	/**
	 * Drop the browser's cached copy of the session a WooPay Store API request just saved.
	 *
	 * WooPay's Cart-Token names the shopper's own session, and the Store API session handler saves that row without
	 * touching the object cache the browser's `WC_Session_Handler::get_session()` reads first, so a persistent cache kept
	 * serving the session as it was before WooPay changed it. Client 11.1.0 swaps in a handler that also writes the cache
	 * (class-woopay-session.php:59, :79-91; class-woopay-store-api-session-handler.php:108-125); core's handler is final, so
	 * this runs after it saves on shutdown and lets the next browser read go to the database.
	 *
	 * @since 11.2.0
	 */
	public function refresh_woopay_browser_session_cache(): void {
		if ( ! $this->is_request_from_woopay() || ! WooPaymentsStoreApiRequestUtils::is_store_api_request() || ! $this->woopay_eligibility->is_woopay_enabled() ) {
			return;
		}

		$session = WC()->session;
		if ( ! $session instanceof SessionHandler ) {
			return;
		}

		$customer_id = (string) $session->get_customer_id();
		if ( '' !== $customer_id ) {
			wp_cache_delete( \WC_Cache_Helper::get_cache_prefix( WC_SESSION_CACHE_GROUP ) . $customer_id, WC_SESSION_CACHE_GROUP );
		}
	}

	/**
	 * Arm fatal-error capture for a WooPay-originated Store API checkout.
	 *
	 * A shutdown handler logs a fatal error and notes it on the order, so the merchant can see why the order stopped part
	 * way.
	 *
	 * @param mixed $order Order the Store API checkout is processing.
	 *
	 * @since 11.0.0
	 */
	public function catch_woopay_checkout_errors( $order ): void {
		if ( ! $this->is_request_from_woopay() || ! ( $order instanceof WC_Order ) ) {
			return;
		}

		$this->checkout_error_order_id = $order->get_id();

		if ( $this->is_error_handler_registered ) {
			return;
		}

		register_shutdown_function(
			function (): void {
				$this->maybe_record_woopay_checkout_fatal( error_get_last() );
			}
		);

		$this->is_error_handler_registered = true;
	}

	/**
	 * Record a fatal error from a WooPay Store API checkout, when one occurred.
	 *
	 * @param array<string,mixed>|null $error Last PHP error, as error_get_last() reports it.
	 *
	 * @since 11.0.0
	 */
	public function maybe_record_woopay_checkout_fatal( ?array $error ): void {
		if ( ! $error || ! $this->is_request_from_woopay() ) {
			return;
		}

		if ( ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
			return;
		}

		if ( null === $this->checkout_error_order_id ) {
			return;
		}

		// Client 11.1.0 logs the fatal's message (class-woopay-session.php:1170-1178), free text that can hold any value; the
		// line carries the error type and where it happened instead.
		wc_get_logger()->error(
			'WooPay checkout fatal error.',
			array(
				'error_type' => $error['type'],
				'file'       => (string) ( $error['file'] ?? '' ),
				'line'       => (int) ( $error['line'] ?? 0 ),
				'order_id'   => $this->checkout_error_order_id,
				'source'     => WooPaymentsLogger::SOURCE,
			)
		);

		$order = wc_get_order( $this->checkout_error_order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$error_first_line = strtok( (string) $error['message'], "\n" );
		if ( false === $error_first_line ) {
			$error_first_line = (string) $error['message'];
		}
		$order->add_order_note(
			sprintf(
				/* translators: %s: error message */
				__( 'WooPay checkout encountered a fatal error: %s', 'woocommerce' ),
				esc_html( $error_first_line )
			)
		);
	}

	/**
	 * Get the validated Cart-Token payload from the current request.
	 *
	 * @return array<string,mixed>|null
	 */
	private function get_payload_from_cart_token(): ?array {
		$cart_token = CartTokenUtils::get_request_cart_token();
		if ( '' === $cart_token || ! CartTokenUtils::validate_cart_token( $cart_token ) ) {
			return null;
		}

		$payload = CartTokenUtils::get_cart_token_payload( $cart_token );

		// The Store API namespace is used as the token issuer.
		if ( 1 !== preg_match( self::STORE_API_NAMESPACE_PATTERN, (string) $payload['iss'] ) ) {
			return null;
		}

		return $payload;
	}

	/**
	 * Get the WooPay-verified email address from the request headers, when present.
	 *
	 * @return string|null
	 */
	private function get_woopay_verified_email_address(): ?string {
		if ( ! isset( $_SERVER['HTTP_X_WOOPAY_VERIFIED_EMAIL_ADDRESS'] ) ) {
			return null;
		}

		return sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WOOPAY_VERIFIED_EMAIL_ADDRESS'] ) );
	}

	/**
	 * Let the classic checkout resume the Store API draft order a WooPay checkout left in the session.
	 *
	 * WooPay's Store API requests share the shopper's session, so a WooPay checkout that did not finish leaves its draft
	 * (and that draft's stock hold) as `store_api_draft_order`, which core's classic checkout never resumes. As in
	 * client 11.2.0 `WC_Payments_WooPay_Direct_Checkout::maybe_use_store_api_draft_order_id()`
	 * (class-wc-payments-woopay-direct-checkout.php:56-103): an order core's Store API would resume (a checkout draft, or a
	 * pending or failed order while the cart is unchanged, DraftOrderTrait::is_valid_draft_order()) becomes the pending order
	 * core resumes, and any other pointer is cleared. Unlike the client, a stored `false` counts as no order awaiting payment
	 * (WC_Session::__isset() reports it as set; core writes it after a payment or a cancellation), and a checkout draft is
	 * resumed only while its cart hash matches the current cart, checked under the lock after the fresh read; the client and
	 * core resume any checkout draft, and WC_Checkout::create_order() places a new order when the cart hash differs, so a draft
	 * totalled for another cart would be left behind as a pending order. The takeover holds the order payment lock and reads
	 * the order again under it, so it never changes an order another request is paying. While another
	 * request holds the lock of a checkout draft, or of a pending or failed order whatever its cart, checkout stops with a
	 * notice instead of placing and paying a second order; the client and core place one.
	 *
	 * @since 11.2.0
	 *
	 * @param mixed $order_id Order ID from the woocommerce_create_order filter.
	 * @return mixed The order ID, unchanged, or a WP_Error that WC_Checkout::process_checkout() shows as the checkout error.
	 */
	public function maybe_use_store_api_draft_order_id( $order_id ) {
		$session = WooPaymentsSessionService::get_wc_session();
		if ( ! Constants::is_true( 'WOOCOMMERCE_CHECKOUT' ) || ! empty( $order_id ) || null === $session || absint( $session->get( 'order_awaiting_payment' ) ) > 0 ) {
			return $order_id;
		}

		$draft_order_id = absint( $session->get( 'store_api_draft_order' ) );
		$draft_order    = $draft_order_id ? wc_get_order( $draft_order_id ) : false;
		// WooPay may be paying a pending or failed order, so its lock is claimed before core's rule is applied.
		if ( ! $draft_order instanceof WC_Order || ( ! $draft_order->has_status( array( OrderStatus::PENDING, OrderStatus::FAILED ) ) && ! $this->is_valid_draft_order( $draft_order ) ) ) {
			$session->set( 'store_api_draft_order', null );

			return $order_id;
		}

		$persistence_vocabulary = new WooPaymentsPersistenceVocabulary();
		$order_payment_lock     = wc_get_container()->get( OrderPaymentLock::class );
		$lock_token             = $order_payment_lock->claim( $draft_order, $persistence_vocabulary, null, self::DRAFT_REUSE_OPERATION );
		if ( null === $lock_token ) {
			$order_payment_lock->log_refusal( $draft_order, $persistence_vocabulary, self::DRAFT_REUSE_OPERATION );

			return new WP_Error(
				'woocommerce_woopay_payment_in_progress',
				__( 'Your previous payment attempt is still being processed, so please wait a moment and check for an order confirmation before trying again.', 'woocommerce' )
			);
		}

		try {
			$draft_order = wc_get_container()->get( OrderPaymentLifecycleService::class )->get_fresh_order_from_data_store( $draft_order );
			if ( ! $this->is_valid_draft_order( $draft_order ) || ! $draft_order->has_cart_hash( WC()->cart->get_cart_hash() ) ) {
				$session->set( 'store_api_draft_order', null );

				return $order_id;
			}

			$draft_order->set_status( OrderStatus::PENDING );
			$draft_order->save();

			$session->set( 'store_api_draft_order', null );
			$session->set( 'order_awaiting_payment', $draft_order_id );
		} finally {
			$order_payment_lock->release( $draft_order, $persistence_vocabulary, $lock_token );
		}

		return $order_id;
	}
}
